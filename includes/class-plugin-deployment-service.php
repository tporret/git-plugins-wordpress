<?php
declare(strict_types=1);
/**
 * Shared plugin deployment service for Git Repos Manager.
 *
 * @package GitPluginsWordPress
 */

defined('ABSPATH') || exit;

/**
 * Handles installing and updating plugins from GitHub release assets.
 */
final class GPW_Plugin_Deployment_Service {
	/**
	 * GitHub API service.
	 *
	 * @var GPW_GitHub_API
	 */
	private GPW_GitHub_API $github_api;

	/**
	 * Managed plugin registry.
	 *
	 * @var GPW_Managed_Plugin_Registry
	 */
	private GPW_Managed_Plugin_Registry $registry;

	/**
	 * Release channel manager.
	 *
	 * @var GPW_Channel_Manager
	 */
	private GPW_Channel_Manager $channel_manager;

	/**
	 * Constructor.
	 *
	 * @param GPW_GitHub_API               $github_api      GitHub API wrapper.
	 * @param GPW_Managed_Plugin_Registry  $registry        Managed plugin registry.
	 * @param GPW_Channel_Manager          $channel_manager Release channel manager.
	 */
	public function __construct(GPW_GitHub_API $github_api, GPW_Managed_Plugin_Registry $registry, GPW_Channel_Manager $channel_manager) {
		$this->github_api      = $github_api;
		$this->registry        = $registry;
		$this->channel_manager = $channel_manager;
	}

	/**
	 * Install a plugin from the latest GitHub release.
	 *
	 * @param string $repo_full_name Repository full name.
	 * @param string $channel        Release channel.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function install_repository(string $repo_full_name, string $channel = GPW_Channel_Manager::CHANNEL_STABLE) {
		$repo_full_name = sanitize_text_field($repo_full_name);
		$channel        = $this->normalize_channel($channel);
		if ('' === $repo_full_name) {
			return new WP_Error('gpw_missing_repo_name', __('Repository name is required.', 'git-plugins-wordpress'));
		}

		$this->load_wordpress_upgrade_dependencies();

		$permissions_error = $this->get_plugins_directory_permissions_error_message();
		if ('' !== $permissions_error) {
			return new WP_Error('gpw_plugins_dir_not_writable', $permissions_error);
		}

		$installed_before   = get_plugins();
		$downloaded_package = $this->prepare_release_package($repo_full_name, $channel);
		if (is_wp_error($downloaded_package)) {
			return $downloaded_package;
		}
		$package_path = $downloaded_package['package_path'];
		$verification = $downloaded_package['verification'];
		$release      = $downloaded_package['release'];

		$package_validation = $this->validate_package_archive($package_path);
		if (is_wp_error($package_validation)) {
			$this->delete_temporary_file($package_path);
			return $package_validation;
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader($skin);
		$result   = $upgrader->install($package_path);

		$this->delete_temporary_file($package_path);

		if (is_wp_error($result)) {
			return $result;
		}

		if (! $result) {
			$error_message = $this->get_upgrader_error_message($upgrader, $skin);
			if ('' === $error_message) {
				$error_message = __('Plugin installation failed.', 'git-plugins-wordpress');
			}

			return new WP_Error('gpw_plugin_install_failed', $error_message);
		}

		wp_clean_plugins_cache(true);
		$installed_after = get_plugins();
		$plugin_file     = $this->resolve_installed_plugin_file($upgrader, $installed_before, $installed_after, $repo_full_name);
		$validation      = $this->validate_installed_plugin_file($plugin_file, $installed_after);

		if (is_wp_error($validation)) {
			$this->cleanup_new_install($installed_before, $installed_after);
			return $validation;
		}

		$this->registry->register_plugin($repo_full_name, $plugin_file, true, $verification);

		return array(
			'channel'     => $channel,
			'plugin_file' => $plugin_file,
			'release'     => $release,
			'verification' => $verification,
		);
	}

	/**
	 * Update an installed plugin from the latest GitHub release.
	 *
	 * @param string $repo_full_name Repository full name.
	 * @param string $plugin_file    Plugin file relative to the plugins directory.
	 * @param string $channel        Release channel.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_repository(string $repo_full_name, string $plugin_file, string $channel = GPW_Channel_Manager::CHANNEL_STABLE) {
		$repo_full_name = sanitize_text_field($repo_full_name);
		$plugin_file    = sanitize_text_field($plugin_file);
		$channel        = $this->normalize_channel($channel);

		if ('' === $repo_full_name || '' === $plugin_file) {
			return new WP_Error('gpw_missing_update_params', __('Required parameters are missing.', 'git-plugins-wordpress'));
		}

		$this->load_wordpress_upgrade_dependencies();

		$installed_plugins = get_plugins();
		if (! array_key_exists($plugin_file, $installed_plugins)) {
			return new WP_Error('gpw_plugin_not_installed', __('Plugin is not installed.', 'git-plugins-wordpress'));
		}

		$downloaded_package = $this->prepare_release_package($repo_full_name, $channel);
		if (is_wp_error($downloaded_package)) {
			return $downloaded_package;
		}

		$permissions_error = $this->get_plugin_update_permissions_error_message($plugin_file);
		if ('' !== $permissions_error) {
			return new WP_Error('gpw_plugin_not_writable', $permissions_error);
		}

		$package_path = $downloaded_package['package_path'];
		$verification = $downloaded_package['verification'];
		$release      = $downloaded_package['release'];

		$package_validation = $this->validate_package_archive($package_path);
		if (is_wp_error($package_validation)) {
			$this->delete_temporary_file($package_path);
			return $package_validation;
		}

		$was_network_active = is_multisite() && is_plugin_active_for_network($plugin_file);
		$was_site_active    = is_plugin_active($plugin_file);
		$is_self_update     = $this->is_current_plugin_file($plugin_file);
		$backup             = $this->create_plugin_backup($plugin_file);
		if (is_wp_error($backup)) {
			$this->delete_temporary_file($package_path);
			return $backup;
		}

		if (! $is_self_update && ($was_network_active || $was_site_active)) {
			deactivate_plugins($plugin_file, true, $was_network_active);
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader($skin);
		$result   = $upgrader->install($package_path, array('overwrite_package' => true));

		$this->delete_temporary_file($package_path);

		if (is_wp_error($result)) {
			$this->restore_plugin_backup($backup);
			if (! $is_self_update) {
				$this->restore_activation_state($plugin_file, $was_network_active, $was_site_active);
			}
			$this->delete_plugin_backup($backup);
			return $result;
		}

		if (! $result) {
			$error_message = $this->get_upgrader_error_message($upgrader, $skin);
			if ('' === $error_message) {
				$error_message = __('Plugin update failed.', 'git-plugins-wordpress');
			}

			$this->restore_plugin_backup($backup);
			if (! $is_self_update) {
				$this->restore_activation_state($plugin_file, $was_network_active, $was_site_active);
			}
			$this->delete_plugin_backup($backup);

			return new WP_Error('gpw_plugin_update_failed', $error_message);
		}

		wp_clean_plugins_cache(true);
		$installed_after       = get_plugins();
		$resolved_plugin_file  = array_key_exists($plugin_file, $installed_after)
			? $plugin_file
			: $this->resolve_installed_plugin_file($upgrader, $installed_plugins, $installed_after, $repo_full_name);
		$resolved_plugin_file  = '' !== $resolved_plugin_file ? $resolved_plugin_file : $plugin_file;
		$validation            = $this->validate_installed_plugin_file($resolved_plugin_file, $installed_after);

		if (is_wp_error($validation)) {
			$this->restore_plugin_backup($backup);
			if (! $is_self_update) {
				$this->restore_activation_state($plugin_file, $was_network_active, $was_site_active);
			}
			$this->delete_plugin_backup($backup);
			return $validation;
		}

		$activation_result = $is_self_update ? true : $this->restore_activation_state($resolved_plugin_file, $was_network_active, $was_site_active);
		if (is_wp_error($activation_result)) {
			$this->restore_plugin_backup($backup);
			if (! $is_self_update) {
				$this->restore_activation_state($plugin_file, $was_network_active, $was_site_active);
			}
			$this->delete_plugin_backup($backup);

			return new WP_Error(
				'gpw_plugin_update_activation_failed',
				sprintf(
					/* translators: %s: activation error message. */
					__('The package was installed, but activation validation failed, so the previous version was restored: %s', 'git-plugins-wordpress'),
					$activation_result->get_error_message()
				)
			);
		}

		$this->delete_plugin_backup($backup);

		$this->registry->register_plugin($repo_full_name, $resolved_plugin_file, true, $verification);

		return array(
			'channel'     => $channel,
			'plugin_file' => $resolved_plugin_file,
			'release'     => $release,
			'verification' => $verification,
		);
	}

	/**
	 * Normalize a release channel using the shared channel manager.
	 *
	 * @param string $channel Release channel.
	 *
	 * @return string
	 */
	private function normalize_channel(string $channel): string {
		return $this->channel_manager->normalize_channel($channel);
	}

	/**
	 * Prepare a verified release package for installation or update.
	 *
	 * @param string $repo_full_name Repository full name.
	 * @param string $channel        Release channel.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function prepare_release_package(string $repo_full_name, string $channel) {
		$repo_full_name = sanitize_text_field($repo_full_name);
		$channel        = $this->normalize_channel($channel);

		if ('' === $repo_full_name) {
			return new WP_Error('gpw_missing_repo_name', __('Repository name is required.', 'git-plugins-wordpress'));
		}

		$release = $this->github_api->get_latest_release($repo_full_name, true, $channel);
		if (is_wp_error($release)) {
			return $release;
		}

		$downloaded_package = $this->download_and_verify_package($release, $repo_full_name);
		if (is_wp_error($downloaded_package)) {
			return $downloaded_package;
		}

		return array(
			'release'      => $release,
			'package_path' => $downloaded_package['package_path'],
			'verification' => $downloaded_package['verification'],
		);
	}

	/**
	 * Load WordPress upgrader dependencies.
	 *
	 * @return void
	 */
	private function load_wordpress_upgrade_dependencies(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	/**
	 * Create an authenticated download filter for GitHub asset requests.
	 *
	 * @param string $repo_full_name Repository full name.
	 *
	 * @return callable|null
	 */
	private function create_download_auth_filter(string $repo_full_name): ?callable {
		$token = $this->github_api->get_auth_token_for_repo($repo_full_name);
		if ('' === $token) {
			return null;
		}

		$allowed_hosts = array(
			'api.github.com',
			'github.com',
			'objects.githubusercontent.com',
			'codeload.github.com',
		);

		$auth_filter = static function (array $args, string $url) use ($token, $allowed_hosts): array {
			$host = wp_parse_url($url, PHP_URL_HOST);
			if (! is_string($host) || ! in_array(strtolower($host), $allowed_hosts, true)) {
				return $args;
			}

			if (! isset($args['headers']) || ! is_array($args['headers'])) {
				$args['headers'] = array();
			}

			$args['headers']['Authorization'] = 'Bearer ' . $token;
			if ('api.github.com' === strtolower($host) && str_contains($url, '/releases/assets/')) {
				$args['headers']['Accept'] = 'application/octet-stream';
			}

			return $args;
		};

		add_filter('http_request_args', $auth_filter, 10, 2);

		return $auth_filter;
	}

	/**
	 * Remove the authenticated download filter.
	 *
	 * @param callable|null $auth_filter Filter callback.
	 *
	 * @return void
	 */
	private function remove_download_auth_filter(?callable $auth_filter): void {
		if (null !== $auth_filter) {
			remove_filter('http_request_args', $auth_filter, 10);
		}
	}

	/**
	 * Download the release package and verify its SHA-256 checksum before extraction.
	 *
	 * @param array<string, mixed> $release        Release data.
	 * @param string               $repo_full_name Repository full name.
	 *
	 * @return array{package_path: string, verification: array{status: string, algorithm: string, verified_at: string, release_version: string, checksum: string}}|WP_Error
	 */
	private function download_and_verify_package(array $release, string $repo_full_name) {
		$package_assets = $this->extract_release_package_assets($release, $repo_full_name);
		if (is_wp_error($package_assets)) {
			return $package_assets;
		}

		$auth_filter  = $this->create_download_auth_filter($repo_full_name);
		$package_path = download_url($package_assets['zip_url'], 300);

		if (is_wp_error($package_path)) {
			$this->remove_download_auth_filter($auth_filter);
			return new WP_Error(
				'gpw_package_download_failed',
				sprintf(
					/* translators: %s: download error message. */
					__('Failed to download the release archive: %s', 'git-plugins-wordpress'),
					$package_path->get_error_message()
				)
			);
		}

		$checksum_path = download_url($package_assets['checksum_url'], 60);
		$this->remove_download_auth_filter($auth_filter);

		if (is_wp_error($checksum_path)) {
			$this->delete_temporary_file($package_path);
			return new WP_Error(
				'gpw_checksum_download_failed',
				sprintf(
					/* translators: 1: checksum asset name, 2: download error message. */
					__('Failed to download checksum asset %1$s: %2$s', 'git-plugins-wordpress'),
					$package_assets['checksum_name'],
					$checksum_path->get_error_message()
				)
			);
		}

		$release_version = isset($release['tag_name']) ? sanitize_text_field((string) $release['tag_name']) : '';
		$verification = $this->verify_package_checksum($package_path, $checksum_path, $package_assets['zip_name'], $release_version);
		$this->delete_temporary_file($checksum_path);

		if (is_wp_error($verification)) {
			$this->delete_temporary_file($package_path);
			return $verification;
		}

		return array(
			'package_path' => $package_path,
			'verification' => $verification,
		);
	}

	/**
	 * Restore the plugin activation state after update.
	 *
	 * @param string $plugin_file        Plugin file relative path.
	 * @param bool   $was_network_active Whether the plugin was network active.
	 * @param bool   $was_site_active    Whether the plugin was site active.
	 *
	 * @return true|WP_Error
	 */
	private function restore_activation_state(string $plugin_file, bool $was_network_active, bool $was_site_active) {
		if (! $was_network_active && ! $was_site_active) {
			return true;
		}

		$result = activate_plugin($plugin_file, '', $was_network_active, true);
		if (is_wp_error($result)) {
			return $result;
		}

		return true;
	}

	/**
	 * Check whether a plugin file points to this running plugin.
	 *
	 * @param string $plugin_file Plugin file relative path.
	 *
	 * @return bool
	 */
	private function is_current_plugin_file(string $plugin_file): bool {
		if (! defined('GPW_PLUGIN_FILE')) {
			return false;
		}

		return plugin_basename((string) GPW_PLUGIN_FILE) === $plugin_file;
	}

	/**
	 * Validate a release archive before installing it into the plugins directory.
	 *
	 * @param string $package_path Downloaded release archive path.
	 *
	 * @return true|WP_Error
	 */
	private function validate_package_archive(string $package_path) {
		if (! is_readable($package_path)) {
			return new WP_Error('gpw_package_unreadable', __('Downloaded release archive could not be read for validation.', 'git-plugins-wordpress'));
		}

		$staging_dir = trailingslashit(get_temp_dir()) . 'gpw-package-' . md5($package_path . microtime(true));
		if (! wp_mkdir_p($staging_dir)) {
			return new WP_Error('gpw_package_staging_failed', __('Could not create a temporary directory to validate the release archive.', 'git-plugins-wordpress'));
		}

		$unzipped = unzip_file($package_path, $staging_dir);
		if (is_wp_error($unzipped)) {
			$this->delete_path($staging_dir);
			return new WP_Error(
				'gpw_package_extract_failed',
				sprintf(
					/* translators: %s: archive extraction error message. */
					__('Release archive validation failed during extraction: %s', 'git-plugins-wordpress'),
					$unzipped->get_error_message()
				)
			);
		}

		$plugin_files = $this->find_plugin_files_in_directory($staging_dir);
		$this->delete_path($staging_dir);

		if (empty($plugin_files)) {
			return new WP_Error('gpw_package_missing_plugin_file', __('Release archive does not contain a valid WordPress plugin file.', 'git-plugins-wordpress'));
		}

		return true;
	}

	/**
	 * Validate the installed plugin file before registration or activation.
	 *
	 * @param string                         $plugin_file       Plugin file relative to plugins directory.
	 * @param array<string, array<string>>   $installed_plugins Installed plugin data.
	 *
	 * @return true|WP_Error
	 */
	private function validate_installed_plugin_file(string $plugin_file, array $installed_plugins) {
		if ('' === $plugin_file) {
			return new WP_Error('gpw_plugin_file_unresolved', __('The installed package did not expose a resolvable WordPress plugin file.', 'git-plugins-wordpress'));
		}

		if (! array_key_exists($plugin_file, $installed_plugins)) {
			return new WP_Error('gpw_plugin_file_missing', __('The installed package is missing its main plugin file.', 'git-plugins-wordpress'));
		}

		$plugin_path = WP_PLUGIN_DIR . '/' . ltrim($plugin_file, '/');
		if (! is_file($plugin_path) || ! is_readable($plugin_path)) {
			return new WP_Error('gpw_plugin_file_unreadable', __('The installed plugin file could not be read.', 'git-plugins-wordpress'));
		}

		$validation = validate_plugin($plugin_file);
		if (is_wp_error($validation)) {
			return new WP_Error(
				'gpw_plugin_file_invalid',
				sprintf(
					/* translators: %s: plugin validation error message. */
					__('Installed plugin validation failed: %s', 'git-plugins-wordpress'),
					$validation->get_error_message()
				)
			);
		}

		if (function_exists('validate_plugin_requirements')) {
			$requirements = validate_plugin_requirements($plugin_file);
			if (is_wp_error($requirements)) {
				return new WP_Error(
					'gpw_plugin_requirements_failed',
					sprintf(
						/* translators: %s: plugin requirements error message. */
						__('Installed plugin requirements failed: %s', 'git-plugins-wordpress'),
						$requirements->get_error_message()
					)
				);
			}
		}

		return true;
	}

	/**
	 * Remove files created by a failed fresh install.
	 *
	 * @param array<string, array<string>> $installed_before Plugins before installation.
	 * @param array<string, array<string>> $installed_after  Plugins after installation.
	 *
	 * @return void
	 */
	private function cleanup_new_install(array $installed_before, array $installed_after): void {
		$new_plugin_files = array_values(array_diff(array_keys($installed_after), array_keys($installed_before)));
		foreach ($new_plugin_files as $new_plugin_file) {
			$this->delete_path($this->get_plugin_path((string) $new_plugin_file));
		}

		wp_clean_plugins_cache(true);
	}

	/**
	 * Create a temporary backup of the plugin being updated.
	 *
	 * @param string $plugin_file Plugin file relative to plugins directory.
	 *
	 * @return array{original_path: string, backup_path: string}|WP_Error
	 */
	private function create_plugin_backup(string $plugin_file) {
		$original_path = $this->get_plugin_path($plugin_file);
		if (! file_exists($original_path)) {
			return new WP_Error('gpw_plugin_backup_missing_source', __('Cannot back up the installed plugin because its files are missing.', 'git-plugins-wordpress'));
		}

		$backup_path = trailingslashit(get_temp_dir()) . 'gpw-plugin-backup-' . md5($plugin_file . microtime(true));
		$copied      = $this->copy_path($original_path, $backup_path);
		if (is_wp_error($copied)) {
			$this->delete_path($backup_path);
			return $copied;
		}

		return array(
			'original_path' => $original_path,
			'backup_path'   => $backup_path,
		);
	}

	/**
	 * Restore an updated plugin from its temporary backup.
	 *
	 * @param array{original_path: string, backup_path: string} $backup Backup metadata.
	 *
	 * @return true|WP_Error
	 */
	private function restore_plugin_backup(array $backup) {
		$this->delete_path($backup['original_path']);
		$restored = $this->copy_path($backup['backup_path'], $backup['original_path']);
		wp_clean_plugins_cache(true);

		return $restored;
	}

	/**
	 * Delete a temporary plugin backup.
	 *
	 * @param array{backup_path: string} $backup Backup metadata.
	 *
	 * @return void
	 */
	private function delete_plugin_backup(array $backup): void {
		$this->delete_path($backup['backup_path']);
	}

	/**
	 * Find plugin header files under a directory.
	 *
	 * @param string $directory Directory to scan.
	 *
	 * @return array<int, string>
	 */
	private function find_plugin_files_in_directory(string $directory): array {
		$plugin_files = array();
		$iterator     = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if (! $file instanceof SplFileInfo || ! $file->isFile() || 'php' !== strtolower($file->getExtension())) {
				continue;
			}

			$headers = get_file_data($file->getPathname(), array('name' => 'Plugin Name'));
			if (isset($headers['name']) && '' !== trim((string) $headers['name'])) {
				$plugin_files[] = $file->getPathname();
			}
		}

		return $plugin_files;
	}

	/**
	 * Get the file or directory path that represents a plugin install.
	 *
	 * @param string $plugin_file Plugin file relative to plugins directory.
	 *
	 * @return string
	 */
	private function get_plugin_path(string $plugin_file): string {
		$plugin_path = WP_PLUGIN_DIR . '/' . ltrim($plugin_file, '/');
		$plugin_dir  = dirname($plugin_path);

		return WP_PLUGIN_DIR === $plugin_dir ? $plugin_path : $plugin_dir;
	}

	/**
	 * Copy a file or directory recursively.
	 *
	 * @param string $source      Source path.
	 * @param string $destination Destination path.
	 *
	 * @return true|WP_Error
	 */
	private function copy_path(string $source, string $destination) {
		if (is_dir($source)) {
			if (! wp_mkdir_p($destination)) {
				return new WP_Error('gpw_copy_directory_failed', __('Could not create a temporary plugin backup directory.', 'git-plugins-wordpress'));
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ($iterator as $item) {
				$target = $destination . '/' . $iterator->getSubPathName();
				if ($item->isDir()) {
					if (! wp_mkdir_p($target)) {
						return new WP_Error('gpw_copy_directory_failed', __('Could not copy the installed plugin directory for rollback.', 'git-plugins-wordpress'));
					}
					continue;
				}

				if (! copy($item->getPathname(), $target)) {
					return new WP_Error('gpw_copy_file_failed', __('Could not copy the installed plugin files for rollback.', 'git-plugins-wordpress'));
				}
			}

			return true;
		}

		if (! wp_mkdir_p(dirname($destination)) || ! copy($source, $destination)) {
			return new WP_Error('gpw_copy_file_failed', __('Could not copy the installed plugin file for rollback.', 'git-plugins-wordpress'));
		}

		return true;
	}

	/**
	 * Delete a file or directory recursively.
	 *
	 * @param string $path Path to delete.
	 *
	 * @return void
	 */
	private function delete_path(string $path): void {
		if ('' === $path || ! file_exists($path)) {
			return;
		}

		if (is_file($path) || is_link($path)) {
			wp_delete_file($path);
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $item) {
			if ($item->isDir() && ! $item->isLink()) {
				rmdir($item->getPathname());
				continue;
			}

			wp_delete_file($item->getPathname());
		}

		rmdir($path);
	}

	/**
	 * Resolve the installed plugin file after an install/update.
	 *
	 * @param Plugin_Upgrader               $upgrader         Plugin upgrader instance.
	 * @param array<string, array<string>>  $installed_before Plugins before the operation.
	 * @param array<string, array<string>>  $installed_after  Plugins after the operation.
	 * @param string                        $repo_full_name   Repository full name.
	 *
	 * @return string
	 */
	private function resolve_installed_plugin_file(Plugin_Upgrader $upgrader, array $installed_before, array $installed_after, string $repo_full_name): string {
		if (method_exists($upgrader, 'plugin_info')) {
			$plugin_info = $upgrader->plugin_info();
			if (is_string($plugin_info) && '' !== $plugin_info && array_key_exists($plugin_info, $installed_after)) {
				return $plugin_info;
			}
		}

		$new_plugin_files = array_values(array_diff(array_keys($installed_after), array_keys($installed_before)));
		if (1 === count($new_plugin_files) && isset($new_plugin_files[0])) {
			return (string) $new_plugin_files[0];
		}

		$repo_name = $this->get_repo_name($repo_full_name);

		return $this->registry->find_plugin_file_by_repo_name($repo_name, $installed_after);
	}

	/**
	 * Get the repository short name from owner/repo.
	 *
	 * @param string $repo_full_name Repository full name.
	 *
	 * @return string
	 */
	private function get_repo_name(string $repo_full_name): string {
		$parts = explode('/', $repo_full_name, 2);

		return isset($parts[1]) ? sanitize_text_field($parts[1]) : '';
	}

	/**
	 * Extract the release zip asset and matching checksum asset URLs.
	 *
	 * @param array<string, mixed> $release        Release data.
	 * @param string               $repo_full_name Repository full name.
	 *
	 * @return array{zip_name: string, zip_url: string, checksum_name: string, checksum_url: string}|WP_Error
	 */
	private function extract_release_package_assets(array $release, string $repo_full_name) {
		$assets = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : array();
		if (empty($assets)) {
			return new WP_Error('gpw_missing_release_assets', __('The latest GitHub release does not contain downloadable assets.', 'git-plugins-wordpress'));
		}

		$has_token       = '' !== $this->github_api->get_auth_token_for_repo($repo_full_name);
		$checksum_assets = array();
		$zip_candidates  = array();

		foreach ($assets as $asset) {
			if (! is_array($asset)) {
				continue;
			}

			$name         = isset($asset['name']) ? sanitize_file_name((string) $asset['name']) : '';
			$api_url      = isset($asset['url']) ? esc_url_raw((string) $asset['url']) : '';
			$browser_url  = isset($asset['browser_download_url']) ? esc_url_raw((string) $asset['browser_download_url']) : '';
			$preferred    = $has_token ? $api_url : $browser_url;
			$fallback     = $has_token ? $browser_url : $api_url;
			$resolved_url = '' !== $preferred ? $preferred : $fallback;

			if ('' === $name || '' === $resolved_url) {
				continue;
			}

			if (str_ends_with(strtolower($name), '.sha256')) {
				$checksum_assets[$name] = $resolved_url;
				continue;
			}

			if (str_ends_with(strtolower($name), '.zip')) {
				$zip_candidates[] = array(
					'name' => $name,
					'url'  => $resolved_url,
				);
			}
		}

		foreach ($zip_candidates as $candidate) {
			$checksum_name = $candidate['name'] . '.sha256';
			if (! isset($checksum_assets[$checksum_name])) {
				continue;
			}

			return array(
				'zip_name'       => $candidate['name'],
				'zip_url'        => $candidate['url'],
				'checksum_name'  => $checksum_name,
				'checksum_url'   => $checksum_assets[$checksum_name],
			);
		}

		if (! empty($zip_candidates)) {
			return new WP_Error(
				'gpw_missing_checksum_asset',
				sprintf(
					/* translators: %s: expected checksum asset suffix. */
					__('Release checksum asset is missing. Expected a .sha256 file matching the release zip name, such as %s.', 'git-plugins-wordpress'),
					$zip_candidates[0]['name'] . '.sha256'
				)
			);
		}

		return new WP_Error('gpw_missing_zip_asset', __('No .zip asset found in the latest GitHub release.', 'git-plugins-wordpress'));
	}

	/**
	 * Validate the downloaded package against the release checksum file.
	 *
	 * @param string $package_path    Temporary downloaded zip path.
	 * @param string $checksum_path   Temporary downloaded checksum path.
	 * @param string $zip_name        Original zip asset name.
	 * @param string $release_version GitHub release version.
	 *
	 * @return array{status: string, algorithm: string, verified_at: string, release_version: string, checksum: string}|WP_Error
	 */
	private function verify_package_checksum(string $package_path, string $checksum_path, string $zip_name, string $release_version) {
		if (! is_readable($package_path) || ! is_readable($checksum_path)) {
			return new WP_Error('gpw_checksum_unreadable', __('Downloaded package or checksum file could not be read for verification.', 'git-plugins-wordpress'));
		}

		$checksum_contents = file_get_contents($checksum_path);
		if (false === $checksum_contents) {
			return new WP_Error('gpw_checksum_read_failed', __('Failed to read the downloaded checksum file.', 'git-plugins-wordpress'));
		}

		$checksum_contents = trim($checksum_contents);
		if (! preg_match('/^([a-f0-9]{64})\b/i', $checksum_contents, $matches)) {
			return new WP_Error(
				'gpw_checksum_invalid_format',
				sprintf(
					/* translators: %s: checksum asset filename. */
					__('Checksum asset %s is not in a valid SHA-256 format.', 'git-plugins-wordpress'),
					basename($checksum_path)
				)
			);
		}

		$expected_hash = strtolower($matches[1]);
		$actual_hash   = hash_file('sha256', $package_path);

		if (false === $actual_hash) {
			return new WP_Error('gpw_checksum_hash_failed', __('Failed to compute the SHA-256 hash for the downloaded release archive.', 'git-plugins-wordpress'));
		}

		$actual_hash = strtolower($actual_hash);
		if (! hash_equals($expected_hash, $actual_hash)) {
			return new WP_Error(
				'gpw_checksum_mismatch',
				sprintf(
					/* translators: %s: zip asset filename. */
					__('Checksum verification failed for %s. The downloaded archive does not match the published SHA-256 fingerprint.', 'git-plugins-wordpress'),
					$zip_name
				)
			);
		}

		return array(
			'status'          => GPW_Managed_Plugin_Registry::VERIFICATION_VERIFIED,
			'algorithm'       => 'sha256',
			'verified_at'     => gmdate('c'),
			'release_version' => $release_version,
			'checksum'        => $actual_hash,
		);
	}

	/**
	 * Extract .zip download URL from a release.
	 *
	 * @param array<string, mixed> $release         Release data.
	 * @param string               $repo_full_name  Repository full name.
	 *
	 * @return string
	 */
	private function extract_zip_url(array $release, string $repo_full_name): string {
		$assets    = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : array();
		$has_token = '' !== $this->github_api->get_auth_token_for_repo($repo_full_name);

		foreach ($assets as $asset) {
			if (! is_array($asset)) {
				continue;
			}

			$name              = isset($asset['name']) ? sanitize_file_name((string) $asset['name']) : '';
			$api_url           = isset($asset['url']) ? esc_url_raw((string) $asset['url']) : '';
			$browser_url       = isset($asset['browser_download_url']) ? esc_url_raw((string) $asset['browser_download_url']) : '';
			$preferred_zip_url = $has_token ? $api_url : $browser_url;
			$fallback_zip_url  = $has_token ? $browser_url : $api_url;

			if ('' === $name || ! str_ends_with(strtolower($name), '.zip')) {
				continue;
			}

			if ('' !== $preferred_zip_url) {
				return $preferred_zip_url;
			}

			if ('' !== $fallback_zip_url) {
				return $fallback_zip_url;
			}
		}

		return isset($release['zipball_url']) ? esc_url_raw((string) $release['zipball_url']) : '';
	}

	/**
	 * Extract a useful error message from upgrader state.
	 *
	 * @param Plugin_Upgrader         $upgrader Upgrader instance.
	 * @param Automatic_Upgrader_Skin $skin     Upgrader skin.
	 *
	 * @return string
	 */
	private function get_upgrader_error_message(Plugin_Upgrader $upgrader, Automatic_Upgrader_Skin $skin): string {
		if (method_exists($skin, 'get_errors')) {
			$errors = $skin->get_errors();
			if ($errors instanceof WP_Error && $errors->has_errors()) {
				return $errors->get_error_message();
			}
		}

		if (isset($skin->result) && $skin->result instanceof WP_Error) {
			return $skin->result->get_error_message();
		}

		if (isset($upgrader->skin) && is_object($upgrader->skin) && method_exists($upgrader->skin, 'get_errors')) {
			$errors = $upgrader->skin->get_errors();
			if ($errors instanceof WP_Error && $errors->has_errors()) {
				return $errors->get_error_message();
			}
		}

		if (isset($upgrader->skin) && is_object($upgrader->skin) && isset($upgrader->skin->result) && $upgrader->skin->result instanceof WP_Error) {
			return $upgrader->skin->result->get_error_message();
		}

		return '';
	}

	/**
	 * Check whether the global plugins directory is writable by the current request user.
	 *
	 * @return string
	 */
	private function get_plugins_directory_permissions_error_message(): string {
		clearstatcache(true, WP_PLUGIN_DIR);

		if (wp_is_writable(WP_PLUGIN_DIR)) {
			return '';
		}

		return sprintf(
			/* translators: %s: plugins directory path. */
			__('The WordPress plugins directory is not writable by the web server: %s', 'git-plugins-wordpress'),
			WP_PLUGIN_DIR
		);
	}

	/**
	 * Check whether the plugin being updated is writable by the current request user.
	 *
	 * @param string $plugin_file Plugin file relative to the plugins directory.
	 *
	 * @return string
	 */
	private function get_plugin_update_permissions_error_message(string $plugin_file): string {
		$plugin_path = WP_PLUGIN_DIR . '/' . ltrim($plugin_file, '/');
		$plugin_dir  = dirname($plugin_path);

		clearstatcache(true, $plugin_dir);
		clearstatcache(true, $plugin_path);

		if (is_dir($plugin_dir) && ! wp_is_writable($plugin_dir)) {
			return sprintf(
				/* translators: %s: plugin directory path. */
				__('The installed plugin directory is not writable by the web server: %s', 'git-plugins-wordpress'),
				$plugin_dir
			);
		}

		if (file_exists($plugin_path) && ! wp_is_writable($plugin_path)) {
			return sprintf(
				/* translators: %s: plugin file path. */
				__('The installed plugin file is not writable by the web server: %s', 'git-plugins-wordpress'),
				$plugin_path
			);
		}

		return '';
	}

	/**
	 * Delete a temporary file if it still exists.
	 *
	 * @param string $file_path Temporary file path.
	 *
	 * @return void
	 */
	private function delete_temporary_file(string $file_path): void {
		if ('' === $file_path || ! file_exists($file_path)) {
			return;
		}

		if (function_exists('wp_delete_file')) {
			wp_delete_file($file_path);
			return;
		}

		unlink($file_path);
	}
}

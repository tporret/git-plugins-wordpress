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
	 * Constructor.
	 *
	 * @param GPW_GitHub_API               $github_api GitHub API wrapper.
	 * @param GPW_Managed_Plugin_Registry  $registry   Managed plugin registry.
	 */
	public function __construct(GPW_GitHub_API $github_api, GPW_Managed_Plugin_Registry $registry) {
		$this->github_api = $github_api;
		$this->registry   = $registry;
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
		$channel        = (new GPW_Channel_Manager())->normalize_channel($channel);
		if ('' === $repo_full_name) {
			return new WP_Error('gpw_missing_repo_name', __('Repository name is required.', 'git-plugins-wordpress'));
		}

		$this->load_wordpress_upgrade_dependencies();

		$release = $this->github_api->get_latest_release($repo_full_name, true, $channel);
		if (is_wp_error($release)) {
			return $release;
		}

		$permissions_error = $this->get_plugins_directory_permissions_error_message();
		if ('' !== $permissions_error) {
			return new WP_Error('gpw_plugins_dir_not_writable', $permissions_error);
		}

		$installed_before   = get_plugins();
		$downloaded_package = $this->download_and_verify_package($release, $repo_full_name);
		if (is_wp_error($downloaded_package)) {
			return $downloaded_package;
		}
		$package_path = $downloaded_package['package_path'];
		$verification = $downloaded_package['verification'];

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
		$channel        = (new GPW_Channel_Manager())->normalize_channel($channel);

		if ('' === $repo_full_name || '' === $plugin_file) {
			return new WP_Error('gpw_missing_update_params', __('Required parameters are missing.', 'git-plugins-wordpress'));
		}

		$this->load_wordpress_upgrade_dependencies();

		$installed_plugins = get_plugins();
		if (! array_key_exists($plugin_file, $installed_plugins)) {
			return new WP_Error('gpw_plugin_not_installed', __('Plugin is not installed.', 'git-plugins-wordpress'));
		}

		$release = $this->github_api->get_latest_release($repo_full_name, true, $channel);
		if (is_wp_error($release)) {
			return $release;
		}

		$permissions_error = $this->get_plugin_update_permissions_error_message($plugin_file);
		if ('' !== $permissions_error) {
			return new WP_Error('gpw_plugin_not_writable', $permissions_error);
		}

		$downloaded_package = $this->download_and_verify_package($release, $repo_full_name);
		if (is_wp_error($downloaded_package)) {
			return $downloaded_package;
		}
		$package_path = $downloaded_package['package_path'];
		$verification = $downloaded_package['verification'];

		$was_network_active = is_multisite() && is_plugin_active_for_network($plugin_file);
		$was_site_active    = is_plugin_active($plugin_file);
		if ($was_network_active || $was_site_active) {
			deactivate_plugins($plugin_file, true, $was_network_active);
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader($skin);
		$result   = $upgrader->install($package_path, array('overwrite_package' => true));

		$this->delete_temporary_file($package_path);

		if (is_wp_error($result)) {
			$this->restore_activation_state($plugin_file, $was_network_active, $was_site_active);
			return $result;
		}

		if (! $result) {
			$error_message = $this->get_upgrader_error_message($upgrader, $skin);
			if ('' === $error_message) {
				$error_message = __('Plugin update failed.', 'git-plugins-wordpress');
			}

			$this->restore_activation_state($plugin_file, $was_network_active, $was_site_active);

			return new WP_Error('gpw_plugin_update_failed', $error_message);
		}

		$this->restore_activation_state($plugin_file, $was_network_active, $was_site_active);

		wp_clean_plugins_cache(true);
		$installed_after       = get_plugins();
		$resolved_plugin_file  = array_key_exists($plugin_file, $installed_after)
			? $plugin_file
			: $this->resolve_installed_plugin_file($upgrader, $installed_plugins, $installed_after, $repo_full_name);

		$this->registry->register_plugin($repo_full_name, '' !== $resolved_plugin_file ? $resolved_plugin_file : $plugin_file, true, $verification);

		return array(
			'channel'     => $channel,
			'plugin_file' => '' !== $resolved_plugin_file ? $resolved_plugin_file : $plugin_file,
			'release'     => $release,
			'verification' => $verification,
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
	 * @return void
	 */
	private function restore_activation_state(string $plugin_file, bool $was_network_active, bool $was_site_active): void {
		if ($was_network_active || $was_site_active) {
			activate_plugin($plugin_file, '', $was_network_active, true);
		}
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

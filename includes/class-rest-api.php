<?php
declare(strict_types=1);
/**
 * REST API controller for Git Repos Manager.
 *
 * @package GitPluginsWordPress
 */

defined('ABSPATH') || exit;

/**
 * Registers and handles REST API routes under the gpw/v1 namespace.
 */
final class GPW_REST_API {
	/**
	 * REST namespace.
	 */
	private const NAMESPACE = 'gpw/v1';

	/**
	 * Option key for saved settings.
	 */
	private const OPTION_NAME = 'gpw_settings';

	/**
	 * Option key for active repositories.
	 */
	private const ACTIVE_REPOS_OPTION = 'gpw_active_repos';

	/**
	 * GitHub API service.
	 *
	 * @var GPW_GitHub_API
	 */
	private GPW_GitHub_API $github_api;

	/**
	 * Constructor.
	 *
	 * @param GPW_GitHub_API $github_api GitHub API wrapper.
	 */
	public function __construct(GPW_GitHub_API $github_api) {
		$this->github_api = $github_api;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action('rest_api_init', array($this, 'register_routes'));
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(self::NAMESPACE, '/settings', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_settings'),
				'permission_callback' => array($this, 'check_admin_permission'),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'save_settings'),
				'permission_callback' => array($this, 'check_admin_permission'),
			),
		));

		register_rest_route(self::NAMESPACE, '/plugins', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array($this, 'get_plugins'),
			'permission_callback' => array($this, 'check_admin_permission'),
		));

		register_rest_route(self::NAMESPACE, '/plugins/toggle', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'toggle_plugin'),
			'permission_callback' => array($this, 'check_admin_permission'),
		));

		register_rest_route(self::NAMESPACE, '/plugins/install', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'install_plugin'),
			'permission_callback' => array($this, 'check_admin_permission'),
		));

		register_rest_route(self::NAMESPACE, '/plugins/uninstall', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'uninstall_plugin'),
			'permission_callback' => array($this, 'check_admin_permission'),
		));

		register_rest_route(self::NAMESPACE, '/plugins/update', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'update_plugin'),
			'permission_callback' => array($this, 'check_admin_permission'),
		));

		register_rest_route(self::NAMESPACE, '/cache/flush', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'flush_cache'),
			'permission_callback' => array($this, 'check_admin_permission'),
		));
	}

	/**
	 * Permission check for admin endpoints.
	 *
	 * @return bool|WP_Error
	 */
	public function check_admin_permission() {
		if (! current_user_can('manage_options')) {
			return new WP_Error(
				'rest_forbidden',
				__('You do not have permission to access this endpoint.', 'git-plugins-wordpress'),
				array('status' => 403)
			);
		}
		return true;
	}

	/**
	 * GET /settings — Fetch sources.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		$settings = get_option(self::OPTION_NAME, array());

		if (! is_array($settings)) {
			$settings = array();
		}

		$sources = array();
		if (isset($settings['sources']) && is_array($settings['sources'])) {
			foreach ($settings['sources'] as $source) {
				if (! is_array($source)) {
					continue;
				}
				$target = isset($source['target']) ? (string) $source['target'] : '';
				$pat    = isset($source['pat']) ? (string) $source['pat'] : '';
				if ('' === $target) {
					continue;
				}
				// Decrypt PAT if encrypted, then mask it for display.
				if ('' !== $pat && GPW_Encryption::is_encrypted($pat)) {
					$pat = GPW_Encryption::decrypt($pat);
				}
				$sources[] = array(
					'target' => $target,
					'pat'    => '' !== $pat ? str_repeat('•', 8) : '',
				);
			}
		}

		return new WP_REST_Response(array('sources' => $sources), 200);
	}

	/**
	 * POST /settings — Save sources.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function save_settings(WP_REST_Request $request): WP_REST_Response {
		$raw_sources = $request->get_param('sources');

		if (! is_array($raw_sources)) {
			return new WP_REST_Response(
				array('message' => __('Invalid sources data.', 'git-plugins-wordpress')),
				400
			);
		}

		$old_settings = get_option(self::OPTION_NAME, array());
		if (! is_array($old_settings)) {
			$old_settings = array();
		}
		$old_sources = isset($old_settings['sources']) && is_array($old_settings['sources']) ? $old_settings['sources'] : array();

		$new_sources = array();
		foreach ($raw_sources as $index => $source) {
			if (! is_array($source)) {
				continue;
			}

			$target = isset($source['target']) ? sanitize_text_field(trim((string) $source['target'])) : '';
			$pat    = isset($source['pat']) ? trim((string) $source['pat']) : '';

			if ('' === $target) {
				continue;
			}

			// If the PAT is the masked placeholder, preserve the original encrypted value.
			if (preg_match('/^[•]+$/', $pat) && isset($old_sources[$index]['pat'])) {
				$pat = (string) $old_sources[$index]['pat'];
			} elseif ('' !== $pat) {
				// Validate PAT format: GitHub classic (ghp_), fine-grained (github_pat_), or legacy alphanumeric.
				if (! preg_match('/^(ghp_[a-zA-Z0-9]{36,255}|github_pat_[a-zA-Z0-9_]{22,255}|[a-f0-9]{40})$/', $pat)) {
					return new WP_REST_Response(
						array('message' => sprintf(
							/* translators: %d: source row number */
							__('Source #%d has an invalid PAT format. Expected a GitHub personal access token.', 'git-plugins-wordpress'),
							$index + 1
						)),
						400
					);
				}
				// Encrypt new/updated PAT.
				$encrypted = GPW_Encryption::encrypt($pat);
				if (is_wp_error($encrypted)) {
					return new WP_REST_Response(
						array('message' => $encrypted->get_error_message()),
						500
					);
				}
				$pat = $encrypted;
			}

			$new_sources[] = array(
				'target' => $target,
				'pat'    => $pat,
			);
		}

		$sources_changed = wp_json_encode($old_sources) !== wp_json_encode($new_sources);
		if ($sources_changed) {
			$this->github_api->flush_cache();
		}

		update_option(self::OPTION_NAME, array('sources' => $new_sources));

		// Store encryption sentinel so key rotation can be detected.
		$has_encrypted_pat = false;
		foreach ($new_sources as $src) {
			if ('' !== $src['pat'] && GPW_Encryption::is_encrypted($src['pat'])) {
				$has_encrypted_pat = true;
				break;
			}
		}
		if ($has_encrypted_pat) {
			GPW_Encryption::store_sentinel();
		}

		return new WP_REST_Response(array('message' => __('Settings saved.', 'git-plugins-wordpress')), 200);
	}

	/**
	 * GET /plugins — Fetch plugin list merged with local status.
	 *
	 * @return WP_REST_Response
	 */
	public function get_plugins(): WP_REST_Response {
		$repositories = $this->github_api->get_repositories();

		if (is_wp_error($repositories)) {
			return new WP_REST_Response(
				array('message' => $repositories->get_error_message(), 'plugins' => array()),
				200
			);
		}

		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed_plugins = get_plugins();
		$installed_dirs    = array();
		foreach ($installed_plugins as $plugin_file => $plugin_data) {
			$parts = explode('/', $plugin_file, 2);
			if (count($parts) === 2) {
				$installed_dirs[strtolower($parts[0])] = $plugin_file;
			}
		}

		$active_repos = (array) get_option(self::ACTIVE_REPOS_OPTION, array());
		$result       = array();

		foreach ($repositories as $repository) {
			$repo_name      = isset($repository['name']) ? sanitize_text_field((string) $repository['name']) : '';
			$repo_full_name = isset($repository['full_name']) ? sanitize_text_field((string) $repository['full_name']) : '';
			$description    = isset($repository['description']) ? sanitize_text_field((string) $repository['description']) : '';

			$release = '' !== $repo_full_name
				? $this->github_api->get_latest_release($repo_full_name, false)
				: new WP_Error('gpw_missing_repo_name', 'Missing');

			$version = is_wp_error($release)
				? ''
				: (isset($release['tag_name']) ? sanitize_text_field((string) $release['tag_name']) : '');

			$plugin_file       = $installed_dirs[strtolower($repo_name)] ?? '';
			$is_installed      = '' !== $plugin_file;
			$is_active         = in_array($repo_full_name, $active_repos, true)
				|| ($is_installed && is_plugin_active($plugin_file));

			$installed_version = $is_installed
				? sanitize_text_field((string) ($installed_plugins[$plugin_file]['Version'] ?? ''))
				: '';
			$github_version    = ltrim($version, 'vV');
			$update_available  = $is_installed
				&& '' !== $installed_version
				&& '' !== $github_version
				&& version_compare($installed_version, $github_version, '<');

			$result[] = array(
				'name'              => $repo_name,
				'full_name'         => $repo_full_name,
				'description'       => $description,
				'version'           => $version,
				'installed_version' => $installed_version,
				'is_installed'      => $is_installed,
				'is_active'         => $is_active,
				'update_available'  => $update_available,
				'plugin_file'       => $plugin_file,
			);
		}

		return new WP_REST_Response(array('plugins' => $result), 200);
	}

	/**
	 * POST /plugins/toggle — Toggle active state.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function toggle_plugin(WP_REST_Request $request): WP_REST_Response {
		$repo_full_name = sanitize_text_field((string) $request->get_param('full_name'));

		if ('' === $repo_full_name) {
			return new WP_REST_Response(
				array('message' => __('Repository name is required.', 'git-plugins-wordpress')),
				400
			);
		}

		$active_repos = (array) get_option(self::ACTIVE_REPOS_OPTION, array());

		if (in_array($repo_full_name, $active_repos, true)) {
			$active_repos = array_values(array_filter(
				$active_repos,
				static fn($r) => $r !== $repo_full_name
			));
			$new_state = false;
		} else {
			$active_repos[] = $repo_full_name;
			$new_state      = true;
		}

		update_option(self::ACTIVE_REPOS_OPTION, array_values(array_unique($active_repos)), false);

		return new WP_REST_Response(array(
			'message'   => __('Toggle updated.', 'git-plugins-wordpress'),
			'is_active' => $new_state,
		), 200);
	}

	/**
	 * POST /plugins/install — Install or update a plugin from GitHub.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function install_plugin(WP_REST_Request $request): WP_REST_Response {
		if (! current_user_can('install_plugins')) {
			return new WP_REST_Response(
				array('message' => __('You do not have permission to install plugins.', 'git-plugins-wordpress')),
				403
			);
		}

		$repo_full_name = sanitize_text_field((string) $request->get_param('full_name'));
		if ('' === $repo_full_name) {
			return new WP_REST_Response(
				array('message' => __('Repository name is required.', 'git-plugins-wordpress')),
				400
			);
		}

		$release = $this->github_api->get_latest_release($repo_full_name);
		if (is_wp_error($release)) {
			return new WP_REST_Response(
				array('message' => $release->get_error_message()),
				422
			);
		}

		$download_url = $this->extract_zip_url($release, $repo_full_name);
		if ('' === $download_url) {
			return new WP_REST_Response(
				array('message' => __('No .zip asset found in the latest GitHub release.', 'git-plugins-wordpress')),
				422
			);
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$token = $this->github_api->get_auth_token_for_repo($repo_full_name);

		$auth_filter = null;
		if ('' !== $token) {
			$allowed_hosts = array(
				'api.github.com',
				'github.com',
				'objects.githubusercontent.com',
				'codeload.github.com',
			);
			$auth_filter = function (array $args, string $url) use ($token, $allowed_hosts): array {
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
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader($skin);
		$result   = $upgrader->install($download_url);

		if (null !== $auth_filter) {
			remove_filter('http_request_args', $auth_filter, 10);
		}

		if (is_wp_error($result)) {
			return new WP_REST_Response(array('message' => $result->get_error_message()), 422);
		}

		if (! $result) {
			return new WP_REST_Response(
				array('message' => __('Plugin installation failed.', 'git-plugins-wordpress')),
				422
			);
		}

		$active_repos   = (array) get_option(self::ACTIVE_REPOS_OPTION, array());
		$active_repos[] = $repo_full_name;
		update_option(self::ACTIVE_REPOS_OPTION, array_values(array_unique($active_repos)), false);

		return new WP_REST_Response(array('message' => __('Plugin installed successfully.', 'git-plugins-wordpress')), 200);
	}

	/**
	 * POST /plugins/uninstall — Uninstall a plugin.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function uninstall_plugin(WP_REST_Request $request): WP_REST_Response {
		if (! current_user_can('delete_plugins')) {
			return new WP_REST_Response(
				array('message' => __('You do not have permission to delete plugins.', 'git-plugins-wordpress')),
				403
			);
		}

		$repo_full_name = sanitize_text_field((string) $request->get_param('full_name'));
		$plugin_file    = sanitize_text_field((string) $request->get_param('plugin_file'));

		if ('' === $repo_full_name || '' === $plugin_file) {
			return new WP_REST_Response(
				array('message' => __('Required parameters are missing.', 'git-plugins-wordpress')),
				400
			);
		}

		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed_plugins = get_plugins();
		if (! array_key_exists($plugin_file, $installed_plugins)) {
			return new WP_REST_Response(
				array('message' => __('Plugin is not installed.', 'git-plugins-wordpress')),
				404
			);
		}

		if (is_plugin_active($plugin_file)) {
			deactivate_plugins($plugin_file, true);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$deleted = delete_plugins(array($plugin_file));
		if (is_wp_error($deleted)) {
			return new WP_REST_Response(array('message' => $deleted->get_error_message()), 422);
		}

		$active_repos = array_values(array_filter(
			(array) get_option(self::ACTIVE_REPOS_OPTION, array()),
			static fn($r) => is_string($r) && $r !== $repo_full_name
		));
		update_option(self::ACTIVE_REPOS_OPTION, $active_repos, false);

		return new WP_REST_Response(array('message' => __('Plugin uninstalled successfully.', 'git-plugins-wordpress')), 200);
	}

	/**
	 * POST /plugins/update — Update an installed plugin to the latest GitHub release.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function update_plugin(WP_REST_Request $request): WP_REST_Response {
		if (! current_user_can('update_plugins')) {
			return new WP_REST_Response(
				array('message' => __('You do not have permission to update plugins.', 'git-plugins-wordpress')),
				403
			);
		}

		$repo_full_name = sanitize_text_field((string) $request->get_param('full_name'));
		$plugin_file    = sanitize_text_field((string) $request->get_param('plugin_file'));

		if ('' === $repo_full_name || '' === $plugin_file) {
			return new WP_REST_Response(
				array('message' => __('Required parameters are missing.', 'git-plugins-wordpress')),
				400
			);
		}

		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if (! array_key_exists($plugin_file, get_plugins())) {
			return new WP_REST_Response(
				array('message' => __('Plugin is not installed.', 'git-plugins-wordpress')),
				404
			);
		}

		$release = $this->github_api->get_latest_release($repo_full_name);
		if (is_wp_error($release)) {
			return new WP_REST_Response(
				array('message' => $release->get_error_message()),
				422
			);
		}

		$download_url = $this->extract_zip_url($release, $repo_full_name);
		if ('' === $download_url) {
			return new WP_REST_Response(
				array('message' => __('No .zip asset found in the latest GitHub release.', 'git-plugins-wordpress')),
				422
			);
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$token = $this->github_api->get_auth_token_for_repo($repo_full_name);

		$auth_filter = null;
		if ('' !== $token) {
			$allowed_hosts = array(
				'api.github.com',
				'github.com',
				'objects.githubusercontent.com',
				'codeload.github.com',
			);
			$auth_filter = function (array $args, string $url) use ($token, $allowed_hosts): array {
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
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader($skin);
		$result   = $upgrader->install($download_url);

		if (null !== $auth_filter) {
			remove_filter('http_request_args', $auth_filter, 10);
		}

		if (is_wp_error($result)) {
			return new WP_REST_Response(array('message' => $result->get_error_message()), 422);
		}

		if (! $result) {
			return new WP_REST_Response(
				array('message' => __('Plugin update failed.', 'git-plugins-wordpress')),
				422
			);
		}

		wp_clean_plugins_cache(true);

		return new WP_REST_Response(array('message' => __('Plugin updated successfully.', 'git-plugins-wordpress')), 200);
	}

	/**
	 * POST /cache/flush — Clear GitHub API cache.
	 *
	 * @return WP_REST_Response
	 */
	public function flush_cache(): WP_REST_Response {
		GPW_Cache_Manager::flush_all();
		$this->github_api->flush_cache();
		wp_clean_plugins_cache(true);

		return new WP_REST_Response(array('message' => __('GitHub cache cleared. Plugins will refresh on next load.', 'git-plugins-wordpress')), 200);
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
		$assets = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : array();

		foreach ($assets as $asset) {
			if (! is_array($asset)) {
				continue;
			}
			$name = isset($asset['name']) ? (string) $asset['name'] : '';
			$url  = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';
			if (str_ends_with(strtolower($name), '.zip') && '' !== $url) {
				return $url;
			}
		}

		// Fallback to zipball.
		return isset($release['zipball_url']) ? (string) $release['zipball_url'] : '';
	}
}

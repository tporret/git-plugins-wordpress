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
	 * Cache key for multisite activation summaries.
	 */
	private const SITES_SUMMARY_CACHE_KEY = 'network_activation_summary';

	/**
	 * Number of seconds to cache activation summaries.
	 */
	private const SITES_SUMMARY_CACHE_TTL = 60;

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

		register_rest_route(self::NAMESPACE, '/plugins/sites', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array($this, 'get_plugin_sites'),
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
		if (! GPW_Context::current_user_can_manage_settings()) {
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
		$settings = GPW_Context::get_option(self::OPTION_NAME, array());

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

		return new WP_REST_Response(array(
			'sources'   => $sources,
			'context'   => GPW_Context::get_js_context(),
			'lastError' => $this->github_api->get_last_error(),
		), 200);
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

		$old_settings = GPW_Context::get_option(self::OPTION_NAME, array());
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

		GPW_Context::update_option(self::OPTION_NAME, array('sources' => $new_sources));

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

		$active_repos       = (array) GPW_Context::get_option(self::ACTIVE_REPOS_OPTION, array());
		$sites_summary      = $this->get_multisite_activation_summary();
		$site_activation_map = isset($sites_summary['active_counts']) && is_array($sites_summary['active_counts'])
			? $sites_summary['active_counts']
			: array();
		$total_site_count   = isset($sites_summary['total_sites']) ? (int) $sites_summary['total_sites'] : 0;
		$result             = array();

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
			$is_tracked        = in_array($repo_full_name, $active_repos, true);
			$is_network_active = $is_installed && is_multisite() && is_plugin_active_for_network($plugin_file);
			$active_site_count = $is_installed && isset($site_activation_map[$plugin_file])
				? (int) $site_activation_map[$plugin_file]
				: 0;

			if ($is_installed && ! is_multisite()) {
				$active_site_count = is_plugin_active($plugin_file) ? 1 : 0;
			}

			$is_site_active = $is_installed && ! $is_network_active && $active_site_count > 0;
			$sites_summary_label = $this->get_sites_summary_label(
				$is_installed,
				$is_network_active,
				$active_site_count,
				$total_site_count
			);

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
				'is_active'         => $is_tracked,
				'is_tracked'        => $is_tracked,
				'is_site_active'    => $is_site_active,
				'is_network_active' => $is_network_active,
				'activation_scope'  => $is_network_active ? 'network' : ($is_site_active ? 'site' : 'inactive'),
				'active_site_count' => $is_network_active ? $total_site_count : $active_site_count,
				'total_site_count'  => $total_site_count,
				'sites_summary_label' => $sites_summary_label,
				'update_available'  => $update_available,
				'plugin_file'       => $plugin_file,
			);
		}

		return new WP_REST_Response(array('plugins' => $result), 200);
	}

	/**
	 * GET /plugins/sites — Fetch paginated multisite activation details for a plugin.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_plugin_sites(WP_REST_Request $request): WP_REST_Response {
		$plugin_file = sanitize_text_field((string) $request->get_param('plugin_file'));
		$page        = max(1, (int) $request->get_param('page'));
		$per_page    = max(1, min(100, (int) $request->get_param('per_page')));

		if ('' === $plugin_file) {
			return new WP_REST_Response(
				array('message' => __('Plugin file is required.', 'git-plugins-wordpress')),
				400
			);
		}

		if (! is_multisite()) {
			return new WP_REST_Response(array(
				'plugin_file'       => $plugin_file,
				'is_network_active' => false,
				'active_site_count' => 0,
				'total_site_count'  => 1,
				'page'              => 1,
				'per_page'          => $per_page,
				'total_pages'       => 1,
				'sites'             => array(),
			), 200);
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

		$is_network_active = is_plugin_active_for_network($plugin_file);
		$site_ids          = $this->get_network_site_ids();
		$total_site_count  = count($site_ids);
		$active_sites      = array();

		foreach ($site_ids as $site_id) {
			$site_id = (int) $site_id;
			$site    = get_blog_details($site_id);
			if (! $site instanceof WP_Site) {
				continue;
			}

			$activation_scope = 'inactive';
			if ($is_network_active) {
				$activation_scope = 'network';
			} else {
				$active_plugins = get_blog_option($site_id, 'active_plugins', array());
				if (! is_array($active_plugins) || ! in_array($plugin_file, $active_plugins, true)) {
					continue;
				}
				$activation_scope = 'site';
			}

			$active_sites[] = array(
				'blog_id'          => $site_id,
				'name'             => sanitize_text_field((string) $site->blogname),
				'url'              => esc_url_raw(get_home_url($site_id, '/')),
				'activation_scope' => $activation_scope,
			);
		}

		$total_active_sites = count($active_sites);
		$total_pages        = max(1, (int) ceil($total_active_sites / $per_page));
		$offset             = ($page - 1) * $per_page;
		$paged_sites        = array_slice($active_sites, $offset, $per_page);

		return new WP_REST_Response(array(
			'plugin_file'       => $plugin_file,
			'is_network_active' => $is_network_active,
			'active_site_count' => $total_active_sites,
			'total_site_count'  => $total_site_count,
			'page'              => $page,
			'per_page'          => $per_page,
			'total_pages'       => $total_pages,
			'sites'             => $paged_sites,
		), 200);
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

		$active_repos = (array) GPW_Context::get_option(self::ACTIVE_REPOS_OPTION, array());

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

		GPW_Context::update_option(self::ACTIVE_REPOS_OPTION, array_values(array_unique($active_repos)), false);

		return new WP_REST_Response(array(
			'message'   => __('Toggle updated.', 'git-plugins-wordpress'),
			'is_active' => $new_state,
			'is_tracked' => $new_state,
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
		if (! GPW_Context::current_user_can_install_plugins()) {
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

		$permissions_error = $this->get_plugins_directory_permissions_error_message();
		if ('' !== $permissions_error) {
			return new WP_REST_Response(array('message' => $permissions_error), 422);
		}

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
			$error_message = $this->get_upgrader_error_message($upgrader, $skin);
			if ('' === $error_message) {
				$error_message = $this->get_plugins_directory_permissions_error_message();
			}

			return new WP_REST_Response(
				array('message' => '' !== $error_message ? $error_message : __('Plugin installation failed.', 'git-plugins-wordpress')),
				422
			);
		}

		$active_repos   = (array) GPW_Context::get_option(self::ACTIVE_REPOS_OPTION, array());
		$active_repos[] = $repo_full_name;
		GPW_Context::update_option(self::ACTIVE_REPOS_OPTION, array_values(array_unique($active_repos)), false);

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
		if (! GPW_Context::current_user_can_delete_plugins()) {
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

		$is_network_active = is_multisite() && is_plugin_active_for_network($plugin_file);
		if ($is_network_active || is_plugin_active($plugin_file)) {
			deactivate_plugins($plugin_file, true, $is_network_active);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$deleted = delete_plugins(array($plugin_file));
		if (is_wp_error($deleted)) {
			return new WP_REST_Response(array('message' => $deleted->get_error_message()), 422);
		}

		$active_repos = array_values(array_filter(
			(array) GPW_Context::get_option(self::ACTIVE_REPOS_OPTION, array()),
			static fn($r) => is_string($r) && $r !== $repo_full_name
		));
		GPW_Context::update_option(self::ACTIVE_REPOS_OPTION, $active_repos, false);

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
		if (! GPW_Context::current_user_can_update_plugins()) {
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

		$permissions_error = $this->get_plugin_update_permissions_error_message($plugin_file);
		if ('' !== $permissions_error) {
			return new WP_REST_Response(array('message' => $permissions_error), 422);
		}

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

		// Deactivate plugin before overwriting to avoid fatal errors from partially-loaded files.
		$was_network_active = is_multisite() && is_plugin_active_for_network($plugin_file);
		$was_site_active    = is_plugin_active($plugin_file);
		if ($was_network_active || $was_site_active) {
			deactivate_plugins($plugin_file, true, $was_network_active);
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader($skin);
		$result   = $upgrader->install($download_url, array('overwrite_package' => true));

		if (null !== $auth_filter) {
			remove_filter('http_request_args', $auth_filter, 10);
		}

		if (is_wp_error($result)) {
			// Re-activate if we deactivated and the update failed.
			if ($was_network_active || $was_site_active) {
				activate_plugin($plugin_file, '', $was_network_active, true);
			}
			return new WP_REST_Response(array('message' => $result->get_error_message()), 422);
		}

		if (! $result) {
			$error_message = $this->get_upgrader_error_message($upgrader, $skin);
			if ('' === $error_message) {
				$error_message = $this->get_plugin_update_permissions_error_message($plugin_file);
			}

			if ($was_network_active || $was_site_active) {
				activate_plugin($plugin_file, '', $was_network_active, true);
			}
			return new WP_REST_Response(
				array('message' => '' !== $error_message ? $error_message : __('Plugin update failed.', 'git-plugins-wordpress')),
				422
			);
		}

		// Re-activate if it was active before the update.
		if ($was_network_active || $was_site_active) {
			activate_plugin($plugin_file, '', $was_network_active, true);
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
		$has_token = '' !== $this->github_api->get_auth_token_for_repo($repo_full_name);

		foreach ($assets as $asset) {
			if (! is_array($asset)) {
				continue;
			}
			$name              = isset($asset['name']) ? (string) $asset['name'] : '';
			$api_url           = isset($asset['url']) ? (string) $asset['url'] : '';
			$browser_url       = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';
			$preferred_zip_url = $has_token ? $api_url : $browser_url;
			$fallback_zip_url  = $has_token ? $browser_url : $api_url;

			if (str_ends_with(strtolower($name), '.zip')) {
				if ('' !== $preferred_zip_url) {
					return $preferred_zip_url;
				}

				if ('' !== $fallback_zip_url) {
					return $fallback_zip_url;
				}
			}
		}

		// Fallback to zipball.
		return isset($release['zipball_url']) ? (string) $release['zipball_url'] : '';
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
	 * Build a compact multisite summary for installed plugin activations.
	 *
	 * @return array{active_counts: array<string, int>, total_sites: int}
	 */
	private function get_multisite_activation_summary(): array {
		if (! is_multisite()) {
			return array(
				'active_counts' => array(),
				'total_sites'   => 1,
			);
		}

		$cached = GPW_Cache_Manager::get(self::SITES_SUMMARY_CACHE_KEY);
		if (is_array($cached)) {
			$cached['active_counts'] = isset($cached['active_counts']) && is_array($cached['active_counts'])
				? $cached['active_counts']
				: array();
			$cached['total_sites'] = isset($cached['total_sites']) ? (int) $cached['total_sites'] : 0;
			return $cached;
		}

		$active_counts = array();
		$site_ids      = $this->get_network_site_ids();

		foreach ($site_ids as $site_id) {
			$active_plugins = get_blog_option((int) $site_id, 'active_plugins', array());
			if (! is_array($active_plugins)) {
				continue;
			}

			foreach ($active_plugins as $plugin_file) {
				if (! is_string($plugin_file) || '' === $plugin_file) {
					continue;
				}

				if (! isset($active_counts[$plugin_file])) {
					$active_counts[$plugin_file] = 0;
				}

				++$active_counts[$plugin_file];
			}
		}

		$summary = array(
			'active_counts' => $active_counts,
			'total_sites'   => count($site_ids),
		);

		GPW_Cache_Manager::set(self::SITES_SUMMARY_CACHE_KEY, $summary, self::SITES_SUMMARY_CACHE_TTL);

		return $summary;
	}

	/**
	 * Get filtered network site IDs.
	 *
	 * @return array<int, int>
	 */
	private function get_network_site_ids(): array {
		$site_ids = get_sites(array(
			'fields'   => 'ids',
			'number'   => 0,
			'deleted'  => 0,
			'spam'     => 0,
			'archived' => 0,
		));

		if (! is_array($site_ids)) {
			return array();
		}

		return array_map('intval', $site_ids);
	}

	/**
	 * Build the compact sites summary label used in the main table.
	 *
	 * @param bool $is_installed      Whether the plugin is installed.
	 * @param bool $is_network_active Whether the plugin is network active.
	 * @param int  $active_site_count Number of active sites.
	 * @param int  $total_site_count  Number of sites in the network.
	 *
	 * @return string
	 */
	private function get_sites_summary_label(bool $is_installed, bool $is_network_active, int $active_site_count, int $total_site_count): string {
		if (! is_multisite()) {
			return '';
		}

		if (! $is_installed) {
			return '—';
		}

		if ($is_network_active) {
			return sprintf(
				/* translators: %d: number of sites in the network */
				_n('Network-wide (%d site)', 'Network-wide (%d sites)', max(1, $total_site_count), 'git-plugins-wordpress'),
				max(1, $total_site_count)
			);
		}

		return sprintf(
			/* translators: %d: number of sites with the plugin active */
			_n('%d site', '%d sites', max(0, $active_site_count), 'git-plugins-wordpress'),
			max(0, $active_site_count)
		);
	}
}

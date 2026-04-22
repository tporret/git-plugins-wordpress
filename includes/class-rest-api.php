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
	 * Managed plugin registry.
	 *
	 * @var GPW_Managed_Plugin_Registry
	 */
	private GPW_Managed_Plugin_Registry $registry;

	/**
	 * Shared plugin deployment service.
	 *
	 * @var GPW_Plugin_Deployment_Service
	 */
	private GPW_Plugin_Deployment_Service $deployment_service;

	/**
	 * Release channel manager.
	 *
	 * @var GPW_Channel_Manager
	 */
	private GPW_Channel_Manager $channel_manager;

	/**
	 * Constructor.
	 *
	 * @param GPW_GitHub_API                $github_api          GitHub API wrapper.
	 * @param GPW_Managed_Plugin_Registry   $registry            Managed plugin registry.
	 * @param GPW_Plugin_Deployment_Service $deployment_service  Shared deployment service.
	 * @param GPW_Channel_Manager           $channel_manager     Release channel manager.
	 */
	public function __construct(GPW_GitHub_API $github_api, GPW_Managed_Plugin_Registry $registry, GPW_Plugin_Deployment_Service $deployment_service, GPW_Channel_Manager $channel_manager) {
		$this->github_api         = $github_api;
		$this->registry           = $registry;
		$this->deployment_service = $deployment_service;
		$this->channel_manager    = $channel_manager;
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
				'args'                => array(
					'sources' => array(
						'required'          => true,
						'validate_callback' => array($this, 'validate_sources_param'),
					),
				),
			),
		));

		register_rest_route(self::NAMESPACE, '/channels', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array($this, 'get_channels'),
				'permission_callback' => array($this, 'check_admin_permission'),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'save_channels'),
				'permission_callback' => array($this, 'check_admin_permission'),
				'args'                => array(
					'default_channel' => array(
						'validate_callback' => array($this, 'validate_channel_param'),
					),
				),
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
			'args'                => array(
				'plugin_file' => array(
					'required'          => true,
					'validate_callback' => array($this, 'validate_plugin_file_param'),
				),
				'page' => array(
					'validate_callback' => array($this, 'validate_page_param'),
				),
				'per_page' => array(
					'validate_callback' => array($this, 'validate_per_page_param'),
				),
			),
		));

		register_rest_route(self::NAMESPACE, '/plugins/toggle', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'toggle_plugin'),
			'permission_callback' => array($this, 'check_admin_permission'),
			'args'                => array(
				'full_name' => array(
					'required'          => true,
					'validate_callback' => array($this, 'validate_repo_full_name_param'),
				),
			),
		));

		register_rest_route(self::NAMESPACE, '/plugins/install', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'install_plugin'),
			'permission_callback' => array($this, 'check_admin_permission'),
			'args'                => array(
				'full_name' => array(
					'required'          => true,
					'validate_callback' => array($this, 'validate_repo_full_name_param'),
				),
				'channel' => array(
					'validate_callback' => array($this, 'validate_channel_param'),
				),
			),
		));

		register_rest_route(self::NAMESPACE, '/plugins/uninstall', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'uninstall_plugin'),
			'permission_callback' => array($this, 'check_admin_permission'),
			'args'                => array(
				'full_name' => array(
					'required'          => true,
					'validate_callback' => array($this, 'validate_repo_full_name_param'),
				),
				'plugin_file' => array(
					'required'          => true,
					'validate_callback' => array($this, 'validate_plugin_file_param'),
				),
			),
		));

		register_rest_route(self::NAMESPACE, '/plugins/update', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'update_plugin'),
			'permission_callback' => array($this, 'check_admin_permission'),
			'args'                => array(
				'full_name' => array(
					'required'          => true,
					'validate_callback' => array($this, 'validate_repo_full_name_param'),
				),
				'plugin_file' => array(
					'required'          => true,
					'validate_callback' => array($this, 'validate_plugin_file_param'),
				),
				'channel' => array(
					'validate_callback' => array($this, 'validate_channel_param'),
				),
			),
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
					$decrypted_pat = GPW_Encryption::decrypt_or_error($pat);
					$pat           = is_wp_error($decrypted_pat) ? '' : $decrypted_pat;
				}
				if ('' !== $pat && ! GPW_GitHub_API::is_supported_auth_token($pat)) {
					$pat = '';
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
				if (! GPW_GitHub_API::is_supported_auth_token($pat)) {
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
	 * GET /channels — Fetch default and explicit plugin channels.
	 *
	 * @return WP_REST_Response
	 */
	public function get_channels(): WP_REST_Response {
		$channels = array();
		foreach ($this->channel_manager->get_saved_plugin_channels() as $repo_full_name => $channel) {
			$channels[] = array(
				'full_name' => $repo_full_name,
				'channel'   => $channel,
			);
		}

		return new WP_REST_Response(array(
			'default_channel' => $this->channel_manager->get_default_channel(),
			'plugins'         => $channels,
		), 200);
	}

	/**
	 * POST /channels — Save default and per-plugin channel selections.
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function save_channels(WP_REST_Request $request): WP_REST_Response {
		$default_channel = $request->get_param('default_channel');
		if (is_string($default_channel)) {
			$this->channel_manager->set_default_channel($default_channel);
		}

		$effective_default = $this->channel_manager->get_default_channel();
		$plugins           = $request->get_param('plugins');
		if (is_array($plugins)) {
			foreach ($plugins as $plugin) {
				if (! is_array($plugin)) {
					continue;
				}

				$repo_full_name = isset($plugin['full_name']) ? sanitize_text_field((string) $plugin['full_name']) : '';
				$channel        = isset($plugin['channel']) ? (string) $plugin['channel'] : '';

				if ('' === $repo_full_name) {
					continue;
				}

				$channel = $this->channel_manager->normalize_channel($channel);
				if ($channel === $effective_default) {
					$this->channel_manager->delete_plugin_channel($repo_full_name);
					continue;
				}

				$this->channel_manager->set_plugin_channel($repo_full_name, $channel);
			}
		}

		$this->github_api->flush_cache();

		return new WP_REST_Response(array('message' => __('Release channels saved.', 'git-plugins-wordpress')), 200);
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

		$managed_plugins     = $this->registry->get_all();
		$sites_summary       = $this->get_multisite_activation_summary();
		$site_activation_map = isset($sites_summary['active_counts']) && is_array($sites_summary['active_counts'])
			? $sites_summary['active_counts']
			: array();
		$total_site_count   = isset($sites_summary['total_sites']) ? (int) $sites_summary['total_sites'] : 0;
		$result             = array();

		foreach ($repositories as $repository) {
			$repo_name      = isset($repository['name']) ? sanitize_text_field((string) $repository['name']) : '';
			$repo_full_name = isset($repository['full_name']) ? sanitize_text_field((string) $repository['full_name']) : '';
			$description    = isset($repository['description']) ? sanitize_text_field((string) $repository['description']) : '';
			$managed_record = $managed_plugins[$repo_full_name] ?? null;
			$verification   = '' !== $repo_full_name ? $this->registry->get_verification($repo_full_name) : array(
				'status'          => GPW_Managed_Plugin_Registry::VERIFICATION_UNKNOWN,
				'algorithm'       => '',
				'verified_at'     => '',
				'release_version' => '',
				'checksum'        => '',
			);

			$release = '' !== $repo_full_name
				? $this->github_api->get_latest_release($repo_full_name, false, $this->channel_manager->get_plugin_channel($repo_full_name))
				: new WP_Error('gpw_missing_repo_name', 'Missing');
			$channel = '' !== $repo_full_name ? $this->channel_manager->get_plugin_channel($repo_full_name) : GPW_Channel_Manager::CHANNEL_STABLE;

			$version = is_wp_error($release)
				? ''
				: (isset($release['tag_name']) ? sanitize_text_field((string) $release['tag_name']) : '');

			$plugin_file       = is_array($managed_record) ? (string) ($managed_record['plugin_file'] ?? '') : '';
			if ('' !== $plugin_file && ! array_key_exists($plugin_file, $installed_plugins)) {
				$plugin_file = '';
			}
			if ('' === $plugin_file) {
				$plugin_file = $this->registry->find_plugin_file_by_repo_name($repo_name, $installed_plugins);
			}
			$is_installed      = '' !== $plugin_file;
			$is_tracked        = is_array($managed_record) && ! empty($managed_record['is_tracked']);
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
				'channel'           => $channel,
				'verification'      => $verification,
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

		$new_state = ! $this->registry->is_tracked($repo_full_name);
		$this->registry->set_tracked($repo_full_name, $new_state);

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
		$channel        = $this->channel_manager->normalize_channel((string) $request->get_param('channel'));
		if ('' === $repo_full_name) {
			return new WP_REST_Response(
				array('message' => __('Repository name is required.', 'git-plugins-wordpress')),
				400
			);
		}

		if ('' === (string) $request->get_param('channel')) {
			$channel = $this->channel_manager->get_plugin_channel($repo_full_name);
		}

		$result = $this->deployment_service->install_repository($repo_full_name, $channel);
		if (is_wp_error($result)) {
			return new WP_REST_Response(array('message' => $result->get_error_message()), 422);
		}

		$this->channel_manager->set_plugin_channel($repo_full_name, $channel);

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
		if ('' === $plugin_file) {
			$repo_parts   = explode('/', $repo_full_name, 2);
			$repo_name    = isset($repo_parts[1]) ? sanitize_text_field((string) $repo_parts[1]) : '';
			$plugin_file  = $this->registry->get_plugin_file($repo_full_name);
			if ('' === $plugin_file || ! array_key_exists($plugin_file, $installed_plugins)) {
				$plugin_file = $this->registry->find_plugin_file_by_repo_name($repo_name, $installed_plugins);
			}
		}

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

		$this->registry->remove($repo_full_name);
		$this->channel_manager->delete_plugin_channel($repo_full_name);

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
		$channel        = $this->channel_manager->normalize_channel((string) $request->get_param('channel'));

		if ('' === $repo_full_name || '' === $plugin_file) {
			return new WP_REST_Response(
				array('message' => __('Required parameters are missing.', 'git-plugins-wordpress')),
				400
			);
		}

		if ('' === (string) $request->get_param('channel')) {
			$channel = $this->channel_manager->get_plugin_channel($repo_full_name);
		}

		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed_plugins = get_plugins();
		if ('' === $plugin_file) {
			$repo_parts   = explode('/', $repo_full_name, 2);
			$repo_name    = isset($repo_parts[1]) ? sanitize_text_field((string) $repo_parts[1]) : '';
			$plugin_file  = $this->registry->get_plugin_file($repo_full_name);
			if ('' === $plugin_file || ! array_key_exists($plugin_file, $installed_plugins)) {
				$plugin_file = $this->registry->find_plugin_file_by_repo_name($repo_name, $installed_plugins);
			}
		}

		if (! array_key_exists($plugin_file, $installed_plugins)) {
			return new WP_REST_Response(
				array('message' => __('Plugin is not installed.', 'git-plugins-wordpress')),
				404
			);
		}

		$result = $this->deployment_service->update_repository($repo_full_name, $plugin_file, $channel);
		if (is_wp_error($result)) {
			return new WP_REST_Response(array('message' => $result->get_error_message()), 422);
		}

		$this->channel_manager->set_plugin_channel($repo_full_name, $channel);

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
	 * Validate the sources payload for the settings endpoint.
	 *
	 * @param mixed           $value   Request parameter value.
	 * @param WP_REST_Request $request Request object.
	 * @param string          $param   Parameter name.
	 *
	 * @return bool|WP_Error
	 */
	public function validate_sources_param($value, WP_REST_Request $request, string $param) {
		unset($request, $param);

		if (is_array($value)) {
			return true;
		}

		return new WP_Error('rest_invalid_param', __('Sources must be an array.', 'git-plugins-wordpress'), array('status' => 400));
	}

	/**
	 * Validate repository full-name request parameters.
	 *
	 * @param mixed           $value   Request parameter value.
	 * @param WP_REST_Request $request Request object.
	 * @param string          $param   Parameter name.
	 *
	 * @return bool|WP_Error
	 */
	public function validate_repo_full_name_param($value, WP_REST_Request $request, string $param) {
		unset($request);

		if (is_string($value) && '' !== sanitize_text_field($value) && str_contains($value, '/')) {
			return true;
		}

		return new WP_Error('rest_invalid_param', sprintf(__('Parameter "%s" must be in owner/repo format.', 'git-plugins-wordpress'), $param), array('status' => 400));
	}

	/**
	 * Validate plugin file request parameters.
	 *
	 * @param mixed           $value   Request parameter value.
	 * @param WP_REST_Request $request Request object.
	 * @param string          $param   Parameter name.
	 *
	 * @return bool|WP_Error
	 */
	public function validate_plugin_file_param($value, WP_REST_Request $request, string $param) {
		unset($request);

		if (is_string($value) && '' !== sanitize_text_field($value)) {
			return true;
		}

		return new WP_Error('rest_invalid_param', sprintf(__('Parameter "%s" is required.', 'git-plugins-wordpress'), $param), array('status' => 400));
	}

	/**
	 * Validate release-channel request parameters.
	 *
	 * @param mixed           $value   Request parameter value.
	 * @param WP_REST_Request $request Request object.
	 * @param string          $param   Parameter name.
	 *
	 * @return bool|WP_Error
	 */
	public function validate_channel_param($value, WP_REST_Request $request, string $param) {
		unset($request, $param);

		if (null === $value || '' === $value) {
			return true;
		}

		if (is_string($value) && $this->channel_manager->normalize_channel($value) === strtolower(trim($value))) {
			return true;
		}

		return new WP_Error('rest_invalid_param', __('Channel must be stable or pre-release.', 'git-plugins-wordpress'), array('status' => 400));
	}

	/**
	 * Validate page request parameters.
	 *
	 * @param mixed           $value   Request parameter value.
	 * @param WP_REST_Request $request Request object.
	 * @param string          $param   Parameter name.
	 *
	 * @return bool|WP_Error
	 */
	public function validate_page_param($value, WP_REST_Request $request, string $param) {
		unset($request);

		if (null === $value || (is_numeric($value) && (int) $value >= 1)) {
			return true;
		}

		return new WP_Error('rest_invalid_param', sprintf(__('Parameter "%s" must be at least 1.', 'git-plugins-wordpress'), $param), array('status' => 400));
	}

	/**
	 * Validate per-page request parameters.
	 *
	 * @param mixed           $value   Request parameter value.
	 * @param WP_REST_Request $request Request object.
	 * @param string          $param   Parameter name.
	 *
	 * @return bool|WP_Error
	 */
	public function validate_per_page_param($value, WP_REST_Request $request, string $param) {
		unset($request);

		if (null === $value || (is_numeric($value) && (int) $value >= 1 && (int) $value <= 100)) {
			return true;
		}

		return new WP_Error('rest_invalid_param', sprintf(__('Parameter "%s" must be between 1 and 100.', 'git-plugins-wordpress'), $param), array('status' => 400));
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

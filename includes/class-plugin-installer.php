<?php
declare(strict_types=1);
/**
 * Plugin installation handler for Git Repos Manager.
 *
 * @package GitPluginsWordPress
 */

defined('ABSPATH') || exit;

/**
 * Handles secure plugin installs from GitHub release assets.
 */
final class GPW_Plugin_Installer {
	/**
	 * Redirect page slug after actions.
	 */
	private const REDIRECT_PAGE = 'gpw-settings';

	/**
	 * Shared plugin deployment service.
	 *
	 * @var GPW_Plugin_Deployment_Service
	 */
	private GPW_Plugin_Deployment_Service $deployment_service;

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
	 * @param GPW_Plugin_Deployment_Service $deployment_service Shared deployment service.
	 * @param GPW_Managed_Plugin_Registry   $registry           Managed plugin registry.
	 * @param GPW_Channel_Manager           $channel_manager    Release channel manager.
	 */
	public function __construct(GPW_Plugin_Deployment_Service $deployment_service, GPW_Managed_Plugin_Registry $registry, GPW_Channel_Manager $channel_manager) {
		$this->deployment_service = $deployment_service;
		$this->registry           = $registry;
		$this->channel_manager    = $channel_manager;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action('admin_post_gpw_install_repo', array($this, 'handle_install_repo'));
		add_action('admin_post_gpw_uninstall_repo', array($this, 'handle_uninstall_repo'));
	}

	/**
	 * Install plugin from latest release zip.
	 *
	 * @return void
	 */
	public function handle_install_repo(): void {
		if (! GPW_Context::current_user_can_install_plugins()) {
			wp_die(esc_html__('You are not allowed to install plugins.', 'git-plugins-wordpress'));
		}

		$repo_full_name = isset($_GET['repo_full_name']) ? sanitize_text_field(wp_unslash((string) $_GET['repo_full_name'])) : '';
		if ('' === $repo_full_name) {
			$this->redirect_with_error(__('Repository identifier is missing.', 'git-plugins-wordpress'));
		}

		check_admin_referer('gpw_install_repo_' . $repo_full_name, 'gpw_install_nonce');

		$channel = $this->channel_manager->get_plugin_channel($repo_full_name);
		$result  = $this->deployment_service->install_repository($repo_full_name, $channel);
		if (is_wp_error($result)) {
			$this->redirect_with_error($result->get_error_message());
		}

		$this->channel_manager->set_plugin_channel($repo_full_name, $channel);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::REDIRECT_PAGE,
					'gpw_notice' => 'install-success',
				),
				$this->get_redirect_base_url()
			)
		);
		exit;
	}

	/**
	 * Uninstall a plugin managed by a GitHub repository.
	 *
	 * @return void
	 */
	public function handle_uninstall_repo(): void {
		if (! GPW_Context::current_user_can_delete_plugins()) {
			wp_die(esc_html__('You are not allowed to delete plugins.', 'git-plugins-wordpress'));
		}

		$repo_full_name = isset($_GET['repo_full_name']) ? sanitize_text_field(wp_unslash((string) $_GET['repo_full_name'])) : '';
		$plugin_file    = isset($_GET['plugin_file']) ? sanitize_text_field(wp_unslash((string) $_GET['plugin_file'])) : '';

		if ('' === $repo_full_name || '' === $plugin_file) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => self::REDIRECT_PAGE,
						'gpw_notice' => 'uninstall-failed',
						'message'    => sanitize_text_field(__('Required parameters are missing.', 'git-plugins-wordpress')),
					),
					$this->get_redirect_base_url()
				)
			);
			exit;
		}

		check_admin_referer('gpw_uninstall_repo_' . $repo_full_name, 'gpw_uninstall_nonce');

		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed_plugins = get_plugins();
		if ('' === $plugin_file) {
			$repo_parts   = explode('/', $repo_full_name, 2);
			$repo_name    = isset($repo_parts[1]) ? (string) $repo_parts[1] : '';
			$plugin_file  = $this->registry->get_plugin_file($repo_full_name);

			if ('' === $plugin_file || ! array_key_exists($plugin_file, $installed_plugins)) {
				$plugin_file = $this->registry->find_plugin_file_by_repo_name($repo_name, $installed_plugins);
			}
		}

		if (! array_key_exists($plugin_file, $installed_plugins)) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => self::REDIRECT_PAGE,
						'gpw_notice' => 'uninstall-failed',
						'message'    => sanitize_text_field(__('Plugin is not installed.', 'git-plugins-wordpress')),
					),
					$this->get_redirect_base_url()
				)
			);
			exit;
		}

		$is_network_active = is_multisite() && is_plugin_active_for_network($plugin_file);
		if ($is_network_active || is_plugin_active($plugin_file)) {
			deactivate_plugins($plugin_file, true, $is_network_active);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$deleted = delete_plugins(array($plugin_file));
		if (is_wp_error($deleted)) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => self::REDIRECT_PAGE,
						'gpw_notice' => 'uninstall-failed',
						'message'    => sanitize_text_field($deleted->get_error_message()),
					),
					$this->get_redirect_base_url()
				)
			);
			exit;
		}

		$this->registry->remove($repo_full_name);
		$this->channel_manager->delete_plugin_channel($repo_full_name);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::REDIRECT_PAGE,
					'gpw_notice' => 'uninstall-success',
				),
				$this->get_redirect_base_url()
			)
		);
		exit;
	}

	/**
	 * Redirect back to available plugins page with an error message.
	 *
	 * @param string $message Error message.
	 *
	 * @return void
	 */
	private function redirect_with_error(string $message): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::REDIRECT_PAGE,
					'gpw_notice' => 'install-failed',
					'message'    => sanitize_text_field($message),
				),
				$this->get_redirect_base_url()
			)
		);
		exit;
	}

	/**
	 * Get the correct admin base URL for redirects.
	 *
	 * @return string
	 */
	private function get_redirect_base_url(): string {
		return GPW_Context::uses_network_scope() ? network_admin_url('admin.php') : admin_url('admin.php');
	}
}

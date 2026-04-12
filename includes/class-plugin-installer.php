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
	 * Option key for active repositories.
	 */
	private const ACTIVE_REPOS_OPTION = 'gpw_active_repos';

	/**
	 * Redirect page slug after actions.
	 */
	private const REDIRECT_PAGE = 'gpw-settings';

	/**
	 * GitHub API service.
	 *
	 * @var GPW_GitHub_API
	 */
	private GPW_GitHub_API $github_api;

	/**
	 * Optional PAT used for private asset download.
	 *
	 * @var string
	 */
	private string $download_token = '';

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

		$release = $this->github_api->get_latest_release($repo_full_name);
		if (is_wp_error($release)) {
			$this->redirect_with_error($release->get_error_message());
		}

		$download_url = $this->extract_zip_download_url($release, $repo_full_name);
		if ('' === $download_url) {
			$this->redirect_with_error(__('No .zip asset found in the latest GitHub release.', 'git-plugins-wordpress'));
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$this->download_token = $this->github_api->get_auth_token_for_repo($repo_full_name);
		add_filter('http_request_args', array($this, 'inject_github_auth_header'), 10, 2);

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader($skin);
		$result   = $upgrader->install($download_url);

		remove_filter('http_request_args', array($this, 'inject_github_auth_header'), 10);
		$this->download_token = '';

		if (is_wp_error($result)) {
			$this->redirect_with_error($result->get_error_message());
		}

		if (! $result) {
			$error_message = $this->get_upgrader_error_message($upgrader, $skin);
			if ('' !== $error_message) {
				$this->redirect_with_error($error_message);
			}

			$this->redirect_with_error(__('Plugin installation failed.', 'git-plugins-wordpress'));
		}

		$active_repos   = (array) GPW_Context::get_option(self::ACTIVE_REPOS_OPTION, array());
		$active_repos[] = $repo_full_name;
		GPW_Context::update_option(self::ACTIVE_REPOS_OPTION, array_values(array_unique($active_repos)), false);

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

		$active_repos = array_values(
			array_filter(
				(array) GPW_Context::get_option(self::ACTIVE_REPOS_OPTION, array()),
				static fn(mixed $r): bool => is_string($r) && $r !== $repo_full_name
			)
		);
		GPW_Context::update_option(self::ACTIVE_REPOS_OPTION, $active_repos, false);

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
	 * Inject Authorization header for GitHub private release asset downloads.
	 *
	 * @param array<string, mixed> $args Request args.
	 * @param string               $url  Request URL.
	 *
	 * @return array<string, mixed>
	 */
	public function inject_github_auth_header(array $args, string $url): array {
		if ('' === $this->download_token) {
			return $args;
		}

		$host = wp_parse_url($url, PHP_URL_HOST);
		if (! is_string($host)) {
			return $args;
		}

		$allowed_hosts = array('api.github.com', 'github.com', 'objects.githubusercontent.com', 'codeload.github.com');
		if (! in_array(strtolower($host), $allowed_hosts, true)) {
			return $args;
		}

		$is_api_asset_download = 'api.github.com' === strtolower($host) && str_contains($url, '/releases/assets/');
		$is_github_binary_host = in_array(strtolower($host), array('github.com', 'objects.githubusercontent.com', 'codeload.github.com'), true);
		if (! $is_api_asset_download && ! $is_github_binary_host) {
			return $args;
		}

		if (! isset($args['headers']) || ! is_array($args['headers'])) {
			$args['headers'] = array();
		}

		$args['headers']['Authorization'] = 'Bearer ' . $this->download_token;

		if ($is_api_asset_download) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}

		return $args;
	}

	/**
	 * Get first zip download URL from release assets.
	 *
	 * @param array<string, mixed> $release        Release data.
	 * @param string               $repo_full_name Repository full name (owner/repo).
	 *
	 * @return string
	 */
	private function extract_zip_download_url(array $release, string $repo_full_name): string {
		if (! isset($release['assets']) || ! is_array($release['assets'])) {
			return '';
		}

		$has_token = '' !== $this->github_api->get_auth_token_for_repo($repo_full_name);

		foreach ($release['assets'] as $asset) {
			if (! is_array($asset)) {
				continue;
			}

			$name             = isset($asset['name']) ? sanitize_file_name((string) $asset['name']) : '';
			$api_url          = isset($asset['url']) ? esc_url_raw((string) $asset['url']) : '';
			$browser_url      = isset($asset['browser_download_url']) ? esc_url_raw((string) $asset['browser_download_url']) : '';
			$preferred_zip_url = $has_token ? $api_url : $browser_url;
			$fallback_zip_url  = $has_token ? $browser_url : $api_url;

			if ('' === $name) {
				continue;
			}

			if (str_ends_with(strtolower($name), '.zip')) {
				if ('' !== $preferred_zip_url) {
					return $preferred_zip_url;
				}

				if ('' !== $fallback_zip_url) {
					return $fallback_zip_url;
				}
			}
		}

		return '';
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

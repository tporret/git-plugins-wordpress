<?php
declare(strict_types=1);
/**
 * Plugin installation handler for Git Plugins WordPress.
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
	private const REDIRECT_PAGE = 'gpw-available-plugins';

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
	}

	/**
	 * Install plugin from latest release zip.
	 *
	 * @return void
	 */
	public function handle_install_repo(): void {
		if (! current_user_can('install_plugins')) {
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

		$download_url = $this->extract_zip_download_url($release);
		if ('' === $download_url) {
			$this->redirect_with_error(__('No .zip asset found in the latest GitHub release.', 'git-plugins-wordpress'));
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$this->download_token = $this->github_api->get_auth_token();
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
			$error_message = $skin->get_error();
			if ($error_message instanceof WP_Error) {
				$this->redirect_with_error($error_message->get_error_message());
			}

			$this->redirect_with_error(__('Plugin installation failed.', 'git-plugins-wordpress'));
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::REDIRECT_PAGE,
					'gpw_notice' => 'install-success',
				),
				admin_url('admin.php')
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

		if (! isset($args['headers']) || ! is_array($args['headers'])) {
			$args['headers'] = array();
		}

		$args['headers']['Authorization'] = 'Bearer ' . $this->download_token;
		$args['headers']['Accept']        = 'application/octet-stream';

		return $args;
	}

	/**
	 * Get first zip download URL from release assets.
	 *
	 * @param array<string, mixed> $release Release data.
	 *
	 * @return string
	 */
	private function extract_zip_download_url(array $release): string {
		if (! isset($release['assets']) || ! is_array($release['assets'])) {
			return '';
		}

		foreach ($release['assets'] as $asset) {
			if (! is_array($asset)) {
				continue;
			}

			$name = isset($asset['name']) ? sanitize_file_name((string) $asset['name']) : '';
			$url  = isset($asset['url']) ? esc_url_raw((string) $asset['url']) : '';

			if ('' === $name || '' === $url) {
				continue;
			}

			if (str_ends_with(strtolower($name), '.zip')) {
				return $url;
			}
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
				admin_url('admin.php')
			)
		);
		exit;
	}
}

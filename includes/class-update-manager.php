<?php
declare(strict_types=1);
/**
 * Update manager for Git Plugins WordPress.
 *
 * @package GitPluginsWordPress
 */

defined('ABSPATH') || exit;

/**
 * Intercepts WordPress update checks for GitHub-managed plugins.
 */
final class GPW_Update_Manager {
	/**
	 * Option key containing active repositories.
	 */
	private const ACTIVE_REPOS_OPTION = 'gpw_active_repos';

	/**
	 * Changelog transient key prefix.
	 */
	private const CHANGELOG_CACHE_PREFIX = 'changelog_';

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
	 * Register hooks used by the update manager.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter('pre_set_site_transient_update_plugins', array($this, 'inject_plugin_updates'));
		add_filter('plugins_api', array($this, 'filter_plugins_api'), 20, 3);
		add_filter('http_request_args', array($this, 'inject_github_download_auth'), 10, 2);
	}

	/**
	 * Inject update data for active GitHub repositories into WordPress update transient.
	 *
	 * This method fails gracefully. If GitHub calls fail or data cannot be mapped,
	 * the existing transient is returned unchanged.
	 *
	 * @param stdClass|mixed $transient Plugin update transient.
	 *
	 * @return stdClass|mixed
	 */
	public function inject_plugin_updates($transient) {
		if (! is_object($transient)) {
			return $transient;
		}

		$active_repos = $this->get_active_repositories();
		if (empty($active_repos)) {
			return $transient;
		}

		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed_plugins = get_plugins();
		if (empty($installed_plugins)) {
			return $transient;
		}

		foreach ($active_repos as $repo_full_name) {
			$plugin_file = $this->map_repo_to_plugin_file($repo_full_name, $installed_plugins);
			if ('' === $plugin_file) {
				continue;
			}

			$plugin_abs_path = trailingslashit(WP_PLUGIN_DIR) . $plugin_file;
			if (! file_exists($plugin_abs_path)) {
				continue;
			}

			$plugin_data = get_plugin_data($plugin_abs_path, false, false);
			if (! is_array($plugin_data)) {
				continue;
			}

			$current_version = isset($plugin_data['Version']) ? sanitize_text_field((string) $plugin_data['Version']) : '';
			if ('' === $current_version) {
				continue;
			}

			$release = $this->github_api->get_latest_release($repo_full_name, false);
			if (is_wp_error($release) || ! is_array($release)) {
				continue;
			}

			$tag_name       = isset($release['tag_name']) ? sanitize_text_field((string) $release['tag_name']) : '';
			$github_version = ltrim($tag_name, "vV \t\n\r\0\x0B");
			if ('' === $github_version) {
				continue;
			}

			if (! version_compare($current_version, $github_version, '<')) {
				continue;
			}

			$package_url = $this->has_valid_zip_asset($release);
			if (! is_string($package_url) || '' === $package_url) {
				continue;
			}

			$plugin_slug = dirname($plugin_file);
			if ('.' === $plugin_slug || '' === $plugin_slug) {
				$plugin_slug = basename($plugin_file, '.php');
			}

			$repo_url   = isset($release['html_url']) ? esc_url_raw((string) $release['html_url']) : 'https://github.com/' . $repo_full_name;
			$update_obj = new stdClass();
			$update_obj->slug        = sanitize_title($plugin_slug);
			$update_obj->plugin      = $plugin_file;
			$update_obj->new_version = $github_version;
			$update_obj->url         = $repo_url;
			$update_obj->package     = $package_url;

			if (! isset($transient->response) || ! is_array($transient->response)) {
				$transient->response = array();
			}

			$transient->response[$plugin_file] = $update_obj;
		}

		return $transient;
	}

	/**
	 * Provide plugin details modal data for GitHub-managed plugins.
	 *
	 * @param false|object|array $result Original API result.
	 * @param string             $action API action.
	 * @param object             $args API args object.
	 *
	 * @return false|object|array
	 */
	public function filter_plugins_api($result, string $action, object $args) {
		if ('plugin_information' !== $action) {
			return $result;
		}

		if (! isset($args->slug) || ! is_string($args->slug)) {
			return false;
		}

		$slug       = sanitize_title((string) $args->slug);
		$active_map = $this->get_active_repo_slug_map();
		if (! isset($active_map[$slug])) {
			return false;
		}

		$repo_full_name = $active_map[$slug];
		$release        = $this->github_api->get_latest_release($repo_full_name, false);
		if (is_wp_error($release) || ! is_array($release)) {
			return false;
		}

		$zip_url = $this->has_valid_zip_asset($release);
		if (! is_string($zip_url) || '' === $zip_url) {
			return false;
		}

		$repo_data = $this->get_repository_by_full_name($repo_full_name);
		$version   = isset($release['tag_name']) ? ltrim(sanitize_text_field((string) $release['tag_name']), "vV \t\n\r\0\x0B") : '';
		$name      = is_array($repo_data) && isset($repo_data['name']) ? sanitize_text_field((string) $repo_data['name']) : $slug;
		$homepage  = is_array($repo_data) && isset($repo_data['html_url']) ? esc_url_raw((string) $repo_data['html_url']) : 'https://github.com/' . $repo_full_name;
		$author    = is_array($repo_data) && isset($repo_data['owner']) && is_array($repo_data['owner']) && isset($repo_data['owner']['login'])
			? sanitize_text_field((string) $repo_data['owner']['login'])
			: '';
		$requires = is_array($repo_data) && isset($repo_data['requires']) ? sanitize_text_field((string) $repo_data['requires']) : '6.0';
		$tested   = get_bloginfo('version');
		$description = is_array($repo_data) && isset($repo_data['description']) ? sanitize_text_field((string) $repo_data['description']) : '';

		$release_body = isset($release['body']) ? (string) $release['body'] : '';
		$changelog    = $this->get_changelog_html($repo_full_name, $release_body);

		$res = new stdClass();
		$res->name          = $name;
		$res->slug          = $slug;
		$res->version       = $version;
		$res->author        = '' !== $author ? '<a href="' . esc_url('https://github.com/' . rawurlencode($author)) . '">' . esc_html($author) . '</a>' : '';
		$res->homepage      = $homepage;
		$res->requires      = $requires;
		$res->tested        = sanitize_text_field((string) $tested);
		$res->download_link = $zip_url;
		$res->sections      = array(
			'description' => '' !== $description ? wp_kses_post(wpautop(esc_html($description))) : '<p>' . esc_html__('No description provided.', 'git-plugins-wordpress') . '</p>',
			'changelog' => $changelog,
		);

		return $res;
	}

	/**
	 * Inject authentication headers for GitHub package downloads.
	 *
	 * @param array<string, mixed> $args Request args.
	 * @param string               $url  Request URL.
	 *
	 * @return array<string, mixed>
	 */
	public function inject_github_download_auth(array $args, string $url): array {
		$token = $this->github_api->get_auth_token();
		if ('' === $token) {
			return $args;
		}

		$host = wp_parse_url($url, PHP_URL_HOST);
		if (! is_string($host)) {
			return $args;
		}

		$host          = strtolower($host);
		$is_github_api = 'api.github.com' === $host;
		$is_asset_url  = str_contains($url, '/releases/assets/') || str_contains($url, 'objects.githubusercontent.com') || str_contains($url, 'github.com') || str_contains($url, 'codeload.github.com');
		if (! $is_github_api && ! $is_asset_url) {
			return $args;
		}

		if (! isset($args['headers']) || ! is_array($args['headers'])) {
			$args['headers'] = array();
		}

		$args['headers']['Authorization'] = 'Bearer ' . $token;
		$args['headers']['Accept']        = 'application/octet-stream';

		return $args;
	}

	/**
	 * Return active repository list from options.
	 *
	 * @return array<int, string>
	 */
	private function get_active_repositories(): array {
		$value = get_option(self::ACTIVE_REPOS_OPTION, array());
		if (! is_array($value)) {
			return array();
		}

		$repos = array();
		foreach ($value as $repo_full_name) {
			$repo = sanitize_text_field((string) $repo_full_name);
			if ('' !== $repo && str_contains($repo, '/')) {
				$repos[] = $repo;
			}
		}

		return array_values(array_unique($repos));
	}

	/**
	 * Build a slug => repo full-name map for active repositories.
	 *
	 * @return array<string, string>
	 */
	private function get_active_repo_slug_map(): array {
		$map = array();
		foreach ($this->get_active_repositories() as $repo_full_name) {
			$slug = $this->repo_slug_from_full_name($repo_full_name);
			if ('' !== $slug) {
				$map[$slug] = $repo_full_name;
			}
		}

		return $map;
	}

	/**
	 * Map a repository to the installed plugin file path.
	 *
	 * @param string                     $repo_full_name Repository full name.
	 * @param array<string, array<mixed>> $installed_plugins Installed plugins list.
	 *
	 * @return string
	 */
	private function map_repo_to_plugin_file(string $repo_full_name, array $installed_plugins): string {
		$repo_slug = strtolower($this->repo_slug_from_full_name($repo_full_name));
		if ('' === $repo_slug) {
			return '';
		}

		$direct_match = $repo_slug . '/' . $repo_slug . '.php';
		if (isset($installed_plugins[$direct_match])) {
			return $direct_match;
		}

		foreach ($installed_plugins as $plugin_file => $plugin_meta) {
			$dir = strtolower((string) dirname($plugin_file));
			if ($repo_slug === $dir) {
				return (string) $plugin_file;
			}

			$plugin_name = isset($plugin_meta['Name']) ? sanitize_title((string) $plugin_meta['Name']) : '';
			if ('' !== $plugin_name && $plugin_name === $repo_slug) {
				return (string) $plugin_file;
			}
		}

		return '';
	}

	/**
	 * Find a repository object in cached repository list.
	 *
	 * @param string $repo_full_name Repository full name.
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_repository_by_full_name(string $repo_full_name): ?array {
		$repositories = $this->github_api->get_repositories();
		if (is_wp_error($repositories) || ! is_array($repositories)) {
			return null;
		}

		foreach ($repositories as $repository) {
			if (! is_array($repository)) {
				continue;
			}

			$full_name = isset($repository['full_name']) ? sanitize_text_field((string) $repository['full_name']) : '';
			if ($repo_full_name === $full_name) {
				return $repository;
			}
		}

		return null;
	}

	/**
	 * Get changelog HTML from GitHub Markdown API with cache.
	 *
	 * If GitHub markdown parsing fails, it gracefully falls back to escaped plain text.
	 *
	 * @param string $repo_full_name Repository full name.
	 * @param string $markdown_body Release notes markdown.
	 *
	 * @return string
	 */
	private function get_changelog_html(string $repo_full_name, string $markdown_body): string {
		if ('' === trim($markdown_body)) {
			return '<p>' . esc_html__('No changelog provided.', 'git-plugins-wordpress') . '</p>';
		}

		$cache_key = self::CHANGELOG_CACHE_PREFIX . md5(strtolower($repo_full_name . '|' . $markdown_body));
		$cached    = GPW_Cache_Manager::get($cache_key);
		if (is_string($cached) && '' !== $cached) {
			return $cached;
		}

		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'Content-Type'         => 'application/json',
			'User-Agent'           => 'git-plugins-wordpress',
			'X-GitHub-Api-Version' => '2022-11-28',
		);

		$token = $this->github_api->get_auth_token();
		if ('' !== $token) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_post(
			'https://api.github.com/markdown',
			array(
				'headers' => $headers,
				'timeout' => 20,
				'body'    => wp_json_encode(
					array(
						'text'    => $markdown_body,
						'mode'    => 'gfm',
						'context' => $repo_full_name,
					)
				),
			)
		);

		if (is_wp_error($response)) {
			$fallback = wp_kses_post(wpautop(esc_html($markdown_body)));
			GPW_Cache_Manager::set($cache_key, $fallback, 12 * HOUR_IN_SECONDS);
			return $fallback;
		}

		$status_code = (int) wp_remote_retrieve_response_code($response);
		$body        = (string) wp_remote_retrieve_body($response);

		if (200 !== $status_code || '' === $body) {
			$fallback = wp_kses_post(wpautop(esc_html($markdown_body)));
			GPW_Cache_Manager::set($cache_key, $fallback, 12 * HOUR_IN_SECONDS);
			return $fallback;
		}

		$changelog_html = wp_kses_post($body);
		GPW_Cache_Manager::set($cache_key, $changelog_html, 12 * HOUR_IN_SECONDS);

		return $changelog_html;
	}

	/**
	 * Return repo slug from owner/repo full name.
	 *
	 * @param string $repo_full_name Repository full name.
	 *
	 * @return string
	 */
	private function repo_slug_from_full_name(string $repo_full_name): string {
		$parts = explode('/', $repo_full_name);
		if (count($parts) < 2) {
			return '';
		}

		return sanitize_title((string) end($parts));
	}

	/**
	 * Validate release assets and return installable zip API URL.
	 *
	 * @param array<string, mixed> $release_data Release payload.
	 *
	 * @return string|bool
	 */
	private function has_valid_zip_asset(array $release_data): string|bool {
		if (! isset($release_data['assets']) || ! is_array($release_data['assets'])) {
			error_log('Git Plugins WordPress: release has no assets array.');
			return false;
		}

		foreach ($release_data['assets'] as $asset) {
			if (! is_array($asset)) {
				continue;
			}

			$asset_name = isset($asset['name']) ? sanitize_file_name((string) $asset['name']) : '';
			$content_type = isset($asset['content_type']) ? sanitize_text_field((string) $asset['content_type']) : '';
			$asset_url = isset($asset['url']) ? esc_url_raw((string) $asset['url']) : '';

			if ('application/zip' === strtolower($content_type) && '' !== $asset_name && str_ends_with(strtolower($asset_name), '.zip') && '' !== $asset_url) {
				return $asset_url;
			}
		}

		error_log('Git Plugins WordPress: no valid zip asset found in release payload.');
		return false;
	}

}

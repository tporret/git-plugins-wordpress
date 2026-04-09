<?php
declare(strict_types=1);
/**
 * GitHub API service wrapper.
 *
 * @package GitPluginsWordPress
 */

defined('ABSPATH') || exit;

/**
 * Handles GitHub repository API calls and response caching.
 */
final class GPW_GitHub_API {
	/**
	 * Saved plugin settings option key.
	 */
	private const OPTION_NAME = 'gpw_settings';

	/**
	 * Cache key for repository listing.
	 */
	private const REPOSITORY_CACHE_KEY = 'repositories';

	/**
	 * Prefix for latest release cache keys.
	 */
	private const RELEASE_CACHE_PREFIX = 'release_';

	/**
	 * Rate-limit lockout key.
	 */
	private const RATE_LIMIT_LOCKOUT_KEY = 'rate_limited';

	/**
	 * Last API error option key.
	 */
	private const LAST_ERROR_OPTION_KEY = 'gpw_last_api_error';

	/**
	 * Base URL for GitHub API.
	 */
	private const API_BASE = 'https://api.github.com';

	/**
	 * Whether the current request has already hit a rate limit.
	 *
	 * @var bool
	 */
	private bool $is_rate_limited_in_request = false;

	/**
	 * Fetch repositories for a configured user/organization and filter by wp-plugin topic.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function get_repositories() {
		if ($this->is_rate_limited()) {
			$message = __('GitHub API rate limit lockout is active. Try again later.', 'git-plugins-wordpress');
			$this->set_last_error($message);
			return new WP_Error('gpw_rate_limited', $message);
		}

		$cached = GPW_Cache_Manager::get(self::REPOSITORY_CACHE_KEY);
		if (is_array($cached)) {
			return $cached;
		}

		$settings = $this->get_settings();
		$target   = $settings['github_target'];
		$token    = $settings['github_pat'];

		if ('' === $target) {
			$message = __('Please set a GitHub target name in Git Plugins settings.', 'git-plugins-wordpress');
			$this->set_last_error($message);
			return new WP_Error('gpw_missing_target', $message);
		}

		$org_response = $this->request_repositories($target, 'orgs', $token);
		if (is_wp_error($org_response) && 'gpw_not_found' === $org_response->get_error_code()) {
			$user_response = $this->request_repositories($target, 'users', $token);
			if (is_wp_error($user_response)) {
				$this->set_last_error($user_response->get_error_message());
				return $user_response;
			}
			$repositories = $user_response;
		} elseif (is_wp_error($org_response)) {
			$this->set_last_error($org_response->get_error_message());
			return $org_response;
		} else {
			$repositories = $org_response;
		}

		$filtered_repositories = array_values(
			array_filter(
				$repositories,
				static function (array $repository): bool {
					$topics = isset($repository['topics']) && is_array($repository['topics']) ? $repository['topics'] : array();
					return in_array('wp-plugin', $topics, true);
				}
			)
		);

		GPW_Cache_Manager::set(self::REPOSITORY_CACHE_KEY, $filtered_repositories, 12 * HOUR_IN_SECONDS);
		$this->clear_last_error();

		return $filtered_repositories;
	}

	/**
	 * Delete repository cache transient.
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		GPW_Cache_Manager::flush_all();
		delete_option('gpw_release_cache_keys');
	}

	/**
	 * Get latest release for a repository.
	 *
	 * @param string $repo_full_name Repository full name (owner/repo).
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_latest_release(string $repo_full_name) {
		if ($this->is_rate_limited()) {
			$message = __('GitHub API rate limit lockout is active. Try again later.', 'git-plugins-wordpress');
			$this->set_last_error($message);
			return new WP_Error('gpw_rate_limited', $message);
		}

		$repo_full_name = sanitize_text_field($repo_full_name);
		if ('' === $repo_full_name || ! str_contains($repo_full_name, '/')) {
			$message = __('Invalid repository name.', 'git-plugins-wordpress');
			$this->set_last_error($message);
			return new WP_Error('gpw_invalid_repo_name', $message);
		}

		$cache_key = $this->get_release_cache_key($repo_full_name);
		$cached    = GPW_Cache_Manager::get($cache_key);

		if (is_array($cached)) {
			return $cached;
		}

		$settings = $this->get_settings();
		$headers  = array(
			'Accept'               => 'application/vnd.github+json',
			'User-Agent'           => 'git-plugins-wordpress',
			'X-GitHub-Api-Version' => '2022-11-28',
		);

		if ('' !== $settings['github_pat']) {
			$headers['Authorization'] = 'Bearer ' . $settings['github_pat'];
		}

		$url = sprintf('%1$s/repos/%2$s/releases/latest', self::API_BASE, $this->encode_repo_full_name($repo_full_name));

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $headers,
				'timeout' => 20,
			)
		);

		if (is_wp_error($response)) {
			$transport_error = sanitize_text_field($response->get_error_message());
			$message         = sprintf(
				/* translators: %s: HTTP transport error message. */
				__('Could not reach the GitHub API. Transport error: %s', 'git-plugins-wordpress'),
				$transport_error
			);
			$this->set_last_error($message);
			return new WP_Error('gpw_http_request_failed', $message);
		}

		$status_code = (int) wp_remote_retrieve_response_code($response);
		$body        = wp_remote_retrieve_body($response);
		$data        = json_decode($body, true);

		if (200 === $status_code && is_array($data)) {
			GPW_Cache_Manager::set($cache_key, $data, 12 * HOUR_IN_SECONDS);
			$this->clear_last_error();
			return $data;
		}

		$error_message = is_array($data) && isset($data['message']) ? (string) $data['message'] : __('GitHub API returned an unexpected response.', 'git-plugins-wordpress');

		if ($this->should_lockout_for_rate_limit($status_code, $response, $error_message)) {
			$message = __('GitHub API rate limit reached. Requests are paused for one hour.', 'git-plugins-wordpress');
			$this->set_last_error($message);
			return new WP_Error('gpw_rate_limited', $message);
		}

		if (401 === $status_code) {
			$message = __('GitHub authentication failed (401). Check your Personal Access Token.', 'git-plugins-wordpress');
			$this->set_last_error($message);
			return new WP_Error('gpw_unauthorized', $message);
		}

		if (404 === $status_code) {
			$message = __('Latest release not found (404). Ensure a published release exists.', 'git-plugins-wordpress');
			$this->set_last_error($message);
			return new WP_Error('gpw_release_not_found', $message);
		}

		$message = sprintf(
			/* translators: 1: HTTP status code, 2: API error message. */
			__('GitHub API error (%1$d): %2$s', 'git-plugins-wordpress'),
			$status_code,
			$error_message
		);
		$this->set_last_error($message);

		return new WP_Error('gpw_api_error', $message);
	}

	/**
	 * Get configured GitHub Personal Access Token.
	 *
	 * @return string
	 */
	public function get_auth_token(): string {
		$settings = $this->get_settings();
		return $settings['github_pat'];
	}

	/**
	 * Get and optionally clear the last API error.
	 *
	 * @param bool $clear Whether to clear the saved error after reading.
	 *
	 * @return string
	 */
	public function get_last_error(bool $clear = false): string {
		$error = (string) get_option(self::LAST_ERROR_OPTION_KEY, '');

		if ($clear) {
			$this->clear_last_error();
		}

		return $error;
	}

	/**
	 * Request repositories from GitHub.
	 *
	 * @param string $target GitHub username or organization.
	 * @param string $type API resource type, users or orgs.
	 * @param string $token GitHub token.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function request_repositories(string $target, string $type, string $token) {
		if ($this->is_rate_limited()) {
			return new WP_Error(
				'gpw_rate_limited',
				__('GitHub API rate limit lockout is active. Try again later.', 'git-plugins-wordpress')
			);
		}

		$url = sprintf(
			'%1$s/%2$s/%3$s/repos?per_page=100&type=all',
			self::API_BASE,
			rawurlencode($type),
			rawurlencode($target)
		);

		$headers = array(
			'Accept'               => 'application/vnd.github+json,application/vnd.github.mercy-preview+json',
			'User-Agent'           => 'git-plugins-wordpress',
			'X-GitHub-Api-Version' => '2022-11-28',
		);

		if ('' !== $token) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $headers,
				'timeout' => 20,
			)
		);

		if (is_wp_error($response)) {
			$transport_error = sanitize_text_field($response->get_error_message());
			return new WP_Error(
				'gpw_http_request_failed',
				sprintf(
					/* translators: %s: HTTP transport error message. */
					__('Could not reach the GitHub API. Transport error: %s', 'git-plugins-wordpress'),
					$transport_error
				)
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code($response);
		$body        = wp_remote_retrieve_body($response);
		$data        = json_decode($body, true);

		if (200 === $status_code && is_array($data)) {
			return $data;
		}

		$error_message = is_array($data) && isset($data['message']) ? (string) $data['message'] : __('GitHub API returned an unexpected response.', 'git-plugins-wordpress');

		if ($this->should_lockout_for_rate_limit($status_code, $response, $error_message)) {
			return new WP_Error(
				'gpw_rate_limited',
				__('GitHub API rate limit reached. Requests are paused for one hour.', 'git-plugins-wordpress')
			);
		}

		if (401 === $status_code) {
			return new WP_Error(
				'gpw_unauthorized',
				__('GitHub authentication failed (401). Check your Personal Access Token.', 'git-plugins-wordpress')
			);
		}

		if (404 === $status_code) {
			return new WP_Error(
				'gpw_not_found',
				__('GitHub user/organization not found (404). Verify the target name.', 'git-plugins-wordpress')
			);
		}

		return new WP_Error(
			'gpw_api_error',
			sprintf(
				/* translators: 1: HTTP status code, 2: API error message. */
				__('GitHub API error (%1$d): %2$s', 'git-plugins-wordpress'),
				$status_code,
				$error_message
			)
		);
	}

	/**
	 * Get settings with defaults.
	 *
	 * @return array<string, string>
	 */
	private function get_settings(): array {
		$settings = get_option(self::OPTION_NAME, array());
		if (! is_array($settings)) {
			$settings = array();
		}

		return array(
			'github_target' => isset($settings['github_target']) ? sanitize_text_field((string) $settings['github_target']) : '',
			'github_pat'    => isset($settings['github_pat']) ? sanitize_text_field((string) $settings['github_pat']) : '',
		);
	}

	/**
	 * Save latest API error message for admin notices.
	 *
	 * @param string $error Error message.
	 *
	 * @return void
	 */
	private function set_last_error(string $error): void {
		update_option(self::LAST_ERROR_OPTION_KEY, $error, false);
	}

	/**
	 * Clear stored API error.
	 *
	 * @return void
	 */
	private function clear_last_error(): void {
		delete_option(self::LAST_ERROR_OPTION_KEY);
	}

	/**
	 * Encode owner/repo value while keeping slash separator.
	 *
	 * @param string $repo_full_name Repository full name.
	 *
	 * @return string
	 */
	private function encode_repo_full_name(string $repo_full_name): string {
		$parts = array_map('rawurlencode', explode('/', $repo_full_name, 2));
		if (2 !== count($parts)) {
			return rawurlencode($repo_full_name);
		}

		return $parts[0] . '/' . $parts[1];
	}

	/**
	 * Build transient key for a repository latest release payload.
	 *
	 * @param string $repo_full_name Repository full name.
	 *
	 * @return string
	 */
	private function get_release_cache_key(string $repo_full_name): string {
		$normalized = strtolower(str_replace('/', '_', $repo_full_name));
		$normalized = preg_replace('/[^a-z0-9_]/', '_', $normalized);

		if (! is_string($normalized)) {
			$normalized = md5($repo_full_name);
		}

		$key = self::RELEASE_CACHE_PREFIX . $normalized;
		if (strlen('gpw_cache_' . $key) > 172) {
			$key = self::RELEASE_CACHE_PREFIX . md5($repo_full_name);
		}

		return $key;
	}

	/**
	 * Determine whether the API should be considered rate limited.
	 *
	 * @return bool
	 *
	 * @phpstan-return bool
	 */
	private function is_rate_limited(): bool {
		if ($this->is_rate_limited_in_request) {
			return true;
		}

		$lockout = GPW_Cache_Manager::get(self::RATE_LIMIT_LOCKOUT_KEY);
		if (false !== $lockout) {
			$this->is_rate_limited_in_request = true;
			return true;
		}

		return false;
	}

	/**
	 * Lock API access after a rate-limit response.
	 *
	 * @param int    $status_code   HTTP status code.
	 * @param array  $response      HTTP response array.
	 * @param string $error_message Response error message.
	 *
	 * @return bool
	 */
	private function should_lockout_for_rate_limit(int $status_code, array $response, string $error_message): bool {
		$remaining_raw = wp_remote_retrieve_header($response, 'x-ratelimit-remaining');
		$remaining     = is_string($remaining_raw) ? $remaining_raw : '';
		$is_rate_error = str_contains(strtolower($error_message), 'rate limit');

		if (429 === $status_code || (403 === $status_code && ('0' === $remaining || $is_rate_error))) {
			$this->is_rate_limited_in_request = true;
			GPW_Cache_Manager::set(self::RATE_LIMIT_LOCKOUT_KEY, 1, HOUR_IN_SECONDS);

			error_log('Git Plugins WordPress: GitHub API rate limited; lockout enabled for 1 hour.');
			return true;
		}

		return false;
	}
}

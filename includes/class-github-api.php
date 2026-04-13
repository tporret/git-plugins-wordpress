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
 *
 * Supports multiple GitHub sources (user/org + optional PAT).
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
	 * Stable release channel.
	 */
	private const CHANNEL_STABLE = GPW_Channel_Manager::CHANNEL_STABLE;

	/**
	 * Pre-release channel.
	 */
	private const CHANNEL_PRERELEASE = GPW_Channel_Manager::CHANNEL_PRERELEASE;

	/**
	 * Whether the current request has already hit a rate limit.
	 *
	 * @var bool
	 */
	private bool $is_rate_limited_in_request = false;

	/**
	 * Fetch repositories for all configured sources, filtered by wp-plugin topic.
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

		$sources = $this->get_sources();

		if (empty($sources)) {
			$message = __('Please add at least one GitHub source in Git Plugins settings.', 'git-plugins-wordpress');
			$this->set_last_error($message);
			return new WP_Error('gpw_missing_target', $message);
		}

		$all_repositories = array();
		$errors           = array();

		foreach ($sources as $source) {
			$target = $source['target'];
			$token  = $source['pat'];

			$org_response = $this->request_repositories($target, 'orgs', $token);
			if (is_wp_error($org_response) && 'gpw_not_found' === $org_response->get_error_code()) {
				$user_response = $this->request_repositories($target, 'users', $token);
				if (is_wp_error($user_response)) {
					$errors[] = sprintf('%s: %s', $target, $user_response->get_error_message());
					continue;
				}
				$repositories = $user_response;
			} elseif (is_wp_error($org_response)) {
				$errors[] = sprintf('%s: %s', $target, $org_response->get_error_message());
				continue;
			} else {
				$repositories = $org_response;
			}

			foreach ($repositories as $repo) {
				if (is_array($repo)) {
					$all_repositories[] = $repo;
				}
			}
		}

		if (empty($all_repositories) && ! empty($errors)) {
			$message = implode(' | ', $errors);
			$this->set_last_error($message);
			return new WP_Error('gpw_api_error', $message);
		}

		$filtered_repositories = array_values(
			array_filter(
				$all_repositories,
				static function (array $repository): bool {
					$topics = isset($repository['topics']) && is_array($repository['topics']) ? $repository['topics'] : array();
					return in_array('wp-plugin', $topics, true);
				}
			)
		);

		// Deduplicate by full_name (in case the same repo appears under multiple sources).
		$seen = array();
		$unique = array();
		foreach ($filtered_repositories as $repo) {
			$full_name = isset($repo['full_name']) ? (string) $repo['full_name'] : '';
			if ('' !== $full_name && ! isset($seen[$full_name])) {
				$seen[$full_name] = true;
				$unique[] = $repo;
			}
		}

		GPW_Cache_Manager::set(self::REPOSITORY_CACHE_KEY, $unique, 12 * HOUR_IN_SECONDS);
		$this->clear_last_error();

		return $unique;
	}

	/**
	 * Delete repository cache transient.
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		GPW_Cache_Manager::flush_all();
		GPW_Context::delete_option('gpw_release_cache_keys');
	}

	/**
	 * Get latest release for a repository.
	 *
	 * @param string $repo_full_name Repository full name (owner/repo).
	 * @param bool   $persist_error  Whether to store API errors for admin notices.
	 * @param string $channel        Release channel to resolve.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_latest_release(string $repo_full_name, bool $persist_error = true, string $channel = self::CHANNEL_STABLE) {
		if ($this->is_rate_limited()) {
			$message = __('GitHub API rate limit lockout is active. Try again later.', 'git-plugins-wordpress');
			if ($persist_error) {
				$this->set_last_error($message);
			}
			return new WP_Error('gpw_rate_limited', $message);
		}

		$repo_full_name = sanitize_text_field($repo_full_name);
		$channel        = $this->normalize_release_channel($channel);
		if ('' === $repo_full_name || ! str_contains($repo_full_name, '/')) {
			$message = __('Invalid repository name.', 'git-plugins-wordpress');
			if ($persist_error) {
				$this->set_last_error($message);
			}
			return new WP_Error('gpw_invalid_repo_name', $message);
		}

		$cache_key = $this->get_release_cache_key($repo_full_name, $channel);
		$cached    = GPW_Cache_Manager::get($cache_key);

		if (is_array($cached)) {
			return $cached;
		}

		$token   = $this->get_token_for_repo($repo_full_name);
		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'User-Agent'           => 'git-plugins-wordpress',
			'X-GitHub-Api-Version' => '2022-11-28',
		);

		if ('' !== $token) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		if (self::CHANNEL_PRERELEASE === $channel) {
			$release = $this->get_latest_release_from_list($repo_full_name, $headers, true);
			if (is_array($release)) {
				GPW_Cache_Manager::set($cache_key, $release, 12 * HOUR_IN_SECONDS);
				if ($persist_error) {
					$this->clear_last_error();
				}
				return $release;
			}

			if ($persist_error) {
				$this->set_last_error($release->get_error_message());
			}

			return $release;
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
			if ($persist_error) {
				$this->set_last_error($message);
			}
			return new WP_Error('gpw_http_request_failed', $message);
		}

		$status_code   = (int) wp_remote_retrieve_response_code($response);
		$body          = wp_remote_retrieve_body($response);
		$data          = json_decode($body, true);
		$error_message = is_array($data) && isset($data['message']) ? (string) $data['message'] : __('GitHub API returned an unexpected response.', 'git-plugins-wordpress');

		if (200 === $status_code && is_array($data)) {
			GPW_Cache_Manager::set($cache_key, $data, 12 * HOUR_IN_SECONDS);
			if ($persist_error) {
				$this->clear_last_error();
			}
			return $data;
		}

		if ($this->should_lockout_for_rate_limit($status_code, $response, $error_message)) {
			$message = __('GitHub API rate limit reached. Requests are paused for one hour.', 'git-plugins-wordpress');
			if ($persist_error) {
				$this->set_last_error($message);
			}
			return new WP_Error('gpw_rate_limited', $message);
		}

		if (401 === $status_code) {
			$message = __('GitHub authentication failed (401). Check your Personal Access Token.', 'git-plugins-wordpress');
			if ($persist_error) {
				$this->set_last_error($message);
			}
			return new WP_Error('gpw_unauthorized', $message);
		}

		if (404 === $status_code) {
			$fallback_release = $this->get_latest_release_from_list($repo_full_name, $headers, false);
			if (is_array($fallback_release)) {
				GPW_Cache_Manager::set($cache_key, $fallback_release, 12 * HOUR_IN_SECONDS);
				if ($persist_error) {
					$this->clear_last_error();
				}
				return $fallback_release;
			}

			if ($persist_error) {
				$this->set_last_error($fallback_release->get_error_message());
			}

			return $fallback_release;
		}

		$message = sprintf(
			/* translators: 1: HTTP status code, 2: API error message. */
			__('GitHub API error (%1$d): %2$s', 'git-plugins-wordpress'),
			$status_code,
			$error_message
		);
		if ($persist_error) {
			$this->set_last_error($message);
		}

		return new WP_Error('gpw_api_error', $message);
	}

	/**
	 * Fallback release lookup using the releases list endpoint.
	 *
	 * @param string               $repo_full_name     Repository full name (owner/repo).
	 * @param array<string, mixed> $headers            HTTP headers.
	 * @param bool                 $prefer_prerelease Whether prereleases should be preferred.
	 *
	 * @return array<string, mixed>|WP_Error|null
	 */
	private function get_latest_release_from_list(string $repo_full_name, array $headers, bool $prefer_prerelease) {
		$url = sprintf('%1$s/repos/%2$s/releases?per_page=10', self::API_BASE, $this->encode_repo_full_name($repo_full_name));

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
			$stable_fallback = null;

			foreach ($data as $release) {
				if (! is_array($release)) {
					continue;
				}

				$is_draft = isset($release['draft']) ? (bool) $release['draft'] : false;
				$is_prerelease = isset($release['prerelease']) ? (bool) $release['prerelease'] : false;
				if ($is_draft) {
					continue;
				}

				if ($prefer_prerelease) {
					if ($is_prerelease) {
						return $release;
					}

					if (null === $stable_fallback) {
						$stable_fallback = $release;
					}
					continue;
				}

				if (! $is_prerelease) {
					return $release;
				}
			}

			if ($prefer_prerelease && is_array($stable_fallback)) {
				return $stable_fallback;
			}

			return new WP_Error(
				'gpw_release_not_found',
				$prefer_prerelease
					? __('No published release or pre-release was found for this repository.', 'git-plugins-wordpress')
					: __('No published stable release was found for this repository.', 'git-plugins-wordpress')
			);
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
				'gpw_release_not_found',
				__('Latest release not found (404). Ensure the repository exists and is accessible. For private repositories, verify the PAT has repository read access.', 'git-plugins-wordpress')
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
	 * Normalize release channel values.
	 *
	 * @param string $channel Channel name.
	 *
	 * @return string
	 */
	private function normalize_release_channel(string $channel): string {
		$channel = strtolower(trim($channel));

		return self::CHANNEL_PRERELEASE === $channel ? self::CHANNEL_PRERELEASE : self::CHANNEL_STABLE;
	}

	/**
	 * Get configured GitHub Personal Access Token for a given repository.
	 *
	 * Matches the repo owner against configured sources to find the right PAT.
	 *
	 * @param string $repo_full_name Repository full name (owner/repo).
	 *
	 * @return string
	 */
	public function get_auth_token_for_repo(string $repo_full_name): string {
		return $this->get_token_for_repo($repo_full_name);
	}

	/**
	 * Get the first available auth token across all sources.
	 *
	 * @deprecated Use get_auth_token_for_repo() for per-repo token resolution.
	 *
	 * @return string
	 */
	public function get_auth_token(): string {
		$sources = $this->get_sources();
		foreach ($sources as $source) {
			if ('' !== $source['pat']) {
				return $source['pat'];
			}
		}

		return '';
	}

	/**
	 * Get and optionally clear the last API error.
	 *
	 * @param bool $clear Whether to clear the saved error after reading.
	 *
	 * @return string
	 */
	public function get_last_error(bool $clear = false): string {
		$error = (string) GPW_Context::get_option(self::LAST_ERROR_OPTION_KEY, '');

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
	 * Get configured sources from settings with backward-compatible migration.
	 *
	 * @return array<int, array{target: string, pat: string}>
	 */
	private function get_sources(): array {
		$settings = GPW_Context::get_option(self::OPTION_NAME, array());
		if (! is_array($settings)) {
			$settings = array();
		}

		if (isset($settings['sources']) && is_array($settings['sources'])) {
			$sources = array();
			foreach ($settings['sources'] as $source) {
				if (! is_array($source)) {
					continue;
				}
				$target = isset($source['target']) ? sanitize_text_field((string) $source['target']) : '';
				$pat    = isset($source['pat']) ? (string) $source['pat'] : '';
				if ('' !== $target) {
					// Decrypt PAT if encrypted.
					if ('' !== $pat && GPW_Encryption::is_encrypted($pat)) {
						$pat = GPW_Encryption::decrypt($pat);
					}
					$sources[] = array('target' => $target, 'pat' => $pat);
				}
			}
			return $sources;
		}

		return array();
	}

	/**
	 * Resolve the PAT token for a given repository by matching owner.
	 *
	 * @param string $repo_full_name Repository full name (owner/repo).
	 *
	 * @return string
	 */
	private function get_token_for_repo(string $repo_full_name): string {
		$parts = explode('/', $repo_full_name, 2);
		$owner = isset($parts[0]) ? strtolower(trim($parts[0])) : '';

		if ('' === $owner) {
			return '';
		}

		$sources = $this->get_sources();
		foreach ($sources as $source) {
			if (strtolower($source['target']) === $owner && '' !== $source['pat']) {
				return $source['pat'];
			}
		}

		return '';
	}

	/**
	 * Save latest API error message for admin notices.
	 *
	 * @param string $error Error message.
	 *
	 * @return void
	 */
	private function set_last_error(string $error): void {
		GPW_Context::update_option(self::LAST_ERROR_OPTION_KEY, $error, false);
	}

	/**
	 * Clear stored API error.
	 *
	 * @return void
	 */
	private function clear_last_error(): void {
		GPW_Context::delete_option(self::LAST_ERROR_OPTION_KEY);
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
	 * @param string $channel        Release channel.
	 *
	 * @return string
	 */
	private function get_release_cache_key(string $repo_full_name, string $channel): string {
		$normalized = strtolower(str_replace('/', '_', $repo_full_name));
		$normalized = preg_replace('/[^a-z0-9_]/', '_', $normalized);

		if (! is_string($normalized)) {
			$normalized = md5($channel . ':' . $repo_full_name);
		}

		$key = self::RELEASE_CACHE_PREFIX . $this->normalize_release_channel($channel) . '_' . $normalized;
		if (strlen('gpw_cache_' . $key) > 172) {
			$key = self::RELEASE_CACHE_PREFIX . md5($channel . ':' . $repo_full_name);
		}

		return $key;
	}

	/**
	 * Determine whether the API should be considered rate limited.
	 *
	 * @return bool
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
			return true;
		}

		return false;
	}
}

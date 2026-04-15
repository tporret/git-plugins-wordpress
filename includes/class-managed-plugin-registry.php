<?php
declare(strict_types=1);
/**
 * Managed plugin registry for Git Repos Manager.
 *
 * @package GitPluginsWordPress
 */

defined('ABSPATH') || exit;

/**
 * Stores tracked repositories and their installed plugin mapping.
 */
final class GPW_Managed_Plugin_Registry {
	/**
	 * Verification state when a managed plugin has not yet been checksum-verified.
	 */
	public const VERIFICATION_UNKNOWN = 'unknown';

	/**
	 * Verification state when a managed plugin package has been SHA-256 verified.
	 */
	public const VERIFICATION_VERIFIED = 'verified';

	/**
	 * Canonical managed plugin registry option key.
	 */
	private const OPTION_NAME = 'gpw_managed_plugins';

	/**
	 * Legacy tracked repositories option key.
	 */
	private const LEGACY_ACTIVE_REPOS_OPTION = 'gpw_active_repos';

	/**
	 * Get all managed plugin records.
	 *
	 * @return array<string, array{repo_full_name: string, plugin_file: string, is_tracked: bool, verification: array{status: string, algorithm: string, verified_at: string, release_version: string, checksum: string}}>
	 */
	public function get_all(): array {
		$records = GPW_Context::get_option(self::OPTION_NAME, array());
		if (! is_array($records)) {
			$records = array();
		}

		$normalized = array();
		foreach ($records as $key => $record) {
			if (! is_array($record)) {
				continue;
			}

			$repo_full_name = sanitize_text_field((string) ($record['repo_full_name'] ?? (is_string($key) ? $key : '')));
			if ('' === $repo_full_name) {
				continue;
			}

			$normalized[$repo_full_name] = array(
				'repo_full_name' => $repo_full_name,
				'plugin_file'    => sanitize_text_field((string) ($record['plugin_file'] ?? '')),
				'is_tracked'     => isset($record['is_tracked']) ? (bool) $record['is_tracked'] : true,
				'verification'   => $this->normalize_verification($record['verification'] ?? null),
			);
		}

		return $normalized;
	}

	/**
	 * Migrate legacy tracked repos from the old gpw_active_repos option.
	 *
	 * @return void
	 */
	public function migrate_legacy_tracked_repos(): void {
		$normalized = $this->get_all();
		$legacy_repos = GPW_Context::get_option(self::LEGACY_ACTIVE_REPOS_OPTION, array());
		$did_migrate  = false;

		if (! is_array($legacy_repos)) {
			return;
		}

		foreach ($legacy_repos as $legacy_repo) {
			if (! is_string($legacy_repo) || '' === trim($legacy_repo)) {
				continue;
			}

			$repo_full_name = sanitize_text_field($legacy_repo);
			if (! isset($normalized[$repo_full_name])) {
				$normalized[$repo_full_name] = array(
					'repo_full_name' => $repo_full_name,
					'plugin_file'    => '',
					'is_tracked'     => true,
					'verification'   => $this->get_default_verification(),
				);
				$did_migrate = true;
				continue;
			}

			if (! $normalized[$repo_full_name]['is_tracked']) {
				$normalized[$repo_full_name]['is_tracked'] = true;
				$did_migrate                               = true;
			}
		}

		if ($did_migrate) {
			$this->save_all($normalized);
		}
	}

	/**
	 * Get a single managed plugin record.
	 *
	 * @param string $repo_full_name Repository full name.
	 *
	 * @return array{repo_full_name: string, plugin_file: string, is_tracked: bool, verification: array{status: string, algorithm: string, verified_at: string, release_version: string, checksum: string}}|null
	 */
	public function get(string $repo_full_name): ?array {
		$repo_full_name = sanitize_text_field($repo_full_name);
		if ('' === $repo_full_name) {
			return null;
		}

		$records = $this->get_all();

		return $records[$repo_full_name] ?? null;
	}

	/**
	 * Check whether a repository is tracked.
	 *
	 * @param string $repo_full_name Repository full name.
	 *
	 * @return bool
	 */
	public function is_tracked(string $repo_full_name): bool {
		$record = $this->get($repo_full_name);

		return is_array($record) && ! empty($record['is_tracked']);
	}

	/**
	 * Get a registered plugin file for a repository.
	 *
	 * @param string $repo_full_name Repository full name.
	 *
	 * @return string
	 */
	public function get_plugin_file(string $repo_full_name): string {
		$record = $this->get($repo_full_name);

		return is_array($record) ? (string) $record['plugin_file'] : '';
	}

	/**
	 * Get stored verification metadata for a repository.
	 *
	 * @param string $repo_full_name Repository full name.
	 *
	 * @return array{status: string, algorithm: string, verified_at: string, release_version: string, checksum: string}
	 */
	public function get_verification(string $repo_full_name): array {
		$record = $this->get($repo_full_name);

		return is_array($record) && isset($record['verification']) && is_array($record['verification'])
			? $record['verification']
			: $this->get_default_verification();
	}

	/**
	 * Set tracked state for a repository.
	 *
	 * @param string $repo_full_name Repository full name.
	 * @param bool   $is_tracked     Whether the repository should be tracked.
	 *
	 * @return void
	 */
	public function set_tracked(string $repo_full_name, bool $is_tracked): void {
		$repo_full_name = sanitize_text_field($repo_full_name);
		if ('' === $repo_full_name) {
			return;
		}

		$records = $this->get_all();
		$record  = $records[$repo_full_name] ?? array(
			'repo_full_name' => $repo_full_name,
			'plugin_file'    => '',
			'is_tracked'     => $is_tracked,
			'verification'   => $this->get_default_verification(),
		);

		$record['is_tracked'] = $is_tracked;

		if (! $record['is_tracked'] && '' === $record['plugin_file']) {
			unset($records[$repo_full_name]);
		} else {
			$records[$repo_full_name] = $record;
		}

		$this->save_all($records);
	}

	/**
	 * Register or update a managed plugin mapping.
	 *
	 * @param string $repo_full_name Repository full name.
	 * @param string $plugin_file    Plugin file relative path.
	 * @param bool   $is_tracked     Whether the repository should remain tracked.
	 * @param array<string, string>|null $verification Verification metadata to store.
	 *
	 * @return void
	 */
	public function register_plugin(string $repo_full_name, string $plugin_file, bool $is_tracked = true, ?array $verification = null): void {
		$repo_full_name = sanitize_text_field($repo_full_name);
		$plugin_file    = sanitize_text_field($plugin_file);

		if ('' === $repo_full_name) {
			return;
		}

		$records                  = $this->get_all();
		$current_verification     = isset($records[$repo_full_name]['verification']) && is_array($records[$repo_full_name]['verification'])
			? $records[$repo_full_name]['verification']
			: $this->get_default_verification();
		$records[$repo_full_name] = array(
			'repo_full_name' => $repo_full_name,
			'plugin_file'    => $plugin_file,
			'is_tracked'     => $is_tracked,
			'verification'   => null !== $verification ? $this->normalize_verification($verification) : $current_verification,
		);

		$this->save_all($records);
	}

	/**
	 * Persist verification metadata for a managed plugin.
	 *
	 * @param string               $repo_full_name Repository full name.
	 * @param array<string, mixed> $verification   Verification metadata.
	 *
	 * @return void
	 */
	public function set_verification(string $repo_full_name, array $verification): void {
		$repo_full_name = sanitize_text_field($repo_full_name);
		if ('' === $repo_full_name) {
			return;
		}

		$records = $this->get_all();
		$record  = $records[$repo_full_name] ?? array(
			'repo_full_name' => $repo_full_name,
			'plugin_file'    => '',
			'is_tracked'     => true,
			'verification'   => $this->get_default_verification(),
		);

		$record['verification']   = $this->normalize_verification($verification);
		$records[$repo_full_name] = $record;

		$this->save_all($records);
	}

	/**
	 * Remove a managed plugin record entirely.
	 *
	 * @param string $repo_full_name Repository full name.
	 *
	 * @return void
	 */
	public function remove(string $repo_full_name): void {
		$repo_full_name = sanitize_text_field($repo_full_name);
		if ('' === $repo_full_name) {
			return;
		}

		$records = $this->get_all();
		unset($records[$repo_full_name]);

		$this->save_all($records);
	}

	/**
	 * Find an installed plugin file by repository name.
	 *
	 * @param string                       $repo_name          Repository short name.
	 * @param array<string, array<string>> $installed_plugins Installed plugins map.
	 *
	 * @return string
	 */
	public function find_plugin_file_by_repo_name(string $repo_name, array $installed_plugins): string {
		$repo_name = strtolower(trim($repo_name));
		if ('' === $repo_name) {
			return '';
		}

		foreach (array_keys($installed_plugins) as $plugin_file) {
			if (! is_string($plugin_file) || '' === $plugin_file) {
				continue;
			}

			$plugin_dir      = dirname($plugin_file);
			$plugin_basename = basename($plugin_file, '.php');

			if ('.' !== $plugin_dir && strtolower($plugin_dir) === $repo_name) {
				return $plugin_file;
			}

			if (strtolower($plugin_basename) === $repo_name) {
				return $plugin_file;
			}
		}

		return '';
	}

	/**
	 * Persist managed plugin records and keep the legacy tracked option in sync.
	 *
	 * @param array<string, array{repo_full_name: string, plugin_file: string, is_tracked: bool, verification: array{status: string, algorithm: string, verified_at: string, release_version: string, checksum: string}}> $records Records to persist.
	 *
	 * @return void
	 */
	private function save_all(array $records): void {
		GPW_Context::update_option(self::OPTION_NAME, $records, false);

		$tracked_repos = array();
		foreach ($records as $record) {
			if (! empty($record['is_tracked']) && ! empty($record['repo_full_name'])) {
				$tracked_repos[] = $record['repo_full_name'];
			}
		}

		GPW_Context::update_option(self::LEGACY_ACTIVE_REPOS_OPTION, array_values(array_unique($tracked_repos)), false);
	}

	/**
	 * Normalize verification metadata to a supported shape.
	 *
	 * @param mixed $verification Verification data.
	 *
	 * @return array{status: string, algorithm: string, verified_at: string, release_version: string, checksum: string}
	 */
	private function normalize_verification($verification): array {
		if (! is_array($verification)) {
			return $this->get_default_verification();
		}

		$status = isset($verification['status']) ? sanitize_text_field((string) $verification['status']) : self::VERIFICATION_UNKNOWN;
		if (! in_array($status, array(self::VERIFICATION_UNKNOWN, self::VERIFICATION_VERIFIED), true)) {
			$status = self::VERIFICATION_UNKNOWN;
		}

		$algorithm = isset($verification['algorithm']) ? sanitize_text_field(strtolower((string) $verification['algorithm'])) : '';
		if ('sha256' !== $algorithm) {
			$algorithm = '';
		}

		$verified_at = isset($verification['verified_at']) ? sanitize_text_field((string) $verification['verified_at']) : '';
		$release_version = isset($verification['release_version']) ? sanitize_text_field((string) $verification['release_version']) : '';
		$checksum = isset($verification['checksum']) ? sanitize_text_field(strtolower((string) $verification['checksum'])) : '';

		if (! preg_match('/^[a-f0-9]{64}$/', $checksum)) {
			$checksum = '';
		}

		if (self::VERIFICATION_VERIFIED !== $status) {
			return $this->get_default_verification();
		}

		return array(
			'status'          => self::VERIFICATION_VERIFIED,
			'algorithm'       => 'sha256',
			'verified_at'     => $verified_at,
			'release_version' => $release_version,
			'checksum'        => $checksum,
		);
	}

	/**
	 * Get the default verification payload.
	 *
	 * @return array{status: string, algorithm: string, verified_at: string, release_version: string, checksum: string}
	 */
	private function get_default_verification(): array {
		return array(
			'status'          => self::VERIFICATION_UNKNOWN,
			'algorithm'       => '',
			'verified_at'     => '',
			'release_version' => '',
			'checksum'        => '',
		);
	}
}

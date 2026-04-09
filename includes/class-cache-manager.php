<?php
declare(strict_types=1);
/**
 * Cache manager for Git Repos Manager.
 *
 * @package GitPluginsWordPress
 */

defined('ABSPATH') || exit;

/**
 * Provides centralized transient cache operations.
 */
final class GPW_Cache_Manager {
	/**
	 * Prefix used for all plugin transients.
	 */
	private const PREFIX = 'gpw_cache_';

	/**
	 * Set a cached value.
	 *
	 * @param string $key        Cache key suffix.
	 * @param mixed  $data       Data to store.
	 * @param int    $expiration Expiration in seconds. Defaults to 12 hours.
	 *
	 * @return bool
	 */
	public static function set(string $key, $data, int $expiration = 43200): bool {
		$cache_key = self::build_key($key);

		if ('' === $cache_key) {
			return false;
		}

		if ($expiration < 1) {
			$expiration = 43200;
		}

		return (bool) set_transient($cache_key, $data, $expiration);
	}

	/**
	 * Get a cached value.
	 *
	 * @param string $key Cache key suffix.
	 *
	 * @return mixed
	 */
	public static function get(string $key) {
		$cache_key = self::build_key($key);
		if ('' === $cache_key) {
			return false;
		}

		return get_transient($cache_key);
	}

	/**
	 * Delete a specific cached value.
	 *
	 * @param string $key Cache key suffix.
	 *
	 * @return bool
	 */
	public static function delete(string $key): bool {
		$cache_key = self::build_key($key);
		if ('' === $cache_key) {
			return false;
		}

		return (bool) delete_transient($cache_key);
	}

	/**
	 * Delete all plugin transients created with the gpw_cache_ prefix.
	 *
	 * @return int Number of rows deleted from options table.
	 */
	public static function flush_all(): int {
		global $wpdb;

		if (! isset($wpdb) || ! ($wpdb instanceof wpdb)) {
			return 0;
		}

		$options_table = $wpdb->options;
		$like_value    = $wpdb->esc_like('_transient_' . self::PREFIX) . '%';
		$timeout_like  = $wpdb->esc_like('_transient_timeout_' . self::PREFIX) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bulk deletion by transient prefix requires direct query against options table.
		$deleted_main = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$options_table} WHERE option_name LIKE %s",
				$like_value
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bulk deletion by transient prefix requires direct query against options table.
		$deleted_timeouts = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$options_table} WHERE option_name LIKE %s",
				$timeout_like
			)
		);

		return (int) max(0, (int) $deleted_main) + (int) max(0, (int) $deleted_timeouts);
	}

	/**
	 * Build transient key with plugin prefix.
	 *
	 * @param string $key Cache key suffix.
	 *
	 * @return string
	 */
	private static function build_key(string $key): string {
		$key = sanitize_key($key);
		if ('' === $key) {
			return '';
		}

		$full_key = self::PREFIX . $key;
		if (strlen($full_key) > 172) {
			$full_key = self::PREFIX . md5($key);
		}

		return $full_key;
	}
}

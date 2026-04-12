<?php
declare(strict_types=1);
/**
 * Multisite-aware plugin context helpers.
 *
 * @package GitPluginsWordPress
 */

defined('ABSPATH') || exit;

/**
 * Centralizes scope, capability, and admin URL decisions.
 */
final class GPW_Context {
	/**
	 * Shared admin page slug.
	 */
	public const PAGE_SLUG = 'gpw-settings';

	/**
	 * Whether plugin data should be stored network-wide.
	 *
	 * @return bool
	 */
	public static function uses_network_scope(): bool {
		return is_multisite();
	}

	/**
	 * Get the active storage scope label.
	 *
	 * @return string
	 */
	public static function get_storage_scope(): string {
		return self::uses_network_scope() ? 'network' : 'site';
	}

	/**
	 * Get the capability required to manage plugin settings.
	 *
	 * @return string
	 */
	public static function get_settings_capability(): string {
		return self::uses_network_scope() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Check whether the current user can manage plugin settings.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage_settings(): bool {
		return current_user_can(self::get_settings_capability());
	}

	/**
	 * Check whether the current user can install plugins.
	 *
	 * @return bool
	 */
	public static function current_user_can_install_plugins(): bool {
		if (! current_user_can('install_plugins')) {
			return false;
		}

		return ! self::uses_network_scope() || current_user_can('manage_network_plugins');
	}

	/**
	 * Check whether the current user can update plugins.
	 *
	 * @return bool
	 */
	public static function current_user_can_update_plugins(): bool {
		if (! current_user_can('update_plugins')) {
			return false;
		}

		return ! self::uses_network_scope() || current_user_can('manage_network_plugins');
	}

	/**
	 * Check whether the current user can delete plugins.
	 *
	 * @return bool
	 */
	public static function current_user_can_delete_plugins(): bool {
		if (! current_user_can('delete_plugins')) {
			return false;
		}

		return ! self::uses_network_scope() || current_user_can('manage_network_plugins');
	}

	/**
	 * Read an option from the active storage scope.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Default value.
	 *
	 * @return mixed
	 */
	public static function get_option(string $key, $default = false) {
		return self::uses_network_scope()
			? get_site_option($key, $default)
			: get_option($key, $default);
	}

	/**
	 * Update an option in the active storage scope.
	 *
	 * @param string    $key      Option key.
	 * @param mixed     $value    Option value.
	 * @param bool|null $autoload Autoload flag for site scope writes.
	 *
	 * @return bool
	 */
	public static function update_option(string $key, $value, ?bool $autoload = null): bool {
		if (self::uses_network_scope()) {
			return (bool) update_site_option($key, $value);
		}

		if (null === $autoload) {
			return (bool) update_option($key, $value);
		}

		return (bool) update_option($key, $value, $autoload);
	}

	/**
	 * Delete an option from the active storage scope.
	 *
	 * @param string $key Option key.
	 *
	 * @return bool
	 */
	public static function delete_option(string $key): bool {
		return self::uses_network_scope()
			? (bool) delete_site_option($key)
			: (bool) delete_option($key);
	}

	/**
	 * Get the admin URL for the plugin page.
	 *
	 * @param bool|null $network Whether to force the network admin URL.
	 *
	 * @return string
	 */
	public static function get_admin_page_url(?bool $network = null): string {
		if (null === $network) {
			$network = self::uses_network_scope();
		}

		$path = 'admin.php?page=' . self::PAGE_SLUG;

		return $network ? network_admin_url($path) : admin_url($path);
	}

	/**
	 * Build app context for the admin SPA.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_js_context(): array {
		return array(
			'isMultisite'         => is_multisite(),
			'isNetworkManaged'    => self::uses_network_scope(),
			'isNetworkAdmin'      => is_network_admin(),
			'canManageSettings'   => self::current_user_can_manage_settings(),
			'scope'               => self::get_storage_scope(),
			'adminPageUrl'        => self::get_admin_page_url(),
			'networkAdminPageUrl' => is_multisite() ? self::get_admin_page_url(true) : '',
		);
	}
}
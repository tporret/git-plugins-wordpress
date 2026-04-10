<?php
declare(strict_types=1);
/**
 * Plugin Name:       Git Repos Manager
 * Description:       Connect GitHub repositories and distribute WordPress extensions via Release assets.
 * Version:           1.0.1
 * Author:            tporret
 * Plugin URI:        https://porretto.com/github-wordpress-plugin-manager/
 * Text Domain:       git-plugins-wordpress
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Donate link: http://porretto.com/donate
 *
 * @package GitPluginsWordPress
 */

defined('ABSPATH') || exit;

define('GPW_VERSION', '1.0.1');
define('GPW_PLUGIN_FILE', __FILE__);
define('GPW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GPW_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once GPW_PLUGIN_DIR . 'includes/class-encryption.php';
require_once GPW_PLUGIN_DIR . 'includes/class-cache-manager.php';
require_once GPW_PLUGIN_DIR . 'includes/class-github-api.php';
require_once GPW_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once GPW_PLUGIN_DIR . 'includes/class-plugin-installer.php';
require_once GPW_PLUGIN_DIR . 'includes/class-rest-api.php';

/**
 * Main plugin bootstrap class.
 */
final class Git_Plugins_WP {
	/**
	 * Singleton instance.
	 *
	 * @var Git_Plugins_WP|null
	 */
	private static ?Git_Plugins_WP $instance = null;

	/**
	 * GitHub API service.
	 *
	 * @var GPW_GitHub_API
	 */
	private GPW_GitHub_API $github_api;

	/**
	 * Admin settings module.
	 *
	 * @var GPW_Admin_Settings
	 */
	private GPW_Admin_Settings $admin_settings;

	/**
	 * Plugin installer module.
	 *
	 * @var GPW_Plugin_Installer
	 */
	private GPW_Plugin_Installer $plugin_installer;

	/**
	 * REST API controller.
	 *
	 * @var GPW_REST_API
	 */
	private GPW_REST_API $rest_api;

	/**
	 * Get singleton instance.
	 *
	 * @return Git_Plugins_WP
	 */
	public static function instance(): Git_Plugins_WP {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->github_api       = new GPW_GitHub_API();
		$this->admin_settings   = new GPW_Admin_Settings();
		$this->plugin_installer = new GPW_Plugin_Installer($this->github_api);
		$this->rest_api         = new GPW_REST_API($this->github_api);
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		$this->rest_api->register_hooks();

		if (is_admin()) {
			$this->admin_settings->register_hooks();
			$this->plugin_installer->register_hooks();
			$this->maybe_migrate_legacy_settings();
			$this->check_encryption_sentinel();
		}
	}

	/**
	 * Verify encryption sentinel and show admin notice if WordPress salts have changed.
	 *
	 * @return void
	 */
	private function check_encryption_sentinel(): void {
		$sentinel_ok = GPW_Encryption::verify_sentinel();

		// null means no sentinel stored yet — nothing to check.
		if (null === $sentinel_ok) {
			return;
		}

		if (false === $sentinel_ok) {
			add_action('admin_notices', static function (): void {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__(
					'Git Plugins: WordPress security salts have changed. Your stored GitHub PATs can no longer be decrypted. Please re-enter your PATs in the Git Plugins settings.',
					'git-plugins-wordpress'
				);
				echo '</p></div>';
			});
		}
	}

	/**
	 * Migrate legacy single-source settings to the multi-source format and encrypt the PAT.
	 * Runs once on admin_init; deletes legacy keys after migration.
	 *
	 * @return void
	 */
	private function maybe_migrate_legacy_settings(): void {
		$settings = get_option('gpw_settings', array());
		if (! is_array($settings)) {
			$settings = array();
		}

		// Already migrated — sources array exists.
		if (isset($settings['sources']) && is_array($settings['sources'])) {
			return;
		}

		$legacy_target = isset($settings['github_target']) ? sanitize_text_field((string) $settings['github_target']) : '';
		$legacy_pat    = isset($settings['github_pat']) ? trim((string) $settings['github_pat']) : '';

		if ('' === $legacy_target) {
			return;
		}

		// Encrypt PAT if it is still in plaintext.
		$encrypted_pat = '';
		if ('' !== $legacy_pat) {
			$result = GPW_Encryption::encrypt($legacy_pat);
			if (is_wp_error($result)) {
				// Cannot encrypt — abort migration to avoid storing plaintext in new format.
				return;
			}
			$encrypted_pat = $result;
		}

		$new_settings = array(
			'sources' => array(
				array(
					'target' => $legacy_target,
					'pat'    => $encrypted_pat,
				),
			),
		);

		update_option('gpw_settings', $new_settings);
	}
}

Git_Plugins_WP::instance()->init();

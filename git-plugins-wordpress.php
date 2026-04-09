<?php
declare(strict_types=1);
/**
 * Plugin Name:       Git Repos Manager
 * Description:       Connect GitHub repositories and distribute WordPress extensions via Release assets.
 * Version:           1.0.0
 * Author:            tporret
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

define('GPW_VERSION', '1.0.0');
define('GPW_PLUGIN_FILE', __FILE__);
define('GPW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GPW_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once GPW_PLUGIN_DIR . 'includes/class-cache-manager.php';
require_once GPW_PLUGIN_DIR . 'includes/class-github-api.php';
require_once GPW_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once GPW_PLUGIN_DIR . 'includes/class-admin-repos-page.php';
require_once GPW_PLUGIN_DIR . 'includes/class-plugin-installer.php';

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
	 * Admin repositories page module.
	 *
	 * @var GPW_Admin_Repos_Page
	 */
	private GPW_Admin_Repos_Page $admin_repos_page;

	/**
	 * Plugin installer module.
	 *
	 * @var GPW_Plugin_Installer
	 */
	private GPW_Plugin_Installer $plugin_installer;

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
		$this->admin_settings   = new GPW_Admin_Settings($this->github_api);
		$this->admin_repos_page = new GPW_Admin_Repos_Page($this->github_api);
		$this->plugin_installer = new GPW_Plugin_Installer($this->github_api);
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		if (is_admin()) {
			$this->admin_settings->register_hooks();
			$this->admin_repos_page->register_hooks();
			$this->plugin_installer->register_hooks();
		}
	}
}

Git_Plugins_WP::instance()->init();

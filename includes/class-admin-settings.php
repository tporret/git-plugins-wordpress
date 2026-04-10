<?php
declare(strict_types=1);
/**
 * Admin settings for Git Repos Manager.
 *
 * @package GitPluginsWordPress
 */

defined('ABSPATH') || exit;

/**
 * Handles plugin settings page and field registration.
 */
final class GPW_Admin_Settings {
	/**
	 * Admin page slug.
	 */
	private const PAGE_SLUG = 'gpw-settings';

	/**
	 * Register all admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_react_assets'));
	}

	/**
	 * Register submenu under Plugins.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			esc_html__('Git Plugins', 'git-plugins-wordpress'),
			esc_html__('Git Plugins', 'git-plugins-wordpress'),
			'manage_options',
			self::PAGE_SLUG,
			array($this, 'render_page'),
			'dashicons-admin-plugins',
			58
		);

		add_submenu_page(
			self::PAGE_SLUG,
			esc_html__('Settings', 'git-plugins-wordpress'),
			esc_html__('Settings', 'git-plugins-wordpress'),
			'manage_options',
			self::PAGE_SLUG,
			array($this, 'render_page')
		);
	}

	/**
	 * Render the settings page — React SPA mount point.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if (! current_user_can('manage_options')) {
			return;
		}
		?>
		<div class="wrap">
			<div id="gpw-react-root"></div>
		</div>
		<?php
	}

	/**
	 * Enqueue compiled React assets on the plugin admin page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 *
	 * @return void
	 */
	public function enqueue_react_assets(string $hook_suffix): void {
		if ('toplevel_page_' . self::PAGE_SLUG !== $hook_suffix) {
			return;
		}

		$asset_file = GPW_PLUGIN_DIR . 'build/index.asset.php';

		if (! file_exists($asset_file)) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_style(
			'gpw-react-style',
			GPW_PLUGIN_URL . 'build/index.css',
			array(),
			$asset['version']
		);

		wp_enqueue_script(
			'gpw-react-app',
			GPW_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script('gpw-react-app', 'gpwSettings', array(
			'restUrl' => esc_url_raw(rest_url('gpw/v1')),
			'nonce'   => wp_create_nonce('wp_rest'),
		));
	}
}

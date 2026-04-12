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
	private const PAGE_SLUG = GPW_Context::PAGE_SLUG;

	/**
	 * Register all admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if (GPW_Context::uses_network_scope()) {
			add_action('network_admin_menu', array($this, 'register_menu'));
		} else {
			add_action('admin_menu', array($this, 'register_menu'));
		}

		add_action('admin_enqueue_scripts', array($this, 'enqueue_react_assets'));
	}

	/**
	 * Register submenu under Plugins.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$capability = GPW_Context::get_settings_capability();

		add_menu_page(
			esc_html__('Git Plugins', 'git-plugins-wordpress'),
			esc_html__('Git Plugins', 'git-plugins-wordpress'),
			$capability,
			self::PAGE_SLUG,
			array($this, 'render_page'),
			'dashicons-admin-plugins',
			58
		);

		add_submenu_page(
			self::PAGE_SLUG,
			esc_html__('Settings', 'git-plugins-wordpress'),
			esc_html__('Settings', 'git-plugins-wordpress'),
			$capability,
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
		if (! GPW_Context::current_user_can_manage_settings()) {
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
		$page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';

		if ('toplevel_page_' . self::PAGE_SLUG !== $hook_suffix && self::PAGE_SLUG !== $page) {
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
			'context' => GPW_Context::get_js_context(),
		));
	}
}

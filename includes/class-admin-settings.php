<?php
declare(strict_types=1);
/**
 * Admin settings for Git Plugins WordPress.
 *
 * @package GitPluginsWordPress
 */

defined('ABSPATH') || exit;

/**
 * Handles plugin settings page and field registration.
 */
final class GPW_Admin_Settings {
	/**
	 * Option group used by Settings API.
	 */
	private const OPTION_GROUP = 'gpw_settings';

	/**
	 * Option key for saved settings.
	 */
	private const OPTION_NAME = 'gpw_settings';

	/**
	 * Admin page slug.
	 */
	private const PAGE_SLUG = 'gpw-settings';

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
	 * Register all admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_init', array($this, 'handle_force_check_utility'));
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
	 * Register settings, section, and fields.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array($this, 'sanitize_settings')
		);

		add_settings_section(
			'gpw_main_section',
			esc_html__('GitHub Configuration', 'git-plugins-wordpress'),
			array($this, 'render_section_intro'),
			self::PAGE_SLUG
		);

		add_settings_field(
			'github_target',
			esc_html__('GitHub Target Name', 'git-plugins-wordpress'),
			array($this, 'render_target_field'),
			self::PAGE_SLUG,
			'gpw_main_section'
		);

		add_settings_field(
			'github_pat',
			esc_html__('GitHub Personal Access Token (PAT)', 'git-plugins-wordpress'),
			array($this, 'render_pat_field'),
			self::PAGE_SLUG,
			'gpw_main_section'
		);
	}

	/**
	 * Sanitize settings input before saving.
	 *
	 * @param mixed $input Raw settings input.
	 *
	 * @return array<string, string>
	 */
	public function sanitize_settings($input): array {
		$old_settings = $this->get_settings();

		$new_settings = array(
			'github_target' => isset($input['github_target']) ? sanitize_text_field((string) $input['github_target']) : '',
			'github_pat'    => isset($input['github_pat']) ? sanitize_text_field((string) $input['github_pat']) : '',
		);

		if (
			$old_settings['github_target'] !== $new_settings['github_target'] ||
			$old_settings['github_pat'] !== $new_settings['github_pat']
		) {
			$this->github_api->flush_cache();
		}

		return $new_settings;
	}

	/**
	 * Handle Force Check button submit from settings form.
	 *
	 * @return void
	 */
	public function handle_force_check_utility(): void {
		if (! isset($_POST['gpw_force_check'])) {
			return;
		}

		$option_page = isset($_POST['option_page']) ? sanitize_text_field(wp_unslash((string) $_POST['option_page'])) : '';
		if (self::OPTION_GROUP !== $option_page) {
			return;
		}

		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do this action.', 'git-plugins-wordpress'));
		}

		check_admin_referer(self::OPTION_GROUP . '-options');

		GPW_Cache_Manager::flush_all();
		$this->github_api->flush_cache();
		wp_clean_plugins_cache(true);
		wp_update_plugins();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE_SLUG,
					'gpw_notice' => 'force-check-updates',
				),
				admin_url('admin.php')
			)
		);
		exit;
	}

	/**
	 * Render settings section text.
	 *
	 * @return void
	 */
	public function render_section_intro(): void {
		echo '<p>' . esc_html__('Set your GitHub source and optional token for higher API limits.', 'git-plugins-wordpress') . '</p>';
	}

	/**
	 * Render GitHub target field.
	 *
	 * @return void
	 */
	public function render_target_field(): void {
		$settings = $this->get_settings();
		?>
		<input
			type="text"
			name="<?php echo esc_attr(self::OPTION_NAME); ?>[github_target]"
			value="<?php echo esc_attr($settings['github_target']); ?>"
			class="regular-text"
			placeholder="octocat"
		/>
		<p class="description"><?php echo esc_html__('GitHub username or organization name.', 'git-plugins-wordpress'); ?></p>
		<?php
	}

	/**
	 * Render GitHub PAT field.
	 *
	 * @return void
	 */
	public function render_pat_field(): void {
		$settings = $this->get_settings();
		?>
		<input
			type="password"
			name="<?php echo esc_attr(self::OPTION_NAME); ?>[github_pat]"
			value="<?php echo esc_attr($settings['github_pat']); ?>"
			class="regular-text"
			autocomplete="new-password"
		/>
		<p class="description"><?php echo esc_html__('Optional. Used to avoid strict rate limits and to access private repositories.', 'git-plugins-wordpress'); ?></p>
		<?php
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if (! current_user_can('manage_options')) {
			return;
		}

		$this->register_page_notices();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__('Git Plugins', 'git-plugins-wordpress'); ?></h1>

			<?php settings_errors('gpw_messages'); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields(self::OPTION_GROUP);
				do_settings_sections(self::PAGE_SLUG);
				submit_button(esc_html__('Save Settings', 'git-plugins-wordpress'));
				?>
				<input
					type="submit"
					name="gpw_force_check"
					class="button button-secondary"
					value="<?php echo esc_attr__('Force Check for Updates', 'git-plugins-wordpress'); ?>"
				/>
			</form>
		</div>
		<?php
	}

	/**
	 * Register admin notices displayed on settings page.
	 *
	 * @return void
	 */
	private function register_page_notices(): void {
		if (isset($_GET['gpw_notice']) && 'force-check-updates' === sanitize_text_field(wp_unslash((string) $_GET['gpw_notice']))) {
			add_settings_error(
				'gpw_messages',
				'gpw_force_check_updates',
				esc_html__('GitHub cache cleared and update check forced.', 'git-plugins-wordpress'),
				'success'
			);
		}

		$last_api_error = $this->github_api->get_last_error(true);
		if (! empty($last_api_error)) {
			add_settings_error(
				'gpw_messages',
				'gpw_last_api_error',
				esc_html($last_api_error),
				'error'
			);
		}
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
			'github_target' => isset($settings['github_target']) ? (string) $settings['github_target'] : '',
			'github_pat'    => isset($settings['github_pat']) ? (string) $settings['github_pat'] : '',
		);
	}
}

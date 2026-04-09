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
			esc_html__('GitHub Sources', 'git-plugins-wordpress'),
			array($this, 'render_section_intro'),
			self::PAGE_SLUG
		);

		add_settings_field(
			'github_sources',
			esc_html__('GitHub Sources', 'git-plugins-wordpress'),
			array($this, 'render_sources_field'),
			self::PAGE_SLUG,
			'gpw_main_section'
		);
	}

	/**
	 * Sanitize settings input before saving.
	 *
	 * @param mixed $input Raw settings input.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize_settings($input): array {
		$new_sources = array();

		if (isset($input['sources']) && is_array($input['sources'])) {
			foreach ($input['sources'] as $source) {
				if (! is_array($source)) {
					continue;
				}

				$target = isset($source['target']) ? sanitize_text_field(trim((string) $source['target'])) : '';
				$pat    = isset($source['pat']) ? sanitize_text_field(trim((string) $source['pat'])) : '';

				if ('' === $target) {
					continue;
				}

				$new_sources[] = array(
					'target' => $target,
					'pat'    => $pat,
				);
			}
		}

		$old_settings  = $this->get_settings();
		$old_sources   = $old_settings['sources'];
		$sources_changed = wp_json_encode($old_sources) !== wp_json_encode($new_sources);

		if ($sources_changed) {
			$this->github_api->flush_cache();
		}

		return array(
			'sources' => $new_sources,
		);
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
		echo '<p>' . esc_html__('Add one or more GitHub users or organizations. Each source can have its own optional PAT for private repo access or higher rate limits.', 'git-plugins-wordpress') . '</p>';
	}

	/**
	 * Render repeatable GitHub sources fields.
	 *
	 * @return void
	 */
	public function render_sources_field(): void {
		$settings = $this->get_settings();
		$sources  = $settings['sources'];

		if (empty($sources)) {
			$sources = array(array('target' => '', 'pat' => ''));
		}
		?>
		<div id="gpw-sources-wrapper">
			<?php foreach ($sources as $index => $source) : ?>
				<?php
				$source_index  = (int) $index;
				$source_target = isset($source['target']) ? (string) $source['target'] : '';
				$source_pat    = isset($source['pat']) ? (string) $source['pat'] : '';
				$source_label  = sprintf(
					/* translators: %d: source number */
					__('Source #%d', 'git-plugins-wordpress'),
					$source_index + 1
				);
				?>
				<fieldset class="gpw-source-row" style="border:1px solid #ccd0d4;padding:12px 16px;margin-bottom:12px;background:#f9f9f9;">
					<legend style="font-weight:600;">
						<?php echo esc_html($source_label); ?>
					</legend>
					<p>
						<label>
							<?php echo esc_html__('GitHub Target Name', 'git-plugins-wordpress'); ?><br/>
							<input
								type="text"
								name="<?php echo esc_attr(self::OPTION_NAME); ?>[sources][<?php echo esc_attr((string) $source_index); ?>][target]"
								value="<?php echo esc_attr($source_target); ?>"
								class="regular-text"
								placeholder="octocat"
							/>
						</label>
						<span class="description"><?php echo esc_html__('GitHub username or organization name.', 'git-plugins-wordpress'); ?></span>
					</p>
					<p>
						<label>
							<?php echo esc_html__('Personal Access Token (PAT)', 'git-plugins-wordpress'); ?><br/>
							<input
								type="password"
								name="<?php echo esc_attr(self::OPTION_NAME); ?>[sources][<?php echo esc_attr((string) $source_index); ?>][pat]"
								value="<?php echo esc_attr($source_pat); ?>"
								class="regular-text"
								autocomplete="new-password"
							/>
						</label>
						<span class="description"><?php echo esc_html__('Optional. Required for private repos, recommended for higher API limits.', 'git-plugins-wordpress'); ?></span>
					</p>
					<?php if (count($sources) > 1) : ?>
						<button type="button" class="button gpw-remove-source" style="color:#a00;"><?php echo esc_html__('Remove Source', 'git-plugins-wordpress'); ?></button>
					<?php endif; ?>
				</fieldset>
			<?php endforeach; ?>
		</div>
		<button type="button" class="button button-secondary" id="gpw-add-source"><?php echo esc_html__('+ Add Another Source', 'git-plugins-wordpress'); ?></button>

		<script>
		(function(){
			var wrapper = document.getElementById('gpw-sources-wrapper');
			var addBtn  = document.getElementById('gpw-add-source');
			var optName = <?php echo wp_json_encode(self::OPTION_NAME); ?>;

			function getNextIndex() {
				var rows = wrapper.querySelectorAll('.gpw-source-row');
				var max = -1;
				rows.forEach(function(row) {
					var inputs = row.querySelectorAll('input[name]');
					inputs.forEach(function(inp) {
						var m = inp.name.match(/\[sources\]\[(\d+)\]/);
						if (m) { var n = parseInt(m[1], 10); if (n > max) max = n; }
					});
				});
				return max + 1;
			}

			function refreshRemoveButtons() {
				var rows = wrapper.querySelectorAll('.gpw-source-row');
				rows.forEach(function(row) {
					var btn = row.querySelector('.gpw-remove-source');
					if (rows.length <= 1) {
						if (btn) btn.style.display = 'none';
					} else {
						if (btn) btn.style.display = '';
						if (!btn) {
							btn = document.createElement('button');
							btn.type = 'button';
							btn.className = 'button gpw-remove-source';
							btn.style.color = '#a00';
							btn.textContent = <?php echo wp_json_encode(__('Remove Source', 'git-plugins-wordpress')); ?>;
							row.appendChild(btn);
						}
					}
				});
			}

			addBtn.addEventListener('click', function(){
				var idx = getNextIndex();
				var fieldset = document.createElement('fieldset');
				fieldset.className = 'gpw-source-row';
				fieldset.style.cssText = 'border:1px solid #ccd0d4;padding:12px 16px;margin-bottom:12px;background:#f9f9f9;';
				fieldset.innerHTML =
					'<legend style="font-weight:600;">' + <?php echo wp_json_encode(__('Source #', 'git-plugins-wordpress')); ?> + (idx + 1) + '</legend>' +
					'<p><label>' + <?php echo wp_json_encode(__('GitHub Target Name', 'git-plugins-wordpress')); ?> + '<br/>' +
					'<input type="text" name="' + optName + '[sources][' + idx + '][target]" value="" class="regular-text" placeholder="octocat" />' +
					'</label> <span class="description">' + <?php echo wp_json_encode(__('GitHub username or organization name.', 'git-plugins-wordpress')); ?> + '</span></p>' +
					'<p><label>' + <?php echo wp_json_encode(__('Personal Access Token (PAT)', 'git-plugins-wordpress')); ?> + '<br/>' +
					'<input type="password" name="' + optName + '[sources][' + idx + '][pat]" value="" class="regular-text" autocomplete="new-password" />' +
					'</label> <span class="description">' + <?php echo wp_json_encode(__('Optional. Required for private repos, recommended for higher API limits.', 'git-plugins-wordpress')); ?> + '</span></p>' +
					'<button type="button" class="button gpw-remove-source" style="color:#a00;">' + <?php echo wp_json_encode(__('Remove Source', 'git-plugins-wordpress')); ?> + '</button>';
				wrapper.appendChild(fieldset);
				refreshRemoveButtons();
			});

			wrapper.addEventListener('click', function(e){
				if (e.target && e.target.classList.contains('gpw-remove-source')) {
					var row = e.target.closest('.gpw-source-row');
					if (row) row.remove();
					refreshRemoveButtons();
				}
			});
		})();
		</script>
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
		$notice_raw = filter_input(INPUT_GET, 'gpw_notice', FILTER_UNSAFE_RAW);
		$notice     = is_string($notice_raw) ? sanitize_text_field(wp_unslash($notice_raw)) : '';

		if ('force-check-updates' === $notice) {
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
	 * Get settings with defaults and backward-compatible migration.
	 *
	 * @return array<string, mixed>
	 */
	private function get_settings(): array {
		$settings = get_option(self::OPTION_NAME, array());

		if (! is_array($settings)) {
			$settings = array();
		}

		if (isset($settings['sources']) && is_array($settings['sources'])) {
			return array(
				'sources' => $settings['sources'],
			);
		}

		$legacy_target = isset($settings['github_target']) ? (string) $settings['github_target'] : '';
		$legacy_pat    = isset($settings['github_pat']) ? (string) $settings['github_pat'] : '';

		$sources = array();
		if ('' !== $legacy_target) {
			$sources[] = array(
				'target' => $legacy_target,
				'pat'    => $legacy_pat,
			);
		}

		return array(
			'sources' => $sources,
		);
	}
}

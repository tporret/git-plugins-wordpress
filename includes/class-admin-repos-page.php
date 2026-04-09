<?php
declare(strict_types=1);
/**
 * Admin repository list page for Git Plugins WordPress.
 *
 * @package GitPluginsWordPress
 */

defined('ABSPATH') || exit;

/**
 * Renders and handles the Available Plugins admin page.
 */
final class GPW_Admin_Repos_Page {
	/**
	 * Option key for active repositories.
	 */
	private const ACTIVE_REPOS_OPTION = 'gpw_active_repos';

	/**
	 * Parent menu slug.
	 */
	private const PARENT_SLUG = 'gpw-settings';

	/**
	 * Admin page slug.
	 */
	private const PAGE_SLUG = 'gpw-available-plugins';

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
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('admin_post_gpw_save_active_repos', array($this, 'handle_save_active_repos'));
	}

	/**
	 * Register submenu page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			esc_html__('Available Plugins', 'git-plugins-wordpress'),
			esc_html__('Available Plugins', 'git-plugins-wordpress'),
			'manage_options',
			self::PAGE_SLUG,
			array($this, 'render_page')
		);
	}

	/**
	 * Save active repository selections.
	 *
	 * @return void
	 */
	public function handle_save_active_repos(): void {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do this action.', 'git-plugins-wordpress'));
		}

		check_admin_referer('gpw_save_active_repos_action', 'gpw_save_active_repos_nonce');

		$submitted_repos = isset($_POST['active_repos']) ? wp_unslash((array) $_POST['active_repos']) : array();
		$sanitized_repos = array();

		foreach ($submitted_repos as $repo_full_name) {
			$repo = sanitize_text_field((string) $repo_full_name);
			if ('' !== $repo) {
				$sanitized_repos[] = $repo;
			}
		}

		$sanitized_repos = array_values(array_unique($sanitized_repos));
		update_option(self::ACTIVE_REPOS_OPTION, $sanitized_repos, false);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE_SLUG,
					'gpw_notice' => 'active-saved',
				),
				admin_url('admin.php')
			)
		);
		exit;
	}

	/**
	 * Render available plugins page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if (! current_user_can('manage_options')) {
			return;
		}

		$this->register_page_notices();
		$repositories = $this->github_api->get_repositories();
		$active_repos = $this->get_active_repositories();
		$installed    = $this->get_installed_plugin_dirs();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__('Available Plugins', 'git-plugins-wordpress'); ?></h1>

			<?php settings_errors('gpw_messages'); ?>

			<?php if (is_wp_error($repositories)) : ?>
				<div class="notice notice-error"><p><?php echo esc_html($repositories->get_error_message()); ?></p></div>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="gpw_save_active_repos" />
					<?php wp_nonce_field('gpw_save_active_repos_action', 'gpw_save_active_repos_nonce'); ?>

					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php echo esc_html__('Plugin Name', 'git-plugins-wordpress'); ?></th>
								<th><?php echo esc_html__('Version', 'git-plugins-wordpress'); ?></th>
								<th><?php echo esc_html__('Status', 'git-plugins-wordpress'); ?></th>
								<th><?php echo esc_html__('Action', 'git-plugins-wordpress'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if (empty($repositories)) : ?>
								<tr>
									<td colspan="4"><?php echo esc_html__('No wp-plugin repositories were found.', 'git-plugins-wordpress'); ?></td>
								</tr>
							<?php else : ?>
								<?php foreach ($repositories as $repository) : ?>
									<?php
									$repo_name      = isset($repository['name']) ? sanitize_text_field((string) $repository['name']) : '';
									$repo_full_name = isset($repository['full_name']) ? sanitize_text_field((string) $repository['full_name']) : '';
									$description    = isset($repository['description']) ? sanitize_text_field((string) $repository['description']) : '';
									$release        = '' !== $repo_full_name ? $this->github_api->get_latest_release($repo_full_name) : new WP_Error('gpw_missing_repo_name', __('Repository data is incomplete.', 'git-plugins-wordpress'));
									$version        = is_wp_error($release) ? esc_html__('Unavailable', 'git-plugins-wordpress') : (isset($release['tag_name']) ? sanitize_text_field((string) $release['tag_name']) : esc_html__('N/A', 'git-plugins-wordpress'));
									$is_active      = in_array($repo_full_name, $active_repos, true);
									$is_installed   = in_array(strtolower($repo_name), $installed, true);
									?>
									<tr>
										<td>
											<strong><?php echo esc_html($repo_name); ?></strong>
											<?php if ('' !== $description) : ?>
												<p class="description"><?php echo esc_html($description); ?></p>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html($version); ?></td>
										<td>
											<label>
												<input
													type="checkbox"
													name="active_repos[]"
													value="<?php echo esc_attr($repo_full_name); ?>"
													<?php checked($is_active); ?>
												/>
												<?php echo esc_html__('Active', 'git-plugins-wordpress'); ?>
											</label>
										</td>
										<td>
											<?php if ($is_installed) : ?>
												<span class="button disabled" aria-disabled="true"><?php echo esc_html__('Installed', 'git-plugins-wordpress'); ?></span>
											<?php else : ?>
												<?php
												$install_url = wp_nonce_url(
													add_query_arg(
														array(
															'action'         => 'gpw_install_repo',
															'repo_full_name' => $repo_full_name,
														),
														admin_url('admin-post.php')
													),
													'gpw_install_repo_' . $repo_full_name,
													'gpw_install_nonce'
												);
												?>
												<a class="button button-primary" href="<?php echo esc_url($install_url); ?>"><?php echo esc_html__('Install Now', 'git-plugins-wordpress'); ?></a>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>

					<?php submit_button(esc_html__('Save Active Plugins', 'git-plugins-wordpress')); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Add admin notices for this page.
	 *
	 * @return void
	 */
	private function register_page_notices(): void {
		$notice = isset($_GET['gpw_notice']) ? sanitize_text_field(wp_unslash((string) $_GET['gpw_notice'])) : '';

		if ('active-saved' === $notice) {
			add_settings_error(
				'gpw_messages',
				'gpw_active_saved',
				esc_html__('Active repository list updated.', 'git-plugins-wordpress'),
				'success'
			);
		}

		if ('install-success' === $notice) {
			add_settings_error(
				'gpw_messages',
				'gpw_install_success',
				esc_html__('Plugin installed successfully.', 'git-plugins-wordpress'),
				'success'
			);
		}

		if ('install-failed' === $notice) {
			$message = isset($_GET['message']) ? sanitize_text_field(wp_unslash((string) $_GET['message'])) : __('Plugin installation failed.', 'git-plugins-wordpress');
			add_settings_error(
				'gpw_messages',
				'gpw_install_failed',
				esc_html($message),
				'error'
			);
		}

		$last_api_error = $this->github_api->get_last_error(true);
		if ('' !== $last_api_error) {
			add_settings_error(
				'gpw_messages',
				'gpw_api_error',
				esc_html($last_api_error),
				'error'
			);
		}
	}

	/**
	 * Return active repository full-name list.
	 *
	 * @return array<int, string>
	 */
	private function get_active_repositories(): array {
		$value = get_option(self::ACTIVE_REPOS_OPTION, array());
		if (! is_array($value)) {
			return array();
		}

		$result = array();
		foreach ($value as $repo) {
			$repo_name = sanitize_text_field((string) $repo);
			if ('' !== $repo_name) {
				$result[] = $repo_name;
			}
		}

		return array_values(array_unique($result));
	}

	/**
	 * Build list of installed plugin directory names.
	 *
	 * @return array<int, string>
	 */
	private function get_installed_plugin_dirs(): array {
		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed_plugins = get_plugins();
		$dirs              = array();

		foreach (array_keys($installed_plugins) as $plugin_file) {
			$dir = dirname($plugin_file);
			if ('.' !== $dir) {
				$dirs[] = strtolower((string) $dir);
			}
		}

		return array_values(array_unique($dirs));
	}
}

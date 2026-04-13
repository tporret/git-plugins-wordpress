<?php
declare(strict_types=1);
/**
 * WP-CLI commands for Git Repos Manager.
 *
 * @package GitPluginsWordPress
 */

defined('ABSPATH') || exit;

/**
 * Provides the `wp gpw` command tree.
 */
final class GPW_CLI {
	/**
	 * Settings option key.
	 */
	private const SETTINGS_OPTION = 'gpw_settings';

	/**
	 * GitHub API service.
	 *
	 * @var GPW_GitHub_API
	 */
	private GPW_GitHub_API $github_api;

	/**
	 * Release channel manager.
	 *
	 * @var GPW_Channel_Manager
	 */
	private GPW_Channel_Manager $channel_manager;

	/**
	 * Managed plugin registry.
	 *
	 * @var GPW_Managed_Plugin_Registry
	 */
	private GPW_Managed_Plugin_Registry $registry;

	/**
	 * Shared plugin deployment service.
	 *
	 * @var GPW_Plugin_Deployment_Service
	 */
	private GPW_Plugin_Deployment_Service $deployment_service;

	/**
	 * REST API controller reused for plugin discovery output.
	 *
	 * @var GPW_REST_API
	 */
	private GPW_REST_API $rest_api;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->github_api         = new GPW_GitHub_API();
		$this->channel_manager    = new GPW_Channel_Manager();
		$this->registry           = new GPW_Managed_Plugin_Registry();
		$this->deployment_service = new GPW_Plugin_Deployment_Service($this->github_api, $this->registry);
		$this->rest_api           = new GPW_REST_API($this->github_api, $this->registry, $this->deployment_service, $this->channel_manager);
	}

	/**
	 * Manage configured GitHub sources.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : One of: list, add, remove.
	 *
	 * [<target>]
	 * : GitHub owner or organization for add/remove.
	 *
	 * [--pat=<token>]
	 * : Optional GitHub Personal Access Token for source add.
	 *
	 * ## EXAMPLES
	 *
	 *     wp gpw source list
	 *     wp gpw source add my-org --pat=ghp_xxx
	 *     wp gpw source remove my-org
	 *
	 * @param array<int, string>          $args       Positional arguments.
	 * @param array<string, string|bool>  $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function source(array $args, array $assoc_args): void {
		$action = $args[0] ?? '';

		switch ($action) {
			case 'list':
				$this->source_list();
				return;

			case 'add':
				$this->source_add($args[1] ?? '', isset($assoc_args['pat']) ? (string) $assoc_args['pat'] : '');
				return;

			case 'remove':
				$this->source_remove($args[1] ?? '');
				return;

			default:
				WP_CLI::error('Unknown source action. Use list, add, or remove.');
		}
	}

	/**
	 * Manage GitHub API cache.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : One supported action: flush.
	 *
	 * ## EXAMPLES
	 *
	 *     wp gpw cache flush
	 *
	 * @param array<int, string>         $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cache(array $args, array $assoc_args): void {
		unset($assoc_args);

		if (('flush' !== ($args[0] ?? ''))) {
			WP_CLI::error('Unknown cache action. Use flush.');
		}

		$this->github_api->flush_cache();
		wp_clean_plugins_cache(true);
		WP_CLI::success('GitHub cache cleared.');
	}

	/**
	 * Manage discovered plugins.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : One of: list, install, update, uninstall.
	 *
	 * [<repository>]
	 * : Repository in owner/repo format for install, update, or uninstall.
	 *
	 * ## EXAMPLES
	 *
	 *     wp gpw plugins list
	 *     wp gpw plugins install owner/repo
	 *     wp gpw plugins update owner/repo
	 *     wp gpw plugins uninstall owner/repo
	 *
	 * @param array<int, string>         $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function plugins(array $args, array $assoc_args): void {
		unset($assoc_args);

		$action         = $args[0] ?? '';
		$repo_full_name = $args[1] ?? '';

		switch ($action) {
			case 'list':
				$this->plugins_list();
				return;

			case 'install':
				$this->plugins_install($repo_full_name);
				return;

			case 'update':
				$this->plugins_update($repo_full_name);
				return;

			case 'uninstall':
				$this->plugins_uninstall($repo_full_name);
				return;

			default:
				WP_CLI::error('Unknown plugins action. Use list, install, update, or uninstall.');
		}
	}

	/**
	 * Manage release channels.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : One of: get, set, set-default.
	 *
	 * [<repository>]
	 * : Repository in owner/repo format for get or set.
	 *
	 * [<channel>]
	 * : Channel value: stable or pre-release.
	 *
	 * ## EXAMPLES
	 *
	 *     wp gpw channel get owner/repo
	 *     wp gpw channel set owner/repo pre-release
	 *     wp gpw channel set-default stable
	 *
	 * @param array<int, string>         $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function channel(array $args, array $assoc_args): void {
		unset($assoc_args);

		$action = $args[0] ?? '';

		switch ($action) {
			case 'get':
				$this->channel_get($args[1] ?? '');
				return;

			case 'set':
				$this->channel_set($args[1] ?? '', $args[2] ?? '');
				return;

			case 'set-default':
				$this->channel_set_default($args[1] ?? '');
				return;

			default:
				WP_CLI::error('Unknown channel action. Use get, set, or set-default.');
		}
	}

	/**
	 * Render source list output.
	 *
	 * @return void
	 */
	private function source_list(): void {
		$sources = $this->get_sources();

		if (empty($sources)) {
			WP_CLI::log('No GitHub sources configured.');
			return;
		}

		$rows = array();
		foreach ($sources as $source) {
			$rows[] = array(
				'target'  => $source['target'],
				'has_pat' => '' !== $source['pat'] ? 'yes' : 'no',
			);
		}

		\WP_CLI\Utils\format_items('table', $rows, array('target', 'has_pat'));
	}

	/**
	 * Add or update a source.
	 *
	 * @param string $target GitHub owner or organization.
	 * @param string $pat    Optional GitHub PAT.
	 *
	 * @return void
	 */
	private function source_add(string $target, string $pat): void {
		$target = sanitize_text_field(trim($target));

		if ('' === $target) {
			WP_CLI::error('Source target is required.');
		}

		if ('' !== $pat) {
			$this->assert_valid_pat($pat);
		}

		$sources  = $this->get_sources();
		$updated  = false;
		$existing = false;

		foreach ($sources as &$source) {
			if (0 !== strcasecmp($source['target'], $target)) {
				continue;
			}

			$existing         = true;
			$source['target'] = $target;
			if ('' !== $pat) {
				$source['pat'] = $pat;
				$updated       = true;
			}
			break;
		}
		unset($source);

		if (! $existing) {
			$sources[] = array(
				'target' => $target,
				'pat'    => $pat,
			);
			$updated = true;
		}

		if (! $updated && $existing) {
			WP_CLI::success(sprintf('Source %s already exists. Nothing changed.', $target));
			return;
		}

		$this->save_sources($sources);

		WP_CLI::success(sprintf('%s source %s.', $existing ? 'Updated' : 'Added', $target));
	}

	/**
	 * Remove a source.
	 *
	 * @param string $target GitHub owner or organization.
	 *
	 * @return void
	 */
	private function source_remove(string $target): void {
		$target = sanitize_text_field(trim($target));

		if ('' === $target) {
			WP_CLI::error('Source target is required.');
		}

		$sources = $this->get_sources();
		$before  = count($sources);
		$sources = array_values(array_filter(
			$sources,
			static fn(array $source): bool => 0 !== strcasecmp($source['target'], $target)
		));

		if ($before === count($sources)) {
			WP_CLI::error(sprintf('Source %s was not found.', $target));
		}

		$this->save_sources($sources);
		WP_CLI::success(sprintf('Removed source %s.', $target));
	}

	/**
	 * Render the plugin inventory.
	 *
	 * @return void
	 */
	private function plugins_list(): void {
		$response = $this->rest_api->get_plugins();
		$data     = $response->get_data();

		if (! is_array($data)) {
			WP_CLI::error('Unexpected plugin list response.');
		}

		$plugins = isset($data['plugins']) && is_array($data['plugins']) ? $data['plugins'] : array();
		if (isset($data['message']) && is_string($data['message']) && '' !== $data['message']) {
			WP_CLI::warning($data['message']);
		}

		if (empty($plugins)) {
			WP_CLI::log('No plugins discovered.');
			return;
		}

		usort($plugins, static function (array $left, array $right): int {
			return strcmp((string) ($left['full_name'] ?? ''), (string) ($right['full_name'] ?? ''));
		});

		$rows = array();
		foreach ($plugins as $plugin) {
			$rows[] = array(
				'repository'        => (string) ($plugin['full_name'] ?? ''),
				'installed'         => ! empty($plugin['is_installed']) ? 'yes' : 'no',
				'tracked'           => ! empty($plugin['is_tracked']) ? 'yes' : 'no',
				'channel'           => (string) ($plugin['channel'] ?? GPW_Channel_Manager::CHANNEL_STABLE),
				'github_version'    => (string) ($plugin['version'] ?? ''),
				'installed_version' => (string) ($plugin['installed_version'] ?? ''),
				'update_available'  => ! empty($plugin['update_available']) ? 'yes' : 'no',
			);
		}

		\WP_CLI\Utils\format_items(
			'table',
			$rows,
			array('repository', 'installed', 'tracked', 'channel', 'github_version', 'installed_version', 'update_available')
		);
	}

	/**
	 * Install a plugin from GitHub.
	 *
	 * @param string $repo_full_name Repository full name.
	 *
	 * @return void
	 */
	private function plugins_install(string $repo_full_name): void {
		$repo_full_name = $this->assert_repo_full_name($repo_full_name);
		$channel        = $this->channel_manager->get_plugin_channel($repo_full_name);

		$result = $this->deployment_service->install_repository($repo_full_name, $channel);
		if (is_wp_error($result)) {
			WP_CLI::error($result->get_error_message());
		}

		$this->channel_manager->set_plugin_channel($repo_full_name, $channel);

		$plugin_file = is_array($result) && isset($result['plugin_file']) ? (string) $result['plugin_file'] : '';
		$version     = is_array($result) && isset($result['release']['tag_name']) ? (string) $result['release']['tag_name'] : '';

		WP_CLI::success(
			sprintf(
				'Installed %1$s%2$s%3$s using the %4$s channel.',
				$repo_full_name,
				'' !== $version ? ' (' : '',
				'' !== $version ? $version . ')' : '',
				$channel
			)
		);

		if ('' !== $plugin_file) {
			WP_CLI::log(sprintf('Plugin file: %s', $plugin_file));
		}
	}

	/**
	 * Update a plugin from GitHub.
	 *
	 * @param string $repo_full_name Repository full name.
	 *
	 * @return void
	 */
	private function plugins_update(string $repo_full_name): void {
		$repo_full_name = $this->assert_repo_full_name($repo_full_name);
		$plugin_file    = $this->resolve_plugin_file($repo_full_name);
		$channel        = $this->channel_manager->get_plugin_channel($repo_full_name);

		$result = $this->deployment_service->update_repository($repo_full_name, $plugin_file, $channel);
		if (is_wp_error($result)) {
			WP_CLI::error($result->get_error_message());
		}

		$this->channel_manager->set_plugin_channel($repo_full_name, $channel);

		$version = is_array($result) && isset($result['release']['tag_name']) ? (string) $result['release']['tag_name'] : '';

		WP_CLI::success(
			sprintf(
				'Updated %1$s%2$s%3$s using the %4$s channel.',
				$repo_full_name,
				'' !== $version ? ' (' : '',
				'' !== $version ? $version . ')' : '',
				$channel
			)
		);
	}

	/**
	 * Uninstall a plugin.
	 *
	 * @param string $repo_full_name Repository full name.
	 *
	 * @return void
	 */
	private function plugins_uninstall(string $repo_full_name): void {
		$repo_full_name = $this->assert_repo_full_name($repo_full_name);
		$plugin_file    = $this->resolve_plugin_file($repo_full_name);

		$this->load_plugin_dependencies();

		$is_network_active = is_multisite() && is_plugin_active_for_network($plugin_file);
		if ($is_network_active || is_plugin_active($plugin_file)) {
			deactivate_plugins($plugin_file, true, $is_network_active);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$deleted = delete_plugins(array($plugin_file));
		if (is_wp_error($deleted)) {
			WP_CLI::error($deleted->get_error_message());
		}

		$this->registry->remove($repo_full_name);
		$this->channel_manager->delete_plugin_channel($repo_full_name);
		wp_clean_plugins_cache(true);

		WP_CLI::success(sprintf('Uninstalled %s.', $repo_full_name));
	}

	/**
	 * Get a repository channel.
	 *
	 * @param string $repo_full_name Repository full name.
	 *
	 * @return void
	 */
	private function channel_get(string $repo_full_name): void {
		$repo_full_name = $this->assert_repo_full_name($repo_full_name);
		WP_CLI::log($this->channel_manager->get_plugin_channel($repo_full_name));
	}

	/**
	 * Set a repository channel.
	 *
	 * @param string $repo_full_name Repository full name.
	 * @param string $channel        Channel value.
	 *
	 * @return void
	 */
	private function channel_set(string $repo_full_name, string $channel): void {
		$repo_full_name = $this->assert_repo_full_name($repo_full_name);
		$channel        = $this->assert_channel($channel);

		$this->channel_manager->set_plugin_channel($repo_full_name, $channel);
		$this->github_api->flush_cache();

		WP_CLI::success(sprintf('Set %s to the %s channel.', $repo_full_name, $channel));
	}

	/**
	 * Set the default channel.
	 *
	 * @param string $channel Channel value.
	 *
	 * @return void
	 */
	private function channel_set_default(string $channel): void {
		$channel = $this->assert_channel($channel);

		$this->channel_manager->set_default_channel($channel);
		$this->github_api->flush_cache();

		WP_CLI::success(sprintf('Default channel set to %s.', $channel));
	}

	/**
	 * Get current sources with decrypted PATs.
	 *
	 * @return array<int, array{target: string, pat: string}>
	 */
	private function get_sources(): array {
		$settings = GPW_Context::get_option(self::SETTINGS_OPTION, array());
		if (! is_array($settings)) {
			return array();
		}

		$raw_sources = isset($settings['sources']) && is_array($settings['sources']) ? $settings['sources'] : array();
		$sources     = array();

		foreach ($raw_sources as $source) {
			if (! is_array($source)) {
				continue;
			}

			$target = isset($source['target']) ? sanitize_text_field((string) $source['target']) : '';
			$pat    = isset($source['pat']) ? (string) $source['pat'] : '';

			if ('' === $target) {
				continue;
			}

			if ('' !== $pat && GPW_Encryption::is_encrypted($pat)) {
				$pat = GPW_Encryption::decrypt($pat);
			}

			$sources[] = array(
				'target' => $target,
				'pat'    => $pat,
			);
		}

		return $sources;
	}

	/**
	 * Save sources using the same option format as the admin UI.
	 *
	 * @param array<int, array{target: string, pat: string}> $sources Sources to persist.
	 *
	 * @return void
	 */
	private function save_sources(array $sources): void {
		$old_settings = GPW_Context::get_option(self::SETTINGS_OPTION, array());
		if (! is_array($old_settings)) {
			$old_settings = array();
		}

		$old_sources = isset($old_settings['sources']) && is_array($old_settings['sources']) ? $old_settings['sources'] : array();
		$new_sources = array();

		foreach ($sources as $source) {
			$target = sanitize_text_field(trim($source['target']));
			$pat    = trim($source['pat']);

			if ('' === $target) {
				continue;
			}

			if ('' !== $pat) {
				$encrypted = GPW_Encryption::encrypt($pat);
				if (is_wp_error($encrypted)) {
					WP_CLI::error($encrypted->get_error_message());
				}
				$pat = $encrypted;
			}

			$new_sources[] = array(
				'target' => $target,
				'pat'    => $pat,
			);
		}

		if (wp_json_encode($old_sources) !== wp_json_encode($new_sources)) {
			$this->github_api->flush_cache();
		}

		GPW_Context::update_option(self::SETTINGS_OPTION, array('sources' => $new_sources));

		foreach ($new_sources as $source) {
			if ('' !== $source['pat']) {
				GPW_Encryption::store_sentinel();
				break;
			}
		}
	}

	/**
	 * Resolve the installed plugin file for a repository.
	 *
	 * @param string $repo_full_name Repository full name.
	 *
	 * @return string
	 */
	private function resolve_plugin_file(string $repo_full_name): string {
		$this->load_plugin_dependencies();

		$installed_plugins = get_plugins();
		$plugin_file       = $this->registry->get_plugin_file($repo_full_name);

		if ('' !== $plugin_file && array_key_exists($plugin_file, $installed_plugins)) {
			return $plugin_file;
		}

		$repo_parts = explode('/', $repo_full_name, 2);
		$repo_name  = isset($repo_parts[1]) ? sanitize_text_field((string) $repo_parts[1]) : '';
		$plugin_file = $this->registry->find_plugin_file_by_repo_name($repo_name, $installed_plugins);

		if ('' === $plugin_file || ! array_key_exists($plugin_file, $installed_plugins)) {
			WP_CLI::error(sprintf('Plugin %s is not installed.', $repo_full_name));
		}

		return $plugin_file;
	}

	/**
	 * Require plugin admin helpers used by install/update/uninstall flows.
	 *
	 * @return void
	 */
	private function load_plugin_dependencies(): void {
		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Ensure a repository argument is valid.
	 *
	 * @param string $repo_full_name Repository full name.
	 *
	 * @return string
	 */
	private function assert_repo_full_name(string $repo_full_name): string {
		$repo_full_name = sanitize_text_field(trim($repo_full_name));

		if ('' === $repo_full_name || ! str_contains($repo_full_name, '/')) {
			WP_CLI::error('Repository must be in owner/repo format.');
		}

		return $repo_full_name;
	}

	/**
	 * Ensure a channel argument is supported.
	 *
	 * @param string $channel Channel name.
	 *
	 * @return string
	 */
	private function assert_channel(string $channel): string {
		$normalized = $this->channel_manager->normalize_channel($channel);
		if ($normalized !== $channel) {
			$channel = strtolower(trim($channel));
		}

		if (! in_array($normalized, array(GPW_Channel_Manager::CHANNEL_STABLE, GPW_Channel_Manager::CHANNEL_PRERELEASE), true)) {
			WP_CLI::error('Channel must be stable or pre-release.');
		}

		$channel = strtolower(trim($channel));
		if (! in_array($channel, array(GPW_Channel_Manager::CHANNEL_STABLE, GPW_Channel_Manager::CHANNEL_PRERELEASE), true)) {
			WP_CLI::error('Channel must be stable or pre-release.');
		}

		return $normalized;
	}

	/**
	 * Validate the PAT format before saving.
	 *
	 * @param string $pat GitHub PAT.
	 *
	 * @return void
	 */
	private function assert_valid_pat(string $pat): void {
		if (! preg_match('/^(ghp_[a-zA-Z0-9]{36,255}|github_pat_[a-zA-Z0-9_]{22,255}|[a-f0-9]{40})$/', $pat)) {
			WP_CLI::error('Invalid PAT format. Expected a GitHub personal access token.');
		}
	}

	/**
	 * Show root help when invoked without a subcommand.
	 *
	 * @return void
	 */
	public function __invoke(): void {
		WP_CLI::log('Available subcommands: source, cache, plugins, channel');
	}
}
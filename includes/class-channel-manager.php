<?php
declare(strict_types=1);
/**
 * Release channel manager for Git Repos Manager.
 *
 * @package GitPluginsWordPress
 */

defined('ABSPATH') || exit;

/**
 * Stores default and per-plugin release channel preferences.
 */
final class GPW_Channel_Manager {
	/**
	 * Stable release channel.
	 */
	public const CHANNEL_STABLE = 'stable';

	/**
	 * Pre-release channel.
	 */
	public const CHANNEL_PRERELEASE = 'pre-release';

	/**
	 * Default channel settings option key.
	 */
	private const SETTINGS_OPTION = 'gpw_channel_settings';

	/**
	 * Per-plugin channel option key.
	 */
	private const PLUGIN_CHANNELS_OPTION = 'gpw_plugin_channels';

	/**
	 * Get the saved default channel.
	 *
	 * @return string
	 */
	public function get_default_channel(): string {
		$settings = GPW_Context::get_option(self::SETTINGS_OPTION, array());
		if (! is_array($settings)) {
			$settings = array();
		}

		$channel = isset($settings['default_channel']) ? (string) $settings['default_channel'] : self::CHANNEL_STABLE;

		return $this->normalize_channel($channel);
	}

	/**
	 * Persist the default channel.
	 *
	 * @param string $channel Channel name.
	 *
	 * @return void
	 */
	public function set_default_channel(string $channel): void {
		GPW_Context::update_option(
			self::SETTINGS_OPTION,
			array('default_channel' => $this->normalize_channel($channel)),
			false
		);
	}

	/**
	 * Get the resolved channel for a repository.
	 *
	 * @param string $repo_full_name Repository full name.
	 *
	 * @return string
	 */
	public function get_plugin_channel(string $repo_full_name): string {
		$repo_full_name = sanitize_text_field($repo_full_name);
		if ('' === $repo_full_name) {
			return $this->get_default_channel();
		}

		$channels = $this->get_saved_plugin_channels();
		if (! isset($channels[$repo_full_name])) {
			return $this->get_default_channel();
		}

		return $this->normalize_channel((string) $channels[$repo_full_name]);
	}

	/**
	 * Get all explicit per-plugin channel overrides.
	 *
	 * @return array<string, string>
	 */
	public function get_saved_plugin_channels(): array {
		$channels = GPW_Context::get_option(self::PLUGIN_CHANNELS_OPTION, array());
		if (! is_array($channels)) {
			return array();
		}

		$normalized = array();
		foreach ($channels as $repo_full_name => $channel) {
			if (! is_string($repo_full_name) || '' === trim($repo_full_name)) {
				continue;
			}

			$normalized[sanitize_text_field($repo_full_name)] = $this->normalize_channel((string) $channel);
		}

		return $normalized;
	}

	/**
	 * Persist a per-plugin release channel.
	 *
	 * @param string $repo_full_name Repository full name.
	 * @param string $channel        Channel name.
	 *
	 * @return void
	 */
	public function set_plugin_channel(string $repo_full_name, string $channel): void {
		$repo_full_name = sanitize_text_field($repo_full_name);
		if ('' === $repo_full_name) {
			return;
		}

		$channels                   = $this->get_saved_plugin_channels();
		$channels[$repo_full_name]  = $this->normalize_channel($channel);

		GPW_Context::update_option(self::PLUGIN_CHANNELS_OPTION, $channels, false);
	}

	/**
	 * Delete a per-plugin channel override.
	 *
	 * @param string $repo_full_name Repository full name.
	 *
	 * @return void
	 */
	public function delete_plugin_channel(string $repo_full_name): void {
		$repo_full_name = sanitize_text_field($repo_full_name);
		if ('' === $repo_full_name) {
			return;
		}

		$channels = $this->get_saved_plugin_channels();
		unset($channels[$repo_full_name]);

		GPW_Context::update_option(self::PLUGIN_CHANNELS_OPTION, $channels, false);
	}

	/**
	 * Normalize channel names to supported values.
	 *
	 * @param string $channel Channel name.
	 *
	 * @return string
	 */
	public function normalize_channel(string $channel): string {
		$channel = strtolower(trim($channel));

		if (self::CHANNEL_PRERELEASE === $channel) {
			return self::CHANNEL_PRERELEASE;
		}

		return self::CHANNEL_STABLE;
	}
}
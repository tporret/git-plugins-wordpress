=== Git Plugins WordPress ===
Contributors: tporret
Donate link: http://porretto.com/donate
Tags: github, plugin-updates, deployment
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect GitHub repositories and distribute WordPress plugins via GitHub Release ZIP assets.

== Description ==

Git Plugins WordPress lets administrators discover, install, and update WordPress plugins hosted in GitHub repositories.

To appear in the Available Plugins screen, a repository must:
- Include the GitHub repository topic `wp-plugin`
- Publish at least one Release
- Attach a `.zip` file asset to that Release (content type must be `application/zip`)

Core capabilities:
- GitHub target settings (user or organization)
- Optional PAT support for private repositories and higher API limits
- Repository filtering by `wp-plugin` topic
- Native plugin installation through WordPress upgrader
- WordPress update integration for active repositories
- Plugin details modal with GitHub release notes rendering
- Caching and force-check utilities for predictable performance

== Installation ==

1. Upload this plugin to `/wp-content/plugins/` and activate it.
2. Go to Git Plugins > Settings.
3. Set your GitHub target name (user or organization).
4. Optionally add a GitHub Personal Access Token (PAT).
5. Go to Git Plugins > Available Plugins.
6. Mark repositories Active to opt in to update checks.
7. Install a plugin using the Install Now action.

== Frequently Asked Questions ==

= Why is my repository not listed? =
Only repositories with the `wp-plugin` topic are shown.

= Do I need a GitHub token? =
No for public repositories. Yes for private repositories, and recommended to avoid low unauthenticated rate limits.

= What release assets are accepted? =
Only `.zip` release assets with `application/zip` content type are accepted for installs and updates.

== Screenshots ==

1. Settings page for GitHub target and token.
2. Available Plugins list with Active and Install actions.
3. Native WordPress update notice for tracked repositories.

== Changelog ==

= 1.0.0 =
* Initial release.
* GitHub repository discovery by topic.
* Native install flow from GitHub release assets.
* Update integration for active repositories.
* Plugin details modal with release notes.
* Caching, lockout, and force-check controls.

== Upgrade Notice ==

= 1.0.0 =
Initial stable release.

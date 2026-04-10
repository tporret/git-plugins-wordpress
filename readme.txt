=== Git Repos Manager ===
Contributors: tporret
Donate link: http://porretto.com/donate
Tags: github, plugin-updates, deployment
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect GitHub repositories and distribute WordPress plugins via GitHub Release ZIP assets.

== Description ==

Git Repos Manager lets administrators discover, install, and update WordPress plugins hosted in GitHub repositories.

The plugin now uses a React-based admin interface for managing GitHub sources and available plugins.

To appear in the Available Plugins screen, a repository must:
- Include the GitHub repository topic `wp-plugin`
- Publish at least one Release
- Attach a `.zip` file asset to that Release (content type must be `application/zip`)

Core capabilities:
- Multi-source GitHub settings (multiple users/orgs)
- Optional PAT support for private repositories and higher API limits
- Encrypted PAT storage at rest (AES-256-GCM)
- Masked PAT display and clear-to-replace token workflow
- Repository filtering by `wp-plugin` topic
- Native plugin installation through WordPress upgrader
- WordPress update integration for active repositories
- Plugin install/uninstall and active toggle controls from the admin UI
- Caching controls including refresh and force cache flush

Security highlights:
- PATs are encrypted before persistence and decrypted only for outbound GitHub calls
- Settings endpoints are protected by WordPress capability checks
- GitHub authorization headers are scoped to approved GitHub hosts
- Encryption sentinel checks detect salt/key rotation and prompt PAT re-entry when needed

== Installation ==

1. Upload this plugin to `/wp-content/plugins/` and activate it.
2. Go to Git Plugins > Settings.
3. Add one or more GitHub sources (target user/org).
4. Optionally add a GitHub Personal Access Token (PAT) per source.
5. Save settings.
6. Go to Git Plugins > Available Plugins.
7. Install plugins and mark repositories Active to opt in to update checks.
8. Use Force Refresh Cache when you need immediate data refresh from GitHub.

== Frequently Asked Questions ==

= Why is my repository not listed? =
Only repositories with the `wp-plugin` topic are shown.

= Do I need a GitHub token? =
No for public repositories. Yes for private repositories, and recommended to avoid low unauthenticated rate limits.

= What PAT permissions are recommended? =
Fine-grained PAT with `Contents` read-only is recommended. For classic tokens, use `public_repo` for public repos, or `repo` only when private repository access is required.

= What release assets are accepted? =
Only `.zip` release assets with `application/zip` content type are accepted for installs and updates.

== Screenshots ==

1. Settings page for GitHub target and token.
2. Available Plugins list with Active and Install actions.
3. Native WordPress update notice for tracked repositories.

== Changelog ==

= 1.0.1 =
* Security hardening for PAT handling and encryption workflows.
* React admin workflow and documentation updates.
* Cache refresh and host-scoped token forwarding improvements.

= 1.0.0 =
* Initial release.
* GitHub repository discovery by topic.
* Native install flow from GitHub release assets.
* Update integration for active repositories.
* Plugin details modal with release notes.
* Caching, lockout, and force-check controls.
* React-based admin UI for sources and plugin management.
* Multi-source GitHub configuration.
* PAT encryption at rest and masked token UX.
* Force cache flush button for GitHub refreshes.
* Hardened token header scoping and key-rotation detection.

== Upgrade Notice ==

= 1.0.1 =
Security and reliability update with improved PAT handling and documentation.

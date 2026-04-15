# Git Repos Manager

Connect GitHub repositories and distribute WordPress plugins through GitHub Release ZIP assets.

## What It Does

- Provides a modern React-based WordPress admin interface for source and plugin management
- Supports multisite installs with a dedicated network-managed admin experience
- Fetches repositories from one or more configured GitHub users or organizations
- Shows only repositories explicitly tagged with the `wp-plugin` topic
- Supports stable and pre-release release channels with default and per-plugin selection
- Lets admins track repositories for update checks
- Shows a multisite Sites summary with on-demand subsite activation details
- Installs plugins using WordPress native upgrader APIs
- Injects updates into WordPress core plugin update checks
- Exposes WP-CLI commands for source, channel, cache, and plugin deployment workflows
- Supports authenticated downloads for private repos via GitHub PAT
- Exposes secured admin REST endpoints for settings, plugin actions, and cache refresh

## Repository Requirements

For a repo to appear and work correctly:

1. Add the repository topic `wp-plugin`
2. Create a GitHub Release
3. Attach a plugin ZIP asset to the release
4. Ensure the release asset has a `.zip` filename and `application/zip` content type

## Plugin Setup

1. Install and activate this plugin in WordPress
2. Open **Git Plugins > Settings**
	- On multisite, use **Network Admin > Git Plugins**
3. Add one or more **GitHub Sources** (target user/org + optional PAT)
4. Save settings
5. In **Available Plugins**, install plugins and toggle desired repos as **Tracked**
6. Use **Force Refresh Cache** when you need a fresh GitHub pull immediately

## Multisite Behavior

- Single-site installs continue to use site-local settings and cache
- Multisite installs store sources, encrypted PATs, tracked repositories, API diagnostics, and cache at the network level
- The plugin adds a dedicated **Network Admin > Git Plugins** screen for multisite management
- Plugin tracking is network-wide, while WordPress activation state still follows normal site-active versus network-active rules
- The admin UI now shows tracking state separately from plugin activation state so network-active plugins are not confused with tracked repositories
- On multisite, the **Available Plugins** table includes a compact **Sites** summary column with a **View** action that opens a modal listing which subsites have the plugin active
- Site details are loaded lazily when requested so larger networks remain responsive

## Security and PAT Handling

- PAT values are encrypted at rest using OpenSSL AES-256-GCM
- PATs are never returned by the settings API; saved tokens are masked in UI
- Saved PAT fields are locked in UI until explicitly cleared for replacement
- Release ZIP assets are verified against published SHA-256 sidecar checksum files before extraction
- REST endpoints require admin capabilities (`manage_options`, plus install/delete plugin capabilities where appropriate)
- GitHub auth headers are only injected for approved GitHub hosts during download flows
- Encryption key-rotation detection alerts admins if WordPress salts changed and PATs must be re-entered

Recommended PAT permissions:

- Fine-grained PAT: `Contents` read-only
- Classic PAT: `public_repo` for public repos, or `repo` if private repos are needed

## Update Flow

- Active repositories are checked against their latest GitHub release
- Release checks respect the configured stable or pre-release channel for each managed plugin
- If release version is newer than the installed plugin version, WordPress shows an update notification
- Plugin details modal is populated from release metadata and release notes
- On multisite, super admins can review per-plugin subsite activation coverage without leaving the Available Plugins screen

## WP-CLI

- `wp gpw source list|add|remove`
- `wp gpw channel get|set|set-default`
- `wp gpw cache flush`
- `wp gpw plugins list|install|update|uninstall`

## Release Verification

- Managed installs and updates download the release ZIP plus its matching `.sha256` asset
- The plugin computes the ZIP SHA-256 hash locally and aborts before extraction if the fingerprint does not match
- The admin UI and CLI both surface whether a managed install has recorded SHA-256 verification metadata

## Caching and Rate Limits

- API responses are cached to reduce calls
- A lockout is applied when GitHub rate limiting is detected
- The UI includes a **Refresh** action and **Force Refresh Cache** action to clear cached GitHub data and reload

## Development Notes

- PHP 8.1+
- WordPress 6.0+
- React + Tailwind admin SPA built via `@wordpress/scripts`
- Main entry point: `git-plugins-wordpress.php`
- Core classes are in `includes/`
- The plugin uses a lightweight class autoloader for `GPW_` classes and preserves normal admin bootstrap behavior for both HTTP and WP-CLI contexts.
- Legacy single-source settings are migrated once during admin initialization into the new multi-source format, with PAT encryption and no plaintext upgrade path.

## License

GPLv2 or later

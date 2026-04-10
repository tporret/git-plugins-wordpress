# Git Repos Manager

Connect GitHub repositories and distribute WordPress plugins through GitHub Release ZIP assets.

## What It Does

- Provides a modern React-based WordPress admin interface for source and plugin management
- Fetches repositories from one or more configured GitHub users or organizations
- Shows only repositories explicitly tagged with the `wp-plugin` topic
- Lets admins mark repositories as Active for update tracking
- Installs plugins using WordPress native upgrader APIs
- Injects updates into WordPress core plugin update checks
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
3. Add one or more **GitHub Sources** (target user/org + optional PAT)
4. Save settings
5. In **Available Plugins**, install plugins and toggle desired repos as **Active**
6. Use **Force Refresh Cache** when you need a fresh GitHub pull immediately

## Security and PAT Handling

- PAT values are encrypted at rest using OpenSSL AES-256-GCM
- PATs are never returned by the settings API; saved tokens are masked in UI
- Saved PAT fields are locked in UI until explicitly cleared for replacement
- REST endpoints require admin capabilities (`manage_options`, plus install/delete plugin capabilities where appropriate)
- GitHub auth headers are only injected for approved GitHub hosts during download flows
- Encryption key-rotation detection alerts admins if WordPress salts changed and PATs must be re-entered

Recommended PAT permissions:

- Fine-grained PAT: `Contents` read-only
- Classic PAT: `public_repo` for public repos, or `repo` if private repos are needed

## Update Flow

- Active repositories are checked against their latest GitHub release
- If release version is newer than the installed plugin version, WordPress shows an update notification
- Plugin details modal is populated from release metadata and release notes

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

## License

GPLv2 or later

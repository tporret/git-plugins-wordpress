# Git Plugins WordPress

Connect GitHub repositories and distribute WordPress plugins through GitHub Release ZIP assets.

## What It Does

- Fetches repositories from a configured GitHub user or organization
- Shows only repositories explicitly tagged with the `wp-plugin` topic
- Lets admins mark repositories as Active for update tracking
- Installs plugins using WordPress native upgrader APIs
- Injects updates into WordPress core plugin update checks
- Supports authenticated downloads for private repos via GitHub PAT

## Repository Requirements

For a repo to appear and work correctly:

1. Add the repository topic `wp-plugin`
2. Create a GitHub Release
3. Attach a plugin ZIP asset to the release
4. Ensure the release asset has a `.zip` filename and `application/zip` content type

## Plugin Setup

1. Install and activate this plugin in WordPress
2. Open **Git Plugins > Settings**
3. Set **GitHub Target Name** (user or organization)
4. Optionally set **GitHub Personal Access Token (PAT)**
5. Open **Git Plugins > Available Plugins**
6. Install plugins and mark desired repos as **Active** for update checks

## Update Flow

- Active repositories are checked against their latest GitHub release
- If release version is newer than the installed plugin version, WordPress shows an update notification
- Plugin details modal is populated from release metadata and release notes

## Caching and Rate Limits

- API responses are cached to reduce calls
- A lockout is applied when GitHub rate limiting is detected
- The Settings page includes a force-check utility to flush cache and trigger fresh checks

## Development Notes

- PHP 8.1+
- WordPress 6.0+
- Main entry point: `git-plugins-wordpress.php`
- Core classes are in `includes/`

## License

GPLv2 or later

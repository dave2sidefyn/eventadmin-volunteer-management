# Contributing to EventAdmin – Volunteer Management

Thanks for your interest in contributing! Here's how to get started.

## Development Setup

1. Set up a local WordPress installation (e.g. with [Local](https://localwp.com/) or [DDEV](https://ddev.com/))
2. Clone this repo into your plugins directory:
   ```bash
   git clone https://github.com/dave2sidefyn/eventadmin-volunteer-management.git wp-content/plugins/eventadmin-volunteer-management
   ```
3. Activate the plugin via WP Admin → Plugins
4. No build step needed — pure PHP, edit and refresh

## Submitting Changes

1. Fork the repository
2. Create a branch: `git checkout -b my-fix`
3. Make your changes
4. Open a Pull Request against `main` with a clear description of what and why

## Coding Standards

- Follow the [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- All user-facing strings must be wrapped with `__()` / `esc_html__()` using the text domain `eventadmin-volunteer-management`
- No new dependencies — this plugin has no Composer or npm setup by design

## Translations

Translation files live in `languages/`. If you add or change strings:
1. Update the `.pot` template
2. Update the relevant `.po` files
3. Compile to `.mo`

## Reporting Bugs

Please open a [GitHub Issue](https://github.com/dave2sidefyn/eventadmin-volunteer-management/issues) with steps to reproduce, WordPress version, and PHP version.

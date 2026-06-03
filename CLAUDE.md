# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**EventAdmin – Volunteer Management** is a WordPress plugin (v1.6.0) for managing volunteers at events. It allows organizers to create shifts, volunteers to self-register, and tracks sign-ups/cancellations.

- **Requires:** WordPress 5.8+, PHP 8.0+
- **No build system** – pure PHP plugin, no npm/composer/Makefile
- **Text domain:** `eventadmin-volunteer-management`

## Development Setup

This is a traditional WordPress plugin. To develop:
1. Symlink or copy this directory into a WordPress installation's `/wp-content/plugins/eventadmin-volunteer-management/`
2. Activate via WP Admin → Plugins
3. No compilation steps required

## Architecture

### Entry Point
`eventadmin-volunteer-management.php` – bootstraps the plugin, registers hooks, and includes all files from `includes/`.

### Custom Post Type & Data Storage
- **Post type:** `eventadmin_shift` – represents a single shift; not publicly queryable
- **Taxonomy:** `eventadmin_shift_category` – hierarchical departments with color term meta (`term_color`)
- **Shift meta keys:** `shift_start`, `shift_end`, `max_volunteers`, `assigned_user_{USER_ID}` (one entry per assigned volunteer)
- **User meta keys:** `eventadmin_phone`, `magic_login_token`, `magic_login_expire`
- **Plugin options:** prefixed `eventadmin_*` (limits, notification templates, sender config)

No custom database tables – everything uses WP post meta, user meta, and options.

### Key Modules (`includes/`)

| File | Purpose |
|------|---------|
| `post-types.php` | Registers `eventadmin_shift` CPT and `eventadmin_shift_category` taxonomy |
| `helpers.php` | Core validation logic: shift limits (year/month/week/day), overlap prevention, magic link auth, role registration |
| `registration.php` | Public registration form; creates users with `eventadmin_volunteer` role; supports magic link login and Nextend Social Login |
| `shiftselector.php` | Shortcode `[eventadmin_shiftselector]` – AJAX-driven frontend for signing up/cancelling shifts |
| `cockpit.php` | Shortcode `[eventadmin_cockpit]` – tabbed volunteer dashboard aggregating shiftselector + profile |
| `profile.php` | Shortcode `[eventadmin_profile]` – volunteer profile editing (name, phone, email) |
| `notifications.php` | Email notifications on signup/cancellation; template placeholders: `{first}`, `{last}`, `{title}`, `{desc}`, `{start}`, `{end}` |
| `admin/dashboard.php` | Admin overview with shift listing (paginated, filtered), manual assignment, CSV export, Chart.js analytics; results cached via transients (5 min) |
| `admin/settings.php` | Settings page with three tabs (General, Display, Communication); each tab uses its own WP settings group (`eventadmin_plugin_settings_general/display/communication`) to prevent cross-tab data loss on save |
| `admin/shift-metaboxes.php` | Custom metaboxes on shift edit screen (start/end datetime, max volunteers) |
| `admin/quick-edit.php` | Inline quick-edit for shifts in post list |
| `admin/import.php` | Demo data import and bulk delete tools |

### Business Rules (enforced in `helpers.php`)
1. User must be logged in to sign up
2. Shift must not be full (`assigned_user_*` count < `max_volunteers`)
3. Per-period limits: year / month / week / day (configurable, 0 = unlimited)
4. Optional overlap prevention (if enabled, no two shifts can overlap in time)
5. Cancellation deadline: `eventadmin_unassign_limit_hours` hours before shift start

### User Roles
- `eventadmin_volunteer` – custom role; read-only WP access, admin bar hidden
- `administrator` – full plugin access

### Frontend Shortcodes
- `[eventadmin_cockpit]` – main volunteer-facing page
- `[eventadmin_shiftselector]` – shift sign-up UI (also usable standalone)
- `[eventadmin_profile]` – profile editor
- `[eventadmin]` – alias

### AJAX Handlers
All AJAX actions use WP nonce verification and are registered in `shiftselector.php`:
- `wp_ajax_eventadmin_assign` / `wp_ajax_nopriv_eventadmin_assign`
- `wp_ajax_eventadmin_unassign` / `wp_ajax_nopriv_eventadmin_unassign`

### Assets
- `assets/js/cockpit.js` – tab switching and AJAX calls for the cockpit
- `assets/js/admin-charts.js` – Chart.js dashboard analytics (uses bundled `chart.umd.min.js`)
- `assets/js/quick-edit.js` – quick-edit row population
- CSS files in `assets/css/` correspond 1:1 to their PHP counterparts

### Internationalization
All strings use `__()` / `esc_html__()` with the text domain. The `.pot` template is at `languages/eventadmin-volunteer-management.pot`.

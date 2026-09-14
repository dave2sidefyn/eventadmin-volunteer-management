# EventAdmin – Volunteer Management

A self-service volunteer roster for events, built as a WordPress plugin. Create shifts, let volunteers sign themselves up, and keep the overview — no page builder, no external service, everything runs in your own WordPress install.

[WordPress.org plugin page](https://wordpress.org/plugins/eventadmin-volunteer-management/) · [Reviews](https://wordpress.org/plugins/eventadmin-volunteer-management/#reviews) · [Support forum](https://wordpress.org/support/plugin/eventadmin-volunteer-management/)

## How it works

- **Set up shifts.** Each shift has a time slot, a department, and a minimum/maximum number of volunteers. Departments can be nested and colour-coded.
- **One page for volunteers.** Drop the `[eventadmin]` shortcode on a page. Visitors who aren't logged in see a short registration form — Google/Facebook/X sign-in via the free Nextend Social Login plugin, or a password-free magic email link. Once registered, the same page becomes their dashboard: open shifts to join, the shifts they've taken, and their profile.
- **Volunteers manage themselves.** They sign up and cancel on their own, up to an optional deadline. Limits you set — shifts per day, week, month, or year, plus optional overlap prevention — are enforced automatically.
- **You stay in control.** The admin overview shows who signed up and which shifts are understaffed, as a Dashboard, Cards, a Table, or a drag-to-reschedule Timeline. Assign or create volunteers by hand, import or export CSV, and send bulk emails to chase the gaps.
- **Delegate without giving out Administrator.** Two extra roles — Shift Manager and Volunteer Manager — hand off day-to-day work to other organizers without full admin access.
- **Recruit in public.** An optional `[eventadmin_open_positions]` list shows visitors where help is still needed before they register — no login required.

New installs get a step-by-step "Getting started" checklist right on the dashboard. For the full feature list and a walkthrough of every setting, see the in-plugin Documentation page (Shifts → Documentation) once the plugin is active, or [`readme.txt`](readme.txt) for the WordPress.org description.

## Requirements

- WordPress 5.8+
- PHP 8.0+
- No build step — pure PHP, no npm/Composer/Makefile

## Installation

**From WordPress.org (recommended):** search for "EventAdmin – Volunteer Management" under Plugins → Add New, or install directly from the [plugin page](https://wordpress.org/plugins/eventadmin-volunteer-management/).

**From this repo (for development):**

```bash
git clone https://github.com/dave2sidefyn/eventadmin-volunteer-management.git wp-content/plugins/eventadmin-volunteer-management
```

Activate via WP Admin → Plugins. No compilation needed — edit and refresh.

## Contributing

Bug reports, translations, and pull requests are welcome — see [`CONTRIBUTING.md`](CONTRIBUTING.md) for the development setup and coding standards.

## Developer Reference: Email Template Customization

All plugin emails are sent through a shared HTML wrapper in [`includes/notifications.php`](includes/notifications.php). This affects registration/magic-link emails, volunteer assignment and cancellation confirmations, organizer notifications, and bulk announcement emails.

The default wrapper provides a simple branded card layout with header, content area, and footer. Integrators can customize this without editing plugin core, using WordPress filters in the theme or a small companion plugin.

### Available Filters

`eventadmin_email_template_args`

Adjust wrapper variables before the final HTML is built. Available keys: `site_name`, `preheader`, `heading`, `footer_text`.

```php
add_filter('eventadmin_email_template_args', function (array $args, string $subject, string $message): array {
    $args['footer_text'] = 'Questions? Reply to this email or contact volunteers@example.org.';
    $args['heading'] = 'Volunteer Update';
    return $args;
}, 10, 3);
```

`eventadmin_email_template_message_html`

Modify only the inner content while keeping the default EventAdmin wrapper.

```php
add_filter('eventadmin_email_template_message_html', function (string $message_html, string $subject, array $args): string {
    return $message_html . '<p style="margin-top:24px;color:#66788a;">Thank you for supporting our event.</p>';
}, 10, 3);
```

`eventadmin_email_template_html`

Completely replace the final HTML output.

```php
add_filter('eventadmin_email_template_html', function (string $html, string $subject, string $message_html, array $args): string {
    return '<html><body style="font-family:Arial,sans-serif;">'
        . '<h1>' . esc_html($subject) . '</h1>'
        . $message_html
        . '</body></html>';
}, 10, 4);
```

**Notes for integrators:**

- Prefer filters over editing plugin files directly, so updates remain safe.
- The message body configured in WordPress settings is inserted into the wrapper as HTML.
- If the body contains plain text only, EventAdmin automatically converts paragraphs for better formatting.
- Custom email subjects and message bodies for assignment and cancellation remain configurable in the plugin settings.

## License

GPLv3 or later — see [`LICENSE`](LICENSE).

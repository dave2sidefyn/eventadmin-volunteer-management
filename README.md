# EventAdmin – Volunteer Management

WordPress plugin for managing volunteers at events. Shifts are stored as the `eventadmin_shift` post type, volunteers can self-register and self-assign, and admins can manage assignments, announcements, exports, and notification settings from the WordPress backend.

## Email Template Customization

All plugin emails are sent through a shared HTML wrapper in [notifications.php](includes/notifications.php). This affects:

- registration and magic-link emails
- volunteer assignment and cancellation confirmations
- organizer/admin notifications
- bulk announcement emails

The default wrapper provides a simple branded card layout with header, content area, and footer. Integrators can customize this without editing plugin core by using WordPress filters in the theme or in a small companion plugin.

### Available Filters

`eventadmin_email_template_args`

Use this to adjust wrapper variables before the final HTML is built.

Available keys:

- `site_name`
- `preheader`
- `heading`
- `footer_text`

Example:

```php
add_filter('eventadmin_email_template_args', function (array $args, string $subject, string $message): array {
    $args['footer_text'] = 'Questions? Reply to this email or contact volunteers@example.org.';
    $args['heading'] = 'Volunteer Update';
    return $args;
}, 10, 3);
```

`eventadmin_email_template_message_html`

Use this to modify only the inner content while keeping the default EventAdmin wrapper.

Example:

```php
add_filter('eventadmin_email_template_message_html', function (string $message_html, string $subject, array $args): string {
    return $message_html . '<p style="margin-top:24px;color:#66788a;">Thank you for supporting our event.</p>';
}, 10, 3);
```

`eventadmin_email_template_html`

Use this to completely replace the final HTML output.

Example:

```php
add_filter('eventadmin_email_template_html', function (string $html, string $subject, string $message_html, array $args): string {
    return '<html><body style="font-family:Arial,sans-serif;">'
        . '<h1>' . esc_html($subject) . '</h1>'
        . $message_html
        . '</body></html>';
}, 10, 4);
```

## Notes For Integrators

- Prefer filters over editing plugin files directly, so updates remain safe.
- The message body configured in WordPress settings is inserted into the wrapper as HTML.
- If the body contains plain text only, EventAdmin automatically converts paragraphs for better formatting.
- Custom email subjects and message bodies for assignment and cancellation remain configurable in the plugin settings.

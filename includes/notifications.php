<?php
/**
 * EventAdmin Volunteer Management - Notifications for volunteers and admins
 * All emails sent to volunteers and admins when a volunteer signs up for or cancels a shift.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Builds reusable email data for a shift and volunteer.
 *
 * @param int $user_id Volunteer ID.
 * @param int $shift_id Shift ID.
 * @param array<string, string|int> $extra_replacements Additional placeholders.
 * @return array<string, mixed>|null
 */
function eventadmin_get_shift_email_context(int $user_id, int $shift_id, array $extra_replacements = []): ?array
{
    $user  = get_userdata($user_id);
    $shift = get_post($shift_id);

    if (!$user || !$shift) {
        return null;
    }

    $start       = get_post_meta($shift_id, 'shift_start', true);
    $end         = get_post_meta($shift_id, 'shift_end', true);
    $defaults    = eventadmin_get_option_defaults();
    $date_format = get_option('eventadmin_shift_date_format', $defaults['eventadmin_shift_date_format']) ?: $defaults['eventadmin_shift_date_format'];
    $time_format = get_option('eventadmin_shift_time_format', $defaults['eventadmin_shift_time_format']) ?: $defaults['eventadmin_shift_time_format'];
    $start_dt    = date_i18n($date_format, strtotime($start));
    $end_dt      = date_i18n($time_format, strtotime($end));

    $organizer_email   = get_post_meta($shift_id, 'shift_organizer_email', true);
    $organizer_name    = get_post_meta($shift_id, 'shift_organizer_name', true);
    $organizer_user_id = absint(get_post_meta($shift_id, 'shift_organizer_user_id', true));
    $organizer_user    = $organizer_user_id ? get_userdata($organizer_user_id) : false;

    $linked_email = '';
    $linked_name  = '';
    if ($organizer_user instanceof WP_User) {
        $linked_email = $organizer_user->user_email;
        $linked_name  = $organizer_user->display_name ?: trim($organizer_user->first_name . ' ' . $organizer_user->last_name);
    }

    $notification_email = $organizer_email ?: ($linked_email ?: get_option('eventadmin_notification_email', get_bloginfo('admin_email')));
    $notification_name  = $organizer_name ?: ($linked_name ?: get_option('eventadmin_notification_email_name', ''));

    // Only fall back to the admin-controlled From when the organizer email is on an
    // external domain — avoids SPF/DKIM failures (e.g. organizer has a gmail.com address)
    // without silently overriding same-domain organizer addresses.
    $site_domain      = strtolower(parse_url(home_url(), PHP_URL_HOST) ?: '');
    $organizer_domain = strtolower(ltrim(strrchr($notification_email, '@'), '@'));
    $use_admin_from   = $organizer_domain && $organizer_domain !== $site_domain;

    $actual_from_email = $use_admin_from
        ? get_option('eventadmin_notification_email', get_bloginfo('admin_email'))
        : $notification_email;
    $actual_from_name  = $use_admin_from
        ? get_option('eventadmin_notification_email_name', '')
        : $notification_name;

    // Compute {days} from the shift start date so the placeholder works in all email types
    // (assign, unassign, reminder). For reminders, $extra_replacements overrides this with
    // the scheduled reminder day, which equals the computed value anyway.
    $start_ts   = strtotime($start);
    $days_until = ($start_ts > time())
        ? (int) ceil(($start_ts - time()) / DAY_IN_SECONDS)
        : 0;

    // Cast all values to string so strtr() is safe regardless of PHP version.
    $extra_strings = array_map('strval', $extra_replacements);

    $replacements = array_merge([
        '{first}' => (string) $user->first_name,
        '{last}'  => (string) $user->last_name,
        '{title}' => (string) $shift->post_title,
        '{desc}'  => (string) wp_strip_all_tags($shift->post_content),
        '{start}' => (string) $start_dt,
        '{end}'   => (string) $end_dt,
        '{days}'  => (string) $days_until,
    ], $extra_strings);

    return [
        'user'              => $user,
        'shift'             => $shift,
        'replacements'      => $replacements,
        'notification_name' => $notification_name,
        'notification_email'=> $notification_email,
        'headers'           => [
            'From: ' . $actual_from_name . ' <' . $actual_from_email . '>',
            'Reply-To: ' . $notification_name . ' <' . $notification_email . '>',
            'Content-Type: text/html; charset=UTF-8',
        ],
    ];
}

/**
 * Sends a notification to the admin and the volunteer when a volunteer signs up for or cancels a shift.
 *
 * @param int $user_id ID of the volunteer
 * @param int $shift_id ID of the shift
 * @param string $action 'assign' or 'unassign'
 * @param bool $send_admin Whether to send the admin/organizer notification email
 * @param bool $send_volunteer Whether to send the volunteer notification email
 */
function eventadmin_send_shift_un_assignment_notification(
    int $user_id,
    int $shift_id,
    string $action,
    bool $send_admin = true,
    bool $send_volunteer = true
): void
{
    $context = eventadmin_get_shift_email_context($user_id, $shift_id);
    if (!$context) {
        return;
    }

    $user         = $context['user'];
    $shift        = $context['shift'];
    $replacements = $context['replacements'];
    $action_label = $action === 'assign'
        ? esc_html__('assigned', 'eventadmin-volunteer-management')
        : esc_html__('removed', 'eventadmin-volunteer-management');

    if ($send_admin) {
        eventadmin_send_HTML_e_mail(
            $context['notification_email'],
            sprintf(
                /* translators: %1$s = action, %2$s = title of shift */
                esc_html__('Volunteer was %1$s: %2$s', 'eventadmin-volunteer-management'),
                $action_label,
                $shift->post_title
            ),
            sprintf(
                /* translators: %1$s = first name, %2$s = last name, %3$s = action, %4$s = title of shift */
                esc_html__('The volunteer %1$s, %2$s was %3$s for the shift: %4$s', 'eventadmin-volunteer-management'),
                $user->first_name,
                $user->last_name,
                $action_label,
                $shift->post_title
            ),
            $context['headers']
        );
    }

    // User email
    $defaults = eventadmin_get_option_defaults();
    if ($action === 'assign') {
        $subject_template = get_option('eventadmin_email_subject_assign') ?: $defaults['eventadmin_email_subject_assign'];
        $message_template = get_option('eventadmin_email_text_assign') ?: $defaults['eventadmin_email_text_assign'];
    } else {
        $subject_template = get_option('eventadmin_email_subject_unassign') ?: $defaults['eventadmin_email_subject_unassign'];
        $message_template = get_option('eventadmin_email_text_unassign') ?: $defaults['eventadmin_email_text_unassign'];
    }

    // Volunteer notification (skip for offline volunteers who have no real email)
    if ($send_volunteer && !get_user_meta($user_id, 'eventadmin_offline_volunteer', true)) {
        eventadmin_send_HTML_e_mail(
            $user->user_email,
            strtr($subject_template, $replacements),
            wpautop(strtr($message_template, $replacements)),
            $context['headers']
        );
    }
}

/**
 * Sends a reminder email for an upcoming shift.
 *
 * @param int $user_id Volunteer ID.
 * @param int $shift_id Shift ID.
 * @param int $days_before Number of days before shift start.
 */
function eventadmin_send_shift_reminder_notification(int $user_id, int $shift_id, int $days_before): void
{
    if ($days_before < 1 || get_user_meta($user_id, 'eventadmin_offline_volunteer', true)) {
        return;
    }

    $context = eventadmin_get_shift_email_context($user_id, $shift_id, [
        '{days}' => $days_before,
    ]);

    if (!$context) {
        return;
    }

    $defaults         = eventadmin_get_option_defaults();
    $subject_template = get_option('eventadmin_email_subject_reminder') ?: $defaults['eventadmin_email_subject_reminder'];
    $message_template = get_option('eventadmin_email_text_reminder') ?: $defaults['eventadmin_email_text_reminder'];

    eventadmin_send_HTML_e_mail(
        $context['user']->user_email,
        strtr($subject_template, $context['replacements']),
        wpautop(strtr($message_template, $context['replacements'])),
        $context['headers']
    );
}

/**
 * Returns the configured reminder day offsets.
 *
 * @return int[]
 */
function eventadmin_get_reminder_days(): array
{
    $raw = (string) get_option('eventadmin_email_reminder_days', '');
    if ($raw === '') {
        return [];
    }

    $parts = preg_split('/[\s,;]+/', $raw);
    $days  = [];

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }

        // A plain (int) cast — not absint() — so a negative value like "-1" is
        // discarded rather than having its sign flipped into a real reminder day.
        $day = (int) $part;
        if ($day > 0) {
            $days[] = $day;
        }
    }

    $days = array_values(array_unique($days));
    sort($days, SORT_NUMERIC);

    return $days;
}

/**
 * Sends configured reminder emails for upcoming shifts.
 */
function eventadmin_send_scheduled_shift_reminders(): void
{
    $days = eventadmin_get_reminder_days();
    if (empty($days)) {
        return;
    }

    $tz          = wp_timezone();
    $today       = new DateTimeImmutable('now', $tz);
    $today_date  = $today->format('Y-m-d');
    $latest_day  = max($days);
    $window_end  = $today->modify('+' . ($latest_day + 1) . ' days')->format('Y-m-d\TH:i');

    $shifts = get_posts([
        'post_type'   => 'eventadmin_shift',
        'numberposts' => -1,
        'meta_key'    => 'shift_start',
        'orderby'     => 'meta_value',
        'meta_type'   => 'DATETIME',
        'order'       => 'ASC',
        'meta_query'  => [[
            'key'     => 'shift_start',
            'value'   => [$today->format('Y-m-d\TH:i'), $window_end],
            'compare' => 'BETWEEN',
            'type'    => 'DATETIME',
        ]],
    ]);

    foreach ($shifts as $shift) {
        $start = get_post_meta($shift->ID, 'shift_start', true);
        if (!$start) {
            continue;
        }

        $shift_dt = new DateTimeImmutable($start, $tz);
        foreach ($days as $day) {
            $target_date = $shift_dt->modify('-' . $day . ' days')->format('Y-m-d');
            if ($target_date !== $today_date) {
                continue;
            }

            $meta = get_post_meta($shift->ID);
            foreach ($meta as $key => $values) {
                if (!str_starts_with($key, 'assigned_user_')) {
                    continue;
                }

                $user_id  = absint($values[0]);
                $sent_key = 'eventadmin_reminder_sent_' . $day . '_' . $user_id;
                if (get_post_meta($shift->ID, $sent_key, true)) {
                    continue;
                }

                eventadmin_send_shift_reminder_notification($user_id, $shift->ID, $day);
                update_post_meta($shift->ID, $sent_key, current_time('mysql'));
            }
        }
    }
}

add_action('eventadmin_send_shift_reminders', 'eventadmin_send_scheduled_shift_reminders');

/**
 * Clears stored reminder markers for a shift.
 *
 * @param int $shift_id Shift ID.
 */
function eventadmin_clear_shift_reminder_markers(int $shift_id): void
{
    $meta = get_post_meta($shift_id);
    foreach (array_keys($meta) as $key) {
        if (str_starts_with($key, 'eventadmin_reminder_sent_')) {
            delete_post_meta($shift_id, $key);
        }
    }
}


/**
 * Generates the sender header for emails.
 *
 * @return string[]
 */
function eventadmin_get_sender_header(): array
{
    $admin_email = get_option('eventadmin_notification_email', get_bloginfo('admin_email'));
    $admin_email_name = get_option('eventadmin_notification_email_name', '');
    return [
        'From: ' . $admin_email_name . ' <' . $admin_email . '>',
        'Content-Type: text/html; charset=UTF-8'
    ];
}

/**
 * Lightens a #rrggbb hex color by the given fraction (0–1), for deriving the second
 * gradient stop of the email header from a single admin-picked color.
 *
 * @param string $hex     A validated #rrggbb (or #rgb) color.
 * @param float  $percent 0–1, how far toward white to shift each channel.
 * @return string
 */
function eventadmin_adjust_color_brightness(string $hex, float $percent): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6) {
        return '#' . $hex;
    }

    $channels = array_map(function (string $channel) use ($percent): int {
        $value = hexdec($channel);
        return (int) round(max(0, min(255, $value + (255 - $value) * $percent)));
    }, str_split($hex, 2));

    return sprintf('#%02x%02x%02x', ...$channels);
}

/**
 * Returns the email body wrapped in the plugin's standard HTML template.
 *
 * Integrators can override the final HTML via the `eventadmin_email_template_html`
 * filter or adjust the template variables via `eventadmin_email_template_args`.
 *
 * @param string $subject Email subject
 * @param string $message Email body HTML or plain text
 * @param array $args Optional template arguments
 * @return string
 */
function eventadmin_wrap_email_template(string $subject, string $message, array $args = []): string
{
    $site_name    = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $logo_id      = (int) get_option('eventadmin_email_header_logo_id', 0);
    $header_color = get_option('eventadmin_email_header_color', '');
    $defaults     = [
        'site_name'          => $site_name,
        'preheader'          => wp_strip_all_tags($subject),
        'heading'            => $subject,
        'header_title'       => get_option('eventadmin_email_header_title', $site_name),
        'header_subtitle'    => get_option('eventadmin_email_header_subtitle', wp_specialchars_decode(get_bloginfo('description'), ENT_QUOTES)),
        'logo_url'           => $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '',
        'header_gradient'    => $header_color
            ? 'linear-gradient(135deg,' . $header_color . ' 0%,' . eventadmin_adjust_color_brightness($header_color, 0.3) . ' 100%)'
            : 'linear-gradient(135deg,#17324d 0%,#28587d 100%)',
        'header_text_color' => get_option('eventadmin_email_header_text_color', '#ffffff') ?: '#ffffff',
        'custom_css'  => get_option('eventadmin_email_custom_css', ''),
        'footer_text' => get_option('eventadmin_email_footer_html', '') ?: sprintf(
            /* translators: %s = site name */
            esc_html__('This email was sent by %s.', 'eventadmin-volunteer-management'),
            $site_name
        ),
    ];
    $args = apply_filters('eventadmin_email_template_args', wp_parse_args($args, $defaults), $subject, $message);

    $message_html = trim($message);
    if (!preg_match('/<(p|div|br|h[1-6]|ul|ol|li|table|blockquote)\b/i', $message_html)) {
        $message_html = wpautop($message_html);
    }
    $message_html = apply_filters('eventadmin_email_template_message_html', $message_html, $subject, $args);

    $html = '
<!DOCTYPE html>
<html lang="' . esc_attr(get_bloginfo('language')) . '">
<head>
    <meta charset="' . esc_attr(get_bloginfo('charset')) . '">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . esc_html($subject) . '</title>' . ($args['custom_css'] !== '' ? '
    <style>' . $args['custom_css'] . '</style>' : '') . '
</head>
<body style="margin:0;padding:0;background:#f3f5f7;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif;color:#1f2933;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">' . esc_html($args['preheader']) . '</div>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f3f5f7;margin:0;padding:24px 0;width:100%;">
        <tr>
            <td align="center">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:640px;background:#ffffff;border:1px solid #dde3ea;border-radius:14px;overflow:hidden;">
                    <tr>
                        <td style="padding:28px 32px;background:' . esc_attr($args['header_gradient']) . ';color:' . esc_attr($args['header_text_color']) . ';">
                            ' . (
                                $args['logo_url']
                                    ? '<img src="' . esc_url($args['logo_url']) . '" alt="' . esc_attr($args['header_title'] ?: $args['site_name']) . '" style="max-width:220px;max-height:80px;display:block;' . (($args['header_title'] || $args['header_subtitle']) ? 'margin-bottom:8px;' : '') . '">'
                                    : ''
                            )
                            . ($args['header_title'] ? '<div style="font-size:15px;font-weight:700;letter-spacing:.02em;">' . esc_html($args['header_title']) . '</div>' : '')
                            . ($args['header_subtitle'] ? '<div style="margin-top:2px;font-size:13px;opacity:.82;">' . esc_html($args['header_subtitle']) . '</div>' : '') . '
                            <div style="margin-top:8px;font-size:28px;line-height:1.25;font-weight:700;">' . esc_html($args['heading']) . '</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;font-size:16px;line-height:1.65;color:#243442;">
                            ' . $message_html . '
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 32px 32px;">
                            <div style="border-top:1px solid #e6ebf0;padding-top:16px;font-size:13px;line-height:1.6;color:#66788a;">
                                ' . wp_kses_post($args['footer_text']) . '
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';

    return apply_filters('eventadmin_email_template_html', $html, $subject, $message_html, $args);
}


/**
 *
 * Sends an HTML email.
 *
 * @param string|string[] $to Array or comma-separated list of email addresses to send message.
 * @param string $subject Email subject.
 * @param string $message Message contents.
 * @param string|string[] $headers Optional. Additional headers.
 * @param array $template_args Optional template arguments
 *
 * @return bool
 */
function eventadmin_send_HTML_e_mail(
    array|string $to,
    string $subject,
    string $message,
    array|string|null $headers = null,
    array $template_args = [],
    array $attachments = []
): bool
{
    $set_html = function () {
        return 'text/html';
    };
    add_filter('wp_mail_content_type', $set_html);
    $wrapped_message = eventadmin_wrap_email_template($subject, $message, $template_args);
    wp_mail(
        $to,
        $subject,
        $wrapped_message,
        $headers ?? eventadmin_get_sender_header(),
        $attachments
    );
    return remove_filter('wp_mail_content_type', $set_html);
}

/**
 * AJAX: renders a subject/body pair through the real email template (header logo, footer,
 * colors, everything) so admin screens can show an accurate visual preview instead of a
 * plain-text approximation — shared by the Settings page and the Send Announcement page.
 */
function eventadmin_ajax_render_email_preview(): void
{
    if (
        !isset($_POST['nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eventadmin_email_preview')
    ) {
        wp_send_json_error();
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error();
    }

    $subject = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '';
    $body    = isset($_POST['body']) ? wp_kses_post(wp_unslash($_POST['body'])) : '';

    // The Settings page previews the header logo / footer as currently edited (possibly
    // unsaved), so it can pass those in directly. When omitted (e.g. the Send Announcement
    // page, which has no logo/footer fields of its own), eventadmin_wrap_email_template()
    // falls back to whatever is actually saved.
    $args = [];
    if (isset($_POST['footer_html'])) {
        $footer_html      = wp_kses_post(wp_unslash($_POST['footer_html']));
        $args['footer_text'] = $footer_html !== '' ? $footer_html : sprintf(
            /* translators: %s = site name */
            esc_html__('This email was sent by %s.', 'eventadmin-volunteer-management'),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
        );
    }
    if (isset($_POST['logo_id'])) {
        $logo_id           = absint($_POST['logo_id']);
        $args['logo_url']  = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';
    }
    if (isset($_POST['header_title'])) {
        $args['header_title'] = sanitize_text_field(wp_unslash($_POST['header_title']));
    }
    if (isset($_POST['header_subtitle'])) {
        $args['header_subtitle'] = sanitize_text_field(wp_unslash($_POST['header_subtitle']));
    }
    if (isset($_POST['header_color'])) {
        $header_color           = sanitize_hex_color(wp_unslash($_POST['header_color']));
        $args['header_gradient'] = $header_color
            ? 'linear-gradient(135deg,' . $header_color . ' 0%,' . eventadmin_adjust_color_brightness($header_color, 0.3) . ' 100%)'
            : 'linear-gradient(135deg,#17324d 0%,#28587d 100%)';
    }
    if (isset($_POST['header_text_color'])) {
        $args['header_text_color'] = sanitize_hex_color(wp_unslash($_POST['header_text_color'])) ?: '#ffffff';
    }
    if (isset($_POST['custom_css'])) {
        $args['custom_css'] = wp_strip_all_tags(wp_unslash($_POST['custom_css']));
    }

    $html = eventadmin_wrap_email_template(
        $subject !== '' ? $subject : esc_html__('(no subject)', 'eventadmin-volunteer-management'),
        $body,
        $args
    );

    wp_send_json_success(['html' => $html]);
}

add_action('wp_ajax_eventadmin_render_email_preview', 'eventadmin_ajax_render_email_preview');

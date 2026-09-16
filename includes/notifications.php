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
 * Collects the department (taxonomy term) name(s) and description(s) assigned to a shift,
 * so an email template can pull a description written once per department (Shifts →
 * Departments) instead of one written on every single shift. Hidden departments (the
 * "Hide from volunteers" term option) are skipped, matching the volunteer-facing side.
 * When a shift has more than one department, names are joined with ", " and descriptions
 * with a blank line so each still renders as its own paragraph.
 *
 * @param int $shift_id Shift ID.
 * @return array{names: string, descriptions: string}
 */
function eventadmin_get_shift_department_text(int $shift_id): array
{
    $terms = wp_get_post_terms($shift_id, 'eventadmin_shift_category');
    if (is_wp_error($terms) || empty($terms)) {
        return ['names' => '', 'descriptions' => ''];
    }

    $names        = [];
    $descriptions = [];
    foreach ($terms as $term) {
        if (eventadmin_is_shift_category_hidden($term->term_id)) {
            continue;
        }
        $names[]     = $term->name;
        $description = trim(wp_strip_all_tags($term->description));
        if ($description !== '') {
            $descriptions[] = $description;
        }
    }

    return [
        'names'        => implode(', ', $names),
        'descriptions' => implode("\n\n", $descriptions),
    ];
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

    $department = eventadmin_get_shift_department_text($shift_id);

    $replacements = array_merge([
        '{first}'           => (string) $user->first_name,
        '{last}'            => (string) $user->last_name,
        '{title}'           => (string) $shift->post_title,
        '{desc}'            => (string) wp_strip_all_tags($shift->post_content),
        '{department}'      => $department['names'],
        '{department_desc}' => $department['descriptions'],
        '{start}'           => (string) $start_dt,
        '{end}'             => (string) $end_dt,
        '{days}'            => (string) $days_until,
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
 * Appends one entry to a volunteer's own notification history (shown on their profile
 * page's "Notification history" section) — capped at the 50 most recent, the same limit
 * already used for the site-wide announcement log.
 *
 * $date defaults to now, which is all every real send site uses — it's only settable so
 * eventadmin_backfill_notification_log() can insert historical entries (reconstructed from
 * the site-wide send log) at their actual send date instead of "now". Because of that, the
 * list is re-sorted by date rather than assumed to already be newest-first, and a
 * date+type+subject duplicate is skipped so re-running that backfill can't double-log.
 *
 * @param int $user_id Volunteer ID.
 * @param string $type One of 'assign', 'unassign', 'reminder', 'announcement'.
 * @param string $subject The email subject actually sent.
 * @param int $shift_id Related shift, if any (0 for announcements).
 * @param string $date 'Y-m-d H:i:s', defaults to now.
 */
function eventadmin_log_volunteer_notification(int $user_id, string $type, string $subject, int $shift_id = 0, string $date = ''): void
{
    // For the Getting Started checklist (includes/admin/getting-started-checklist.php) — the
    // one place every actually-sent assignment confirmation passes through, regardless of
    // whether it came from self-signup (shiftselector.php) or a manual admin assignment.
    if ($type === 'assign' && !get_option('eventadmin_sent_assign_confirmation')) {
        update_option('eventadmin_sent_assign_confirmation', 1);
    }

    $log = get_user_meta($user_id, 'eventadmin_notification_log', true);
    if (!is_array($log)) {
        $log = [];
    }

    if ($date === '') {
        $date = current_time('mysql');
    }

    foreach ($log as $entry) {
        if (($entry['date'] ?? '') === $date && ($entry['type'] ?? '') === $type && ($entry['subject'] ?? '') === $subject) {
            return;
        }
    }

    $log[] = [
        'date'     => $date,
        'type'     => $type,
        'subject'  => $subject,
        'shift_id' => $shift_id,
    ];

    usort($log, static fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

    update_user_meta($user_id, 'eventadmin_notification_log', array_slice($log, 0, 50));
}

/**
 * One-time, best-effort backfill of eventadmin_notification_log from eventadmin_email_log —
 * the site-wide bulk-announcement history that already existed before per-volunteer logging
 * was added. That history records each send *job* (subject, date, and a targeting
 * descriptor like "user:Jane Doe" or "has_shift"), not the actual list of who received it,
 * so reconstruction is exact for a job that named one specific person, and only
 * approximate for a job that targeted a whole audience — "who currently matches that
 * targeting rule" stands in for "who matched it back on the send date", since the plugin
 * never snapshotted the real recipient list. Runs once per site (tracked by its own
 * option, independent of the plugin version) the next time it loads.
 */
function eventadmin_backfill_notification_log(): void
{
    if (get_option('eventadmin_notification_log_backfilled')) {
        return;
    }

    $jobs = get_option('eventadmin_email_log', []);
    if (is_array($jobs)) {
        foreach ($jobs as $job) {
            $subject = (string) ($job['subject'] ?? '');
            $date    = (string) ($job['date'] ?? '');
            if ($subject === '' || $date === '') {
                continue;
            }

            foreach (eventadmin_resolve_backfill_recipients((string) ($job['recipients'] ?? ''), $date) as $user_id) {
                // An offline volunteer (or one with no stored email) could never actually
                // have received an email — the real send path already excludes them from
                // every targeting query, so a match here can only be a stale/wrong one
                // (e.g. converted to offline since, or a coincidental name match).
                if (!eventadmin_user_can_receive_email($user_id)) {
                    continue;
                }
                eventadmin_log_volunteer_notification($user_id, 'announcement', $subject, 0, $date);
            }
        }
    }

    update_option('eventadmin_notification_log_backfilled', 1);
}

add_action('plugins_loaded', 'eventadmin_backfill_notification_log', 20);

/**
 * Whether a volunteer could actually receive an email at all — offline volunteers (no
 * login, no real address) never can, and a user record with no stored email address
 * can't either. Used to keep the notification-log backfill from attributing a
 * historical announcement to someone who was never a real recipient of it.
 */
function eventadmin_user_can_receive_email(int $user_id): bool
{
    if (get_user_meta($user_id, 'eventadmin_offline_volunteer', true)) {
        return false;
    }
    $user = get_userdata($user_id);
    return $user instanceof WP_User && $user->user_email !== '';
}

/**
 * Resolves a bulk-email job's targeting descriptor to the user IDs it would match today —
 * see eventadmin_backfill_notification_log() for why that's exact in the "user:Name" case
 * and only approximate for the audience-based ones.
 *
 * @param string $recipients e.g. 'user:Jane Doe', 'shift:Bar', 'has_shift'.
 * @param string $date The job's own send date, used as the "upcoming as of" reference point
 *                      for the audience-based cases (matching eventadmin_bulk_email_batch()'s
 *                      own shift_end >= now rule, just evaluated at a past "now").
 * @return int[]
 */
function eventadmin_resolve_backfill_recipients(string $recipients, string $date): array
{
    if (str_starts_with($recipients, 'user:')) {
        $name    = trim(substr($recipients, 5));
        $matches = [];
        foreach (get_users(['role' => 'eventadmin_volunteer', 'fields' => ['ID', 'first_name', 'last_name', 'display_name']]) as $u) {
            $full = trim($u->first_name . ' ' . $u->last_name);
            if ($name !== '' && ($full === $name || $u->display_name === $name)) {
                $matches[] = (int) $u->ID;
            }
        }
        // A name collision (or no match at all) can't be resolved safely — skip rather
        // than risk logging the message against the wrong person.
        return count($matches) === 1 ? $matches : [];
    }

    if (str_starts_with($recipients, 'shift:')) {
        $title  = trim(substr($recipients, 6));
        $shifts = $title === '' ? [] : get_posts([
            'post_type'   => 'eventadmin_shift',
            'title'       => $title,
            'post_status' => ['publish', 'trash'],
            'numberposts' => -1,
        ]);

        return eventadmin_collect_assigned_user_ids($shifts);
    }

    if ($recipients === 'has_shift') {
        $shifts = get_posts([
            'post_type'   => 'eventadmin_shift',
            'post_status' => ['publish', 'trash'],
            'numberposts' => -1,
            'meta_query'  => [[
                'key'     => 'shift_end',
                'value'   => $date,
                'compare' => '>=',
                'type'    => 'DATETIME',
            ]],
        ]);

        return eventadmin_collect_assigned_user_ids($shifts);
    }

    // 'all', 'subscribed', 'no_shift', 'category' etc. have no reliable current-day proxy
    // (they either depend on state the plugin never stored historically, or are too broad
    // to attribute to specific people with any confidence) — left unresolved rather than
    // guessed.
    return [];
}

/**
 * @param WP_Post[] $shifts
 * @return int[]
 */
function eventadmin_collect_assigned_user_ids(array $shifts): array
{
    $user_ids = [];
    foreach ($shifts as $shift) {
        foreach (get_post_meta($shift->ID) as $key => $val) {
            if (str_starts_with($key, 'assigned_user_')) {
                $user_ids[] = absint($val[0]);
            }
        }
    }
    return array_values(array_unique($user_ids));
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
    $replacements = $context['replacements'];
    $defaults     = eventadmin_get_option_defaults();

    if ($send_admin) {
        if ($action === 'assign') {
            $admin_subject_template = get_option('eventadmin_email_subject_admin_assign') ?: $defaults['eventadmin_email_subject_admin_assign'];
            $admin_message_template = get_option('eventadmin_email_text_admin_assign') ?: $defaults['eventadmin_email_text_admin_assign'];
        } else {
            $admin_subject_template = get_option('eventadmin_email_subject_admin_unassign') ?: $defaults['eventadmin_email_subject_admin_unassign'];
            $admin_message_template = get_option('eventadmin_email_text_admin_unassign') ?: $defaults['eventadmin_email_text_admin_unassign'];
        }

        eventadmin_send_HTML_e_mail(
            $context['notification_email'],
            strtr($admin_subject_template, $replacements),
            wpautop(strtr($admin_message_template, $replacements)),
            $context['headers']
        );
    }

    // User email
    if ($action === 'assign') {
        $subject_template = get_option('eventadmin_email_subject_assign') ?: $defaults['eventadmin_email_subject_assign'];
        $message_template = get_option('eventadmin_email_text_assign') ?: $defaults['eventadmin_email_text_assign'];
    } else {
        $subject_template = get_option('eventadmin_email_subject_unassign') ?: $defaults['eventadmin_email_subject_unassign'];
        $message_template = get_option('eventadmin_email_text_unassign') ?: $defaults['eventadmin_email_text_unassign'];
    }

    // Volunteer notification (skip for offline volunteers who have no real email)
    if ($send_volunteer && !get_user_meta($user_id, 'eventadmin_offline_volunteer', true)) {
        $volunteer_subject = strtr($subject_template, $replacements);
        eventadmin_send_HTML_e_mail(
            $user->user_email,
            $volunteer_subject,
            wpautop(strtr($message_template, $replacements)),
            $context['headers']
        );
        eventadmin_log_volunteer_notification($user_id, $action, $volunteer_subject, $shift_id);
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
    $subject          = strtr($subject_template, $context['replacements']);

    eventadmin_send_HTML_e_mail(
        $context['user']->user_email,
        $subject,
        wpautop(strtr($message_template, $context['replacements'])),
        $context['headers']
    );
    eventadmin_log_volunteer_notification($user_id, 'reminder', $subject, $shift_id);
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

    if (!current_user_can('eventadmin_manage_volunteers')) {
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

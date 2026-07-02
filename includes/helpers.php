<?php
/**
 * EventAdmin Volunteer Management - Helper functions
 * Enqueue styles, user-shift management, custom role and login check
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct access
}

/**
 * Enqueue styles for forms
 * Loads the CSS file for forms in the frontend
 */
function eventadmin_form_styles(): void
{
    $css_path = plugin_dir_path(__FILE__) . '../assets/css/forms.css';

    wp_enqueue_style(
        'eventadmin-form-css',
        plugin_dir_url(__FILE__) . '../assets/css/forms.css',
        [],
        file_exists($css_path) ? filemtime($css_path) : null
    );
}

add_action('wp_enqueue_scripts', 'eventadmin_form_styles');

/**
 * Returns the names of assigned users for a shift
 * @param int $shift_id The ID of the shift
 * @return array Array with user names
 */
function eventadmin_get_user_display_names(int $shift_id): array
{
    $meta = get_post_meta($shift_id);
    $names = [];

    foreach ($meta as $key => $val) {
        if (str_starts_with($key, 'assigned_user_')) {
            $user_id = (int)$val[0];
            if (!get_userdata($user_id)) {
                continue;
            }
            $first = get_user_meta($user_id, 'first_name', true);
            $last = get_user_meta($user_id, 'last_name', true);
            $names[] = esc_html($first . ' ' . strtoupper(mb_substr($last, 0, 1)) . '.');
        }
    }

    return $names;
}

/**
 * Counts the number of assigned users for a shift
 * @param int $shift_id The ID of the shift
 * @return int Number of assigned users
 */
function eventadmin_count_assignments(int $shift_id): int
{
    $meta = get_post_meta($shift_id);
    $count = 0;

    foreach ($meta as $key => $values) {
        if (str_starts_with($key, 'assigned_user_')) {
            if (get_userdata((int)$values[0])) {
                $count++;
            }
        }
    }

    return $count;
}

/**
 * Removes all shift assignments for a deleted user.
 * Prevents orphaned assigned_user_* meta from inflating shift counts.
 * @param int $user_id The ID of the deleted user
 */
function eventadmin_cleanup_user_assignments(int $user_id): void
{
    $shifts = get_posts([
        'post_type'   => 'eventadmin_shift',
        'numberposts' => -1,
        'fields'      => 'ids',
        'meta_query'  => [['key' => 'assigned_user_' . $user_id, 'compare' => 'EXISTS']],
    ]);

    foreach ($shifts as $shift_id) {
        delete_post_meta($shift_id, 'assigned_user_' . $user_id);
    }
}

add_action('deleted_user', 'eventadmin_cleanup_user_assignments');

/**
 * Returns the IDs of all shifts a user has taken in a given year
 * @param int $user_id The user ID
 * @param int $year The year for which the shifts should be queried
 * @return array Array with the IDs of the shifts
 */
function eventadmin_get_user_shifts(int $user_id, int $year): array
{
    $all_shifts = get_posts(['post_type' => 'eventadmin_shift', 'numberposts' => -1]);
    $user_shifts = [];
    foreach ($all_shifts as $shift) {
        $meta_key = 'assigned_user_' . $user_id;
        if (get_post_meta($shift->ID, $meta_key, true)) {
            $start = strtotime(get_post_meta($shift->ID, 'shift_start', true));
            if (gmdate('Y', $start) == $year) {
                $user_shifts[] = $shift->ID;
            }
        }
    }
    return $user_shifts;
}

/**
 * Registers a custom role "Volunteer"
 * @return void
 */
function eventadmin_register_custom_role(): void
{
    $display_name = esc_html__('Volunteer', 'eventadmin-volunteer-management');
    $role = add_role(
        'eventadmin_volunteer',
        $display_name,
        [
            'read' => true,
        ]
    );

    // If the role already exists, ensure display name and capabilities stay in sync.
    if (null === $role) {
        $existing = wp_roles()->get_role('eventadmin_volunteer');
        if ($existing) {
            if ($existing->name !== $display_name) {
                $existing->name                                   = $display_name;
                wp_roles()->roles['eventadmin_volunteer']['name'] = $display_name;
                wp_roles()->role_names['eventadmin_volunteer']    = $display_name;
                update_option(wp_roles()->role_key, wp_roles()->roles);
            }
            // Remove any explicit false caps (left over from older plugin versions) that would
            // silently override capabilities granted by a user's other roles, e.g. an
            // Administrator who is also a Volunteer losing access to wp-admin menus.
            // WP_Role::remove_cap() updates both the live role object (so it takes effect
            // immediately) and the persisted option, using the site's actual role option key
            // instead of a hardcoded name — required for custom table prefixes and multisite.
            foreach (['edit_posts', 'delete_posts'] as $cap) {
                if (isset($existing->capabilities[$cap])) {
                    $existing->remove_cap($cap);
                }
            }
        }
    }
}

add_action('init', 'eventadmin_register_custom_role');

/**
 * Checks if a user can take a shift
 * @param int $user_id The user ID
 * @param int $shift_id The shift ID
 * @return string|null Error message or 'ok' if all is fine
 */
function eventadmin_check_match_schicht_user(int $user_id, int $shift_id): ?string
{
    // Check if user is already assigned to this shift
    if (get_post_meta($shift_id, 'assigned_user_' . $user_id, true)) {
        return esc_html__('This volunteer is already assigned to this shift.', 'eventadmin-volunteer-management');
    }

    $start = strtotime(get_post_meta($shift_id, 'shift_start', true));
    $end = strtotime(get_post_meta($shift_id, 'shift_end', true));
    $year = gmdate('Y', $start);

    $user_shifts = eventadmin_get_user_shifts($user_id, $year);

    // Load limits from options
    $limits = [
        'year' => (int)get_option('eventadmin_limit_per_year'),
        'month' => (int)get_option('eventadmin_limit_per_month'),
        'week' => (int)get_option('eventadmin_limit_per_week'),
        'day' => (int)get_option('eventadmin_limit_per_day'),
    ];

    $counts = [
        'year' => 0,
        'month' => 0,
        'week' => 0,
        'day' => 0,
    ];

    foreach ($user_shifts as $sid) {
        $s_start = strtotime(get_post_meta($sid, 'shift_start', true));
        if (!$s_start) continue;

        if (gmdate('Y', $s_start) === gmdate('Y', $start)) $counts['year']++;
        if (gmdate('Ym', $s_start) === gmdate('Ym', $start)) $counts['month']++;
        if (gmdate('W', $s_start) === gmdate('W', $start)) $counts['week']++;
        if (gmdate('Ymd', $s_start) === gmdate('Ymd', $start)) $counts['day']++;
    }

    $period_names = [
        'year' => esc_html__('year', 'eventadmin-volunteer-management'),
        'month' => esc_html__('month', 'eventadmin-volunteer-management'),
        'week' => esc_html__('week', 'eventadmin-volunteer-management'),
        'day' => esc_html__('day', 'eventadmin-volunteer-management'),
    ];

    foreach ($limits as $period => $max) {
        if ($max > 0 && $counts[$period] >= $max) {
            $period_en = $period_names[$period] ?? $period; // fallback to English if not defined
            return sprintf(
            /* translators: %1$d = max number, %2$s = period (year/month/week/day) */
                esc_html__('You may only take %1$d shifts per %2$s.', 'eventadmin-volunteer-management'),
                $max,
                $period_en
            );
        }
    }

    // Check for overlaps if not allowed
    if (!get_option('eventadmin_allow_overlap')) {
        foreach ($user_shifts as $sid) {
            $s_start = strtotime(get_post_meta($sid, 'shift_start', true));
            $s_end = strtotime(get_post_meta($sid, 'shift_end', true));
            if (!($end <= $s_start || $start >= $s_end)) {
                return esc_html__('This shift overlaps with one you have already taken.', 'eventadmin-volunteer-management');
            }
        }
    }

    return 'ok';
}

/**
 * Checks the magic-link and logs the user in. The token is then deleted.
 * @return void
 */
function eventadmin_magic_login_check(): void
{
    if (
        isset($_GET['magic_login'], $_GET['uid'], $_GET['_wpnonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'eventadmin_magic_login')
    ) {
        $token = sanitize_text_field(wp_unslash($_GET['magic_login']));
        $user_id = absint($_GET['uid']);

        $saved_token = get_user_meta($user_id, 'magic_login_token', true);
        $expires = get_user_meta($user_id, 'magic_login_expire', true);

        if ($token === $saved_token && time() < $expires) {
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, true);
            delete_user_meta($user_id, 'magic_login_token');
            delete_user_meta($user_id, 'magic_login_expire');
            $redirect_to = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash($_GET['redirect_to'])) : site_url('/');
            if (!str_starts_with($redirect_to, home_url())) {
                $redirect_to = home_url('/');
            }
            wp_safe_redirect($redirect_to);
            exit;
        } else {
            wp_die(esc_html__('Login link has expired or is invalid.', 'eventadmin-volunteer-management'));
        }
    }
}

add_action('init', 'eventadmin_magic_login_check');

/**
 * Schedules the daily cleanup of unverified volunteer accounts.
 */
function eventadmin_schedule_cleanup(): void
{
    if (!wp_next_scheduled('eventadmin_cleanup_unverified')) {
        wp_schedule_event(time(), 'daily', 'eventadmin_cleanup_unverified');
    }
}

add_action('init', 'eventadmin_schedule_cleanup');

/**
 * Schedules hourly reminder processing for upcoming shifts.
 */
function eventadmin_schedule_shift_reminders(): void
{
    if (!wp_next_scheduled('eventadmin_send_shift_reminders')) {
        wp_schedule_event(time(), 'hourly', 'eventadmin_send_shift_reminders');
    }
}

add_action('init', 'eventadmin_schedule_shift_reminders');

/**
 * Deletes volunteer accounts that were registered but never verified via magic link.
 * Only removes users whose token has already expired and who have no shift assignments.
 */
function eventadmin_cleanup_unverified_volunteers(): void
{
    $users = get_users([
        'role'       => 'eventadmin_volunteer',
        'meta_query' => [
            'relation' => 'AND',
            ['key' => 'magic_login_token', 'compare' => 'EXISTS'],
            ['key' => 'magic_login_expire', 'value' => time(), 'compare' => '<', 'type' => 'NUMERIC'],
            ['key' => 'eventadmin_manually_added', 'compare' => 'NOT EXISTS'],
        ],
    ]);

    if (empty($users)) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/user.php';

    $log = get_option('eventadmin_cleanup_log', []);

    foreach ($users as $user) {
        $assigned = get_posts([
            'post_type'   => 'eventadmin_shift',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_query'  => [['key' => 'assigned_user_' . $user->ID, 'compare' => 'EXISTS']],
        ]);

        if (!empty($assigned)) {
            continue;
        }

        $log[] = [
            'time'  => time(),
            'name'  => trim($user->first_name . ' ' . $user->last_name) ?: $user->user_login,
            'email' => $user->user_email,
        ];

        wp_delete_user($user->ID);
    }

    // Keep only the 100 most recent log entries.
    update_option('eventadmin_cleanup_log', array_slice($log, -100), false);
}

add_action('eventadmin_cleanup_unverified', 'eventadmin_cleanup_unverified_volunteers');

/**
 * Formatting of the period for shifts
 * @param string $start Start time of the shift
 * @param string $end End time of the shift
 * @return string Formatted period
 */
function eventadmin_get_formatted_zeitraum(string $start, string $end): string
{
    $defaults     = eventadmin_get_option_defaults();
    $start_format = get_option('eventadmin_shift_date_format', $defaults['eventadmin_shift_date_format']) ?: $defaults['eventadmin_shift_date_format'];
    $end_format   = get_option('eventadmin_shift_time_format', $defaults['eventadmin_shift_time_format']) ?: $defaults['eventadmin_shift_time_format'];

    // date_i18n automatically translates weekday, month, etc. based on WP language
    $start_fmt = date_i18n($start_format, strtotime($start));
    $end_fmt   = date_i18n($end_format, strtotime($end));

    return $start_fmt . ' – ' . $end_fmt;
}

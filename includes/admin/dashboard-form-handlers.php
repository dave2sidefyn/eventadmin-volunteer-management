<?php
/**
 * EventAdmin Volunteer Management - Classic Form Submission Handlers
 * The non-AJAX admin_init POST handlers shared by every view (CSV export, unassign, move,
 * add volunteer, copy shifts) — the AJAX equivalents used by the Timeline live in
 * dashboard-tab-timeline.php instead.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

use JetBrains\PhpStorm\NoReturn;

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * This hook processes the form submits in the admin area
 *
 * @return void
 */
function eventadmin_admin_dashboard_admin_init(): void
{
    if (isset($_POST['eventadmin_export_shift']) &&
        check_admin_referer('eventadmin_export_shift', 'eventadmin_export_shift_nonce')) {
        eventadmin_export_shifts_csv([absint($_POST['eventadmin_export_shift'])]);
    }

    if (isset($_POST['eventadmin_export_all']) &&
        check_admin_referer('eventadmin_export_all', 'eventadmin_export_all_nonce')) {
        eventadmin_export_shifts_csv();
    }

    if (isset($_POST['eventadmin_admin_unassign']) && isset($_POST['shift_id'], $_POST['user_id']) &&
        check_admin_referer('eventadmin_unassign', 'eventadmin_unassign_nonce') &&
        isset($_SERVER['HTTP_REFERER'])) {
        $shift_id          = (int)$_POST['shift_id'];
        $user_id           = (int)$_POST['user_id'];
        $notify_volunteer  = !empty($_POST['notify_volunteer']);

        delete_post_meta($shift_id, 'assigned_user_' . $user_id);

        if ($notify_volunteer && !get_user_meta($user_id, 'eventadmin_offline_volunteer', true)) {
            eventadmin_send_shift_un_assignment_notification($user_id, $shift_id, 'unassign', false, true);
        }

        wp_safe_redirect(esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])));
        exit;
    }

    if (isset($_POST['eventadmin_move_volunteer']) && isset($_POST['from_shift_id'], $_POST['to_shift_id'], $_POST['user_id']) &&
        check_admin_referer('eventadmin_move_volunteer', 'eventadmin_move_volunteer_nonce') &&
        isset($_SERVER['HTTP_REFERER'])) {
        $from_shift_id    = absint($_POST['from_shift_id']);
        $to_shift_id      = absint($_POST['to_shift_id']);
        $user_id          = absint($_POST['user_id']);
        $notify_volunteer = !empty($_POST['notify_volunteer']);
        $meta_key         = 'assigned_user_' . $user_id;

        $to_shift = $to_shift_id ? get_post($to_shift_id) : null;

        if (!$from_shift_id || !$to_shift_id || $from_shift_id === $to_shift_id || !$user_id
            || !get_post_meta($from_shift_id, $meta_key, true)
            || !$to_shift || $to_shift->post_type !== 'eventadmin_shift') {
            global $eventadmin_form_error;
            $eventadmin_form_error = esc_html__('This volunteer is not assigned to this shift.', 'eventadmin-volunteer-management');
            return;
        }

        // Only ever move into a shift that's still upcoming and that still has room —
        // enforced here (not just as a dropdown filter) since the request could bypass it.
        $tz          = wp_timezone();
        $to_end      = get_post_meta($to_shift_id, 'shift_end', true);
        $to_end_ts   = $to_end ? (new DateTime($to_end, $tz))->getTimestamp() : 0;
        $now_ts      = (new DateTime('now', $tz))->getTimestamp();
        $to_max      = (int) get_post_meta($to_shift_id, 'max_volunteers', true);
        $to_assigned = eventadmin_count_assignments($to_shift_id);

        if ($to_end_ts < $now_ts) {
            global $eventadmin_form_error;
            $eventadmin_form_error = esc_html__('You can only move a volunteer into a shift that is still upcoming.', 'eventadmin-volunteer-management');
            return;
        }

        if ($to_assigned >= $to_max) {
            global $eventadmin_form_error;
            $eventadmin_form_error = esc_html__('This shift is already full.', 'eventadmin-volunteer-management');
            return;
        }

        // Remove the old assignment before validating the new shift — otherwise the
        // volunteer's own per-period-limit and overlap checks would count the shift
        // they're moving out of against the shift they're moving into.
        delete_post_meta($from_shift_id, $meta_key);

        $error = eventadmin_check_match_schicht_user($user_id, $to_shift_id);

        if ($error !== 'ok') {
            // Roll back — leave the volunteer in their original shift rather than in none.
            // Deliberately does NOT redirect: a redirect starts a fresh request where this
            // global is unset again, so the error would never actually reach the page.
            add_post_meta($from_shift_id, $meta_key, $user_id);
            global $eventadmin_form_error;
            $eventadmin_form_error = $error;
            return;
        }

        add_post_meta($to_shift_id, $meta_key, $user_id);

        if ($notify_volunteer && !get_user_meta($user_id, 'eventadmin_offline_volunteer', true)) {
            eventadmin_send_shift_un_assignment_notification($user_id, $from_shift_id, 'unassign', false, true);
            eventadmin_send_shift_un_assignment_notification($user_id, $to_shift_id, 'assign', false, true);
        }

        wp_safe_redirect(esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])));
        exit;
    }

    if (isset($_POST['eventadmin_admin_add_user'], $_POST['shift_id']) &&
        check_admin_referer('eventadmin_add_user', 'eventadmin_add_user_nonce')) {
        $shift_id         = (int)$_POST['shift_id'];
        $notify_volunteer = !empty($_POST['notify_volunteer']);

        if (!empty($_POST['assign_existing']) && !empty($_POST['existing_user_id'])) {
            // Path A: assign an existing volunteer directly
            $user = get_userdata((int)$_POST['existing_user_id']);
            if (!$user || !in_array('eventadmin_volunteer', (array)$user->roles)) {
                global $eventadmin_form_error;
                $eventadmin_form_error = esc_html__('Invalid volunteer selected.', 'eventadmin-volunteer-management');
                return;
            }
        } else {
            // Path B: create new (online or offline)
            $identifier = isset($_POST['user_identifier']) ? sanitize_email(wp_unslash($_POST['user_identifier'])) : '';
            $first      = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
            $last       = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
            $phone      = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));

            $user = $identifier ? (get_user_by('email', $identifier) ?: get_user_by('login', $identifier)) : null;

            if (!$user) {
                $is_offline = empty($identifier);
                $email      = $is_offline
                    ? 'offline_' . wp_generate_password(12, false) . '@volunteer.invalid'
                    : sanitize_email($identifier);
                $login      = $is_offline
                    ? 'volunteer_' . wp_generate_password(8, false)
                    : sanitize_user($identifier);

                $user_id = wp_insert_user([
                    'user_login' => $login,
                    'user_email' => $email,
                    'user_pass'  => wp_generate_password(),
                    'first_name' => $first,
                    'last_name'  => $last,
                    'role'       => 'eventadmin_volunteer',
                ]);

                if (is_wp_error($user_id)) {
                    wp_die('Error creating user: ' . esc_html($user_id->get_error_message()));
                }

                if ($phone) update_user_meta($user_id, 'eventadmin_phone', $phone);
                if ($is_offline) {
                    update_user_meta($user_id, 'eventadmin_offline_volunteer', '1');
                } else {
                    update_user_meta($user_id, 'eventadmin_manually_added', '1');
                }

                $user = get_userdata($user_id);
            } else {
                if ($first) update_user_meta($user->ID, 'first_name', $first);
                if ($last) update_user_meta($user->ID, 'last_name', $last);
                if ($phone) update_user_meta($user->ID, 'eventadmin_phone', $phone);
            }
        }

        $error = eventadmin_check_match_schicht_user($user->ID, $shift_id);

        if ($error !== 'ok') {
            global $eventadmin_form_error;
            $eventadmin_form_error = 'Error: ' . esc_html($error);
            return;
        }

        add_post_meta($shift_id, 'assigned_user_' . $user->ID, $user->ID);

        if ($notify_volunteer && !get_user_meta($user->ID, 'eventadmin_offline_volunteer', true)) {
            eventadmin_send_shift_un_assignment_notification($user->ID, $shift_id, 'assign', false, true);
        }

        wp_safe_redirect(add_query_arg(['added' => '1'], esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']))));
        exit;
    }

    if (isset($_POST['eventadmin_copy_shifts_day']) &&
        check_admin_referer('eventadmin_copy_shifts', 'eventadmin_copy_shifts_nonce')) {
        if (!current_user_can('eventadmin_manage_shifts')) {
            wp_die(esc_html__('Not allowed', 'eventadmin-volunteer-management'));
        }

        $source_date     = isset($_POST['copy_source_date']) ? sanitize_text_field(wp_unslash($_POST['copy_source_date'])) : '';
        $target_date     = isset($_POST['copy_target_date']) ? sanitize_text_field(wp_unslash($_POST['copy_target_date'])) : '';
        $copy_volunteers = !empty($_POST['copy_volunteers']);

        $is_valid_date = fn($d) => (bool) DateTime::createFromFormat('Y-m-d', $d);

        if (!$source_date || !$target_date || !$is_valid_date($source_date) || !$is_valid_date($target_date)) {
            global $eventadmin_form_error;
            $eventadmin_form_error = esc_html__('Please select both a source and a target date.', 'eventadmin-volunteer-management');
            return;
        }

        $copied = eventadmin_copy_shifts_to_day($source_date, $target_date, $copy_volunteers);

        wp_safe_redirect(add_query_arg(
            ['shifts_copied' => $copied],
            admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-shift-manager')
        ));
        exit;
    }

}

add_action('admin_init', 'eventadmin_admin_dashboard_admin_init');

/**
 * Exports shifts as CSV file
 *
 * @param array|null $shift_ids Array of shift IDs or null for all shifts
 */
#[NoReturn] function eventadmin_export_shifts_csv(array $shift_ids = null): void
{
    if (!function_exists('get_userdata')) {
        require_once ABSPATH . 'wp-includes/pluggable.php';
    }

    // Shift selection: all or specific
    if (is_null($shift_ids)) {
        $shifts = get_posts(['post_type' => 'eventadmin_shift', 'numberposts' => -1]);
        $filename = 'eventadmin_all.csv';
    } else {
        $shifts = array_map('get_post', $shift_ids);
        $title = sanitize_title($shifts[0]->post_title ?? 'eventadmin_shift');
        $filename = 'eventadmin_' . $title . '.csv';
    }

    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=$filename");

    $out = fopen("php://output", "w");
    // UTF-8 BOM so Excel (which does not auto-detect CSV encoding) doesn't mangle non-ASCII characters.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        esc_html__('Category', 'eventadmin-volunteer-management'),
        esc_html__('Shift', 'eventadmin-volunteer-management'),
        esc_html__('Period', 'eventadmin-volunteer-management'),
        esc_html__('Name', 'eventadmin-volunteer-management'),
        esc_html__('E-Mail', 'eventadmin-volunteer-management'),
        esc_html__('Phone', 'eventadmin-volunteer-management'),
        esc_html__('Start', 'eventadmin-volunteer-management'),
        esc_html__('End', 'eventadmin-volunteer-management')
    ]);

    foreach ($shifts as $shift) {
        if (!$shift instanceof WP_Post) continue;

        $meta = get_post_meta($shift->ID);
        $start = get_post_meta($shift->ID, 'shift_start', true);
        $end = get_post_meta($shift->ID, 'shift_end', true);
        $categories = wp_get_post_terms($shift->ID, 'eventadmin_shift_category');
        $category_names = implode(', ', wp_list_pluck($categories, 'name'));

        $has_assignment = false;
        foreach ($meta as $key => $val) {
            if (str_starts_with($key, 'assigned_user_')) {
                $uid = absint($val[0]);
                $u = get_userdata($uid);
                if (!$u) continue;

                $has_assignment = true;
                $is_offline_csv = (bool) get_user_meta($uid, 'eventadmin_offline_volunteer', true);
                fputcsv($out, [
                    $category_names,
                    $shift->post_title,
                    eventadmin_get_formatted_zeitraum($start, $end),
                    trim($u->first_name . ' ' . $u->last_name),
                    $is_offline_csv ? '' : $u->user_email,
                    get_user_meta($uid, 'eventadmin_phone', true),
                    $start,
                    $end
                ]);
            }
        }

        // Shifts with no volunteers still appear as a row so they are not silently omitted.
        if (!$has_assignment) {
            fputcsv($out, [
                $category_names,
                $shift->post_title,
                eventadmin_get_formatted_zeitraum($start, $end),
                '',
                '',
                '',
                $start,
                $end
            ]);
        }
    }

    exit;
}

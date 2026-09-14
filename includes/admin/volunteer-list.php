<?php
/**
 * EventAdmin Volunteer Management - Volunteer List
 * Admin overview of all registered volunteers with per-volunteer and per-shift messaging.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

use JetBrains\PhpStorm\NoReturn;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders a small "copy to clipboard" icon button (see the .eventadmin-copy-value click
 * handler in assets/js/volunteer-list.js) for a value the Volunteers list truncates for
 * display — e-mail, phone — but still needs to be copyable in full without selecting text.
 *
 * @param string $raw_value Unescaped value to copy (e.g. the full e-mail address or phone number).
 * @param string $label     Already-escaped title/aria-label text (e.g. from esc_attr__()).
 * @return string
 */
function eventadmin_render_copy_value_button(string $raw_value, string $label): string
{
    return '<button type="button" class="button-link eventadmin-copy-value" data-copy="' . esc_attr($raw_value) . '" title="' . $label . '" aria-label="' . $label . '" style="flex-shrink:0;line-height:1;">'
        . '<span class="dashicons dashicons-clipboard" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom;"></span></button>';
}

function eventadmin_volunteer_list_admin_menu(): void
{
    add_submenu_page(
        'edit.php?post_type=eventadmin_shift',
        esc_html__('Volunteers', 'eventadmin-volunteer-management'),
        esc_html__('Volunteers', 'eventadmin-volunteer-management'),
        'eventadmin_manage_volunteers',
        'eventadmin-volunteers',
        'eventadmin_volunteer_list_page'
    );
}

add_action('admin_menu', 'eventadmin_volunteer_list_admin_menu', 100);

function eventadmin_volunteer_list_page(): void
{
    $volunteers = get_users([
        'role'       => 'eventadmin_volunteer',
        'meta_query' => [['key' => 'eventadmin_offline_volunteer', 'compare' => 'NOT EXISTS']],
    ]);

    // All upcoming shifts and categories for the filter dropdowns
    $all_shifts = get_posts([
        'post_type'   => 'eventadmin_shift',
        'numberposts' => -1,
        'meta_key'    => 'shift_start',
        'orderby'     => ['title' => 'ASC', 'meta_value' => 'ASC'],
        'meta_type'   => 'DATETIME',
    ]);
    $all_categories = eventadmin_get_hierarchical_shift_categories();

    // Nonce-gated filters
    $filter_valid      = isset($_GET['eventadmin_vol_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['eventadmin_vol_nonce'])), 'eventadmin_vol_filter');
    $selected_shift    = $filter_valid && isset($_GET['filter_shift'])    ? absint($_GET['filter_shift'])    : 0;
    $selected_category = $filter_valid && isset($_GET['filter_category']) ? absint($_GET['filter_category']) : 0;

    // Filter by shift
    if ($selected_shift) {
        $shift_meta     = get_post_meta($selected_shift);
        $shift_user_ids = [];
        foreach ($shift_meta as $key => $val) {
            if (str_starts_with($key, 'assigned_user_')) {
                $shift_user_ids[] = absint($val[0]);
            }
        }
        $volunteers = array_filter($volunteers, fn($u) => in_array($u->ID, $shift_user_ids, true));
    }

    // Filter by department: matches volunteers with an assigned shift in the category
    // (or a sub-category) as well as volunteers explicitly linked to it via the "Edit
    // Departments" modal, since linking is meant to work independent of shift history
    // (see the same union in eventadmin_bulk_email_get_recipient_users()'s 'department_link'
    // branch in bulk-email.php).
    if ($selected_category) {
        $cat_shifts = get_posts([
            'post_type'   => 'eventadmin_shift',
            'numberposts' => -1,
            'fields'      => 'ids',
            'tax_query'   => [['taxonomy' => 'eventadmin_shift_category', 'field' => 'term_id', 'terms' => $selected_category]],
        ]);
        $cat_user_ids = [];
        foreach ($cat_shifts as $shift_id) {
            foreach (get_post_meta($shift_id) as $key => $val) {
                if (str_starts_with($key, 'assigned_user_')) {
                    $cat_user_ids[] = absint($val[0]);
                }
            }
        }

        $children        = get_term_children($selected_category, 'eventadmin_shift_category');
        $department_ids  = array_merge([$selected_category], is_array($children) ? $children : []);
        $linked_user_ids = get_users([
            'role'       => 'eventadmin_volunteer',
            'fields'     => 'ID',
            'meta_query' => [['key' => 'eventadmin_department', 'value' => $department_ids, 'compare' => 'IN']],
        ]);

        $cat_user_ids = array_unique(array_merge($cat_user_ids, array_map('absint', $linked_user_ids)));
        $volunteers   = array_filter($volunteers, fn($u) => in_array($u->ID, $cat_user_ids, true));
    }

    $blocked_log = get_option('eventadmin_blocked_log', []);
    $cleanup_log = get_option('eventadmin_cleanup_log', []);

    $tabs = [
        'volunteers' => esc_html__('Volunteers', 'eventadmin-volunteer-management'),
        /* translators: %d is the number of blocked registration attempts */
        'blocked'    => sprintf(esc_html__('Blocked registration attempts (%d)', 'eventadmin-volunteer-management'), count($blocked_log)),
        /* translators: %d is the number of auto-deleted unverified accounts */
        'cleanup'    => sprintf(esc_html__('Auto-deleted unverified accounts (%d)', 'eventadmin-volunteer-management'), count($cleanup_log)),
    ];
    $active_tab = isset($_GET['tab']) && array_key_exists(sanitize_key(wp_unslash($_GET['tab'])), $tabs)
        ? sanitize_key(wp_unslash($_GET['tab']))
        : 'volunteers';

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Volunteers', 'eventadmin-volunteer-management') . '</h1>';

    echo '<h2 class="nav-tab-wrapper">';
    foreach ($tabs as $slug => $label) {
        $url    = admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-volunteers&tab=' . $slug);
        $active = $active_tab === $slug ? ' nav-tab-active' : '';
        echo '<a class="nav-tab' . $active . '" href="' . esc_url($url) . '">' . $label . '</a>';
    }
    echo '</h2>';

    if ($active_tab === 'blocked') {
        if (empty($blocked_log)) {
            echo '<p><em>' . esc_html__('No blocked registration attempts.', 'eventadmin-volunteer-management') . '</em></p>';
        } else {
            echo '<table class="widefat striped" style="max-width:800px;margin-top:1rem;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Date', 'eventadmin-volunteer-management') . '</th>';
            echo '<th>' . esc_html__('E-Mail', 'eventadmin-volunteer-management') . '</th>';
            echo '<th>' . esc_html__('IP', 'eventadmin-volunteer-management') . '</th>';
            echo '<th>' . esc_html__('Provider', 'eventadmin-volunteer-management') . '</th>';
            echo '<th>' . esc_html__('Reason', 'eventadmin-volunteer-management') . '</th>';
            echo '</tr></thead><tbody>';
            foreach (array_reverse($blocked_log) as $entry) {
                echo '<tr>';
                echo '<td>' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $entry['time'])) . '</td>';
                echo '<td>' . esc_html($entry['email'] ?: '—') . '</td>';
                echo '<td>' . esc_html($entry['ip'] ?: '—') . '</td>';
                echo '<td>' . esc_html($entry['provider'] ?: '—') . '</td>';
                echo '<td>' . esc_html($entry['reason'] ?: '—') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
        return;
    }

    if ($active_tab === 'cleanup') {
        if (empty($cleanup_log)) {
            echo '<p><em>' . esc_html__('No auto-deleted unverified accounts.', 'eventadmin-volunteer-management') . '</em></p>';
        } else {
            echo '<div id="eventadmin-cleanup-log-section">';
            echo '<p style="margin-top:1rem;"><button type="button" id="eventadmin-clear-cleanup-log" class="button">' . esc_html__('Clear log', 'eventadmin-volunteer-management') . '</button></p>';
            echo '<table class="widefat striped" style="max-width:640px;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Date', 'eventadmin-volunteer-management') . '</th>';
            echo '<th>' . esc_html__('Name', 'eventadmin-volunteer-management') . '</th>';
            echo '<th>' . esc_html__('E-Mail', 'eventadmin-volunteer-management') . '</th>';
            echo '</tr></thead><tbody>';
            foreach (array_reverse($cleanup_log) as $entry) {
                echo '<tr>';
                echo '<td>' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $entry['time'])) . '</td>';
                echo '<td>' . esc_html($entry['name']) . '</td>';
                echo '<td>' . esc_html($entry['email']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';

            $volunteer_list_js_path = plugin_dir_path(__FILE__) . '../../assets/js/volunteer-list.js';
            wp_enqueue_script(
                'eventadmin-volunteer-list',
                plugin_dir_url(__FILE__) . '../../assets/js/volunteer-list.js',
                ['jquery'],
                file_exists($volunteer_list_js_path) ? filemtime($volunteer_list_js_path) : null,
                true
            );
            wp_localize_script('eventadmin-volunteer-list', 'EVENTADMIN_VOL', [
                'ajax_url'                => admin_url('admin-ajax.php'),
                'nonce_clear_cleanup_log' => wp_create_nonce('eventadmin_clear_cleanup_log'),
                'i18n'                    => [
                    'error'                     => esc_html__('An error occurred. Please try again.', 'eventadmin-volunteer-management'),
                    'clear_cleanup_log_confirm' => esc_html__('Clear the auto-deleted unverified accounts log? This cannot be undone.', 'eventadmin-volunteer-management'),
                ],
            ]);
        }
        echo '</div>';
        return;
    }

    // Single toolbar row: create/grant buttons, shift+category filters, and search — all
    // on one line (wrapping only on narrow screens) instead of three stacked full-width rows.
    echo '<div class="eventadmin-vol-toolbar" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin:1rem 0;">';

    echo '<div class="eventadmin-vol-actions" style="display:flex;gap:8px;flex-wrap:wrap;">';
    echo '<button type="button" class="button button-primary eventadmin-modal-open" data-target="#eventadmin-create-volunteer-modal">' . esc_html__('Create new volunteer', 'eventadmin-volunteer-management') . '</button>';
    echo '<button type="button" class="button eventadmin-modal-open" data-target="#eventadmin-grant-role-modal">' . esc_html__('Grant volunteer role', 'eventadmin-volunteer-management') . '</button>';
    // Exports the whole roster (every online volunteer), independent of the on-page shift/
    // category filter or the client-side search box — see eventadmin_export_volunteers_csv().
    echo '<form method="post" style="display:inline;margin:0;">';
    wp_nonce_field('eventadmin_export_volunteers', 'eventadmin_export_volunteers_nonce');
    echo '<input type="hidden" name="eventadmin_export_volunteers" value="1">';
    echo '<button type="submit" class="button">' . esc_html__('Export CSV', 'eventadmin-volunteer-management') . '</button>';
    echo '</form>';
    echo '</div>';

    echo '<form method="get" action="edit.php" id="eventadmin-volunteers-filters" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0;">';
    wp_nonce_field('eventadmin_vol_filter', 'eventadmin_vol_nonce');
    echo '<input type="hidden" name="post_type" value="eventadmin_shift">';
    echo '<input type="hidden" name="page" value="eventadmin-volunteers">';
    echo '<select name="filter_shift" aria-label="' . esc_attr__('Filter by shift', 'eventadmin-volunteer-management') . '">';
    echo '<option value="">' . esc_html__('All volunteers', 'eventadmin-volunteer-management') . '</option>';
    foreach ($all_shifts as $shift) {
        $start = get_post_meta($shift->ID, 'shift_start', true);
        $end   = get_post_meta($shift->ID, 'shift_end', true);
        $label = esc_html($shift->post_title) . ($start ? ' (' . esc_html(eventadmin_get_formatted_zeitraum($start, $end)) . ')' : '');
        $sel   = selected($selected_shift, $shift->ID, false);
        echo '<option value="' . esc_attr($shift->ID) . '"' . $sel . '>' . $label . '</option>';
    }
    echo '</select>';
    if (!empty($all_categories)) {
        echo '<select name="filter_category" aria-label="' . esc_attr__('Category', 'eventadmin-volunteer-management') . '">';
        echo '<option value="">' . esc_html__('All categories', 'eventadmin-volunteer-management') . '</option>';
        echo eventadmin_category_dropdown_options($all_categories, $selected_category, 'term_id');
        echo '</select>';
    }
    echo '<noscript><input type="submit" class="button" value="' . esc_attr__('Filter', 'eventadmin-volunteer-management') . '"></noscript>';
    if ($selected_shift || $selected_category) {
        echo '<a href="' . esc_url(admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-volunteers')) . '" class="button">' . esc_html__('Reset', 'eventadmin-volunteer-management') . '</a>';
    }
    echo '</form>';

    echo '<div class="eventadmin-vol-search" style="display:flex;align-items:center;gap:8px;">';
    echo '<input type="search" id="eventadmin-vol-search" placeholder="' . esc_attr__('Search volunteers…', 'eventadmin-volunteer-management') . '" class="regular-text">';
    echo '<span id="eventadmin-vol-count" style="color:#666;font-style:italic;"></span>';
    echo '</div>';

    echo '</div>';
    echo '<script>
        document.querySelectorAll("#eventadmin-volunteers-filters select").forEach(function (el) {
            el.addEventListener("change", function () { el.form.submit(); });
        });
    </script>';

    // "Create new volunteer" modal
    eventadmin_render_modal_open('eventadmin-create-volunteer-modal');
    eventadmin_render_modal_close_button();
    echo '<h2 style="margin-top:0;">' . esc_html__('Create new volunteer', 'eventadmin-volunteer-management') . '</h2>';
    echo '<form id="eventadmin-create-volunteer-form">';
    wp_nonce_field('eventadmin_create_volunteer', 'eventadmin_create_volunteer_nonce');
    echo '<p style="display:flex;gap:8px;flex-wrap:wrap;">';
    echo '<input type="text" name="first_name" placeholder="' . esc_attr__('First name', 'eventadmin-volunteer-management') . '" required style="flex:1;min-width:120px;">';
    echo '<input type="text" name="last_name" placeholder="' . esc_attr__('Last name', 'eventadmin-volunteer-management') . '" style="flex:1;min-width:120px;">';
    echo '</p>';
    echo '<p style="display:flex;gap:8px;flex-wrap:wrap;">';
    echo '<input type="text" name="user_identifier" placeholder="' . esc_attr__('E-Mail (optional)', 'eventadmin-volunteer-management') . '" title="' . esc_attr__('Leave blank for offline volunteers without an email address', 'eventadmin-volunteer-management') . '" style="flex:1;min-width:120px;">';
    echo '<input type="text" name="phone" placeholder="' . esc_attr__('Phone', 'eventadmin-volunteer-management') . '" style="flex:1;min-width:120px;">';
    echo '</p>';
    echo '<p>';
    echo '<button type="submit" class="button button-primary">' . esc_html__('Create volunteer', 'eventadmin-volunteer-management') . '</button>';
    echo ' <span id="eventadmin-create-volunteer-result" style="margin-left:8px;"></span>';
    echo '</p></form>';
    eventadmin_render_modal_close();

    // "Grant volunteer role" modal
    $non_volunteers = get_users(['role__not_in' => ['eventadmin_volunteer'], 'orderby' => 'display_name', 'fields' => ['ID', 'display_name', 'user_email']]);
    eventadmin_render_modal_open('eventadmin-grant-role-modal');
    eventadmin_render_modal_close_button();
    echo '<h2 style="margin-top:0;">' . esc_html__('Grant volunteer role', 'eventadmin-volunteer-management') . '</h2>';
    if (empty($non_volunteers)) {
        echo '<p><em>' . esc_html__('All existing users already have the volunteer role.', 'eventadmin-volunteer-management') . '</em></p>';
    } else {
        echo '<form id="eventadmin-grant-role-form">';
        wp_nonce_field('eventadmin_grant_volunteer_role', 'eventadmin_grant_role_nonce');
        echo '<p>';
        echo '<select name="user_id" id="eventadmin-grant-role-user" style="max-width:100%;width:100%;" required>';
        echo '<option value="">' . esc_html__('— Select user —', 'eventadmin-volunteer-management') . '</option>';
        foreach ($non_volunteers as $u) {
            echo '<option value="' . esc_attr($u->ID) . '">' . esc_html($u->display_name) . ' (' . esc_html($u->user_email) . ')</option>';
        }
        echo '</select>';
        echo '</p>';
        echo '<p>';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Grant role', 'eventadmin-volunteer-management') . '</button>';
        echo ' <span id="eventadmin-grant-role-result" style="margin-left:8px;"></span>';
        echo '</p></form>';
    }
    eventadmin_render_modal_close();

    // "View profile" + "Shift details" + "Edit departments" modals — shared with the
    // Timeline view and user-edit.php (see includes/admin/user-profile.php).
    eventadmin_render_shared_volunteer_modals();

    // Volunteer table
    $sortable_cols = [
        'name'          => esc_html__('Name', 'eventadmin-volunteer-management'),
        'email'         => esc_html__('E-Mail', 'eventadmin-volunteer-management'),
        'phone'         => esc_html__('Phone', 'eventadmin-volunteer-management'),
        'announcements' => esc_html__('Announcements', 'eventadmin-volunteer-management'),
        'shifts'        => esc_html__('Upcoming shifts', 'eventadmin-volunteer-management'),
        'registered'    => esc_html__('Registered', 'eventadmin-volunteer-management'),
        'last_shift'    => esc_html__('Last shift', 'eventadmin-volunteer-management'),
    ];
    echo '<table id="eventadmin-vol-table" class="widefat striped">';
    echo '<thead><tr>';
    foreach ($sortable_cols as $col => $label) {
        echo '<th data-sort="' . esc_attr($col) . '" style="cursor:pointer;user-select:none;" title="' . esc_attr__('Click to sort', 'eventadmin-volunteer-management') . '">';
        echo $label . ' <span class="eventadmin-sort-icon" style="opacity:.4;">↕</span></th>';
    }
    echo '<th>' . esc_html__('Departments', 'eventadmin-volunteer-management') . '</th>';
    echo '<th>' . esc_html__('Actions', 'eventadmin-volunteer-management') . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($volunteers)) {
        echo '<tr><td colspan="9"><em>' . esc_html__('No volunteers found.', 'eventadmin-volunteer-management') . '</em></td></tr>';
    }

    // Pre-fetch social login user IDs in one query to avoid N+1.
    // Nextend Social Login stores connections in the {prefix}social_users table.
    global $wpdb;
    $social_users_table = $wpdb->prefix . 'social_users';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$social_users_table}'") === $social_users_table;
    $social_user_ids = $table_exists
        ? array_map('intval', $wpdb->get_col("SELECT DISTINCT ID FROM `{$social_users_table}`"))
        : [];

    $now_ts = current_time('timestamp');

    foreach ($volunteers as $volunteer) {
        $phone             = get_user_meta($volunteer->ID, 'eventadmin_phone', true);
        $announcements_raw = get_user_meta($volunteer->ID, 'eventadmin_announcements', true);
        $subscribed        = ($announcements_raw === '0') ? false : true;
        $is_offline        = (bool) get_user_meta($volunteer->ID, 'eventadmin_offline_volunteer', true) || empty($volunteer->user_email);
        $department_terms  = array_filter(array_map(
            fn($term_id) => get_term($term_id, 'eventadmin_shift_category'),
            eventadmin_get_volunteer_department_ids($volunteer->ID)
        ), fn($term) => $term instanceof WP_Term);

        // Fetch every shift assigned to this volunteer (past and future), newest first, to
        // derive both the upcoming-shift count and the most recent past shift in one query.
        $assigned_shift_ids = get_posts([
            'post_type'   => 'eventadmin_shift',
            'numberposts' => -1,
            'post_status' => 'any',
            'fields'      => 'ids',
            'meta_key'    => 'shift_start',
            'orderby'     => 'meta_value',
            'meta_type'   => 'DATETIME',
            'order'       => 'DESC',
            'meta_query'  => [
                ['key' => 'assigned_user_' . $volunteer->ID, 'compare' => 'EXISTS'],
            ],
        ]);
        $shift_count      = 0;
        $last_shift_start = '';
        foreach ($assigned_shift_ids as $assigned_shift_id) {
            $assigned_start = get_post_meta($assigned_shift_id, 'shift_start', true);
            if (strtotime($assigned_start) >= $now_ts) {
                $shift_count++;
            } elseif ($last_shift_start === '') {
                $last_shift_start = $assigned_start;
            }
        }

        $token_set     = (bool) get_user_meta($volunteer->ID, 'magic_login_token', true);
        $is_unverified = $token_set;
        $is_social     = in_array($volunteer->ID, $social_user_ids, true);
        $is_manual     = (bool) get_user_meta($volunteer->ID, 'eventadmin_manually_added', true);

        $profile_trigger_name = trim($volunteer->first_name . ' ' . $volunteer->last_name) ?: $volunteer->user_login;
        // Opens the same "Volunteer activity" modal used from the Timeline (upcoming/past
        // shifts + notification history) — lets an admin see what a volunteer has already
        // been assigned/sent without leaving this list.
        $display_name = '<button type="button" class="button-link eventadmin-view-volunteer-profile" data-user-id="'
            . esc_attr($volunteer->ID) . '" data-name="' . esc_attr($profile_trigger_name) . '">'
            . esc_html($profile_trigger_name) . '</button>';
        if ($is_offline) {
            $tip = esc_attr__('Created by an admin without an email address. Cannot log in and receives no notifications.', 'eventadmin-volunteer-management');
            $display_name .= ' <span class="eventadmin-badge" tabindex="0" data-tooltip="' . $tip . '" aria-label="' . $tip . '" style="background:#777;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;font-weight:normal;">' . esc_html__('Offline', 'eventadmin-volunteer-management') . '</span>';
        }
        if ($is_unverified) {
            $tip = esc_attr__('Registered via the public form but has not yet clicked the magic login link. The account is auto-deleted once the link expires (~24 h). The badge disappears as soon as the link is clicked.', 'eventadmin-volunteer-management');
            $display_name .= ' <span class="eventadmin-badge" tabindex="0" data-tooltip="' . $tip . '" aria-label="' . $tip . '" style="background:#dba617;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;font-weight:normal;">' . esc_html__('Unverified', 'eventadmin-volunteer-management') . '</span>';
        }
        if ($is_social) {
            $tip = esc_attr__('Registered or linked via Nextend Social Login (e.g. Google, Facebook). Requires the Nextend Social Login plugin.', 'eventadmin-volunteer-management');
            $display_name .= ' <span class="eventadmin-badge" tabindex="0" data-tooltip="' . $tip . '" aria-label="' . $tip . '" style="background:#4285f4;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;font-weight:normal;">' . esc_html__('Social', 'eventadmin-volunteer-management') . '</span>';
        }
        if ($is_manual) {
            $tip = esc_attr__('Added by an admin via the dashboard form or the "Grant volunteer role" function. Never auto-deleted.', 'eventadmin-volunteer-management');
            $display_name .= ' <span class="eventadmin-badge" tabindex="0" data-tooltip="' . $tip . '" aria-label="' . $tip . '" style="background:#2e7d32;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;font-weight:normal;">' . esc_html__('Manual', 'eventadmin-volunteer-management') . '</span>';
        }

        // Shortened to "1. Sep. 25" (day, abbreviated i18n month, 2-digit year) so the
        // column stays narrow — the site's full date_format is still one hover away via
        // the <td title="…"> below.
        $registered_ts    = strtotime($volunteer->user_registered . ' UTC') ?: 0;
        $registered_label = $registered_ts ? mysql2date('j. M. y', $volunteer->user_registered) : '—';
        $registered_full  = $registered_ts ? mysql2date(get_option('date_format'), $volunteer->user_registered) : '';
        $last_shift_ts    = $last_shift_start ? strtotime($last_shift_start) : 0;
        $last_shift_label = $last_shift_ts ? date_i18n('j. M. y', $last_shift_ts) : '—';
        $last_shift_full  = $last_shift_ts ? date_i18n(get_option('date_format'), $last_shift_ts) : '';

        $sort_name = trim($volunteer->first_name . ' ' . $volunteer->last_name) ?: $volunteer->user_login;
        echo '<tr'
            . ' data-name="' . esc_attr(strtolower($sort_name)) . '"'
            . ' data-email="' . esc_attr(strtolower($volunteer->user_email)) . '"'
            . ' data-phone="' . esc_attr($phone) . '"'
            . ' data-announcements="' . esc_attr($is_offline ? '-1' : ($subscribed ? '1' : '0')) . '"'
            . ' data-shifts="' . esc_attr($shift_count) . '"'
            . ' data-registered="' . esc_attr($registered_ts) . '"'
            . ' data-last_shift="' . esc_attr($last_shift_ts) . '"'
            . '>';
        echo '<td><strong>' . $display_name . '</strong></td>';

        // E-Mail: a copy-to-clipboard icon (assets/js/volunteer-list.js's .eventadmin-copy-value
        // handler) plus a truncated value, so a long address doesn't force the row to wrap.
        // Sorting/search still key off this <tr>'s data-email attribute, not this markup.
        echo '<td>';
        if ($is_offline) {
            echo '—';
        } else {
            echo '<span style="display:inline-flex;align-items:center;gap:4px;max-width:100%;">';
            echo eventadmin_render_copy_value_button($volunteer->user_email, esc_attr__('Copy e-mail address', 'eventadmin-volunteer-management'));
            echo '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:160px;display:inline-block;" title="' . esc_attr($volunteer->user_email) . '">' . esc_html($volunteer->user_email) . '</span>';
            echo '</span>';
        }
        echo '</td>';

        // Phone: same copy-icon pattern, truncated hard to a "079…"-length sliver — the
        // full number is one click away, so the column doesn't need to show it all.
        echo '<td>';
        if ($phone) {
            $tel_href = esc_attr(eventadmin_phone_tel_href($phone));
            echo '<span style="display:inline-flex;align-items:center;gap:4px;max-width:100%;">';
            echo eventadmin_render_copy_value_button($phone, esc_attr__('Copy phone number', 'eventadmin-volunteer-management'));
            echo '<a href="tel:' . $tel_href . '" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:60px;display:inline-block;" title="' . esc_attr($phone) . '">' . esc_html($phone) . '</a>';
            echo '</span>';
        } else {
            echo '—';
        }
        echo '</td>';
        echo '<td>' . ($is_offline
            ? '<span style="color:#999;">—</span>'
            : ($subscribed
                ? '<span style="color:#00a32a;">&#10003; ' . esc_html__('Subscribed', 'eventadmin-volunteer-management') . '</span>'
                : '<span style="color:#999;">&#10007; ' . esc_html__('Opted out', 'eventadmin-volunteer-management') . '</span>')) . '</td>';
        echo '<td>' . esc_html($shift_count) . '</td>';
        echo '<td' . ($registered_full ? ' title="' . esc_attr($registered_full) . '"' : '') . '>' . esc_html($registered_label) . '</td>';
        echo '<td' . ($last_shift_full ? ' title="' . esc_attr($last_shift_full) . '"' : '') . '>' . esc_html($last_shift_label) . '</td>';
        echo '<td><div style="display:flex;align-items:center;gap:4px;flex-wrap:nowrap;white-space:nowrap;">';
        if (empty($department_terms)) {
            echo '<span style="color:#999;">—</span>';
        } else {
            // Caps the badges shown so a volunteer linked to many departments doesn't blow
            // up the row height — the rest collapse into a "+N" badge with the same instant
            // hover tooltip as the Offline/Unverified/Social/Manual badges (see
            // eventadmin_render_volunteer_badges() and its .eventadmin-badge CSS), listing
            // the remaining department names.
            $dept_display_cap = 1;
            $shown_terms       = array_slice($department_terms, 0, $dept_display_cap);
            $overflow_terms    = array_slice($department_terms, $dept_display_cap);
            foreach ($shown_terms as $term) {
                $color = get_term_meta($term->term_id, 'term_color', true) ?: '#777';
                echo '<span style="background:' . esc_attr($color) . ';color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;flex-shrink:0;">' . esc_html($term->name) . '</span>';
            }
            if (!empty($overflow_terms)) {
                $overflow_names = esc_attr(implode(', ', array_map(fn($t) => $t->name, $overflow_terms)));
                echo '<span class="eventadmin-badge" tabindex="0" data-tooltip="' . $overflow_names . '" aria-label="' . $overflow_names . '" style="background:#777;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;flex-shrink:0;">'
                    . esc_html(sprintf('+%d', count($overflow_terms))) . '</span>';
            }
        }
        echo '<button type="button" class="button button-small eventadmin-edit-departments" data-user-id="' . esc_attr($volunteer->ID) . '" data-name="' . esc_attr($profile_trigger_name) . '" style="flex-shrink:0;">' . esc_html__('Edit', 'eventadmin-volunteer-management') . '</button>';
        echo '</div></td>';
        $safe_name = esc_attr(trim($volunteer->first_name . ' ' . $volunteer->last_name) ?: $volunteer->user_login);
        // Icon-only actions (Email / Remove role) so this stays one column at one line
        // tall regardless of how many actions a row ends up needing — each icon's title/
        // aria-label carries the explanation a full-text button used to show.
        echo '<td style="white-space:nowrap;">';
        if (!$is_offline) {
            $email_label = esc_attr__('Send this volunteer an email', 'eventadmin-volunteer-management');
            echo '<a href="' . esc_url(admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-bulk-email&recipient_user_id=' . $volunteer->ID)) . '" class="button button-small" title="' . $email_label . '" aria-label="' . $email_label . '">'
                . '<span class="dashicons dashicons-email-alt" style="vertical-align:text-bottom;"></span></a> ';
        }
        $remove_label = esc_attr__('Remove volunteer role', 'eventadmin-volunteer-management');
        echo '<button type="button" class="button button-small eventadmin-remove-role"'
            . ' data-user-id="' . esc_attr($volunteer->ID) . '"'
            . ' data-shift-count="' . esc_attr($shift_count) . '"'
            . ' data-name="' . $safe_name . '"'
            . ' title="' . $remove_label . '"'
            . ' aria-label="' . $remove_label . '">'
            . '<span class="dashicons dashicons-remove" style="vertical-align:text-bottom;"></span></button>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    // JS for the volunteer table, modals and group email form
    $volunteer_list_js_path = plugin_dir_path(__FILE__) . '../../assets/js/volunteer-list.js';
    wp_enqueue_script(
        'eventadmin-volunteer-list',
        plugin_dir_url(__FILE__) . '../../assets/js/volunteer-list.js',
        ['jquery'],
        file_exists($volunteer_list_js_path) ? filemtime($volunteer_list_js_path) : null,
        true
    );
    wp_localize_script('eventadmin-volunteer-list', 'EVENTADMIN_VOL', [
        'ajax_url'      => admin_url('admin-ajax.php'),
        'nonce_remove'  => wp_create_nonce('eventadmin_remove_volunteer_role'),
        'i18n'          => [
            'volunteers'              => esc_html__('volunteers', 'eventadmin-volunteer-management'),
            'error'                   => esc_html__('An error occurred. Please try again.', 'eventadmin-volunteer-management'),
            'role_granted'            => esc_html__('Role granted. Reloading…', 'eventadmin-volunteer-management'),
            'role_removed'            => esc_html__('Role removed.', 'eventadmin-volunteer-management'),
            'volunteer_created'       => esc_html__('Volunteer created. Reloading…', 'eventadmin-volunteer-management'),
            'remove_confirm'          => esc_html__('Remove the volunteer role from {name}? They still have {shifts} upcoming shift(s).', 'eventadmin-volunteer-management'),
            'remove_confirm_no_shifts' => esc_html__('Remove the volunteer role from {name}?', 'eventadmin-volunteer-management'),
        ],
    ]);

    echo '</div>';
}

/**
 * AJAX: grant the eventadmin_volunteer role to an existing user.
 */
function eventadmin_grant_volunteer_role_handler(): void
{
    if (
        !isset($_POST['eventadmin_grant_role_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eventadmin_grant_role_nonce'])), 'eventadmin_grant_volunteer_role')
    ) {
        wp_send_json_error(['message' => esc_html__('Security check failed.', 'eventadmin-volunteer-management')]);
    }

    if (!current_user_can('eventadmin_manage_volunteers')) {
        wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'eventadmin-volunteer-management')]);
    }

    $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
    $user    = $user_id ? get_user_by('id', $user_id) : false;

    if (!$user) {
        wp_send_json_error(['message' => esc_html__('User not found.', 'eventadmin-volunteer-management')]);
    }

    $user->add_role('eventadmin_volunteer');
    update_user_meta($user_id, 'eventadmin_manually_added', '1');
    wp_send_json_success(['message' => esc_html__('Role granted.', 'eventadmin-volunteer-management')]);
}

add_action('wp_ajax_eventadmin_grant_volunteer_role', 'eventadmin_grant_volunteer_role_handler');

/**
 * AJAX: remove the eventadmin_volunteer role from a user.
 */
function eventadmin_remove_volunteer_role_handler(): void
{
    if (
        !isset($_POST['_ajax_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])), 'eventadmin_remove_volunteer_role')
    ) {
        wp_send_json_error(['message' => esc_html__('Security check failed.', 'eventadmin-volunteer-management')]);
    }

    if (!current_user_can('eventadmin_manage_volunteers')) {
        wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'eventadmin-volunteer-management')]);
    }

    $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
    $user    = $user_id ? get_user_by('id', $user_id) : false;

    if (!$user) {
        wp_send_json_error(['message' => esc_html__('User not found.', 'eventadmin-volunteer-management')]);
    }

    $user->remove_role('eventadmin_volunteer');
    wp_send_json_success(['message' => esc_html__('Role removed.', 'eventadmin-volunteer-management')]);
}

add_action('wp_ajax_eventadmin_remove_volunteer_role', 'eventadmin_remove_volunteer_role_handler');

/**
 * AJAX: create a brand-new volunteer user (online or offline).
 */
function eventadmin_create_volunteer_handler(): void
{
    if (
        !isset($_POST['eventadmin_create_volunteer_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eventadmin_create_volunteer_nonce'])), 'eventadmin_create_volunteer')
    ) {
        wp_send_json_error(['message' => esc_html__('Security check failed.', 'eventadmin-volunteer-management')]);
    }

    if (!current_user_can('eventadmin_manage_volunteers')) {
        wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'eventadmin-volunteer-management')]);
    }

    $first      = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
    $last       = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
    $phone      = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
    $identifier = isset($_POST['user_identifier']) ? sanitize_email(wp_unslash($_POST['user_identifier'])) : '';

    if (empty($first)) {
        wp_send_json_error(['message' => esc_html__('First name is required.', 'eventadmin-volunteer-management')]);
    }

    // If an email is supplied, check for an existing user
    if ($identifier) {
        $existing = get_user_by('email', $identifier) ?: get_user_by('login', $identifier);
        if ($existing) {
            wp_send_json_error(['message' => esc_html__('A user with this e-mail already exists.', 'eventadmin-volunteer-management')]);
        }
    }

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
        wp_send_json_error(['message' => $user_id->get_error_message()]);
    }

    if ($phone) {
        update_user_meta($user_id, 'eventadmin_phone', $phone);
    }
    if ($is_offline) {
        update_user_meta($user_id, 'eventadmin_offline_volunteer', '1');
    } else {
        update_user_meta($user_id, 'eventadmin_manually_added', '1');
    }

    wp_send_json_success(['message' => esc_html__('Volunteer created. Reloading…', 'eventadmin-volunteer-management')]);
}

add_action('wp_ajax_eventadmin_create_volunteer', 'eventadmin_create_volunteer_handler');

/**
 * AJAX: clear the auto-deleted unverified accounts log.
 */
function eventadmin_clear_cleanup_log_handler(): void
{
    if (
        !isset($_POST['_ajax_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])), 'eventadmin_clear_cleanup_log')
    ) {
        wp_send_json_error(['message' => esc_html__('Security check failed.', 'eventadmin-volunteer-management')]);
    }

    if (!current_user_can('eventadmin_manage_volunteers')) {
        wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'eventadmin-volunteer-management')]);
    }

    delete_option('eventadmin_cleanup_log');
    wp_send_json_success(['message' => esc_html__('Log cleared.', 'eventadmin-volunteer-management')]);
}

add_action('wp_ajax_eventadmin_clear_cleanup_log', 'eventadmin_clear_cleanup_log_handler');

/**
 * Non-AJAX admin_init handler for the "Export CSV" button on the Volunteers list — same
 * classic form-submit pattern as the shift CSV exports in dashboard-form-handlers.php,
 * kept local here since it's specific to this screen's own volunteer roster, not shifts.
 */
function eventadmin_volunteer_list_admin_init(): void
{
    if (
        isset($_POST['eventadmin_export_volunteers']) &&
        check_admin_referer('eventadmin_export_volunteers', 'eventadmin_export_volunteers_nonce') &&
        current_user_can('eventadmin_manage_volunteers')
    ) {
        eventadmin_export_volunteers_csv();
    }
}

add_action('admin_init', 'eventadmin_volunteer_list_admin_init');

/**
 * Exports the whole Volunteers roster (every online eventadmin_volunteer, independent of
 * the on-page shift/category filter or the client-side search box) as a CSV file — same
 * header/BOM pattern as the shift CSV exports in dashboard-form-handlers.php.
 */
#[NoReturn] function eventadmin_export_volunteers_csv(): void
{
    $volunteers = get_users([
        'role'       => 'eventadmin_volunteer',
        'meta_query' => [['key' => 'eventadmin_offline_volunteer', 'compare' => 'NOT EXISTS']],
        'orderby'    => 'display_name',
    ]);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=eventadmin_volunteers.csv');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel (which does not auto-detect CSV encoding) doesn't mangle non-ASCII characters.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        esc_html__('Name', 'eventadmin-volunteer-management'),
        esc_html__('E-Mail', 'eventadmin-volunteer-management'),
        esc_html__('Phone', 'eventadmin-volunteer-management'),
        esc_html__('Announcements', 'eventadmin-volunteer-management'),
        esc_html__('Upcoming shifts', 'eventadmin-volunteer-management'),
        esc_html__('Registered', 'eventadmin-volunteer-management'),
        esc_html__('Departments', 'eventadmin-volunteer-management'),
    ]);

    $now_ts = current_time('timestamp');

    foreach ($volunteers as $volunteer) {
        $announcements_raw = get_user_meta($volunteer->ID, 'eventadmin_announcements', true);
        $subscribed        = $announcements_raw !== '0';

        $upcoming_count = 0;
        foreach (get_posts([
            'post_type'   => 'eventadmin_shift',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [['key' => 'assigned_user_' . $volunteer->ID, 'compare' => 'EXISTS']],
        ]) as $shift_id) {
            $start = get_post_meta($shift_id, 'shift_start', true);
            if ($start && strtotime($start) >= $now_ts) {
                $upcoming_count++;
            }
        }

        $department_names = implode(', ', array_filter(array_map(
            function ($term_id) {
                $term = get_term($term_id, 'eventadmin_shift_category');
                return $term instanceof WP_Term ? $term->name : null;
            },
            eventadmin_get_volunteer_department_ids($volunteer->ID)
        )));

        fputcsv($out, [
            trim($volunteer->first_name . ' ' . $volunteer->last_name) ?: $volunteer->user_login,
            $volunteer->user_email,
            get_user_meta($volunteer->ID, 'eventadmin_phone', true),
            $subscribed ? esc_html__('Subscribed', 'eventadmin-volunteer-management') : esc_html__('Opted out', 'eventadmin-volunteer-management'),
            $upcoming_count,
            mysql2date(get_option('date_format'), $volunteer->user_registered),
            $department_names,
        ]);
    }

    exit;
}

/**
 * Renders and enqueues the "Edit departments" modal — AJAX-filled per volunteer (see
 * eventadmin_ajax_get_volunteer_departments() / eventadmin_ajax_save_volunteer_departments()
 * below), same shared-shell pattern as the "View profile" modal. Called from
 * eventadmin_render_shared_volunteer_modals() (includes/admin/user-profile.php) so its
 * ".eventadmin-edit-departments" trigger works both from the Volunteers list Departments
 * column and from the Departments row inside the "View profile" modal, on every screen
 * that modal appears (Volunteers list, Timeline, Overview, user-edit.php).
 *
 * @return void
 */
function eventadmin_render_edit_departments_modal(): void
{
    eventadmin_render_modal_open('eventadmin-edit-departments-modal');
    eventadmin_render_modal_close_button('eventadmin-edit-departments-close');
    echo '<h2 id="eventadmin-edit-departments-heading" style="margin-top:0;"></h2>';
    echo '<form id="eventadmin-edit-departments-form">';
    wp_nonce_field('eventadmin_edit_volunteer_departments', 'eventadmin_edit_departments_nonce');
    echo '<input type="hidden" name="user_id" id="eventadmin-edit-departments-user-id" value="">';
    echo '<div id="eventadmin-edit-departments-body"></div>';
    echo '<p><button type="submit" class="button button-primary">' . esc_html__('Save', 'eventadmin-volunteer-management') . '</button> <span id="eventadmin-edit-departments-result" style="margin-left:8px;"></span></p>';
    echo '</form>';
    eventadmin_render_modal_close();

    $department_checkboxes_js_path = plugin_dir_path(__FILE__) . '../../assets/js/department-checkboxes.js';
    wp_enqueue_script(
        'eventadmin-department-checkboxes',
        plugin_dir_url(__FILE__) . '../../assets/js/department-checkboxes.js',
        [],
        file_exists($department_checkboxes_js_path) ? filemtime($department_checkboxes_js_path) : null,
        true
    );
    $edit_departments_modal_js_path = plugin_dir_path(__FILE__) . '../../assets/js/edit-departments-modal.js';
    wp_enqueue_script(
        'eventadmin-edit-departments-modal',
        plugin_dir_url(__FILE__) . '../../assets/js/edit-departments-modal.js',
        [],
        file_exists($edit_departments_modal_js_path) ? filemtime($edit_departments_modal_js_path) : null,
        true
    );
    wp_localize_script('eventadmin-edit-departments-modal', 'EVENTADMIN_EDIT_DEPARTMENTS', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('eventadmin_edit_volunteer_departments'),
        'i18n'     => [
            'loading' => esc_html__('Loading…', 'eventadmin-volunteer-management'),
            'error'   => esc_html__('An error occurred. Please try again.', 'eventadmin-volunteer-management'),
            'saved'   => esc_html__('Saved. Reloading…', 'eventadmin-volunteer-management'),
        ],
    ]);
}

/**
 * Renders the department checklist body (hint + hierarchical checkboxes) for the
 * "Edit departments" modal — moved here from a section on user-edit.php, since that
 * native screen requires the broad edit_users capability just to open, which the
 * eventadmin_volunteer_manager role should not need for something this narrow.
 * Cascading behaviour (checking a parent also checks everything nested under it) comes
 * from assets/js/department-checkboxes.js via the shared .eventadmin-department-checkbox
 * class and data-depth attribute.
 *
 * @param int[] $linked Currently linked department term IDs.
 * @return void
 */
function eventadmin_render_department_checklist(array $linked): void
{
    $categories = eventadmin_get_hierarchical_shift_categories();
    if (empty($categories)) {
        echo '<p>' . esc_html__('No departments exist yet.', 'eventadmin-volunteer-management') . '</p>';
        return;
    }

    echo '<p class="description" style="margin-top:0;">' . esc_html__('Checking (or unchecking) a department also checks/unchecks every department nested under it.', 'eventadmin-volunteer-management') . '</p>';
    echo '<div class="eventadmin-department-checklist">';
    foreach ($categories as $cat) {
        $margin_left = $cat->depth > 0 ? $cat->depth * 20 : 0;
        $hidden_suffix = eventadmin_is_shift_category_hidden($cat->term_id)
            ? ' <em>' . esc_html__('(hidden from volunteers)', 'eventadmin-volunteer-management') . '</em>'
            : '';
        echo '<label style="display:block;margin:4px 0 4px ' . esc_attr($margin_left) . 'px;">';
        echo '<input type="checkbox" class="eventadmin-department-checkbox" data-depth="' . esc_attr($cat->depth) . '" name="eventadmin_department[]" value="' . esc_attr($cat->term_id) . '"' . checked(in_array($cat->term_id, $linked, true), true, false) . '> ';
        echo esc_html($cat->name) . $hidden_suffix;
        echo '</label>';
    }
    echo '</div>';
}

/**
 * Resolves and validates the target volunteer for both department-modal AJAX handlers
 * below — shared so the two stay in sync on what counts as a valid target.
 *
 * @param int $user_id
 * @return WP_User|null
 */
function eventadmin_get_department_modal_target(int $user_id): ?WP_User
{
    $user = $user_id ? get_userdata($user_id) : false;
    return ($user && in_array('eventadmin_volunteer', (array) $user->roles, true)) ? $user : null;
}

/**
 * AJAX: returns the department checklist for one volunteer, for the "Edit departments"
 * modal's initial fetch.
 */
function eventadmin_ajax_get_volunteer_departments(): void
{
    if (
        !isset($_POST['nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eventadmin_edit_volunteer_departments')
    ) {
        wp_send_json_error(['message' => esc_html__('Security check failed.', 'eventadmin-volunteer-management')]);
    }

    if (!current_user_can('eventadmin_manage_volunteers')) {
        wp_send_json_error(['message' => esc_html__('Not allowed', 'eventadmin-volunteer-management')]);
    }

    $user = eventadmin_get_department_modal_target(isset($_POST['user_id']) ? absint($_POST['user_id']) : 0);
    if (!$user) {
        wp_send_json_error(['message' => esc_html__('Volunteer not found.', 'eventadmin-volunteer-management')]);
    }

    ob_start();
    eventadmin_render_department_checklist(eventadmin_get_volunteer_department_ids($user->ID));
    $html = ob_get_clean();

    wp_send_json_success(['html' => $html]);
}

add_action('wp_ajax_eventadmin_get_volunteer_departments', 'eventadmin_ajax_get_volunteer_departments');

/**
 * AJAX: saves the department checklist submitted from the "Edit departments" modal.
 */
function eventadmin_ajax_save_volunteer_departments(): void
{
    if (
        !isset($_POST['nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eventadmin_edit_volunteer_departments')
    ) {
        wp_send_json_error(['message' => esc_html__('Security check failed.', 'eventadmin-volunteer-management')]);
    }

    if (!current_user_can('eventadmin_manage_volunteers')) {
        wp_send_json_error(['message' => esc_html__('Not allowed', 'eventadmin-volunteer-management')]);
    }

    $user = eventadmin_get_department_modal_target(isset($_POST['user_id']) ? absint($_POST['user_id']) : 0);
    if (!$user) {
        wp_send_json_error(['message' => esc_html__('Volunteer not found.', 'eventadmin-volunteer-management')]);
    }

    $posted = (isset($_POST['eventadmin_department']) && is_array($_POST['eventadmin_department']))
        ? wp_unslash($_POST['eventadmin_department'])
        : [];
    eventadmin_save_volunteer_department_ids($user->ID, $posted);

    wp_send_json_success();
}

add_action('wp_ajax_eventadmin_save_volunteer_departments', 'eventadmin_ajax_save_volunteer_departments');

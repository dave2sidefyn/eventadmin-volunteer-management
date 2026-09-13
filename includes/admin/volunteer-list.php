<?php
/**
 * EventAdmin Volunteer Management - Volunteer List
 * Admin overview of all registered volunteers with per-volunteer and per-shift messaging.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit;
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

    // Filter by category
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
        $cat_user_ids = array_unique($cat_user_ids);
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

            wp_enqueue_script(
                'eventadmin-volunteer-list',
                plugin_dir_url(__FILE__) . '../../assets/js/volunteer-list.js',
                ['jquery'],
                '1.0',
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

    // "View profile" modal — shared with the Timeline view (see includes/admin/user-profile.php).
    eventadmin_render_volunteer_profile_modal_markup();
    eventadmin_enqueue_volunteer_profile_modal_script();

    // "Edit departments" modal — AJAX-filled per volunteer, same shared-shell pattern as the
    // "View profile" modal above (see eventadmin_ajax_get_volunteer_departments() /
    // eventadmin_ajax_save_volunteer_departments() below).
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

    wp_enqueue_script(
        'eventadmin-department-checkboxes',
        plugin_dir_url(__FILE__) . '../../assets/js/department-checkboxes.js',
        [],
        '1.0',
        true
    );
    wp_enqueue_script(
        'eventadmin-edit-departments-modal',
        plugin_dir_url(__FILE__) . '../../assets/js/edit-departments-modal.js',
        [],
        '1.0',
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
    echo '<th>' . esc_html__('Contact', 'eventadmin-volunteer-management') . '</th>';
    echo '<th>' . esc_html__('Actions', 'eventadmin-volunteer-management') . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($volunteers)) {
        echo '<tr><td colspan="10"><em>' . esc_html__('No volunteers found.', 'eventadmin-volunteer-management') . '</em></td></tr>';
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
            $display_name .= ' <span style="background:#777;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;font-weight:normal;">' . esc_html__('Offline', 'eventadmin-volunteer-management') . '</span>';
        }
        if ($is_unverified) {
            $display_name .= ' <span style="background:#dba617;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;font-weight:normal;">' . esc_html__('Unverified', 'eventadmin-volunteer-management') . '</span>';
        }
        if ($is_social) {
            $display_name .= ' <span style="background:#4285f4;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;font-weight:normal;">' . esc_html__('Social', 'eventadmin-volunteer-management') . '</span>';
        }
        if ($is_manual) {
            $display_name .= ' <span style="background:#2e7d32;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;font-weight:normal;">' . esc_html__('Manual', 'eventadmin-volunteer-management') . '</span>';
        }

        $registered_ts   = strtotime($volunteer->user_registered . ' UTC') ?: 0;
        $registered_label = $registered_ts ? mysql2date(get_option('date_format'), $volunteer->user_registered) : '—';
        $last_shift_ts    = $last_shift_start ? strtotime($last_shift_start) : 0;
        $last_shift_label = $last_shift_ts ? date_i18n(get_option('date_format'), $last_shift_ts) : '—';

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
        echo '<td>' . ($is_offline ? '—' : esc_html($volunteer->user_email)) . '</td>';
        echo '<td>' . esc_html($phone ?: '—') . '</td>';
        echo '<td>' . ($is_offline
            ? '<span style="color:#999;">—</span>'
            : ($subscribed
                ? '<span style="color:#00a32a;">&#10003; ' . esc_html__('Subscribed', 'eventadmin-volunteer-management') . '</span>'
                : '<span style="color:#999;">&#10007; ' . esc_html__('Opted out', 'eventadmin-volunteer-management') . '</span>')) . '</td>';
        echo '<td>' . esc_html($shift_count) . '</td>';
        echo '<td>' . esc_html($registered_label) . '</td>';
        echo '<td>' . esc_html($last_shift_label) . '</td>';
        echo '<td>';
        if (empty($department_terms)) {
            echo '<span style="color:#999;">—</span> ';
        } else {
            foreach ($department_terms as $term) {
                $color = get_term_meta($term->term_id, 'term_color', true) ?: '#777';
                echo '<span style="background:' . esc_attr($color) . ';color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;display:inline-block;margin:1px 2px 1px 0;">' . esc_html($term->name) . '</span> ';
            }
        }
        echo '<button type="button" class="button-link eventadmin-edit-departments" data-user-id="' . esc_attr($volunteer->ID) . '" data-name="' . esc_attr($profile_trigger_name) . '" style="font-size:11px;">' . esc_html__('Edit', 'eventadmin-volunteer-management') . '</button>';
        echo '</td>';
        echo '<td>' . ($is_offline
            ? '—'
            : '<a href="' . esc_url(admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-bulk-email&recipient_user_id=' . $volunteer->ID)) . '" class="button button-small">' . esc_html__('Email', 'eventadmin-volunteer-management') . '</a>') . '</td>';
        $safe_name = esc_attr(trim($volunteer->first_name . ' ' . $volunteer->last_name) ?: $volunteer->user_login);
        echo '<td><button class="button button-small eventadmin-remove-role"'
            . ' data-user-id="' . esc_attr($volunteer->ID) . '"'
            . ' data-shift-count="' . esc_attr($shift_count) . '"'
            . ' data-name="' . $safe_name . '">'
            . esc_html__('Remove role', 'eventadmin-volunteer-management')
            . '</button></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    // JS for the volunteer table, modals and group email form
    wp_enqueue_script(
        'eventadmin-volunteer-list',
        plugin_dir_url(__FILE__) . '../../assets/js/volunteer-list.js',
        ['jquery'],
        '1.0',
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

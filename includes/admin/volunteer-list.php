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
        'edit_posts',
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
        'orderby'     => 'meta_value',
        'order'       => 'ASC',
    ]);
    $all_categories = get_terms(['taxonomy' => 'eventadmin_shift_category', 'hide_empty' => false]);

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

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Volunteers', 'eventadmin-volunteer-management') . '</h1>';

    // Shift filter form
    echo '<form method="get" action="edit.php" style="margin-bottom:16px;">';
    wp_nonce_field('eventadmin_vol_filter', 'eventadmin_vol_nonce');
    echo '<input type="hidden" name="post_type" value="eventadmin_shift">';
    echo '<input type="hidden" name="page" value="eventadmin-volunteers">';
    echo '<label>' . esc_html__('Filter by shift:', 'eventadmin-volunteer-management') . ' ';
    echo '<select name="filter_shift">';
    echo '<option value="">' . esc_html__('All volunteers', 'eventadmin-volunteer-management') . '</option>';
    foreach ($all_shifts as $shift) {
        $start = get_post_meta($shift->ID, 'shift_start', true);
        $label = esc_html($shift->post_title) . ($start ? ' (' . esc_html(eventadmin_get_formatted_zeitraum($start, '')) . ')' : '');
        $sel   = selected($selected_shift, $shift->ID, false);
        echo '<option value="' . esc_attr($shift->ID) . '"' . $sel . '>' . $label . '</option>';
    }
    echo '</select></label> ';
    if (!empty($all_categories)) {
        echo '<label>' . esc_html__('Category:', 'eventadmin-volunteer-management') . ' ';
        echo '<select name="filter_category">';
        echo '<option value="">' . esc_html__('All', 'eventadmin-volunteer-management') . '</option>';
        foreach ($all_categories as $cat) {
            $sel = selected($selected_category, $cat->term_id, false);
            echo '<option value="' . esc_attr($cat->term_id) . '"' . $sel . '>' . esc_html($cat->name) . '</option>';
        }
        echo '</select></label> ';
    }
    echo '<input type="submit" class="button" value="' . esc_attr__('Filter', 'eventadmin-volunteer-management') . '">';
    if ($selected_shift || $selected_category) {
        echo ' <a href="' . esc_url(admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-volunteers')) . '" class="button">' . esc_html__('Reset', 'eventadmin-volunteer-management') . '</a>';
    }
    echo '</form>';

    // Link to Send Announcement page for bulk emails
    $announcement_url = admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-bulk-email');
    echo '<p style="margin-bottom:24px;">';
    echo esc_html__('To send an email to multiple volunteers, use the', 'eventadmin-volunteer-management') . ' ';
    echo '<a href="' . esc_url($announcement_url) . '">' . esc_html__('Send Announcement', 'eventadmin-volunteer-management') . '</a>.';
    echo '</p>';

    // Create new volunteer section
    echo '<div style="background:#f6f7f7;border:1px solid #dcdcde;padding:16px;margin-bottom:24px;max-width:480px;">';
    echo '<h3 style="margin-top:0;">' . esc_html__('Create new volunteer', 'eventadmin-volunteer-management') . '</h3>';
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
    echo '<button type="submit" class="button button-primary">' . esc_html__('Create volunteer', 'eventadmin-volunteer-management') . '</button>';
    echo ' <span id="eventadmin-create-volunteer-result" style="margin-left:8px;"></span>';
    echo '</form>';
    echo '</div>';

    // Grant volunteer role section
    $non_volunteers = get_users(['role__not_in' => ['eventadmin_volunteer'], 'fields' => ['ID', 'display_name', 'user_email']]);
    echo '<div style="background:#f6f7f7;border:1px solid #dcdcde;padding:16px;margin-bottom:24px;max-width:480px;">';
    echo '<h3 style="margin-top:0;">' . esc_html__('Grant volunteer role', 'eventadmin-volunteer-management') . '</h3>';
    if (empty($non_volunteers)) {
        echo '<p><em>' . esc_html__('All existing users already have the volunteer role.', 'eventadmin-volunteer-management') . '</em></p>';
    } else {
        echo '<form id="eventadmin-grant-role-form">';
        wp_nonce_field('eventadmin_grant_volunteer_role', 'eventadmin_grant_role_nonce');
        echo '<p>';
        echo '<select name="user_id" id="eventadmin-grant-role-user" style="max-width:100%;" required>';
        echo '<option value="">' . esc_html__('— Select user —', 'eventadmin-volunteer-management') . '</option>';
        foreach ($non_volunteers as $u) {
            echo '<option value="' . esc_attr($u->ID) . '">' . esc_html($u->display_name) . ' (' . esc_html($u->user_email) . ')</option>';
        }
        echo '</select>';
        echo '</p>';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Grant role', 'eventadmin-volunteer-management') . '</button>';
        echo ' <span id="eventadmin-grant-role-result" style="margin-left:8px;"></span>';
        echo '</form>';
    }
    echo '</div>';

    // Text search + volunteer count
    echo '<p style="margin-bottom:8px;">';
    echo '<input type="search" id="eventadmin-vol-search" placeholder="' . esc_attr__('Search volunteers…', 'eventadmin-volunteer-management') . '" class="regular-text">';
    echo ' <span id="eventadmin-vol-count" style="color:#666;font-style:italic;margin-left:8px;"></span>';
    echo '</p>';

    // Volunteer table
    $sortable_cols = [
        'name'          => esc_html__('Name', 'eventadmin-volunteer-management'),
        'email'         => esc_html__('E-Mail', 'eventadmin-volunteer-management'),
        'phone'         => esc_html__('Phone', 'eventadmin-volunteer-management'),
        'announcements' => esc_html__('Announcements', 'eventadmin-volunteer-management'),
        'shifts'        => esc_html__('Upcoming shifts', 'eventadmin-volunteer-management'),
    ];
    echo '<table id="eventadmin-vol-table" class="widefat striped">';
    echo '<thead><tr>';
    foreach ($sortable_cols as $col => $label) {
        echo '<th data-sort="' . esc_attr($col) . '" style="cursor:pointer;user-select:none;" title="' . esc_attr__('Click to sort', 'eventadmin-volunteer-management') . '">';
        echo $label . ' <span class="eventadmin-sort-icon" style="opacity:.4;">↕</span></th>';
    }
    echo '<th>' . esc_html__('Contact', 'eventadmin-volunteer-management') . '</th>';
    echo '<th>' . esc_html__('Actions', 'eventadmin-volunteer-management') . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($volunteers)) {
        echo '<tr><td colspan="7"><em>' . esc_html__('No volunteers found.', 'eventadmin-volunteer-management') . '</em></td></tr>';
    }

    // Pre-fetch social login user IDs in one query to avoid N+1.
    // Nextend Social Login stores connections in the {prefix}social_users table.
    global $wpdb;
    $social_users_table = $wpdb->prefix . 'social_users';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$social_users_table}'") === $social_users_table;
    $social_user_ids = $table_exists
        ? array_map('intval', $wpdb->get_col("SELECT DISTINCT ID FROM `{$social_users_table}`"))
        : [];

    $now = current_time('Y-m-d\TH:i');

    foreach ($volunteers as $volunteer) {
        $phone             = get_user_meta($volunteer->ID, 'eventadmin_phone', true);
        $announcements_raw = get_user_meta($volunteer->ID, 'eventadmin_announcements', true);
        $subscribed        = ($announcements_raw === '0') ? false : true;
        $is_offline        = (bool) get_user_meta($volunteer->ID, 'eventadmin_offline_volunteer', true) || empty($volunteer->user_email);

        // Count upcoming shifts
        $upcoming_shifts = get_posts([
            'post_type'   => 'eventadmin_shift',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [
                ['key' => 'shift_start', 'value' => $now, 'compare' => '>=', 'type' => 'DATETIME'],
                ['key' => 'assigned_user_' . $volunteer->ID, 'compare' => 'EXISTS'],
            ],
        ]);
        $shift_count = count($upcoming_shifts);

        $token_set     = (bool) get_user_meta($volunteer->ID, 'magic_login_token', true);
        $is_unverified = $token_set;
        $is_social     = in_array($volunteer->ID, $social_user_ids, true);
        $is_manual     = (bool) get_user_meta($volunteer->ID, 'eventadmin_manually_added', true);

        $display_name = esc_html(trim($volunteer->first_name . ' ' . $volunteer->last_name) ?: $volunteer->user_login);
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

        $sort_name = trim($volunteer->first_name . ' ' . $volunteer->last_name) ?: $volunteer->user_login;
        echo '<tr'
            . ' data-name="' . esc_attr(strtolower($sort_name)) . '"'
            . ' data-email="' . esc_attr(strtolower($volunteer->user_email)) . '"'
            . ' data-phone="' . esc_attr($phone) . '"'
            . ' data-announcements="' . esc_attr($is_offline ? '-1' : ($subscribed ? '1' : '0')) . '"'
            . ' data-shifts="' . esc_attr($shift_count) . '"'
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

    // Blocked registration log
    $blocked_log = get_option('eventadmin_blocked_log', []);
    if (!empty($blocked_log)) {
        echo '<h3 style="margin-top:2rem;">' . esc_html__('Blocked registration attempts', 'eventadmin-volunteer-management') . '</h3>';
        echo '<table class="widefat striped" style="max-width:800px;">';
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

    // Auto-cleanup log
    $cleanup_log = get_option('eventadmin_cleanup_log', []);
    if (!empty($cleanup_log)) {
        echo '<h3 style="margin-top:2rem;">' . esc_html__('Auto-deleted unverified accounts', 'eventadmin-volunteer-management') . '</h3>';
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
    }

    // JS for the group email form
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

    if (!current_user_can('edit_posts')) {
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

    if (!current_user_can('edit_posts')) {
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

    if (!current_user_can('edit_posts')) {
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

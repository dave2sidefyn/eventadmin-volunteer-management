<?php
/**
 * EventAdmin Volunteer Management - Admin Dashboard
 * Overview of all shifts and their assignments
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

use JetBrains\PhpStorm\NoReturn;

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Adds the overview page to the admin menu and places it first
 *
 * @return void
 */
function eventadmin_dashboard_admin_menu(): void
{
    add_submenu_page(
        'edit.php?post_type=eventadmin_shift',
        esc_html__('Overview', 'eventadmin-volunteer-management'),
        esc_html__('Overview', 'eventadmin-volunteer-management'),
        'edit_posts',
        'eventadmin-overview',
        'eventadmin_admin_overview_page'
    );

    global $submenu;

    if (!isset($submenu['edit.php?post_type=eventadmin_shift'])) {
        return;
    }

    foreach ($submenu['edit.php?post_type=eventadmin_shift'] as $key => $item) {
        if (isset($item[2]) && $item[2] === 'eventadmin-overview') {
            $overview = $item;
            unset($submenu['edit.php?post_type=eventadmin_shift'][$key]);
            break;
        }
    }

    if (isset($overview)) {
        array_unshift($submenu['edit.php?post_type=eventadmin_shift'], $overview);
    }
}

add_action('admin_menu', 'eventadmin_dashboard_admin_menu', 100);

/**
 * Gets all shifts with optional filtering by category (including caching)
 *
 * @param string $selected_cat Slug of the selected category
 * @param int $paged Current page for pagination
 * @param int $per_page Number of shifts per page
 * @return array Array of WP_Post objects for the shifts
 */
function eventadmin_get_shifts(
    string $selected_cat = '',
    int $paged = 1,
    int $per_page = 20,
    string $time_filter = 'future',
    string $sort_by = 'date',
    string $order = 'ASC',
    int $selected_volunteer = 0,
    string $selected_date = '',
    int &$total = 0
): array {
    $now = current_time('Y-m-d\TH:i');

    // Build the meta_query – named 'date_clause' is reused for ordering by date
    $meta_query = ['relation' => 'AND'];

    if ($selected_date) {
        $meta_query['date_clause'] = [
            'key'     => 'shift_start',
            'value'   => [$selected_date . 'T00:00', $selected_date . 'T23:59'],
            'compare' => 'BETWEEN',
            'type'    => 'DATETIME',
        ];
    } elseif ($time_filter !== 'all') {
        $meta_query['date_clause'] = [
            'key'     => 'shift_start',
            'value'   => $now,
            'compare' => $time_filter === 'future' ? '>=' : '<',
            'type'    => 'DATETIME',
        ];
    } else {
        // No time filter, but still need date_clause for orderby
        $meta_query['date_clause'] = ['key' => 'shift_start', 'compare' => 'EXISTS'];
    }

    if ($selected_volunteer > 0) {
        $meta_query[] = ['key' => 'assigned_user_' . $selected_volunteer, 'compare' => 'EXISTS'];
    }

    $args = [
        'post_type'      => 'eventadmin_shift',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'meta_query'     => $meta_query,
        'orderby'        => $sort_by === 'date' ? 'date_clause' : 'title',
        'order'          => $order,
    ];

    if ($selected_cat) {
        $args['tax_query'] = [[
            'taxonomy' => 'eventadmin_shift_category',
            'field'    => 'slug',
            'terms'    => $selected_cat,
        ]];
    }

    $cache_key = 'eventadmin_shifts_' . md5(serialize($args));
    $cached    = get_transient($cache_key);

    if ($cached === false) {
        $query  = new WP_Query($args);
        $cached = ['posts' => $query->posts, 'total' => $query->found_posts];
        set_transient($cache_key, $cached, 5 * MINUTE_IN_SECONDS);
    }

    $total = $cached['total'];
    return $cached['posts'];
}

/**
 * Displays the EventAdmin overview page in the admin area
 *
 * @return void
 */
function eventadmin_admin_overview_page(): void
{

    $all_users = get_users(['role' => 'eventadmin_volunteer']);
    $total_users = count($all_users);

    // Stats are always based on upcoming shifts only
    $upcoming_shifts = get_posts([
        'post_type'   => 'eventadmin_shift',
        'numberposts' => -1,
        'meta_query'  => [[
            'key'     => 'shift_start',
            'value'   => current_time('Y-m-d\TH:i'),
            'compare' => '>=',
            'type'    => 'DATETIME',
        ]],
    ]);
    $total_shifts        = count($upcoming_shifts);
    $filled_shifts       = 0;
    $open_shifts         = 0;
    $empty_shifts        = 0;
    $understaffed_shifts = 0;
    $assigned_user_ids   = [];
    $category_counts     = [];

    foreach ($upcoming_shifts as $shift) {
        $max      = (int)get_post_meta($shift->ID, 'max_volunteers', true);
        $min      = (int)get_post_meta($shift->ID, 'min_volunteers', true);
        $assigned = eventadmin_count_assignments($shift->ID);
        $open     = max(0, $max - $assigned);
        $open_shifts   += $open;
        $filled_shifts += $assigned;
        if ($assigned === 0) $empty_shifts++;
        if ($min > 0 && $assigned < $min) $understaffed_shifts++;

        $meta = get_post_meta($shift->ID);
        foreach ($meta as $key => $val) {
            if (str_starts_with($key, 'assigned_user_')) {
                $assigned_user_ids[] = (int)$val[0];
            }
        }
        $terms = wp_get_post_terms($shift->ID, 'eventadmin_shift_category');
        foreach ($terms as $t) {
            $name = $t->name;
            if (!isset($category_counts[$name])) {
                $category_counts[$name] = ['filled' => 0, 'open' => 0];
            }
            $category_counts[$name]['filled'] += $assigned;
            $category_counts[$name]['open']   += $open;
        }
    }

    $unique_assigned      = array_unique($assigned_user_ids);
    $volunteers_without_shift = $total_users - count($unique_assigned);

    // JSON for JS
    $chart_data = [
        'labels'      => array_keys($category_counts),
        'data_filled' => array_column($category_counts, 'filled'),
        'data_open'   => array_column($category_counts, 'open'),
        'i18n'        => [
            'filled'           => esc_html__('Filled', 'eventadmin-volunteer-management'),
            'open'             => esc_html__('Open', 'eventadmin-volunteer-management'),
            'util_dept'        => esc_html__('Utilization per department', 'eventadmin-volunteer-management'),
            'util_all'         => esc_html__('Utilization of all shifts', 'eventadmin-volunteer-management'),
        ],
        'stats'       => [
            'total_users'             => $total_users,
            'total_shifts'            => $total_shifts,
            'filled_shifts'           => $filled_shifts,
            'open_shifts'             => $open_shifts,
            'empty_shifts'            => $empty_shifts,
            'understaffed_shifts'     => $understaffed_shifts,
            'volunteers_without_shift' => $volunteers_without_shift,
        ],
    ];

    echo '<script>';
    echo 'const EVENTADMIN_VOLUNTEER_STATS = ' . wp_json_encode($chart_data);
    echo ' </script>';

    echo '<form method="post" class="export-form">';
    wp_nonce_field('eventadmin_export_all', 'eventadmin_export_all_nonce');
    echo '<input type="hidden" name="eventadmin_export_all" value="1">';
    submit_button(esc_html__('CSV export all shifts', 'eventadmin-volunteer-management'));
    echo '</form>';
    echo '
    <div class="wrap"><h1>' . esc_html__('EventAdmin Overview', 'eventadmin-volunteer-management') . '</h1>
        <div class="eventadmin-dashboard-chart">
            <div class="eventadmin-dashboard-summary">
                <div class="eventadmin-dashboard-box"><strong>' . esc_html__('Registered Volunteers:', 'eventadmin-volunteer-management') . '</strong><br>' . esc_html($total_users) . '</div>
                <div class="eventadmin-dashboard-box"><strong>' . esc_html__('Volunteers without upcoming shift:', 'eventadmin-volunteer-management') . '</strong><br>' . esc_html($volunteers_without_shift) . '</div>
                <div class="eventadmin-dashboard-box"><strong>' . esc_html__('Upcoming shifts:', 'eventadmin-volunteer-management') . '</strong><br>' . esc_html($total_shifts) . '</div>
                <div class="eventadmin-dashboard-box"><strong>' . esc_html__('Empty shifts:', 'eventadmin-volunteer-management') . '</strong><br>' . esc_html($empty_shifts) . '</div>
                <div class="eventadmin-dashboard-box"><strong>' . esc_html__('Understaffed shifts:', 'eventadmin-volunteer-management') . '</strong><br>' . esc_html($understaffed_shifts) . '</div>
                <div class="eventadmin-dashboard-box"><strong>' . esc_html__('Filled spots:', 'eventadmin-volunteer-management') . '</strong><br>' . esc_html($filled_shifts) . '</div>
                <div class="eventadmin-dashboard-box"><strong>' . esc_html__('Open spots:', 'eventadmin-volunteer-management') . '</strong><br>' . esc_html($open_shifts) . '</div>
            </div>
            <div class="chart-box single">
                <canvas id="eventadmin-chart-auslastung"></canvas>
            </div>
            <div class="chart-box double">
                <canvas id="eventadmin-chart"></canvas>
            </div>
        </div>';

    global $eventadmin_form_error;
    if (!empty($eventadmin_form_error)) {
        echo '<div class="notice notice-error"><p>' . esc_html($eventadmin_form_error) . '</p></div>';
    }

    $filter_valid = isset($_GET['eventadmin_filter_shifts_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['eventadmin_filter_shifts_nonce'])), 'eventadmin_filter_shifts');


    $selected_cat   = $filter_valid && isset($_GET['filter_cat'])   ? sanitize_text_field(wp_unslash($_GET['filter_cat']))   : '';
    $selected_state = $filter_valid && isset($_GET['filter_state']) ? sanitize_text_field(wp_unslash($_GET['filter_state'])) : '';
    $categories = get_terms(['taxonomy' => 'eventadmin_shift_category', 'hide_empty' => false]);
    $states = [
        'empty'               => esc_html__('Empty', 'eventadmin-volunteer-management'),
        'understaffed'        => esc_html__('Understaffed', 'eventadmin-volunteer-management'),
        'heavilyunderstaffed' => esc_html__('Heavily understaffed', 'eventadmin-volunteer-management'),
    ];

    // Prepare list (exclude offline volunteers — they have no meaningful profile to filter by)
    $volunteers = get_users([
        'role'       => 'eventadmin_volunteer',
        'meta_query' => [['key' => 'eventadmin_offline_volunteer', 'compare' => 'NOT EXISTS']],
    ]);
    $selected_volunteer = $filter_valid && isset($_GET['filter_volunteer']) ? absint($_GET['filter_volunteer']) : 0;

    // Prepare date filter
    $selected_date = $filter_valid && isset($_GET['filter_date']) ? sanitize_text_field(wp_unslash($_GET['filter_date'])) : '';

    // Time / sort / order filters
    $allowed_time_filters = ['future', 'past', 'all'];
    $allowed_sort_by      = ['date', 'title'];
    $allowed_orders       = ['ASC', 'DESC'];
    $raw_time   = $filter_valid && isset($_GET['filter_time']) ? sanitize_text_field(wp_unslash($_GET['filter_time'])) : '';
    $raw_sortby = $filter_valid && isset($_GET['sort_by'])     ? sanitize_text_field(wp_unslash($_GET['sort_by']))     : '';
    $raw_order  = $filter_valid && isset($_GET['order'])       ? sanitize_text_field(wp_unslash($_GET['order']))       : '';
    $time_filter = in_array($raw_time, $allowed_time_filters, true)          ? $raw_time              : 'future';
    $sort_by     = in_array($raw_sortby, $allowed_sort_by, true)             ? $raw_sortby            : 'date';
    $order       = in_array(strtoupper($raw_order), $allowed_orders, true)  ? strtoupper($raw_order) : 'ASC';

    echo '<form method="get" action="edit.php" class="form-filters">';
    wp_nonce_field('eventadmin_filter_shifts', 'eventadmin_filter_shifts_nonce');
    echo '<input type="hidden" name="post_type" value="eventadmin_shift">';
    echo '<input type="hidden" name="page" value="eventadmin-overview">';

    // Department filter
    echo '<label>' . esc_html__('Department:', 'eventadmin-volunteer-management') . '<select name="filter_cat">';
    echo '<option value="">' . esc_html__('All', 'eventadmin-volunteer-management') . '</option>';
    foreach ($categories as $cat) {
        $sel = $selected_cat === $cat->slug ? 'selected' : '';
        echo '<option value="' . esc_attr($cat->slug) . '" ' . esc_attr($sel) . '>' . esc_html($cat->name) . '</option>';
    }
    echo '</select></label>';

    // State filter
    echo '<label>' . esc_html__('State:', 'eventadmin-volunteer-management') . '<select name="filter_state">';
    echo '<option value="">' . esc_html__('All', 'eventadmin-volunteer-management') . '</option>';
    foreach ($states as $key => $val) {
        $sel = $selected_state === $key ? 'selected' : '';
        echo '<option value="' . esc_attr($key) . '" ' . esc_attr($sel) . '>' . esc_html($val) . '</option>';
    }
    echo '</select></label>';

    // Volunteer filter
    echo '<label>' . esc_html__('Volunteers:', 'eventadmin-volunteer-management') . '<select name="filter_volunteer">';
    echo '<option value="">' . esc_html__('All', 'eventadmin-volunteer-management') . '</option>';
    foreach ($volunteers as $volunteer) {
        $sel = $selected_volunteer == $volunteer->ID ? 'selected' : '';
        echo '<option value="' . esc_attr($volunteer->ID) . '" ' . esc_attr($sel) . '>' . esc_html($volunteer->first_name . ' ' . $volunteer->last_name) . '</option>';
    }
    echo '</select></label>';

    // Date filter
    echo '<label>' . esc_html__('Date:', 'eventadmin-volunteer-management') . ' <input type="date" name="filter_date" value="' . esc_attr($selected_date) . '"></label>';

    // Time period filter
    echo '<label>' . esc_html__('Show:', 'eventadmin-volunteer-management') . '<select name="filter_time">';
    foreach ([
        'future' => esc_html__('Upcoming', 'eventadmin-volunteer-management'),
        'past'   => esc_html__('Past', 'eventadmin-volunteer-management'),
        'all'    => esc_html__('All', 'eventadmin-volunteer-management'),
    ] as $val => $label) {
        echo '<option value="' . esc_attr($val) . '"' . selected($time_filter, $val, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label>';

    // Sort by
    echo '<label>' . esc_html__('Sort by:', 'eventadmin-volunteer-management') . '<select name="sort_by">';
    foreach ([
        'date'  => esc_html__('Date', 'eventadmin-volunteer-management'),
        'title' => esc_html__('Name', 'eventadmin-volunteer-management'),
    ] as $val => $label) {
        echo '<option value="' . esc_attr($val) . '"' . selected($sort_by, $val, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label>';

    // Order
    echo '<label>' . esc_html__('Order:', 'eventadmin-volunteer-management') . '<select name="order">';
    foreach ([
        'ASC'  => esc_html__('Ascending', 'eventadmin-volunteer-management'),
        'DESC' => esc_html__('Descending', 'eventadmin-volunteer-management'),
    ] as $val => $label) {
        echo '<option value="' . esc_attr($val) . '"' . selected($order, $val, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label>';

    echo '<input type="submit" class="button" value="' . esc_attr__('Filter', 'eventadmin-volunteer-management') . '">';
    echo '<a href="' . esc_html(admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-overview')) . '" class="button">' . esc_html__('Reset filter', 'eventadmin-volunteer-management') . '</a>';

    echo '</form>';

    $current_page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
    $per_page     = 20;
    $total_found  = 0;

    $shifts = eventadmin_get_shifts(
        $selected_cat,
        $current_page,
        $per_page,
        $time_filter,
        $sort_by,
        $order,
        $selected_volunteer,
        $selected_date,
        $total_found
    );

    foreach ($shifts as $shift) {
        $title = esc_html($shift->post_title);
        $start = esc_html(get_post_meta($shift->ID, 'shift_start', true));
        $end = esc_html(get_post_meta($shift->ID, 'shift_end', true));
        $min = (int)get_post_meta($shift->ID, 'min_volunteers', true);
        $max = (int)get_post_meta($shift->ID, 'max_volunteers', true);
        $meta = get_post_meta($shift->ID);
        $users = [];

        foreach ($meta as $key => $val) {
            if (str_starts_with($key, 'assigned_user_')) {
                $user_id = absint($val[0]);
                $user = get_userdata($user_id);
                if ($user) {
                    $users[] = [
                        'id'      => $user_id,
                        'name'    => $user->first_name . ' ' . $user->last_name,
                        'email'   => $user->user_email,
                        'phone'   => get_user_meta($user_id, 'eventadmin_phone', true),
                        'offline' => (bool) get_user_meta($user_id, 'eventadmin_offline_volunteer', true),
                    ];
                }
            }
        }

        if (
            ($selected_state === 'empty'               && !empty($users)) ||
            ($selected_state === 'understaffed'        && !(count($users) < $max)) ||
            ($selected_state === 'heavilyunderstaffed' && !($min > 0 && count($users) < $min))
        ) {
            continue;
        }

        $filled = count($users);
        $shift_entry_type = $filled < $max - 1
            ? 'shift-entry-open'
            : ($filled < $max
                ? 'shift-entry-almost-full'
                : 'shift-entry-full');

        echo '<div class="shift-entry ' . esc_attr($shift_entry_type) . '">';
        // CSV export per shift
        echo '<form method="post" class="form-export-shift">';
        wp_nonce_field('eventadmin_export_shift', 'eventadmin_export_shift_nonce');
        echo '<input type="hidden" name="eventadmin_export_shift" value="' . esc_attr($shift->ID) . '">';
        submit_button(esc_html__('CSV for this shift', 'eventadmin-volunteer-management'), 'small', '', false);
        echo '</form>';

        echo '<h2>' . esc_html($title) . '</h2>';
        echo '<p><strong>' . esc_html__('Period:', 'eventadmin-volunteer-management') . '</strong> ' . esc_html(eventadmin_get_formatted_zeitraum($start, $end)) . '</p>';
        echo '<p><strong>' . esc_html__('Filled:', 'eventadmin-volunteer-management') . '</strong> ' . esc_html($filled) . '/' . esc_html($max);
        if ($min > 0) {
            echo ' &nbsp;<strong>' . esc_html__('Min.:', 'eventadmin-volunteer-management') . '</strong> ' . esc_html($min);
            if ($filled < $min) {
                echo ' &nbsp;<span style="color:#d63638;">&#9888; ' . esc_html__('Understaffed', 'eventadmin-volunteer-management') . '</span>';
            }
        }
        echo '</p>';

        $toggle_id = 'add-volunteer-form-' . $shift->ID;

        echo '<p><a href="#" class="toggle-volunteer-form" data-target="#' . esc_attr($toggle_id) . '">' . esc_html__('Add volunteers manually', 'eventadmin-volunteer-management') . '</a></p>';

        echo '<div id="' . esc_attr($toggle_id) . '" class="manual-volunteer-form" style="display:none;">';

        // Existing volunteer selector (exclude already-assigned and offline users)
        $assigned_ids = array_column($users, 'id');
        $existing_volunteers = get_users([
            'role'       => 'eventadmin_volunteer',
            'exclude'    => $assigned_ids,
            'orderby'    => 'display_name',
            'meta_query' => [['key' => 'eventadmin_offline_volunteer', 'compare' => 'NOT EXISTS']],
        ]);
        if (!empty($existing_volunteers)) {
            echo '<form method="post" style="margin-bottom:12px;">';
            wp_nonce_field('eventadmin_add_user', 'eventadmin_add_user_nonce');
            echo '<input type="hidden" name="eventadmin_admin_add_user" value="1">';
            echo '<input type="hidden" name="shift_id" value="' . esc_attr($shift->ID) . '">';
            echo '<input type="hidden" name="assign_existing" value="1">';
            echo '<select name="existing_user_id" style="max-width:220px;margin-right:4px;">';
            echo '<option value="">' . esc_html__('Select existing volunteer…', 'eventadmin-volunteer-management') . '</option>';
            foreach ($existing_volunteers as $v) {
                $label = trim(get_user_meta($v->ID, 'first_name', true) . ' ' . get_user_meta($v->ID, 'last_name', true)) ?: $v->user_login;
                echo '<option value="' . esc_attr($v->ID) . '">' . esc_html($label) . '</option>';
            }
            echo '</select>';
            echo '<label style="margin-right:8px;"><input type="checkbox" name="notify_volunteer" value="1"> ' . esc_html__('Send confirmation email', 'eventadmin-volunteer-management') . '</label>';
            submit_button(esc_html__('Add to shift', 'eventadmin-volunteer-management'), 'secondary small', '', false);
            echo '</form>';
            echo '<p style="margin:0 0 8px;color:#888;font-size:12px;font-style:italic;">— ' . esc_html__('or add a new volunteer below', 'eventadmin-volunteer-management') . ' —</p>';
        }

        // New volunteer form (email optional — leave blank for offline volunteers)
        echo '<form method="post">';
        wp_nonce_field('eventadmin_add_user', 'eventadmin_add_user_nonce');
        echo '<input type="hidden" name="eventadmin_admin_add_user" value="1">';
        echo '<input type="hidden" name="shift_id" value="' . esc_attr($shift->ID) . '">';
        echo '<input type="text" name="first_name" placeholder="' . esc_html__('First name', 'eventadmin-volunteer-management') . '" class="add-volunteer-firstname" title="' . esc_html__('First name', 'eventadmin-volunteer-management') . '" required>';
        echo '<input type="text" name="last_name" placeholder="' . esc_html__('Last name', 'eventadmin-volunteer-management') . '" class="add-volunteer-lastname" title="' . esc_html__('Last name', 'eventadmin-volunteer-management') . '">';
        echo '<input type="text" name="user_identifier" placeholder="' . esc_html__('E-Mail (optional)', 'eventadmin-volunteer-management') . '" class="add-volunteer-email" title="' . esc_html__('Leave blank for offline volunteers without an email address', 'eventadmin-volunteer-management') . '">';
        echo '<input type="text" name="phone" placeholder="' . esc_html__('Phone', 'eventadmin-volunteer-management') . '" class="add-volunteer-phone" title="' . esc_html__('Phone', 'eventadmin-volunteer-management') . '">';
        echo '<label style="display:inline-block;margin-right:8px;"><input type="checkbox" name="notify_volunteer" value="1"> ' . esc_html__('Send confirmation email', 'eventadmin-volunteer-management') . '</label>';
        submit_button(esc_html__('Add', 'eventadmin-volunteer-management'), 'secondary small', '', false);
        echo '</form>';

        echo '</div>';

        if (!empty($users)) {
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>' . esc_html__('Name', 'eventadmin-volunteer-management') . '</th><th>' . esc_html__('E-Mail', 'eventadmin-volunteer-management') . '</th><th>' . esc_html__('Phone', 'eventadmin-volunteer-management') . '</th><th>' . esc_html__('Action', 'eventadmin-volunteer-management') . '</th></tr></thead><tbody>';
            foreach ($users as $u) {
                echo '<tr>';
                echo '<td>' . esc_html($u['name']);
                if (!empty($u['offline'])) echo ' <span style="background:#888;color:#fff;font-size:10px;padding:1px 6px;border-radius:3px;vertical-align:middle;">' . esc_html__('Offline', 'eventadmin-volunteer-management') . '</span>';
                echo '</td>';
                echo '<td>' . (!empty($u['offline']) ? '<em style="color:#aaa;">—</em>' : esc_html($u['email'])) . '</td>';
                echo '<td>' . esc_html($u['phone']) . '</td>';
                echo '<td>';
                echo '<form method="post">';
                wp_nonce_field('eventadmin_unassign', 'eventadmin_unassign_nonce');
                echo '<input type="hidden" name="eventadmin_admin_unassign" value="1">';
                echo '<input type="hidden" name="user_id" value="' . esc_attr($u['id']) . '">';
                echo '<input type="hidden" name="shift_id" value="' . esc_attr($shift->ID) . '">';
                echo '<label style="margin-right:8px;"><input type="checkbox" name="notify_volunteer" value="1"> ' . esc_html__('Notify volunteer', 'eventadmin-volunteer-management') . '</label>';
                submit_button(esc_html__('Remove', 'eventadmin-volunteer-management'), 'delete small', '', false);
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

        } else {
            echo '<p><em>' . esc_html__('No volunteers assigned.', 'eventadmin-volunteer-management') . '</em></p>';
        }


        echo '</div><hr>';
    }

    // Pagination
    $total_pages = $total_found > 0 ? (int)ceil($total_found / $per_page) : 1;
    if ($total_pages > 1) {
        echo '<div class="tablenav"><div class="tablenav-pages">';
        $base_url = add_query_arg(array_filter([
            'post_type'                       => 'eventadmin_shift',
            'page'                            => 'eventadmin-overview',
            'filter_cat'                      => $selected_cat ?: null,
            'filter_state'                    => $selected_state ?: null,
            'filter_volunteer'                => $selected_volunteer ?: null,
            'filter_date'                     => $selected_date ?: null,
            'filter_time'                     => $time_filter !== 'future' ? $time_filter : null,
            'sort_by'                         => $sort_by !== 'date' ? $sort_by : null,
            'order'                           => $order !== 'ASC' ? $order : null,
            'eventadmin_filter_shifts_nonce'  => wp_create_nonce('eventadmin_filter_shifts'),
        ]), admin_url('edit.php'));

        for ($p = 1; $p <= $total_pages; $p++) {
            $url = add_query_arg('paged', $p, $base_url);
            if ($p === $current_page) {
                echo '<span class="current">' . esc_html($p) . '</span> ';
            } else {
                echo '<a class="page-numbers" href="' . esc_url($url) . '">' . esc_html($p) . '</a> ';
            }
        }
        echo '</div></div>';
    }

    echo '</div>';
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

    header("Content-Type: text/csv");
    header("Content-Disposition: attachment; filename=$filename");

    $out = fopen("php://output", "w");
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

/**
 * Adds the metabox for shift details in the admin area
 * @return void
 */
function eventadmin_add_meta_boxes(): void
{
    add_meta_box(
        'shift_eventadmin_info',
        esc_html__('Assigned Volunteers', 'eventadmin-volunteer-management'),
        'eventadmin_shift_meta_box',
        'eventadmin_shift',
        'normal'
    );
}

add_action('add_meta_boxes', 'eventadmin_add_meta_boxes');

/**
 * Metabox for shift details in the admin area
 *
 * Shows the assigned volunteers for a shift
 *
 * @param WP_Post $post The current post object
 */
function eventadmin_shift_meta_box(WP_Post $post): void
{
    $meta = get_post_meta($post->ID);
    $max = get_post_meta($post->ID, 'max_volunteers', true);
    $count = eventadmin_count_assignments($post->ID);

    echo '<p><strong>' . esc_html__('Filled:', 'eventadmin-volunteer-management') . '</strong> ' . esc_html($count) . '/' . esc_html($max) . '</p>';

    echo '<table class="widefat striped">
<thead>
<tr>
<th>' . esc_html__('Name', 'eventadmin-volunteer-management') . '</th>
<th>' . esc_html__('E-Mail', 'eventadmin-volunteer-management') . '</th>
<th>' . esc_html__('Phone', 'eventadmin-volunteer-management') . '</th>
</tr>
</thead>
<tbody>';
    foreach ($meta as $key => $val) {
        if (str_starts_with($key, 'assigned_user_')) {
            $uid = absint($val[0]);
            $user = get_userdata($uid);
            if (!$user) continue;
            $phone      = get_user_meta($uid, 'eventadmin_phone', true);
            $is_offline = (bool) get_user_meta($uid, 'eventadmin_offline_volunteer', true);
            echo '<tr>';
            echo '<td>' . esc_html($user->first_name . ' ' . $user->last_name);
            if ($is_offline) echo ' <span style="background:#888;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;">' . esc_html__('Offline', 'eventadmin-volunteer-management') . '</span>';
            echo '</td>';
            echo '<td>' . ($is_offline ? '—' : esc_html($user->user_email)) . '</td>';
            echo '<td>' . esc_html($phone) . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
}

/**
 * Enqueue scripts and styles for the admin page
 * @return void
 */
function eventadmin_admin_enqueue_dashboard_scripts(): void
{
    $screen = get_current_screen();
    if ($screen->id !== 'eventadmin_shift_page_eventadmin-overview') return;

    wp_enqueue_script(
        'chart-js',
        plugin_dir_url(__FILE__) . '../../assets/js/chart.umd.min.js',
        [],
        '4.5.0',
        true
    );

    wp_enqueue_script(
        'eventadmin-admin-charts',
        plugin_dir_url(__FILE__) . '../../assets/js/admin-charts.js',
        ['chart-js'],
        '1.0',
        true
    );

    wp_enqueue_style(
        'eventadmin-admin-dashboard',
        plugin_dir_url(__FILE__) . '../../assets/css/admin-dashboard.css',
        [],
        '1.0'
    );
}

add_action('admin_enqueue_scripts', 'eventadmin_admin_enqueue_dashboard_scripts');

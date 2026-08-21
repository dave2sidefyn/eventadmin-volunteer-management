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
 * Renders the "Dashboard" tab: summary boxes + utilization charts for upcoming shifts.
 *
 * @param int $total_users Registered volunteer count.
 * @return void
 */
function eventadmin_render_dashboard_stats_tab(int $total_users): void
{
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
    $assigned_user_ids   = [];
    $category_counts     = [];

    $required_open_shifts = 0;
    $optional_open_shifts = 0;

    foreach ($upcoming_shifts as $shift) {
        $max      = (int)get_post_meta($shift->ID, 'max_volunteers', true);
        $min      = (int)get_post_meta($shift->ID, 'min_volunteers', true);
        $assigned = eventadmin_count_assignments($shift->ID);
        $open     = max(0, $max - $assigned);
        // Below min_volunteers is critical/required; the remainder up to max is optional
        // extra capacity — mirrors the red/grey open-slot split used in the Timeline view.
        $required_open = max(0, $min - $assigned);
        $optional_open = $open - $required_open;
        $open_shifts          += $open;
        $required_open_shifts += $required_open;
        $optional_open_shifts += $optional_open;
        $filled_shifts        += $assigned;

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
                $category_counts[$name] = ['filled' => 0, 'required_open' => 0, 'optional_open' => 0];
            }
            $category_counts[$name]['filled']        += $assigned;
            $category_counts[$name]['required_open'] += $required_open;
            $category_counts[$name]['optional_open'] += $optional_open;
        }
    }

    $unique_assigned      = array_unique($assigned_user_ids);
    $volunteers_without_shift = $total_users - count($unique_assigned);

    // JSON for JS
    $chart_data = [
        'labels'               => array_keys($category_counts),
        'data_filled'          => array_column($category_counts, 'filled'),
        'data_required_open'   => array_column($category_counts, 'required_open'),
        'data_optional_open'   => array_column($category_counts, 'optional_open'),
        'i18n'        => [
            'filled'           => esc_html__('Filled', 'eventadmin-volunteer-management'),
            'required_open'    => esc_html__('Open (required)', 'eventadmin-volunteer-management'),
            'optional_open'    => esc_html__('Open (optional)', 'eventadmin-volunteer-management'),
            'util_dept'        => esc_html__('Utilization per department', 'eventadmin-volunteer-management'),
            'util_all'         => esc_html__('Utilization of all shifts', 'eventadmin-volunteer-management'),
        ],
        'stats'       => [
            'total_users'             => $total_users,
            'total_shifts'            => $total_shifts,
            'filled_shifts'           => $filled_shifts,
            'open_shifts'             => $open_shifts,
            'required_open_shifts'    => $required_open_shifts,
            'optional_open_shifts'    => $optional_open_shifts,
            'volunteers_without_shift' => $volunteers_without_shift,
        ],
    ];

    echo '<script>';
    echo 'const EVENTADMIN_VOLUNTEER_STATS = ' . wp_json_encode($chart_data);
    echo ' </script>';

    echo '
        <div class="eventadmin-dashboard-chart">
            <div class="eventadmin-dashboard-summary">
                <div class="eventadmin-dashboard-box"><strong>' . esc_html__('Registered Volunteers:', 'eventadmin-volunteer-management') . '</strong><br>' . esc_html($total_users) . '</div>
                <div class="eventadmin-dashboard-box"><strong>' . esc_html__('Volunteers without upcoming shift:', 'eventadmin-volunteer-management') . '</strong><br>' . esc_html($volunteers_without_shift) . '</div>
                <div class="eventadmin-dashboard-box"><strong>' . esc_html__('Upcoming shifts:', 'eventadmin-volunteer-management') . '</strong><br>' . esc_html($total_shifts) . '</div>
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

    global $eventadmin_form_error;
    if (!empty($eventadmin_form_error)) {
        echo '<div class="notice notice-error"><p>' . esc_html($eventadmin_form_error) . '</p></div>';
    }

    $filter_valid = isset($_GET['eventadmin_filter_shifts_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['eventadmin_filter_shifts_nonce'])), 'eventadmin_filter_shifts');


    $selected_cat   = $filter_valid && isset($_GET['filter_cat'])   ? sanitize_text_field(wp_unslash($_GET['filter_cat']))   : '';
    $selected_state = $filter_valid && isset($_GET['filter_state']) ? sanitize_text_field(wp_unslash($_GET['filter_state'])) : '';
    $categories = eventadmin_get_hierarchical_shift_categories();
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

    // Prepare date filter — list only the dates that actually have shifts (typically just
    // the 3-4 event days), instead of a blind date picker where almost every day is empty.
    $selected_date = $filter_valid && isset($_GET['filter_date']) ? sanitize_text_field(wp_unslash($_GET['filter_date'])) : '';
    global $wpdb;
    $shift_dates = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT DATE(pm.meta_value) AS shift_date
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = 'shift_start' AND p.post_type = %s AND p.post_status != 'trash' AND pm.meta_value != ''
         ORDER BY shift_date ASC",
        'eventadmin_shift'
    ));

    // Time / sort / order filters
    $allowed_time_filters = ['future', 'past', 'all'];
    $allowed_sort_by      = ['date', 'title'];
    $allowed_orders       = ['ASC', 'DESC'];
    $raw_time   = $filter_valid && isset($_GET['filter_time']) ? sanitize_text_field(wp_unslash($_GET['filter_time'])) : '';
    $raw_sortby = $filter_valid && isset($_GET['sort_by'])     ? sanitize_text_field(wp_unslash($_GET['sort_by']))     : '';
    $raw_order  = $filter_valid && isset($_GET['order'])       ? sanitize_text_field(wp_unslash($_GET['order']))       : '';
    $raw_view   = $filter_valid && isset($_GET['filter_view']) ? sanitize_text_field(wp_unslash($_GET['filter_view'])) : '';
    $time_filter = in_array($raw_time, $allowed_time_filters, true)          ? $raw_time              : 'future';
    $sort_by     = in_array($raw_sortby, $allowed_sort_by, true)             ? $raw_sortby            : 'date';
    $order       = in_array(strtoupper($raw_order), $allowed_orders, true)  ? strtoupper($raw_order) : 'ASC';
    $allowed_views = ['dashboard', 'cards', 'table', 'timeline'];
    $view          = in_array($raw_view, $allowed_views, true) ? $raw_view : 'dashboard';
    // Checkboxes submit nothing when unchecked, so a hidden "0" companion field (rendered
    // just before the checkbox) is what lets us tell "unchecked" apart from "not submitted
    // yet" — the checkbox's own value overwrites it in the query string only when checked.
    $show_open = !$filter_valid || (isset($_GET['show_open']) && $_GET['show_open'] === '1');

    // Shared base query args for building both the view tabs and pagination links below,
    // so a filter change (department, date, …) doesn't reset the other.
    $filter_query_args = array_filter([
        'post_type'                       => 'eventadmin_shift',
        'page'                            => 'eventadmin-overview',
        'filter_cat'                      => $selected_cat ?: null,
        'filter_state'                    => $selected_state ?: null,
        'filter_volunteer'                => $selected_volunteer ?: null,
        'filter_date'                     => $selected_date ?: null,
        'filter_time'                     => $time_filter !== 'future' ? $time_filter : null,
        'sort_by'                         => $sort_by !== 'date' ? $sort_by : null,
        'order'                           => $order !== 'ASC' ? $order : null,
        'show_open'                       => $show_open ? null : '0',
        'eventadmin_filter_shifts_nonce'  => wp_create_nonce('eventadmin_filter_shifts'),
    ]);

    echo '<form method="post" class="export-form">';
    wp_nonce_field('eventadmin_export_all', 'eventadmin_export_all_nonce');
    echo '<input type="hidden" name="eventadmin_export_all" value="1">';
    submit_button(esc_html__('CSV export all shifts', 'eventadmin-volunteer-management'));
    echo '</form>';

    echo '<div class="wrap"><h1>' . esc_html__('EventAdmin Overview', 'eventadmin-volunteer-management') . '</h1>';

    // View tabs
    echo '<h2 class="nav-tab-wrapper">';
    foreach ([
        'dashboard' => esc_html__('Dashboard', 'eventadmin-volunteer-management'),
        'cards'    => esc_html__('Cards', 'eventadmin-volunteer-management'),
        'table'    => esc_html__('Table', 'eventadmin-volunteer-management'),
        'timeline' => esc_html__('Timeline', 'eventadmin-volunteer-management'),
    ] as $slug => $label) {
        $tab_url = add_query_arg(array_merge($filter_query_args, ['filter_view' => $slug]), admin_url('edit.php'));
        $active  = $view === $slug ? ' nav-tab-active' : '';
        echo '<a class="nav-tab' . $active . '" href="' . esc_url($tab_url) . '">' . $label . '</a>';
    }
    echo '</h2>';

    if ($view === 'dashboard') {
        eventadmin_render_dashboard_stats_tab($total_users);
        echo '</div>';
        return;
    }

    echo '<form method="get" action="edit.php" id="eventadmin-overview-filters" class="form-filters" style="margin-top:1rem;">';
    wp_nonce_field('eventadmin_filter_shifts', 'eventadmin_filter_shifts_nonce');
    echo '<input type="hidden" name="post_type" value="eventadmin_shift">';
    echo '<input type="hidden" name="page" value="eventadmin-overview">';

    // Department filter
    echo '<label>' . esc_html__('Department:', 'eventadmin-volunteer-management') . '<select name="filter_cat">';
    echo '<option value="">' . esc_html__('All', 'eventadmin-volunteer-management') . '</option>';
    echo eventadmin_category_dropdown_options($categories, $selected_cat, 'slug');
    echo '</select></label>';

    // The State filter only makes sense against the Cards view's per-shift grouping — Table
    // and Timeline both show individual assignment rows, not one card per shift.
    if ($view === 'cards') {
        echo '<label>' . esc_html__('State:', 'eventadmin-volunteer-management') . '<select name="filter_state">';
        echo '<option value="">' . esc_html__('All', 'eventadmin-volunteer-management') . '</option>';
        foreach ($states as $key => $val) {
            $sel = $selected_state === $key ? 'selected' : '';
            echo '<option value="' . esc_attr($key) . '" ' . esc_attr($sel) . '>' . esc_html($val) . '</option>';
        }
        echo '</select></label>';
    }

    // Volunteer filter doesn't apply to the Timeline (it already shows every volunteer's
    // own row, so scoping to one defeats the point of the view).
    if ($view !== 'timeline') {
        echo '<label>' . esc_html__('Volunteers:', 'eventadmin-volunteer-management') . '<select name="filter_volunteer">';
        echo '<option value="">' . esc_html__('All', 'eventadmin-volunteer-management') . '</option>';
        foreach ($volunteers as $volunteer) {
            $sel = $selected_volunteer == $volunteer->ID ? 'selected' : '';
            echo '<option value="' . esc_attr($volunteer->ID) . '" ' . esc_attr($sel) . '>' . esc_html($volunteer->first_name . ' ' . $volunteer->last_name) . '</option>';
        }
        echo '</select></label>';
    }

    // Date filter
    echo '<label>' . esc_html__('Date:', 'eventadmin-volunteer-management') . '<select name="filter_date">';
    echo '<option value="">' . esc_html__('All', 'eventadmin-volunteer-management') . '</option>';
    foreach ($shift_dates as $date) {
        $label = date_i18n('l, ' . get_option('date_format'), strtotime($date));
        echo '<option value="' . esc_attr($date) . '"' . selected($selected_date, $date, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label>';

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

    // Sort/Order dropdowns are only needed for Cards — the Timeline positions bars by
    // actual time regardless, and the Table sorts via its own clickable column headers.
    if ($view === 'cards') {
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
    }

    // Preserves the active view tab when submitting other filters (the tabs themselves
    // are plain links, not part of this form).
    echo '<input type="hidden" name="filter_view" value="' . esc_attr($view) . '">';

    // Only meaningful on the Timeline itself.
    if ($view === 'timeline') {
        echo '<label style="margin-left:8px;"><input type="hidden" name="show_open" value="0">';
        echo '<input type="checkbox" name="show_open" value="1"' . checked($show_open, true, false) . '> ';
        echo esc_html__('Show open slots in timeline', 'eventadmin-volunteer-management') . '</label>';
    }

    echo '<a href="' . esc_html(admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-overview')) . '" class="button">' . esc_html__('Reset filter', 'eventadmin-volunteer-management') . '</a>';
    echo '<noscript><input type="submit" class="button" value="' . esc_attr__('Filter', 'eventadmin-volunteer-management') . '"></noscript>';

    echo '</form>';
    echo '<script>
        document.querySelectorAll("#eventadmin-overview-filters select, #eventadmin-overview-filters input[type=checkbox]").forEach(function (el) {
            el.addEventListener("change", function () { el.form.submit(); });
        });
    </script>';

    $current_page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
    $per_page     = 200;
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

    $table_rows     = [];
    $timeline_rows  = [];
    $shift_info_map = [];
    $shift_edit_map = [];

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

        if ($view !== 'cards') {
            $shift_info_map[$shift->ID] = [
                'title'    => $title,
                'assigned' => array_column($users, 'id'),
            ];
        }

        if (
            ($selected_state === 'empty'               && !empty($users)) ||
            ($selected_state === 'understaffed'        && !(count($users) < $max)) ||
            ($selected_state === 'heavilyunderstaffed' && !($min > 0 && count($users) < $min))
        ) {
            continue;
        }

        if ($view === 'table') {
            $shift_categories = wp_get_post_terms($shift->ID, 'eventadmin_shift_category');
            $category_names   = implode(', ', wp_list_pluck($shift_categories, 'name'));
            $period           = eventadmin_get_formatted_zeitraum($start, $end);
            $capacity_label   = count($users) . '/' . $max;
            if ($min > 0) {
                /* translators: %d is the minimum number of volunteers required */
                $capacity_label .= ' ' . sprintf(esc_html__('(min %d)', 'eventadmin-volunteer-management'), $min);
            }
            foreach ($users as $u) {
                $table_rows[] = [
                    'category' => $category_names,
                    'shift'    => $title,
                    'period'   => $period,
                    'capacity' => $capacity_label,
                    'name'     => $u['name'],
                    'email'    => $u['offline'] ? '' : $u['email'],
                    'phone'    => $u['phone'],
                    'open'     => false,
                ];
            }
            // One placeholder row per still-open slot, so it's obvious at a glance
            // how many more volunteers a shift needs — and gives a ready line to fill in.
            for ($i = count($users); $i < $max; $i++) {
                $table_rows[] = [
                    'category' => $category_names,
                    'shift'    => $title,
                    'shift_id' => $shift->ID,
                    'period'   => $period,
                    'capacity' => $capacity_label,
                    'name'     => '',
                    'email'    => '',
                    'phone'    => '',
                    'open'     => true,
                ];
            }
            continue;
        }

        if ($view === 'timeline') {
            $shift_categories = wp_get_post_terms($shift->ID, 'eventadmin_shift_category');
            $bar_color        = !empty($shift_categories)
                ? (get_term_meta($shift_categories[0]->term_id, 'term_color', true) ?: '#2271b1')
                : '#2271b1';
            $start_ts = eventadmin_wallclock_to_ts($start);
            $end_ts   = eventadmin_wallclock_to_ts($end);
            $period   = eventadmin_get_formatted_zeitraum($start, $end);
            $assigned = count($users);

            // Raw (untranslated-label) data for the Edit Shift modal — keyed by shift so
            // clicking any of that shift's rows (one per volunteer/open slot) opens the
            // same editable record.
            $shift_edit_map[$shift->ID] = [
                'title'       => $shift->post_title,
                'category_id' => !empty($shift_categories) ? $shift_categories[0]->term_id : 0,
                'start'       => $start_ts,
                'end'         => $end_ts,
                'min'         => $min,
                'max'         => $max,
            ];
            $capacity_label = $assigned . '/' . $max;
            if ($min > 0) {
                /* translators: %d is the minimum number of volunteers required */
                $capacity_label .= ' ' . sprintf(esc_html__('(min %d)', 'eventadmin-volunteer-management'), $min);
            }
            foreach ($users as $u) {
                $timeline_rows[] = [
                    'volunteer' => $u['name'],
                    'shift'     => $title,
                    'shift_id'  => $shift->ID,
                    'period'    => $period,
                    'capacity'  => $capacity_label,
                    'start'     => $start_ts,
                    'end'       => $end_ts,
                    'color'     => $bar_color,
                    'open'      => false,
                ];
            }
            // Open slots: the portion still below min_volunteers is critical (red),
            // the rest up to max_volunteers is nice-to-have extra capacity (grey).
            // Skipped entirely when the "show open slots" filter is off.
            if ($show_open) {
                $required_open = max(0, $min - $assigned);
                $optional_open = max(0, $max - $assigned) - $required_open;
                $open_label    = esc_html__('Open slot', 'eventadmin-volunteer-management');
                for ($i = 0; $i < $required_open; $i++) {
                    $timeline_rows[] = [
                        'volunteer' => $open_label,
                        'shift'     => $title,
                        'shift_id'  => $shift->ID,
                        'period'    => $period,
                        'capacity'  => $capacity_label,
                        'start'     => $start_ts,
                        'end'       => $end_ts,
                        'color'     => '#e53935',
                        'open'      => true,
                    ];
                }
                for ($i = 0; $i < $optional_open; $i++) {
                    $timeline_rows[] = [
                        'volunteer' => $open_label,
                        'shift'     => $title,
                        'shift_id'  => $shift->ID,
                        'period'    => $period,
                        'capacity'  => $capacity_label,
                        'start'     => $start_ts,
                        'end'       => $end_ts,
                        'color'     => '#9e9e9e',
                        'open'      => true,
                    ];
                }
            }
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
            'meta_query' => [['key' => 'eventadmin_offline_volunteer', 'compare' => 'NOT EXISTS']],
        ]);
        // Sort by the same first/last name shown in the option label below — registration
        // never sets WP's display_name, so sorting by it (as before) put volunteers in
        // an order unrelated to what the dropdown actually displays.
        usort($existing_volunteers, function ($a, $b) {
            $label_a = trim($a->first_name . ' ' . $a->last_name) ?: $a->user_login;
            $label_b = trim($b->first_name . ' ' . $b->last_name) ?: $b->user_login;
            return strcasecmp($label_a, $label_b);
        });
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
                // Offline volunteers have no email address to notify — the checkbox would
                // be a no-op (already silently skipped server-side), so don't show it at all.
                if (empty($u['offline'])) {
                    echo '<label style="margin-right:8px;"><input type="checkbox" name="notify_volunteer" value="1"> ' . esc_html__('Notify volunteer', 'eventadmin-volunteer-management') . '</label>';
                }
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

    if ($view === 'table') {
        echo '<table class="widefat striped" id="eventadmin-roster-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Category', 'eventadmin-volunteer-management') . '</th>';
        // Shift/Period headers are clickable — sorting by name or date directly on the
        // column removes the need for the separate "Sort by"/"Order" dropdowns.
        foreach (['title' => esc_html__('Shift', 'eventadmin-volunteer-management'), 'date' => esc_html__('Period', 'eventadmin-volunteer-management')] as $sort_key => $col_label) {
            $next_order = ($sort_by === $sort_key && $order === 'ASC') ? 'DESC' : 'ASC';
            $col_url    = add_query_arg(array_merge($filter_query_args, ['filter_view' => $view, 'sort_by' => $sort_key, 'order' => $next_order]), admin_url('edit.php'));
            $arrow      = $sort_by === $sort_key ? ($order === 'ASC' ? ' &#8593;' : ' &#8595;') : '';
            echo '<th><a href="' . esc_url($col_url) . '" style="text-decoration:none;color:inherit;">' . $col_label . $arrow . '</a></th>';
        }
        echo '<th>' . esc_html__('Capacity', 'eventadmin-volunteer-management') . '</th>';
        echo '<th>' . esc_html__('Name', 'eventadmin-volunteer-management') . '</th>';
        echo '<th>' . esc_html__('E-Mail', 'eventadmin-volunteer-management') . '</th>';
        echo '<th>' . esc_html__('Phone', 'eventadmin-volunteer-management') . '</th>';
        echo '</tr></thead><tbody>';
        if (empty($table_rows)) {
            echo '<tr><td colspan="7"><em>' . esc_html__('No shifts found.', 'eventadmin-volunteer-management') . '</em></td></tr>';
        }
        foreach ($table_rows as $row) {
            echo '<tr' . ($row['open'] ? ' class="eventadmin-open-slot-row"' : '') . '>';
            echo '<td>' . esc_html($row['category']) . '</td>';
            echo '<td>' . esc_html($row['shift']) . '</td>';
            echo '<td>' . esc_html($row['period']) . '</td>';
            echo '<td>' . esc_html($row['capacity']) . '</td>';
            if ($row['open']) {
                echo '<td colspan="3"><em>&#9888; ' . esc_html__('Open slot — not yet booked', 'eventadmin-volunteer-management') . '</em> ';
                echo '<button type="button" class="button button-small eventadmin-open-slot-add" data-shift-id="' . esc_attr($row['shift_id']) . '">' . esc_html__('Add volunteer', 'eventadmin-volunteer-management') . '</button></td>';
            } else {
                echo '<td>' . esc_html($row['name']) . '</td>';
                echo '<td>' . ($row['email'] === '' ? '<em style="color:#aaa;">—</em>' : esc_html($row['email'])) . '</td>';
                echo '<td>' . ($row['phone'] === '' ? '<em style="color:#aaa;">—</em>' : esc_html($row['phone'])) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    if ($view === 'timeline') {
        if (empty($timeline_rows)) {
            echo '<p><em>' . esc_html__('No shifts found.', 'eventadmin-volunteer-management') . '</em></p>';
        } else {
            // One row per (volunteer, shift) instance, ordered chronologically, then by
            // shift name, then by volunteer name.
            usort($timeline_rows, function ($a, $b) {
                return $a['start'] <=> $b['start']
                    ?: $a['shift'] <=> $b['shift']
                    ?: $a['volunteer'] <=> $b['volunteer'];
            });

            $row_height = 28;
            $height     = max(200, count($timeline_rows) * $row_height + 60);

            echo '<p id="eventadmin-timeline-selected" style="min-height:1.5em;font-weight:600;"></p>';
            echo '<div style="overflow-x:auto;">';
            echo '<div style="min-width:700px;height:' . esc_attr($height) . 'px;">';
            echo '<canvas id="eventadmin-timeline-chart"></canvas>';
            echo '</div>';
            echo '</div>';

            echo '<script>';
            echo 'const EVENTADMIN_TIMELINE_DATA = ' . wp_json_encode([
                'rows' => $timeline_rows,
                'i18n' => [
                    'shift'    => esc_html__('Shift', 'eventadmin-volunteer-management'),
                    'period'   => esc_html__('Period', 'eventadmin-volunteer-management'),
                    'capacity' => esc_html__('Capacity', 'eventadmin-volunteer-management'),
                    'selected' => esc_html__('Selected: {volunteer} — {shift} ({period}, {capacity})', 'eventadmin-volunteer-management'),
                ],
            ]);
            echo ';</script>';
        }
    }

    // Shared "add volunteer" modal for the Table and Timeline views — Cards already has
    // this same form inline per shift, so this reuses the exact same fields/handler and
    // just wraps them in a JS-toggled overlay instead of one form per card.
    if ($view !== 'cards') {
        echo '<div id="eventadmin-add-volunteer-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;">';
        echo '<div style="position:relative;background:#fff;max-width:480px;margin:60px auto;padding:20px;border-radius:6px;max-height:80vh;overflow-y:auto;">';
        echo '<button type="button" id="eventadmin-modal-close" aria-label="' . esc_attr__('Close', 'eventadmin-volunteer-management') . '" style="position:absolute;top:10px;right:10px;background:none;border:none;font-size:24px;line-height:1;cursor:pointer;color:#666;padding:4px 8px;">&times;</button>';
        echo '<h2 id="eventadmin-modal-shift-title" style="margin-top:0;"></h2>';

        echo '<form method="post" id="eventadmin-modal-existing-form" style="margin-bottom:12px;">';
        wp_nonce_field('eventadmin_add_user', 'eventadmin_add_user_nonce');
        echo '<input type="hidden" name="eventadmin_admin_add_user" value="1">';
        echo '<input type="hidden" name="shift_id" id="eventadmin-modal-shift-id-existing" value="">';
        echo '<input type="hidden" name="assign_existing" value="1">';
        echo '<p><select name="existing_user_id" id="eventadmin-modal-existing-select" style="width:100%;">';
        echo '<option value="">' . esc_html__('Select existing volunteer…', 'eventadmin-volunteer-management') . '</option>';
        echo '</select></p>';
        echo '<label><input type="checkbox" name="notify_volunteer" value="1"> ' . esc_html__('Send confirmation email', 'eventadmin-volunteer-management') . '</label>';
        echo '<p>';
        submit_button(esc_html__('Add to shift', 'eventadmin-volunteer-management'), 'secondary small', '', false);
        echo '</p></form>';

        echo '<p style="color:#888;font-size:12px;font-style:italic;">— ' . esc_html__('or add a new volunteer below', 'eventadmin-volunteer-management') . ' —</p>';

        echo '<form method="post" id="eventadmin-modal-new-form">';
        wp_nonce_field('eventadmin_add_user', 'eventadmin_add_user_nonce');
        echo '<input type="hidden" name="eventadmin_admin_add_user" value="1">';
        echo '<input type="hidden" name="shift_id" id="eventadmin-modal-shift-id-new" value="">';
        echo '<p><input type="text" name="first_name" placeholder="' . esc_attr__('First name', 'eventadmin-volunteer-management') . '" style="width:100%;" required></p>';
        echo '<p><input type="text" name="last_name" placeholder="' . esc_attr__('Last name', 'eventadmin-volunteer-management') . '" style="width:100%;"></p>';
        echo '<p><input type="text" name="user_identifier" placeholder="' . esc_attr__('E-Mail (optional)', 'eventadmin-volunteer-management') . '" style="width:100%;" title="' . esc_attr__('Leave blank for offline volunteers without an email address', 'eventadmin-volunteer-management') . '"></p>';
        echo '<p><input type="text" name="phone" placeholder="' . esc_attr__('Phone', 'eventadmin-volunteer-management') . '" style="width:100%;"></p>';
        echo '<label><input type="checkbox" name="notify_volunteer" value="1"> ' . esc_html__('Send confirmation email', 'eventadmin-volunteer-management') . '</label>';
        echo '<p>';
        submit_button(esc_html__('Add', 'eventadmin-volunteer-management'), 'secondary small', '', false);
        echo '</p></form>';

        echo '</div></div>';

        $all_volunteer_options = array_map(function ($v) {
            return [
                'id'    => $v->ID,
                'label' => trim($v->first_name . ' ' . $v->last_name) ?: $v->user_login,
            ];
        }, $volunteers);

        echo '<script>';
        echo 'const EVENTADMIN_VOLUNTEERS = ' . wp_json_encode($all_volunteer_options) . ';';
        echo 'const EVENTADMIN_SHIFT_INFO = ' . wp_json_encode($shift_info_map) . ';';
        echo ';</script>';
    }

    // Timeline-only: drag-to-move/resize bars, and click a filled bar to edit the shift's
    // own details (title, department, times, min/max) without leaving the page.
    if ($view === 'timeline') {
        echo '<div id="eventadmin-edit-shift-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;">';
        echo '<div style="position:relative;background:#fff;max-width:480px;margin:60px auto;padding:20px;border-radius:6px;max-height:80vh;overflow-y:auto;">';
        echo '<button type="button" id="eventadmin-edit-shift-close" aria-label="' . esc_attr__('Close', 'eventadmin-volunteer-management') . '" style="position:absolute;top:10px;right:10px;background:none;border:none;font-size:24px;line-height:1;cursor:pointer;color:#666;padding:4px 8px;">&times;</button>';
        echo '<h2 style="margin-top:0;">' . esc_html__('Edit shift', 'eventadmin-volunteer-management') . '</h2>';
        echo '<form id="eventadmin-edit-shift-form">';
        echo '<input type="hidden" id="eventadmin-edit-shift-id" value="">';
        echo '<p><label>' . esc_html__('Title', 'eventadmin-volunteer-management') . '<br><input type="text" id="eventadmin-edit-shift-title" style="width:100%;" required></label></p>';
        echo '<p><label>' . esc_html__('Department', 'eventadmin-volunteer-management') . '<br><select id="eventadmin-edit-shift-category" style="width:100%;">';
        echo '<option value="0">' . esc_html__('— None —', 'eventadmin-volunteer-management') . '</option>';
        echo eventadmin_category_dropdown_options($categories, 0, 'term_id');
        echo '</select></label></p>';
        echo '<p style="display:flex;gap:8px;">';
        echo '<label style="flex:1;">' . esc_html__('Start', 'eventadmin-volunteer-management') . '<br><input type="datetime-local" id="eventadmin-edit-shift-start" style="width:100%;" required></label>';
        echo '<label style="flex:1;">' . esc_html__('End', 'eventadmin-volunteer-management') . '<br><input type="datetime-local" id="eventadmin-edit-shift-end" style="width:100%;" required></label>';
        echo '</p>';
        echo '<p style="display:flex;gap:8px;">';
        echo '<label style="flex:1;">' . esc_html__('Min. Volunteers', 'eventadmin-volunteer-management') . '<br><input type="number" id="eventadmin-edit-shift-min" min="0" style="width:100%;"></label>';
        echo '<label style="flex:1;">' . esc_html__('Max. Volunteers', 'eventadmin-volunteer-management') . '<br><input type="number" id="eventadmin-edit-shift-max" min="1" style="width:100%;"></label>';
        echo '</p>';
        echo '<p id="eventadmin-edit-shift-error" style="color:#d63638;"></p>';
        echo '<p>';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Save', 'eventadmin-volunteer-management') . '</button> ';
        echo '<a href="#" id="eventadmin-edit-shift-full-link" target="_blank" style="margin-left:8px;">' . esc_html__('Open full editor', 'eventadmin-volunteer-management') . '</a>';
        echo '</p>';
        echo '</form>';
        echo '</div></div>';

        echo '<script>';
        echo 'const EVENTADMIN_SHIFT_EDIT = ' . wp_json_encode([
            'ajax_url'      => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('eventadmin_update_shift'),
            'shifts'        => $shift_edit_map,
            'edit_url_base' => admin_url('post.php?action=edit&post='),
            'i18n'          => [
                'error'          => esc_html__('An error occurred. Please try again.', 'eventadmin-volunteer-management'),
                'timeUpdated'    => esc_html__('Shift time updated.', 'eventadmin-volunteer-management'),
                'undo'           => esc_html__('Undo', 'eventadmin-volunteer-management'),
            ],
        ]);
        echo ';</script>';
    }

    // Pagination
    $total_pages = $total_found > 0 ? (int)ceil($total_found / $per_page) : 1;
    if ($total_pages > 1) {
        echo '<div class="tablenav"><div class="tablenav-pages">';
        $base_url = add_query_arg(
            array_merge($filter_query_args, ['filter_view' => $view]),
            admin_url('edit.php')
        );

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
 * AJAX: updates a shift's own details from the Timeline view — dragging a bar sends just
 * start/end, the Edit Shift modal sends everything. Only fields actually present in the
 * request are touched, and the fresh values are returned so the caller can patch its chart
 * data in place without reloading the page.
 */
function eventadmin_ajax_update_shift(): void
{
    if (
        !isset($_POST['nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eventadmin_update_shift')
    ) {
        wp_send_json_error(['message' => esc_html__('Security check failed.', 'eventadmin-volunteer-management')]);
    }

    $shift_id = isset($_POST['shift_id']) ? absint($_POST['shift_id']) : 0;
    $shift    = $shift_id ? get_post($shift_id) : null;

    if (!$shift || $shift->post_type !== 'eventadmin_shift' || !current_user_can('edit_post', $shift_id)) {
        wp_send_json_error(['message' => esc_html__('Shift not found.', 'eventadmin-volunteer-management')]);
    }

    $time_changed = false;

    if (isset($_POST['title'])) {
        $title = sanitize_text_field(wp_unslash($_POST['title']));
        if ($title !== '') {
            wp_update_post(['ID' => $shift_id, 'post_title' => $title]);
        }
    }

    if (isset($_POST['start'])) {
        update_post_meta($shift_id, 'shift_start', eventadmin_normalize_datetime_input(sanitize_text_field(wp_unslash($_POST['start']))));
        $time_changed = true;
    }

    if (isset($_POST['end'])) {
        update_post_meta($shift_id, 'shift_end', eventadmin_normalize_datetime_input(sanitize_text_field(wp_unslash($_POST['end']))));
        $time_changed = true;
    }

    if (isset($_POST['min'])) {
        update_post_meta($shift_id, 'min_volunteers', absint($_POST['min']));
    }

    if (isset($_POST['max'])) {
        update_post_meta($shift_id, 'max_volunteers', max(1, absint($_POST['max'])));
    }

    if (isset($_POST['category_id'])) {
        $category_id = absint($_POST['category_id']);
        if ($category_id > 0 && get_term($category_id, 'eventadmin_shift_category')) {
            wp_set_object_terms($shift_id, [$category_id], 'eventadmin_shift_category');
        } else {
            wp_set_object_terms($shift_id, [], 'eventadmin_shift_category');
        }
    }

    if ($time_changed) {
        eventadmin_clear_shift_reminder_markers($shift_id);
    }

    $start          = get_post_meta($shift_id, 'shift_start', true);
    $end            = get_post_meta($shift_id, 'shift_end', true);
    $terms          = wp_get_post_terms($shift_id, 'eventadmin_shift_category');
    $color          = !empty($terms) ? (get_term_meta($terms[0]->term_id, 'term_color', true) ?: '#2271b1') : '#2271b1';
    $min            = (int) get_post_meta($shift_id, 'min_volunteers', true);
    $max            = (int) get_post_meta($shift_id, 'max_volunteers', true);
    $assigned_count = eventadmin_count_assignments($shift_id);
    $capacity_label = $assigned_count . '/' . $max;
    if ($min > 0) {
        /* translators: %d is the minimum number of volunteers required */
        $capacity_label .= ' ' . sprintf(esc_html__('(min %d)', 'eventadmin-volunteer-management'), $min);
    }

    wp_send_json_success([
        'shift_id'    => $shift_id,
        'title'       => get_the_title($shift_id),
        'start'       => eventadmin_wallclock_to_ts($start),
        'end'         => eventadmin_wallclock_to_ts($end),
        'period'      => eventadmin_get_formatted_zeitraum($start, $end),
        'category_id' => !empty($terms) ? $terms[0]->term_id : 0,
        'color'       => $color,
        'min'         => $min,
        'max'         => $max,
        'capacity'    => $capacity_label,
    ]);
}

add_action('wp_ajax_eventadmin_update_shift', 'eventadmin_ajax_update_shift');

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

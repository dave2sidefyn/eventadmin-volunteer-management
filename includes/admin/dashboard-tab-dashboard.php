<?php
/**
 * EventAdmin Volunteer Management - Dashboard Tab
 * Renders the Dashboard tab's stat boxes and Chart.js charts.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Renders the "Dashboard" tab: summary boxes + utilization charts for upcoming shifts.
 *
 * @param int $total_users Registered volunteer count.
 * @return void
 */
function eventadmin_render_dashboard_stats_tab(int $total_users): void
{
    // Stats are always based on upcoming shifts only — "upcoming" here means the shift
    // hasn't ended yet, so a shift already in progress still counts.
    $upcoming_shifts = get_posts([
        'post_type'   => 'eventadmin_shift',
        'numberposts' => -1,
        'meta_query'  => [[
            'key'     => 'shift_end',
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

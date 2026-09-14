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

/**
 * Builds the clickable "View profile" trigger for one activity-feed entry — a volunteer
 * who has since been deleted (rare, but the log outlives the user account) falls back to
 * plain, non-clickable text instead of a dead link.
 *
 * @param int $user_id
 * @return string
 */
function eventadmin_activity_feed_volunteer_link(int $user_id): string
{
    $user = get_userdata($user_id);
    if (!$user) {
        return esc_html__('a deleted volunteer', 'eventadmin-volunteer-management');
    }

    $name = trim($user->first_name . ' ' . $user->last_name) ?: $user->user_login;

    return '<button type="button" class="button-link eventadmin-view-volunteer-profile" data-user-id="'
        . esc_attr($user_id) . '" data-name="' . esc_attr($name) . '">' . esc_html($name) . '</button>';
}

/**
 * Builds the clickable "Shift details" trigger for one activity-feed entry — a shift that
 * has since been deleted falls back to plain, non-clickable text.
 *
 * @param int $shift_id
 * @return string
 */
function eventadmin_activity_feed_shift_link(int $shift_id): string
{
    $shift = get_post($shift_id);
    if (!$shift || $shift->post_type !== 'eventadmin_shift') {
        return esc_html__('a deleted shift', 'eventadmin-volunteer-management');
    }

    return '<button type="button" class="button-link eventadmin-view-shift-details" data-shift-id="'
        . esc_attr($shift_id) . '" data-title="' . esc_attr($shift->post_title) . '">' . esc_html($shift->post_title) . '</button>';
}

/**
 * Turns one eventadmin_log_shift_activity() entry into its human-readable sentence, e.g.
 * "Achim Spörri signed up for Morning Shift" or "Jane Doe moved Max Muster from Morning
 * Shift to Afternoon Shift". Volunteer and shift names are clickable triggers for the
 * "View profile" / "Shift details" modals (both rendered by
 * eventadmin_render_shared_volunteer_modals(), always present alongside this feed).
 *
 * @param array<string, mixed> $entry
 * @return string HTML — already escaped/built from trusted pieces, safe to echo directly.
 */
function eventadmin_activity_feed_entry_text(array $entry): string
{
    $type        = (string) ($entry['type'] ?? '');
    $user_id     = (int) ($entry['user_id'] ?? 0);
    $shift_id    = (int) ($entry['shift_id'] ?? 0);
    $to_shift_id = (int) ($entry['to_shift_id'] ?? 0);
    $actor       = (string) ($entry['actor'] ?? 'self');
    $actor_id    = (int) ($entry['actor_id'] ?? 0);

    $volunteer_link = eventadmin_activity_feed_volunteer_link($user_id);
    $shift_link      = eventadmin_activity_feed_shift_link($shift_id);

    $actor_user = $actor === 'admin' && $actor_id ? get_userdata($actor_id) : null;
    $actor_name = $actor_user
        ? ($actor_user->display_name ?: trim($actor_user->first_name . ' ' . $actor_user->last_name))
        : esc_html__('An admin', 'eventadmin-volunteer-management');

    switch ($type) {
        case 'assign':
            if ($actor === 'admin') {
                return sprintf(
                    /* translators: 1: admin name, 2: volunteer name, 3: shift title */
                    esc_html__('%1$s added %2$s to %3$s', 'eventadmin-volunteer-management'),
                    esc_html($actor_name),
                    $volunteer_link,
                    $shift_link
                );
            }
            return sprintf(
                /* translators: 1: volunteer name, 2: shift title */
                esc_html__('%1$s signed up for %2$s', 'eventadmin-volunteer-management'),
                $volunteer_link,
                $shift_link
            );

        case 'unassign':
            if ($actor === 'admin') {
                return sprintf(
                    /* translators: 1: admin name, 2: volunteer name, 3: shift title */
                    esc_html__('%1$s removed %2$s from %3$s', 'eventadmin-volunteer-management'),
                    esc_html($actor_name),
                    $volunteer_link,
                    $shift_link
                );
            }
            return sprintf(
                /* translators: 1: volunteer name, 2: shift title */
                esc_html__('%1$s cancelled %2$s', 'eventadmin-volunteer-management'),
                $volunteer_link,
                $shift_link
            );

        case 'move':
            return sprintf(
                /* translators: 1: admin name, 2: volunteer name, 3: origin shift title, 4: destination shift title */
                esc_html__('%1$s moved %2$s from %3$s to %4$s', 'eventadmin-volunteer-management'),
                esc_html($actor_name),
                $volunteer_link,
                $shift_link,
                $to_shift_id ? eventadmin_activity_feed_shift_link($to_shift_id) : esc_html__('another shift', 'eventadmin-volunteer-management')
            );

        default:
            return '';
    }
}

/**
 * Renders the "Recent activity" panel on the Overview dashboard — the most recent shift
 * assignments, cancellations and moves across every volunteer, from the site-wide log kept
 * by eventadmin_log_shift_activity() (includes/helpers.php). Distinct from each volunteer's
 * own "Notification history" (includes/admin/user-profile.php), which only records what was
 * actually emailed and therefore misses offline volunteers.
 *
 * @param int $limit
 * @return void
 */
function eventadmin_render_activity_feed(int $limit = 20): void
{
    $log = get_option('eventadmin_activity_log', []);
    if (!is_array($log)) {
        $log = [];
    }

    $allowed_html = [
        'button' => [
            'type'          => true,
            'class'         => true,
            'data-user-id'  => true,
            'data-shift-id' => true,
            'data-name'     => true,
            'data-title'    => true,
        ],
    ];

    echo '<div class="eventadmin-profile-section eventadmin-activity-feed-section">';
    echo '<h3>' . esc_html__('Recent activity', 'eventadmin-volunteer-management') . '</h3>';

    if (empty($log)) {
        echo '<p class="eventadmin-profile-empty">' . esc_html__('Nothing has happened yet.', 'eventadmin-volunteer-management') . '</p>';
        echo '</div>';
        return;
    }

    echo '<ul class="eventadmin-activity-feed">';
    foreach (array_slice($log, 0, $limit) as $entry) {
        $text = eventadmin_activity_feed_entry_text($entry);
        if ($text === '') {
            continue;
        }
        $date_ts = isset($entry['date']) ? strtotime((string) $entry['date']) : false;
        $when    = $date_ts
            ? sprintf(
                /* translators: %s: relative time, e.g. "2 hours" */
                esc_html__('%s ago', 'eventadmin-volunteer-management'),
                human_time_diff($date_ts, current_time('timestamp'))
            )
            : '';

        echo '<li><span class="eventadmin-activity-feed-text">' . wp_kses($text, $allowed_html) . '</span>'
            . ($when ? ' <span class="eventadmin-activity-feed-time">' . esc_html($when) . '</span>' : '')
            . '</li>';
    }
    echo '</ul>';
    echo '</div>';
}

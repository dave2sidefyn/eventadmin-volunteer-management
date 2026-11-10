<?php
/**
 * EventAdmin Volunteer Management - Date bar for the Manager / Table / Cards views
 * A single event of a few days gets one chip per day; long-running setups (weekly pantry
 * sessions, a season of matches…) get "◀ day ▶", "Today" and a month picker whose days are
 * marked by how well staffed they are — instead of a dropdown with one entry per day.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/** Up to this many days with shifts are shown as chips; more switch to the ◀ day ▶ picker. */
const EVENTADMIN_DATE_CHIPS_MAX = 7;

/** Shifts spread over more than this many days count as "long-running" (see below). */
const EVENTADMIN_LONG_RUNNING_DAYS = 7;

/**
 * Per-day staffing summary of every (non-trashed) shift, in one query:
 * ['Y-m-d' => ['shifts' => int, 'status' => 'under'|'open'|'full']]. A day is 'under' when
 * any of its shifts is below its minimum, 'open' when any still has free slots, else 'full'.
 *
 * @param string $selected_cat Department slug from the filter; limits to it and its
 *                             sub-departments, like the shift list itself does.
 * @return array<string, array{shifts:int, status:string}>
 */
function eventadmin_get_shift_day_stats(string $selected_cat = ''): array
{
    global $wpdb;

    $cat_join  = '';
    $cat_where = '';
    if ($selected_cat !== '') {
        $term = get_term_by('slug', $selected_cat, 'eventadmin_shift_category');
        if (!$term instanceof WP_Term) {
            return [];
        }
        $term_taxonomy_ids = [];
        foreach (eventadmin_expand_department_ids([(int) $term->term_id]) as $term_id) {
            $t = get_term($term_id, 'eventadmin_shift_category');
            if ($t instanceof WP_Term) {
                $term_taxonomy_ids[] = (int) $t->term_taxonomy_id;
            }
        }
        $cat_join  = "INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID";
        $cat_where = 'AND tr.term_taxonomy_id IN (' . implode(',', array_map('absint', $term_taxonomy_ids)) . ')';
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $cat_join/$cat_where are built from absint()ed IDs only
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT p.ID,
                DATE(s.meta_value) AS shift_date,
                CAST(COALESCE(mx.meta_value, '0') AS UNSIGNED) AS max_v,
                CAST(COALESCE(mn.meta_value, '0') AS UNSIGNED) AS min_v,
                (SELECT COUNT(*) FROM {$wpdb->postmeta} a WHERE a.post_id = p.ID AND a.meta_key LIKE %s) AS assigned
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = 'shift_start' AND s.meta_value != ''
         LEFT JOIN {$wpdb->postmeta} mx ON mx.post_id = p.ID AND mx.meta_key = 'max_volunteers'
         LEFT JOIN {$wpdb->postmeta} mn ON mn.post_id = p.ID AND mn.meta_key = 'min_volunteers'
         {$cat_join}
         WHERE p.post_type = %s AND p.post_status != 'trash' {$cat_where}",
        $wpdb->esc_like('assigned_user_') . '%',
        'eventadmin_shift'
    ));

    $rank  = ['full' => 0, 'open' => 1, 'under' => 2];
    $stats = [];
    foreach ((array) $rows as $row) {
        $date = (string) $row->shift_date;
        if ($date === '') {
            continue;
        }
        $assigned = (int) $row->assigned;
        if ((int) $row->min_v > 0 && $assigned < (int) $row->min_v) {
            $status = 'under';
        } elseif ($assigned < (int) $row->max_v) {
            $status = 'open';
        } else {
            $status = 'full';
        }
        if (!isset($stats[$date])) {
            $stats[$date] = ['shifts' => 0, 'status' => 'full'];
        }
        $stats[$date]['shifts']++;
        if ($rank[$status] > $rank[$stats[$date]['status']]) {
            $stats[$date]['status'] = $status;
        }
    }
    ksort($stats);
    return $stats;
}

/**
 * Whether the upcoming shifts are spread over more than EVENTADMIN_LONG_RUNNING_DAYS days —
 * i.e. an ongoing setup rather than a single event of a few days.
 *
 * @param array<string, mixed> $day_stats From eventadmin_get_shift_day_stats().
 * @return bool
 */
function eventadmin_shifts_are_long_running(array $day_stats): bool
{
    $upcoming = eventadmin_filter_dates_for_time($day_stats, 'future');
    if (count($upcoming) < 2) {
        return false;
    }
    $first = strtotime(reset($upcoming) . ' 00:00:00');
    $last  = strtotime(end($upcoming) . ' 00:00:00');
    return ($last - $first) / DAY_IN_SECONDS > EVENTADMIN_LONG_RUNNING_DAYS;
}

/**
 * The days with shifts that fit the "Show: Upcoming / Past / All" filter.
 *
 * @param array<string, mixed> $day_stats
 * @param string               $time_filter 'future'|'past'|'all'
 * @return string[] Y-m-d, ascending
 */
function eventadmin_filter_dates_for_time(array $day_stats, string $time_filter): array
{
    $today = current_time('Y-m-d');
    $dates = array_keys($day_stats);
    if ($time_filter === 'future') {
        $dates = array_filter($dates, fn($d) => $d >= $today);
    } elseif ($time_filter === 'past') {
        $dates = array_filter($dates, fn($d) => $d < $today);
    }
    return array_values($dates);
}

/**
 * The next day with shifts from today on (or the last one, if everything is in the past).
 *
 * @param array<string, mixed> $day_stats
 * @return string Y-m-d, or '' when there are no shifts at all
 */
function eventadmin_next_shift_date(array $day_stats): string
{
    $upcoming = eventadmin_filter_dates_for_time($day_stats, 'future');
    if (!empty($upcoming)) {
        return $upcoming[0];
    }
    $all = array_keys($day_stats);
    return empty($all) ? '' : (string) end($all);
}

/**
 * Renders the "when" control at the start of the toolbar — the one place that decides which
 * period is shown, so a range (Upcoming / Past / All) and a single day can never contradict
 * each other: picking a day replaces the range, picking a range clears the day.
 *
 * - Up to EVENTADMIN_DATE_CHIPS_MAX days in the current range (a single event): the range
 *   button plus one chip per day.
 * - More (long-running setups): ◀ day ▶, "Today", and the range button.
 * Either way the range button opens a popover with the three ranges and a month picker
 * whose days are dotted by staffing (assets/js/date-navigator.js).
 *
 * @param array<string, mixed> $filter_query_args Current filters (from eventadmin_overview_parse_filter_state()).
 * @param string               $view              Current view slug.
 * @param string               $selected_date     Y-m-d or '' for the whole range.
 * @param array<string, mixed> $day_stats         From eventadmin_get_shift_day_stats().
 * @param string               $time_filter       'future'|'past'|'all'
 * @return void
 */
function eventadmin_render_when_control(array $filter_query_args, string $view, string $selected_date, array $day_stats, string $time_filter): void
{
    $base_args = array_merge($filter_query_args, ['filter_view' => $view]);
    unset($base_args['filter_date'], $base_args['filter_time'], $base_args['paged']);
    $range_arg = $time_filter !== 'future' ? ['filter_time' => $time_filter] : [];
    $date_url  = static fn(string $date): string => add_query_arg(array_merge($base_args, $range_arg, ['filter_date' => $date]), admin_url('edit.php'));
    $range_url = static fn(string $range): string => add_query_arg(array_merge($base_args, ['filter_time' => $range, 'filter_date' => '']), admin_url('edit.php'));

    $ranges = [
        'future' => __('Upcoming', 'eventadmin-volunteer-management'),
        'past'   => __('Past', 'eventadmin-volunteer-management'),
        'all'    => __('All dates', 'eventadmin-volunteer-management'),
    ];
    $status_labels = [
        'under' => __('Below the minimum', 'eventadmin-volunteer-management'),
        'open'  => __('Open slots', 'eventadmin-volunteer-management'),
        'full'  => __('Fully staffed', 'eventadmin-volunteer-management'),
    ];
    $day_label = static fn(string $date, string $format): string => date_i18n($format, strtotime($date . ' 12:00:00'));

    $dates = eventadmin_filter_dates_for_time($day_stats, $time_filter);
    if ($selected_date !== '' && !in_array($selected_date, $dates, true) && isset($day_stats[$selected_date])) {
        $dates[] = $selected_date;
        sort($dates);
    }
    $chips_mode = count($dates) <= EVENTADMIN_DATE_CHIPS_MAX;

    echo '<div class="eventadmin-date-nav" role="group" aria-label="' . esc_attr__('Choose a day', 'eventadmin-volunteer-management') . '">';

    $nav_button = static function (string $date, string $symbol, string $label) use ($date_url): void {
        if ($date === '') {
            echo '<span class="button eventadmin-date-step" aria-disabled="true" title="' . esc_attr($label) . '">' . esc_html($symbol) . '</span>';
            return;
        }
        echo '<a class="button eventadmin-date-step" href="' . esc_url($date_url($date)) . '" title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '">' . esc_html($symbol) . '</a>';
    };

    if (!$chips_mode) {
        $all_dates = array_keys($day_stats);
        $index     = $selected_date !== '' ? array_search($selected_date, $all_dates, true) : false;
        $prev      = $index !== false && $index > 0 ? $all_dates[$index - 1] : '';
        $next      = $index !== false && $index < count($all_dates) - 1 ? $all_dates[$index + 1] : '';
        if ($selected_date === '') {
            $next = eventadmin_next_shift_date($day_stats);
        }
        $nav_button($prev, '◀', __('Previous day with shifts', 'eventadmin-volunteer-management'));
    }

    // The button always says what is shown right now: the chosen day, or the range.
    echo '<button type="button" class="button eventadmin-date-current" id="eventadmin-date-picker-toggle" aria-haspopup="dialog" aria-expanded="false">';
    if ($selected_date !== '' && isset($day_stats[$selected_date])) {
        echo '<span class="eventadmin-date-dot is-' . esc_attr($day_stats[$selected_date]['status']) . '" aria-hidden="true"></span>';
        echo esc_html($day_label($selected_date, $chips_mode ? 'D j M' : 'l, ' . get_option('date_format')));
    } else {
        echo esc_html($ranges[$time_filter] ?? $ranges['future']);
    }
    echo ' <span aria-hidden="true">&#9662;</span></button>';

    if ($chips_mode) {
        foreach ($dates as $date) {
            if ($date === $selected_date) {
                continue; // already named on the button
            }
            $status = $day_stats[$date]['status'];
            echo '<a class="eventadmin-date-chip" href="' . esc_url($date_url($date)) . '" title="' . esc_attr($status_labels[$status]) . '">'
                . '<span class="eventadmin-date-dot is-' . esc_attr($status) . '" aria-hidden="true"></span>'
                . esc_html($day_label($date, 'D j M')) . '</a>';
        }
    } else {
        $nav_button($next, '▶', __('Next day with shifts', 'eventadmin-volunteer-management'));
        $today_target = eventadmin_next_shift_date($day_stats);
        if ($today_target !== '' && $today_target !== $selected_date) {
            echo '<a class="button eventadmin-date-today" href="' . esc_url($date_url($today_target)) . '">' . esc_html__('Today', 'eventadmin-volunteer-management') . '</a>';
        }
    }

    // Popover: the three ranges on top, the month picker below.
    echo '<div class="eventadmin-date-picker" id="eventadmin-date-picker" role="dialog" aria-label="' . esc_attr__('Choose a day', 'eventadmin-volunteer-management') . '" hidden>';
    echo '<div class="eventadmin-date-ranges">';
    foreach ($ranges as $key => $label) {
        $active = $selected_date === '' && $time_filter === $key;
        echo '<a class="eventadmin-date-range' . ($active ? ' is-active' : '') . '" href="' . esc_url($range_url($key)) . '"' . ($active ? ' aria-current="true"' : '') . '>' . esc_html($label) . '</a>';
    }
    echo '</div>';
    echo '<div class="eventadmin-date-picker-cal"></div>';
    echo '</div>';

    $picker_days = [];
    foreach ($day_stats as $date => $info) {
        $picker_days[$date] = $info['status'];
    }
    $locale = str_replace('_', '-', preg_replace('/_(formal|informal)$/', '', get_user_locale()));
    wp_localize_script('eventadmin-date-navigator', 'EVENTADMIN_DATE_NAV', [
        'days'        => $picker_days,
        'selected'    => $selected_date,
        // Month the picker opens on: the chosen day, else the next day with shifts.
        'anchor'      => $selected_date !== '' ? $selected_date : (eventadmin_next_shift_date($day_stats) ?: current_time('Y-m-d')),
        'today'       => current_time('Y-m-d'),
        'url'         => esc_url_raw($date_url('__DATE__')),
        'startOfWeek' => (int) get_option('start_of_week', 1),
        'locale'      => $locale,
        'i18n'        => [
            'prev_month' => __('Previous month', 'eventadmin-volunteer-management'),
            'next_month' => __('Next month', 'eventadmin-volunteer-management'),
            'status'     => $status_labels,
        ],
    ]);

    echo '</div>';
}

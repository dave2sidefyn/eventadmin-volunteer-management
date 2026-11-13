<?php
/**
 * EventAdmin Volunteer Management - Calendar tab on the Manager page
 * A month (or week) grid for setups that run over many weeks, where the Day timeline and the
 * Table only ever show a slice. Each shift is a chip coloured by its department, with its
 * start time and fill ("3/5", red while below the minimum); clicking a chip opens the shared
 * shift modal, clicking a day number opens that day on the Day tab.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/** Shifts spanning more than this many days open the Manager page on the Calendar tab. */
const EVENTADMIN_CALENDAR_DEFAULT_DAYS = 14;

/** Chips shown per day in the month grid before collapsing the rest into "+N more". */
const EVENTADMIN_CALENDAR_CHIPS_PER_DAY = 4;

/**
 * Whether the upcoming shifts span long enough that a calendar beats a single day.
 *
 * @param array<string, mixed> $day_stats From eventadmin_get_shift_day_stats().
 * @return bool
 */
function eventadmin_calendar_is_default(array $day_stats): bool
{
    $upcoming = eventadmin_filter_dates_for_time($day_stats, 'future');
    if (count($upcoming) < 2) {
        return false;
    }
    $first = strtotime(reset($upcoming) . ' 00:00:00');
    $last  = strtotime(end($upcoming) . ' 00:00:00');
    return ($last - $first) / DAY_IN_SECONDS > EVENTADMIN_CALENDAR_DEFAULT_DAYS;
}

/**
 * Reads the calendar's own state: 'month' or 'week', and the anchor day it shows. Without an
 * explicit anchor it opens on today — or, when the current month/week has no shifts, on the
 * next day that has one, so a calendar opened in the off-season isn't simply empty.
 *
 * @param bool                 $filter_valid Whether the filter nonce checked out.
 * @param array<string, mixed> $day_stats
 * @return array{mode: string, anchor: string}
 */
function eventadmin_calendar_parse_state(bool $filter_valid, array $day_stats): array
{
    $raw_mode   = $filter_valid && isset($_GET['cal_mode']) ? sanitize_key(wp_unslash($_GET['cal_mode'])) : '';
    $raw_anchor = $filter_valid && isset($_GET['cal_anchor']) ? sanitize_text_field(wp_unslash($_GET['cal_anchor'])) : '';
    $mode       = $raw_mode === 'week' ? 'week' : 'month';
    $selected_date = $filter_valid && isset($_GET['filter_date']) ? sanitize_text_field(wp_unslash($_GET['filter_date'])) : '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date) && strtotime($selected_date . ' 12:00:00') !== false) {
        return ['mode' => $mode, 'anchor' => $selected_date];
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw_anchor) && strtotime($raw_anchor . ' 12:00:00') !== false) {
        return ['mode' => $mode, 'anchor' => $raw_anchor];
    }

    $today = current_time('Y-m-d');
    [$from, $to] = eventadmin_calendar_range($mode, $today);
    foreach (array_keys($day_stats) as $date) {
        if ($date >= $from && $date <= $to) {
            return ['mode' => $mode, 'anchor' => $today];
        }
    }
    $next = eventadmin_filter_dates_for_time($day_stats, 'future');
    return ['mode' => $mode, 'anchor' => $next[0] ?? $today];
}

/**
 * First and last day of the grid, padded to whole weeks starting on the site's
 * "Week starts on" day.
 *
 * @param string $mode   'month'|'week'
 * @param string $anchor Y-m-d
 * @return string[] [from, to] as Y-m-d
 */
function eventadmin_calendar_range(string $mode, string $anchor): array
{
    $start_of_week = (int) get_option('start_of_week', 1);
    $anchor_ts     = strtotime($anchor . ' 12:00:00');

    if ($mode === 'week') {
        $first = $anchor_ts;
        $last  = $anchor_ts;
    } else {
        $first = strtotime(gmdate('Y-m-01', $anchor_ts) . ' 12:00:00');
        $last  = strtotime(gmdate('Y-m-t', $anchor_ts) . ' 12:00:00');
    }

    $lead  = ((int) gmdate('w', $first) - $start_of_week + 7) % 7;
    $trail = ($start_of_week + 6 - (int) gmdate('w', $last)) % 7;

    return [
        gmdate('Y-m-d', $first - $lead * DAY_IN_SECONDS),
        gmdate('Y-m-d', $last + $trail * DAY_IN_SECONDS),
    ];
}

/**
 * The shifts overlapping two days, grouped by each occupied day and sorted by start time — with what
 * a chip needs: title, start, fill, minimum and department colour.
 *
 * @param string $from               Y-m-d
 * @param string $to                 Y-m-d
 * @param string $selected_cat       Department slug ('' = all); includes sub-departments.
 * @param int    $selected_volunteer Only shifts this volunteer is assigned to (0 = all).
 * @return array<string, array<int, array<string, mixed>>> Y-m-d => chips
 */
function eventadmin_get_calendar_shifts(string $from, string $to, string $selected_cat, int $selected_volunteer): array
{
    $meta_query = eventadmin_shift_overlap_meta_query($from, $to);
    if ($selected_volunteer > 0) {
        $meta_query[] = ['key' => 'assigned_user_' . $selected_volunteer, 'compare' => 'EXISTS'];
    }

    $args = [
        'post_type'      => 'eventadmin_shift',
        'posts_per_page' => -1,
        'meta_query'     => $meta_query,
        'orderby'        => 'sort_clause',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ];
    if ($selected_cat !== '') {
        $args['tax_query'] = [[
            'taxonomy' => 'eventadmin_shift_category',
            'field'    => 'slug',
            'terms'    => $selected_cat,
        ]];
    }

    $by_day = [];
    foreach ((new WP_Query($args))->posts as $shift) {
        $meta  = get_post_meta($shift->ID);
        $start = (string) ($meta['shift_start'][0] ?? '');
        if ($start === '') {
            continue;
        }
        $assigned = 0;
        foreach (array_keys($meta) as $key) {
            if (str_starts_with((string) $key, 'assigned_user_')) {
                $assigned++;
            }
        }
        $max = (int) ($meta['max_volunteers'][0] ?? 0);
        $min = (int) ($meta['min_volunteers'][0] ?? 0);

        $color = '';
        $dept  = '';
        $terms = get_the_terms($shift->ID, 'eventadmin_shift_category');
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if (eventadmin_is_shift_category_hidden($term->term_id)) {
                    continue;
                }
                $color = (string) get_term_meta($term->term_id, 'term_color', true);
                $dept  = $term->name;
                break;
            }
        }

        if ($min > 0 && $assigned < $min) {
            $status = 'under';
        } elseif ($assigned < $max) {
            $status = 'open';
        } else {
            $status = 'full';
        }

        $chip = [
            'id'       => $shift->ID,
            'title'    => get_the_title($shift),
            'time'     => date_i18n(get_option('time_format'), strtotime($start)),
            'assigned' => $assigned,
            'max'      => $max,
            'min'      => $min,
            'status'   => $status,
            'color'    => $color !== '' ? $color : '#8c8f94',
            'dept'     => $dept,
        ];
        $end = (string) ($meta['shift_end'][0] ?? '');
        foreach (eventadmin_shift_occupied_days($start, $end, $from, $to) as $date) {
            $daily_chip = $chip;
            // Do not repeat the original start time on continuation days.
            if ($date !== substr($start, 0, 10)) $daily_chip['time'] = '↳';
            $daily_chip['period'] = eventadmin_get_formatted_zeitraum($start, $end);
            $by_day[$date][] = $daily_chip;
        }
    }

    return $by_day;
}

/**
 * The calendar's controls in the toolbar's Date slot: ‹ period ›, "Today" and the
 * Month / Week switch — all links, so they keep every other filter.
 *
 * @param array<string, mixed> $filter_query_args
 * @param string               $mode
 * @param string               $anchor
 * @return void
 */
function eventadmin_render_calendar_nav(array $filter_query_args, string $mode, string $anchor): void
{
    $base = array_merge($filter_query_args, ['filter_view' => 'calendar']);
    unset($base['cal_mode'], $base['cal_anchor'], $base['filter_date'], $base['paged']);
    $url = static fn(string $m, string $a): string => add_query_arg(array_merge($base, ['cal_mode' => $m, 'cal_anchor' => $a]), admin_url('edit.php'));

    $anchor_ts = strtotime($anchor . ' 12:00:00');
    if ($mode === 'week') {
        $prev = gmdate('Y-m-d', $anchor_ts - 7 * DAY_IN_SECONDS);
        $next = gmdate('Y-m-d', $anchor_ts + 7 * DAY_IN_SECONDS);
        [$from, $to] = eventadmin_calendar_range('week', $anchor);
        $label = date_i18n('j M', strtotime($from . ' 12:00:00')) . ' – ' . date_i18n('j M Y', strtotime($to . ' 12:00:00'));
        $prev_label = __('Previous week', 'eventadmin-volunteer-management');
        $next_label = __('Next week', 'eventadmin-volunteer-management');
    } else {
        $first = strtotime(gmdate('Y-m-01', $anchor_ts) . ' 12:00:00');
        $prev  = gmdate('Y-m-01', strtotime('-1 month', $first));
        $next  = gmdate('Y-m-01', strtotime('+1 month', $first));
        $label = date_i18n('F Y', $first);
        $prev_label = __('Previous month', 'eventadmin-volunteer-management');
        $next_label = __('Next month', 'eventadmin-volunteer-management');
    }

    echo '<div class="eventadmin-date-nav eventadmin-cal-nav">';
    echo '<a class="button eventadmin-date-step" href="' . esc_url($url($mode, $prev)) . '" aria-label="' . esc_attr($prev_label) . '">◀</a>';
    echo '<span class="eventadmin-cal-period">' . esc_html($label) . '</span>';
    echo '<a class="button eventadmin-date-step" href="' . esc_url($url($mode, $next)) . '" aria-label="' . esc_attr($next_label) . '">▶</a>';
    echo '<a class="button" href="' . esc_url($url($mode, current_time('Y-m-d'))) . '">' . esc_html__('Today', 'eventadmin-volunteer-management') . '</a>';
    echo '<span class="eventadmin-segmented" role="group">';
    foreach (['month' => __('Month', 'eventadmin-volunteer-management'), 'week' => __('Week', 'eventadmin-volunteer-management')] as $m => $m_label) {
        echo '<a class="button' . ($m === $mode ? ' is-active' : '') . '" href="' . esc_url($url($m, $anchor)) . '"' . ($m === $mode ? ' aria-current="true"' : '') . '>' . esc_html($m_label) . '</a>';
    }
    echo '</span>';
    echo '</div>';
}

/**
 * Renders the grid.
 *
 * @param array<string, mixed> $filter_query_args
 * @param string               $mode
 * @param string               $anchor
 * @param string               $selected_cat
 * @param int                  $selected_volunteer
 * @return void
 */
function eventadmin_render_calendar_view(array $filter_query_args, string $mode, string $anchor, string $selected_cat, int $selected_volunteer, string $selected_state = ''): void
{
    [$from, $to] = eventadmin_calendar_range($mode, $anchor);
    $by_day      = eventadmin_get_calendar_shifts($from, $to, $selected_cat, $selected_volunteer);
    if ($selected_state !== '') {
        foreach ($by_day as $date => $chips) {
            $by_day[$date] = array_values(array_filter($chips, static fn($chip) => match ($selected_state) {
                'empty' => $chip['assigned'] === 0,
                'full' => $chip['assigned'] >= $chip['max'],
                'understaffed' => $chip['assigned'] < $chip['max'],
                'heavilyunderstaffed' => $chip['status'] === 'under',
                default => true,
            }));
            if (!$by_day[$date]) unset($by_day[$date]);
        }
    }
    $today       = current_time('Y-m-d');
    $month       = substr($anchor, 0, 7);

    $day_base = array_merge($filter_query_args, ['filter_view' => 'timeline']);
    unset($day_base['cal_mode'], $day_base['cal_anchor'], $day_base['filter_volunteer'], $day_base['paged']);
    $day_url = static fn(string $date): string => add_query_arg(array_merge($day_base, ['filter_date' => $date]), admin_url('edit.php'));

    echo '<div class="eventadmin-cal is-' . esc_attr($mode) . '">';

    if (empty($by_day)) {
        echo '<p class="eventadmin-cal-empty">' . esc_html($mode === 'week'
            ? __('No shifts this week.', 'eventadmin-volunteer-management')
            : __('No shifts this month.', 'eventadmin-volunteer-management')) . '</p>';
    }

    echo '<div class="eventadmin-cal-grid">';
    $start_of_week = (int) get_option('start_of_week', 1);
    for ($i = 0; $i < 7; $i++) {
        // 2023-01-01 was a Sunday (weekday 0).
        $wd_ts = strtotime('2023-01-0' . (1 + ($start_of_week + $i) % 7) . ' 12:00:00');
        echo '<div class="eventadmin-cal-weekday">' . esc_html(date_i18n('D', $wd_ts)) . '</div>';
    }

    for ($ts = strtotime($from . ' 12:00:00'); gmdate('Y-m-d', $ts) <= $to; $ts += DAY_IN_SECONDS) {
        $date   = gmdate('Y-m-d', $ts);
        $chips  = $by_day[$date] ?? [];
        // Keep chronological order within each status; urgent shifts come first.
        usort($chips, static fn($a, $b) => ($b['status'] === 'under') <=> ($a['status'] === 'under'));
        $class  = 'eventadmin-cal-day';
        $class .= $date === $today ? ' is-today' : '';
        $class .= $date < $today ? ' is-past' : '';
        $class .= $mode === 'month' && substr($date, 0, 7) !== $month ? ' is-outside' : '';
        $class .= empty($chips) ? ' is-empty' : '';

        echo '<div class="' . esc_attr($class) . '">';
        $num = '<span class="eventadmin-cal-daynum">' . esc_html(date_i18n('j', $ts)) . '</span>'
            . '<span class="eventadmin-cal-dayname">' . esc_html(date_i18n('l, j F', $ts)) . '</span>';
        if (!empty($chips)) {
            /* translators: %s: date */
            echo '<a class="eventadmin-cal-dayhead" href="' . esc_url($day_url($date)) . '" title="' . esc_attr(sprintf(__('Open %s on the Day tab', 'eventadmin-volunteer-management'), date_i18n(get_option('date_format'), $ts))) . '">' . $num . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
        } else {
            echo '<span class="eventadmin-cal-dayhead">' . $num . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
        }

        if ($chips) {
            $under = count(array_filter($chips, static fn($chip) => $chip['status'] === 'under'));
            $open = array_sum(array_map(static fn($chip) => max(0, $chip['max'] - $chip['assigned']), $chips));
            echo '<p class="eventadmin-cal-summary">' . esc_html__('Below the minimum', 'eventadmin-volunteer-management') . ': ' . (int) $under . ' · ' . esc_html__('Open slots', 'eventadmin-volunteer-management') . ': ' . (int) $open . '</p>';
        }
        $limit = $mode === 'month' ? EVENTADMIN_CALENDAR_CHIPS_PER_DAY : PHP_INT_MAX;
        foreach ($chips as $index => $chip) {
            if ($index === $limit) {
                /* translators: %d: number of further shifts that day */
                echo '<details class="eventadmin-cal-extra"><summary class="eventadmin-cal-more">' . esc_html(sprintf(__('+%d more', 'eventadmin-volunteer-management'), count($chips) - $limit)) . '</summary>';
            }
            $fill = $chip['max'] > 0 ? $chip['assigned'] . '/' . $chip['max'] : (string) $chip['assigned'];
            /* translators: 1: shift title, 2: department, 3: assigned volunteers, 4: maximum volunteers */
            $title = sprintf(__('%1$s (%2$s) — %3$d of %4$d volunteers', 'eventadmin-volunteer-management'), $chip['title'], $chip['dept'] ?: '—', $chip['assigned'], $chip['max']);
            $title .= ' · ' . $chip['period'];
            if ($chip['status'] === 'under') {
                /* translators: %d: minimum number of volunteers */
                $title .= ' · ' . sprintf(__('minimum %d', 'eventadmin-volunteer-management'), $chip['min']);
            }
            echo '<button type="button" class="eventadmin-cal-chip eventadmin-view-shift-details is-' . esc_attr($chip['status']) . '"'
                . ' style="--dept:' . esc_attr($chip['color']) . '"'
                . ' data-shift-id="' . esc_attr($chip['id']) . '" data-title="' . esc_attr($chip['title']) . '" title="' . esc_attr($title) . '">'
                . '<span class="eventadmin-cal-chip-time">' . esc_html($chip['time']) . '</span>'
                . '<span class="eventadmin-cal-chip-title">' . esc_html($chip['title']) . '</span>'
                . '<span class="eventadmin-cal-chip-fill">' . esc_html($fill) . '</span>'
                . '</button>';
        }
        if (count($chips) > $limit) echo '</details>';
        echo '</div>';
    }
    echo '</div>';

    echo '<p class="eventadmin-cal-legend">'
        . '<span><span class="eventadmin-date-dot is-under" aria-hidden="true"></span>' . esc_html__('Below the minimum', 'eventadmin-volunteer-management') . '</span>'
        . '<span><span class="eventadmin-date-dot is-open" aria-hidden="true"></span>' . esc_html__('Open slots', 'eventadmin-volunteer-management') . '</span>'
        . '<span><span class="eventadmin-date-dot is-full" aria-hidden="true"></span>' . esc_html__('Fully staffed', 'eventadmin-volunteer-management') . '</span>'
        . '<span class="eventadmin-cal-legend-hint">' . esc_html__('Click a shift to edit it, or a day to open it on the Day tab.', 'eventadmin-volunteer-management') . '</span>'
        . '</p>';

    echo '</div>';
}

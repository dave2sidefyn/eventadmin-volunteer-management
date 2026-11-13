<?php
/**
 * EventAdmin Volunteer Management - Shift Data & Caching
 * The shared shift-querying layer (with transient caching + cache-invalidation hooks) used
 * by every Overview/Manager view, plus the "copy shifts to another day" data operation.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Query shifts active on any part of an inclusive range of site-local calendar days.
 * End times are exclusive: a shift ending at midnight does not occupy the next day.
 * Keep start-only shifts on their start date for compatibility with existing data.
 */
function eventadmin_shift_overlap_meta_query(string $from, string $to): array
{
    $until = (new DateTimeImmutable($to . 'T00:00', new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d\TH:i');
    return [
        'relation' => 'AND',
        'sort_clause' => ['key' => 'shift_start', 'value' => $until, 'compare' => '<', 'type' => 'DATETIME'],
        [
            'relation' => 'OR',
            ['key' => 'shift_start', 'value' => $from . 'T00:00', 'compare' => '>=', 'type' => 'DATETIME'],
            ['key' => 'shift_end', 'value' => $from . 'T00:00', 'compare' => '>', 'type' => 'DATETIME'],
        ],
    ];
}

/** Calendar dates occupied by a shift, clipped to the visible range. */
function eventadmin_shift_occupied_days(string $start, string $end, string $from, string $to): array
{
    try {
        $timezone = new DateTimeZone('UTC'); // Stored times are site-local wall clocks.
        $first = new DateTimeImmutable($start, $timezone);
        $last = $end !== '' ? new DateTimeImmutable($end, $timezone) : $first;
    } catch (Exception $exception) {
        return [];
    }
    if ($start === '') return [];
    $last = $last > $first ? $last->modify('-1 second') : $first;
    $day = max($from, $first->format('Y-m-d'));
    $through = min($to, $last->format('Y-m-d'));
    $days = [];
    for ($date = new DateTimeImmutable($day, $timezone); $date->format('Y-m-d') <= $through; $date = $date->modify('+1 day')) {
        $days[] = $date->format('Y-m-d');
    }
    return $days;
}

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

    // Build the meta_query. 'sort_clause' is always present (regardless of which
    // filter below is active, if any) so 'orderby' can always sort by start time.
    $meta_query = ['relation' => 'AND'];
    $meta_query['sort_clause'] = ['key' => 'shift_start', 'compare' => 'EXISTS'];

    if ($selected_date) {
        $meta_query = eventadmin_shift_overlap_meta_query($selected_date, $selected_date);
    } elseif ($time_filter !== 'all') {
        // "Upcoming" means the shift hasn't ended yet — this intentionally keeps
        // shifts already in progress (started before now, ending after now) visible
        // instead of dropping them the moment their start time passes. "Past" is the
        // exact complement: shifts that have fully ended.
        $meta_query['date_clause'] = [
            'key'     => 'shift_end',
            'value'   => $now,
            'compare' => $time_filter === 'future' ? '>=' : '<',
            'type'    => 'DATETIME',
        ];
    }

    if ($selected_volunteer > 0) {
        $meta_query[] = ['key' => 'assigned_user_' . $selected_volunteer, 'compare' => 'EXISTS'];
    }

    $args = [
        'post_type'      => 'eventadmin_shift',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'meta_query'     => $meta_query,
        'orderby'        => $sort_by === 'date' ? 'sort_clause' : 'title',
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
 * Deletes every cached eventadmin_get_shifts() result — its transient keys are hashed
 * from the query args, so an individual key can't be targeted; clearing all of them is
 * the only option, and cheap enough at this scale (a handful of distinct filter
 * combinations at most).
 */
function eventadmin_flush_shifts_cache(): void
{
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_eventadmin\_shifts\_%' OR option_name LIKE '\_transient\_timeout\_eventadmin\_shifts\_%'"
    );
}

/**
 * Every shift create/edit/delete already fires save_post_eventadmin_shift or the
 * trash/delete hooks below, which is enough to catch title/content/status changes. But
 * a shift's displayed time, capacity and roster live entirely in post meta — set via
 * plain update_post_meta()/add_post_meta()/delete_post_meta() calls in several places
 * (AJAX handlers here, the public sign-up flow, quick-edit, imports) that don't touch
 * the post row itself and so don't fire save_post. Hooking the meta actions directly,
 * scoped to just the keys eventadmin_get_shifts() actually filters/sorts on, means no
 * mutation path — current or future — can forget to invalidate the cache.
 */
function eventadmin_maybe_flush_shifts_cache_for_meta($meta_id, $post_id, $meta_key): void
{
    $is_relevant_key = in_array($meta_key, ['shift_start', 'shift_end', 'min_volunteers', 'max_volunteers'], true)
        || str_starts_with($meta_key, 'assigned_user_');
    if (!$is_relevant_key) {
        return;
    }
    if (get_post_type($post_id) === 'eventadmin_shift') {
        eventadmin_flush_shifts_cache();
    }
}
add_action('added_post_meta', 'eventadmin_maybe_flush_shifts_cache_for_meta', 10, 3);
add_action('updated_post_meta', 'eventadmin_maybe_flush_shifts_cache_for_meta', 10, 3);
add_action('deleted_post_meta', 'eventadmin_maybe_flush_shifts_cache_for_meta', 10, 3);

function eventadmin_flush_shifts_cache_for_post_id(int $post_id): void
{
    if (get_post_type($post_id) === 'eventadmin_shift') {
        eventadmin_flush_shifts_cache();
    }
}
add_action('save_post_eventadmin_shift', 'eventadmin_flush_shifts_cache');
add_action('trashed_post', 'eventadmin_flush_shifts_cache_for_post_id');
add_action('untrashed_post', 'eventadmin_flush_shifts_cache_for_post_id');
add_action('deleted_post', 'eventadmin_flush_shifts_cache_for_post_id');

/** Upper limit for "Copy shifts → Repeat" (dates per series), so a mistyped year can't create thousands of shifts. */
const EVENTADMIN_MAX_REPEAT_WEEKS = 52;

/**
 * All shifts (any status) that start on the given day.
 *
 * @param string $date Y-m-d
 * @return WP_Post[]
 */
function eventadmin_get_shifts_starting_on_day(string $date): array
{
    return get_posts([
        'post_type'   => 'eventadmin_shift',
        'numberposts' => -1,
        'post_status' => 'any',
        'meta_query'  => [[
            'key'     => 'shift_start',
            'value'   => [$date . 'T00:00', $date . 'T23:59'],
            'compare' => 'BETWEEN',
            'type'    => 'DATETIME',
        ]],
    ]);
}

/**
 * The dates a repeating copy lands on: $first_date, then every $interval weeks or months,
 * ending on $until_date (inclusive) or after $count dates — whichever is given — and never
 * more than EVENTADMIN_MAX_REPEAT_WEEKS dates. Monthly repeats keep the day of the month
 * and skip months that don't have it (e.g. the 31st), like most calendar apps.
 *
 * @param string $first_date Y-m-d
 * @param string $unit       'week' or 'month'
 * @param int    $interval   Every N weeks/months (min 1)
 * @param string $until_date Y-m-d, or '' to end by $count
 * @param int    $count      Number of dates, or 0 to end by $until_date
 * @return string[] Y-m-d dates
 */
function eventadmin_repeat_dates(string $first_date, string $unit, int $interval, string $until_date = '', int $count = 0): array
{
    $first = DateTimeImmutable::createFromFormat('!Y-m-d', $first_date);
    $until = $until_date !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $until_date) : null;
    if (!$first || ($until_date !== '' && !$until) || (!$until && $count < 1)) {
        return [];
    }
    $interval = max(1, $interval);
    $limit    = $count > 0 ? min($count, EVENTADMIN_MAX_REPEAT_WEEKS) : EVENTADMIN_MAX_REPEAT_WEEKS;
    $dates    = [];
    // Step from the first date each time (not from the previous one), so a skipped short
    // month doesn't shift every later date.
    for ($step = 0; count($dates) < $limit && $step < EVENTADMIN_MAX_REPEAT_WEEKS * 12; $step++) {
        if ($unit === 'month') {
            $year  = (int) $first->format('Y');
            $month = (int) $first->format('n') - 1 + $step * $interval;
            $year += intdiv($month, 12);
            $day   = (int) $first->format('j');
            if (!checkdate($month % 12 + 1, $day, $year)) {
                continue;
            }
            $date = $first->setDate($year, $month % 12 + 1, $day);
        } else {
            $date = $first->modify('+' . ($step * $interval * 7) . ' days');
        }
        if ($until && $date > $until) {
            break;
        }
        $dates[] = $date->format('Y-m-d');
    }
    return $dates;
}

/** Weekly series ending on a date — kept for callers of the original weekly-only repeat. */
function eventadmin_weekly_repeat_dates(string $first_date, string $until_date): array
{
    return eventadmin_repeat_dates($first_date, 'week', 1, $until_date);
}

/**
 * Copies every shift starting on $source_date to $target_date, preserving each shift's
 * time-of-day and duration (including shifts that run past midnight). Used for
 * "this new day should look like that past/other day" setups after recurring events.
 *
 * @param string $source_date Y-m-d
 * @param string $target_date Y-m-d
 * @param bool $copy_volunteers Whether to also copy the volunteers currently assigned
 * @param bool $skip_existing   Skip a shift when the target day already has one with the
 *                              same title and start time — used by "repeat weekly" so
 *                              running the same repeat twice never creates duplicates.
 * @param int|null $skipped     Set to the number of shifts skipped as already existing.
 * @return int Number of shifts copied
 */
function eventadmin_copy_shifts_to_day(string $source_date, string $target_date, bool $copy_volunteers, bool $skip_existing = false, ?int &$skipped = null): int
{
    $skipped = 0;
    $shifts  = eventadmin_get_shifts_starting_on_day($source_date);

    $existing_keys = [];
    if ($skip_existing) {
        foreach (eventadmin_get_shifts_starting_on_day($target_date) as $existing) {
            $existing_keys[$existing->post_title . '|' . eventadmin_wallclock_to_ts((string) get_post_meta($existing->ID, 'shift_start', true))] = true;
        }
    }

    $copied = 0;

    foreach ($shifts as $shift) {
        $start = (string) get_post_meta($shift->ID, 'shift_start', true);
        $end   = (string) get_post_meta($shift->ID, 'shift_end', true);
        if (!$start || !$end) {
            continue;
        }

        try {
            $start_dt = new DateTime($start);
            $end_dt   = new DateTime($end);
        } catch (Exception $e) {
            continue;
        }

        // Preserve the shift's duration (not just its time-of-day) so shifts that run
        // past midnight land correctly on the day after the new start date too.
        $duration_seconds = $end_dt->getTimestamp() - $start_dt->getTimestamp();
        $new_start_dt      = new DateTime($target_date . ' ' . $start_dt->format('H:i:s'));
        $new_end_dt        = (clone $new_start_dt)->modify('+' . $duration_seconds . ' seconds');

        if ($skip_existing && isset($existing_keys[$shift->post_title . '|' . eventadmin_wallclock_to_ts($new_start_dt->format('Y-m-d H:i:s'))])) {
            $skipped++;
            continue;
        }

        $new_id = wp_insert_post([
            'post_type'    => 'eventadmin_shift',
            'post_status'  => 'publish',
            'post_title'   => $shift->post_title,
            'post_content' => $shift->post_content,
        ]);

        if (!$new_id || is_wp_error($new_id)) {
            continue;
        }

        update_post_meta($new_id, 'shift_start', $new_start_dt->format('Y-m-d H:i:s'));
        update_post_meta($new_id, 'shift_end', $new_end_dt->format('Y-m-d H:i:s'));

        foreach (['min_volunteers', 'max_volunteers', 'shift_organizer_user_id', 'shift_organizer_name', 'shift_organizer_email'] as $field) {
            $value = get_post_meta($shift->ID, $field, true);
            if ($value !== '') {
                update_post_meta($new_id, $field, $value);
            }
        }

        $terms = wp_get_post_terms($shift->ID, 'eventadmin_shift_category', ['fields' => 'ids']);
        if (!empty($terms) && !is_wp_error($terms)) {
            wp_set_object_terms($new_id, $terms, 'eventadmin_shift_category');
        }

        if ($copy_volunteers) {
            foreach (get_post_meta($shift->ID) as $key => $val) {
                if (str_starts_with($key, 'assigned_user_')) {
                    add_post_meta($new_id, $key, (int) $val[0]);
                }
            }
        }

        $copied++;
    }

    return $copied;
}

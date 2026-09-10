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
        $meta_query['date_clause'] = [
            'key'     => 'shift_start',
            'value'   => [$selected_date . 'T00:00', $selected_date . 'T23:59'],
            'compare' => 'BETWEEN',
            'type'    => 'DATETIME',
        ];
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

/**
 * Copies every shift starting on $source_date to $target_date, preserving each shift's
 * time-of-day and duration (including shifts that run past midnight). Used for
 * "this new day should look like that past/other day" setups after recurring events.
 *
 * @param string $source_date Y-m-d
 * @param string $target_date Y-m-d
 * @param bool $copy_volunteers Whether to also copy the volunteers currently assigned
 * @return int Number of shifts copied
 */
function eventadmin_copy_shifts_to_day(string $source_date, string $target_date, bool $copy_volunteers): int
{
    $shifts = get_posts([
        'post_type'   => 'eventadmin_shift',
        'numberposts' => -1,
        'post_status' => 'any',
        'meta_query'  => [[
            'key'     => 'shift_start',
            'value'   => [$source_date . 'T00:00', $source_date . 'T23:59'],
            'compare' => 'BETWEEN',
            'type'    => 'DATETIME',
        ]],
    ]);

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

<?php
/**
 * EventAdmin Volunteer Management - Shift selector
 * Allows volunteers to sign up for or cancel shifts.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Sorts shifts by start time.
 *
 * @param $a , Shift A
 * @param $b , Shift B
 * @return int
 */
function eventadmin_sort_shifts_by_start($a, $b): int
{
    $startA = strtotime(get_post_meta($a->ID, 'shift_start', true));
    $startB = strtotime(get_post_meta($b->ID, 'shift_start', true));
    return $startA <=> $startB;
}

/**
 * Formats a "still open" slot count, e.g. "3/7 slots open".
 *
 * @param int $open Number of open (unfilled) slots
 * @param int $total Total number of slots
 * @return string
 */
function eventadmin_format_slots_open(int $open, int $total): string
{
    // A shift can end up over-assigned (e.g. max_volunteers lowered after volunteers already
    // signed up), which would otherwise show a confusing negative count.
    $open = max(0, $open);

    /* translators: 1: number of open volunteer slots remaining, 2: total number of volunteer slots offered */
    return sprintf(__('%1$d/%2$d slots open', 'eventadmin-volunteer-management'), $open, $total);
}

/**
 * Flattens a list of (possibly nested) category terms into parent-first,
 * depth-first order, setting an `eventadmin_depth` property on each term
 * so callers can indent child departments under their parent.
 *
 * @param WP_Term[] $categories
 * @param int $parent
 * @param int $depth
 * @return WP_Term[]
 */
function eventadmin_order_categories_hierarchically(array $categories, int $parent = 0, int $depth = 0): array
{
    // A valid tree can't be deeper than its number of nodes; bail out instead of recursing
    // forever if term data is ever corrupted into a parent cycle.
    if ($depth > count($categories)) {
        return [];
    }

    // A parent may be missing from $categories (e.g. hidden). Treat such orphans as top-level
    // instead of silently dropping them.
    $existing_ids = wp_list_pluck($categories, 'term_id');

    $ordered = [];
    foreach ($categories as $cat) {
        $effective_parent = in_array((int) $cat->parent, $existing_ids, true) ? (int) $cat->parent : 0;
        if ($effective_parent === $parent) {
            $cat->eventadmin_depth = $depth;
            $ordered[] = $cat;
            $ordered = array_merge($ordered, eventadmin_order_categories_hierarchically($categories, $cat->term_id, $depth + 1));
        }
    }
    return $ordered;
}

/**
 * A shift is hidden from volunteers when every department it's assigned to is hidden.
 * A shift with no department, or with at least one visible department, is never hidden.
 *
 * @param int $shift_id
 * @return bool
 */
function eventadmin_shift_is_hidden_from_volunteers(int $shift_id): bool
{
    $term_ids = wp_get_post_terms($shift_id, 'eventadmin_shift_category', ['fields' => 'ids']);
    if (empty($term_ids)) {
        return false;
    }
    foreach ($term_ids as $term_id) {
        if (!eventadmin_is_shift_category_hidden($term_id)) {
            return false;
        }
    }
    return true;
}

function eventadmin_shiftselector_shortcode(): bool|string
{
    if (!is_user_logged_in()) return esc_html__('Please log in first.', 'eventadmin-volunteer-management');

    $current_user_id = get_current_user_id();
    $shifts = get_posts(['post_type' => 'eventadmin_shift', 'numberposts' => -1]);

    $my_shifts = [];
    $available_shifts = [];
    $full_shifts = [];
    $shift_slots = [];

    foreach ($shifts as $shift) {
        $is_assigned = get_post_meta($shift->ID, 'assigned_user_' . $current_user_id, true);
        $max = (int)get_post_meta($shift->ID, 'max_volunteers', true);
        $current = eventadmin_count_assignments($shift->ID);
        $shift_slots[$shift->ID] = ['max' => $max, 'current' => $current];

        if ($is_assigned) {
            $my_shifts[] = $shift;
        } elseif (eventadmin_shift_is_hidden_from_volunteers($shift->ID)) {
            continue; // not signed up, and every department it's in is hidden — don't offer it
        } elseif ($current < $max) {
            $available_shifts[] = $shift;
        } else {
            $full_shifts[] = $shift;
        }
    }

    usort($my_shifts, 'eventadmin_sort_shifts_by_start');
    usort($available_shifts, 'eventadmin_sort_shifts_by_start');
    usort($full_shifts, 'eventadmin_sort_shifts_by_start');

    ob_start();

    echo '<h2>' . esc_html__('My shifts', 'eventadmin-volunteer-management') . '</h2>';
    echo '<div id="section-my-shifts" class="shift-section">';
    if (empty($my_shifts)) {
        echo '<p class="no-shifts">' . esc_html__('You have not signed up for any shifts yet.', 'eventadmin-volunteer-management') . '</p>';
    } else {
        foreach ($my_shifts as $shift) {
            eventadmin_render_shift($shift, $current_user_id);
        }
    }
    echo '</div>';

    echo '<div class="shift-section-header">';
    echo '<h2>' . esc_html__('Available shifts', 'eventadmin-volunteer-management') . '</h2>';
    if (!empty($available_shifts)) {
        $categories     = get_terms(['taxonomy' => 'eventadmin_shift_category', 'hide_empty' => false]);
        $categories     = array_filter($categories, fn($cat) => !eventadmin_is_shift_category_hidden($cat->term_id));
        $categories     = eventadmin_order_categories_hierarchically($categories);
        $enabled_filters = (array) get_option('eventadmin_enabled_filters', ['text_search', 'date_filter', 'category_filter']);

        echo '<div class="shift-filter">';

        if (!empty($categories) && in_array('category_filter', $enabled_filters, true)) {
            $shift_term_ids = [];
            foreach (array_merge($available_shifts, $full_shifts) as $shift) {
                $shift_term_ids[$shift->ID] = wp_get_post_terms($shift->ID, 'eventadmin_shift_category', ['fields' => 'ids']);
            }

            echo '<select id="shift-category-filter">';

            $all_open = 0;
            $all_total = 0;
            foreach (array_merge($available_shifts, $full_shifts) as $shift) {
                $all_open += $shift_slots[$shift->ID]['max'] - $shift_slots[$shift->ID]['current'];
                $all_total += $shift_slots[$shift->ID]['max'];
            }
            echo '<option value="all">' . esc_html__('All departments', 'eventadmin-volunteer-management') .
                ' (' . esc_html(eventadmin_format_slots_open($all_open, $all_total)) . ')</option>';

            foreach ($categories as $cat) {
                // Don't attribute a hidden child's shifts to its (visible) parent's count.
                $visible_descendant_ids = array_filter(
                    (array) get_term_children($cat->term_id, 'eventadmin_shift_category'),
                    fn($id) => !eventadmin_is_shift_category_hidden($id)
                );
                $cat_ids = array_merge([$cat->term_id], $visible_descendant_ids);

                $open = 0;
                $total = 0;
                foreach (array_merge($available_shifts, $full_shifts) as $shift) {
                    if (array_intersect($cat_ids, $shift_term_ids[$shift->ID] ?? [])) {
                        $open += $shift_slots[$shift->ID]['max'] - $shift_slots[$shift->ID]['current'];
                        $total += $shift_slots[$shift->ID]['max'];
                    }
                }

                $indent = str_repeat('— ', $cat->eventadmin_depth);
                echo '<option value="' . esc_attr($cat->slug) . '">' . esc_html($indent . $cat->name) .
                    ' (' . esc_html(eventadmin_format_slots_open($open, $total)) . ')</option>';
            }
            echo '</select>';
        }

        if (in_array('text_search', $enabled_filters, true)) {
            echo '<input type="search" id="shift-text-filter" placeholder="' . esc_attr__('Search shifts…', 'eventadmin-volunteer-management') . '">';
        }

        if (in_array('date_filter', $enabled_filters, true)) {
            echo '<label class="shift-filter-date-label">' . esc_html__('Date:', 'eventadmin-volunteer-management') . ' ';
            echo '<input type="date" id="shift-date-filter">';
            echo '</label>';
        }

        echo '</div>';
    }
    echo '</div>';
    echo '<div id="section-open-shifts" class="shift-section">';
    if (empty($available_shifts)) {
        echo '<p class="no-shifts">' . esc_html__('Currently no open shifts available.', 'eventadmin-volunteer-management') . '</p>';
    } else {

        foreach ($available_shifts as $shift) {
            eventadmin_render_shift($shift, $current_user_id);
        }
    }
    echo '</div>';

    if (get_option('eventadmin_show_full_shifts', 0)) {
        echo '<h2>' . esc_html__('Full shifts', 'eventadmin-volunteer-management') . '</h2>';
        echo '<div id="section-full-shifts" class="shift-section">';
        if (empty($full_shifts)) {
            echo '<p class="no-shifts">' . esc_html__('No full shifts at the moment.', 'eventadmin-volunteer-management') . '</p>';
        } else {
            foreach ($full_shifts as $shift) {
                eventadmin_render_shift($shift, $current_user_id, true);
            }
        }
        echo '</div>';
    }

    return ob_get_clean();
}

add_shortcode('eventadmin_shiftselector', 'eventadmin_shiftselector_shortcode');

function eventadmin_render_shift($shift, $user_id, $is_full = false): void
{
    $start = get_post_meta($shift->ID, 'shift_start', true);
    $end = get_post_meta($shift->ID, 'shift_end', true);
    $max = (int)get_post_meta($shift->ID, 'max_volunteers', true);
    $current = eventadmin_count_assignments($shift->ID);
    $is_assigned = get_post_meta($shift->ID, 'assigned_user_' . $user_id, true);
    $terms = wp_get_post_terms($shift->ID, 'eventadmin_shift_category');
    $cat_classes = [];
    foreach ($terms as $term) {
        if (!eventadmin_is_shift_category_hidden($term->term_id)) {
            $cat_classes[] = $term->slug;
        }
        foreach (get_ancestors($term->term_id, 'eventadmin_shift_category') as $ancestor_id) {
            if (eventadmin_is_shift_category_hidden($ancestor_id)) {
                continue;
            }
            $ancestor = get_term($ancestor_id, 'eventadmin_shift_category');
            if ($ancestor && !is_wp_error($ancestor)) {
                $cat_classes[] = $ancestor->slug;
            }
        }
    }
    $cat_classes = array_unique($cat_classes);
    $data_attr = implode(' ', $cat_classes);
    $names = eventadmin_get_user_display_names($shift->ID);

    $hours_left = (strtotime($start) - time()) / 3600;
    echo '<div class="shift-box" data-category="' . esc_attr($data_attr) . '" data-shift-id="' . esc_attr($shift->ID) . '" data-hours-left="' . esc_attr(floor($hours_left)) . '">';

    echo '<div class="shift-card-header">';
    echo '<div class="shift-datetime" data-start="' . esc_attr($start) . '">' . esc_html(eventadmin_get_formatted_zeitraum($start, $end)) . '</div>';

    $visible_terms = array_filter($terms, fn($term) => !eventadmin_is_shift_category_hidden($term->term_id));

    if (!empty($visible_terms)) {
        echo '<div class="shift-categories">';
        foreach ($visible_terms as $term) {
            $color = get_term_meta($term->term_id, 'term_color', true);

            // If not set → generate, save and use one
            if (!$color) {
                $color = sprintf("#%06x", wp_rand(0x444444, 0xAAAAAA));
                update_term_meta($term->term_id, 'term_color', $color);
            }

            echo '<span class="shift-category-label" style="background:' . esc_attr($color) . ';">' .
                esc_html($term->name) . '</span>';
        }
        echo '</div>';
    }
    echo '</div>';

    echo '<div class="shift-header"><strong>' . esc_html($shift->post_title) . '</strong></div>';
    echo '<div class="shift-meta">';
    echo '<div class="shift-people">';
    echo '<span class="shift-open">' . esc_html(eventadmin_format_slots_open($max - $current, $max)) . '</span><br>';
    echo '<span class="shift-names">' . esc_html(!empty($names) ? implode(', ', $names) : '') . '</span>';
    echo '</div>';
    echo '<div class="shift-description">' . wp_kses_post($shift->post_content) . '</div>';
    echo '</div>';
    echo '<form class="eventadmin-assign-form">';
    echo '<input type="hidden" name="shift_id" value="' . esc_attr($shift->ID) . '">';
    if ($is_assigned) {
        echo '<input type="hidden" name="action" value="eventadmin_unassign_ajax">';
        echo '<input type="submit" class="button-red" value="' . esc_html__('Unassign', 'eventadmin-volunteer-management') . '">';
    } elseif (!$is_full) {
        echo '<input type="hidden" name="action" value="eventadmin_assign_ajax">';
        echo '<input type="submit" class="button" value="' . esc_html__('Assign', 'eventadmin-volunteer-management') . '">';
    } else {
        echo '<button disabled>' . esc_html__('Full', 'eventadmin-volunteer-management') . '</button>';
    }
    echo '</form>';
    echo '</div>';
}

function eventadmin_assign_ajax(): void
{
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => esc_html__('Please log in first.', 'eventadmin-volunteer-management')]);
    }

    if (
        !isset($_POST['_ajax_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])), 'eventadmin_assign_shift')
    ) {
        wp_send_json_error(['message' => esc_html__('Security check failed.', 'eventadmin-volunteer-management')]);
    }

    $user_id = get_current_user_id();
    $shift_id = isset($_POST['shift_id']) ? absint($_POST['shift_id']) : 0;
    if (!$shift_id) {
        wp_send_json_error(['message' => esc_html__('Invalid shift ID.', 'eventadmin-volunteer-management')]);
    }
    $error = eventadmin_check_match_schicht_user($user_id, $shift_id);

    if ($error !== 'ok') {
        wp_send_json_error(['message' => $error]);
    }

    // Save assignment
    add_post_meta($shift_id, 'assigned_user_' . $user_id, $user_id);
    eventadmin_send_shift_un_assignment_notification($user_id, $shift_id, 'assign');

    $names = eventadmin_get_user_display_names($shift_id);
    $count = eventadmin_count_assignments($shift_id);
    $max = (int) get_post_meta($shift_id, 'max_volunteers', true);

    wp_send_json_success([
        'message' => esc_html__('You have been successfully signed up.', 'eventadmin-volunteer-management'),
        'count' => $count,
        'open' => eventadmin_format_slots_open($max - $count, $max),
        'names' => implode(', ', $names)
    ]);
}

add_action('wp_ajax_eventadmin_assign_ajax', 'eventadmin_assign_ajax');

/**
 * Checks if the shift and user are compatible.
 *
 * @return void 'ok' on success, otherwise error message.
 * @throws Exception
 */
function eventadmin_unassign_ajax(): void
{
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => esc_html__('Please log in first.', 'eventadmin-volunteer-management')]);
    }

    if (
        !isset($_POST['_ajax_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])), 'eventadmin_assign_shift')
    ) {
        wp_send_json_error(['message' => esc_html__('Security check failed.', 'eventadmin-volunteer-management')]);
    }

    $user_id = get_current_user_id();
    $shift_id = isset($_POST['shift_id']) ? absint($_POST['shift_id']) : 0;
    if (!$shift_id) {
        wp_send_json_error(['message' => esc_html__('Invalid shift ID.', 'eventadmin-volunteer-management')]);
    }
    $meta_key = 'assigned_user_' . $user_id;

    $start = get_post_meta($shift_id, 'shift_start', true);
    $hours_limit = get_option('eventadmin_unassign_limit_hours', 0);
    $tz = wp_timezone(); // returns DateTimeZone object
    $now = new DateTime('now', $tz);
    $start_dt = new DateTime($start, $tz);
    $diff = $start_dt->getTimestamp() - $now->getTimestamp();

    if ($hours_limit > 0 && $diff < $hours_limit * 3600) {
        $limit_text = $hours_limit . ' ' . ($hours_limit === 1 ? esc_html__('hour', 'eventadmin-volunteer-management') : esc_html__('hours', 'eventadmin-volunteer-management'));

        wp_send_json_error([
            'message' => sprintf(
                // translators: %s is the time span before shift start, e.g. '24 hours'
                esc_html__('Cancellation not possible. Cancellation is only allowed up to %s before the shift starts.', 'eventadmin-volunteer-management'),
                $limit_text
            )
        ]);
    }

    if (get_post_meta($shift_id, $meta_key, true)) {
        delete_post_meta($shift_id, $meta_key);
        eventadmin_send_shift_un_assignment_notification($user_id, $shift_id, 'unassign');

        $names = eventadmin_get_user_display_names($shift_id);
        $count = eventadmin_count_assignments($shift_id);
        $max = (int) get_post_meta($shift_id, 'max_volunteers', true);

        wp_send_json_success([
            'message' => esc_html__('You have been successfully removed.', 'eventadmin-volunteer-management'),
            'count' => $count,
            'open' => eventadmin_format_slots_open($max - $count, $max),
            'names' => implode(', ', $names)
        ]);
    } else {
        wp_send_json_error(['message' => esc_html__('You are not signed up for this shift.', 'eventadmin-volunteer-management')]);
    }
}

add_action('wp_ajax_eventadmin_unassign_ajax', 'eventadmin_unassign_ajax');

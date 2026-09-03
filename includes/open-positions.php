<?php
/**
 * EventAdmin Volunteer Management - Public "open positions" list
 *
 * A read-only, no-login shortcode that shows where volunteers are still needed,
 * grouped by department (Ressort). Meant to be embedded on public recruitment
 * pages so visitors can see where help is needed before they register.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Registers the open-positions stylesheet so the shortcode can enqueue it on demand.
 *
 * @return void
 */
function eventadmin_register_open_positions_assets(): void
{
    $css_path = plugin_dir_path(__FILE__) . '../assets/css/open-positions.css';

    wp_register_style(
        'eventadmin-open-positions',
        plugin_dir_url(__FILE__) . '../assets/css/open-positions.css',
        [],
        file_exists($css_path) ? filemtime($css_path) : null
    );
}

add_action('wp_enqueue_scripts', 'eventadmin_register_open_positions_assets');

/**
 * Returns the display colour for a department term, generating and persisting one
 * the first time it is needed (mirrors the behaviour of the shift selector).
 *
 * @param int $term_id
 * @return string Hex colour, e.g. "#7a7a7a".
 */
function eventadmin_open_positions_term_color(int $term_id): string
{
    $color = get_term_meta($term_id, 'term_color', true);
    if (!$color) {
        $color = sprintf('#%06x', wp_rand(0x444444, 0xAAAAAA));
        update_term_meta($term_id, 'term_color', $color);
    }
    return $color;
}

/**
 * Formats a bare open-slot count, e.g. "12 spots open" / "1 spot open".
 *
 * @param int $open
 * @return string
 */
function eventadmin_open_positions_format_count(int $open): string
{
    return sprintf(
        /* translators: %d: number of unfilled volunteer slots */
        _n('%d spot open', '%d spots open', $open, 'eventadmin-volunteer-management'),
        $open
    );
}

/**
 * Shortcode `[eventadmin_open_positions]` — public overview of where volunteers
 * are still needed, grouped by department.
 *
 * Attributes:
 *  - style      "summary" (default): one line per department with its open-slot count.
 *               "list": every open shift, grouped under its department.
 *  - category   Comma-separated department slugs to limit the output to. Default: all visible.
 *  - show_intro "0" to hide the "We still need volunteers in:" lead-in (summary style only).
 *  - show_full  "1" to also include shifts whose slots are already filled (list style only).
 *  - hide_past  "0" to also include shifts that have already ended. Default: "1".
 *
 * @param array<string,string>|string $atts
 * @return string
 */
function eventadmin_open_positions_shortcode($atts): string
{
    $atts = shortcode_atts([
        'style'      => 'summary',
        'category'   => '',
        'show_intro' => '1',
        'show_full'  => '0',
        'hide_past'  => '1',
    ], $atts, 'eventadmin_open_positions');

    $style      = strtolower(trim((string) $atts['style'])) === 'list' ? 'list' : 'summary';
    $show_intro = filter_var($atts['show_intro'], FILTER_VALIDATE_BOOLEAN);
    $show_full  = $style === 'list' && filter_var($atts['show_full'], FILTER_VALIDATE_BOOLEAN);
    $hide_past  = filter_var($atts['hide_past'], FILTER_VALIDATE_BOOLEAN);
    $only_slugs = array_filter(array_map('trim', explode(',', (string) $atts['category'])));

    wp_enqueue_style('eventadmin-open-positions');

    $shifts = get_posts(['post_type' => 'eventadmin_shift', 'numberposts' => -1]);
    $now    = time();

    // department term_id => ['open' => int, 'total' => int, 'shifts' => WP_Post[]]
    $groups        = [];
    $ungrouped     = ['open' => 0, 'total' => 0, 'shifts' => []];
    $has_ungrouped = false;

    foreach ($shifts as $shift) {
        $max     = (int) get_post_meta($shift->ID, 'max_volunteers', true);
        $current = eventadmin_count_assignments($shift->ID);
        $open    = max(0, $max - $current);

        if (!$show_full && $open <= 0) {
            continue;
        }

        if ($hide_past) {
            $end_raw = get_post_meta($shift->ID, 'shift_end', true)
                ?: get_post_meta($shift->ID, 'shift_start', true);
            $end_ts = $end_raw ? strtotime($end_raw) : 0;
            if ($end_ts && $end_ts < $now) {
                continue;
            }
        }

        // Every department the shift is in is hidden → it's not offered to volunteers,
        // so it has no place on a public "help wanted" list either.
        if (eventadmin_shift_is_hidden_from_volunteers($shift->ID)) {
            continue;
        }

        $term_ids = wp_get_post_terms($shift->ID, 'eventadmin_shift_category', ['fields' => 'ids']);
        $visible_term_ids = array_filter(
            (array) $term_ids,
            fn($id) => !eventadmin_is_shift_category_hidden($id)
        );

        if (empty($visible_term_ids)) {
            $ungrouped['shifts'][] = $shift;
            $ungrouped['open']    += $open;
            $ungrouped['total']   += $max;
            $has_ungrouped         = true;
            continue;
        }

        foreach ($visible_term_ids as $term_id) {
            if (!isset($groups[$term_id])) {
                $groups[$term_id] = ['open' => 0, 'total' => 0, 'shifts' => []];
            }
            $groups[$term_id]['shifts'][] = $shift;
            $groups[$term_id]['open']    += $open;
            $groups[$term_id]['total']   += $max;
        }
    }

    // Order the departments parent-first, depth-first (same helper the selector uses).
    $categories = get_terms(['taxonomy' => 'eventadmin_shift_category', 'hide_empty' => false]);
    if (is_wp_error($categories)) {
        $categories = [];
    }
    $categories = array_filter($categories, fn($cat) => !eventadmin_is_shift_category_hidden($cat->term_id));
    if (!empty($only_slugs)) {
        $categories = array_filter($categories, fn($cat) => in_array($cat->slug, $only_slugs, true));
    }
    $categories = eventadmin_order_categories_hierarchically($categories);

    $show_other = $has_ungrouped && empty($only_slugs);

    ob_start();
    echo '<div class="eventadmin-open-positions eaop-style-' . esc_attr($style) . '">';

    if ($style === 'list') {
        $rendered = eventadmin_open_positions_render_list($categories, $groups, $ungrouped, $show_other);
    } else {
        $rendered = eventadmin_open_positions_render_summary($categories, $groups, $ungrouped, $show_other, $show_intro);
    }

    if (!$rendered) {
        echo '<p class="eaop-empty">'
            . esc_html__('There are currently no open volunteer positions.', 'eventadmin-volunteer-management')
            . '</p>';
    }

    echo '</div>';

    return ob_get_clean();
}

add_shortcode('eventadmin_open_positions', 'eventadmin_open_positions_shortcode');

/**
 * Renders the condensed summary: one row per department with its open-slot count.
 *
 * @param WP_Term[] $categories  Ordered, hierarchy-annotated department terms.
 * @param array<int,array{open:int,total:int,shifts:WP_Post[]}> $groups
 * @param array{open:int,total:int,shifts:WP_Post[]} $ungrouped
 * @param bool $show_other  Whether to append the "Other" bucket.
 * @param bool $show_intro  Whether to print the lead-in sentence.
 * @return bool  True if at least one row was rendered.
 */
function eventadmin_open_positions_render_summary(array $categories, array $groups, array $ungrouped, bool $show_other, bool $show_intro): bool
{
    $rows = [];

    foreach ($categories as $cat) {
        $open = $groups[$cat->term_id]['open'] ?? 0;
        if ($open <= 0) {
            continue;
        }
        $rows[] = [
            'name'  => $cat->name,
            'open'  => $open,
            'color' => eventadmin_open_positions_term_color($cat->term_id),
            'depth' => (int) ($cat->eventadmin_depth ?? 0),
            'other' => false,
        ];
    }

    if ($show_other && $ungrouped['open'] > 0) {
        $rows[] = [
            'name'  => __('Other', 'eventadmin-volunteer-management'),
            'open'  => $ungrouped['open'],
            'color' => '#9a9a9a',
            'depth' => 0,
            'other' => true,
        ];
    }

    if (empty($rows)) {
        return false;
    }

    if ($show_intro) {
        echo '<p class="eaop-summary-intro">'
            . esc_html__('We still need volunteers in:', 'eventadmin-volunteer-management') . '</p>';
    }

    echo '<ul class="eaop-summary-list">';
    foreach ($rows as $row) {
        echo '<li class="eaop-summary-item' . ($row['other'] ? ' eaop-summary-item--other' : '') . '"'
            . ' style="--eaop-color:' . esc_attr($row['color']) . ';--eaop-depth:' . esc_attr((string) $row['depth']) . ';">';
        echo '<span class="eaop-summary-badge" aria-hidden="true"></span>';
        echo '<span class="eaop-summary-name">' . esc_html($row['name']) . '</span>';
        echo '<span class="eaop-summary-count">'
            . esc_html(eventadmin_open_positions_format_count($row['open'])) . '</span>';
        echo '</li>';
    }
    echo '</ul>';

    return true;
}

/**
 * Renders the detailed list: every open shift, grouped under its department.
 *
 * @param WP_Term[] $categories
 * @param array<int,array{open:int,total:int,shifts:WP_Post[]}> $groups
 * @param array{open:int,total:int,shifts:WP_Post[]} $ungrouped
 * @param bool $show_other
 * @return bool  True if at least one section was rendered.
 */
function eventadmin_open_positions_render_list(array $categories, array $groups, array $ungrouped, bool $show_other): bool
{
    $rendered_any = false;

    foreach ($categories as $cat) {
        if (empty($groups[$cat->term_id]['shifts'])) {
            continue;
        }
        $rendered_any = true;
        $group = $groups[$cat->term_id];
        usort($group['shifts'], 'eventadmin_sort_shifts_by_start');

        $color = eventadmin_open_positions_term_color($cat->term_id);
        $depth = (int) ($cat->eventadmin_depth ?? 0);

        echo '<section class="eaop-ressort' . ($depth > 0 ? ' eaop-ressort--nested' : '') . '"'
            . ' style="--eaop-color:' . esc_attr($color) . ';--eaop-depth:' . esc_attr((string) $depth) . ';">';
        echo '<h3 class="eaop-ressort-title">';
        echo '<span class="eaop-ressort-badge" aria-hidden="true"></span>';
        echo '<span class="eaop-ressort-name">' . esc_html($cat->name) . '</span>';
        echo '<span class="eaop-ressort-open">'
            . esc_html(eventadmin_format_slots_open($group['open'], $group['total'])) . '</span>';
        echo '</h3>';
        eventadmin_open_positions_render_shifts($group['shifts']);
        echo '</section>';
    }

    if ($show_other) {
        $rendered_any = true;
        usort($ungrouped['shifts'], 'eventadmin_sort_shifts_by_start');

        echo '<section class="eaop-ressort eaop-ressort--other" style="--eaop-color:#9a9a9a;--eaop-depth:0;">';
        echo '<h3 class="eaop-ressort-title">';
        echo '<span class="eaop-ressort-badge" aria-hidden="true"></span>';
        echo '<span class="eaop-ressort-name">' . esc_html__('Other', 'eventadmin-volunteer-management') . '</span>';
        echo '<span class="eaop-ressort-open">'
            . esc_html(eventadmin_format_slots_open($ungrouped['open'], $ungrouped['total'])) . '</span>';
        echo '</h3>';
        eventadmin_open_positions_render_shifts($ungrouped['shifts']);
        echo '</section>';
    }

    return $rendered_any;
}

/**
 * Renders the shift list inside one department section.
 *
 * @param WP_Post[] $shifts Already sorted by start time.
 * @return void
 */
function eventadmin_open_positions_render_shifts(array $shifts): void
{
    echo '<ul class="eaop-shift-list">';
    foreach ($shifts as $shift) {
        $start   = get_post_meta($shift->ID, 'shift_start', true);
        $end     = get_post_meta($shift->ID, 'shift_end', true);
        $max     = (int) get_post_meta($shift->ID, 'max_volunteers', true);
        $current = eventadmin_count_assignments($shift->ID);

        echo '<li class="eaop-shift">';
        echo '<div class="eaop-shift-when">'
            . esc_html(eventadmin_get_formatted_zeitraum($start, $end)) . '</div>';
        echo '<div class="eaop-shift-title">' . esc_html($shift->post_title) . '</div>';
        echo '<div class="eaop-shift-open">'
            . esc_html(eventadmin_format_slots_open($max - $current, $max)) . '</div>';
        if ($shift->post_content !== '') {
            echo '<div class="eaop-shift-desc">' . wp_kses_post($shift->post_content) . '</div>';
        }
        echo '</li>';
    }
    echo '</ul>';
}

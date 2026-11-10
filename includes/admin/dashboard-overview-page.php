<?php
/**
 * EventAdmin Volunteer Management - Overview/Manager Page Skeleton
 * The shared filter-parsing/rendering, per-shift row-building, and pagination that every
 * Overview/Manager view (Dashboard/Table/Cards/Timeline) plugs into, plus the page
 * dispatcher itself.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Parses and validates every filter/view GET parameter for eventadmin_render_overview_page()
 * (nonce-gated — an invalid/missing nonce falls back to each filter's default rather than
 * trusting the raw query string), and builds the shared query-args array used to construct
 * both the view tabs and pagination links further down.
 *
 * @param string   $page_slug     The registered submenu page slug this call is rendering for.
 * @param string[] $allowed_views Which of 'dashboard'/'table'/'cards'/'timeline' this page
 *                                 offers (see eventadmin_render_overview_page()).
 * @param string   $default_view  Fallback when filter_view is absent/invalid.
 * @return array<string, mixed>
 */
function eventadmin_overview_parse_filter_state(string $page_slug, array $allowed_views, string $default_view): array
{
    $total_users = count(get_users(['role' => 'eventadmin_volunteer']));

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

    // Per-day staffing for the date bar (includes/admin/date-navigator.php). For long-running
    // setups the Manager timeline opens on the next day with shifts instead of every upcoming
    // shift at once — unless a date (or "All dates", sent as an empty filter_date) was chosen.
    $day_stats     = eventadmin_get_shift_day_stats($selected_cat);
    $long_running  = eventadmin_shifts_are_long_running($day_stats);
    $date_chosen   = $filter_valid && isset($_GET['filter_date']);
    $sort_by     = in_array($raw_sortby, $allowed_sort_by, true)             ? $raw_sortby            : 'date';
    $order       = in_array(strtoupper($raw_order), $allowed_orders, true)  ? strtoupper($raw_order) : 'ASC';
    $view          = in_array($raw_view, $allowed_views, true) ? $raw_view : $default_view;
    if ($view === 'timeline' && $time_filter === 'future' && $long_running && !$date_chosen && $selected_date === '') {
        $selected_date = eventadmin_next_shift_date($day_stats);
    }
    // Checkboxes submit nothing when unchecked, so a hidden "0" companion field (rendered
    // just before the checkbox) is what lets us tell "unchecked" apart from "not submitted
    // yet" — the checkbox's own value overwrites it in the query string only when checked.
    // Links built from $filter_query_args (view tabs, pagination, the date bar) leave
    // show_open out while it's on, so only an explicit "0" turns it off.
    $show_open = !($filter_valid && isset($_GET['show_open']) && $_GET['show_open'] !== '1');

    // Shared base query args for building both the view tabs and pagination links below,
    // so a filter change (department, date, …) doesn't reset the other.
    $filter_query_args = array_filter([
        'post_type'                       => 'eventadmin_shift',
        'page'                            => $page_slug,
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

    return [
        'total_users'        => $total_users,
        'selected_cat'       => $selected_cat,
        'selected_state'     => $selected_state,
        'categories'         => $categories,
        'states'             => $states,
        'volunteers'         => $volunteers,
        'selected_volunteer' => $selected_volunteer,
        'selected_date'      => $selected_date,
        'shift_dates'        => $shift_dates,
        'day_stats'          => $day_stats,
        'time_filter'        => $time_filter,
        'sort_by'            => $sort_by,
        'order'              => $order,
        'view'               => $view,
        'show_open'          => $show_open,
        'filter_query_args'  => $filter_query_args,
    ];
}

/**
 * Renders the "N shift(s) copied" notice (if a copy just happened) and the "Copy shifts to
 * another day" modal itself — Manager's Timeline tab is the only one with a trigger button
 * for it, but the modal markup is harmless to also include on Table/Cards.
 *
 * @param string   $view        Current view — no-op on 'dashboard', which has no trigger.
 * @param string[] $shift_dates Distinct dates with at least one shift, for the "from" dropdown.
 * @return void
 */
function eventadmin_render_copy_shifts_notice_and_modal(string $view, array $shift_dates): void
{
    if ($view === 'dashboard') {
        return;
    }

    if (isset($_GET['shifts_copied'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $copied_count  = absint($_GET['shifts_copied']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $copy_days     = isset($_GET['copy_days']) ? absint($_GET['copy_days']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $copy_skipped  = isset($_GET['copy_skipped']) ? absint($_GET['copy_skipped']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ($copy_days > 0) {
            $message = sprintf(
                /* translators: 1: number of shifts created, 2: number of days (weeks) they were spread over */
                _n('%1$d shift was created across %2$d weeks.', '%1$d shifts were created across %2$d weeks.', $copied_count, 'eventadmin-volunteer-management'),
                $copied_count,
                $copy_days
            );
            if ($copy_skipped > 0) {
                $message .= ' ' . sprintf(
                    /* translators: %d: number of shifts skipped because an identical shift already existed */
                    _n('%d shift already existed and was skipped.', '%d shifts already existed and were skipped.', $copy_skipped, 'eventadmin-volunteer-management'),
                    $copy_skipped
                );
            }
            echo '<div class="notice notice-' . ($copied_count > 0 ? 'success' : 'warning') . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        } elseif ($copied_count > 0) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(
                /* translators: %d: number of shifts copied */
                _n('%d shift was copied.', '%d shifts were copied.', $copied_count, 'eventadmin-volunteer-management'),
                $copied_count
            )) . '</p></div>';
        } else {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('No shifts were found on the selected day to copy.', 'eventadmin-volunteer-management') . '</p></div>';
        }
    }

    // "Copy shifts to another day" modal, opened via the button above.
    eventadmin_render_modal_open('eventadmin-copy-shifts-modal');
    eventadmin_render_modal_close_button('eventadmin-copy-shifts-close');
    echo '<h2 style="margin-top:0;">' . esc_html__('Copy shifts to another day', 'eventadmin-volunteer-management') . '</h2>';
    echo '<p class="description">' . esc_html__('Recreate every shift from a past (or future) day on a new date, keeping each shift\'s time of day and duration.', 'eventadmin-volunteer-management') . ' ' . esc_html__('Use "Repeat every week" to fill a whole series at once, e.g. every Monday for 12 weeks.', 'eventadmin-volunteer-management') . '</p>';
    echo '<form method="post">';
    wp_nonce_field('eventadmin_copy_shifts', 'eventadmin_copy_shifts_nonce');
    echo '<input type="hidden" name="eventadmin_copy_shifts_day" value="1">';
    echo '<p><label>' . esc_html__('Copy shifts from:', 'eventadmin-volunteer-management') . '<br><select name="copy_source_date" required style="width:100%;">';
    echo '<option value="">' . esc_html__('Select a day…', 'eventadmin-volunteer-management') . '</option>';
    foreach ($shift_dates as $date) {
        $date_label = date_i18n('l, ' . get_option('date_format'), strtotime($date));
        echo '<option value="' . esc_attr($date) . '">' . esc_html($date_label) . '</option>';
    }
    echo '</select></label></p>';
    echo '<p><label>' . esc_html__('to:', 'eventadmin-volunteer-management') . '<br><input type="date" name="copy_target_date" required style="width:100%;"></label></p>';
    echo '<p><label><input type="checkbox" name="copy_repeat_weekly" value="1" id="eventadmin-copy-repeat-weekly"> ' . esc_html__('Repeat every week', 'eventadmin-volunteer-management') . '</label></p>';
    echo '<p id="eventadmin-copy-repeat-until-row" style="display:none;"><label>' . esc_html__('until (including):', 'eventadmin-volunteer-management') . '<br><input type="date" name="copy_repeat_until" id="eventadmin-copy-repeat-until" style="width:100%;"></label>';
    echo '<span class="description">' . esc_html(sprintf(
        /* translators: %d: maximum number of weeks */
        __('Creates the shifts on the same weekday every week, up to %d weeks. Shifts that already exist on a day are skipped, and volunteers are not copied.', 'eventadmin-volunteer-management'),
        EVENTADMIN_MAX_REPEAT_WEEKS
    )) . '</span></p>';
    echo '<p id="eventadmin-copy-volunteers-row"><label><input type="checkbox" name="copy_volunteers" value="1"> ' . esc_html__('Also copy the volunteers currently signed up', 'eventadmin-volunteer-management') . '</label></p>';
    echo '<p>';
    submit_button(esc_html__('Copy shifts', 'eventadmin-volunteer-management'), 'primary', '', false);
    echo '</p>';
    echo '</form>';
    eventadmin_render_modal_close();
}

/**
 * Renders the toolbar above the Day / Table / Cards content — the same structure on every
 * tab, left to right:
 *   1. When: the period control (eventadmin_render_when_control()) — always visible, also
 *      on mobile, since it's what's changed most.
 *   2. Filters, always in this order: Department → Volunteer → Status. One that doesn't
 *      apply to the current tab is left out; the others keep their order.
 *   3. View options (right): how results are shown, not which — "Open slots" on Day,
 *      sorting on Cards.
 * Active filters are repeated below as removable chips with "Clear filters"; on mobile the
 * filters and view options fold behind a "Filters (n)" toggle.
 *
 * @param string    $page_slug          Registered submenu page slug.
 * @param string    $view               Current view.
 * @param WP_Term[] $categories         From eventadmin_get_hierarchical_shift_categories().
 * @param string    $selected_cat       Currently selected department slug.
 * @param array<string,string> $states  Cards-only status options, keyed by value.
 * @param string    $selected_state     Currently selected status key (Cards only).
 * @param WP_User[] $volunteers         Non-offline volunteers.
 * @param int       $selected_volunteer Currently selected volunteer ID.
 * @param string    $selected_date      Y-m-d or ''.
 * @param string    $time_filter        'future'|'past'|'all'.
 * @param string    $sort_by            'date'|'title' (Cards only).
 * @param string    $order              'ASC'|'DESC' (Cards only).
 * @param bool      $show_open          Day-only "open slots" option.
 * @param array<string, mixed> $filter_query_args Current filters as query args.
 * @param array<string, mixed> $day_stats         From eventadmin_get_shift_day_stats().
 * @return void
 */
function eventadmin_render_overview_toolbar(
    string $page_slug,
    string $view,
    array $categories,
    string $selected_cat,
    array $states,
    string $selected_state,
    array $volunteers,
    int $selected_volunteer,
    string $selected_date,
    string $time_filter,
    string $sort_by,
    string $order,
    bool $show_open,
    array $filter_query_args,
    array $day_stats
): void {
    $show_volunteer = $view !== 'timeline'; // Day already shows every volunteer's own row
    $show_status    = $view === 'cards';    // Table and Day show assignment rows, not shifts

    // Active filters, for the chips row and the mobile "Filters (n)" count.
    $active = [];
    $remove_url = static function (string $key) use ($filter_query_args, $view): string {
        $args = array_merge($filter_query_args, ['filter_view' => $view]);
        unset($args[$key], $args['paged']);
        return add_query_arg($args, admin_url('edit.php'));
    };
    if ($selected_cat !== '') {
        $term = get_term_by('slug', $selected_cat, 'eventadmin_shift_category');
        $active[] = [__('Department', 'eventadmin-volunteer-management'), $term instanceof WP_Term ? $term->name : $selected_cat, $remove_url('filter_cat')];
    }
    if ($show_volunteer && $selected_volunteer > 0) {
        $user = get_userdata($selected_volunteer);
        $active[] = [__('Volunteer', 'eventadmin-volunteer-management'), $user ? trim($user->first_name . ' ' . $user->last_name) : '#' . $selected_volunteer, $remove_url('filter_volunteer')];
    }
    if ($show_status && $selected_state !== '' && isset($states[$selected_state])) {
        $active[] = [__('Status', 'eventadmin-volunteer-management'), $states[$selected_state], $remove_url('filter_state')];
    }

    echo '<form method="get" action="edit.php" id="eventadmin-overview-filters" class="eventadmin-toolbar' . (!empty($active) ? ' is-open' : '') . '">';
    wp_nonce_field('eventadmin_filter_shifts', 'eventadmin_filter_shifts_nonce', false);
    echo '<input type="hidden" name="post_type" value="eventadmin_shift">';
    echo '<input type="hidden" name="page" value="' . esc_attr($page_slug) . '">';
    // Tabs and the "when" control are links, not part of this form — these keep them when
    // a filter below changes.
    echo '<input type="hidden" name="filter_view" value="' . esc_attr($view) . '">';
    echo '<input type="hidden" name="filter_date" value="' . esc_attr($selected_date) . '">';
    echo '<input type="hidden" name="filter_time" value="' . esc_attr($time_filter) . '">';

    // 1. When
    echo '<div class="eventadmin-toolbar-group eventadmin-toolbar-when">';
    echo '<span class="eventadmin-filter-label">' . esc_html__('Date', 'eventadmin-volunteer-management') . '</span>';
    eventadmin_render_when_control($filter_query_args, $view, $selected_date, $day_stats, $time_filter);
    echo '</div>';

    // Mobile only: folds groups 2 and 3.
    $toggle_label = empty($active)
        ? __('Filters', 'eventadmin-volunteer-management')
        /* translators: %d: number of active filters */
        : sprintf(__('Filters (%d)', 'eventadmin-volunteer-management'), count($active));
    echo '<button type="button" id="eventadmin-filters-toggle" class="button eventadmin-filters-toggle"'
        . ' data-show-label="' . esc_attr($toggle_label) . '" data-hide-label="' . esc_attr__('Hide filters', 'eventadmin-volunteer-management') . '">'
        . esc_html(!empty($active) ? __('Hide filters', 'eventadmin-volunteer-management') : $toggle_label) . '</button>';

    // 2. Filters: Department → Volunteer → Status
    echo '<div class="eventadmin-toolbar-group eventadmin-toolbar-filters">';
    echo '<label class="eventadmin-filter"><span class="eventadmin-filter-label">' . esc_html__('Department', 'eventadmin-volunteer-management') . '</span>';
    echo '<select name="filter_cat"><option value="">' . esc_html__('All departments', 'eventadmin-volunteer-management') . '</option>';
    echo eventadmin_category_dropdown_options($categories, $selected_cat, 'slug'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside
    echo '</select></label>';

    if ($show_volunteer) {
        echo '<label class="eventadmin-filter"><span class="eventadmin-filter-label">' . esc_html__('Volunteer', 'eventadmin-volunteer-management') . '</span>';
        echo '<select name="filter_volunteer"><option value="">' . esc_html__('Anyone', 'eventadmin-volunteer-management') . '</option>';
        foreach ($volunteers as $volunteer) {
            echo '<option value="' . esc_attr($volunteer->ID) . '"' . selected($selected_volunteer, $volunteer->ID, false) . '>' . esc_html(trim($volunteer->first_name . ' ' . $volunteer->last_name) ?: $volunteer->user_login) . '</option>';
        }
        echo '</select></label>';
    }

    if ($show_status) {
        echo '<label class="eventadmin-filter"><span class="eventadmin-filter-label">' . esc_html__('Status', 'eventadmin-volunteer-management') . '</span>';
        echo '<select name="filter_state"><option value="">' . esc_html__('Any status', 'eventadmin-volunteer-management') . '</option>';
        foreach ($states as $key => $val) {
            echo '<option value="' . esc_attr($key) . '"' . selected($selected_state, $key, false) . '>' . esc_html($val) . '</option>';
        }
        echo '</select></label>';
    }
    echo '</div>';

    // 3. View options
    if ($view === 'timeline' || $view === 'cards') {
        echo '<div class="eventadmin-toolbar-group eventadmin-toolbar-view">';
        echo '<span class="eventadmin-filter-label">' . esc_html__('View', 'eventadmin-volunteer-management') . '</span>';
        echo '<div class="eventadmin-toolbar-view-controls">';
        if ($view === 'timeline') {
            echo '<label class="eventadmin-view-option"><input type="hidden" name="show_open" value="0">';
            echo '<input type="checkbox" name="show_open" value="1"' . checked($show_open, true, false) . '> ';
            echo esc_html__('Open slots', 'eventadmin-volunteer-management') . '</label>';
        } else {
            echo '<select name="sort_by" aria-label="' . esc_attr__('Sort by', 'eventadmin-volunteer-management') . '">';
            foreach (['date' => __('Sort by date', 'eventadmin-volunteer-management'), 'title' => __('Sort by name', 'eventadmin-volunteer-management')] as $val => $label) {
                echo '<option value="' . esc_attr($val) . '"' . selected($sort_by, $val, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
            echo '<select name="order" aria-label="' . esc_attr__('Order', 'eventadmin-volunteer-management') . '">';
            foreach (['ASC' => __('Ascending', 'eventadmin-volunteer-management'), 'DESC' => __('Descending', 'eventadmin-volunteer-management')] as $val => $label) {
                echo '<option value="' . esc_attr($val) . '"' . selected($order, $val, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
        }
        echo '</div></div>';
    }

    echo '<noscript><input type="submit" class="button" value="' . esc_attr__('Filter', 'eventadmin-volunteer-management') . '"></noscript>';
    echo '</form>';

    if (!empty($active)) {
        $clear_url = add_query_arg([
            'post_type'                      => 'eventadmin_shift',
            'page'                           => $page_slug,
            'filter_view'                    => $view,
            'eventadmin_filter_shifts_nonce' => wp_create_nonce('eventadmin_filter_shifts'),
        ], admin_url('edit.php'));
        echo '<div class="eventadmin-active-filters">';
        foreach ($active as [$label, $value, $url]) {
            echo '<a class="eventadmin-active-filter" href="' . esc_url($url) . '" title="' . esc_attr__('Remove filter', 'eventadmin-volunteer-management') . '">'
                . '<span class="eventadmin-active-filter-label">' . esc_html($label) . ':</span> ' . esc_html($value)
                . ' <span aria-hidden="true">✕</span></a>';
        }
        echo '<a class="eventadmin-clear-filters" href="' . esc_url($clear_url) . '">' . esc_html__('Clear filters', 'eventadmin-volunteer-management') . '</a>';
        echo '</div>';
    }

    echo '<script>
        document.querySelectorAll("#eventadmin-overview-filters select, #eventadmin-overview-filters input[type=checkbox]").forEach(function (el) {
            el.addEventListener("change", function () { el.form.submit(); });
        });
    </script>';
}

/**
 * Renders the page heading with its one action group — "+ Add shift" plus a menu with the
 * less frequent actions — the same on every tab, instead of a different button (or none)
 * per tab somewhere between the filters and the content.
 *
 * @param string $page_title
 * @param string $view
 * @return void
 */
function eventadmin_render_overview_heading(string $page_title, string $view): void
{
    echo '<h1 class="wp-heading-inline">' . esc_html($page_title) . '</h1>';

    if ($view !== 'dashboard' && current_user_can('eventadmin_manage_shifts')) {
        echo '<div class="eventadmin-split-button eventadmin-heading-actions">';
        echo '<button type="button" id="eventadmin-open-new-shift-modal" class="button button-primary">+ ' . esc_html__('Add shift', 'eventadmin-volunteer-management') . '</button>';
        echo '<button type="button" id="eventadmin-split-button-toggle" class="button button-primary eventadmin-split-button-toggle" aria-haspopup="true" aria-expanded="false" aria-label="' . esc_attr__('More options', 'eventadmin-volunteer-management') . '">';
        echo '<span aria-hidden="true">&#9662;</span>';
        echo '</button>';
        echo '<div id="eventadmin-split-button-menu" class="eventadmin-split-button-menu" style="display:none;">';
        echo '<button type="button" id="eventadmin-open-copy-shifts-modal" class="eventadmin-timeline-menu-item">' . esc_html__('Copy shifts to another day', 'eventadmin-volunteer-management') . '</button>';
        echo '<button type="submit" form="eventadmin-export-all-form" class="eventadmin-timeline-menu-item">' . esc_html__('Export all shifts (CSV)', 'eventadmin-volunteer-management') . '</button>';
        if (current_user_can('eventadmin_manage_departments')) {
            echo '<a href="' . esc_url(admin_url('edit-tags.php?taxonomy=eventadmin_shift_category&post_type=eventadmin_shift')) . '" class="eventadmin-timeline-menu-item">' . esc_html__('Departments', 'eventadmin-volunteer-management') . '</a>';
        }
        echo '</div>';
        echo '</div>';

        // Target of the "Export all shifts (CSV)" menu item — kept outside the menu markup.
        echo '<form method="post" id="eventadmin-export-all-form" style="display:none;">';
        wp_nonce_field('eventadmin_export_all', 'eventadmin_export_all_nonce');
        echo '<input type="hidden" name="eventadmin_export_all" value="1">';
        echo '</form>';
    }

    echo '<hr class="wp-header-end">';
}

/**
 * Builds the per-shift data for the Table and Timeline views (returned, for their own
 * dedicated renderers below) and, for Cards, echoes each shift's full card directly — Cards
 * has no separate row-collection step since every shift becomes its own self-contained block
 * of markup instead of feeding a shared table/chart.
 *
 * @param WP_Post[] $shifts         From eventadmin_get_shifts().
 * @param string    $view           'table'|'timeline'|'cards' (or the default/legacy branch).
 * @param string    $selected_state Cards-only "State" filter value ('', 'empty', 'understaffed', 'heavilyunderstaffed').
 * @param bool      $show_open      Timeline-only: include open-slot rows.
 * @return array{table_rows: array, timeline_rows: array, shift_info_map: array, shift_edit_map: array}
 */
function eventadmin_build_overview_rows(array $shifts, string $view, string $selected_state, bool $show_open): array
{
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
            $start_ts = eventadmin_wallclock_to_ts($start);
            $end_ts   = eventadmin_wallclock_to_ts($end);

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
                'description' => $shift->post_content,
                'organizer_user_id' => (int) get_post_meta($shift->ID, 'shift_organizer_user_id', true),
                'organizer_name'    => (string) get_post_meta($shift->ID, 'shift_organizer_name', true),
                'organizer_email'   => (string) get_post_meta($shift->ID, 'shift_organizer_email', true),
            ];

            $timeline_rows = array_merge($timeline_rows, eventadmin_build_timeline_rows_for_shift($shift, $show_open));
            continue;
        }

        eventadmin_render_card_for_shift($shift, $title, $start, $end, $min, $max, $users);
    }

    return [
        'table_rows'      => $table_rows,
        'timeline_rows'   => $timeline_rows,
        'shift_info_map'  => $shift_info_map,
        'shift_edit_map'  => $shift_edit_map,
    ];
}

/**
 * Renders the shared "add volunteer" modal for the Table and Timeline views — Cards already
 * has this same form inline per shift, so this reuses the exact same fields/handler and just
 * wraps them in a JS-toggled overlay instead of one form per card.
 *
 * @param string $view           Current view — no-op on 'cards'.
 * @param WP_User[] $volunteers  Non-offline volunteers, for the "existing volunteer" dropdown.
 * @param array  $shift_info_map From eventadmin_build_overview_rows() — which volunteers are
 *                                already on which shift, so the JS can exclude them.
 * @return void
 */
function eventadmin_render_add_volunteer_modal(string $view, array $volunteers, array $shift_info_map): void
{
    if ($view === 'cards') {
        return;
    }

    eventadmin_render_modal_open('eventadmin-add-volunteer-modal');
    eventadmin_render_modal_close_button('eventadmin-modal-close');
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

    eventadmin_render_modal_close();

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

/**
 * Renders the pagination links at the bottom of the Table/Cards/Timeline views.
 *
 * @param int    $total_found       From eventadmin_get_shifts()'s $total output parameter.
 * @param int    $per_page          Page size used for that query.
 * @param int    $current_page      Current page number.
 * @param array  $filter_query_args Shared query args, for the page-number links.
 * @param string $view              Current view, preserved in each page-number link.
 * @return void
 */
function eventadmin_render_overview_pagination(int $total_found, int $per_page, int $current_page, array $filter_query_args, string $view): void
{
    $total_pages = $total_found > 0 ? (int)ceil($total_found / $per_page) : 1;
    if ($total_pages <= 1) {
        return;
    }

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

/**
 * Shared renderer for both the Overview and Manager pages — same filters, same view
 * rendering (dashboard stats / table / cards / timeline), split only by which of those
 * views a given page allows and defaults to, and which page slug its own links point back
 * at. Kept as one function rather than duplicated per page since every view here shares
 * the bulk of its filter-parsing and query logic regardless of which page hosts it.
 *
 * @param string $page_slug The registered submenu page slug this call is rendering for.
 * @param string[] $allowed_views Which of 'dashboard'/'table'/'cards'/'timeline' this page
 *                                offers — also doubles as the tab list, in order given.
 * @param string $default_view Fallback when filter_view is absent/invalid — must be one of
 *                              $allowed_views.
 * @param string $page_title The <h1> heading.
 * @return void
 */
function eventadmin_render_overview_page(string $page_slug, array $allowed_views, string $default_view, string $page_title): void
{
    global $eventadmin_form_error;
    if (!empty($eventadmin_form_error)) {
        echo '<div class="notice notice-error"><p>' . esc_html($eventadmin_form_error) . '</p></div>';
    }

    $filter_state       = eventadmin_overview_parse_filter_state($page_slug, $allowed_views, $default_view);
    $total_users        = $filter_state['total_users'];
    $selected_cat       = $filter_state['selected_cat'];
    $selected_state     = $filter_state['selected_state'];
    $categories         = $filter_state['categories'];
    $states             = $filter_state['states'];
    $volunteers         = $filter_state['volunteers'];
    $selected_volunteer = $filter_state['selected_volunteer'];
    $selected_date      = $filter_state['selected_date'];
    $shift_dates        = $filter_state['shift_dates'];
    $time_filter        = $filter_state['time_filter'];
    $sort_by            = $filter_state['sort_by'];
    $order              = $filter_state['order'];
    $view               = $filter_state['view'];
    $show_open          = $filter_state['show_open'];
    $filter_query_args  = $filter_state['filter_query_args'];
    $day_stats          = $filter_state['day_stats'];

    eventadmin_render_copy_shifts_notice_and_modal($view, $shift_dates);

    echo '<div class="wrap">';
    eventadmin_render_overview_heading($page_title, $view);

    // View tabs — only shown when this page actually offers more than one view (Overview
    // is dashboard-stats-only now, so it skips the tab row entirely).
    if (count($allowed_views) > 1) {
        echo '<h2 class="nav-tab-wrapper">';
        $tab_labels = [
            'dashboard' => esc_html__('Dashboard', 'eventadmin-volunteer-management'),
            'timeline'  => esc_html__('Day', 'eventadmin-volunteer-management'),
            'table'     => esc_html__('Table', 'eventadmin-volunteer-management'),
            'cards'     => esc_html__('Cards (old)', 'eventadmin-volunteer-management'),
        ];
        foreach ($allowed_views as $slug) {
            $tab_url = add_query_arg(array_merge($filter_query_args, ['filter_view' => $slug]), admin_url('edit.php'));
            $active  = $view === $slug ? ' nav-tab-active' : '';
            echo '<a class="nav-tab' . $active . '" href="' . esc_url($tab_url) . '">' . $tab_labels[$slug] . '</a>';
        }
        echo '</h2>';
    }

    if ($view === 'dashboard') {
        eventadmin_render_getting_started_checklist();
        eventadmin_render_dashboard_stats_tab($total_users);
        eventadmin_render_activity_feed();
        eventadmin_render_shared_volunteer_modals();
        echo '</div>';
        return;
    }

    eventadmin_render_overview_toolbar(
        $page_slug,
        $view,
        $categories,
        $selected_cat,
        $states,
        $selected_state,
        $volunteers,
        $selected_volunteer,
        $selected_date,
        $time_filter,
        $sort_by,
        $order,
        $show_open,
        $filter_query_args,
        $day_stats
    );

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

    $overview_rows  = eventadmin_build_overview_rows($shifts, $view, $selected_state, $show_open);
    $table_rows     = $overview_rows['table_rows'];
    $timeline_rows  = $overview_rows['timeline_rows'];
    $shift_info_map = $overview_rows['shift_info_map'];
    $shift_edit_map = $overview_rows['shift_edit_map'];

    if ($view === 'table') {
        eventadmin_render_table_view($table_rows, $filter_query_args, $view, $sort_by, $order);
    }

    if ($view === 'timeline') {
        eventadmin_render_timeline_chart($timeline_rows);
    }

    eventadmin_render_add_volunteer_modal($view, $volunteers, $shift_info_map);

    eventadmin_render_timeline_shift_modals_and_config($view, $shift_edit_map, $show_open, $selected_date);

    // The Day view renders the shift modal with its own richer config (above); the other
    // tabs get the shared one, so "+ Add shift" and editing work there too — reloading
    // afterwards, since only the Day view updates itself in place.
    if ($view !== 'timeline' && current_user_can('eventadmin_manage_shifts')) {
        eventadmin_render_shared_volunteer_modals(['default_date' => $selected_date]);
        echo '<script>window.eventadminOnShiftCreated = window.eventadminOnShiftSaved = function () { window.location.reload(); };</script>';
    }

    eventadmin_render_overview_pagination($total_found, $per_page, $current_page, $filter_query_args, $view);

    echo '</div>';
}

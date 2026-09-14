<?php
/**
 * EventAdmin Volunteer Management - Admin Menu Registration & Page Routing
 * Registers the Overview/Manager submenu pages, their thin page callbacks, and enqueues
 * the Manager page's scripts/styles.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Adds the Overview (dashboard stats only) and Manager (Table/Cards/Timeline) pages to the
 * admin menu, places them first in that order, and optionally hides the classic "All
 * Shifts"/"Add Shift" menu items — those are WordPress's own auto-added CPT list/new-post
 * screens, which Manager's own modals have made largely redundant for everyday use
 * (Settings → General → "Admin menu").
 *
 * @return void
 */
function eventadmin_dashboard_admin_menu(): void
{
    add_submenu_page(
        'edit.php?post_type=eventadmin_shift',
        esc_html__('Overview', 'eventadmin-volunteer-management'),
        esc_html__('Overview', 'eventadmin-volunteer-management'),
        'eventadmin_manage_shifts',
        'eventadmin-overview',
        'eventadmin_admin_overview_page'
    );

    add_submenu_page(
        'edit.php?post_type=eventadmin_shift',
        esc_html__('Manager', 'eventadmin-volunteer-management'),
        esc_html__('Manager', 'eventadmin-volunteer-management'),
        'eventadmin_manage_shifts',
        'eventadmin-shift-manager',
        'eventadmin_admin_shift_manager_page'
    );

    global $submenu;

    if (!isset($submenu['edit.php?post_type=eventadmin_shift'])) {
        return;
    }

    // Overview then Manager, in that order, ahead of everything else (WordPress's own
    // "All Shifts"/"Add Shift"/"Departments" etc. included).
    $ordered = [];
    foreach (['eventadmin-overview', 'eventadmin-shift-manager'] as $slug) {
        foreach ($submenu['edit.php?post_type=eventadmin_shift'] as $key => $item) {
            if (isset($item[2]) && $item[2] === $slug) {
                $ordered[] = $item;
                unset($submenu['edit.php?post_type=eventadmin_shift'][$key]);
                break;
            }
        }
    }
    array_splice($submenu['edit.php?post_type=eventadmin_shift'], 0, 0, $ordered);

}

add_action('admin_menu', 'eventadmin_dashboard_admin_menu', 100);

/**
 * Hides the classic "All Shifts"/"Add Shift"/"Departments" menu items with CSS instead of
 * removing them from $submenu. Removing an entry (whether via array_filter() as this used
 * to do, or WordPress's own remove_submenu_page() — it does the same unset() internally)
 * breaks get_admin_page_parent()'s only way of resolving post-new.php?post_type=
 * eventadmin_shift back to its parent menu. Once that lookup fails, WordPress falls back to
 * an unscoped nopriv check keyed on the bare pagenow ("post-new.php"), which any role
 * without the generic edit_posts capability already fails for WordPress's own native "Add
 * Post" screen — incorrectly denying access to our own Add Shift screen too, for a role
 * such as eventadmin_shift_manager that deliberately never gets edit_posts. Hiding with CSS
 * keeps $submenu intact so page resolution and capability checks keep working, while still
 * decluttering the sidebar for whoever enabled the setting.
 *
 * @return void
 */
function eventadmin_hide_shift_menu_items_css(): void
{
    $rules = [];

    if (get_option('eventadmin_hide_all_shifts_menu')) {
        $rules[] = '#adminmenu li:has(> a[href="edit.php?post_type=eventadmin_shift"])';
    }

    if (get_option('eventadmin_hide_add_shift_menu')) {
        $rules[] = '#adminmenu li:has(> a[href="post-new.php?post_type=eventadmin_shift"])';
    }

    if (get_option('eventadmin_hide_departments_menu')) {
        // WordPress builds this particular submenu entry's URL with an HTML-escaped "&amp;"
        // (unlike the two plain-string slugs above), so match on the path prefix instead of
        // the full string — the browser decodes the entity before CSS attribute matching.
        $rules[] = '#adminmenu li:has(> a[href^="edit-tags.php?taxonomy=eventadmin_shift_category"])';
    }

    if (!$rules) {
        return;
    }

    echo '<style>' . implode(',', $rules) . '{display:none!important;}</style>';
}

add_action('admin_head', 'eventadmin_hide_shift_menu_items_css');

/**
 * Keeps the "Shifts" top-level menu icon (see the 'menu_icon' in eventadmin_register_shift_post_type()
 * / includes/post-types.php) at full opacity at all times. WordPress dims every non-current
 * menu item's <img> icon to opacity:.6 at rest (the same treatment its own dashicon sprites
 * get), which is fine for those — a thin monochrome glyph dimming to grey barely changes how
 * it reads — but our icon was specifically recolored (see the menu_icon comment) so its
 * checkmark cutout stays legible at any opacity; there's no reason to still dim it, so this
 * overrides that one rule for just this one menu item's icon rather than sidebar-wide.
 *
 * "menu-icon-eventadmin_shift" is a stable class WordPress itself adds to this <li> (derived
 * from the post type slug), present regardless of whether "Shifts" is the current section.
 *
 * @return void
 */
function eventadmin_shift_menu_icon_full_opacity_css(): void
{
    echo '<style>#adminmenu .menu-icon-eventadmin_shift .wp-menu-image img{opacity:1!important;}</style>';
}

add_action('admin_head', 'eventadmin_shift_menu_icon_full_opacity_css');

/**
 * Displays the Overview page — dashboard stats only, no tabs (there's only one view here).
 *
 * @return void
 */
function eventadmin_admin_overview_page(): void
{
    eventadmin_render_overview_page(
        'eventadmin-overview',
        ['dashboard'],
        'dashboard',
        esc_html__('EventAdmin Overview', 'eventadmin-volunteer-management')
    );
}

/**
 * Displays the Manager page — Timeline/Table/Cards, tabbed (the day-to-day shift work
 * that used to share a page, and its own set of tabs, with the dashboard stats).
 *
 * @return void
 */
function eventadmin_admin_shift_manager_page(): void
{
    eventadmin_render_overview_page(
        'eventadmin-shift-manager',
        ['timeline', 'table', 'cards'],
        'timeline',
        esc_html__('EventAdmin Manager', 'eventadmin-volunteer-management')
    );
}

/**
 * Enqueue scripts and styles for the admin page
 * @return void
 */
function eventadmin_admin_enqueue_dashboard_scripts(): void
{
    $screen = get_current_screen();
    $allowed_screens = ['eventadmin_shift_page_eventadmin-overview', 'eventadmin_shift_page_eventadmin-shift-manager'];
    if (!in_array($screen->id, $allowed_screens, true)) return;

    wp_enqueue_script(
        'chart-js',
        plugin_dir_url(__FILE__) . '../../assets/js/chart.umd.min.js',
        [],
        '4.5.0',
        true
    );

    $admin_charts_js_path = plugin_dir_path(__FILE__) . '../../assets/js/admin-charts.js';
    wp_enqueue_script(
        'eventadmin-admin-charts',
        plugin_dir_url(__FILE__) . '../../assets/js/admin-charts.js',
        ['chart-js'],
        file_exists($admin_charts_js_path) ? filemtime($admin_charts_js_path) : null,
        true
    );

    $admin_dashboard_css_path = plugin_dir_path(__FILE__) . '../../assets/css/admin-dashboard.css';
    wp_enqueue_style(
        'eventadmin-admin-dashboard',
        plugin_dir_url(__FILE__) . '../../assets/css/admin-dashboard.css',
        [],
        file_exists($admin_dashboard_css_path) ? filemtime($admin_dashboard_css_path) : null
    );
}

add_action('admin_enqueue_scripts', 'eventadmin_admin_enqueue_dashboard_scripts');

/**
 * Registers the "EventAdmin Volunteer Management" wp-admin Dashboard widget — a compact,
 * chart-free glance at the same KPI numbers and "Recent activity" feed as the Overview
 * page's Dashboard tab, so they surface right where an admin lands after login instead of
 * only on a page they have to remember to visit. Gated behind the same capabilities that
 * gate the Overview/Volunteers pages themselves, so it only appears for someone who could
 * already see this data there.
 *
 * Stats are computed once here (rather than inside the render callback) so the count that
 * matters most — shifts still missing a required volunteer — can lead the widget's own
 * title bar, visible even when the widget is collapsed; the same numbers are then handed
 * to the render callback via $callback_args instead of being recomputed.
 *
 * @return void
 */
function eventadmin_register_dashboard_widget(): void
{
    if (!current_user_can('eventadmin_manage_shifts') && !current_user_can('eventadmin_manage_volunteers')) {
        return;
    }

    $total_users = count(get_users(['role' => 'eventadmin_volunteer']));
    $stats       = eventadmin_calculate_dashboard_stats($total_users, 3);

    $plugin_title = esc_html__('EventAdmin – Volunteer Management', 'eventadmin-volunteer-management');
    $widget_title = $stats['required_open_shifts'] > 0
        ? $stats['required_open_shifts'] . ' ' . esc_html__('Open (required)', 'eventadmin-volunteer-management') . ' — ' . $plugin_title
        : $plugin_title;

    wp_add_dashboard_widget(
        'eventadmin_volunteer_management_dashboard_widget',
        $widget_title,
        'eventadmin_render_dashboard_widget',
        null,
        ['total_users' => $total_users, 'stats' => $stats]
    );
}

add_action('wp_dashboard_setup', 'eventadmin_register_dashboard_widget');

/**
 * Renders the Dashboard widget's content: a flat, narrow-width-friendly KPI row (no
 * shadowed cards — the Overview page's own .eventadmin-dashboard-box styling reads as too
 * heavy once squeezed into a ~350px widget column), a "Next shifts" mini-agenda (the
 * soonest few upcoming shifts, each flagging its own still-needed count so the single most
 * urgent gap surfaces even if it's not the very next shift chronologically), the same
 * "Recent activity" feed/toggle used on the Overview page, and a link into the full page.
 *
 * @param mixed $post
 * @param array{args: array{total_users: int, stats: array}} $box
 * @return void
 */
function eventadmin_render_dashboard_widget($post, array $box): void
{
    $total_users = $box['args']['total_users'];
    $stats       = $box['args']['stats'];

    echo '<div class="eventadmin-widget-stats">';
    $flat_stats = [
        esc_html__('Open (required)', 'eventadmin-volunteer-management')        => [$stats['required_open_shifts'], true],
        esc_html__('Upcoming shifts', 'eventadmin-volunteer-management')         => [$stats['total_shifts'], false],
        rtrim(esc_html__('Volunteers without upcoming shift:', 'eventadmin-volunteer-management'), ':') => [$stats['volunteers_without_shift'], false],
    ];
    foreach ($flat_stats as $label => [$value, $is_urgent_metric]) {
        $urgent_class = ($is_urgent_metric && $value > 0) ? ' eventadmin-widget-stat-urgent' : '';
        echo '<div class="eventadmin-widget-stat' . esc_attr($urgent_class) . '">';
        echo '<span class="eventadmin-widget-stat-value">' . esc_html($value) . '</span>';
        echo '<span class="eventadmin-widget-stat-label">' . $label . '</span>';
        echo '</div>';
    }
    echo '</div>';

    if (!empty($stats['next_shifts'])) {
        echo '<div class="eventadmin-widget-section-title">' . esc_html__('Next shifts', 'eventadmin-volunteer-management') . '</div>';
        echo '<ul class="eventadmin-widget-agenda">';
        foreach ($stats['next_shifts'] as $shift) {
            $needs_help = $shift['required_open'] > 0;
            echo '<li class="' . ($needs_help ? 'eventadmin-widget-agenda-urgent' : '') . '">';
            echo '<span class="eventadmin-widget-agenda-title"><a href="' . esc_url((string) get_edit_post_link($shift['id'])) . '">' . esc_html($shift['title']) . '</a>'
                . '<br><span class="eventadmin-widget-agenda-when">' . esc_html(eventadmin_get_formatted_zeitraum($shift['start'], $shift['end'])) . '</span></span>';
            echo '<span class="eventadmin-widget-agenda-meta">' . ($needs_help
                ? esc_html(eventadmin_open_positions_format_count($shift['required_open']))
                : esc_html($shift['assigned'] . '/' . $shift['max'])) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
    }

    eventadmin_render_activity_feed();

    echo '<p class="eventadmin-widget-footer-link"><a href="' . esc_url(admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-overview')) . '">' . esc_html__('View full Overview', 'eventadmin-volunteer-management') . '</a></p>';
}

/**
 * Enqueues the Dashboard widget's own flat, narrow-width layout styles — kept in its own
 * stylesheet (dashboard-menu.css) rather than admin-dashboard.css, whose .eventadmin-
 * dashboard-box "card" styling this widget deliberately does not reuse — plus the activity-
 * feed styles, handled by eventadmin_enqueue_volunteer_profile_styles() in user-profile.php,
 * whose allowed screens include 'dashboard'. Nothing here needs Chart.js.
 *
 * @param string $hook
 * @return void
 */
function eventadmin_enqueue_dashboard_widget_styles(string $hook): void
{
    if ($hook !== 'index.php') {
        return;
    }

    $dashboard_widget_css_path = plugin_dir_path(__FILE__) . '../../assets/css/dashboard-menu.css';
    wp_enqueue_style(
        'eventadmin-dashboard-widget',
        plugin_dir_url(__FILE__) . '../../assets/css/dashboard-menu.css',
        [],
        file_exists($dashboard_widget_css_path) ? filemtime($dashboard_widget_css_path) : null
    );
}

add_action('admin_enqueue_scripts', 'eventadmin_enqueue_dashboard_widget_styles');

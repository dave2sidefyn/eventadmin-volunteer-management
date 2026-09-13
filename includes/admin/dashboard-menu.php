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

    wp_enqueue_script(
        'eventadmin-admin-charts',
        plugin_dir_url(__FILE__) . '../../assets/js/admin-charts.js',
        ['chart-js'],
        '1.0',
        true
    );

    wp_enqueue_style(
        'eventadmin-admin-dashboard',
        plugin_dir_url(__FILE__) . '../../assets/css/admin-dashboard.css',
        [],
        '1.0'
    );
}

add_action('admin_enqueue_scripts', 'eventadmin_admin_enqueue_dashboard_scripts');

<?php
/**
 * EventAdmin Volunteer Management - Enabling custom post types and taxonomies for shifts
 * Reuse of shift post types and departments for the management of volunteers
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}
function eventadmin_register_post_types(): void
{
    register_post_type('eventadmin_shift', [
        'labels' => [
            'name' => esc_html__('Shifts', 'eventadmin-volunteer-management'),
            'singular_name' => esc_html__('Shift', 'eventadmin-volunteer-management'),
            'add_new' => esc_html__('Add Shift', 'eventadmin-volunteer-management'),
            'add_new_item' => esc_html__('Add New Shift', 'eventadmin-volunteer-management'),
            'edit_item' => esc_html__('Edit Shift', 'eventadmin-volunteer-management'),
            'new_item' => esc_html__('New Shift', 'eventadmin-volunteer-management'),
            'view_item' => esc_html__('View Shift', 'eventadmin-volunteer-management'),
            'search_items' => esc_html__('Search Shift', 'eventadmin-volunteer-management'),
            'not_found' => esc_html__('No shift found', 'eventadmin-volunteer-management'),
            'not_found_in_trash' => esc_html__('No shift in trash', 'eventadmin-volunteer-management'),
            'all_items' => esc_html__('All Shifts', 'eventadmin-volunteer-management'),
            'menu_name' => esc_html__('Shifts', 'eventadmin-volunteer-management'),
            'name_admin_bar' => esc_html__('Shift', 'eventadmin-volunteer-management')
        ],
        'public' => false,              // Not publicly queryable
        'publicly_queryable' => false,  // Prevent direct URL access
        'exclude_from_search' => true,  // Not included in site search
        'show_ui' => true,              // Still visible in backend
        'show_in_menu' => true,
        'menu_position' => 20,
        'menu_icon' => 'dashicons-calendar-alt',
        'supports' => ['title', 'editor'],
        'has_archive' => false,
        'rewrite' => false,             // Disable pretty permalinks
        'show_in_rest' => true          // Keep available in Gutenberg/REST
    ]);


    register_taxonomy('eventadmin_shift_category', 'eventadmin_shift', [
        'labels' => [
            'name' => esc_html__('Departments', 'eventadmin-volunteer-management'),
            'singular_name' => esc_html__('Department', 'eventadmin-volunteer-management'),
            'search_items' => esc_html__('Search Departments', 'eventadmin-volunteer-management'),
            'all_items' => esc_html__('All Departments', 'eventadmin-volunteer-management'),
            'parent_item' => esc_html__('Parent Department', 'eventadmin-volunteer-management'),
            'parent_item_colon' => esc_html__('Parent Department:', 'eventadmin-volunteer-management'),
            'edit_item' => esc_html__('Edit Department', 'eventadmin-volunteer-management'),
            'update_item' => esc_html__('Update Department', 'eventadmin-volunteer-management'),
            'add_new_item' => esc_html__('Add New Department', 'eventadmin-volunteer-management'),
            'new_item_name' => esc_html__('New Department Name', 'eventadmin-volunteer-management'),
            'menu_name' => esc_html__('Departments', 'eventadmin-volunteer-management')
        ],
        'public' => false,
        'publicly_queryable' => false,
        'hierarchical' => true,
        'show_ui' => true,
        'show_admin_column' => true,
        'rewrite' => false,
        'show_in_rest' => true
    ]);

}

add_action('init', 'eventadmin_register_post_types');

add_action('template_redirect', function () {
    if (is_singular('eventadmin_shift') && !is_user_logged_in()) {
        wp_redirect(home_url());
        exit;
    }
});

/**
 * Shifts are a standard custom post type, so WordPress's native Tools > Export
 * already includes them (with their departments and shift meta) in the WXR file,
 * and Tools > Import > WordPress can recreate them on another site.
 *
 * The one thing that export shouldn't carry across sites is who signed up for a
 * shift: assigned_user_* meta holds another site's user IDs, which are meaningless
 * (or, worse, coincidentally valid) on the target site. Strip it from the export.
 */
add_filter('wxr_export_skip_postmeta', function (bool $skip, string $meta_key): bool {
    return $skip || str_starts_with($meta_key, 'assigned_user_');
}, 10, 2);

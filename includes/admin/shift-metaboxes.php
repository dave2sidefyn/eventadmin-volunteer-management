<?php
/**
 * EventAdmin Volunteer Management - Metabox for shifts
 * Number of required volunteers, start and end time of the shift can be edited here.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

use JetBrains\PhpStorm\NoReturn;

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Returns the roles allowed in the organizer user selector.
 *
 * @return string[]
 */
function eventadmin_get_allowed_organizer_roles(): array
{
    $roles = ['administrator', 'editor', 'author'];
    $roles = apply_filters('eventadmin_allowed_organizer_roles', $roles);
    return is_array($roles) ? array_values(array_unique(array_filter($roles, 'is_string'))) : ['administrator', 'editor', 'author'];
}

/**
 * Add metabox for shifts
 * Adds the metabox for shift details in the admin area
 */
function eventadmin_shift_meta_boxes(): void
{
    add_meta_box(
        'eventadmin_shift_meta',
        esc_html__('Shift details', 'eventadmin-volunteer-management'),
        'eventadmin_shift_meta_callback',
        'eventadmin_shift');
}

add_action('add_meta_boxes', 'eventadmin_shift_meta_boxes');

/**
 * Callback function for the metabox
 * Shows the fields for max volunteers, start and end time
 *
 * @param WP_Post $post The current post object
 */
function eventadmin_shift_meta_callback(WP_Post $post): void
{
    // Add nonce field
    wp_nonce_field('eventadmin_shift_meta_nonce_action', 'eventadmin_shift_meta_nonce_field');

    $min                = get_post_meta($post->ID, 'min_volunteers', true);
    $max                = get_post_meta($post->ID, 'max_volunteers', true);
    $start              = get_post_meta($post->ID, 'shift_start', true);
    $end                = get_post_meta($post->ID, 'shift_end', true);
    $organizer_user_id  = absint(get_post_meta($post->ID, 'shift_organizer_user_id', true));
    $organizer_email    = get_post_meta($post->ID, 'shift_organizer_email', true);
    $organizer_name     = get_post_meta($post->ID, 'shift_organizer_name', true);
    $organizer_users = get_users([
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'role__in'=> eventadmin_get_allowed_organizer_roles(),
    ]);

    echo '<label>' . esc_html__('Minimum volunteers:', 'eventadmin-volunteer-management') . '</label><br>';
    echo '<input type="number" name="min_volunteers" value="' . esc_attr($min) . '" min="0" /><br>';
    echo '<label>' . esc_html__('Number of required volunteers:', 'eventadmin-volunteer-management') . '</label><br>';
    echo '<input type="number" name="max_volunteers" value="' . esc_attr($max) . '" /><br>';
    echo '<label>' . esc_html__('Start time:', 'eventadmin-volunteer-management') . '</label><br>';
    echo '<input type="datetime-local" name="shift_start" value="' . esc_attr($start) . '" /><br>';
    echo '<label>' . esc_html__('End time:', 'eventadmin-volunteer-management') . '</label><br>';
    echo '<input type="datetime-local" name="shift_end" value="' . esc_attr($end) . '" /><br>';

    $global_email = get_option('eventadmin_notification_email', get_bloginfo('admin_email'));
    $global_name  = get_option('eventadmin_notification_email_name', '');

    echo '<label>' . esc_html__('Organizer user:', 'eventadmin-volunteer-management') . '</label><br>';
    echo '<select name="shift_organizer_user_id" style="width:100%;max-width:300px;">';
    echo '<option value="">' . esc_html__('— None —', 'eventadmin-volunteer-management') . '</option>';
    foreach ($organizer_users as $user) {
        $label = $user->display_name;
        if (!empty($user->user_email)) {
            $label .= ' (' . $user->user_email . ')';
        }
        echo '<option value="' . esc_attr($user->ID) . '"' . selected($organizer_user_id, $user->ID, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">' . esc_html__('Only staff-side users are shown here by default. If selected, this user is used as the sender fallback for shift emails unless a custom organizer name/email is entered below.', 'eventadmin-volunteer-management') . '</p>';

    echo '<label>' . esc_html__('Organizer name:', 'eventadmin-volunteer-management') . '</label><br>';
    echo '<input type="text" name="shift_organizer_name" value="' . esc_attr($organizer_name) . '" placeholder="' . esc_attr($global_name) . '" style="width:100%;max-width:300px;" /><br>';
    echo '<label>' . esc_html__('Organizer email:', 'eventadmin-volunteer-management') . '</label><br>';
    echo '<input type="email" name="shift_organizer_email" value="' . esc_attr($organizer_email) . '" placeholder="' . esc_attr($global_email) . '" style="width:100%;max-width:300px;" />';
    echo '<p class="description">' . esc_html__('Leave empty to use the linked organizer user or the global notification sender.', 'eventadmin-volunteer-management') . '</p>';
}

/**
 * Save metadata for shifts
 * Saves the number of volunteers, start and end time when saving a shift post
 *
 * @param int $post_id The current post ID
 */
function eventadmin_save_shift_meta(int $post_id): void
{
    // Check if nonce is set and valid
    if (!isset($_POST['eventadmin_shift_meta_nonce_field']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eventadmin_shift_meta_nonce_field'])), 'eventadmin_shift_meta_nonce_action')) {
        return;
    }

    // Exclude autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    // Check user permission
    if (!current_user_can('edit_post', $post_id)) return;

    if (isset($_POST['min_volunteers'])) {
        update_post_meta($post_id, 'min_volunteers', absint(wp_unslash($_POST['min_volunteers'])));
    }
    if (isset($_POST['max_volunteers'])) {
        update_post_meta($post_id, 'max_volunteers', absint(wp_unslash($_POST['max_volunteers'])));
    }
    $old_start = (string) get_post_meta($post_id, 'shift_start', true);
    $old_end   = (string) get_post_meta($post_id, 'shift_end', true);

    if (isset($_POST['shift_start'])) {
        update_post_meta($post_id, 'shift_start', sanitize_text_field(wp_unslash($_POST['shift_start'])));
    }
    if (isset($_POST['shift_end'])) {
        update_post_meta($post_id, 'shift_end', sanitize_text_field(wp_unslash($_POST['shift_end'])));
    }
    if (isset($_POST['shift_organizer_name'])) {
        $org_name = sanitize_text_field(wp_unslash($_POST['shift_organizer_name']));
        if ($org_name) {
            update_post_meta($post_id, 'shift_organizer_name', $org_name);
        } else {
            delete_post_meta($post_id, 'shift_organizer_name');
        }
    }
    if (isset($_POST['shift_organizer_user_id'])) {
        $organizer_user_id = absint(wp_unslash($_POST['shift_organizer_user_id']));
        if ($organizer_user_id > 0 && get_userdata($organizer_user_id)) {
            update_post_meta($post_id, 'shift_organizer_user_id', $organizer_user_id);
        } else {
            delete_post_meta($post_id, 'shift_organizer_user_id');
        }
    }
    if (isset($_POST['shift_organizer_email'])) {
        $org_email = sanitize_email(wp_unslash($_POST['shift_organizer_email']));
        if ($org_email) {
            update_post_meta($post_id, 'shift_organizer_email', $org_email);
        } else {
            delete_post_meta($post_id, 'shift_organizer_email');
        }
    }

    $new_start = (string) get_post_meta($post_id, 'shift_start', true);
    $new_end   = (string) get_post_meta($post_id, 'shift_end', true);
    if ($old_start !== $new_start || $old_end !== $new_end) {
        eventadmin_clear_shift_reminder_markers($post_id);
    }
}

add_action('save_post', 'eventadmin_save_shift_meta');

// Duplicate button
function eventadmin_add_duplicate_button($actions, $post)
{
    if ($post->post_type == 'eventadmin_shift') {
        $url = wp_nonce_url(admin_url('admin-post.php?action=duplicate_shift&post=' . $post->ID), 'duplicate_shift_' . $post->ID);
        $actions['duplicate'] = '<a href="' . esc_url($url) . '">' . esc_html__('Duplicate', 'eventadmin-volunteer-management') . '</a>';
    }
    return $actions;
}

add_filter('post_row_actions', 'eventadmin_add_duplicate_button', 10, 2);

/**
 * Function to duplicate shifts
 * This function is called when the "Duplicate" button is clicked.
 * It creates a copy of the shift and redirects the user to the edit page of the new shift.
 *
 * @return void
 */
#[NoReturn] function eventadmin_duplicate_shift(): void
{

    if (!current_user_can('edit_posts') || !isset($_REQUEST['_wpnonce']) || !isset($_GET['post']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'duplicate_shift_' . absint(wp_unslash($_GET['post'])))) {
        wp_die(esc_html__('Not allowed', 'eventadmin-volunteer-management'));
    }
    $post_id = absint($_GET['post']);
    $post = get_post($post_id);
    $new_id = wp_insert_post([
        'post_type' => $post->post_type,
        'post_status' => 'draft',
        'post_title' => $post->post_title . esc_html__(' (Copy)', 'eventadmin-volunteer-management'),
        'post_content' => $post->post_content
    ]);

    $fields = ['min_volunteers', 'max_volunteers', 'shift_start', 'shift_end', 'shift_organizer_user_id', 'shift_organizer_name', 'shift_organizer_email'];
    foreach ($fields as $field) {
        update_post_meta($new_id, $field, get_post_meta($post_id, $field, true));
    }

    // Copy category (department)
    $terms = wp_get_post_terms($post_id, 'eventadmin_shift_category', ['fields' => 'ids']);
    if (!empty($terms) && !is_wp_error($terms)) {
        wp_set_object_terms($new_id, $terms, 'eventadmin_shift_category');
    }

    wp_safe_redirect(admin_url('post.php?action=edit&post=' . $new_id));
    exit;
}

add_action('admin_post_duplicate_shift', 'eventadmin_duplicate_shift');

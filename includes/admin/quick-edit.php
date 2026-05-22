<?php
/**
 * EventAdmin Volunteer Management - Standard Quick Edit modifications
 * Allows editing times and number of volunteers directly in Quick Edit
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Adjust Quick Edit Box for shifts
 * Adds fields for start time, end time and max. volunteers
 * @param string $column_name The column name
 * @param string $post_type The post type
 * @return void
 */
function eventadmin_quick_edit_custom_box(string $column_name, string $post_type): void
{
    if ($post_type !== 'eventadmin_shift') return;
    if ($column_name !== 'shift_belegt') return;
    ?>

    <fieldset class="inline-edit-col-right">
        <div class="inline-edit-col">
            <?php wp_nonce_field('shift_quick_edit_nonce_action', 'shift_quick_edit_nonce_field'); ?>
            <label>
                <span><?php esc_html_e('Start time', 'eventadmin-volunteer-management') ?></span>
                <input type="datetime-local" name="shift_start" class="shift_start_field" value="">
            </label><br>
            <label>
                <span><?php esc_html_e('End time', 'eventadmin-volunteer-management') ?></span>
                <input type="datetime-local" name="shift_end" class="shift_end_field" value="">
            </label><br>
            <label>
                <span><?php esc_html_e('Min. Volunteers', 'eventadmin-volunteer-management') ?></span>
                <input type="number" name="min_volunteers" class="min_volunteers_field" value="" min="0">
            </label><br>
            <label>
                <span><?php esc_html_e('Max. Volunteers', 'eventadmin-volunteer-management') ?></span>
                <input type="number" name="max_volunteers" class="max_volunteers_field" value="" min="1">
            </label>
        </div>
    </fieldset>
    <?php
}

add_action('quick_edit_custom_box', 'eventadmin_quick_edit_custom_box', 10, 2);

/**
 * Adds the columns start time, end time and max. volunteers to the shift overview
 * @param array $columns The current columns
 * @return array The updated columns
 */
function eventadmin_manage_edit_shift_columns(array $columns): array
{
    $columns['shift_start'] = esc_html__('Start', 'eventadmin-volunteer-management');
    $columns['shift_end'] = esc_html__('End', 'eventadmin-volunteer-management');
    $columns['min_volunteers'] = esc_html__('Min. Volunteers', 'eventadmin-volunteer-management');
    $columns['max_volunteers'] = esc_html__('Max. Volunteers', 'eventadmin-volunteer-management');
    return $columns;
}

add_filter('manage_edit-eventadmin_shift_columns', 'eventadmin_manage_edit_shift_columns');

/**
 * Adds the new columns to the shift posts
 *
 * @param string $column The column name
 * @param int $post_id The current post ID
 */
function eventadmin_manage_shift_posts_custom_column(string $column, int $post_id): void
{
    if ($column === 'shift_belegt') {
        $max = get_post_meta($post_id, 'max_volunteers', true);
        $count = eventadmin_count_assignments($post_id);
        echo esc_html($count . '/' . $max);
    }
    if ($column === 'shift_zeitraum') {
        $start = get_post_meta($post_id, 'shift_start', true);
        $end = get_post_meta($post_id, 'shift_end', true);
        echo esc_html(eventadmin_get_formatted_zeitraum($start, $end));
    }
    // Hidden columns used by quick-edit JS to pre-populate fields
    if ($column === 'shift_start') {
        echo esc_html(get_post_meta($post_id, 'shift_start', true));
    }
    if ($column === 'shift_end') {
        echo esc_html(get_post_meta($post_id, 'shift_end', true));
    }
    if ($column === 'min_volunteers') {
        echo esc_html(get_post_meta($post_id, 'min_volunteers', true));
    }
    if ($column === 'max_volunteers') {
        echo esc_html(get_post_meta($post_id, 'max_volunteers', true));
    }
}

// Fill column content
add_action('manage_eventadmin_shift_posts_custom_column', 'eventadmin_manage_shift_posts_custom_column', 10, 2);


/**
 * Processes the columns for shift posts in the admin area
 *
 * @param array $columns
 * @return array
 */
function eventadmin_manage_shift_posts_columns(array $columns): array
{
    $columns['shift_zeitraum'] = esc_html__('Period', 'eventadmin-volunteer-management');
    $columns['shift_belegt'] = esc_html__('Filled', 'eventadmin-volunteer-management');
    unset($columns['date']);
    return $columns;
}

// Add columns
add_filter('manage_eventadmin_shift_posts_columns', 'eventadmin_manage_shift_posts_columns');

/**
 * Save Quick Edit fields
 * Saves start time, end time and max. volunteers when saving a shift post
 * @param int $post_id The current post ID
 * @return void
 */
function eventadmin_save_quick_edit_post_shift(int $post_id): void
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    // Check nonce
    if (
        !isset($_POST['shift_quick_edit_nonce_field']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['shift_quick_edit_nonce_field'])), 'shift_quick_edit_nonce_action')
    ) {
        return; // no permission or tampered
    }

    // Check permission
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $old_start = (string) get_post_meta($post_id, 'shift_start', true);
    $old_end   = (string) get_post_meta($post_id, 'shift_end', true);

    if (isset($_POST['shift_start'])) {
        update_post_meta($post_id, 'shift_start', sanitize_text_field(wp_unslash($_POST['shift_start'])));
    }
    if (isset($_POST['shift_end'])) {
        update_post_meta($post_id, 'shift_end', sanitize_text_field(wp_unslash($_POST['shift_end'])));
    }
    if (isset($_POST['min_volunteers'])) {
        update_post_meta($post_id, 'min_volunteers', absint(wp_unslash($_POST['min_volunteers'])));
    }
    if (isset($_POST['max_volunteers'])) {
        update_post_meta($post_id, 'max_volunteers', absint(wp_unslash($_POST['max_volunteers'])));
    }

    $new_start = (string) get_post_meta($post_id, 'shift_start', true);
    $new_end   = (string) get_post_meta($post_id, 'shift_end', true);
    if ($old_start !== $new_start || $old_end !== $new_end) {
        eventadmin_clear_shift_reminder_markers($post_id);
    }
}

add_action('save_post_eventadmin_shift', 'eventadmin_save_quick_edit_post_shift');

/**
 * Enqueue scripts and styles for Quick Edit
 * @param $hook
 * @return void
 */
function eventadmin_enqueue_quick_edit_scripts($hook): void
{
    global $post_type;
    if ($hook === 'edit.php' && $post_type === 'eventadmin_shift') {
        wp_enqueue_script(
            'eventadmin-quick-edit',
            plugin_dir_url(__FILE__) . '../../assets/js/quick-edit.js',
            ['jquery'],
            '1.0',
            true
        );

        wp_enqueue_style(
            'eventadmin-quick-edit',
            plugin_dir_url(__FILE__) . '../../assets/css/quick-edit.css',
            [],
            '1.0'
        );
    }
}

add_action('admin_enqueue_scripts', 'eventadmin_enqueue_quick_edit_scripts');

/**
 * Hides the raw data columns (used only for quick-edit JS) by default
 */
function eventadmin_default_hidden_columns(array $hidden, WP_Screen $screen): array
{
    if (isset($screen->post_type) && $screen->post_type === 'eventadmin_shift') {
        $hidden = array_merge($hidden, ['shift_start', 'shift_end', 'min_volunteers', 'max_volunteers']);
    }
    return $hidden;
}

add_filter('default_hidden_columns', 'eventadmin_default_hidden_columns', 10, 2);

/**
 * Makes the shift period column sortable by start time
 */
function eventadmin_sortable_columns(array $columns): array
{
    $columns['shift_zeitraum'] = 'shift_start';
    return $columns;
}

add_filter('manage_edit-eventadmin_shift_sortable_columns', 'eventadmin_sortable_columns');

/**
 * Handles sorting by shift_start meta key in the admin list
 */
function eventadmin_sort_by_shift_start(WP_Query $query): void
{
    if (!is_admin() || !$query->is_main_query()) return;
    if ($query->get('post_type') !== 'eventadmin_shift') return;
    if ($query->get('orderby') === 'shift_start') {
        $query->set('meta_key', 'shift_start');
        $query->set('orderby', 'meta_value');
    }
}

add_action('pre_get_posts', 'eventadmin_sort_by_shift_start');

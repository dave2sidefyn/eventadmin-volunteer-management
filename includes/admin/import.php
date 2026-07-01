<?php
/**
 * EventAdmin Volunteer Management - Shift Categories Import
 * Allows importing sample data for shift categories.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Adds a menu item in the admin area to import shift categories
 * @return void
 */
function eventadmin_import_admin_menu(): void
{
    add_submenu_page(
        'tools.php',
        esc_html__('EventAdmin Data', 'eventadmin-volunteer-management'),
        esc_html__('EventAdmin Data', 'eventadmin-volunteer-management'),
        'manage_options',
        'eventadmin-import',
        'eventadmin_render_import_page'
    );
}

add_action('admin_menu', 'eventadmin_import_admin_menu');

/**
 * Renders the import page for shift categories
 * @return void
 */
function eventadmin_render_import_page(): void
{
    ?>
    <div class="wrap">
        <h1><?php esc_attr_e('Import a setup from another site', 'eventadmin-volunteer-management'); ?></h1>
        <p><?php esc_html_e('Shifts and departments are regular WordPress posts and taxonomy terms, so you can bring over another site\'s setup using WordPress\'s own export/import tools:', 'eventadmin-volunteer-management'); ?></p>
        <ol>
            <li><?php echo wp_kses(sprintf(
                /* translators: %s: link to the Tools > Export screen */
                __('On the source site, go to %s, choose "Shifts" and download the file.', 'eventadmin-volunteer-management'),
                '<a href="' . esc_url(admin_url('export.php')) . '">' . esc_html__('Tools > Export', 'eventadmin-volunteer-management') . '</a>'
            ), ['a' => ['href' => []]]); ?></li>
            <li><?php echo wp_kses(sprintf(
                /* translators: %s: link to the Tools > Import screen */
                __('On this site, make sure this plugin is active, then go to %s, install the "WordPress" importer if needed, and upload the file.', 'eventadmin-volunteer-management'),
                '<a href="' . esc_url(admin_url('import.php')) . '">' . esc_html__('Tools > Import', 'eventadmin-volunteer-management') . '</a>'
            ), ['a' => ['href' => []]]); ?></li>
        </ol>
        <p><?php esc_html_e('Departments (with their color and hierarchy) are carried over automatically for any department that has at least one shift. Volunteer sign-ups are not included in the export, since another site\'s user IDs would be meaningless here.', 'eventadmin-volunteer-management'); ?></p>
    </div>

    <div class="wrap">
        <h1><?php esc_attr_e('Import demo data', 'eventadmin-volunteer-management'); ?></h1>
        <form method="post" action="">
            <?php wp_nonce_field('eventadmin_import_shift_cats', 'eventadmin_import_nonce'); ?>
            <input type="hidden" name="eventadmin_import_action" value="1">
            <p><input type="submit" class="button button-primary"
                      value="<?php esc_attr_e('Start import', 'eventadmin-volunteer-management'); ?>"></p>
        </form>
    </div>

    <div class="wrap">
        <h1><?php esc_attr_e('Delete all data', 'eventadmin-volunteer-management'); ?></h1>
        <form method="post" action=""
              onsubmit="return confirm('Are you sure you want to delete ALL shifts and departments?');">
            <?php wp_nonce_field('eventadmin_delete_shift_cats', 'eventadmin_delete_nonce'); ?>
            <input type="hidden" name="eventadmin_delete_action" value="1">
            <p><input type="submit" class="button button-secondary"
                      value="<?php esc_attr_e('Delete all', 'eventadmin-volunteer-management'); ?>"></p>
        </form>
    </div>

    <div class="wrap">
        <h1><?php esc_attr_e('Clean up orphaned assignments', 'eventadmin-volunteer-management'); ?></h1>
        <p><?php esc_html_e('Removes shift assignments for users that no longer exist in WordPress. This can happen when a user was deleted without the plugin\'s cleanup hook running.', 'eventadmin-volunteer-management'); ?></p>
        <?php
        $orphan_count = eventadmin_count_orphaned_assignments();
        if ($orphan_count === 0) {
            echo '<p><em>' . esc_html__('No orphaned assignments found.', 'eventadmin-volunteer-management') . '</em></p>';
        } else {
            echo '<p><strong>' . sprintf(
                /* translators: %d: number of orphaned assignments found */
                esc_html__('%d orphaned assignment(s) found.', 'eventadmin-volunteer-management'),
                $orphan_count
            ) . '</strong></p>';
        }
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('eventadmin_cleanup_orphans', 'eventadmin_cleanup_nonce'); ?>
            <input type="hidden" name="eventadmin_cleanup_action" value="1">
            <p><input type="submit" class="button button-secondary"
                      <?php if ($orphan_count === 0) echo 'disabled'; ?>
                      value="<?php esc_attr_e('Remove orphaned assignments', 'eventadmin-volunteer-management'); ?>"></p>
        </form>
    </div>
    <?php

    if (isset($_GET['import']) && $_GET['import'] === 'success') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Departments were imported successfully.', 'eventadmin-volunteer-management') . '</p></div>';
    }

    if (isset($_GET['deleted']) && $_GET['deleted'] === 'success') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('All departments and shifts have been deleted.', 'eventadmin-volunteer-management') . '</p></div>';
    }

    if (isset($_GET['cleanup']) && $_GET['cleanup'] === 'success') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $removed = (int) ($_GET['removed'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
            /* translators: %d: number of removed orphaned assignments */
            esc_html__('%d orphaned assignment(s) removed.', 'eventadmin-volunteer-management'),
            $removed
        ) . '</p></div>';
    }
}

/**
 * Handles the import of shift categories
 * Called when the form is submitted
 *
 * @return void
 */
function eventadmin_import_admin_init(): void
{
    if (!current_user_can('manage_options')) return;

    if (!empty($_POST['eventadmin_import_action'])) {

        if (!isset($_POST['eventadmin_import_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eventadmin_import_nonce'])), 'eventadmin_import_shift_cats')) {
            wp_die(esc_html__('Security check failed.', 'eventadmin-volunteer-management'));
        }

        eventadmin_import_shift_categories();

        wp_safe_redirect(admin_url('tools.php?page=eventadmin-import&import=success'));
        exit;
    }

    // Handle admin notice import question
    if (isset($_GET['eventadmin_import_demo'])) {
        if (!isset($_GET['eventadmin_import_demo_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['eventadmin_import_demo_nonce'])), 'eventadmin_import_demo')) {
            wp_die(esc_html__('Security check failed.', 'eventadmin-volunteer-management'));
        }

        if ($_GET['eventadmin_import_demo'] === 'yes') {
            eventadmin_import_shift_categories();
            add_option('eventadmin_import_demo_data_done', true);
            wp_safe_redirect(remove_query_arg('eventadmin_import_demo'));
            exit;
        } elseif ($_GET['eventadmin_import_demo'] === 'no') {
            add_option('eventadmin_import_demo_data_done', true);
            wp_safe_redirect(remove_query_arg('eventadmin_import_demo'));
            exit;
        }
    }

    if (!empty($_POST['eventadmin_delete_action'])) {
        if (!isset($_POST['eventadmin_delete_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eventadmin_delete_nonce'])), 'eventadmin_delete_shift_cats')) {
            wp_die(esc_html__('Security check failed.', 'eventadmin-volunteer-management'));
        }

        eventadmin_delete_all_shifts_and_categories();

        wp_safe_redirect(admin_url('tools.php?page=eventadmin-import&deleted=success'));
        exit;
    }

    if (!empty($_POST['eventadmin_cleanup_action'])) {
        if (!isset($_POST['eventadmin_cleanup_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eventadmin_cleanup_nonce'])), 'eventadmin_cleanup_orphans')) {
            wp_die(esc_html__('Security check failed.', 'eventadmin-volunteer-management'));
        }

        $removed = eventadmin_cleanup_orphaned_assignments();

        wp_safe_redirect(admin_url('tools.php?page=eventadmin-import&cleanup=success&removed=' . $removed));
        exit;
    }
}

add_action('admin_init', 'eventadmin_import_admin_init');

/**
 * Deletes all shifts and categories
 * @return void
 */
function eventadmin_delete_all_shifts_and_categories(): void
{
    // Delete all shifts
    $shifts = get_posts([
        'post_type' => 'eventadmin_shift',
        'numberposts' => -1,
        'post_status' => 'any',
    ]);

    foreach ($shifts as $shift) {
        wp_delete_post($shift->ID, true);
    }

    // Delete all categories
    $terms = get_terms([
        'taxonomy' => 'eventadmin_shift_category',
        'hide_empty' => false,
    ]);

    foreach ($terms as $term) {
        wp_delete_term($term->term_id, 'eventadmin_shift_category');
    }
}


/**
 * Counts assigned_user_* postmeta entries that reference non-existent users.
 *
 * @return int Number of orphaned assignments found
 */
function eventadmin_count_orphaned_assignments(): int
{
    $shifts = get_posts([
        'post_type'   => 'eventadmin_shift',
        'numberposts' => -1,
        'fields'      => 'ids',
        'post_status' => 'any',
    ]);

    $count = 0;

    foreach ($shifts as $shift_id) {
        $meta = get_post_meta($shift_id);
        foreach ($meta as $key => $val) {
            if (str_starts_with($key, 'assigned_user_')) {
                if (!get_userdata((int)$val[0])) {
                    $count++;
                }
            }
        }
    }

    return $count;
}

/**
 * Removes assigned_user_* postmeta entries that reference non-existent users.
 * Returns the number of entries removed.
 *
 * @return int Number of orphaned assignments deleted
 */
function eventadmin_cleanup_orphaned_assignments(): int
{
    $shifts = get_posts([
        'post_type'   => 'eventadmin_shift',
        'numberposts' => -1,
        'fields'      => 'ids',
        'post_status' => 'any',
    ]);

    $removed = 0;

    foreach ($shifts as $shift_id) {
        $meta = get_post_meta($shift_id);
        foreach ($meta as $key => $val) {
            if (str_starts_with($key, 'assigned_user_')) {
                $user_id = (int)$val[0];
                if (!get_userdata($user_id)) {
                    delete_post_meta($shift_id, $key);
                    $removed++;
                }
            }
        }
    }

    return $removed;
}

/**
 * Imports sample shift categories
 * @return void
 */
function eventadmin_import_shift_categories(): void
{
    $categories = [
        esc_html__('Dismantling', 'eventadmin-volunteer-management') => esc_html__('Dismantling of booths, tents, tables, etc. This person should be skilled and have experience in dismantling.', 'eventadmin-volunteer-management'),
        esc_html__('Setup', 'eventadmin-volunteer-management') => esc_html__('Setup of booths, tents, tables, etc. This person should be skilled and have experience in setup.', 'eventadmin-volunteer-management'),
        esc_html__('Merchandise', 'eventadmin-volunteer-management') => esc_html__("Sale of merchandise items such as T-shirts, hoodies, mugs, etc. This person should be friendly and good with customers.", 'eventadmin-volunteer-management'),
        esc_html__('Floater', 'eventadmin-volunteer-management') => esc_html__('Help with various tasks that do not fit into another category. This person should be flexible and willing to take on different tasks.', 'eventadmin-volunteer-management'),
        esc_html__('Trash Hero', 'eventadmin-volunteer-management') => esc_html__('Collecting trash, disposing of waste, ensuring cleanliness. This person should always have a trash bag and gloves.', 'eventadmin-volunteer-management'),
        esc_html__('Bar', 'eventadmin-volunteer-management') => esc_html__('People for the bar, who can also tap beer, are important. This person should be friendly and good with customers.', 'eventadmin-volunteer-management'),
        esc_html__('Deposit', 'eventadmin-volunteer-management') => esc_html__('Return of plates and cups. There should always be at least one person at the deposit stand.', 'eventadmin-volunteer-management'),
        esc_html__('Grill', 'eventadmin-volunteer-management') => esc_html__("- Grilling sausages, possibly also burgers\n", 'eventadmin-volunteer-management'),
    ];

    foreach ($categories as $name => $description) {
        if (!term_exists($name, 'eventadmin_shift_category')) {
            wp_insert_term($name, 'eventadmin_shift_category', ['description' => $description]);
        }
    }

    //Create shifts in the different categories
    $now = new DateTime();
    $shift_date = $now->modify('+1 month')->format('Y-m-d');

    $shifts = [
        esc_html__('Dismantling', 'eventadmin-volunteer-management') => [
            'description' => esc_html__('Volunteers needed for dismantling the event.', 'eventadmin-volunteer-management'),
            'start' => $shift_date . ' 18:00:00',
            'end' => $shift_date . ' 22:00:00',
            'max_volunteers' => 6,
            'shift_category' => esc_html__('Dismantling', 'eventadmin-volunteer-management'),
        ],
        esc_html__('Setup Early Morning', 'eventadmin-volunteer-management') => [
            'description' => esc_html__('Volunteers needed for setting up the event.', 'eventadmin-volunteer-management'),
            'start' => $shift_date . ' 07:00:00',
            'end' => $shift_date . ' 09:00:00',
            'max_volunteers' => 3,
            'shift_category' => esc_html__('Setup', 'eventadmin-volunteer-management'),
        ],
        esc_html__('Setup Morning', 'eventadmin-volunteer-management') => [
            'description' => esc_html__('Volunteers needed for setting up the event.', 'eventadmin-volunteer-management'),
            'start' => $shift_date . ' 09:00:00',
            'end' => $shift_date . ' 12:00:00',
            'max_volunteers' => 7,
            'shift_category' => esc_html__('Setup', 'eventadmin-volunteer-management'),
        ],
    ];

    foreach ($shifts as $name => $description) {
        $shift_category = get_term_by('name', $description['shift_category'], 'eventadmin_shift_category');
        if (!$shift_category) {
            continue; // Category does not exist, skip
        }

        $post_data = [
            'post_title' => $name,
            'post_content' => $description['description'],
            'post_type' => 'eventadmin_shift',
            'post_status' => 'publish',
        ];

        $shift_id = wp_insert_post($post_data);

        if ($shift_id && !is_wp_error($shift_id)) {
            update_post_meta($shift_id, 'shift_start', $description['start']);
            update_post_meta($shift_id, 'shift_end', $description['end']);
            update_post_meta($shift_id, 'max_volunteers', $description['max_volunteers']);
            wp_set_object_terms($shift_id, $shift_category->term_id, 'eventadmin_shift_category');
        }
    }
}

/**
 * Shows an admin notice asking whether to import demo data
 *
 * @return void
 */
function eventadmin_admin_notices(): void
{
    if (get_option('eventadmin_import_demo_data_done')) return;
    if (!current_user_can('manage_options')) return;

    $url_yes = wp_nonce_url(add_query_arg('eventadmin_import_demo', 'yes', admin_url()), 'eventadmin_import_demo', 'eventadmin_import_demo_nonce');
    $url_no = wp_nonce_url(add_query_arg('eventadmin_import_demo', 'no', admin_url()), 'eventadmin_import_demo', 'eventadmin_import_demo_nonce');

    echo '<div class="notice notice-info is-dismissible">';
    echo '<p>' . esc_html__('Do you want to import the sample data for EventAdmin?', 'eventadmin-volunteer-management') . '</p>';
    echo '<p><a href="' . esc_url($url_yes) . '" class="button-primary">' . esc_html__('Yes, please import', 'eventadmin-volunteer-management') . '</a> ';
    echo '<a href="' . esc_url($url_no) . '" class="button">' . esc_html__('No, thanks', 'eventadmin-volunteer-management') . '</a></p>';
    echo '</div>';
}

add_action('admin_notices', 'eventadmin_admin_notices');

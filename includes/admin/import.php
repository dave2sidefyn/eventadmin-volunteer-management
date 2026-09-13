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
        <h1><?php esc_attr_e('Import volunteers', 'eventadmin-volunteer-management'); ?></h1>
        <p><?php esc_html_e('Upload a CSV file to create volunteer accounts in bulk. The first row must be a header row; columns can be in any order and the delimiter may be a comma or a semicolon.', 'eventadmin-volunteer-management'); ?></p>
        <p><?php echo wp_kses(
            __('Recognised columns: <code>first_name</code>, <code>last_name</code>, <code>email</code>, <code>phone</code>. Only <code>first_name</code> is required; <code>last_name</code>, <code>email</code> and <code>phone</code> are optional.', 'eventadmin-volunteer-management'),
            ['code' => []]
        ); ?></p>
        <p><?php esc_html_e('A row with no e-mail address creates an offline volunteer (no login, no notifications). A row whose e-mail already belongs to a user is reported and skipped – no existing account is changed. New volunteers are created without a notification e-mail.', 'eventadmin-volunteer-management'); ?></p>
        <pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:8px 12px;display:inline-block;">first_name,last_name,email,phone
Anna,Muster,anna@example.com,+41 79 123 45 67</pre>
        <form method="post" action="" enctype="multipart/form-data">
            <?php wp_nonce_field('eventadmin_import_volunteers', 'eventadmin_import_volunteers_nonce'); ?>
            <input type="hidden" name="eventadmin_import_volunteers_action" value="1">
            <p>
                <input type="file" name="eventadmin_volunteer_csv" accept=".csv,text/csv" required>
            </p>
            <p><input type="submit" class="button button-primary"
                      value="<?php esc_attr_e('Import volunteers', 'eventadmin-volunteer-management'); ?>"></p>
        </form>
    </div>

    <div class="wrap">
        <h1><?php esc_attr_e('Import shifts', 'eventadmin-volunteer-management'); ?></h1>
        <p><?php esc_html_e('Upload a CSV file to create shifts in bulk. The first row must be a header row; columns can be in any order and the delimiter may be a comma or a semicolon.', 'eventadmin-volunteer-management'); ?></p>
        <p><?php echo wp_kses(
            __('Required columns: <code>title</code>, <code>start</code>, <code>end</code>, <code>max_volunteers</code>. Optional columns: <code>details</code>, <code>department</code>, <code>min_volunteers</code>, <code>organizer_user</code>, <code>organizer_name</code>, <code>organizer_email</code>.', 'eventadmin-volunteer-management'),
            ['code' => []]
        ); ?></p>
        <p><?php echo wp_kses(
            __('<code>start</code> and <code>end</code> accept most common date/time formats (e.g. <code>2026-09-20 09:00</code>). <code>department</code> is matched to an existing department by name and created automatically if it does not exist yet. <code>organizer_user</code> is matched against an existing WordPress user by user ID, e-mail address, or username, in that order – if it cannot be matched, the shift is still created without a linked organizer user.', 'eventadmin-volunteer-management'),
            ['code' => []]
        ); ?></p>
        <pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:8px 12px;display:inline-block;">title,details,start,end,department,min_volunteers,max_volunteers,organizer_user,organizer_name,organizer_email
Bar shift,Serve drinks and keep the bar tidy,2026-09-20 18:00,2026-09-20 22:00,Bar,1,3,stein@example.com,Stein Selseth,stein@example.com</pre>
        <form method="post" action="" enctype="multipart/form-data">
            <?php wp_nonce_field('eventadmin_import_shifts', 'eventadmin_import_shifts_nonce'); ?>
            <input type="hidden" name="eventadmin_import_shifts_action" value="1">
            <p>
                <input type="file" name="eventadmin_shift_csv" accept=".csv,text/csv" required>
            </p>
            <p><input type="submit" class="button button-primary"
                      value="<?php esc_attr_e('Import shifts', 'eventadmin-volunteer-management'); ?>"></p>
        </form>
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

    if (isset($_GET['volimport']) && $_GET['volimport'] === 'done') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $result = get_transient('eventadmin_volunteer_import_result_' . get_current_user_id());
        delete_transient('eventadmin_volunteer_import_result_' . get_current_user_id());

        if (!is_array($result)) {
            $result = ['created' => 0, 'duplicates' => [], 'errors' => []];
        }

        $class = (!empty($result['errors']) || !empty($result['duplicates'])) ? 'notice-warning' : 'notice-success';
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . sprintf(
            /* translators: %d: number of volunteer accounts created */
            esc_html__('%d volunteer(s) imported.', 'eventadmin-volunteer-management'),
            (int) $result['created']
        ) . '</p>';

        foreach (['duplicates', 'errors'] as $group) {
            if (empty($result[$group])) {
                continue;
            }
            $label = $group === 'duplicates'
                ? esc_html__('Skipped – e-mail already in use:', 'eventadmin-volunteer-management')
                : esc_html__('Skipped – invalid rows:', 'eventadmin-volunteer-management');
            echo '<p><strong>' . $label . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
            foreach ($result[$group] as $line) {
                echo '<li>' . esc_html($line) . '</li>';
            }
            echo '</ul>';
        }

        echo '</div>';
    }

    if (isset($_GET['shiftimport']) && $_GET['shiftimport'] === 'done') { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $result = get_transient('eventadmin_shift_import_result_' . get_current_user_id());
        delete_transient('eventadmin_shift_import_result_' . get_current_user_id());

        if (!is_array($result)) {
            $result = ['created' => 0, 'errors' => [], 'warnings' => []];
        }

        $class = !empty($result['errors']) ? 'notice-warning' : 'notice-success';
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . sprintf(
            /* translators: %d: number of shifts created */
            esc_html__('%d shift(s) imported.', 'eventadmin-volunteer-management'),
            (int) $result['created']
        ) . '</p>';

        foreach (['warnings', 'errors'] as $group) {
            if (empty($result[$group])) {
                continue;
            }
            $label = $group === 'warnings'
                ? esc_html__('Notes:', 'eventadmin-volunteer-management')
                : esc_html__('Skipped – invalid rows:', 'eventadmin-volunteer-management');
            echo '<p><strong>' . $label . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
            foreach ($result[$group] as $line) {
                echo '<li>' . esc_html($line) . '</li>';
            }
            echo '</ul>';
        }

        echo '</div>';
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

    if (!empty($_POST['eventadmin_import_volunteers_action'])) {
        if (
            !isset($_POST['eventadmin_import_volunteers_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eventadmin_import_volunteers_nonce'])), 'eventadmin_import_volunteers')
        ) {
            wp_die(esc_html__('Security check failed.', 'eventadmin-volunteer-management'));
        }

        $result = eventadmin_import_volunteers_from_upload();
        set_transient('eventadmin_volunteer_import_result_' . get_current_user_id(), $result, MINUTE_IN_SECONDS);

        wp_safe_redirect(admin_url('tools.php?page=eventadmin-import&volimport=done'));
        exit;
    }

    if (!empty($_POST['eventadmin_import_shifts_action'])) {
        if (
            !isset($_POST['eventadmin_import_shifts_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eventadmin_import_shifts_nonce'])), 'eventadmin_import_shifts')
        ) {
            wp_die(esc_html__('Security check failed.', 'eventadmin-volunteer-management'));
        }

        $result = eventadmin_import_shifts_from_upload();
        set_transient('eventadmin_shift_import_result_' . get_current_user_id(), $result, MINUTE_IN_SECONDS);

        wp_safe_redirect(admin_url('tools.php?page=eventadmin-import&shiftimport=done'));
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
 * Validates the uploaded volunteer CSV and hands it to the parser.
 *
 * @return array{created: int, duplicates: string[], errors: string[]}
 */
function eventadmin_import_volunteers_from_upload(): array
{
    $empty = ['created' => 0, 'duplicates' => [], 'errors' => []];

    if (
        empty($_FILES['eventadmin_volunteer_csv']['tmp_name']) ||
        !is_uploaded_file($_FILES['eventadmin_volunteer_csv']['tmp_name'])
    ) {
        $empty['errors'][] = esc_html__('No file was uploaded.', 'eventadmin-volunteer-management');
        return $empty;
    }

    if ((int) ($_FILES['eventadmin_volunteer_csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $empty['errors'][] = esc_html__('The file could not be uploaded. Please try again.', 'eventadmin-volunteer-management');
        return $empty;
    }

    // sanitize_text_field() is fine here: filenames only, no path.
    $name = sanitize_file_name((string) ($_FILES['eventadmin_volunteer_csv']['name'] ?? ''));
    if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
        $empty['errors'][] = esc_html__('Please upload a .csv file.', 'eventadmin-volunteer-management');
        return $empty;
    }

    return eventadmin_import_volunteers_from_csv($_FILES['eventadmin_volunteer_csv']['tmp_name']);
}

/**
 * Maps a raw CSV header cell to one of the canonical field keys
 * (first_name, last_name, email, phone) or '' when it is not recognised.
 */
function eventadmin_normalize_volunteer_csv_header(string $raw): string
{
    $key = strtolower(trim($raw));
    $key = preg_replace('/^\xEF\xBB\xBF/', '', $key);      // strip UTF-8 BOM
    $key = preg_replace('/[\s_\-.]+/', '', (string) $key); // collapse separators

    $map = [
        'first_name' => ['firstname', 'first', 'givenname', 'vorname', 'prenom', 'prénom', 'voornaam', 'fornavn'],
        'last_name'  => ['lastname', 'last', 'surname', 'familyname', 'nachname', 'nom', 'achternaam', 'etternavn'],
        'email'      => ['email', 'emailaddress', 'mail', 'emailadres', 'epost', 'courriel', 'emailadresse'],
        'phone'      => ['phone', 'phonenumber', 'tel', 'telephone', 'mobile', 'telefon', 'telefoon', 'telefonnummer', 'natel', 'gsm'],
    ];

    foreach ($map as $canonical => $aliases) {
        if (in_array($key, $aliases, true)) {
            return $canonical;
        }
    }

    return '';
}

/**
 * Parses a volunteer CSV file and creates one eventadmin_volunteer account per row.
 *
 * A valid e-mail and a first name are required on every row. Rows whose e-mail
 * already belongs to a WordPress user are reported and skipped without touching
 * the existing account. No notification e-mail is sent for created accounts.
 *
 * @param string $path Absolute path to the uploaded CSV file.
 * @return array{created: int, duplicates: string[], errors: string[]}
 */
function eventadmin_import_volunteers_from_csv(string $path): array
{
    $result = ['created' => 0, 'duplicates' => [], 'errors' => []];

    $handle = fopen($path, 'r');
    if ($handle === false) {
        $result['errors'][] = esc_html__('The file could not be read.', 'eventadmin-volunteer-management');
        return $result;
    }

    // Detect the delimiter from the header line, then rewind to parse it properly.
    $first_line = (string) fgets($handle);
    $delimiter  = substr_count($first_line, ';') > substr_count($first_line, ',') ? ';' : ',';
    rewind($handle);

    $header = fgetcsv($handle, 0, $delimiter, '"', '');
    if (!is_array($header)) {
        fclose($handle);
        $result['errors'][] = esc_html__('The file is empty.', 'eventadmin-volunteer-management');
        return $result;
    }

    $columns = array_map('eventadmin_normalize_volunteer_csv_header', $header);
    if (!in_array('first_name', $columns, true)) {
        fclose($handle);
        $result['errors'][] = esc_html__('The header row must contain a "first_name" column.', 'eventadmin-volunteer-management');
        return $result;
    }

    // Suppress WordPress's default "new user" e-mail for every account created here.
    $suppress_cb = static function (array $mail): array {
        return array_merge($mail, ['to' => '']);
    };
    add_filter('wp_new_user_notification_email', $suppress_cb, 999);

    $line = 1; // header consumed
    while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
        $line++;

        // Skip blank lines.
        if ($row === [null] || implode('', array_map('strval', $row)) === '') {
            continue;
        }

        $fields = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''];
        foreach ($columns as $index => $canonical) {
            if ($canonical !== '' && isset($row[$index])) {
                $fields[$canonical] = trim((string) $row[$index]);
            }
        }

        $first = sanitize_text_field($fields['first_name']);
        $last  = sanitize_text_field($fields['last_name']);
        $phone = sanitize_text_field($fields['phone']);
        $email = sanitize_email($fields['email']);

        if ($first === '') {
            /* translators: %d: CSV line number */
            $result['errors'][] = sprintf(esc_html__('Row %d: first name is required.', 'eventadmin-volunteer-management'), $line);
            continue;
        }

        // No e-mail column value -> offline volunteer (no login, no real address).
        $is_offline = ($fields['email'] === '');

        if (!$is_offline && !is_email($email)) {
            /* translators: %d: CSV line number */
            $result['errors'][] = sprintf(esc_html__('Row %d: invalid e-mail address.', 'eventadmin-volunteer-management'), $line);
            continue;
        }

        if (!$is_offline && (email_exists($email) || username_exists($email))) {
            /* translators: 1: CSV line number, 2: e-mail address */
            $result['duplicates'][] = sprintf(esc_html__('Row %1$d: %2$s', 'eventadmin-volunteer-management'), $line, $email);
            continue;
        }

        $user_id = wp_insert_user([
            'user_login'   => $is_offline ? 'volunteer_' . wp_generate_password(8, false) : $email,
            'user_email'   => $is_offline ? 'offline_' . wp_generate_password(12, false) . '@volunteer.invalid' : $email,
            'user_pass'    => wp_generate_password(),
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => trim($first . ' ' . $last),
            'role'         => 'eventadmin_volunteer',
        ]);

        if (is_wp_error($user_id)) {
            /* translators: 1: CSV line number, 2: error message */
            $result['errors'][] = sprintf(
                esc_html__('Row %1$d: %2$s', 'eventadmin-volunteer-management'),
                $line,
                $user_id->get_error_message()
            );
            continue;
        }

        if ($phone !== '') {
            update_user_meta($user_id, 'eventadmin_phone', $phone);
        }
        update_user_meta($user_id, $is_offline ? 'eventadmin_offline_volunteer' : 'eventadmin_manually_added', '1');

        $result['created']++;
    }

    remove_filter('wp_new_user_notification_email', $suppress_cb, 999);
    fclose($handle);

    return $result;
}

/**
 * Validates the uploaded shift CSV and hands it to the parser.
 *
 * @return array{created: int, errors: string[], warnings: string[]}
 */
function eventadmin_import_shifts_from_upload(): array
{
    $empty = ['created' => 0, 'errors' => [], 'warnings' => []];

    if (
        empty($_FILES['eventadmin_shift_csv']['tmp_name']) ||
        !is_uploaded_file($_FILES['eventadmin_shift_csv']['tmp_name'])
    ) {
        $empty['errors'][] = esc_html__('No file was uploaded.', 'eventadmin-volunteer-management');
        return $empty;
    }

    if ((int) ($_FILES['eventadmin_shift_csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $empty['errors'][] = esc_html__('The file could not be uploaded. Please try again.', 'eventadmin-volunteer-management');
        return $empty;
    }

    // sanitize_text_field() is fine here: filenames only, no path.
    $name = sanitize_file_name((string) ($_FILES['eventadmin_shift_csv']['name'] ?? ''));
    if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
        $empty['errors'][] = esc_html__('Please upload a .csv file.', 'eventadmin-volunteer-management');
        return $empty;
    }

    return eventadmin_import_shifts_from_csv($_FILES['eventadmin_shift_csv']['tmp_name']);
}

/**
 * Maps a raw CSV header cell to one of the canonical shift field keys, or '' when
 * it is not recognised.
 */
function eventadmin_normalize_shift_csv_header(string $raw): string
{
    $key = strtolower(trim($raw));
    $key = preg_replace('/^\xEF\xBB\xBF/', '', $key);      // strip UTF-8 BOM
    $key = preg_replace('/[\s_\-.]+/', '', (string) $key); // collapse separators

    $map = [
        'title'            => ['title', 'shifttitle', 'name', 'titel', 'titre', 'tittel'],
        'details'          => ['details', 'description', 'desc', 'beschreibung', 'beschrijving', 'beskrivelse'],
        'start'            => ['start', 'from', 'shiftstart', 'startdate', 'startdatetime', 'startzeit', 'startdatum', 'starttid', 'datedebut'],
        'end'              => ['end', 'to', 'shiftend', 'enddate', 'enddatetime', 'endzeit', 'einddatum', 'sluttid', 'datefin'],
        'department'       => ['department', 'category', 'shiftcategory', 'abteilung', 'departement', 'afdeling', 'avdeling'],
        'min_volunteers'   => ['minvolunteers', 'min', 'minimum'],
        'max_volunteers'   => ['maxvolunteers', 'max', 'maximum'],
        'organizer_user'   => ['organizeruser', 'organizer', 'verantwortlicher', 'organisateur', 'organisator'],
        'organizer_name'   => ['organizername'],
        'organizer_email'  => ['organizeremail'],
    ];

    foreach ($map as $canonical => $aliases) {
        if (in_array($key, $aliases, true)) {
            return $canonical;
        }
    }

    return '';
}

/**
 * Parses a shift CSV file and creates one eventadmin_shift post per row.
 *
 * Required columns: title, start, end, max_volunteers. A department is matched
 * against an existing department by name (case-insensitive) and automatically
 * created when no matching term exists yet. organizer_user is resolved against
 * an existing user by numeric ID, then e-mail address, then username, in that
 * order; when it does not resolve, the row is still imported but without a
 * linked organizer user (see eventadmin_save_shift_organizer_fields()).
 *
 * @param string $path Absolute path to the uploaded CSV file.
 * @return array{created: int, errors: string[], warnings: string[]}
 */
function eventadmin_import_shifts_from_csv(string $path): array
{
    $result = ['created' => 0, 'errors' => [], 'warnings' => []];

    $handle = fopen($path, 'r');
    if ($handle === false) {
        $result['errors'][] = esc_html__('The file could not be read.', 'eventadmin-volunteer-management');
        return $result;
    }

    // Detect the delimiter from the header line, then rewind to parse it properly.
    $first_line = (string) fgets($handle);
    $delimiter  = substr_count($first_line, ';') > substr_count($first_line, ',') ? ';' : ',';
    rewind($handle);

    $header = fgetcsv($handle, 0, $delimiter, '"', '');
    if (!is_array($header)) {
        fclose($handle);
        $result['errors'][] = esc_html__('The file is empty.', 'eventadmin-volunteer-management');
        return $result;
    }

    $columns = array_map('eventadmin_normalize_shift_csv_header', $header);
    foreach (['title', 'start', 'end', 'max_volunteers'] as $required_column) {
        if (!in_array($required_column, $columns, true)) {
            fclose($handle);
            /* translators: %s: required column name, e.g. "title" */
            $result['errors'][] = sprintf(esc_html__('The header row must contain a "%s" column.', 'eventadmin-volunteer-management'), $required_column);
            return $result;
        }
    }

    // Departments matched or created during this import, name (lowercased) => term_id,
    // so the same new department name in multiple rows is only created once per file.
    $department_cache = [];

    $line = 1; // header consumed
    while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
        $line++;

        // Skip blank lines.
        if ($row === [null] || implode('', array_map('strval', $row)) === '') {
            continue;
        }

        $fields = [
            'title' => '', 'details' => '', 'start' => '', 'end' => '', 'department' => '',
            'min_volunteers' => '', 'max_volunteers' => '',
            'organizer_user' => '', 'organizer_name' => '', 'organizer_email' => '',
        ];
        foreach ($columns as $index => $canonical) {
            if ($canonical !== '' && isset($row[$index])) {
                $fields[$canonical] = trim((string) $row[$index]);
            }
        }

        $title = sanitize_text_field($fields['title']);
        if ($title === '') {
            /* translators: %d: CSV line number */
            $result['errors'][] = sprintf(esc_html__('Row %d: title is required.', 'eventadmin-volunteer-management'), $line);
            continue;
        }

        $start = eventadmin_normalize_datetime_input($fields['start']);
        if ($start === '') {
            /* translators: %d: CSV line number */
            $result['errors'][] = sprintf(esc_html__('Row %d: start date/time is missing or not recognised.', 'eventadmin-volunteer-management'), $line);
            continue;
        }

        $end = eventadmin_normalize_datetime_input($fields['end']);
        if ($end === '') {
            /* translators: %d: CSV line number */
            $result['errors'][] = sprintf(esc_html__('Row %d: end date/time is missing or not recognised.', 'eventadmin-volunteer-management'), $line);
            continue;
        }

        if (strtotime($end) <= strtotime($start)) {
            /* translators: %d: CSV line number */
            $result['errors'][] = sprintf(esc_html__('Row %d: end must be after start.', 'eventadmin-volunteer-management'), $line);
            continue;
        }

        if ($fields['max_volunteers'] === '' || !ctype_digit($fields['max_volunteers']) || (int) $fields['max_volunteers'] < 1) {
            /* translators: %d: CSV line number */
            $result['errors'][] = sprintf(esc_html__('Row %d: max_volunteers must be a whole number of 1 or more.', 'eventadmin-volunteer-management'), $line);
            continue;
        }
        $max_volunteers = (int) $fields['max_volunteers'];

        $min_volunteers = ($fields['min_volunteers'] !== '' && ctype_digit($fields['min_volunteers'])) ? (int) $fields['min_volunteers'] : 0;
        if ($min_volunteers > $max_volunteers) {
            /* translators: %d: CSV line number */
            $result['errors'][] = sprintf(esc_html__('Row %d: min_volunteers cannot be greater than max_volunteers.', 'eventadmin-volunteer-management'), $line);
            continue;
        }

        $details = $fields['details'] !== '' ? wp_kses_post($fields['details']) : '';

        $shift_id = wp_insert_post([
            'post_type'    => 'eventadmin_shift',
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_content' => $details,
        ]);

        if (!$shift_id || is_wp_error($shift_id)) {
            /* translators: 1: CSV line number, 2: error message */
            $result['errors'][] = sprintf(
                esc_html__('Row %1$d: %2$s', 'eventadmin-volunteer-management'),
                $line,
                is_wp_error($shift_id) ? $shift_id->get_error_message() : esc_html__('the shift could not be created.', 'eventadmin-volunteer-management')
            );
            continue;
        }

        update_post_meta($shift_id, 'shift_start', $start);
        update_post_meta($shift_id, 'shift_end', $end);
        update_post_meta($shift_id, 'min_volunteers', $min_volunteers);
        update_post_meta($shift_id, 'max_volunteers', $max_volunteers);

        if ($fields['department'] !== '') {
            $dept_key = strtolower($fields['department']);
            if (isset($department_cache[$dept_key])) {
                $term_id = $department_cache[$dept_key];
            } else {
                $term    = get_term_by('name', $fields['department'], 'eventadmin_shift_category');
                $term_id = $term instanceof WP_Term ? $term->term_id : 0;

                if (!$term_id) {
                    $inserted = wp_insert_term(sanitize_text_field($fields['department']), 'eventadmin_shift_category');
                    if (is_wp_error($inserted)) {
                        /* translators: 1: CSV line number, 2: department name */
                        $result['warnings'][] = sprintf(esc_html__('Row %1$d: could not create department "%2$s", shift left without a department.', 'eventadmin-volunteer-management'), $line, $fields['department']);
                    } else {
                        $term_id = (int) $inserted['term_id'];
                        /* translators: %s: department name */
                        $result['warnings'][] = sprintf(esc_html__('Created new department "%s".', 'eventadmin-volunteer-management'), $fields['department']);
                    }
                }
                $department_cache[$dept_key] = $term_id;
            }
            if ($term_id > 0) {
                wp_set_object_terms($shift_id, [$term_id], 'eventadmin_shift_category');
            }
        }

        // Organizer user: numeric ID, then e-mail address, then username, in that order.
        $organizer_user_id = 0;
        if ($fields['organizer_user'] !== '') {
            $identifier = $fields['organizer_user'];
            $user       = null;
            if (ctype_digit($identifier)) {
                $user = get_userdata((int) $identifier) ?: null;
            }
            if (!$user && is_email($identifier)) {
                $user = get_user_by('email', $identifier) ?: null;
            }
            if (!$user) {
                $user = get_user_by('login', $identifier) ?: null;
            }
            if ($user instanceof WP_User) {
                $organizer_user_id = $user->ID;
            } else {
                /* translators: 1: CSV line number, 2: the organizer_user value from the row */
                $result['warnings'][] = sprintf(esc_html__('Row %1$d: organizer user "%2$s" was not found and was left unset.', 'eventadmin-volunteer-management'), $line, $identifier);
            }
        }

        eventadmin_save_shift_organizer_fields($shift_id, [
            'shift_organizer_user_id' => $organizer_user_id,
            'shift_organizer_name'    => $fields['organizer_name'],
            'shift_organizer_email'   => $fields['organizer_email'],
        ]);

        $result['created']++;
    }

    fclose($handle);

    return $result;
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

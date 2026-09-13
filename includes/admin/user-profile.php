<?php
/**
 * EventAdmin Volunteer Management - Volunteer activity on the user profile screen
 * Adds an "Upcoming shifts / Past shifts / Notification history" section when an
 * authorized user views a volunteer's wp-admin user-edit.php page.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Splits a volunteer's full shift assignment history into upcoming/past buckets.
 *
 * @param int $user_id
 * @return array{upcoming: WP_Post[], past: WP_Post[]}
 */
function eventadmin_get_volunteer_shift_history(int $user_id): array
{
    $shifts = get_posts([
        'post_type'   => 'eventadmin_shift',
        'numberposts' => -1,
        'post_status' => 'any',
        'meta_key'    => 'shift_start',
        'orderby'     => 'meta_value',
        'meta_type'   => 'DATETIME',
        'order'       => 'ASC',
        'meta_query'  => [
            ['key' => 'assigned_user_' . $user_id, 'compare' => 'EXISTS'],
        ],
    ]);

    $now_ts   = current_time('timestamp');
    $upcoming = [];
    $past     = [];

    foreach ($shifts as $shift) {
        $end    = get_post_meta($shift->ID, 'shift_end', true);
        $end_ts = $end ? strtotime($end) : 0;
        if ($end_ts >= $now_ts) {
            $upcoming[] = $shift;
        } else {
            $past[] = $shift;
        }
    }

    // Upcoming reads best soonest-first (already ASC from the query); past reads best
    // most-recent-first.
    $past = array_reverse($past);

    return ['upcoming' => $upcoming, 'past' => $past];
}

/**
 * Renders a compact table of shifts for the profile page (title, period, capacity),
 * each shift title linking to its own edit screen.
 *
 * @param WP_Post[] $shifts
 * @return string
 */
function eventadmin_render_volunteer_shift_table(array $shifts): string
{
    $html = '<table class="eventadmin-profile-table">';
    $html .= '<thead><tr><th>' . esc_html__('Shift', 'eventadmin-volunteer-management') . '</th><th>'
        . esc_html__('Period', 'eventadmin-volunteer-management') . '</th><th>'
        . esc_html__('Capacity', 'eventadmin-volunteer-management') . '</th></tr></thead><tbody>';

    foreach ($shifts as $shift) {
        $start     = get_post_meta($shift->ID, 'shift_start', true);
        $end       = get_post_meta($shift->ID, 'shift_end', true);
        $max       = (int) get_post_meta($shift->ID, 'max_volunteers', true);
        $assigned  = eventadmin_count_assignments($shift->ID);
        $edit_link = get_edit_post_link($shift->ID);
        $title     = $edit_link
            ? '<a href="' . esc_url($edit_link) . '">' . esc_html($shift->post_title) . '</a>'
            : esc_html($shift->post_title);

        $html .= '<tr>';
        $html .= '<td>' . $title . '</td>';
        $html .= '<td>' . esc_html(eventadmin_get_formatted_zeitraum($start, $end)) . '</td>';
        $html .= '<td>' . esc_html($assigned . '/' . $max) . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';

    return $html;
}

/**
 * Renders the "Volunteer activity" section on user-edit.php — shown only when an
 * authorized user (e.g. an administrator) is viewing another EventAdmin volunteer's
 * profile screen. Deliberately not shown on a user's own profile page (`show_user_profile`):
 * the notification log and shift history here are for admin use, not something a
 * volunteer needs surfaced on their own account screen (they already have the front-end
 * [eventadmin_profile] shortcode for that).
 *
 * @param WP_User $user
 */
function eventadmin_render_volunteer_profile_section(WP_User $user): void
{
    if (!in_array('eventadmin_volunteer', (array) $user->roles, true)) {
        return;
    }

    echo '<h2 id="eventadmin-volunteer-activity">' . esc_html__('Volunteer activity', 'eventadmin-volunteer-management') . '</h2>';
    eventadmin_render_volunteer_profile_content($user);
}

add_action('edit_user_profile', 'eventadmin_render_volunteer_profile_section');

/**
 * Renders an editable "Departments" checklist on user-edit.php when an admin is viewing
 * another EventAdmin volunteer's profile. Sets the same eventadmin_department link the
 * volunteer can also set themselves via the front-end [eventadmin_profile] form (see
 * includes/profile.php) — used to target department-specific announcements
 * (includes/admin/bulk-email.php), independent of any shift the volunteer has actually
 * worked. Deliberately not shown on `show_user_profile` (a volunteer's own profile page)
 * — same admin-only-here reasoning as eventadmin_render_volunteer_profile_section() above.
 *
 * @param WP_User $user
 */
function eventadmin_render_volunteer_departments_section(WP_User $user): void
{
    if (!in_array('eventadmin_volunteer', (array) $user->roles, true)) {
        return;
    }

    $categories = eventadmin_get_hierarchical_shift_categories();
    if (empty($categories)) {
        return;
    }

    $linked = eventadmin_get_volunteer_department_ids($user->ID);

    echo '<h2>' . esc_html__('Departments', 'eventadmin-volunteer-management') . '</h2>';
    wp_nonce_field('eventadmin_save_volunteer_departments', 'eventadmin_volunteer_departments_nonce');
    echo '<table class="form-table"><tr><th>' . esc_html__('Linked departments', 'eventadmin-volunteer-management') . '</th><td>';
    echo '<p class="description" style="margin-top:0;">' . esc_html__('Used to target this volunteer with department-specific announcements (Tools → Send Announcement), independent of any shift they have actually signed up for. The volunteer can also set this themselves from their own profile page. Checking (or unchecking) a department also checks/unchecks every department nested under it.', 'eventadmin-volunteer-management') . '</p>';
    foreach ($categories as $cat) {
        $margin_left = $cat->depth > 0 ? $cat->depth * 20 : 0;
        $hidden_suffix = eventadmin_is_shift_category_hidden($cat->term_id)
            ? ' <em>' . esc_html__('(hidden from volunteers)', 'eventadmin-volunteer-management') . '</em>'
            : '';
        echo '<label style="display:block;margin:4px 0 4px ' . esc_attr($margin_left) . 'px;">';
        echo '<input type="checkbox" class="eventadmin-department-checkbox" data-depth="' . esc_attr($cat->depth) . '" name="eventadmin_department[]" value="' . esc_attr($cat->term_id) . '"' . checked(in_array($cat->term_id, $linked, true), true, false) . '> ';
        echo esc_html($cat->name) . $hidden_suffix;
        echo '</label>';
    }
    echo '</td></tr></table>';

    wp_enqueue_script(
        'eventadmin-department-checkboxes',
        plugin_dir_url(__FILE__) . '../../assets/js/department-checkboxes.js',
        [],
        '1.0',
        true
    );
}

add_action('edit_user_profile', 'eventadmin_render_volunteer_departments_section');

/**
 * Saves the "Departments" checklist from user-edit.php. edit_user_profile_update only
 * fires when an admin is editing someone ELSE's profile (never on personal_options_update,
 * a volunteer's own profile save) — matching this field's admin-side placement above.
 *
 * @param int $user_id
 */
function eventadmin_save_volunteer_departments(int $user_id): void
{
    if (
        !isset($_POST['eventadmin_volunteer_departments_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eventadmin_volunteer_departments_nonce'])), 'eventadmin_save_volunteer_departments')
    ) {
        return;
    }

    if (!current_user_can('edit_user', $user_id)) {
        return;
    }

    $posted = (isset($_POST['eventadmin_department']) && is_array($_POST['eventadmin_department']))
        ? wp_unslash($_POST['eventadmin_department'])
        : [];
    eventadmin_save_volunteer_department_ids($user_id, $posted);
}

add_action('edit_user_profile_update', 'eventadmin_save_volunteer_departments');

/**
 * Renders the actual "Upcoming shifts / Past shifts / Notification history" cards —
 * shared by the user-edit.php section above and the Timeline view's "View profile"
 * modal (fetched there via AJAX, see eventadmin_ajax_get_volunteer_profile()).
 *
 * Uses its own markup/CSS (eventadmin-profile-*) rather than core's .form-table/.widefat —
 * stacking a wide table inside a .form-table row doubles up padding meant for a single
 * label+input pair, not a multi-row table.
 *
 * @param WP_User $user
 */
function eventadmin_render_volunteer_profile_content(WP_User $user): void
{
    $history           = eventadmin_get_volunteer_shift_history($user->ID);
    $notification_log  = get_user_meta($user->ID, 'eventadmin_notification_log', true);
    if (!is_array($notification_log)) {
        $notification_log = [];
    }

    $type_labels = [
        'assign'       => esc_html__('Signed up', 'eventadmin-volunteer-management'),
        'unassign'     => esc_html__('Cancelled', 'eventadmin-volunteer-management'),
        'reminder'     => esc_html__('Reminder', 'eventadmin-volunteer-management'),
        'announcement' => esc_html__('Announcement', 'eventadmin-volunteer-management'),
    ];

    echo '<div class="eventadmin-profile-activity">';

    echo '<div class="eventadmin-profile-section">';
    echo '<h3>' . esc_html__('Upcoming shifts', 'eventadmin-volunteer-management') . '</h3>';
    if (empty($history['upcoming'])) {
        echo '<p class="eventadmin-profile-empty">' . esc_html__('No upcoming shifts.', 'eventadmin-volunteer-management') . '</p>';
    } else {
        echo eventadmin_render_volunteer_shift_table($history['upcoming']);
    }
    echo '</div>';

    echo '<div class="eventadmin-profile-section">';
    echo '<h3>' . esc_html__('Past shifts', 'eventadmin-volunteer-management') . '</h3>';
    if (empty($history['past'])) {
        echo '<p class="eventadmin-profile-empty">' . esc_html__('No past shifts.', 'eventadmin-volunteer-management') . '</p>';
    } else {
        // Capped the same way the site-wide announcement log already is, so a
        // long-serving volunteer's page doesn't turn into an unbounded dump.
        $shown_past = array_slice($history['past'], 0, 100);
        echo eventadmin_render_volunteer_shift_table($shown_past);
        if (count($history['past']) > 100) {
            echo '<p class="eventadmin-profile-hint">' . esc_html(sprintf(
                /* translators: %d: total number of past shifts */
                __('Showing the 100 most recent of %d past shifts.', 'eventadmin-volunteer-management'),
                count($history['past'])
            )) . '</p>';
        }
    }
    echo '</div>';

    echo '<div class="eventadmin-profile-section">';
    echo '<h3>' . esc_html__('Notification history', 'eventadmin-volunteer-management') . '</h3>';
    if (empty($notification_log)) {
        echo '<p class="eventadmin-profile-empty">' . esc_html__('No notifications logged yet.', 'eventadmin-volunteer-management') . '</p>';
        echo '<p class="eventadmin-profile-hint">' . esc_html__('Only notifications sent from now on are recorded here.', 'eventadmin-volunteer-management') . '</p>';
    } else {
        echo '<table class="eventadmin-profile-table">';
        echo '<thead><tr><th>' . esc_html__('Date', 'eventadmin-volunteer-management') . '</th><th>'
            . esc_html__('Type', 'eventadmin-volunteer-management') . '</th><th>'
            . esc_html__('Subject', 'eventadmin-volunteer-management') . '</th></tr></thead><tbody>';

        foreach ($notification_log as $entry) {
            $entry_date = isset($entry['date']) ? strtotime((string) $entry['date']) : false;
            $date_display = $entry_date
                ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $entry_date)
                : '';
            $entry_type = (string) ($entry['type'] ?? '');
            $type_label = $type_labels[$entry_type] ?? esc_html($entry_type);
            $subject    = esc_html((string) ($entry['subject'] ?? ''));
            $shift_id   = (int) ($entry['shift_id'] ?? 0);

            if ($shift_id && ($shift_edit_link = get_edit_post_link($shift_id))) {
                $subject = '<a href="' . esc_url($shift_edit_link) . '">' . $subject . '</a>';
            }

            echo '<tr><td>' . esc_html($date_display) . '</td><td><span class="eventadmin-profile-type eventadmin-profile-type-'
                . esc_attr($entry_type) . '">' . $type_label . '</span></td><td>' . $subject . '</td></tr>';
        }

        echo '</tbody></table>';
    }
    echo '</div>';

    echo '</div>';
}

/**
 * AJAX: returns the same "Volunteer activity" cards as HTML, for the Timeline view's
 * "View profile" popover action — opens in a modal there instead of navigating away to
 * user-edit.php, since that's where admins are already spending their time.
 */
function eventadmin_ajax_get_volunteer_profile(): void
{
    if (
        !isset($_POST['nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eventadmin_update_shift')
    ) {
        wp_send_json_error(['message' => esc_html__('Security check failed.', 'eventadmin-volunteer-management')]);
    }

    $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
    $user    = $user_id ? get_userdata($user_id) : false;

    if (!$user || !current_user_can('edit_user', $user_id)) {
        wp_send_json_error(['message' => esc_html__('Not allowed', 'eventadmin-volunteer-management')]);
    }

    ob_start();
    eventadmin_render_volunteer_profile_content($user);
    $html = ob_get_clean();

    wp_send_json_success(['html' => $html]);
}

add_action('wp_ajax_eventadmin_get_volunteer_profile', 'eventadmin_ajax_get_volunteer_profile');

/**
 * Renders the "View profile" modal shell (heading + AJAX-filled body + link to the full
 * user-edit.php profile) — shared by the Timeline view and the Volunteers list page, both
 * of which open it via window.eventadminOpenVolunteerProfileModal() (see
 * assets/js/volunteer-profile-modal.js) rather than navigating away to user-edit.php.
 */
function eventadmin_render_volunteer_profile_modal_markup(): void
{
    eventadmin_render_modal_open('eventadmin-volunteer-profile-modal', 720);
    eventadmin_render_modal_close_button('eventadmin-volunteer-profile-close');
    echo '<h2 id="eventadmin-volunteer-profile-heading" style="margin-top:0;"></h2>';
    echo '<div id="eventadmin-volunteer-profile-body"></div>';
    echo '<p style="margin-top:16px;"><a href="#" id="eventadmin-volunteer-profile-full-link" target="_blank">' . esc_html__('Open full profile', 'eventadmin-volunteer-management') . '</a></p>';
    eventadmin_render_modal_close();
}

/**
 * Enqueues the shared "View profile" modal's JS and localizes its config — used by any
 * screen that renders eventadmin_render_volunteer_profile_modal_markup() and wants a
 * "View profile" trigger to open it (Timeline view, Volunteers list page).
 */
function eventadmin_enqueue_volunteer_profile_modal_script(): void
{
    wp_enqueue_script(
        'eventadmin-volunteer-profile-modal',
        plugin_dir_url(__FILE__) . '../../assets/js/volunteer-profile-modal.js',
        [],
        '1.0',
        true
    );
    wp_localize_script('eventadmin-volunteer-profile-modal', 'EVENTADMIN_VOLUNTEER_PROFILE', [
        'ajax_url'           => admin_url('admin-ajax.php'),
        'nonce'              => wp_create_nonce('eventadmin_update_shift'),
        'user_edit_url_base' => admin_url('user-edit.php?user_id='),
        'i18n'               => [
            'loading' => esc_html__('Loading…', 'eventadmin-volunteer-management'),
            'error'   => esc_html__('An error occurred. Please try again.', 'eventadmin-volunteer-management'),
        ],
    ]);
}

/**
 * Enqueues the "Volunteer activity" section's stylesheet — on the Edit User screen (where
 * it renders directly on the page), and on the Manager and Volunteers screens, where the
 * "View profile" modal reuses the same markup/classes for its AJAX-fetched content.
 */
function eventadmin_enqueue_volunteer_profile_styles(): void
{
    $screen = get_current_screen();
    $allowed_screens = ['user-edit', 'eventadmin_shift_page_eventadmin-shift-manager', 'eventadmin_shift_page_eventadmin-volunteers'];
    if (!$screen || !in_array($screen->id, $allowed_screens, true)) {
        return;
    }

    wp_enqueue_style(
        'eventadmin-admin-user-profile',
        plugin_dir_url(__FILE__) . '../../assets/css/admin-user-profile.css',
        [],
        '1.0'
    );
}

add_action('admin_enqueue_scripts', 'eventadmin_enqueue_volunteer_profile_styles');

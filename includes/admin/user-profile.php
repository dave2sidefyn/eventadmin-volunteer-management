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
 * Renders a compact table of shifts for the profile page (title, period, capacity), each
 * shift title opening the "Shift details" modal (see includes/admin/shift-details-modal.php)
 * — which lists that shift's whole roster, so an admin can jump from one volunteer's history
 * straight into who else was on a given shift.
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
        $start    = get_post_meta($shift->ID, 'shift_start', true);
        $end      = get_post_meta($shift->ID, 'shift_end', true);
        $max      = (int) get_post_meta($shift->ID, 'max_volunteers', true);
        $assigned = eventadmin_count_assignments($shift->ID);
        $title    = '<button type="button" class="button-link eventadmin-view-shift-details" data-shift-id="'
            . esc_attr($shift->ID) . '" data-title="' . esc_attr($shift->post_title) . '">'
            . esc_html($shift->post_title) . '</button>';

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
    // This volunteer's own activity renders directly on the page (no modal needed for
    // them), but the Upcoming/Past shifts tables above can still open the Shift details
    // modal, whose roster can in turn link to a *different* volunteer's profile — so both
    // modals still need to exist here, just hidden until something opens one.
    eventadmin_render_shared_volunteer_modals();
}

add_action('edit_user_profile', 'eventadmin_render_volunteer_profile_section');

/**
 * Renders the badge spans for one volunteer (Offline/Unverified/Social/Manual), with the
 * same instant hover tooltip as the Volunteers list table — see eventadmin_volunteer_list_page()
 * for the matching .eventadmin-badge CSS. A single-user version of that page's batch-fetched
 * checks; fine to run its own query here since this only renders for one profile at a time.
 *
 * @param WP_User $user
 * @return string
 */
function eventadmin_render_volunteer_badges(WP_User $user): string
{
    global $wpdb;
    $social_users_table = $wpdb->prefix . 'social_users';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$social_users_table}'") === $social_users_table;
    $is_social = $table_exists && (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM `{$social_users_table}` WHERE ID = %d",
        $user->ID
    ));

    $is_offline    = (bool) get_user_meta($user->ID, 'eventadmin_offline_volunteer', true) || empty($user->user_email);
    $is_unverified = (bool) get_user_meta($user->ID, 'magic_login_token', true);
    $is_manual     = (bool) get_user_meta($user->ID, 'eventadmin_manually_added', true);

    $badges = [];
    if ($is_offline) {
        $tip = esc_attr__('Created by an admin without an email address. Cannot log in and receives no notifications.', 'eventadmin-volunteer-management');
        $badges[] = '<span class="eventadmin-badge" tabindex="0" data-tooltip="' . $tip . '" aria-label="' . $tip . '" style="background:#777;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;font-weight:normal;">' . esc_html__('Offline', 'eventadmin-volunteer-management') . '</span>';
    }
    if ($is_unverified) {
        $tip = esc_attr__('Registered via the public form but has not yet clicked the magic login link. The account is auto-deleted once the link expires (~24 h). The badge disappears as soon as the link is clicked.', 'eventadmin-volunteer-management');
        $badges[] = '<span class="eventadmin-badge" tabindex="0" data-tooltip="' . $tip . '" aria-label="' . $tip . '" style="background:#dba617;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;font-weight:normal;">' . esc_html__('Unverified', 'eventadmin-volunteer-management') . '</span>';
    }
    if ($is_social) {
        $tip = esc_attr__('Registered or linked via Nextend Social Login (e.g. Google, Facebook). Requires the Nextend Social Login plugin.', 'eventadmin-volunteer-management');
        $badges[] = '<span class="eventadmin-badge" tabindex="0" data-tooltip="' . $tip . '" aria-label="' . $tip . '" style="background:#4285f4;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;font-weight:normal;">' . esc_html__('Social', 'eventadmin-volunteer-management') . '</span>';
    }
    if ($is_manual) {
        $tip = esc_attr__('Added by an admin via the dashboard form or the "Grant volunteer role" function. Never auto-deleted.', 'eventadmin-volunteer-management');
        $badges[] = '<span class="eventadmin-badge" tabindex="0" data-tooltip="' . $tip . '" aria-label="' . $tip . '" style="background:#2e7d32;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;font-weight:normal;">' . esc_html__('Manual', 'eventadmin-volunteer-management') . '</span>';
    }

    return implode(' ', $badges);
}

/**
 * Strips a phone number down to what a `tel:` link needs (digits and a leading +),
 * while the visible text keeps whatever formatting the volunteer entered.
 *
 * @param string $phone
 * @return string
 */
function eventadmin_phone_tel_href(string $phone): string
{
    $stripped = preg_replace('/[^0-9+]/', '', $phone) ?? '';
    return $stripped;
}

/**
 * Renders the "Volunteer details" summary table at the top of the profile activity view —
 * everything an admin needs at a glance to answer a volunteer's question without hunting
 * across the Volunteers list, their user-edit.php fields, and the Departments column. Phone
 * and the announcements opt-in are editable inline (AJAX, see volunteer-profile-modal.js);
 * badges are shown next to the section heading by the caller, not as a row here.
 *
 * @param WP_User $user
 * @return string
 */
function eventadmin_render_volunteer_summary(WP_User $user): string
{
    $phone             = get_user_meta($user->ID, 'eventadmin_phone', true);
    $announcements_raw = get_user_meta($user->ID, 'eventadmin_announcements', true);
    $subscribed        = $announcements_raw !== '0';
    $department_terms  = array_filter(array_map(
        fn($term_id) => get_term($term_id, 'eventadmin_shift_category'),
        eventadmin_get_volunteer_department_ids($user->ID)
    ), fn($term) => $term instanceof WP_Term);

    $registered_ts = strtotime($user->user_registered . ' UTC') ?: 0;
    $registered    = $registered_ts
        ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $registered_ts)
        : '—';

    $phone_value_html = $phone
        ? '<a href="tel:' . esc_attr(eventadmin_phone_tel_href($phone)) . '">' . esc_html($phone) . '</a>'
        : '<span class="eventadmin-profile-muted">' . esc_html__('(none)', 'eventadmin-volunteer-management') . '</span>';
    $phone_display = '<span class="eventadmin-phone-view"><span class="eventadmin-phone-value">' . $phone_value_html . '</span>'
        . ' <button type="button" class="button-link eventadmin-profile-edit-trigger eventadmin-edit-phone-trigger">' . esc_html__('Edit', 'eventadmin-volunteer-management') . '</button></span>'
        . '<span class="eventadmin-phone-edit" style="display:none;">'
        . '<input type="text" class="eventadmin-phone-input" value="' . esc_attr($phone) . '">'
        . '<button type="button" class="button button-small eventadmin-phone-save" data-user-id="' . esc_attr($user->ID) . '">' . esc_html__('Save', 'eventadmin-volunteer-management') . '</button>'
        . '<button type="button" class="button-link eventadmin-phone-cancel">' . esc_html__('Cancel', 'eventadmin-volunteer-management') . '</button>'
        . '</span>';

    $announcements_display = ($subscribed
        ? esc_html__('Subscribed', 'eventadmin-volunteer-management')
        : esc_html__('Not subscribed', 'eventadmin-volunteer-management'))
        . ' <button type="button" class="button-link eventadmin-profile-edit-trigger eventadmin-toggle-announcements" data-user-id="' . esc_attr($user->ID) . '" data-subscribed="' . ($subscribed ? '1' : '0') . '">'
        . ($subscribed ? esc_html__('Unsubscribe', 'eventadmin-volunteer-management') : esc_html__('Subscribe', 'eventadmin-volunteer-management'))
        . '</button>';

    $rows = [
        esc_html__('E-Mail', 'eventadmin-volunteer-management')       => $user->user_email ? esc_html($user->user_email) : '—',
        esc_html__('Phone', 'eventadmin-volunteer-management')        => $phone_display,
        esc_html__('Registered', 'eventadmin-volunteer-management')   => esc_html($registered),
        esc_html__('Departments', 'eventadmin-volunteer-management')  => $department_terms
            ? esc_html(implode(', ', array_map(fn($t) => $t->name, $department_terms)))
            : '—',
        esc_html__('Announcements', 'eventadmin-volunteer-management') => $announcements_display,
    ];

    $html = '<table class="eventadmin-profile-table eventadmin-profile-summary">';
    foreach ($rows as $label => $value) {
        $html .= '<tr><th>' . $label . '</th><td>' . $value . '</td></tr>';
    }
    $html .= '</table>';

    return $html;
}

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
    $volunteer_badges = eventadmin_render_volunteer_badges($user);
    echo '<h3>' . esc_html__('Volunteer details', 'eventadmin-volunteer-management') . ($volunteer_badges ? ' ' . $volunteer_badges : '') . '</h3>';
    echo eventadmin_render_volunteer_summary($user);
    if (current_user_can('eventadmin_manage_volunteers')) {
        $safe_name  = esc_attr(trim($user->first_name . ' ' . $user->last_name) ?: $user->user_login);
        $shift_count = count($history['upcoming']);
        echo '<p><button type="button" class="button eventadmin-remove-role-profile"'
            . ' data-user-id="' . esc_attr($user->ID) . '"'
            . ' data-shift-count="' . esc_attr($shift_count) . '"'
            . ' data-name="' . $safe_name . '">'
            . esc_html__('Remove volunteer role', 'eventadmin-volunteer-management')
            . '</button></p>';
    }
    echo '</div>';

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

    // For the Getting Started checklist (includes/admin/getting-started-checklist.php).
    if (!get_option('eventadmin_viewed_volunteer_profile')) {
        update_option('eventadmin_viewed_volunteer_profile', 1);
    }

    ob_start();
    eventadmin_render_volunteer_profile_content($user);
    $html = ob_get_clean();

    wp_send_json_success(['html' => $html]);
}

add_action('wp_ajax_eventadmin_get_volunteer_profile', 'eventadmin_ajax_get_volunteer_profile');

/**
 * AJAX: updates a volunteer's phone number (`eventadmin_phone` user meta) from the
 * "Volunteer details" summary — same capability as every other volunteer-management action
 * (eventadmin_manage_volunteers), so Volunteer Managers can use it too, not just admins.
 */
function eventadmin_ajax_update_volunteer_phone(): void
{
    if (
        !isset($_POST['_ajax_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])), 'eventadmin_update_volunteer_phone')
    ) {
        wp_send_json_error(['message' => esc_html__('Security check failed.', 'eventadmin-volunteer-management')]);
    }

    if (!current_user_can('eventadmin_manage_volunteers')) {
        wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'eventadmin-volunteer-management')]);
    }

    $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
    $user    = $user_id ? get_userdata($user_id) : false;

    if (!$user) {
        wp_send_json_error(['message' => esc_html__('User not found.', 'eventadmin-volunteer-management')]);
    }

    $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
    update_user_meta($user_id, 'eventadmin_phone', $phone);

    wp_send_json_success([
        'phone'      => $phone,
        'tel_href'   => eventadmin_phone_tel_href($phone),
    ]);
}

add_action('wp_ajax_eventadmin_update_volunteer_phone', 'eventadmin_ajax_update_volunteer_phone');

/**
 * AJAX: toggles a volunteer's announcements opt-in (`eventadmin_announcements` user meta)
 * from the "Volunteer details" summary.
 */
function eventadmin_ajax_toggle_volunteer_announcements(): void
{
    if (
        !isset($_POST['_ajax_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])), 'eventadmin_toggle_volunteer_announcements')
    ) {
        wp_send_json_error(['message' => esc_html__('Security check failed.', 'eventadmin-volunteer-management')]);
    }

    if (!current_user_can('eventadmin_manage_volunteers')) {
        wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'eventadmin-volunteer-management')]);
    }

    $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
    $user    = $user_id ? get_userdata($user_id) : false;

    if (!$user) {
        wp_send_json_error(['message' => esc_html__('User not found.', 'eventadmin-volunteer-management')]);
    }

    $subscribed = !empty($_POST['subscribed']);
    update_user_meta($user_id, 'eventadmin_announcements', $subscribed ? '1' : '0');

    wp_send_json_success([
        'subscribed' => $subscribed,
        'label'      => $subscribed
            ? esc_html__('Subscribed', 'eventadmin-volunteer-management')
            : esc_html__('Not subscribed', 'eventadmin-volunteer-management'),
        'toggle_label' => $subscribed
            ? esc_html__('Unsubscribe', 'eventadmin-volunteer-management')
            : esc_html__('Subscribe', 'eventadmin-volunteer-management'),
    ]);
}

add_action('wp_ajax_eventadmin_toggle_volunteer_announcements', 'eventadmin_ajax_toggle_volunteer_announcements');

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
    echo '<p style="margin-top:16px;"><a href="#" id="eventadmin-volunteer-profile-full-link" target="_blank" class="button">' . esc_html__('Open full profile', 'eventadmin-volunteer-management') . '</a></p>';
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
        'ajax_url'             => admin_url('admin-ajax.php'),
        'nonce'                => wp_create_nonce('eventadmin_update_shift'),
        'nonce_phone'          => wp_create_nonce('eventadmin_update_volunteer_phone'),
        'nonce_announcements'  => wp_create_nonce('eventadmin_toggle_volunteer_announcements'),
        'nonce_remove'         => wp_create_nonce('eventadmin_remove_volunteer_role'),
        'user_edit_url_base'   => admin_url('user-edit.php?user_id='),
        'i18n'                 => [
            'loading'                  => esc_html__('Loading…', 'eventadmin-volunteer-management'),
            'error'                    => esc_html__('An error occurred. Please try again.', 'eventadmin-volunteer-management'),
            'none'                     => esc_html__('(none)', 'eventadmin-volunteer-management'),
            'role_removed'             => esc_html__('Role removed. Reloading…', 'eventadmin-volunteer-management'),
            'remove_confirm'           => esc_html__('Remove the volunteer role from {name}? They still have {shifts} upcoming shift(s).', 'eventadmin-volunteer-management'),
            'remove_confirm_no_shifts' => esc_html__('Remove the volunteer role from {name}?', 'eventadmin-volunteer-management'),
        ],
    ]);
}

/**
 * Renders and enqueues the pair of modals shared by every screen that can show a "View
 * profile" popup — the volunteer profile modal itself, and the shared shift modal (see
 * includes/admin/shift-details-modal.php) it can navigate into from a volunteer's
 * Upcoming/Past shifts table (and back again from that shift's own volunteer roster). Used
 * on the Volunteers list, Manager/Timeline, user-edit.php, and the Overview dashboard's
 * activity feed — always as a pair, since either modal's content can link into the other.
 *
 * @param array<string, mixed> $shift_modal_extra Passed through to
 *                              eventadmin_enqueue_shift_details_modal_script() — only the
 *                              Manager/Timeline view needs this, to layer its local shift
 *                              cache, move-target list and fuller i18n set on top of the
 *                              defaults every other screen gets.
 */
function eventadmin_render_shared_volunteer_modals(array $shift_modal_extra = []): void
{
    eventadmin_render_volunteer_profile_modal_markup();
    eventadmin_enqueue_volunteer_profile_modal_script();
    eventadmin_render_shift_details_modal_markup();
    eventadmin_enqueue_shift_details_modal_script($shift_modal_extra);
}

/**
 * Enqueues the "Volunteer activity" section's stylesheet — on the Edit User screen (where
 * it renders directly on the page), and on the Manager, Volunteers and Overview screens,
 * where the "View profile" and "Shift details" modals reuse the same markup/classes for
 * their AJAX-fetched content.
 */
function eventadmin_enqueue_volunteer_profile_styles(): void
{
    $screen = get_current_screen();
    $allowed_screens = ['user-edit', 'eventadmin_shift_page_eventadmin-shift-manager', 'eventadmin_shift_page_eventadmin-volunteers', 'eventadmin_shift_page_eventadmin-overview'];
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

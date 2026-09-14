<?php
/**
 * EventAdmin Volunteer Management - Manager / Timeline Tab
 * The drag-and-drop Gantt view: row-building, the Chart.js canvas, its modals/JS config,
 * and every AJAX handler the Timeline's own JS (assets/js/admin-charts.js) talks to.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Builds the Timeline view's rows for a single shift — one row per assigned volunteer,
 * plus (when $show_open is true) one row per still-open slot, split into "required"
 * (red, below min_volunteers) and "optional" (grey, up to max_volunteers) capacity.
 * Shared between the initial page render and the AJAX "remove volunteer" handler so
 * both produce identical row data for the same shift.
 *
 * @param WP_Post $shift
 * @param bool $show_open
 * @return array
 */
function eventadmin_build_timeline_rows_for_shift(WP_Post $shift, bool $show_open): array
{
    $title = esc_html($shift->post_title);
    $start = (string) get_post_meta($shift->ID, 'shift_start', true);
    $end   = (string) get_post_meta($shift->ID, 'shift_end', true);
    $min   = (int) get_post_meta($shift->ID, 'min_volunteers', true);
    $max   = (int) get_post_meta($shift->ID, 'max_volunteers', true);

    $users = [];
    foreach (get_post_meta($shift->ID) as $key => $val) {
        if (str_starts_with($key, 'assigned_user_')) {
            $user_id = absint($val[0]);
            $user    = get_userdata($user_id);
            if ($user) {
                $users[] = [
                    'id'      => $user_id,
                    'name'    => $user->first_name . ' ' . $user->last_name,
                    // Gates the Timeline's "Notify volunteer"/"Notify affected volunteers"
                    // checkboxes (see admin-charts.js) — the offline flag alone isn't
                    // enough, since a volunteer can also just have no email on file
                    // without being explicitly marked offline.
                    'offline' => !eventadmin_user_can_receive_email($user_id),
                ];
            }
        }
    }

    $shift_categories = wp_get_post_terms($shift->ID, 'eventadmin_shift_category');
    $bar_color        = !empty($shift_categories)
        ? (get_term_meta($shift_categories[0]->term_id, 'term_color', true) ?: '#2271b1')
        : '#2271b1';
    $start_ts = eventadmin_wallclock_to_ts($start);
    $end_ts   = eventadmin_wallclock_to_ts($end);
    $period   = eventadmin_get_formatted_zeitraum($start, $end);
    $assigned = count($users);

    $capacity_label = $assigned . '/' . $max;
    if ($min > 0) {
        /* translators: %d is the minimum number of volunteers required */
        $capacity_label .= ' ' . sprintf(esc_html__('(min %d)', 'eventadmin-volunteer-management'), $min);
    }

    $rows = [];
    foreach ($users as $u) {
        $rows[] = [
            'volunteer'    => $u['name'],
            'volunteer_id' => $u['id'],
            'offline'      => $u['offline'],
            'shift'        => $title,
            'shift_id'     => $shift->ID,
            'period'       => $period,
            'capacity'     => $capacity_label,
            'start'        => $start_ts,
            'end'          => $end_ts,
            'color'        => $bar_color,
            'open'         => false,
        ];
    }

    // Open slots: the portion still below min_volunteers is critical (red),
    // the rest up to max_volunteers is nice-to-have extra capacity (grey).
    // Skipped entirely when the "show open slots" filter is off.
    if ($show_open) {
        $required_open = max(0, $min - $assigned);
        $optional_open = max(0, $max - $assigned) - $required_open;
        $open_label    = esc_html__('Open slot', 'eventadmin-volunteer-management');
        for ($i = 0; $i < $required_open; $i++) {
            $rows[] = [
                'volunteer'    => $open_label,
                'volunteer_id' => 0,
                'shift'        => $title,
                'shift_id'     => $shift->ID,
                'period'       => $period,
                'capacity'     => $capacity_label,
                'start'        => $start_ts,
                'end'          => $end_ts,
                'color'        => '#e53935',
                'open'         => true,
            ];
        }
        for ($i = 0; $i < $optional_open; $i++) {
            $rows[] = [
                'volunteer'    => $open_label,
                'volunteer_id' => 0,
                'shift'        => $title,
                'shift_id'     => $shift->ID,
                'period'       => $period,
                'capacity'     => $capacity_label,
                'start'        => $start_ts,
                'end'          => $end_ts,
                'color'        => '#9e9e9e',
                'open'         => true,
            ];
        }
    }

    return $rows;
}

/**
 * Renders the Timeline view's Chart.js canvas and its data, or a "no shifts found" message
 * when there's nothing to plot.
 *
 * @param array $timeline_rows From eventadmin_build_overview_rows(); sorted here (by start
 *                              time, then shift, then volunteer) before being handed to JS.
 * @return void
 */
function eventadmin_render_timeline_chart(array $timeline_rows): void
{
    if (empty($timeline_rows)) {
        echo '<p><em>' . esc_html__('No shifts found.', 'eventadmin-volunteer-management') . '</em></p>';
        return;
    }

    // One row per (volunteer, shift) instance, ordered chronologically, then by
    // shift name, then by volunteer name.
    usort($timeline_rows, function ($a, $b) {
        return $a['start'] <=> $b['start']
            ?: $a['shift'] <=> $b['shift']
            ?: $a['volunteer'] <=> $b['volunteer'];
    });

    $row_height = 28;
    $height     = max(200, count($timeline_rows) * $row_height + 60);

    echo '<div style="overflow-x:auto;">';
    echo '<div style="min-width:700px;height:' . esc_attr($height) . 'px;">';
    echo '<canvas id="eventadmin-timeline-chart"></canvas>';
    echo '</div>';
    echo '</div>';

    echo '<script>';
    echo 'const EVENTADMIN_TIMELINE_DATA = ' . wp_json_encode([
        'rows' => $timeline_rows,
    ]);
    echo ';</script>';
}

/**
 * Renders the Timeline-only "Move to another shift" modal and the click-to-act popover
 * shell, then the shared "View profile" + shift modal (with this view's own richer
 * EVENTADMIN_SHIFT_EDIT config layered on) that ties them all together with
 * admin-charts.js — drag-to-move/resize bars, and clicking a filled bar to edit the shift's
 * own details without leaving the page.
 *
 * @param string $view           Current view — no-op on anything other than 'timeline'.
 * @param array  $shift_edit_map From eventadmin_build_overview_rows() — per-shift data for
 *                                the shared shift modal's fast local-cache path.
 * @param bool   $show_open      Whether the Timeline is currently showing open slots.
 * @param string $selected_date  Currently selected date filter, passed through to the JS
 *                                config as the default date for a newly created shift.
 * @return void
 */
function eventadmin_render_timeline_shift_modals_and_config(string $view, array $shift_edit_map, bool $show_open, string $selected_date): void
{
    if ($view !== 'timeline') {
        return;
    }

    // "Move to another shift" modal — a real form POST (not AJAX) since the target
    // shift can be on a completely different day/department than what's currently
    // filtered, and a plain page reload is the simplest way to always end up with a
    // correctly re-rendered view regardless of where the volunteer landed.
    eventadmin_render_modal_open('eventadmin-move-volunteer-modal');
    eventadmin_render_modal_close_button('eventadmin-move-volunteer-close');
    echo '<h2 style="margin-top:0;">' . esc_html__('Move to another shift', 'eventadmin-volunteer-management') . '</h2>';
    echo '<p id="eventadmin-move-volunteer-subtitle" style="color:#666;"></p>';
    echo '<form method="post">';
    wp_nonce_field('eventadmin_move_volunteer', 'eventadmin_move_volunteer_nonce');
    echo '<input type="hidden" name="eventadmin_move_volunteer" value="1">';
    echo '<input type="hidden" name="from_shift_id" id="eventadmin-move-from-shift-id" value="">';
    echo '<input type="hidden" name="user_id" id="eventadmin-move-user-id" value="">';
    echo '<p><label>' . esc_html__('Move to:', 'eventadmin-volunteer-management') . '<br><select name="to_shift_id" id="eventadmin-move-to-shift-select" required style="width:100%;"></select></label></p>';
    echo '<p><label><input type="checkbox" name="notify_volunteer" value="1"> ' . esc_html__('Notify volunteer', 'eventadmin-volunteer-management') . '</label></p>';
    echo '<p>';
    submit_button(esc_html__('Move', 'eventadmin-volunteer-management'), 'primary', '', false);
    echo '</p>';
    echo '</form>';
    eventadmin_render_modal_close();

    // Small click-to-act popover: appears at the clicked bar with "Edit shift" and
    // (for a filled bar) "View profile", "Move to another shift" and "Remove from
    // shift", instead of jumping straight into the Edit Shift modal.
    echo '<div id="eventadmin-timeline-actions" style="display:none;position:absolute;z-index:100002;background:#fff;border:1px solid #e0e0e0;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,.15);padding:6px;box-sizing:border-box;width:min(240px, calc(100vw - 16px));"></div>';

    // Every upcoming shift with a free slot (any day/department), for the "Move to
    // another shift" dropdown — reuses the same "future" definition as the rest of the
    // Overview page (hasn't ended yet, so a shift already in progress is still a valid
    // move target), and skips shifts that are already full.
    $move_target_total = 0;
    $move_target_shifts = eventadmin_get_shifts('', 1, 9999, 'future', 'date', 'ASC', 0, '', $move_target_total);
    $move_shift_options = [];
    foreach ($move_target_shifts as $mts) {
        $mts_start    = get_post_meta($mts->ID, 'shift_start', true);
        $mts_end      = get_post_meta($mts->ID, 'shift_end', true);
        $mts_max      = (int) get_post_meta($mts->ID, 'max_volunteers', true);
        $mts_assigned = eventadmin_count_assignments($mts->ID);
        if ($mts_assigned >= $mts_max) {
            continue;
        }
        $move_shift_options[] = [
            'id'    => $mts->ID,
            'label' => $mts->post_title . ' — ' . eventadmin_get_formatted_zeitraum($mts_start, $mts_end)
                . ' (' . $mts_assigned . '/' . $mts_max . ')',
        ];
    }

    // "View profile" + shared shift modal — the shift modal's markup/AJAX endpoint are the
    // same one used everywhere (see includes/admin/shift-details-modal.php); this view just
    // layers its own local shift cache, move-target list and fuller i18n set on top of the
    // defaults, so opening a shift here is instant and also drives the drag/resize/delete/
    // split/popover behavior in admin-charts.js that only exists on this page.
    eventadmin_render_shared_volunteer_modals([
        'shifts'       => $shift_edit_map,
        'move_shifts'  => $move_shift_options,
        'show_open'    => $show_open,
        'default_date' => $selected_date,
        'i18n'         => [
            'error'             => esc_html__('An error occurred. Please try again.', 'eventadmin-volunteer-management'),
            'loading'           => esc_html__('Loading…', 'eventadmin-volunteer-management'),
            'timeUpdated'       => esc_html__('Shift time updated.', 'eventadmin-volunteer-management'),
            'undo'              => esc_html__('Undo', 'eventadmin-volunteer-management'),
            'editShift'         => esc_html__('Edit shift', 'eventadmin-volunteer-management'),
            'addShift'          => esc_html__('Add shift', 'eventadmin-volunteer-management'),
            'requiredFields'    => esc_html__('Title, start and end are required.', 'eventadmin-volunteer-management'),
            'removeVolunteer'   => esc_html__('Remove from shift', 'eventadmin-volunteer-management'),
            /* translators: %s is the volunteer's name */
            'confirmRemove'     => esc_html__('Remove %s from this shift?', 'eventadmin-volunteer-management'),
            'notifyVolunteer'   => esc_html__('Notify volunteer', 'eventadmin-volunteer-management'),
            'viewProfile'       => esc_html__('View profile', 'eventadmin-volunteer-management'),
            'moveToShift'       => esc_html__('Move to another shift', 'eventadmin-volunteer-management'),
            'addVolunteer'      => esc_html__('Add volunteer', 'eventadmin-volunteer-management'),
            'deleteShift'       => esc_html__('Delete shift', 'eventadmin-volunteer-management'),
            'confirmDeleteShiftEmpty' => esc_html__('Delete this shift?', 'eventadmin-volunteer-management'),
            /* translators: %d is the number of volunteers currently assigned to the shift */
            'confirmDeleteShiftWithVolunteers' => esc_html__('Delete this shift? This will also remove %d assigned volunteer(s) from it.', 'eventadmin-volunteer-management'),
            'cancel'            => esc_html__('Cancel', 'eventadmin-volunteer-management'),
            'confirmButton'     => esc_html__('Confirm', 'eventadmin-volunteer-management'),
            /* translators: %s is the volunteer's name */
            'splitShiftPromptOne' => esc_html__('This shift also has one other person on it. Apply the new time to both, or only to %s?', 'eventadmin-volunteer-management'),
            /* translators: %d is the number of other people on the shift, %s is the volunteer's name */
            'splitShiftPromptMany' => esc_html__('This shift also has %d other people on it. Apply the new time to everyone, or only to %s?', 'eventadmin-volunteer-management'),
            'applyToEveryone'   => esc_html__('Apply to everyone', 'eventadmin-volunteer-management'),
            /* translators: %s is the volunteer's name */
            'applyToOnlyPerson' => esc_html__('Only to %s', 'eventadmin-volunteer-management'),
            'notifyAffectedVolunteers' => esc_html__('Notify affected volunteers', 'eventadmin-volunteer-management'),
        ],
    ]);
}

/**
 * Verifies the nonce shared by every Timeline AJAX action and, when $shift_id is given, also
 * resolves and authorizes that shift. Ends the request with wp_send_json_error() on any
 * failure — same as each caller did inline before this was extracted.
 *
 * @param int|null $shift_id   Pass the posted shift ID to also fetch+authorize it; null to
 *                              only check the nonce (e.g. before a shift exists to create one).
 * @param string   $capability Capability to check against the shift post — ignored when
 *                              $shift_id is null.
 * @return WP_Post|null The authorized shift, or null when $shift_id was null.
 */
function eventadmin_ajax_verify_timeline_nonce(?int $shift_id = null, string $capability = 'edit_post'): ?WP_Post
{
    if (
        !isset($_POST['nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eventadmin_update_shift')
    ) {
        wp_send_json_error(['message' => esc_html__('Security check failed.', 'eventadmin-volunteer-management')]);
    }

    if ($shift_id === null) {
        return null;
    }

    $shift = $shift_id ? get_post($shift_id) : null;

    if (!$shift || $shift->post_type !== 'eventadmin_shift' || !current_user_can($capability, $shift_id)) {
        wp_send_json_error(['message' => esc_html__('Shift not found.', 'eventadmin-volunteer-management')]);
    }

    return $shift;
}

/**
 * AJAX: updates a shift's own details from the Timeline view — dragging a bar sends just
 * start/end, the Edit Shift modal sends everything. Only fields actually present in the
 * request are touched, and the fresh values are returned so the caller can patch its chart
 * data in place without reloading the page.
 */
function eventadmin_ajax_update_shift(): void
{
    $shift_id = isset($_POST['shift_id']) ? absint($_POST['shift_id']) : 0;
    $shift    = eventadmin_ajax_verify_timeline_nonce($shift_id, 'edit_post');

    $time_changed = false;

    if (isset($_POST['title'])) {
        $title = sanitize_text_field(wp_unslash($_POST['title']));
        if ($title !== '') {
            wp_update_post(['ID' => $shift_id, 'post_title' => $title]);
        }
    }

    if (isset($_POST['start'])) {
        update_post_meta($shift_id, 'shift_start', eventadmin_normalize_datetime_input(sanitize_text_field(wp_unslash($_POST['start']))));
        $time_changed = true;
    }

    if (isset($_POST['end'])) {
        update_post_meta($shift_id, 'shift_end', eventadmin_normalize_datetime_input(sanitize_text_field(wp_unslash($_POST['end']))));
        $time_changed = true;
    }

    // For the Getting Started checklist (includes/admin/getting-started-checklist.php) —
    // reschedule_source is only ever sent by the drag/resize save in admin-charts.js, never
    // by the Edit Shift modal's form submit, which is what makes this endpoint's own request
    // shape the only reliable place to detect "the admin actually tried dragging a shift".
    if (($_POST['reschedule_source'] ?? '') === 'drag' && !get_option('eventadmin_used_drag_reschedule')) {
        update_option('eventadmin_used_drag_reschedule', 1);
    }

    if (isset($_POST['min'])) {
        update_post_meta($shift_id, 'min_volunteers', absint($_POST['min']));
    }

    if (isset($_POST['max'])) {
        update_post_meta($shift_id, 'max_volunteers', max(1, absint($_POST['max'])));
    }

    if (isset($_POST['category_id'])) {
        $category_id = absint($_POST['category_id']);
        if ($category_id > 0 && get_term($category_id, 'eventadmin_shift_category')) {
            wp_set_object_terms($shift_id, [$category_id], 'eventadmin_shift_category');
        } else {
            wp_set_object_terms($shift_id, [], 'eventadmin_shift_category');
        }
    }

    if (isset($_POST['description'])) {
        wp_update_post(['ID' => $shift_id, 'post_content' => wp_kses_post(wp_unslash($_POST['description']))]);
    }

    eventadmin_save_shift_organizer_fields($shift_id, $_POST);

    if ($time_changed) {
        eventadmin_clear_shift_reminder_markers($shift_id);
    }

    $start          = get_post_meta($shift_id, 'shift_start', true);
    $end            = get_post_meta($shift_id, 'shift_end', true);
    $terms          = wp_get_post_terms($shift_id, 'eventadmin_shift_category');
    $color          = !empty($terms) ? (get_term_meta($terms[0]->term_id, 'term_color', true) ?: '#2271b1') : '#2271b1';
    $min            = (int) get_post_meta($shift_id, 'min_volunteers', true);
    $max            = (int) get_post_meta($shift_id, 'max_volunteers', true);
    $assigned_count = eventadmin_count_assignments($shift_id);
    $capacity_label = $assigned_count . '/' . $max;
    if ($min > 0) {
        /* translators: %d is the minimum number of volunteers required */
        $capacity_label .= ' ' . sprintf(esc_html__('(min %d)', 'eventadmin-volunteer-management'), $min);
    }

    // Re-fetch: the title/description updates above only touched the database, and this
    // needs the current post_title for the row-rebuild below (post_content isn't used there).
    $fresh_shift = get_post($shift_id);

    wp_send_json_success([
        'shift_id'    => $shift_id,
        'title'       => get_the_title($shift_id),
        'start'       => eventadmin_wallclock_to_ts($start),
        'end'         => eventadmin_wallclock_to_ts($end),
        'period'      => eventadmin_get_formatted_zeitraum($start, $end),
        'category_id' => !empty($terms) ? $terms[0]->term_id : 0,
        'color'       => $color,
        'min'         => $min,
        'max'         => $max,
        'capacity'    => $capacity_label,
        'description' => get_post_field('post_content', $shift_id),
        'organizer_user_id' => (int) get_post_meta($shift_id, 'shift_organizer_user_id', true),
        'organizer_name'    => (string) get_post_meta($shift_id, 'shift_organizer_name', true),
        'organizer_email'   => (string) get_post_meta($shift_id, 'shift_organizer_email', true),
        // Changing min/max can add or remove open-slot rows, not just retext existing ones —
        // rebuilding the full row set (rather than patching rows in place) is what makes that
        // show up immediately instead of only after a reload.
        'rows'        => eventadmin_build_timeline_rows_for_shift($fresh_shift, !empty($_POST['show_open'])),
    ]);
}

add_action('wp_ajax_eventadmin_update_shift', 'eventadmin_ajax_update_shift');

/**
 * AJAX: creates a brand-new shift from the Timeline view's "+ Add shift" modal — the
 * same fields as the Edit Shift modal, just against a post that doesn't exist yet.
 */
function eventadmin_ajax_create_shift(): void
{
    eventadmin_ajax_verify_timeline_nonce();

    if (!current_user_can('eventadmin_manage_shifts')) {
        wp_send_json_error(['message' => esc_html__('Not allowed', 'eventadmin-volunteer-management')]);
    }

    $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
    $start = isset($_POST['start']) ? eventadmin_normalize_datetime_input(sanitize_text_field(wp_unslash($_POST['start']))) : '';
    $end   = isset($_POST['end'])   ? eventadmin_normalize_datetime_input(sanitize_text_field(wp_unslash($_POST['end'])))   : '';

    $description = isset($_POST['description']) ? wp_kses_post(wp_unslash($_POST['description'])) : '';

    $shift_id = wp_insert_post([
        'post_type'    => 'eventadmin_shift',
        'post_status'  => 'publish',
        'post_title'   => $title,
        'post_content' => $description,
    ]);

    if (!$shift_id || is_wp_error($shift_id)) {
        wp_send_json_error(['message' => esc_html__('An error occurred. Please try again.', 'eventadmin-volunteer-management')]);
    }

    update_post_meta($shift_id, 'shift_start', $start);
    update_post_meta($shift_id, 'shift_end', $end);
    update_post_meta($shift_id, 'min_volunteers', isset($_POST['min']) ? absint($_POST['min']) : 0);
    update_post_meta($shift_id, 'max_volunteers', isset($_POST['max']) ? max(1, absint($_POST['max'])) : 1);

    $category_id = isset($_POST['category_id']) ? absint($_POST['category_id']) : 0;
    if ($category_id > 0 && get_term($category_id, 'eventadmin_shift_category')) {
        wp_set_object_terms($shift_id, [$category_id], 'eventadmin_shift_category');
    }

    eventadmin_save_shift_organizer_fields($shift_id, $_POST);

    $shift = get_post($shift_id);
    $terms = wp_get_post_terms($shift_id, 'eventadmin_shift_category');
    $color = !empty($terms) ? (get_term_meta($terms[0]->term_id, 'term_color', true) ?: '#2271b1') : '#2271b1';

    // Always built as if "show open slots" were on, regardless of the page's own filter
    // state — a freshly created shift has no volunteers yet, so it would otherwise be
    // completely invisible (zero rows) the moment it's created.
    $rows = eventadmin_build_timeline_rows_for_shift($shift, true);

    wp_send_json_success([
        'shift_id'    => $shift_id,
        'title'       => $title,
        'start'       => eventadmin_wallclock_to_ts($start),
        'end'         => eventadmin_wallclock_to_ts($end),
        'period'      => eventadmin_get_formatted_zeitraum($start, $end),
        'category_id' => $category_id,
        'color'       => $color,
        'min'         => (int) get_post_meta($shift_id, 'min_volunteers', true),
        'max'         => (int) get_post_meta($shift_id, 'max_volunteers', true),
        'description' => $description,
        'organizer_user_id' => (int) get_post_meta($shift_id, 'shift_organizer_user_id', true),
        'organizer_name'    => (string) get_post_meta($shift_id, 'shift_organizer_name', true),
        'organizer_email'   => (string) get_post_meta($shift_id, 'shift_organizer_email', true),
        'capacity'    => $rows[0]['capacity'] ?? '',
        'edit_url'    => admin_url('post.php?action=edit&post=' . $shift_id),
        'rows'        => $rows,
    ]);
}

add_action('wp_ajax_eventadmin_create_shift', 'eventadmin_ajax_create_shift');

/**
 * AJAX: moves a shift to the trash from the Timeline view's click-to-act popover —
 * recoverable from "All Shifts" like any other trashed post, not a permanent delete.
 */
function eventadmin_ajax_delete_shift(): void
{
    $shift_id = isset($_POST['shift_id']) ? absint($_POST['shift_id']) : 0;
    $shift    = eventadmin_ajax_verify_timeline_nonce($shift_id, 'delete_post');

    // Every assigned volunteer is about to be unassigned as a side effect of the shift
    // itself disappearing — offer the same "let them know" notification the explicit
    // "Remove from shift" action already sends, before the post (and its meta) moves to
    // trash, same as eventadmin_ajax_timeline_unassign() does for a single volunteer.
    if (!empty($_POST['notify_volunteers'])) {
        foreach (get_post_meta($shift_id) as $key => $val) {
            if (!str_starts_with($key, 'assigned_user_')) {
                continue;
            }
            $assigned_user_id = absint($val[0]);
            if ($assigned_user_id && eventadmin_user_can_receive_email($assigned_user_id)) {
                eventadmin_send_shift_un_assignment_notification($assigned_user_id, $shift_id, 'unassign', false, true);
            }
        }
    }

    wp_trash_post($shift_id);

    wp_send_json_success(['shift_id' => $shift_id]);
}

add_action('wp_ajax_eventadmin_delete_shift', 'eventadmin_ajax_delete_shift');

/**
 * AJAX: splits one volunteer off a shared shift into a brand-new shift (same title,
 * department and description, capacity 1) at a new time, leaving the original shift and
 * everyone else on it untouched — used when dragging one person's bar on a multi-occupant
 * shift in the Timeline and answering "only this person" to the resulting prompt.
 */
function eventadmin_ajax_split_shift_volunteer(): void
{
    $shift_id = isset($_POST['shift_id']) ? absint($_POST['shift_id']) : 0;
    $user_id  = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
    $shift    = eventadmin_ajax_verify_timeline_nonce($shift_id, 'edit_post');

    $meta_key = 'assigned_user_' . $user_id;
    if (!$user_id || !get_post_meta($shift_id, $meta_key, true)) {
        wp_send_json_error(['message' => esc_html__('This volunteer is not assigned to this shift.', 'eventadmin-volunteer-management')]);
    }

    $start = isset($_POST['start']) ? eventadmin_normalize_datetime_input(sanitize_text_field(wp_unslash($_POST['start']))) : '';
    $end   = isset($_POST['end'])   ? eventadmin_normalize_datetime_input(sanitize_text_field(wp_unslash($_POST['end'])))   : '';

    $new_shift_id = wp_insert_post([
        'post_type'    => 'eventadmin_shift',
        'post_status'  => 'publish',
        'post_title'   => $shift->post_title,
        'post_content' => $shift->post_content,
    ]);

    if (!$new_shift_id || is_wp_error($new_shift_id)) {
        wp_send_json_error(['message' => esc_html__('An error occurred. Please try again.', 'eventadmin-volunteer-management')]);
    }

    update_post_meta($new_shift_id, 'shift_start', $start);
    update_post_meta($new_shift_id, 'shift_end', $end);
    update_post_meta($new_shift_id, 'min_volunteers', 0);
    update_post_meta($new_shift_id, 'max_volunteers', 1);

    $terms = wp_get_post_terms($shift_id, 'eventadmin_shift_category');
    $category_id = !empty($terms) ? $terms[0]->term_id : 0;
    if ($category_id) {
        wp_set_object_terms($new_shift_id, [$category_id], 'eventadmin_shift_category');
    }

    delete_post_meta($shift_id, $meta_key);
    add_post_meta($new_shift_id, $meta_key, $user_id);
    eventadmin_log_shift_activity('move', $user_id, $shift_id, 'admin', $new_shift_id);

    // The original shift now needs one fewer person — shrink its own min/max to match so
    // its capacity stays consistent instead of quietly implying a spot that no longer
    // needs filling. Never below the number of people still actually on it, and never
    // below zero.
    $remaining_assigned = eventadmin_count_assignments($shift_id);
    $old_max = (int) get_post_meta($shift_id, 'max_volunteers', true);
    if ($old_max > 0) {
        update_post_meta($shift_id, 'max_volunteers', max($remaining_assigned, $old_max - 1));
    }
    $old_min = (int) get_post_meta($shift_id, 'min_volunteers', true);
    if ($old_min > 0) {
        update_post_meta($shift_id, 'min_volunteers', max(0, $old_min - 1));
    }

    $show_open  = !empty($_POST['show_open']);
    $new_shift  = get_post($new_shift_id);
    $color      = $category_id ? (get_term_meta($category_id, 'term_color', true) ?: '#2271b1') : '#2271b1';
    $old_start  = get_post_meta($shift_id, 'shift_start', true);
    $old_end    = get_post_meta($shift_id, 'shift_end', true);

    wp_send_json_success([
        'old_shift_id' => $shift_id,
        'new_shift_id' => $new_shift_id,
        'old_rows'     => eventadmin_build_timeline_rows_for_shift($shift, $show_open),
        'new_rows'     => eventadmin_build_timeline_rows_for_shift($new_shift, $show_open),
        'old_shift'    => [
            'title'       => $shift->post_title,
            'category_id' => $category_id,
            'start'       => eventadmin_wallclock_to_ts($old_start),
            'end'         => eventadmin_wallclock_to_ts($old_end),
            'min'         => (int) get_post_meta($shift_id, 'min_volunteers', true),
            'max'         => (int) get_post_meta($shift_id, 'max_volunteers', true),
            'description' => $shift->post_content,
        ],
        'new_shift'    => [
            'title'       => $shift->post_title,
            'category_id' => $category_id,
            'start'       => eventadmin_wallclock_to_ts($start),
            'end'         => eventadmin_wallclock_to_ts($end),
            'min'         => 0,
            'max'         => 1,
            'description' => $shift->post_content,
            'color'       => $color,
        ],
    ]);
}

add_action('wp_ajax_eventadmin_split_shift_volunteer', 'eventadmin_ajax_split_shift_volunteer');

/**
 * AJAX: removes one volunteer from a shift, triggered from the Timeline view's
 * click-to-act popover. Returns the shift's fresh set of Timeline rows so the caller
 * can patch just that shift's bars in place (an open slot may now appear, and the
 * remaining bars' capacity label changes) without reloading the page.
 */
function eventadmin_ajax_timeline_unassign(): void
{
    $shift_id = isset($_POST['shift_id']) ? absint($_POST['shift_id']) : 0;
    $user_id  = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
    $shift    = eventadmin_ajax_verify_timeline_nonce($shift_id, 'edit_post');

    $meta_key = 'assigned_user_' . $user_id;

    if (!$user_id || !get_post_meta($shift_id, $meta_key, true)) {
        wp_send_json_error(['message' => esc_html__('This volunteer is not assigned to this shift.', 'eventadmin-volunteer-management')]);
    }

    delete_post_meta($shift_id, $meta_key);
    eventadmin_log_shift_activity('unassign', $user_id, $shift_id, 'admin');

    if (!empty($_POST['notify_volunteer']) && eventadmin_user_can_receive_email($user_id)) {
        eventadmin_send_shift_un_assignment_notification($user_id, $shift_id, 'unassign', false, true);
    }

    wp_send_json_success([
        'rows' => eventadmin_build_timeline_rows_for_shift($shift, !empty($_POST['show_open'])),
    ]);
}

add_action('wp_ajax_eventadmin_timeline_unassign', 'eventadmin_ajax_timeline_unassign');

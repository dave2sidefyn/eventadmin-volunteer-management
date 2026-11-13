<?php
/**
 * EventAdmin Volunteer Management - Shared Shift Modal
 * One modal — not two — for "what's this shift about": the same markup, script and AJAX
 * endpoint are used whether it's opened from the Manager/Timeline view (where it doubles as
 * the shift editor and the "+ Add shift" form) or from a volunteer's Upcoming/Past shifts
 * table / the Overview dashboard's activity feed (where it's typically read-only). Anyone
 * who can edit the shift gets the editable form; everyone else gets a read-only summary.
 * Either way, it always lists who's on the shift, each linking back into the "View profile"
 * modal (see includes/admin/user-profile.php), so an admin can hop shift -> volunteer ->
 * shift freely.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Renders the modal shell: a read-only summary (period/department/capacity, shown when the
 * viewer can't edit this shift), the full edit/create form (shown when they can — identical
 * to what the Manager/Timeline view has always used), and the volunteer roster, which is
 * always shown regardless of edit rights.
 */
function eventadmin_render_shift_details_modal_markup(): void
{
    eventadmin_render_modal_open('eventadmin-edit-shift-modal', 600);
    eventadmin_render_modal_close_button('eventadmin-edit-shift-close');
    echo '<h2 id="eventadmin-edit-shift-heading" style="margin-top:0;"></h2>';

    echo '<div id="eventadmin-shift-readonly" style="display:none;">';
    echo '<table class="eventadmin-profile-table eventadmin-profile-summary">';
    echo '<tr><th>' . esc_html__('Period', 'eventadmin-volunteer-management') . '</th><td id="eventadmin-shift-readonly-period"></td></tr>';
    echo '<tr><th>' . esc_html__('Department', 'eventadmin-volunteer-management') . '</th><td id="eventadmin-shift-readonly-department"></td></tr>';
    echo '<tr><th>' . esc_html__('Capacity', 'eventadmin-volunteer-management') . '</th><td id="eventadmin-shift-readonly-capacity"></td></tr>';
    echo '</table>';
    echo '</div>';

    // A plain <div>, not a <form> — this markup renders on user-edit.php too, which is
    // itself one big native WordPress <form>, and a nested <form> there is invalid HTML;
    // browsers silently drop the inner tag (its fields still render, but it's absent from
    // the DOM), which breaks every document.getElementById('eventadmin-edit-shift-form')
    // lookup. The Save button below is a plain button with its own click handler instead
    // of a submit, and required-field checks happen in JS rather than via the "required"
    // attribute, which only browsers enforce on an actual <form> submit.
    echo '<div id="eventadmin-edit-shift-form">';
    echo '<input type="hidden" id="eventadmin-edit-shift-id" value="">';
    echo '<p><label>' . esc_html__('Title', 'eventadmin-volunteer-management') . '<br><input type="text" id="eventadmin-edit-shift-title" style="width:100%;"></label></p>';
    echo '<p><label>' . esc_html__('Department', 'eventadmin-volunteer-management') . '<br><select id="eventadmin-edit-shift-category" style="width:100%;">';
    echo '<option value="0">' . esc_html__('— None —', 'eventadmin-volunteer-management') . '</option>';
    echo eventadmin_category_dropdown_options(eventadmin_get_hierarchical_shift_categories(), 0, 'term_id');
    echo '</select></label></p>';
    echo '<p style="display:flex;gap:8px;">';
    echo '<label style="flex:1;">' . esc_html__('Start', 'eventadmin-volunteer-management') . '<br><input type="datetime-local" id="eventadmin-edit-shift-start" style="width:100%;"></label>';
    echo '<label style="flex:1;">' . esc_html__('End', 'eventadmin-volunteer-management') . '<br><input type="datetime-local" id="eventadmin-edit-shift-end" style="width:100%;"></label>';
    echo '</p>';
    echo '<p style="display:flex;gap:8px;">';
    echo '<label style="flex:1;">' . esc_html__('Min. Volunteers', 'eventadmin-volunteer-management') . '<br><input type="number" id="eventadmin-edit-shift-min" min="0" style="width:100%;"></label>';
    echo '<label style="flex:1;">' . esc_html__('Max. Volunteers', 'eventadmin-volunteer-management') . '<br><input type="number" id="eventadmin-edit-shift-max" min="1" style="width:100%;"></label>';
    echo '</p>';
    echo '<p><label>' . esc_html__('Description', 'eventadmin-volunteer-management') . '</label></p>';
    wp_editor('', 'eventadmin_edit_shift_description', [
        'textarea_name' => 'description',
        'textarea_rows' => 6,
        'media_buttons' => false,
        'teeny'         => true,
        'quicktags'     => false,
    ]);
    echo '<details style="margin:12px 0;">';
    echo '<summary style="cursor:pointer;font-weight:600;padding:4px 0;">' . esc_html__('Advanced', 'eventadmin-volunteer-management') . '</summary>';
    echo '<div style="padding-top:8px;">';
    echo '<p><label>' . esc_html__('Organizer user:', 'eventadmin-volunteer-management') . '<br><select id="eventadmin-edit-shift-organizer-user" style="width:100%;">';
    echo '<option value="">' . esc_html__('— None —', 'eventadmin-volunteer-management') . '</option>';
    foreach (get_users(['orderby' => 'display_name', 'order' => 'ASC', 'role__in' => eventadmin_get_allowed_organizer_roles()]) as $organizer_user) {
        $organizer_label = $organizer_user->display_name;
        if (!empty($organizer_user->user_email)) {
            $organizer_label .= ' (' . $organizer_user->user_email . ')';
        }
        echo '<option value="' . esc_attr($organizer_user->ID) . '">' . esc_html($organizer_label) . '</option>';
    }
    echo '</select></label></p>';
    echo '<p class="description" style="margin-top:-8px;">' . esc_html__('Only staff-side users are shown here by default. If selected, this user is used as the sender fallback for shift emails unless a custom organizer name/email is entered below.', 'eventadmin-volunteer-management') . '</p>';
    echo '<p style="display:flex;gap:8px;">';
    echo '<label style="flex:1;">' . esc_html__('Organizer name:', 'eventadmin-volunteer-management') . '<br><input type="text" id="eventadmin-edit-shift-organizer-name" style="width:100%;"></label>';
    echo '<label style="flex:1;">' . esc_html__('Organizer email:', 'eventadmin-volunteer-management') . '<br><input type="email" id="eventadmin-edit-shift-organizer-email" style="width:100%;"></label>';
    echo '</p>';
    echo '<p class="description" style="margin-top:-8px;">' . esc_html__('Leave empty to use the linked organizer user or the global notification sender.', 'eventadmin-volunteer-management') . '</p>';
    echo '</div>';
    echo '</details>';
    echo '<p id="eventadmin-edit-shift-error" style="color:#d63638;"></p>';
    echo '<p>';
    echo '<button type="button" id="eventadmin-edit-shift-submit" class="button button-primary">' . esc_html__('Save', 'eventadmin-volunteer-management') . '</button> ';
    echo '</p>';
    echo '</div>';

    echo '<section id="eventadmin-shift-roster-section" aria-labelledby="eventadmin-shift-roster-heading" style="display:none;">';
    echo '<h3 id="eventadmin-shift-roster-heading">' . esc_html__('Volunteers on this shift', 'eventadmin-volunteer-management') . '</h3>';
    echo '<div id="eventadmin-shift-roster"></div></section>';

    echo '<dialog id="eventadmin-remove-confirm" aria-labelledby="eventadmin-remove-confirm-heading" aria-describedby="eventadmin-remove-confirm-question eventadmin-remove-confirm-context" style="width:min(440px,calc(100vw - 48px));padding:24px;border:1px solid #c3c4c7;border-radius:8px;box-shadow:0 8px 40px #0004;">';
    echo '<h3 id="eventadmin-remove-confirm-heading" style="margin-top:0;">' . esc_html__('Remove from shift', 'eventadmin-volunteer-management') . '</h3>';
    echo '<p id="eventadmin-remove-confirm-question"></p><p id="eventadmin-remove-confirm-context" class="description"></p>';
    echo '<p id="eventadmin-remove-notify-row"><label><input type="checkbox" id="eventadmin-remove-notify"> ' . esc_html__('Notify volunteer', 'eventadmin-volunteer-management') . '</label></p>';
    echo '<p><button type="button" class="button" id="eventadmin-remove-cancel" autofocus>' . esc_html__('Cancel', 'eventadmin-volunteer-management') . '</button> ';
    echo '<button type="button" class="button button-primary" id="eventadmin-remove-submit">' . esc_html__('Remove from shift', 'eventadmin-volunteer-management') . '</button></p></dialog>';
    eventadmin_render_modal_close();
}

/**
 * Enqueues the shared shift modal's JS and localizes EVENTADMIN_SHIFT_EDIT. $extra lets the
 * Manager/Timeline view (the only screen with a chart to keep in sync, an "Add shift" form,
 * and a "Move to another shift" dropdown) layer its own richer config — the local shift
 * cache for instant open, move-target list, and its own larger i18n set — on top of these
 * universal defaults, which is what every other screen gets as-is.
 *
 * @param array<string, mixed> $extra
 */
function eventadmin_enqueue_shift_details_modal_script(array $extra = []): void
{
    eventadmin_enqueue_searchable_select();
    $shift_details_modal_js_path = plugin_dir_path(__FILE__) . '../../assets/js/shift-details-modal.js';
    wp_enqueue_script(
        'eventadmin-shift-details-modal',
        plugin_dir_url(__FILE__) . '../../assets/js/shift-details-modal.js',
        ['eventadmin-searchable-select'],
        file_exists($shift_details_modal_js_path) ? filemtime($shift_details_modal_js_path) : null,
        true
    );

    $config = array_merge([
        'ajax_url'      => admin_url('admin-ajax.php'),
        'nonce'         => wp_create_nonce('eventadmin_update_shift'),
        'edit_url_base' => admin_url('post.php?action=edit&post='),
        'shifts'        => [],
        'move_shifts'   => [],
        'show_open'     => false,
        'default_date'  => '',
        'i18n'          => [
            'error'          => esc_html__('An error occurred. Please try again.', 'eventadmin-volunteer-management'),
            'loading'        => esc_html__('Loading…', 'eventadmin-volunteer-management'),
            'editShift'      => esc_html__('Edit shift', 'eventadmin-volunteer-management'),
            'addShift'       => esc_html__('Add shift', 'eventadmin-volunteer-management'),
            /* translators: %s is the volunteer's name. */
            'confirmRemove' => esc_html__('Remove %s from this shift?', 'eventadmin-volunteer-management'),
            'requiredFields' => esc_html__('Title, start and end are required.', 'eventadmin-volunteer-management'),
        ],
    ], $extra);

    wp_localize_script('eventadmin-shift-details-modal', 'EVENTADMIN_SHIFT_EDIT', $config);
}

/**
 * AJAX: returns everything the shared shift modal needs to render a shift it doesn't
 * already have cached client-side — the editable field values (used when the viewer can
 * edit_post this shift), a read-only period/department/capacity summary (used when they
 * can't), and the volunteer roster HTML (used either way). Read-only access only requires
 * managing volunteers or shifts; a Volunteer Manager still needs to see who's on a shift
 * from a volunteer's profile even though they can't edit it.
 */
function eventadmin_ajax_get_shift_details(): void
{
    if (
        !isset($_POST['nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'eventadmin_update_shift')
    ) {
        wp_send_json_error(['message' => esc_html__('Security check failed.', 'eventadmin-volunteer-management')]);
    }

    if (!current_user_can('eventadmin_manage_volunteers') && !current_user_can('eventadmin_manage_shifts')) {
        wp_send_json_error(['message' => esc_html__('Not allowed', 'eventadmin-volunteer-management')]);
    }

    $shift_id = isset($_POST['shift_id']) ? absint($_POST['shift_id']) : 0;
    $shift    = $shift_id ? get_post($shift_id) : null;

    if (!$shift || $shift->post_type !== 'eventadmin_shift') {
        wp_send_json_error(['message' => esc_html__('Shift not found.', 'eventadmin-volunteer-management')]);
    }

    $start        = get_post_meta($shift_id, 'shift_start', true);
    $end          = get_post_meta($shift_id, 'shift_end', true);
    $min          = (int) get_post_meta($shift_id, 'min_volunteers', true);
    $max          = (int) get_post_meta($shift_id, 'max_volunteers', true);
    $terms        = wp_get_post_terms($shift_id, 'eventadmin_shift_category');
    $assigned_ids = eventadmin_get_shift_assigned_user_ids($shift_id);
    $can_edit     = current_user_can('edit_post', $shift_id);
    $can_manage_roster = $can_edit && current_user_can('eventadmin_manage_shifts');

    ob_start();
    if (empty($assigned_ids)) {
        echo '<p class="eventadmin-profile-empty">' . esc_html__('No volunteers assigned yet.', 'eventadmin-volunteer-management') . '</p>';
    } else {
        echo '<table class="eventadmin-profile-table eventadmin-shift-volunteer-list"><tbody>';
        foreach ($assigned_ids as $volunteer_id) {
            $volunteer = get_userdata($volunteer_id);
            if (!$volunteer) {
                continue;
            }
            $name = trim($volunteer->first_name . ' ' . $volunteer->last_name) ?: $volunteer->user_login;
            echo '<tr><td><button type="button" class="button-link eventadmin-view-volunteer-profile" data-user-id="' . esc_attr($volunteer_id) . '" data-name="' . esc_attr($name) . '">' . esc_html($name) . '</button></td>';
            if ($can_manage_roster) {
                echo '<td><button type="button" class="button-link eventadmin-roster-remove" data-can-notify="' . (eventadmin_user_can_receive_email($volunteer_id) ? '1' : '0') . '" data-user-id="' . esc_attr($volunteer_id) . '" aria-label="' . esc_attr__('Remove from shift', 'eventadmin-volunteer-management') . ' — ' . esc_attr($name) . '">' . esc_html__('Remove from shift', 'eventadmin-volunteer-management') . '</button></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    if ($can_manage_roster) {
        echo '<div class="eventadmin-roster-controls">';
        if (count($assigned_ids) < $max) {
            echo '<p><button type="button" class="button eventadmin-roster-reveal" aria-expanded="false" aria-controls="eventadmin-roster-add-panel">' . esc_html__('Add volunteer', 'eventadmin-volunteer-management') . '</button></p>';
            echo '<div id="eventadmin-roster-add-panel" class="eventadmin-roster-add-panel" hidden>';
            echo '<p><select class="eventadmin-roster-select" id="eventadmin-roster-select" aria-label="' . esc_attr__('Select existing volunteer…', 'eventadmin-volunteer-management') . '" data-search-label="' . esc_attr__('Search volunteers…', 'eventadmin-volunteer-management') . '" data-empty-label="' . esc_attr__('No volunteers found.', 'eventadmin-volunteer-management') . '">';
            echo '<option value="">' . esc_html__('Select existing volunteer…', 'eventadmin-volunteer-management') . '</option>';
            foreach (get_users(['role' => 'eventadmin_volunteer', 'exclude' => $assigned_ids, 'orderby' => 'display_name', 'order' => 'ASC']) as $candidate) {
                $label = trim($candidate->first_name . ' ' . $candidate->last_name) ?: $candidate->user_login;
                if (eventadmin_user_can_receive_email($candidate->ID)) $label .= ' — ' . $candidate->user_email;
                echo '<option value="' . esc_attr($candidate->ID) . '">' . esc_html($label) . '</option>';
            }
            echo '</select></p>';
            echo '<label><input type="checkbox" class="eventadmin-roster-notify"> ' . esc_html__('Send confirmation email', 'eventadmin-volunteer-management') . '</label>';
            echo '<p><button type="button" class="button button-primary eventadmin-roster-add">' . esc_html__('Add to shift', 'eventadmin-volunteer-management') . '</button> <button type="button" class="button eventadmin-roster-add-cancel">' . esc_html__('Cancel', 'eventadmin-volunteer-management') . '</button></p></div>';
        } else {
            echo '<p>' . esc_html__('This shift is already full.', 'eventadmin-volunteer-management') . '</p>';
        }
        echo '<p class="eventadmin-roster-message" role="status"></p></div>';
    }
    $roster_html = ob_get_clean();

    wp_send_json_success([
        'title'             => $shift->post_title,
        'category_id'       => !empty($terms) && !is_wp_error($terms) ? $terms[0]->term_id : 0,
        'department_names'  => !empty($terms) && !is_wp_error($terms) ? implode(', ', wp_list_pluck($terms, 'name')) : '—',
        'start'             => eventadmin_wallclock_to_ts($start),
        'end'               => eventadmin_wallclock_to_ts($end),
        'period'            => eventadmin_get_formatted_zeitraum($start, $end),
        'min'               => $min,
        'max'               => $max,
        'capacity'          => count($assigned_ids) . '/' . $max,
        'description'       => get_post_field('post_content', $shift_id),
        'organizer_user_id' => (int) get_post_meta($shift_id, 'shift_organizer_user_id', true),
        'organizer_name'    => (string) get_post_meta($shift_id, 'shift_organizer_name', true),
        'organizer_email'   => (string) get_post_meta($shift_id, 'shift_organizer_email', true),
        'roster_html'       => $roster_html,
        'can_edit'          => $can_edit,
        'edit_url'          => $can_edit ? admin_url('post.php?action=edit&post=' . $shift_id) : '',
    ]);
}

add_action('wp_ajax_eventadmin_get_shift_details', 'eventadmin_ajax_get_shift_details');

/** Add/remove a roster member using the same authorization and assignment rules as the manager. */
function eventadmin_ajax_change_shift_roster(): void
{
    $shift_id = absint($_POST['shift_id'] ?? 0);
    $shift = eventadmin_ajax_verify_timeline_nonce($shift_id, 'edit_post');
    if (!current_user_can('eventadmin_manage_shifts')) {
        wp_send_json_error(['message' => esc_html__('Not allowed', 'eventadmin-volunteer-management')]);
    }
    $user_id = absint($_POST['user_id'] ?? 0);
    $user = get_userdata($user_id);
    $operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
    if (!$user || !in_array($operation, ['assign', 'unassign'], true)) {
        wp_send_json_error(['message' => esc_html__('Invalid volunteer selected.', 'eventadmin-volunteer-management')]);
    }
    $key = 'assigned_user_' . $user_id;
    if ($operation === 'assign') {
        if (!in_array('eventadmin_volunteer', (array) $user->roles, true)) {
            wp_send_json_error(['message' => esc_html__('Invalid volunteer selected.', 'eventadmin-volunteer-management')]);
        }
        if (eventadmin_count_assignments($shift_id) >= (int) get_post_meta($shift_id, 'max_volunteers', true)) {
            wp_send_json_error(['message' => esc_html__('This shift is already full.', 'eventadmin-volunteer-management')]);
        }
        $error = eventadmin_check_match_schicht_user($user_id, $shift_id);
        if ($error !== 'ok') wp_send_json_error(['message' => $error]);
        if (!add_post_meta($shift_id, $key, $user_id, true)) {
            wp_send_json_error(['message' => esc_html__('This volunteer is already assigned to this shift.', 'eventadmin-volunteer-management')]);
        }
    } else {
        if (!get_post_meta($shift_id, $key, true)) {
            wp_send_json_error(['message' => esc_html__('This volunteer is not assigned to this shift.', 'eventadmin-volunteer-management')]);
        }
        delete_post_meta($shift_id, $key);
    }
    eventadmin_log_shift_activity($operation, $user_id, $shift_id, 'admin');
    if (!empty($_POST['notify_volunteer']) && eventadmin_user_can_receive_email($user_id)) {
        eventadmin_send_shift_un_assignment_notification($user_id, $shift_id, $operation, false, true);
    }
    wp_send_json_success([
        'rows' => eventadmin_build_timeline_rows_for_shift($shift, !empty($_POST['show_open'])),
        'assigned' => eventadmin_get_shift_assigned_user_ids($shift_id),
    ]);
}
add_action('wp_ajax_eventadmin_change_shift_roster', 'eventadmin_ajax_change_shift_roster');

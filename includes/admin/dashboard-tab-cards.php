<?php
/**
 * EventAdmin Volunteer Management - Cards (old) Tab
 * Renders the legacy Cards tab: one self-contained card per shift.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Renders one shift's card for the Cards (old) view — CSV export, period/capacity summary,
 * an "add volunteer" toggle form, and the roster table with per-volunteer "Remove" actions.
 *
 * @param WP_Post $shift  The shift post.
 * @param string  $title  Escaped shift title (from eventadmin_build_overview_rows()).
 * @param string  $start  Escaped raw shift_start meta value.
 * @param string  $end    Escaped raw shift_end meta value.
 * @param int     $min    Minimum volunteers.
 * @param int     $max    Maximum volunteers.
 * @param array   $users  Assigned volunteers, from eventadmin_build_overview_rows().
 * @return void
 */
function eventadmin_render_card_for_shift(WP_Post $shift, string $title, string $start, string $end, int $min, int $max, array $users): void
{
    $filled = count($users);
    $shift_entry_type = $filled < $max - 1
        ? 'shift-entry-open'
        : ($filled < $max
            ? 'shift-entry-almost-full'
            : 'shift-entry-full');

    echo '<div class="shift-entry ' . esc_attr($shift_entry_type) . '">';
    // CSV export per shift
    echo '<form method="post" class="form-export-shift">';
    wp_nonce_field('eventadmin_export_shift', 'eventadmin_export_shift_nonce');
    echo '<input type="hidden" name="eventadmin_export_shift" value="' . esc_attr($shift->ID) . '">';
    submit_button(esc_html__('CSV for this shift', 'eventadmin-volunteer-management'), 'small', '', false);
    echo '</form>';

    echo '<h2>' . esc_html($title) . '</h2>';
    echo '<p><strong>' . esc_html__('Period:', 'eventadmin-volunteer-management') . '</strong> ' . esc_html(eventadmin_get_formatted_zeitraum($start, $end)) . '</p>';
    echo '<p><strong>' . esc_html__('Filled:', 'eventadmin-volunteer-management') . '</strong> ' . esc_html($filled) . '/' . esc_html($max);
    if ($min > 0) {
        echo ' &nbsp;<strong>' . esc_html__('Min.:', 'eventadmin-volunteer-management') . '</strong> ' . esc_html($min);
        if ($filled < $min) {
            echo ' &nbsp;<span style="color:#d63638;">&#9888; ' . esc_html__('Understaffed', 'eventadmin-volunteer-management') . '</span>';
        }
    }
    echo '</p>';

    $toggle_id = 'add-volunteer-form-' . $shift->ID;

    echo '<p><a href="#" class="toggle-volunteer-form" data-target="#' . esc_attr($toggle_id) . '">' . esc_html__('Add volunteers manually', 'eventadmin-volunteer-management') . '</a></p>';

    echo '<div id="' . esc_attr($toggle_id) . '" class="manual-volunteer-form" style="display:none;">';

    // Existing volunteer selector (exclude already-assigned and offline users)
    $assigned_ids = array_column($users, 'id');
    $existing_volunteers = get_users([
        'role'       => 'eventadmin_volunteer',
        'exclude'    => $assigned_ids,
        'meta_query' => [['key' => 'eventadmin_offline_volunteer', 'compare' => 'NOT EXISTS']],
    ]);
    // Sort by the same first/last name shown in the option label below — registration
    // never sets WP's display_name, so sorting by it (as before) put volunteers in
    // an order unrelated to what the dropdown actually displays.
    usort($existing_volunteers, function ($a, $b) {
        $label_a = trim($a->first_name . ' ' . $a->last_name) ?: $a->user_login;
        $label_b = trim($b->first_name . ' ' . $b->last_name) ?: $b->user_login;
        return strcasecmp($label_a, $label_b);
    });
    if (!empty($existing_volunteers)) {
        echo '<form method="post" style="margin-bottom:12px;">';
        wp_nonce_field('eventadmin_add_user', 'eventadmin_add_user_nonce');
        echo '<input type="hidden" name="eventadmin_admin_add_user" value="1">';
        echo '<input type="hidden" name="shift_id" value="' . esc_attr($shift->ID) . '">';
        echo '<input type="hidden" name="assign_existing" value="1">';
        echo '<select name="existing_user_id" style="max-width:220px;margin-right:4px;">';
        echo '<option value="">' . esc_html__('Select existing volunteer…', 'eventadmin-volunteer-management') . '</option>';
        foreach ($existing_volunteers as $v) {
            $label = trim(get_user_meta($v->ID, 'first_name', true) . ' ' . get_user_meta($v->ID, 'last_name', true)) ?: $v->user_login;
            echo '<option value="' . esc_attr($v->ID) . '">' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<label style="margin-right:8px;"><input type="checkbox" name="notify_volunteer" value="1"> ' . esc_html__('Send confirmation email', 'eventadmin-volunteer-management') . '</label>';
        submit_button(esc_html__('Add to shift', 'eventadmin-volunteer-management'), 'secondary small', '', false);
        echo '</form>';
        echo '<p style="margin:0 0 8px;color:#888;font-size:12px;font-style:italic;">— ' . esc_html__('or add a new volunteer below', 'eventadmin-volunteer-management') . ' —</p>';
    }

    // New volunteer form (email optional — leave blank for offline volunteers)
    echo '<form method="post">';
    wp_nonce_field('eventadmin_add_user', 'eventadmin_add_user_nonce');
    echo '<input type="hidden" name="eventadmin_admin_add_user" value="1">';
    echo '<input type="hidden" name="shift_id" value="' . esc_attr($shift->ID) . '">';
    echo '<input type="text" name="first_name" placeholder="' . esc_html__('First name', 'eventadmin-volunteer-management') . '" class="add-volunteer-firstname" title="' . esc_html__('First name', 'eventadmin-volunteer-management') . '" required>';
    echo '<input type="text" name="last_name" placeholder="' . esc_html__('Last name', 'eventadmin-volunteer-management') . '" class="add-volunteer-lastname" title="' . esc_html__('Last name', 'eventadmin-volunteer-management') . '">';
    echo '<input type="text" name="user_identifier" placeholder="' . esc_html__('E-Mail (optional)', 'eventadmin-volunteer-management') . '" class="add-volunteer-email" title="' . esc_html__('Leave blank for offline volunteers without an email address', 'eventadmin-volunteer-management') . '">';
    echo '<input type="text" name="phone" placeholder="' . esc_html__('Phone', 'eventadmin-volunteer-management') . '" class="add-volunteer-phone" title="' . esc_html__('Phone', 'eventadmin-volunteer-management') . '">';
    echo '<label style="display:inline-block;margin-right:8px;"><input type="checkbox" name="notify_volunteer" value="1"> ' . esc_html__('Send confirmation email', 'eventadmin-volunteer-management') . '</label>';
    submit_button(esc_html__('Add', 'eventadmin-volunteer-management'), 'secondary small', '', false);
    echo '</form>';

    echo '</div>';

    if (!empty($users)) {
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>' . esc_html__('Name', 'eventadmin-volunteer-management') . '</th><th>' . esc_html__('E-Mail', 'eventadmin-volunteer-management') . '</th><th>' . esc_html__('Phone', 'eventadmin-volunteer-management') . '</th><th>' . esc_html__('Action', 'eventadmin-volunteer-management') . '</th></tr></thead><tbody>';
        foreach ($users as $u) {
            echo '<tr>';
            echo '<td>' . esc_html($u['name']);
            if (!empty($u['offline'])) echo ' <span style="background:#888;color:#fff;font-size:10px;padding:1px 6px;border-radius:3px;vertical-align:middle;">' . esc_html__('Offline', 'eventadmin-volunteer-management') . '</span>';
            echo '</td>';
            echo '<td>' . (!empty($u['offline']) ? '<em style="color:#aaa;">—</em>' : esc_html($u['email'])) . '</td>';
            echo '<td>' . esc_html($u['phone']) . '</td>';
            echo '<td>';
            echo '<form method="post">';
            wp_nonce_field('eventadmin_unassign', 'eventadmin_unassign_nonce');
            echo '<input type="hidden" name="eventadmin_admin_unassign" value="1">';
            echo '<input type="hidden" name="user_id" value="' . esc_attr($u['id']) . '">';
            echo '<input type="hidden" name="shift_id" value="' . esc_attr($shift->ID) . '">';
            // Offline volunteers have no email address to notify — the checkbox would
            // be a no-op (already silently skipped server-side), so don't show it at all.
            if (empty($u['offline'])) {
                echo '<label style="margin-right:8px;"><input type="checkbox" name="notify_volunteer" value="1"> ' . esc_html__('Notify volunteer', 'eventadmin-volunteer-management') . '</label>';
            }
            submit_button(esc_html__('Remove', 'eventadmin-volunteer-management'), 'delete small', '', false);
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

    } else {
        echo '<p><em>' . esc_html__('No volunteers assigned.', 'eventadmin-volunteer-management') . '</em></p>';
    }


    echo '</div><hr>';
}

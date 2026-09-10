<?php
/**
 * EventAdmin Volunteer Management - Bulk Email
 * Sends a custom announcement email to all or subscribed volunteers in batches.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Registers the Bulk Email submenu page
 */
function eventadmin_bulk_email_admin_menu(): void
{
    add_submenu_page(
        'edit.php?post_type=eventadmin_shift',
        esc_html__('Send Announcement', 'eventadmin-volunteer-management'),
        esc_html__('Send Announcement', 'eventadmin-volunteer-management'),
        'manage_options',
        'eventadmin-bulk-email',
        'eventadmin_bulk_email_page'
    );
}

add_action('admin_menu', 'eventadmin_bulk_email_admin_menu', 100);

/**
 * Renders the Bulk Email admin page
 */
function eventadmin_bulk_email_page(): void
{
    $preset_user_id = isset($_GET['recipient_user_id']) ? absint($_GET['recipient_user_id']) : 0;
    $preset_user    = $preset_user_id ? get_userdata($preset_user_id) : null;

    $log  = get_option('eventadmin_email_log', []);
    $tabs = [
        'send'    => esc_html__('Send Announcement', 'eventadmin-volunteer-management'),
        /* translators: %d is the number of previously sent announcements */
        'history' => sprintf(esc_html__('Send History (%d)', 'eventadmin-volunteer-management'), count($log)),
    ];
    $active_tab = isset($_GET['tab']) && array_key_exists(sanitize_key(wp_unslash($_GET['tab'])), $tabs)
        ? sanitize_key(wp_unslash($_GET['tab']))
        : 'send';

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Send Announcement to Volunteers', 'eventadmin-volunteer-management') . '</h1>';

    echo '<h2 class="nav-tab-wrapper">';
    foreach ($tabs as $slug => $label) {
        $url    = admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-bulk-email&tab=' . $slug);
        $active = $active_tab === $slug ? ' nav-tab-active' : '';
        echo '<a class="nav-tab' . $active . '" href="' . esc_url($url) . '">' . $label . '</a>';
    }
    echo '</h2>';

    wp_enqueue_media();

    wp_enqueue_script(
        'eventadmin-bulk-email',
        plugin_dir_url(__FILE__) . '../../assets/js/bulk-email.js',
        ['jquery', 'editor'],
        '1.0',
        true
    );

    wp_localize_script('eventadmin-bulk-email', 'EVENTADMIN_BULK_EMAIL', [
        'ajax_url'    => admin_url('admin-ajax.php'),
        'nonce_batch'   => wp_create_nonce('eventadmin_bulk_email_batch'),
        'nonce_preview' => wp_create_nonce('eventadmin_email_preview'),
        'i18n'        => [
            'done'               => esc_html__('Done! All emails sent.', 'eventadmin-volunteer-management'),
            'failed'             => esc_html__('({failed} could not be delivered)', 'eventadmin-volunteer-management'),
            'error'              => esc_html__('An error occurred. Please try again.', 'eventadmin-volunteer-management'),
            'sending'            => esc_html__('Sent {sent} of {total}…', 'eventadmin-volunteer-management'),
            'counting'           => esc_html__('Counting…', 'eventadmin-volunteer-management'),
            'recipientCountOne'  => esc_html__('{n} recipient', 'eventadmin-volunteer-management'),
            'recipientCountMany' => esc_html__('{n} recipients', 'eventadmin-volunteer-management'),
            'selectPdfTitle'     => esc_html__('Select a PDF to attach', 'eventadmin-volunteer-management'),
            'selectPdfButton'    => esc_html__('Use this PDF', 'eventadmin-volunteer-management'),
        ],
    ]);

    if ($active_tab === 'history') {
        eventadmin_bulk_email_render_history_tab($log);
        echo '</div>';
        return;
    }

    echo '<form id="eventadmin-bulk-email-form" method="post" style="margin-top:1rem;">';
    wp_nonce_field('eventadmin_bulk_email_init', 'eventadmin_bulk_email_nonce');

    $default_from_name  = get_option('eventadmin_notification_email_name', get_bloginfo('name'));
    $default_from_email = get_option('eventadmin_notification_email', get_option('admin_email'));

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(360px, 1fr));gap:24px;align-items:start;max-width:1100px;">';

    // Row 1, left: Recipients
    echo '<div>';
    echo '<h3 style="margin-top:0;">' . esc_html__('Recipients', 'eventadmin-volunteer-management') . '</h3>';

    $offline_exclude = ['key' => 'eventadmin_offline_volunteer', 'compare' => 'NOT EXISTS'];
    $users_subscribed = eventadmin_bulk_email_get_recipient_users('subscribed', 0, 0, 0);
    $users_all        = eventadmin_bulk_email_get_recipient_users('all', 0, 0, 0);
    $users_no_shift   = eventadmin_bulk_email_get_recipient_users('no_shift', 0, 0, 0);
    $users_has_shift  = eventadmin_bulk_email_get_recipient_users('has_shift', 0, 0, 0);
    $count_subscribed = count($users_subscribed);
    $count_all        = count($users_all);
    $count_no_shift   = count($users_no_shift);
    $count_has_shift  = count($users_has_shift);
    $tooltip_subscribed = eventadmin_bulk_email_build_recipient_tooltip($users_subscribed, 'subscribed');
    $tooltip_all        = eventadmin_bulk_email_build_recipient_tooltip($users_all, 'all');
    $tooltip_no_shift   = eventadmin_bulk_email_build_recipient_tooltip($users_no_shift, 'no_shift');
    $tooltip_has_shift  = eventadmin_bulk_email_build_recipient_tooltip($users_has_shift, 'has_shift');

    $all_shifts     = get_posts([
        'post_type'   => 'eventadmin_shift',
        'numberposts' => -1,
        'meta_key'    => 'shift_start',
        'orderby'     => ['title' => 'ASC', 'meta_value' => 'ASC'],
        'meta_type'   => 'DATETIME',
    ]);
    $all_categories = eventadmin_get_hierarchical_shift_categories();

    if ($preset_user) {
        $preset_name = esc_html(trim($preset_user->first_name . ' ' . $preset_user->last_name) ?: $preset_user->user_login);
        echo '<label><input type="radio" name="bulk_email_recipients" value="user" checked> ';
        echo $preset_name;
        echo '</label><br>';
        echo '<input type="hidden" name="bulk_email_user_id" value="' . esc_attr($preset_user_id) . '">';
    }
    echo '<label><input type="radio" name="bulk_email_recipients" value="subscribed"' . ($preset_user ? '' : ' checked') . '> ';
    echo esc_html__('Subscribed volunteers only (opted-in)', 'eventadmin-volunteer-management');
    echo ' &nbsp;<span class="bulk-email-count" data-for="subscribed" style="color:#666;font-style:italic;cursor:help;" title="' . esc_attr($tooltip_subscribed) . '">';
    /* translators: %d number of recipients */
    echo esc_html(sprintf(_n('%d recipient', '%d recipients', $count_subscribed, 'eventadmin-volunteer-management'), $count_subscribed));
    echo '</span></label><br>';
    echo '<label><input type="radio" name="bulk_email_recipients" value="all"> ';
    echo esc_html__('All volunteers', 'eventadmin-volunteer-management');
    echo ' &nbsp;<span class="bulk-email-count" data-for="all" style="color:#666;font-style:italic;display:none;cursor:help;" title="' . esc_attr($tooltip_all) . '">';
    echo esc_html(sprintf(_n('%d recipient', '%d recipients', $count_all, 'eventadmin-volunteer-management'), $count_all));
    echo '</span></label><br>';
    echo '<label><input type="radio" name="bulk_email_recipients" value="no_shift"> ';
    echo esc_html__('Volunteers without any upcoming shift', 'eventadmin-volunteer-management');
    echo ' &nbsp;<span class="bulk-email-count" data-for="no_shift" style="color:#666;font-style:italic;display:none;cursor:help;" title="' . esc_attr($tooltip_no_shift) . '">';
    echo esc_html(sprintf(_n('%d recipient', '%d recipients', $count_no_shift, 'eventadmin-volunteer-management'), $count_no_shift));
    echo '</span></label><br>';
    echo '<label><input type="radio" name="bulk_email_recipients" value="has_shift"> ';
    echo esc_html__('Volunteers with at least one upcoming shift', 'eventadmin-volunteer-management');
    echo ' &nbsp;<span class="bulk-email-count" data-for="has_shift" style="color:#666;font-style:italic;display:none;cursor:help;" title="' . esc_attr($tooltip_has_shift) . '">';
    echo esc_html(sprintf(_n('%d recipient', '%d recipients', $count_has_shift, 'eventadmin-volunteer-management'), $count_has_shift));
    echo '</span></label><br>';
    echo '<label><input type="radio" name="bulk_email_recipients" value="shift"> ';
    echo esc_html__('Volunteers of a specific shift', 'eventadmin-volunteer-management');
    echo '</label>';
    echo '<div id="eventadmin-shift-select-wrap" style="margin-top:8px;display:none;">';
    echo '<select name="bulk_email_shift_id">';
    echo '<option value="">' . esc_html__('— Select shift —', 'eventadmin-volunteer-management') . '</option>';
    foreach ($all_shifts as $shift) {
        $start = get_post_meta($shift->ID, 'shift_start', true);
        $end   = get_post_meta($shift->ID, 'shift_end', true);
        $label = esc_html($shift->post_title) . ($start ? ' (' . esc_html(eventadmin_get_formatted_zeitraum($start, $end)) . ')' : '');
        echo '<option value="' . esc_attr($shift->ID) . '">' . $label . '</option>';
    }
    echo '</select>';
    echo ' <span id="eventadmin-shift-recipient-count" style="color:#666;font-style:italic;"></span>';
    echo '</div>';
    if (!empty($all_categories)) {
        echo '<br><label><input type="radio" name="bulk_email_recipients" value="category"> ';
        echo esc_html__('Volunteers of a specific category', 'eventadmin-volunteer-management');
        echo '</label>';
        echo '<div id="eventadmin-category-select-wrap" style="margin-top:8px;display:none;">';
        echo '<select name="bulk_email_category_id">';
        echo '<option value="">' . esc_html__('— Select category —', 'eventadmin-volunteer-management') . '</option>';
        echo eventadmin_category_dropdown_options($all_categories, 0, 'term_id');
        echo '</select>';
        echo ' <span id="eventadmin-category-recipient-count" style="color:#666;font-style:italic;"></span>';
        echo '</div>';
    }
    echo '</div>'; // end Recipients cell

    // Row 1, right: Sender
    echo '<div>';
    echo '<h3 style="margin-top:0;">' . esc_html__('Sender', 'eventadmin-volunteer-management') . '</h3>';
    echo '<p><label for="bulk_from_name">' . esc_html__('From name', 'eventadmin-volunteer-management') . '</label><br>';
    echo '<input type="text" id="bulk_from_name" name="bulk_email_from_name" style="width:100%;" value="' . esc_attr($default_from_name) . '" required></p>';
    echo '<p><label for="bulk_from_email">' . esc_html__('From email', 'eventadmin-volunteer-management') . '</label><br>';
    echo '<input type="email" id="bulk_from_email" name="bulk_email_from_email" style="width:100%;" value="' . esc_attr($default_from_email) . '" required></p>';
    echo '</div>';

    // Row 2, left: Subject
    echo '<div>';
    echo '<h3 style="margin-top:0;">' . esc_html__('Subject', 'eventadmin-volunteer-management') . '</h3>';
    echo '<input type="text" id="bulk_email_subject" name="bulk_email_subject" style="width:100%;" required>';
    echo '</div>';

    // Row 2, right: Attachment
    echo '<div>';
    echo '<h3 style="margin-top:0;">' . esc_html__('Attachment (optional)', 'eventadmin-volunteer-management') . '</h3>';
    echo '<input type="hidden" id="bulk_email_attachment_id" name="bulk_email_attachment_id" value="">';
    echo '<button type="button" id="bulk_email_attachment_button" class="button">' . esc_html__('Select PDF…', 'eventadmin-volunteer-management') . '</button>';
    echo ' <span id="bulk_email_attachment_name" style="margin-left:8px;"></span>';
    echo ' <a href="#" id="bulk_email_attachment_remove" style="margin-left:8px;display:none;">' . esc_html__('Remove', 'eventadmin-volunteer-management') . '</a>';
    echo '<p class="description">' . esc_html__('The PDF will be attached to every email in this announcement.', 'eventadmin-volunteer-management') . '</p>';
    echo '</div>';

    // Row 3, left: Message
    echo '<div>';
    echo '<h3 style="margin-top:0;">' . esc_html__('Message', 'eventadmin-volunteer-management') . '</h3>';
    wp_editor('', 'bulk_email_body', [
        'textarea_name' => 'bulk_email_body',
        'textarea_rows' => 14,
        'media_buttons' => true,
        'teeny'         => true,
        'quicktags'     => false,
    ]);
    echo '</div>';

    // Row 3, right: live preview
    echo '<div>';
    echo '<h3 style="margin-top:0;">' . esc_html__('Preview (example data)', 'eventadmin-volunteer-management') . '</h3>';
    echo '<p class="description">' . esc_html__('Use {first_name} and {last_name} as placeholders in the message body. Use {shifts} to list each recipient\'s own upcoming shifts.', 'eventadmin-volunteer-management') . '</p>';
    echo '<div id="eventadmin-email-preview" style="background:#f6f7f7;border:1px solid #dcdcde;padding:16px;">';
    echo '<p style="margin:0 0 4px;"><strong>' . esc_html__('From:', 'eventadmin-volunteer-management') . '</strong> <span id="ea-preview-from"></span></p>';
    echo '<p id="ea-preview-attachment-row" style="margin:0 0 4px;display:none;"><strong>' . esc_html__('Attachment:', 'eventadmin-volunteer-management') . '</strong> <span id="ea-preview-attachment"></span></p>';
    echo '<iframe id="ea-preview-body" title="' . esc_attr__('Preview (example data)', 'eventadmin-volunteer-management') . '" style="display:block;width:100%;height:460px;border:1px solid #dcdcde;background:#fff;margin-top:8px;"></iframe>';
    echo '</div>';
    echo '</div>';

    echo '</div>'; // end grid

    echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__('Start sending', 'eventadmin-volunteer-management') . '</button></p>';
    echo '</form>';

    // Progress UI (hidden until send starts)
    echo '<div id="eventadmin-bulk-email-progress" style="display:none;">';
    echo '<h2>' . esc_html__('Sending in progress…', 'eventadmin-volunteer-management') . '</h2>';
    echo '<div style="background:#e0e0e0;border-radius:4px;height:24px;width:100%;max-width:600px;">';
    echo '<div id="eventadmin-bulk-email-bar" style="background:#2271b1;height:24px;border-radius:4px;width:0%;transition:width .3s;"></div>';
    echo '</div>';
    echo '<p id="eventadmin-bulk-email-status"></p>';
    echo '</div>';

    echo '</div>';
}

/**
 * Renders the "Send History" tab: a filterable, sortable table of every previously sent
 * announcement.
 *
 * @param array $log Entries from the 'eventadmin_email_log' option.
 * @return void
 */
function eventadmin_bulk_email_render_history_tab(array $log): void
{
    echo '<div style="margin-top:1rem;">';

    if (empty($log)) {
        echo '<p><em>' . esc_html__('No announcements sent yet.', 'eventadmin-volunteer-management') . '</em></p>';
    } else {
        echo '<p style="margin:12px 0 8px;">';
        echo '<input type="search" id="eventadmin-history-filter" placeholder="' . esc_attr__('Filter…', 'eventadmin-volunteer-management') . '" class="regular-text">';
        echo '</p>';
        echo '<table id="eventadmin-history-table" class="widefat striped" style="margin-top:0;">';
        echo '<thead><tr>';
        foreach ([
            'date'       => esc_html__('Date', 'eventadmin-volunteer-management'),
            'subject'    => esc_html__('Subject', 'eventadmin-volunteer-management'),
            'recipients' => esc_html__('Recipients', 'eventadmin-volunteer-management'),
            'from'       => esc_html__('From', 'eventadmin-volunteer-management'),
            'total'      => esc_html__('Sent to', 'eventadmin-volunteer-management'),
            'failed'     => esc_html__('Failed', 'eventadmin-volunteer-management'),
            'sent_by'    => esc_html__('Sent by', 'eventadmin-volunteer-management'),
            'message'    => esc_html__('Message', 'eventadmin-volunteer-management'),
        ] as $col => $label) {
            $sortable = $col !== 'message';
            echo '<th' . ($sortable ? ' data-sort="' . esc_attr($col) . '" style="cursor:pointer;user-select:none;" title="' . esc_attr__('Click to sort', 'eventadmin-volunteer-management') . '"' : '') . '>';
            echo $label;
            if ($sortable) echo ' <span class="eventadmin-sort-icon" style="opacity:.4;">↕</span>';
            echo '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($log as $entry) {
            $sender      = get_userdata((int)($entry['sent_by'] ?? 0));
            $sender_name = $sender ? trim($sender->first_name . ' ' . $sender->last_name) ?: $sender->user_login : '—';
            $entry_recip = $entry['recipients'] ?? 'all';
            if ($entry_recip === 'subscribed') {
                $recipients_label = __('Subscribed', 'eventadmin-volunteer-management');
            } elseif ($entry_recip === 'no_shift') {
                $recipients_label = __('Without an upcoming shift', 'eventadmin-volunteer-management');
            } elseif ($entry_recip === 'has_shift') {
                $recipients_label = __('With an upcoming shift', 'eventadmin-volunteer-management');
            } elseif (str_starts_with($entry_recip, 'shift:')) {
                /* translators: %s is the shift title */
                $recipients_label = sprintf(__('Shift: %s', 'eventadmin-volunteer-management'), substr($entry_recip, 6));
            } elseif (str_starts_with($entry_recip, 'category:')) {
                /* translators: %s is the category name */
                $recipients_label = sprintf(__('Category: %s', 'eventadmin-volunteer-management'), substr($entry_recip, 9));
            } elseif (str_starts_with($entry_recip, 'user:')) {
                $recipients_label = substr($entry_recip, 5);
            } else {
                $recipients_label = __('All', 'eventadmin-volunteer-management');
            }
            $from_name  = $entry['from_name']  ?? '';
            $from_email = $entry['from_email'] ?? '';
            $from_label = $from_name ? $from_name . ($from_email ? ' <' . $from_email . '>' : '') : $from_email;
            $failed_count    = (int)($entry['failed'] ?? 0);
            $full_body       = wp_kses_post($entry['body'] ?? '');
            $preview         = esc_html(wp_strip_all_tags(mb_strimwidth($entry['body'] ?? '', 0, 80, '…')));
            $date            = $entry['date'] ?? '';
            $attachment_name = $entry['attachment_name'] ?? '';

            echo '<tr'
                . ' data-date="' . esc_attr($date) . '"'
                . ' data-subject="' . esc_attr($entry['subject'] ?? '') . '"'
                . ' data-recipients="' . esc_attr($recipients_label) . '"'
                . ' data-from="' . esc_attr($from_label) . '"'
                . ' data-total="' . esc_attr((int)($entry['total'] ?? 0)) . '"'
                . ' data-failed="' . esc_attr($failed_count) . '"'
                . ' data-sent_by="' . esc_attr($sender_name) . '"'
                . '>';
            echo '<td>' . esc_html($date) . '</td>';
            echo '<td>' . esc_html($entry['subject'] ?? '') . '</td>';
            echo '<td>' . esc_html($recipients_label) . '</td>';
            echo '<td><small>' . esc_html($from_label ?: '—') . '</small></td>';
            echo '<td>' . esc_html((int)($entry['total'] ?? 0)) . '</td>';
            echo '<td>' . ($failed_count > 0 ? '<span style="color:#d63638;">' . esc_html($failed_count) . '</span>' : '0') . '</td>';
            echo '<td>' . esc_html($sender_name) . '</td>';
            echo '<td><details><summary style="cursor:pointer;"><small>' . $preview . '</small></summary>';
            if ($attachment_name) {
                echo '<p style="margin:8px 0 0;font-size:12px;"><strong>' . esc_html__('Attachment:', 'eventadmin-volunteer-management') . '</strong> &#128206; ' . esc_html($attachment_name) . '</p>';
            }
            echo '<div style="margin:8px 0 0;font-size:12px;max-width:500px;">' . $full_body . '</div></details></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    echo '</div>';
}

/**
 * Resolves the WP_User objects for a given recipient selection.
 * Shared by the job initializer and the live recipient-count lookup.
 *
 * @param string $recipients  One of 'all', 'subscribed', 'shift', 'category', 'user'.
 * @param int    $shift_id    Shift post ID (for 'shift').
 * @param int    $category_id Term ID (for 'category').
 * @param int    $target_user_id User ID (for 'user').
 * @return WP_User[]
 */
function eventadmin_bulk_email_get_recipient_users(string $recipients, int $shift_id, int $category_id, int $target_user_id): array
{
    $offline_exclude = ['key' => 'eventadmin_offline_volunteer', 'compare' => 'NOT EXISTS'];

    if ($recipients === 'subscribed') {
        // Users who opted in (meta=1) or have no preference set (meta doesn't exist); never offline
        return get_users([
            'role'       => 'eventadmin_volunteer',
            'meta_query' => [
                'relation' => 'AND',
                $offline_exclude,
                [
                    'relation' => 'OR',
                    ['key' => 'eventadmin_announcements', 'compare' => 'NOT EXISTS'],
                    ['key' => 'eventadmin_announcements', 'value' => '1'],
                ],
            ],
        ]);
    }

    if ($recipients === 'category') {
        if (!$category_id) return [];
        $cat_shifts = get_posts([
            'post_type'   => 'eventadmin_shift',
            'numberposts' => -1,
            'fields'      => 'ids',
            'tax_query'   => [['taxonomy' => 'eventadmin_shift_category', 'field' => 'term_id', 'terms' => $category_id]],
        ]);
        $cat_user_ids = [];
        foreach ($cat_shifts as $sid) {
            foreach (get_post_meta($sid) as $key => $val) {
                if (str_starts_with($key, 'assigned_user_')) {
                    $cat_user_ids[] = absint($val[0]);
                }
            }
        }
        $cat_user_ids = array_values(array_unique($cat_user_ids));
        return empty($cat_user_ids) ? [] : get_users([
            'include'    => $cat_user_ids,
            'meta_query' => [$offline_exclude],
        ]);
    }

    if ($recipients === 'user') {
        if (!$target_user_id) return [];
        $target_user = get_userdata($target_user_id);
        return $target_user ? [$target_user] : [];
    }

    if ($recipients === 'shift') {
        if (!$shift_id) return [];
        $shift_meta     = get_post_meta($shift_id);
        $shift_user_ids = [];
        foreach ($shift_meta as $key => $val) {
            if (str_starts_with($key, 'assigned_user_')) {
                $shift_user_ids[] = absint($val[0]);
            }
        }
        return empty($shift_user_ids) ? [] : get_users([
            'include'    => $shift_user_ids,
            'meta_query' => [$offline_exclude],
        ]);
    }

    if ($recipients === 'has_shift' || $recipients === 'no_shift') {
        $upcoming_shifts = get_posts([
            'post_type'   => 'eventadmin_shift',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [
                // "Upcoming" means the shift hasn't ended yet, so a shift already in
                // progress still counts — matches the Overview page's own definition.
                ['key' => 'shift_end', 'value' => current_time('mysql'), 'compare' => '>=', 'type' => 'DATETIME'],
            ],
        ]);
        $assigned_user_ids = [];
        foreach ($upcoming_shifts as $sid) {
            foreach (get_post_meta($sid) as $key => $val) {
                if (str_starts_with($key, 'assigned_user_')) {
                    $assigned_user_ids[] = absint($val[0]);
                }
            }
        }
        $assigned_user_ids = array_values(array_unique($assigned_user_ids));

        if ($recipients === 'has_shift') {
            return empty($assigned_user_ids) ? [] : get_users([
                'include'    => $assigned_user_ids,
                'meta_query' => [$offline_exclude],
            ]);
        }

        // 'no_shift': every online volunteer not present in the assigned set
        $all_volunteers = get_users([
            'role'       => 'eventadmin_volunteer',
            'meta_query' => [$offline_exclude],
        ]);
        return array_values(array_filter(
            $all_volunteers,
            fn($u) => !in_array($u->ID, $assigned_user_ids, true)
        ));
    }

    // 'all'
    return get_users([
        'role'       => 'eventadmin_volunteer',
        'meta_query' => [$offline_exclude],
    ]);
}

/**
 * Builds a hover-tooltip string listing recipient names for a "N recipients" count —
 * so hovering over the count shows who they are. Volunteers matched via more than one
 * upcoming shift (relevant for the 'has_shift' and 'category' selections, where a
 * recipient can qualify through several of their assigned shifts) are annotated with
 * how many shifts, e.g. "Anna Muster (3 shifts)".
 *
 * @param WP_User[] $users
 * @param string    $recipients  One of 'all', 'subscribed', 'no_shift', 'has_shift', 'shift', 'category'.
 * @param int       $category_id Term ID (for 'category'), used to scope the per-person shift count.
 * @return string
 */
function eventadmin_bulk_email_build_recipient_tooltip(array $users, string $recipients, int $category_id = 0): string
{
    if (empty($users)) {
        return '';
    }

    $shift_counts = [];
    if (in_array($recipients, ['has_shift', 'category'], true)) {
        $query_args = [
            'post_type'   => 'eventadmin_shift',
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [
                // "Upcoming" means the shift hasn't ended yet, so a shift already in
                // progress still counts — matches the Overview page's own definition.
                ['key' => 'shift_end', 'value' => current_time('mysql'), 'compare' => '>=', 'type' => 'DATETIME'],
            ],
        ];
        if ($recipients === 'category' && $category_id) {
            $query_args['tax_query'] = [['taxonomy' => 'eventadmin_shift_category', 'field' => 'term_id', 'terms' => $category_id]];
        }
        foreach (get_posts($query_args) as $sid) {
            foreach (get_post_meta($sid) as $key => $val) {
                if (str_starts_with($key, 'assigned_user_')) {
                    $uid = absint($val[0]);
                    $shift_counts[$uid] = ($shift_counts[$uid] ?? 0) + 1;
                }
            }
        }
    }

    $max_names = 30;
    $lines     = [];
    foreach (array_slice($users, 0, $max_names) as $u) {
        $name  = trim($u->first_name . ' ' . $u->last_name) ?: $u->user_login;
        $count = $shift_counts[$u->ID] ?? 0;
        if ($count > 1) {
            /* translators: 1: volunteer name, 2: number of shifts */
            $name = sprintf(__('%1$s (%2$d shifts)', 'eventadmin-volunteer-management'), $name, $count);
        }
        // Belt-and-suspenders: every recipient list above already excludes offline
        // volunteers (they have no real email address), but flag it here too in case a
        // future recipient path forgets to — better an obvious label than a silent miss.
        if (get_user_meta($u->ID, 'eventadmin_offline_volunteer', true)) {
            /* translators: %s is the volunteer name */
            $name = sprintf(__('%s (Offline — will NOT be notified)', 'eventadmin-volunteer-management'), $name);
        }
        $lines[] = $name;
    }
    if (count($users) > $max_names) {
        /* translators: %d is the number of additional recipients not listed */
        $lines[] = sprintf(__('…and %d more', 'eventadmin-volunteer-management'), count($users) - $max_names);
    }

    return implode("\n", $lines);
}

/**
 * Formats a volunteer's own upcoming shifts as an HTML list, for the {shifts} placeholder.
 *
 * @param int $user_id Volunteer ID.
 * @return string
 */
function eventadmin_bulk_email_format_upcoming_shifts(int $user_id): string
{
    $shifts = get_posts([
        'post_type'   => 'eventadmin_shift',
        'numberposts' => -1,
        'meta_key'    => 'shift_start',
        'orderby'     => 'meta_value',
        'meta_type'   => 'DATETIME',
        'order'       => 'ASC',
        'meta_query'  => [
            // "Upcoming" means the shift hasn't ended yet, so a shift already in
            // progress still counts — matches the Overview page's own definition.
            ['key' => 'shift_end', 'value' => current_time('mysql'), 'compare' => '>=', 'type' => 'DATETIME'],
            ['key' => 'assigned_user_' . $user_id, 'compare' => 'EXISTS'],
        ],
    ]);

    if (empty($shifts)) {
        return '<p><em>' . esc_html__('No upcoming shifts.', 'eventadmin-volunteer-management') . '</em></p>';
    }

    $items = '';
    foreach ($shifts as $shift) {
        $start = get_post_meta($shift->ID, 'shift_start', true);
        $end   = get_post_meta($shift->ID, 'shift_end', true);
        $items .= '<li>' . esc_html($shift->post_title) . ' — ' . esc_html(eventadmin_get_formatted_zeitraum($start, $end)) . '</li>';
    }

    return '<ul>' . $items . '</ul>';
}

/**
 * AJAX: returns the live recipient count for the currently selected shift or category
 * in the bulk email form (radio button counts for 'all'/'subscribed' are rendered server-side).
 */
function eventadmin_bulk_email_count(): void
{
    if (
        !isset($_POST['_ajax_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])), 'eventadmin_bulk_email_batch')
    ) {
        wp_send_json_error(['message' => esc_html__('Security check failed.', 'eventadmin-volunteer-management')]);
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'eventadmin-volunteer-management')]);
    }

    $raw_recip   = isset($_POST['bulk_email_recipients']) ? sanitize_text_field(wp_unslash($_POST['bulk_email_recipients'])) : '';
    $recipients  = in_array($raw_recip, ['shift', 'category'], true) ? $raw_recip : '';
    $shift_id    = isset($_POST['bulk_email_shift_id'])    ? absint($_POST['bulk_email_shift_id'])    : 0;
    $category_id = isset($_POST['bulk_email_category_id']) ? absint($_POST['bulk_email_category_id']) : 0;

    if (!$recipients) {
        wp_send_json_error(['message' => esc_html__('Invalid recipient type.', 'eventadmin-volunteer-management')]);
    }

    $users = eventadmin_bulk_email_get_recipient_users($recipients, $shift_id, $category_id, 0);

    wp_send_json_success([
        'count'   => count($users),
        'tooltip' => eventadmin_bulk_email_build_recipient_tooltip($users, $recipients, $category_id),
    ]);
}

add_action('wp_ajax_eventadmin_bulk_email_count', 'eventadmin_bulk_email_count');

/**
 * Handles the form submit: stores job data in a transient and returns the job key + total.
 */
function eventadmin_bulk_email_init(): void
{
    if (
        !isset($_POST['eventadmin_bulk_email_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eventadmin_bulk_email_nonce'])), 'eventadmin_bulk_email_init')
    ) {
        wp_send_json_error(['message' => esc_html__('Security check failed.', 'eventadmin-volunteer-management')]);
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'eventadmin-volunteer-management')]);
    }

    $subject    = isset($_POST['bulk_email_subject'])    ? sanitize_text_field(wp_unslash($_POST['bulk_email_subject'])) : '';
    $body       = isset($_POST['bulk_email_body'])       ? wp_kses_post(wp_unslash($_POST['bulk_email_body'])) : '';
    $from_name  = isset($_POST['bulk_email_from_name'])  ? sanitize_text_field(wp_unslash($_POST['bulk_email_from_name'])) : get_bloginfo('name');
    $from_email = isset($_POST['bulk_email_from_email']) ? sanitize_email(wp_unslash($_POST['bulk_email_from_email'])) : get_option('admin_email');
    $raw_recip  = isset($_POST['bulk_email_recipients']) ? sanitize_text_field(wp_unslash($_POST['bulk_email_recipients'])) : 'subscribed';
    $recipients  = in_array($raw_recip, ['all', 'subscribed', 'shift', 'user', 'category', 'no_shift', 'has_shift'], true) ? $raw_recip : 'subscribed';
    $shift_id    = isset($_POST['bulk_email_shift_id'])    ? absint($_POST['bulk_email_shift_id'])    : 0;
    $category_id = isset($_POST['bulk_email_category_id']) ? absint($_POST['bulk_email_category_id']) : 0;
    $target_user_id = isset($_POST['bulk_email_user_id']) ? absint($_POST['bulk_email_user_id']) : 0;
    $attachment_id  = isset($_POST['bulk_email_attachment_id']) ? absint($_POST['bulk_email_attachment_id']) : 0;

    if (!$subject || !$body) {
        wp_send_json_error(['message' => esc_html__('Subject and message are required.', 'eventadmin-volunteer-management')]);
    }

    if ($recipients === 'shift' && !$shift_id) {
        wp_send_json_error(['message' => esc_html__('Please select a shift.', 'eventadmin-volunteer-management')]);
    }

    if ($recipients === 'category' && !$category_id) {
        wp_send_json_error(['message' => esc_html__('Please select a category.', 'eventadmin-volunteer-management')]);
    }

    if ($recipients === 'user' && !$target_user_id) {
        wp_send_json_error(['message' => esc_html__('No recipient specified.', 'eventadmin-volunteer-management')]);
    }

    $attachment_path = '';
    $attachment_name = '';
    if ($attachment_id) {
        $attachment_post = get_post($attachment_id);
        if (!$attachment_post || $attachment_post->post_type !== 'attachment' || get_post_mime_type($attachment_id) !== 'application/pdf') {
            wp_send_json_error(['message' => esc_html__('The selected attachment is not a valid PDF.', 'eventadmin-volunteer-management')]);
        }
        $resolved_path = get_attached_file($attachment_id);
        if (!$resolved_path || !file_exists($resolved_path)) {
            wp_send_json_error(['message' => esc_html__('The selected attachment could not be found.', 'eventadmin-volunteer-management')]);
        }
        $attachment_path = $resolved_path;
        $attachment_name = basename($resolved_path);
    }

    $users    = eventadmin_bulk_email_get_recipient_users($recipients, $shift_id, $category_id, $target_user_id);
    $user_ids = wp_list_pluck($users, 'ID');
    if ($recipients === 'shift') {
        $recipients_meta = 'shift:' . get_the_title($shift_id);
    } elseif ($recipients === 'category') {
        $cat_term        = get_term($category_id);
        $recipients_meta = 'category:' . ($cat_term && !is_wp_error($cat_term) ? $cat_term->name : '#' . $category_id);
    } elseif ($recipients === 'user') {
        $target_user     = get_userdata($target_user_id);
        $target_name     = $target_user ? (trim($target_user->first_name . ' ' . $target_user->last_name) ?: $target_user->user_login) : '#' . $target_user_id;
        $recipients_meta = 'user:' . $target_name;
    } else {
        $recipients_meta = $recipients;
    }
    $job_key = 'eventadmin_bulk_email_' . wp_generate_password(12, false);

    set_transient($job_key, [
        'subject'         => $subject,
        'body'            => $body,
        'from_name'       => $from_name,
        'from_email'      => $from_email,
        'user_ids'        => $user_ids,
        'recipients'      => $recipients_meta,
        'sent_by'         => get_current_user_id(),
        'offset'          => 0,
        'failed'          => 0,
        'attachment_path' => $attachment_path,
        'attachment_name' => $attachment_name,
    ], HOUR_IN_SECONDS);

    wp_send_json_success([
        'job_key' => $job_key,
        'total'   => count($user_ids),
    ]);
}

add_action('wp_ajax_eventadmin_bulk_email_init', 'eventadmin_bulk_email_init');

/**
 * Processes one batch of emails (25 per call).
 */
function eventadmin_bulk_email_batch(): void
{
    if (
        !isset($_POST['_ajax_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])), 'eventadmin_bulk_email_batch')
    ) {
        wp_send_json_error(['message' => esc_html__('Security check failed.', 'eventadmin-volunteer-management')]);
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => esc_html__('Insufficient permissions.', 'eventadmin-volunteer-management')]);
    }

    $job_key = isset($_POST['job_key']) ? sanitize_text_field(wp_unslash($_POST['job_key'])) : '';
    $job     = $job_key ? get_transient($job_key) : false;

    if (!$job) {
        wp_send_json_error(['message' => esc_html__('Job not found or expired.', 'eventadmin-volunteer-management')]);
    }

    $batch_size   = 25;
    $offset       = (int)$job['offset'];
    $user_ids     = $job['user_ids'];
    $total        = count($user_ids);
    $batch        = array_slice($user_ids, $offset, $batch_size);
    $blog_name    = get_bloginfo('name');
    $from_name    = $job['from_name']  ?? $blog_name;
    $from_email   = $job['from_email'] ?? get_option('admin_email');
    $sender       = get_userdata($job['sent_by'] ?? 0);
    $reply_to     = $sender ? $sender->user_email : $from_email;
    $attachments  = !empty($job['attachment_path']) ? [$job['attachment_path']] : [];
    $has_shifts_placeholder = str_contains($job['body'], '{shifts}');

    $failed = 0;
    foreach ($batch as $user_id) {
        $user = get_userdata($user_id);
        if (!$user) continue;

        $body = str_replace(
            ['{first_name}', '{last_name}'],
            [$user->first_name, $user->last_name],
            $job['body']
        );
        // Convert plain line breaks to <br> for any body that has no block-level HTML already.
        // Done before expanding {shifts} so its injected <ul>/<li> markup doesn't suppress
        // nl2br for the surrounding plain-text parts of the message.
        if (!preg_match('/<(p|div|br|h[1-6]|ul|ol|li)\b/i', $body)) {
            $body = nl2br($body);
        }
        if ($has_shifts_placeholder) {
            $body = str_replace('{shifts}', eventadmin_bulk_email_format_upcoming_shifts($user_id), $body);
        }

        $sent = eventadmin_send_HTML_e_mail(
            $user->user_email,
            $job['subject'],
            $body,
            [
                'From: ' . $from_name . ' <' . $from_email . '>',
                'Reply-To: ' . $reply_to,
                'Content-Type: text/html; charset=UTF-8',
            ],
            [
                'preheader' => wp_strip_all_tags($job['subject']),
                'heading'   => $job['subject'],
                'site_name' => $blog_name,
            ],
            $attachments
        );

        if ($sent) {
            eventadmin_log_volunteer_notification($user_id, 'announcement', $job['subject']);
        } else {
            $failed++;
        }
    }

    $new_offset   = $offset + count($batch);
    $total_failed = ($job['failed'] ?? 0) + $failed;
    $done         = $new_offset >= $total;

    if ($done) {
        delete_transient($job_key);

        // Append to send log (capped at 50 entries)
        $log = get_option('eventadmin_email_log', []);
        array_unshift($log, [
            'date'            => current_time('Y-m-d H:i:s'),
            'subject'         => $job['subject'],
            'body'            => $job['body'],
            'recipients'      => $job['recipients'] ?? 'all',
            'from_name'       => $job['from_name']  ?? '',
            'from_email'      => $job['from_email'] ?? '',
            'total'           => $total,
            'failed'          => $total_failed,
            'sent_by'         => $job['sent_by'] ?? 0,
            'attachment_name' => $job['attachment_name'] ?? '',
        ]);
        $log = array_slice($log, 0, 50);
        update_option('eventadmin_email_log', $log);

        // Send one summary email to the person who triggered the send
        if ($sender) {
            $failed_note = $total_failed > 0
                ? sprintf(
                    // translators: %d is the number of failed deliveries
                    __('%d emails could not be delivered.', 'eventadmin-volunteer-management'),
                    $total_failed
                )
                : __('All emails were delivered successfully.', 'eventadmin-volunteer-management');

            eventadmin_send_HTML_e_mail(
                $sender->user_email,
                sprintf(
                    // translators: %s is the email subject that was sent
                    __('[%s] Announcement sent: %s', 'eventadmin-volunteer-management'),
                    $blog_name,
                    $job['subject']
                ),
                sprintf(
                    // translators: 1: blog name, 2: email subject, 3: sent count, 4: delivery note
                    wp_kses_post(__("Your announcement <strong>%2\$s</strong> was sent on %1\$s.<br><br>Recipients: %3\$d<br>%4\$s", 'eventadmin-volunteer-management')),
                    $blog_name,
                    $job['subject'],
                    $total,
                    $failed_note
                ),
                [
                    'From: ' . $from_name . ' <' . $from_email . '>',
                    'Content-Type: text/html; charset=UTF-8',
                ],
                [
                    'preheader' => wp_strip_all_tags($job['subject']),
                    'heading'   => __('Announcement delivery summary', 'eventadmin-volunteer-management'),
                    'site_name' => $blog_name,
                ]
            );
        }
    } else {
        $job['offset'] = $new_offset;
        $job['failed'] = $total_failed;
        set_transient($job_key, $job, HOUR_IN_SECONDS);
    }

    wp_send_json_success([
        'sent'   => $new_offset,
        'total'  => $total,
        'failed' => $total_failed,
        'done'   => $done,
    ]);
}

add_action('wp_ajax_eventadmin_bulk_email_batch', 'eventadmin_bulk_email_batch');

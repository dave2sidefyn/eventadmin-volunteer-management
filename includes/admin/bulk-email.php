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

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('Send Announcement to Volunteers', 'eventadmin-volunteer-management') . '</h1>';

    echo '<p>' . esc_html__('Use {first_name} and {last_name} as placeholders in the message body.', 'eventadmin-volunteer-management') . '</p>';

    echo '<form id="eventadmin-bulk-email-form" method="post">';
    wp_nonce_field('eventadmin_bulk_email_init', 'eventadmin_bulk_email_nonce');

    $default_from_name  = get_option('eventadmin_notification_email_name', get_bloginfo('name'));
    $default_from_email = get_option('eventadmin_notification_email', get_option('admin_email'));

    echo '<table class="form-table"><tbody>';

    echo '<tr><th scope="row"><label for="bulk_from_name">' . esc_html__('From name', 'eventadmin-volunteer-management') . '</label></th>';
    echo '<td><input type="text" id="bulk_from_name" name="bulk_email_from_name" class="regular-text" value="' . esc_attr($default_from_name) . '" required></td></tr>';

    echo '<tr><th scope="row"><label for="bulk_from_email">' . esc_html__('From email', 'eventadmin-volunteer-management') . '</label></th>';
    echo '<td><input type="email" id="bulk_from_email" name="bulk_email_from_email" class="regular-text" value="' . esc_attr($default_from_email) . '" required></td></tr>';

    echo '<tr><th scope="row"><label for="bulk_email_subject">' . esc_html__('Subject', 'eventadmin-volunteer-management') . '</label></th>';
    echo '<td><input type="text" id="bulk_email_subject" name="bulk_email_subject" class="regular-text" required></td></tr>';

    echo '<tr><th scope="row"><label for="bulk_email_body">' . esc_html__('Message', 'eventadmin-volunteer-management') . '</label></th>';
    echo '<td><textarea id="bulk_email_body" name="bulk_email_body" rows="10" class="large-text" required></textarea></td></tr>';

    $offline_exclude = ['key' => 'eventadmin_offline_volunteer', 'compare' => 'NOT EXISTS'];
    $count_all = count(get_users([
        'role'       => 'eventadmin_volunteer',
        'fields'     => 'ID',
        'meta_query' => [$offline_exclude],
    ]));
    $count_subscribed = count(get_users([
        'role'       => 'eventadmin_volunteer',
        'fields'     => 'ID',
        'meta_query' => [
            'relation' => 'AND',
            $offline_exclude,
            [
                'relation' => 'OR',
                ['key' => 'eventadmin_announcements', 'compare' => 'NOT EXISTS'],
                ['key' => 'eventadmin_announcements', 'value' => '1'],
            ],
        ],
    ]));

    $all_shifts     = get_posts([
        'post_type'   => 'eventadmin_shift',
        'numberposts' => -1,
        'meta_key'    => 'shift_start',
        'orderby'     => 'meta_value',
        'order'       => 'ASC',
    ]);
    $all_categories = get_terms(['taxonomy' => 'eventadmin_shift_category', 'hide_empty' => false]);

    echo '<tr><th scope="row">' . esc_html__('Recipients', 'eventadmin-volunteer-management') . '</th>';
    echo '<td>';
    if ($preset_user) {
        $preset_name = esc_html(trim($preset_user->first_name . ' ' . $preset_user->last_name) ?: $preset_user->user_login);
        echo '<label><input type="radio" name="bulk_email_recipients" value="user" checked> ';
        echo $preset_name;
        echo '</label><br>';
        echo '<input type="hidden" name="bulk_email_user_id" value="' . esc_attr($preset_user_id) . '">';
    }
    echo '<label><input type="radio" name="bulk_email_recipients" value="subscribed"' . ($preset_user ? '' : ' checked') . '> ';
    echo esc_html__('Subscribed volunteers only (opted-in)', 'eventadmin-volunteer-management');
    echo ' &nbsp;<span class="bulk-email-count" data-for="subscribed" style="color:#666;font-style:italic;">';
    /* translators: %d number of recipients */
    echo esc_html(sprintf(_n('%d recipient', '%d recipients', $count_subscribed, 'eventadmin-volunteer-management'), $count_subscribed));
    echo '</span></label><br>';
    echo '<label><input type="radio" name="bulk_email_recipients" value="all"> ';
    echo esc_html__('All volunteers', 'eventadmin-volunteer-management');
    echo ' &nbsp;<span class="bulk-email-count" data-for="all" style="color:#666;font-style:italic;display:none;">';
    echo esc_html(sprintf(_n('%d recipient', '%d recipients', $count_all, 'eventadmin-volunteer-management'), $count_all));
    echo '</span></label><br>';
    echo '<label><input type="radio" name="bulk_email_recipients" value="shift"> ';
    echo esc_html__('Volunteers of a specific shift', 'eventadmin-volunteer-management');
    echo '</label>';
    echo '<div id="eventadmin-shift-select-wrap" style="margin-top:8px;display:none;">';
    echo '<select name="bulk_email_shift_id">';
    echo '<option value="">' . esc_html__('— Select shift —', 'eventadmin-volunteer-management') . '</option>';
    foreach ($all_shifts as $shift) {
        $start = get_post_meta($shift->ID, 'shift_start', true);
        $label = esc_html($shift->post_title) . ($start ? ' (' . esc_html(eventadmin_get_formatted_zeitraum($start, '')) . ')' : '');
        echo '<option value="' . esc_attr($shift->ID) . '">' . $label . '</option>';
    }
    echo '</select>';
    echo '</div>';
    if (!empty($all_categories)) {
        echo '<br><label><input type="radio" name="bulk_email_recipients" value="category"> ';
        echo esc_html__('Volunteers of a specific category', 'eventadmin-volunteer-management');
        echo '</label>';
        echo '<div id="eventadmin-category-select-wrap" style="margin-top:8px;display:none;">';
        echo '<select name="bulk_email_category_id">';
        echo '<option value="">' . esc_html__('— Select category —', 'eventadmin-volunteer-management') . '</option>';
        foreach ($all_categories as $cat) {
            echo '<option value="' . esc_attr($cat->term_id) . '">' . esc_html($cat->name) . '</option>';
        }
        echo '</select>';
        echo '</div>';
    }
    echo '</td></tr>';

    echo '</tbody></table>';

    echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__('Start sending', 'eventadmin-volunteer-management') . '</button></p>';
    echo '</form>';

    // Live preview
    echo '<div id="eventadmin-email-preview" style="background:#f6f7f7;border:1px solid #dcdcde;padding:16px;margin-top:8px;max-width:700px;">';
    echo '<h3 style="margin-top:0;">' . esc_html__('Preview (example data)', 'eventadmin-volunteer-management') . '</h3>';
    echo '<p style="margin:0 0 4px;"><strong>' . esc_html__('From:', 'eventadmin-volunteer-management') . '</strong> <span id="ea-preview-from"></span></p>';
    echo '<p style="margin:0 0 4px;"><strong>' . esc_html__('Subject:', 'eventadmin-volunteer-management') . '</strong> <span id="ea-preview-subject"></span></p>';
    echo '<hr style="margin:8px 0;">';
    echo '<div id="ea-preview-body" style="font-size:13px;"></div>';
    echo '</div>';

    // Progress UI (hidden until send starts)
    echo '<div id="eventadmin-bulk-email-progress" style="display:none;">';
    echo '<h2>' . esc_html__('Sending in progress…', 'eventadmin-volunteer-management') . '</h2>';
    echo '<div style="background:#e0e0e0;border-radius:4px;height:24px;width:100%;max-width:600px;">';
    echo '<div id="eventadmin-bulk-email-bar" style="background:#2271b1;height:24px;border-radius:4px;width:0%;transition:width .3s;"></div>';
    echo '</div>';
    echo '<p id="eventadmin-bulk-email-status"></p>';
    echo '</div>';

    wp_enqueue_script(
        'eventadmin-bulk-email',
        plugin_dir_url(__FILE__) . '../../assets/js/bulk-email.js',
        ['jquery'],
        '1.0',
        true
    );

    wp_localize_script('eventadmin-bulk-email', 'EVENTADMIN_BULK_EMAIL', [
        'ajax_url'    => admin_url('admin-ajax.php'),
        'nonce_batch' => wp_create_nonce('eventadmin_bulk_email_batch'),
        'i18n'        => [
            'done'    => esc_html__('Done! All emails sent.', 'eventadmin-volunteer-management'),
            'failed'  => esc_html__('({failed} could not be delivered)', 'eventadmin-volunteer-management'),
            'error'   => esc_html__('An error occurred. Please try again.', 'eventadmin-volunteer-management'),
            'sending' => esc_html__('Sent {sent} of {total}…', 'eventadmin-volunteer-management'),
        ],
    ]);

    // Send log
    $log = get_option('eventadmin_email_log', []);
    echo '<hr>';
    echo '<details id="eventadmin-history-details">';
    /* translators: %d number of log entries */
    echo '<summary style="cursor:pointer;font-size:1.3em;font-weight:600;padding:8px 0;">';
    echo esc_html(sprintf(
        _n('Send History (%d entry)', 'Send History (%d entries)', count($log), 'eventadmin-volunteer-management'),
        count($log)
    ));
    echo '</summary>';

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
            $failed_count = (int)($entry['failed'] ?? 0);
            $full_body    = wp_kses_post($entry['body'] ?? '');
            $preview      = esc_html(wp_strip_all_tags(mb_strimwidth($entry['body'] ?? '', 0, 80, '…')));
            $date         = $entry['date'] ?? '';

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
            echo '<div style="margin:8px 0 0;font-size:12px;max-width:500px;">' . $full_body . '</div></details></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
    echo '</details>';

    echo '</div>';
}

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
    $recipients  = in_array($raw_recip, ['all', 'subscribed', 'shift', 'user', 'category'], true) ? $raw_recip : 'subscribed';
    $shift_id    = isset($_POST['bulk_email_shift_id'])    ? absint($_POST['bulk_email_shift_id'])    : 0;
    $category_id = isset($_POST['bulk_email_category_id']) ? absint($_POST['bulk_email_category_id']) : 0;
    $target_user_id = isset($_POST['bulk_email_user_id']) ? absint($_POST['bulk_email_user_id']) : 0;

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

    $offline_exclude = ['key' => 'eventadmin_offline_volunteer', 'compare' => 'NOT EXISTS'];
    if ($recipients === 'subscribed') {
        // Users who opted in (meta=1) or have no preference set (meta doesn't exist); never offline
        $users = get_users([
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
    } elseif ($recipients === 'category') {
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
        $users = empty($cat_user_ids) ? [] : get_users([
            'include'    => $cat_user_ids,
            'meta_query' => [$offline_exclude],
        ]);
    } elseif ($recipients === 'user') {
        $target_user = get_userdata($target_user_id);
        $users = $target_user ? [$target_user] : [];
    } elseif ($recipients === 'shift') {
        $shift_meta     = get_post_meta($shift_id);
        $shift_user_ids = [];
        foreach ($shift_meta as $key => $val) {
            if (str_starts_with($key, 'assigned_user_')) {
                $shift_user_ids[] = absint($val[0]);
            }
        }
        $users = empty($shift_user_ids) ? [] : get_users([
            'include'    => $shift_user_ids,
            'meta_query' => [$offline_exclude],
        ]);
    } else {
        $users = get_users([
            'role'       => 'eventadmin_volunteer',
            'meta_query' => [$offline_exclude],
        ]);
    }

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
        'subject'    => $subject,
        'body'       => $body,
        'from_name'  => $from_name,
        'from_email' => $from_email,
        'user_ids'   => $user_ids,
        'recipients' => $recipients_meta,
        'sent_by'    => get_current_user_id(),
        'offset'     => 0,
        'failed'     => 0,
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

    $failed = 0;
    foreach ($batch as $user_id) {
        $user = get_userdata($user_id);
        if (!$user) continue;

        $body = str_replace(
            ['{first_name}', '{last_name}'],
            [$user->first_name, $user->last_name],
            $job['body']
        );
        // Convert plain line breaks to <br> for any body that has no block-level HTML already
        if (!preg_match('/<(p|div|br|h[1-6]|ul|ol|li)\b/i', $body)) {
            $body = nl2br($body);
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
            ]
        );

        if (!$sent) $failed++;
    }

    $new_offset   = $offset + count($batch);
    $total_failed = ($job['failed'] ?? 0) + $failed;
    $done         = $new_offset >= $total;

    if ($done) {
        delete_transient($job_key);

        // Append to send log (capped at 50 entries)
        $log = get_option('eventadmin_email_log', []);
        array_unshift($log, [
            'date'       => current_time('Y-m-d H:i:s'),
            'subject'    => $job['subject'],
            'body'       => $job['body'],
            'recipients' => $job['recipients'] ?? 'all',
            'from_name'  => $job['from_name']  ?? '',
            'from_email' => $job['from_email'] ?? '',
            'total'      => $total,
            'failed'     => $total_failed,
            'sent_by'    => $job['sent_by'] ?? 0,
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

<?php
/**
 * EventAdmin Volunteer Management - Getting Started Checklist
 * A self-hiding checklist shown at the top of the Overview dashboard tab until the site's
 * initial setup is done, so a fresh install knows what to do next without reading the full
 * Documentation page end to end. Every row checks live data rather than a one-time "setup
 * complete" flag (settings review excepted, tracked in settings.php), so it stays accurate
 * even if e.g. the one department created so far gets deleted again.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Returns the ID of the first published page carrying one of the plugin's shortcodes, or 0 —
 * shared by eventadmin_get_getting_started_status()'s "page" check and the "log in as a
 * volunteer" item's CTA link below, so the two never drift on what counts as "the" page.
 *
 * @return int
 */
function eventadmin_get_getting_started_public_page_id(): int
{
    global $wpdb;

    return (int) $wpdb->get_var(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type = 'page' AND post_status = 'publish' AND (
            post_content LIKE '%[eventadmin]%' OR
            post_content LIKE '%[eventadmin_cockpit%' OR
            post_content LIKE '%[eventadmin_register%' OR
            post_content LIKE '%[eventadmin_shiftselector%'
         ) LIMIT 1"
    );
}

/**
 * Returns whether each getting-started step is done, keyed by step id.
 *
 * @return array<string, bool>
 */
function eventadmin_get_getting_started_status(): array
{
    global $wpdb;

    $has_page = eventadmin_get_getting_started_public_page_id() > 0;

    $settings_reviewed = get_option('eventadmin_settings_reviewed_general')
        && get_option('eventadmin_settings_reviewed_display')
        && get_option('eventadmin_settings_reviewed_communication');

    $has_department = !empty(eventadmin_get_hierarchical_shift_categories());

    $shift_counts = wp_count_posts('eventadmin_shift');
    $has_shift = !empty($shift_counts->publish);

    $has_assignment = (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE p.post_type = %s AND pm.meta_key LIKE %s",
        'eventadmin_shift',
        $wpdb->esc_like('assigned_user_') . '%'
    ));

    $has_delegated_role = (bool) count(get_users([
        'role__in' => ['eventadmin_shift_manager', 'eventadmin_volunteer_manager'],
        'number'   => 1,
        'fields'   => 'ID',
    ]));

    // Any branding field or subject/body override away from its built-in default — matches
    // eventadmin_send_shift_un_assignment_notification()'s own "$saved ?: $default" fallback,
    // so an option only reads as "customized" once it actually holds a saved value.
    $has_customized_email_template = (bool) (
        get_option('eventadmin_email_header_logo_id')
        || get_option('eventadmin_email_header_color')
        || get_option('eventadmin_email_header_title')
        || get_option('eventadmin_email_header_subtitle')
        || get_option('eventadmin_email_footer_html')
        || get_option('eventadmin_email_custom_css')
        || get_option('eventadmin_email_subject_assign')
        || get_option('eventadmin_email_text_assign')
        || get_option('eventadmin_email_subject_unassign')
        || get_option('eventadmin_email_text_unassign')
        || get_option('eventadmin_email_subject_reminder')
        || get_option('eventadmin_email_text_reminder')
    );

    // eventadmin_bulk_email_batch() (bulk-email.php) appends to this option once a send
    // finishes — reusing it here means one less one-way flag to maintain, and it stays
    // accurate even across the 50-entry cap since only ever having sent zero flips it back.
    $has_sent_announcement = !empty(get_option('eventadmin_email_log', []));

    return [
        'page'       => $has_page,
        'settings'   => (bool) $settings_reviewed,
        'department' => $has_department,
        'shift'      => $has_shift,
        'assignment' => $has_assignment,
        'role'       => $has_delegated_role,
        'template'   => $has_customized_email_template,
        'announcement' => $has_sent_announcement,
        // Set from eventadmin_ajax_get_volunteer_profile() (user-profile.php), the
        // reschedule_source='drag' branch of eventadmin_ajax_update_shift()
        // (dashboard-tab-timeline.php), eventadmin_log_volunteer_notification()
        // (notifications.php, type==='assign'), and eventadmin_magic_login_check()
        // (helpers.php) the first time each actually happens — there's no persisted state to
        // query for "has anyone ever opened this modal / dragged a bar / logged in via a
        // magic link", so these are one-way flags rather than a live data check like above.
        'profile'    => (bool) get_option('eventadmin_viewed_volunteer_profile'),
        'drag'       => (bool) get_option('eventadmin_used_drag_reschedule'),
        'confirmation' => (bool) get_option('eventadmin_sent_assign_confirmation'),
        'login'      => (bool) get_option('eventadmin_magic_login_used'),
        'import'     => (bool) get_option('eventadmin_used_import_tool'),
        // Self-attested, not verifiable (we can't know if the external Rate/Donate click
        // went anywhere) — set by whichever of the three links in that row was clicked, "I
        // just take and don't give" included, all treated as an equally valid answer.
        'support'    => (bool) get_option('eventadmin_support_acknowledged'),
    ];
}

/**
 * Renders the checklist at the top of the Overview dashboard tab. Administrator-only — several
 * of its steps (Settings, delegating a role, email templates) require capabilities a Shift
 * Manager never has, so showing it to them would mean half the buttons are dead ends.
 * Disappears for good once every required step is done, or once dismissed; the optional
 * "explore more" steps never gate that disappearance — they're feature pointers, not setup
 * requirements.
 *
 * @return void
 */
function eventadmin_render_getting_started_checklist(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    if (get_option('eventadmin_getting_started_dismissed')) {
        return;
    }

    $status = eventadmin_get_getting_started_status();
    $required_done = $status['page'] && $status['settings'] && $status['department'] && $status['shift'] && $status['assignment'];
    if ($required_done) {
        update_option('eventadmin_getting_started_dismissed', 1);
        return;
    }

    $documentation_url = admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-documentation');
    $dismiss_url = wp_nonce_url(
        add_query_arg('eventadmin_dismiss_getting_started', '1'),
        'eventadmin_dismiss_getting_started'
    );

    // The public page carrying the shortcode, if one was found (see "page" status above) —
    // used as the "try logging in as a volunteer" CTA. Falls back to the Documentation page
    // when none exists yet, since that item can't be usefully actioned before the first one.
    $public_page_id  = eventadmin_get_getting_started_public_page_id();
    $public_page_url = $public_page_id ? get_permalink($public_page_id) : $documentation_url;

    // Three answers to the same question, all equally valid — rate/donate open in a new tab
    // via our own nonce-checked redirector (see eventadmin_handle_getting_started_support_ack()
    // below) so the click still gets acknowledged before it leaves the site; "skip" just marks
    // it acknowledged directly. No JS needed for any of the three.
    $support_rate_url = wp_nonce_url(
        add_query_arg('eventadmin_getting_started_support', 'rate'),
        'eventadmin_getting_started_support'
    );
    $support_donate_url = wp_nonce_url(
        add_query_arg('eventadmin_getting_started_support', 'donate'),
        'eventadmin_getting_started_support'
    );
    $support_skip_url = wp_nonce_url(
        add_query_arg('eventadmin_getting_started_support', 'skip'),
        'eventadmin_getting_started_support'
    );
    $support_actions_html = '<span style="display:flex;flex-direction:column;gap:6px;align-items:flex-end;flex-shrink:0;">'
        . '<span>'
        . '<a href="' . esc_url($support_rate_url) . '" target="_blank" rel="noopener" class="button">⭐ ' . esc_html__('Rate 5 stars', 'eventadmin-volunteer-management') . '</a> '
        . '<a href="' . esc_url($support_donate_url) . '" target="_blank" rel="noopener" class="button">❤️ ' . esc_html__('Donate', 'eventadmin-volunteer-management') . '</a>'
        . '</span>'
        . '<a href="' . esc_url($support_skip_url) . '" style="font-size:12px;color:#666;">' . esc_html__('I just take and don’t give ;)', 'eventadmin-volunteer-management') . '</a>'
        . '</span>';

    $items = [
        [
            'done'  => $status['page'],
            'label' => esc_html__('Add the [eventadmin] shortcode to a page', 'eventadmin-volunteer-management'),
            'hint'  => esc_html__('This is the main page volunteers land on: registration, shift sign-up, and their profile.', 'eventadmin-volunteer-management'),
            'url'   => admin_url('post-new.php?post_type=page'),
            'cta'   => esc_html__('Create page', 'eventadmin-volunteer-management'),
        ],
        [
            'done'  => $status['settings'],
            'label' => esc_html__('Review the plugin settings', 'eventadmin-volunteer-management'),
            'hint'  => esc_html__('Open and save each of General, Display, and Communication at least once — every option has a sensible default, but it’s worth deciding on purpose rather than by accident.', 'eventadmin-volunteer-management'),
            'url'   => admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-settings'),
            'cta'   => esc_html__('Open Settings', 'eventadmin-volunteer-management'),
        ],
        [
            'done'  => $status['department'],
            'label' => esc_html__('Create a department', 'eventadmin-volunteer-management'),
            'hint'  => esc_html__('Departments group shifts (e.g. "Bar", "Kitchen") and give them a color badge — every shift needs at least one.', 'eventadmin-volunteer-management'),
            'url'   => admin_url('edit-tags.php?taxonomy=eventadmin_shift_category&post_type=eventadmin_shift'),
            'cta'   => esc_html__('Add department', 'eventadmin-volunteer-management'),
        ],
        [
            'done'  => $status['shift'],
            'label' => esc_html__('Create your first shift', 'eventadmin-volunteer-management'),
            'hint'  => esc_html__('Set a time period, department, and how many volunteers it needs.', 'eventadmin-volunteer-management'),
            'url'   => admin_url('post-new.php?post_type=eventadmin_shift'),
            'cta'   => esc_html__('Add shift', 'eventadmin-volunteer-management'),
        ],
        [
            'done'  => $status['assignment'],
            'label' => esc_html__('Get a volunteer onto a shift', 'eventadmin-volunteer-management'),
            'hint'  => esc_html__('Either a volunteer signs up themselves on your page, or you assign one manually from the Manager page.', 'eventadmin-volunteer-management'),
            'url'   => admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-shift-manager'),
            'cta'   => esc_html__('Open Manager', 'eventadmin-volunteer-management'),
        ],
        [
            'done'     => $status['role'],
            'label'    => esc_html__('Delegate to a Shift Manager or Volunteer Manager', 'eventadmin-volunteer-management'),
            'hint'     => esc_html__('Only relevant if someone besides you should help without getting full Administrator access — see the Documentation page for what each role can do.', 'eventadmin-volunteer-management'),
            'url'      => admin_url('users.php'),
            'cta'      => esc_html__('Manage users', 'eventadmin-volunteer-management'),
            'optional' => true,
        ],
        [
            'done'     => $status['import'],
            'label'    => esc_html__('Import your data', 'eventadmin-volunteer-management'),
            'hint'     => esc_html__('Bring departments over from another site’s export, or bulk-import volunteers/shifts from a CSV file — Shifts → Import.', 'eventadmin-volunteer-management'),
            'url'      => admin_url('tools.php?page=eventadmin-import'),
            'cta'      => esc_html__('Open Import', 'eventadmin-volunteer-management'),
            'optional' => true,
        ],
        [
            'done'     => $status['profile'],
            'label'    => esc_html__('Open a volunteer’s profile', 'eventadmin-volunteer-management'),
            'hint'     => esc_html__('Click "View profile" on the Volunteers page (or from a shift on the Manager Timeline) to see their upcoming/past shifts and notification history in one place.', 'eventadmin-volunteer-management'),
            'url'      => admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-volunteers'),
            'cta'      => esc_html__('Open Volunteers', 'eventadmin-volunteer-management'),
            'optional' => true,
        ],
        [
            'done'     => $status['drag'],
            'label'    => esc_html__('Drag a shift to reschedule it', 'eventadmin-volunteer-management'),
            'hint'     => esc_html__('On the Manager page’s Timeline tab, drag a shift bar to move it, or drag its edge to resize it — much quicker than editing the time by hand.', 'eventadmin-volunteer-management'),
            'url'      => admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-shift-manager'),
            'cta'      => esc_html__('Open Manager', 'eventadmin-volunteer-management'),
            'optional' => true,
        ],
        [
            'done'     => $status['template'],
            'label'    => esc_html__('Customize your email templates', 'eventadmin-volunteer-management'),
            'hint'     => esc_html__('Add a header logo/color and adjust the subject/body text for confirmations, cancellations, and reminders — Settings → Communication.', 'eventadmin-volunteer-management'),
            'url'      => admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-settings&tab=communication'),
            'cta'      => esc_html__('Open Settings', 'eventadmin-volunteer-management'),
            'optional' => true,
        ],
        [
            'done'     => $status['confirmation'],
            'label'    => esc_html__('Have a confirmation email actually sent', 'eventadmin-volunteer-management'),
            'hint'     => esc_html__('Happens automatically on self-signup, or tick "Send confirmation email" when assigning someone manually from the Manager page — good way to check your template looks right in a real inbox.', 'eventadmin-volunteer-management'),
            'url'      => admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-shift-manager'),
            'cta'      => esc_html__('Open Manager', 'eventadmin-volunteer-management'),
            'optional' => true,
        ],
        [
            'done'     => $status['login'],
            'label'    => esc_html__('Log in as a volunteer', 'eventadmin-volunteer-management'),
            'hint'     => esc_html__('Register a test volunteer on your public page (or use an existing one’s "Grant volunteer role") and open their emailed login link, to see the front-end exactly as they would.', 'eventadmin-volunteer-management'),
            'url'      => $public_page_url,
            'cta'      => esc_html__('Open page', 'eventadmin-volunteer-management'),
            'optional' => true,
        ],
        [
            'done'     => $status['announcement'],
            'label'    => esc_html__('Send a first announcement', 'eventadmin-volunteer-management'),
            'hint'     => esc_html__('Send Announcement lets you email all volunteers, just the ones without an upcoming shift, or a specific shift/department — with HTML formatting and a live preview.', 'eventadmin-volunteer-management'),
            'url'      => admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-bulk-email'),
            'cta'      => esc_html__('Send Announcement', 'eventadmin-volunteer-management'),
            'optional' => true,
        ],
        [
            'done'        => $status['support'],
            'label'       => esc_html__('Enjoying EventAdmin?', 'eventadmin-volunteer-management'),
            'hint'        => esc_html__('A rating or a small donation both genuinely help — but no pressure either way.', 'eventadmin-volunteer-management'),
            'optional'    => true,
            'custom_html' => $support_actions_html,
        ],
    ];

    echo '<div class="notice notice-info eventadmin-getting-started" style="padding:16px;">';
    echo '<h2 style="margin-top:0;">' . esc_html__('Getting started', 'eventadmin-volunteer-management') . '</h2>';
    echo '<p>' . wp_kses(sprintf(
        /* translators: %s: link to the Documentation page */
        __('This checklist walks through the essentials; the %s has the full picture.', 'eventadmin-volunteer-management'),
        '<a href="' . esc_url($documentation_url) . '">' . esc_html__('Documentation page', 'eventadmin-volunteer-management') . '</a>'
    ), ['a' => ['href' => []]]) . '</p>';

    echo '<ul style="list-style:none;margin:0;padding:0;">';
    // A one-time heading right before the first optional row, splitting the list into
    // "required" and "explore more" without needing a second <ul> — $items is built with all
    // the required rows first, so this only ever fires once.
    $optional_heading_shown = false;
    foreach ($items as $item) {
        if (!empty($item['optional']) && !$optional_heading_shown) {
            echo '<li style="padding:16px 0 4px;">' . esc_html__('Optional — explore more of what EventAdmin can do:', 'eventadmin-volunteer-management') . '</li>';
            $optional_heading_shown = true;
        }
        echo '<li style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-top:1px solid #dcdcde;">';
        echo '<span style="font-size:16px;line-height:1.4;flex-shrink:0;" aria-hidden="true">' . ($item['done'] ? '✅' : '⬜') . '</span>';
        echo '<span style="flex-grow:1;">';
        echo '<strong>' . $item['label'] . '</strong>';
        echo '<br><span style="color:#666;">' . $item['hint'] . '</span>';
        echo '</span>';
        if (!$item['done'] && !empty($item['custom_html'])) {
            echo $item['custom_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url()/esc_html__() pieces above.
        } elseif (!$item['done']) {
            echo '<a href="' . esc_url($item['url']) . '" class="button" style="flex-shrink:0;">' . $item['cta'] . '</a>';
        }
        echo '</li>';
    }
    echo '</ul>';

    echo '<p style="margin-bottom:0;"><button type="button" class="button-link" id="eventadmin-getting-started-hide-btn">' . esc_html__('Hide this checklist', 'eventadmin-volunteer-management') . '</button></p>';

    eventadmin_render_modal_open('eventadmin-getting-started-hide-modal', 380);
    eventadmin_render_modal_close_button('eventadmin-getting-started-hide-cancel');
    echo '<h2 style="margin-top:0;">' . esc_html__('Hide this checklist?', 'eventadmin-volunteer-management') . '</h2>';
    echo '<p>' . esc_html__('You can still find everything it links to from the Documentation page.', 'eventadmin-volunteer-management') . '</p>';
    echo '<p style="margin-bottom:0;">';
    echo '<a href="' . esc_url($dismiss_url) . '" class="button button-primary">' . esc_html__('Yes, hide it', 'eventadmin-volunteer-management') . '</a> ';
    echo '<button type="button" class="button" id="eventadmin-getting-started-hide-cancel-2">' . esc_html__('Cancel', 'eventadmin-volunteer-management') . '</button>';
    echo '</p>';
    eventadmin_render_modal_close();

    echo '<script>
    (function () {
        var openBtn = document.getElementById("eventadmin-getting-started-hide-btn");
        var modal = document.getElementById("eventadmin-getting-started-hide-modal");
        if (!openBtn || !modal) return;
        function close() { modal.style.display = "none"; }
        openBtn.addEventListener("click", function () { modal.style.display = "block"; });
        modal.addEventListener("click", function (e) { if (e.target === modal) close(); });
        document.getElementById("eventadmin-getting-started-hide-cancel").addEventListener("click", close);
        document.getElementById("eventadmin-getting-started-hide-cancel-2").addEventListener("click", close);
    })();
    </script>';

    echo '</div>';
}

/**
 * Handles the "Yes, hide it" link inside the confirmation modal (see
 * eventadmin_render_getting_started_checklist() above) — a plain GET link (not a form) is
 * enough server-side since it only ever flips one boolean option and nothing destructive; the
 * modal itself is what stops a stray click on the outer "Hide this checklist" button from
 * hiding it permanently without meaning to, rather than a browser-native confirm().
 *
 * @return void
 */
function eventadmin_handle_dismiss_getting_started(): void
{
    if (!isset($_GET['eventadmin_dismiss_getting_started'])) {
        return;
    }

    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'eventadmin_dismiss_getting_started')) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    update_option('eventadmin_getting_started_dismissed', 1);
    wp_safe_redirect(remove_query_arg(['eventadmin_dismiss_getting_started', '_wpnonce']));
    exit;
}

add_action('admin_init', 'eventadmin_handle_dismiss_getting_started');

/**
 * Handles the three links in the "Enjoying EventAdmin?" row — rate and donate both mark the
 * row acknowledged and then hand off to the real external URL (so the click still counts even
 * though we can't know what happens after); skip marks it acknowledged and returns to the
 * current admin page, exactly like eventadmin_handle_dismiss_getting_started() above.
 *
 * @return void
 */
function eventadmin_handle_getting_started_support_ack(): void
{
    if (!isset($_GET['eventadmin_getting_started_support'])) {
        return;
    }

    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'eventadmin_getting_started_support')) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    if (!get_option('eventadmin_support_acknowledged')) {
        update_option('eventadmin_support_acknowledged', 1);
    }

    $answer = sanitize_key(wp_unslash($_GET['eventadmin_getting_started_support']));

    if ($answer === 'rate') {
        wp_redirect(EVENTADMIN_REVIEW_URL);
        exit;
    }

    if ($answer === 'donate') {
        wp_redirect(EVENTADMIN_DONATE_URL);
        exit;
    }

    wp_safe_redirect(remove_query_arg(['eventadmin_getting_started_support', '_wpnonce']));
    exit;
}

add_action('admin_init', 'eventadmin_handle_getting_started_support_ack');

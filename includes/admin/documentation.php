<?php
/**
 * EventAdmin Volunteer Management - Documentation
 * Simple way to access the documentation.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Renders a bundled documentation screenshot as a side-by-side card: a smallish preview
 * image on one side, a description and an optional "go see it on your own site" link on
 * the other. Alternates image left/right (set via $image_right) purely so a page with
 * several of these in a row reads as a relaxed zig-zag instead of a wall of stacked images.
 *
 * @param string      $filename    File name under assets/img/docs/.
 * @param string      $caption     Descriptive text shown beside the image (also used as alt text).
 * @param string|null $link_url    Optional URL to the live equivalent page on this site.
 * @param string|null $link_label  Optional label for that link, e.g. "Open Manager".
 * @param bool        $image_right Show the image on the right instead of the left.
 * @return void
 */
function eventadmin_doc_screenshot(string $filename, string $caption, ?string $link_url = null, ?string $link_label = null, bool $image_right = false): void
{
    $src = plugin_dir_url(__FILE__) . '../../assets/img/docs/' . $filename;
    ?>
    <div style="display:flex;flex-direction:<?php echo $image_right ? 'row-reverse' : 'row'; ?>;flex-wrap:wrap;align-items:center;gap:24px;margin:20px 0;padding:20px;background:#fff;border:1px solid #dcdcde;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.05);">
        <div style="flex:1 1 300px;max-width:340px;">
            <img
                src="<?php echo esc_url($src); ?>"
                alt="<?php echo esc_attr($caption); ?>"
                style="width:100%;height:auto;display:block;border:1px solid #e2e4e7;border-radius:6px;"
            >
        </div>
        <div style="flex:1 1 260px;min-width:220px;">
            <p style="margin:0;font-size:14px;line-height:1.65;color:#3c434a;"><?php echo esc_html($caption); ?></p>
            <?php if ($link_url && $link_label) : ?>
                <p style="margin:14px 0 0;">
                    <a href="<?php echo esc_url($link_url); ?>" class="button button-secondary"><?php echo esc_html($link_label); ?> →</a>
                </p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Adds a menu item in the admin area to display the documentation
 *
 * @return void
 */
function eventadmin_documentation_admin_menu(): void
{
    add_submenu_page(
        'edit.php?post_type=eventadmin_shift',
        esc_html__('Documentation', 'eventadmin-volunteer-management'),
        esc_html__('Documentation' , 'eventadmin-volunteer-management'),
        'read',
        'eventadmin-documentation',
        'eventadmin_plugin_documentation_page'
    );
}
add_action('admin_menu', 'eventadmin_documentation_admin_menu', 200);

/**
 * Enqueues the [eventadmin_open_positions] shortcode's own stylesheet on the Documentation
 * page — needed because the Public Recruitment section renders that shortcode live, with
 * the reader's own real data, instead of only describing it. The shortcode's handler calls
 * wp_enqueue_style() on a handle that eventadmin_register_open_positions_assets() only
 * registers on the front-end 'wp_enqueue_scripts' hook, so without this the live preview
 * would render completely unstyled here.
 *
 * @return void
 */
function eventadmin_documentation_enqueue_open_positions_style(): void
{
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'eventadmin_shift_page_eventadmin-documentation') {
        return;
    }

    $css_path = plugin_dir_path(__FILE__) . '../../assets/css/open-positions.css';
    wp_enqueue_style(
        'eventadmin-open-positions',
        plugin_dir_url(__FILE__) . '../../assets/css/open-positions.css',
        [],
        file_exists($css_path) ? filemtime($css_path) : null
    );
}
add_action('admin_enqueue_scripts', 'eventadmin_documentation_enqueue_open_positions_style');

/**
 * Renders the Documentation page. Structured for a reader who just installed the plugin and
 * has no idea where to start, not as an alphabetical reference: a one-screen "start here"
 * checklist first, then a jump-to table of contents grouping every deeper topic under the
 * tier where it actually matters (setup vs. day-to-day vs. optional customization vs.
 * reference), so someone can either read top to bottom once or come back later and jump
 * straight to the one section they need.
 *
 * @return void
 */
function eventadmin_plugin_documentation_page()
{
    $eventadmin_doc_manager_timeline_url = admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-shift-manager&filter_view=timeline');
    $eventadmin_doc_manager_table_url    = admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-shift-manager&filter_view=table');
    $eventadmin_doc_settings_general_url = admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-settings');
    $eventadmin_doc_settings_comm_url    = admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-settings&tab=communication');
    $eventadmin_doc_announcement_url     = admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-bulk-email');
    $eventadmin_doc_departments_url      = admin_url('edit-tags.php?taxonomy=eventadmin_shift_category&post_type=eventadmin_shift');
    $eventadmin_doc_volunteers_url       = admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-volunteers');
    $eventadmin_doc_cleanup_url          = admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-volunteers&tab=cleanup');
    $eventadmin_doc_public_page_id       = eventadmin_get_getting_started_public_page_id();
    $eventadmin_doc_public_page_url      = $eventadmin_doc_public_page_id ? get_permalink($eventadmin_doc_public_page_id) : '';
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('📘 EventAdmin – Documentation', 'eventadmin-volunteer-management'); ?></h1>

        <p>
            <?php echo esc_html__('EventAdmin is a simple yet powerful plugin for managing volunteers at events.', 'eventadmin-volunteer-management'); ?>
            <?php echo esc_html__('Ideal for clubs, street festivals, and events. Organizers can create shifts, assign participants, or let volunteers sign up themselves. CSV export, limits, visual statistics, and an admin dashboard are included.', 'eventadmin-volunteer-management'); ?>
        </p>

        <details style="margin-bottom:16px;">
            <summary style="cursor:pointer;"><?php echo esc_html__('See the full feature list', 'eventadmin-volunteer-management'); ?></summary>
            <ul>
                <li><?php echo esc_html__('Create shifts with time period, category, and min./max. volunteers', 'eventadmin-volunteer-management'); ?></li>
                <li><?php echo esc_html__('Frontend view for registered volunteers (registration, shift selection, profile)', 'eventadmin-volunteer-management'); ?></li>
                <li><?php echo esc_html__('Self-registration and cancellation by volunteers', 'eventadmin-volunteer-management'); ?></li>
                <li><?php echo esc_html__('Optional "Full shifts" section visible to volunteers (enable under Settings)', 'eventadmin-volunteer-management'); ?></li>
                <li><?php echo esc_html__('Automatic checks (e.g. max. 2 shifts/year, no overlaps)', 'eventadmin-volunteer-management'); ?></li>
                <li><?php echo esc_html__('Manual assignment by admins, including creating new volunteer accounts on the fly', 'eventadmin-volunteer-management'); ?></li>
                <li><?php echo esc_html__('CSV export per shift or for all shifts', 'eventadmin-volunteer-management'); ?></li>
                <li><?php echo esc_html__('Hierarchical departments with custom colors, optional "hide from volunteers", and inline Quick Edit', 'eventadmin-volunteer-management'); ?></li>
                <li><?php echo esc_html__('Public "open positions" overview shortcode for visitors who are not registered yet', 'eventadmin-volunteer-management'); ?></li>
                <li><?php echo esc_html__('Per-shift organizer: a linked WordPress user and/or a manual sender name and email', 'eventadmin-volunteer-management'); ?></li>
                <li><?php echo esc_html__('Assignment, cancellation, and reminder emails (reminders a configurable number of days before a shift)', 'eventadmin-volunteer-management'); ?></li>
                <li><?php echo esc_html__('Registration form with honeypot and optional CAPTCHA (reCAPTCHA, hCaptcha, or Cloudflare Turnstile); blocked attempts are logged', 'eventadmin-volunteer-management'); ?></li>
                <li><?php echo esc_html__('E-mail design settings: header color, header title/subtitle, rich-text templates, and custom CSS', 'eventadmin-volunteer-management'); ?></li>
                <li><?php echo esc_html__('Shift overview with Dashboard, Cards, Table, and Timeline tabs — drag shifts on the Timeline to reschedule; dashboard stats split open spots into required vs. optional', 'eventadmin-volunteer-management'); ?></li>
                <li><?php echo esc_html__('Bulk email tool: send announcements to all, opted-in, shift-specific, or individual volunteers; supports HTML formatting; includes progress tracking and send history', 'eventadmin-volunteer-management'); ?></li>
                <li><?php echo esc_html__('Volunteers can opt out of announcements in their profile', 'eventadmin-volunteer-management'); ?></li>
                <li><?php echo esc_html__('Integration with Nextend Social Login', 'eventadmin-volunteer-management'); ?></li>
            </ul>
        </details>

        <div class="notice notice-info" style="padding:16px;margin:0 0 24px;">
            <h2 style="margin-top:0;"><?php echo esc_html__('New here? Start with these steps', 'eventadmin-volunteer-management'); ?></h2>
            <ol style="margin-bottom:0;">
                <li><?php echo esc_html__('Install the plugin via the WordPress backend or upload the ZIP', 'eventadmin-volunteer-management'); ?></li>
                <li><?php echo esc_html__('Activate the plugin', 'eventadmin-volunteer-management'); ?></li>
                <li><?php echo esc_html__('Create a page and insert the', 'eventadmin-volunteer-management'); ?> <code>[eventadmin]</code> <?php echo esc_html__('shortcode — this is the main volunteer page (registration for new visitors, shift selector and profile for logged-in volunteers)', 'eventadmin-volunteer-management'); ?></li>
                <li><?php echo esc_html__('Create shift categories via the "Departments" button on the Manager page', 'eventadmin-volunteer-management'); ?></li>
                <li><?php echo esc_html__('Create your first shifts under Shifts → Add New', 'eventadmin-volunteer-management'); ?></li>
            </ol>
            <p style="margin-bottom:0;margin-top:12px;">
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %s: link to the Overview page */
                        __('That\'s the minimum to go live. There\'s also a step-by-step %s on the dashboard itself that tracks this for you and points out a few more things worth trying.', 'eventadmin-volunteer-management'),
                        '<a href="' . esc_url(admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-overview')) . '">' . esc_html__('"Getting started" checklist', 'eventadmin-volunteer-management') . '</a>'
                    ),
                    ['a' => ['href' => []]]
                );
                ?>
            </p>
        </div>

        <h2><?php echo esc_html__('On this page', 'eventadmin-volunteer-management'); ?></h2>
        <ul>
            <li>
                <strong><?php echo esc_html__('Departments & Roles', 'eventadmin-volunteer-management'); ?></strong>
                <ul>
                    <li><a href="#eventadmin-doc-roles"><?php echo esc_html__('User Roles', 'eventadmin-volunteer-management'); ?></a></li>
                    <li><a href="#eventadmin-doc-departments"><?php echo esc_html__('Departments', 'eventadmin-volunteer-management'); ?></a></li>
                </ul>
            </li>
            <li>
                <strong><?php echo esc_html__('Running Your Event', 'eventadmin-volunteer-management'); ?></strong>
                <ul>
                    <li><a href="#eventadmin-doc-registration-login"><?php echo esc_html__('Volunteer Registration & Login', 'eventadmin-volunteer-management'); ?></a></li>
                    <li><a href="#eventadmin-doc-shift-overview"><?php echo esc_html__('Shift Overview', 'eventadmin-volunteer-management'); ?></a></li>
                    <li><a href="#eventadmin-doc-volunteer-list"><?php echo esc_html__('Volunteer List & Badges', 'eventadmin-volunteer-management'); ?></a></li>
                    <li><a href="#eventadmin-doc-cleanup"><?php echo esc_html__('Automatic Cleanup of Unverified Accounts', 'eventadmin-volunteer-management'); ?></a></li>
                </ul>
            </li>
            <li>
                <strong><?php echo esc_html__('Communication', 'eventadmin-volunteer-management'); ?></strong>
                <ul>
                    <li><a href="#eventadmin-doc-send-announcement"><?php echo esc_html__('Send Announcement', 'eventadmin-volunteer-management'); ?></a></li>
                    <li><a href="#eventadmin-doc-reminders"><?php echo esc_html__('Reminder & Notification Emails', 'eventadmin-volunteer-management'); ?></a></li>
                </ul>
            </li>
            <li>
                <strong><?php echo esc_html__('Customizing Emails', 'eventadmin-volunteer-management'); ?></strong>
                <ul>
                    <li><a href="#eventadmin-doc-email-design"><?php echo esc_html__('E-mail Design', 'eventadmin-volunteer-management'); ?></a></li>
                    <li><a href="#eventadmin-doc-email-template"><?php echo esc_html__('Email Template Customization', 'eventadmin-volunteer-management'); ?></a></li>
                </ul>
            </li>
            <li>
                <strong><?php echo esc_html__('Public Recruitment', 'eventadmin-volunteer-management'); ?></strong>
                <ul>
                    <li><a href="#eventadmin-doc-open-positions"><?php echo esc_html__('Public "Open Positions" Overview', 'eventadmin-volunteer-management'); ?></a></li>
                </ul>
            </li>
            <li>
                <strong><?php echo esc_html__('Connecting an AI Assistant (MCP)', 'eventadmin-volunteer-management'); ?></strong>
                <ul>
                    <li><a href="#eventadmin-doc-mcp-what"><?php echo esc_html__('What is this?', 'eventadmin-volunteer-management'); ?></a></li>
                    <li><a href="#eventadmin-doc-mcp-setup"><?php echo esc_html__('Setting it up', 'eventadmin-volunteer-management'); ?></a></li>
                    <li><a href="#eventadmin-doc-mcp-can-do"><?php echo esc_html__('What it can do', 'eventadmin-volunteer-management'); ?></a></li>
                    <li><a href="#eventadmin-doc-mcp-security"><?php echo esc_html__('Security', 'eventadmin-volunteer-management'); ?></a></li>
                </ul>
            </li>
            <li>
                <strong><?php echo esc_html__('Reference', 'eventadmin-volunteer-management'); ?></strong>
                <ul>
                    <li><a href="#eventadmin-doc-shortcodes"><?php echo esc_html__('All Shortcodes', 'eventadmin-volunteer-management'); ?></a></li>
                    <li><a href="#eventadmin-doc-faq"><?php echo esc_html__('Frequently Asked Questions (FAQ)', 'eventadmin-volunteer-management'); ?></a></li>
                </ul>
            </li>
        </ul>

        <hr style="margin:32px 0;">

        <h2 id="eventadmin-doc-departments-roles"><?php echo esc_html__('Departments & Roles', 'eventadmin-volunteer-management'); ?></h2>
        <p><?php echo esc_html__('Departments group your shifts; roles decide who in your organization can manage what.', 'eventadmin-volunteer-management'); ?></p>

        <h3 id="eventadmin-doc-roles"><?php echo esc_html__('User Roles', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('Besides the Volunteer role, the plugin offers two roles for delegating day-to-day work without handing out full Administrator access:', 'eventadmin-volunteer-management'); ?></p>
        <table class="widefat striped" style="max-width:800px;margin-bottom:16px;">
            <thead><tr>
                <th><?php echo esc_html__('Role', 'eventadmin-volunteer-management'); ?></th>
                <th><?php echo esc_html__('Can do', 'eventadmin-volunteer-management'); ?></th>
            </tr></thead>
            <tbody>
                <tr>
                    <td><strong><?php echo esc_html__('Volunteer', 'eventadmin-volunteer-management'); ?></strong></td>
                    <td><?php echo esc_html__('Sign up for and cancel shifts, edit their own profile. No access to wp-admin beyond that.', 'eventadmin-volunteer-management'); ?></td>
                </tr>
                <tr>
                    <td><strong><?php echo esc_html__('Volunteer Manager', 'eventadmin-volunteer-management'); ?></strong></td>
                    <td><?php echo esc_html__('Manage volunteers (edit their department links) and send announcements. Cannot create, edit, or delete shifts, and cannot access Import or Settings.', 'eventadmin-volunteer-management'); ?></td>
                </tr>
                <tr>
                    <td><strong><?php echo esc_html__('Shift Manager', 'eventadmin-volunteer-management'); ?></strong></td>
                    <td><?php echo esc_html__('Everything Volunteer Manager can do, plus create, edit, and delete shifts and departments. Cannot access Import or Settings.', 'eventadmin-volunteer-management'); ?></td>
                </tr>
                <tr>
                    <td><strong><?php echo esc_html__('Administrator', 'eventadmin-volunteer-management'); ?></strong></td>
                    <td><?php echo esc_html__('Full access to everything, including Import and Settings.', 'eventadmin-volunteer-management'); ?></td>
                </tr>
            </tbody>
        </table>
        <p><?php echo esc_html__('Assign a role under Users → All Users → edit the user → Role.', 'eventadmin-volunteer-management'); ?></p>
        <p><strong><?php echo esc_html__('Important:', 'eventadmin-volunteer-management'); ?></strong> <?php echo esc_html__('a WordPress account can only have one role at a time through that Role dropdown — picking a new role there replaces the old one, it does not add to it. So switching an Administrator to Shift Manager or Volunteer Manager removes their Administrator access; only do this for accounts that should not have full admin access. The one exception is the Volunteer role: use the "Grant volunteer role" / "Remove role" buttons on the Volunteers page instead of the Role dropdown to add or remove it without disturbing whatever other role the account already has — for example, to let a Shift Manager or Volunteer Manager also show up on the Volunteers page itself, so they can be assigned to a shift or receive department-targeted announcements.', 'eventadmin-volunteer-management'); ?></p>

        <h3 id="eventadmin-doc-departments"><?php echo esc_html__('Departments', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('Departments (Manager → Departments) group shifts, can be nested under a parent, and each carries a color used for its badge on shift cards and in the overview. "Hide from volunteers" removes a department from the frontend filter and labels; a shift disappears for volunteers only when every department it belongs to is hidden (shifts someone already signed up for are never hidden). Color, parent, visibility, and description can also be changed via Quick Edit in the department list, and the shift list itself can be filtered and sorted by department.', 'eventadmin-volunteer-management'); ?></p>
        <?php
        eventadmin_doc_screenshot(
            'departments.webp',
            __('Adding a department: nest it under a parent to build a hierarchy, give it a color for its badges, and optionally hide it from volunteers without deleting it — shifts already assigned there stay visible to the people on them.', 'eventadmin-volunteer-management'),
            $eventadmin_doc_departments_url,
            __('Open Departments', 'eventadmin-volunteer-management'),
            false
        );
        ?>

        <hr style="margin:32px 0;">

        <h2 id="eventadmin-doc-running-event"><?php echo esc_html__('Running Your Event', 'eventadmin-volunteer-management'); ?></h2>
        <p><?php echo esc_html__('Once shifts exist, this is where you\'ll spend most of your time: watching sign-ups, managing volunteers, and keeping everyone informed. Most of the rules mentioned below — registration behavior, shift limits, overlap prevention, spam protection — live on one screen, Shifts → Settings.', 'eventadmin-volunteer-management'); ?></p>
        <?php
        eventadmin_doc_screenshot(
            'settings-general.webp',
            __('Settings → General: whether WordPress sends its own "set your password" email, and the shift-limit rules (per day/week/month/year, overlap prevention, cancellation deadline). Scroll further on this tab for the admin-menu and CAPTCHA options mentioned in the FAQ below.', 'eventadmin-volunteer-management'),
            $eventadmin_doc_settings_general_url,
            __('Open Settings', 'eventadmin-volunteer-management'),
            false
        );
        ?>

        <h3 id="eventadmin-doc-registration-login"><?php echo esc_html__('Volunteer Registration & Login', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('The [eventadmin] page offers two ways in, so people can pick whichever is faster for them — neither is required for the other to work:', 'eventadmin-volunteer-management'); ?></p>
        <ul>
            <li><strong><?php echo esc_html__('Social login', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('install the free Nextend Social Login plugin and configure at least one provider (Google, Facebook, and X are included free) and its buttons appear above the registration form automatically — no code or shortcode changes needed. One click, no form to fill in: the easiest option for anyone who already has one of those accounts.', 'eventadmin-volunteer-management'); ?></li>
            <li><strong><?php echo esc_html__('Magic login link', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('everyone else fills in name, phone, and email and gets a one-time login link by email instead of a password (see the FAQ below). No account to remember, and no "forgot password" flow to support — this is what makes the plugin usable for occasional, one-off volunteers who won\'t want to set up a login just for one shift.', 'eventadmin-volunteer-management'); ?></li>
        </ul>
        <p><?php echo esc_html__('Both create the same volunteer account underneath. Someone who registers via Google still receives the same assignment and reminder emails, shows up once on the Volunteers page, and is badged "Social" there (see Volunteer List & Badges below) so you can see at a glance how each volunteer signed up.', 'eventadmin-volunteer-management'); ?></p>
        <?php
        eventadmin_doc_screenshot(
            'volunteer-available-shifts.webp',
            __('The [eventadmin] page as a logged-in volunteer sees it: shifts they\'ve already joined at the top ("My shifts"), then everything still open to sign up for below — department, time, spots left, and who else is already on it.', 'eventadmin-volunteer-management'),
            $eventadmin_doc_public_page_url,
            $eventadmin_doc_public_page_url ? __('Open your volunteer page', 'eventadmin-volunteer-management') : null,
            true
        );
        ?>

        <h3 id="eventadmin-doc-shift-overview"><?php echo esc_html__('Shift Overview', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('The shift overview (Shifts → Overview) is split into four tabs, each showing only the filters relevant to it:', 'eventadmin-volunteer-management'); ?></p>
        <ul>
            <li><strong><?php echo esc_html__('Dashboard', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('registered volunteers, upcoming shifts, and open spots split into required (below a shift\'s minimum) and optional (up to the maximum).', 'eventadmin-volunteer-management'); ?></li>
            <li><strong><?php echo esc_html__('Cards', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('the shift cards, with filters for upcoming/past/all, department, volunteer, and date.', 'eventadmin-volunteer-management'); ?></li>
            <li><strong><?php echo esc_html__('Table', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('a flat, export-style table; the "Add volunteer" button assigns someone without leaving the page.', 'eventadmin-volunteer-management'); ?></li>
            <li><strong><?php echo esc_html__('Timeline', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('a per-volunteer Gantt chart. Drag a shift\'s bar to move or resize it (with an Undo after saving), or use the Edit Shift modal for exact times. Unfilled slots show as red (below minimum) or grey (optional) bars, which can be toggled off.', 'eventadmin-volunteer-management'); ?></li>
        </ul>
        <?php
        eventadmin_doc_screenshot(
            'timeline-view.webp',
            __('The Timeline tab for one day: one row per volunteer, coloured bars for their shifts, and grey/red bars for the slots still open. Drag a bar sideways to reschedule it.', 'eventadmin-volunteer-management'),
            $eventadmin_doc_manager_timeline_url,
            __('Open Manager (Timeline)', 'eventadmin-volunteer-management'),
            false
        );
        eventadmin_doc_screenshot(
            'timeline-context-menu.webp',
            __('Click any bar on the Timeline — assigned or open — to get this menu: edit the shift\'s details, view that volunteer\'s profile, move them to a different shift, remove them from this one, or delete the shift entirely.', 'eventadmin-volunteer-management'),
            $eventadmin_doc_manager_timeline_url,
            __('Open Manager (Timeline)', 'eventadmin-volunteer-management'),
            true
        );
        eventadmin_doc_screenshot(
            'edit-shift-modal.webp',
            __('The Edit Shift modal, opened from "+ Add shift" or from any shift\'s menu: title, department, exact start/end time, minimum/maximum volunteers, and a rich-text description — no separate page needed.', 'eventadmin-volunteer-management'),
            $eventadmin_doc_manager_timeline_url,
            __('Open Manager', 'eventadmin-volunteer-management'),
            false
        );
        eventadmin_doc_screenshot(
            'add-volunteer-modal.webp',
            __('The Table tab\'s "Add volunteer" button opens this: pick an existing volunteer from the dropdown, or fill in the fields below to create a brand-new account — without ever leaving the shift list.', 'eventadmin-volunteer-management'),
            $eventadmin_doc_manager_table_url,
            __('Open Manager (Table)', 'eventadmin-volunteer-management'),
            true
        );
        ?>

        <h3 id="eventadmin-doc-volunteer-list"><?php echo esc_html__('Volunteer List & Badges', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('The Volunteers page (Shifts → Volunteers) lists all volunteer accounts and shows badges next to each name to indicate how the account was created or its current status.', 'eventadmin-volunteer-management'); ?></p>
        <table class="widefat striped" style="max-width:640px;margin-bottom:16px;">
            <thead><tr>
                <th><?php echo esc_html__('Badge', 'eventadmin-volunteer-management'); ?></th>
                <th><?php echo esc_html__('Meaning', 'eventadmin-volunteer-management'); ?></th>
            </tr></thead>
            <tbody>
                <tr>
                    <td><span style="background:#777;color:#fff;font-size:11px;padding:2px 6px;border-radius:3px;"><?php echo esc_html__('Offline', 'eventadmin-volunteer-management'); ?></span></td>
                    <td><?php echo esc_html__('Created by an admin without an email address. Cannot log in and receives no notifications.', 'eventadmin-volunteer-management'); ?></td>
                </tr>
                <tr>
                    <td><span style="background:#2e7d32;color:#fff;font-size:11px;padding:2px 6px;border-radius:3px;"><?php echo esc_html__('Manual', 'eventadmin-volunteer-management'); ?></span></td>
                    <td><?php echo esc_html__('Added by an admin via the dashboard form or the "Grant volunteer role" function. Never auto-deleted.', 'eventadmin-volunteer-management'); ?></td>
                </tr>
                <tr>
                    <td><span style="background:#dba617;color:#fff;font-size:11px;padding:2px 6px;border-radius:3px;"><?php echo esc_html__('Unverified', 'eventadmin-volunteer-management'); ?></span></td>
                    <td><?php echo esc_html__('Registered via the public form but has not yet clicked the magic login link. The account is auto-deleted once the link expires (~24 h). The badge disappears as soon as the link is clicked.', 'eventadmin-volunteer-management'); ?></td>
                </tr>
                <tr>
                    <td><span style="background:#4285f4;color:#fff;font-size:11px;padding:2px 6px;border-radius:3px;"><?php echo esc_html__('Social', 'eventadmin-volunteer-management'); ?></span></td>
                    <td><?php echo esc_html__('Registered or linked via Nextend Social Login (e.g. Google, Facebook). Requires the Nextend Social Login plugin.', 'eventadmin-volunteer-management'); ?></td>
                </tr>
                <tr>
                    <td><em><?php echo esc_html__('(no badge)', 'eventadmin-volunteer-management'); ?></em></td>
                    <td><?php echo esc_html__('Self-registered via the public form and verified by clicking the magic link, or registered before the badge system was introduced (version 0.9.8).', 'eventadmin-volunteer-management'); ?></td>
                </tr>
            </tbody>
        </table>
        <p><?php echo esc_html__('A volunteer can have multiple badges at the same time (e.g. Manual + Social).', 'eventadmin-volunteer-management'); ?></p>
        <?php
        eventadmin_doc_screenshot(
            'volunteer-list-badges.webp',
            __('Three volunteers in practice: one who registered but has not clicked their login link yet (Unverified), one you added by hand (Manual), and one with no badge at all — self-registered, verified, and linked to two departments.', 'eventadmin-volunteer-management'),
            $eventadmin_doc_volunteers_url,
            __('Open Volunteers', 'eventadmin-volunteer-management'),
            true
        );
        ?>

        <h3 id="eventadmin-doc-cleanup"><?php echo esc_html__('Automatic Cleanup of Unverified Accounts', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('A daily background job automatically deletes volunteer accounts that were registered via the public form but whose magic login link expired without ever being clicked. Accounts are only deleted if they have no shift assignments and are not marked as manually added.', 'eventadmin-volunteer-management'); ?></p>
        <p><?php echo esc_html__('A log of automatically deleted accounts (up to the last 100) is shown at the bottom of the Volunteers page.', 'eventadmin-volunteer-management'); ?></p>
        <?php
        eventadmin_doc_screenshot(
            'auto-cleanup.webp',
            __('The cleanup log on the Volunteers page — every account this automatic job has removed, with the date it happened, so you can double-check nobody real got swept up by mistake.', 'eventadmin-volunteer-management'),
            $eventadmin_doc_cleanup_url,
            __('Open the cleanup log', 'eventadmin-volunteer-management'),
            false
        );
        ?>

        <hr style="margin:32px 0;">

        <h2 id="eventadmin-doc-communication"><?php echo esc_html__('Communication', 'eventadmin-volunteer-management'); ?></h2>
        <p><?php echo esc_html__('Everything the plugin sends on your behalf — announcements you write yourself, plus the automatic emails volunteers and organizers get as shifts fill up.', 'eventadmin-volunteer-management'); ?></p>

        <h3 id="eventadmin-doc-send-announcement"><?php echo esc_html__('Send Announcement', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('The Send Announcement page (Shifts → Send Announcement) lets you send a custom email to volunteers. Available recipient options:', 'eventadmin-volunteer-management'); ?></p>
        <ul>
            <li><strong><?php echo esc_html__('Subscribed only', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('Volunteers who opted in to announcements (default).', 'eventadmin-volunteer-management'); ?></li>
            <li><strong><?php echo esc_html__('All volunteers', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('Every volunteer with an email address, regardless of opt-in status.', 'eventadmin-volunteer-management'); ?></li>
            <li><strong><?php echo esc_html__('Volunteers without any upcoming shift', 'eventadmin-volunteer-management'); ?></strong> / <strong><?php echo esc_html__('Volunteers with at least one upcoming shift', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('Split volunteers by whether they currently have an upcoming shift assigned — useful for nudging people who haven\'t signed up yet.', 'eventadmin-volunteer-management'); ?></li>
            <li><strong><?php echo esc_html__('Volunteers of a specific shift', 'eventadmin-volunteer-management'); ?></strong> / <strong><?php echo esc_html__('Volunteers of a specific category', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('Only the volunteers assigned to a selected shift or department. A live recipient count is shown once you pick one.', 'eventadmin-volunteer-management'); ?></li>
            <li><strong><?php echo esc_html__('Volunteers linked to a department', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('Every volunteer linked to a department (Volunteers page → Edit), regardless of shift history or opt-in status — useful for announcing new shifts to a department before anyone has signed up for one.', 'eventadmin-volunteer-management'); ?></li>
            <li><strong><?php echo esc_html__('Individual volunteer', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('Click "Email" on any volunteer row to open the form pre-filled for that person.', 'eventadmin-volunteer-management'); ?></li>
        </ul>
        <p><?php echo esc_html__('The From name and From email can be overridden per send. Use {first_name} and {last_name} as personalisation placeholders, and {shifts} to list each recipient\'s own upcoming shifts. HTML formatting is supported — the live preview below the form renders the email as it will appear.', 'eventadmin-volunteer-management'); ?></p>
        <p><?php echo esc_html__('A PDF can optionally be attached — it will be sent with every email in that announcement.', 'eventadmin-volunteer-management'); ?></p>
        <p><?php echo esc_html__('Every sent announcement is recorded in the collapsible Send History table on the same page. The table can be filtered by typing and sorted by clicking column headers.', 'eventadmin-volunteer-management'); ?></p>
        <p><?php echo esc_html__('Offline volunteers (no email address) are excluded from all announcement sending. Volunteers can opt out under their profile.', 'eventadmin-volunteer-management'); ?></p>
        <?php
        eventadmin_doc_screenshot(
            'send-announcement.webp',
            __('Recipient groups on the left, sender and attachment on the right, and a live preview on the right that renders {first_name} and {shifts} with real example data as you type — so you see exactly what recipients will get before you send it.', 'eventadmin-volunteer-management'),
            $eventadmin_doc_announcement_url,
            __('Open Send Announcement', 'eventadmin-volunteer-management'),
            false
        );
        ?>

        <h3 id="eventadmin-doc-reminders"><?php echo esc_html__('Reminder & Notification Emails', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('Volunteers are emailed when they are assigned to or removed from a shift, and again as a reminder a configurable number of days before it starts (Settings → Communication → Reminders — leave the days field empty to switch reminders off). All three messages use a rich-text editor and share placeholders such as {first}, {last}, {title}, {start}, {end}, {desc}, {department}, {department_desc}, and {days}. Each shift can override the sender through a linked WordPress user and/or a manual organizer name and email.', 'eventadmin-volunteer-management'); ?></p>
        <?php
        eventadmin_doc_screenshot(
            'volunteer-notifications.webp',
            __('The volunteer-facing side, under Settings → Communication → Shift Confirmations: separate subject and rich-text body for sign-up and sign-out, with the placeholders above available in both.', 'eventadmin-volunteer-management'),
            $eventadmin_doc_settings_comm_url,
            __('Open Settings → Communication', 'eventadmin-volunteer-management'),
            false
        );
        ?>
        <p><?php echo esc_html__('The shift\'s organizer is also emailed on every sign-up and cancellation — a separate pair of templates under Settings → Communication → Shift Confirmations → "Organizer Notifications", with the same placeholders as above.', 'eventadmin-volunteer-management'); ?></p>
        <?php
        eventadmin_doc_screenshot(
            'organizer-notifications.webp',
            __('The organizer-facing side, right below the volunteer one on the same tab: this is the email you (or whoever runs a shift) get every time someone signs up or cancels — separate from what the volunteer receives, and just as editable.', 'eventadmin-volunteer-management'),
            $eventadmin_doc_settings_comm_url,
            __('Open Settings → Communication', 'eventadmin-volunteer-management'),
            true
        );
        ?>

        <hr style="margin:32px 0;">

        <h2 id="eventadmin-doc-customizing-emails"><?php echo esc_html__('Customizing Emails', 'eventadmin-volunteer-management'); ?></h2>
        <p><?php echo esc_html__('Optional — every email already looks fine with the defaults. Come back here once you want your own branding or wording.', 'eventadmin-volunteer-management'); ?></p>

        <h3 id="eventadmin-doc-email-design"><?php echo esc_html__('E-mail Design', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('Settings → Communication → E-mail design styles the shared wrapper around every plugin email: a header color, a header title and subtitle shown next to the site logo, a rich-text footer, and — under advanced — custom CSS applied to all emails. The filters below are for changes that go beyond these options.', 'eventadmin-volunteer-management'); ?></p>
        <?php
        eventadmin_doc_screenshot(
            'email-design.webp',
            __('The full placeholder reference on the right, and a live preview below it that re-renders every plugin email with example data as you change the header color, title, or sender — so you can see exactly what volunteers receive before saving anything.', 'eventadmin-volunteer-management'),
            $eventadmin_doc_settings_comm_url,
            __('Open Settings → Communication', 'eventadmin-volunteer-management'),
            true
        );
        ?>

        <h3 id="eventadmin-doc-email-template"><?php echo esc_html__('Email Template Customization', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('All plugin emails use a shared HTML wrapper so registration links, assignment confirmations, cancellations, and announcements have a consistent look.', 'eventadmin-volunteer-management'); ?></p>
        <p><?php echo esc_html__('Integrators can customize or fully replace this wrapper in theme code or a small companion plugin using WordPress filters.', 'eventadmin-volunteer-management'); ?></p>
        <ul>
            <li><code>eventadmin_email_template_args</code> – <?php echo esc_html__('Adjust template variables such as heading, preheader, site name, or footer text before the final HTML is generated.', 'eventadmin-volunteer-management'); ?></li>
            <li><code>eventadmin_email_template_message_html</code> – <?php echo esc_html__('Modify only the inner email body HTML while keeping the default wrapper.', 'eventadmin-volunteer-management'); ?></li>
            <li><code>eventadmin_email_template_html</code> – <?php echo esc_html__('Replace the complete final HTML output if you need a fully custom branded layout.', 'eventadmin-volunteer-management'); ?></li>
        </ul>
        <p><?php echo esc_html__('Example: add this in your theme’s functions.php or a small custom plugin to change the footer text of all EventAdmin emails.', 'eventadmin-volunteer-management'); ?></p>
        <pre><code>add_filter('eventadmin_email_template_args', function (array $args, string $subject, string $message): array {
    $args['footer_text'] = 'Questions? Reply to this email or contact volunteers@example.org.';
    return $args;
}, 10, 3);</code></pre>
        <p><?php echo esc_html__('Use a custom plugin instead of editing EventAdmin directly if you want your changes to survive plugin updates.', 'eventadmin-volunteer-management'); ?></p>

        <hr style="margin:32px 0;">

        <h2 id="eventadmin-doc-public-recruitment"><?php echo esc_html__('Public Recruitment', 'eventadmin-volunteer-management'); ?></h2>
        <p><?php echo esc_html__('Optional — for reaching people who haven\'t registered as a volunteer yet.', 'eventadmin-volunteer-management'); ?></p>

        <h3 id="eventadmin-doc-open-positions"><?php echo esc_html__('Public "Open Positions" Overview', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('The [eventadmin_open_positions] shortcode shows visitors who are not logged in where volunteers are still needed, grouped by department. Only departments with unfilled slots in upcoming shifts are listed. Put it on a public recruitment page, next to the [eventadmin_register] form.', 'eventadmin-volunteer-management'); ?></p>
        <?php
        eventadmin_doc_screenshot(
            'open-positions-list.webp',
            __('The "list" style: one card per open shift, grouped under its department with a running open-slot count — no login needed to see it.', 'eventadmin-volunteer-management'),
            null,
            null,
            false
        );
        eventadmin_doc_screenshot(
            'open-positions-summary.webp',
            __('The "summary" style (the default): one compact line per department, just the open-slot count — a quick "here\'s where help is still needed" overview rather than the full shift list.', 'eventadmin-volunteer-management'),
            null,
            null,
            true
        );
        ?>
        <table class="widefat striped" style="max-width:640px;margin-bottom:16px;">
            <thead><tr>
                <th><?php echo esc_html__('Attribute', 'eventadmin-volunteer-management'); ?></th>
                <th><?php echo esc_html__('Meaning', 'eventadmin-volunteer-management'); ?></th>
            </tr></thead>
            <tbody>
                <tr>
                    <td><code>style</code></td>
                    <td><?php echo esc_html__('"summary" (default): one line per department with its open-slot count. "list": every open shift, grouped under its department.', 'eventadmin-volunteer-management'); ?></td>
                </tr>
                <tr>
                    <td><code>category</code></td>
                    <td><?php echo esc_html__('Comma-separated department slugs to limit the output to. Default: all visible departments.', 'eventadmin-volunteer-management'); ?></td>
                </tr>
                <tr>
                    <td><code>show_intro</code></td>
                    <td><?php echo esc_html__('"0" hides the "We still need volunteers in:" intro line (summary style only).', 'eventadmin-volunteer-management'); ?></td>
                </tr>
                <tr>
                    <td><code>show_full</code></td>
                    <td><?php echo esc_html__('"1" also includes shifts whose slots are already filled (list style only).', 'eventadmin-volunteer-management'); ?></td>
                </tr>
                <tr>
                    <td><code>hide_past</code></td>
                    <td><?php echo esc_html__('"0" also includes shifts that have already ended. Default: past shifts are hidden.', 'eventadmin-volunteer-management'); ?></td>
                </tr>
            </tbody>
        </table>

        <p>
            <strong><?php echo esc_html__('Live preview, using your own site\'s real data:', 'eventadmin-volunteer-management'); ?></strong>
            <?php echo esc_html__('this is exactly what [eventadmin_open_positions] renders right now — no separate page needed to see it.', 'eventadmin-volunteer-management'); ?>
        </p>
        <div style="max-width:640px;margin-bottom:16px;padding:20px;background:#fff;border:1px solid #dcdcde;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.05);">
            <?php echo do_shortcode('[eventadmin_open_positions]'); ?>
        </div>

        <hr style="margin:32px 0;">

        <h2 id="eventadmin-doc-mcp"><?php echo esc_html__('Connecting an AI Assistant (MCP)', 'eventadmin-volunteer-management'); ?></h2>
        <p><?php echo esc_html__('Optional, and a bit more technical than the rest of this page — skip this section if it doesn\'t sound useful to you. Everything else in EventAdmin works exactly the same whether or not this is turned on.', 'eventadmin-volunteer-management'); ?></p>

        <h3 id="eventadmin-doc-mcp-what"><?php echo esc_html__('What is this?', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('MCP (Model Context Protocol) is a way for AI assistants such as Claude to connect to other apps and use them as tools. Turning this on lets a connected AI assistant look at your shifts and dashboard, and create new shifts, when you ask it to — without you needing to open wp-admin yourself.', 'eventadmin-volunteer-management'); ?></p>

        <h3 id="eventadmin-doc-mcp-setup"><?php echo esc_html__('Setting it up', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('This needs Node.js installed on the computer running your AI assistant (a free, one-time install — see nodejs.org if you don\'t have it yet). Once that\'s done:', 'eventadmin-volunteer-management'); ?></p>
        <ol style="max-width:640px;">
            <li><?php echo esc_html__('Go to Settings → General, turn on "Allow API access" under "API access", and save.', 'eventadmin-volunteer-management'); ?></li>
            <li><?php echo esc_html__('A "Connect an MCP client" button now appears in that same section. Click it.', 'eventadmin-volunteer-management'); ?></li>
            <li><?php echo esc_html__('You land on WordPress\'s own "Authorize Application" screen — click "Approve". This only grants the same access level your own account already has; it doesn\'t share your WordPress login itself.', 'eventadmin-volunteer-management'); ?></li>
            <li><?php echo esc_html__('You\'re taken back to Settings, where a green box shows a ready-to-use configuration. Click "Copy to clipboard".', 'eventadmin-volunteer-management'); ?></li>
            <li><?php echo esc_html__('Paste that into your AI assistant\'s configuration — see the two common examples just below, depending on which one you use.', 'eventadmin-volunteer-management'); ?></li>
        </ol>

        <p><strong><?php echo esc_html__('Example: Claude Desktop', 'eventadmin-volunteer-management'); ?></strong><br>
        <?php echo esc_html__('Settings → Developer → Edit Config. This opens (or creates) a file called claude_desktop_config.json. If it already lists other servers, add "eventadmin" alongside them inside the same "mcpServers" section instead of replacing the file; otherwise paste the whole thing. Save, then fully quit and reopen Claude Desktop (closing the window isn\'t enough).', 'eventadmin-volunteer-management'); ?></p>
        <pre><code>{
  "mcpServers": {
    "eventadmin": {
      "command": "npx",
      "args": ["-y", "eventadmin-mcp-server"],
      "env": {
        "EVENTADMIN_SITE_URL": "https://example.com",
        "EVENTADMIN_USERNAME": "admin",
        "EVENTADMIN_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}</code></pre>

        <p><strong><?php echo esc_html__('Example: Claude Code', 'eventadmin-volunteer-management'); ?></strong><br>
        <?php echo esc_html__('Run this in a terminal, using the site URL, username and password from what you copied:', 'eventadmin-volunteer-management'); ?></p>
        <pre><code>claude mcp add-json eventadmin '{"command":"npx","args":["-y","eventadmin-mcp-server"],"env":{"EVENTADMIN_SITE_URL":"https://example.com","EVENTADMIN_USERNAME":"admin","EVENTADMIN_APP_PASSWORD":"xxxx xxxx xxxx xxxx xxxx xxxx"}}'</code></pre>
        <p><?php echo esc_html__('Then run', 'eventadmin-volunteer-management'); ?> <code>claude mcp list</code> <?php echo esc_html__('to confirm it registered.', 'eventadmin-volunteer-management'); ?></p>

        <p><?php echo esc_html__('That\'s it — your assistant can now see and manage your shifts when you ask it to.', 'eventadmin-volunteer-management'); ?></p>

        <h3 id="eventadmin-doc-mcp-can-do"><?php echo esc_html__('What it can do', 'eventadmin-volunteer-management'); ?></h3>
        <table class="widefat striped" style="max-width:640px;margin-bottom:16px;">
            <thead><tr>
                <th><?php echo esc_html__('Attribute', 'eventadmin-volunteer-management'); ?></th>
                <th><?php echo esc_html__('Meaning', 'eventadmin-volunteer-management'); ?></th>
            </tr></thead>
            <tbody>
                <tr><td><code>list_shifts</code></td><td><?php echo esc_html__('Lists shifts, optionally filtered by department, date, or time period. Each shift includes who is assigned to it.', 'eventadmin-volunteer-management'); ?></td></tr>
                <tr><td><code>get_shift</code></td><td><?php echo esc_html__('Gets the full details of one specific shift, including who is assigned to it.', 'eventadmin-volunteer-management'); ?></td></tr>
                <tr><td><code>create_shift</code></td><td><?php echo esc_html__('Creates a new shift.', 'eventadmin-volunteer-management'); ?></td></tr>
                <tr><td><code>get_dashboard</code></td><td><?php echo esc_html__('Gets the same overview numbers shown on the Overview dashboard (open shifts, understaffed shifts, and so on).', 'eventadmin-volunteer-management'); ?></td></tr>
                <tr><td><code>notify_shift</code></td><td><?php echo esc_html__('Emails everyone assigned to a specific shift.', 'eventadmin-volunteer-management'); ?></td></tr>
                <tr><td><code>list_departments</code></td><td><?php echo esc_html__('Lists every department, so a shift can be created in one by name.', 'eventadmin-volunteer-management'); ?></td></tr>
                <tr><td><code>get_settings</code></td><td><?php echo esc_html__('Gets the active sign-up rules: shift limits per volunteer, overlap policy, cancellation deadline.', 'eventadmin-volunteer-management'); ?></td></tr>
                <tr><td><code>get_documentation</code></td><td><?php echo esc_html__('Gets this Documentation page as plain text, so your assistant can answer "how does this work" questions from it.', 'eventadmin-volunteer-management'); ?></td></tr>
            </tbody>
        </table>

        <h3 id="eventadmin-doc-mcp-security"><?php echo esc_html__('Security', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('Most of this requires an Administrator or Shift Manager account — the same permission level already required for these actions in wp-admin. Two exceptions: emailing a shift\'s roster only needs volunteer-management access (so a Volunteer Manager account can do that one thing, the same as "Send Announcement" in wp-admin), and reading settings requires a full Administrator account (Shift Managers can\'t see the Settings page in wp-admin either).', 'eventadmin-volunteer-management'); ?></p>
        <p><?php echo esc_html__('To disconnect it again, turn "Allow API access" back off, and/or remove the "EventAdmin MCP" entry under Users → your profile → Application Passwords.', 'eventadmin-volunteer-management'); ?></p>

        <hr style="margin:32px 0;">

        <h2 id="eventadmin-doc-reference"><?php echo esc_html__('Reference', 'eventadmin-volunteer-management'); ?></h2>
        <p><?php echo esc_html__('Everything else: the full shortcode list and answers to common questions.', 'eventadmin-volunteer-management'); ?></p>

        <h3 id="eventadmin-doc-shortcodes"><?php echo esc_html__('All Shortcodes', 'eventadmin-volunteer-management'); ?></h3>
        <ul>
            <li>
                <pre><code>[eventadmin]</code></pre>
                <?php echo esc_html__('The main volunteer page — registration for new visitors, shift selector and profile for logged-in volunteers. This is the one shortcode almost every site needs.', 'eventadmin-volunteer-management'); ?>
            </li>
            <li>
                <pre><code>[eventadmin_register]</code></pre>
                <?php echo esc_html__('Shows the registration form for new volunteers – and if logged in – the complete cockpit.', 'eventadmin-volunteer-management'); ?>
            </li>
            <li>
                <pre><code>[eventadmin_cockpit]</code></pre>
                <?php echo esc_html__('Shows the full cockpit for logged-in volunteers (profile & shift selection).', 'eventadmin-volunteer-management'); ?>
            </li>
            <li>
                <pre><code>[eventadmin_profile]</code></pre>
                <?php echo esc_html__('Shows the volunteer profile for logged-in users.', 'eventadmin-volunteer-management'); ?>
            </li>
            <li>
                <pre><code>[eventadmin_shiftselector]</code></pre>
                <?php echo esc_html__('Volunteers can sign up for or cancel shifts.', 'eventadmin-volunteer-management'); ?>
            </li>
            <li>
                <pre><code>[eventadmin_open_positions]</code></pre>
                <?php echo esc_html__('Public, no-login overview of where volunteers are still needed, grouped by department. Ideal for a recruitment page that points visitors to the registration form.', 'eventadmin-volunteer-management'); ?>
            </li>
        </ul>

        <h3 id="eventadmin-doc-faq"><?php echo esc_html__('Frequently Asked Questions (FAQ)', 'eventadmin-volunteer-management'); ?></h3>
        <dl>
            <dt><strong><?php echo esc_html__('Do volunteers need an account?', 'eventadmin-volunteer-management'); ?></strong></dt>
            <dd><?php echo esc_html__('Yes, they must be logged in to view and join shifts.', 'eventadmin-volunteer-management'); ?></dd>

            <dt><strong><?php echo esc_html__('How can I assign volunteers manually?', 'eventadmin-volunteer-management'); ?></strong></dt>
            <dd><?php echo esc_html__('Open Shifts → Overview. In the Table view use the "Add volunteer" button on a shift, or click an open slot in the Timeline view. You can pick an existing volunteer or create a new account (with or without an email address) on the spot.', 'eventadmin-volunteer-management'); ?></dd>

            <dt><strong><?php echo esc_html__('What happens when shifts are full?', 'eventadmin-volunteer-management'); ?></strong></dt>
            <dd><?php echo esc_html__('By default, full shifts are hidden on the volunteer page. You can optionally show them in a separate read-only section by enabling "Show full shifts to volunteers" under Settings → Display.', 'eventadmin-volunteer-management'); ?></dd>

            <dt><strong><?php echo esc_html__('What is the minimum volunteers field for?', 'eventadmin-volunteer-management'); ?></strong></dt>
            <dd><?php echo esc_html__('You can set a minimum number of volunteers per shift. The admin dashboard will highlight shifts that have not reached the minimum with an understaffing warning. This is informational only – no signup rules are enforced based on the minimum.', 'eventadmin-volunteer-management'); ?></dd>

            <dt><strong><?php echo esc_html__('Can visitors see where help is needed before registering?', 'eventadmin-volunteer-management'); ?></strong></dt>
            <dd><?php echo esc_html__('Yes. Put the [eventadmin_open_positions] shortcode on any public page. It lists every department that still has unfilled slots, needs no login, and by default shows a compact summary — add style="list" for the individual open shifts.', 'eventadmin-volunteer-management'); ?></dd>

            <dt><strong><?php echo esc_html__('Why can\'t a volunteer cancel their shift anymore?', 'eventadmin-volunteer-management'); ?></strong></dt>
            <dd><?php echo esc_html__('By default they can cancel anytime, right up to the start. Settings → General → "Sign out possible up to X hours before start" lets you require a minimum notice period instead (e.g. 24 hours) — after that point, the cancel option disappears for them.', 'eventadmin-volunteer-management'); ?></dd>

            <dt><strong><?php echo esc_html__('Can a volunteer sign up for two overlapping shifts?', 'eventadmin-volunteer-management'); ?></strong></dt>
            <dd><?php echo esc_html__('Not by default — Settings → General → "Allow overlapping shifts" blocks a volunteer from signing up for a shift that overlaps one they\'re already on. Check that box if overlapping shifts should be allowed instead.', 'eventadmin-volunteer-management'); ?></dd>

            <dt><strong><?php echo esc_html__('How do I limit how many shifts one person can take?', 'eventadmin-volunteer-management'); ?></strong></dt>
            <dd><?php echo esc_html__('Settings → General has four separate limits — per day, week, month, and year. Each is 0 (unlimited) by default; set any of them to cap how many shifts a single volunteer can sign up for in that period.', 'eventadmin-volunteer-management'); ?></dd>

            <dt><strong><?php echo esc_html__('How do I stop spam registrations?', 'eventadmin-volunteer-management'); ?></strong></dt>
            <dd><?php echo esc_html__('Bots occasionally submit fake volunteer registrations through the public form. Besides the built-in honeypot field (always on), Settings → General lets you add a CAPTCHA challenge — Google reCAPTCHA v2/v3, hCaptcha, or Cloudflare Turnstile. Turnstile specifically requires the free "Simple CAPTCHA with Cloudflare Turnstile" plugin to also be installed and configured — until it is, registration is blocked for everyone, so double-check that plugin\'s own settings after switching to it.', 'eventadmin-volunteer-management'); ?></dd>

            <dt><strong><?php echo esc_html__('Why isn\'t there a password field when volunteers register or log in?', 'eventadmin-volunteer-management'); ?></strong></dt>
            <dd><?php echo esc_html__('Volunteers don\'t set a password — the registration form and the "resend login" option both email a one-time login link (valid ~24 hours) instead. It\'s simpler for occasional volunteers, and it\'s what powers the "Unverified" badge (see Volunteer List & Badges) for accounts that registered but haven\'t clicked their link yet.', 'eventadmin-volunteer-management'); ?></dd>

            <dt><strong><?php echo esc_html__('What\'s the difference between assigning a volunteer to a shift and linking them to a department?', 'eventadmin-volunteer-management'); ?></strong></dt>
            <dd><?php echo esc_html__('Assigning them to a shift is the actual roster — it\'s what they signed up for. Linking them to a department (Volunteers page → Edit) is independent of that: it only controls who receives department-targeted announcements (Send Announcement → "Volunteers linked to a department"), which is useful for reaching people before any shift in that department even exists yet.', 'eventadmin-volunteer-management'); ?></dd>

            <dt><strong><?php echo esc_html__('Does deleting or deactivating the plugin delete volunteer data?', 'eventadmin-volunteer-management'); ?></strong></dt>
            <dd><?php echo esc_html__('Deactivating only stops the scheduled reminder and cleanup jobs. Uninstalling does not delete anything either — volunteer accounts, shifts, settings, and the "Volunteer" role stay in the database, so nothing is lost if you reinstall. Remove them by hand (or with a cleanup plugin) if you want a clean slate.', 'eventadmin-volunteer-management'); ?></dd>

            <dt><strong><?php echo esc_html__('Can I reuse last year\'s shifts for a new event?', 'eventadmin-volunteer-management'); ?></strong></dt>
            <dd><?php echo esc_html__('Yes — on the Manager page\'s Timeline tab, use "Copy shifts to another day" (in the + Add shift dropdown) to recreate every shift from a past day onto a new date, keeping each shift\'s time-of-day and duration. There\'s an option to also copy over the volunteers who were signed up, so a whole day\'s roster can be cloned forward to next year in one go.', 'eventadmin-volunteer-management'); ?></dd>
        </dl>

        <hr style="margin:32px 0;">

        <h2>❤️ <?php echo esc_html__('Support EventAdmin', 'eventadmin-volunteer-management'); ?></h2>
        <p><?php echo esc_html__('EventAdmin is free and open source. If it saves you time, please consider leaving a review or making a small donation — it helps a lot!', 'eventadmin-volunteer-management'); ?></p>
        <p>
            <a href="<?php echo esc_url(EVENTADMIN_REVIEW_URL); ?>" target="_blank" class="button button-primary" style="margin-right:8px;">⭐ <?php echo esc_html__('Rate 5 stars on WordPress.org', 'eventadmin-volunteer-management'); ?></a>
            <a href="<?php echo esc_url(EVENTADMIN_DONATE_URL); ?>" target="_blank" class="button">❤️ <?php echo esc_html__('Donate via Revolut', 'eventadmin-volunteer-management'); ?></a>
        </p>
    </div>
    <?php
}

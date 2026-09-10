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
        'manage_options',
        'eventadmin-documentation',
        'eventadmin_plugin_documentation_page'
    );
}
add_action('admin_menu', 'eventadmin_documentation_admin_menu', 200);

function eventadmin_plugin_documentation_page()
{
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('📘 EventAdmin Plugin – Documentation', 'eventadmin-volunteer-management'); ?></h1>

        <p>
            <?php echo esc_html__('Please create at least one page with the shortcode', 'eventadmin-volunteer-management'); ?>
            <code>[eventadmin]</code>
            <?php echo esc_html__('to use the plugin. You can also access the other shortcodes:', 'eventadmin-volunteer-management'); ?>
        </p>

        <h2><?php echo esc_html__('Other Shortcodes', 'eventadmin-volunteer-management'); ?></h2>
        <ul>
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

        <h2><?php echo esc_html__('Manage Volunteers for Events', 'eventadmin-volunteer-management'); ?></h2>
        <p><?php echo esc_html__('Shift planning, self-registration, limits, CSV export, statistics, and dashboard.', 'eventadmin-volunteer-management'); ?></p>

        <h3><?php echo esc_html__('Description', 'eventadmin-volunteer-management'); ?></h3>
        <p>
            <?php echo esc_html__('EventAdmin is a simple yet powerful plugin for managing volunteers at events.', 'eventadmin-volunteer-management'); ?>
            <?php echo esc_html__('Ideal for clubs, street festivals, and events. Organizers can create shifts, assign participants, or let volunteers sign up themselves. CSV export, limits, visual statistics, and an admin dashboard are included.', 'eventadmin-volunteer-management'); ?>
        </p>

        <h4><?php echo esc_html__('Features', 'eventadmin-volunteer-management'); ?></h4>
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

        <h3><?php echo esc_html__('Installation', 'eventadmin-volunteer-management'); ?></h3>
        <ol>
            <li><?php echo esc_html__('Install the plugin via the WordPress backend or upload the ZIP', 'eventadmin-volunteer-management'); ?></li>
            <li><?php echo esc_html__('Activate the plugin', 'eventadmin-volunteer-management'); ?></li>
            <li><?php echo esc_html__('Create a page and insert the', 'eventadmin-volunteer-management'); ?> <code>[eventadmin]</code> <?php echo esc_html__('shortcode — this is the main volunteer page (registration for new visitors, shift selector and profile for logged-in volunteers)', 'eventadmin-volunteer-management'); ?></li>
            <li><?php echo esc_html__('Create shift categories via the "Departments" button on the Manager page', 'eventadmin-volunteer-management'); ?></li>
            <li><?php echo esc_html__('Create your first shifts under Shifts → Add New', 'eventadmin-volunteer-management'); ?></li>
        </ol>

        <h3><?php echo esc_html__('Departments', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('Departments (Manager → Departments) group shifts, can be nested under a parent, and each carries a color used for its badge on shift cards and in the overview. "Hide from volunteers" removes a department from the frontend filter and labels; a shift disappears for volunteers only when every department it belongs to is hidden (shifts someone already signed up for are never hidden). Color, parent, visibility, and description can also be changed via Quick Edit in the department list, and the shift list itself can be filtered and sorted by department.', 'eventadmin-volunteer-management'); ?></p>

        <h3><?php echo esc_html__('Shift Overview', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('The shift overview (Shifts → Overview) is split into four tabs, each showing only the filters relevant to it:', 'eventadmin-volunteer-management'); ?></p>
        <ul>
            <li><strong><?php echo esc_html__('Dashboard', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('registered volunteers, upcoming shifts, and open spots split into required (below a shift\'s minimum) and optional (up to the maximum).', 'eventadmin-volunteer-management'); ?></li>
            <li><strong><?php echo esc_html__('Cards', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('the shift cards, with filters for upcoming/past/all, department, volunteer, and date.', 'eventadmin-volunteer-management'); ?></li>
            <li><strong><?php echo esc_html__('Table', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('a flat, export-style table; the "Add volunteer" button assigns someone without leaving the page.', 'eventadmin-volunteer-management'); ?></li>
            <li><strong><?php echo esc_html__('Timeline', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('a per-volunteer Gantt chart. Drag a shift\'s bar to move or resize it (with an Undo after saving), or use the Edit Shift modal for exact times. Unfilled slots show as red (below minimum) or grey (optional) bars, which can be toggled off.', 'eventadmin-volunteer-management'); ?></li>
        </ul>

        <h3><?php echo esc_html__('Volunteer List & Badges', 'eventadmin-volunteer-management'); ?></h3>
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

        <h3><?php echo esc_html__('Send Announcement', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('The Send Announcement page (Shifts → Send Announcement) lets you send a custom email to volunteers. Available recipient options:', 'eventadmin-volunteer-management'); ?></p>
        <ul>
            <li><strong><?php echo esc_html__('Subscribed only', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('Volunteers who opted in to announcements (default).', 'eventadmin-volunteer-management'); ?></li>
            <li><strong><?php echo esc_html__('All volunteers', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('Every volunteer with an email address, regardless of opt-in status.', 'eventadmin-volunteer-management'); ?></li>
            <li><strong><?php echo esc_html__('Volunteers without any upcoming shift', 'eventadmin-volunteer-management'); ?></strong> / <strong><?php echo esc_html__('Volunteers with at least one upcoming shift', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('Split volunteers by whether they currently have an upcoming shift assigned — useful for nudging people who haven\'t signed up yet.', 'eventadmin-volunteer-management'); ?></li>
            <li><strong><?php echo esc_html__('Volunteers of a specific shift', 'eventadmin-volunteer-management'); ?></strong> / <strong><?php echo esc_html__('Volunteers of a specific category', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('Only the volunteers assigned to a selected shift or department. A live recipient count is shown once you pick one.', 'eventadmin-volunteer-management'); ?></li>
            <li><strong><?php echo esc_html__('Individual volunteer', 'eventadmin-volunteer-management'); ?></strong> – <?php echo esc_html__('Click "Email" on any volunteer row to open the form pre-filled for that person.', 'eventadmin-volunteer-management'); ?></li>
        </ul>
        <p><?php echo esc_html__('The From name and From email can be overridden per send. Use {first_name} and {last_name} as personalisation placeholders, and {shifts} to list each recipient\'s own upcoming shifts. HTML formatting is supported — the live preview below the form renders the email as it will appear.', 'eventadmin-volunteer-management'); ?></p>
        <p><?php echo esc_html__('A PDF can optionally be attached — it will be sent with every email in that announcement.', 'eventadmin-volunteer-management'); ?></p>
        <p><?php echo esc_html__('Every sent announcement is recorded in the collapsible Send History table on the same page. The table can be filtered by typing and sorted by clicking column headers.', 'eventadmin-volunteer-management'); ?></p>
        <p><?php echo esc_html__('Offline volunteers (no email address) are excluded from all announcement sending. Volunteers can opt out under their profile.', 'eventadmin-volunteer-management'); ?></p>

        <h3><?php echo esc_html__('Reminder & Notification Emails', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('Volunteers are emailed when they are assigned to or removed from a shift, and again as a reminder a configurable number of days before it starts (Settings → Communication → Reminders — leave the days field empty to switch reminders off). All three messages use a rich-text editor and share placeholders such as {first}, {last}, {title}, {start}, {end}, {desc}, {department}, {department_desc}, and {days}. Each shift can override the sender through a linked WordPress user and/or a manual organizer name and email.', 'eventadmin-volunteer-management'); ?></p>

        <h3><?php echo esc_html__('E-mail Design', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('Settings → Communication → E-mail design styles the shared wrapper around every plugin email: a header color, a header title and subtitle shown next to the site logo, a rich-text footer, and — under advanced — custom CSS applied to all emails. The filters below are for changes that go beyond these options.', 'eventadmin-volunteer-management'); ?></p>

        <h3><?php echo esc_html__('Email Template Customization', 'eventadmin-volunteer-management'); ?></h3>
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

        <h3><?php echo esc_html__('Automatic Cleanup of Unverified Accounts', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('A daily background job automatically deletes volunteer accounts that were registered via the public form but whose magic login link expired without ever being clicked. Accounts are only deleted if they have no shift assignments and are not marked as manually added.', 'eventadmin-volunteer-management'); ?></p>
        <p><?php echo esc_html__('A log of automatically deleted accounts (up to the last 100) is shown at the bottom of the Volunteers page.', 'eventadmin-volunteer-management'); ?></p>

        <h3><?php echo esc_html__('Public "Open Positions" Overview', 'eventadmin-volunteer-management'); ?></h3>
        <p><?php echo esc_html__('The [eventadmin_open_positions] shortcode shows visitors who are not logged in where volunteers are still needed, grouped by department. Only departments with unfilled slots in upcoming shifts are listed. Put it on a public recruitment page, next to the [eventadmin_register] form.', 'eventadmin-volunteer-management'); ?></p>
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

        <h3><?php echo esc_html__('Frequently Asked Questions (FAQ)', 'eventadmin-volunteer-management'); ?></h3>
        <dl>
            <dt><strong><?php echo esc_html__('Do volunteers need an account?', 'eventadmin-volunteer-management'); ?></strong></dt>
            <dd><?php echo esc_html__('Yes, they must be logged in to view and join shifts.', 'eventadmin-volunteer-management'); ?></dd>

            <dt><strong><?php echo esc_html__('How can I assign volunteers manually?', 'eventadmin-volunteer-management'); ?></strong></dt>
            <dd><?php echo esc_html__('In the admin dashboard under “Volunteer Overview” for each shift via form.', 'eventadmin-volunteer-management'); ?></dd>

            <dt><strong><?php echo esc_html__('What happens when shifts are full?', 'eventadmin-volunteer-management'); ?></strong></dt>
            <dd><?php echo esc_html__('By default, full shifts are hidden on the volunteer page. You can optionally show them in a separate read-only section by enabling "Show full shifts to volunteers" under Settings.', 'eventadmin-volunteer-management'); ?></dd>

            <dt><strong><?php echo esc_html__('What is the minimum volunteers field for?', 'eventadmin-volunteer-management'); ?></strong></dt>
            <dd><?php echo esc_html__('You can set a minimum number of volunteers per shift. The admin dashboard will highlight shifts that have not reached the minimum with an understaffing warning. This is informational only – no signup rules are enforced based on the minimum.', 'eventadmin-volunteer-management'); ?></dd>

            <dt><strong><?php echo esc_html__('Can visitors see where help is needed before registering?', 'eventadmin-volunteer-management'); ?></strong></dt>
            <dd><?php echo esc_html__('Yes. Put the [eventadmin_open_positions] shortcode on any public page. It lists every department that still has unfilled slots, needs no login, and by default shows a compact summary — add style="list" for the individual open shifts.', 'eventadmin-volunteer-management'); ?></dd>
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

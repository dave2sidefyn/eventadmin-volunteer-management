=== EventAdmin – Volunteer Management ===
Contributors: davesidefyn
Tags: volunteer, volunteer management, shift scheduling, event management, roster
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 3.10.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Donate link: https://revolut.me/davidwiedmer

A self-service volunteer roster for events: create shifts, let volunteers sign up themselves, set limits, export CSV, and track it on a dashboard.

== Description ==

EventAdmin turns a WordPress page into a self-service volunteer roster for an event. You create the shifts; volunteers register and sign themselves up; you keep the overview.

**How it works:**

* **Set up shifts.** Each shift has a time slot, a department, and a minimum/maximum number of volunteers. Departments can be nested and colour-coded.
* **One page for volunteers.** Put the `[eventadmin]` shortcode on a page. Visitors who are not logged in see a short registration form. Once registered, the same page becomes their dashboard: open shifts to join, the shifts they have taken, and their profile.
* **Volunteers manage themselves.** They sign up and cancel on their own, up to an optional deadline. Limits you set — shifts per day, week, month or year, and optional overlap prevention — are enforced automatically.
* **You stay in control.** The admin overview shows who signed up and which shifts are understaffed, as a Dashboard, Cards, a Table, or a drag-to-reschedule Timeline. Assign or create volunteers by hand, import or export CSV, and send bulk emails to chase the gaps.
* **Delegate without giving out Administrator.** Two extra roles — Shift Manager and Volunteer Manager — hand off day-to-day work (shifts, volunteers, announcements) to other organizers without full admin access.
* **Reach people by department.** Link a volunteer to one or more departments to target them with announcements — even before a single shift in that department exists yet.
* **Recruit in public.** An optional `[eventadmin_open_positions]` list shows visitors where help is still needed before they register — no login required.

Built for clubs, street festivals, and community events. Everything runs in your own WordPress install — no page builder and no external service required. Emails are sent through WordPress; social login works via the free Nextend Social Login plugin. New installs get a step-by-step "Getting started" checklist right on the dashboard.

**Features:**

* Create shifts with a time period, a department, and a minimum/maximum number of volunteers
* Nested, colour-coded departments; a department (and its shifts) can be hidden from volunteers
* Public frontend on one page: volunteers register, sign up for shifts, and manage their profile
* Volunteers sign up and cancel themselves, with an optional cancellation deadline
* Configurable limits: maximum shifts per day, week, month, or year, plus optional overlap prevention
* Optional "Full shifts" section so volunteers can still see fully booked shifts (disabled by default)
* Public "open positions" overview (`[eventadmin_open_positions]`) — a no-login list of where volunteers are still needed, grouped by department, for a recruitment page
* Two delegated roles — Shift Manager (shifts, departments, volunteers) and Volunteer Manager (volunteers, announcements) — for handing off work without full Administrator access
* Link volunteers to one or more departments, independent of shift history, to target them with department-specific announcements
* CSV import for volunteers (name, email, phone) and for shifts (title, description, time, department, capacity, organizer) under Tools → EventAdmin Data
* Manual creation and assignment of volunteers by admins — pick an existing volunteer from a dropdown or create a new account on the fly, including offline volunteers without an email address
* Per-shift organizer — override the notification sender for a single shift with a linked WordPress user and/or a manual name and email
* Assignment, cancellation, and reminder emails (reminders a configurable number of days before a shift), plus a separate, fully customizable notification to the shift's organizer on every sign-up and cancellation
* Registration form protected by a honeypot plus optional CAPTCHA (Google reCAPTCHA v2/v3, hCaptcha, or Cloudflare Turnstile); blocked attempts are logged
* CSV export per shift or for all shifts
* Admin overview with Dashboard, Cards, Table, and Timeline views — drag shifts on the Timeline to reschedule them, or copy a whole day's shifts (and optionally its volunteers) to a new date
* Dashboard statistics: registered volunteers, upcoming shifts, and open spots split into required (below the minimum) vs. optional openings
* A "Getting started" checklist on the dashboard for new installs — tracks initial setup and surfaces less obvious features, then disappears once everything's done
* Bulk email tool: announcements to all, opted-in, department-, shift-, or upcoming-shift-based recipient groups — batched, with a progress bar, delivery-failure tracking, a live HTML preview, an optional PDF attachment, and a confirmation email to the sender
* Customisable email design: rich-text templates, header colour, header title/subtitle, footer, and custom CSS
* Volunteers can opt out of announcements on their profile page
* Send history log with subject, message preview, recipient count, and failure count
* Integration with Nextend Social Login
* Fully translatable; ships with German (informal & formal), French, Dutch, and Norwegian translations

== Installation ==

1. Install the plugin via the WordPress backend or upload the ZIP file
2. Activate the plugin
3. Go to Pages → Add New and insert the `[eventadmin]` shortcode — this is the main volunteer page (registration for new visitors, shift selector + profile for logged-in volunteers)
4. Create your departments under Shifts → Categories (optionally give each a colour)
5. Create your first shifts under Shifts → Add New
6. Optional: follow the "Getting started" checklist on Shifts → Overview — it tracks these same steps and points out a few more things worth trying
7. Optional: create separate pages for `[eventadmin_profile]` or `[eventadmin_shiftselector]` if you want dedicated pages for those features
8. Optional: add `[eventadmin_open_positions]` to a public recruitment page to show non-registered visitors where volunteers are still needed
9. Optional: assign the Shift Manager or Volunteer Manager role (Users → All Users) to delegate work without giving out full Administrator access

== Frequently Asked Questions ==

= Do volunteers need an account? =
Yes — they need to be logged in to join or cancel a shift, but there is no password to remember. On the registration form they enter their name, email, and phone; they then get a magic login link by email that signs them in. Admins can also create accounts manually and email them a login link as an invitation, including "offline" volunteers with no email address (added by hand, no login).

= Which pages do I need to create? =
Just one: a page with the `[eventadmin]` shortcode. It shows the registration form to logged-out visitors and the full dashboard (open shifts, my shifts, profile) to logged-in volunteers. `[eventadmin_open_positions]`, `[eventadmin_shiftselector]`, and `[eventadmin_profile]` are optional extras for dedicated pages.

= How can I assign volunteers manually? =
Open Shifts → Overview. In the Table view use the "Add volunteer" button on a shift, or click an open slot in the Timeline view. You can pick an existing volunteer or create a new account (with or without an email address) on the spot.

= What happens when shifts are full? =
By default, full shifts disappear from the volunteer page. You can optionally enable a "Full shifts" section under Settings → Display so volunteers still see them, read-only with a disabled button.

= What is the minimum volunteers field for? =
It marks how many volunteers a shift really needs. The dashboard flags shifts below the minimum and splits their open spots into "required" (up to the minimum) and "optional" (up to the maximum). It is informational — nothing is blocked based on the minimum.

= Can I set how many shifts one person may take? =
Yes. Under Settings → General you can cap the number of shifts per volunteer per day, week, month, and year (0 = no limit), and optionally forbid overlapping shifts. These rules are enforced when a volunteer tries to sign up.

= Is the plugin translated? =
It ships with German (informal and formal), Swiss German, Austrian German, French (FR and BE), and Dutch (informal and formal). All strings are translatable, and the bundled translations take priority over community language packs.

= What happens to my data if I deactivate or uninstall the plugin? =
Deactivating only stops the scheduled reminder and cleanup jobs. Uninstalling does **not** delete anything — volunteer accounts, shifts, settings, and the "Volunteer" role stay in the database, so nothing is lost if you reinstall. Remove them by hand (or with a cleanup plugin) if you want a clean slate.

= Can visitors see where help is needed before registering? =
Yes. Put the `[eventadmin_open_positions]` shortcode on any public page (for example a "become a helper" page). It lists each department that still has unfilled slots in upcoming shifts, with the number of open spots, and needs no login. By default it shows a compact summary (one line per department); add `style="list"` for the full list of individual open shifts. Further attributes: `category="slug,slug"` limits it to certain departments, `show_intro="0"` hides the intro line, `hide_past="0"` includes shifts that already ended.

== External services ==

The plugin does not send your data anywhere on its own. Two optional features rely on third-party services, and only when you switch them on:

* **Spam protection on the registration form.** If you enable a CAPTCHA under Settings → General → Security, the chosen provider's script is loaded on the registration page and the visitor's CAPTCHA response and IP address are sent to that provider for verification:
  * Google reCAPTCHA — [Privacy Policy](https://policies.google.com/privacy), [Terms](https://policies.google.com/terms)
  * hCaptcha — [Privacy Policy](https://www.hcaptcha.com/privacy), [Terms](https://www.hcaptcha.com/terms)
  * Cloudflare Turnstile — [Privacy Policy](https://www.cloudflare.com/privacypolicy/), [Terms](https://www.cloudflare.com/website-terms/)
* **Social login.** If the separate Nextend Social Login plugin is installed and configured, volunteers can sign in through the provider you set up there (e.g. Google, Facebook). That exchange is handled by Nextend Social Login and the provider, under their terms.

With none of these enabled, the plugin makes no external requests.

== Privacy ==

EventAdmin stores volunteer data in your own WordPress database: name, email address, and phone number on the user account, plus which shifts each person signed up for. Passwordless login uses a short-lived token in user meta. When CAPTCHA spam protection is enabled, blocked or suspicious registration attempts are logged with a timestamp, the submitted email, and the IP address.

The plugin does not yet integrate with WordPress's personal-data export and erase tools, and it does not remove its data on uninstall (see the FAQ). To delete a volunteer's data, delete their WordPress user — their shift assignments are removed automatically.

== Screenshots ==

1. Timeline view — every shift across the event on one chart, colour-coded by department; drag a shift to reschedule it
2. The volunteer view — browse open shifts by department and sign up in one click
3. Click a volunteer on the Timeline for quick actions — edit the shift, view their profile, move or remove them
4. Edit shift — title, department, time, min/max volunteers, and a rich-text description
5. Add a volunteer on the spot — pick an existing one or create a new account without leaving the shift
6. Settings — registration options and shift-limit rules (per day/week/month/year, overlap prevention)
7. Email design — shared placeholders and a live preview of exactly what volunteers receive
8. Send Announcement — target a recipient group and see a live preview of the email
9. Registration page — one-click sign-in via Google, Facebook, or X through the free Nextend Social Login plugin, or a password-free magic email link for everyone else

== Changelog ==

= Version 3.10.0 =
* New: Flexible repeats in "Copy shifts to another day" — every N weeks or months, ending on a date or after a number of times (up to 52 dates). Monthly repeats keep the day of the month and skip months without it
* New: Multi-day shifts appear on every day they cover in the Calendar, the date picker and the Day, Table and Cards views
* New: Add or remove volunteers directly in the shift window. Removing asks for confirmation, and emailing the volunteer is an explicit, optional choice
* New: "Full" staffing status, and the Volunteer and Status filters on every Manager tab, including Day. Filters stay applied while you edit on the Day view
* Improved: Searchable volunteer picker (name or email) with date, time and capacity shown when adding a volunteer; creating a new volunteer there is tucked into its own section with labelled fields
* Improved: Calendar shows each day's shifts below the minimum and open slots, lists understaffed shifts first, and "+N more" expands in place
* Improved: The selected date carries over when switching between Calendar, Day, Table and Cards, and a day without shifts is still shown by its date. The date and filter bar stays visible while scrolling. "Cards (old)" is now "Cards"
* Improved: Clickable email and phone links in the Table view and volunteer profiles, plus a "Send Announcement" shortcut per volunteer
* Improved: Compact Volunteers list with a "More details" toggle; registration diagnostics moved under "Registration activity"
* Improved: Volunteer page — clearer sign-up buttons ("Sign up for this shift" / "Cancel my sign-up"), "places left" instead of "x/y slots open" (also in [eventadmin_open_positions]), shifts grouped by day, "Find a shift" when you have none yet, "No shifts found" with "Clear filters", and no duplicate department badge when it matches the shift title
* Improved: Volunteer page — the Profile tab says "Phone number missing" instead of showing only a warning icon, sign-up buttons can't be pressed twice and network errors are shown, and the department setting in the profile explains what it does
* Improved: Registration form explains the email login link and why a phone number is needed, and supports browser autofill
* Improved: Keyboard and screen-reader support for tabs, dialogs and sortable columns
* Removed: Active-filter chips (the dropdowns already show the selection) and the "Open full editor" link in the shift window
* Fix: Turning off "Open slots" on the Day view is now kept when switching tabs
* Fix: PHP 8.4+ deprecation notice from the CSV export

= Version 3.9.0 =
* New: Calendar tab on the Manager page — a month or week grid with every shift in its department's colour, its start time and how many volunteers it has (red while below the minimum). Click a shift to edit it, or a day to open it on the Day tab. Opens automatically when your shifts span more than two weeks
* New: Date picker on the Manager page — Upcoming / Past / All dates or a single day, with a month view whose days are marked by staffing. Long-running setups step day by day and open on the next day with shifts
* New: Weekly repeating shifts — "Copy shifts to another day" can repeat a day's shifts every week until an end date (up to 52 weeks); running it again skips shifts that already exist
* New: "Shifts per department" (Settings → General, off by default) — volunteers only see and can sign up for shifts in the departments you allow them under Volunteers → Edit departments, kept separate from the departments they get notified about
* New: department="slug" option for [eventadmin], [eventadmin_cockpit] and [eventadmin_shiftselector], for one volunteer page per department
* New: Switching between "I'm new" and "I already have an account" on the volunteer page no longer reloads the page
* Improved: One consistent filter toolbar on the Manager and Volunteers pages (Date → Department → Volunteer/Shift → Status), with removable filter chips, "Clear filters" and a compact filter toggle on phones. Page actions (Add shift, Export CSV, Departments) sit at the top right. The Timeline tab is now called "Day"
* Fix: Volunteer pages are now kept out of page caches and CDNs, which could show volunteers an outdated or empty "My shifts" after reopening the browser on a phone

= Version 3.8.0 =
* Fix: An Editor, Shift Manager or other non-admin who viewed the volunteer page and then logged in through wp-login.php within the hour lost their role and was turned into a volunteer. Roles are now only assigned in the volunteer flows (login link, social login from the volunteer page) and never removed
* New: Invite volunteers you add yourself: "Send login link by email" when creating a volunteer, and "Send login link" in every volunteer profile
* New: Optional email + password login for volunteers (Settings → General → "Password login", off by default). Volunteers can set a password after their first login or in their profile; the login link remains available for sign-up, invitations and forgotten passwords
* New: The volunteer page has "I'm new" / "I already have an account" views, so returning volunteers can get a login link with just their email address
* New: An expired or already-used login link now leads back to the volunteer page with a one-field form to request a new one, instead of an error page
* Fix: "Suppress WordPress password email" now also works for social login sign-ups, cancels the email properly, and its description explains what it affects
* Fix: Send Announcement now counts emails the mail server rejected as failed
* Fix: The "Register" button is translated, and the "Profile incomplete" warning on the profile page shows its text again

= Version 3.7.3 =
* Fix: The daily cleanup of unverified accounts could delete volunteers who had verified long ago — requesting a new login link (e.g. for another device) and not clicking it within 24 hours made them look unverified again. Accounts now keep a permanent "verified" status from their first login, and existing volunteers are marked as verified on update
* Fix: The cleanup could also delete an administrator or other account that had the volunteer role (e.g. an admin who registered with their own email). It now only ever deletes plain volunteer accounts
* Fix: The "Unverified" badge no longer reappears for verified volunteers who request a new login link

= Version 3.7.2 =
* Fix: The login link in registration emails is now a clickable link — some mail clients did not turn the plain URL into a link
* Fix: Login links no longer fail silently depending on who triggered the email or when they were opened. Clicking an already-used link while logged in now goes straight to the volunteer page instead of the home page
* Fix: Registering with the email address of an existing account (e.g. the site admin testing the form) and logging in via the link now grants that account the volunteer role, so it sees the shifts instead of the registration form again. Existing roles are kept
* Fix: The "Update User" button on a volunteer's Edit User screen did nothing, so admins could not change a volunteer's password or other WordPress profile fields there

= Version 3.7.1 =
* Fix: Login links (registration and "already registered" resend) and shift emails were silently not sent when the sender email under Settings → Communication had been saved empty — WordPress rejected them with "Invalid address: (From):". The plugin now falls back to the site's admin email (and the site name as sender name), also for the Send Announcement form

= Version 3.7.0 =
* New: Importing shifts from CSV now skips any row whose title and start date/time exactly match an existing shift, and lists those rows under "Skipped – already exists" — importing the same file twice no longer creates duplicates
* New: The "All Shifts" admin list now sorts by start time (soonest first) by default, instead of by creation date, so upcoming shifts are visible without first clicking the Start column
* Fix: Volunteers no longer see shifts that are already over in the shift selector (neither to sign up for nor under their own shifts), and can no longer sign up for a shift that has already started

= Version 3.6.0 =
* New: The EventAdmin MCP tools can now see who is assigned to a shift, and a new "notify_shift" tool can email a shift's roster directly
* New: "list_departments", "get_settings", and "get_documentation" MCP tools/endpoints — resolve a department name to its ID, check the active sign-up rules (limits, overlap, cancellation deadline), and answer "how does this work" questions from the real Documentation page instead of guessing
* Fix: The "Connect an MCP client" screen's "Copy to clipboard" button silently did nothing on non-HTTPS sites (the most common case for local development) — added the same fallback the Volunteers list's own copy buttons already use
* Fix: Importing volunteers or shifts from a CSV file could silently blank out names containing accented or Nordic letters (é, ø, å, æ, ü, and similar) when the file was saved as Windows-1252 (Excel's default "CSV" export on Windows) instead of UTF-8

= Version 3.5.0 =
* New: The Manager view's shift popover gained a "Duplicate" action — pre-fills the Add Shift form with the same time, department, capacity and organizer, so you only need to adjust the date
* New: An optional REST API (Settings → General → API access → "Allow API access") exposing shifts and dashboard data at /wp-json/eventadmin/v1/, for use with MCP or other external integrations — off by default, and limited to Administrator and Shift Manager accounts
* New: A "Connect an MCP client" button that uses WordPress's own "Authorize Application" screen to set up an AI assistant connection, with a ready-to-paste configuration shown once — no manual password copying needed
* New: A "Connecting an AI Assistant (MCP)" section on the Documentation page explaining what this is and how to set it up, for anyone unfamiliar with MCP

= Version 3.4.0 =
* New: A "EventAdmin – Volunteer Management" widget on wp-admin's own Dashboard (Dashboard → Home, not just the plugin's own Overview page) shows the KPI numbers that need attention, the next few upcoming shifts (flagging any still short on volunteers), and the same "Recent activity" feed — visible right after login
* New: Export the Volunteers list to CSV
* New: A copy-to-clipboard button next to each volunteer's e-mail and phone number in the Volunteers list, now that both are truncated to keep rows from growing tall when a volunteer is linked to several departments
* New: The plugin's own logo now appears as the "Shifts" admin menu icon and in the update notice, instead of a generic calendar icon
* Fix: Filtering the Volunteers list by department now also includes volunteers explicitly linked to that department (via "Edit" on their Departments badges), not just those with a shift assigned to it
* Fix: Department links can now also be edited from the "View profile" popup, not only from the Volunteers list's Departments column
* Fix: The Volunteers list's Email and Remove-role actions are now one compact "Actions" column instead of two, and a volunteer linked to several departments no longer stretches their whole row
* Fix: The "Recent activity" panel (Overview dashboard and the new widget) shows 5 entries by default with a "Show more" toggle, instead of always listing up to 20
* Fix: Plugin assets (CSS/JS) now bust the browser cache automatically whenever they change, instead of a fixed version number that could leave an old cached copy in place after an update

= Version 3.3.0 =
* New: The Overview dashboard has a "Recent activity" panel showing the most recent shift sign-ups, cancellations, and admin actions across every volunteer — separate from each volunteer's own email-notification history, so it also covers offline volunteers and actions sent without a notification
* New: Clicking a shift anywhere in the admin area (a volunteer's Upcoming/Past shifts, the new activity feed) opens the same shift modal used in the Manager view — showing who else is on it, with each name linking to that volunteer's own profile, and back again
* New: The Volunteers admin profile (and its "View profile" popup) shows an at-a-glance summary — badges, phone, registration date, departments, and announcement subscription — and lets you edit the phone number, toggle the announcement subscription, or remove the volunteer role right there
* New: Phone numbers throughout the admin area are click-to-call links
* New: "Documentation" now also appears as a link on the plugin's row on the Plugins screen, next to "Settings"
* Fix: Volunteer badges (Offline/Unverified/Social/Manual) now show their tooltip instantly instead of relying on the browser's native, slow and inconsistent title-attribute tooltip

= Version 3.2.0 =
* New: Two new roles — "Shift Manager" (shift/department CRUD, manage volunteers) and "Volunteer Manager" (manage volunteers, send announcements) — for delegating day-to-day work without handing out full Administrator access. See the in-plugin Documentation page for exactly what each role can do
* New: Volunteers can be linked to one or more departments — from the Volunteers page ("Edit" next to their department badges) or by the volunteer themselves on their own profile page — independent of any shift they've actually signed up for
* New: Send Announcement gained a "Volunteers linked to a department" recipient option, so you can notify just that department (e.g. "new shifts are up") without emailing everyone
* New: Tools → EventAdmin Data → "Import shifts" — bulk-create shifts from a CSV file (columns for title, details, start/end date and time, department, min/max volunteers, and organizer user/name/email)
* New: A "Getting started" checklist appears on the Overview dashboard for sites still finishing setup — walks through the essentials and points out less obvious features (drag-to-reschedule, email template customization, delegating roles, and more); disappears once everything's done, or can be hidden manually
* New: A "Settings" link now appears directly on the plugin's row on the Plugins screen
* Fix: Hiding "All Shifts"/"Add Shift"/"Departments" from the admin menu (Settings → General) now uses CSS instead of removing the items from WordPress's own menu structure — removing them could incorrectly deny the new Shift Manager role access to "Add Shift"
* Fix: Editing a volunteer's department links no longer requires the broad `edit_users` capability — the control moved from the native user-edit screen onto the Volunteers list itself, so the Volunteer Manager role can use it

= Version 3.1.1 =
* Fix: CSV export (per shift and for all shifts) now includes a UTF-8 byte-order mark, so Excel correctly displays accented and non-Latin characters (e.g. ø, å, é) instead of garbled text

= Version 3.1.0 =
* New: Departments can have a description that assignment, cancellation, and reminder emails pull in automatically — use the {department} and {department_desc} placeholders in the email templates (Settings → Communication) instead of writing a description on every shift
* New: Tools → EventAdmin Data → "Import volunteers" — bulk-create volunteer accounts from a CSV file (columns first_name, last_name, email, phone; comma or semicolon delimiter). Only first_name is required; a row with no email address creates an offline volunteer; a row whose email already belongs to a user is reported and skipped. No notification emails are sent

= Version 3.0.0 =
* New: Shift overview split into two separate admin pages — "Overview" (Dashboard stats only) and a new "Manager" page (with the drag-and-drop timeline, Table, and Cards tabs), each with its own sidebar menu item
* New: Settings → General → "Admin menu" section can hide the classic "All Shifts", "Add Shift", and "Departments" menu items from the sidebar — all three are hidden by default on new installs, since Manager's own buttons cover the same tasks
* New: "Departments" management moved from its own sidebar item into the Manager page's "+ Add shift" dropdown, next to "Copy shifts to another day"
* New: CSV export for all shifts moved into the Table tab
* New: On narrow screens, the filter row collapses behind a "Show filter" / "Hide filter" toggle instead of showing every dropdown at once
* New: Advanced, collapsible "Organizer" section (linked user, name, email) in the Timeline's Add/Edit Shift modal — previously only available on the classic shift edit screen
* New: Hidden departments are now marked "(hidden from volunteers)" in every admin category dropdown
* New: Deleting a shift, or removing a volunteer from one, now offers a "Notify affected volunteer(s)" option at confirmation time instead of a separate always-visible checkbox
* Improvement: Timeline tab renamed to "Manager"; tab order is now Manager, Table, Cards (old)
* Improvement: Timeline's click-to-act popover now shows the shift's date and time directly in the header
* Fix: New, edited, or deleted shifts, and volunteer assignments, could take up to 5 minutes to appear due to a caching bug
* Fix: The plugin's admin footer credit line no longer eats horizontal space on mobile
* Fix: Historical bulk-announcement sends are now reflected in each volunteer's notification log (applied automatically on update), excluding volunteers with no email address on file

For older versions, see changelog.txt in the plugin folder.

== Upgrade Notice ==

= 3.10.0 =
Adds repeats every N weeks or months, multi-day shifts on every day in the Calendar, Volunteer and Status filters on the Day view, and adding or removing volunteers right from the shift window. No breaking changes.

= 3.9.0 =
Adds a Calendar tab and date picker for long-running events, weekly repeating shifts, optional per-department sign-up and per-department volunteer pages, and keeps volunteer pages out of page caches. No breaking changes.

= 3.8.0 =
Important: fixes Editors and Shift Managers losing their role after looking at the volunteer page and then logging in. Also adds volunteer invitations by login link and an optional password login (off by default). No breaking changes.

= 3.7.3 =
Important: fixes the daily cleanup deleting verified volunteers (and admins with the volunteer role) who had requested a new login link and not clicked it. Update as soon as possible. No breaking changes.

= 3.7.2 =
Login links in emails are clickable again and reliably lead to the shifts, also for existing accounts. Recommended for all sites. No breaking changes.

= 3.7.1 =
Fixes login links and shift emails not being sent when the sender email under Settings → Communication was left empty. Recommended for all sites. No breaking changes.

= 3.7.0 =
Re-importing the same shift CSV no longer creates duplicates, volunteers no longer see past shifts or sign up for ones already under way, and the All Shifts list sorts by start time by default. No breaking changes.

= 3.6.0 =
Adds a shift roster + email-the-roster MCP tool, department/settings/documentation lookup tools for MCP, and fixes a clipboard-copy bug on non-HTTPS sites plus a CSV import bug that silently dropped names with accented or Nordic letters. No breaking changes.

= 3.5.0 =
Adds an optional REST API for MCP/AI-assistant integrations (off by default), a one-click way to connect one via WordPress's own Application Passwords screen, and a "Duplicate" action for shifts in the Manager view. No breaking changes.

= 3.4.0 =
Adds a wp-admin Dashboard widget (open shifts, next shifts, recent activity), a Volunteers list CSV export, and fixes the Departments filter to also match volunteers linked to a department. No breaking changes.

= 3.3.0 =
Adds a "Recent activity" feed on the Overview dashboard, a unified shift modal with a clickable volunteer roster reachable from anywhere, and a richer, editable volunteer profile summary. No breaking changes.

= 3.2.0 =
Adds two new roles (Shift Manager, Volunteer Manager) for delegating work, volunteer-department linking with targeted announcements, CSV shift import, and a "Getting started" checklist. No breaking changes.

= 3.1.0 =
Adds department descriptions in emails (the {department_desc} placeholder) and a CSV "Import volunteers" tool under Tools → EventAdmin Data. No breaking changes.

= 2.1.1 =
Maintenance only — refreshed screenshots and description. Nothing to do.

= 2.1.0 =
Adds the [eventadmin_open_positions] shortcode for a public "where we still need volunteers" list. No breaking changes.

= 2.0.1 =
Housekeeping release — corrects the in-plugin "What's new" notice. Safe to update.

== License ==

This plugin is free software under the GPLv3 or later.

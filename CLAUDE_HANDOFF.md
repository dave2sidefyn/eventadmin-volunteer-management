# EventAdmin UX handoff — 13 November 2026

> **Status update:** this UX batch, the review fixes below and flexible repeats (every N weeks/months, ends on a date or after N times — `eventadmin_repeat_dates()`) were released as **3.10.0**. The "uncommitted" notes below describe the state before that release; the user decisions remain valid. Screenshots under `design/handoff/2026-11-13/screenshots/` were kept local only (not committed).

## Start here

The user asked to record the work so Claude can continue. This handoff describes the **current working tree**, including decisions that supersede earlier screenshots and parts of `CLAUDE.md`.

- Repository: `/Users/davidwiedmer/IdeaProjects/eventadmin-volunteer-management`
- Branch: `improvement/manager-volunteer-ux`
- HEAD: `3332a6a` (`Merge pull request #14 from dave2sidefyn/feature/calendar-view`), tagged `v3.9.0`.
- Plugin header and `EVENTADMIN_VERSION` are still **3.9.0**.
- All UX work described below is **uncommitted, unpushed, untagged and unreleased**. Preserve the working tree; do not reset it.
- Earlier in the conversation, the 3.9.0 release was completed (PR #14, tag, WordPress.org deployment reported successful, SVN revision 3795174). That is separate from this new UX work. Refresh remote release state before doing release work.
- No build system. PHP WordPress plugin with plain JS/CSS. Read `CLAUDE.md`, but use the latest user decisions below where its older descriptions conflict.

## User decisions — do not reintroduce rejected designs

1. **Keep the detailed Table view**, with one row per volunteer assignment and one row per open slot. An attempted grouped roster looked too similar to Cards and was reverted.
2. **Keep the original editable timeline on mobile.** An attempted special mobile agenda/list was reverted. Do not replace it with a separate mobile view.
3. **Respect configured date/time formats.** Users may have fine-tuned them. The attempted abbreviated card date/time display and forced year addition in the date navigator were reverted. Shift periods must use `eventadmin_get_formatted_zeitraum()` and its `eventadmin_shift_date_format` / `eventadmin_shift_time_format` settings.
4. **Keep the original release notice.** Item 21 in the review (smaller, collapsed notice and less-prominent rating button) was explicitly rejected and fully reverted. The main plugin entry file now has no UX diff for that notice.
5. **Remove active-filter chips.** They duplicate the dropdown selections. Keep dropdowns, mobile filter count and Clear filters. Do not confuse these removed chips with the date-navigation chips or calendar shift chips, which remain.
6. **Show Volunteer and Status filters in Day too.** Inherited filters must be visible and adjustable. Do not silently clear the volunteer when switching tabs. Selecting Anyone clears it.
7. **Add Full staffing status** to all manager views. Full means assigned count >= maximum, including over-capacity shifts.
8. **Open slots remains a Day-only view option.** It toggles empty timeline rows. Calendar has one chip per shift, capacity counts and daily open-slot summaries; it does not have separate empty-slot rows.
9. **Shift modal: add volunteer is progressive disclosure.** Initially show Add volunteer; clicking reveals the searchable selection, Send confirmation email checkbox, then Add to shift and Cancel. Successful addition collapses the controls again. Cancel discards selection/checkbox changes.
10. **Addition confirmation checkbox belongs above Add to shift.** Hide both addition controls and that checkbox when full. This checkbox only applies to the new assignment.
11. **Removal must ask for confirmation**, name the volunteer and identify the shift, with its own optional Notify volunteer checkbox (unchecked by default). Cancel/Escape must not remove anyone. Hide notification option for offline/non-emailable volunteers. Removing is again allowed to send an email when explicitly selected in this separate confirmation; the previous temporary decision to never notify on removal is superseded.
12. **Remove Open full editor** from the shift modal. Markup and JS references have been removed (some unused URL config remains; optional cleanup).
13. **Hide Volunteers on this shift while creating or duplicating an unsaved shift.** Show the section when editing an existing shift.
14. **Multi-day shifts must appear on all dates they actually occupy**, not just their start day. Include shifts starting before the visible week/month. Midnight end is exclusive: no chip on the following day if the shift ends exactly at its start.

## Current implementation inventory

### Manager filters, dates and calendar

- Sticky date/filter toolbar, below the WordPress admin bar; mobile top offset accounts for the bar scrolling away.
- Selected date wins over a stale calendar anchor when switching views; explicitly navigating calendar periods clears the day constraint via existing calendar URL building.
- Selecting a date with no shifts still labels it with the actual selected date instead of Upcoming.
- Query argument filtering preserves `show_open=0` (no longer dropped by generic `array_filter`).
- Volunteer and staffing Status dropdowns now appear on Day, Calendar, Table and Cards.
- Staffing options: Any status, Empty, Full, Understaffed, Heavily understaffed. Existing internal keys remain `empty`, `full`, `understaffed`, `heavilyunderstaffed`; Understaffed = below max, Heavily understaffed = below min.
- Active-filter chips and their unused styling/label lookups removed. Dropdowns, mobile count and Clear filters remain.
- Calendar daily summary: number below minimum and number of free places (for currently filtered shifts).
- Calendar shows below-minimum shifts first; the rest retain chronological ordering.
- Calendar +N more now expands inline with `<details>` instead of navigating away.
- Calendar shift touch targets enlarged on mobile.
- Cards (old) renamed Cards.

### Multi-day behavior

`includes/admin/dashboard-data.php` now has:

- `eventadmin_shift_overlap_meta_query($from, $to)`: inclusive calendar-day range, exclusive next-day boundary; includes shifts that started earlier but end after the range start. Preserves start-only shifts on their start day. Named `sort_clause` still supports sorting.
- `eventadmin_shift_occupied_days($start, $end, $from, $to)`: clips occupied dates to the range; subtracts one second from a positive-duration end to exclude midnight's next day. Advances calendar dates using `DateTimeImmutable` in UTC to treat stored site-local wall clocks consistently across DST.
- `eventadmin_get_shifts()` uses the overlap query for a specific selected date, so Day/Table/Cards find continuation shifts too.
- The separate copy-shift function `eventadmin_get_shifts_starting_on_day()` is intentionally unchanged: copying still copies shifts that start that day.

`includes/admin/dashboard-tab-calendar.php` queries overlapping shifts and repeats the same shift ID on every occupied day. The first day shows the existing configured start-time format; continuation days show `↳` instead of falsely repeating the original start time. Tooltips append the configured full period. Clicking any repeated chip edits the same shift.

`includes/admin/date-navigator.php` includes shift end metadata in the aggregate query and expands staffing/date statistics across occupied days. Continuation dates therefore exist in navigation and are considered when choosing the calendar/default date.

### Assignment and shift details modals

- Shared add-volunteer modal (Table/open slots): date/time and capacity context; a single dropdown with integrated search by name/email; already-assigned volunteers excluded.
- New-volunteer creation in that modal is collapsed in details; fields have real labels and email/tel input types. Primary action wording is Add to shift.
- `assets/js/searchable-select.js` and `assets/css/searchable-select.css` provide dependency-free enhancement of the native select, keyboard navigation, filtering, no-results feedback, Escape handling and required-value validation where appropriate.
- `eventadmin_enqueue_searchable_select()` in helpers enqueues these assets for manager and shared shift-modal screens.
- Shift roster AJAX includes per-person Remove from shift controls and the initially collapsed Add volunteer panel. Full shifts retain removal controls but have no addition checkbox/button.
- New action `eventadmin_change_shift_roster` in `includes/admin/shift-details-modal.php` validates nonce, edit_post and eventadmin_manage_shifts, volunteer role on addition, duplicates, capacity, overlap and per-period limits. Uses existing assignment metadata, activity logging and notification helpers.
- Native nested `<dialog>` provides removal confirmation, shift context, Cancel/Remove buttons and a separate notification choice. Default focus is Cancel. Shared modal keyboard handling yields to the native dialog while open.
- Notification delivery is opt-in per action; offline volunteers cannot receive it. Addition and removal choices are independent.
- Roster refresh does not refill the editor's other fields, preserving unsaved edits.
- Day timeline patches rows in place through `eventadminOnShiftRosterChanged`. Other views reload after all drill-down dialogs close; opening a volunteer profile from the updated roster remains possible first.
- Assignment maps are refreshed after roster/timeline edits to avoid stale volunteer choices/context.
- Open full editor removed from markup and JS.
- A wrapper `eventadmin-shift-roster-section` groups heading + roster, hidden for create/duplicate and shown for existing shifts.

### Contact details and Volunteers administration

- Detailed manager Table: email uses mailto; phone uses tel with normalized number and original visible formatting.
- Volunteer summary: clickable real email, existing clickable phone, and Send Announcement link targeting `eventadmin-bulk-email&recipient_user_id=...`. Reuses the existing composer recipient feature; does not send on click. Offline synthetic emails are not shown as contact actions.
- Volunteers list starts compact: announcement status, registered date and last shift are hidden until More details is selected. No data is removed.
- Registration diagnostics tabs are under a collapsed Registration activity disclosure (opens when visiting one of those diagnostic tabs).
- Sortable Volunteers columns support Enter/Space and aria-sort.
- More room for contact text on wide screens in compact mode.

### Volunteer-facing UX

- Shifts/Profile tabs are real accessible tab buttons; arrow keys, Home/End, focus styling and unique panel IDs. Multiple cockpit shortcode tab groups no longer share IDs.
- Profile tab displays Phone number missing text rather than only a warning emoji.
- Sign-up actions: Sign up for this shift / Cancel my sign-up, with shift context in accessible labels.
- Availability: singular/plural place(s) left, replacing fractions of open slots.
- Day headings group shift lists; full configured card date/time formatting remains intact.
- Omit department badge only when its name exactly equals the shift title.
- Empty My shifts section offers Find a shift when open shifts exist.
- No shifts found feedback and Clear filters control for active frontend filters. Existing settings still determine which filters are enabled; no new settings were forced on.
- Pending AJAX submissions disable repeats; network errors are surfaced; feedback has live-status semantics.
- Card/filter/form spacing improved, including smaller-screen layouts.
- Registration explains email-link login, required fields and phone purpose, adds autofill attributes, and says Register and email me a login link.
- Profile department notification wording simplified and explains its independence from general announcements/shift eligibility.

### Shared accessibility and translations

- New `assets/js/modal-accessibility.js`: dialog roles/labels, focus containment, Escape close, focus return; TinyMCE iframe key handling. Search dropdown Escape closes the dropdown first; native nested removal dialog gets its own keyboard behavior.
- New scripts/styles use filemtime versioning.
- Ten existing PO/MO catalogs and POT updated repeatedly. No version bump yet. Latest roster-section hide change adds no strings, but source line references may need regeneration before release.

## Files to inspect

Core manager changes:
`includes/admin/dashboard-overview-page.php`, `dashboard-data.php`, `dashboard-tab-calendar.php`, `dashboard-tab-table.php`, `date-navigator.php`, `dashboard-menu.php`, `assets/js/admin-charts.js`, `assets/css/admin-dashboard.css`.

Modal/contact changes:
`includes/admin/shift-details-modal.php`, `includes/admin/user-profile.php`, `includes/helpers.php`, `assets/js/shift-details-modal.js`, new `assets/js/searchable-select.js`, new `assets/css/searchable-select.css`, new `assets/js/modal-accessibility.js`.

Volunteer-facing changes:
`includes/admin/volunteer-list.php`, `assets/js/volunteer-list.js`, `includes/cockpit.php`, `includes/profile.php`, `includes/registration.php`, `includes/shiftselector.php`, `assets/js/cockpit.js`, `assets/css/cockpit.css`, `assets/css/forms.css`.

`languages/*.po`, `*.mo`, and POT carry the translations. Review source changes separately from noisy generated translation line-reference changes.

## Verification performed and its limits

### Browser verified

Earlier passes used native Chrome with the CUA tool (including Pixel 9 Pro emulation). Verified:
- Date retention and calendar state; original mobile timeline plus sticky date controls.
- Table mailto/tel targets.
- Integrated dropdown filtering, Enter selection, Escape before outer-modal close.
- Adding and removing the test volunteer through shift modal; roster refresh and restoration; notifications off.
- Volunteer details mail link and Send Announcement opening composer with Helena Leu selected. No announcement sent.
- Calendar multi-day fixture on four dates; continuation arrows; switching Calendar to Day retains a visible volunteer selection.
- Volunteers compact list and volunteer-facing cards/tabs.

**Not fully browser verified after the latest refinements:** Status/Full additions, removal-chip removal, the new native removal confirmation, progressive Add volunteer disclosure, removal of Open full editor, and hiding the roster for new/duplicate shifts. These received syntax and/or backend/JS checks as described below, not a claim of final visual validation. Browser verification was interrupted by window changes several times.

### Preserved check scripts

Copies are in `design/handoff/2026-11-13/checks/`; original scripts also remain under `/tmp`. Run from repository root.

- `php design/handoff/2026-11-13/checks/eventadmin-ux-regression.php`: 12 date/table/escaping checks + 2 custom-format checks + 2 clickable-contact checks.
- `node design/handoff/2026-11-13/checks/eventadmin-tabs-test.cjs`: 9 tab keyboard/isolation checks.
- `node design/handoff/2026-11-13/checks/eventadmin-removal-confirmation-test.cjs`: 11 helper checks for question/context, Cancel/Escape, focus return and notification choices including offline case. Uses the real helper function with minimal fake dialog elements; not an end-to-end browser test.
- `eventadmin-calendar-integration.php`: 27 real-WordPress checks for multi-day ranges, preceding-week starts, midnight, DST date expansion, start-only data, volunteer filter, Day status control and Full behavior across four views. Creates temporary fixtures and removes them in finally.
- `eventadmin-roster-integration.php`: real-WordPress authorization, capacity, duplicate, overlap, add/remove, contact/announcement link, checkbox order, full-shift controls and collapsed-add-panel checks. Exercises optional removal notification with mail transport blocked.
- `eventadmin-ux-translations.py`: merges catalogs, fills added UX strings for existing locales/formal variants and compiles MO files. Requires polib, msgmerge and msgfmt. Review before reuse; this is a task helper, not a general translation service.

The PHP integration scripts target the local test site and accounts, not an arbitrary installation. They temporarily mutate the dedicated test volunteer's roster; do not run against production or in parallel with tests/user edits. Roster script restores its known test assignment to absent. Calendar script has optional preview/cleanup commands; preview should never be left behind. During development a fixture tracking scope issue was corrected, and the leaked fixture IDs 5130/5131 plus preview 5132 were explicitly removed. Later tests use corrected global fixture tracking and finally cleanup.

All test notifications were off or the pre_wp_mail test filter blocked actual delivery. No real emails/announcements were sent. Activity logs can contain test assignment/unassignment entries.

Syntax checks (`php -l`, `node --check`) and `git diff --check` passed throughout; latest create/duplicate roster-hide change received PHP/JS syntax and diff checks only. There is an existing PHP 8.5 deprecation on nullable `$shift_ids` in dashboard-form-handlers.php during WP CLI runs; it did not fail the tests and was not changed.

## Local environment / commands

- Local app site: `plugin-tester`, `http://plugin-tester.local`.
- WordPress root: `/Users/davidwiedmer/Local Sites/plugin-tester/app/public`.
- Plugin is linked to this working tree; code edits affect the local site immediately.
- Local runtime ID `euLAlOJ99`; PHP 8.4.27 shown by Local, while shell WP CLI uses Homebrew PHP (8.5 warnings possible).
- MySQL socket: `/Users/davidwiedmer/Library/Application Support/Local/run/euLAlOJ99/mysql/mysqld.sock`.
- Test account names: `claude-test-admin`, `claude-test-volunteer` (Andrea Frei, ID 1652). Credentials are deliberately not written here. Use an existing session or Local's existing one-click admin feature rather than resetting passwords.
- Frontend test pages: `/claude-test-registration/`, `/claude-test-shift-selector/`, `/claude-test-volunteer-profile/`.
- Event sample data is mostly September 2026/2027, so the current month can legitimately be empty.

Example local integration invocation (may require sandbox permission to reach the socket):

```sh
php -d 'mysqli.default_socket=/Users/davidwiedmer/Library/Application Support/Local/run/euLAlOJ99/mysql/mysqld.sock' -d error_reporting=22527 /opt/homebrew/bin/wp --path='/Users/davidwiedmer/Local Sites/plugin-tester/app/public' eval-file design/handoff/2026-11-13/checks/eventadmin-calendar-integration.php
```

POT regeneration used:

```sh
wp i18n make-pot . languages/eventadmin-volunteer-management.pot --domain=eventadmin-volunteer-management --exclude=node_modules,vendor
```

Redirect its verbose PHP deprecations to a log, then run the translation helper and check all ten catalogs for untranslated/fuzzy entries and compiled MO consistency.

## Screenshots and review artifacts

Stable repository copy: `design/handoff/2026-11-13/screenshots/index.html`.
Original local gallery: `/tmp/eventadmin-ux-final/index.html`.
Finder: Cmd+Shift+G, enter the folder, open index.html.

The screenshot gallery is historical evidence, **not an exact representation of the final code**:
- 01: detailed Table contact links (still representative).
- 02: shift roster controls before progressive disclosure, removal confirmation and Open full editor removal — outdated for those controls.
- 03/04: volunteer contact/announcement actions and selected recipient (still representative).
- 05/06: compact Volunteers list and volunteer-facing cards (still representative).
- 07: multi-day calendar fixture, which has since been deleted; screenshot still contains active-filter chips that have since been removed.
- 08: Day inherited volunteer filter, before chips removal and Status/Full additions.
- mobile-timeline-sticky and searchable-dropdown: earlier retained layouts; they also predate later modal/filter refinements.

Earlier raw review `/tmp/eventadmin-ux-review/` and first implementation `/tmp/eventadmin-ux-after/` exist, but the latter includes the **rejected** grouped Table and mobile agenda. Never treat those as the desired final design.

`design` is already excluded by `.distignore`. This handoff file was also added to `.distignore`, and `CLAUDE.md` points here, so handoff artifacts do not ship in the WordPress plugin package.

## Recommended continuation

1. Review the current diff and the user decisions above; preserve accepted changes and reversions.
2. Browser-test the **latest** modal flow end to end: new/duplicate hides roster; existing shift shows it; Add volunteer reveals selection + optional email above Add to shift; Cancel resets; success collapses; full shift shows no addition controls; removal asks confirmation; Cancel/Escape makes no request; optional removal email is independent and unavailable offline; no Open full editor link.
3. Check interaction between native removal dialog and shared focus/Escape handling, including on user-edit.php and with TinyMCE active.
4. Check Day Status/Full and volunteer filters after calendar switching, clearing and AJAX edits. In particular, live timeline roster/edit callbacks replace rows directly; verify an active staffing-status filter is still honored after the shift's staffing changes. This is a review concern, not a verified failure/fix.
5. Verify add-panel focus after additions/removals (including full -> open), and error feedback if roster refresh fails after a successful mutation.
6. Optional cleanup: unused full-editor URL config, older misleading module comments and repeated document listeners created by searchable-select enhancement when roster HTML is replaced. Do not expand scope into a redesign.
7. Re-run appropriate scripts and final PHP/JS syntax, translation and diff checks. Refresh screenshots for the final modal/filter design.
8. User has not yet asked to release this UX batch. If/when releasing, choose the new version, update the header/constant/readme/changelog/release notice consistently, commit/push the reviewed branch and complete the project's PR/tag flow. `.github/workflows/deploy.yml` deploys to WordPress.org on `v*` tag pushes, so tagging is a publishing action. Verify Actions and SVN logs after release. Do not assume the original 3.9.0 release should be repeated.

## Review follow-up fixes (13 November 2026, later session)

- `modal-accessibility.js`: focus trap, Tab wrap and Escape now ignore TinyMCE's link toolbar / WordPress link dialog (`.mce-container`, `.mce-inline-toolbar-grp`, `#wp-link-wrap`, …), which render outside the overlay. Previously inserting a link in the shift description was impossible. Browser-verified.
- Day view: timeline rows carry `staffing` (assigned/min/max); `EVENTADMIN_SHIFT_EDIT.filters` passes the active Status/Volunteer filters; `replaceShiftRows()` drops a shift that no longer matches after a live edit. Newly created shifts stay visible regardless (`ignoreFilters`).
- Add-volunteer modal labels only append the email when `eventadmin_user_can_receive_email()`.
- "Create new volunteer" identifier field back to `type="text"` (`inputmode="email"`) — the handler also accepts an existing username.
- "places left" wording kept everywhere, including `[eventadmin_open_positions]` (user decision); translator comment fixed.
- nl_NL_formal "je u kunt" → "u zich kunt". POT regenerated, catalogs merged (929 strings, "Open full editor" now obsolete), MO files recompiled.
- `CLAUDE.md` toolbar/calendar descriptions updated (no active-filter chips, Status on all views, multi-day).
- `shift-details-modal.js`: `eventadminOnShiftRosterChanged()` now receives a numeric shift ID. It used to get the string from `idInput.value`, so `replaceShiftRows()`'s strict `shift_id ===` never matched: the old rows stayed (shown twice with no filter, never removed under a Status filter).

### Browser pass (same day, temporary shift 5147, deleted afterwards)
Verified in Chrome: link insert + Escape in the link field; roster shown on Edit, hidden on Add shift and Duplicate; no Open full editor; Add volunteer reveal → focus on picker, checkbox above Add to shift, keyboard search/select, Cancel resets selection + checkbox; successful add collapses and keeps unsaved description edits; limit error shown inline with controls re-enabled; full shift has no add controls; removal dialog (name + shift context, Cancel focused, notify unchecked), Escape removes nobody and leaves the shift modal open; confirmed removal full → open with focus on Add volunteer; Calendar reloads on close with updated summary; Day keeps date, shows Volunteer/Status, no chips; Day live patch honors Status (Empty/Full) and replaces rows without duplicates. No emails sent (notify always unchecked). Not covered: user-edit.php, mobile widths, offline volunteer (notify row hidden — covered by unit script only).

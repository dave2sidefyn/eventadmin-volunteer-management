<?php
/**
 * EventAdmin Volunteer Management - Helper functions
 * Enqueue styles, user-shift management, custom role and login check
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Prevent direct access
}

/**
 * Enqueue styles for forms
 * Loads the CSS file for forms in the frontend
 */
function eventadmin_form_styles(): void
{
    $css_path = plugin_dir_path(__FILE__) . '../assets/css/forms.css';

    wp_enqueue_style(
        'eventadmin-form-css',
        plugin_dir_url(__FILE__) . '../assets/css/forms.css',
        [],
        file_exists($css_path) ? filemtime($css_path) : null
    );
}

add_action('wp_enqueue_scripts', 'eventadmin_form_styles');

/**
 * Returns the names of assigned users for a shift
 * @param int $shift_id The ID of the shift
 * @return array Array with user names
 */
function eventadmin_get_user_display_names(int $shift_id): array
{
    $meta = get_post_meta($shift_id);
    $names = [];

    foreach ($meta as $key => $val) {
        if (str_starts_with($key, 'assigned_user_')) {
            $user_id = (int)$val[0];
            if (!get_userdata($user_id)) {
                continue;
            }
            $first = get_user_meta($user_id, 'first_name', true);
            $last = get_user_meta($user_id, 'last_name', true);
            $names[] = esc_html($first . ' ' . strtoupper(mb_substr($last, 0, 1)) . '.');
        }
    }

    return $names;
}

/**
 * Counts the number of assigned users for a shift
 * @param int $shift_id The ID of the shift
 * @return int Number of assigned users
 */
function eventadmin_count_assignments(int $shift_id): int
{
    $meta = get_post_meta($shift_id);
    $count = 0;

    foreach ($meta as $key => $values) {
        if (str_starts_with($key, 'assigned_user_')) {
            if (get_userdata((int)$values[0])) {
                $count++;
            }
        }
    }

    return $count;
}

/**
 * Removes all shift assignments for a deleted user.
 * Prevents orphaned assigned_user_* meta from inflating shift counts.
 * @param int $user_id The ID of the deleted user
 */
function eventadmin_cleanup_user_assignments(int $user_id): void
{
    $shifts = get_posts([
        'post_type'   => 'eventadmin_shift',
        'numberposts' => -1,
        'fields'      => 'ids',
        'meta_query'  => [['key' => 'assigned_user_' . $user_id, 'compare' => 'EXISTS']],
    ]);

    foreach ($shifts as $shift_id) {
        delete_post_meta($shift_id, 'assigned_user_' . $user_id);
    }
}

add_action('deleted_user', 'eventadmin_cleanup_user_assignments');

/**
 * Returns the IDs of all shifts a user has taken in a given year
 * @param int $user_id The user ID
 * @param int $year The year for which the shifts should be queried
 * @return array Array with the IDs of the shifts
 */
function eventadmin_get_user_shifts(int $user_id, int $year): array
{
    $all_shifts = get_posts(['post_type' => 'eventadmin_shift', 'numberposts' => -1]);
    $user_shifts = [];
    foreach ($all_shifts as $shift) {
        $meta_key = 'assigned_user_' . $user_id;
        if (get_post_meta($shift->ID, $meta_key, true)) {
            $start = strtotime(get_post_meta($shift->ID, 'shift_start', true));
            if (gmdate('Y', $start) == $year) {
                $user_shifts[] = $shift->ID;
            }
        }
    }
    return $user_shifts;
}

/**
 * Registers a custom role "Volunteer"
 * @return void
 */
function eventadmin_register_custom_role(): void
{
    $display_name = esc_html__('Volunteer', 'eventadmin-volunteer-management');
    $role = add_role(
        'eventadmin_volunteer',
        $display_name,
        [
            'read' => true,
        ]
    );

    // If the role already exists, ensure display name and capabilities stay in sync.
    if (null === $role) {
        $existing = wp_roles()->get_role('eventadmin_volunteer');
        if ($existing) {
            if ($existing->name !== $display_name) {
                $existing->name                                   = $display_name;
                wp_roles()->roles['eventadmin_volunteer']['name'] = $display_name;
                wp_roles()->role_names['eventadmin_volunteer']    = $display_name;
                update_option(wp_roles()->role_key, wp_roles()->roles);
            }
            // Remove any explicit false caps (left over from older plugin versions) that would
            // silently override capabilities granted by a user's other roles, e.g. an
            // Administrator who is also a Volunteer losing access to wp-admin menus.
            // WP_Role::remove_cap() updates both the live role object (so it takes effect
            // immediately) and the persisted option, using the site's actual role option key
            // instead of a hardcoded name — required for custom table prefixes and multisite.
            foreach (['edit_posts', 'delete_posts'] as $cap) {
                if (isset($existing->capabilities[$cap])) {
                    $existing->remove_cap($cap);
                }
            }
        }
    }
}

add_action('init', 'eventadmin_register_custom_role');

/**
 * Registers the "Shift Manager" and "Volunteer Manager" roles, and grants their custom
 * capabilities to Administrator too — WordPress does not automatically add a newly
 * registered capability to Administrator, it has to be granted explicitly like any
 * other role. Follows the same self-healing pattern as eventadmin_register_custom_role()
 * above (add_role(), then sync name/capabilities on every load if it already exists)
 * rather than a one-off activation hook, so an update that adds a capability reaches
 * sites that installed an earlier version without requiring reactivation.
 *
 * @return void
 */
function eventadmin_register_management_roles(): void
{
    // Primitive capabilities WordPress derives for the eventadmin_shift post type now that
    // it has its own capability_type (see eventadmin_register_post_types() in
    // post-types.php) — map_meta_cap resolves checks like current_user_can('edit_post', $id)
    // against these, but a role still needs each primitive granted directly.
    $shift_caps = [
        'edit_eventadmin_shifts'             => true,
        'edit_others_eventadmin_shifts'      => true,
        'edit_private_eventadmin_shifts'     => true,
        'edit_published_eventadmin_shifts'   => true,
        'publish_eventadmin_shifts'          => true,
        'read_private_eventadmin_shifts'     => true,
        'delete_eventadmin_shifts'           => true,
        'delete_others_eventadmin_shifts'    => true,
        'delete_private_eventadmin_shifts'   => true,
        'delete_published_eventadmin_shifts' => true,
    ];

    $management_caps = array_merge($shift_caps, [
        'eventadmin_manage_shifts'      => true,
        'eventadmin_manage_volunteers'  => true,
        'eventadmin_manage_departments' => true,
    ]);

    $roles = [
        'eventadmin_shift_manager' => [
            'display_name' => esc_html__('Shift Manager', 'eventadmin-volunteer-management'),
            'caps' => array_merge($management_caps, ['read' => true]),
        ],
        'eventadmin_volunteer_manager' => [
            'display_name' => esc_html__('Volunteer Manager', 'eventadmin-volunteer-management'),
            'caps' => [
                'read'                         => true,
                'eventadmin_manage_volunteers' => true,
            ],
        ],
        'administrator' => [
            'display_name' => null, // Never renamed.
            'caps'         => $management_caps,
        ],
    ];

    // Reads and writes the raw wp_user_roles option directly, once, rather than going
    // through add_role()/WP_Role::add_cap()/remove_cap() for the "role already exists,
    // keep it in sync" path — those were observed, in testing, to sometimes update the
    // live WP_Roles/WP_Role object cache (so current_user_can() looks right for the rest
    // of that request) without the underlying update_option() call actually landing,
    // leaving the persisted option stale. A single direct read-modify-write avoids
    // relying on that object-caching layer for correctness. add_role() is still used
    // below for a genuinely new role, since that also needs to populate the in-memory
    // WP_Roles::$role_objects/$role_names caches other code reads this same request.
    $stored  = get_option(wp_roles()->role_key, []);
    $changed = false;

    foreach ($roles as $slug => $role_def) {
        if (!isset($stored[$slug])) {
            if ($slug === 'administrator') {
                continue; // Never happens, but nothing to "create" for a core role.
            }
            add_role($slug, $role_def['display_name'], $role_def['caps']);
            $stored  = get_option(wp_roles()->role_key, []);
            $changed = true;
            continue;
        }

        if ($role_def['display_name'] !== null && $stored[$slug]['name'] !== $role_def['display_name']) {
            $stored[$slug]['name'] = $role_def['display_name'];
            $changed                = true;
        }

        foreach (array_keys($role_def['caps']) as $cap) {
            if (empty($stored[$slug]['capabilities'][$cap])) {
                $stored[$slug]['capabilities'][$cap] = true;
                $changed                              = true;
            }
        }

        // Cleans up manage_categories, granted to eventadmin_shift_manager by an earlier
        // version of this function before eventadmin_shift_category got its own
        // capabilities — that generic capability is shared with the site's own blog post
        // categories, so leaving it granted would be scope creep.
        if ($slug === 'eventadmin_shift_manager' && isset($stored[$slug]['capabilities']['manage_categories'])) {
            unset($stored[$slug]['capabilities']['manage_categories']);
            $changed = true;
        }
    }

    if ($changed) {
        update_option(wp_roles()->role_key, $stored);
        // Rebuild the live WP_Roles cache from what was just persisted, so
        // current_user_can() reflects the change for the rest of this request too,
        // instead of only from the next request onward.
        wp_roles()->roles = $stored;
        wp_roles()->init_roles();
    }
}

add_action('init', 'eventadmin_register_management_roles');

/**
 * Checks if a user can take a shift
 * @param int $user_id The user ID
 * @param int $shift_id The shift ID
 * @return string|null Error message or 'ok' if all is fine
 */
function eventadmin_check_match_schicht_user(int $user_id, int $shift_id): ?string
{
    // Check if user is already assigned to this shift
    if (get_post_meta($shift_id, 'assigned_user_' . $user_id, true)) {
        return esc_html__('This volunteer is already assigned to this shift.', 'eventadmin-volunteer-management');
    }

    $start = strtotime(get_post_meta($shift_id, 'shift_start', true));
    $end = strtotime(get_post_meta($shift_id, 'shift_end', true));
    $year = gmdate('Y', $start);

    $user_shifts = eventadmin_get_user_shifts($user_id, $year);

    // Load limits from options
    $limits = [
        'year' => (int)get_option('eventadmin_limit_per_year'),
        'month' => (int)get_option('eventadmin_limit_per_month'),
        'week' => (int)get_option('eventadmin_limit_per_week'),
        'day' => (int)get_option('eventadmin_limit_per_day'),
    ];

    $counts = [
        'year' => 0,
        'month' => 0,
        'week' => 0,
        'day' => 0,
    ];

    foreach ($user_shifts as $sid) {
        $s_start = strtotime(get_post_meta($sid, 'shift_start', true));
        if (!$s_start) continue;

        if (gmdate('Y', $s_start) === gmdate('Y', $start)) $counts['year']++;
        if (gmdate('Ym', $s_start) === gmdate('Ym', $start)) $counts['month']++;
        if (gmdate('W', $s_start) === gmdate('W', $start)) $counts['week']++;
        if (gmdate('Ymd', $s_start) === gmdate('Ymd', $start)) $counts['day']++;
    }

    $period_names = [
        'year' => esc_html__('year', 'eventadmin-volunteer-management'),
        'month' => esc_html__('month', 'eventadmin-volunteer-management'),
        'week' => esc_html__('week', 'eventadmin-volunteer-management'),
        'day' => esc_html__('day', 'eventadmin-volunteer-management'),
    ];

    foreach ($limits as $period => $max) {
        if ($max > 0 && $counts[$period] >= $max) {
            $period_en = $period_names[$period] ?? $period; // fallback to English if not defined
            return sprintf(
            /* translators: %1$d = max number, %2$s = period (year/month/week/day) */
                esc_html__('You may only take %1$d shifts per %2$s.', 'eventadmin-volunteer-management'),
                $max,
                $period_en
            );
        }
    }

    // Check for overlaps if not allowed
    if (!get_option('eventadmin_allow_overlap')) {
        foreach ($user_shifts as $sid) {
            $s_start = strtotime(get_post_meta($sid, 'shift_start', true));
            $s_end = strtotime(get_post_meta($sid, 'shift_end', true));
            if (!($end <= $s_start || $start >= $s_end)) {
                return esc_html__('This shift overlaps with one you have already taken.', 'eventadmin-volunteer-management');
            }
        }
    }

    return 'ok';
}

/**
 * Checks the magic-link and logs the user in. The token is then deleted.
 * @return void
 */
function eventadmin_magic_login_check(): void
{
    if (
        isset($_GET['magic_login'], $_GET['uid'], $_GET['_wpnonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'eventadmin_magic_login')
    ) {
        $token = sanitize_text_field(wp_unslash($_GET['magic_login']));
        $user_id = absint($_GET['uid']);

        $saved_token = get_user_meta($user_id, 'magic_login_token', true);
        $expires = get_user_meta($user_id, 'magic_login_expire', true);

        if ($token === $saved_token && time() < $expires) {
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, true);
            delete_user_meta($user_id, 'magic_login_token');
            delete_user_meta($user_id, 'magic_login_expire');

            // For the Getting Started checklist (includes/admin/getting-started-checklist.php)
            // — confirms the magic-login flow actually works end to end, whoever used it.
            if (!get_option('eventadmin_magic_login_used')) {
                update_option('eventadmin_magic_login_used', 1);
            }
            $redirect_to = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash($_GET['redirect_to'])) : site_url('/');
            if (!str_starts_with($redirect_to, home_url())) {
                $redirect_to = home_url('/');
            }
            wp_safe_redirect($redirect_to);
            exit;
        } else {
            wp_die(esc_html__('Login link has expired or is invalid.', 'eventadmin-volunteer-management'));
        }
    }
}

add_action('init', 'eventadmin_magic_login_check');

/**
 * Schedules the daily cleanup of unverified volunteer accounts.
 */
function eventadmin_schedule_cleanup(): void
{
    if (!wp_next_scheduled('eventadmin_cleanup_unverified')) {
        wp_schedule_event(time(), 'daily', 'eventadmin_cleanup_unverified');
    }
}

add_action('init', 'eventadmin_schedule_cleanup');

/**
 * Schedules hourly reminder processing for upcoming shifts.
 */
function eventadmin_schedule_shift_reminders(): void
{
    if (!wp_next_scheduled('eventadmin_send_shift_reminders')) {
        wp_schedule_event(time(), 'hourly', 'eventadmin_send_shift_reminders');
    }
}

add_action('init', 'eventadmin_schedule_shift_reminders');

/**
 * Deletes volunteer accounts that were registered but never verified via magic link.
 * Only removes users whose token has already expired and who have no shift assignments.
 */
function eventadmin_cleanup_unverified_volunteers(): void
{
    $users = get_users([
        'role'       => 'eventadmin_volunteer',
        'meta_query' => [
            'relation' => 'AND',
            ['key' => 'magic_login_token', 'compare' => 'EXISTS'],
            ['key' => 'magic_login_expire', 'value' => time(), 'compare' => '<', 'type' => 'NUMERIC'],
            ['key' => 'eventadmin_manually_added', 'compare' => 'NOT EXISTS'],
        ],
    ]);

    if (empty($users)) {
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/user.php';

    $log = get_option('eventadmin_cleanup_log', []);

    foreach ($users as $user) {
        $assigned = get_posts([
            'post_type'   => 'eventadmin_shift',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_query'  => [['key' => 'assigned_user_' . $user->ID, 'compare' => 'EXISTS']],
        ]);

        if (!empty($assigned)) {
            continue;
        }

        $log[] = [
            'time'  => time(),
            'name'  => trim($user->first_name . ' ' . $user->last_name) ?: $user->user_login,
            'email' => $user->user_email,
        ];

        wp_delete_user($user->ID);
    }

    // Keep only the 100 most recent log entries.
    update_option('eventadmin_cleanup_log', array_slice($log, -100), false);
}

add_action('eventadmin_cleanup_unverified', 'eventadmin_cleanup_unverified_volunteers');

/**
 * Formatting of the period for shifts
 * @param string $start Start time of the shift
 * @param string $end End time of the shift
 * @return string Formatted period
 */
function eventadmin_get_formatted_zeitraum(string $start, string $end): string
{
    $defaults     = eventadmin_get_option_defaults();
    $start_format = get_option('eventadmin_shift_date_format', $defaults['eventadmin_shift_date_format']) ?: $defaults['eventadmin_shift_date_format'];
    $end_format   = get_option('eventadmin_shift_time_format', $defaults['eventadmin_shift_time_format']) ?: $defaults['eventadmin_shift_time_format'];

    // date_i18n automatically translates weekday, month, etc. based on WP language
    $start_fmt = date_i18n($start_format, strtotime($start));

    // An empty $end means "start only" — bail out before strtotime('') / date_i18n(false)
    // silently fall back to the current time instead of a real end time.
    if ($end === '') {
        return $start_fmt;
    }

    $end_fmt = date_i18n($end_format, strtotime($end));

    return $start_fmt . ' – ' . $end_fmt;
}

/**
 * Normalizes a shift_start/shift_end datetime value to the canonical 'Y-m-d H:i:s' format.
 *
 * The `<input type="datetime-local">` fields in the shift editor submit
 * 'Y-m-d\TH:i' (no seconds, 'T' separator), while other write paths (e.g. the demo
 * data importer) already use 'Y-m-d H:i:s'. Left un-normalized, this format drift
 * breaks any `orderby => 'meta_value'` sort on shift_start, since it compares the
 * stored strings byte-for-byte rather than as dates.
 *
 * @param string $raw Raw datetime string from the client.
 * @return string Canonical 'Y-m-d H:i:s', or '' if $raw is empty/unparseable.
 */
function eventadmin_normalize_datetime_input(string $raw): string
{
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d H:i:s', $ts) : '';
}

/**
 * Converts a shift_start/shift_end wall-clock string to a Unix timestamp *without* any
 * timezone shift — i.e. "2026-09-04 17:00:00" always becomes the timestamp for 17:00,
 * regardless of the server's php.ini date.timezone (which, unlike WP's own timezone_string
 * option, plain strtotime()/date() silently fall back to — commonly UTC, while the site
 * itself runs in a different zone). This is what the Timeline chart's JS side needs: it
 * displays and edits these timestamps using the browser's own local time formatting with
 * an explicit UTC timezone, so both ends have to agree on "the number IS the wall clock
 * time" with no shift applied by either side.
 *
 * @param string $raw 'Y-m-d H:i:s' or 'Y-m-d\TH:i'.
 * @return int Unix timestamp, or 0 if unparseable.
 */
function eventadmin_wallclock_to_ts(string $raw): int
{
    if ($raw === '') {
        return 0;
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $raw, new DateTimeZone('UTC'))
        ?: DateTime::createFromFormat('Y-m-d\TH:i', $raw, new DateTimeZone('UTC'));
    return $dt ? $dt->getTimestamp() : 0;
}

/**
 * Re-orders a flat list of hierarchical terms into parent-then-children order,
 * annotating each term with a `depth` property so callers can indent it for display.
 * Shared by every admin department dropdown/filter so they all show the same
 * parent/child structure instead of a flat, unordered list.
 *
 * @param WP_Term[] $terms  Flat term list (e.g. from get_terms()).
 * @param int       $parent Parent term_id to start from; 0 for top-level terms.
 * @param int       $depth  Current nesting depth (used internally for recursion).
 * @return WP_Term[]
 */
function eventadmin_flatten_term_hierarchy(array $terms, int $parent = 0, int $depth = 0): array
{
    $result = [];
    foreach ($terms as $term) {
        if ((int) $term->parent !== $parent) {
            continue;
        }
        $term->depth = $depth;
        $result[]    = $term;
        $result      = array_merge($result, eventadmin_flatten_term_hierarchy($terms, $term->term_id, $depth + 1));
    }
    return $result;
}

/**
 * Fetches every department term, ordered parent-then-children (see
 * eventadmin_flatten_term_hierarchy()) and ready to render as a dropdown.
 *
 * @return WP_Term[]
 */
function eventadmin_get_hierarchical_shift_categories(): array
{
    $terms = get_terms(['taxonomy' => 'eventadmin_shift_category', 'hide_empty' => false, 'orderby' => 'name']);
    return eventadmin_flatten_term_hierarchy(is_array($terms) ? $terms : []);
}

/**
 * Returns the department term IDs a volunteer is linked to — set either by an admin
 * (includes/admin/user-profile.php) or by the volunteer themselves (includes/profile.php).
 * Used to target department-specific announcements (includes/admin/bulk-email.php)
 * independent of whether the volunteer has ever actually worked a shift there.
 *
 * @param int $user_id
 * @return int[]
 */
function eventadmin_get_volunteer_department_ids(int $user_id): array
{
    return array_values(array_unique(array_map('absint', get_user_meta($user_id, 'eventadmin_department'))));
}

/**
 * Replaces a volunteer's department links with the given, already-unslashed term IDs —
 * validated against the real eventadmin_shift_category list so a stray/removed term ID
 * can't linger in user meta. Shared by the admin save handler (user-profile.php) and the
 * volunteer's own front-end profile form (profile.php).
 *
 * @param int   $user_id
 * @param array $term_ids Raw (but already wp_unslash()ed) values, e.g. from $_POST.
 * @return void
 */
function eventadmin_save_volunteer_department_ids(int $user_id, array $term_ids): void
{
    delete_user_meta($user_id, 'eventadmin_department');

    $valid_terms = wp_list_pluck(eventadmin_get_hierarchical_shift_categories(), 'term_id');
    foreach ($term_ids as $term_id) {
        $term_id = absint($term_id);
        if (in_array($term_id, $valid_terms, true)) {
            add_user_meta($user_id, 'eventadmin_department', $term_id);
        }
    }
}

/**
 * Builds indented <option> markup for a hierarchical department dropdown. Shared by every
 * admin department filter/select so children are always shown nested under their parent
 * the same way, instead of each screen re-implementing its own flat option loop.
 *
 * Every call site of this function is admin-only (Overview filters, the Edit/Add Shift
 * modal, Quick Edit, bulk email, the volunteer list) — a department hidden from volunteers
 * (see eventadmin_is_shift_category_hidden(), Shifts → Departments → "Hide from
 * volunteers") still needs to be selectable and recognizable here, just visibly marked, so
 * an admin isn't left guessing why a department volunteers keep asking about doesn't show
 * up for them. The volunteer-facing side (open-positions.php, shiftselector.php,
 * registration.php) filters hidden departments out entirely and does not use this function.
 *
 * @param WP_Term[]  $categories  From eventadmin_get_hierarchical_shift_categories().
 * @param string|int $selected    Currently selected value, matched against $value_field.
 * @param string     $value_field 'slug' or 'term_id' — which term property becomes the option value.
 * @return string
 */
function eventadmin_category_dropdown_options(array $categories, $selected, string $value_field = 'slug'): string
{
    $html = '';
    foreach ($categories as $cat) {
        $value  = $value_field === 'term_id' ? $cat->term_id : $cat->slug;
        $prefix = $cat->depth > 0 ? str_repeat('&nbsp;&nbsp;&nbsp;', $cat->depth) . '&#8211; ' : '';
        $suffix = eventadmin_is_shift_category_hidden($cat->term_id)
            ? ' ' . esc_html__('(hidden from volunteers)', 'eventadmin-volunteer-management')
            : '';
        $html  .= '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>' . $prefix . esc_html($cat->name) . $suffix . '</option>';
    }
    return $html;
}

/**
 * Echoes the opening markup for the simple centered modal dialog (dark overlay + white box)
 * shared by every "quick popup form" in the admin — copy shifts, add/move/edit volunteer,
 * view profile, create volunteer, grant role, etc. Kept in one place instead of repeating
 * the same overlay/box styling in each of those call sites.
 *
 * @param string $overlay_id HTML id for the outer overlay element (shown/hidden by this id).
 * @param int    $max_width  Box width in pixels — wider for content-heavy modals (e.g. the
 *                            volunteer profile modal), 480 (the common case) by default.
 * @return void
 */
function eventadmin_render_modal_open(string $overlay_id, int $max_width = 480): void
{
    echo '<div id="' . esc_attr($overlay_id) . '" class="eventadmin-modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;">';
    echo '<div style="position:relative;background:#fff;max-width:' . esc_attr($max_width) . 'px;margin:60px auto;padding:20px;border-radius:6px;max-height:80vh;overflow-y:auto;">';
}

/**
 * Echoes the "×" close button shared by every modal opened via eventadmin_render_modal_open().
 * Always carries the "eventadmin-modal-close" class (some pages close any open modal via a
 * single delegated handler on that class); pass $id as well for a page that instead binds
 * its own dedicated click handler to a specific button.
 *
 * @param string $id Optional HTML id, for a page with its own dedicated close handler.
 * @return void
 */
function eventadmin_render_modal_close_button(string $id = ''): void
{
    $id_attr = $id !== '' ? ' id="' . esc_attr($id) . '"' : '';
    echo '<button type="button"' . $id_attr . ' class="eventadmin-modal-close" aria-label="' . esc_attr__('Close', 'eventadmin-volunteer-management') . '" style="position:absolute;top:10px;right:10px;background:none;border:none;font-size:24px;line-height:1;cursor:pointer;color:#666;padding:4px 8px;">&times;</button>';
}

/**
 * Echoes the closing markup for a modal opened via eventadmin_render_modal_open().
 *
 * @return void
 */
function eventadmin_render_modal_close(): void
{
    echo '</div></div>';
}

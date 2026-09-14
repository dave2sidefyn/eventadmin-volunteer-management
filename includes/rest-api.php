<?php
/**
 * EventAdmin Volunteer Management - REST API
 * Exposes shifts and dashboard data at /wp-json/eventadmin/v1/, gated by the "Allow API
 * access" setting (Settings → General → API access) and one of two capabilities, same as
 * their wp-admin equivalents: eventadmin_manage_shifts for reading/creating shifts and
 * dashboard data (same as the Manager/Overview screens), and eventadmin_manage_volunteers
 * for emailing a shift's roster (same as "Send Announcement") — so a Volunteer Manager, who
 * has no shift-management access at all, can still use that one endpoint. Authenticated with
 * a WordPress Application Password, e.g. from an MCP server.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

function eventadmin_rest_permission_check(): bool
{
    return current_user_can('eventadmin_manage_shifts');
}

/**
 * Gates the notify-roster endpoint on the same capability the "Send Announcement" feature
 * already uses (includes/admin/bulk-email.php), not eventadmin_manage_shifts — sending mail
 * to volunteers is a volunteer-communication action, so a Volunteer Manager (who has no
 * shift-management access at all) can still use it, same as they already can in wp-admin.
 */
function eventadmin_rest_permission_check_volunteers(): bool
{
    return current_user_can('eventadmin_manage_volunteers');
}

/**
 * Gates /settings on manage_options, matching the Settings page itself — Shift Managers
 * can't see that page in wp-admin, so the API shouldn't hand them its contents either.
 */
function eventadmin_rest_permission_check_admin(): bool
{
    return current_user_can('manage_options');
}

/**
 * Enumerates the volunteers assigned to a shift from its `assigned_user_{ID}` post meta —
 * same raw enumeration eventadmin_ajax_delete_shift() uses (dashboard-tab-timeline.php) —
 * shaped as {id, name} pairs. Includes offline volunteers too (this just answers "who is on
 * this shift", not "who can be emailed" — that check happens separately, at send time).
 *
 * @param int $shift_id
 * @return array<int, array{id: int, name: string}>
 */
function eventadmin_rest_shift_roster(int $shift_id): array
{
    $roster = [];

    foreach (get_post_meta($shift_id) as $key => $val) {
        if (!str_starts_with($key, 'assigned_user_')) {
            continue;
        }
        $user_id = absint($val[0]);
        $user    = $user_id ? get_userdata($user_id) : false;
        if (!$user) {
            continue;
        }
        $roster[] = [
            'id'   => $user_id,
            'name' => $user->display_name ?: trim($user->first_name . ' ' . $user->last_name),
        ];
    }

    return $roster;
}

/**
 * Shapes a shift post into the REST response format shared by the list and single-shift
 * endpoints.
 *
 * @param WP_Post $shift
 * @return array<string, mixed>
 */
function eventadmin_rest_shift_response(WP_Post $shift): array
{
    $terms    = wp_get_post_terms($shift->ID, 'eventadmin_shift_category');
    $category = (!empty($terms) && !is_wp_error($terms)) ? $terms[0] : null;
    $max      = (int) get_post_meta($shift->ID, 'max_volunteers', true);
    $assigned = eventadmin_count_assignments($shift->ID);

    return [
        'id'                  => $shift->ID,
        'title'               => $shift->post_title,
        'description'         => $shift->post_content,
        'start'               => (string) get_post_meta($shift->ID, 'shift_start', true),
        'end'                 => (string) get_post_meta($shift->ID, 'shift_end', true),
        'min_volunteers'      => (int) get_post_meta($shift->ID, 'min_volunteers', true),
        'max_volunteers'      => $max,
        'assigned_count'      => $assigned,
        'is_full'             => $max > 0 && $assigned >= $max,
        'assigned_volunteers' => eventadmin_rest_shift_roster($shift->ID),
        'category_id'         => $category ? $category->term_id : 0,
        'category_name'       => $category ? $category->name : '',
        'organizer_user_id'   => (int) get_post_meta($shift->ID, 'shift_organizer_user_id', true),
        'organizer_name'      => (string) get_post_meta($shift->ID, 'shift_organizer_name', true),
        'organizer_email'     => (string) get_post_meta($shift->ID, 'shift_organizer_email', true),
        'edit_url'            => admin_url('post.php?action=edit&post=' . $shift->ID),
    ];
}

/**
 * GET /shifts - list shifts, with the same filters the Manager/Overview views use.
 */
function eventadmin_rest_get_shifts(WP_REST_Request $request): WP_REST_Response
{
    $total  = 0;
    $shifts = eventadmin_get_shifts(
        (string) $request->get_param('category'),
        (int) $request->get_param('page'),
        (int) $request->get_param('per_page'),
        (string) $request->get_param('time_filter'),
        'date',
        'ASC',
        0,
        (string) $request->get_param('date'),
        $total
    );

    $response = rest_ensure_response(array_map('eventadmin_rest_shift_response', $shifts));
    $response->header('X-WP-Total', (string) $total);

    return $response;
}

/**
 * GET /shifts/{id} - a single shift's details.
 */
function eventadmin_rest_get_shift(WP_REST_Request $request)
{
    $shift = get_post((int) $request->get_param('id'));

    if (!$shift || $shift->post_type !== 'eventadmin_shift') {
        return new WP_Error('eventadmin_shift_not_found', esc_html__('Shift not found.', 'eventadmin-volunteer-management'), ['status' => 404]);
    }

    return rest_ensure_response(eventadmin_rest_shift_response($shift));
}

/**
 * POST /shifts - creates a shift, mirroring the Manager view's "+ Add shift" fields
 * (see eventadmin_ajax_create_shift() in includes/admin/dashboard-tab-timeline.php).
 */
function eventadmin_rest_create_shift(WP_REST_Request $request)
{
    $title = sanitize_text_field((string) $request->get_param('title'));
    $start = eventadmin_normalize_datetime_input((string) $request->get_param('start'));
    $end   = eventadmin_normalize_datetime_input((string) $request->get_param('end'));

    if ($title === '' || $start === '' || $end === '') {
        return new WP_Error('eventadmin_invalid_shift', esc_html__('Title, start and end are required.', 'eventadmin-volunteer-management'), ['status' => 400]);
    }

    $shift_id = wp_insert_post([
        'post_type'    => 'eventadmin_shift',
        'post_status'  => 'publish',
        'post_title'   => $title,
        'post_content' => wp_kses_post((string) $request->get_param('description')),
    ], true);

    if (is_wp_error($shift_id)) {
        return $shift_id;
    }

    update_post_meta($shift_id, 'shift_start', $start);
    update_post_meta($shift_id, 'shift_end', $end);
    update_post_meta($shift_id, 'min_volunteers', absint($request->get_param('min_volunteers')));
    update_post_meta($shift_id, 'max_volunteers', max(1, absint($request->get_param('max_volunteers'))));

    $category_id = absint($request->get_param('category_id'));
    if ($category_id > 0 && get_term($category_id, 'eventadmin_shift_category')) {
        wp_set_object_terms($shift_id, [$category_id], 'eventadmin_shift_category');
    }

    eventadmin_save_shift_organizer_fields($shift_id, [
        'shift_organizer_user_id' => $request->get_param('organizer_user_id'),
        'shift_organizer_name'    => $request->get_param('organizer_name'),
        'shift_organizer_email'   => $request->get_param('organizer_email'),
    ]);

    eventadmin_flush_shifts_cache();

    return rest_ensure_response(eventadmin_rest_shift_response(get_post($shift_id)));
}

/**
 * GET /dashboard - the same KPI numbers shown on the Overview dashboard and the wp-admin
 * Dashboard widget (see eventadmin_calculate_dashboard_stats()).
 */
function eventadmin_rest_get_dashboard(): WP_REST_Response
{
    $total_users = count(get_users(['role' => 'eventadmin_volunteer']));

    return rest_ensure_response(eventadmin_calculate_dashboard_stats($total_users, 5));
}

/**
 * GET /departments - every shift department (eventadmin_shift_category term), so a caller
 * can resolve a department name to the category_id create_shift needs — without this, an MCP
 * client has no way to discover a department's ID unless a shift already exists in it.
 */
function eventadmin_rest_get_departments(): WP_REST_Response
{
    $terms = get_terms(['taxonomy' => 'eventadmin_shift_category', 'hide_empty' => false]);
    if (is_wp_error($terms)) {
        $terms = [];
    }

    $departments = array_map(static function (WP_Term $term): array {
        return [
            'id'          => $term->term_id,
            'name'        => $term->name,
            'parent_id'   => $term->parent,
            'description' => $term->description,
            'color'       => get_term_meta($term->term_id, 'term_color', true) ?: '#cccccc',
            'hidden'      => eventadmin_is_shift_category_hidden($term->term_id),
        ];
    }, $terms);

    return rest_ensure_response($departments);
}

/**
 * GET /settings - the operative rules that affect shift creation/sign-up, mirroring exactly
 * what Settings → General shows (same manage_options capability as that page itself — this
 * deliberately does not use eventadmin_manage_shifts like the rest of this file, since a
 * Shift Manager can't see the Settings page in wp-admin either, and the API shouldn't expose
 * more than wp-admin already does for that role).
 */
function eventadmin_rest_get_settings(): WP_REST_Response
{
    return rest_ensure_response([
        'limit_per_day'               => (int) get_option('eventadmin_limit_per_day', 0),
        'limit_per_week'              => (int) get_option('eventadmin_limit_per_week', 0),
        'limit_per_month'             => (int) get_option('eventadmin_limit_per_month', 0),
        'limit_per_year'              => (int) get_option('eventadmin_limit_per_year', 0),
        'unlimited_when_zero'         => true,
        'allow_overlapping_shifts'    => (bool) get_option('eventadmin_allow_overlap'),
        'cancellation_deadline_hours' => (int) get_option('eventadmin_unassign_limit_hours', 0),
    ]);
}

/**
 * GET /documentation - the plugin's own in-admin Documentation page (EventAdmin →
 * Documentation), flattened to plain text. Reuses that page's real render function instead
 * of keeping a second copy of the same content, so this can never drift out of sync with what
 * a human sees there. Intended for an MCP client to consult when a user asks how a feature
 * works, rather than guessing.
 */
function eventadmin_rest_get_documentation(): WP_REST_Response
{
    if (!function_exists('eventadmin_plugin_documentation_page')) {
        return rest_ensure_response(['content' => '']);
    }

    ob_start();
    eventadmin_plugin_documentation_page();
    $html = ob_get_clean();

    // Turn block-level boundaries into line breaks before stripping tags, so the result
    // reads as plain text with headings/paragraphs/list items on their own lines instead of
    // one unbroken wall of text. Heading level becomes that many '#'s, markdown-style.
    $html = (string) preg_replace_callback('/<h([1-6])[^>]*>/i', static function (array $m): string {
        return "\n\n" . str_repeat('#', (int) $m[1]) . ' ';
    }, $html);
    $html = (string) preg_replace('/<\/h[1-6]>/i', "\n", $html);
    $html = (string) preg_replace('/<li[^>]*>/i', "\n- ", $html);
    $html = (string) preg_replace('/<\/(p|div|tr|ul|ol)>/i', "\n\n", $html);
    $html = (string) preg_replace('/<br\s*\/?>/i', "\n", $html);

    $text = wp_strip_all_tags((string) $html);
    // Every heading/label was written as esc_html__(), so entities like &amp; or &#039; are
    // still literal text at this point — decode them back to normal readable characters.
    $text = wp_specialchars_decode($text, ENT_QUOTES);
    $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);
    $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);

    return rest_ensure_response(['content' => trim($text)]);
}

/**
 * POST /shifts/{id}/notify - sends an ad-hoc email to everyone assigned to a shift. Mirrors
 * the "Send Announcement" feature's own sending logic (includes/admin/bulk-email.php): same
 * {first_name}/{last_name} placeholders, same plain-text-to-<br> handling, same
 * eventadmin_send_HTML_e_mail()/eventadmin_log_volunteer_notification() calls, logged as an
 * 'announcement' — this is a one-off version of the same idea, scoped to one shift's roster
 * instead of a chosen audience. Volunteers without a real e-mail address (offline volunteers)
 * are skipped, same as everywhere else.
 */
function eventadmin_rest_notify_shift(WP_REST_Request $request)
{
    $shift_id = (int) $request->get_param('id');
    $shift    = get_post($shift_id);

    if (!$shift || $shift->post_type !== 'eventadmin_shift') {
        return new WP_Error('eventadmin_shift_not_found', esc_html__('Shift not found.', 'eventadmin-volunteer-management'), ['status' => 404]);
    }

    $subject = sanitize_text_field((string) $request->get_param('subject'));
    $message = wp_kses_post((string) $request->get_param('message'));

    if ($subject === '' || $message === '') {
        return new WP_Error('eventadmin_invalid_notification', esc_html__('Subject and message are required.', 'eventadmin-volunteer-management'), ['status' => 400]);
    }

    $current_user = wp_get_current_user();
    $blog_name    = get_bloginfo('name');
    $from_email   = get_option('admin_email');
    $reply_to     = $current_user->exists() ? $current_user->user_email : $from_email;
    $has_shifts_placeholder = str_contains($message, '{shifts}');

    $sent_to = [];
    $skipped = [];

    foreach (get_post_meta($shift_id) as $key => $val) {
        if (!str_starts_with($key, 'assigned_user_')) {
            continue;
        }
        $user_id = absint($val[0]);
        $user    = $user_id ? get_userdata($user_id) : false;
        if (!$user) {
            continue;
        }

        if (!eventadmin_user_can_receive_email($user_id)) {
            $skipped[] = ['id' => $user_id, 'name' => $user->display_name];
            continue;
        }

        $body = str_replace(['{first_name}', '{last_name}'], [$user->first_name, $user->last_name], $message);
        if (!preg_match('/<(p|div|br|h[1-6]|ul|ol|li)\b/i', $body)) {
            $body = nl2br($body);
        }
        if ($has_shifts_placeholder) {
            $body = str_replace('{shifts}', eventadmin_bulk_email_format_upcoming_shifts($user_id), $body);
        }

        eventadmin_send_HTML_e_mail(
            $user->user_email,
            $subject,
            $body,
            [
                'From: ' . $blog_name . ' <' . $from_email . '>',
                'Reply-To: ' . $reply_to,
                'Content-Type: text/html; charset=UTF-8',
            ],
            [
                'preheader' => wp_strip_all_tags($subject),
                'heading'   => $subject,
                'site_name' => $blog_name,
            ]
        );

        eventadmin_log_volunteer_notification($user_id, 'announcement', $subject, $shift_id);
        $sent_to[] = ['id' => $user_id, 'name' => $user->display_name];
    }

    if (empty($sent_to) && empty($skipped)) {
        return new WP_Error('eventadmin_no_volunteers', esc_html__('This shift has no assigned volunteers.', 'eventadmin-volunteer-management'), ['status' => 400]);
    }

    return rest_ensure_response([
        'sent_to' => $sent_to,
        'skipped' => $skipped,
    ]);
}

function eventadmin_register_rest_routes(): void
{
    if (!get_option('eventadmin_enable_rest_api')) {
        return;
    }

    register_rest_route('eventadmin/v1', '/shifts', [
        [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'eventadmin_rest_get_shifts',
            'permission_callback' => 'eventadmin_rest_permission_check',
            'args'                => [
                'category'    => ['type' => 'string', 'default' => ''],
                'page'        => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page'    => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
                'time_filter' => ['type' => 'string', 'default' => 'future', 'enum' => ['future', 'past', 'all']],
                'date'        => ['type' => 'string', 'default' => ''],
            ],
        ],
        [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'eventadmin_rest_create_shift',
            'permission_callback' => 'eventadmin_rest_permission_check',
            'args'                => [
                'title'             => ['type' => 'string', 'required' => true],
                'start'             => ['type' => 'string', 'required' => true],
                'end'               => ['type' => 'string', 'required' => true],
                'description'       => ['type' => 'string', 'default' => ''],
                'category_id'       => ['type' => 'integer', 'default' => 0],
                'min_volunteers'    => ['type' => 'integer', 'default' => 0],
                'max_volunteers'    => ['type' => 'integer', 'default' => 1],
                'organizer_user_id' => ['type' => 'integer', 'default' => 0],
                'organizer_name'    => ['type' => 'string', 'default' => ''],
                'organizer_email'   => ['type' => 'string', 'default' => ''],
            ],
        ],
    ]);

    register_rest_route('eventadmin/v1', '/shifts/(?P<id>\d+)', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'eventadmin_rest_get_shift',
        'permission_callback' => 'eventadmin_rest_permission_check',
        'args'                => [
            'id' => ['type' => 'integer'],
        ],
    ]);

    register_rest_route('eventadmin/v1', '/dashboard', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'eventadmin_rest_get_dashboard',
        'permission_callback' => 'eventadmin_rest_permission_check',
    ]);

    register_rest_route('eventadmin/v1', '/shifts/(?P<id>\d+)/notify', [
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'eventadmin_rest_notify_shift',
        'permission_callback' => 'eventadmin_rest_permission_check_volunteers',
        'args'                => [
            'id'      => ['type' => 'integer'],
            'subject' => ['type' => 'string', 'required' => true],
            'message' => ['type' => 'string', 'required' => true],
        ],
    ]);

    register_rest_route('eventadmin/v1', '/departments', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'eventadmin_rest_get_departments',
        'permission_callback' => 'eventadmin_rest_permission_check',
    ]);

    register_rest_route('eventadmin/v1', '/settings', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'eventadmin_rest_get_settings',
        'permission_callback' => 'eventadmin_rest_permission_check_admin',
    ]);

    register_rest_route('eventadmin/v1', '/documentation', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'eventadmin_rest_get_documentation',
        'permission_callback' => 'eventadmin_rest_permission_check',
    ]);
}

add_action('rest_api_init', 'eventadmin_register_rest_routes');

/**
 * Fixed app_id for the "Authorize Application" flow below — a stable ID (rather than a
 * fresh one per click) means re-connecting updates the same "EventAdmin MCP" entry in the
 * account's Application Passwords list instead of creating a new one every time.
 */
const EVENTADMIN_MCP_APP_ID = '78de1378-b178-4031-9ab8-0b2aa87e95b0';

/**
 * Renders the Settings → General → "API access" section's "Connect" field: a button that
 * uses WordPress core's own "Authorize Application" screen (the same browser hand-off flow
 * the official WordPress mobile app uses) to create an Application Password for whichever
 * account is currently logged in, without anyone having to create and copy-paste one by
 * hand via Users → profile → Application Passwords.
 */
function eventadmin_render_mcp_connect_field(): void
{
    if (!get_option('eventadmin_enable_rest_api')) {
        echo '<p class="description">' . esc_html__('Turn on "Allow API access" above and save, then come back here to connect an MCP client.', 'eventadmin-volunteer-management') . '</p>';
        return;
    }

    if (!wp_is_application_passwords_available()) {
        echo '<p class="description">' . esc_html__('Application Passwords aren\'t available on this site — they require HTTPS. Enable HTTPS to connect an MCP client this way.', 'eventadmin-volunteer-management') . '</p>';
        return;
    }

    $settings_url  = admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-settings&tab=general');
    $success_url   = $settings_url . '&eventadmin_mcp_connected=1';
    $authorize_url = admin_url('authorize-application.php')
        . '?app_name=' . rawurlencode('EventAdmin MCP')
        . '&app_id=' . EVENTADMIN_MCP_APP_ID
        . '&success_url=' . rawurlencode($success_url)
        . '&reject_url=' . rawurlencode($settings_url);

    echo '<a href="' . esc_url($authorize_url) . '" class="button button-primary">' . esc_html__('Connect an MCP client', 'eventadmin-volunteer-management') . '</a>';
    echo '<p class="description">' . esc_html__('Uses WordPress\'s own "Authorize Application" screen, so there\'s no password to create or copy by hand. Connects whichever account you\'re currently logged in as.', 'eventadmin-volunteer-management') . '</p>';

    if (
        current_user_can('manage_options')
        && isset($_GET['eventadmin_mcp_connected'], $_GET['site_url'], $_GET['user_login'], $_GET['password'])
    ) {
        eventadmin_render_mcp_connect_result(
            sanitize_text_field(wp_unslash($_GET['site_url'])),
            sanitize_text_field(wp_unslash($_GET['user_login'])),
            sanitize_text_field(wp_unslash($_GET['password']))
        );
    }
}

/**
 * Shows the freshly-authorized site URL/username/password once as a ready-to-paste MCP
 * client config — the plugin never stores this password itself, so (matching the same
 * "shown once, copy it now" convention WordPress's own Application Passwords screen uses)
 * this is the only chance to grab it from here.
 *
 * @param string $site_url
 * @param string $user_login
 * @param string $password
 */
function eventadmin_render_mcp_connect_result(string $site_url, string $user_login, string $password): void
{
    $config = wp_json_encode([
        'mcpServers' => [
            'eventadmin' => [
                'command' => 'npx',
                'args'    => ['-y', 'eventadmin-mcp-server'],
                'env'     => [
                    'EVENTADMIN_SITE_URL'     => $site_url,
                    'EVENTADMIN_USERNAME'     => $user_login,
                    'EVENTADMIN_APP_PASSWORD' => $password,
                ],
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    echo '<div class="notice notice-success" style="margin:12px 0 0;padding:12px;">';
    echo '<p><strong>' . esc_html__('Connected! Paste this into your MCP client\'s configuration file or settings screen — not into a chat message — then close this page. This password is shown only once.', 'eventadmin-volunteer-management') . '</strong></p>';
    echo '<pre id="eventadmin-mcp-config" style="background:#fff;padding:10px;overflow:auto;">' . esc_html($config) . '</pre>';
    echo '<button type="button" class="button" id="eventadmin-mcp-copy">' . esc_html__('Copy to clipboard', 'eventadmin-volunteer-management') . '</button>';
    echo '<script>
    jQuery(function ($) {
        $("#eventadmin-mcp-copy").on("click", function () {
            var $btn = $(this);
            var text = document.getElementById("eventadmin-mcp-config").textContent;

            function showCopied() {
                var original = $btn.text();
                $btn.text(' . wp_json_encode(esc_html__('Copied!', 'eventadmin-volunteer-management')) . ');
                setTimeout(function () { $btn.text(original); }, 1500);
            }

            // navigator.clipboard only exists in a "secure context" — HTTPS, or the literal
            // hostname localhost/127.0.0.1. Most local dev sites (e.g. Local by Flywheel\'s
            // *.local over plain HTTP) are neither, even though WordPress itself is happy to
            // hand out Application Passwords there (it treats WP_ENVIRONMENT_TYPE=local as
            // equivalent to HTTPS — the browser API does not). Same fallback as the
            // Volunteers list\'s own copy-to-clipboard buttons (assets/js/volunteer-list.js).
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(showCopied, fallbackCopy);
            } else {
                fallbackCopy();
            }

            function fallbackCopy() {
                var $tmp = $("<textarea>").val(text).css({position: "fixed", left: "-9999px"}).appendTo("body");
                $tmp[0].select();
                document.execCommand("copy");
                $tmp.remove();
                showCopied();
            }
        });
    });
    </script>';
    echo '</div>';
}

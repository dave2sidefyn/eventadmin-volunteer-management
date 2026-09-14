<?php
/**
 * EventAdmin Volunteer Management - REST API
 * Exposes shifts and dashboard data at /wp-json/eventadmin/v1/, gated by the "Allow API
 * access" setting (Settings → General → API access) and the eventadmin_manage_shifts
 * capability — the same one that already gates the Manager/Overview screens, so anyone who
 * can already manage shifts in wp-admin can do the same over the API (e.g. from an MCP
 * server), authenticated with a WordPress Application Password.
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
        'id'                => $shift->ID,
        'title'             => $shift->post_title,
        'description'       => $shift->post_content,
        'start'             => (string) get_post_meta($shift->ID, 'shift_start', true),
        'end'               => (string) get_post_meta($shift->ID, 'shift_end', true),
        'min_volunteers'    => (int) get_post_meta($shift->ID, 'min_volunteers', true),
        'max_volunteers'    => $max,
        'assigned_count'    => $assigned,
        'is_full'           => $max > 0 && $assigned >= $max,
        'category_id'       => $category ? $category->term_id : 0,
        'category_name'     => $category ? $category->name : '',
        'organizer_user_id' => (int) get_post_meta($shift->ID, 'shift_organizer_user_id', true),
        'organizer_name'    => (string) get_post_meta($shift->ID, 'shift_organizer_name', true),
        'organizer_email'   => (string) get_post_meta($shift->ID, 'shift_organizer_email', true),
        'edit_url'          => admin_url('post.php?action=edit&post=' . $shift->ID),
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
    echo '<p><strong>' . esc_html__('Connected! Paste this into your MCP client\'s configuration, then close this page — this password is shown only once.', 'eventadmin-volunteer-management') . '</strong></p>';
    echo '<pre id="eventadmin-mcp-config" style="background:#fff;padding:10px;overflow:auto;">' . esc_html($config) . '</pre>';
    echo '<button type="button" class="button" id="eventadmin-mcp-copy">' . esc_html__('Copy to clipboard', 'eventadmin-volunteer-management') . '</button>';
    echo '<script>
    jQuery(function ($) {
        $("#eventadmin-mcp-copy").on("click", function () {
            var $btn = $(this);
            var text = document.getElementById("eventadmin-mcp-config").textContent;
            navigator.clipboard.writeText(text);
            var original = $btn.text();
            $btn.text(' . wp_json_encode(esc_html__('Copied!', 'eventadmin-volunteer-management')) . ');
            setTimeout(function () { $btn.text(original); }, 1500);
        });
    });
    </script>';
    echo '</div>';
}

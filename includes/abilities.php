<?php
/**
 * EventAdmin Volunteer Management - WordPress Abilities API
 *
 * Registers the same eight operations as the REST API (includes/rest-api.php) as WordPress
 * "abilities" (WordPress 6.9+), so AI assistants and other tools that discover abilities —
 * the core /wp-json/wp-abilities/v1/ endpoints, the WordPress MCP adapter, AI features in
 * WordPress itself — can use EventAdmin without the separate eventadmin-mcp-server package.
 *
 * Deliberately a thin layer over the REST callbacks: every ability builds a WP_REST_Request
 * and calls the matching eventadmin_rest_*() function, so the two can never drift apart. Same
 * on/off switch ("Allow API access", eventadmin_enable_rest_api) and the same three capability
 * gates as the REST API — never more access than the role already has in wp-admin.
 *
 * Compatibility: on WordPress before 6.9 the hooks below never fire, so nothing happens. Only
 * arguments that exist since 6.9 are used (permission_callback rather than 7.2's
 * required_capabilities, which older versions would reject as an unknown property).
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

const EVENTADMIN_ABILITY_CATEGORY = 'eventadmin';

/**
 * Whether EventAdmin's abilities are registered at all: same switch as the REST API.
 */
function eventadmin_abilities_enabled(): bool
{
    return (bool) get_option('eventadmin_enable_rest_api');
}

function eventadmin_register_ability_category(): void
{
    if (!eventadmin_abilities_enabled() || !function_exists('wp_register_ability_category')) {
        return;
    }

    wp_register_ability_category(EVENTADMIN_ABILITY_CATEGORY, [
        'label'       => __('EventAdmin', 'eventadmin-volunteer-management'),
        'description' => __('Volunteer shifts, departments and sign-ups managed with EventAdmin.', 'eventadmin-volunteer-management'),
    ]);
}
add_action('wp_abilities_api_categories_init', 'eventadmin_register_ability_category');

/**
 * Runs one of the REST callbacks with $params and hands back its plain data (or WP_Error),
 * which is what an ability's execute_callback has to return.
 *
 * @param callable             $callback REST callback, e.g. 'eventadmin_rest_get_shifts'.
 * @param array<string, mixed> $params   Request parameters, already defaulted.
 * @return mixed|WP_Error
 */
function eventadmin_ability_call_rest(callable $callback, array $params = [])
{
    $request = new WP_REST_Request();
    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }

    $result = $callback($request);
    if (is_wp_error($result)) {
        return $result;
    }

    return rest_ensure_response($result)->get_data();
}

/**
 * Ability input arrives as null when the caller sends nothing; normalise to an array.
 *
 * @param mixed $input
 * @return array<string, mixed>
 */
function eventadmin_ability_input($input): array
{
    return is_array($input) ? $input : [];
}

/**
 * JSON Schema of one shift, matching eventadmin_rest_shift_response().
 *
 * @return array<string, mixed>
 */
function eventadmin_ability_shift_schema(): array
{
    return [
        'type'       => 'object',
        'properties' => [
            'id'                  => ['type' => 'integer'],
            'title'               => ['type' => 'string'],
            'description'         => ['type' => 'string'],
            'start'               => ['type' => 'string', 'description' => 'Site-local start, Y-m-d H:i:s.'],
            'end'                 => ['type' => 'string', 'description' => 'Site-local end, Y-m-d H:i:s.'],
            'min_volunteers'      => ['type' => 'integer'],
            'max_volunteers'      => ['type' => 'integer'],
            'assigned_count'      => ['type' => 'integer'],
            'is_full'             => ['type' => 'boolean'],
            'assigned_volunteers' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'   => ['type' => 'integer'],
                        'name' => ['type' => 'string'],
                    ],
                ],
            ],
            'category_id'         => ['type' => 'integer'],
            'category_name'       => ['type' => 'string'],
            'organizer_user_id'   => ['type' => 'integer'],
            'organizer_name'      => ['type' => 'string'],
            'organizer_email'     => ['type' => 'string'],
            'edit_url'            => ['type' => 'string'],
        ],
    ];
}

/**
 * Shared meta: listed in the abilities REST API and offered to MCP clients.
 *
 * @param bool $readonly
 * @param bool $idempotent
 * @return array<string, mixed>
 */
function eventadmin_ability_meta(bool $readonly, bool $idempotent): array
{
    return [
        'annotations'  => [
            'readonly'    => $readonly,
            'destructive' => false,
            'idempotent'  => $idempotent,
        ],
        'show_in_rest' => true,
        'public'       => true,
        'mcp'          => ['public' => true],
    ];
}

function eventadmin_register_abilities(): void
{
    if (!eventadmin_abilities_enabled() || !function_exists('wp_register_ability')) {
        return;
    }

    $shift_schema = eventadmin_ability_shift_schema();

    wp_register_ability('eventadmin/list-shifts', [
        'label'               => __('List shifts', 'eventadmin-volunteer-management'),
        'description'         => __('Lists volunteer shifts, optionally filtered by department, date or time period. Each shift includes who is signed up and whether it is full.', 'eventadmin-volunteer-management'),
        'category'            => EVENTADMIN_ABILITY_CATEGORY,
        'permission_callback' => 'eventadmin_rest_permission_check',
        'input_schema'        => [
            'type'                 => 'object',
            'properties'           => [
                'category'    => ['type' => 'string', 'description' => 'Department ID; empty for all departments.', 'default' => ''],
                'date'        => ['type' => 'string', 'description' => 'Only shifts on this day, Y-m-d.', 'default' => ''],
                'time_filter' => ['type' => 'string', 'enum' => ['future', 'past', 'all'], 'default' => 'future'],
                'page'        => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'per_page'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            ],
            'additionalProperties' => false,
            // Lets a caller send no input at all (null) for "upcoming shifts, first page".
            'default'              => [],
        ],
        'output_schema'       => ['type' => 'array', 'items' => $shift_schema],
        'execute_callback'    => static function ($input = null) {
            $input = eventadmin_ability_input($input);
            return eventadmin_ability_call_rest('eventadmin_rest_get_shifts', [
                'category'    => (string) ($input['category'] ?? ''),
                'date'        => (string) ($input['date'] ?? ''),
                'time_filter' => in_array($input['time_filter'] ?? '', ['future', 'past', 'all'], true) ? $input['time_filter'] : 'future',
                'page'        => max(1, (int) ($input['page'] ?? 1)),
                'per_page'    => min(100, max(1, (int) ($input['per_page'] ?? 20))),
            ]);
        },
        'meta'                => eventadmin_ability_meta(true, true),
    ]);

    wp_register_ability('eventadmin/get-shift', [
        'label'               => __('Get shift', 'eventadmin-volunteer-management'),
        'description'         => __('Gets the full details of one shift, including who is signed up.', 'eventadmin-volunteer-management'),
        'category'            => EVENTADMIN_ABILITY_CATEGORY,
        'permission_callback' => 'eventadmin_rest_permission_check',
        'input_schema'        => [
            'type'                 => 'object',
            'properties'           => [
                'id' => ['type' => 'integer', 'description' => 'Shift ID.', 'minimum' => 1],
            ],
            'required'             => ['id'],
            'additionalProperties' => false,
        ],
        'output_schema'       => $shift_schema,
        'execute_callback'    => static function ($input = null) {
            $input = eventadmin_ability_input($input);
            return eventadmin_ability_call_rest('eventadmin_rest_get_shift', ['id' => (int) ($input['id'] ?? 0)]);
        },
        'meta'                => eventadmin_ability_meta(true, true),
    ]);

    wp_register_ability('eventadmin/create-shift', [
        'label'               => __('Create shift', 'eventadmin-volunteer-management'),
        'description'         => __('Creates a new volunteer shift. Use List departments first to find the department ID.', 'eventadmin-volunteer-management'),
        'category'            => EVENTADMIN_ABILITY_CATEGORY,
        'permission_callback' => 'eventadmin_rest_permission_check',
        'input_schema'        => [
            'type'                 => 'object',
            'properties'           => [
                'title'             => ['type' => 'string', 'minLength' => 1],
                'start'             => ['type' => 'string', 'description' => 'Site-local start, e.g. 2027-01-15 09:00.'],
                'end'               => ['type' => 'string', 'description' => 'Site-local end, e.g. 2027-01-15 12:00.'],
                'description'       => ['type' => 'string', 'default' => ''],
                'category_id'       => ['type' => 'integer', 'description' => 'Department ID; 0 for none.', 'default' => 0],
                'min_volunteers'    => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                'max_volunteers'    => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'organizer_user_id' => ['type' => 'integer', 'default' => 0],
                'organizer_name'    => ['type' => 'string', 'default' => ''],
                'organizer_email'   => ['type' => 'string', 'default' => ''],
            ],
            'required'             => ['title', 'start', 'end'],
            'additionalProperties' => false,
        ],
        'output_schema'       => $shift_schema,
        'execute_callback'    => static function ($input = null) {
            $input = eventadmin_ability_input($input);
            return eventadmin_ability_call_rest('eventadmin_rest_create_shift', [
                'title'             => (string) ($input['title'] ?? ''),
                'start'             => (string) ($input['start'] ?? ''),
                'end'               => (string) ($input['end'] ?? ''),
                'description'       => (string) ($input['description'] ?? ''),
                'category_id'       => (int) ($input['category_id'] ?? 0),
                'min_volunteers'    => (int) ($input['min_volunteers'] ?? 0),
                'max_volunteers'    => (int) ($input['max_volunteers'] ?? 1),
                'organizer_user_id' => (int) ($input['organizer_user_id'] ?? 0),
                'organizer_name'    => (string) ($input['organizer_name'] ?? ''),
                'organizer_email'   => (string) ($input['organizer_email'] ?? ''),
            ]);
        },
        'meta'                => eventadmin_ability_meta(false, false),
    ]);

    wp_register_ability('eventadmin/get-dashboard', [
        'label'               => __('Get dashboard numbers', 'eventadmin-volunteer-management'),
        'description'         => __('Gets the numbers shown on the EventAdmin Overview: open, understaffed and full shifts, volunteers and sign-ups.', 'eventadmin-volunteer-management'),
        'category'            => EVENTADMIN_ABILITY_CATEGORY,
        'permission_callback' => 'eventadmin_rest_permission_check',
        'output_schema'       => ['type' => 'object'],
        'execute_callback'    => static function ($input = null) {
            return eventadmin_ability_call_rest('eventadmin_rest_get_dashboard');
        },
        'meta'                => eventadmin_ability_meta(true, true),
    ]);

    wp_register_ability('eventadmin/list-departments', [
        'label'               => __('List departments', 'eventadmin-volunteer-management'),
        'description'         => __('Lists every department with its ID, parent and colour, so a shift can be created or filtered by department name.', 'eventadmin-volunteer-management'),
        'category'            => EVENTADMIN_ABILITY_CATEGORY,
        'permission_callback' => 'eventadmin_rest_permission_check',
        'output_schema'       => [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'id'          => ['type' => 'integer'],
                    'name'        => ['type' => 'string'],
                    'parent_id'   => ['type' => 'integer'],
                    'description' => ['type' => 'string'],
                    'color'       => ['type' => 'string'],
                    'hidden'      => ['type' => 'boolean'],
                ],
            ],
        ],
        'execute_callback'    => static function ($input = null) {
            return eventadmin_ability_call_rest('eventadmin_rest_get_departments');
        },
        'meta'                => eventadmin_ability_meta(true, true),
    ]);

    wp_register_ability('eventadmin/notify-shift-volunteers', [
        'label'               => __('Email a shift\'s volunteers', 'eventadmin-volunteer-management'),
        'description'         => __('Sends an email to everyone signed up for one shift. The message may use {first_name}, {last_name} and {shifts}. Volunteers without an email address are skipped.', 'eventadmin-volunteer-management'),
        'category'            => EVENTADMIN_ABILITY_CATEGORY,
        'permission_callback' => 'eventadmin_rest_permission_check_volunteers',
        'input_schema'        => [
            'type'                 => 'object',
            'properties'           => [
                'id'      => ['type' => 'integer', 'description' => 'Shift ID.', 'minimum' => 1],
                'subject' => ['type' => 'string', 'minLength' => 1],
                'message' => ['type' => 'string', 'minLength' => 1],
            ],
            'required'             => ['id', 'subject', 'message'],
            'additionalProperties' => false,
        ],
        'output_schema'       => [
            'type'       => 'object',
            'properties' => [
                'sent_to' => ['type' => 'array'],
                'skipped' => ['type' => 'array'],
            ],
        ],
        'execute_callback'    => static function ($input = null) {
            $input = eventadmin_ability_input($input);
            return eventadmin_ability_call_rest('eventadmin_rest_notify_shift', [
                'id'      => (int) ($input['id'] ?? 0),
                'subject' => (string) ($input['subject'] ?? ''),
                'message' => (string) ($input['message'] ?? ''),
            ]);
        },
        'meta'                => eventadmin_ability_meta(false, false),
    ]);

    wp_register_ability('eventadmin/get-settings', [
        'label'               => __('Get sign-up rules', 'eventadmin-volunteer-management'),
        'description'         => __('Gets the active sign-up rules: shift limits per volunteer, overlap policy and cancellation deadline.', 'eventadmin-volunteer-management'),
        'category'            => EVENTADMIN_ABILITY_CATEGORY,
        'permission_callback' => 'eventadmin_rest_permission_check_admin',
        'output_schema'       => ['type' => 'object'],
        'execute_callback'    => static function ($input = null) {
            return eventadmin_ability_call_rest('eventadmin_rest_get_settings');
        },
        'meta'                => eventadmin_ability_meta(true, true),
    ]);

    wp_register_ability('eventadmin/get-documentation', [
        'label'               => __('Get EventAdmin documentation', 'eventadmin-volunteer-management'),
        'description'         => __('Gets the EventAdmin Documentation page as plain text, to answer questions about how a feature works.', 'eventadmin-volunteer-management'),
        'category'            => EVENTADMIN_ABILITY_CATEGORY,
        'permission_callback' => 'eventadmin_rest_permission_check',
        'output_schema'       => [
            'type'       => 'object',
            'properties' => ['content' => ['type' => 'string']],
        ],
        'execute_callback'    => static function ($input = null) {
            return eventadmin_ability_call_rest('eventadmin_rest_get_documentation');
        },
        'meta'                => eventadmin_ability_meta(true, true),
    ]);
}
add_action('wp_abilities_api_init', 'eventadmin_register_abilities');

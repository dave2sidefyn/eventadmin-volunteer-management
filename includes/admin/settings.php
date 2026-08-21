<?php
/**
 * EventAdmin Volunteer Management - Plugin Settings
 *
 * @package EventAdminVolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns configuration for the settings tabs.
 *
 * @return array<string, array<string, string>>
 */
function eventadmin_get_settings_tabs(): array
{
    return [
        'general' => [
            'title' => esc_html__('General', 'eventadmin-volunteer-management'),
            'menu'  => esc_html__('General', 'eventadmin-volunteer-management'),
            'page'  => 'eventadmin-settings-general',
            'group' => 'eventadmin_plugin_settings_general',
        ],
        'display' => [
            'title' => esc_html__('Display', 'eventadmin-volunteer-management'),
            'menu'  => esc_html__('Display', 'eventadmin-volunteer-management'),
            'page'  => 'eventadmin-settings-display',
            'group' => 'eventadmin_plugin_settings_display',
        ],
        'communication' => [
            'title' => esc_html__('Communication', 'eventadmin-volunteer-management'),
            'menu'  => esc_html__('Communication', 'eventadmin-volunteer-management'),
            'page'  => 'eventadmin-settings-communication',
            'group' => 'eventadmin_plugin_settings_communication',
        ],
    ];
}

/**
 * Adds the settings page for EventAdmin.
 */
function eventadmin_plugin_settings_menu(): void
{
    add_submenu_page(
        'edit.php?post_type=eventadmin_shift',
        esc_html__('Settings', 'eventadmin-volunteer-management'),
        esc_html__('Settings', 'eventadmin-volunteer-management'),
        'manage_options',
        'eventadmin-settings',
        'eventadmin_plugin_settings_page'
    );
}

add_action('admin_menu', 'eventadmin_plugin_settings_menu', 200);

/**
 * Returns the current settings tab configuration.
 *
 * @return array<string, string>|null
 */
function eventadmin_get_current_settings_tab(): ?array
{
    $tabs = eventadmin_get_settings_tabs();
    $tab  = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
    return $tabs[$tab] ?? null;
}

/**
 * Renders the Communication tab's settings sections grouped into sub-tabs (General / Shift
 * Confirmations / Reminders), navigated via a WP-native "subsubsub" link row — the same
 * style as the "All | Mine | Trash" links above a post list — instead of one long page.
 * All fields still live in the same single form/settings group, so switching sub-tabs never
 * risks losing edits made on another one: this only changes which section is *visible*.
 *
 * @param string $page The settings page slug (do_settings_sections()'s own $page argument).
 * @return void
 */
function eventadmin_render_communication_subtabs(string $page): void
{
    global $wp_settings_sections, $wp_settings_fields;

    $groups = [
        'general' => [
            'label'    => esc_html__('General', 'eventadmin-volunteer-management'),
            'sections' => ['eventadmin_communication_sender', 'eventadmin_communication_design'],
        ],
        'confirmations' => [
            'label'    => esc_html__('Shift Confirmations', 'eventadmin-volunteer-management'),
            'sections' => ['eventadmin_communication_templates'],
        ],
        'reminders' => [
            'label'    => esc_html__('Reminders', 'eventadmin-volunteer-management'),
            'sections' => ['eventadmin_communication_reminders'],
        ],
    ];

    $active = isset($_GET['subtab']) && array_key_exists(sanitize_key(wp_unslash($_GET['subtab'])), $groups)
        ? sanitize_key(wp_unslash($_GET['subtab']))
        : 'general';

    $slugs = array_keys($groups);
    $last  = end($slugs);

    echo '<ul class="subsubsub" id="eventadmin-communication-subtabs">';
    foreach ($groups as $slug => $group) {
        $url = add_query_arg('subtab', $slug);
        echo '<li><a href="' . esc_url($url) . '" data-subtab="' . esc_attr($slug) . '"' . ($slug === $active ? ' class="current"' : '') . '>' . $group['label'] . '</a>' . ($slug !== $last ? ' |' : '') . '</li>';
    }
    echo '</ul>';
    echo '<br class="clear">';

    foreach ($groups as $slug => $group) {
        $hidden = $slug !== $active;
        echo '<div class="eventadmin-subtab-panel" data-subtab-panel="' . esc_attr($slug) . '"' . ($hidden ? ' style="display:none;"' : '') . '>';
        foreach ($group['sections'] as $section_id) {
            if (!isset($wp_settings_sections[$page][$section_id])) {
                continue;
            }
            $section = $wp_settings_sections[$page][$section_id];
            if ($section['title']) {
                echo '<h2>' . esc_html($section['title']) . '</h2>';
            }
            if ($section['callback']) {
                call_user_func($section['callback'], $section);
            }
            if (isset($wp_settings_fields[$page][$section_id])) {
                echo '<table class="form-table" role="presentation">';
                do_settings_fields($page, $section_id);
                echo '</table>';
            }
        }
        echo '</div>';
    }

    echo '<script>
        document.querySelectorAll("#eventadmin-communication-subtabs a").forEach(function (link) {
            link.addEventListener("click", function (e) {
                e.preventDefault();
                var target = link.dataset.subtab;
                document.querySelectorAll("#eventadmin-communication-subtabs a").forEach(function (l) {
                    l.classList.toggle("current", l === link);
                });
                document.querySelectorAll(".eventadmin-subtab-panel").forEach(function (panel) {
                    panel.style.display = panel.dataset.subtabPanel === target ? "" : "none";
                });
                document.querySelectorAll("[data-subtab-preview]").forEach(function (preview) {
                    preview.style.display = preview.dataset.subtabPreview.split(" ").includes(target) ? "" : "none";
                });
                history.replaceState(null, "", link.href);
            });
        });
    </script>';
}

/**
 * Renders the settings page for the EventAdmin plugin.
 */
function eventadmin_plugin_settings_page(): void
{
    $current = eventadmin_get_current_settings_tab();
    if (!$current) {
        return;
    }

    $tabs = eventadmin_get_settings_tabs();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('EventAdmin Settings', 'eventadmin-volunteer-management'); ?></h1>
        <?php settings_errors(); ?>
        <h2 class="nav-tab-wrapper">
            <?php foreach ($tabs as $slug => $tab) : ?>
                <?php
                $url = admin_url('edit.php?post_type=eventadmin_shift&page=eventadmin-settings&tab=' . $slug);
                $active = (isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general') === $slug;
                ?>
                <a class="nav-tab <?php echo $active ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url($url); ?>">
                    <?php echo esc_html($tab['menu']); ?>
                </a>
            <?php endforeach; ?>
        </h2>

        <div class="inner-wrap">
            <form method="post" action="options.php" class="plugin-settings-left">
                <?php
                settings_fields($current['group']);
                if ($current['page'] === 'eventadmin-settings-communication') {
                    eventadmin_render_communication_subtabs($current['page']);
                } else {
                    do_settings_sections($current['page']);
                }
                submit_button();
                ?>
            </form>

            <?php if ($current['page'] === 'eventadmin-settings-communication') : ?>
                <div class="plugin-settings-right">
                    <?php eventadmin_plugin_placeholders_info(); ?>
                    <?php eventadmin_plugin_preview_field(); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Shows the available placeholders for email notifications.
 */
function eventadmin_plugin_placeholders_info(): void
{
    echo '<div class="plugin-placeholders-info">';
    echo '<p><strong>' . esc_html__('Available placeholders:', 'eventadmin-volunteer-management') . '</strong></p>';
    echo '<ul>';
    echo '<li><code>{first}</code> – ' . esc_html__('First name of the volunteer', 'eventadmin-volunteer-management') . '</li>';
    echo '<li><code>{last}</code> – ' . esc_html__('Last name of the volunteer', 'eventadmin-volunteer-management') . '</li>';
    echo '<li><code>{title}</code> – ' . esc_html__('Title of the shift', 'eventadmin-volunteer-management') . '</li>';
    echo '<li><code>{desc}</code> – ' . esc_html__('Description of the shift', 'eventadmin-volunteer-management') . '</li>';
    echo '<li><code>{start}</code> – ' . esc_html__('Start time of the shift (formatted)', 'eventadmin-volunteer-management') . '</li>';
    echo '<li><code>{end}</code> – ' . esc_html__('End time of the shift (formatted)', 'eventadmin-volunteer-management') . '</li>';
    echo '<li><code>{days}</code> – ' . esc_html__('Number of days before the shift starts', 'eventadmin-volunteer-management') . '</li>';
    echo '</ul>';
    echo '</div>';
}

/**
 * Sanitizes the reminder days input.
 *
 * @param mixed $value Raw option value.
 * @return string
 */
function eventadmin_sanitize_reminder_days($value): string
{
    $parts = preg_split('/[\s,;]+/', (string) $value);
    $days  = [];

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        // A plain (int) cast — not absint() — so a negative value like "-1" is
        // discarded rather than having its sign flipped into a real reminder day.
        $day = (int) $part;
        if ($day > 0) {
            $days[] = $day;
        }
    }

    $days = array_values(array_unique($days));
    sort($days, SORT_NUMERIC);

    return implode(', ', $days);
}

/**
 * Registers the settings for the EventAdmin plugin.
 */
function eventadmin_plugin_register_settings(): void
{
    register_setting('eventadmin_plugin_settings_general', 'eventadmin_suppress_wp_password_email', [
        'sanitize_callback' => static function ($val) {
            return $val === '1' ? 1 : 0;
        },
    ]);

    register_setting('eventadmin_plugin_settings_general', 'eventadmin_allow_overlap', [
        'sanitize_callback' => static function ($val) {
            return $val === '1' ? 1 : 0;
        },
    ]);

    register_setting('eventadmin_plugin_settings_display', 'eventadmin_show_full_shifts', [
        'sanitize_callback' => static function ($val) {
            return $val === '1' ? 1 : 0;
        },
    ]);

    register_setting('eventadmin_plugin_settings_general', 'eventadmin_unassign_limit_hours', [
        'sanitize_callback' => 'absint',
    ]);

    register_setting('eventadmin_plugin_settings_communication', 'eventadmin_notification_email', [
        'sanitize_callback' => 'sanitize_email',
    ]);

    register_setting('eventadmin_plugin_settings_communication', 'eventadmin_email_header_logo_id', [
        'sanitize_callback' => 'absint',
    ]);

    register_setting('eventadmin_plugin_settings_communication', 'eventadmin_email_header_color', [
        'sanitize_callback' => static function ($val) {
            if (empty($_POST['eventadmin_email_header_color_enabled'])) {
                return '';
            }
            return sanitize_hex_color($val) ?: '';
        },
    ]);

    register_setting('eventadmin_plugin_settings_communication', 'eventadmin_email_header_text_color', [
        'sanitize_callback' => static function ($val) {
            return sanitize_hex_color($val) ?: '#ffffff';
        },
    ]);

    register_setting('eventadmin_plugin_settings_communication', 'eventadmin_email_header_title', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);

    register_setting('eventadmin_plugin_settings_communication', 'eventadmin_email_header_subtitle', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);

    register_setting('eventadmin_plugin_settings_communication', 'eventadmin_email_footer_html', [
        'sanitize_callback' => 'wp_kses_post',
    ]);

    register_setting('eventadmin_plugin_settings_communication', 'eventadmin_email_custom_css', [
        'sanitize_callback' => 'wp_strip_all_tags',
    ]);

    register_setting('eventadmin_plugin_settings_communication', 'eventadmin_notification_email_name', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);

    register_setting('eventadmin_plugin_settings_communication', 'eventadmin_email_subject_assign', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);

    register_setting('eventadmin_plugin_settings_communication', 'eventadmin_email_subject_unassign', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);

    register_setting('eventadmin_plugin_settings_communication', 'eventadmin_email_text_assign', [
        'sanitize_callback' => 'wp_kses_post',
    ]);

    register_setting('eventadmin_plugin_settings_communication', 'eventadmin_email_text_unassign', [
        'sanitize_callback' => 'wp_kses_post',
    ]);

    register_setting('eventadmin_plugin_settings_communication', 'eventadmin_email_reminder_days', [
        'sanitize_callback' => 'eventadmin_sanitize_reminder_days',
    ]);

    register_setting('eventadmin_plugin_settings_communication', 'eventadmin_email_subject_reminder', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);

    register_setting('eventadmin_plugin_settings_communication', 'eventadmin_email_text_reminder', [
        'sanitize_callback' => 'wp_kses_post',
    ]);

    register_setting('eventadmin_plugin_settings_display', 'eventadmin_shift_date_format', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);

    register_setting('eventadmin_plugin_settings_display', 'eventadmin_shift_time_format', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);

    register_setting('eventadmin_plugin_settings_display', 'eventadmin_custom_css', [
        'sanitize_callback' => 'wp_strip_all_tags',
    ]);

    register_setting('eventadmin_plugin_settings_display', 'eventadmin_enabled_filters', [
        'sanitize_callback' => static function ($val) {
            $allowed = ['text_search', 'date_filter', 'category_filter'];
            return is_array($val) ? array_values(array_filter($val, static fn($v) => in_array($v, $allowed, true))) : [];
        },
    ]);

    add_settings_section(
        'eventadmin_general_registration',
        esc_html__('Registration', 'eventadmin-volunteer-management'),
        null,
        'eventadmin-settings-general'
    );

    add_settings_field(
        'eventadmin_suppress_wp_password_email',
        esc_html__('Suppress WordPress password email', 'eventadmin-volunteer-management'),
        static function () {
            echo '<input type="checkbox" name="eventadmin_suppress_wp_password_email" value="1" ' . checked(1, get_option('eventadmin_suppress_wp_password_email', 1), false) . '>';
            echo ' ' . esc_html__('Yes', 'eventadmin-volunteer-management');
            echo '<p class="description">' . esc_html__('If enabled, WordPress will not send the default "set your password" email to newly registered volunteers.', 'eventadmin-volunteer-management') . '</p>';
        },
        'eventadmin-settings-general',
        'eventadmin_general_registration'
    );

    add_settings_section(
        'eventadmin_general_rules',
        esc_html__('Rules', 'eventadmin-volunteer-management'),
        null,
        'eventadmin-settings-general'
    );

    $limits = [
        'eventadmin_limit_per_year'  => esc_html__('Max. shifts per year', 'eventadmin-volunteer-management'),
        'eventadmin_limit_per_month' => esc_html__('Max. shifts per month', 'eventadmin-volunteer-management'),
        'eventadmin_limit_per_week'  => esc_html__('Max. shifts per week', 'eventadmin-volunteer-management'),
        'eventadmin_limit_per_day'   => esc_html__('Max. shifts per day', 'eventadmin-volunteer-management'),
    ];

    foreach ($limits as $key => $label) {
        register_setting('eventadmin_plugin_settings_general', $key, [
            'sanitize_callback' => 'absint',
        ]);

        add_settings_field(
            $key,
            $label,
            static function () use ($key) {
                echo '<input type="number" name="' . esc_attr($key) . '" value="' . esc_attr(get_option($key)) . '" min="0">';
            },
            'eventadmin-settings-general',
            'eventadmin_general_rules'
        );
    }

    add_settings_field(
        'eventadmin_allow_overlap',
        esc_html__('Allow overlapping shifts', 'eventadmin-volunteer-management'),
        static function () {
            echo '<input type="checkbox" name="eventadmin_allow_overlap" value="1" ' . checked(1, get_option('eventadmin_allow_overlap'), false) . '>';
            echo ' ' . esc_html__('Yes', 'eventadmin-volunteer-management');
        },
        'eventadmin-settings-general',
        'eventadmin_general_rules'
    );

    add_settings_field(
        'eventadmin_unassign_limit_hours',
        esc_html__('Sign out possible up to X hours before start', 'eventadmin-volunteer-management'),
        static function () {
            echo '<input type="number" name="eventadmin_unassign_limit_hours" value="' . esc_attr(get_option('eventadmin_unassign_limit_hours', 0)) . '" min="0"> ' . esc_html__('hours', 'eventadmin-volunteer-management');
        },
        'eventadmin-settings-general',
        'eventadmin_general_rules'
    );

    register_setting('eventadmin_plugin_settings_general', 'eventadmin_captcha_provider', [
        'sanitize_callback' => static function ($val) {
            return in_array($val, ['none', 'recaptcha_v2', 'recaptcha_v3', 'hcaptcha', 'cf_turnstile'], true) ? $val : 'none';
        },
    ]);

    register_setting('eventadmin_plugin_settings_general', 'eventadmin_captcha_v3_threshold', [
        'sanitize_callback' => static function ($val) {
            $f = (float) $val;
            return ($f >= 0.0 && $f <= 1.0) ? $f : 0.5;
        },
    ]);

    register_setting('eventadmin_plugin_settings_general', 'eventadmin_captcha_site_key', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);

    register_setting('eventadmin_plugin_settings_general', 'eventadmin_captcha_secret_key', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);

    add_settings_section(
        'eventadmin_general_security',
        esc_html__('Security', 'eventadmin-volunteer-management'),
        null,
        'eventadmin-settings-general'
    );

    add_settings_field(
        'eventadmin_captcha_provider',
        esc_html__('CAPTCHA provider', 'eventadmin-volunteer-management'),
        static function () {
            $val     = get_option('eventadmin_captcha_provider', 'none');
            $options = [
                'none'         => esc_html__('None', 'eventadmin-volunteer-management'),
                'cf_turnstile' => 'Cloudflare Turnstile',
                'recaptcha_v2' => 'Google reCAPTCHA v2',
                'recaptcha_v3' => 'Google reCAPTCHA v3',
                'hcaptcha'     => 'hCaptcha',
            ];
            echo '<select name="eventadmin_captcha_provider">';
            foreach ($options as $k => $label) {
                echo '<option value="' . esc_attr($k) . '" ' . selected($val, $k, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
            echo '<p class="description">' . esc_html__('Adds a CAPTCHA challenge to the volunteer registration form to block automated spam registrations.', 'eventadmin-volunteer-management') . '</p>';

            if ($val === 'cf_turnstile' && !function_exists('cfturnstile_field_show')) {
                echo '<p class="description" style="color:#b32d2e;">' . sprintf(
                /* translators: %s = link to the Simple Cloudflare Turnstile plugin on WordPress.org */
                    esc_html__('Requires the free %s plugin to be installed and activated.', 'eventadmin-volunteer-management'),
                    '<a href="https://wordpress.org/plugins/simple-cloudflare-turnstile/" target="_blank" rel="noopener noreferrer">Simple CAPTCHA with Cloudflare Turnstile</a>'
                ) . '</p>';
            } elseif ($val === 'cf_turnstile' && (empty(get_option('cfturnstile_key', '')) || empty(get_option('cfturnstile_secret', '')))) {
                echo '<p class="description" style="color:#b32d2e;">' . esc_html__('The Simple Cloudflare Turnstile plugin is active but has no site key/secret key configured yet. Until it is configured, registration is blocked for everyone.', 'eventadmin-volunteer-management') . '</p>';
            }
        },
        'eventadmin-settings-general',
        'eventadmin_general_security'
    );

    add_settings_field(
        'eventadmin_captcha_site_key',
        esc_html__('CAPTCHA site key', 'eventadmin-volunteer-management'),
        static function () {
            echo '<input type="text" name="eventadmin_captcha_site_key" value="' . esc_attr(get_option('eventadmin_captcha_site_key', '')) . '" class="regular-text">';
            echo '<p class="description">' . esc_html__('Get your keys from the provider\'s admin console. Not used for Cloudflare Turnstile — configure its site key in the Simple Cloudflare Turnstile plugin settings instead.', 'eventadmin-volunteer-management') . '</p>';
        },
        'eventadmin-settings-general',
        'eventadmin_general_security'
    );

    add_settings_field(
        'eventadmin_captcha_secret_key',
        esc_html__('CAPTCHA secret key', 'eventadmin-volunteer-management'),
        static function () {
            echo '<input type="text" name="eventadmin_captcha_secret_key" value="' . esc_attr(get_option('eventadmin_captcha_secret_key', '')) . '" class="regular-text">';
            echo '<p class="description">' . esc_html__('Not used for Cloudflare Turnstile — configure its secret key in the Simple Cloudflare Turnstile plugin settings instead.', 'eventadmin-volunteer-management') . '</p>';
        },
        'eventadmin-settings-general',
        'eventadmin_general_security'
    );

    add_settings_field(
        'eventadmin_captcha_v3_threshold',
        esc_html__('reCAPTCHA v3 score threshold', 'eventadmin-volunteer-management'),
        static function () {
            $val = get_option('eventadmin_captcha_v3_threshold', 0.5);
            echo '<input type="number" name="eventadmin_captcha_v3_threshold" value="' . esc_attr($val) . '" min="0" max="1" step="0.1" style="width:70px">';
            echo '<p class="description">' . esc_html__('Scores below this value are treated as bots (0.0 = all pass, 1.0 = all blocked). Default: 0.5. Only applies to reCAPTCHA v3.', 'eventadmin-volunteer-management') . '</p>';
        },
        'eventadmin-settings-general',
        'eventadmin_general_security'
    );

    add_settings_section(
        'eventadmin_display_shift_selector',
        esc_html__('Volunteer Shift Selector', 'eventadmin-volunteer-management'),
        null,
        'eventadmin-settings-display'
    );

    add_settings_field(
        'eventadmin_show_full_shifts',
        esc_html__('Show full shifts to volunteers', 'eventadmin-volunteer-management'),
        static function () {
            echo '<input type="checkbox" name="eventadmin_show_full_shifts" value="1" ' . checked(1, get_option('eventadmin_show_full_shifts', 0), false) . '>';
            echo ' ' . esc_html__('Yes', 'eventadmin-volunteer-management');
            echo '<p class="description">' . esc_html__('If enabled, a "Full shifts" section is shown on the volunteer shift selector page.', 'eventadmin-volunteer-management') . '</p>';
        },
        'eventadmin-settings-display',
        'eventadmin_display_shift_selector'
    );

    add_settings_field(
        'eventadmin_enabled_filters',
        esc_html__('Volunteer shift filters', 'eventadmin-volunteer-management'),
        static function () {
            $enabled = (array) get_option('eventadmin_enabled_filters', ['text_search', 'date_filter', 'category_filter']);
            $options = [
                'text_search'     => esc_html__('Text search', 'eventadmin-volunteer-management'),
                'date_filter'     => esc_html__('Date filter', 'eventadmin-volunteer-management'),
                'category_filter' => esc_html__('Category filter', 'eventadmin-volunteer-management'),
            ];

            foreach ($options as $key => $label) {
                $checked = in_array($key, $enabled, true) ? 'checked' : '';
                echo '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="eventadmin_enabled_filters[]" value="' . esc_attr($key) . '" ' . $checked . '> ' . $label . '</label>';
            }

            echo '<p class="description">' . esc_html__('Choose which filter controls appear on the volunteer shift selector.', 'eventadmin-volunteer-management') . '</p>';
        },
        'eventadmin-settings-display',
        'eventadmin_display_shift_selector'
    );

    add_settings_section(
        'eventadmin_display_formatting',
        esc_html__('Formatting', 'eventadmin-volunteer-management'),
        null,
        'eventadmin-settings-display'
    );

    add_settings_field(
        'eventadmin_shift_date_format',
        esc_html__('Date format (shift start)', 'eventadmin-volunteer-management'),
        static function () {
            $val     = get_option('eventadmin_shift_date_format', 'l, j. F Y, H:i');
            $example = date_i18n($val, mktime(8, 0, 0, 1, 5, 2026));
            echo '<input type="text" name="eventadmin_shift_date_format" value="' . esc_attr($val) . '" class="regular-text">';
            echo '<p class="description">';
            echo esc_html__('Example:', 'eventadmin-volunteer-management') . ' <strong>' . esc_html($example) . '</strong><br>';
            echo esc_html__('Default:', 'eventadmin-volunteer-management') . ' <code>l, j. F Y, H:i</code><br>';
            echo '<details style="margin-top:4px"><summary style="cursor:pointer">' . esc_html__('Common tokens', 'eventadmin-volunteer-management') . '</summary>';
            echo '<code>l</code> = Monday &nbsp; <code>D</code> = Mon &nbsp; <code>j</code> = 5 &nbsp; <code>d</code> = 05 &nbsp; <code>F</code> = January &nbsp; <code>M</code> = Jan &nbsp; <code>Y</code> = 2026 &nbsp; <code>y</code> = 26 &nbsp; <code>H</code> = 08 &nbsp; <code>G</code> = 8 &nbsp; <code>h</code> = 08 &nbsp; <code>i</code> = 00 &nbsp; <code>A</code> = AM</details>';
            echo '</p>';
        },
        'eventadmin-settings-display',
        'eventadmin_display_formatting'
    );

    add_settings_field(
        'eventadmin_shift_time_format',
        esc_html__('Time format (shift end)', 'eventadmin-volunteer-management'),
        static function () {
            $val     = get_option('eventadmin_shift_time_format', 'H:i');
            $example = date_i18n($val, mktime(16, 30, 0, 1, 5, 2026));
            echo '<input type="text" name="eventadmin_shift_time_format" value="' . esc_attr($val) . '" class="regular-text">';
            echo '<p class="description">';
            echo esc_html__('Example:', 'eventadmin-volunteer-management') . ' <strong>' . esc_html($example) . '</strong><br>';
            echo esc_html__('Default:', 'eventadmin-volunteer-management') . ' <code>H:i</code><br>';
            echo '<details style="margin-top:4px"><summary style="cursor:pointer">' . esc_html__('Common tokens', 'eventadmin-volunteer-management') . '</summary>';
            echo '<code>H</code> = 16 &nbsp; <code>G</code> = 16 &nbsp; <code>h</code> = 04 &nbsp; <code>g</code> = 4 &nbsp; <code>i</code> = 30 &nbsp; <code>A</code> = PM &nbsp; <code>a</code> = pm</details>';
            echo '</p>';
        },
        'eventadmin-settings-display',
        'eventadmin_display_formatting'
    );

    add_settings_field(
        'eventadmin_custom_css',
        esc_html__('Custom CSS', 'eventadmin-volunteer-management'),
        static function () {
            $val = get_option('eventadmin_custom_css', '');
            echo '<textarea name="eventadmin_custom_css" rows="10" class="large-text code">' . esc_textarea($val) . '</textarea>';
            echo '<p class="description">' . esc_html__('Additional CSS injected on the frontend. Use this for theme-specific adjustments without modifying your theme files.', 'eventadmin-volunteer-management') . '</p>';
        },
        'eventadmin-settings-display',
        'eventadmin_display_formatting'
    );

    add_settings_section(
        'eventadmin_communication_sender',
        esc_html__('Sender', 'eventadmin-volunteer-management'),
        null,
        'eventadmin-settings-communication'
    );

    add_settings_field(
        'eventadmin_notification_email_name',
        esc_html__('Name', 'eventadmin-volunteer-management'),
        static function () {
            echo '<input type="text" name="eventadmin_notification_email_name" value="' . esc_attr(get_option('eventadmin_notification_email_name', '')) . '" class="regular-text">';
        },
        'eventadmin-settings-communication',
        'eventadmin_communication_sender'
    );

    add_settings_field(
        'eventadmin_notification_email',
        esc_html__('E-Mail', 'eventadmin-volunteer-management'),
        static function () {
            echo '<input type="email" name="eventadmin_notification_email" value="' . esc_attr(get_option('eventadmin_notification_email', '')) . '" class="regular-text">';
        },
        'eventadmin-settings-communication',
        'eventadmin_communication_sender'
    );

    add_settings_section(
        'eventadmin_communication_design',
        esc_html__('Email Design', 'eventadmin-volunteer-management'),
        static function () {
            echo '<p>' . esc_html__('These apply to every email the plugin sends — sign-up confirmations, reminders, and announcements.', 'eventadmin-volunteer-management') . '</p>';
        },
        'eventadmin-settings-communication'
    );

    add_settings_field(
        'eventadmin_email_header_color',
        esc_html__('Header color', 'eventadmin-volunteer-management'),
        static function () {
            $val     = get_option('eventadmin_email_header_color', '');
            $checked = $val !== '';
            echo '<label><input type="checkbox" name="eventadmin_email_header_color_enabled" value="1" id="eventadmin-email-header-color-toggle"' . checked($checked, true, false) . '> ' . esc_html__('Use a custom color', 'eventadmin-volunteer-management') . '</label><br>';
            echo '<input type="color" name="eventadmin_email_header_color" value="' . esc_attr($val ?: '#1d4e78') . '" id="eventadmin-email-header-color-input"' . ($checked ? '' : ' disabled') . '>';
            echo '<p class="description">' . esc_html__('Default is a dark blue gradient.', 'eventadmin-volunteer-management') . '</p>';
        },
        'eventadmin-settings-communication',
        'eventadmin_communication_design'
    );

    add_settings_field(
        'eventadmin_email_header_text_color',
        esc_html__('Header text color', 'eventadmin-volunteer-management'),
        static function () {
            $val = get_option('eventadmin_email_header_text_color', '#ffffff');
            echo '<input type="color" name="eventadmin_email_header_text_color" value="' . esc_attr($val) . '">';
            echo '<p class="description">' . esc_html__('Choose a color that stays readable against your header color/logo.', 'eventadmin-volunteer-management') . '</p>';
        },
        'eventadmin-settings-communication',
        'eventadmin_communication_design'
    );

    add_settings_field(
        'eventadmin_email_header_title',
        esc_html__('Header title', 'eventadmin-volunteer-management'),
        static function () {
            $val = get_option('eventadmin_email_header_title', get_bloginfo('name'));
            echo '<input type="text" name="eventadmin_email_header_title" value="' . esc_attr($val) . '" class="regular-text">';
            echo '<p class="description">' . esc_html__('Leave empty to hide this line.', 'eventadmin-volunteer-management') . '</p>';
        },
        'eventadmin-settings-communication',
        'eventadmin_communication_design'
    );

    add_settings_field(
        'eventadmin_email_header_subtitle',
        esc_html__('Header subtitle', 'eventadmin-volunteer-management'),
        static function () {
            $val = get_option('eventadmin_email_header_subtitle', get_bloginfo('description'));
            echo '<input type="text" name="eventadmin_email_header_subtitle" value="' . esc_attr($val) . '" class="regular-text">';
            echo '<p class="description">' . esc_html__('Leave empty to hide this line.', 'eventadmin-volunteer-management') . '</p>';
        },
        'eventadmin-settings-communication',
        'eventadmin_communication_design'
    );

    add_settings_field(
        'eventadmin_email_header_logo_id',
        esc_html__('Header logo', 'eventadmin-volunteer-management'),
        'eventadmin_render_email_logo_field',
        'eventadmin-settings-communication',
        'eventadmin_communication_design'
    );

    add_settings_field(
        'eventadmin_email_footer_html',
        esc_html__('Footer', 'eventadmin-volunteer-management'),
        'eventadmin_render_email_footer_field',
        'eventadmin-settings-communication',
        'eventadmin_communication_design'
    );

    add_settings_field(
        'eventadmin_email_custom_css',
        esc_html__('Custom CSS', 'eventadmin-volunteer-management'),
        static function () {
            $val = get_option('eventadmin_email_custom_css', '');
            echo '<textarea name="eventadmin_email_custom_css" id="eventadmin_email_custom_css" rows="6" class="large-text code">' . esc_textarea($val) . '</textarea>';
            echo '<p class="description">' . esc_html__('Advanced: extra CSS added to every email. Support varies a lot by email client (e.g. Outlook ignores it entirely) — use it for minor tweaks, not anything essential.', 'eventadmin-volunteer-management') . '</p>';
        },
        'eventadmin-settings-communication',
        'eventadmin_communication_design'
    );

    add_settings_section(
        'eventadmin_communication_templates',
        esc_html__('Volunteer Notifications', 'eventadmin-volunteer-management'),
        null,
        'eventadmin-settings-communication'
    );

    add_settings_field(
        'eventadmin_email_subject_assign',
        esc_html__('Email Subject (Sign up)', 'eventadmin-volunteer-management'),
        static function () {
            $val = get_option('eventadmin_email_subject_assign', 'Confirmation: You have been registered for \'{title}\'');
            echo '<input type="text" name="eventadmin_email_subject_assign" value="' . esc_attr($val) . '" class="regular-text">';
        },
        'eventadmin-settings-communication',
        'eventadmin_communication_templates'
    );

    add_settings_field(
        'eventadmin_email_text_assign',
        esc_html__('Email Text (Sign up)', 'eventadmin-volunteer-management'),
        static function () {
            eventadmin_render_email_text_field(
                'eventadmin_email_text_assign',
                "Dear {first},\n\nThank you for volunteering at the event.\nYour shift:\n\n<b>{title}</b>\n<i>{start} – {end}</i>\n{desc}\n\nPlease arrive 20 minutes early."
            );
        },
        'eventadmin-settings-communication',
        'eventadmin_communication_templates'
    );

    add_settings_field(
        'eventadmin_email_subject_unassign',
        esc_html__('Email Subject (Sign out)', 'eventadmin-volunteer-management'),
        static function () {
            $val = get_option('eventadmin_email_subject_unassign', 'Confirmation: You have been removed from \'{title}\'');
            echo '<input type="text" name="eventadmin_email_subject_unassign" value="' . esc_attr($val) . '" class="regular-text">';
        },
        'eventadmin-settings-communication',
        'eventadmin_communication_templates'
    );

    add_settings_field(
        'eventadmin_email_text_unassign',
        esc_html__('Email Text (Sign out)', 'eventadmin-volunteer-management'),
        static function () {
            eventadmin_render_email_text_field(
                'eventadmin_email_text_unassign',
                "Dear {first},\n\nYou have successfully signed out from:\n\n<b>{title}</b>\n<i>{start} – {end}</i>\n{desc}\n\nThank you for your update!"
            );
        },
        'eventadmin-settings-communication',
        'eventadmin_communication_templates'
    );

    add_settings_section(
        'eventadmin_communication_reminders',
        esc_html__('Shift Reminders', 'eventadmin-volunteer-management'),
        null,
        'eventadmin-settings-communication'
    );

    add_settings_field(
        'eventadmin_email_reminder_days',
        esc_html__('Send reminder X days before start', 'eventadmin-volunteer-management'),
        static function () {
            $val = get_option('eventadmin_email_reminder_days', '7, 1');
            echo '<input type="text" name="eventadmin_email_reminder_days" value="' . esc_attr($val) . '" class="regular-text">';
            echo '<p class="description">' . esc_html__('Comma-separated whole days, e.g. 7, 1. Negative or zero values are ignored. Leave empty to turn reminder emails off entirely.', 'eventadmin-volunteer-management') . '</p>';
        },
        'eventadmin-settings-communication',
        'eventadmin_communication_reminders'
    );

    add_settings_field(
        'eventadmin_email_subject_reminder',
        esc_html__('Email Subject (Reminder)', 'eventadmin-volunteer-management'),
        static function () {
            $val = get_option('eventadmin_email_subject_reminder', 'Reminder: Your shift \'{title}\' starts in {days} day(s)');
            echo '<input type="text" name="eventadmin_email_subject_reminder" value="' . esc_attr($val) . '" class="regular-text">';
        },
        'eventadmin-settings-communication',
        'eventadmin_communication_reminders'
    );

    add_settings_field(
        'eventadmin_email_text_reminder',
        esc_html__('Email Text (Reminder)', 'eventadmin-volunteer-management'),
        static function () {
            eventadmin_render_email_text_field(
                'eventadmin_email_text_reminder',
                "Dear {first},\n\nThis is a reminder that your shift starts in {days} day(s).\n\n<b>{title}</b>\n<i>{start} – {end}</i>\n{desc}\n\nThank you for your support."
            );
        },
        'eventadmin-settings-communication',
        'eventadmin_communication_reminders'
    );
}

add_action('admin_init', 'eventadmin_plugin_register_settings');

/**
 * Renders the email header logo picker (media library, restricted to images).
 */
function eventadmin_render_email_logo_field(): void
{
    $logo_id  = (int) get_option('eventadmin_email_header_logo_id', 0);
    $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';

    echo '<div id="eventadmin-email-logo-preview" style="margin-bottom:8px;' . ($logo_url ? '' : 'display:none;') . '">';
    echo '<img src="' . esc_url($logo_url) . '" alt="" style="max-width:220px;max-height:80px;display:block;border:1px solid #dcdcde;padding:4px;background:#fff;">';
    echo '</div>';
    echo '<input type="hidden" id="eventadmin_email_header_logo_id" name="eventadmin_email_header_logo_id" value="' . esc_attr($logo_id) . '">';
    echo '<button type="button" id="eventadmin-email-logo-button" class="button">' . esc_html__('Select logo…', 'eventadmin-volunteer-management') . '</button> ';
    echo '<button type="button" id="eventadmin-email-logo-remove" class="button" style="' . ($logo_id ? '' : 'display:none;') . '">' . esc_html__('Remove', 'eventadmin-volunteer-management') . '</button>';
    echo '<p class="description">' . esc_html__('Shown at the top of every email instead of the site name. Recommended: a wide image with a transparent background, under 200px tall.', 'eventadmin-volunteer-management') . '</p>';
}

/**
 * Renders the email footer rich-text field (bold/links/images, no raw HTML required).
 */
function eventadmin_render_email_footer_field(): void
{
    $default_footer = sprintf(
        /* translators: %s = site name */
        esc_html__('This email was sent by %s.', 'eventadmin-volunteer-management'),
        wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
    );

    wp_editor(get_option('eventadmin_email_footer_html', ''), 'eventadmin_email_footer_html', [
        'textarea_name' => 'eventadmin_email_footer_html',
        'textarea_rows' => 6,
        'media_buttons' => true,
        'teeny'         => true,
        'quicktags'     => false,
    ]);
    echo '<p class="description">' . sprintf(
        /* translators: %s is the default footer text that will be used if this field is left empty */
        esc_html__('Shown at the bottom of every email. Leave empty to use the default: "%s"', 'eventadmin-volunteer-management'),
        esc_html($default_footer)
    ) . '</p>';
}

/**
 * Renders a rich-text editor for an email body template (bold/links/images, no raw HTML
 * required), shared by the Sign up / Sign out / Reminder text fields.
 *
 * @param string $option_name
 * @param string $default
 * @return void
 */
function eventadmin_render_email_text_field(string $option_name, string $default): void
{
    wp_editor(get_option($option_name, $default), $option_name, [
        'textarea_name' => $option_name,
        'textarea_rows' => 8,
        'media_buttons' => true,
        'teeny'         => true,
        'quicktags'     => false,
    ]);
}

/**
 * Renders the live preview of email notifications.
 */
function eventadmin_plugin_preview_field(): void
{
    // Each preview belongs to one Communication sub-tab (see
    // eventadmin_render_communication_subtabs()) — only that sub-tab's own preview(s) are
    // relevant, so only they're shown, kept in sync via the same JS that toggles the
    // sub-tab panels themselves.
    $actions = [
        // "Sign up" doubles as General's one example preview, since General has no email
        // template of its own but does control the shared header/footer/logo/colors.
        'assign'   => ['label' => esc_html__('Sign up', 'eventadmin-volunteer-management'), 'subtabs' => ['general', 'confirmations']],
        'unassign' => ['label' => esc_html__('Sign out', 'eventadmin-volunteer-management'), 'subtabs' => ['confirmations']],
        'reminder' => ['label' => esc_html__('Reminder', 'eventadmin-volunteer-management'), 'subtabs' => ['reminders']],
    ];

    $active_subtab = isset($_GET['subtab']) && in_array(sanitize_key(wp_unslash($_GET['subtab'])), ['general', 'confirmations', 'reminders'], true)
        ? sanitize_key(wp_unslash($_GET['subtab']))
        : 'general';

    echo '<p><strong>' . esc_html__('Live preview of emails (with example values):', 'eventadmin-volunteer-management') . '</strong></p>';

    foreach ($actions as $key => $action) {
        $hidden = !in_array($active_subtab, $action['subtabs'], true);
        echo '<div class="preview-block" data-subtab-preview="' . esc_attr(implode(' ', $action['subtabs'])) . '"' . ($hidden ? ' style="display:none;"' : '') . '>';
        echo '<h4>' . esc_html($action['label']) . '</h4>';
        echo '<p><strong>' . esc_html__('Subject:', 'eventadmin-volunteer-management') . '</strong> <span id="preview-subject-' . esc_attr($key) . '"></span></p>';
        echo '<iframe id="preview-body-' . esc_attr($key) . '" class="preview-body" title="' . esc_attr($action['label']) . '"></iframe>';
        echo '</div>';
    }
}

/**
 * Enqueue scripts and styles for the settings pages.
 */
function eventadmin_admin_enqueue_settings_scripts(): void
{
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'eventadmin_shift_page_eventadmin-settings') {
        return;
    }

    wp_enqueue_style(
        'eventadmin-admin-settings',
        plugin_dir_url(__FILE__) . '../../assets/css/settings.css',
        [],
        '1.0'
    );

    wp_enqueue_script(
        'eventadmin-admin-settings',
        plugin_dir_url(__FILE__) . '../../assets/js/settings.js',
        ['media-editor'],
        '1.0',
        true
    );

    $example_ts     = mktime(8, 0, 0, 6, 16, 2026);
    $example_end_ts = mktime(22, 0, 0, 6, 16, 2026);

    wp_enqueue_media();

    $defaults = eventadmin_get_option_defaults();
    wp_localize_script('eventadmin-admin-settings', 'EVENTADMIN_SETTINGS', [
        'ajax_url'          => admin_url('admin-ajax.php'),
        'nonce'             => wp_create_nonce('eventadmin_preview_date_format'),
        'preview_nonce'     => wp_create_nonce('eventadmin_email_preview'),
        'i18n'              => [
            'selectLogoTitle'  => esc_html__('Select a logo image', 'eventadmin-volunteer-management'),
            'selectLogoButton' => esc_html__('Use this image', 'eventadmin-volunteer-management'),
        ],
        'start_label' => date_i18n(
            get_option('eventadmin_shift_date_format', $defaults['eventadmin_shift_date_format']) ?: $defaults['eventadmin_shift_date_format'],
            $example_ts
        ),
        'end_label'   => date_i18n(
            get_option('eventadmin_shift_time_format', $defaults['eventadmin_shift_time_format']) ?: $defaults['eventadmin_shift_time_format'],
            $example_end_ts
        ),
        'days_label'  => '7',
    ]);
}

add_action('admin_enqueue_scripts', 'eventadmin_admin_enqueue_settings_scripts');

/**
 * AJAX: return PHP-formatted example dates for the live preview.
 */
function eventadmin_preview_date_format_handler(): void
{
    check_ajax_referer('eventadmin_preview_date_format', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error();
    }

    $date_format = sanitize_text_field(wp_unslash($_POST['date_format'] ?? 'l, j. F Y, H:i'));
    $time_format = sanitize_text_field(wp_unslash($_POST['time_format'] ?? 'H:i'));

    wp_send_json_success([
        'start' => date_i18n($date_format, mktime(8, 0, 0, 6, 16, 2026)),
        'end'   => date_i18n($time_format, mktime(22, 0, 0, 6, 16, 2026)),
    ]);
}

add_action('wp_ajax_eventadmin_preview_date_format', 'eventadmin_preview_date_format_handler');

<?php
/**
 * EventAdmin Volunteer Management - Shift cockpit for volunteers
 * Sign up and sign out of shifts, profile management and more.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Shortcode for the EventAdmin Cockpit
 * Shows the tabs for shifts and profile
 *
 * @return bool|string HTML output of the cockpit
 */
function eventadmin_cockpit_shortcode(): bool|string
{
    if (!is_user_logged_in()) return esc_html__('Please log in first.', 'eventadmin-volunteer-management');

    $user_id = get_current_user_id();
    $has_phone = get_user_meta($user_id, 'eventadmin_phone', true);
    $phone_warning = empty($has_phone) ? ' <span title="' . esc_html__('Phone number missing', 'eventadmin-volunteer-management') . '">⚠️</span>' : '';

    ob_start(); ?>
    <div class="eventadmin-tabs">
        <div class="tab-nav">
            <div class="tab-item active"
                 data-tab="einsaetze"><?php esc_html_e('📋 Shifts', 'eventadmin-volunteer-management'); ?></div>
            <div class="tab-item"
                 data-tab="profil"><?php echo esc_html__('👤 Profile', 'eventadmin-volunteer-management') . wp_kses($phone_warning, [
                        'span' => ['title' => []],
                    ]); ?>
            </div>
        </div>

        <div class="tab-panel" id="einsaetze"><?php echo do_shortcode('[eventadmin_shiftselector]'); ?></div>
        <div class="tab-panel" id="profil"
             style="display: none;"><?php echo do_shortcode('[eventadmin_profile]'); ?></div>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('eventadmin_cockpit', 'eventadmin_cockpit_shortcode');

/**
 * Enqueue styles and scripts for the EventAdmin Cockpit
 * @return void
 */
function eventadmin_enqueue_cockpit_scripts(): void
{
    $css_path = plugin_dir_path(__FILE__) . '../assets/css/cockpit.css';

    $js_path = plugin_dir_path(__FILE__) . '../assets/js/cockpit.js';

    wp_enqueue_script(
        'eventadmin-cockpit-js',
        plugin_dir_url(__FILE__) . '../assets/js/cockpit.js',
        ['jquery'],
        file_exists($js_path) ? filemtime($js_path) : null,
        true
    );

    wp_enqueue_style(
        'eventadmin-cockpit-css',
        plugin_dir_url(__FILE__) . '../assets/css/cockpit.css',
        [],
        file_exists($css_path) ? filemtime($css_path) : null
    );

    wp_localize_script('eventadmin-cockpit-js', 'eventadmin_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('eventadmin_assign_shift'),
        'unassign_limit' => get_option('eventadmin_unassign_limit_hours', 0),
        'i18n' => [
            'assign' => esc_html__('Assign', 'eventadmin-volunteer-management'),
            'unassign' => esc_html__('Unassign', 'eventadmin-volunteer-management'),
            'unknown_error' => esc_html__('Unknown error', 'eventadmin-volunteer-management'),
            'unassign_disabled' => sprintf(
                /* translators: %d = hours before shift start */
                esc_html__('Unassignment not possible from %d hours before start', 'eventadmin-volunteer-management'),
                (int) get_option('eventadmin_unassign_limit_hours', 0)
            ),
        ],
    ]);

    $custom_css = get_option('eventadmin_custom_css', '');
    if (!empty(trim($custom_css))) {
        wp_add_inline_style('eventadmin-cockpit-css', $custom_css);
    }
}

add_action('wp_enqueue_scripts', 'eventadmin_enqueue_cockpit_scripts');

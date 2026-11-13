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
 * @param array|string $atts Optional department="slug,slug" — only offer shifts from these
 *                           departments (and their sub-departments) on this page.
 * @return bool|string HTML output of the cockpit
 */
function eventadmin_cockpit_shortcode($atts = []): bool|string
{
    if (!is_user_logged_in()) return esc_html__('Please log in first.', 'eventadmin-volunteer-management');

    $user_id = get_current_user_id();
    $has_phone = get_user_meta($user_id, 'eventadmin_phone', true);

    ob_start(); ?>
    <?php
    // "Log in with a password next time?" — only when password login is enabled and the
    // volunteer hasn't set one or declined (see includes/password-login.php).
    echo eventadmin_render_password_prompt(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside
    ?>
    <?php $tabs_id = wp_unique_id('eventadmin-tabs-'); ?>
    <div class="eventadmin-tabs">
        <div class="tab-nav" role="tablist" aria-label="<?php esc_attr_e('Volunteer dashboard', 'eventadmin-volunteer-management'); ?>">
            <button type="button" class="tab-item active" role="tab" id="<?php echo esc_attr($tabs_id . 'shifts-tab'); ?>" aria-controls="<?php echo esc_attr($tabs_id . 'shifts'); ?>" aria-selected="true" data-tab="<?php echo esc_attr($tabs_id . 'shifts'); ?>"><?php esc_html_e('Shifts', 'eventadmin-volunteer-management'); ?></button>
            <button type="button" class="tab-item" role="tab" id="<?php echo esc_attr($tabs_id . 'profile-tab'); ?>" aria-controls="<?php echo esc_attr($tabs_id . 'profile'); ?>" aria-selected="false" tabindex="-1" data-tab="<?php echo esc_attr($tabs_id . 'profile'); ?>"><?php esc_html_e('Profile', 'eventadmin-volunteer-management'); ?><?php if (empty($has_phone)) : ?> <span class="eventadmin-profile-warning"><?php esc_html_e('Phone number missing', 'eventadmin-volunteer-management'); ?></span><?php endif; ?></button>
        </div>
        <div class="tab-panel" role="tabpanel" id="<?php echo esc_attr($tabs_id . 'shifts'); ?>" aria-labelledby="<?php echo esc_attr($tabs_id . 'shifts-tab'); ?>"><?php echo do_shortcode('[eventadmin_shiftselector' . eventadmin_department_shortcode_passthrough($atts) . ']'); ?></div>
        <div class="tab-panel" role="tabpanel" id="<?php echo esc_attr($tabs_id . 'profile'); ?>" aria-labelledby="<?php echo esc_attr($tabs_id . 'profile-tab'); ?>" hidden><?php echo do_shortcode('[eventadmin_profile]'); ?></div>
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
            'assign' => esc_html__('Sign up for this shift', 'eventadmin-volunteer-management'),
            'unassign' => esc_html__('Cancel my sign-up', 'eventadmin-volunteer-management'),
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

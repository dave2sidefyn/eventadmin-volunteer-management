<?php
/**
 * Plugin Name:       EventAdmin – Volunteer Management
 * Description:       Manage volunteers for events directly in WordPress. Create and schedule shifts, allow volunteers to sign up and cancel independently, and configure individual rules – e.g., maximum shifts per person per year.
 * Version:           1.7.1
 * Author:            David Wiedmer, sidefyn GmbH
 * Author URI:        https://profiles.wordpress.org/davesidefyn/
 * Requires at least: 5.8
 * Requires PHP:      8.0
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       eventadmin-volunteer-management
 * Domain Path:        /languages
 * Contributors:      davesidefyn
 */

defined('ABSPATH') or die('No script kiddies please!');

define('EVENTADMIN_VERSION', '1.7.1');
define('EVENTADMIN_REVIEW_URL', 'https://wordpress.org/plugins/eventadmin-volunteer-management/#reviews');
define('EVENTADMIN_DONATE_URL', 'https://revolut.me/davidwiedmer');

require_once plugin_dir_path(__FILE__) . 'includes/admin/dashboard.php';
require_once plugin_dir_path(__FILE__) . 'includes/post-types.php';
require_once plugin_dir_path(__FILE__) . 'includes/profile.php';
require_once plugin_dir_path(__FILE__) . 'includes/registration.php';
require_once plugin_dir_path(__FILE__) . 'includes/shiftselector.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers.php';
require_once plugin_dir_path(__FILE__) . 'includes/notifications.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/import.php';
require_once plugin_dir_path(__FILE__) . 'includes/cockpit.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/documentation.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/shift-metaboxes.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/category-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/quick-edit.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/bulk-email.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin/volunteer-list.php';


/**
 * Returns the default values for all plugin options.
 * Used by both the activation hook and the upgrade routine to fill in missing settings
 * without overwriting values the admin has already configured.
 *
 * @return array<string, mixed>
 */
function eventadmin_get_option_defaults(): array
{
    return [
        // General
        'eventadmin_suppress_wp_password_email' => 1,
        'eventadmin_allow_overlap'              => 0,
        'eventadmin_unassign_limit_hours'       => 0,
        'eventadmin_limit_per_year'             => 0,
        'eventadmin_limit_per_month'            => 0,
        'eventadmin_limit_per_week'             => 0,
        'eventadmin_limit_per_day'              => 0,
        // Display
        'eventadmin_show_full_shifts'           => 0,
        'eventadmin_shift_date_format'          => 'l, j. F Y, H:i',
        'eventadmin_shift_time_format'          => 'H:i',
        'eventadmin_custom_css'                 => '',
        'eventadmin_enabled_filters'            => ['text_search', 'date_filter', 'category_filter'],
        // Communication
        'eventadmin_notification_email'         => '',
        'eventadmin_notification_email_name'    => '',
        'eventadmin_email_subject_assign'       => "Confirmation: You have been registered for '{title}'",
        'eventadmin_email_subject_unassign'     => "Confirmation: You have been removed from '{title}'",
        'eventadmin_email_text_assign'          => "Dear {first},\n\nThank you for volunteering at the event.\nYour shift:\n\n<b>{title}</b>\n<i>{start} – {end}</i>\n{desc}\n\nPlease arrive 20 minutes early.",
        'eventadmin_email_text_unassign'        => "Dear {first},\n\nYou have successfully signed out from:\n\n<b>{title}</b>\n<i>{start} – {end}</i>\n{desc}\n\nThank you for your update!",
        'eventadmin_email_reminder_days'        => '7, 1',
        'eventadmin_email_subject_reminder'     => "Reminder: Your shift '{title}' starts in {days} day(s)",
        'eventadmin_email_text_reminder'        => "Dear {first},\n\nThis is a reminder that your shift starts in {days} day(s).\n\n<b>{title}</b>\n<i>{start} – {end}</i>\n{desc}\n\nThank you for your support.",
    ];
}

/**
 * Runs upgrade routines when the stored DB version differs from the current plugin version.
 * Only fills in missing options with their defaults — never overwrites existing values.
 *
 * @return void
 */
function eventadmin_run_upgrades(): void
{
    $stored_version = get_option('eventadmin_db_version', '');

    if ($stored_version === EVENTADMIN_VERSION) {
        return;
    }

    // Fill in any options that are missing (e.g. newly added settings or after accidental data loss).
    // add_option() is a no-op when the option already exists, so existing values are always preserved.
    foreach (eventadmin_get_option_defaults() as $key => $default) {
        add_option($key, $default);
    }

    // For options whose default is a non-empty string, restore from default when the stored value
    // was wiped to an empty string — empty format strings produce silent data loss in emails.
    // (Numeric/boolean options are left alone because 0 is a valid stored value.)
    $must_not_be_empty = [
        'eventadmin_shift_date_format' => 'l, j. F Y, H:i',
        'eventadmin_shift_time_format' => 'H:i',
    ];
    foreach ($must_not_be_empty as $key => $default) {
        if (get_option($key) === '') {
            update_option($key, $default);
        }
    }

    update_option('eventadmin_db_version', EVENTADMIN_VERSION);
}

add_action('plugins_loaded', 'eventadmin_run_upgrades');

/**
 * Activates the plugin: initialises the DB version and seeds missing options with defaults.
 *
 * @return void
 */
function eventadmin_plugin_activate(): void
{
    add_option('eventadmin_import_demo_data', false);

    foreach (eventadmin_get_option_defaults() as $key => $default) {
        add_option($key, $default);
    }

    add_option('eventadmin_db_version', EVENTADMIN_VERSION);
}

// Plugin activation
register_activation_hook(__FILE__, 'eventadmin_plugin_activate');

function eventadmin_plugin_deactivate(): void
{
    $timestamp = wp_next_scheduled('eventadmin_cleanup_unverified');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'eventadmin_cleanup_unverified');
    }

    $reminder_timestamp = wp_next_scheduled('eventadmin_send_shift_reminders');
    if ($reminder_timestamp) {
        wp_unschedule_event($reminder_timestamp, 'eventadmin_send_shift_reminders');
    }
}

register_deactivation_hook(__FILE__, 'eventadmin_plugin_deactivate');


/**
 * Adds a footer in the admin area displaying plugin information
 * @return void
 */
function eventadmin_admin_footer(): void
{
    $screen = get_current_screen();
    if (!isset($screen->id) || !str_contains($screen->id, 'eventadmin_shift')) return;

    echo '<div class="footer-info">';
    echo wp_kses_post(__('EventAdmin – Volunteer Management – developed by <a href="mailto:dave@sidefyn.ch">David Wiedmer, sidefyn GmbH</a>', 'eventadmin-volunteer-management'));
    echo '</div>';
}

add_action('admin_footer', 'eventadmin_admin_footer');

/**
 * Adds a volunteer role when a user registers
 * @param string $user_login The username of the registered user
 * @param WP_User $user The WP_User object of the registered user
 */
function eventadmin_custom_wp_login(string $user_login, WP_User $user): void
{
    $token = isset($_COOKIE['eventadmin_register_token']) ? sanitize_text_field(wp_unslash($_COOKIE['eventadmin_register_token'])) : '';
    $nonce = isset($_COOKIE['eventadmin_register_nonce']) ? sanitize_text_field(wp_unslash($_COOKIE['eventadmin_register_nonce'])) : '';

    if ($token && $nonce) {
        // Check nonce
        if (wp_verify_nonce($nonce, 'eventadmin_register_' . $token)) {
            if (!in_array('eventadmin_volunteer', $user->roles)) {
                if (in_array('administrator', $user->roles)) {
                    $user->add_role('eventadmin_volunteer');
                } else {
                    $user->set_role('eventadmin_volunteer');
                }
            }

            // Cleanup
            delete_transient('eventadmin_token_' . $token);
            setcookie('eventadmin_register_token', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
            setcookie('eventadmin_register_nonce', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
        }
    }
}

add_action('wp_login', 'eventadmin_custom_wp_login', 10, 2);

/**
 * Disables the admin bar for volunteers
 * @return void
 */
function eventadmin_after_setup_theme(): void
{
    if (current_user_can('eventadmin_volunteer') && !current_user_can('edit_posts')) {
        show_admin_bar(false);
    }
}

add_action('after_setup_theme', 'eventadmin_after_setup_theme');

/**
 * Enqueue scripts and styles for the admin page
 * @return void
 */
function eventadmin_admin_enqueue_main_scripts(): void
{
    wp_enqueue_style(
        'eventadmin-volunteer-management',
        plugin_dir_url(__FILE__) . 'assets/css/eventadmin-volunteer-management.css',
        [],
        '1.0'
    );
}

add_action('admin_enqueue_scripts', 'eventadmin_admin_enqueue_main_scripts');

add_action( 'plugins_loaded', function() {
    load_plugin_textdomain(
        'eventadmin-volunteer-management',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
} );

/**
 * Always use the bundled translation files from the plugin's own languages/
 * directory, preventing language packs from translate.wordpress.org from
 * silently overriding them with potentially incomplete community translations.
 */
add_filter( 'load_textdomain_mofile', function( string $mofile, string $domain ): string {
    if ( $domain !== 'eventadmin-volunteer-management' ) {
        return $mofile;
    }
    $bundled = plugin_dir_path( __FILE__ ) . 'languages/' . basename( $mofile );
    return file_exists( $bundled ) ? $bundled : $mofile;
}, 10, 2 );

/**
 * Adds donation and review links to the plugin row in the Plugins list
 */
function eventadmin_plugin_row_meta(array $links, string $file): array
{
    if (plugin_basename(__FILE__) !== $file) return $links;
    $links[] = '<a href="' . EVENTADMIN_REVIEW_URL . '" target="_blank">⭐ ' . esc_html__('Rate 5 stars', 'eventadmin-volunteer-management') . '</a>';
    $links[] = '<a href="' . EVENTADMIN_DONATE_URL . '" target="_blank">❤️ ' . esc_html__('Donate', 'eventadmin-volunteer-management') . '</a>';
    return $links;
}

add_filter('plugin_row_meta', 'eventadmin_plugin_row_meta', 10, 2);

/**
 * Shows a dismissible update notice to admins after a plugin update
 */
function eventadmin_update_notice(): void
{
    if (!current_user_can('manage_options')) return;

    $dismissed = get_user_meta(get_current_user_id(), 'eventadmin_dismissed_version', true);
    if ($dismissed === EVENTADMIN_VERSION) return;

    $nonce = wp_create_nonce('eventadmin_dismiss_notice');
    ?>
    <div id="eventadmin-update-notice" style="background:#fff;border-left:4px solid #2271b1;padding:16px 20px;margin:20px 0;box-shadow:0 1px 4px rgba(0,0,0,.08);display:flex;align-items:flex-start;gap:16px;max-width:800px;">
        <div style="font-size:28px;line-height:1;">🎉</div>
        <div style="flex:1;">
            <?php
            /* translators: %s is the plugin version number, e.g. "0.9.3" */
            $notice_title = sprintf(__('EventAdmin %s is here!', 'eventadmin-volunteer-management'), EVENTADMIN_VERSION);
            ?>
            <strong><?php echo esc_html($notice_title); ?></strong><br>
            <?php echo esc_html__('New in this release: fixed a bug where Administrators who also held the Volunteer role could lose access to wp-admin menu items on sites using a custom database table prefix, plus completed French and Dutch translations.', 'eventadmin-volunteer-management'); ?>
            <div style="margin-top:10px;">
                <a href="<?php echo esc_url(EVENTADMIN_REVIEW_URL); ?>" target="_blank" class="button button-primary" style="margin-right:8px;">⭐ <?php echo esc_html__('Rate 5 stars', 'eventadmin-volunteer-management'); ?></a>
                <a href="<?php echo esc_url(EVENTADMIN_DONATE_URL); ?>" target="_blank" class="button">❤️ <?php echo esc_html__('Donate', 'eventadmin-volunteer-management'); ?></a>
            </div>
        </div>
        <button type="button" id="eventadmin-dismiss-notice" style="background:none;border:none;cursor:pointer;font-size:20px;line-height:1;color:#888;padding:0;margin-left:8px;" title="<?php echo esc_attr__('Dismiss', 'eventadmin-volunteer-management'); ?>">&#x2715;</button>
    </div>
    <script>
    jQuery(function ($) {
        $('#eventadmin-dismiss-notice').on('click', function () {
            $('#eventadmin-update-notice').slideUp(200);
            $.post(window.ajaxurl, {
                action: 'eventadmin_dismiss_notice',
                _ajax_nonce: '<?php echo esc_js($nonce); ?>',
            });
        });
    });
    </script>
    <?php
}

add_action('admin_notices', 'eventadmin_update_notice');

/**
 * Handles AJAX dismissal of the update notice
 */
function eventadmin_dismiss_notice(): void
{
    check_ajax_referer('eventadmin_dismiss_notice');
    if (!current_user_can('manage_options')) wp_die();
    update_user_meta(get_current_user_id(), 'eventadmin_dismissed_version', EVENTADMIN_VERSION);
    wp_die();
}

add_action('wp_ajax_eventadmin_dismiss_notice', 'eventadmin_dismiss_notice');

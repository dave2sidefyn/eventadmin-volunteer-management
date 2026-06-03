<?php
/**
 * EventAdmin Volunteer Management - Registration of volunteers
 * Allows users to register as volunteers and create a profile.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Normalizes the email address, especially for Gmail addresses.
 *
 * @param string $email The email address to normalize.
 * @return string The normalized email address.
 */
function eventadmin_normalize_email(string $email): string
{
    $email = sanitize_email($email);
    if (str_contains($email, '@gmail.com')) {
        [$local, $domain] = explode('@', $email);
        $local = preg_replace('/\+.*$/', '', $local); // Remove +alias
        return $local . '@' . $domain;
    }
    return $email;
}

/**
 * Returns true when a CAPTCHA provider is selected and a site key is configured.
 */
function eventadmin_captcha_is_enabled(): bool
{
    return get_option('eventadmin_captcha_provider', 'none') !== 'none'
        && !empty(get_option('eventadmin_captcha_site_key', ''));
}

function eventadmin_enqueue_captcha_script(): void
{
    if (!eventadmin_captcha_is_enabled()) {
        return;
    }
    $provider = get_option('eventadmin_captcha_provider', 'none');
    $site_key = get_option('eventadmin_captcha_site_key', '');

    if ($provider === 'recaptcha_v2') {
        wp_enqueue_script('eventadmin-captcha', 'https://www.google.com/recaptcha/api.js', [], null, true);
    } elseif ($provider === 'hcaptcha') {
        wp_enqueue_script('eventadmin-captcha', 'https://js.hcaptcha.com/1/api.js', [], null, true);
    } elseif ($provider === 'recaptcha_v3') {
        wp_enqueue_script('eventadmin-captcha', 'https://www.google.com/recaptcha/api.js?render=' . urlencode($site_key), [], null, true);
        $js_path = plugin_dir_path(__FILE__) . '../assets/js/captcha-v3.js';
        wp_enqueue_script(
            'eventadmin-captcha-v3',
            plugin_dir_url(__FILE__) . '../assets/js/captcha-v3.js',
            ['eventadmin-captcha'],
            file_exists($js_path) ? filemtime($js_path) : null,
            true
        );
        wp_localize_script('eventadmin-captcha-v3', 'eventadminCaptchaV3', ['siteKey' => $site_key]);
    }
}

/**
 * Renders the CAPTCHA widget div if a provider is configured.
 */
function eventadmin_render_captcha_widget(): void
{
    if (!eventadmin_captcha_is_enabled()) {
        return;
    }
    $provider = get_option('eventadmin_captcha_provider', 'none');
    $site_key = get_option('eventadmin_captcha_site_key', '');

    if ($provider === 'recaptcha_v3') {
        // v3 is invisible — JS populates this hidden field before submit
        echo '<input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response-v3">';
    } else {
        $class = $provider === 'recaptcha_v2' ? 'g-recaptcha' : 'h-captcha';
        echo '<div class="' . esc_attr($class) . '" data-sitekey="' . esc_attr($site_key) . '"></div>';
    }
}

/**
 * Verifies the CAPTCHA token server-side. Returns true when CAPTCHA is disabled.
 */
function eventadmin_verify_captcha_response(): bool
{
    if (!eventadmin_captcha_is_enabled()) {
        return true;
    }
    $provider = get_option('eventadmin_captcha_provider', 'none');
    $secret   = get_option('eventadmin_captcha_secret_key', '');

    if ($provider === 'recaptcha_v3') {
        $token = sanitize_text_field(wp_unslash($_POST['g-recaptcha-response'] ?? ''));
        if (empty($token)) {
            return false;
        }
        $result = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
            'body' => ['secret' => $secret, 'response' => $token],
        ]);
        if (is_wp_error($result)) {
            return true; // Fail open: don't block registrations if the CAPTCHA API is unreachable
        }
        $data      = json_decode(wp_remote_retrieve_body($result), true);
        $threshold = (float) get_option('eventadmin_captcha_v3_threshold', 0.5);
        return !empty($data['success']) && isset($data['score']) && (float) $data['score'] >= $threshold;
    }

    $field      = $provider === 'recaptcha_v2' ? 'g-recaptcha-response' : 'h-captcha-response';
    $token      = sanitize_text_field(wp_unslash($_POST[$field] ?? ''));
    $verify_url = $provider === 'recaptcha_v2'
        ? 'https://www.google.com/recaptcha/api/siteverify'
        : 'https://hcaptcha.com/siteverify';

    if (empty($token)) {
        return false;
    }
    $result = wp_remote_post($verify_url, ['body' => ['secret' => $secret, 'response' => $token]]);
    if (is_wp_error($result)) {
        return true; // Fail open: don't block registrations if the CAPTCHA API is unreachable
    }
    $data = json_decode(wp_remote_retrieve_body($result), true);
    return !empty($data['success']);
}

function eventadmin_main_shortcode(): string
{

    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        if (in_array('eventadmin_volunteer', $user->roles)) {
            // Show cockpit instead of registration
            return do_shortcode('[eventadmin_cockpit]');
        }
    }

    return do_shortcode('[eventadmin_register]');

}

function eventadmin_registration_form_shortcode(): bool|string
{

    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        if (in_array('eventadmin_volunteer', $user->roles)) {
            // Show cockpit instead of registration form
            return do_shortcode('[eventadmin_cockpit]');
        }
    }

    // Generate one-time token
    $token = wp_generate_password(16, false);
    $nonce = wp_create_nonce('eventadmin_register_' . $token);

    // Set cookie
    setcookie('eventadmin_register_token', $token, time() + 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
    setcookie('eventadmin_register_nonce', $nonce, time() + 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);

    // Save transient for server-side (optional for extra security)
    set_transient('eventadmin_token_' . $token, $nonce, HOUR_IN_SECONDS);

    eventadmin_enqueue_captcha_script();

    ob_start(); ?>
    <div class="eventadmin-form-wrapper">
        <?php
        if (shortcode_exists('nextend_social_login')) {
            echo do_shortcode('[nextend_social_login]');
            echo '<hr />';
        }
        ?>
        <form method="post" class="eventadmin-form">
            <?php wp_nonce_field('eventadmin_register_action', 'eventadmin_register_nonce'); ?>
            <label><?php esc_html_e('First name', 'eventadmin-volunteer-management'); ?>
                <input type="text" name="eventadmin_firstname" required/>
            </label>
            <label><?php esc_html_e('Last name', 'eventadmin-volunteer-management'); ?>
                <input type="text" name="eventadmin_lastname" required/>
            </label>
            <label><?php esc_html_e('Phone number', 'eventadmin-volunteer-management'); ?>
                <input type="tel" name="eventadmin_phone" required/>
            </label>
            <label><?php esc_html_e('E-Mail', 'eventadmin-volunteer-management'); ?>
                <input type="email" name="eventadmin_email" required/>
            </label>
            <input type="hidden" name="eventadmin_redirect_to" value="<?php echo esc_url(get_permalink()); ?>"/>
            <?php eventadmin_render_captcha_widget(); ?>
            <div style="display:none !important;visibility:hidden" aria-hidden="true">
                <input type="text" name="eventadmin_website" tabindex="-1" autocomplete="off">
            </div>
            <input type="submit" name="eventadmin_register_submit" value="Register"/>
        </form>

        <?php if (isset($_GET['registration'], $_GET['eventadmin_register_nonce']) && $_GET['registration'] === 'success' && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['eventadmin_register_nonce'])), 'eventadmin_register_action')) : ?>
            <div class="eventadmin-success">
                <?php esc_html_e('Thank you for your registration. You will receive an email with your login link shortly.', 'eventadmin-volunteer-management'); ?>
            </div>
        <?php elseif (isset($_GET['registration'], $_GET['eventadmin_register_nonce']) && $_GET['registration'] === 'exists' && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['eventadmin_register_nonce'])), 'eventadmin_register_action')) : ?>
            <div class="eventadmin-info">
                <?php esc_html_e('This email is already registered. We have resent your login link.', 'eventadmin-volunteer-management'); ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('eventadmin', 'eventadmin_main_shortcode');
add_shortcode('eventadmin_register', 'eventadmin_registration_form_shortcode');

// Needed for is_plugin_active:
if (!function_exists('is_plugin_active')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

/**
 * Handles the registration process for volunteers.
 *
 * @throws \Random\RandomException
 */
function eventadmin_handle_registration(): void
{
    // 1. Only run on form submit
    if (empty($_POST['eventadmin_register_submit'])) {
        return;
    }

    // 2. Check nonce
    if (
        empty($_POST['eventadmin_register_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eventadmin_register_nonce'])), 'eventadmin_register_action')
    ) {
        wp_die(esc_html__('Invalid form submission.', 'eventadmin-volunteer-management'));
    }

    // 3. Honeypot – bots fill hidden fields; humans don't
    if (!empty($_POST['eventadmin_website'])) {
        return;
    }

    // 4. CAPTCHA verification
    if (!eventadmin_verify_captcha_response()) {
        wp_die(esc_html__('CAPTCHA verification failed. Please try again.', 'eventadmin-volunteer-management'));
    }

    // 5. Check fields
    $email = isset($_POST['eventadmin_email']) ? eventadmin_normalize_email(sanitize_email(wp_unslash($_POST['eventadmin_email']))) : '';
    $first = isset($_POST['eventadmin_firstname']) ? sanitize_text_field(wp_unslash($_POST['eventadmin_firstname'])) : '';
    $last = isset($_POST['eventadmin_lastname']) ? sanitize_text_field(wp_unslash($_POST['eventadmin_lastname'])) : '';
    $phone = isset($_POST['eventadmin_phone']) ? sanitize_text_field(wp_unslash($_POST['eventadmin_phone'])) : '';

    // 6. Email validation
    if (!is_email($email)) {
        wp_die(esc_html__('Please enter a valid email address.', 'eventadmin-volunteer-management'));
    }

    // 7. Check required fields (optional)
    if (empty($first) || empty($last) || empty($phone)) {
        wp_die(esc_html__('Please fill in all required fields.', 'eventadmin-volunteer-management'));
    }

    // 8. Check if email already exists, if so, send new magic link
    $referer_url = isset($_POST['eventadmin_redirect_to']) ? esc_url_raw(wp_unslash($_POST['eventadmin_redirect_to'])) : site_url();
    if (email_exists($email)) {
        $existing_user = get_user_by('email', $email);
        $user_id = $existing_user->ID;

        $login_url = eventadmin_create_login_url($user_id, $referer_url);

        eventadmin_send_HTML_e_mail(
            $email,
            esc_html__('Your login', 'eventadmin-volunteer-management'),
            sprintf(
            /* translators: %1$s = first name, %2$s = login url */
                wp_kses_post(__('Hello %1$s,<br><br>Your login link:<br>%2$s', 'eventadmin-volunteer-management')),
                $existing_user->first_name,
                $login_url
            ));

        wp_safe_redirect(add_query_arg([
            'registration' => 'exists',
            'eventadmin_register_nonce' => wp_create_nonce('eventadmin_register_action')
        ], wp_get_referer()));
        exit;
    }

    // 9. Create user
    $userdata = [
        'user_login' => $email,
        'user_email' => $email,
        'user_pass' => wp_generate_password(), // Passwordless registration
        'first_name' => $first,
        'last_name' => $last,
        'role' => 'eventadmin_volunteer', // Role for volunteers
    ];

    // Suppress WP password email around wp_insert_user so the role check is not needed.
    $suppress_cb = null;
    if (get_option('eventadmin_suppress_wp_password_email', 1)) {
        $suppress_cb = static function (array $mail): array {
            return array_merge($mail, ['to' => '']);
        };
        add_filter('wp_new_user_notification_email', $suppress_cb, 999);
    }

    $user_id = wp_insert_user($userdata);

    if ($suppress_cb !== null) {
        remove_filter('wp_new_user_notification_email', $suppress_cb, 999);
    }

    if (is_wp_error($user_id)) {
        wp_die(esc_html__('Registration error. Please try again later.', 'eventadmin-volunteer-management'));
    }

    // 10. Save additional metadata
    update_user_meta($user_id, 'eventadmin_phone', $phone);

    // 11. Generate login link
    $login_url = eventadmin_create_login_url($user_id, $referer_url);

    // 12. Send email to new user
    $site_name = get_bloginfo('name');
    $site_url = home_url();

    eventadmin_send_HTML_e_mail(
        $email,
        esc_html__('Your login', 'eventadmin-volunteer-management'),
        sprintf(
        /* translators: %1$s = first name, %2$s = site name, %3$s = site url, %4$s = login url */
            wp_kses_post(__('Hello %1$s,<br><br>Thank you for registering at %2$s (%3$s).<br><br>Here is your login link:<br>%4$s', 'eventadmin-volunteer-management')),
            $first,
            $site_name,
            $site_url,
            $login_url
        ));

    // 13. Redirect with success
    wp_safe_redirect(add_query_arg([
        'registration' => 'success',
        'eventadmin_register_nonce' => wp_create_nonce('eventadmin_register_action')
    ], wp_get_referer()));
    exit;
}


/**
 * Creates a magic login URL for a user.
 * This URL can be used to log in without a password.
 *
 * @param WP_Error|int $user_id
 * @param string $referer_url Optional. The URL to redirect to after login.
 * @return string
 * @throws \Random\RandomException
 */
function eventadmin_create_login_url(WP_Error|int $user_id, string $referer_url): string
{
    // Generate magic login token
    $token = bin2hex(random_bytes(16));
    update_user_meta($user_id, 'magic_login_token', $token);
    update_user_meta($user_id, 'magic_login_expire', time() + DAY_IN_SECONDS);

    return add_query_arg([
        'magic_login' => $token,
        'uid' => $user_id,
        '_wpnonce' => wp_create_nonce('eventadmin_magic_login'),
        'redirect_to' => $referer_url ?: get_permalink()
    ], site_url());
}

add_action('init', 'eventadmin_handle_registration');

/**
 * Filter to modify the new user notification email.
 * If the user is a volunteer, we don't send a password.
 *
 * @param array $email The email data.
 * @param WP_User $user The user object.
 * @return array Modified email data.
 */
function eventadmin_wp_new_user_notification_email(array $email, WP_User $user): array
{
    // As a volunteer they don't need a password to login
    if (in_array('eventadmin_volunteer', $user->roles, true) && get_option('eventadmin_suppress_wp_password_email', 1)) {
        return [
            'to' => '',
            'subject' => '',
            'message' => '',
            'headers' => '',
        ];
    }

    // All other roles should keep the default behavior
    return $email;
}

add_filter('wp_new_user_notification_email', 'eventadmin_wp_new_user_notification_email', 10, 3);

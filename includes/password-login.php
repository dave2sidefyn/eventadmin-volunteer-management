<?php
/**
 * EventAdmin Volunteer Management - Optional email + password login for volunteers
 *
 * Off by default (Settings → General → "Password login"). The login link stays the primary
 * way in and is always available: it verifies the email address on sign-up, it's how
 * invitations work ("Send login link"), and it replaces "forgot password" — so there is no
 * separate password-reset flow. A password is something a volunteer may add afterwards.
 *
 * Password login only happens on the volunteer page (the "I already have an account" view of
 * the registration form), never via wp-login.php, and never assigns or changes roles.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

const EVENTADMIN_MIN_PASSWORD_LENGTH = 8;
const EVENTADMIN_MAX_FAILED_LOGINS   = 5;

/**
 * Whether volunteers may set a password and log in with it.
 *
 * @return bool
 */
function eventadmin_password_login_enabled(): bool
{
    return (bool) get_option('eventadmin_allow_password_login', 0);
}

/**
 * Whether the user has chosen a password themselves (accounts created by the plugin only
 * hold a random one nobody knows).
 *
 * @param int $user_id
 * @return bool
 */
function eventadmin_user_has_own_password(int $user_id): bool
{
    return (bool) get_user_meta($user_id, 'eventadmin_password_set', true);
}

// A password set through WordPress's own reset screen counts too.
add_action('after_password_reset', static function (WP_User $user): void {
    update_user_meta($user->ID, 'eventadmin_password_set', 1);
});

/**
 * Sets the current volunteer's own password and keeps them logged in — wp_set_password()
 * ends all of the user's sessions, including the current one.
 *
 * @param int    $user_id
 * @param string $password
 * @return bool|WP_Error True on success.
 */
function eventadmin_set_volunteer_password(int $user_id, string $password): bool|WP_Error
{
    if (mb_strlen($password) < EVENTADMIN_MIN_PASSWORD_LENGTH) {
        return new WP_Error('password_too_short', sprintf(
        /* translators: %d = minimum number of characters */
            esc_html__('Your password must be at least %d characters long.', 'eventadmin-volunteer-management'),
            EVENTADMIN_MIN_PASSWORD_LENGTH
        ));
    }

    wp_set_password($password, $user_id);
    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id, true);
    update_user_meta($user_id, 'eventadmin_password_set', 1);

    return true;
}

/**
 * The tip appended to login-link emails while the volunteer hasn't set a password yet, so
 * they know a password is possible — and that they don't need one.
 *
 * @param int $user_id
 * @return string HTML (empty when not applicable)
 */
function eventadmin_password_tip_html(int $user_id): string
{
    if (!eventadmin_password_login_enabled() || eventadmin_user_has_own_password($user_id)) {
        return '';
    }

    return '<br><br>' . esc_html__('Tip: once you\'re logged in, you can set a password in your profile and use it next time. If you don\'t, that\'s fine — you can always request a new login link like this one.', 'eventadmin-volunteer-management');
}

/**
 * Failed password logins in the last 15 minutes, counted per email address and per IP.
 *
 * @param string $email
 * @return string[] Transient keys
 */
function eventadmin_failed_login_keys(string $email): array
{
    $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));

    return [
        'eventadmin_failed_login_e_' . md5(strtolower($email)),
        'eventadmin_failed_login_i_' . md5($ip),
    ];
}

/**
 * @param string $email
 * @return bool
 */
function eventadmin_too_many_failed_logins(string $email): bool
{
    foreach (eventadmin_failed_login_keys($email) as $key) {
        if ((int) get_transient($key) >= EVENTADMIN_MAX_FAILED_LOGINS) {
            return true;
        }
    }

    return false;
}

/**
 * @param string $email
 * @return void
 */
function eventadmin_record_failed_login(string $email): void
{
    foreach (eventadmin_failed_login_keys($email) as $key) {
        set_transient($key, (int) get_transient($key) + 1, 15 * MINUTE_IN_SECONDS);
    }
}

/**
 * Password login from the volunteer page. Called by eventadmin_handle_login_link_request()
 * (includes/registration.php) after its nonce / bot / CAPTCHA checks. Uses wp_signon(), so
 * security plugins hooked into WordPress authentication (2FA, login limits) still apply.
 *
 * @param string $email
 * @param string $password
 * @param string $redirect_to Already validated to be on this site.
 * @return void Always redirects.
 */
function eventadmin_handle_password_login(string $email, string $password, string $redirect_to): void
{
    $login_view = add_query_arg('eventadmin_view', 'login', remove_query_arg(['eventadmin_login', 'eventadmin_login_error'], $redirect_to));

    if (eventadmin_too_many_failed_logins($email)) {
        wp_safe_redirect(add_query_arg('eventadmin_login_error', 'locked', $login_view));
        exit;
    }

    $user = wp_signon([
        'user_login'    => $email,
        'user_password' => $password,
        'remember'      => true,
    ], is_ssl());

    if (is_wp_error($user)) {
        eventadmin_record_failed_login($email);
        wp_safe_redirect(add_query_arg('eventadmin_login_error', 'invalid', $login_view));
        exit;
    }

    foreach (eventadmin_failed_login_keys($email) as $key) {
        delete_transient($key);
    }

    wp_safe_redirect(remove_query_arg(['eventadmin_view', 'eventadmin_login', 'eventadmin_login_error'], $redirect_to));
    exit;
}

/**
 * The "log in with a password next time?" banner at the top of the cockpit, shown once a
 * volunteer is logged in (typically right after their first login link) until they set a
 * password or decline.
 *
 * @return string HTML (empty when not applicable)
 */
function eventadmin_render_password_prompt(): string
{
    $user_id = get_current_user_id();
    $status  = isset($_GET['eventadmin_pw']) ? sanitize_key(wp_unslash($_GET['eventadmin_pw'])) : '';

    if ($status === 'set') {
        return '<div class="hinweis-box hinweis-box-success">'
            . esc_html__('Your password is saved. Next time you can log in with your email address and password — or keep using a login link.', 'eventadmin-volunteer-management')
            . '</div>';
    }

    if (
        !$user_id
        || !eventadmin_password_login_enabled()
        || eventadmin_user_has_own_password($user_id)
        || get_user_meta($user_id, 'eventadmin_password_prompt_dismissed', true)
    ) {
        return '';
    }

    ob_start(); ?>
    <div class="eventadmin-password-prompt">
        <p><strong><?php esc_html_e('Want to log in with a password next time?', 'eventadmin-volunteer-management'); ?></strong><br>
            <?php esc_html_e('Optional: you can also keep logging in with a link sent by email.', 'eventadmin-volunteer-management'); ?></p>
        <?php if ($status === 'too_short') : ?>
            <p class="eventadmin-error"><?php echo esc_html(sprintf(
                /* translators: %d = minimum number of characters */
                    __('Your password must be at least %d characters long.', 'eventadmin-volunteer-management'),
                    EVENTADMIN_MIN_PASSWORD_LENGTH
                )); ?></p>
        <?php endif; ?>
        <form method="post" class="eventadmin-form">
            <?php wp_nonce_field('eventadmin_password_prompt', 'eventadmin_password_prompt_nonce'); ?>
            <label><?php esc_html_e('Choose a password', 'eventadmin-volunteer-management'); ?>
                <input type="password" name="eventadmin_new_password" autocomplete="new-password" minlength="<?php echo esc_attr(EVENTADMIN_MIN_PASSWORD_LENGTH); ?>">
            </label>
            <button type="submit" name="eventadmin_password_prompt" value="set"><?php esc_html_e('Set password', 'eventadmin-volunteer-management'); ?></button>
            <button type="submit" name="eventadmin_password_prompt" value="dismiss" class="eventadmin-link-button" formnovalidate><?php esc_html_e('No thanks', 'eventadmin-volunteer-management'); ?></button>
        </form>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Handles the banner's "Set password" / "No thanks" buttons.
 *
 * @return void
 */
function eventadmin_handle_password_prompt(): void
{
    if (empty($_POST['eventadmin_password_prompt_nonce']) || !is_user_logged_in()) {
        return;
    }

    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eventadmin_password_prompt_nonce'])), 'eventadmin_password_prompt')) {
        wp_die(esc_html__('Invalid form submission.', 'eventadmin-volunteer-management'));
    }

    $user_id = get_current_user_id();
    // Back to the page the banner is on. (Not wp_get_referer(): it deliberately returns false
    // when the form posts to its own page, as this one does.) Without a URL argument,
    // remove_query_arg() works on the current request URI.
    $back    = remove_query_arg('eventadmin_pw');

    if (($_POST['eventadmin_password_prompt'] ?? '') === 'dismiss') {
        update_user_meta($user_id, 'eventadmin_password_prompt_dismissed', 1);
        wp_safe_redirect($back);
        exit;
    }

    if (!eventadmin_password_login_enabled()) {
        wp_safe_redirect($back);
        exit;
    }

    // Passwords are taken verbatim (no sanitizing — that would change what the user typed).
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $password = isset($_POST['eventadmin_new_password']) ? (string) wp_unslash($_POST['eventadmin_new_password']) : '';
    $result   = eventadmin_set_volunteer_password($user_id, $password);

    wp_safe_redirect(add_query_arg('eventadmin_pw', is_wp_error($result) ? 'too_short' : 'set', $back));
    exit;
}

add_action('init', 'eventadmin_handle_password_prompt');

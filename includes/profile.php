<?php
/**
 * EventAdmin Volunteer Management - Profile management for volunteers
 * Allows volunteers to edit their own profile (first name, last name, phone number, email).
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Shortcode for the profile form
 *
 * @return bool|string|null HTML output of the profile form
 */
function eventadmin_profile_shortcode(): bool|string|null
{
    if (!is_user_logged_in()) return esc_html__('Please log in first.', 'eventadmin-volunteer-management');

    $user         = wp_get_current_user();
    $phone        = get_user_meta($user->ID, 'eventadmin_phone', true);
    $announcements = get_user_meta($user->ID, 'eventadmin_announcements', true);
    // Default: opted-in (empty meta = 1)
    $announcements = ($announcements === '0') ? 0 : 1;
    // Departments hidden from volunteers (Shifts → Departments → "Hide from volunteers")
    // aren't offered here either — a volunteer can't subscribe to something they can't see.
    $departments = array_values(array_filter(
        eventadmin_get_hierarchical_shift_categories(),
        fn($term) => !eventadmin_is_shift_category_hidden($term->term_id)
    ));
    $linked_departments = eventadmin_get_volunteer_department_ids($user->ID);

    ob_start(); ?>
    <div class="eventadmin-form-wrapper">

        <?php if (
            isset($_GET['update'], $_GET['_wpnonce']) &&
            $_GET['update'] === 'success' &&
            wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'eventadmin_profile_success')
        ) : ?>
            <div class="hinweis-box hinweis-box-success">
                <?php esc_html_e('✅ Your profile was saved successfully.', 'eventadmin-volunteer-management'); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($phone)) : ?>
            <div class="hinweis-box hinweis-box-warning">
                <?php wp_kses_post(__('<strong>Profile incomplete:</strong> Please enter your phone number so we can reach you in case of last-minute changes.', 'eventadmin-volunteer-management')); ?>

            </div>
        <?php endif; ?>

        <form method="post" class="eventadmin-form">
            <label><?php esc_html_e('First name', 'eventadmin-volunteer-management'); ?>
                <input type="text" name="eventadmin_first_name" value="<?php echo esc_attr($user->first_name); ?>"
                       required>
            </label>

            <label><?php esc_html_e('Last name', 'eventadmin-volunteer-management'); ?>
                <input type="text" name="eventadmin_last_name" value="<?php echo esc_attr($user->last_name); ?>"
                       required>
            </label>
            <label><?php esc_html_e('Phone number', 'eventadmin-volunteer-management'); ?>
                <input type="text" name="eventadmin_phone" value="<?php echo esc_attr($phone); ?>" required>
            </label>
            <label><?php esc_html_e('E-Mail', 'eventadmin-volunteer-management'); ?>
                <input type="text" name="eventadmin_email" value="<?php echo esc_attr($user->user_email); ?>" readonly
                       style="background: #eee;">
            </label>

            <label class="eventadmin-checkbox-label">
                <input type="checkbox" name="eventadmin_announcements" value="1"<?php checked($announcements, 1); ?>>
                <?php esc_html_e('Receive announcements about new shifts and events', 'eventadmin-volunteer-management'); ?>
            </label>

            <?php if (!empty($departments)) :
                wp_enqueue_script(
                    'eventadmin-department-checkboxes',
                    plugin_dir_url(__FILE__) . '../assets/js/department-checkboxes.js',
                    [],
                    '1.0',
                    true
                );
                ?>
                <details class="eventadmin-departments-toggle"<?php echo !empty($linked_departments) ? ' open' : ''; ?>>
                    <summary><?php esc_html_e('Advanced: notify me about specific departments', 'eventadmin-volunteer-management'); ?></summary>
                    <p><?php esc_html_e('Also notify me about new shifts in:', 'eventadmin-volunteer-management'); ?></p>
                    <?php foreach ($departments as $department) : ?>
                        <label class="eventadmin-checkbox-label"<?php echo $department->depth > 0 ? ' style="margin-left:' . esc_attr($department->depth * 20) . 'px;"' : ''; ?>>
                            <input type="checkbox" class="eventadmin-department-checkbox" data-depth="<?php echo esc_attr($department->depth); ?>"
                                   name="eventadmin_department[]"
                                   value="<?php echo esc_attr($department->term_id); ?>"
                                <?php checked(in_array($department->term_id, $linked_departments, true)); ?>>
                            <?php echo esc_html($department->name); ?>
                        </label>
                    <?php endforeach; ?>
                </details>
            <?php endif; ?>

            <?php wp_nonce_field('eventadmin_profile_update', 'eventadmin_profile_nonce'); ?>

            <button type="submit"
                    name="eventadmin_profile_submit"><?php esc_html_e('Save', 'eventadmin-volunteer-management'); ?></button>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('eventadmin_profile', 'eventadmin_profile_shortcode');

/**
 * Handles the profile update
 * Called when the profile form is submitted
 *
 * @return void
 */
function eventadmin_handle_profile_update(): void
{
    if (!is_user_logged_in() || !isset($_POST['eventadmin_profile_submit'])) {
        return;
    }

    if (!isset($_POST['eventadmin_profile_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eventadmin_profile_nonce'])), 'eventadmin_profile_update')) {
        wp_die();
    }

    $user_id = get_current_user_id();

    // Sanitize all inputs
    $email = isset($_POST['eventadmin_email']) ? sanitize_email(wp_unslash($_POST['eventadmin_email'])) : '';
    $first_name = isset($_POST['eventadmin_first_name']) ? sanitize_text_field(wp_unslash($_POST['eventadmin_first_name'])) : '';
    $last_name = isset($_POST['eventadmin_last_name']) ? sanitize_text_field(wp_unslash($_POST['eventadmin_last_name'])) : '';
    $phone = isset($_POST['eventadmin_phone']) ? sanitize_text_field(wp_unslash($_POST['eventadmin_phone'])) : '';

    if (!empty($email)) {
        $existing = get_user_by('email', $email);
        if ($existing && $existing->ID !== $user_id) {
            wp_die(esc_html__('This email address is already used by another user.', 'eventadmin-volunteer-management'));
        }
    }

    wp_update_user([
        'ID' => $user_id,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'user_email' => $email,
    ]);

    update_user_meta($user_id, 'eventadmin_phone', $phone);

    $announcements = isset($_POST['eventadmin_announcements']) ? 1 : 0;
    update_user_meta($user_id, 'eventadmin_announcements', $announcements);

    $posted_departments = (isset($_POST['eventadmin_department']) && is_array($_POST['eventadmin_department']))
        ? wp_unslash($_POST['eventadmin_department'])
        : [];
    // Departments hidden from volunteers aren't rendered as checkboxes above, so preserve
    // any existing link to one (e.g. set by an admin) instead of this form silently
    // dropping it just because it wasn't offered as an option to toggle.
    $preserved_hidden_departments = array_filter(
        eventadmin_get_volunteer_department_ids($user_id),
        'eventadmin_is_shift_category_hidden'
    );
    eventadmin_save_volunteer_department_ids($user_id, array_merge($posted_departments, $preserved_hidden_departments));

    if (!empty($password)) {
        wp_set_password($password, $user_id);
        wp_safe_redirect(wp_login_url()); // Re-login required
        exit;
    }

    $redirect_url = add_query_arg([
        'update' => 'success',
        '_wpnonce' => wp_create_nonce('eventadmin_profile_success'),
    ]);

    wp_safe_redirect($redirect_url);
    exit;
}

add_action('init', 'eventadmin_handle_profile_update');

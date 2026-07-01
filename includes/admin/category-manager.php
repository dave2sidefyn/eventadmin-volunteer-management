<?php
/**
 * EventAdmin Volunteer Management - Category Manager for Departments
 * Adds an additional color field for departments and saves the color in the term meta. This color is then used to display department labels more clearly in the shift selection.
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Adds a color field for departments to save the color of the department label
 * Displayed when adding a new category in the admin area
 *
 * @return void
 */
function eventadmin_custom_shift_category_add_form_fields(): void
{
    wp_nonce_field('save_eventadmin_shift_category_color', 'eventadmin_shift_category_color_nonce');
    echo '
<div class="form-field term-color-wrap">
    <label for="term-color">' . esc_html__('Color', 'eventadmin-volunteer-management') . '</label>
    <input type="color" name="term_color" value="#cccccc">
    <p class="description">' . esc_html__('Choose a color for the department label.', 'eventadmin-volunteer-management') . '</p>
</div>
<div class="form-field term-hidden-wrap">
    <label for="term_hidden"><input type="checkbox" name="term_hidden" id="term_hidden" value="1"> ' . esc_html__('Hide from volunteers', 'eventadmin-volunteer-management') . '</label>
    <p class="description">' . esc_html__('Hidden departments no longer appear in the frontend filter or as a category label, but shifts already assigned to them remain visible.', 'eventadmin-volunteer-management') . '</p>
</div>';
}

// Color field when adding the category
add_action('eventadmin_shift_category_add_form_fields', 'eventadmin_custom_shift_category_add_form_fields');

/**
 * Adds a color field for departments to edit the color of the department label
 * Displayed when editing an existing category in the admin area
 *
 * @param WP_Term $term The current term object (department)
 * @return void
 */
function eventadmin_custom_shift_category_edit_form_fields(WP_Term $term): void
{
    $color = get_term_meta($term->term_id, 'term_color', true) ?: '#cccccc';
    $hidden = get_term_meta($term->term_id, 'term_hidden', true);
    wp_nonce_field('save_eventadmin_shift_category_color', 'eventadmin_shift_category_color_nonce');
    echo '<tr class="form-field term-color-wrap">
    <th scope="row"><label for="term_color">' . esc_html__('Color', 'eventadmin-volunteer-management') . '</label></th>
    <td>
        <input type="color" name="term_color" value="' . esc_attr($color) . '">
        <p class="description">' . esc_html__('Choose a color for the department label.', 'eventadmin-volunteer-management') . '</p>
    </td>
</tr>
<tr class="form-field term-hidden-wrap">
    <th scope="row"><label for="term_hidden">' . esc_html__('Hide from volunteers', 'eventadmin-volunteer-management') . '</label></th>
    <td>
        <input type="checkbox" name="term_hidden" id="term_hidden" value="1"' . checked($hidden, '1', false) . '>
        <p class="description">' . esc_html__('Hidden departments no longer appear in the frontend filter or as a category label, but shifts already assigned to them remain visible.', 'eventadmin-volunteer-management') . '</p>
    </td>
</tr>';
}

// Color field when editing the category
add_action('eventadmin_shift_category_edit_form_fields', 'eventadmin_custom_shift_category_edit_form_fields');

/**
 * Saves the color for the department label in the term meta
 *
 * @param int $term_id The ID of the term (department)
 */
function eventadmin_custom_save_term_color(int $term_id): void
{
    if (
        !isset($_POST['eventadmin_shift_category_color_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eventadmin_shift_category_color_nonce'])), 'save_eventadmin_shift_category_color')
    ) {
        return;
    }

    if (isset($_POST['term_color'])) {
        $color = sanitize_hex_color(wp_unslash($_POST['term_color']));
        update_term_meta($term_id, 'term_color', $color);
    }

    update_term_meta($term_id, 'term_hidden', !empty($_POST['term_hidden']) ? '1' : '');
}

/**
 * Checks whether a shift category has been marked as hidden from volunteers.
 *
 * @param int $term_id The ID of the term (department)
 * @return bool
 */
function eventadmin_is_shift_category_hidden(int $term_id): bool
{
    return get_term_meta($term_id, 'term_hidden', true) === '1';
}

add_action('created_eventadmin_shift_category', 'eventadmin_custom_save_term_color');
add_action('edited_eventadmin_shift_category', 'eventadmin_custom_save_term_color');

/**
 * Enqueues a small inline script to reset the color input to its default after WordPress
 * clears the add-term form via AJAX (which otherwise leaves the color input black).
 *
 * @return void
 */
function eventadmin_enqueue_category_color_reset_script(): void
{
    $screen = get_current_screen();
    if (!$screen || $screen->taxonomy !== 'eventadmin_shift_category') {
        return;
    }
    wp_add_inline_script('jquery', '
        jQuery(document).on("ajaxSuccess", function(event, xhr, settings) {
            if (typeof settings.data === "string" && settings.data.indexOf("action=add-tag") !== -1) {
                var colorInput = document.querySelector("#addtag input[name=\'term_color\']");
                if (colorInput) {
                    colorInput.value = "#cccccc";
                }
            }
        });
    ');
}

add_action('admin_enqueue_scripts', 'eventadmin_enqueue_category_color_reset_script');

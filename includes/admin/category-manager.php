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
 * Adds a "Color" column to the department term list table.
 *
 * @param array $columns
 * @return array
 */
function eventadmin_shift_category_columns(array $columns): array
{
    $new_columns = [];
    foreach ($columns as $key => $label) {
        $new_columns[$key] = $label;
        if ($key === 'name') {
            $new_columns['term_color'] = esc_html__('Color', 'eventadmin-volunteer-management');
        }
    }
    return $new_columns;
}

add_filter('manage_edit-eventadmin_shift_category_columns', 'eventadmin_shift_category_columns');

/**
 * Renders the content of the "Color" term list column.
 *
 * @param string $content     Existing column content (empty for custom columns).
 * @param string $column_name The column being rendered.
 * @param int    $term_id     The department term ID.
 * @return string
 */
function eventadmin_shift_category_column_content(string $content, string $column_name, int $term_id): string
{
    if ($column_name === 'term_color') {
        $color  = get_term_meta($term_id, 'term_color', true) ?: '#cccccc';
        $hidden = eventadmin_is_shift_category_hidden($term_id);
        $term   = get_term($term_id, 'eventadmin_shift_category');
        $description = ($term && !is_wp_error($term)) ? $term->description : '';
        // data-color/data-hidden/data-description let the Quick Edit JS prefill its fields,
        // since WordPress core has no built-in way to do that for custom fields (and, for
        // description, doesn't expose it via the row's own hidden inline-edit data either).
        $content = '<span class="eventadmin-term-color" data-color="' . esc_attr($color) . '" data-hidden="' . ($hidden ? '1' : '0') . '" data-description="' . esc_attr($description) . '">'
            . '<span class="eventadmin-term-color-swatch" style="background-color:' . esc_attr($color) . '"></span>'
            . '<span class="eventadmin-term-color-code">' . esc_html(strtoupper($color)) . '</span>'
            . '</span>';
    }
    return $content;
}

add_filter('manage_eventadmin_shift_category_custom_column', 'eventadmin_shift_category_column_content', 10, 3);

/**
 * Prepends a "Hidden from volunteers" badge to the Description column on the department
 * term list table. WordPress core renders that column via a hardcoded method with no
 * column-content filter, and re-sanitizes the term (stripping any HTML we inject) via
 * sanitize_term()'s 'display' context before rendering — so the badge can't be added to
 * $term->description directly, it has to hook the taxonomy-specific field filter that
 * sanitize_term_field() applies afterwards, once the built-in kses/wpautop filters on the
 * generic 'term_description' hook are already done running.
 *
 * @param string $description The description, already run through core's display filters.
 * @param int    $term_id
 * @param string $context
 * @return string
 */
function eventadmin_shift_category_inject_hidden_badge(string $description, int $term_id, string $context): string
{
    if ($context !== 'display' || !eventadmin_is_shift_category_hidden($term_id)) {
        return $description;
    }

    $badge = '<span class="eventadmin-term-badge-hidden">' . esc_html__('Hidden from volunteers', 'eventadmin-volunteer-management') . '</span>';
    return trim($badge . ' ' . $description);
}

add_filter('eventadmin_shift_category_description', 'eventadmin_shift_category_inject_hidden_badge', 10, 3);

/**
 * Adds the Color and "Hide from volunteers" fields to the term list table's Quick Edit box.
 * WordPress fires this action once per non-core column (see WP_Terms_List_Table::inline_edit()),
 * so it's guarded to only render for our one custom column.
 *
 * @param string $column_name
 * @param string $screen_id
 * @param string $taxonomy
 * @return void
 */
function eventadmin_shift_category_quick_edit_fields(string $column_name, string $screen_id, string $taxonomy): void
{
    if ($column_name !== 'term_color' || $taxonomy !== 'eventadmin_shift_category') {
        return;
    }

    wp_nonce_field('save_eventadmin_shift_category_color', 'eventadmin_shift_category_color_nonce');
    $categories = eventadmin_get_hierarchical_shift_categories();
    echo '<fieldset>
    <div class="inline-edit-col">
        <label class="inline-edit-group">
            <span class="title">' . esc_html__('Parent', 'eventadmin-volunteer-management') . '</span>
            <select name="parent">
                <option value="0">' . esc_html__('— None —', 'eventadmin-volunteer-management') . '</option>
                ' . eventadmin_category_dropdown_options($categories, 0, 'term_id') . '
            </select>
        </label>
        <label class="inline-edit-group">
            <span class="title">' . esc_html__('Color', 'eventadmin-volunteer-management') . '</span>
            <span class="input-text-wrap"><input type="color" name="term_color" value="#cccccc"></span>
        </label>
        <label class="inline-edit-group">
            <input type="checkbox" name="term_hidden" value="1">
            <span class="checkbox-title">' . esc_html__('Hide from volunteers', 'eventadmin-volunteer-management') . '</span>
        </label>
        <label class="inline-edit-group">
            <span class="title">' . esc_html__('Description', 'eventadmin-volunteer-management') . '</span>
            <textarea name="description" rows="3" class="ptitle"></textarea>
        </label>
    </div>
</fieldset>';
}

add_action('quick_edit_custom_box', 'eventadmin_shift_category_quick_edit_fields', 10, 3);

/**
 * Enqueues styles for the department term list columns, plus inline scripts to:
 * - reset the "Add" form's color input after WordPress clears it via AJAX (which otherwise
 *   leaves the color input black);
 * - prefill the Quick Edit box's color/hidden fields, since WordPress core has no built-in
 *   way to do that for custom fields — it only knows about name/slug.
 *
 * @return void
 */
function eventadmin_enqueue_category_color_reset_script(): void
{
    $screen = get_current_screen();
    if (!$screen || $screen->taxonomy !== 'eventadmin_shift_category') {
        return;
    }

    $category_manager_css_path = plugin_dir_path(__FILE__) . '../../assets/css/category-manager.css';
    wp_enqueue_style(
        'eventadmin-category-manager',
        plugin_dir_url(__FILE__) . '../../assets/css/category-manager.css',
        [],
        file_exists($category_manager_css_path) ? filemtime($category_manager_css_path) : null
    );

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

    wp_add_inline_script('inline-edit-tax', '
        (function () {
            if (typeof window.inlineEditTax === "undefined") {
                return;
            }
            var originalEdit = window.inlineEditTax.edit;
            window.inlineEditTax.edit = function (id) {
                var result = originalEdit.apply(this, arguments);
                var termId = (typeof id === "object") ? this.getId(id) : id;
                var row = document.getElementById("tag-" + termId);
                var editRow = document.getElementById("edit-" + termId);
                var colorWrap = row ? row.querySelector(".eventadmin-term-color") : null;
                if (colorWrap && editRow) {
                    var colorInput = editRow.querySelector(\'input[name="term_color"]\');
                    var hiddenInput = editRow.querySelector(\'input[name="term_hidden"]\');
                    if (colorInput) {
                        colorInput.value = colorWrap.dataset.color || "#cccccc";
                    }
                    if (hiddenInput) {
                        hiddenInput.checked = colorWrap.dataset.hidden === "1";
                    }
                    var descriptionInput = editRow.querySelector(\'textarea[name="description"]\');
                    if (descriptionInput) {
                        descriptionInput.value = colorWrap.dataset.description || "";
                    }
                }
                if (editRow) {
                    var parentSelect = editRow.querySelector(\'select[name="parent"]\');
                    var parentData = document.querySelector("#inline_" + termId + " .parent");
                    if (parentSelect && parentData) {
                        // Can\'t be its own parent — WordPress would silently reject it anyway
                        // (wp_check_term_hierarchy_for_loops), but disabling it here avoids
                        // offering an option that visibly does nothing when picked.
                        var selfOption = parentSelect.querySelector(\'option[value="\' + termId + \'"]\');
                        if (selfOption) {
                            selfOption.disabled = true;
                        }
                        parentSelect.value = parentData.textContent.trim() || "0";
                    }
                }
                return result;
            };
        })();
    ');
}

add_action('admin_enqueue_scripts', 'eventadmin_enqueue_category_color_reset_script');

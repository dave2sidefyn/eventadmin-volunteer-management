<?php
/**
 * EventAdmin Volunteer Management - Table Tab
 * Renders the Table tab's roster (one row per assignment, plus a placeholder row per
 * still-open slot).
 *
 * @package EventAdminVolunteerManagement
 * @namespace EventAdmin\VolunteerManagement
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

/**
 * Renders the Table view's roster (one row per assignment, plus a placeholder row per
 * still-open slot) — Shift/Period column headers are clickable to sort in place.
 *
 * @param array  $table_rows        From eventadmin_build_overview_rows().
 * @param array  $filter_query_args Shared query args, for the sortable column-header links.
 * @param string $view              Current view (always 'table' when this is called; passed
 *                                   through so the column-header links preserve it).
 * @param string $sort_by           'date'|'title'.
 * @param string $order             'ASC'|'DESC'.
 * @return void
 */
function eventadmin_render_table_view(array $table_rows, array $filter_query_args, string $view, string $sort_by, string $order): void
{
    echo '<table class="widefat striped" id="eventadmin-roster-table">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__('Category', 'eventadmin-volunteer-management') . '</th>';
    // Shift/Period headers are clickable — sorting by name or date directly on the
    // column removes the need for the separate "Sort by"/"Order" dropdowns.
    foreach (['title' => esc_html__('Shift', 'eventadmin-volunteer-management'), 'date' => esc_html__('Period', 'eventadmin-volunteer-management')] as $sort_key => $col_label) {
        $next_order = ($sort_by === $sort_key && $order === 'ASC') ? 'DESC' : 'ASC';
        $col_url    = add_query_arg(array_merge($filter_query_args, ['filter_view' => $view, 'sort_by' => $sort_key, 'order' => $next_order]), admin_url('edit.php'));
        $arrow      = $sort_by === $sort_key ? ($order === 'ASC' ? ' &#8593;' : ' &#8595;') : '';
        echo '<th><a href="' . esc_url($col_url) . '" style="text-decoration:none;color:inherit;">' . $col_label . $arrow . '</a></th>';
    }
    echo '<th>' . esc_html__('Capacity', 'eventadmin-volunteer-management') . '</th>';
    echo '<th>' . esc_html__('Name', 'eventadmin-volunteer-management') . '</th>';
    echo '<th>' . esc_html__('E-Mail', 'eventadmin-volunteer-management') . '</th>';
    echo '<th>' . esc_html__('Phone', 'eventadmin-volunteer-management') . '</th>';
    echo '</tr></thead><tbody>';
    if (empty($table_rows)) {
        echo '<tr><td colspan="7"><em>' . esc_html__('No shifts found.', 'eventadmin-volunteer-management') . '</em></td></tr>';
    }
    foreach ($table_rows as $row) {
        echo '<tr' . ($row['open'] ? ' class="eventadmin-open-slot-row"' : '') . '>';
        echo '<td>' . esc_html($row['category']) . '</td>';
        echo '<td>' . esc_html($row['shift']) . '</td>';
        echo '<td>' . esc_html($row['period']) . '</td>';
        echo '<td>' . esc_html($row['capacity']) . '</td>';
        if ($row['open']) {
            echo '<td colspan="3"><em>&#9888; ' . esc_html__('Open slot — not yet booked', 'eventadmin-volunteer-management') . '</em> ';
            echo '<button type="button" class="button button-small eventadmin-open-slot-add" data-shift-id="' . esc_attr($row['shift_id']) . '">' . esc_html__('Add volunteer', 'eventadmin-volunteer-management') . '</button></td>';
        } else {
            echo '<td>' . esc_html($row['name']) . '</td>';
            echo '<td>' . ($row['email'] === '' ? '<em style="color:#aaa;">—</em>' : esc_html($row['email'])) . '</td>';
            echo '<td>' . ($row['phone'] === '' ? '<em style="color:#aaa;">—</em>' : esc_html($row['phone'])) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
}

/**
 * EventAdmin Volunteer Management - Department checkbox hierarchy
 * Checking (or unchecking) a parent department also checks/unchecks every
 * department nested under it, on both the admin user-edit.php checklist and
 * the front-end [eventadmin_profile] checklist. Relies purely on DOM order
 * (the checkboxes are always rendered parent-first, depth-first) plus each
 * checkbox's own data-depth attribute — no separate parent/child map needed.
 */
document.addEventListener('DOMContentLoaded', function () {
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.eventadmin-department-checkbox'));

    boxes.forEach(function (box, index) {
        box.addEventListener('change', function () {
            var depth = parseInt(box.dataset.depth, 10) || 0;
            for (var i = index + 1; i < boxes.length; i++) {
                var childDepth = parseInt(boxes[i].dataset.depth, 10) || 0;
                if (childDepth <= depth) {
                    break;
                }
                boxes[i].checked = box.checked;
            }
        });
    });
});

/**
 * EventAdmin Volunteer Management - Department checkbox hierarchy
 * Checking (or unchecking) a parent department also checks/unchecks every
 * department nested under it, wherever a .eventadmin-department-checkbox list
 * renders: the front-end [eventadmin_profile] checklist, and the admin "Edit
 * departments" modal on the Volunteers list (whose checklist is injected via
 * AJAX after page load, hence event delegation here rather than a one-time
 * DOMContentLoaded query). Relies purely on DOM order (the checkboxes are
 * always rendered parent-first, depth-first) plus each checkbox's own
 * data-depth attribute — no separate parent/child map needed.
 */
document.addEventListener('change', function (e) {
    if (!e.target.classList || !e.target.classList.contains('eventadmin-department-checkbox')) {
        return;
    }

    var scope = e.target.closest('.eventadmin-department-checklist') || document;
    var boxes = Array.prototype.slice.call(scope.querySelectorAll('.eventadmin-department-checkbox'));
    var index = boxes.indexOf(e.target);
    var depth = parseInt(e.target.dataset.depth, 10) || 0;

    for (var i = index + 1; i < boxes.length; i++) {
        var childDepth = parseInt(boxes[i].dataset.depth, 10) || 0;
        if (childDepth <= depth) {
            break;
        }
        boxes[i].checked = e.target.checked;
    }
});

document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('eventadmin-edit-departments-modal');
    if (!modal || typeof EVENTADMIN_EDIT_DEPARTMENTS === 'undefined') return;

    const cfg = EVENTADMIN_EDIT_DEPARTMENTS;
    const i18n = cfg.i18n || {};
    const heading = document.getElementById('eventadmin-edit-departments-heading');
    const body = document.getElementById('eventadmin-edit-departments-body');
    const userIdField = document.getElementById('eventadmin-edit-departments-user-id');
    const form = document.getElementById('eventadmin-edit-departments-form');
    const result = document.getElementById('eventadmin-edit-departments-result');
    const closeBtn = document.getElementById('eventadmin-edit-departments-close');

    // Fetches the department checklist for one volunteer (see
    // eventadmin_ajax_get_volunteer_departments() in includes/admin/volunteer-list.php) —
    // moved here from a section on user-edit.php, since that native screen requires the
    // broad edit_users capability just to open, which the volunteer-manager role should
    // not need.
    window.eventadminOpenEditDepartmentsModal = function (userId, volunteerName) {
        heading.textContent = volunteerName;
        userIdField.value = userId;
        body.innerHTML = '<p>' + (i18n.loading || 'Loading…') + '</p>';
        result.textContent = '';
        modal.style.display = 'block';

        fetch(cfg.ajax_url, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'eventadmin_get_volunteer_departments',
                nonce: cfg.nonce,
                user_id: userId,
            }),
        })
            .then((r) => r.json())
            .then((res) => {
                if (!res.success) {
                    body.innerHTML = '<p>' + ((res.data && res.data.message) || i18n.error || 'Error') + '</p>';
                    return;
                }
                body.innerHTML = res.data.html;
            })
            .catch(() => {
                body.innerHTML = '<p>' + (i18n.error || 'Error') + '</p>';
            });
    };

    function closeModal() {
        modal.style.display = 'none';
    }

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    // Opens this modal from any ".eventadmin-edit-departments" trigger on the page —
    // the Volunteers list Departments column, and the "Departments" row inside the
    // shared "View profile" modal (see eventadmin_render_volunteer_summary() in
    // includes/admin/user-profile.php). Delegated on document since the profile
    // modal's own trigger is injected via AJAX after this listener is attached.
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('.eventadmin-edit-departments');
        if (!trigger) return;
        window.eventadminOpenEditDepartmentsModal(trigger.dataset.userId, trigger.dataset.name);
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        const checked = Array.prototype.slice
            .call(body.querySelectorAll('.eventadmin-department-checkbox:checked'))
            .map((el) => el.value);

        const params = new URLSearchParams({
            action: 'eventadmin_save_volunteer_departments',
            nonce: cfg.nonce,
            user_id: userIdField.value,
        });
        checked.forEach((id) => params.append('eventadmin_department[]', id));

        result.textContent = '';

        fetch(cfg.ajax_url, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: params,
        })
            .then((r) => r.json())
            .then((res) => {
                if (!res.success) {
                    result.textContent = (res.data && res.data.message) || i18n.error || 'Error';
                    return;
                }
                result.textContent = i18n.saved || 'Saved. Reloading…';
                setTimeout(function () { location.reload(); }, 600);
            })
            .catch(() => {
                result.textContent = i18n.error || 'Error';
            });
    });
});

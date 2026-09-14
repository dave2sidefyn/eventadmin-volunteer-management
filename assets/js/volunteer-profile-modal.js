document.addEventListener('DOMContentLoaded', function () {
    if (typeof EVENTADMIN_VOLUNTEER_PROFILE === 'undefined') return;

    const cfg = EVENTADMIN_VOLUNTEER_PROFILE;
    const i18n = cfg.i18n || {};
    const profileModal = document.getElementById('eventadmin-volunteer-profile-modal');

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // The modal shell only exists on the Timeline and Volunteers list pages — on
    // user-edit.php the same "Volunteer details"/"Volunteer activity" markup renders
    // directly on the page, so the delegated handlers below (phone edit, announcements
    // toggle, remove role) are wired up unconditionally further down, not gated on the
    // modal being present.
    if (profileModal) {
        const profileHeading = document.getElementById('eventadmin-volunteer-profile-heading');
        const profileBody = document.getElementById('eventadmin-volunteer-profile-body');
        const profileFullLink = document.getElementById('eventadmin-volunteer-profile-full-link');
        const profileCloseBtn = document.getElementById('eventadmin-volunteer-profile-close');

        // Fetches the same "Volunteer activity" cards shown on the volunteer's own
        // user-edit.php page (see includes/admin/user-profile.php), via AJAX, so working the
        // Timeline or the Volunteers list doesn't mean leaving it.
        window.eventadminOpenVolunteerProfileModal = function (userId, volunteerName) {
            profileHeading.textContent = volunteerName;
            profileBody.innerHTML = '<p class="eventadmin-profile-empty">' + (i18n.loading || 'Loading…') + '</p>';
            profileFullLink.href = cfg.user_edit_url_base + userId + '#eventadmin-volunteer-activity';
            profileModal.style.display = 'block';

            fetch(cfg.ajax_url, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'eventadmin_get_volunteer_profile',
                    nonce: cfg.nonce,
                    user_id: userId,
                }),
            })
                .then((r) => r.json())
                .then((res) => {
                    if (!res.success) {
                        profileBody.innerHTML = '<p class="eventadmin-profile-empty">'
                            + ((res.data && res.data.message) || i18n.error || 'Error') + '</p>';
                        return;
                    }
                    profileBody.innerHTML = res.data.html;
                })
                .catch(() => {
                    profileBody.innerHTML = '<p class="eventadmin-profile-empty">' + (i18n.error || 'Error') + '</p>';
                });
        };

        window.eventadminCloseVolunteerProfileModal = function () {
            profileModal.style.display = 'none';
        };

        if (profileCloseBtn) profileCloseBtn.addEventListener('click', window.eventadminCloseVolunteerProfileModal);
        profileModal.addEventListener('click', (e) => {
            if (e.target === profileModal) window.eventadminCloseVolunteerProfileModal();
        });
    }

    // Opens this modal from a volunteer-name trigger anywhere (the Volunteers list table,
    // a shift's roster in the Shift details modal, the Overview dashboard's activity feed)
    // — closes the shift details modal first if that's what's currently open.
    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('.eventadmin-view-volunteer-profile');
        if (!trigger) return;
        if (typeof window.eventadminCloseShiftDetailsModal === 'function') {
            window.eventadminCloseShiftDetailsModal();
        }
        if (typeof window.eventadminOpenVolunteerProfileModal === 'function') {
            window.eventadminOpenVolunteerProfileModal(trigger.dataset.userId, trigger.dataset.name);
        }
    });

    // Phone edit: toggle between the display span and the inline input.
    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('.eventadmin-edit-phone-trigger');
        if (!trigger) return;
        const td = trigger.closest('td');
        td.querySelector('.eventadmin-phone-view').style.display = 'none';
        td.querySelector('.eventadmin-phone-edit').style.display = '';
        td.querySelector('.eventadmin-phone-input').focus();
    });

    document.addEventListener('click', function (e) {
        const cancel = e.target.closest('.eventadmin-phone-cancel');
        if (!cancel) return;
        const td = cancel.closest('td');
        td.querySelector('.eventadmin-phone-edit').style.display = 'none';
        td.querySelector('.eventadmin-phone-view').style.display = '';
    });

    document.addEventListener('click', function (e) {
        const saveBtn = e.target.closest('.eventadmin-phone-save');
        if (!saveBtn) return;
        const td = saveBtn.closest('td');
        const input = td.querySelector('.eventadmin-phone-input');
        const userId = saveBtn.dataset.userId;

        saveBtn.disabled = true;

        fetch(cfg.ajax_url, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'eventadmin_update_volunteer_phone',
                _ajax_nonce: cfg.nonce_phone,
                user_id: userId,
                phone: input.value,
            }),
        })
            .then((r) => r.json())
            .then((res) => {
                saveBtn.disabled = false;
                if (!res.success) {
                    alert((res.data && res.data.message) || i18n.error || 'Error');
                    return;
                }
                const valueEl = td.querySelector('.eventadmin-phone-value');
                valueEl.innerHTML = res.data.phone
                    ? '<a href="tel:' + escapeHtml(res.data.tel_href) + '">' + escapeHtml(res.data.phone) + '</a>'
                    : '<span style="color:#999;">' + escapeHtml(i18n.none || '(none)') + '</span>';
                td.querySelector('.eventadmin-phone-edit').style.display = 'none';
                td.querySelector('.eventadmin-phone-view').style.display = '';
            })
            .catch(() => {
                saveBtn.disabled = false;
                alert(i18n.error || 'Error');
            });
    });

    // Announcements: click the Subscribe/Unsubscribe button to toggle opt-in state.
    document.addEventListener('click', function (e) {
        const toggleBtn = e.target.closest('.eventadmin-toggle-announcements');
        if (!toggleBtn) return;
        const userId = toggleBtn.dataset.userId;
        const nextSubscribed = toggleBtn.dataset.subscribed !== '1';

        toggleBtn.disabled = true;

        fetch(cfg.ajax_url, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'eventadmin_toggle_volunteer_announcements',
                _ajax_nonce: cfg.nonce_announcements,
                user_id: userId,
                subscribed: nextSubscribed ? '1' : '0',
            }),
        })
            .then((r) => r.json())
            .then((res) => {
                toggleBtn.disabled = false;
                if (!res.success) {
                    alert((res.data && res.data.message) || i18n.error || 'Error');
                    return;
                }
                toggleBtn.dataset.subscribed = res.data.subscribed ? '1' : '0';
                toggleBtn.textContent = res.data.toggle_label;
                const td = toggleBtn.closest('td');
                const textNode = Array.prototype.find.call(td.childNodes, (n) => n.nodeType === 3);
                if (textNode) textNode.textContent = res.data.label + ' ';
            })
            .catch(() => {
                toggleBtn.disabled = false;
                alert(i18n.error || 'Error');
            });
    });

    // Remove volunteer role, from the "Volunteer details" summary (distinct class from the
    // Volunteers list table's own .eventadmin-remove-role so both can load on the same page
    // — e.g. the modal opened from the Volunteers list — without double-binding).
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.eventadmin-remove-role-profile');
        if (!btn) return;

        const userId = btn.dataset.userId;
        const name = btn.dataset.name;
        const shiftCount = parseInt(btn.dataset.shiftCount, 10) || 0;

        let msg;
        if (shiftCount > 0) {
            msg = (i18n.remove_confirm || '')
                .replace('{name}', name)
                .replace('{shifts}', shiftCount);
        } else {
            msg = (i18n.remove_confirm_no_shifts || '').replace('{name}', name);
        }

        if (!window.confirm(msg)) return;

        btn.disabled = true;

        fetch(cfg.ajax_url, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'eventadmin_remove_volunteer_role',
                _ajax_nonce: cfg.nonce_remove,
                user_id: userId,
            }),
        })
            .then((r) => r.json())
            .then((res) => {
                if (!res.success) {
                    alert((res.data && res.data.message) || i18n.error || 'Error');
                    btn.disabled = false;
                    return;
                }
                btn.textContent = i18n.role_removed || 'Role removed. Reloading…';
                setTimeout(function () { location.reload(); }, 800);
            })
            .catch(() => {
                alert(i18n.error || 'Error');
                btn.disabled = false;
            });
    });
});

document.addEventListener('DOMContentLoaded', function () {
    const profileModal = document.getElementById('eventadmin-volunteer-profile-modal');
    if (!profileModal || typeof EVENTADMIN_VOLUNTEER_PROFILE === 'undefined') return;

    const cfg = EVENTADMIN_VOLUNTEER_PROFILE;
    const i18n = cfg.i18n || {};
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

    function closeVolunteerProfileModal() {
        profileModal.style.display = 'none';
    }

    if (profileCloseBtn) profileCloseBtn.addEventListener('click', closeVolunteerProfileModal);
    profileModal.addEventListener('click', (e) => {
        if (e.target === profileModal) closeVolunteerProfileModal();
    });
});

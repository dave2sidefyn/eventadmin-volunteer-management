/**
 * EventAdmin Volunteer Management - "I'm new" / "I already have an account" switch
 * Both views are rendered by [eventadmin_register] (includes/registration.php); this swaps
 * between them in place instead of reloading the page. The tabs stay real links, so the
 * ?eventadmin_view=login URL keeps working without JavaScript, and the address bar is
 * updated so a refresh (or a shared link) shows the same view.
 */
document.addEventListener('click', function (e) {
    var tab = e.target.closest ? e.target.closest('[data-eventadmin-view-tab]') : null;
    if (!tab || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
        return; // let modified clicks (new tab etc.) behave like normal links
    }

    var wrapper = tab.closest('.eventadmin-form-wrapper');
    var view = tab.getAttribute('data-eventadmin-view-tab');
    var panel = wrapper && wrapper.querySelector('[data-eventadmin-view="' + view + '"]');
    if (!panel) {
        return;
    }

    e.preventDefault();

    wrapper.querySelectorAll('[data-eventadmin-view-tab]').forEach(function (t) {
        var active = t === tab;
        t.classList.toggle('is-active', active);
        if (active) {
            t.setAttribute('aria-current', 'page');
        } else {
            t.removeAttribute('aria-current');
        }
    });

    wrapper.querySelectorAll('[data-eventadmin-view]').forEach(function (p) {
        p.hidden = p !== panel;
    });

    // Notices from a previous visit (expired link, wrong password) belong to that visit.
    if (view === 'signup') {
        wrapper.querySelectorAll('[data-eventadmin-view="login"] .eventadmin-info, [data-eventadmin-view="login"] .eventadmin-error')
            .forEach(function (n) { n.remove(); });
    }

    if (window.history && history.replaceState) {
        history.replaceState(history.state, '', tab.href);
    }

});

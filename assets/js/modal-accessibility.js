/** Shared keyboard behavior for the admin's existing show/hide modal overlays. */
document.addEventListener('DOMContentLoaded', () => {
    let opener = null;
    const returnTargets = new WeakMap();
    const overlays = [...document.querySelectorAll('.eventadmin-modal-overlay')];
    const visible = overlay => getComputedStyle(overlay).display !== 'none';
    const topmost = () => overlays.filter(visible).at(-1);
    // TinyMCE's link toolbar and WordPress's link dialog render at the end of <body>,
    // outside the overlay; they belong to the open modal's editor, so leave them alone.
    const editorUi = target => Boolean(target?.closest?.('.mce-container, .mce-inline-toolbar-grp, #wp-link-wrap, #wp-link-backdrop, .ui-autocomplete'));
    const focusable = overlay => [...overlay.querySelectorAll('button, a[href], input, select, textarea, iframe, [tabindex]')]
        .filter(el => !el.disabled && el.tabIndex >= 0 && el.getClientRects().length);

    document.addEventListener('click', event => {
        if (!event.target.closest('.eventadmin-modal-overlay')) opener = event.target.closest('button, a, [tabindex]') || document.activeElement;
    }, true);

    function keyboard(event) {
        const overlay = topmost();
        if (!overlay || overlay.querySelector('dialog[open]') || editorUi(event.target)) return;
        if (event.key === 'Escape' && event.target.closest?.('.eventadmin-search-select')?.querySelector('[aria-expanded="true"]')) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            overlay.querySelector('.eventadmin-modal-close')?.click();
        } else if (event.key === 'Tab') {
            const items = focusable(overlay);
            const active = document.activeElement;
            if (!items.length) {
                event.preventDefault();
                overlay.firstElementChild.focus();
            } else if (event.shiftKey && (active === items[0] || !items.includes(active))) {
                event.preventDefault();
                items.at(-1).focus();
            } else if (!event.shiftKey && (active === items.at(-1) || !overlay.contains(active))) {
                event.preventDefault();
                items[0].focus();
            }
        }
    }
    document.addEventListener('keydown', keyboard, true);
    document.addEventListener('focusin', event => {
        const overlay = topmost();
        if (overlay && !overlay.contains(event.target) && !editorUi(event.target)) overlay.firstElementChild.focus();
    });

    overlays.forEach(overlay => {
        const dialog = overlay.firstElementChild;
        const heading = dialog.querySelector('h2, h3');
        if (heading) {
            heading.id ||= overlay.id + '-heading';
            dialog.setAttribute('aria-labelledby', heading.id);
        }
        let wasOpen = false;
        const sync = () => {
            const isOpen = visible(overlay);
            if (isOpen === wasOpen) return;
            wasOpen = isOpen;
            if (isOpen) {
                returnTargets.set(overlay, overlay.contains(document.activeElement) ? opener : document.activeElement);
                if (!overlay.contains(document.activeElement)) dialog.focus();
            } else {
                const target = returnTargets.get(overlay);
                if (target?.isConnected && !topmost()) target.focus();
            }
        };
        new MutationObserver(sync).observe(overlay, {attributes: true, attributeFilter: ['style', 'class']});
        // TinyMCE runs in its own document; Escape there must reach the same handler.
        const connectFrames = () => overlay.querySelectorAll('iframe').forEach(frame => {
            if (frame.dataset.eventadminKeyboard) return;
            frame.dataset.eventadminKeyboard = '1';
            const connect = () => {
                try { frame.contentDocument?.addEventListener('keydown', keyboard, true); } catch (_) { /* Cross-origin frames are not inspected. */ }
            };
            frame.addEventListener('load', connect);
            connect();
        });
        new MutationObserver(connectFrames).observe(overlay, {childList: true, subtree: true});
        connectFrames();
        sync();
    });
});

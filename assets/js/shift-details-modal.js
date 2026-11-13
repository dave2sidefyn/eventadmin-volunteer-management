document.addEventListener('DOMContentLoaded', function () {
    if (typeof EVENTADMIN_SHIFT_EDIT === 'undefined') return;

    const cfg = EVENTADMIN_SHIFT_EDIT;
    const i18n = cfg.i18n || {};
    const editModal = document.getElementById('eventadmin-edit-shift-modal');
    if (!editModal) return;

    const idInput             = document.getElementById('eventadmin-edit-shift-id');
    const titleInput          = document.getElementById('eventadmin-edit-shift-title');
    const categorySelect      = document.getElementById('eventadmin-edit-shift-category');
    const startInput          = document.getElementById('eventadmin-edit-shift-start');
    const endInput            = document.getElementById('eventadmin-edit-shift-end');
    const minInput            = document.getElementById('eventadmin-edit-shift-min');
    const maxInput            = document.getElementById('eventadmin-edit-shift-max');
    const organizerUserSelect = document.getElementById('eventadmin-edit-shift-organizer-user');
    const organizerNameInput  = document.getElementById('eventadmin-edit-shift-organizer-name');
    const organizerEmailInput = document.getElementById('eventadmin-edit-shift-organizer-email');
    const errorEl             = document.getElementById('eventadmin-edit-shift-error');
    const closeBtn            = document.getElementById('eventadmin-edit-shift-close');
    const form                = document.getElementById('eventadmin-edit-shift-form');
    const headingEl           = document.getElementById('eventadmin-edit-shift-heading');
    const submitBtn           = document.getElementById('eventadmin-edit-shift-submit');
    const newShiftBtn         = document.getElementById('eventadmin-open-new-shift-modal');
    const readonlyEl          = document.getElementById('eventadmin-shift-readonly');
    const readonlyPeriod      = document.getElementById('eventadmin-shift-readonly-period');
    const readonlyDepartment  = document.getElementById('eventadmin-shift-readonly-department');
    const readonlyCapacity    = document.getElementById('eventadmin-shift-readonly-capacity');
    const rosterEl            = document.getElementById('eventadmin-shift-roster');
    const rosterSection       = document.getElementById('eventadmin-shift-roster-section');
    const saveButtonLabel     = submitBtn ? submitBtn.textContent : '';
    const DESCRIPTION_EDITOR_ID = 'eventadmin_edit_shift_description';

    let isCreateMode = false;
    let rosterBusy = false;
    let rosterChanged = false;
    let rosterContext = '';
    let rosterPicker = null;
    const removalDialog = document.getElementById('eventadmin-remove-confirm');
    function confirmRemoval(button) {
        const name = button.closest('tr').querySelector('.eventadmin-view-volunteer-profile').textContent;
        const notify = document.getElementById('eventadmin-remove-notify');
        document.getElementById('eventadmin-remove-confirm-question').textContent = (i18n.confirmRemove || 'Remove %s from this shift?').replace('%s', name);
        document.getElementById('eventadmin-remove-confirm-context').textContent = rosterContext;
        document.getElementById('eventadmin-remove-notify-row').hidden = button.dataset.canNotify !== '1';
        notify.checked = false;
        removalDialog.returnValue = '';
        return new Promise(resolve => {
            removalDialog.addEventListener('close', () => {
                resolve(removalDialog.returnValue === 'remove' ? {notify: button.dataset.canNotify === '1' && notify.checked} : null);
                if (button.isConnected) button.focus();
            }, {once: true});
            removalDialog.showModal();
        });
    }
    document.getElementById('eventadmin-remove-cancel').addEventListener('click', () => removalDialog.close('cancel'));
    document.getElementById('eventadmin-remove-submit').addEventListener('click', () => removalDialog.close('remove'));

    function renderRoster(data) {
        rosterContext = [data.title, data.period].filter(Boolean).join(' — ');
        rosterEl.innerHTML = data.roster_html;
        const select = rosterEl.querySelector('.eventadmin-roster-select');
        rosterPicker = select ? eventadminSearchableSelect(select) : null;
    }
    rosterEl.addEventListener('click', async event => {
        const reveal = event.target.closest('.eventadmin-roster-reveal');
        const cancel = event.target.closest('.eventadmin-roster-add-cancel');
        if (reveal || cancel) {
            if (rosterBusy) return;
            const panel = rosterEl.querySelector('.eventadmin-roster-add-panel');
            const trigger = rosterEl.querySelector('.eventadmin-roster-reveal');
            panel.hidden = Boolean(cancel);
            trigger.hidden = !cancel;
            trigger.setAttribute('aria-expanded', String(!cancel));
            if (cancel) {
                rosterEl.querySelector('.eventadmin-roster-select').value = '';
                rosterEl.querySelector('.eventadmin-roster-notify').checked = false;
                rosterEl.querySelector('.eventadmin-roster-message').textContent = '';
                rosterPicker.refresh();
                trigger.focus();
            } else rosterEl.querySelector('.eventadmin-search-select-trigger')?.focus();
            return;
        }
        const button = event.target.closest('.eventadmin-roster-add, .eventadmin-roster-remove');
        if (!button || rosterBusy) return;
        const shiftId = idInput.value;
        const select = rosterEl.querySelector('.eventadmin-roster-select');
        const removing = button.classList.contains('eventadmin-roster-remove');
        const userId = removing ? button.dataset.userId : select?.value;
        if (!userId) {
            rosterEl.querySelector('.eventadmin-search-select-trigger')?.click();
            return;
        }
        let notifyVolunteer = false;
        if (removing) {
            rosterBusy = true;
            const confirmation = await confirmRemoval(button);
            rosterBusy = false;
            if (!confirmation || idInput.value !== shiftId || editModal.style.display === 'none') return;
            notifyVolunteer = confirmation.notify;
        } else {
            notifyVolunteer = Boolean(rosterEl.querySelector('.eventadmin-roster-notify')?.checked);
        }
        const message = rosterEl.querySelector('.eventadmin-roster-message');
        const buttons = [...rosterEl.querySelectorAll('button')];
        rosterBusy = true; buttons.forEach(item => { item.disabled = true; });
        message.textContent = i18n.loading || 'Loading…';
        try {
            const response = await fetch(cfg.ajax_url, {
                method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({action: 'eventadmin_change_shift_roster', nonce: cfg.nonce,
                    shift_id: shiftId, user_id: userId, operation: removing ? 'unassign' : 'assign',
                    notify_volunteer: notifyVolunteer ? '1' : '',
                    show_open: cfg.show_open ? '1' : ''}),
            }).then(result => result.json());
            if (!response.success) throw new Error(response.data?.message || i18n.error);
            rosterChanged = true;
            if (typeof EVENTADMIN_SHIFT_INFO !== 'undefined' && EVENTADMIN_SHIFT_INFO[shiftId]) {
                EVENTADMIN_SHIFT_INFO[shiftId].assigned = response.data.assigned;
            }
            if (typeof window.eventadminOnShiftRosterChanged === 'function') {
                // Numeric, like eventadminOnShiftSaved — timeline rows compare shift_id strictly.
                window.eventadminOnShiftRosterChanged(Number(shiftId), response.data);
            }
            const details = await fetchShiftDetails(shiftId);
            if (!details.success) throw new Error(details.data?.message || i18n.error);
            if (idInput.value === shiftId && editModal.style.display !== 'none') {
                renderRoster(details.data);
                readonlyCapacity.textContent = details.data.capacity;
                rosterEl.querySelector(removing ? '.eventadmin-roster-reveal' : '.eventadmin-roster-remove')?.focus();
            }
        } catch (error) {
            message.textContent = error.message || i18n.error || 'Error';
        } finally {
            rosterBusy = false; buttons.forEach(item => { item.disabled = false; });
        }
    });

    // The description field is a wp_editor() (TinyMCE) instance — it only syncs to its
    // underlying textarea on blur/save, not on every keystroke, and setting the textarea's
    // .value directly doesn't update the visible TinyMCE iframe. Both directions need to go
    // through the tinymce API when the editor is active, falling back to the plain textarea
    // when running in "Text" mode (or before TinyMCE has finished initializing).
    function getDescriptionValue() {
        const editor = window.tinymce && window.tinymce.get(DESCRIPTION_EDITOR_ID);
        if (editor && !editor.isHidden()) return editor.getContent();
        const textarea = document.getElementById(DESCRIPTION_EDITOR_ID);
        return textarea ? textarea.value : '';
    }

    function setDescriptionValue(html) {
        const editor = window.tinymce && window.tinymce.get(DESCRIPTION_EDITOR_ID);
        if (editor) {
            editor.setContent(html || '');
        }
        const textarea = document.getElementById(DESCRIPTION_EDITOR_ID);
        if (textarea) textarea.value = html || '';
    }

    // TinyMCE sizes its iframe from the container's layout at init time — since this modal
    // starts (and returns to) display:none, the editor would otherwise be measured as
    // 0-width. Repainting once the modal is actually visible fixes that.
    function repaintDescriptionEditor() {
        const editor = window.tinymce && window.tinymce.get(DESCRIPTION_EDITOR_ID);
        if (editor) editor.execCommand('mceRepaint');
    }

    // "start"/"end" are wall-clock times encoded as UTC (see eventadmin_wallclock_to_ts())
    // — format via UTC getters for the same reason, so the datetime-local input shows the
    // shift's actual site-local time rather than shifting it by the browser's own timezone.
    function tsToLocalInputValue(tsSeconds) {
        const d = new Date(tsSeconds * 1000);
        const pad = (n) => String(n).padStart(2, '0');
        return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()) + 'T'
            + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes());
    }

    function fillForm(shift) {
        categorySelect.value = shift.category_id || '0';
        titleInput.value = shift.title;
        startInput.value = tsToLocalInputValue(shift.start);
        endInput.value = tsToLocalInputValue(shift.end);
        minInput.value = shift.min;
        maxInput.value = shift.max;
        setDescriptionValue(shift.description);
        organizerUserSelect.value = shift.organizer_user_id || '';
        organizerNameInput.value = shift.organizer_name || '';
        organizerEmailInput.value = shift.organizer_email || '';
    }

    function showEditMode(heading) {
        readonlyEl.style.display = 'none';
        form.style.display = '';
        headingEl.textContent = heading;
        submitBtn.textContent = saveButtonLabel;
    }

    function showReadonlyMode(title, data) {
        form.style.display = 'none';
        readonlyEl.style.display = '';
        headingEl.textContent = title || '';
        readonlyPeriod.textContent = data.period || '';
        readonlyDepartment.textContent = data.department_names || '—';
        readonlyCapacity.textContent = data.capacity || '';
    }

    function fetchShiftDetails(shiftId) {
        return fetch(cfg.ajax_url, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'eventadmin_get_shift_details',
                nonce: cfg.nonce,
                shift_id: shiftId,
            }),
        }).then((r) => r.json());
    }

    // Opens the shift modal. When this session already has the shift's fields cached
    // locally (cfg.shifts — only ever populated on the Manager/Timeline view), the form
    // appears instantly; everywhere else (a volunteer's profile, the Overview dashboard),
    // and always for the roster, the details come from a small AJAX fetch.
    window.eventadminOpenEditShiftModal = function (shiftId) {
        isCreateMode = false;
        rosterSection.style.display = '';
        errorEl.textContent = '';
        idInput.value = shiftId;
        rosterEl.innerHTML = '<p class="eventadmin-profile-empty">' + (i18n.loading || 'Loading…') + '</p>';
        editModal.style.display = 'block';

        const cached = cfg.shifts[shiftId];
        if (cached) {
            showEditMode(i18n.editShift || 'Edit shift');
            fillForm(cached);
            setTimeout(repaintDescriptionEditor, 0);
        }

        fetchShiftDetails(shiftId)
            .then((res) => {
                if (!res.success) {
                    rosterEl.innerHTML = '<p class="eventadmin-profile-empty">'
                        + ((res.data && res.data.message) || i18n.error || 'Error') + '</p>';
                    if (!cached) headingEl.textContent = '';
                    return;
                }
                const data = res.data;
                if (String(shiftId) !== idInput.value || isCreateMode) return;
                renderRoster(data);

                if (!cached) {
                    if (data.can_edit) {
                        showEditMode(data.title);
                        fillForm(data);
                        // Registers the shift locally too, so re-opening it in this same
                        // session (including from the Manager/Timeline view, if this
                        // session also has that open elsewhere) takes the fast path next.
                        cfg.shifts[shiftId] = {
                            title: data.title,
                            category_id: data.category_id,
                            start: data.start,
                            end: data.end,
                            min: data.min,
                            max: data.max,
                            description: data.description,
                            organizer_user_id: data.organizer_user_id,
                            organizer_name: data.organizer_name,
                            organizer_email: data.organizer_email,
                        };
                        setTimeout(repaintDescriptionEditor, 0);
                    } else {
                        showReadonlyMode(data.title, data);
                    }
                }
            })
            .catch(() => {
                rosterEl.innerHTML = '<p class="eventadmin-profile-empty">' + (i18n.error || 'Error') + '</p>';
            });
    };

    // Opens the same modal empty, defaulting the date to whatever the page's own "Date"
    // filter is currently scoped to (falling back to today) — the common case is adding a
    // shift to the day you're already looking at. Only ever triggered from the
    // Manager/Timeline view's own "+ Add shift" button.
    window.eventadminOpenNewShiftModal = function () {
        const pad = (n) => String(n).padStart(2, '0');
        const now = new Date();
        const hasFilterDate = cfg.default_date && /^\d{4}-\d{2}-\d{2}$/.test(cfg.default_date);

        let startValue, endValue;
        if (hasFilterDate) {
            // A specific day is already selected in the "Date" filter — "now" doesn't mean
            // anything for a different day, so just default to a plausible time.
            startValue = cfg.default_date + 'T09:00';
            endValue = cfg.default_date + 'T11:00';
        } else {
            // No date filter active: default to "starting soon" (now, rounded up to the
            // next half hour) rather than a fixed time, so a shift created this afternoon
            // doesn't default to a morning slot that's already in the past.
            const start = new Date(now.getTime() + (30 - (now.getMinutes() % 30)) * 60000);
            start.setSeconds(0, 0);
            const end = new Date(start.getTime() + 2 * 60 * 60000);
            const fmt = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
            startValue = fmt(start);
            endValue = fmt(end);
        }

        isCreateMode = true;
        rosterSection.style.display = 'none';
        errorEl.textContent = '';
        idInput.value = '';
        showEditMode((i18n.addShift || 'Add shift'));
        submitBtn.textContent = (i18n.addShift || 'Add shift');
        categorySelect.value = '0';
        titleInput.value = '';
        startInput.value = startValue;
        endInput.value = endValue;
        minInput.value = 0;
        maxInput.value = 1;
        setDescriptionValue('');
        organizerUserSelect.value = '';
        organizerNameInput.value = '';
        organizerEmailInput.value = '';
        rosterEl.innerHTML = '';

        editModal.style.display = 'block';
        setTimeout(repaintDescriptionEditor, 0);
        titleInput.focus();
    };

    if (newShiftBtn) {
        newShiftBtn.addEventListener('click', () => window.eventadminOpenNewShiftModal());
    }

    // Opens the same modal in "create" mode, pre-filled from an existing shift (used by the
    // Manager/Timeline view's "Duplicate" popover action) — same date/time/department/
    // capacity/organizer as the original, so the common case (nudging it to a different day)
    // is a quick edit rather than starting from a blank form. Nothing about the original
    // shift, including its volunteers, is touched until Save is clicked on this new one.
    window.eventadminOpenDuplicateShiftModal = function (shiftId) {
        isCreateMode = true;
        rosterSection.style.display = 'none';
        errorEl.textContent = '';
        idInput.value = '';
        rosterEl.innerHTML = '';
        showEditMode(i18n.duplicateShift || 'Duplicate');
        submitBtn.textContent = i18n.duplicateShift || 'Duplicate';
        editModal.style.display = 'block';

        const applyData = (data) => {
            fillForm(data);
            titleInput.value = (data.title || '') + (i18n.copySuffix || ' (Copy)');
            setTimeout(repaintDescriptionEditor, 0);
            titleInput.focus();
        };

        const cached = cfg.shifts[shiftId];
        if (cached) {
            applyData(cached);
            return;
        }

        fetchShiftDetails(shiftId)
            .then((res) => {
                if (!res.success) {
                    errorEl.textContent = (res.data && res.data.message) || i18n.error || 'Error';
                    return;
                }
                applyData(res.data);
            })
            .catch(() => {
                errorEl.textContent = i18n.error || 'Error';
            });
    };

    window.eventadminCloseShiftDetailsModal = function () {
        editModal.style.display = 'none';
    };

    // Refresh non-timeline views once all drill-down dialogs are closed. Opening a
    // volunteer profile from the updated roster must remain possible first.
    document.addEventListener('click', event => {
        if (!rosterChanged || typeof window.eventadminOnShiftRosterChanged === 'function') return;
        if (!event.target.closest('.eventadmin-modal-close') && !event.target.classList.contains('eventadmin-modal-overlay')) return;
        requestAnimationFrame(() => {
            const anyOpen = [...document.querySelectorAll('.eventadmin-modal-overlay')]
                .some(overlay => getComputedStyle(overlay).display !== 'none');
            if (!anyOpen) window.location.reload();
        });
    });
    if (closeBtn) closeBtn.addEventListener('click', window.eventadminCloseShiftDetailsModal);
    editModal.addEventListener('click', (e) => {
        if (e.target === editModal) window.eventadminCloseShiftDetailsModal();
    });

    // Opens this modal from a shift-title trigger anywhere (a volunteer's Upcoming/Past
    // shifts tables, the Overview dashboard's activity feed) — closes the volunteer
    // profile modal first if that's what's currently open, so only one modal shows at once.
    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('.eventadmin-view-shift-details');
        if (!trigger) return;
        if (typeof window.eventadminCloseVolunteerProfileModal === 'function') {
            window.eventadminCloseVolunteerProfileModal();
        }
        window.eventadminOpenEditShiftModal(trigger.dataset.shiftId);
    });

    // eventadmin-edit-shift-form is a <div>, not a <form> (this modal also renders on
    // user-edit.php, itself one big native <form> — a nested one is invalid HTML and gets
    // silently dropped by the browser), so Save is a plain button click, and the fields
    // that used to rely on the "required" attribute are checked by hand instead.
    submitBtn.addEventListener('click', () => {
        errorEl.textContent = '';

        if (!titleInput.value.trim() || !startInput.value || !endInput.value) {
            errorEl.textContent = i18n.requiredFields || 'Title, start and end are required.';
            return;
        }

        const shiftId = isCreateMode ? 0 : parseInt(idInput.value, 10);

        fetch(cfg.ajax_url, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: isCreateMode ? 'eventadmin_create_shift' : 'eventadmin_update_shift',
                nonce: cfg.nonce,
                shift_id: shiftId,
                title: titleInput.value,
                category_id: categorySelect.value,
                start: startInput.value.replace('T', ' '),
                end: endInput.value.replace('T', ' '),
                min: minInput.value,
                max: maxInput.value,
                description: getDescriptionValue(),
                shift_organizer_user_id: organizerUserSelect.value,
                shift_organizer_name: organizerNameInput.value,
                shift_organizer_email: organizerEmailInput.value,
                show_open: cfg.show_open ? '1' : '0',
            }),
        })
            .then((r) => r.json())
            .then((res) => {
                if (!res.success) {
                    errorEl.textContent = (res.data && res.data.message) || i18n.error || 'Error';
                    return;
                }
                const data = res.data;

                if (isCreateMode) {
                    cfg.shifts[data.shift_id] = {
                        title: data.title,
                        category_id: data.category_id,
                        start: data.start,
                        end: data.end,
                        min: data.min,
                        max: data.max,
                        description: data.description,
                        organizer_user_id: data.organizer_user_id,
                        organizer_name: data.organizer_name,
                        organizer_email: data.organizer_email,
                    };
                    // Registers the new shift with the "Add volunteer" modal (Table and
                    // Timeline views both use this shared map) — without it, clicking the
                    // new shift's open-slot bar to add someone would silently do nothing
                    // until the page was reloaded.
                    if (typeof EVENTADMIN_SHIFT_INFO !== 'undefined') {
                        EVENTADMIN_SHIFT_INFO[data.shift_id] = {title: data.title, period: data.period, capacity: data.capacity, assigned: []};
                    }
                    if (typeof window.eventadminOnShiftCreated === 'function') {
                        window.eventadminOnShiftCreated(data.shift_id, data);
                    } else {
                        window.eventadminCloseShiftDetailsModal();
                    }
                    return;
                }

                cfg.shifts[shiftId] = {
                    title: data.title,
                    category_id: data.category_id,
                    start: data.start,
                    end: data.end,
                    min: data.min,
                    max: data.max,
                    description: data.description,
                    organizer_user_id: data.organizer_user_id,
                    organizer_name: data.organizer_name,
                    organizer_email: data.organizer_email,
                };
                if (typeof EVENTADMIN_SHIFT_INFO !== 'undefined' && EVENTADMIN_SHIFT_INFO[shiftId]) {
                    Object.assign(EVENTADMIN_SHIFT_INFO[shiftId], {title: data.title, period: data.period, capacity: data.capacity});
                }
                if (typeof window.eventadminOnShiftSaved === 'function') {
                    window.eventadminOnShiftSaved(shiftId, data);
                }
                window.eventadminCloseShiftDetailsModal();
            })
            .catch(() => {
                errorEl.textContent = i18n.error || 'Error';
            });
    });
});

document.addEventListener('DOMContentLoaded', function () {

    document.querySelectorAll(".toggle-volunteer-form").forEach(btn => {
        btn.addEventListener("click", function (e) {
            e.preventDefault();
            const target = document.querySelector(this.dataset.target);
            if (target) {
                target.style.display = target.style.display === "none" ? "block" : "none";
            }
        });
    });

    // Shared "add volunteer" modal for the Table and Timeline views.
    const addVolunteerModal = document.getElementById('eventadmin-add-volunteer-modal');
    if (addVolunteerModal && typeof EVENTADMIN_VOLUNTEERS !== 'undefined' && typeof EVENTADMIN_SHIFT_INFO !== 'undefined') {
        const modalShiftTitle    = document.getElementById('eventadmin-modal-shift-title');
        const modalExistingSelect = document.getElementById('eventadmin-modal-existing-select');
        const modalShiftIdExisting = document.getElementById('eventadmin-modal-shift-id-existing');
        const modalShiftIdNew    = document.getElementById('eventadmin-modal-shift-id-new');
        const modalCloseBtn      = document.getElementById('eventadmin-modal-close');
        const modalPlaceholderText = modalExistingSelect.options.length ? modalExistingSelect.options[0].textContent : '';

        window.eventadminOpenAddVolunteerModal = function (shiftId) {
            const info = EVENTADMIN_SHIFT_INFO[shiftId];
            if (!info) return;

            modalShiftTitle.textContent = info.title;
            modalShiftIdExisting.value = shiftId;
            modalShiftIdNew.value = shiftId;

            modalExistingSelect.innerHTML = '';
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = modalPlaceholderText;
            modalExistingSelect.appendChild(placeholder);

            EVENTADMIN_VOLUNTEERS
                .filter(v => !info.assigned.includes(v.id))
                .sort((a, b) => a.label.localeCompare(b.label))
                .forEach(v => {
                    const opt = document.createElement('option');
                    opt.value = v.id;
                    opt.textContent = v.label;
                    modalExistingSelect.appendChild(opt);
                });

            addVolunteerModal.style.display = 'block';
        };

        function closeAddVolunteerModal() {
            addVolunteerModal.style.display = 'none';
        }

        if (modalCloseBtn) modalCloseBtn.addEventListener('click', closeAddVolunteerModal);
        addVolunteerModal.addEventListener('click', (e) => {
            if (e.target === addVolunteerModal) closeAddVolunteerModal();
        });

        document.querySelectorAll('.eventadmin-open-slot-add').forEach(btn => {
            btn.addEventListener('click', () => {
                window.eventadminOpenAddVolunteerModal(parseInt(btn.dataset.shiftId, 10));
            });
        });
    }

    // Dashboard tab charts — only present when that tab is active, so this whole block is
    // skipped (not an early-return) on other tabs like Table/Timeline, which have their
    // own independent chart setup below.
    const ctx = document.getElementById('eventadmin-chart');
    if (ctx && typeof EVENTADMIN_VOLUNTEER_STATS !== 'undefined') {
        const i18n = EVENTADMIN_VOLUNTEER_STATS.i18n;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: EVENTADMIN_VOLUNTEER_STATS.labels,
                datasets: [
                    {
                        label: i18n.filled,
                        data: EVENTADMIN_VOLUNTEER_STATS.data_filled,
                        backgroundColor: '#4caf50'
                    },
                    {
                        label: i18n.required_open,
                        data: EVENTADMIN_VOLUNTEER_STATS.data_required_open,
                        backgroundColor: '#e53935'
                    },
                    {
                        label: i18n.optional_open,
                        data: EVENTADMIN_VOLUNTEER_STATS.data_optional_open,
                        backgroundColor: '#9e9e9e'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: i18n.util_dept
                    }
                },
                scales: {
                    x: {stacked: true},
                    y: {stacked: true}
                }
            }
        });

        const ctx2 = document.getElementById('eventadmin-chart-auslastung').getContext('2d');

        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: [i18n.filled, i18n.required_open, i18n.optional_open],
                datasets: [{
                    data: [
                        EVENTADMIN_VOLUNTEER_STATS.stats.filled_shifts,
                        EVENTADMIN_VOLUNTEER_STATS.stats.required_open_shifts,
                        EVENTADMIN_VOLUNTEER_STATS.stats.optional_open_shifts
                    ],
                    backgroundColor: ['#4caf50', '#e53935', '#9e9e9e']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    title: {
                        display: true,
                        text: i18n.util_all
                    }
                }
            }
        });
    }

    const timelineCtx = document.getElementById('eventadmin-timeline-chart');
    if (!timelineCtx || typeof EVENTADMIN_TIMELINE_DATA === 'undefined') return;

    const rows = EVENTADMIN_TIMELINE_DATA.rows;
    const timelineI18n = EVENTADMIN_TIMELINE_DATA.i18n;
    // r.start/r.end are wall-clock times encoded as if they were UTC (see
    // eventadmin_wallclock_to_ts() on the PHP side) — timeZone: 'UTC' here is what makes
    // that encoding round-trip correctly regardless of the browser's own system timezone,
    // rather than silently shifting bars by the difference between the two.
    const dateFormatter = new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'UTC',
    });

    // Bar charts default their value axis to include zero, which would compress
    // these (very large) millisecond timestamps into an invisible sliver — bound
    // the axis to the actual data range instead, with a small margin either side.
    const xMin = Math.min(...rows.map(r => r.start * 1000));
    const xMax = Math.max(...rows.map(r => r.end * 1000));
    const xPad = (xMax - xMin) * 0.02 || 30 * 60 * 1000;

    const HOUR_MS = 3600000;
    const NICE_HOUR_STEPS = [1, 2, 3, 4, 6, 8, 12, 24, 48];

    let selectedIndex = null;
    const selectedInfoEl = document.getElementById('eventadmin-timeline-selected');

    function updateSelectedInfo() {
        if (!selectedInfoEl) return;
        if (selectedIndex === null) {
            selectedInfoEl.textContent = '';
            return;
        }
        const row = rows[selectedIndex];
        selectedInfoEl.textContent = timelineI18n.selected
            .replace('{volunteer}', row.volunteer)
            .replace('{shift}', row.shift)
            .replace('{period}', row.period)
            .replace('{capacity}', row.capacity);
    }

    // Converts a #rrggbb (or #rgb) color to an rgba() string at the given alpha —
    // used to give bars a softer fill while keeping a fully-saturated border, for a
    // lighter, more layered look than a flat opaque fill.
    function hexToRgba(hex, alpha) {
        let h = hex.replace('#', '');
        if (h.length === 3) h = h.split('').map(c => c + c).join('');
        const num = parseInt(h, 16);
        const r = (num >> 16) & 255, g = (num >> 8) & 255, b = num & 255;
        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
    }

    // Draws the shift name directly on each bar, clipped to the bar's own (rounded) shape.
    const barLabelPlugin = {
        id: 'eventadminBarLabel',
        afterDatasetsDraw(chart) {
            const {ctx} = chart;
            const meta = chart.getDatasetMeta(0);
            meta.data.forEach((bar, index) => {
                const left = Math.min(bar.x, bar.base);
                const right = Math.max(bar.x, bar.base);
                if (right - left < 28) return;

                ctx.save();
                ctx.beginPath();
                ctx.roundRect(left, bar.y - bar.height / 2, right - left, bar.height, 6);
                ctx.clip();
                ctx.font = '600 11px -apple-system, system-ui, sans-serif';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = '#fff';
                ctx.fillText(rows[index].shift, left + 8, bar.y);
                ctx.restore();
            });
        },
    };

    // Highlights the clicked row so it's easy to trace which volunteer a bar belongs to.
    const rowHighlightPlugin = {
        id: 'eventadminRowHighlight',
        beforeDatasetsDraw(chart) {
            if (selectedIndex === null) return;
            const {ctx, chartArea, scales} = chart;
            const yScale = scales.y;
            const rowSpan = (yScale.bottom - yScale.top) / rows.length;
            const center = yScale.getPixelForTick(selectedIndex);
            ctx.save();
            ctx.fillStyle = 'rgba(255, 200, 0, 0.18)';
            ctx.beginPath();
            ctx.roundRect(chartArea.left, center - rowSpan / 2 + 2, chartArea.right - chartArea.left, rowSpan - 4, 8);
            ctx.fill();
            ctx.restore();
        },
    };

    const timelineChart = new Chart(timelineCtx, {
        type: 'bar',
        data: {
            labels: rows.map(r => r.volunteer),
            datasets: [{
                data: rows.map(r => [r.start * 1000, r.end * 1000]),
                backgroundColor: rows.map(r => hexToRgba(r.color, 0.88)),
                borderColor: rows.map(r => r.color),
                borderWidth: 1.5,
                borderRadius: 6,
                borderSkipped: false,
                barPercentage: 0.7,
                categoryPercentage: 0.9,
            }],
        },
        plugins: [rowHighlightPlugin, barLabelPlugin],
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            events: [], // all interaction is handled by our own mousedown/mousemove/mouseup below
            plugins: {
                legend: {display: false},
                // Replaced by direct drag-to-move/resize and click-to-edit interaction.
                tooltip: {enabled: false},
            },
            scales: {
                x: {
                    type: 'linear',
                    min: xMin - xPad,
                    max: xMax + xPad,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.06)',
                        drawTicks: false,
                    },
                    ticks: {
                        padding: 8,
                        callback: (value) => dateFormatter.format(new Date(value)),
                        // Always land on whole-hour boundaries, picking a step (1h, 2h, 3h, …)
                        // that keeps the tick count readable across the full data range.
                        source: 'data',
                    },
                    afterBuildTicks: (scale) => {
                        const rangeHours = (scale.max - scale.min) / HOUR_MS;
                        const step = NICE_HOUR_STEPS.find(s => rangeHours / s <= 10) || NICE_HOUR_STEPS[NICE_HOUR_STEPS.length - 1];
                        const stepMs = step * HOUR_MS;
                        const first = Math.ceil(scale.min / stepMs) * stepMs;
                        const ticks = [];
                        for (let t = first; t <= scale.max; t += stepMs) {
                            ticks.push({value: t});
                        }
                        scale.ticks = ticks;
                    },
                },
                y: {
                    grid: {
                        display: false,
                    },
                    ticks: {
                        autoSkip: false,
                        font: {size: 12},
                        color: (context) => {
                            if (context.index === selectedIndex) return '#d63638';
                            if (rows[context.index] && rows[context.index].open) return '#bbb';
                            return undefined;
                        },
                    },
                },
            },
        },
    });

    // --- Drag-to-move/resize and click-to-edit -----------------------------------------
    // Chart.js has no built-in support for dragging a bar, so bar interaction is handled
    // entirely by hand: hit-test on mousedown, track the drag on mousemove, and on mouseup
    // either persist the drag (if the pointer actually moved) or treat it as a click.

    const EDGE_PX = 8; // distance from a bar's edge that counts as grabbing that edge
    const CLICK_THRESHOLD_PX = 4; // pointer movement below this is still a click, not a drag
    const MIN_DURATION_MS = 5 * 60 * 1000; // shifts can't be dragged/resized shorter than this

    function getBarAt(evt) {
        const rect = timelineCtx.getBoundingClientRect();
        const x = evt.clientX - rect.left;
        const y = evt.clientY - rect.top;
        const meta = timelineChart.getDatasetMeta(0);
        for (let i = 0; i < meta.data.length; i++) {
            const bar = meta.data[i];
            if (y < bar.y - bar.height / 2 || y > bar.y + bar.height / 2) continue;
            const left = Math.min(bar.x, bar.base);
            const right = Math.max(bar.x, bar.base);
            if (x < left - EDGE_PX || x > right + EDGE_PX) continue;
            return {index: i, left, right, x};
        }
        return null;
    }

    // Formats a unix timestamp (seconds) as "Y-m-d H:i:s" using UTC getters — since these
    // timestamps are wall-clock times encoded as UTC (see eventadmin_wallclock_to_ts()),
    // not real UTC instants, using the browser's *local* getters here would silently shift
    // the saved time by the difference between the browser's zone and the site's.
    function tsToDatetimeString(tsSeconds) {
        const d = new Date(tsSeconds * 1000);
        const pad = (n) => String(n).padStart(2, '0');
        return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()) + ' '
            + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()) + ':' + pad(d.getUTCSeconds());
    }

    let pointerState = null;

    timelineCtx.addEventListener('mousedown', (evt) => {
        const hit = getBarAt(evt);
        const state = {hit, startXpx: evt.clientX, moved: false};

        if (hit) {
            const row = rows[hit.index];
            let mode = 'move';
            if (Math.abs(hit.x - hit.left) <= EDGE_PX) mode = 'resize-start';
            else if (Math.abs(hit.x - hit.right) <= EDGE_PX) mode = 'resize-end';

            // All rows belonging to the same shift (one per volunteer/open slot) must move
            // together, since they represent the same underlying start/end time.
            const indices = rows.reduce((acc, r, i) => {
                if (r.shift_id === row.shift_id) acc.push(i);
                return acc;
            }, []);
            const startValues = {};
            indices.forEach((i) => { startValues[i] = [rows[i].start, rows[i].end]; });

            state.mode = mode;
            state.shiftId = row.shift_id;
            state.indices = indices;
            state.startValues = startValues;
        }

        pointerState = state;
        evt.preventDefault();
    });

    document.addEventListener('mousemove', (evt) => {
        if (!pointerState) return;
        if (Math.abs(evt.clientX - pointerState.startXpx) > CLICK_THRESHOLD_PX) {
            pointerState.moved = true;
        }
        if (!pointerState.hit || !pointerState.moved) return;

        const xScale = timelineChart.scales.x;
        const rect = timelineCtx.getBoundingClientRect();
        const deltaMs = xScale.getValueForPixel(evt.clientX - rect.left) - xScale.getValueForPixel(pointerState.startXpx - rect.left);

        pointerState.indices.forEach((i) => {
            const [origStartS, origEndS] = pointerState.startValues[i];
            let newStart = origStartS * 1000;
            let newEnd = origEndS * 1000;
            if (pointerState.mode === 'move') {
                newStart += deltaMs;
                newEnd += deltaMs;
            } else if (pointerState.mode === 'resize-start') {
                newStart = Math.min(newStart + deltaMs, newEnd - MIN_DURATION_MS);
            } else {
                newEnd = Math.max(newEnd + deltaMs, newStart + MIN_DURATION_MS);
            }
            rows[i].start = Math.round(newStart / 1000);
            rows[i].end = Math.round(newEnd / 1000);
            timelineChart.data.datasets[0].data[i] = [newStart, newEnd];
        });
        timelineChart.update('none');
    });

    document.addEventListener('mouseup', () => {
        if (!pointerState) return;
        const state = pointerState;
        pointerState = null;

        if (!state.hit) {
            // Click on empty space — deselect.
            if (state.moved) return;
            selectedIndex = null;
            updateSelectedInfo();
            timelineChart.update();
            return;
        }

        if (!state.moved) {
            handleBarClick(state.hit.index);
            return;
        }

        // Remember the pre-drag times so a successful save can still be undone afterwards.
        const originalValues = {};
        state.indices.forEach((i) => { originalValues[i] = state.startValues[i]; });

        persistShiftTime(state.shiftId, state.indices, originalValues);
    });

    // Applies rows[indices]'s *current* start/end to the chart display (used after both a
    // successful save and an undo).
    function applyRowsToChart(indices) {
        indices.forEach((i) => {
            timelineChart.data.datasets[0].data[i] = [rows[i].start * 1000, rows[i].end * 1000];
        });
        timelineChart.update();
    }

    // Sends shift_id's new start/end to the server. Resolves with the response data on
    // success (after patching rows[indices] + the shift-edit map + the chart), rejects on
    // failure.
    function saveShiftTime(shiftId, indices, startTs, endTs) {
        const cfg = EVENTADMIN_SHIFT_EDIT;
        return fetch(cfg.ajax_url, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                action: 'eventadmin_update_shift',
                nonce: cfg.nonce,
                shift_id: shiftId,
                start: tsToDatetimeString(startTs),
                end: tsToDatetimeString(endTs),
            }),
        })
            .then((r) => r.json())
            .then((res) => {
                if (!res.success) throw new Error('save failed');
                indices.forEach((i) => {
                    rows[i].start = res.data.start;
                    rows[i].end = res.data.end;
                    rows[i].period = res.data.period;
                });
                if (cfg.shifts[shiftId]) {
                    cfg.shifts[shiftId].start = res.data.start;
                    cfg.shifts[shiftId].end = res.data.end;
                }
                applyRowsToChart(indices);
                return res.data;
            });
    }

    function persistShiftTime(shiftId, indices, originalValues) {
        // EVENTADMIN_SHIFT_EDIT is declared with `const` in its own inline <script> tag,
        // which does not attach it to `window` — reference the bare identifier instead
        // (matching how EVENTADMIN_TIMELINE_DATA is already checked above via typeof).
        if (typeof EVENTADMIN_SHIFT_EDIT === 'undefined') return;
        const cfg = EVENTADMIN_SHIFT_EDIT;
        const firstRow = rows[indices[0]];

        saveShiftTime(shiftId, indices, firstRow.start, firstRow.end)
            .then(() => {
                showUndoToast((cfg.i18n && cfg.i18n.timeUpdated) || 'Shift time updated.', function () {
                    const [origStart, origEnd] = originalValues[indices[0]];
                    saveShiftTime(shiftId, indices, origStart, origEnd).catch(function () {
                        window.alert((cfg.i18n && cfg.i18n.error) || 'Error');
                    });
                });
            })
            .catch(() => {
                // Revert the optimistic drag on failure — nothing changed server-side, so
                // the pre-drag value is just whatever the shift-edit map still holds.
                if (cfg.shifts[shiftId]) {
                    indices.forEach((i) => {
                        rows[i].start = cfg.shifts[shiftId].start;
                        rows[i].end = cfg.shifts[shiftId].end;
                    });
                }
                applyRowsToChart(indices);
                window.alert((cfg.i18n && cfg.i18n.error) || 'Error');
            });
    }

    // A small self-dismissing toast with an Undo action, shown after a drag is saved.
    let undoToastTimer = null;
    function showUndoToast(message, onUndo) {
        let toast = document.getElementById('eventadmin-undo-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'eventadmin-undo-toast';
            toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#1e1e1e;color:#fff;'
                + 'padding:12px 16px;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.25);'
                + 'font-size:13px;z-index:100001;display:flex;align-items:center;gap:12px;';
            document.body.appendChild(toast);
        }
        clearTimeout(undoToastTimer);
        toast.innerHTML = '';

        const text = document.createElement('span');
        text.textContent = message;
        toast.appendChild(text);

        const undoBtn = document.createElement('button');
        undoBtn.type = 'button';
        undoBtn.textContent = (EVENTADMIN_SHIFT_EDIT.i18n && EVENTADMIN_SHIFT_EDIT.i18n.undo) || 'Undo';
        undoBtn.style.cssText = 'background:none;border:none;color:#66b3ff;font-weight:600;cursor:pointer;padding:0;font-size:13px;';
        undoBtn.addEventListener('click', function () {
            clearTimeout(undoToastTimer);
            toast.remove();
            onUndo();
        });
        toast.appendChild(undoBtn);

        toast.style.display = 'flex';
        undoToastTimer = setTimeout(function () {
            toast.remove();
        }, 6000);
    }

    function handleBarClick(index) {
        const row = rows[index];
        if (row.open) {
            if (typeof window.eventadminOpenAddVolunteerModal === 'function') {
                window.eventadminOpenAddVolunteerModal(row.shift_id);
            }
            return;
        }
        selectedIndex = index;
        updateSelectedInfo();
        timelineChart.update();
        if (typeof window.eventadminOpenEditShiftModal === 'function') {
            window.eventadminOpenEditShiftModal(row.shift_id);
        }
    }

    // --- Edit Shift modal ----------------------------------------------------------------
    const editModal = document.getElementById('eventadmin-edit-shift-modal');
    if (editModal && typeof EVENTADMIN_SHIFT_EDIT !== 'undefined') {
        const cfg = EVENTADMIN_SHIFT_EDIT;
        const idInput       = document.getElementById('eventadmin-edit-shift-id');
        const titleInput    = document.getElementById('eventadmin-edit-shift-title');
        const categorySelect = document.getElementById('eventadmin-edit-shift-category');
        const startInput    = document.getElementById('eventadmin-edit-shift-start');
        const endInput      = document.getElementById('eventadmin-edit-shift-end');
        const minInput      = document.getElementById('eventadmin-edit-shift-min');
        const maxInput      = document.getElementById('eventadmin-edit-shift-max');
        const errorEl       = document.getElementById('eventadmin-edit-shift-error');
        const closeBtn      = document.getElementById('eventadmin-edit-shift-close');
        const fullLink      = document.getElementById('eventadmin-edit-shift-full-link');
        const form          = document.getElementById('eventadmin-edit-shift-form');

        // "start"/"end" are wall-clock times encoded as UTC (see
        // eventadmin_wallclock_to_ts()) — format via UTC getters for the same reason
        // tsToDatetimeString() above does, so the datetime-local input shows the shift's
        // actual site-local time rather than shifting it by the browser's own timezone.
        function tsToLocalInputValue(tsSeconds) {
            const d = new Date(tsSeconds * 1000);
            const pad = (n) => String(n).padStart(2, '0');
            return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()) + 'T'
                + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes());
        }

        window.eventadminOpenEditShiftModal = function (shiftId) {
            const shift = cfg.shifts[shiftId];
            if (!shift) return;

            errorEl.textContent = '';
            idInput.value = shiftId;
            titleInput.value = shift.title;
            categorySelect.value = shift.category_id || '0';
            startInput.value = tsToLocalInputValue(shift.start);
            endInput.value = tsToLocalInputValue(shift.end);
            minInput.value = shift.min;
            maxInput.value = shift.max;
            fullLink.href = cfg.edit_url_base + shiftId;

            editModal.style.display = 'block';
        };

        function closeEditShiftModal() {
            editModal.style.display = 'none';
        }

        if (closeBtn) closeBtn.addEventListener('click', closeEditShiftModal);
        editModal.addEventListener('click', (e) => {
            if (e.target === editModal) closeEditShiftModal();
        });

        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const shiftId = parseInt(idInput.value, 10);
            errorEl.textContent = '';

            fetch(cfg.ajax_url, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'eventadmin_update_shift',
                    nonce: cfg.nonce,
                    shift_id: shiftId,
                    title: titleInput.value,
                    category_id: categorySelect.value,
                    start: startInput.value.replace('T', ' '),
                    end: endInput.value.replace('T', ' '),
                    min: minInput.value,
                    max: maxInput.value,
                }),
            })
                .then((r) => r.json())
                .then((res) => {
                    if (!res.success) {
                        errorEl.textContent = (res.data && res.data.message) || (cfg.i18n && cfg.i18n.error) || 'Error';
                        return;
                    }
                    const data = res.data;
                    cfg.shifts[shiftId] = {
                        title: data.title,
                        category_id: data.category_id,
                        start: data.start,
                        end: data.end,
                        min: data.min,
                        max: data.max,
                    };
                    rows.forEach((row, i) => {
                        if (row.shift_id !== shiftId) return;
                        row.shift = data.title;
                        row.start = data.start;
                        row.end = data.end;
                        row.period = data.period;
                        row.capacity = data.capacity;
                        if (!row.open) row.color = data.color;
                        timelineChart.data.datasets[0].data[i] = [data.start * 1000, data.end * 1000];
                        timelineChart.data.datasets[0].backgroundColor[i] = hexToRgba(row.color, 0.88);
                        timelineChart.data.datasets[0].borderColor[i] = row.color;
                    });
                    timelineChart.update();
                    closeEditShiftModal();
                })
                .catch(() => {
                    errorEl.textContent = (cfg.i18n && cfg.i18n.error) || 'Error';
                });
        });
    }
});

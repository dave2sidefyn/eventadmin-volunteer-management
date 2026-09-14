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

    // Mobile-only "Filter" toggle — expands/collapses the filter row (see admin-dashboard.css)
    // instead of it always occupying space above the actual content on a narrow screen.
    const filtersToggle = document.getElementById('eventadmin-filters-toggle');
    const filtersForm = document.getElementById('eventadmin-overview-filters');
    if (filtersToggle && filtersForm) {
        filtersToggle.addEventListener('click', () => {
            const isOpen = filtersForm.classList.toggle('is-open');
            filtersToggle.textContent = isOpen ? filtersToggle.dataset.hideLabel : filtersToggle.dataset.showLabel;
        });
    }

    // "+ Add shift" split button (Timeline view only): the dropdown toggle reveals "Copy
    // shifts to another day" instead of that living as its own permanent button.
    const splitButtonToggle = document.getElementById('eventadmin-split-button-toggle');
    const splitButtonMenu = document.getElementById('eventadmin-split-button-menu');
    if (splitButtonToggle && splitButtonMenu) {
        function closeSplitButtonMenu() {
            splitButtonMenu.style.display = 'none';
            splitButtonToggle.setAttribute('aria-expanded', 'false');
        }
        splitButtonToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = splitButtonMenu.style.display !== 'none';
            if (isOpen) {
                closeSplitButtonMenu();
            } else {
                splitButtonMenu.style.display = 'block';
                splitButtonToggle.setAttribute('aria-expanded', 'true');
            }
        });
        document.addEventListener('click', (e) => {
            if (!splitButtonMenu.contains(e.target) && e.target !== splitButtonToggle) {
                closeSplitButtonMenu();
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeSplitButtonMenu();
        });
    }

    // "Copy shifts to another day" modal, present on every view (it sits above the view tabs).
    const copyShiftsModal = document.getElementById('eventadmin-copy-shifts-modal');
    const copyShiftsOpenBtn = document.getElementById('eventadmin-open-copy-shifts-modal');
    if (copyShiftsModal && copyShiftsOpenBtn) {
        const copyShiftsCloseBtn = document.getElementById('eventadmin-copy-shifts-close');
        function closeCopyShiftsModal() {
            copyShiftsModal.style.display = 'none';
        }
        copyShiftsOpenBtn.addEventListener('click', () => {
            if (splitButtonMenu) splitButtonMenu.style.display = 'none';
            copyShiftsModal.style.display = 'block';
        });
        if (copyShiftsCloseBtn) copyShiftsCloseBtn.addEventListener('click', closeCopyShiftsModal);
        copyShiftsModal.addEventListener('click', (e) => {
            if (e.target === copyShiftsModal) closeCopyShiftsModal();
        });
    }

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

    let timelineState = null;

    // Wrapped in its own function (rather than an early return) so the Edit Shift
    // modal below — which now also handles creating a brand-new shift — still works
    // when the Timeline currently has zero rows to chart.
    function eventadminInitTimelineChart() {
        const timelineCtx = document.getElementById('eventadmin-timeline-chart');
        if (!timelineCtx || typeof EVENTADMIN_TIMELINE_DATA === 'undefined') return;

        const rows = EVENTADMIN_TIMELINE_DATA.rows;
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

        // Hour:minute only, used to stamp each bar with its own time range — this is what
        // makes the shift time readable on mobile without having to scroll to the x-axis
        // and compare tick positions.
        const timeFormatter = new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
            hour: '2-digit',
            minute: '2-digit',
            timeZone: 'UTC',
        });

        function formatRowTimeRange(row) {
            return timeFormatter.format(new Date(row.start * 1000)) + '–' + timeFormatter.format(new Date(row.end * 1000));
        }

        // Bar charts default their value axis to include zero, which would compress
        // these (very large) millisecond timestamps into an invisible sliver — bound
        // the axis to the actual data range instead, with a small margin either side.
        const xMin = Math.min(...rows.map(r => r.start * 1000));
        const xMax = Math.max(...rows.map(r => r.end * 1000));
        const xPad = (xMax - xMin) * 0.02 || 30 * 60 * 1000;

        const HOUR_MS = 3600000;
        const NICE_HOUR_STEPS = [1, 2, 3, 4, 6, 8, 12, 24, 48];

        let selectedIndex = null;

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

        // Draws each bar's own time range and shift name directly on or next to the bar.
        // Anchoring the time to the bar itself — rather than relying on the x-axis ticks — is
        // what keeps it readable on a narrow/mobile screen: wherever you scroll to see a bar,
        // its time comes with it, instead of needing to scroll back to compare the bar's
        // position against the axis. The shift name is never dropped, only relocated: it moves
        // outside the bar (after the time) once there isn't room for both inside.
        // Draws time, shift name, and the volunteer's own name (who this bar is; the row's
        // y-axis label carries the same name, but that column scrolls out of view along
        // with everything else on a narrow/mobile screen, so it isn't a reliable place to
        // read it from). Falls back progressively as the bar gets too narrow to hold
        // everything, always keeping as much as possible anchored on the bar itself and
        // never simply hiding a piece — text that doesn't fit moves just past the bar's
        // right edge instead.
        const barLabelPlugin = {
            id: 'eventadminBarLabel',
            afterDatasetsDraw(chart) {
                const {ctx} = chart;
                const meta = chart.getDatasetMeta(0);
                const font = '600 11px -apple-system, system-ui, sans-serif';
                meta.data.forEach((bar, index) => {
                    const row = rows[index];
                    const left = Math.min(bar.x, bar.base);
                    const right = Math.max(bar.x, bar.base);
                    const width = right - left;
                    const timeText = formatRowTimeRange(row);
                    const timeShiftLabel = timeText + '   ' + row.shift;
                    const fullLabel = timeShiftLabel + '   ' + row.volunteer;

                    ctx.save();
                    ctx.font = font;
                    ctx.textBaseline = 'middle';
                    const timeWidth = ctx.measureText(timeText).width;
                    const timeShiftWidth = ctx.measureText(timeShiftLabel).width;
                    const fullWidth = ctx.measureText(fullLabel).width;

                    function drawInside(text) {
                        ctx.beginPath();
                        ctx.roundRect(left, bar.y - bar.height / 2, width, bar.height, 6);
                        ctx.clip();
                        ctx.fillStyle = '#fff';
                        ctx.fillText(text, left + 8, bar.y);
                        ctx.restore();
                        ctx.save();
                        ctx.font = font;
                        ctx.textBaseline = 'middle';
                    }

                    if (width >= fullWidth + 16) {
                        // Everything fits inside the bar.
                        drawInside(fullLabel);
                    } else if (width >= timeShiftWidth + 16) {
                        // Time + shift name fit; the volunteer's name goes just past the edge.
                        drawInside(timeShiftLabel);
                        ctx.fillStyle = '#3c434a';
                        ctx.fillText(row.volunteer, right + 6, bar.y);
                    } else if (width >= timeWidth + 16) {
                        // Only the time fits; shift name + volunteer go past the edge together.
                        drawInside(timeText);
                        ctx.fillStyle = '#3c434a';
                        ctx.fillText(row.shift + ' — ' + row.volunteer, right + 6, bar.y);
                    } else {
                        // Bar too narrow (short shift) for even the time alone: draw
                        // everything just past its right edge instead of hiding any of it —
                        // short shifts are exactly the ones hardest to read off the x-axis.
                        ctx.fillStyle = '#3c434a';
                        ctx.fillText(fullLabel, right + 6, bar.y);
                    }
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
                        border: {
                            display: false,
                        },
                        // The category label (volunteer name, or "open slot") is already drawn
                        // directly on/next to each bar by barLabelPlugin, so a left-hand axis
                        // label column would just repeat it — hiding it frees that width for
                        // the bars themselves, which matters most on narrow/mobile screens.
                        ticks: {
                            display: false,
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

        // Which drag mode a given hit-test point would trigger — shared by the hover-cursor
        // preview (below) and the actual mousedown handler, so the cursor always promises
        // exactly what clicking there will do.
        function modeForHit(hit) {
            if (Math.abs(hit.x - hit.left) <= EDGE_PX) return 'resize-start';
            if (Math.abs(hit.x - hit.right) <= EDGE_PX) return 'resize-end';
            return 'move';
        }

        const CURSOR_FOR_MODE = {'resize-start': 'ew-resize', 'resize-end': 'ew-resize', move: 'grab'};

        // Previews the drag mode under the pointer before a drag starts — an edge shows the
        // resize cursor (only the start or end time will move), the middle of the bar shows
        // a grab cursor (the whole shift will move), and empty space shows the default arrow.
        timelineCtx.addEventListener('mousemove', (evt) => {
            if (pointerState) return; // an active drag sets its own cursor, see mousedown/mouseup
            const hit = getBarAt(evt);
            timelineCtx.style.cursor = hit ? CURSOR_FOR_MODE[modeForHit(hit)] : '';
        });

        timelineCtx.addEventListener('mouseleave', () => {
            if (!pointerState) timelineCtx.style.cursor = '';
        });

        timelineCtx.addEventListener('mousedown', (evt) => {
            const hit = getBarAt(evt);
            const state = {hit, startXpx: evt.clientX, moved: false};

            if (hit) {
                const row = rows[hit.index];
                const mode = modeForHit(hit);

                // All rows belonging to the same shift (one per volunteer/open slot) must move
                // together, since they represent the same underlying start/end time.
                const indices = rows.reduce((acc, r, i) => {
                    if (r.shift_id === row.shift_id) acc.push(i);
                    return acc;
                }, []);
                const startValues = {};
                indices.forEach((i) => { startValues[i] = [rows[i].start, rows[i].end]; });

                // When a shift has more than one occupant row and the one being dragged is a
                // specific volunteer (not an open slot), we don't yet know whether the new
                // time should apply to the whole shift or just this person — that's answered
                // on release (see mouseup below). Until then, only preview *this* row moving;
                // the rest stay put so the drag doesn't imply an answer it hasn't gotten yet.
                const isSplitCandidate = indices.length > 1 && !row.open && !!row.volunteer_id;

                state.mode = mode;
                state.shiftId = row.shift_id;
                state.indices = indices;
                state.dragIndices = isSplitCandidate ? [hit.index] : indices;
                state.isSplitCandidate = isSplitCandidate;
                state.startValues = startValues;

                // Lock the cursor to the mode for the whole drag — 'grabbing' while a move is
                // in progress, resize arrows while an edge is in progress — regardless of
                // where the pointer wanders relative to the bar mid-drag.
                timelineCtx.style.cursor = mode === 'move' ? 'grabbing' : CURSOR_FOR_MODE[mode];
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

            pointerState.dragIndices.forEach((i) => {
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

        document.addEventListener('mouseup', (evt) => {
            if (!pointerState) return;
            const state = pointerState;
            pointerState = null;
            closeTimelineActionsMenu();

            // The drag-locked cursor (mousedown, above) doesn't clear itself — re-preview
            // whatever's now under the pointer instead of leaving it stuck as grabbing/resize.
            const hoverHit = getBarAt(evt);
            timelineCtx.style.cursor = hoverHit ? CURSOR_FOR_MODE[modeForHit(hoverHit)] : '';

            if (!state.hit) {
                // Click on empty space — deselect.
                if (state.moved) return;
                selectedIndex = null;
                timelineChart.update();
                return;
            }

            if (!state.moved) {
                handleBarClick(state.hit.index, evt);
                return;
            }

            if (!state.isSplitCandidate) {
                // Remember the pre-drag times so a successful save can still be undone afterwards.
                const originalValues = {};
                state.indices.forEach((i) => { originalValues[i] = state.startValues[i]; });
                persistShiftTime(state.shiftId, state.indices, originalValues);
                return;
            }

            // Only the dragged row was previewed moving (see mousedown) — ask whether the new
            // time is for the whole shift or just this one person before touching anyone else.
            const draggedIndex = state.hit.index;
            const draggedRow = rows[draggedIndex];
            const revertDraggedRow = () => {
                const [origStart, origEnd] = state.startValues[draggedIndex];
                rows[draggedIndex].start = origStart;
                rows[draggedIndex].end = origEnd;
                applyRowsToChart([draggedIndex]);
            };

            const cfg = EVENTADMIN_SHIFT_EDIT;
            const i18n = cfg.i18n || {};
            const otherCount = state.indices.length - 1;
            const promptTemplate = otherCount === 1
                ? (i18n.splitShiftPromptOne || 'This shift also has one other person on it. Apply the new time to both, or only to %s?')
                : (i18n.splitShiftPromptMany || 'This shift also has %d other people on it. Apply the new time to everyone, or only to %s?');
            const message = promptTemplate.replace('%d', otherCount).replace('%s', draggedRow.volunteer);

            showChoiceModal(message, [
                {key: 'all', label: i18n.applyToEveryone || 'Apply to everyone', primary: true},
                {key: 'only', label: (i18n.applyToOnlyPerson || 'Only to %s').replace('%s', draggedRow.volunteer)},
            ]).then((choice) => {
                if (choice === 'all') {
                    const newStart = draggedRow.start;
                    const newEnd = draggedRow.end;
                    state.indices.forEach((i) => {
                        rows[i].start = newStart;
                        rows[i].end = newEnd;
                    });
                    applyRowsToChart(state.indices);
                    const originalValues = {};
                    state.indices.forEach((i) => { originalValues[i] = state.startValues[i]; });
                    persistShiftTime(state.shiftId, state.indices, originalValues);
                } else if (choice === 'only') {
                    splitVolunteerToNewShift(state.shiftId, draggedRow.volunteer_id, draggedRow.start, draggedRow.end, revertDraggedRow);
                } else {
                    revertDraggedRow();
                }
            });
        });

        // Applies rows[indices]'s *current* start/end to the chart display (used after both a
        // successful save and an undo).
        function applyRowsToChart(indices) {
            indices.forEach((i) => {
                timelineChart.data.datasets[0].data[i] = [rows[i].start * 1000, rows[i].end * 1000];
            });
            updateXAxisBounds();
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
                    // Lets eventadmin_ajax_update_shift() tell a drag/resize save apart from
                    // the Edit Shift modal's form submit, for the Getting Started checklist
                    // (includes/admin/getting-started-checklist.php).
                    reschedule_source: 'drag',
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
                            showErrorToast((cfg.i18n && cfg.i18n.error) || 'Error');
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
                    showErrorToast((cfg.i18n && cfg.i18n.error) || 'Error');
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

        // A self-dismissing error toast — replaces window.alert(), which blocks the whole
        // page (and, in an automated/remote-controlled browser, the entire tab) until a
        // human dismisses it natively.
        let errorToastTimer = null;
        function showErrorToast(message) {
            let toast = document.getElementById('eventadmin-error-toast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'eventadmin-error-toast';
                toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#d63638;color:#fff;'
                    + 'padding:12px 16px;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.25);'
                    + 'font-size:13px;z-index:100001;max-width:320px;';
                document.body.appendChild(toast);
            }
            clearTimeout(errorToastTimer);
            toast.textContent = message;
            toast.style.display = 'block';
            errorToastTimer = setTimeout(function () {
                toast.style.display = 'none';
            }, 6000);
        }

        // A small in-page confirm dialog — replaces window.confirm(), which blocks the whole
        // page (and, in an automated/remote-controlled browser, the entire tab) until a human
        // dismisses it natively. Returns a Promise<boolean> resolving to whether the user
        // confirmed.
        // `checkboxOption`, when given ({label, checked}), adds an extra checkbox row between
        // the message and the buttons — e.g. "Notify volunteer" belongs on the confirmation
        // itself (it's a property of the action being confirmed), not sitting unconditionally
        // in the popover above where it looks like it might apply to every action there.
        // Resolves to `false` on cancel; otherwise `true`, or (when checkboxOption was given)
        // `{checked: boolean}` reflecting the checkbox's state at the moment of confirming.
        function showConfirmModal(message, checkboxOption) {
            const cfg = (typeof EVENTADMIN_SHIFT_EDIT !== 'undefined') ? EVENTADMIN_SHIFT_EDIT : {};
            const i18n = cfg.i18n || {};

            let overlay = document.getElementById('eventadmin-confirm-overlay');
            let textEl;
            let checkboxLabel;
            let checkboxEl;
            let cancelBtn;
            let okBtn;
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'eventadmin-confirm-overlay';
                overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100010;'
                    + 'display:none;align-items:center;justify-content:center;';

                const box = document.createElement('div');
                box.style.cssText = 'background:#fff;border-radius:6px;box-shadow:0 8px 30px rgba(0,0,0,.3);'
                    + 'max-width:420px;width:90%;padding:20px;';

                textEl = document.createElement('p');
                textEl.id = 'eventadmin-confirm-text';
                textEl.style.cssText = 'margin:0 0 16px;font-size:14px;line-height:1.5;color:#1d2327;';
                box.appendChild(textEl);

                checkboxLabel = document.createElement('label');
                checkboxLabel.id = 'eventadmin-confirm-checkbox-label';
                checkboxLabel.style.cssText = 'display:flex;align-items:center;gap:8px;font-size:13px;'
                    + 'color:#1d2327;cursor:pointer;margin:0 0 16px;';
                checkboxEl = document.createElement('input');
                checkboxEl.type = 'checkbox';
                checkboxEl.id = 'eventadmin-confirm-checkbox';
                checkboxLabel.appendChild(checkboxEl);
                checkboxLabel.appendChild(document.createTextNode(''));
                box.appendChild(checkboxLabel);

                const actions = document.createElement('div');
                actions.style.cssText = 'display:flex;justify-content:flex-end;gap:8px;';

                cancelBtn = document.createElement('button');
                cancelBtn.type = 'button';
                cancelBtn.className = 'button';
                cancelBtn.id = 'eventadmin-confirm-cancel';
                actions.appendChild(cancelBtn);

                okBtn = document.createElement('button');
                okBtn.type = 'button';
                okBtn.className = 'button button-primary';
                okBtn.id = 'eventadmin-confirm-ok';
                actions.appendChild(okBtn);

                box.appendChild(actions);
                overlay.appendChild(box);
                document.body.appendChild(overlay);

                overlay.addEventListener('click', (evt) => {
                    if (evt.target === overlay) overlay.dispatchEvent(new CustomEvent('eventadmin:cancel'));
                });
            } else {
                textEl = document.getElementById('eventadmin-confirm-text');
                checkboxLabel = document.getElementById('eventadmin-confirm-checkbox-label');
                checkboxEl = document.getElementById('eventadmin-confirm-checkbox');
                cancelBtn = document.getElementById('eventadmin-confirm-cancel');
                okBtn = document.getElementById('eventadmin-confirm-ok');
            }

            textEl.textContent = message;
            if (checkboxOption) {
                checkboxLabel.lastChild.textContent = checkboxOption.label;
                checkboxEl.checked = !!checkboxOption.checked;
                checkboxLabel.style.display = 'flex';
            } else {
                checkboxLabel.style.display = 'none';
            }
            cancelBtn.textContent = i18n.cancel || 'Cancel';
            okBtn.textContent = i18n.confirmButton || 'Confirm';
            overlay.style.display = 'flex';

            return new Promise((resolve) => {
                function cleanup(confirmed) {
                    overlay.style.display = 'none';
                    cancelBtn.removeEventListener('click', onCancel);
                    okBtn.removeEventListener('click', onOk);
                    overlay.removeEventListener('eventadmin:cancel', onCancel);
                    document.removeEventListener('keydown', onKeydown);
                    if (!confirmed) {
                        resolve(false);
                    } else {
                        resolve(checkboxOption ? {checked: checkboxEl.checked} : true);
                    }
                }
                function onCancel() { cleanup(false); }
                function onOk() { cleanup(true); }
                function onKeydown(evt) {
                    if (evt.key === 'Escape') cleanup(false);
                }
                cancelBtn.addEventListener('click', onCancel);
                okBtn.addEventListener('click', onOk);
                overlay.addEventListener('eventadmin:cancel', onCancel);
                document.addEventListener('keydown', onKeydown);
                okBtn.focus();
            });
        }

        // Like showConfirmModal, but for a choice between more than two outcomes (e.g. "apply
        // to everyone" vs. "only to this person" vs. cancel) — rebuilds its button row from
        // `choices` each call since the set of options isn't fixed like showConfirmModal's.
        // Resolves to the chosen `key`, or null if dismissed (Escape/backdrop/no explicit
        // cancel button — callers treat null as "cancel").
        function showChoiceModal(message, choices) {
            const cfg = (typeof EVENTADMIN_SHIFT_EDIT !== 'undefined') ? EVENTADMIN_SHIFT_EDIT : {};
            const i18n = cfg.i18n || {};

            let overlay = document.getElementById('eventadmin-choice-overlay');
            let textEl;
            let actions;
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'eventadmin-choice-overlay';
                overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100010;'
                    + 'display:none;align-items:center;justify-content:center;';

                const box = document.createElement('div');
                box.style.cssText = 'background:#fff;border-radius:6px;box-shadow:0 8px 30px rgba(0,0,0,.3);'
                    + 'max-width:440px;width:90%;padding:20px;';

                textEl = document.createElement('p');
                textEl.id = 'eventadmin-choice-text';
                textEl.style.cssText = 'margin:0 0 16px;font-size:14px;line-height:1.5;color:#1d2327;';
                box.appendChild(textEl);

                actions = document.createElement('div');
                actions.id = 'eventadmin-choice-actions';
                actions.style.cssText = 'display:flex;flex-direction:column;gap:8px;';
                box.appendChild(actions);

                overlay.appendChild(box);
                document.body.appendChild(overlay);

                overlay.addEventListener('click', (evt) => {
                    if (evt.target === overlay) overlay.dispatchEvent(new CustomEvent('eventadmin:cancel'));
                });
            } else {
                textEl = document.getElementById('eventadmin-choice-text');
                actions = document.getElementById('eventadmin-choice-actions');
            }

            textEl.textContent = message;
            actions.innerHTML = '';

            return new Promise((resolve) => {
                function cleanup(result) {
                    overlay.style.display = 'none';
                    overlay.removeEventListener('eventadmin:cancel', onCancel);
                    document.removeEventListener('keydown', onKeydown);
                    resolve(result);
                }
                function onCancel() { cleanup(null); }
                function onKeydown(evt) {
                    if (evt.key === 'Escape') cleanup(null);
                }

                choices.forEach((choice) => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = choice.primary ? 'button button-primary' : 'button';
                    btn.textContent = choice.label;
                    btn.addEventListener('click', () => cleanup(choice.key));
                    actions.appendChild(btn);
                });

                const cancelBtn = document.createElement('button');
                cancelBtn.type = 'button';
                cancelBtn.className = 'button-link';
                cancelBtn.style.cssText = 'align-self:center;margin-top:4px;';
                cancelBtn.textContent = i18n.cancel || 'Cancel';
                cancelBtn.addEventListener('click', onCancel);
                actions.appendChild(cancelBtn);

                overlay.addEventListener('eventadmin:cancel', onCancel);
                document.addEventListener('keydown', onKeydown);
                overlay.style.display = 'flex';
                actions.querySelector('button').focus();
            });
        }

        function handleBarClick(index, evt) {
            selectedIndex = index;
            timelineChart.update();
            openTimelineActionsMenu(index, evt);
        }

        // --- Click-to-act popover (Edit shift / Remove volunteer) ----------------------------
        const actionsMenu = document.getElementById('eventadmin-timeline-actions');

        function closeTimelineActionsMenu() {
            if (actionsMenu) actionsMenu.style.display = 'none';
        }

        function openTimelineActionsMenu(index, evt) {
            if (!actionsMenu || typeof EVENTADMIN_SHIFT_EDIT === 'undefined') return;
            const row = rows[index];
            const cfg = EVENTADMIN_SHIFT_EDIT;
            const i18n = cfg.i18n || {};

            actionsMenu.innerHTML = '';

            // Context header — always names the volunteer + shift the popover acts on, so it
            // stays unambiguous even after the position below gets clamped away from the bar
            // itself (e.g. a click near a screen edge on a small/mobile viewport).
            const header = document.createElement('div');
            header.textContent = row.volunteer + ' — ' + row.shift;
            header.style.cssText = 'font-weight:600;font-size:13px;padding:8px 10px 0;color:#1d2327;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
            actionsMenu.appendChild(header);

            const headerPeriod = document.createElement('div');
            headerPeriod.textContent = row.period;
            headerPeriod.style.cssText = 'font-weight:400;font-size:12px;padding:2px 10px 6px;color:#646970;';
            actionsMenu.appendChild(headerPeriod);

            const divider = document.createElement('hr');
            divider.className = 'eventadmin-timeline-menu-divider';
            actionsMenu.appendChild(divider);

            const editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'eventadmin-timeline-menu-item';
            editBtn.textContent = i18n.editShift || 'Edit shift';
            editBtn.addEventListener('click', () => {
                closeTimelineActionsMenu();
                if (typeof window.eventadminOpenEditShiftModal === 'function') {
                    window.eventadminOpenEditShiftModal(row.shift_id);
                }
            });
            actionsMenu.appendChild(editBtn);

            if (row.open) {
                const addVolunteerBtn = document.createElement('button');
                addVolunteerBtn.type = 'button';
                addVolunteerBtn.className = 'eventadmin-timeline-menu-item';
                addVolunteerBtn.textContent = i18n.addVolunteer || 'Add volunteer';
                addVolunteerBtn.addEventListener('click', () => {
                    closeTimelineActionsMenu();
                    if (typeof window.eventadminOpenAddVolunteerModal === 'function') {
                        window.eventadminOpenAddVolunteerModal(row.shift_id);
                    }
                });
                actionsMenu.appendChild(addVolunteerBtn);
            } else if (row.volunteer_id) {
                const viewProfileBtn = document.createElement('button');
                viewProfileBtn.type = 'button';
                viewProfileBtn.className = 'eventadmin-timeline-menu-item';
                viewProfileBtn.textContent = i18n.viewProfile || 'View profile';
                viewProfileBtn.addEventListener('click', () => {
                    closeTimelineActionsMenu();
                    if (typeof window.eventadminOpenVolunteerProfileModal === 'function') {
                        window.eventadminOpenVolunteerProfileModal(row.volunteer_id, row.volunteer);
                    }
                });
                actionsMenu.appendChild(viewProfileBtn);

                const moveBtn = document.createElement('button');
                moveBtn.type = 'button';
                moveBtn.className = 'eventadmin-timeline-menu-item';
                moveBtn.textContent = i18n.moveToShift || 'Move to another shift';
                moveBtn.addEventListener('click', () => {
                    closeTimelineActionsMenu();
                    if (typeof window.eventadminOpenMoveVolunteerModal === 'function') {
                        window.eventadminOpenMoveVolunteerModal(row.shift_id, row.volunteer_id, row.volunteer, row.shift);
                    }
                });
                actionsMenu.appendChild(moveBtn);

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'eventadmin-timeline-menu-item is-destructive';
                removeBtn.textContent = i18n.removeVolunteer || 'Remove from shift';
                removeBtn.addEventListener('click', () => {
                    closeTimelineActionsMenu();
                    const confirmMsg = (i18n.confirmRemove || 'Remove %s from this shift?').replace('%s', row.volunteer);
                    // Matches the "Notify volunteer" checkbox every other removal path in the
                    // plugin already offers (Table/Cards views, admin unassign form) — offline
                    // volunteers have no email to notify, so it's skipped for them, same as
                    // everywhere else. Lives on the confirmation itself rather than the
                    // popover above, since it's a property of this specific action, not
                    // something that could apply to "Edit shift" or "Move to another shift" too.
                    const checkboxOption = row.offline ? null : {label: i18n.notifyVolunteer || 'Notify volunteer', checked: false};
                    showConfirmModal(confirmMsg, checkboxOption).then((result) => {
                        if (!result) return;
                        const notify = typeof result === 'object' && result.checked;
                        removeVolunteerFromShift(row.shift_id, row.volunteer_id, notify);
                    });
                });
                actionsMenu.appendChild(removeBtn);
            }

            // Deletes the whole shift — always available regardless of whether this bar is an
            // open slot or an assigned volunteer, since it acts on the shift, not this one row.
            const deleteDivider = document.createElement('hr');
            deleteDivider.className = 'eventadmin-timeline-menu-divider';
            actionsMenu.appendChild(deleteDivider);

            const deleteShiftBtn = document.createElement('button');
            deleteShiftBtn.type = 'button';
            deleteShiftBtn.className = 'eventadmin-timeline-menu-item is-destructive';
            deleteShiftBtn.textContent = i18n.deleteShift || 'Delete shift';
            deleteShiftBtn.addEventListener('click', () => {
                closeTimelineActionsMenu();
                const assignedRows = rows.filter((r) => r.shift_id === row.shift_id && !r.open);
                const notifiableCount = assignedRows.filter((r) => !r.offline).length;
                const confirmMsg = assignedRows.length > 0
                    ? (i18n.confirmDeleteShiftWithVolunteers || 'Delete this shift? This will also remove %d assigned volunteer(s) from it.').replace('%d', assignedRows.length)
                    : (i18n.confirmDeleteShiftEmpty || 'Delete this shift?');
                // Same idea as the "Remove from shift" confirmation — only offered when at
                // least one assigned volunteer could actually receive the email (offline
                // volunteers, same as everywhere else, are silently excluded).
                const checkboxOption = notifiableCount > 0
                    ? {label: i18n.notifyAffectedVolunteers || 'Notify affected volunteers', checked: false}
                    : null;
                showConfirmModal(confirmMsg, checkboxOption).then((result) => {
                    if (!result) return;
                    const notify = typeof result === 'object' && result.checked;
                    deleteShift(row.shift_id, notify);
                });
            });
            actionsMenu.appendChild(deleteShiftBtn);

            // Position at the click point, then clamp so the popover always stays fully inside
            // the viewport — on a narrow/mobile screen a click near an edge would otherwise
            // push part of it off-screen, making it hard or impossible to read/tap.
            const rect = timelineCtx.getBoundingClientRect();
            const clickX = evt ? evt.clientX - rect.left : 0;
            const clickY = evt ? evt.clientY - rect.top : 0;

            actionsMenu.style.visibility = 'hidden';
            actionsMenu.style.left = '0px';
            actionsMenu.style.top = '0px';
            actionsMenu.style.display = 'block';
            const menuWidth = actionsMenu.offsetWidth;
            const menuHeight = actionsMenu.offsetHeight;

            const margin = 8;
            let viewportLeft = rect.left + clickX;
            let viewportTop = rect.top + clickY + margin;
            viewportLeft = Math.min(Math.max(margin, viewportLeft), window.innerWidth - menuWidth - margin);
            viewportTop = Math.min(Math.max(margin, viewportTop), window.innerHeight - menuHeight - margin);

            actionsMenu.style.left = (window.scrollX + viewportLeft) + 'px';
            actionsMenu.style.top = (window.scrollY + viewportTop) + 'px';
            actionsMenu.style.visibility = 'visible';
        }

        // Closes the popover on any click outside it — but not on the canvas itself, since
        // canvas clicks are already handled (and re-open/re-position it) via mouseup above.
        document.addEventListener('click', (evt) => {
            if (!actionsMenu || actionsMenu.style.display === 'none') return;
            if (evt.target === timelineCtx || actionsMenu.contains(evt.target)) return;
            closeTimelineActionsMenu();
        });

        function removeVolunteerFromShift(shiftId, userId, notify) {
            const cfg = EVENTADMIN_SHIFT_EDIT;
            fetch(cfg.ajax_url, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'eventadmin_timeline_unassign',
                    nonce: cfg.nonce,
                    shift_id: shiftId,
                    user_id: userId,
                    show_open: cfg.show_open ? '1' : '0',
                    notify_volunteer: notify ? '1' : '0',
                }),
            })
                .then((r) => r.json())
                .then((res) => {
                    if (!res.success) throw new Error('remove failed');
                    replaceShiftRows(shiftId, res.data.rows);
                })
                .catch(() => {
                    showErrorToast((cfg.i18n && cfg.i18n.error) || 'Error');
                });
        }

        // Moves a shift to the trash and removes every one of its rows (assigned volunteers
        // and/or open slots) from the chart in place.
        function deleteShift(shiftId, notify) {
            const cfg = EVENTADMIN_SHIFT_EDIT;
            fetch(cfg.ajax_url, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'eventadmin_delete_shift',
                    nonce: cfg.nonce,
                    shift_id: shiftId,
                    notify_volunteers: notify ? '1' : '0',
                }),
            })
                .then((r) => r.json())
                .then((res) => {
                    if (!res.success) throw new Error('delete failed');
                    replaceShiftRows(shiftId, []);
                })
                .catch(() => {
                    showErrorToast((cfg.i18n && cfg.i18n.error) || 'Error');
                });
        }

        // Splits one volunteer off a shared shift into a brand-new shift (same title/
        // department/description, capacity 1) at the dragged time, leaving the original
        // shift and everyone else on it untouched — used when a drag on a multi-occupant
        // shift is answered with "only this person" (see the mouseup handler above).
        function splitVolunteerToNewShift(shiftId, userId, startTs, endTs, onFailure) {
            const cfg = EVENTADMIN_SHIFT_EDIT;
            fetch(cfg.ajax_url, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'eventadmin_split_shift_volunteer',
                    nonce: cfg.nonce,
                    shift_id: shiftId,
                    user_id: userId,
                    start: tsToDatetimeString(startTs),
                    end: tsToDatetimeString(endTs),
                    show_open: cfg.show_open ? '1' : '0',
                }),
            })
                .then((r) => r.json())
                .then((res) => {
                    if (!res.success) throw new Error('split failed');
                    const data = res.data;
                    cfg.shifts[data.new_shift_id] = data.new_shift;
                    // The original shift's own min/max shrink by one (see the PHP handler),
                    // so its cached edit-modal data needs to reflect that too.
                    cfg.shifts[shiftId] = data.old_shift;
                    // Registers the new shift with the "Add volunteer" modal (Table and
                    // Timeline views both use this shared map), matching how a freshly
                    // created shift is registered elsewhere in this file.
                    if (typeof EVENTADMIN_SHIFT_INFO !== 'undefined') {
                        EVENTADMIN_SHIFT_INFO[data.new_shift_id] = {title: data.new_shift.title, assigned: [userId]};
                    }
                    replaceShiftRows(shiftId, data.old_rows);
                    replaceShiftRows(data.new_shift_id, data.new_rows);
                })
                .catch(() => {
                    onFailure();
                    showErrorToast((cfg.i18n && cfg.i18n.error) || 'Error');
                });
        }

        // The x-axis min/max above are computed once from the rows present at page load
        // and Chart.js does NOT recompute a fixed numeric min/max on its own — so a shift
        // created (or retimed) outside that original window renders completely off-scale
        // and silently disappears until the page is reloaded. Anything that changes a
        // row's start/end or adds/removes rows must call this before chart.update().
        function updateXAxisBounds() {
            if (!rows.length) return;
            const newMin = Math.min(...rows.map((r) => r.start * 1000));
            const newMax = Math.max(...rows.map((r) => r.end * 1000));
            const newPad = (newMax - newMin) * 0.02 || 30 * 60 * 1000;
            timelineChart.options.scales.x.min = newMin - newPad;
            timelineChart.options.scales.x.max = newMax + newPad;
        }

        // Swaps every row belonging to shiftId for a freshly computed set (fewer assigned
        // rows, possibly a new open-slot row) and rebuilds the chart's labels/data/colors
        // in lockstep, since the row count itself can change.
        function replaceShiftRows(shiftId, newRows) {
            let insertAt = rows.length;
            for (let i = rows.length - 1; i >= 0; i--) {
                if (rows[i].shift_id === shiftId) {
                    insertAt = i;
                    rows.splice(i, 1);
                }
            }
            rows.splice(insertAt, 0, ...newRows);

            timelineChart.data.labels = rows.map((r) => r.volunteer);
            timelineChart.data.datasets[0].data = rows.map((r) => [r.start * 1000, r.end * 1000]);
            timelineChart.data.datasets[0].backgroundColor = rows.map((r) => hexToRgba(r.color, 0.88));
            timelineChart.data.datasets[0].borderColor = rows.map((r) => r.color);

            selectedIndex = null;
            updateXAxisBounds();
            timelineChart.update();
        }

        timelineState = {rows, chart: timelineChart, replaceShiftRows, hexToRgba, updateXAxisBounds};
    }

    eventadminInitTimelineChart();

    // Registers with the shared shift modal (assets/js/shift-details-modal.js) so a save or
    // a brand-new shift patches this page's Timeline chart in place — the modal itself has
    // no idea a chart even exists; it's reused unchanged on pages that don't have one (a
    // volunteer's profile, the Overview dashboard).
    if (typeof EVENTADMIN_SHIFT_EDIT !== 'undefined') {
        window.eventadminOnShiftSaved = function (shiftId, data) {
            if (timelineState) {
                timelineState.replaceShiftRows(shiftId, data.rows);
            }
        };

        window.eventadminOnShiftCreated = function (shiftId, data) {
            if (timelineState) {
                if (!EVENTADMIN_SHIFT_EDIT.show_open) {
                    EVENTADMIN_SHIFT_EDIT.show_open = true;
                    const showOpenCheckbox = document.querySelector('#eventadmin-overview-filters input[name="show_open"][type="checkbox"]');
                    if (showOpenCheckbox) showOpenCheckbox.checked = true;
                }
                timelineState.replaceShiftRows(shiftId, data.rows);
                if (typeof window.eventadminCloseShiftDetailsModal === 'function') {
                    window.eventadminCloseShiftDetailsModal();
                }
            } else {
                // No chart exists yet (the Timeline had zero rows) — nothing to patch in
                // place, so reload with "show open slots" forced on so the brand-new
                // (necessarily empty) shift is visible.
                const url = new URL(window.location.href);
                url.searchParams.set('show_open', '1');
                window.location.href = url.toString();
            }
        };
    }

    // --- Move to another shift modal --------------------------------------------------
    // A real form submit (not AJAX): the target shift can be on a completely different
    // day or department than what's currently filtered, so a plain reload is the
    // simplest way to always land on a correctly re-rendered view afterward.
    const moveModal = document.getElementById('eventadmin-move-volunteer-modal');
    if (moveModal && typeof EVENTADMIN_SHIFT_EDIT !== 'undefined') {
        const cfg = EVENTADMIN_SHIFT_EDIT;
        const moveCloseBtn = document.getElementById('eventadmin-move-volunteer-close');
        const moveSubtitle = document.getElementById('eventadmin-move-volunteer-subtitle');
        const moveFromShiftInput = document.getElementById('eventadmin-move-from-shift-id');
        const moveUserIdInput = document.getElementById('eventadmin-move-user-id');
        const moveToSelect = document.getElementById('eventadmin-move-to-shift-select');

        window.eventadminOpenMoveVolunteerModal = function (fromShiftId, userId, volunteerName, shiftTitle) {
            moveFromShiftInput.value = fromShiftId;
            moveUserIdInput.value = userId;
            moveSubtitle.textContent = volunteerName + ' — ' + shiftTitle;

            moveToSelect.innerHTML = '';
            (cfg.move_shifts || [])
                .filter((s) => s.id !== fromShiftId)
                .forEach((s) => {
                    const opt = document.createElement('option');
                    opt.value = s.id;
                    opt.textContent = s.label;
                    moveToSelect.appendChild(opt);
                });

            moveModal.style.display = 'block';
        };

        function closeMoveVolunteerModal() {
            moveModal.style.display = 'none';
        }

        if (moveCloseBtn) moveCloseBtn.addEventListener('click', closeMoveVolunteerModal);
        moveModal.addEventListener('click', (e) => {
            if (e.target === moveModal) closeMoveVolunteerModal();
        });
    }

    // "View profile" modal and the shared shift modal (assets/js/volunteer-profile-modal.js,
    // assets/js/shift-details-modal.js) are wired up independently — nothing to do here.
});

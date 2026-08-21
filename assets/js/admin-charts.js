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

    const ctx = document.getElementById('eventadmin-chart');
    if (!ctx || typeof EVENTADMIN_VOLUNTEER_STATS === 'undefined') return;

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
                    label: i18n.open,
                    data: EVENTADMIN_VOLUNTEER_STATS.data_open,
                    backgroundColor: '#cfd8dc'
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
            labels: [i18n.filled, i18n.open],
            datasets: [{
                data: [
                    EVENTADMIN_VOLUNTEER_STATS.stats.filled_shifts,
                    EVENTADMIN_VOLUNTEER_STATS.stats.open_shifts
                ],
                backgroundColor: ['#4caf50', '#cfd8dc']
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

    const timelineCtx = document.getElementById('eventadmin-timeline-chart');
    if (!timelineCtx || typeof EVENTADMIN_TIMELINE_DATA === 'undefined') return;

    const rows = EVENTADMIN_TIMELINE_DATA.rows;
    const timelineI18n = EVENTADMIN_TIMELINE_DATA.i18n;
    const dateFormatter = new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
        weekday: 'short',
        hour: '2-digit',
        minute: '2-digit',
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

    // Draws the shift name directly on each bar, clipped to the bar's own width.
    const barLabelPlugin = {
        id: 'eventadminBarLabel',
        afterDatasetsDraw(chart) {
            const {ctx} = chart;
            const meta = chart.getDatasetMeta(0);
            meta.data.forEach((bar, index) => {
                const left = Math.min(bar.x, bar.base);
                const right = Math.max(bar.x, bar.base);
                if (right - left < 24) return;

                ctx.save();
                ctx.beginPath();
                ctx.rect(left, bar.y - bar.height / 2, right - left, bar.height);
                ctx.clip();
                ctx.font = '11px sans-serif';
                ctx.textBaseline = 'middle';
                ctx.lineWidth = 3;
                ctx.strokeStyle = 'rgba(0,0,0,0.55)';
                ctx.fillStyle = '#fff';
                ctx.strokeText(rows[index].shift, left + 4, bar.y);
                ctx.fillText(rows[index].shift, left + 4, bar.y);
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
            ctx.fillStyle = 'rgba(255, 214, 0, 0.25)';
            ctx.fillRect(chartArea.left, center - rowSpan / 2, chartArea.right - chartArea.left, rowSpan);
            ctx.restore();
        },
    };

    new Chart(timelineCtx, {
        type: 'bar',
        data: {
            labels: rows.map(r => r.volunteer),
            datasets: [{
                data: rows.map(r => [r.start * 1000, r.end * 1000]),
                backgroundColor: rows.map(r => r.color),
                barPercentage: 0.8,
                categoryPercentage: 0.9,
            }],
        },
        plugins: [rowHighlightPlugin, barLabelPlugin],
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            onClick: (evt, elements, chart) => {
                if (!elements.length) {
                    selectedIndex = null;
                    updateSelectedInfo();
                    chart.update();
                    return;
                }
                const index = elements[0].index;
                const row = rows[index];
                if (row.open && typeof window.eventadminOpenAddVolunteerModal === 'function') {
                    window.eventadminOpenAddVolunteerModal(row.shift_id);
                    return;
                }
                selectedIndex = index;
                updateSelectedInfo();
                chart.update();
            },
            plugins: {
                legend: {display: false},
                tooltip: {
                    callbacks: {
                        title: (items) => rows[items[0].dataIndex].volunteer,
                        label: (item) => {
                            const row = rows[item.dataIndex];
                            return [
                                timelineI18n.shift + ': ' + row.shift,
                                timelineI18n.period + ': ' + row.period,
                                timelineI18n.capacity + ': ' + row.capacity,
                            ];
                        },
                    },
                },
            },
            scales: {
                x: {
                    type: 'linear',
                    min: xMin - xPad,
                    max: xMax + xPad,
                    ticks: {
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
                    ticks: {
                        autoSkip: false,
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
});

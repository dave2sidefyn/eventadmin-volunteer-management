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
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
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
                    },
                },
                y: {
                    ticks: {autoSkip: false},
                },
            },
        },
    });
});

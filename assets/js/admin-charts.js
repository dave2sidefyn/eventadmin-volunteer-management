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
});

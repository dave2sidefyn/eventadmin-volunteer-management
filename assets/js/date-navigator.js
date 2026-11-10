/**
 * EventAdmin Volunteer Management - Month picker for the date bar
 * (includes/admin/date-navigator.php). Shows one month at a time; days with shifts carry a
 * dot coloured by how well they're staffed and link to that day, days without shifts are
 * inert. Data comes from EVENTADMIN_DATE_NAV (wp_localize_script).
 */
document.addEventListener('DOMContentLoaded', function () {
    const cfg = window.EVENTADMIN_DATE_NAV;
    const toggle = document.getElementById('eventadmin-date-picker-toggle');
    const picker = document.getElementById('eventadmin-date-picker');
    if (!cfg || !toggle || !picker) return;

    // wp_localize_script() hands every scalar over as a string.
    const startOfWeek = parseInt(cfg.startOfWeek, 10) || 0;
    const pad = (n) => String(n).padStart(2, '0');
    const ymd = (y, m, d) => y + '-' + pad(m + 1) + '-' + pad(d);
    let locale = cfg.locale;
    try { new Intl.DateTimeFormat(locale); } catch (e) { locale = undefined; }

    const start = (cfg.selected || cfg.today).split('-').map(Number);
    let year = start[0];
    let month = start[1] - 1;

    function render() {
        picker.textContent = '';

        const header = document.createElement('div');
        header.className = 'eventadmin-date-picker-header';
        const prevBtn = document.createElement('button');
        prevBtn.type = 'button';
        prevBtn.className = 'button-link';
        prevBtn.textContent = '‹';
        prevBtn.setAttribute('aria-label', cfg.i18n.prev_month);
        const nextBtn = prevBtn.cloneNode();
        nextBtn.textContent = '›';
        nextBtn.setAttribute('aria-label', cfg.i18n.next_month);
        const title = document.createElement('strong');
        title.textContent = new Intl.DateTimeFormat(locale, {month: 'long', year: 'numeric', timeZone: 'UTC'})
            .format(new Date(Date.UTC(year, month, 1)));
        prevBtn.addEventListener('click', () => { month--; if (month < 0) { month = 11; year--; } render(); });
        nextBtn.addEventListener('click', () => { month++; if (month > 11) { month = 0; year++; } render(); });
        header.append(prevBtn, title, nextBtn);
        picker.appendChild(header);

        const grid = document.createElement('div');
        grid.className = 'eventadmin-date-picker-grid';

        // Weekday headings, starting on the site's "Week starts on" day.
        const weekdayFmt = new Intl.DateTimeFormat(locale, {weekday: 'narrow', timeZone: 'UTC'});
        for (let i = 0; i < 7; i++) {
            const wd = (startOfWeek + i) % 7;
            const cell = document.createElement('span');
            cell.className = 'eventadmin-date-picker-weekday';
            // 2023-01-01 was a Sunday (weekday 0).
            cell.textContent = weekdayFmt.format(new Date(Date.UTC(2023, 0, 1 + wd)));
            grid.appendChild(cell);
        }

        const firstWeekday = new Date(Date.UTC(year, month, 1)).getUTCDay();
        const offset = (firstWeekday - startOfWeek + 7) % 7;
        for (let i = 0; i < offset; i++) {
            grid.appendChild(document.createElement('span'));
        }

        const daysInMonth = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
        for (let d = 1; d <= daysInMonth; d++) {
            const date = ymd(year, month, d);
            const status = cfg.days[date];
            let cell;
            if (status) {
                cell = document.createElement('a');
                cell.href = cfg.url.replace('__DATE__', date);
                cell.title = cfg.i18n.status[status] || '';
                const dot = document.createElement('span');
                dot.className = 'eventadmin-date-dot is-' + status;
                dot.setAttribute('aria-hidden', 'true');
                cell.append(String(d), dot);
            } else {
                cell = document.createElement('span');
                cell.className = 'is-empty';
                cell.textContent = String(d);
            }
            cell.classList.add('eventadmin-date-picker-day');
            if (date === cfg.selected) cell.classList.add('is-selected');
            if (date === cfg.today) cell.classList.add('is-today');
            grid.appendChild(cell);
        }
        picker.appendChild(grid);
    }

    function open() {
        render();
        picker.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
    }
    function close() {
        picker.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.addEventListener('click', (e) => {
        e.stopPropagation();
        picker.hidden ? open() : close();
    });
    picker.addEventListener('click', (e) => e.stopPropagation());
    document.addEventListener('click', close);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
});

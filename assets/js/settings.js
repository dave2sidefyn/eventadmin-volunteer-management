document.addEventListener('DOMContentLoaded', function () {
    const cfg = window.EVENTADMIN_SETTINGS || {};

    const dummyData = {
        '{first}': 'Anna',
        '{last}': 'Example',
        '{title}': 'Bar (Friday Evening)',
        '{desc}': 'Serve drinks and collect money (18+)',
        '{start}': cfg.start_label || 'Tuesday, 16. June 2026, 08:00',
        '{end}':   cfg.end_label   || '22:00',
        '{days}':  cfg.days_label  || '7'
    };

    function replacePlaceholders(template) {
        let result = template;
        for (const [key, value] of Object.entries(dummyData)) {
            result = result.replaceAll(key, value);
        }
        return result;
    }

    function updatePreview() {
        const config = [
            ['assign', 'eventadmin_email_subject_assign', 'eventadmin_email_text_assign'],
            ['unassign', 'eventadmin_email_subject_unassign', 'eventadmin_email_text_unassign'],
            ['reminder', 'eventadmin_email_subject_reminder', 'eventadmin_email_text_reminder']
        ];

        config.forEach(([key, subjectName, bodyName]) => {
            const subjectInput = document.querySelector(`input[name="${subjectName}"]`);
            const bodyInput = document.querySelector(`textarea[name="${bodyName}"]`);
            const subjectOut = document.getElementById(`preview-subject-${key}`);
            const bodyOut = document.getElementById(`preview-body-${key}`);

            if (!subjectInput || !bodyInput || !subjectOut || !bodyOut) {
                return;
            }

            subjectOut.textContent = replacePlaceholders(subjectInput.value);
            bodyOut.innerHTML = wpautop(replacePlaceholders(bodyInput.value));
        });
    }

    function wpautop(str) {
        return str.replace(/\n\n+/g, '</p><p>').replace(/\n/g, '<br>');
    }

    // Refresh {start} and {end} via AJAX when the date/time format fields change
    let formatDebounce = null;
    function refreshDateFormat() {
        if (!cfg.ajax_url || !cfg.nonce) return;
        clearTimeout(formatDebounce);
        formatDebounce = setTimeout(function () {
            const dateFormat = document.querySelector('input[name="eventadmin_shift_date_format"]')?.value || 'l, j. F Y, H:i';
            const timeFormat = document.querySelector('input[name="eventadmin_shift_time_format"]')?.value || 'H:i';

            const body = new URLSearchParams({
                action:      'eventadmin_preview_date_format',
                nonce:       cfg.nonce,
                date_format: dateFormat,
                time_format: timeFormat,
            });

            fetch(cfg.ajax_url, { method: 'POST', body })
                .then(r => r.json())
                .then(function (res) {
                    if (res.success) {
                        dummyData['{start}'] = res.data.start;
                        dummyData['{end}']   = res.data.end;
                        updatePreview();
                    }
                })
                .catch(function () {});
        }, 400);
    }

    ['input', 'textarea'].forEach(selector => {
        document.querySelectorAll(selector).forEach(el => {
            const name = el.getAttribute('name') || '';
            if (name === 'eventadmin_shift_date_format' || name === 'eventadmin_shift_time_format') {
                el.addEventListener('input', refreshDateFormat);
            } else if (name === 'eventadmin_email_reminder_days') {
                el.addEventListener('input', function () {
                    dummyData['{days}'] = (el.value.split(',')[0] || '7').trim() || '7';
                    updatePreview();
                });
            } else {
                el.addEventListener('input', updatePreview);
            }
        });
    });

    updatePreview();
});

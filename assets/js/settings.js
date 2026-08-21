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

    // Renders each preview through the real email template (header logo, footer, colors)
    // via AJAX, so admins see an accurate approximation instead of plain text. Debounced
    // and de-duplicated (by request signature) since it fires on every keystroke across
    // three previews plus the shared logo/footer fields that affect all of them.
    let previewDebounce = null;
    function updatePreview() {
        clearTimeout(previewDebounce);
        previewDebounce = setTimeout(renderPreviews, 400);
    }

    // Reads a field's current value — from its TinyMCE instance when the (teeny) visual
    // editor is active, since that only syncs to the underlying textarea on blur/save,
    // not on every keystroke — falling back to the plain textarea otherwise.
    function getEditorValue(name) {
        const editor = window.tinymce && window.tinymce.get(name);
        if (editor) return editor.getContent();
        return document.querySelector(`textarea[name="${name}"]`)?.value ?? '';
    }

    function renderPreviews() {
        const config = [
            ['assign', 'eventadmin_email_subject_assign', 'eventadmin_email_text_assign'],
            ['unassign', 'eventadmin_email_subject_unassign', 'eventadmin_email_text_unassign'],
            ['reminder', 'eventadmin_email_subject_reminder', 'eventadmin_email_text_reminder']
        ];

        config.forEach(([key, subjectName, bodyName]) => {
            const subjectInput = document.querySelector(`input[name="${subjectName}"]`);
            const subjectOut = document.getElementById(`preview-subject-${key}`);
            const iframe = document.getElementById(`preview-body-${key}`);

            if (!subjectInput || !subjectOut || !iframe) {
                return;
            }

            const subject = replacePlaceholders(subjectInput.value);
            const body    = replacePlaceholders(getEditorValue(bodyName));

            subjectOut.textContent = subject;
            renderTemplatePreview(subject, body, iframe);
        });
    }

    function renderTemplatePreview(subject, body, iframe) {
        if (!cfg.ajax_url || !cfg.preview_nonce) return;

        // Preview the header logo/color/title/footer as currently edited (possibly
        // unsaved yet), not just what's already stored in the database.
        const logoInput      = document.getElementById('eventadmin_email_header_logo_id');
        const colorToggle    = document.getElementById('eventadmin-email-header-color-toggle');
        const colorInput     = document.getElementById('eventadmin-email-header-color-input');
        const textColorInput = document.querySelector('input[name="eventadmin_email_header_text_color"]');
        const titleInput     = document.querySelector('input[name="eventadmin_email_header_title"]');
        const subtitleInput  = document.querySelector('input[name="eventadmin_email_header_subtitle"]');
        const footerHtml     = getEditorValue('eventadmin_email_footer_html');
        const customCssInput = document.getElementById('eventadmin_email_custom_css');

        const params = new URLSearchParams({
            action:            'eventadmin_render_email_preview',
            nonce:              cfg.preview_nonce,
            subject:            subject,
            body:               body,
            footer_html:        footerHtml,
            logo_id:            logoInput ? logoInput.value : '',
            header_color:       (colorToggle && colorToggle.checked && colorInput) ? colorInput.value : '',
            header_text_color:  textColorInput ? textColorInput.value : '',
            header_title:       titleInput ? titleInput.value : '',
            header_subtitle:    subtitleInput ? subtitleInput.value : '',
            custom_css:         customCssInput ? customCssInput.value : '',
        });

        fetch(cfg.ajax_url, { method: 'POST', body: params })
            .then(r => r.json())
            .then(function (res) {
                if (res.success) {
                    iframe.srcdoc = res.data.html;
                }
            })
            .catch(function () {});
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

    // Header logo picker (media library, images only)
    let logoFrame;
    const logoButton  = document.getElementById('eventadmin-email-logo-button');
    const logoRemove  = document.getElementById('eventadmin-email-logo-remove');
    const logoInput   = document.getElementById('eventadmin_email_header_logo_id');
    const logoPreview = document.getElementById('eventadmin-email-logo-preview');

    if (logoButton && window.wp && window.wp.media) {
        logoButton.addEventListener('click', function (e) {
            e.preventDefault();
            if (logoFrame) {
                logoFrame.open();
                return;
            }
            logoFrame = window.wp.media({
                title: cfg.i18n?.selectLogoTitle || 'Select a logo image',
                button: { text: cfg.i18n?.selectLogoButton || 'Use this image' },
                library: { type: 'image' },
                multiple: false,
            });
            logoFrame.on('select', function () {
                const attachment = logoFrame.state().get('selection').first().toJSON();
                logoInput.value = attachment.id;
                logoPreview.querySelector('img').src = attachment.sizes?.medium?.url || attachment.url;
                logoPreview.style.display = '';
                logoRemove.style.display = '';
                updatePreview();
            });
            logoFrame.open();
        });
    }

    if (logoRemove) {
        logoRemove.addEventListener('click', function (e) {
            e.preventDefault();
            logoInput.value = '';
            logoPreview.style.display = 'none';
            logoRemove.style.display = 'none';
            updatePreview();
        });
    }

    // Header color: the color input is only meaningful while the checkbox is on
    const colorToggle = document.getElementById('eventadmin-email-header-color-toggle');
    const colorInput  = document.getElementById('eventadmin-email-header-color-input');
    if (colorToggle && colorInput) {
        colorToggle.addEventListener('change', function () {
            colorInput.disabled = !colorToggle.checked;
            updatePreview();
        });
    }

    // The footer and email-text fields are TinyMCE (teeny) editors — they only sync to
    // their underlying textarea on blur/save, not on every keystroke, so their own change
    // events need hooking too for the preview to stay live while typing. wp_editor()'s
    // inline bootstrap script can finish initializing an editor before this script's own
    // ready handler runs, so bind directly to any editor that already exists — waiting on
    // "AddEditor" alone would silently miss it, since that event never fires again.
    const richTextFieldIds = [
        'eventadmin_email_footer_html',
        'eventadmin_email_text_assign',
        'eventadmin_email_text_unassign',
        'eventadmin_email_text_reminder',
    ];
    if (window.tinymce) {
        richTextFieldIds.forEach(function (id) {
            const existingEditor = window.tinymce.get(id);
            if (existingEditor) {
                existingEditor.on('input keyup change', updatePreview);
            }
        });
        window.tinymce.on('AddEditor', function (e) {
            if (richTextFieldIds.includes(e.editor.id)) {
                e.editor.on('input keyup change', updatePreview);
            }
        });
    }

    updatePreview();
});

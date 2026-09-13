jQuery(function ($) {
    const cfg = EVENTADMIN_BULK_EMAIL;

    // The message body is a TinyMCE (teeny) editor — it only syncs to its underlying
    // textarea on blur/save, not on every keystroke, so read its live content directly.
    function getEditorValue(name) {
        const editor = window.tinymce && window.tinymce.get(name);
        if (editor && !editor.isHidden()) {
            return editor.getContent();
        }
        return $('[name="' + name + '"]').val();
    }

    // Show recipient count / shift selector matching selected radio
    $('[name="bulk_email_recipients"]').on('change', function () {
        const val = $(this).val();
        $('.bulk-email-count').hide();
        $('.bulk-email-count[data-for="' + val + '"]').show();
        $('#eventadmin-shift-select-wrap').toggle(val === 'shift');
        $('#eventadmin-category-select-wrap').toggle(val === 'category');
        $('#eventadmin-department-select-wrap').toggle(val === 'department_link');
    });

    // Live recipient count when a specific shift, category or department is picked
    function fetchRecipientCount(recipients, id, $target) {
        if (!id) {
            $target.text('').removeAttr('title').css('cursor', '');
            return;
        }
        $target.text(cfg.i18n.counting);
        $.post(cfg.ajax_url, {
            action:                  'eventadmin_bulk_email_count',
            _ajax_nonce:             cfg.nonce_batch,
            bulk_email_recipients:   recipients,
            bulk_email_shift_id:     recipients === 'shift'          ? id : '',
            bulk_email_category_id:  recipients === 'category'       ? id : '',
            bulk_email_department_id: recipients === 'department_link' ? id : '',
        })
        .done(function (res) {
            if (res.success) {
                const n = res.data.count;
                const tpl = n === 1 ? cfg.i18n.recipientCountOne : cfg.i18n.recipientCountMany;
                $target.text(tpl.replace('{n}', n))
                    .attr('title', res.data.tooltip || '')
                    .css('cursor', res.data.tooltip ? 'help' : '');
            } else {
                $target.text('').removeAttr('title').css('cursor', '');
            }
        })
        .fail(function () {
            $target.text('').removeAttr('title').css('cursor', '');
        });
    }

    $('[name="bulk_email_shift_id"]').on('change', function () {
        fetchRecipientCount('shift', $(this).val(), $('#eventadmin-shift-recipient-count'));
    });
    $('[name="bulk_email_category_id"]').on('change', function () {
        fetchRecipientCount('category', $(this).val(), $('#eventadmin-category-recipient-count'));
    });
    $('[name="bulk_email_department_id"]').on('change', function () {
        fetchRecipientCount('department_link', $(this).val(), $('#eventadmin-department-recipient-count'));
    });

    // Attachment: WP media picker restricted to PDFs
    let attachmentFrame;
    $('#bulk_email_attachment_button').on('click', function (e) {
        e.preventDefault();
        if (attachmentFrame) {
            attachmentFrame.open();
            return;
        }
        attachmentFrame = wp.media({
            title: cfg.i18n.selectPdfTitle,
            button: { text: cfg.i18n.selectPdfButton },
            library: { type: 'application/pdf' },
            multiple: false,
        });
        attachmentFrame.on('select', function () {
            const attachment = attachmentFrame.state().get('selection').first().toJSON();
            $('#bulk_email_attachment_id').val(attachment.id);
            $('#bulk_email_attachment_name').text(attachment.filename);
            $('#bulk_email_attachment_remove').show();
            updatePreview();
        });
        attachmentFrame.open();
    });

    $('#bulk_email_attachment_remove').on('click', function (e) {
        e.preventDefault();
        $('#bulk_email_attachment_id').val('');
        $('#bulk_email_attachment_name').text('');
        $(this).hide();
        updatePreview();
    });

    $('#eventadmin-bulk-email-form').on('submit', function (e) {
        e.preventDefault();

        const $form     = $(this);
        const $progress = $('#eventadmin-bulk-email-progress');
        const $bar      = $('#eventadmin-bulk-email-bar');
        const $status   = $('#eventadmin-bulk-email-status');

        $form.hide();
        $progress.show();
        $status.text('');
        $bar.css('width', '0%');

        // Step 1: initialise the job
        $.post(cfg.ajax_url, {
            action:                      'eventadmin_bulk_email_init',
            eventadmin_bulk_email_nonce: $form.find('[name="eventadmin_bulk_email_nonce"]').val(),
            bulk_email_from_name:        $form.find('[name="bulk_email_from_name"]').val(),
            bulk_email_from_email:       $form.find('[name="bulk_email_from_email"]').val(),
            bulk_email_subject:          $form.find('[name="bulk_email_subject"]').val(),
            bulk_email_body:             getEditorValue('bulk_email_body'),
            bulk_email_recipients:       $form.find('[name="bulk_email_recipients"]:checked').val(),
            bulk_email_shift_id:         $form.find('[name="bulk_email_shift_id"]').val(),
            bulk_email_category_id:      $form.find('[name="bulk_email_category_id"]').val(),
            bulk_email_department_id:    $form.find('[name="bulk_email_department_id"]').val(),
            bulk_email_user_id:          $form.find('[name="bulk_email_user_id"]').val(),
            bulk_email_attachment_id:    $form.find('[name="bulk_email_attachment_id"]').val(),
        })
        .done(function (res) {
            if (!res.success) {
                showError(res.data && res.data.message ? res.data.message : cfg.i18n.error);
                return;
            }
            sendBatch(res.data.job_key, res.data.total, 0);
        })
        .fail(function () {
            showError(cfg.i18n.error);
        });
    });

    function sendBatch(jobKey, total, sent) {
        const $bar    = $('#eventadmin-bulk-email-bar');
        const $status = $('#eventadmin-bulk-email-status');

        $.post(cfg.ajax_url, {
            action:      'eventadmin_bulk_email_batch',
            _ajax_nonce: cfg.nonce_batch,
            job_key:     jobKey,
        })
        .done(function (res) {
            if (!res.success) {
                showError(res.data && res.data.message ? res.data.message : cfg.i18n.error);
                return;
            }

            const data = res.data;
            const pct  = total > 0 ? Math.round((data.sent / total) * 100) : 100;

            $bar.css('width', pct + '%');
            $status.text(
                cfg.i18n.sending
                    .replace('{sent}', data.sent)
                    .replace('{total}', data.total)
            );

            if (data.done) {
                const failedMsg = data.failed > 0
                    ? ' ' + cfg.i18n.failed.replace('{failed}', data.failed)
                    : '';
                $status.text(cfg.i18n.done + failedMsg);
            } else {
                sendBatch(jobKey, total, data.sent);
            }
        })
        .fail(function () {
            showError(cfg.i18n.error);
        });
    }

    function showError(msg) {
        const $progress = $('#eventadmin-bulk-email-progress');
        const $form     = $('#eventadmin-bulk-email-form');
        $progress.append('<div class="notice notice-error"><p>' + $('<span>').text(msg).html() + '</p></div>');
        $form.show();
    }

    // Live preview
    const previewPlaceholders = {
        '{first_name}': 'Anna',
        '{last_name}':  'Example',
    };
    const previewShiftsHtml = '<ul><li>Registration Desk — Sat, 12 Sep 2026, 09:00–13:00</li><li>Bar — Sat, 12 Sep 2026, 18:00–22:00</li></ul>';

    function applyPlaceholders(str) {
        for (const [key, val] of Object.entries(previewPlaceholders)) {
            str = str.split(key).join(val);
        }
        return str;
    }

    let previewDebounce = null;
    function updatePreview() {
        const fromName  = $('[name="bulk_email_from_name"]').val();
        const fromEmail = $('[name="bulk_email_from_email"]').val();
        const subject   = $('[name="bulk_email_subject"]').val();
        const body      = getEditorValue('bulk_email_body');

        const fromLabel = fromName
            ? fromName + (fromEmail ? ' <' + fromEmail + '>' : '')
            : fromEmail;

        $('#ea-preview-from').text(fromLabel || '—');

        const attachmentName = $('#bulk_email_attachment_name').text();
        $('#ea-preview-attachment-row').toggle(!!attachmentName);
        $('#ea-preview-attachment').text(attachmentName);

        const subjectReplaced = applyPlaceholders(subject);
        const bodyReplaced    = applyPlaceholders(body).split('{shifts}').join(previewShiftsHtml);

        // Render through the real email template (header logo, footer, colors) via AJAX,
        // debounced since this fires on every keystroke.
        clearTimeout(previewDebounce);
        previewDebounce = setTimeout(function () {
            if (!cfg.ajax_url || !cfg.nonce_preview) return;
            $.post(cfg.ajax_url, {
                action:  'eventadmin_render_email_preview',
                nonce:   cfg.nonce_preview,
                subject: subjectReplaced,
                body:    bodyReplaced,
            }).done(function (res) {
                if (res.success) {
                    $('#ea-preview-body').attr('srcdoc', res.data.html);
                }
            });
        }, 400);
    }

    $('#eventadmin-bulk-email-form').on('input', 'input, textarea', updatePreview);

    // wp_editor()'s inline bootstrap script runs as the page is parsed, which can complete
    // before this script's own ready handler does — so the editor may already exist by the
    // time we get here. Bind directly in that case instead of only waiting for "AddEditor",
    // which would otherwise never fire again and silently drop the live preview updates.
    if (window.tinymce) {
        const existingEditor = window.tinymce.get('bulk_email_body');
        if (existingEditor) {
            existingEditor.on('input keyup change', updatePreview);
        } else {
            window.tinymce.on('AddEditor', function (e) {
                if (e.editor.id === 'bulk_email_body') {
                    e.editor.on('input keyup change', updatePreview);
                }
            });
        }
    }

    updatePreview();

    // History table: filter
    $(document).on('input', '#eventadmin-history-filter', function () {
        const q = $(this).val().toLowerCase();
        $('#eventadmin-history-table tbody tr').each(function () {
            $(this).toggle($(this).text().toLowerCase().indexOf(q) > -1);
        });
    });

    // History table: sort
    let sortCol = null, sortAsc = true;
    $(document).on('click', '#eventadmin-history-table thead th[data-sort]', function () {
        const col = $(this).data('sort');
        if (sortCol === col) {
            sortAsc = !sortAsc;
        } else {
            sortCol = col;
            sortAsc = true;
        }
        $('#eventadmin-history-table thead th .eventadmin-sort-icon').text('↕').css('opacity', '.4');
        $(this).find('.eventadmin-sort-icon').text(sortAsc ? '↑' : '↓').css('opacity', '1');

        const $tbody = $('#eventadmin-history-table tbody');
        const rows   = $tbody.find('tr').toArray();
        rows.sort(function (a, b) {
            const aVal = $(a).data(col) + '';
            const bVal = $(b).data(col) + '';
            const num  = !isNaN(parseFloat(aVal)) && !isNaN(parseFloat(bVal));
            const cmp  = num ? parseFloat(aVal) - parseFloat(bVal) : aVal.localeCompare(bVal);
            return sortAsc ? cmp : -cmp;
        });
        $tbody.append(rows);
    });
});

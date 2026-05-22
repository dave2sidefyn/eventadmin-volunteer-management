jQuery(function ($) {
    const cfg = EVENTADMIN_BULK_EMAIL;

    // Show recipient count / shift selector matching selected radio
    $('[name="bulk_email_recipients"]').on('change', function () {
        const val = $(this).val();
        $('.bulk-email-count').hide();
        $('.bulk-email-count[data-for="' + val + '"]').show();
        $('#eventadmin-shift-select-wrap').toggle(val === 'shift');
        $('#eventadmin-category-select-wrap').toggle(val === 'category');
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
            bulk_email_body:             $form.find('[name="bulk_email_body"]').val(),
            bulk_email_recipients:       $form.find('[name="bulk_email_recipients"]:checked').val(),
            bulk_email_shift_id:         $form.find('[name="bulk_email_shift_id"]').val(),
            bulk_email_category_id:      $form.find('[name="bulk_email_category_id"]').val(),
            bulk_email_user_id:          $form.find('[name="bulk_email_user_id"]').val(),
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

    function applyPlaceholders(str) {
        for (const [key, val] of Object.entries(previewPlaceholders)) {
            str = str.split(key).join(val);
        }
        return str;
    }

    function updatePreview() {
        const fromName  = $('[name="bulk_email_from_name"]').val();
        const fromEmail = $('[name="bulk_email_from_email"]').val();
        const subject   = $('[name="bulk_email_subject"]').val();
        const body      = $('[name="bulk_email_body"]').val();

        const fromLabel = fromName
            ? fromName + (fromEmail ? ' <' + fromEmail + '>' : '')
            : fromEmail;

        $('#ea-preview-from').text(fromLabel || '—');
        $('#ea-preview-subject').text(applyPlaceholders(subject) || '—');
        const bodyReplaced = applyPlaceholders(body);
        // If body contains HTML tags, render as HTML; otherwise convert line breaks
        const hasHtml = /<[a-z][\s\S]*>/i.test(bodyReplaced);
        if (hasHtml) {
            $('#ea-preview-body').html(bodyReplaced);
        } else {
            $('#ea-preview-body').html(bodyReplaced.replace(/\n/g, '<br>'));
        }
    }

    $('#eventadmin-bulk-email-form').on('input', 'input, textarea', updatePreview);
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

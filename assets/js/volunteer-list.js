jQuery(function ($) {
    const cfg = EVENTADMIN_VOL;

    // Create volunteer / Grant role modals
    $(document).on('click', '.eventadmin-modal-open', function () {
        $($(this).data('target')).css('display', 'block');
    });
    $(document).on('click', '.eventadmin-modal-close', function () {
        $(this).closest('.eventadmin-modal-overlay').hide();
    });
    $(document).on('click', '.eventadmin-modal-overlay', function (e) {
        if (e.target === this) $(this).hide();
    });

    // Copy-to-clipboard icon buttons (E-Mail / Phone columns) — the visible text is
    // truncated, so this is how an admin gets the full value without selecting it by hand.
    $(document).on('click', '.eventadmin-copy-value', function (e) {
        e.preventDefault();
        const $btn   = $(this);
        const value  = $btn.data('copy');
        const $icon  = $btn.find('.dashicons');

        function showCopied() {
            $icon.removeClass('dashicons-clipboard').addClass('dashicons-yes');
            setTimeout(function () {
                $icon.removeClass('dashicons-yes').addClass('dashicons-clipboard');
            }, 1200);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(showCopied);
        } else {
            // Fallback for browsers/contexts without the async Clipboard API.
            const $tmp = $('<textarea>').val(value).css({position: 'fixed', left: '-9999px'}).appendTo('body');
            $tmp[0].select();
            document.execCommand('copy');
            $tmp.remove();
            showCopied();
        }
    });

    $('#eventadmin-vol-columns').on('click', function () {
        const expanded = $('#eventadmin-vol-table').toggleClass('eventadmin-compact-columns').hasClass('eventadmin-compact-columns') === false;
        $(this).attr('aria-pressed', String(expanded));
    });
    $(document).on('keydown', '#eventadmin-vol-table th[data-sort]', function (event) {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); $(this).trigger('click'); }
    });

    // Volunteer table: search
    function updateCount() {
        const visible = $('#eventadmin-vol-table tbody tr:visible').length;
        $('#eventadmin-vol-count').text(visible + ' ' + (cfg.i18n.volunteers || 'volunteers'));
    }

    $('#eventadmin-vol-search').on('input', function () {
        const q = $(this).val().toLowerCase();
        $('#eventadmin-vol-table tbody tr').each(function () {
            $(this).toggle($(this).text().toLowerCase().indexOf(q) > -1);
        });
        updateCount();
    });

    // Volunteer table: sort
    let volSortCol = null, volSortAsc = true;
    $(document).on('click', '#eventadmin-vol-table thead th[data-sort]', function () {
        const col = $(this).data('sort');
        volSortAsc = volSortCol === col ? !volSortAsc : true;
        volSortCol = col;
        $('#eventadmin-vol-table thead th .eventadmin-sort-icon').text('↕').css('opacity', '.4');
        $('#eventadmin-vol-table th[data-sort]').attr('aria-sort', 'none');
        $(this).attr('aria-sort', volSortAsc ? 'ascending' : 'descending');
        $(this).find('.eventadmin-sort-icon').text(volSortAsc ? '↑' : '↓').css('opacity', '1');
        const $tbody = $('#eventadmin-vol-table tbody');
        const rows   = $tbody.find('tr').toArray();
        rows.sort(function (a, b) {
            const aVal = $(a).data(col) + '';
            const bVal = $(b).data(col) + '';
            const num  = !isNaN(parseFloat(aVal)) && !isNaN(parseFloat(bVal));
            const cmp  = num ? parseFloat(aVal) - parseFloat(bVal) : aVal.localeCompare(bVal);
            return volSortAsc ? cmp : -cmp;
        });
        $tbody.append(rows);
    });

    updateCount();

    // Create new volunteer
    // No email address = offline volunteer, who can't receive a login link.
    $('#eventadmin-create-volunteer-form [name="user_identifier"]').on('input', function () {
        $('#eventadmin-create-volunteer-form [name="send_login_link"]').prop('disabled', $.trim($(this).val()) === '');
    }).trigger('input');

    $('#eventadmin-create-volunteer-form').on('submit', function (e) {
        e.preventDefault();
        const $form   = $(this);
        const $btn    = $form.find('button[type="submit"]');
        const $result = $('#eventadmin-create-volunteer-result');

        $btn.prop('disabled', true);
        $result.text('').css('color', '');

        $.post(cfg.ajax_url, {
            action:                            'eventadmin_create_volunteer',
            eventadmin_create_volunteer_nonce: $form.find('[name="eventadmin_create_volunteer_nonce"]').val(),
            first_name:                        $form.find('[name="first_name"]').val(),
            last_name:                         $form.find('[name="last_name"]').val(),
            user_identifier:                   $form.find('[name="user_identifier"]').val(),
            phone:                             $form.find('[name="phone"]').val(),
            send_login_link:                   $form.find('[name="send_login_link"]').is(':checked:enabled') ? '1' : '',
        })
        .done(function (res) {
            if (res.success) {
                // The server message says whether a login link went out; keep a failed-email
                // warning on screen long enough to read before reloading.
                const failed = !!(res.data && res.data.mail_failed);
                $result.css('color', failed ? '#d63638' : '').text((res.data && res.data.message) || cfg.i18n.volunteer_created);
                setTimeout(function () { location.reload(); }, failed ? 4000 : 800);
            } else {
                $result.css('color', '#d63638').text(res.data && res.data.message ? res.data.message : cfg.i18n.error);
                $btn.prop('disabled', false);
            }
        })
        .fail(function () {
            $result.css('color', '#d63638').text(cfg.i18n.error);
            $btn.prop('disabled', false);
        });
    });

    // Grant volunteer role
    $('#eventadmin-grant-role-form').on('submit', function (e) {
        e.preventDefault();
        const $form   = $(this);
        const $btn    = $form.find('button[type="submit"]');
        const $result = $('#eventadmin-grant-role-result');
        const userId  = $form.find('[name="user_id"]').val();

        if (!userId) return;

        $btn.prop('disabled', true);
        $result.text('');

        $.post(cfg.ajax_url, {
            action:                    'eventadmin_grant_volunteer_role',
            eventadmin_grant_role_nonce: $form.find('[name="eventadmin_grant_role_nonce"]').val(),
            user_id:                   userId,
        })
        .done(function (res) {
            if (res.success) {
                $result.text(cfg.i18n.role_granted);
                setTimeout(function () { location.reload(); }, 800);
            } else {
                $result.css('color', '#d63638').text(res.data && res.data.message ? res.data.message : cfg.i18n.error);
                $btn.prop('disabled', false);
            }
        })
        .fail(function () {
            $result.css('color', '#d63638').text(cfg.i18n.error);
            $btn.prop('disabled', false);
        });
    });

    // Remove volunteer role
    $(document).on('click', '.eventadmin-remove-role', function () {
        const $btn       = $(this);
        const userId     = $btn.data('user-id');
        const name       = $btn.data('name');
        const shiftCount = parseInt($btn.data('shift-count'), 10) || 0;

        let msg;
        if (shiftCount > 0) {
            msg = cfg.i18n.remove_confirm
                .replace('{name}', name)
                .replace('{shifts}', shiftCount);
        } else {
            msg = cfg.i18n.remove_confirm_no_shifts.replace('{name}', name);
        }

        if (!window.confirm(msg)) return;

        $btn.prop('disabled', true);

        $.post(cfg.ajax_url, {
            action:      'eventadmin_remove_volunteer_role',
            _ajax_nonce: cfg.nonce_remove,
            user_id:     userId,
        })
        .done(function (res) {
            if (res.success) {
                $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
            } else {
                alert(res.data && res.data.message ? res.data.message : cfg.i18n.error);
                $btn.prop('disabled', false);
            }
        })
        .fail(function () {
            alert(cfg.i18n.error);
            $btn.prop('disabled', false);
        });
    });

    // Clear auto-deleted unverified accounts log
    $(document).on('click', '#eventadmin-clear-cleanup-log', function () {
        const $btn = $(this);

        if (!window.confirm(cfg.i18n.clear_cleanup_log_confirm)) return;

        $btn.prop('disabled', true);

        $.post(cfg.ajax_url, {
            action:      'eventadmin_clear_cleanup_log',
            _ajax_nonce: cfg.nonce_clear_cleanup_log,
        })
        .done(function (res) {
            if (res.success) {
                $('#eventadmin-cleanup-log-section').remove();
            } else {
                alert(res.data && res.data.message ? res.data.message : cfg.i18n.error);
                $btn.prop('disabled', false);
            }
        })
        .fail(function () {
            alert(cfg.i18n.error);
            $btn.prop('disabled', false);
        });
    });

});

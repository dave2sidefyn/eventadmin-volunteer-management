jQuery(function ($) {
    const cfg = EVENTADMIN_VOL;

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
        })
        .done(function (res) {
            if (res.success) {
                $result.text(cfg.i18n.volunteer_created || res.data.message);
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

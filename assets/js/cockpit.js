document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.eventadmin-tabs').forEach(root => {
        const tabs = [...root.querySelectorAll('[role="tab"]')];
        const panels = [...root.querySelectorAll('[role="tabpanel"]')];
        function select(tab) {
            tabs.forEach(item => {
                const active = item === tab;
                item.classList.toggle('active', active);
                item.setAttribute('aria-selected', String(active));
                item.tabIndex = active ? 0 : -1;
            });
            panels.forEach(panel => { panel.hidden = panel.id !== tab.dataset.tab; });
        }
        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => select(tab));
            tab.addEventListener('keydown', event => {
                let next;
                if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
                if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
                if (event.key === 'Home') next = 0;
                if (event.key === 'End') next = tabs.length - 1;
                if (next === undefined) return;
                event.preventDefault();
                select(tabs[next]);
                tabs[next].focus();
            });
        });
    });
});

jQuery(document).ready(function ($) {

    function sortShiftsByStart($container) {
        const $boxes = $container.find('.shift-box');

        $boxes.sort(function (a, b) {
            const aStart = new Date($(a).find('.shift-datetime').data('start'));
            const bStart = new Date($(b).find('.shift-datetime').data('start'));
            return aStart - bStart;
        });

        $boxes.detach().appendTo($container);
    }

    let $eventadmin = $('.eventadmin-assign-form');
    $eventadmin.each(function () {
        const $form = $(this);
        const $box = $form.closest('.shift-box');
        const hoursLeft = parseInt($box.data('hours-left'), 10);
        const unassignLimit = parseInt(eventadmin_ajax.unassign_limit, 10);

        if (
            $form.find('input[name="action"]').val() === 'eventadmin_unassign_ajax' &&
            !isNaN(hoursLeft) && !isNaN(unassignLimit) && hoursLeft < unassignLimit
        ) {
            const $btn = $form.find('input[type="submit"]');
            $btn.prop('disabled', true);
            $btn.val(eventadmin_ajax.i18n.unassign_disabled);
        }
    });

    // Message element
    const $msg = $('<div class="shift-message" role="status" aria-live="polite" style="display:none;"></div>').prependTo('body');

    $eventadmin.on('submit', function (e) {
        e.preventDefault();

        const form = $(this);
        const shiftBox = form.closest('.shift-box');
        const section = shiftBox.parent(); // current section

        // Get form data as array
        const dataArray = form.serializeArray();

        // Add nonce
        dataArray.push({name: '_ajax_nonce', value: eventadmin_ajax.nonce});

        if (form.data('pending')) return;
        form.data('pending', true);
        form.find(':submit').prop('disabled', true);
        const data = form.serialize();
        $.post(eventadmin_ajax.ajax_url, dataArray, function (response) {
            $msg.removeClass('success error').addClass(response.success ? 'success' : 'error')
                .html(response.data.message || eventadmin_ajax.i18n.unknown_error)
                .fadeIn().delay(2500).fadeOut();

            if (response.success) {
                // Visually move
                let $target;
                if (data.includes('eventadmin_assign_ajax')) {
                    $target = $('#section-my-shifts');
                } else if (data.includes('eventadmin_unassign_ajax')) {
                    $target = $('#section-open-shifts');
                } else {
                    return; // no action
                }

                shiftBox.fadeOut(300, function () {
                    shiftBox.detach().appendTo($target).hide().fadeIn(300, function () {
                        // ✅ After FadeIn → update UI
                        // Hide "no shifts" notice in target
                        $target.find('.no-shifts').hide();

                        // If list is empty after unassign, show the message again
                        const $oldSection = section;
                        if ($oldSection.find('.shift-box').length === 0) {
                            $oldSection.find('.no-shifts').show();
                        }

                        // Update assigned row
                        shiftBox.find('.shift-open').text(response.data.open);
                        shiftBox.find('.shift-names').text(response.data.names);


                        // Update form
                        const newForm = shiftBox.find('form.eventadmin-assign-form');
                        const $button = newForm.find('input[type="submit"]');

                        if (data.includes('eventadmin_assign_ajax')) {
                            newForm.find('input[name="action"]').val('eventadmin_unassign_ajax');
                            newForm.find('input[type="submit"]').val(eventadmin_ajax.i18n.unassign);
                            $button.val(eventadmin_ajax.i18n.unassign)
                                .removeClass('button')
                                .addClass('button-red');
                        } else {
                            newForm.find('input[name="action"]').val('eventadmin_assign_ajax');
                            newForm.find('input[type="submit"]').val(eventadmin_ajax.i18n.assign);
                            $button.val(eventadmin_ajax.i18n.assign)
                                .removeClass('button-red')
                                .addClass('button');
                        }

                        sortShiftsByStart($target);
                        applyShiftFilters();
                        labelActions();
                    });
                });
            }
        }).fail(function () {
            $msg.removeClass('success').addClass('error').text(eventadmin_ajax.i18n.unknown_error).show();
        }).always(function () {
            form.data('pending', false);
            form.find(':submit').prop('disabled', false);
        });
    });

    function labelActions() {
        $('.eventadmin-assign-form').each(function () {
            const button = $(this).find(':submit');
            button.attr('aria-label', button.val() + ' — ' + $(this).data('context'));
        });
    }
    function groupDays() {
        $('.shift-section').each(function () {
            const section = $(this);
            section.find('.shift-day-heading').remove();
            let previous = '';
            section.find('.shift-box').each(function () {
                if (this.hidden) return;
                const date = this.dataset.shiftDate;
                if (date !== previous) $('<h3 class="shift-day-heading">').text(this.dataset.dateLabel).insertBefore(this);
                previous = date;
            });
        });
    }
    labelActions();

    // Unified shift filter (applies to available-shifts section only)
    const activeFilters = { category: 'all', text: '', date: '' };

    function applyShiftFilters() {
        $('#section-open-shifts .shift-box').each(function () {
            const $box    = $(this);
            const cats    = $box.data('category')?.split(' ') || [];
            const text    = $box.text().toLowerCase();
            const startRaw = $box.find('.shift-datetime').data('start') || '';
            const startDate = startRaw.slice(0, 10); // "2026-06-16"

            const catOk  = activeFilters.category === 'all' || cats.includes(activeFilters.category);
            const textOk = !activeFilters.text || text.includes(activeFilters.text.toLowerCase());
            const dateOk = !activeFilters.date || startDate === activeFilters.date;

            if (catOk && textOk && dateOk) {
                $box.prop('hidden', false).show();
            } else {
                $box.prop('hidden', true).hide();
            }
        });
        const open = $('#section-open-shifts');
        const anyVisible = open.find('.shift-box').toArray().some(box => !box.hidden);
        $('#eventadmin-filter-empty').prop('hidden', anyVisible || !open.find('.shift-box').length);
        $('.eventadmin-clear-shift-filters').prop('hidden', !activeFilters.text && !activeFilters.date && activeFilters.category === 'all');
        groupDays();
    }

    $('.eventadmin-clear-shift-filters').on('click', function () {
        activeFilters.category = 'all'; activeFilters.text = ''; activeFilters.date = '';
        $('#shift-category-filter').val('all'); $('#shift-text-filter, #shift-date-filter').val('');
        applyShiftFilters();
        $('#shift-text-filter, #shift-date-filter, #shift-category-filter').first().trigger('focus');
    });
    $('.eventadmin-find-shift').on('click', function () { $('#section-open-shifts').trigger('focus'); });

    $('#shift-category-filter').on('change', function () {
        activeFilters.category = $(this).val();
        applyShiftFilters();
    });

    $('#shift-text-filter').on('input', function () {
        activeFilters.text = $(this).val().trim();
        applyShiftFilters();
    });

    $('#shift-date-filter').on('input change', function () {
        activeFilters.date = $(this).val();
        applyShiftFilters();
    });

    applyShiftFilters();
});

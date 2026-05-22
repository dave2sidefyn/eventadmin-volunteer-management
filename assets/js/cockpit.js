document.addEventListener('DOMContentLoaded', function () {
    const tabs = document.querySelectorAll('.tab-item');
    const panels = document.querySelectorAll('.tab-panel');

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.tab;

            tabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');

            panels.forEach(panel => {
                panel.style.display = panel.id === target ? 'block' : 'none';
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
    const $msg = $('<div class="shift-message" style="display:none;"></div>').prependTo('body');

    $eventadmin.on('submit', function (e) {
        e.preventDefault();

        const form = $(this);
        const shiftBox = form.closest('.shift-box');
        const section = shiftBox.parent(); // current section

        // Get form data as array
        const dataArray = form.serializeArray();

        // Add nonce
        dataArray.push({name: '_ajax_nonce', value: eventadmin_ajax.nonce});

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
                        shiftBox.find('.shift-count').text(response.data.count);
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
                    });
                });
            }
        });
    });

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
                $box.slideDown(200);
            } else {
                $box.slideUp(200);
            }
        });
    }

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

});

(function ($) {
    'use strict';

    // ── Step Editor ──────────────────────────────────────────────────────────

    function reindexSteps() {
        $('#step-list .synmon-step-row').each(function (i) {
            $(this).find('[name]').each(function () {
                var name    = $(this).attr('name');
                var newName = name.replace(/steps\[\d+]/, 'steps[' + i + ']');
                $(this).attr('name', newName);
                // Keep sortOrder value in sync with actual position
                if (name.indexOf('[sortOrder]') !== -1) {
                    $(this).val(i);
                }
            });
            $(this).find('[data-step-index]').attr('data-step-index', i);
        });
    }

    // Add step via AJAX
    $(document).on('click', '#btn-add-step', function () {
        var $btn      = $(this);
        var type      = $('#new-step-type').val();
        var index     = $('#step-list .synmon-step-row').length;
        var csrfToken = $('meta[name="csrf-token"]').attr('content') ||
                        (window.Craft && Craft.csrfTokenValue) || '';

        $btn.prop('disabled', true);

        $.ajax({
            url: Craft.getCpUrl('synmon/suites/add-step'),
            method: 'POST',
            data: { type: type, index: index, CRAFT_CSRF_TOKEN: csrfToken },
            dataType: 'json',
        }).done(function (resp) {
            if (resp.success) {
                $('#step-list').append(resp.html);
                reindexSteps();
                initSortable();
            }
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    // Remove step (also removes the sibling hint div)
    $(document).on('click', '.btn-remove-step', function () {
        var $row = $(this).closest('.synmon-step-row');
        $row.next('.synmon-step-hint').remove();
        $row.remove();
        reindexSteps();
    });

    // ── Sortable drag-to-reorder ─────────────────────────────────────────────
    function initSortable() {
        var el = document.getElementById('step-list');
        if (!el || typeof Sortable === 'undefined') return;
        if (el._sortable) el._sortable.destroy();
        el._sortable = Sortable.create(el, {
            handle: '.synmon-drag-handle',
            animation: 150,
            onEnd: function () { reindexSteps(); }
        });
    }

    // Re-index before form submit
    $('form#suite-form').on('submit', function () {
        reindexSteps();
    });

    // ── Run Suite button → Queue ──────────────────────────────────────────────
    $(document).on('click', '.btn-run-suite', function (e) {
        e.preventDefault();
        var $btn      = $(this);
        var suiteId   = $btn.data('suite-id');
        var origText  = $btn.text();
        var csrfToken = (window.Craft && Craft.csrfTokenValue) || '';

        $btn.prop('disabled', true).text('⏳ Wird eingereiht…');

        $.ajax({
            url: Craft.getCpUrl('synmon/suites/run'),
            method: 'POST',
            data: { suiteId: suiteId, CRAFT_CSRF_TOKEN: csrfToken },
            dataType: 'json',
        }).done(function (resp) {
            if (resp.success) {
                Craft.cp.displayNotice('Suite in Queue eingereiht – Craft verarbeitet den Job automatisch.');
                if (resp.runsUrl) {
                    setTimeout(function () { window.location.href = resp.runsUrl; }, 1200);
                }
            }
        }).fail(function () {
            Craft.cp.displayError('Fehler beim Einreihen.');
            $btn.prop('disabled', false).text(origText);
        });
    });

    // ── Toggle Suite enabled ──────────────────────────────────────────────────
    $(document).on('change', '.suite-toggle', function () {
        var $toggle   = $(this);
        var suiteId   = $toggle.data('suite-id');
        var csrfToken = (window.Craft && Craft.csrfTokenValue) || '';

        $.ajax({
            url: Craft.getCpUrl('synmon/suites/toggle'),
            method: 'POST',
            data: { suiteId: suiteId, CRAFT_CSRF_TOKEN: csrfToken },
            dataType: 'json',
        }).done(function (resp) {
            if (!resp.success) {
                $toggle.prop('checked', !$toggle.prop('checked'));
            }
        });
    });

    // ── Cron Builder ─────────────────────────────────────────────────────────

    var cronManualMode = false;

    function buildCronExpression() {
        if (cronManualMode) return;

        // Collect times
        var hours   = [];
        var minutes = [];
        $('.cron-time').each(function () {
            var val = $(this).val();
            if (!val) return;
            var parts = val.split(':');
            hours.push(parseInt(parts[0], 10));
            minutes.push(parseInt(parts[1], 10));
        });

        if (hours.length === 0) {
            hours   = [8];
            minutes = [0];
        }

        // Unique minutes – use first if all same, else 0
        var uniqueMinutes = [...new Set(minutes)];
        var minutePart = uniqueMinutes.length === 1 ? uniqueMinutes[0] : minutes[0];
        var hourPart   = [...new Set(hours)].join(',');

        // Day of month
        var selectedDays = [];
        $('.cron-monthday:checked').each(function () {
            selectedDays.push($(this).val());
        });
        var dayPart = selectedDays.length ? selectedDays.join(',') : '*';

        // Weekdays
        var selectedWeekdays = [];
        $('.cron-weekday:checked').each(function () {
            selectedWeekdays.push($(this).val());
        });
        var weekdayPart = selectedWeekdays.length ? selectedWeekdays.join(',') : '*';

        // If both day-of-month and weekday are set, cron ORs them – warn user
        var expr = minutePart + ' ' + hourPart + ' ' + dayPart + ' * ' + weekdayPart;
        $('#cron-expression').val(expr);
        updateCronPreview(expr);
    }

    function updateCronPreview(expr) {
        if (!expr) return;
        var $preview = $('#cron-preview-text');
        var parts = expr.trim().split(/\s+/);
        if (parts.length !== 5) {
            $preview.text('');
            return;
        }
        var min     = parts[0];
        var hour    = parts[1];
        var day     = parts[2];
        var weekday = parts[4];

        var timeStr = hour === '*' ? 'jede Stunde' : 'um ' + hour.split(',').map(function (h) {
            return h + ':' + (min === '0' || min === '00' ? '00' : min.padStart(2, '0'));
        }).join(', ');

        var dayNames = ['', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        var dayStr;

        if (weekday !== '*' && day !== '*') {
            let wdLabels = weekday.split(',').map(function (d) { return dayNames[parseInt(d)] || d; });
            dayStr = ' | Wochentage: ' + wdLabels.join(', ') + ', Monatstage: ' + day;
        } else if (weekday !== '*') {
            let wdLabels = weekday.split(',').map(function (d) { return dayNames[parseInt(d)] || d; });
            dayStr = ' | ' + wdLabels.join(', ');
        } else if (day !== '*') {
            dayStr = ' | Tag ' + day + '. im Monat';
        } else {
            dayStr = ' | täglich';
        }

        $preview.text('→ ' + timeStr + dayStr);
    }

    // Init cron builder from existing expression
    function parseCronIntoUI(expr) {
        if (!expr) return;
        var parts = expr.trim().split(/\s+/);
        if (parts.length !== 5) return;

        var min     = parts[0];
        var hours   = parts[1];
        var days    = parts[2];
        var weekdays= parts[4];

        // Set times
        $('#cron-times-list').empty();
        if (hours !== '*') {
            hours.split(',').forEach(function (h) {
                var hh = parseInt(h, 10);
                var mm = parseInt(min, 10) || 0;
                addTimeEntry(
                    String(hh).padStart(2, '0') + ':' + String(mm).padStart(2, '0')
                );
            });
        } else {
            addTimeEntry('08:00');
        }

        // Set weekdays
        $('.cron-weekday').prop('checked', false);
        if (weekdays !== '*') {
            weekdays.split(',').forEach(function (d) {
                $('.cron-weekday[value="' + d + '"]').prop('checked', true);
            });
        }

        // Set month days
        $('.cron-monthday').prop('checked', false);
        if (days !== '*') {
            days.split(',').forEach(function (d) {
                $('.cron-monthday[value="' + parseInt(d, 10) + '"]').prop('checked', true);
            });
        }

        updateCronPreview(expr);
    }

    function addTimeEntry(value) {
        var $input = $('<input>').attr({ type: 'time', class: 'cron-time text', style: 'width:120px' }).val(value || '08:00');
        var $btn   = $('<button>').attr({ type: 'button', class: 'btn-remove-time btn small', style: 'padding:2px 8px' }).text('\u2715');
        var $row   = $('<div>').addClass('cron-time-entry').css({ display: 'flex', gap: '6px', alignItems: 'center' }).append($input, $btn);
        $('#cron-times-list').append($row);
    }

    // Add time
    $(document).on('click', '#btn-add-time', function () {
        addTimeEntry('08:00');
    });

    // Remove time
    $(document).on('click', '.btn-remove-time', function () {
        if ($('.cron-time-entry').length > 1) {
            $(this).closest('.cron-time-entry').remove();
            buildCronExpression();
        }
    });

    // Rebuild on any change
    $(document).on('change input', '.cron-weekday, .cron-monthday, .cron-time', function () {
        buildCronExpression();
    });

    // "Täglich" shortcut
    $(document).on('change', '#cron-every-day', function () {
        if ($(this).prop('checked')) {
            $('.cron-weekday').prop('checked', false);
            $('.cron-monthday').prop('checked', false);
        }
        buildCronExpression();
    });

    // Manual edit toggle
    $(document).on('click', '#btn-cron-advanced', function () {
        cronManualMode = !cronManualMode;
        $('#cron-expression').prop('readonly', !cronManualMode);
        $(this).toggleClass('active', cronManualMode);
        $(this).attr('title', cronManualMode ? 'Builder nutzen' : 'Manuell bearbeiten');
    });

    // Update preview when manually editing
    $(document).on('input', '#cron-expression', function () {
        if (cronManualMode) updateCronPreview($(this).val());
    });

    // ── Step type change: toggle selector/value fields + update hint ─────────
    function applyStepTypeVisibility($row, type) {
        var types  = window.synmonStepTypes || {};
        var cfg    = types[type] || {};
        var hasSel = cfg.hasSelector !== undefined ? cfg.hasSelector : true;
        var hasVal = cfg.hasValue    !== undefined ? cfg.hasValue    : true;

        $row.find('.step-selector-input').toggle(hasSel)
            .attr('placeholder', cfg.selectorPlaceholder || 'CSS-Selector');
        $row.find('.step-value-input').toggle(hasVal)
            .attr('placeholder', cfg.valuePlaceholder || 'Wert / URL');

        // Update hint text in the sibling hint div
        var $hint = $row.next('.synmon-step-hint');
        if ($hint.length) {
            $hint.html(cfg.hint || '');
        }
    }

    $(document).on('change', '.step-type-select', function () {
        applyStepTypeVisibility($(this).closest('.synmon-step-row'), $(this).val());
    });

    // ── Step help toggle ──────────────────────────────────────────────────────
    $(document).on('click', '.btn-step-help', function () {
        var $hint = $(this).closest('.synmon-step-row').next('.synmon-step-hint');
        $hint.slideToggle(150);
        $(this).toggleClass('active');
    });

    // ── Init ──────────────────────────────────────────────────────────────────
    $(document).ready(function () {
        initSortable();
        // Apply visibility for all existing step rows on load
        $('#step-list .synmon-step-row').each(function () {
            var type = $(this).find('.step-type-select').val();
            if (type) applyStepTypeVisibility($(this), type);
        });

        // Parse existing cron expression into UI
        var existingCron = $('#cron-expression').val();
        if (existingCron) {
            parseCronIntoUI(existingCron);
        }

        // Start readonly (builder mode)
        $('#cron-expression').prop('readonly', true);
    });

})(jQuery);

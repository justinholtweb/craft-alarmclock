/**
 * Control-panel behaviour for Alarm Clock.
 *
 * Everything posts through Craft.sendActionRequest rather than through a form, for one structural
 * reason: the draft-scheduling panel renders inside Craft's element-editor form, and a nested
 * <form> does not fail cleanly — the parser drops the tag and keeps the children, leaving a second
 * `action` input in the page form. Craft takes the last one, so Save would run this action.
 */
(function() {
    'use strict';

    function post(action, data, button) {
        var label = button ? button.textContent : null;

        if (button) {
            button.classList.add('disabled');
            button.setAttribute('disabled', 'disabled');
        }

        return Craft.sendActionRequest('POST', action, { data: data || {} })
            .then(function(response) {
                Craft.cp.displayNotice((response.data && response.data.message) || Craft.t('alarm-clock', 'Done.'));
                return response;
            })
            .catch(function(error) {
                var message = (error && error.response && error.response.data && error.response.data.message)
                    || Craft.t('alarm-clock', 'Something went wrong.');
                Craft.cp.displayError(message);
                throw error;
            })
            .finally(function() {
                if (button) {
                    button.classList.remove('disabled');
                    button.removeAttribute('disabled');
                    if (label !== null) button.textContent = label;
                }
            });
    }

    // ---- simple action buttons (retry, retry all, run now, cancel schedule)

    document.addEventListener('click', function(event) {
        var button = event.target.closest('[data-ac-action]');
        if (!button) return;

        event.preventDefault();

        var data = {};
        var id = button.getAttribute('data-ac-id');
        if (id) data.id = id;

        post(button.getAttribute('data-ac-action'), data, button).then(function() {
            // Reload so counts, statuses and the "next try" column are all consistent again.
            // Patching one row by hand would leave the four summary numbers above it wrong.
            window.location.reload();
        }).catch(function() {});
    });

    // ---- scheduling a draft from the entry sidebar

    document.addEventListener('click', function(event) {
        var button = event.target.closest('[data-ac-schedule]');
        if (!button) return;

        event.preventDefault();

        var panel = button.closest('[data-ac-sidebar]');
        var dateInput = panel.querySelector('#ac-publish-at-date');
        var timeInput = panel.querySelector('#ac-publish-at-time');
        var timezone = panel.querySelector('input[name="acPublishAt[timezone]"]');
        var enableAfter = panel.querySelector('#ac-enable-after');

        if (!dateInput || !dateInput.value) {
            Craft.cp.displayError(Craft.t('alarm-clock', 'Pick a date and time first.'));
            return;
        }

        // Craft's date/time field posts three separate inputs. Sending them as an object keeps
        // DateTimeHelper's own parsing — including the time zone — rather than reassembling a
        // string here and hoping the formats agree.
        post('alarm-clock/dashboard/schedule-draft', {
            draftId: button.getAttribute('data-draft-id'),
            siteId: button.getAttribute('data-site-id'),
            publishAt: {
                date: dateInput.value,
                time: timeInput ? timeInput.value : '',
                timezone: timezone ? timezone.value : ''
            },
            enableAfter: enableAfter && enableAfter.checked ? 1 : 0
        }, button).then(function() {
            window.location.reload();
        }).catch(function() {});
    });

    document.addEventListener('click', function(event) {
        var button = event.target.closest('[data-ac-cancel]');
        if (!button) return;

        event.preventDefault();

        post('alarm-clock/dashboard/cancel-schedule', { id: button.getAttribute('data-ac-cancel') }, button)
            .then(function() { window.location.reload(); })
            .catch(function() {});
    });
})();

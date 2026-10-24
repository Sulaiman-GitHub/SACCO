/*
 * Offline safety for forms. Nothing here saves, queues or replays anything.
 *  - Shows a banner while the browser is offline.
 *  - Disables the submit buttons of every form that changes data (anything that is not GET) while offline,
 *    and cancels a submit that still gets through (Enter key, scripts).
 *  - Locks a form after a valid submit so a double click cannot record a deposit or repayment twice.
 */
(function () {
    'use strict';

    var OFFLINE_BANNER = "You're offline. Changes can't be saved until you reconnect.";
    var OFFLINE_HINT = "You're offline, so this can't be saved until you reconnect.";
    var NOT_SAVED = 'Not saved. Check your connection and try again.';
    var BANNER_ID = 'offline-banner';

    var lockedForms = new WeakSet();

    function isOffline() {
        return navigator.onLine === false;
    }

    function isWriteForm(form) {
        var method = (form.method || 'get').toLowerCase();

        return method !== 'get' && method !== 'dialog';
    }

    function submitButtons(form) {
        return Array.prototype.filter.call(form.elements, function (el) {
            return el.type === 'submit' || el.type === 'image';
        });
    }

    /* ---- Toasts (also used by the update prompt in pwa.js) ---- */

    function toast(message, action) {
        var host = document.getElementById('sacco-toasts');

        if (!host) {
            host = document.createElement('div');
            host.id = 'sacco-toasts';
            host.className = 'sacco-toasts';
            host.setAttribute('role', 'status');
            host.setAttribute('aria-live', 'polite');
            document.body.appendChild(host);
        }

        var item = document.createElement('div');
        item.className = 'sacco-toast';

        var text = document.createElement('span');
        text.textContent = message;
        item.appendChild(text);

        function close() {
            if (item.parentNode) {
                item.parentNode.removeChild(item);
            }
        }

        if (action) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'sacco-toast-action';
            button.textContent = action.label;
            button.addEventListener('click', function () {
                action.onClick();
                close();
            });
            item.appendChild(button);
        }

        var dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'sacco-toast-close';
        dismiss.setAttribute('aria-label', 'Dismiss');
        dismiss.textContent = '×';
        dismiss.addEventListener('click', close);
        item.appendChild(dismiss);

        host.appendChild(item);

        if (!action) {
            setTimeout(close, 8000);
        }

        return item;
    }

    window.SaccoUI = window.SaccoUI || {};
    window.SaccoUI.toast = toast;
    window.SaccoUI.notSaved = function () { return toast(NOT_SAVED); };

    /* ---- Offline banner and disabled write forms ---- */

    function showBanner() {
        if (document.getElementById(BANNER_ID)) {
            return;
        }

        var banner = document.createElement('div');
        banner.id = BANNER_ID;
        banner.className = 'offline-banner';
        banner.setAttribute('role', 'status');
        banner.textContent = OFFLINE_BANNER;
        document.body.insertBefore(banner, document.body.firstChild);
    }

    function hideBanner() {
        var banner = document.getElementById(BANNER_ID);

        if (banner) {
            banner.parentNode.removeChild(banner);
        }
    }

    function disableForOffline(button) {
        if (button.disabled) {
            return;
        }

        button.dataset.offlineDisabled = '1';
        button.dataset.offlineTitle = button.getAttribute('title') || '';
        button.disabled = true;
        button.setAttribute('title', OFFLINE_HINT);
        button.setAttribute('aria-describedby', BANNER_ID);
    }

    function restoreAfterOffline(button) {
        if (button.dataset.offlineDisabled !== '1') {
            return;
        }

        button.disabled = false;
        button.removeAttribute('aria-describedby');

        if (button.dataset.offlineTitle) {
            button.setAttribute('title', button.dataset.offlineTitle);
        } else {
            button.removeAttribute('title');
        }

        delete button.dataset.offlineDisabled;
        delete button.dataset.offlineTitle;
    }

    function syncConnectionState() {
        var offline = isOffline();

        if (offline) {
            showBanner();
        } else {
            hideBanner();
        }

        Array.prototype.forEach.call(document.forms, function (form) {
            if (!isWriteForm(form) || lockedForms.has(form)) {
                return;
            }

            submitButtons(form).forEach(offline ? disableForOffline : restoreAfterOffline);
        });
    }

    /* ---- Double-submit protection ---- */

    // Shows the busy state. This runs on the next tick, never inside the submit event: disabling the clicked
    // button during the event makes the browser drop the submission (and its name/value) altogether.
    function showBusy(form, submitter) {
        if (!lockedForms.has(form)) {
            return;
        }

        submitButtons(form).forEach(function (button) {
            var busy = button === submitter || (!submitter && button === submitButtons(form)[0]);

            if (busy) {
                var label = button.dataset.busyLabel || 'Saving...';
                button.dataset.idleLabel = button.tagName === 'INPUT' ? button.value : button.innerHTML;
                button.style.minWidth = button.offsetWidth + 'px';

                if (button.tagName === 'INPUT') {
                    button.value = label;
                } else {
                    button.textContent = label;
                }
            }

            button.dataset.submitLocked = '1';
            button.disabled = true;
        });
    }

    function unlockForm(form) {
        if (!lockedForms.has(form)) {
            return;
        }

        lockedForms.delete(form);

        submitButtons(form).forEach(function (button) {
            if (button.dataset.submitLocked !== '1') {
                return;
            }

            if (button.dataset.idleLabel !== undefined) {
                if (button.tagName === 'INPUT') {
                    button.value = button.dataset.idleLabel;
                } else {
                    button.innerHTML = button.dataset.idleLabel;
                }

                delete button.dataset.idleLabel;
            }

            button.style.minWidth = '';
            button.disabled = false;
            delete button.dataset.submitLocked;
        });

        syncConnectionState();
    }

    // Capture phase: runs before page handlers, so an offline submit never starts.
    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement) || !isWriteForm(form)) {
            return;
        }

        if (isOffline()) {
            event.preventDefault();
            event.stopImmediatePropagation();
            toast(NOT_SAVED);
        }
    }, true);

    // Bubble phase: runs after inline handlers, so a cancelled confirm() does not lock the form.
    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement) || !isWriteForm(form) || event.defaultPrevented) {
            return;
        }

        if (lockedForms.has(form)) {
            event.preventDefault();

            return;
        }

        if (form.target && form.target !== '_self') {
            return;
        }

        // Locked at once so nothing else can submit this form; the buttons change on the next tick.
        lockedForms.add(form);

        var submitter = event.submitter || null;
        setTimeout(function () { showBusy(form, submitter); }, 0);
    });

    // A second click on a locked form's button is cancelled before it can raise another submit.
    document.addEventListener('click', function (event) {
        var button = event.target instanceof Element ? event.target.closest('button, input[type="submit"], input[type="image"]') : null;

        if (button && button.form && (button.type === 'submit' || button.type === 'image') && isWriteForm(button.form) && lockedForms.has(button.form)) {
            event.preventDefault();
        }
    }, true);

    // Coming back with the Back button (or after a failed navigation) restores the page with its values;
    // make the forms usable again.
    window.addEventListener('pageshow', function () {
        Array.prototype.forEach.call(document.forms, unlockForm);
        syncConnectionState();
    });

    window.addEventListener('online', syncConnectionState);
    window.addEventListener('offline', syncConnectionState);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', syncConnectionState);
    } else {
        syncConnectionState();
    }
})();

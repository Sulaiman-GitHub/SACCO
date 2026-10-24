/*
 * Install prompt, service worker registration and update prompt.
 * Registered only on HTTPS or localhost. Every storage and registration call is wrapped so a failure never breaks a page.
 */
(function () {
    'use strict';

    var DISMISS_KEY = 'sacco.install.dismissed';
    var isLocalHost = ['localhost', '127.0.0.1', '[::1]'].indexOf(location.hostname) !== -1;
    var canRegister = 'serviceWorker' in navigator && (location.protocol === 'https:' || isLocalHost);

    function remembered() {
        try {
            return window.localStorage.getItem(DISMISS_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function remember() {
        try {
            window.localStorage.setItem(DISMISS_KEY, '1');
        } catch (e) { /* private mode or blocked storage: the prompt simply shows again next time */ }
    }

    /* ---- Install button (Chrome, Edge, Android) and iOS hint ---- */

    function setUpInstall() {
        var wrap = document.querySelector('[data-install-wrap]');

        // Pages without the install block (the sign-in pages) get no install UI and no interference.
        if (!wrap) {
            return;
        }

        var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;

        if (standalone || remembered()) {
            return;
        }

        var button = wrap.querySelector('[data-install-button]');
        var iosHint = wrap.querySelector('[data-install-ios]');
        var deferredPrompt = null;

        function hide() {
            wrap.hidden = true;
        }

        var isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

        if (isIos && iosHint) {
            iosHint.hidden = false;
            wrap.hidden = false;
        }

        window.addEventListener('beforeinstallprompt', function (event) {
            event.preventDefault();
            deferredPrompt = event;

            if (button) {
                button.hidden = false;
                wrap.hidden = false;
            }
        });

        window.addEventListener('appinstalled', function () {
            deferredPrompt = null;
            hide();
        });

        if (button) {
            button.addEventListener('click', function () {
                if (!deferredPrompt) {
                    return;
                }

                var prompt = deferredPrompt;
                deferredPrompt = null;
                prompt.prompt();
                prompt.userChoice.then(function (choice) {
                    if (choice && choice.outcome === 'dismissed') {
                        remember();
                    }

                    hide();
                });
            });
        }

        wrap.querySelectorAll('[data-install-dismiss]').forEach(function (close) {
            close.addEventListener('click', function () {
                remember();
                hide();
            });
        });
    }

    /* ---- Service worker ---- */

    function promptForUpdate(worker) {
        if (!window.SaccoUI || !window.SaccoUI.toast) {
            return;
        }

        window.SaccoUI.toast('A new version is available.', {
            label: 'Refresh',
            onClick: function () { worker.postMessage({ type: 'SKIP_WAITING' }); },
        });
    }

    function registerWorker() {
        if (!canRegister) {
            return;
        }

        var refreshRequested = false;
        var reloading = false;

        try {
            navigator.serviceWorker.addEventListener('controllerchange', function () {
                // Only reload when the person accepted the refresh prompt, never mid-form on its own.
                if (reloading || !refreshRequested) {
                    return;
                }

                reloading = true;
                window.location.reload();
            });

            navigator.serviceWorker.register('/sw.js', { scope: '/' }).then(function (registration) {
                function offer(worker) {
                    if (!navigator.serviceWorker.controller) {
                        return; // first install: nothing to refresh
                    }

                    promptForUpdate({
                        postMessage: function (message) {
                            refreshRequested = true;
                            worker.postMessage(message);
                        },
                    });
                }

                if (registration.waiting) {
                    offer(registration.waiting);
                }

                registration.addEventListener('updatefound', function () {
                    var installing = registration.installing;

                    if (!installing) {
                        return;
                    }

                    installing.addEventListener('statechange', function () {
                        if (installing.state === 'installed') {
                            offer(installing);
                        }
                    });
                });

                document.addEventListener('visibilitychange', function () {
                    if (document.visibilityState === 'visible') {
                        registration.update().catch(function () {});
                    }
                });
            }).catch(function () { /* registration failed: the site keeps working without it */ });
        } catch (e) { /* unsupported or blocked: ignore */ }
    }

    // Signing out on a shared device: drop what the worker has stored for this browser.
    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement) || !/\/logout$/.test(form.getAttribute('action') || '')) {
            return;
        }

        try {
            if (navigator.serviceWorker && navigator.serviceWorker.controller) {
                navigator.serviceWorker.controller.postMessage({ type: 'CLEAR_RUNTIME_CACHES' });
            }
        } catch (e) { /* ignore */ }
    });

    setUpInstall();
    registerWorker();
})();

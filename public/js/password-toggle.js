/* Adds a show/hide eye button to every password field on the page. */
(function () {
    'use strict';

    function enhance(input) {
        if (input.dataset.toggleReady) { return; }
        input.dataset.toggleReady = '1';

        var group = document.createElement('div');
        group.className = 'input-group has-validation';
        input.parentNode.insertBefore(group, input);
        group.appendChild(input);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-outline-secondary';
        btn.setAttribute('aria-label', 'Show password');
        btn.setAttribute('aria-pressed', 'false');
        btn.innerHTML = '<i class="bi bi-eye" aria-hidden="true"></i>';
        group.appendChild(btn);

        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.firstElementChild.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
        });

        // Never leave a revealed password behind in the page cache.
        window.addEventListener('pagehide', function () { input.type = 'password'; });
    }

    function init() {
        document.querySelectorAll('input[type="password"]').forEach(enhance);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

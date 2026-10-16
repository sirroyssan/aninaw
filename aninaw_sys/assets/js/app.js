/**
 * ANINAW — general UI interactions:
 * themed SweetAlert2 helpers, flash messages, sidebar toggle, password visibility,
 * logout confirmation and the inactivity warning.
 */
(function () {
    'use strict';

    var body = document.body;
    var baseUrl = body.getAttribute('data-base-url') || '';
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');

    // ---------------------------------------------------------------------
    // SweetAlert2 helpers in the ANINAW theme
    // ---------------------------------------------------------------------
    var Alert = window.Swal ? window.Swal.mixin({
        buttonsStyling: false,
        customClass: {
            confirmButton: 'btn btn-primary',
            cancelButton: 'btn btn-outline-secondary',
            denyButton: 'btn btn-danger'
        },
        iconColor: '#198754',
        reverseButtons: true
    }) : null;

    var iconColors = { success: '#198754', error: '#DC3545', warning: '#D99A16', info: '#6B7770', question: '#6B7770' };

    var Aninaw = {
        url: function (path) {
            return baseUrl + '/' + String(path || '').replace(/^\/+/, '');
        },
        csrfToken: function () {
            return csrfMeta ? csrfMeta.getAttribute('content') : '';
        },
        alert: function (type, title, text) {
            if (!Alert) { window.alert(title + (text ? '\n\n' + text : '')); return Promise.resolve({}); }
            return Alert.fire({ icon: type, iconColor: iconColors[type], title: title, text: text || undefined });
        },
        toast: function (type, title, text) {
            if (!Alert) { return Promise.resolve({}); }
            return window.Swal.fire({
                toast: true, position: 'top-end', timer: 3200, timerProgressBar: true, showConfirmButton: false,
                icon: type, iconColor: iconColors[type], title: title, text: text || undefined
            });
        },
        confirm: function (options) {
            if (!Alert) { return Promise.resolve({ isConfirmed: window.confirm(options.title) }); }
            var danger = options.danger === true;
            return Alert.fire({
                icon: options.icon || (danger ? 'warning' : 'question'),
                iconColor: danger ? iconColors.error : iconColors.warning,
                title: options.title,
                text: options.text,
                showCancelButton: true,
                confirmButtonText: options.confirmText || 'Confirm',
                cancelButtonText: options.cancelText || 'Cancel',
                customClass: {
                    confirmButton: danger ? 'btn btn-danger' : 'btn btn-primary',
                    cancelButton: 'btn btn-outline-secondary'
                },
                focusCancel: danger
            });
        }
    };
    window.Aninaw = Aninaw;

    // ---------------------------------------------------------------------
    // Flash messages rendered by the server (data-flash on <body>)
    // ---------------------------------------------------------------------
    function showFlashes() {
        var messages = [];
        try { messages = JSON.parse(body.getAttribute('data-flash') || '[]'); } catch (e) { messages = []; }
        if (!Array.isArray(messages) || messages.length === 0) { return; }

        messages.reduce(function (chain, message) {
            return chain.then(function () {
                // Successes are brief toasts; problems need acknowledgement.
                return message.type === 'success'
                    ? Aninaw.toast('success', message.title, message.message)
                    : Aninaw.alert(message.type, message.title, message.message);
            });
        }, Promise.resolve());
    }

    // ---------------------------------------------------------------------
    // Sidebar (mobile)
    // ---------------------------------------------------------------------
    function setSidebar(open) {
        body.classList.toggle('sidebar-open', open);
        document.querySelectorAll('[data-sidebar-toggle]').forEach(function (button) {
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-sidebar-toggle]')) { setSidebar(!body.classList.contains('sidebar-open')); }
        if (event.target.closest('[data-sidebar-close]')) { setSidebar(false); }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && body.classList.contains('sidebar-open')) { setSidebar(false); }
    });

    // ---------------------------------------------------------------------
    // Password visibility toggle
    // ---------------------------------------------------------------------
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-password-toggle]');
        if (!button) { return; }
        var input = document.getElementById(button.getAttribute('data-password-toggle'));
        if (!input) { return; }
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-pressed', show ? 'true' : 'false');
        button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        var icon = button.querySelector('.bi');
        if (icon) { icon.className = 'bi ' + (show ? 'bi-eye-slash' : 'bi-eye'); }
    });

    // ---------------------------------------------------------------------
    // Logout (POST + CSRF via the hidden #logout-form)
    // ---------------------------------------------------------------------
    document.addEventListener('click', function (event) {
        if (!event.target.closest('[data-logout]')) { return; }
        event.preventDefault();
        var form = document.getElementById('logout-form');
        if (!form) { return; }
        Aninaw.confirm({
            title: 'Sign out?',
            text: 'You will need to sign in again to continue.',
            confirmText: 'Sign out',
            icon: 'question'
        }).then(function (result) {
            if (result.isConfirmed) { form.submit(); }
        });
    });

    // ---------------------------------------------------------------------
    // Inactivity warning. The server enforces the timeout; this only warns.
    // ---------------------------------------------------------------------
    function startIdleWatch() {
        var timeout = parseInt(body.getAttribute('data-idle-timeout') || '0', 10);
        if (!timeout || !document.getElementById('logout-form')) { return; }

        var warnBefore = Math.min(120, Math.floor(timeout / 4));
        var warnTimer, expireTimer;

        function schedule() {
            clearTimeout(warnTimer);
            clearTimeout(expireTimer);
            warnTimer = setTimeout(warn, (timeout - warnBefore) * 1000);
            expireTimer = setTimeout(function () {
                window.location.href = Aninaw.url('login.php?expired=1');
            }, (timeout + 2) * 1000);
        }

        function warn() {
            Aninaw.confirm({
                title: 'Still there?',
                text: 'For your security you will be signed out soon because of inactivity.',
                confirmText: 'Stay signed in',
                cancelText: 'Sign out',
                icon: 'warning'
            }).then(function (result) {
                if (result.isConfirmed) {
                    fetch(Aninaw.url('session_ping.php'), {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'X-CSRF-Token': Aninaw.csrfToken(), 'X-Requested-With': 'XMLHttpRequest' }
                    }).then(function (response) {
                        if (response.ok) { schedule(); Aninaw.toast('success', 'Session extended'); }
                        else { window.location.href = Aninaw.url('login.php?expired=1'); }
                    }).catch(function () {
                        Aninaw.alert('error', 'Connection problem', 'We could not reach the server. Please check your connection.');
                    });
                } else if (result.dismiss === 'cancel') {
                    document.getElementById('logout-form').submit();
                }
            });
        }

        schedule();
    }

    showFlashes();
    startIdleWatch();
})();

/**
 * ANINAW — form validation.
 * Inline required-field checks, plus AJAX submission of the sign-in form with
 * loading feedback and SweetAlert2 results. The server validates everything again.
 */
(function () {
    'use strict';

    /** Marks empty required fields as invalid. Returns true when the form is valid. */
    function validateRequired(form) {
        var firstInvalid = null;
        form.querySelectorAll('[required]').forEach(function (field) {
            var valid = field.value.trim() !== '';
            field.classList.toggle('is-invalid', !valid);
            field.setAttribute('aria-invalid', valid ? 'false' : 'true');
            if (!valid && !firstInvalid) { firstInvalid = field; }
        });
        if (firstInvalid) { firstInvalid.focus(); }
        return firstInvalid === null;
    }

    function clearOnInput(form) {
        form.addEventListener('input', function (event) {
            if (event.target.matches('.is-invalid') && event.target.value.trim() !== '') {
                event.target.classList.remove('is-invalid');
                event.target.setAttribute('aria-invalid', 'false');
            }
        });
    }

    window.AninawValidation = { validateRequired: validateRequired };

    // ---------------------------------------------------------------------
    // Sign-in form
    // ---------------------------------------------------------------------
    var form = document.getElementById('login-form');
    if (!form) { return; }

    var submit = document.getElementById('login-submit');
    var password = document.getElementById('password');
    clearOnInput(form);

    function setLoading(loading) {
        submit.disabled = loading;
        submit.querySelector('.btn-label').classList.toggle('d-none', loading);
        submit.querySelector('.btn-loading').classList.toggle('d-none', !loading);
    }

    function showFieldErrors(errors) {
        Object.keys(errors || {}).forEach(function (name) {
            var field = form.querySelector('[name="' + name + '"]');
            if (!field) { return; }
            field.classList.add('is-invalid');
            var feedback = document.getElementById(name + '-feedback');
            if (feedback) { feedback.textContent = errors[name]; }
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!validateRequired(form)) { return; }
        if (!window.fetch || !window.Aninaw) { form.submit(); return; }

        setLoading(true);

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json().catch(function () { return { ok: false, message: 'Unexpected server response.' }; })
                .then(function (data) { return { status: response.status, data: data }; });
        }).then(function (result) {
            var data = result.data;

            if (data.ok) {
                window.Aninaw.toast('success', data.message || 'Signed in');
                setTimeout(function () { window.location.href = data.redirect; }, 700);
                return;
            }

            setLoading(false);
            password.value = '';

            if (data.reason === 'validation') { showFieldErrors(data.errors); return; }
            if (data.reason === 'csrf') {
                window.Aninaw.alert('warning', 'Page expired', data.message).then(function () { window.location.reload(); });
                return;
            }

            var type = data.reason === 'invalid' ? 'error' : 'warning';
            var title = data.reason === 'throttled' ? 'Too many attempts'
                : data.reason === 'invalid' ? 'Sign-in failed'
                : data.reason === 'inactive' ? 'Account not active'
                : data.reason === 'no_access' ? 'No institution access'
                : 'Unable to sign in';
            window.Aninaw.alert(type, title, data.message || 'Please try again.').then(function () {
                password.focus();
            });
        }).catch(function () {
            setLoading(false);
            window.Aninaw.alert('error', 'Connection problem', 'We could not reach the server. Please check your connection and try again.');
        });
    });
})();

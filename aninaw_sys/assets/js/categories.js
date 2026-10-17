/**
 * ANINAW — "New" beside a Category list (data-add-category="#select"): asks for the name,
 * adds it to the institution's expense & budget categories in the background, then adds it
 * to the list and selects it. Works inside pop-ups too.
 */
(function () {
    'use strict';
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-add-category]');
        if (!button || !window.Swal) { return; }
        var form = button.closest('form');
        var select = form && form.querySelector(button.getAttribute('data-add-category'));
        if (!select) { return; }
        window.Swal.fire({
            target: button.closest('.modal') || document.body,
            title: 'New category',
            input: 'text',
            inputLabel: 'Used for expenses and budget lines across the institution',
            inputPlaceholder: 'e.g. Registration',
            inputAttributes: { maxlength: '100', 'aria-label': 'Category name' },
            showCancelButton: true,
            confirmButtonText: 'Add Category',
            customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-outline-secondary' },
            buttonsStyling: false,
            inputValidator: function (value) { return (!value || value.trim().length < 2) ? 'Enter a category name (at least 2 characters).' : undefined; },
            preConfirm: function (value) {
                var data = new FormData();
                data.append('_csrf', form.querySelector('input[name="_csrf"]').value);
                data.append('name', value.trim());
                return fetch(button.getAttribute('data-url'), { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                    .then(function (response) { return response.json(); })
                    .then(function (result) {
                        if (!result.ok) { window.Swal.showValidationMessage(result.message); return false; }
                        return result;
                    })
                    .catch(function () { window.Swal.showValidationMessage('Could not save. Please try again.'); return false; });
            }
        }).then(function (outcome) {
            if (!outcome.isConfirmed || !outcome.value) { return; }
            var name = outcome.value.name;
            var exists = Array.prototype.some.call(select.options, function (option) { return option.value === name; });
            if (!exists) { select.add(new Option(name, name)); }
            select.value = name;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            if (window.Aninaw) { window.Aninaw.toast('success', 'Category added', outcome.value.message); }
        });
    });
})();

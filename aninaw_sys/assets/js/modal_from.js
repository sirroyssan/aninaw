/**
 * ANINAW — links with data-modal-from="modal-id" open that modal from the page they point to,
 * without leaving the current page (e.g. Edit on a collection rule). The page is fetched, the
 * modal is taken from it and shown here. Modals marked data-open-on-load open by themselves
 * (a link opened directly, or a form that came back with an error). Ctrl/Cmd-click or no
 * JavaScript simply opens the page.
 *
 * Inside the Edit Rule pop-up, "Change amount" (data-change-amount) asks for the correct amount
 * and the reason in a small dialog, saves it in the background and refreshes the pop-up in place;
 * the page behind reloads when the pop-up closes.
 */
(function () {
    'use strict';
    if (!window.bootstrap) { return; }
    var changed = false;

    function show(modalEl, source) {
        if (source) { modalEl.setAttribute('data-source', source); }
        if (window.enhanceSearchableSelects) { window.enhanceSearchableSelects(modalEl); }
        modalEl.addEventListener('hidden.bs.modal', function () {
            if (changed) { window.location.reload(); return; }
            // Opened from a ?rule=… address: drop it so a refresh does not reopen the pop-up.
            if (window.history.replaceState && new URL(window.location.href).searchParams.has('rule')) {
                var url = new URL(window.location.href);
                url.searchParams.delete('rule');
                window.history.replaceState(null, '', url.toString());
            }
        });
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    function fetchModal(href, id) {
        return fetch(href, { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok || response.redirected) { throw new Error('load'); }
                return response.text();
            })
            .then(function (html) {
                var incoming = new DOMParser().parseFromString(html, 'text/html').getElementById(id);
                if (!incoming) { throw new Error('missing'); }
                incoming.removeAttribute('data-open-on-load');
                return incoming;
            });
    }

    document.querySelectorAll('.modal[data-open-on-load]#rule-modal, .modal[data-open-on-load]#new-rule-modal').forEach(function (modalEl) {
        show(modalEl, window.location.href);
    });

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[data-modal-from]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) { return; }
        event.preventDefault();
        var id = link.getAttribute('data-modal-from');
        fetchModal(link.href, id)
            .then(function (incoming) {
                var existing = document.getElementById(id);
                if (existing) {
                    var instance = window.bootstrap.Modal.getInstance(existing);
                    if (instance) { instance.dispose(); }
                    existing.remove();
                }
                document.body.appendChild(incoming);
                show(incoming, link.href);
            })
            .catch(function () { window.location.href = link.href; });
    });

    // "Change amount": a small dialog on top of the Edit pop-up, then the pop-up refreshes in place.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-change-amount]');
        if (!button || !window.Swal) { return; }
        var modalEl = button.closest('.modal');
        var form = button.closest('form');
        var esc = function (text) { var div = document.createElement('div'); div.textContent = text; return div.innerHTML; };
        window.Swal.fire({
            target: modalEl || document.body,
            title: 'Change amount',
            html: '<p class="text-muted-sm mb-3">' + esc(button.getAttribute('data-rule-name')) + ' is now <strong>' + esc(button.getAttribute('data-current-label')) + '</strong>. '
                + 'Every charge changes to the new amount; anyone who already paid more is marked Overpaid, to refund or apply to their other charges.</p>'
                + '<label class="form-label w-100 text-start" for="change-amount-new">Correct amount</label>'
                + '<input class="form-control text-amount mb-3" id="change-amount-new" inputmode="decimal" placeholder="Lower than ' + esc(button.getAttribute('data-current')) + '">'
                + '<label class="form-label w-100 text-start" for="change-amount-reason">Reason</label>'
                + '<textarea class="form-control" id="change-amount-reason" rows="2" maxlength="1000" placeholder="e.g. Registration is ₱1,500; ₱1,750 was entered by mistake"></textarea>',
            showCancelButton: true,
            confirmButtonText: 'Save Amount',
            customClass: { confirmButton: 'btn btn-warning', cancelButton: 'btn btn-outline-secondary' },
            buttonsStyling: false,
            focusConfirm: false,
            didOpen: function () { document.getElementById('change-amount-new').focus(); },
            preConfirm: function () {
                var amount = document.getElementById('change-amount-new').value.trim();
                var reason = document.getElementById('change-amount-reason').value.trim();
                if (!amount) { window.Swal.showValidationMessage('Enter the correct amount.'); return false; }
                if (reason.length < 5) { window.Swal.showValidationMessage('Give the reason (at least 5 characters).'); return false; }
                var data = new FormData();
                data.append('_csrf', form.querySelector('input[name="_csrf"]').value);
                data.append('action', 'correct_amount');
                data.append('tab', 'rules');
                data.append('rule_id', button.getAttribute('data-rule-id'));
                data.append('amount', amount);
                data.append('reason', reason);
                return fetch(form.getAttribute('action') || window.location.href, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
                    .then(function (response) { return response.json(); })
                    .then(function (result) {
                        if (!result.ok) { window.Swal.showValidationMessage(result.message); return false; }
                        return result;
                    })
                    .catch(function () { window.Swal.showValidationMessage('Could not save. Please try again.'); return false; });
            }
        }).then(function (outcome) {
            if (!outcome.isConfirmed || !outcome.value) { return; }
            changed = true;
            if (window.Aninaw) { window.Aninaw.toast('success', 'Amount changed', outcome.value.message); }
            var source = modalEl && modalEl.getAttribute('data-source');
            if (!modalEl || !source) { window.location.reload(); return; }
            // Stay in the Edit pop-up: reload its content with the new amount.
            fetchModal(source, modalEl.id).then(function (incoming) {
                modalEl.querySelector('.modal-content').innerHTML = incoming.querySelector('.modal-content').innerHTML;
                if (window.enhanceSearchableSelects) { window.enhanceSearchableSelects(modalEl); }
                if (outcome.value.overpaid) {
                    var note = document.createElement('div');
                    note.className = 'notice notice-warning m-3 mb-0';
                    note.innerHTML = '<i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i><div class="flex-grow-1">' + esc(outcome.value.message) + '</div><a class="btn btn-warning btn-sm" href="' + esc(outcome.value.settle_url) + '">Settle Overpayments</a>';
                    modalEl.querySelector('.modal-body').prepend(note);
                }
            }).catch(function () { window.location.reload(); });
        });
    });
})();

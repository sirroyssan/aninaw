/**
 * ANINAW — Collections list: "Pay" opens the payment form for that person and event in a
 * modal instead of leaving the list. Waive inside it is saved in the background and the modal
 * reloads; the list refreshes when the modal closes. Without JavaScript the link opens the full page.
 */
(function () {
    'use strict';

    var modalEl = document.getElementById('pay-modal');
    if (!modalEl || !window.bootstrap) { return; }
    var modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
    var title = modalEl.querySelector('[data-pay-title]');
    var body = modalEl.querySelector('[data-pay-body]');
    var full = modalEl.querySelector('[data-pay-full]');

    var current = null;
    var changed = false;

    function load(href) {
        current = href;
        return fetch(href + '&fragment=1', { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
            .then(function (response) {
                if (!response.ok || response.redirected) { throw new Error('load'); }
                return response.text();
            })
            .then(function (html) {
                body.innerHTML = html;
                if (window.AninawFinance) { window.AninawFinance.refreshSums(body); }
                var first = body.querySelector('input.text-amount');
                if (first) { first.focus(); }
            });
    }

    // Waive inside the pop-up: saved in the background, then the pop-up reloads with the new balances.
    body.addEventListener('aninaw:confirmed', function (event) {
        var form = event.target;
        fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (!result.ok) {
                    if (window.Aninaw) { window.Aninaw.alert('error', 'Not saved', result.message); }
                    return;
                }
                changed = true;
                if (window.Aninaw) { window.Aninaw.toast('success', 'Balance waived', result.message); }
                return load(current);
            })
            .catch(function () { if (window.Aninaw) { window.Aninaw.toast('error', 'Not saved', 'Please try again.'); } });
    });

    // The list behind still shows the old balances: refresh it when the pop-up closes after a waive.
    modalEl.addEventListener('hidden.bs.modal', function () {
        if (changed) { window.location.reload(); }
    });

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[data-pay]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey) { return; }
        event.preventDefault();
        title.textContent = link.getAttribute('data-pay');
        full.href = link.href;
        body.innerHTML = '<div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Loading…</div>';
        modal.show();
        load(link.href).catch(function () { window.location.href = link.href; });
    });
})();

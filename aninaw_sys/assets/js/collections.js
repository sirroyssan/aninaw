/**
 * ANINAW — Collections list: "Pay" opens the payment form for that person and event in a
 * modal instead of leaving the list. Without JavaScript the link opens the full page.
 */
(function () {
    'use strict';

    var modalEl = document.getElementById('pay-modal');
    if (!modalEl || !window.bootstrap) { return; }
    var modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
    var title = modalEl.querySelector('[data-pay-title]');
    var body = modalEl.querySelector('[data-pay-body]');
    var full = modalEl.querySelector('[data-pay-full]');

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[data-pay]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey) { return; }
        event.preventDefault();
        title.textContent = link.getAttribute('data-pay');
        full.href = link.href;
        body.innerHTML = '<div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Loading…</div>';
        modal.show();
        fetch(link.href + '&fragment=1', { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
            .then(function (response) {
                if (!response.ok || response.redirected) { throw new Error('load'); }
                return response.text();
            })
            .then(function (html) {
                body.innerHTML = html;
                if (window.AninawFinance) { window.AninawFinance.refreshSums(body); }
                var first = body.querySelector('input.text-amount');
                if (first) { first.focus(); }
            })
            .catch(function () { window.location.href = link.href; });
    });
})();

/**
 * ANINAW — clicking a person's name ([data-person-card] links) opens their card in a modal
 * (participants/card.php: details, charges in this event, "Edit details") instead of leaving the page.
 * Ctrl/Cmd-click or no JavaScript opens the full profile page as before.
 */
(function () {
    'use strict';

    var modalEl = document.getElementById('person-modal');
    if (!modalEl || !window.bootstrap) { return; }
    var modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
    var title = modalEl.querySelector('[data-person-title]');
    var body = modalEl.querySelector('[data-person-body]');
    var full = modalEl.querySelector('[data-person-full]');

    function open(cardUrl, profileUrl, name) {
        title.textContent = name || 'Person';
        full.href = profileUrl;
        body.innerHTML = '<div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Loading…</div>';
        modal.show();
        fetch(cardUrl, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
            .then(function (response) {
                if (!response.ok || response.redirected) { throw new Error('load'); }
                return response.text();
            })
            .then(function (html) {
                body.innerHTML = html;
                var card = body.querySelector('[data-person-card]');
                if (card && !name) { title.textContent = card.getAttribute('data-person-name'); }
                var editing = body.querySelector('details[open] input:not([type="hidden"])');
                if (editing) { editing.focus(); }
            })
            .catch(function () { window.location.href = profileUrl; });
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[data-person-card]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) { return; }
        event.preventDefault();
        open(link.getAttribute('data-person-card'), link.href, link.textContent.trim());
    });

    // "Edit details" came back with an error: reopen the card with what was typed.
    var reopen = modalEl.getAttribute('data-reopen-card');
    if (reopen) { open(reopen, modalEl.getAttribute('data-reopen-profile'), ''); }
})();

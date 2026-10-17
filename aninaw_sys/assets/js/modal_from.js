/**
 * ANINAW — links with data-modal-from="modal-id" open that modal from the page they point to,
 * without leaving the current page (e.g. Edit on a collection rule). The page is fetched, the
 * modal is taken from it and shown here. Modals marked data-open-on-load open by themselves
 * (a link opened directly, or a form that came back with an error). Ctrl/Cmd-click or no
 * JavaScript simply opens the page.
 */
(function () {
    'use strict';
    if (!window.bootstrap) { return; }

    function show(modalEl) {
        if (window.enhanceSearchableSelects) { window.enhanceSearchableSelects(modalEl); }
        modalEl.addEventListener('hidden.bs.modal', function () {
            // Opened from a ?rule=… address: drop it so a refresh does not reopen the pop-up.
            if (modalEl.hasAttribute('data-open-on-load') && window.history.replaceState) {
                var url = new URL(window.location.href);
                url.searchParams.delete('rule');
                window.history.replaceState(null, '', url.toString());
            }
        }, { once: true });
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    document.querySelectorAll('.modal[data-open-on-load][id="rule-modal"]').forEach(show);

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[data-modal-from]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) { return; }
        event.preventDefault();
        var id = link.getAttribute('data-modal-from');
        fetch(link.href, { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok || response.redirected) { throw new Error('load'); }
                return response.text();
            })
            .then(function (html) {
                var incoming = new DOMParser().parseFromString(html, 'text/html').getElementById(id);
                if (!incoming) { throw new Error('missing'); }
                incoming.removeAttribute('data-open-on-load');
                var existing = document.getElementById(id);
                if (existing) {
                    var instance = window.bootstrap.Modal.getInstance(existing);
                    if (instance) { instance.dispose(); }
                    existing.remove();
                }
                document.body.appendChild(incoming);
                show(incoming);
            })
            .catch(function () { window.location.href = link.href; });
    });
})();

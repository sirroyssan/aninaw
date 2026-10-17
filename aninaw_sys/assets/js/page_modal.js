/**
 * ANINAW — links with data-page-modal open another page's form or list in a pop-up instead of
 * leaving the current page (e.g. Record Expense, New Budget, My Liquidations). The page is fetched
 * and its [data-modal-body] element is shown in the pop-up; its scripts are loaded once.
 * Paging links inside the pop-up reload it in place.
 *
 * Forms marked data-modal-ajax (all forms taken from a page, and pop-up forms such as Record
 * Deposit) are sent in the background: on success the browser goes where the server says
 * (usually the new record); a mistake keeps the pop-up open with everything typed, shows the
 * message there and focuses the field it is about. Ctrl/Cmd-click or no JavaScript opens the page.
 */
(function () {
    'use strict';
    if (!window.bootstrap) { return; }

    var shell = null;
    var loaded = {};

    function getShell() {
        if (shell) { return shell; }
        shell = document.createElement('div');
        shell.className = 'modal fade';
        shell.id = 'page-modal';
        shell.tabIndex = -1;
        shell.setAttribute('aria-labelledby', 'page-modal-title');
        shell.setAttribute('aria-hidden', 'true');
        shell.innerHTML = '<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">'
            + '<div class="modal-header"><h2 class="modal-title h5" id="page-modal-title"></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
            + '<div class="modal-body p-0" data-page-modal-body></div></div></div>';
        document.body.appendChild(shell);
        return shell;
    }

    function scriptKey(src) { return src.split('?')[0]; }
    document.querySelectorAll('script[src]').forEach(function (script) { loaded[scriptKey(script.src)] = true; });

    function loadScripts(doc) {
        return Array.prototype.reduce.call(doc.querySelectorAll('script[src]'), function (chain, script) {
            var src = script.src;
            if (loaded[scriptKey(src)]) { return chain; }
            loaded[scriptKey(src)] = true;
            return chain.then(function () {
                return new Promise(function (resolve) {
                    var tag = document.createElement('script');
                    tag.src = src;
                    tag.onload = tag.onerror = resolve;
                    document.body.appendChild(tag);
                });
            });
        }, Promise.resolve());
    }

    // A page's form card becomes the pop-up body: no card frame, Cancel closes the pop-up.
    function adapt(content) {
        content.classList.remove('form-card');
        content.classList.add('border-0', 'rounded-0', 'shadow-none', 'mb-0');
        content.style.maxWidth = '';
        content.removeAttribute('data-modal-body');
        var forms = content.matches('form') ? [content] : Array.prototype.slice.call(content.querySelectorAll('form'));
        forms.forEach(function (form) {
            if ((form.getAttribute('method') || '').toLowerCase() === 'post') {
                form.setAttribute('data-ajax', '');
                form.setAttribute('data-modal-ajax', '');
            }
        });
        content.querySelectorAll('.form-actions a.btn-outline-secondary').forEach(function (cancel) {
            cancel.setAttribute('data-bs-dismiss', 'modal');
            cancel.addEventListener('click', function (event) { event.preventDefault(); });
        });
    }

    function fill(href, title, size) {
        return fetch(href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
            .then(function (response) {
                if (!response.ok || response.redirected) { throw new Error('load'); }
                return response.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var content = doc.querySelector('[data-modal-body]');
                if (!content) { throw new Error('missing'); }
                // Forms without an action post to the page they came from.
                (content.matches('form') ? [content] : Array.prototype.slice.call(content.querySelectorAll('form'))).forEach(function (form) {
                    if (!form.getAttribute('action')) { form.setAttribute('action', href); }
                });
                var modalEl = getShell();
                modalEl.querySelector('.modal-dialog').className = 'modal-dialog modal-dialog-centered modal-dialog-scrollable modal-' + (size || 'lg');
                modalEl.querySelector('#page-modal-title').textContent = title;
                var body = modalEl.querySelector('[data-page-modal-body]');
                body.innerHTML = '';
                body.appendChild(document.importNode(content, true));
                adapt(body.firstElementChild);
                modalEl.setAttribute('data-source', href);
                return loadScripts(doc).then(function () {
                    if (window.AninawFinance) { window.AninawFinance.refreshSums(body); window.AninawFinance.syncMethods(body); }
                    if (window.enhanceSearchableSelects) { window.enhanceSearchableSelects(modalEl); }
                    return modalEl;
                });
            });
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[data-page-modal]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) { return; }
        event.preventDefault();
        var title = link.getAttribute('data-modal-title') || link.textContent.trim();
        // Reopened: keep what was typed (and the page scripts already set up on it).
        if (shell && shell.getAttribute('data-source') === link.href && shell.querySelector('[data-page-modal-body]').children.length) {
            window.bootstrap.Modal.getOrCreateInstance(shell).show();
            return;
        }
        fill(link.href, title, link.getAttribute('data-page-modal'))
            .then(function (modalEl) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
                var first = modalEl.querySelector('[data-page-modal-body] input:not([type=hidden]), [data-page-modal-body] select, [data-page-modal-body] textarea');
                modalEl.addEventListener('shown.bs.modal', function () { if (first) { first.focus(); } }, { once: true });
            })
            .catch(function () { window.location.href = link.href; });
    });

    // Paging inside a list pop-up: reload the pop-up in place.
    document.addEventListener('click', function (event) {
        var link = event.target.closest('#page-modal .pagination a[href]');
        if (!link) { return; }
        event.preventDefault();
        fill(link.href, shell.querySelector('#page-modal-title').textContent, null).catch(function () { window.location.href = link.href; });
    });

    function showError(form, result) {
        var modalEl = form.closest('.modal');
        var field = result.field ? form.querySelector('[name="' + result.field + '"]') : null;
        var shown = window.Swal
            ? window.Swal.fire({ target: modalEl || document.body, icon: 'warning', title: 'Not saved', text: result.message, confirmButtonText: 'OK', customClass: { confirmButton: 'btn btn-primary' }, buttonsStyling: false, returnFocus: false })
            : Promise.resolve(window.alert(result.message));
        shown.then(function () {
            if (!field) { return; }
            field.classList.add('is-invalid');
            field.addEventListener('input', function () { field.classList.remove('is-invalid'); }, { once: true });
            field.scrollIntoView({ block: 'center' });
            field.focus();
        });
    }

    document.addEventListener('aninaw:confirmed', function (event) {
        var form = event.target;
        if (!form.matches || !form.matches('form[data-modal-ajax]')) { return; }
        var data = new FormData(form);
        var submitter = event.detail && event.detail.submitter;
        if (submitter && submitter.name) { data.append(submitter.name, submitter.value); }
        var buttons = form.querySelectorAll('button[type="submit"]');
        buttons.forEach(function (button) { button.disabled = true; });
        fetch(form.getAttribute('action') || window.location.href, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (response) {
                var type = response.headers.get('Content-Type') || '';
                if (type.indexOf('application/json') === -1) {
                    // The page answered with a redirect instead: follow it.
                    window.location.href = response.url;
                    return null;
                }
                return response.json();
            })
            .then(function (result) {
                if (!result) { return; }
                if (result.ok) { window.location.href = result.redirect || window.location.href; return; }
                buttons.forEach(function (button) { button.disabled = false; });
                showError(form, result);
            })
            .catch(function () {
                buttons.forEach(function (button) { button.disabled = false; });
                if (window.Aninaw) { window.Aninaw.toast('error', 'Not saved', 'Please try again.'); }
            });
    });
})();

/**
 * ANINAW — helpers for financial forms.
 *   data-money-sum="#target"  sums the amount inputs inside the element into #target
 *   data-fill-max             button that fills the sibling input with its data-max value
 *   data-fill-all             button that fills every data-max input in its form
 *   data-checked-sum="#t"     sums data-amount of ticked boxes into #t (or counts them if #t has data-count)
 *   data-row-editor           add/remove repeatable rows from a <template>
 *   data-journal-lines        adjusting-entry lines (cash-account select, debit/credit totals)
 *   data-order-form           order lines, subtotal, discount and total due
 * Totals here are a convenience; the server recalculates and validates every amount.
 */
(function () {
    'use strict';

    // Parse "1,250.50" into centavos without floating-point drift.
    function toCents(value) {
        var clean = String(value || '').replace(/[,\s₱]/g, '');
        if (!/^\d+(\.\d{0,2})?$/.test(clean)) { return 0; }
        var parts = clean.split('.');
        return parseInt(parts[0], 10) * 100 + parseInt(((parts[1] || '') + '00').slice(0, 2), 10);
    }

    function formatCents(cents) {
        var whole = Math.floor(cents / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return whole + '.' + ('0' + (cents % 100)).slice(-2);
    }

    function refresh(container) {
        var target = document.querySelector(container.getAttribute('data-money-sum'));
        if (!target) { return; }
        var total = 0;
        container.querySelectorAll('input.text-amount').forEach(function (input) {
            var cents = toCents(input.value);
            var max = input.hasAttribute('data-max') ? toCents(input.getAttribute('data-max')) : null;
            input.classList.toggle('is-invalid', max !== null && cents > max);
            total += cents;
        });
        target.textContent = (target.getAttribute('data-currency') || '') + formatCents(total);
    }

    // Delegated so forms loaded later (the Collections payment modal) work too.
    document.addEventListener('input', function (event) {
        var container = event.target.closest && event.target.closest('[data-money-sum]');
        if (container) { refresh(container); }
    });
    function refreshAll(root) {
        (root || document).querySelectorAll('[data-money-sum]').forEach(refresh);
    }
    refreshAll();
    window.AninawFinance = { refreshSums: refreshAll };

    document.addEventListener('click', function (event) {
        var all = event.target.closest('[data-fill-all]');
        if (all && all.form) {
            all.form.querySelectorAll('input[data-max]').forEach(function (input) { input.value = input.getAttribute('data-max'); });
            refreshAll(all.form);
            return;
        }
        var button = event.target.closest('[data-fill-max]');
        if (!button) { return; }
        var input = button.parentElement.querySelector('input[data-max]');
        if (!input) { return; }
        input.value = input.getAttribute('data-max');
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });

    // data-checked-sum="#target": sums data-amount of checked boxes; data-check-all toggles them.
    function refreshChecked(container) {
        var target = document.querySelector(container.getAttribute('data-checked-sum'));
        if (!target) { return; }
        var total = 0;
        var boxes = container.querySelectorAll('input[type="checkbox"][data-amount]:checked');
        if (target.hasAttribute('data-count')) { target.textContent = String(boxes.length); return; } // count ticked rows instead of summing
        boxes.forEach(function (box) { total += toCents(box.getAttribute('data-amount')); });
        target.textContent = (target.getAttribute('data-currency') || '') + formatCents(total);
    }
    document.querySelectorAll('[data-checked-sum]').forEach(function (container) {
        container.addEventListener('change', function (event) {
            if (event.target.matches('[data-check-all]')) {
                container.querySelectorAll('input[type="checkbox"][data-amount]').forEach(function (box) { box.checked = event.target.checked; });
            }
            refreshChecked(container);
        });
        refreshChecked(container);
    });

    // data-row-editor: repeatable rows. Rows live in [data-row-body]; new rows come from
    // <template data-row-template> with __INDEX__ replaced; [data-row-add] / [data-row-remove]
    // buttons; data-min-rows (default 1) rows are always kept. Fires "row:added" on new rows.
    document.querySelectorAll('[data-row-editor]').forEach(function (editor) {
        var body = editor.querySelector('[data-row-body]');
        var template = editor.querySelector('[data-row-template]');
        var minRows = parseInt(editor.getAttribute('data-min-rows') || '1', 10);
        var next = body.children.length;
        editor.addEventListener('click', function (event) {
            if (event.target.closest('[data-row-add]')) {
                body.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__INDEX__/g, String(next++)));
                body.lastElementChild.dispatchEvent(new CustomEvent('row:added', { bubbles: true }));
                return;
            }
            var remove = event.target.closest('[data-row-remove]');
            if (remove && body.children.length > minRows) {
                remove.closest('[data-row-body] > *').remove();
                editor.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
    });

    // data-journal-lines: adjusting-entry editor. Add/remove rows, enable the cash-account
    // select only for cash ledger accounts (filtered to matching account types), live totals.
    var CASH_KEYS = ['cash_custody', 'bank', 'e_wallet', 'other'];
    document.querySelectorAll('[data-journal-lines]').forEach(function (form) {
        var body = form.querySelector('[data-row-body]');
        var prefix = form.getAttribute('data-currency') || '';

        function syncCash(row) {
            var key = row.querySelector('[data-line-account]').value;
            var cash = row.querySelector('[data-line-cash]');
            var isCash = CASH_KEYS.indexOf(key) !== -1;
            cash.disabled = !isCash;
            cash.required = isCash;
            Array.prototype.forEach.call(cash.options, function (option) {
                option.hidden = option.value !== '' && isCash && option.getAttribute('data-key') !== key;
            });
            if (!isCash || (cash.selectedOptions[0] && cash.selectedOptions[0].hidden)) { cash.value = ''; }
        }

        function totals() {
            var debit = 0, credit = 0;
            body.querySelectorAll('[data-line]').forEach(function (row) {
                var d = row.querySelector('[data-line-debit]'), c = row.querySelector('[data-line-credit]');
                debit += toCents(d.value);
                credit += toCents(c.value);
                var both = toCents(d.value) > 0 && toCents(c.value) > 0;
                d.classList.toggle('is-invalid', both);
                c.classList.toggle('is-invalid', both);
            });
            form.querySelector('[data-total-debit]').textContent = prefix + formatCents(debit);
            form.querySelector('[data-total-credit]').textContent = prefix + formatCents(credit);
            var status = form.querySelector('[data-total-status]');
            var diff = debit - credit;
            status.textContent = diff === 0 ? (debit ? 'Balanced' : '') : 'Out of balance by ' + prefix + formatCents(Math.abs(diff));
            status.classList.toggle('text-danger', diff !== 0);
            status.classList.toggle('text-success', diff === 0 && debit > 0);
        }

        body.querySelectorAll('[data-line]').forEach(syncCash);
        form.addEventListener('change', function (event) {
            if (event.target.matches('[data-line-account]')) { syncCash(event.target.closest('[data-line]')); }
        });
        form.addEventListener('input', totals);
        form.addEventListener('row:added', function (event) { syncCash(event.target); });
        totals();
    });

    // data-order-form: order lines (item price × quantity, or pages × copies for per-page
    // services, + extra charge), subtotal, discount and total due. The server recomputes all of it.
    document.querySelectorAll('[data-order-form]').forEach(function (form) {
        var prefix = form.getAttribute('data-currency') || '';
        function toggle(selector, show, line) {
            line.querySelectorAll(selector).forEach(function (el) { el.classList.toggle('d-none', !show); });
        }
        function syncLine(line) {
            var option = line.querySelector('[data-order-variant]').selectedOptions[0];
            var service = option && option.getAttribute('data-service') === '1';
            var unit = option ? option.getAttribute('data-unit') : '';
            var perPage = service && unit === 'page', perCopy = service && unit === 'copy';
            toggle('[data-service-only]', service, line);
            toggle('[data-goods-only]', !(perPage || perCopy), line);
            var pages = line.querySelector('[data-order-pages]').closest('[data-service-only]');
            pages.classList.toggle('d-none', !perPage);
            var copies = line.querySelector('[data-order-copies]').closest('[data-service-only]');
            copies.classList.toggle('d-none', !(perPage || perCopy));
        }
        function lineTotal(line) {
            var option = line.querySelector('[data-order-variant]').selectedOptions[0];
            if (!option || !option.value) { return 0; }
            var unit = option.getAttribute('data-unit'), service = option.getAttribute('data-service') === '1';
            var qty = parseInt(line.querySelector('[data-order-qty]').value, 10) || 0;
            var pages = parseInt(line.querySelector('[data-order-pages]').value, 10) || 0;
            var copies = parseInt(line.querySelector('[data-order-copies]').value, 10) || 0;
            if (service && unit === 'page' && pages) { qty = pages * (copies || 1); }
            else if (service && unit === 'copy' && copies) { qty = copies; }
            return qty * toCents(option.getAttribute('data-price')) + toCents(line.querySelector('[data-order-charge]').value);
        }
        function totals() {
            var subtotal = 0;
            form.querySelectorAll('[data-order-line]').forEach(function (line) {
                var cents = lineTotal(line);
                line.querySelector('[data-order-line-total]').textContent = prefix + formatCents(cents);
                subtotal += cents;
            });
            var discountInput = form.querySelector('[data-order-discount]');
            var discount = Math.min(discountInput ? toCents(discountInput.value) : 0, subtotal);
            form.querySelector('[data-order-subtotal]').textContent = prefix + formatCents(subtotal);
            form.querySelector('[data-order-discount-shown]').textContent = prefix + formatCents(discount);
            form.querySelector('[data-order-total]').textContent = prefix + formatCents(subtotal - discount);
            syncPayment(subtotal - discount);
        }
        // Payment received with the order: full or down payment, into an account linked to the items' fund.
        var pay = form.querySelector('[data-order-pay]');
        function syncPayment(total) {
            if (!pay || !pay.querySelector('[data-pay-mode]')) { return; }
            var chosen = [];
            form.querySelectorAll('[data-order-variant]').forEach(function (select) {
                var option = select.selectedOptions[0];
                if (option && option.value) { chosen.push(option); }
            });
            var partial = chosen.length > 0 && chosen.every(function (o) { return o.getAttribute('data-partial') === '1'; });
            var down = pay.querySelector('[data-pay-mode][value="down"]');
            down.disabled = !partial;
            if (!partial && down.checked) { pay.querySelector('[data-pay-mode][value="full"]').checked = true; }
            pay.querySelector('[data-pay-partial-note]').classList.toggle('d-none', partial || chosen.length === 0);
            var mode = (pay.querySelector('[data-pay-mode]:checked') || {}).value || 'later';
            pay.querySelector('[data-pay-fields]').classList.toggle('d-none', mode === 'later');
            pay.querySelector('[data-pay-down-only]').classList.toggle('d-none', mode !== 'down');
            pay.querySelector('[data-pay-full-only]').classList.toggle('d-none', mode !== 'full');
            var amountInput = pay.querySelector('[data-pay-amount]');
            amountInput.required = mode === 'down';
            pay.querySelector('[data-pay-full]').textContent = prefix + formatCents(total);
            pay.querySelector('[data-pay-balance]').textContent = prefix + formatCents(Math.max(0, total - toCents(amountInput.value)));
            var fund = chosen.length ? chosen[0].getAttribute('data-fund-id') : null;
            var account = pay.querySelector('[data-pay-account]');
            account.required = mode !== 'later';
            var visible = [];
            Array.prototype.forEach.call(account.options, function (option) {
                if (!option.value) { return; }
                var linked = !fund || (option.getAttribute('data-funds') || '').split(',').indexOf(fund) !== -1;
                option.hidden = !linked;
                option.disabled = !linked;
                if (linked) { visible.push(option); }
            });
            if (account.selectedOptions[0] && account.selectedOptions[0].hidden) { account.value = ''; }
            if (!account.value && visible.length === 1) { account.value = visible[0].value; }
            pay.querySelector('[data-pay-no-account]').classList.toggle('d-none', !(mode !== 'later' && fund && visible.length === 0));
            // Counter sale: offered when paid in full now and something is sold from stock.
            var releaseWrap = pay.querySelector('[data-release-now-wrap]');
            if (releaseWrap) {
                var hasStock = chosen.some(function (o) { return o.getAttribute('data-stock') === '1'; });
                var canRelease = mode === 'full' && hasStock;
                releaseWrap.classList.toggle('d-none', !canRelease);
                if (!canRelease) { releaseWrap.querySelector('[data-release-now]').checked = false; }
            }
        }
        var orderDate = form.querySelector('[data-order-date]');
        if (orderDate) {
            var pastNote = form.querySelector('[data-past-order-note]');
            var syncPast = function () { pastNote.classList.toggle('d-none', !orderDate.value || orderDate.value >= orderDate.getAttribute('data-today')); };
            orderDate.addEventListener('input', syncPast);
            orderDate.addEventListener('change', syncPast);
            syncPast();
        }
        if (pay) {
            pay.addEventListener('change', function (event) {
                // Taking money means the customer has committed: confirm by default (can be unticked).
                if (event.target.matches('[data-release-now]') && event.target.checked) {
                    var confirmNow = form.querySelector('[data-confirm-now]');
                    if (confirmNow) { confirmNow.checked = true; }
                }
                if (event.target.matches('[data-pay-mode]') && event.target.value !== 'later') {
                    var confirmBox = form.querySelector('[data-confirm-now]');
                    if (confirmBox) { confirmBox.checked = true; }
                }
            });
        }
        function syncCustomer() {
            var walkin = form.querySelector('[data-customer-mode][value="walkin"]').checked;
            form.querySelectorAll('[data-customer-walkin]').forEach(function (el) { el.classList.toggle('d-none', !walkin); });
            form.querySelectorAll('[data-customer-existing]').forEach(function (el) { el.classList.toggle('d-none', walkin); });
        }
        form.querySelectorAll('[data-order-line]').forEach(syncLine);
        form.addEventListener('change', function (event) {
            if (event.target.matches('[data-order-variant]')) { syncLine(event.target.closest('[data-order-line]')); }
            if (event.target.matches('[data-customer-mode]')) { syncCustomer(); }
            totals();
        });
        form.addEventListener('input', totals);
        form.addEventListener('row:added', function (event) { syncLine(event.target); totals(); });
        syncCustomer();
        totals();
    });

    window.AninawMoney = { toCents: toCents, formatCents: formatCents };
})();

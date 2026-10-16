/**
 * ANINAW — helpers for financial forms.
 *   data-money-sum="#target"  sums the amount inputs inside the element into #target
 *   data-fill-max             button that fills the sibling input with its data-max value
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

    document.querySelectorAll('[data-money-sum]').forEach(function (container) {
        container.addEventListener('input', function () { refresh(container); });
        refresh(container);
    });

    document.addEventListener('click', function (event) {
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
        container.querySelectorAll('input[type="checkbox"][data-amount]:checked').forEach(function (box) { total += toCents(box.getAttribute('data-amount')); });
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

    window.AninawMoney = { toCents: toCents, formatCents: formatCents };
})();

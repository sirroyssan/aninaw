/**
 * ANINAW — Record Solicitation form: new or existing donor, company or person fields,
 * "received now" (payment fields) or "pledge only", the event's fund preselected, and only
 * the accounts linked to the chosen fund. The server checks all of it again.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-donation-form]');
    if (!form) { return; }
    var eventSelect = form.querySelector('[data-donation-event]');
    var fundSelect = form.querySelector('[data-donation-fund]');
    var accountSelect = form.querySelector('[data-donation-account]');
    var noAccount = form.querySelector('[data-donation-no-account]');

    function checked(name) {
        var box = form.querySelector('input[name="' + name + '"]:checked');
        return box ? box.value : '';
    }
    function show(selector, visible) {
        form.querySelectorAll(selector).forEach(function (el) {
            el.classList.toggle('d-none', !visible);
            el.querySelectorAll('input, select, textarea').forEach(function (field) { field.disabled = !visible; });
        });
    }

    function syncDonor() {
        var existing = checked('donor_mode') === 'existing';
        show('[data-donor-existing]', existing);
        show('[data-donor-new]', !existing);
        form.querySelector('#donor_participant_id').required = existing;
        if (!existing) {
            var kind = form.querySelector('[data-donor-kind]').value;
            var group = kind === 'company' || kind === 'organization';
            show('[data-donor-org]', group);
            show('[data-donor-person]', !group);
            form.querySelector('#donor_org_name').required = group;
            form.querySelector('#donor_first_name').required = !group;
            form.querySelector('#donor_last_name').required = !group;
        }
    }

    function syncMode() {
        var received = checked('mode') !== 'pledge';
        show('[data-received-only]', received);
        show('[data-pledge-only]', !received);
        accountSelect.required = received;
        form.querySelector('#payment_date').required = received;
        form.querySelector('[data-amount-label]').textContent = received ? 'Amount received' : 'Amount pledged';
        form.querySelector('[data-submit-label]').textContent = received ? 'Record & Issue Receipt' : 'Record Pledge';
        form.setAttribute('data-confirm', received ? 'Record this solicitation?' : 'Record this pledge?');
        form.setAttribute('data-confirm-text', received
            ? 'Money received is posted to the fund and an official receipt is issued. Corrections need an authorised reversal.'
            : 'Nothing is posted yet. Receive payments on the pledge when the money comes in.');
        syncAccounts();
    }

    function syncAccounts() {
        var fund = fundSelect.value;
        var visible = 0;
        Array.prototype.forEach.call(accountSelect.options, function (option) {
            if (!option.value) { return; }
            var linked = fund !== '' && (option.getAttribute('data-funds') || '').split(',').indexOf(fund) !== -1;
            option.hidden = !linked;
            option.disabled = !linked;
            if (linked) { visible += 1; }
        });
        var current = accountSelect.options[accountSelect.selectedIndex];
        if (current && current.value && current.disabled) { accountSelect.value = ''; }
        if (!accountSelect.value && visible === 1) {
            Array.prototype.forEach.call(accountSelect.options, function (option) { if (option.value && !option.disabled) { accountSelect.value = option.value; } });
        }
        noAccount.classList.toggle('d-none', !fund || visible > 0 || checked('mode') === 'pledge');
    }

    eventSelect.addEventListener('change', function () {
        var option = eventSelect.options[eventSelect.selectedIndex];
        var funds = (option && option.getAttribute('data-funds') || '').split(',').filter(Boolean);
        if (funds.length && funds.indexOf(fundSelect.value) === -1) { fundSelect.value = funds[0]; }
        syncAccounts();
    });
    fundSelect.addEventListener('change', syncAccounts);
    form.addEventListener('change', function (event) {
        if (event.target.matches('[data-donor-mode], [data-donor-kind]')) { syncDonor(); }
        if (event.target.matches('[data-donation-mode]')) { syncMode(); }
    });
    syncDonor();
    syncMode();
})();

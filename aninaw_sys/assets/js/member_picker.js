/*
 * Group "Add Members": search people as you type instead of listing everyone.
 * Results come from participants/search.php (at most 20, only after 2 characters).
 * Clicking a result adds it to the selection (chips with hidden inputs); "Add Selected"
 * submits them. When no one matches, "Add a new person" opens the modal, prefilled with
 * the name that was typed. Without JavaScript the page falls back to a normal search form.
 */
(function () {
    'use strict';

    var picker = document.querySelector('[data-member-picker]');
    var modalEl = document.getElementById('new-member-modal');
    if (modalEl && modalEl.hasAttribute('data-open-on-load') && window.bootstrap) {
        window.bootstrap.Modal.getOrCreateInstance(modalEl).show(); // form came back with an error
    }
    if (!picker) {
        return;
    }
    var input = picker.querySelector('[data-member-search]');
    var searchForm = picker.querySelector('[data-member-search-form]');
    var results = picker.querySelector('[data-member-results]');
    var chips = picker.querySelector('[data-member-chips]');
    var addButton = picker.querySelector('[data-member-add-button]');
    var url = picker.getAttribute('data-search-url');
    var group = picker.getAttribute('data-group');
    var selected = {};
    var timer = null;
    var lastQuery = null;
    var request = 0;

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    function updateButton() {
        var count = Object.keys(selected).length + picker.querySelectorAll('input[type="checkbox"][name="participant_ids[]"]:checked').length;
        addButton.disabled = count === 0;
        addButton.lastChild.textContent = count > 1 ? 'Add ' + count + ' Selected' : 'Add Selected';
    }

    function select(person) {
        if (selected[person.id]) { return; }
        selected[person.id] = true;
        var chip = el('span', 'member-chip');
        chip.appendChild(el('span', '', person.name + ' · ' + person.number));
        var hidden = el('input');
        hidden.type = 'hidden';
        hidden.name = 'participant_ids[]';
        hidden.value = String(person.id);
        chip.appendChild(hidden);
        var remove = el('button', 'member-chip-remove', '×');
        remove.type = 'button';
        remove.setAttribute('aria-label', 'Remove ' + person.name);
        remove.addEventListener('click', function () {
            delete selected[person.id];
            chip.remove();
            render(lastResults);
            updateButton();
        });
        chip.appendChild(remove);
        chips.appendChild(chip);
        updateButton();
    }

    var lastResults = null;
    function render(list) {
        lastResults = list;
        results.innerHTML = '';
        var query = input.value.trim();
        if (list === null || query.length < 2) {
            results.appendChild(el('p', 'text-muted-sm mb-2', 'Type at least 2 letters of a name or ID number.'));
            return;
        }
        if (!list.length) {
            var empty = el('div', 'member-empty');
            empty.appendChild(el('p', 'text-muted-sm mb-2', 'No one found for "' + query + '".'));
            var create = el('button', 'btn btn-outline-primary btn-sm w-100');
            create.type = 'button';
            create.setAttribute('data-bs-toggle', 'modal');
            create.setAttribute('data-bs-target', '#new-member-modal');
            create.innerHTML = '<i class="bi bi-person-plus me-1" aria-hidden="true"></i>';
            create.appendChild(document.createTextNode('Add "' + query + '" as a new person'));
            empty.appendChild(create);
            results.appendChild(empty);
            return;
        }
        list.forEach(function (person) {
            var row = el('button', 'member-result');
            row.type = 'button';
            var text = el('span');
            text.appendChild(el('span', 'fw-semibold', person.name));
            text.appendChild(el('span', 'text-muted-sm', ' ' + person.number + ' · ' + person.type));
            row.appendChild(text);
            if (person.in_group) {
                row.disabled = true;
                row.appendChild(el('span', 'badge badge-soft-secondary', 'Member'));
            } else if (selected[person.id]) {
                row.disabled = true;
                row.appendChild(el('span', 'badge badge-soft-success', 'Selected'));
            } else {
                row.appendChild(el('span', 'member-result-add', '+ Add'));
                row.addEventListener('click', function () {
                    select(person);
                    render(lastResults);
                    input.focus();
                });
            }
            results.appendChild(row);
        });
    }

    function search() {
        var query = input.value.trim();
        if (query === lastQuery) { return; }
        lastQuery = query;
        if (query.length < 2) {
            render(null);
            return;
        }
        var mine = ++request;
        fetch(url + '?q=' + encodeURIComponent(query) + '&group=' + encodeURIComponent(group), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.ok ? response.json() : { results: [] };
        }).then(function (data) {
            if (mine === request) { render(data.results || []); }
        }).catch(function () {
            if (mine === request) {
                results.innerHTML = '';
                results.appendChild(el('p', 'text-danger small mb-2', 'Search failed. Check your connection and try again.'));
            }
        });
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(search, 250);
    });
    searchForm.addEventListener('submit', function (event) {
        event.preventDefault(); // search happens as you type
        clearTimeout(timer);
        lastQuery = null;
        search();
    });
    input.addEventListener('keydown', function (event) {
        // Enter adds the only match, so a known ID number can be added without the mouse.
        if (event.key === 'Enter' && lastResults && lastResults.filter(function (p) { return !p.in_group && !selected[p.id]; }).length === 1) {
            event.preventDefault();
            select(lastResults.filter(function (p) { return !p.in_group && !selected[p.id]; })[0]);
            render(lastResults);
        }
    });
    picker.addEventListener('change', updateButton);

    // Prefill the new-person form with what was typed (a name, or an ID number).
    if (modalEl) {
        modalEl.addEventListener('show.bs.modal', function () {
            var query = input.value.trim();
            if (!query || modalEl.hasAttribute('data-open-on-load')) { return; }
            var number = modalEl.querySelector('[name="participant_number"]');
            var first = modalEl.querySelector('[data-member-new-first]');
            var last = modalEl.querySelector('[data-member-new-last]');
            if (/\d/.test(query) && !/\s/.test(query)) {
                if (!number.value) { number.value = query; }
            } else if (!first.value && !last.value) {
                if (query.indexOf(',') !== -1) { // "Dela Cruz, Juan"
                    var pieces = query.split(',');
                    last.value = pieces[0].trim();
                    first.value = pieces.slice(1).join(' ').trim();
                } else { // "Juan Dela Cruz": surname particles stay with the last name
                    var words = query.split(/\s+/);
                    var surname = words.length > 1 ? [words.pop()] : [];
                    var particles = ['de', 'del', 'dela', 'delas', 'delos', 'la', 'las', 'los', 'san', 'sta', 'sta.', 'sto', 'sto.', 'santa', 'santo', 'van', 'von', 'mac', 'mc'];
                    while (words.length > 1 && particles.indexOf(words[words.length - 1].toLowerCase()) !== -1) {
                        surname.unshift(words.pop());
                    }
                    last.value = surname.join(' ');
                    first.value = words.join(' ');
                }
            }
        });
        modalEl.addEventListener('shown.bs.modal', function () {
            var firstEmpty = modalEl.querySelector('input[required]:placeholder-shown, input[required]:not([value])');
            (firstEmpty || modalEl.querySelector('input:not([type=hidden])')).focus();
        });
    }

    results.querySelectorAll('[data-member-hint]').forEach(function () { render(null); });
    updateButton();
})();

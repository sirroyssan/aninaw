/*
 * Class list review (participants/import_pdf.php).
 * Rows are posted as one JSON field so long lists are not cut off by the server's limit
 * on form fields; the per-row inputs stop being submitted once this script runs.
 * Without the script the form still works with ordinary fields.
 */
(function () {
    'use strict';

    var form = document.querySelector('form[data-class-list]');
    if (!form) {
        return;
    }
    var rows = Array.prototype.slice.call(form.querySelectorAll('tr[data-row]'));
    var jsonField = form.querySelector('[data-rows-json]');
    var countLabel = document.querySelector('[data-selected-count]');
    var selectAll = form.querySelector('[data-select-all]');

    // Row inputs keep their values but no longer post individually.
    rows.forEach(function (row) {
        row.querySelectorAll('input[name^="rows["]').forEach(function (input) {
            if (input.type === 'hidden' && input.value === '0' && input.name.slice(-9) === '[include]') {
                input.remove();
                return;
            }
            input.removeAttribute('name');
        });
    });

    function sync() {
        var data = [];
        var selected = 0;
        rows.forEach(function (row) {
            var item = {};
            row.querySelectorAll('[data-field]').forEach(function (input) {
                var field = input.getAttribute('data-field');
                item[field] = input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value;
                if (input.type !== 'checkbox' && field !== 'sex' && field !== 'no' && field !== 'raw_name') {
                    input.required = field !== 'middle_name' && item.include !== '0';
                }
            });
            if (item.include === '1') {
                selected++;
            }
            row.classList.toggle('text-muted', item.include !== '1');
            data.push(item);
        });
        jsonField.value = JSON.stringify(data);
        if (countLabel) {
            countLabel.textContent = String(selected);
        }
        if (selectAll) {
            selectAll.checked = selected === rows.length;
            selectAll.indeterminate = selected > 0 && selected < rows.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            rows.forEach(function (row) {
                var box = row.querySelector('[data-field="include"]');
                if (box) {
                    box.checked = selectAll.checked;
                }
            });
            sync();
        });
    }
    form.addEventListener('input', sync);
    form.addEventListener('change', sync);
    form.addEventListener('submit', sync, true);

    // Group options: show the fields for the chosen mode.
    function syncGroup() {
        var checked = form.querySelector('[data-group-mode]:checked');
        var mode = checked ? checked.value : 'none';
        form.querySelectorAll('[data-group-panel]').forEach(function (panel) {
            var active = panel.getAttribute('data-group-panel') === mode;
            panel.classList.toggle('d-none', !active);
            panel.querySelectorAll('input, select').forEach(function (input) {
                input.required = active && (input.id === 'group_id' || input.id === 'group_code' || input.id === 'group_name');
            });
        });
    }
    form.querySelectorAll('[data-group-mode]').forEach(function (radio) {
        radio.addEventListener('change', syncGroup);
    });

    syncGroup();
    sync();
})();

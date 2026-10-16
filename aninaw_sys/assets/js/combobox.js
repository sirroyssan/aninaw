/*
 * Searchable select ("combo box") for long lists such as customers, members and products.
 *
 * Usage: <select class="form-select" data-searchable> ... </select>
 * The native <select> stays in the form and remains the source of truth: it is still
 * submitted, still validated (required), and receives "change" events, so page scripts
 * that read select.value / selectedOptions keep working. Typing filters the options by
 * every word (accents and case ignored) across the option text, its group label and an
 * optional data-search attribute. Selects added later (e.g. new order lines) are enhanced
 * when they appear ("row:added") or when enhanceSearchableSelects(root) is called.
 */
(function () {
    'use strict';

    var MAX_RESULTS = 150;
    var uid = 0;

    function normalize(text) {
        return String(text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function optionText(option) {
        return option ? option.textContent.replace(/\s+/g, ' ').trim() : '';
    }

    function enhance(select) {
        if (select.dataset.comboReady || select.multiple) {
            return;
        }
        select.dataset.comboReady = '1';
        var id = 'combo-' + (++uid);

        var wrapper = document.createElement('div');
        wrapper.className = 'combo';
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);
        select.classList.add('combo-native');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');

        var input = document.createElement('input');
        input.type = 'text';
        input.className = select.className.replace('combo-native', '').replace('form-select', 'form-select combo-input').trim();
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', id + '-list');
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('spellcheck', 'false');
        // The visible input takes over the select's label and accessible name.
        if (select.id) {
            input.id = select.id + '-search';
            var label = document.querySelector('label[for="' + select.id + '"]');
            if (label) {
                label.setAttribute('for', input.id);
            }
        }
        if (select.getAttribute('aria-label')) {
            input.setAttribute('aria-label', select.getAttribute('aria-label'));
        }
        wrapper.insertBefore(input, select);

        var list = document.createElement('ul');
        list.className = 'combo-list';
        list.id = id + '-list';
        list.setAttribute('role', 'listbox');
        list.hidden = true;
        document.body.appendChild(list);

        var placeholderOption = Array.prototype.find.call(select.options, function (o) { return o.value === ''; });
        input.placeholder = placeholderOption ? optionText(placeholderOption) : 'Search…';

        var matches = [];
        var active = -1;
        var open = false;

        function selectedLabel() {
            var option = select.selectedOptions[0];
            return option && option.value !== '' ? optionText(option) : '';
        }

        function syncInput() {
            input.value = selectedLabel();
            input.disabled = select.disabled;
            wrapper.classList.toggle('is-invalid', select.classList.contains('is-invalid'));
        }

        function searchable(option) {
            var group = option.parentNode && option.parentNode.tagName === 'OPTGROUP' ? option.parentNode.label : '';
            return normalize(optionText(option) + ' ' + group + ' ' + (option.dataset.search || ''));
        }

        function position() {
            var rect = input.getBoundingClientRect();
            var below = window.innerHeight - rect.bottom;
            var height = Math.min(320, list.scrollHeight || 320);
            list.style.left = rect.left + 'px';
            list.style.width = Math.max(rect.width, 240) + 'px';
            if (below < height + 8 && rect.top > below) {
                list.style.top = '';
                list.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
            } else {
                list.style.bottom = '';
                list.style.top = (rect.bottom + 4) + 'px';
            }
        }

        // Whole-word matches rank above word starts, which rank above matches inside a word.
        function score(haystack, words) {
            var tokens = haystack.split(/[^a-z0-9]+/).filter(Boolean);
            return words.reduce(function (sum, word) {
                var best = 0;
                tokens.forEach(function (token) {
                    best = Math.max(best, token === word ? 3 : (token.indexOf(word) === 0 ? 2 : 0));
                });
                return sum + (best || 1);
            }, 0);
        }

        function render(query) {
            var words = normalize(query).split(' ').filter(Boolean);
            var bestIndex = -1;
            var bestScore = -1;
            matches = [];
            var total = 0;
            list.innerHTML = '';
            var lastGroup = null;
            Array.prototype.forEach.call(select.options, function (option) {
                if (option.value === '' || option.hidden || option.disabled) {
                    return;
                }
                var haystack = searchable(option);
                if (!words.every(function (w) { return haystack.indexOf(w) !== -1; })) {
                    return;
                }
                total++;
                if (matches.length >= MAX_RESULTS) {
                    return;
                }
                var group = option.parentNode && option.parentNode.tagName === 'OPTGROUP' ? option.parentNode.label : null;
                if (group && group !== lastGroup) {
                    var header = document.createElement('li');
                    header.className = 'combo-group';
                    header.setAttribute('role', 'presentation');
                    header.textContent = group;
                    list.appendChild(header);
                }
                lastGroup = group;
                var item = document.createElement('li');
                item.className = 'combo-option' + (option.selected ? ' is-selected' : '');
                item.id = id + '-opt-' + matches.length;
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', option.selected ? 'true' : 'false');
                item.textContent = optionText(option);
                item.dataset.index = String(matches.length);
                list.appendChild(item);
                if (words.length) {
                    var points = score(haystack, words);
                    if (points > bestScore) {
                        bestScore = points;
                        bestIndex = matches.length;
                    }
                }
                matches.push(option);
            });
            if (!matches.length) {
                var empty = document.createElement('li');
                empty.className = 'combo-empty';
                empty.textContent = 'No matches';
                list.appendChild(empty);
            } else if (total > matches.length) {
                var more = document.createElement('li');
                more.className = 'combo-empty';
                more.textContent = 'Showing ' + matches.length + ' of ' + total + '. Type more to narrow the list.';
                list.appendChild(more);
            }
            var current = matches.indexOf(select.selectedOptions[0]);
            setActive(words.length ? bestIndex : current);
        }

        function setActive(index) {
            var items = list.querySelectorAll('.combo-option');
            if (active >= 0 && items[active]) {
                items[active].classList.remove('is-active');
            }
            active = matches.length ? Math.max(-1, Math.min(index, matches.length - 1)) : -1;
            if (active >= 0 && items[active]) {
                items[active].classList.add('is-active');
                input.setAttribute('aria-activedescendant', items[active].id);
                var top = items[active].offsetTop;
                var bottom = top + items[active].offsetHeight;
                if (top < list.scrollTop) {
                    list.scrollTop = top;
                } else if (bottom > list.scrollTop + list.clientHeight) {
                    list.scrollTop = bottom - list.clientHeight;
                }
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        }

        function show(query) {
            render(query);
            list.hidden = false;
            open = true;
            input.setAttribute('aria-expanded', 'true');
            position();
        }

        function hide() {
            list.hidden = true;
            open = false;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        }

        function choose(option) {
            var changed = select.value !== option.value;
            select.value = option.value;
            select.classList.remove('is-invalid');
            syncInput();
            hide();
            if (changed) {
                select.dispatchEvent(new Event('input', { bubbles: true }));
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        function restore() {
            // Leaving the field: an emptied field clears the choice; anything else shows the current choice.
            if (input.value.trim() === '' && placeholderOption && select.value !== '') {
                select.value = '';
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
            syncInput();
        }

        // A click that focuses the field keeps the whole text selected, so typing starts a new search.
        var selectOnMouseUp = false;
        input.addEventListener('mousedown', function () {
            selectOnMouseUp = document.activeElement !== input;
        });
        input.addEventListener('mouseup', function (event) {
            if (selectOnMouseUp) {
                event.preventDefault();
                input.select();
                selectOnMouseUp = false;
            }
        });
        input.addEventListener('focus', function () {
            input.select();
            show('');
        });
        input.addEventListener('click', function () {
            if (!open) {
                input.select(); // reopening after a choice: typing replaces the shown choice
                show('');
            }
        });
        input.addEventListener('input', function () {
            show(input.value);
        });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (!open) {
                    show('');
                    return;
                }
                var next = active < 0 ? 0 : active + (event.key === 'ArrowDown' ? 1 : -1);
                setActive(Math.max(0, Math.min(next, matches.length - 1)));
            } else if (event.key === 'Enter') {
                if (open) {
                    event.preventDefault(); // never submit the form while choosing
                    if (active >= 0 && matches[active]) {
                        choose(matches[active]);
                    }
                }
            } else if (event.key === 'Escape') {
                if (open) {
                    event.preventDefault();
                    hide();
                    syncInput();
                }
            } else if (event.key === 'Tab' && open && input.value.trim() !== '' && input.value !== selectedLabel() && active >= 0 && matches[active]) {
                choose(matches[active]);
            }
        });
        input.addEventListener('blur', function () {
            setTimeout(function () {
                if (document.activeElement !== input) {
                    hide();
                    restore();
                }
            }, 120);
        });
        list.addEventListener('mousedown', function (event) {
            event.preventDefault(); // keep focus in the input
            var item = event.target.closest('.combo-option');
            if (item && matches[Number(item.dataset.index)]) {
                choose(matches[Number(item.dataset.index)]);
            }
        });
        list.addEventListener('mousemove', function (event) {
            var item = event.target.closest('.combo-option');
            if (item && Number(item.dataset.index) !== active) {
                setActive(Number(item.dataset.index));
            }
        });

        // Changes made by other scripts or by the browser (form reset, back button).
        select.addEventListener('change', syncInput);
        select.addEventListener('invalid', function () {
            wrapper.classList.add('is-invalid');
            input.focus();
        });
        window.addEventListener('resize', function () { if (open) { position(); } });
        window.addEventListener('scroll', function () { if (open) { position(); } }, true);

        syncInput();
    }

    function enhanceAll(root) {
        (root || document).querySelectorAll('select[data-searchable]').forEach(enhance);
    }

    window.enhanceSearchableSelects = enhanceAll;
    document.addEventListener('row:added', function (event) {
        enhanceAll(event.target);
    });
    enhanceAll(document);
})();

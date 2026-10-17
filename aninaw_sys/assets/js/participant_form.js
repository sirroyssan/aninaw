/*
 * Person form: a student needs a middle name unless "No middle name" is ticked.
 * Ticking it clears and disables the field; the server checks the same rule.
 */
(function () {
    'use strict';
    var middle = document.querySelector('[data-middle-name]');
    var none = document.querySelector('[data-no-middle]');
    var type = document.getElementById('participant_type');
    var label = document.querySelector('[data-middle-label]');
    if (!middle || !none || !type) {
        return;
    }
    function sync() {
        if (none.checked) {
            middle.value = '';
        }
        middle.disabled = none.checked;
        var required = type.value === 'student' && !none.checked;
        middle.required = required;
        label.classList.toggle('required', required);
    }
    none.addEventListener('change', sync);
    type.addEventListener('change', sync);
    sync();
})();

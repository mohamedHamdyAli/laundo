/**
 * The laundry auth pages.
 *
 * One behaviour, no dependencies. These pages deliberately load none of the
 * panel's stack — no jQuery, no bootstrap — so anything they need is here and
 * is plain DOM. The map is not here: `x-map-picker` is the panel's own
 * component and already does it, so the register form uses that.
 */
(function () {
    'use strict';

    /* ---------------------------------------------------------- reveal a password
     *
     * The eye toggles the field it names through `data-reveal-for`, rather than
     * the nearest input: the application form has two password boxes, and a
     * "nearest input" implementation on a two-column grid reveals whichever one
     * the markup happens to put first.
     */
    document.querySelectorAll('[data-reveal-for]').forEach(function (button) {
        button.addEventListener('click', function () {
            var field = document.getElementById(button.getAttribute('data-reveal-for'));

            if (!field) {
                return;
            }

            var shown = field.type === 'text';

            field.type = shown ? 'password' : 'text';
            button.setAttribute('aria-label', shown ? button.dataset.showLabel || 'Show password'
                : button.dataset.hideLabel || 'Hide password');
            button.classList.toggle('is-on', !shown);
        });
    });
})();

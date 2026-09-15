/**
 * An eye on every password box in the panel.
 *
 * Five screens take a password — change-password, and the create/edit forms for
 * drivers, laundries, laundry staff and moderators — and none of them could show
 * what had been typed. That matters more here than on a sign-in form: an
 * operator is not recalling their own password, they are **inventing one for
 * somebody else** and then reading it out or writing it down. A typo they cannot
 * see becomes a locked-out driver and a support call, and the «Confirm» box only
 * catches it when the same typo was not made twice.
 *
 * Done here rather than in five Blade files so the markup stays as it is and a
 * sixth form gets the behaviour by existing. The panel's own auth pages already
 * have this through `auth-card.js` and its `data-reveal-for` attribute; that
 * script is deliberately not loaded outside them, and this is its counterpart
 * for a layout where the markup cannot be hand-written per field.
 *
 * Progressive: with no `closest` or no `classList` nothing binds and the fields
 * behave exactly as they did.
 */
(function () {
    'use strict';

    if (!document.querySelectorAll || !Element.prototype.closest) {
        return;
    }

    var WRAPPER = 'pw-reveal';

    /**
     * `type="button"` is load-bearing.
     *
     * A `<button>` inside a form defaults to `type="submit"`, so an eye without
     * it posts the half-filled form the moment somebody checks what they typed
     * — on a create screen that is a validation error, and on an edit screen it
     * is a save nobody asked for.
     */
    function buttonFor(input) {
        var button = document.createElement('button');

        button.type = 'button';
        button.className = 'pw-reveal-btn';
        button.setAttribute('aria-label', window.pwRevealShowLabel || 'Show password');
        button.setAttribute('aria-pressed', 'false');
        button.setAttribute('tabindex', '-1');
        button.innerHTML = '<i class="bi bi-eye" aria-hidden="true"></i>';

        button.addEventListener('click', function () {
            var shown = input.type === 'text';

            input.type = shown ? 'password' : 'text';
            button.setAttribute('aria-pressed', shown ? 'false' : 'true');
            button.setAttribute(
                'aria-label',
                shown ? (window.pwRevealShowLabel || 'Show password')
                    : (window.pwRevealHideLabel || 'Hide password')
            );
            button.innerHTML = shown
                ? '<i class="bi bi-eye" aria-hidden="true"></i>'
                : '<i class="bi bi-eye-slash" aria-hidden="true"></i>';

            // Put the caret back where it was: the click moved focus off the
            // field, and an operator mid-word should not have to find their
            // place again.
            input.focus();
        });

        return button;
    }

    document.querySelectorAll('input[type="password"]').forEach(function (input) {
        // `data-reveal-for` is the auth pages' own implementation. If a field is
        // already served by one, adding a second eye beside it is worse than
        // none.
        if (input.closest('.' + WRAPPER) || document.querySelector('[data-reveal-for="' + input.id + '"]')) {
            return;
        }

        var wrapper = document.createElement('div');
        wrapper.className = WRAPPER;

        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);
        wrapper.appendChild(buttonFor(input));
    });
})();

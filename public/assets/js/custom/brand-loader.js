/**
 * The splash, taken away.
 *
 * Two clocks have to finish before the panel is shown, and the screen stays up
 * until the later of them:
 *
 *   - the wordmark reveal, so the animation is never cut off mid-word;
 *   - `window.load`, so the panel is not shown half-painted.
 *
 * Ordered this way round because either one alone is wrong. Hiding on load
 * makes the reveal a flicker on a warm cache; hiding on the animation makes a
 * slow page show a finished logo over nothing.
 *
 * Deliberately no jQuery and no bundler: this has to run before everything
 * else, and every other script in the panel loads at the bottom of the page.
 */
(function () {
    'use strict';

    var loader = document.getElementById('brand-loader');

    if (!loader) {
        return;
    }

    var mark = loader.querySelector('.brand-loader-mark');
    var done = { reveal: false, page: false };

    function dismiss() {
        if (!done.reveal || !done.page || loader.classList.contains('is-leaving')) {
            return;
        }

        loader.classList.add('is-leaving');

        // Removed rather than left hidden: it sits above everything at z-index
        // 9999, and a fade-out that is interrupted leaves an invisible sheet
        // over the whole panel swallowing every click.
        window.setTimeout(function () {
            if (loader.parentNode) {
                loader.parentNode.removeChild(loader);
            }
        }, 400);
    }

    function revealDone() {
        done.reveal = true;
        dismiss();
    }

    if (mark) {
        mark.addEventListener('animationend', revealDone);
    }

    // The belt to the animationend braces. A cached page can fire the animation
    // before this script runs, and a reduced-motion visitor has no animation to
    // end at all — either way the panel must not stay behind a splash screen.
    window.setTimeout(revealDone, 1200);

    if (document.readyState === 'complete') {
        done.page = true;
        dismiss();
    } else {
        window.addEventListener('load', function () {
            done.page = true;
            dismiss();
        });
    }
})();

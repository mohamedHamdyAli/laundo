/* ==========================================================================
   landing.js — everything the public page needs, and nothing else

   No jQuery, no Alpine, no framework. The admin panel loads ~30 scripts; this
   page loads one, deferred, and every behaviour below degrades to something
   sensible without it:

     * the header simply never gains its scrolled border
     * the nav sheet is closed, and its toggle does nothing — so the same links
       are repeated in the footer, which is not a workaround, it is the reason
       the footer has a nav
     * `data-reveal` elements stay visible, because the hiding class is only
       added by the inline head script when it has confirmed it can animate
     * the timeline rail and the review card render in their final state, since
       the markup already contains the finished values

   That last point is the one worth keeping. The common version of a
   reveal-on-scroll hides everything in CSS and depends on script to show it,
   which fails silently and takes the whole page with it.
   ========================================================================== */

(function () {
    'use strict';

    var root = document.documentElement;
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ----------------------------------------------------------------------
       The header's scrolled state

       Flush with the hero at rest — a border across nothing reads as a stray
       rule — and hairlined once the page has moved. 8px rather than 0 so a
       trackpad's idle jitter does not flicker it.
       ---------------------------------------------------------------------- */
    var header = document.querySelector('[data-site-header]');

    if (header) {
        var applyScrolled = function () {
            header.classList.toggle('is-scrolled', window.scrollY > 8);
        };

        applyScrolled();
        window.addEventListener('scroll', applyScrolled, { passive: true });
    }

    /* ----------------------------------------------------------------------
       The mobile nav sheet

       `aria-expanded` on the button is the state; the class on the header is
       only how it is drawn. Escape closes it and returns focus to the button,
       and any click outside closes it — both are what a keyboard user and a
       thumb respectively expect, and neither is free with CSS alone.
       ---------------------------------------------------------------------- */
    var toggle = document.querySelector('[data-nav-toggle]');
    var toggleLabel = document.querySelector('[data-nav-toggle-label]');

    if (header && toggle) {
        var setOpen = function (open) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            header.classList.toggle('is-open', open);

            if (toggleLabel) {
                toggleLabel.textContent = toggle.getAttribute(
                    open ? 'data-label-close' : 'data-label-open'
                ) || '';
            }
        };

        toggle.addEventListener('click', function () {
            setOpen(toggle.getAttribute('aria-expanded') !== 'true');
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
                setOpen(false);
                toggle.focus();
            }
        });

        document.addEventListener('click', function (event) {
            if (toggle.getAttribute('aria-expanded') !== 'true') {
                return;
            }

            if (!header.contains(event.target)) {
                setOpen(false);
            }
        });

        // Following an in-page link should put the sheet away, or the section
        // the visitor just chose is behind it.
        var nav = document.querySelector('[data-site-nav]');

        if (nav) {
            nav.addEventListener('click', function (event) {
                var link = event.target.closest('a[href^="#"]');

                if (link) {
                    setOpen(false);
                }
            });
        }
    }

    /* ----------------------------------------------------------------------
       Reveal on scroll

       One-shot, then unobserved. `js-reveal` was added by the inline head
       script — blocking, before first paint — so there is no flash of visible
       content being hidden. If that class is absent, everything is already
       visible and there is nothing to do here.
       ---------------------------------------------------------------------- */
    var revealables = document.querySelectorAll('[data-reveal]');

    if (root.classList.contains('js-reveal') && 'IntersectionObserver' in window) {
        var revealObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }

                entry.target.classList.add('is-revealed');
                revealObserver.unobserve(entry.target);
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });

        Array.prototype.forEach.call(revealables, function (el) {
            revealObserver.observe(el);
        });
    } else {
        Array.prototype.forEach.call(revealables, function (el) {
            el.classList.add('is-revealed');
        });
    }

    /* ----------------------------------------------------------------------
       The timeline rail

       Draws once, when the timeline comes into view. Under reduced motion the
       stylesheet already pins the rail at full scale, so this is skipped
       rather than applied instantly — the class is what the transition hangs
       off, and adding it would start a transition the media query has turned
       off anyway.
       ---------------------------------------------------------------------- */
    var timeline = document.querySelector('[data-timeline]');

    if (timeline) {
        if (reduced || !('IntersectionObserver' in window)) {
            timeline.classList.add('is-drawn');
        } else {
            var railObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) {
                        return;
                    }

                    entry.target.classList.add('is-drawn');
                    railObserver.unobserve(entry.target);
                });
            }, { threshold: 0.25 });

            railObserver.observe(timeline);
        }
    }

    /* ----------------------------------------------------------------------
       The review card's count-up

       This is the one animation on the page that is an *explanation* rather
       than an entrance: the whole proposition is that the count changes and
       the customer gets to approve the new number, so watching it move from
       the estimate to the laundry's count is the argument in miniature.

       The markup holds the **final** figures. This winds the piece count back
       to the estimate and counts it up — so a reader with reduced motion, or
       with JS off, sees the finished card rather than a number frozen at the
       wrong value. That inversion matters: the alternative renders the
       estimate and depends on script to correct it, which means a
       reduced-motion visitor is shown a price that is not the one being
       approved.

       Only the integer is animated. The money strings come from
       `moneyFormat()` and carry a currency symbol, thousands separators and
       RTL marks; interpolating them would mean reimplementing the formatter in
       JavaScript, and getting it subtly wrong in Arabic.
       ---------------------------------------------------------------------- */
    var actual = document.querySelector('[data-count-from][data-count-to]');
    var piecesEl = actual && actual.querySelector('[data-review-pieces]');

    if (actual && piecesEl && !reduced && 'IntersectionObserver' in window) {
        var from = parseInt(actual.getAttribute('data-count-from'), 10);
        var to = parseInt(actual.getAttribute('data-count-to'), 10);

        if (!isNaN(from) && !isNaN(to) && from !== to) {
            // The label is a translated template — «:count قطعة» — already
            // rendered with the final number. Rebuilding it by replacing that
            // number keeps the surrounding words, whatever language they are
            // in and whichever side of the number they sit on.
            var template = piecesEl.textContent.trim();
            var finalText = template;
            var withCount = function (value) {
                return finalText.replace(String(to), String(value));
            };

            var countObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) {
                        return;
                    }

                    countObserver.unobserve(entry.target);

                    var start = null;
                    var duration = 700;

                    piecesEl.textContent = withCount(from);

                    var step = function (now) {
                        if (start === null) {
                            start = now;
                        }

                        var progress = Math.min((now - start) / duration, 1);
                        // Ease-out, so it settles rather than stopping dead.
                        var eased = 1 - Math.pow(1 - progress, 3);
                        var value = Math.round(from + (to - from) * eased);

                        piecesEl.textContent = withCount(value);

                        if (progress < 1) {
                            window.requestAnimationFrame(step);
                        } else {
                            piecesEl.textContent = finalText;
                        }
                    };

                    window.requestAnimationFrame(step);
                });
            }, { threshold: 0.4 });

            countObserver.observe(actual);
        }
    }
})();

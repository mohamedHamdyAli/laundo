/**
 * Dashboard forms keep what you typed when validation fails.
 *
 * Every create and edit screen in the panel posted normally, so a failed
 * validation was a full page load: the server re-rendered the form from
 * `old()` and everything `old()` cannot hold went with it — every file
 * chosen, both passwords, every select2 selection, and the scroll position.
 * The report that started this was somebody filling in the driver form,
 * mistyping a phone number, and getting an empty form back with two uploads
 * gone.
 *
 * The fix is not new validation. Laravel already answers a request that wants
 * JSON with `422 {message, errors}` instead of a redirect, so nothing on the
 * server changes: the form is submitted in the background, and on a 422 the
 * messages are painted beside the fields they belong to and the page is left
 * exactly as it was.
 *
 * `form.needs-validation` is the hook because all 41 create and edit forms
 * already carry it and nothing else was bound to it. The small action forms —
 * approve, toggle, delete — deliberately do not: there is nothing typed in
 * them to lose, and a background submit would only hide the page they lead to.
 *
 * Progressive: if `fetch` or `FormData` is missing the listener never binds
 * and the form posts the way it always did.
 */
(function () {
    'use strict';

    if (!window.fetch || !window.FormData || !window.NodeList) {
        return;
    }

    var FIELD_ERROR = 'js-field-error';
    var INVALID = 'is-invalid';

    /**
     * Laravel reports `name.en`; the input is called `name[en]`.
     *
     * Also tried without the trailing `[]`, because a checkbox group posts as
     * `zones[]` and comes back keyed simply `zones`.
     */
    function findField(form, key) {
        var bracketed = key.replace(/\.(\w+)/g, '[$1]');

        return form.querySelector('[name="' + bracketed + '"]')
            || form.querySelector('[name="' + bracketed + '[]"]')
            || form.querySelector('[name="' + key + '"]')
            || form.querySelector('[name="' + key + '[]"]');
    }

    /**
     * Where a message should sit.
     *
     * Beside the control, not at the end of the column: a file input is
     * wrapped in a dropzone and a select2 replaces its <select> with a
     * sibling, so appending to the field's own parent is what keeps the
     * message under the thing it is about.
     */
    function anchorFor(field) {
        return field.closest('.mb-3, .form-group, .col-12, .col-md-3, .col-md-4, .col-md-6, .col-lg-3, .col-lg-6')
            || field.parentNode;
    }

    function clearErrors(form) {
        form.querySelectorAll('.' + FIELD_ERROR).forEach(function (node) {
            node.remove();
        });

        form.querySelectorAll('.' + INVALID).forEach(function (node) {
            node.classList.remove(INVALID);
        });

        var banner = form.querySelector('[data-form-banner]');

        if (banner) {
            banner.remove();
        }
    }

    function paintErrors(form, errors) {
        var first = null;

        Object.keys(errors).forEach(function (key) {
            var field = findField(form, key);
            var message = [].concat(errors[key])[0];

            var note = document.createElement('div');
            note.className = FIELD_ERROR;
            note.textContent = message;

            if (field) {
                field.classList.add(INVALID);
                anchorFor(field).appendChild(note);
                first = first || field;
            } else {
                // An error on something with no field of its own — a
                // cross-field rule, or a key the form does not render. It still
                // has to be visible, so it goes to the top rather than nowhere.
                banner(form).appendChild(note);
                first = first || form;
            }
        });

        return first;
    }

    function banner(form) {
        var existing = form.querySelector('[data-form-banner]');

        if (existing) {
            return existing;
        }

        var box = document.createElement('div');
        box.className = 'alert alert-danger js-form-banner';
        box.setAttribute('data-form-banner', '');
        form.insertBefore(box, form.firstChild);

        return box;
    }

    function reveal(target) {
        if (!target) {
            return;
        }

        target.scrollIntoView({ block: 'center', behavior: 'smooth' });

        // Focus after the scroll is under way. Focusing first makes the browser
        // jump instantly and the smooth scroll then has nowhere to go.
        window.setTimeout(function () {
            if (typeof target.focus === 'function') {
                target.focus({ preventScroll: true });
            }
        }, 250);
    }

    function busy(button, on) {
        if (!button) {
            return;
        }

        if (on) {
            button.dataset.idleLabel = button.innerHTML;
            button.disabled = true;
            button.innerHTML = button.dataset.busyLabel || button.innerHTML;
        } else {
            button.disabled = false;

            if (button.dataset.idleLabel) {
                button.innerHTML = button.dataset.idleLabel;
            }
        }
    }

    document.querySelectorAll('form.needs-validation').forEach(function (form) {
        // A field the person is fixing stops being marked wrong as they fix it.
        form.addEventListener('input', function (event) {
            var field = event.target;

            if (!field.classList || !field.classList.contains(INVALID)) {
                return;
            }

            field.classList.remove(INVALID);

            var note = anchorFor(field).querySelector('.' + FIELD_ERROR);

            if (note) {
                note.remove();
            }
        }, true);

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var button = form.querySelector('[type="submit"]');

            clearErrors(form);
            busy(button, true);

            fetch(form.getAttribute('action'), {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    // Without this Laravel answers a failed validation with a
                    // redirect, which is the page reload this exists to stop.
                    'Accept': 'application/json'
                }
            }).then(function (response) {
                if (response.status === 422) {
                    return response.json().then(function (body) {
                        reveal(paintErrors(form, body.errors || {}));
                        busy(button, false);
                    });
                }

                if (response.ok || response.redirected) {
                    // The controller redirected and fetch followed it, so
                    // `response.url` is where the page was going anyway. Going
                    // there properly keeps the flash message and the history
                    // entry the panel expects.
                    window.location.href = response.url;

                    return;
                }

                // 403, 500, a session that expired. Nothing useful to paint per
                // field, and swallowing it would leave a dead button — so the
                // browser is handed the request the ordinary way and shows
                // whatever the server actually said.
                busy(button, false);
                form.submit();
            }).catch(function () {
                // Offline, or the request was cut. Same reasoning.
                busy(button, false);
                form.submit();
            });
        });
    });
})();

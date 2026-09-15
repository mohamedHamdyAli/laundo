/**
 * The topbar bell.
 *
 * Two faults lived here and both were invisible in the code:
 *
 *   1. **The counter could never be drawn.** The badge ships with the `hidden`
 *      attribute and the vendor `app.css` carries
 *      `[hidden] { display: none !important }`; this raised it with
 *      `style.display`, an inline style, which cannot out-rank `!important`
 *      from a stylesheet. Fifty alerts sat unread on the live install with
 *      nothing on screen to say so. Toggled with `.hidden` now.
 *
 *   2. **Rows were appended to the list**, and the list already ended with
 *      «See all notifications» — so the way out of the dropdown rendered as a
 *      heading above the things it was a way out of. They are inserted before
 *      the footer now.
 *
 * The rendering is deliberately element-by-element rather than an HTML string:
 * the text comes from a customer's order, a driver's name or an operator's own
 * message, and `textContent` cannot be talked into being markup. The old
 * `innerHTML` template escaped by hand, which is one forgotten call away from
 * an injection.
 */
(function () {
    const badge = document.getElementById('notification-badge');
    const list = document.getElementById('notification-list');
    const emptyItem = document.getElementById('notification-empty');
    const footItem = document.getElementById('notification-foot');
    const markAll = document.getElementById('notification-mark-all');

    if (!badge || !list) {
        return;
    }

    const unreadUrl = window.notificationUnreadUrl;
    const readUrlTemplate = window.notificationReadUrlTemplate;
    const readAllUrl = window.notificationReadAllUrl;
    const csrfToken = window.csrfToken;

    /**
     * One line of a row, pointed whichever way its own words run.
     *
     * `dir="auto"` per line, not per list. This panel is operated in Arabic
     * while plenty of notification copy is English — an order code, a driver's
     * Latin name, an operator's own message — and a single paragraph direction
     * puts the full stop of an English sentence on the wrong side inside the
     * Arabic panel, and the plus of `+20100…` on the wrong side of the number.
     * Each line takes its direction from its first strong character, which is
     * the only rule that is right for both.
     */
    function line(className, text) {
        const el = document.createElement('span');
        el.className = className;
        el.dir = 'auto';
        el.textContent = text ?? '';

        return el;
    }

    function row(item) {
        const li = document.createElement('li');
        li.className = 'notification-item';

        const link = document.createElement('a');
        link.className = 'notification-row' + (item.read ? '' : ' is-unread');
        link.dataset.id = item.id;

        /*
         * A row with nowhere to go is not a link. Most notifications carry the
         * screen they are about; the few that do not would otherwise render as
         * an `href="#"` that scrolls the page to the top and looks broken.
         */
        if (item.url) {
            link.href = item.url;
        } else {
            link.setAttribute('role', 'button');
            link.tabIndex = 0;
        }

        // The dot is the whole unread affordance. Bold text alone is not one:
        // beside another bold row it reads as emphasis, not as state.
        const dot = document.createElement('span');
        dot.className = 'notification-dot';
        dot.setAttribute('aria-hidden', 'true');
        link.appendChild(dot);

        const body = document.createElement('span');
        body.className = 'notification-body';
        body.appendChild(line('notification-title', item.title));

        if (item.message) {
            body.appendChild(line('notification-text', item.message));
        }

        body.appendChild(line('notification-time', item.created_at));
        link.appendChild(body);

        link.addEventListener('click', function () {
            if (item.read || !readUrlTemplate) {
                return;
            }

            // Marked on the way out, so the count is right when the target
            // screen loads. A failure here must not swallow the navigation.
            markRead(readUrlTemplate.replace('__ID__', item.id));
            link.classList.remove('is-unread');
        });

        li.appendChild(link);

        return li;
    }

    function markRead(url) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
        }).catch(() => {});
    }

    function renderNotifications(data) {
        const count = data.count || 0;
        const items = data.items || [];

        /*
         * `.hidden`, not `style.display`. `app.css` hides `[hidden]` with
         * `!important`, which an inline style cannot beat — see the header.
         */
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.hidden = false;
        } else {
            badge.hidden = true;
        }

        if (markAll) {
            markAll.hidden = count === 0;
        }

        list.querySelectorAll('.notification-item').forEach((el) => el.remove());

        if (emptyItem) {
            emptyItem.hidden = items.length > 0;
        }

        items.forEach((item) => {
            // Before the footer. Appending put every row after «See all».
            list.insertBefore(row(item), footItem);
        });
    }

    function fetchUnread() {
        if (!unreadUrl) {
            return;
        }

        fetch(unreadUrl, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((response) => response.json())
            .then(renderNotifications)
            .catch(() => {});
    }

    if (markAll && readAllUrl) {
        markAll.addEventListener('click', function (event) {
            // Inside an open dropdown: without this the menu closes on the way
            // to the handler and the list the operator wanted to watch clear
            // disappears instead.
            event.preventDefault();
            event.stopPropagation();

            markRead(readAllUrl).then(fetchUnread);
        });
    }

    fetchUnread();
    setInterval(fetchUnread, 15000);
})();

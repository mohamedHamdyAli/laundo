(function () {
    const badge = document.getElementById('notification-badge');
    const list = document.getElementById('notification-list');
    const emptyItem = document.getElementById('notification-empty');

    if (!badge || !list) {
        return;
    }

    const unreadUrl = window.notificationUnreadUrl;
    const readUrlTemplate = window.notificationReadUrlTemplate;
    const csrfToken = window.csrfToken;

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    }

    function renderNotifications(data) {
        const count = data.count || 0;

        // `badge.hidden`, **not** `style.display`.
        //
        // The markup ships the badge with the `hidden` attribute, and the vendor
        // `app.css` carries `[hidden] { display: none !important }`. An inline
        // style cannot out-rank `!important` from a stylesheet, so
        // `style.display = 'inline-block'` was a no-op and the counter was
        // invisible no matter how many notifications were waiting.
        //
        // That is the whole of «nothing ever arrives in the dashboard»: the
        // alerts were arriving, unread, for weeks — 50 of them on the live
        // install — and the only thing that would have said so could not be
        // drawn. The dropdown behind it was filling correctly the entire time.
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.hidden = false;
        } else {
            badge.hidden = true;
        }

        list.querySelectorAll('.notification-item').forEach((el) => el.remove());

        if (!data.items || data.items.length === 0) {
            emptyItem.hidden = false;
            return;
        }

        emptyItem.hidden = true;

        data.items.forEach((item) => {
            const li = document.createElement('li');
            li.className = 'notification-item';

            const link = document.createElement('a');
            link.href = item.url || '#';
            link.className = 'dropdown-item' + (item.read ? '' : ' fw-bold');
            link.dataset.id = item.id;
            link.innerHTML = `
                <div class="small">${escapeHtml(item.title)}</div>
                <div class="text-muted" style="font-size:12px;">${escapeHtml(item.message)}</div>
                <div class="text-muted" style="font-size:11px;">${escapeHtml(item.created_at)}</div>
            `;

            link.addEventListener('click', function () {
                if (item.read || !readUrlTemplate) {
                    return;
                }

                fetch(readUrlTemplate.replace('__ID__', item.id), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                }).catch(() => {});
            });

            li.appendChild(link);
            list.appendChild(li);
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

    fetchUnread();
    setInterval(fetchUnread, 15000);
})();

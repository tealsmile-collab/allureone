/* AllureOne PWA service worker */
const STATIC_CACHE = 'allureone-static-v1';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    if (event.request.method !== 'GET') {
        return;
    }
    if (url.pathname.indexOf('/assets/') !== -1 || url.pathname.endsWith('.css') || url.pathname.endsWith('.png')) {
        event.respondWith(
            caches.open(STATIC_CACHE).then((cache) =>
                cache.match(event.request).then((cached) => {
                    if (cached) {
                        return cached;
                    }
                    return fetch(event.request).then((response) => {
                        if (response && response.status === 200) {
                            cache.put(event.request, response.clone());
                        }
                        return response;
                    });
                })
            )
        );
    }
});

function postAck(payload) {
    return fetch('pwa_push_ack_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        credentials: 'same-origin',
    }).catch(function () {});
}

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { body: event.data ? event.data.text() : '' };
    }
    const title = data.title || 'AllureOne';
    const body = data.body || '';
    const iconUrl = data.icon || 'assets/images/pwa-icon-192.png';
    const options = {
        body: body,
        icon: iconUrl,
        badge: iconUrl,
        tag: data.tag || ('announcement-' + (data.announcementId || '0')),
        data: {
            url: data.url || 'dashboard.php',
            announcementId: data.announcementId || 0,
            deliveryId: data.deliveryId || 0,
            ackToken: data.ackToken || '',
        },
    };
    event.waitUntil(
        self.registration.showNotification(title, options).then(function () {
            if (options.data.ackToken) {
                return postAck({
                    ack_token: options.data.ackToken,
                    event: 'delivered',
                });
            }
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const data = event.notification.data || {};
    if (data.ackToken) {
        postAck({ ack_token: data.ackToken, event: 'read' });
    }
    const targetPath = data.url || 'dashboard.php';
    const targetUrl = new URL(targetPath, self.location.origin).href;
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (let i = 0; i < clientList.length; i++) {
                const client = clientList[i];
                try {
                    if (new URL(client.url).pathname.endsWith(targetPath.replace(/^\.\//, '')) && 'focus' in client) {
                        return client.focus();
                    }
                } catch (e) {}
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(targetUrl);
            }
        })
    );
});

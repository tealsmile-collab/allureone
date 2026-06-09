/* global AllureOnePwa */
window.AllureOnePwa = (function () {
    function vapidKeyMeta() {
        var el = document.querySelector('meta[name="allureone-vapid-key"]');
        return el ? String(el.getAttribute('content') || '').trim() : '';
    }

    function urlBase64ToUint8Array(base64String) {
        var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var rawData = window.atob(base64);
        var outputArray = new Uint8Array(rawData.length);
        for (var i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) {
            return Promise.resolve(null);
        }
        return navigator.serviceWorker.register('sw.js', { scope: './' }).catch(function () {
            return null;
        });
    }

    function pushContentEncoding() {
        if ('PushManager' in window && PushManager.supportedContentEncodings && PushManager.supportedContentEncodings.length) {
            return PushManager.supportedContentEncodings[0];
        }
        return 'aes128gcm';
    }

    function subscribePush(csrf) {
        var publicKey = vapidKeyMeta();
        if (!publicKey || !('PushManager' in window) || !('Notification' in window)) {
            return Promise.resolve({ ok: false, skipped: true });
        }
        var contentEncoding = pushContentEncoding();
        return Notification.requestPermission().then(function (perm) {
            if (perm !== 'granted') {
                return { ok: false, permission: perm };
            }
            return navigator.serviceWorker.ready.then(function (reg) {
                return reg.pushManager.getSubscription().then(function (existing) {
                    if (existing) {
                        return existing;
                    }
                    return reg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlBase64ToUint8Array(publicKey),
                    });
                });
            }).then(function (subscription) {
                if (!subscription) {
                    return { ok: false };
                }
                var subJson = subscription.toJSON();
                subJson.contentEncoding = contentEncoding;
                return fetch('pwa_subscribe_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        _csrf: csrf,
                        action: 'subscribe',
                        subscription: subJson,
                    }),
                }).then(function (r) { return r.json(); });
            });
        });
    }

    function initPush(csrf) {
        registerServiceWorker().then(function () {
            subscribePush(csrf);
        });
    }

    return {
        registerServiceWorker: registerServiceWorker,
        initPush: initPush,
        subscribePush: subscribePush,
    };
})();

document.addEventListener('DOMContentLoaded', function () {
    window.AllureOnePwa.registerServiceWorker();
});

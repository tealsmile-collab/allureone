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

    function basePath() {
        var el = document.querySelector('meta[name="allureone-base"]');
        var base = el ? String(el.getAttribute('content') || '').trim() : '/';
        if (base === '') {
            base = '/';
        }
        if (base.charAt(base.length - 1) !== '/') {
            base += '/';
        }
        return base;
    }

    function appUrl(path) {
        var p = String(path || '').replace(/^\//, '');
        return basePath() + p;
    }

    function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) {
            return Promise.resolve(null);
        }
        var scope = basePath();
        return navigator.serviceWorker.register(appUrl('sw.js'), { scope: scope }).catch(function () {
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
                return fetch(appUrl('pwa_subscribe_api.php'), {
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

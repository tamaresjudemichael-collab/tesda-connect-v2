(() => {
    const script = document.currentScript;
    const workerUrl = script?.dataset?.workerUrl || './service-worker.js';
    const workerScope = script?.dataset?.workerScope || './';

    function showConnectivityNotice() {
        let notice = document.getElementById('tesda-connect-network-status');
        if (!notice) {
            notice = document.createElement('div');
            notice.id = 'tesda-connect-network-status';
            notice.setAttribute('role', 'status');
            notice.setAttribute('aria-live', 'polite');
            notice.style.cssText = 'position:fixed;z-index:99999;left:50%;bottom:16px;transform:translateX(-50%);width:max-content;max-width:calc(100vw - 32px);padding:12px 16px;border-radius:12px;font:600 13px/1.45 system-ui,sans-serif;box-shadow:0 8px 28px rgb(15 23 42 / 18%);display:none;text-align:center;';
            document.body.appendChild(notice);
        }

        if (!navigator.onLine) {
            notice.textContent = 'Offline: server actions and forms need a connection. Changes are not being queued.';
            notice.style.background = '#7f1d1d';
            notice.style.color = '#fff';
            notice.style.display = 'block';
        } else if (notice.style.display === 'block') {
            notice.textContent = 'Connection restored. You can continue using TESDA Connect.';
            notice.style.background = '#ecfdf5';
            notice.style.color = '#065f46';
            window.setTimeout(() => {
                if (navigator.onLine) notice.style.display = 'none';
            }, 3500);
        }
    }

    window.addEventListener('online', showConnectivityNotice);
    window.addEventListener('offline', showConnectivityNotice);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', showConnectivityNotice, { once: true });
    } else {
        showConnectivityNotice();
    }

    if ('serviceWorker' in navigator && window.isSecureContext) {
        navigator.serviceWorker.register(workerUrl, { scope: workerScope }).catch((error) => {
            // PWA installability must not break normal application use.
            console.warn('TESDA Connect service worker registration failed.', error);
        });
    }
})();

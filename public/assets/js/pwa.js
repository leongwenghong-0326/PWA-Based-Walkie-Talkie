(function () {
    'use strict';

    if (!('serviceWorker' in navigator)) {
        return;
    }

    let refreshing = false;
    navigator.serviceWorker.addEventListener('controllerchange', function () {
        if (refreshing) {
            return;
        }
        refreshing = true;
        window.location.reload();
    });

    window.addEventListener('load', function () {
        const manifest = document.querySelector('link[rel="manifest"]');
        if (!manifest) {
            return;
        }

        const swUrl = new URL('sw.js', manifest.href).href;
        const scope = new URL('./', manifest.href).href;

        navigator.serviceWorker.getRegistrations().then(function (regs) {
            return Promise.all(regs.map(function (reg) {
                return reg.update().catch(function () {});
            }));
        }).finally(function () {
            navigator.serviceWorker.register(swUrl, { scope: scope }).then(function (reg) {
                if (reg && reg.waiting) {
                    reg.waiting.postMessage({ type: 'SKIP_WAITING' });
                }
                if (reg) {
                    reg.addEventListener('updatefound', function () {
                        const worker = reg.installing;
                        if (!worker) {
                            return;
                        }
                        worker.addEventListener('statechange', function () {
                            if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                                worker.postMessage({ type: 'SKIP_WAITING' });
                            }
                        });
                    });
                }
            }).catch(function () {
                /* PWA install is optional during local HTTP testing. */
            });
        });
    });
})();

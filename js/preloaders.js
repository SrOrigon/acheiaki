/**
 * PedidoZap — Preloader da vitrine (compatível com carregamento dinâmico via store-loader).
 */
(function (global) {
    function hideSitePreloader() {
        const preloader = document.getElementById('preloader');
        if (!preloader || preloader.dataset.hidden === '1') return;

        preloader.dataset.hidden = '1';
        const minDisplayTime = 400;
        const startTime = preloader.dataset.startTime
            ? parseInt(preloader.dataset.startTime, 10)
            : Date.now();

        const runHide = () => {
            const elapsedTime = Date.now() - startTime;
            const remainingTime = Math.max(0, minDisplayTime - elapsedTime);

            setTimeout(() => {
                preloader.classList.add('fade-out');
                setTimeout(() => {
                    preloader.style.display = 'none';
                    if (document.body) document.body.style.overflow = 'auto';
                }, 800);
            }, remainingTime);
        };

        if (document.readyState === 'complete') {
            runHide();
        } else {
            global.addEventListener('load', runHide, { once: true });
            setTimeout(runHide, 3000);
        }
    }

    function init() {
        const preloader = document.getElementById('preloader');
        if (preloader && !preloader.dataset.startTime) {
            preloader.dataset.startTime = String(Date.now());
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', hideSitePreloader, { once: true });
        } else {
            hideSitePreloader();
        }
    }

    global.PedidoZapUI = global.PedidoZapUI || {};
    global.PedidoZapUI.hidePreloader = hideSitePreloader;

    init();
})(typeof window !== 'undefined' ? window : globalThis);

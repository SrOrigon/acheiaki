/**
 * PedidoZap — Preloader leve e fluido da vitrine (zero atraso artificial).
 */
(function (global) {
    function hideSitePreloader() {
        const preloader = document.getElementById('preloader');
        if (!preloader || preloader.dataset.hidden === '1') return;

        preloader.dataset.hidden = '1';
        try {
            document.documentElement.setAttribute('data-pz-ready', '1');
        } catch (e) { /* noop */ }

        preloader.classList.add('fade-out');
        setTimeout(() => {
            preloader.style.display = 'none';
            if (document.body) document.body.style.overflow = 'auto';
        }, 220);
    }

    function init() {
        const preloader = document.getElementById('preloader');
        if (preloader && !preloader.dataset.startTime) {
            preloader.dataset.startTime = String(Date.now());
        }
        // Failsafe caso algum recurso externo demore a responder
        setTimeout(hideSitePreloader, 4000);
    }

    global.PedidoZapUI = global.PedidoZapUI || {};
    global.PedidoZapUI.hidePreloader = hideSitePreloader;

    init();
})(typeof window !== 'undefined' ? window : globalThis);

/**
 * Inicialização segura da vitrine (evita tela presa no preloader).
 */
(function () {
    var scripts = ['js/ajuda-loja.js', 'js/preloaders.js', 'js/script.js', 'js/vitrine-agente.js'];
    var opts = { fallbackStatic: true, showLoading: true };
    var host = (location.hostname || '').toLowerCase();
    if (host.endsWith('.hostingersite.com') || host.endsWith('.hostinger.com') || host.endsWith('.up.railway.app') || host.endsWith('.railway.app')) {
        opts.fallbackStatic = false;
    }

    function run() {
        if (typeof PedidoZapStore !== 'undefined') {
            PedidoZapStore.bootstrap(scripts, opts);
            return;
        }
        console.error('PedidoZapStore não encontrado — usando fallback legado.');
        var v = Date.now();
        var chain = ['js/tenant.js', 'js/config.js', 'js/dados_cardapio.js'].concat(scripts);
        var i = 0;
        function next() {
            if (i >= chain.length) {
                var pre = document.getElementById('preloader');
                if (pre) pre.style.display = 'none';
                return;
            }
            var s = document.createElement('script');
            s.src = chain[i++] + '?v=' + v;
            s.onload = next;
            s.onerror = next;
            document.head.appendChild(s);
        }
        next();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();

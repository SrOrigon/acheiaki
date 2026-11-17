function configAjuda() {
    return (window.APP_CONFIG && APP_CONFIG.AJUDA) ? APP_CONFIG.AJUDA : {};
}

function mapaFerramentas(lista) {
    const mapa = {};
    (lista || []).forEach((item) => {
        if (item && item.id) mapa[item.id] = item;
    });
    return mapa;
}

function preencherExplicacaoFerramenta(id, mapa, tituloEl, textoEl) {
    const item = mapa[id];
    if (!item) return;
    if (tituloEl) tituloEl.textContent = item.titulo || '';
    if (textoEl) textoEl.textContent = item.texto || '';
}

function garantirElementosAjudaCardapio() {
    if (!document.querySelector('link[href*="ajuda-visivel"]')) {
        const l = document.createElement('link');
        l.rel = 'stylesheet';
        l.href = 'css/ajuda-visivel.css?v=2';
        document.head.appendChild(l);
    }
    const main = document.querySelector('.container-principal');
    if (main && !document.getElementById('secao-ajuda-cliente')) {
        const sec = document.createElement('section');
        sec.className = 'ajuda-cliente';
        sec.id = 'secao-ajuda-cliente';
        sec.setAttribute('aria-label', 'Como pedir');
        sec.innerHTML = '<h2 id="ajuda-cardapio-titulo"></h2><p class="intro" id="ajuda-cardapio-intro"></p>'
            + '<ol class="ajuda-passos-cliente" id="lista-ajuda-cardapio-passos"></ol>';
        main.insertBefore(sec, main.firstChild);
    }
    const carrinho = document.getElementById('carrinho-flutuante');
    if (carrinho && !document.getElementById('ajuda-carrinho-flutuante')) {
        const span = document.createElement('span');
        span.className = 'ajuda-carrinho-barra';
        span.id = 'ajuda-carrinho-flutuante';
        carrinho.insertBefore(span, carrinho.firstChild);
    }
    const modalCar = document.getElementById('modal-carrinho');
    if (modalCar && !document.getElementById('ajuda-modal-carrinho')) {
        const cab = modalCar.querySelector('.modal-cabecalho');
        const p = document.createElement('p');
        p.className = 'ajuda-modal-cliente';
        p.id = 'ajuda-modal-carrinho';
        p.style.margin = '12px 25px 0';
        if (cab && cab.nextSibling) modalCar.querySelector('.modal-conteudo').insertBefore(p, cab.nextSibling);
        else if (cab) cab.after(p);
    }
    const modalEnd = document.getElementById('modal-endereco');
    if (modalEnd && !document.getElementById('ajuda-modal-endereco')) {
        const corpo = modalEnd.querySelector('.modal-corpo-form');
        if (corpo) {
            const p = document.createElement('p');
            p.className = 'ajuda-modal-cliente';
            p.id = 'ajuda-modal-endereco';
            corpo.insertBefore(p, corpo.firstChild);
        }
    }
    const modalConf = document.getElementById('modal-confirmacao');
    if (modalConf && !document.getElementById('ajuda-checkout-final')) {
        const corpo = modalConf.querySelector('.modal-corpo-confirmacao');
        if (corpo) {
            const p1 = document.createElement('p');
            p1.className = 'ajuda-modal-cliente';
            p1.id = 'ajuda-checkout-final';
            corpo.insertBefore(p1, corpo.firstChild);
            const h4s = corpo.querySelectorAll('h4');
            h4s.forEach((h4) => {
                if (/receber/i.test(h4.textContent) && !document.getElementById('ajuda-checkout-frete')) {
                    const p2 = document.createElement('p');
                    p2.className = 'ajuda-modal-cliente';
                    p2.id = 'ajuda-checkout-frete';
                    h4.parentNode.insertBefore(p2, h4);
                }
            });
        }
    }
}

function aplicarAjudaCardapioCliente() {
    garantirElementosAjudaCardapio();
    const aj = configAjuda().cardapio || {};
    const sec = document.getElementById('secao-ajuda-cliente');
    if (sec) sec.hidden = aj.ativo === false;
    const tit = document.getElementById('ajuda-cardapio-titulo');
    const intro = document.getElementById('ajuda-cardapio-intro');
    if (tit && aj.titulo) tit.textContent = aj.titulo;
    if (intro && aj.intro) intro.textContent = aj.intro;
    const lista = document.getElementById('lista-ajuda-cardapio-passos');
    if (lista && Array.isArray(aj.passos)) {
        lista.innerHTML = aj.passos.map((p, i) => {
            const n = i + 1;
            return '<li><span class="num" aria-hidden="true">' + n + '</span><div><strong>'
                + String(p.titulo || '').replace(/</g, '&lt;') + '</strong><span>'
                + String(p.texto || '').replace(/</g, '&lt;') + '</span></div></li>';
        }).join('');
    }
    const cf = document.getElementById('ajuda-carrinho-flutuante');
    if (cf && aj.carrinho_flutuante) cf.textContent = aj.carrinho_flutuante;
    const mc = document.getElementById('ajuda-modal-carrinho');
    if (mc && aj.modal_carrinho) mc.textContent = aj.modal_carrinho;
    const me = document.getElementById('ajuda-modal-endereco');
    if (me && aj.modal_endereco) me.textContent = aj.modal_endereco;
    const ck = document.getElementById('ajuda-checkout-frete');
    if (ck && aj.checkout_frete) ck.textContent = aj.checkout_frete;
    const cfin = document.getElementById('ajuda-checkout-final');
    if (cfin && aj.checkout_final) cfin.textContent = aj.checkout_final;
}

function desenharCozinhaFichasAjuda() {
    const aj = (configAjuda().cozinha) || {};
    const wrap = document.getElementById('cozinha-fichas-ajuda');
    const tit = document.getElementById('cozinha-fichas-titulo');
    if (tit && aj.fichas_titulo) tit.textContent = aj.fichas_titulo;
    if (!wrap || !Array.isArray(aj.fichas)) return;
    const cores = { reimprimir: '#475569', pronto: '#f97316' };
    wrap.innerHTML = aj.fichas.map((f) => {
        const cor = cores[f.id] || '#64748b';
        return '<div class="ferramenta-linha"><span class="btn-ferramenta btn-ref" style="background:'
            + cor + ';color:#fff;"><i class="fa-solid fa-circle-info"></i> '
            + String(f.titulo || '').replace(/</g, '&lt;') + '</span><div class="ferramenta-explica"><strong>'
            + String(f.titulo || '').replace(/</g, '&lt;') + '</strong><span>'
            + String(f.texto || '').replace(/</g, '&lt;') + '</span></div></div>';
    }).join('');
}

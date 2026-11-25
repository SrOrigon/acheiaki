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
        sec.setAttribute('aria-label', 'Como comprar');
        sec.innerHTML = '<div class="aki-a11y-bar">'
            + '<button type="button" class="aki-a11y-btn" id="aki-btn-ouvir-ajuda" aria-label="Ouvir explicação passo a passo em voz alta">🔊 Ouvir explicação</button>'
            + '<button type="button" class="aki-a11y-btn" id="aki-btn-letras-grandes" aria-pressed="false">Aa Letras Maiores</button>'
            + '</div>'
            + '<h2 id="ajuda-cardapio-titulo"></h2><p class="intro" id="ajuda-cardapio-intro"></p>'
            + '<ol class="ajuda-passos-cliente" id="lista-ajuda-cardapio-passos"></ol>';
        main.insertBefore(sec, main.firstChild);
        if (typeof window.akiConectarAcessibilidade === 'function') {
            window.akiConectarAcessibilidade();
        }
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
    if (tit && aj.titulo) {
        tit.textContent = aj.titulo === 'Como pedir — é fácil' ? 'Como comprar — é fácil' : aj.titulo;
    }
    if (intro && aj.intro) intro.textContent = aj.intro;
    const lista = document.getElementById('lista-ajuda-cardapio-passos');
    if (lista && Array.isArray(aj.passos)) {
        lista.innerHTML = aj.passos.map((p, i) => {
            const n = i + 1;
            const titP = (p.titulo === '1. Toque no prato' || /\bprato\b/i.test(p.titulo || ''))
                ? '1. Escolha o produto'
                : (p.titulo || '');
            return '<li><span class="num" aria-hidden="true">' + n + '</span><div><strong>'
                + String(titP).replace(/</g, '&lt;') + '</strong><span>'
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

window.akiLerEmVozAlta = function (texto, btnEl) {
    if (typeof window === 'undefined' || !('speechSynthesis' in window)) return;
    if (window.speechSynthesis.speaking) {
        window.speechSynthesis.cancel();
        if (btnEl) btnEl.classList.remove('ativo');
        return;
    }
    const u = new SpeechSynthesisUtterance(String(texto || '').trim());
    u.lang = 'pt-BR';
    u.rate = 0.94;
    if (btnEl) {
        btnEl.classList.add('ativo');
        u.onend = () => btnEl.classList.remove('ativo');
        u.onerror = () => btnEl.classList.remove('ativo');
    }
    window.speechSynthesis.speak(u);
};

window.akiToggleModoSimples = function () {
    const ativo = !document.body.classList.contains('aki-a11y-simples');
    document.body.classList.toggle('aki-a11y-simples', ativo);
    try { localStorage.setItem('aki_modo_simples_a11y', ativo ? '1' : '0'); } catch (e) {}
    document.querySelectorAll('#aki-btn-letras-grandes, #aki-admin-letras-grandes').forEach((b) => {
        b.classList.toggle('ativo', ativo);
        b.setAttribute('aria-pressed', ativo ? 'true' : 'false');
    });
};

window.akiConectarAcessibilidade = function () {
    try {
        if (localStorage.getItem('aki_modo_simples_a11y') === '1') {
            document.body.classList.add('aki-a11y-simples');
        }
    } catch (e) {}
    const ativo = document.body.classList.contains('aki-a11y-simples');
    const bLetras = document.getElementById('aki-btn-letras-grandes');
    if (bLetras && !bLetras.dataset.bound) {
        bLetras.dataset.bound = '1';
        bLetras.classList.toggle('ativo', ativo);
        bLetras.setAttribute('aria-pressed', ativo ? 'true' : 'false');
        bLetras.addEventListener('click', window.akiToggleModoSimples);
    }
    const bOuvir = document.getElementById('aki-btn-ouvir-ajuda');
    if (bOuvir && !bOuvir.dataset.bound) {
        bOuvir.dataset.bound = '1';
        bOuvir.addEventListener('click', () => {
            const sec = document.getElementById('secao-ajuda-cliente');
            const texto = sec ? sec.innerText : 'Escolha o produto, toque em Ver Pedido, digite seu nome, WhatsApp e endereço, e envie seu pedido.';
            window.akiLerEmVozAlta(texto, bOuvir);
        });
    }
};

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        window.akiConectarAcessibilidade();
        const bAdmLetras = document.getElementById('aki-admin-letras-grandes');
        if (bAdmLetras) {
            const ativo = document.body.classList.contains('aki-a11y-simples');
            bAdmLetras.classList.toggle('ativo', ativo);
            bAdmLetras.addEventListener('click', window.akiToggleModoSimples);
        }
        const bAdmOuvir = document.getElementById('aki-admin-ouvir-passos');
        if (bAdmOuvir) {
            bAdmOuvir.addEventListener('click', () => {
                window.akiLerEmVozAlta('Bem-vindo ao painel fácil da sua loja. Use os 4 botões grandes na tela: 1, Ver pedidos novos. 2, Colocar fotos e preços dos produtos. 3, Conectar seu WhatsApp com robô automático. 4, Ver como sua loja aparece para o cliente.', bAdmOuvir);
            });
        }
    });
}

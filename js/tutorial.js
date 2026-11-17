function paginaUrl(page) {
    if (typeof TENANT !== 'undefined' && TENANT.apiUrl) return TENANT.apiUrl(page);
    return page;
}

function chaveProgresso() {
    return (typeof TENANT !== 'undefined' && TENANT.storageKey)
        ? TENANT.storageKey('tutorial_progress')
        : 'tutorial_progress';
}

function lerProgresso() {
    try {
        const raw = localStorage.getItem(chaveProgresso());
        return raw ? JSON.parse(raw) : {};
    } catch (e) {
        return {};
    }
}

function gravarProgresso(mapa) {
    try {
        localStorage.setItem(chaveProgresso(), JSON.stringify(mapa || {}));
    } catch (e) { /* quota */ }
}

function slugId(texto, fallback) {
    const base = String(texto || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .slice(0, 48);
    return base || fallback || 'passo';
}

function urlAdminAba(aba) {
    const base = paginaUrl('admin.html');
    const sep = base.includes('?') ? '&' : '?';
    return base + sep + 'tab=' + encodeURIComponent(aba);
}

function destinoPasso(passo) {
    const link = String(passo.link || '').trim();
    if (link) return paginaUrl(link);
    const aba = String(passo.aba || '').trim();
    if (aba) return urlAdminAba(aba);
    return paginaUrl('admin.html');
}

function embedYoutube(url) {
    const u = String(url || '').trim();
    if (!u) return '';
    const m = u.match(/(?:youtu\.be\/|v=|embed\/)([a-zA-Z0-9_-]{6,})/);
    return m ? 'https://www.youtube.com/embed/' + m[1] : '';
}

function escapar(s) {
  return String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/"/g, '&quot;');
}

async function iniciarTutorial() {
    if (!sessionStorage.getItem('admin_logado')) {
        window.location.href = paginaUrl('index.html');
        return;
    }

    try {
        if (typeof PedidoZapStore !== 'undefined') {
            await PedidoZapStore.load({ fallbackStatic: true, allowEmptyTenant: true });
        }
    } catch (err) {
        console.error('Tutorial: falha ao carregar loja', err);
        document.getElementById('tut-titulo').textContent = 'Não foi possível carregar o tutorial.';
        return;
    }

    const cfg = window.APP_CONFIG || {};
    const tut = cfg.TUTORIAL || {};
    const passos = Array.isArray(tut.passos) ? tut.passos : [];

    document.title = (tut.titulo || 'Primeiros passos') + ' — tutorial';
    const elTitulo = document.getElementById('tut-titulo');
    const elIntro = document.getElementById('tut-intro');
    if (elTitulo) elTitulo.textContent = tut.titulo || 'Primeiros passos';
    if (elIntro) elIntro.textContent = tut.intro || '';

    const linkAdmin = document.getElementById('link-voltar-admin');
    const linkPed = document.getElementById('link-pedidos');
    if (linkAdmin) linkAdmin.href = paginaUrl('admin.html');
    if (linkPed) linkPed.href = paginaUrl('painel.html');

    const btnIr = tut.btn_ir_passo || 'Ir fazer agora';
    const btnMarca = tut.btn_marcar || 'Marcar como feito';
    const btnDes = tut.btn_desmarcar || 'Desmarcar';
    const rotuloProg = tut.texto_progresso || 'concluídos';

    const embed = embedYoutube(tut.video_url);
    const wrapVid = document.getElementById('tut-video-wrap');
    const frame = document.getElementById('tut-video');
    if (embed && wrapVid && frame) {
        frame.src = embed;
        wrapVid.style.display = 'block';
    }

    const lista = document.getElementById('tut-lista');
    const vazio = document.getElementById('tut-vazio');

    function atualizarBarra() {
        const prog = lerProgresso();
        const total = passos.length;
        let feitos = 0;
        passos.forEach((p) => {
            const id = p.id || slugId(p.titulo, 'p');
            if (prog[id]) feitos += 1;
        });
        const pct = total ? Math.round((feitos / total) * 100) : 0;
        const barra = document.getElementById('tut-barra');
        const txt = document.getElementById('tut-progresso');
        if (barra) barra.style.width = pct + '%';
        if (txt) txt.textContent = feitos + ' de ' + total + ' ' + rotuloProg + ' (' + pct + '%)';
    }

    function redesenhar() {
        if (!lista) return;
        const prog = lerProgresso();
        if (!passos.length) {
            lista.innerHTML = '';
            if (vazio) vazio.hidden = false;
            atualizarBarra();
            return;
        }
        if (vazio) vazio.hidden = true;
        lista.innerHTML = passos.map((p, i) => {
            const id = p.id || slugId(p.titulo, 'passo-' + i);
            const feito = !!prog[id];
            const href = destinoPasso(p);
            return '<article class="passo' + (feito ? ' feito' : '') + '" data-id="' + escapar(id) + '">'
                + '<h2><span class="num">' + (i + 1) + '</span><span>' + escapar(p.titulo || 'Passo ' + (i + 1)) + '</span></h2>'
                + '<p style="margin:0;color:#4b5563;">' + escapar(p.texto || '') + '</p>'
                + '<div class="acoes">'
                + '<a class="btn btn-prim" href="' + escapar(href) + '">' + escapar(btnIr) + '</a>'
                + '<button type="button" class="btn ' + (feito ? 'btn-sec' : 'btn-ok') + '" data-toggle="' + escapar(id) + '">'
                + escapar(feito ? btnDes : btnMarca) + '</button>'
                + '</div></article>';
        }).join('');
        atualizarBarra();
    }

    redesenhar();

    if (lista) {
        lista.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-toggle]');
            if (!btn) return;
            const id = btn.dataset.toggle;
            const prog = lerProgresso();
            if (prog[id]) delete prog[id];
            else prog[id] = true;
            gravarProgresso(prog);
            redesenhar();
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciarTutorial);
} else {
    iniciarTutorial();
}

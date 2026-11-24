/**
 * Aplica config da loja no DOM: textos, cores, SEO, categorias e rodapé.
 */
(function (global) {
    const PLACEHOLDER = 'assets/images/placeholder-logo.svg';
    const HERO_PADRAO = 'assets/images/placeholder-hero.svg';

    function cfgOf(config) {
        return config || global.APP_CONFIG || {};
    }

    function texto(valor, fallback) {
        const v = valor == null ? '' : String(valor).trim();
        return v || fallback || '';
    }

    function setText(selector, value) {
        if (value == null) return;
        document.querySelectorAll(selector).forEach((el) => {
            el.textContent = value;
        });
    }

    function setMeta(selector, attr, value) {
        if (!value) return;
        const el = document.querySelector(selector);
        if (el) el.setAttribute(attr, value);
    }

    function paginaAtual() {
        const body = document.body;
        if (body && body.dataset.pagina) return body.dataset.pagina;
        const arquivo = (location.pathname.split('/').pop() || 'index.html').replace('.html', '');
        if (!arquivo || arquivo === 'index') return 'home';
        return arquivo;
    }

    function enderecoLinhas(config) {
        const end = config.ENDERECO || {};
        const linha1 = [end.rua, end.numero].filter(Boolean).join(', ');
        const cidade = [end.bairro, end.cidade].filter(Boolean).join(' - ');
        const linha2 = [cidade, end.estado, end.cep].filter(Boolean).join(' · ');
        return { linha1: linha1, linha2: linha2 };
    }

    function horarioTexto(config) {
        const h = config.HORARIOS || {};
        if (h.TEXTO) return { principal: h.TEXTO, folga: h.TEXTO_FOLGA || '' };
        const semana = h.SEMANA || {};
        const fds = h.FIM_DE_SEMANA || {};
        const principal = 'Seg a Qui: ' + (semana.inicio || '--') + ' – ' + (semana.fim || '--')
            + ' · Sex a Dom: ' + (fds.inicio || '--') + ' – ' + (fds.fim || '--');
        const nomes = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
        const fechados = (h.FECHADO || []).map((d) => nomes[Number(d)]).filter(Boolean);
        return {
            principal: principal,
            folga: fechados.length ? 'Fechado: ' + fechados.join(', ') : (h.TEXTO_FOLGA || '')
        };
    }

    function aplicarCores(config) {
        const cores = (config.IDENTIDADE && config.IDENTIDADE.CORES) || {};
        const primaria = cores.primaria || '#f97136';
        const secundaria = cores.secundaria || '#1f2937';
        const fundo = cores.fundo || '#f9fafb';
        const root = document.documentElement;
        root.style.setProperty('--pz-primaria', primaria);
        root.style.setProperty('--pz-secundaria', secundaria);
        root.style.setProperty('--pz-fundo', fundo);
        root.style.setProperty('--primary', primaria);
        root.style.setProperty('--primary-dark', secundaria);
        setMeta('meta[name="theme-color"]', 'content', primaria);
    }

    function aplicarImagens(config) {
        const id = config.IDENTIDADE || {};
        const logo = id.LOGO || PLACEHOLDER;
        const favicon = id.FAVICON || logo;
        const heroPadrao = id.HERO || HERO_PADRAO;
        const pagina = paginaAtual();
        const paginaCfg = ((config.TEXTOS || {}).PAGINAS || {})[pagina] || {};
        const hero = paginaCfg.hero || heroPadrao;

        document.querySelectorAll('[data-bind="logo"]').forEach((img) => {
            img.src = logo;
            img.alt = (config.NOME_LOJA || 'Logo') + ' — logo';
        });
        document.querySelectorAll('link[rel="icon"]').forEach((link) => {
            link.href = favicon;
        });
        document.querySelectorAll('[data-bind="hero"]').forEach((img) => {
            img.src = hero;
            img.alt = config.NOME_LOJA || 'Capa da loja';
        });
    }

    function aplicarSeo(config, cardapio) {
        const nome = texto(config.NOME_LOJA, 'Nome da Sua Loja');
        const descricao = texto(config.DESCRICAO_SEO, config.SLOGAN || nome);
        const pagina = paginaAtual();
        const paginaCfg = ((config.TEXTOS || {}).PAGINAS || {})[pagina] || {};
        const tituloPagina = pagina === 'home'
            ? nome
            : texto(paginaCfg.titulo, nome);
        document.title = pagina === 'home' ? nome : (tituloPagina + ' | ' + nome);

        setMeta('meta[name="description"]', 'content', descricao);
        setMeta('meta[name="keywords"]', 'content', config.PALAVRAS_CHAVE || '');
        setMeta('meta[name="author"]', 'content', nome);
        setMeta('meta[property="og:title"]', 'content', document.title);
        setMeta('meta[property="og:description"]', 'content', descricao);
        setMeta('meta[property="og:site_name"]', 'content', nome);
        setMeta('meta[name="twitter:title"]', 'content', document.title);
        setMeta('meta[name="twitter:description"]', 'content', descricao);

        const logo = (config.IDENTIDADE && config.IDENTIDADE.LOGO) || '';
        if (logo && !logo.startsWith('data:')) {
            const abs = new URL(logo, location.href).href;
            setMeta('meta[property="og:image"]', 'content', abs);
            setMeta('meta[name="twitter:image"]', 'content', abs);
        }

        const end = config.ENDERECO || {};
        const sameAs = [config.REDES && config.REDES.instagram, config.REDES && config.REDES.facebook].filter(Boolean);
        if (config.WHATSAPP_NUMBER) {
            sameAs.push('https://wa.me/' + String(config.WHATSAPP_NUMBER).replace(/\D/g, ''));
        }

        const menu = { '@type': 'ItemList', name: 'Catálogo', itemListElement: [] };
        Object.keys(cardapio || {}).forEach((chave) => {
            const itens = cardapio[chave];
            if (!Array.isArray(itens) || !itens.length) return;
            itens.filter((item) => item && item.ativo !== false).slice(0, 30).forEach((item) => {
                menu.itemListElement.push({
                    '@type': 'Product',
                    name: item.nome,
                    description: item.descricao || '',
                    offers: {
                        '@type': 'Offer',
                        price: Number(item.preco || 0).toFixed(2),
                        priceCurrency: 'BRL'
                    }
                });
            });
        });

        const nichoLd = String(config.NICHO || '').toLowerCase();
        const ehFoodLd = ['lanchonete', 'pizzaria', 'marmitaria', 'pensao', 'acai', 'confeitaria'].indexOf(nichoLd) !== -1;
        const ld = {
            '@context': 'https://schema.org',
            '@type': ehFoodLd ? 'Restaurant' : 'LocalBusiness',
            name: nome,
            description: descricao,
            url: location.origin + location.pathname,
            telephone: config.WHATSAPP_NUMBER ? '+' + String(config.WHATSAPP_NUMBER).replace(/\D/g, '') : undefined,
            image: (config.IDENTIDADE && config.IDENTIDADE.HERO) || undefined,
            logo: (config.IDENTIDADE && config.IDENTIDADE.LOGO) || undefined,
            address: {
                '@type': 'PostalAddress',
                streetAddress: [end.rua, end.numero].filter(Boolean).join(', '),
                addressLocality: end.cidade || '',
                addressRegion: end.estado || '',
                postalCode: end.cep || '',
                addressCountry: 'BR'
            },
            sameAs: sameAs,
            mainEntity: menu
        };

        let node = document.getElementById('seo-jsonld');
        if (!node) {
            node = document.createElement('script');
            node.id = 'seo-jsonld';
            node.type = 'application/ld+json';
            document.head.appendChild(node);
        }
        node.textContent = JSON.stringify(ld);
    }

    function aplicarTextos(config) {
        const nome = texto(config.NOME_LOJA, 'Nome da Sua Loja');
        const textos = config.TEXTOS || {};
        const pagina = paginaAtual();
        const paginaCfg = (textos.PAGINAS || {})[pagina] || {};

        setText('[data-bind="nome"]', nome);
        setText('[data-bind="slogan"]', config.SLOGAN || '');

        const heroTitulo = pagina === 'home'
            ? texto(textos.HERO_TITULO, 'Bem-vindo')
            : texto(paginaCfg.titulo, textos.HERO_TITULO);
        const heroSub = pagina === 'home'
            ? texto(textos.HERO_SUBTITULO, '')
            : texto(paginaCfg.subtitulo, textos.HERO_SUBTITULO);
        setText('[data-bind="hero-titulo"]', heroTitulo);
        setText('[data-bind="hero-subtitulo"]', heroSub);
        setText('[data-bind="secao-ofertas"]', textos.SECAO_OFERTAS || 'Ofertas do Dia');
        setText('[data-bind="secao-destaques"]', textos.SECAO_DESTAQUES || 'Destaques');
        const secCardBruto = String(textos.SECAO_CARDAPIO || '').trim();
        const secCardFinal = (!secCardBruto || /^explore nosso card[aá]pio$/i.test(secCardBruto) || /^card[aá]pio$/i.test(secCardBruto))
            ? 'Explore nosso Catálogo'
            : secCardBruto;
        setText('[data-bind="secao-cardapio"]', secCardFinal);
        setText('[data-bind="secao-avaliacoes"]', textos.SECAO_AVALIACOES || 'Avaliações');

        const banner = config.BANNER_WPP || {};
        const blocoBanner = document.querySelector('.banner-wpp-ofertas');
        if (blocoBanner) blocoBanner.style.display = banner.ativo ? '' : 'none';
        setText('[data-bind="banner-titulo"]', banner.titulo || '');
        setText('[data-bind="banner-mensagem"]', banner.mensagem || '');
        setText('[data-bind="banner-botao"]', banner.botao || 'Pedir no Zap');

        const upsell = config.UPSELL || {};
        const modalUpsell = document.getElementById('modal-upsell');
        if (modalUpsell) modalUpsell.dataset.ativo = upsell.ativo ? '1' : '0';
        setText('[data-bind="upsell-titulo"]', upsell.titulo || '');
        setText('[data-bind="upsell-subtitulo"]', upsell.subtitulo || '');
        const imgUpsell = document.querySelector('[data-bind="upsell-imagem"]');
        if (imgUpsell) {
            imgUpsell.src = upsell.imagem || (config.IDENTIDADE && config.IDENTIDADE.LOGO) || PLACEHOLDER;
        }

        const checkout = config.CHECKOUT || {};
        setText('[data-bind="aviso-observacoes"]', checkout.aviso_observacoes || '');
        setText('[data-bind="aviso-agendamento"]', checkout.agendamento_texto || '');

        document.querySelectorAll('.tempo-espera-display').forEach((el) => {
            el.textContent = config.TEMPO_ESPERA || '';
        });
    }

    function telefoneLegivel(config) {
        const redes = config.REDES || {};
        const bruto = redes.whatsapp || config.WHATSAPP_NUMBER || '';
        let digitos = String(bruto).replace(/\D/g, '');
        if (digitos.length > 11 && digitos.indexOf('55') === 0) digitos = digitos.slice(2);
        if (digitos.length === 11) return '(' + digitos.slice(0, 2) + ') ' + digitos.slice(2, 7) + '-' + digitos.slice(7);
        if (digitos.length === 10) return '(' + digitos.slice(0, 2) + ') ' + digitos.slice(2, 6) + '-' + digitos.slice(6);
        return '';
    }

    function aplicarContato(config) {
        const end = enderecoLinhas(config);
        setText('[data-bind="endereco-1"]', end.linha1);
        setText('[data-bind="endereco-2"]', end.linha2);
        document.querySelectorAll('[data-bind="endereco-1"]').forEach((el) => {
            el.style.display = end.linha1 ? '' : 'none';
        });
        document.querySelectorAll('[data-bind="endereco-2"]').forEach((el) => {
            el.style.display = end.linha2 ? '' : 'none';
        });
        const rodape = config.RODAPE || {};
        setText('[data-bind="rodape-titulo-horario"]', rodape.titulo_horario || 'Horário');
        setText('[data-bind="rodape-titulo-contato"]', rodape.titulo_contato || 'Fale com a gente');
        setText('[data-bind="rodape-frase"]', rodape.frase || '');
        document.querySelectorAll('[data-bind="rodape-frase"]').forEach((el) => {
            el.style.display = rodape.frase ? '' : 'none';
        });
        const fone = telefoneLegivel(config);
        setText('[data-bind="rodape-telefone"]', fone);
        document.querySelectorAll('[data-bind="rodape-telefone"]').forEach((el) => {
            el.style.display = fone ? '' : 'none';
        });
        const horas = horarioTexto(config);
        setText('[data-bind="horario"]', horas.principal);
        setText('[data-bind="horario-folga"]', horas.folga);
        document.querySelectorAll('[data-bind="horario-folga"]').forEach((el) => {
            el.style.display = horas.folga ? '' : 'none';
        });

        const redes = config.REDES || {};
        const mapa = {
            instagram: redes.instagram,
            facebook: redes.facebook,
            whatsapp: redes.whatsapp || (config.WHATSAPP_NUMBER ? 'https://wa.me/' + String(config.WHATSAPP_NUMBER).replace(/\D/g, '') : '')
        };
        Object.keys(mapa).forEach((rede) => {
            document.querySelectorAll('[data-rede="' + rede + '"]').forEach((link) => {
                if (!mapa[rede]) {
                    link.style.display = 'none';
                    link.removeAttribute('href');
                } else {
                    link.style.display = '';
                    link.href = mapa[rede];
                }
            });
        });
    }

    const vitrineRepState = {
        tenant: '',
        nota: 5,
        filtro: 'todas',
        resumo: null,
        avaliacoes: [],
        legacy: [],
        modalBound: false,
        cellBound: false,
    };

    function apiMarketplaceBase() {
        return location.pathname.indexOf('/loja/') !== -1 ? '../../marketplace_api.php' : 'marketplace_api.php';
    }

    function tenantSlugVitrine(config) {
        const cfg = cfgOf(config);
        const query = new URLSearchParams(location.search || '');
        const q = (query.get('tenant') || query.get('loja') || '').trim();
        if (q) return q;
        const m = location.pathname.match(/\/loja\/([^/?#]+)/i);
        if (m && m[1]) return decodeURIComponent(m[1]);
        return String(cfg.TENANT_SLUG || cfg.SLUG || 'default').trim() || 'default';
    }

    function estrelasHtmlVitrine(nota) {
        const n = Math.max(0, Math.min(5, Math.round(Number(nota || 0))));
        let html = '';
        for (let i = 1; i <= 5; i += 1) {
            html += i <= n ? '★' : '☆';
        }
        return html;
    }

    function filtrarAvaliacoesVitrine(lista) {
        const filtro = vitrineRepState.filtro || 'todas';
        if (filtro === '5') return lista.filter((a) => Number(a.nota || 0) === 5);
        if (filtro === '4') return lista.filter((a) => Number(a.nota || 0) === 4);
        if (filtro === '3menos') return lista.filter((a) => Number(a.nota || 0) <= 3);
        if (filtro === 'respondidas') return lista.filter((a) => String(a.resposta_loja || '').trim() !== '');
        return lista;
    }

    function renderizarVitrineAvaliacoes() {
        const boxResumo = document.getElementById('pz-vitrine-rep-resumo');
        const lista = document.getElementById('lista-avaliacoes');
        const secao = document.getElementById('avaliacoes');
        if (secao) secao.style.display = '';

        const itensFonte = vitrineRepState.avaliacoes.length ? vitrineRepState.avaliacoes : vitrineRepState.legacy;
        const total = vitrineRepState.resumo && vitrineRepState.resumo.total != null
            ? Number(vitrineRepState.resumo.total)
            : itensFonte.length;
        const mediaBruta = vitrineRepState.resumo
            ? (vitrineRepState.resumo.nota_media != null ? vitrineRepState.resumo.nota_media : vitrineRepState.resumo.media)
            : null;
        const media = mediaBruta != null
            ? Number(mediaBruta)
            : (itensFonte.length
                ? itensFonte.reduce((acc, it) => acc + Number(it.nota || 5), 0) / itensFonte.length
                : 0);
        const pct = vitrineRepState.resumo && vitrineRepState.resumo.percentuais ? vitrineRepState.resumo.percentuais : {};
        const dist = vitrineRepState.resumo && vitrineRepState.resumo.distribuicao ? vitrineRepState.resumo.distribuicao : {};
        const criterios = vitrineRepState.resumo && vitrineRepState.resumo.criterios ? vitrineRepState.resumo.criterios : {};
        const topTags = vitrineRepState.resumo && Array.isArray(vitrineRepState.resumo.top_tags)
            ? vitrineRepState.resumo.top_tags
            : [];

        if (boxResumo) {
            if (!total) {
                boxResumo.innerHTML = '<div class="pz-vrep-vazio">'
                    + '<strong>Ainda sem avaliações públicas</strong>'
                    + '<span>Seja o primeiro cliente a contar como foi sua experiência com este estabelecimento.</span>'
                    + '</div>';
            } else {
                const barras = [5, 4, 3, 2, 1].map((s) => {
                    const p = Number(pct[String(s)] || 0);
                    const q = Number(dist[String(s)] || 0);
                    return '<div class="pz-vrep-bar-row">'
                        + '<span>' + s + '★</span>'
                        + '<div class="pz-vrep-track"><div class="pz-vrep-fill" style="width:' + p + '%"></div></div>'
                        + '<span>' + q + '</span>'
                        + '</div>';
                }).join('');

                const critHtml = [
                    criterios.comida ? '<span class="pz-vrep-tag">🍽️ Comida ' + Number(criterios.comida).toFixed(1) + '</span>' : '',
                    criterios.entrega ? '<span class="pz-vrep-tag">🛵 Entrega ' + Number(criterios.entrega).toFixed(1) + '</span>' : '',
                    criterios.custo ? '<span class="pz-vrep-tag">💰 Preço ' + Number(criterios.custo).toFixed(1) + '</span>' : '',
                ].filter(Boolean).join('');

                const tagsHtml = topTags.map((t) => '<span class="pz-vrep-tag">#' + escapeHtml(t.tag || '') + ' (' + Number(t.qtd || 0) + ')</span>').join('');

                const filtros = [
                    { id: 'todas', label: 'Todas (' + itensFonte.length + ')' },
                    { id: '5', label: '5 ★' },
                    { id: '4', label: '4 ★' },
                    { id: '3menos', label: '3 ★ ou menos' },
                    { id: 'respondidas', label: 'Respondidas' },
                ];
                const filtrosHtml = filtros.map((f) => {
                    const active = vitrineRepState.filtro === f.id ? ' ativo' : '';
                    return '<button type="button" class="pz-vrep-filtro-btn' + active + '" data-vrep-filtro="' + f.id + '">' + f.label + '</button>';
                }).join('');

                boxResumo.innerHTML = '<div class="pz-vrep-box">'
                    + '<div class="pz-vrep-overview">'
                    + '<div class="pz-vrep-score">'
                    + '<strong>' + media.toFixed(1) + '</strong>'
                    + '<div class="pz-vrep-score-stars">' + estrelasHtmlVitrine(Math.round(media)) + '</div>'
                    + '<small>' + total + (total === 1 ? ' avaliação de cliente' : ' avaliações de clientes') + '</small>'
                    + '</div>'
                    + '<div class="pz-vrep-bars">' + barras + '</div>'
                    + '</div>'
                    + ((critHtml || tagsHtml) ? '<div class="pz-vrep-tags">' + critHtml + tagsHtml + '</div>' : '')
                    + '<div class="pz-vrep-filtros">' + filtrosHtml + '</div>'
                    + '</div>';

                boxResumo.querySelectorAll('[data-vrep-filtro]').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        vitrineRepState.filtro = btn.getAttribute('data-vrep-filtro') || 'todas';
                        renderizarVitrineAvaliacoes();
                    });
                });
            }
        }

        if (!lista) return;
        const filtradas = filtrarAvaliacoesVitrine(itensFonte);
        if (!filtradas.length) {
            lista.innerHTML = '<div class="card-avaliacao pz-vrep-empty-card">'
                + '<p>' + (itensFonte.length ? 'Nenhuma avaliação encontrada para este filtro.' : 'Ainda não há comentários registrados nesta loja. Use o botão "Avaliar estabelecimento" para compartilhar sua opinião!') + '</p>'
                + '</div>';
            return;
        }

        lista.innerHTML = filtradas.map((item) => {
            const foto = item.foto || PLACEHOLDER;
            const nome = item.cliente_nome || item.autor || item.nome || 'Cliente';
            const nota = Number(item.nota || 5);
            const dataFmt = item.criado_em ? String(item.criado_em).slice(0, 10).split('-').reverse().join('/') : '';
            const verif = (item.verificada || item.compra_verificada) ? '<span class="pz-vrep-verif">✓ Compra verificada</span>' : '';
            const listaTags = Array.isArray(item.melhorias_tags) ? item.melhorias_tags : (Array.isArray(item.tags) ? item.tags : []);
            const tagsItem = listaTags.length
                ? '<div class="pz-vrep-tags" style="margin:6px 0;">' + listaTags.map((tg) => '<span class="pz-vrep-tag">' + escapeHtml(tg) + '</span>').join('') + '</div>'
                : '';
            const respLoja = String(item.resposta_loja || '').trim()
                ? '<div class="pz-vrep-reply"><strong>Resposta do estabelecimento</strong><p style="margin:4px 0 0;">' + escapeHtml(item.resposta_loja) + '</p></div>'
                : '';
            const coment = item.comentario || item.texto || '';
            return '<div class="card-avaliacao">'
                + '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">'
                + '<span style="color:#f59e0b;font-weight:800;" aria-label="' + nota + ' de 5 estrelas">' + estrelasHtmlVitrine(nota) + ' <b>' + nota.toFixed(1) + '</b></span>'
                + verif
                + (dataFmt ? '<small style="color:#64748b;margin-left:auto;">' + escapeHtml(dataFmt) + '</small>' : '')
                + '</div>'
                + (coment ? '<p>' + escapeHtml(coment) + '</p>' : '<p style="color:#64748b;font-style:italic;">Avaliação por estrelas sem comentário.</p>')
                + tagsItem
                + '<div class="autor-avaliacao"><img src="' + escapeAttr(foto) + '" alt="Foto de ' + escapeAttr(nome) + '"><span>- ' + escapeHtml(nome) + '</span></div>'
                + respLoja
                + '</div>';
        }).join('');
    }

    async function carregarAvaliacoesPublicasVitrine(tenant) {
        const cacheKey = 'pz_offline_reviews_' + tenant;
        try {
            const cached = localStorage.getItem(cacheKey);
            if (cached) {
                const parsed = JSON.parse(cached);
                if (parsed && Array.isArray(parsed.avaliacoes)) {
                    vitrineRepState.resumo = parsed.resumo || null;
                    vitrineRepState.avaliacoes = parsed.avaliacoes;
                    renderizarVitrineAvaliacoes();
                }
            }
        } catch (e) { /* sem cache */ }

        try {
            const res = await fetch(apiMarketplaceBase() + '?action=avaliacoes_loja&tenant=' + encodeURIComponent(tenant));
            const data = await res.json();
            if (data && (data.success || data.ok)) {
                vitrineRepState.resumo = data.resumo || null;
                vitrineRepState.avaliacoes = Array.isArray(data.avaliacoes) ? data.avaliacoes : [];
                try {
                    localStorage.setItem(cacheKey, JSON.stringify({
                        resumo: vitrineRepState.resumo,
                        avaliacoes: vitrineRepState.avaliacoes,
                        savedAt: Date.now(),
                    }));
                } catch (e) { /* cota */ }
                renderizarVitrineAvaliacoes();
            }
        } catch (e) { /* offline usa cache local */ }
    }

    function vincularModalAvaliacaoVitrine() {
        if (vitrineRepState.modalBound) return;
        vitrineRepState.modalBound = true;

        const modal = document.getElementById('modal-avaliar-vitrine');
        const btnAbrir = document.getElementById('btn-abrir-avaliar-vitrine');
        const btnFechar = document.getElementById('btn-fechar-avaliar-vitrine');
        const form = document.getElementById('form-avaliar-vitrine');
        const starsWrap = document.getElementById('v-av-stars');
        const inputNota = document.getElementById('v-av-nota');
        const lblNota = document.getElementById('v-av-nota-lbl');
        const tagsWrap = document.getElementById('v-av-tags');
        const statusEl = document.getElementById('v-av-msg');
        const rotulosNota = { 1: '1/5 · Ruim', 2: '2/5 · Regular', 3: '3/5 · Bom', 4: '4/5 · Muito bom', 5: '5/5 · Excelente' };

        function pintarEstrelas(val) {
            vitrineRepState.nota = Math.max(1, Math.min(5, Number(val || 5)));
            if (inputNota) inputNota.value = String(vitrineRepState.nota);
            if (lblNota) lblNota.textContent = rotulosNota[vitrineRepState.nota] || (vitrineRepState.nota + '/5');
            if (!starsWrap) return;
            starsWrap.querySelectorAll('[data-v-star]').forEach((btn) => {
                const s = Number(btn.getAttribute('data-v-star') || 0);
                btn.classList.toggle('ativo', s <= vitrineRepState.nota);
                btn.textContent = s <= vitrineRepState.nota ? '★' : '☆';
            });
        }

        if (starsWrap) {
            pintarEstrelas(5);
            starsWrap.addEventListener('click', (ev) => {
                const btn = ev.target.closest('[data-v-star]');
                if (!btn) return;
                pintarEstrelas(btn.getAttribute('data-v-star'));
            });
        }

        if (tagsWrap) {
            tagsWrap.addEventListener('click', (ev) => {
                const chip = ev.target.closest('[data-v-tag]');
                if (!chip) return;
                chip.classList.toggle('ativo');
            });
        }

        function fechar() {
            if (modal) modal.classList.add('escondido');
        }

        if (btnAbrir && modal) {
            btnAbrir.addEventListener('click', () => {
                if (statusEl) {
                    statusEl.textContent = '';
                    statusEl.style.color = '#334155';
                }
                pintarEstrelas(5);
                modal.classList.remove('escondido');
            });
        }

        if (btnFechar) btnFechar.addEventListener('click', fechar);
        if (modal) {
            modal.addEventListener('click', (ev) => {
                if (ev.target === modal) fechar();
            });
        }

        if (form) {
            form.addEventListener('submit', async (ev) => {
                ev.preventDefault();
                const btnEnviar = form.querySelector('button[type="submit"]');
                const autor = (document.getElementById('v-av-nome') || {}).value || '';
                const comentario = (document.getElementById('v-av-comentario') || {}).value || '';
                const comida = Number((document.getElementById('v-av-comida') || {}).value || vitrineRepState.nota);
                const entrega = Number((document.getElementById('v-av-entrega') || {}).value || vitrineRepState.nota);
                const custo = Number((document.getElementById('v-av-custo') || {}).value || vitrineRepState.nota);
                const tags = tagsWrap
                    ? Array.from(tagsWrap.querySelectorAll('[data-v-tag].ativo')).map((el) => el.getAttribute('data-v-tag')).filter(Boolean)
                    : [];

                if (btnEnviar) btnEnviar.disabled = true;
                if (statusEl) {
                    statusEl.textContent = 'Publicando sua avaliação...';
                    statusEl.style.color = '#334155';
                }

                try {
                    const res = await fetch(apiMarketplaceBase() + '?action=avaliar', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            tenant: vitrineRepState.tenant || 'default',
                            nota: vitrineRepState.nota,
                            nota_comida: comida,
                            nota_entrega: entrega,
                            nota_custo: custo,
                            melhorias_tags: tags,
                            cliente_nome: autor.trim(),
                            comentario: comentario.trim(),
                            origem: 'vitrine_publica',
                        }),
                    });
                    const data = await res.json();
                    if (!res.ok || !data || (!data.success && !data.ok)) {
                        throw new Error((data && (data.message || data.erro)) || 'Não foi possível enviar a avaliação.');
                    }
                    if (data.resumo) vitrineRepState.resumo = data.resumo;
                    if (data.avaliacao) {
                        vitrineRepState.avaliacoes = [data.avaliacao].concat(vitrineRepState.avaliacoes);
                    }
                    renderizarVitrineAvaliacoes();
                    carregarAvaliacoesPublicasVitrine(vitrineRepState.tenant);
                    if (global.PedidoZapCelulas && typeof global.PedidoZapCelulas.emitir === 'function') {
                        global.PedidoZapCelulas.emitir('avaliacao_publicada', {
                            tenant: vitrineRepState.tenant,
                            nota: vitrineRepState.nota,
                        });
                    }
                    form.reset();
                    if (tagsWrap) tagsWrap.querySelectorAll('[data-v-tag]').forEach((t) => t.classList.remove('ativo'));
                    pintarEstrelas(5);
                    if (statusEl) {
                        statusEl.textContent = 'Obrigado! Sua avaliação já está visível para todos.';
                        statusEl.style.color = '#059669';
                    }
                    setTimeout(() => {
                        fechar();
                    }, 900);
                } catch (err) {
                    if (statusEl) {
                        statusEl.textContent = (err && err.message) || 'Sem conexão no momento. Tente novamente quando estiver online.';
                        statusEl.style.color = '#dc2626';
                    }
                } finally {
                    if (btnEnviar) btnEnviar.disabled = false;
                }
            });
        }
    }

    function aplicarAvaliacoes(config) {
        const itens = Array.isArray(config.AVALIACOES)
            ? config.AVALIACOES.filter((a) => a && (a.texto || a.nome || a.comentario))
            : [];
        vitrineRepState.legacy = itens.map((it) => ({
            cliente_nome: it.nome || it.cliente_nome || it.autor || 'Cliente',
            nota: Number(it.nota || 5),
            comentario: it.texto || it.comentario || '',
            foto: it.foto || PLACEHOLDER,
            criado_em: it.criado_em || '',
            verificada: Boolean(it.verificada || it.compra_verificada),
            melhorias_tags: Array.isArray(it.melhorias_tags) ? it.melhorias_tags : (Array.isArray(it.tags) ? it.tags : []),
            resposta_loja: it.resposta_loja || '',
        }));

        const tenant = tenantSlugVitrine(config);
        vitrineRepState.tenant = tenant;
        renderizarVitrineAvaliacoes();
        vincularModalAvaliacaoVitrine();
        carregarAvaliacoesPublicasVitrine(tenant);

        if (!vitrineRepState.cellBound && global.PedidoZapCelulas && typeof global.PedidoZapCelulas.escutar === 'function') {
            vitrineRepState.cellBound = true;
            global.PedidoZapCelulas.escutar((ev) => {
                if (!ev || ev.tipo !== 'avaliacao_publicada') return;
                const d = ev.dados || ev.payload || {};
                if (!d.tenant || d.tenant === vitrineRepState.tenant) {
                    carregarAvaliacoesPublicasVitrine(vitrineRepState.tenant);
                }
            });
        }
    }

    function aplicarPagamentos(config) {
        const pag = config.PAGAMENTOS || {};
        const mapa = { PIX: 'pix', Débito: 'debito', Crédito: 'credito', Dinheiro: 'dinheiro' };
        document.querySelectorAll('[data-pagamento]').forEach((el) => {
            const chave = el.dataset.pagamento;
            const flag = pag[chave];
            const label = el.closest('.radio-pagamento') || el;
            label.style.display = flag === false ? 'none' : '';
        });
        Object.keys(mapa).forEach((valor) => {
            const input = document.querySelector('input[name="forma-pagamento"][value="' + valor + '"]');
            if (!input) return;
            const ativo = pag[mapa[valor]] !== false;
            const label = input.closest('.radio-pagamento');
            if (label) label.style.display = ativo ? '' : 'none';
            if (!ativo) input.checked = false;
        });
        const pix = document.querySelector('[data-bind="chave-pix"]');
        if (pix) {
            pix.textContent = pag.chave_pix ? 'Chave PIX: ' + pag.chave_pix : '';
            pix.style.display = pag.pix !== false && pag.chave_pix ? '' : 'none';
        }
    }

    function urlLoja(arquivo) {
        let base = (global.TENANT && typeof global.TENANT.apiUrl === 'function')
            ? global.TENANT.apiUrl(arquivo)
            : arquivo;
        if (global.TENANT && global.TENANT.slug === 'default' && /^\/?index\.html(?:#|$)/i.test(base)) {
            const partes = base.split('#');
            base = partes[0] + '?tenant=default' + (partes[1] ? '#' + partes[1] : '');
        }
        return base;
    }

    function categoriasVisiveis(config, cardapio) {
        const metas = config.CATEGORIAS || {};
        const menu = cardapio || {};
        return Object.keys(metas).filter((chave) => chave !== 'promocoes').map((chave) => {
            const meta = metas[chave] || {};
            const ativos = (menu[chave] || []).filter((item) => item && item.ativo !== false);
            const visivel = meta.ativo !== false && ativos.length > 0;
            return Object.assign({ slug: chave, visivel: visivel }, meta);
        }).filter((cat) => cat.visivel);
    }

    function linkPromocoesVisivel(config, cardapio) {
        const meta = (config.CATEGORIAS || {}).promocoes || {};
        if (meta.ativo === false) return false;
        const promos = ((cardapio || {}).promocoes || []).filter((p) => p && p.ativo !== false);
        const banner = config.BANNER_WPP && config.BANNER_WPP.ativo;
        return promos.length > 0 || !!banner;
    }

    function aplicarDemonstracao(config) {
        const slug = (global.TENANT && global.TENANT.slug) || config.TENANT_SLUG || 'default';
        const ehDemoTenant = slug === 'default' || slug === 'loja-demonstracao-acheiaki';
        if (!ehDemoTenant) {
            const antigo = document.getElementById('pz-banner-demo');
            if (antigo) antigo.remove();
            return;
        }
        if (document.getElementById('pz-banner-demo')) return;
        const rootBase = location.pathname.indexOf('/loja/') !== -1 ? '../../' : '';
        const el = document.createElement('div');
        el.id = 'pz-banner-demo';
        el.setAttribute('role', 'status');
        el.style.cssText = 'background:linear-gradient(90deg,#14532d,#0f766e);color:#fff;padding:12px 16px;font-size:0.93rem;line-height:1.45;display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap;text-align:center;';
        el.innerHTML = '<span><strong>🎯 Loja Demonstração AcheIAki</strong> · Veja como cadastrar produtos com fotos e testar o painel:</span>'
            + '<button type="button" id="aki-btn-demo-painel" style="background:#ea580c;color:#fff;border:none;border-radius:8px;padding:7px 14px;font-weight:800;cursor:pointer;font-size:0.86rem;">🔑 Testar Painel do Dono (1 clique)</button>'
            + '<a href="' + rootBase + 'cadastro.html?modo=cadastrar" style="background:#fff;color:#14532d;border-radius:8px;padding:7px 14px;font-weight:800;text-decoration:none;font-size:0.86rem;">🚀 Criar Minha Loja Grátis</a>'
            + '<span style="font-size:0.8rem;opacity:0.92;">(Login demo: <code>demo</code> · Senha: <code>Demo@2026</code>)</span>';
        const body = document.body;
        if (body && body.firstChild) body.insertBefore(el, body.firstChild);
        else if (body) body.appendChild(el);

        const btnDemo = document.getElementById('aki-btn-demo-painel');
        if (btnDemo) {
            btnDemo.addEventListener('click', async function () {
                btnDemo.disabled = true;
                btnDemo.textContent = 'Abrindo Painel Demo...';
                try {
                    const r = await fetch(rootBase + 'login.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            user: 'demo',
                            pass: 'Demo@2026',
                            tenant: 'loja-demonstracao-acheiaki'
                        })
                    });
                    const d = await r.json();
                    if (d && d.success) {
                        sessionStorage.setItem('admin_logado', 'true');
                        sessionStorage.setItem('admin_user', d.user || 'demo');
                        sessionStorage.setItem('admin_pass', d.token || 'Demo@2026');
                        sessionStorage.setItem('admin_role', d.role || 'lojista');
                        sessionStorage.setItem('admin_nome', d.nome || 'Loja Demonstração AcheIAki');
                        sessionStorage.setItem('pedidozap_tenant_slug', 'loja-demonstracao-acheiaki');
                        try { sessionStorage.setItem('admin_permissoes', JSON.stringify(d.permissoes || {})); } catch (e) {}
                        window.location.href = rootBase + 'loja/loja-demonstracao-acheiaki/admin.html?tab=cardapio';
                        return;
                    }
                } catch (e) { /* fallback abaixo */ }
                window.location.href = rootBase + 'cadastro.html?modo=entrar';
            });
        }
    }

    function aplicarCategorias(config, cardapio) {
        const cats = categoriasVisiveis(config, cardapio);
        const mostrarPromo = linkPromocoesVisivel(config, cardapio);
        document.querySelectorAll('a.logo-container').forEach((a) => {
            a.href = urlLoja('index.html');
        });
        document.querySelectorAll('nav.nav-desktop a[href="#promocoes"], nav.nav-desktop a[href*="promocoes"]').forEach((a) => {
            a.style.display = mostrarPromo ? '' : 'none';
        });
        const mapaIconesCat = {
            promocoes: 'fa-tags', destaques: 'fa-tags', ofertas: 'fa-tags',
            combos: 'fa-box-open', kits: 'fa-box-open', novidades: 'fa-star',
            catalogo: 'fa-bag-shopping', acessorios: 'fa-bag-shopping', servicos: 'fa-briefcase',
            lanches: 'fa-burger', hamburgueres: 'fa-burger',
            porcoes: 'fa-bowl-food', guarnicoes: 'fa-bowl-food',
            bebidas: 'fa-martini-glass-citrus', refrigerantes: 'fa-martini-glass-citrus',
            cervejas: 'fa-beer-mug-empty', destilados: 'fa-wine-glass', conveniencia: 'fa-snowflake',
            sobremesas: 'fa-cake-candles', bolos: 'fa-cake-candles', doces: 'fa-candy-cane',
            pizzas: 'fa-pizza-slice', bordas: 'fa-pizza-slice',
            acai: 'fa-ice-cream', complementos: 'fa-ice-cream',
            pratos: 'fa-utensils', marmitas: 'fa-utensils', executivos: 'fa-utensils', sopas: 'fa-mug-hot',
            hortifruti: 'fa-carrot', mercearia: 'fa-basket-shopping', mercado: 'fa-basket-shopping',
            acougue: 'fa-drumstick-bite', limpeza: 'fa-pump-soap', higiene: 'fa-pump-soap',
            medicamentos: 'fa-pills', bebe: 'fa-baby', suplementos: 'fa-heart-pulse',
            caes: 'fa-paw', gatos: 'fa-paw', petiscos: 'fa-paw', higiene_pet: 'fa-paw',
            feminino: 'fa-shirt', masculino: 'fa-shirt', calcados: 'fa-shoe-prints',
            celulares: 'fa-mobile-screen-button', audio: 'fa-headphones',
            informatica: 'fa-laptop', energia: 'fa-bolt',
            maquiagem: 'fa-wand-magic-sparkles', skincare: 'fa-wand-magic-sparkles', cabelos: 'fa-wand-magic-sparkles',
            perfumes: 'fa-gift', presentes: 'fa-gift', flores: 'fa-seedling',
            escolar: 'fa-pen-ruler', brinquedos: 'fa-puzzle-piece',
            ferramentas: 'fa-screwdriver-wrench', eletrica: 'fa-lightbulb',
            hidraulica: 'fa-faucet', utilidades: 'fa-house'
        };
        document.querySelectorAll('.menu-grid').forEach((grade) => {
            grade.innerHTML = cats.map((cat) => {
                const ic = (!cat.icone || cat.icone === 'fa-utensils')
                    ? (mapaIconesCat[String(cat.slug || '').toLowerCase()] || 'fa-store')
                    : cat.icone;
                return '<a class="card-categoria" href="' + escapeAttr(urlLoja('categoria.html?cat=' + encodeURIComponent(cat.slug))) + '">'
                    + '<div class="card-cat-img" style="background:' + escapeAttr(cat.cor || '#f97136') + '"><i class="fa-solid ' + escapeAttr(ic) + '"></i></div>'
                    + '<h3>' + escapeHtml((cat.emoji ? cat.emoji + ' ' : '') + (cat.nome || cat.slug)) + '</h3><p>' + escapeHtml(cat.subtitulo || '') + '</p></a>';
            }).join('');
        });
        const secao = document.getElementById('cardapio');
        if (secao && document.body && document.body.dataset.pagina === 'home') {
            secao.style.display = '';
            let aviso = document.getElementById('cardapio-vazio');
            if (!cats.length) {
                if (!aviso) {
                    aviso = document.createElement('p');
                    aviso.id = 'cardapio-vazio';
                    aviso.className = 'cardapio-vazio';
                    aviso.textContent = 'Os produtos aparecem aqui depois que você colocar o nome, o preço e a foto no painel.';
                    secao.appendChild(aviso);
                }
            } else if (aviso) {
                aviso.remove();
            }
        }
        document.querySelectorAll('.filtros-categoria').forEach((barra) => {
            const inicio = document.body && document.body.dataset.pagina === 'home'
                ? ''
                : '<a class="btn-filtro" href="' + escapeAttr(urlLoja('index.html')) + '">🏠 Início</a>';
            const promoBtn = mostrarPromo
                ? '<a class="btn-filtro" href="' + escapeAttr(urlLoja('index.html#promocoes')) + '">🏷️ Promoções</a>'
                : '';
            barra.innerHTML = inicio + promoBtn + cats.map((cat) => {
                return '<a class="btn-filtro" href="' + escapeAttr(urlLoja('categoria.html?cat=' + encodeURIComponent(cat.slug))) + '">'
                    + escapeHtml((cat.emoji ? cat.emoji + ' ' : '') + (cat.nome || cat.slug)) + '</a>';
            }).join('');
        });
        document.querySelectorAll('.busca-e-filtros').forEach((caixa) => {
            caixa.classList.toggle('sem-filtros', !caixa.querySelector('a'));
        });
    }

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    function escapeAttr(str) {
        return escapeHtml(str).replace(/`/g, '');
    }

    let celulasLojaInscrito = false;

    function aplicarLinksMultitenant() {
        const slug = (global.TENANT && global.TENANT.slug) ? global.TENANT.slug : 'default';
        if (!slug || slug === 'default') return;
        document.querySelectorAll('a[href]').forEach((a) => {
            const href = a.getAttribute('href') || '';
            if (!href || href.startsWith('#') || /^(https?:|mailto:|tel:|javascript:)/i.test(href)) return;
            if (/^(marketplace|cadastro|superadmin)\.html/i.test(href) || href.includes('tenant=')) return;
            if (/^[\w-]+\.html([?#].*)?$/i.test(href)) {
                a.setAttribute('href', urlLoja(href));
            }
        });
    }

    function escutarCelulasLoja() {
        if (celulasLojaInscrito || typeof global.PedidoZapCelulas === 'undefined') return;
        celulasLojaInscrito = true;
        global.PedidoZapCelulas.escutar((ev) => {
            if (!ev || ev.tipo !== 'loja_atualizada') return;
            const slugAtual = (global.TENANT && global.TENANT.slug) ? global.TENANT.slug : 'default';
            if (ev.dados && ev.dados.tenant && ev.dados.tenant !== slugAtual) return;
            if (global.PedidoZapStore && typeof global.PedidoZapStore.load === 'function') {
                global.PedidoZapStore.load({ silent: true }).catch(() => {});
            }
        });
    }

    function apply(config, cardapio) {
        const c = cfgOf(config);
        const menu = cardapio || global.CARDAPIO_DADOS || {};
        aplicarCores(c);
        aplicarImagens(c);
        aplicarTextos(c);
        aplicarContato(c);
        aplicarAvaliacoes(c);
        aplicarPagamentos(c);
        aplicarCategorias(c, menu);
        aplicarSeo(c, menu);
        aplicarDemonstracao(c);
        aplicarLinksMultitenant();
        escutarCelulasLoja();
        document.documentElement.setAttribute('data-loja', c.NOME_LOJA || '');
        document.documentElement.setAttribute('data-pz-ready', '1');
    }

    global.PedidoZapHydrate = { apply: apply };
})(typeof window !== 'undefined' ? window : globalThis);

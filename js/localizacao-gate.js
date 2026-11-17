(function (global) {
    'use strict';

    const STORAGE_GEO = 'pz_user_geo_v1';
    const STORAGE_VID = 'pz_visitor_id_v1';

    function getBaseUrl(path) {
        if (typeof global.TENANT !== 'undefined' && typeof global.TENANT.apiUrl === 'function') {
            return global.TENANT.apiUrl(path);
        }
        return path;
    }

    function getVisitorId() {
        try {
            let vid = localStorage.getItem(STORAGE_VID) || '';
            if (!vid || vid.length < 8) {
                vid = 'v_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
                localStorage.setItem(STORAGE_VID, vid);
            }
            return vid;
        } catch (e) {
            return 'v_anon_' + Date.now().toString(36);
        }
    }

    function getGeo() {
        try {
            const raw = localStorage.getItem(STORAGE_GEO);
            if (!raw) return null;
            const g = JSON.parse(raw);
            if (!g || (!Number(g.lat) && !g.cidade)) return null;
            return g;
        } catch (e) {
            return null;
        }
    }

    function saveGeo(geo) {
        const clean = {
            lat: Number(geo.lat || 0),
            lng: Number(geo.lng || 0),
            cidade: String(geo.cidade || '').trim(),
            bairro: String(geo.bairro || '').trim(),
            estado: String(geo.estado || '').trim(),
            label: String(geo.label || '').trim(),
            fonte: String(geo.fonte || 'gps'),
            atualizado_em: Date.now()
        };
        try {
            localStorage.setItem(STORAGE_GEO, JSON.stringify(clean));
        } catch (e) { /* ignore */ }
        atualizarBarraGeo(clean);
        global.dispatchEvent(new CustomEvent('pz:localizacao-ativa', { detail: clean }));
        global.dispatchEvent(new CustomEvent('pz:localizacao-atualizada', { detail: clean }));
        return clean;
    }

    function atualizarBarraGeo(geo) {
        const txtEl = document.getElementById('pz-loc-bar-texto');
        if (!txtEl || !geo) return;
        const partes = [geo.bairro, geo.cidade].filter(Boolean);
        const titulo = partes.length ? partes.join(', ') : (geo.label || 'Localização GPS ativa');
        txtEl.textContent = 'Localização ativa: ' + titulo;
    }

    async function resolverReverseGeocode(lat, lng) {
        try {
            const url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat='
                + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng);
            const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
            if (!r.ok) return {};
            const d = await r.json();
            const addr = (d && d.address) || {};
            return {
                cidade: addr.city || addr.town || addr.village || addr.municipality || '',
                bairro: addr.suburb || addr.neighbourhood || addr.quarter || '',
                estado: addr.state_code || '',
                label: d.display_name || ''
            };
        } catch (e) {
            return {};
        }
    }

    async function resolverEnderecoViaServidor(textoBusca) {
        const resp = await fetch('geocode.php?q=' + encodeURIComponent(textoBusca));
        const data = await resp.json();
        if (!resp.ok || !data || !data.success) {
            throw new Error((data && data.message) || 'Não foi possível localizar esse endereço.');
        }
        return data;
    }

    function criarModalGate() {
        let wrap = document.getElementById('pz-loc-gate-backdrop');
        if (wrap) return wrap;

        wrap = document.createElement('div');
        wrap.id = 'pz-loc-gate-backdrop';
        wrap.className = 'pz-loc-gate-backdrop';
        wrap.hidden = true;
        wrap.innerHTML = ''
            + '<div class="pz-loc-gate-card" role="dialog" aria-modal="true" aria-labelledby="pz-loc-titulo">'
            + '  <div class="pz-loc-gate-icon"><i class="fa-solid fa-location-dot"></i></div>'
            + '  <h2 id="pz-loc-titulo">Onde você está agora?</h2>'
            + '  <p>Toque no botão abaixo para encontrarmos lojas abertas perto do seu endereço e calcular a entrega:</p>'
            + '  <button type="button" class="pz-loc-btn" id="pz-loc-btn-gps" style="min-height:52px;font-size:1rem;"><i class="fa-solid fa-location-crosshairs"></i> Usar minha localização atual (GPS)</button>'
            + '  <div class="pz-loc-divisor">ou digite seu CEP / Cidade</div>'
            + '  <div class="pz-loc-form-alt">'
            + '    <input type="text" id="pz-loc-cep" placeholder="CEP ou nome da rua" style="min-height:48px;font-size:1rem;">'
            + '    <input type="text" id="pz-loc-cidade" placeholder="Cidade (ex.: Goiânia - GO)" style="min-height:48px;font-size:1rem;">'
            + '    <button type="button" class="pz-loc-btn-sec" id="pz-loc-btn-manual" style="min-height:48px;font-size:0.98rem;">Confirmar endereço e entrar</button>'
            + '  </div>'
            + '  <button type="button" id="pz-loc-btn-pular" hidden style="margin-top:10px;border:none;background:transparent;color:#475569;font-size:0.84rem;font-weight:700;cursor:pointer;text-decoration:underline;">Explorar sem travar agora (Modo Análise)</button>'
            + '  <p class="pz-loc-erro" id="pz-loc-erro" hidden></p>'
            + '</div>';
        document.body.appendChild(wrap);
        return wrap;
    }

    function criarBarraTopo() {
        if (document.getElementById('pz-loc-bar-status')) return;
        const bar = document.createElement('div');
        bar.id = 'pz-loc-bar-status';
        bar.className = 'pz-loc-bar-status';
        bar.innerHTML = '<i class="fa-solid fa-location-dot" style="color:#fb923c;"></i>'
            + '<span id="pz-loc-bar-texto">Ativando localização...</span>'
            + '<button type="button" id="pz-loc-bar-trocar">Trocar local</button>';
        document.body.insertBefore(bar, document.body.firstChild);
        document.getElementById('pz-loc-bar-trocar').addEventListener('click', () => {
            exigirLocalizacao(true);
        });
    }

    let promessaAtual = null;

    function modoApresentacaoAtivo() {
        return Boolean(global.PedidoZapApresentacao && typeof global.PedidoZapApresentacao.ativo === 'function' && global.PedidoZapApresentacao.ativo());
    }

    function exigirLocalizacao(forcarAbrir) {
        const atual = getGeo();
        if (atual && (Number(atual.lat) !== 0 || modoApresentacaoAtivo()) && !forcarAbrir) {
            atualizarBarraGeo(atual);
            return Promise.resolve(atual);
        }
        if (!forcarAbrir && modoApresentacaoAtivo()) {
            const livre = { lat: 0, lng: 0, cidade: '', bairro: '', label: 'Exploração livre (Modo Análise)', fonte: 'apresentacao' };
            const txtEl = document.getElementById('pz-loc-bar-texto');
            if (txtEl) txtEl.textContent = 'Modo Análise: localização opcional (toque em Trocar local para simular GPS)';
            return Promise.resolve(livre);
        }
        if (promessaAtual && !forcarAbrir) return promessaAtual;

        const wrap = criarModalGate();
        wrap.hidden = false;
        const btnPular = document.getElementById('pz-loc-btn-pular');
        if (btnPular) btnPular.hidden = !modoApresentacaoAtivo();

        promessaAtual = new Promise((resolve) => {
            const btnGps = document.getElementById('pz-loc-btn-gps');
            const btnManual = document.getElementById('pz-loc-btn-manual');
            const errEl = document.getElementById('pz-loc-erro');

            if (btnPular) {
                btnPular.onclick = () => {
                    wrap.hidden = true;
                    promessaAtual = null;
                    const livre = getGeo() || { lat: 0, lng: 0, cidade: '', bairro: '', label: 'Exploração livre (Modo Análise)', fonte: 'apresentacao' };
                    resolve(livre);
                };
            }

            const mostrarErro = (msg) => {
                errEl.textContent = msg;
                errEl.hidden = false;
            };

            btnGps.onclick = () => {
                errEl.hidden = true;
                if (!navigator.geolocation) {
                    mostrarErro('Seu navegador não possui GPS automático. Digite seu CEP e Cidade abaixo.');
                    return;
                }
                btnGps.disabled = true;
                btnGps.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Obtendo GPS...';

                const restaurarBotao = () => {
                    btnGps.disabled = false;
                    btnGps.innerHTML = '<i class="fa-solid fa-location-crosshairs"></i> Ativar localização agora (GPS)';
                };

                const aoObterPosicao = async (pos) => {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;
                    const rev = await resolverReverseGeocode(lat, lng);
                    const salvo = saveGeo({
                        lat,
                        lng,
                        cidade: rev.cidade || '',
                        bairro: rev.bairro || '',
                        estado: rev.estado || '',
                        label: rev.label || '',
                        fonte: 'gps'
                    });
                    wrap.hidden = true;
                    restaurarBotao();
                    promessaAtual = null;
                    resolve(salvo);
                };

                const aoFalharFinal = (err) => {
                    restaurarBotao();
                    if (err && err.code === 1) {
                        mostrarErro('Permissão de localização bloqueada no navegador ou no Windows. Libere a localização ou informe seu CEP/Cidade abaixo.');
                    } else {
                        mostrarErro('Localização automática indisponível no momento. Informe seu CEP ou Cidade abaixo para continuar.');
                    }
                };

                navigator.geolocation.getCurrentPosition(
                    aoObterPosicao,
                    (err) => {
                        // Retry with network/Wi-Fi accuracy when high-accuracy GPS is unavailable or times out.
                        if (err && err.code !== 1) {
                            navigator.geolocation.getCurrentPosition(
                                aoObterPosicao,
                                aoFalharFinal,
                                { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 }
                            );
                            return;
                        }
                        aoFalharFinal(err);
                    },
                    { enableHighAccuracy: true, timeout: 8000 }
                );
            };

            btnManual.onclick = async () => {
                errEl.hidden = true;
                const cep = (document.getElementById('pz-loc-cep').value || '').trim();
                const cid = (document.getElementById('pz-loc-cidade').value || '').trim();
                const consulta = [cep, cid, 'Brasil'].filter(Boolean).join(', ');
                if (!cep && !cid) {
                    mostrarErro('Informe o CEP ou a Cidade para confirmar sua localização.');
                    return;
                }
                btnManual.disabled = true;
                btnManual.textContent = 'Localizando...';
                try {
                    const geo = await resolverEnderecoViaServidor(consulta);
                    const salvo = saveGeo({
                        lat: geo.lat,
                        lng: geo.lng,
                        cidade: cid || '',
                        bairro: cep || '',
                        label: geo.label || consulta,
                        fonte: 'endereco'
                    });
                    wrap.hidden = true;
                    promessaAtual = null;
                    resolve(salvo);
                } catch (e) {
                    mostrarErro(e.message || 'Endereço não localizado. Tente incluir Cidade e Estado.');
                } finally {
                    btnManual.disabled = false;
                    btnManual.textContent = 'Confirmar endereço e entrar';
                }
            };
        });

        return promessaAtual;
    }

    // =========================================================================
    // SDK DE SINAIS COMPORTAMENTAIS (ALGORITMO ESTILO TIKTOK / IFOOD)
    // =========================================================================
    async function registrar(evento, dados) {
        if (global.AcheIAkiLGPD && typeof global.AcheIAkiLGPD.podeUsarIA === 'function' && !global.AcheIAkiLGPD.podeUsarIA()) {
            return;
        }
        const geo = getGeo() || {};
        const nichoAuto = (global.APP_CONFIG && global.APP_CONFIG.NICHO) ? global.APP_CONFIG.NICHO : '';
        const payload = Object.assign({
            action: 'registrar_evento',
            consentimento_ia: true,
            visitor_id: getVisitorId(),
            evento: evento || 'view_loja',
            nicho: nichoAuto,
            lat: geo.lat || 0,
            lng: geo.lng || 0,
            cidade: geo.cidade || '',
            bairro: geo.bairro || ''
        }, dados || {});

        try {
            const url = getBaseUrl('algoritmo_api.php?action=registrar_evento');
            if (navigator.sendBeacon && evento === 'dwell_loja') {
                navigator.sendBeacon(url, JSON.stringify(payload));
                return;
            }
            await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
        } catch (e) { /* silencioso para não travar UX */ }
    }

    function iniciarRastreioVitrine() {
        const pagina = document.body && document.body.dataset.pagina;
        const paginasVitrine = ['home', 'categoria', 'acai', 'bebidas', 'combos', 'hamburgueres', 'porcoes'];
        if (!pagina || paginasVitrine.indexOf(pagina) === -1) {
            return;
        }
        const tenant = (typeof global.TENANT !== 'undefined' && global.TENANT.slug) ? global.TENANT.slug : 'default';
        const inicioMs = Date.now();

        criarBarraTopo();
        exigirLocalizacao(false).then(() => {
            registrar('view_loja', { tenant });
        });

        let enviouDwell = false;
        const enviarDwell = () => {
            if (enviouDwell) return;
            enviouDwell = true;
            const dwell = Date.now() - inicioMs;
            if (dwell >= 2500) {
                registrar('dwell_loja', { tenant, dwell_ms: dwell });
            }
        };
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') enviarDwell();
        });
        global.addEventListener('pagehide', enviarDwell);

        // Rastreia buscas na vitrine da loja para alimentar recomendações da IA
        const campoBusca = document.getElementById('input-busca') || document.getElementById('campo-busca');
        if (campoBusca) {
            let timerB = null;
            campoBusca.addEventListener('input', () => {
                clearTimeout(timerB);
                const q = campoBusca.value.trim();
                if (q.length >= 3) {
                    timerB = setTimeout(() => {
                        registrar('busca', { tenant, termo: q });
                    }, 700);
                }
            });
        }

        // Rastreia cliques em pratos e adições ao carrinho automaticamente
        document.addEventListener('click', (e) => {
            const card = e.target.closest('.item-card, .produto-card, .produto, .produto-promocao, [data-produto-id], [data-id]');
            if (card) {
                const titulo = (card.querySelector('h3, h4, .produto-nome') || {}).textContent || '';
                if (titulo) {
                    registrar('click_produto', { tenant, produto_nome: titulo.trim() });
                }
            }
            const btnAdd = e.target.closest('.btn-adicionar, .btn-add-carrinho, #btn-confirmar-item');
            if (btnAdd) {
                const modalTitulo = (document.getElementById('modal-produto-nome') || {}).textContent || '';
                const cardTitulo = card ? ((card.querySelector('h3, h4, .produto-nome') || {}).textContent || '') : '';
                const nome = (modalTitulo || cardTitulo).trim();
                if (nome) {
                    registrar('add_carrinho', { tenant, produto_nome: nome });
                }
            }
        });
    }

    global.PZAlgoritmo = {
        getVisitorId,
        getGeo,
        saveGeo,
        exigirLocalizacao,
        registrar,
        resolverEnderecoViaServidor
    };

    global.PedidoZapLocalizacao = {
        obterSalva: getGeo,
        abrirGate: () => exigirLocalizacao(true),
        salvar: saveGeo,
        obterVisitorId: getVisitorId
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciarRastreioVitrine);
    } else {
        iniciarRastreioVitrine();
    }
})(typeof window !== 'undefined' ? window : globalThis);

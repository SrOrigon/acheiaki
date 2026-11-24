(function () {
    'use strict';

    const STATE = {
        lojas: [],
        comparativoSegmentos: [],
        pratosIa: [],
        alertaExpansao: null,
        alternativas: [],
        comprador: null,
        token: localStorage.getItem('pz_comprador_token') || '',
        nichoAtivo: '',
        abaAtiva: 'descobrir',
        pedidosTimer: null,
        modoEdicaoGate: false, // true quando usuário logado tem perfil incompleto
        loc: { lat: 0, lng: 0, cidade: '', bairro: '' },
    };

    const $ = (id) => document.getElementById(id);

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function brl(v) {
        return 'R$ ' + Number(v || 0).toFixed(2).replace('.', ',');
    }

    function fmtCnpj(c) {
        const d = String(c || '').replace(/\D/g, '');
        if (d.length !== 14) return d;
        return d.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5');
    }

    function validarCpfLocal(cpf) {
        const d = String(cpf || '').replace(/\D/g, '');
        if (d.length !== 11 || /^(\d)\1{10}$/.test(d)) return false;
        for (let t = 9; t < 11; t++) {
            let s = 0;
            for (let i = 0; i < t; i++) s += parseInt(d[i], 10) * (t + 1 - i);
            const dv = ((10 * s) % 11) % 10;
            if (parseInt(d[t], 10) !== dv) return false;
        }
        return true;
    }

    function maskCpf(input) {
        if (!input) return;
        input.addEventListener('input', () => {
            const d = input.value.replace(/\D/g, '').slice(0, 11);
            input.value = d
                .replace(/^(\d{3})(\d)/, '$1.$2')
                .replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
                .replace(/\.(\d{3})(\d)/, '.$1-$2');
        });
    }

    function maskCep(input, onFound) {
        if (!input) return;
        input.addEventListener('input', () => {
            const d = input.value.replace(/\D/g, '').slice(0, 8);
            input.value = d.length > 5 ? d.slice(0, 5) + '-' + d.slice(5) : d;
            if (d.length === 8 && typeof onFound === 'function') {
                fetch('https://viacep.com.br/ws/' + d + '/json/')
                    .then((r) => r.json())
                    .then((via) => {
                        if (via && !via.erro) onFound(via);
                    })
                    .catch(() => {});
            }
        });
    }

    function toast(msg) {
        const el = $('mkt-toast');
        if (!el) return;
        el.textContent = msg;
        el.hidden = false;
        clearTimeout(el._t);
        el._t = setTimeout(() => { el.hidden = true; }, 3500);
    }

    // =========================================================
    // LOCALIZAÇÃO & ENDEREÇO ATIVO
    // =========================================================
    function obterLocalAtual() {
        let loc = { lat: 0, lng: 0, cidade: '', bairro: '' };
        if (window.PedidoZapLocalizacao && window.PedidoZapLocalizacao.obterSalva) {
            const salva = window.PedidoZapLocalizacao.obterSalva();
            if (salva) {
                loc = {
                    lat: Number(salva.lat || 0),
                    lng: Number(salva.lng || 0),
                    cidade: salva.cidade || '',
                    bairro: salva.bairro || '',
                };
            }
        }
        if (STATE.comprador && Array.isArray(STATE.comprador.enderecos) && STATE.comprador.enderecos.length) {
            const ativo = STATE.comprador.enderecos[0];
            if (ativo) {
                if ((!loc.lat || !loc.lng) && ativo.lat && ativo.lng) {
                    loc.lat = Number(ativo.lat);
                    loc.lng = Number(ativo.lng);
                }
                if (!loc.cidade && ativo.cidade) loc.cidade = ativo.cidade;
                if (!loc.bairro && ativo.bairro) loc.bairro = ativo.bairro;
            }
        }
        STATE.loc = loc;
        return loc;
    }

    function atualizarPillLocal() {
        const txt = $('mkt-local-texto');
        if (!txt) return;
        const l = obterLocalAtual();
        if (STATE.comprador && Array.isArray(STATE.comprador.enderecos) && STATE.comprador.enderecos[0]) {
            const e = STATE.comprador.enderecos[0];
            txt.textContent = (e.rua ? e.rua + ', ' + (e.numero || 'S/N') + ' — ' : '') + (e.cidade || l.cidade || 'Endereço ativo');
            return;
        }
        if (l.cidade || l.bairro) {
            txt.textContent = [l.bairro, l.cidade].filter(Boolean).join(', ');
        } else if (l.lat && l.lng) {
            txt.textContent = 'GPS ativo (' + l.lat.toFixed(3) + ', ' + l.lng.toFixed(3) + ')';
        } else {
            txt.textContent = 'Ativar localização / endereço';
        }
    }

    function capturarGps(statusEl, onCoords) {
        if (!navigator.geolocation) {
            if (statusEl) statusEl.textContent = 'Navegador sem suporte a GPS. Use o CEP acima.';
            return;
        }
        if (statusEl) statusEl.textContent = 'Obtendo coordenadas GPS...';
        const sucesso = (pos) => {
            const lat = Number(pos.coords.latitude.toFixed(7));
            const lng = Number(pos.coords.longitude.toFixed(7));
            if (statusEl) statusEl.textContent = 'GPS capturado: ' + lat.toFixed(4) + ', ' + lng.toFixed(4);
            if (typeof onCoords === 'function') onCoords(lat, lng);
        };
        navigator.geolocation.getCurrentPosition(
            sucesso,
            () => {
                navigator.geolocation.getCurrentPosition(
                    sucesso,
                    () => {
                        if (statusEl) statusEl.textContent = 'GPS indisponível no aparelho — o endereço pelo CEP será geocodificado automaticamente.';
                    },
                    { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 }
                );
            },
            { enableHighAccuracy: true, timeout: 7000, maximumAge: 60000 }
        );
    }

    // =========================================================
    // COMPRADOR: GATE OBRIGATÓRIO & PERFIL
    // =========================================================
    function salvarSessaoComprador(token, comprador) {
        STATE.token = token || STATE.token;
        STATE.comprador = comprador || null;
        if (STATE.token) localStorage.setItem('pz_comprador_token', STATE.token);
        if (STATE.comprador) {
            localStorage.setItem('pz_comprador_info', JSON.stringify({
                id: STATE.comprador.id,
                nome: STATE.comprador.nome,
                cpf: STATE.comprador.cpf,
                telefone: STATE.comprador.telefone,
                email: STATE.comprador.email,
                enderecos: STATE.comprador.enderecos || [],
                favoritos: STATE.comprador.favoritos || [],
                perfil_completo: !!STATE.comprador.perfil_completo,
            }));
        }
    }

    function modoPortalEtapa(etapa) {
        const escBox = $('mkt-gate-escolha');
        const empBox = $('mkt-gate-empresa');
        const cliBox = $('mkt-gate-cliente');
        if (escBox) escBox.hidden = (etapa !== 'escolha');
        if (empBox) empBox.hidden = (etapa !== 'empresa');
        if (cliBox) cliBox.hidden = (etapa !== 'cliente');
        if ($('mkt-conta-msg')) $('mkt-conta-msg').textContent = '';
    }

    function verificarGateObrigatorio(forcarAbertura, etapaInicial) {
        const modal = $('mkt-modal-conta');
        const btnX = $('mkt-fechar-conta');
        const pendBox = $('mkt-gate-pendencias');
        const demoWrap = $('mkt-gate-demo-wrap');
        if (!modal) return false;

        const emDemo = Boolean(window.PedidoZapApresentacao && window.PedidoZapApresentacao.ativo());
        const jaPulouDemo = emDemo && sessionStorage.getItem('aki_demo_gate_ok') === '1';
        if (demoWrap) demoWrap.hidden = !emDemo;

        if (!STATE.comprador) {
            STATE.modoEdicaoGate = false;
            const lgpdBan = $('aki-lgpd-banner');
            if (lgpdBan) lgpdBan.hidden = true;
            // Obrigatório na entrada normal: só permite fechar se estiver em Modo Análise (ou já estiver logado)
            if (btnX) btnX.hidden = !emDemo;
            if (pendBox) pendBox.hidden = true;
            $('mkt-auth-tabs').hidden = false;
            $('mkt-cad-senhas-row').hidden = false;
            $('mkt-cad-tel').readOnly = false;
            $('mkt-cad-submit').textContent = 'Criar conta e continuar →';
            const alvoEtapa = etapaInicial || 'escolha';
            modoPortalEtapa(alvoEtapa);
            if (forcarAbertura || (!jaPulouDemo)) {
                modal.hidden = false;
                return true;
            }
            modal.hidden = true;
            return false;
        }

        if (STATE.comprador.perfil_completo === false) {
            if (!forcarAbertura && emDemo) {
                if (btnX) btnX.hidden = false;
                modal.hidden = true;
                return false;
            }
            STATE.modoEdicaoGate = true;
            if (btnX) btnX.hidden = true;
            const pends = Array.isArray(STATE.comprador.pendencias) ? STATE.comprador.pendencias : [];
            if (pendBox) {
                pendBox.hidden = false;
                pendBox.innerHTML = '<strong>Complete seus dados essenciais para continuar:</strong><ul style="margin:6px 0 0 18px;">'
                    + pends.map((p) => '<li>' + esc(p) + '</li>').join('') + '</ul>';
            }
            modoPortalEtapa('cliente');
            $('mkt-auth-tabs').hidden = true;
            $('mkt-form-login').hidden = true;
            $('mkt-form-cadastro').hidden = false;
            if ($('mkt-cli-titulo')) $('mkt-cli-titulo').textContent = 'Confirmar Nome, WhatsApp e E-mail';
            if ($('mkt-cli-sub')) $('mkt-cli-sub').textContent = 'Precisamos só desses dados para entregar seu pedido certinho.';

            const c = STATE.comprador;
            const e0 = (c.enderecos && c.enderecos[0]) || {};
            $('mkt-cad-nome').value = c.nome || '';
            $('mkt-cad-cpf').value = c.cpf || '';
            $('mkt-cad-tel').value = c.telefone || '';
            $('mkt-cad-tel').readOnly = !!c.telefone;
            $('mkt-cad-email').value = c.email || '';
            $('mkt-cad-cep').value = e0.cep || '';
            $('mkt-cad-numero').value = e0.numero || '';
            $('mkt-cad-rua').value = e0.rua || '';
            $('mkt-cad-bairro').value = e0.bairro || '';
            $('mkt-cad-cidade').value = e0.cidade || '';
            $('mkt-cad-estado').value = e0.estado || '';
            $('mkt-cad-comp').value = e0.complemento || '';
            $('mkt-cad-lat').value = e0.lat || 0;
            $('mkt-cad-lng').value = e0.lng || 0;
            $('mkt-cad-senhas-row').hidden = true;
            $('mkt-cad-submit').textContent = 'Salvar e continuar →';
            modal.hidden = false;
            return true;
        }

        if (btnX) btnX.hidden = false;
        if (pendBox) pendBox.hidden = true;
        modal.hidden = true;
        return false;
    }

    function inicializarGoogleLogin() {
        fetch('comprador_api.php?action=config_login')
            .then((r) => r.json())
            .then((cfg) => {
                const cid = (cfg && cfg.google_oauth_client_id) ? String(cfg.google_oauth_client_id).trim() : '';
                if (!cid || !document.getElementById('mkt-google-btn-oficial')) return;
                if ($('mkt-google-wrap')) $('mkt-google-wrap').hidden = false;
                const sc = document.createElement('script');
                sc.src = 'https://accounts.google.com/gsi/client';
                sc.async = true;
                sc.defer = true;
                sc.onload = () => {
                    if (!window.google || !window.google.accounts || !window.google.accounts.id) return;
                    window.google.accounts.id.initialize({
                        client_id: cid,
                        callback: (resp) => {
                            if (!resp || !resp.credential) return;
                            const msg = $('mkt-conta-msg');
                            if (msg) {
                                msg.style.color = '#334155';
                                msg.textContent = 'Validando conta Google...';
                            }
                            fetch('comprador_api.php?action=entrar_google', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    credential: resp.credential,
                                    telefone: ($('mkt-cad-tel') && $('mkt-cad-tel').value.trim()) || '',
                                }),
                            })
                                .then((r) => r.json())
                                .then((d) => {
                                    if (!d.success) {
                                        if (msg) {
                                            msg.style.color = '#dc2626';
                                            msg.textContent = d.message || 'Falha ao conectar Google.';
                                        }
                                        return;
                                    }
                                    if (d.precisa_whatsapp && d.google_perfil) {
                                        document.querySelectorAll('#mkt-auth-tabs button').forEach((x) => x.classList.toggle('ativo', x.dataset.auth === 'cadastrar'));
                                        $('mkt-form-login').hidden = true;
                                        $('mkt-form-cadastro').hidden = false;
                                        $('mkt-cad-email').value = d.google_perfil.email || '';
                                        if (d.google_perfil.nome && !$('mkt-cad-nome').value) $('mkt-cad-nome').value = d.google_perfil.nome;
                                        if (msg) {
                                            msg.style.color = '#c2410c';
                                            msg.textContent = 'Conta Google conectada! Agora digite seu WhatsApp com DDD para concluir.';
                                        }
                                        if ($('mkt-cad-tel')) $('mkt-cad-tel').focus();
                                        return;
                                    }
                                    if (msg) msg.textContent = '';
                                    salvarSessaoComprador(d.token, d.comprador);
                                    atualizarUiConta();
                                    carregarMarketplace();
                                    toast('Conectado com sua conta Google!');
                                });
                        },
                    });
                    window.google.accounts.id.renderButton(document.getElementById('mkt-google-btn-oficial'), {
                        theme: 'outline',
                        size: 'large',
                        width: 340,
                        text: 'continue_with',
                    });
                };
                document.head.appendChild(sc);
            })
            .catch(() => {});
    }

    function atualizarUiConta() {
        const label = $('mkt-conta-label');
        const favBadge = $('mkt-badge-fav');
        const bvNome = $('mkt-boas-vindas-nome');
        const c = STATE.comprador;
        const primeiroNome = c && c.nome ? c.nome.split(' ')[0] : '';
        if (label) {
            label.textContent = primeiroNome ? ('Olá, ' + primeiroNome) : 'Entrar / Cadastrar';
        }
        if (bvNome) {
            bvNome.textContent = primeiroNome
                ? ('Olá, ' + primeiroNome + '! O que você gostaria de pedir hoje?')
                : 'Olá! O que você gostaria de pedir hoje?';
        }
        if (favBadge) {
            favBadge.textContent = c && Array.isArray(c.favoritos) ? c.favoritos.length : 0;
        }
        if (c && $('mkt-tel-pedido') && !$('mkt-tel-pedido').value) {
            $('mkt-tel-pedido').value = c.telefone || '';
        }
        renderizarAbaConta();
        atualizarPillLocal();
        verificarGateObrigatorio();
    }

    function carregarPerfil() {
        if (!STATE.token) {
            STATE.comprador = null;
            atualizarUiConta();
            return Promise.resolve();
        }
        return fetch('comprador_api.php?action=perfil&token=' + encodeURIComponent(STATE.token))
            .then((r) => r.json())
            .then((d) => {
                if (d && d.success && d.comprador) {
                    salvarSessaoComprador(STATE.token, d.comprador);
                } else {
                    STATE.token = '';
                    STATE.comprador = null;
                    localStorage.removeItem('pz_comprador_token');
                }
                atualizarUiConta();
            })
            .catch(() => {
                atualizarUiConta();
            });
    }

    // =========================================================
    // CARREGAMENTO DO MARKETPLACE + ALGORITMO IA
    // =========================================================
    function carregarMarketplace() {
        const l = obterLocalAtual();
        const params = new URLSearchParams({
            action: 'listar_lojas',
            q: ($('mkt-busca') && $('mkt-busca').value.trim()) || '',
            cidade: ($('mkt-cidade') && $('mkt-cidade').value) || '',
            bairro: ($('mkt-bairro') && $('mkt-bairro').value) || '',
            nicho: STATE.nichoAtivo || '',
            ordenar: ($('mkt-ordenar') && $('mkt-ordenar').value) || 'algoritmo',
            abertas: ($('mkt-abertas') && $('mkt-abertas').checked) ? '1' : '0',
            frete_gratis: ($('mkt-gratis') && $('mkt-gratis').checked) ? '1' : '0',
            lat: String(l.lat || 0),
            lng: String(l.lng || 0),
        });

        const grid = $('mkt-grid-lojas');
        if (grid) {
            grid.innerHTML = Array.from({ length: 4 }, () =>
                '<div class="mkt-skel-card" aria-hidden="true">'
                + '<div class="mkt-skel-capa mkt-skel-shimmer"></div>'
                + '<div class="mkt-skel-body">'
                + '<div class="mkt-skel-line mkt-skel-shimmer" style="width:65%;height:18px;"></div>'
                + '<div class="mkt-skel-line mkt-skel-shimmer" style="width:45%;"></div>'
                + '<div class="mkt-skel-line mkt-skel-shimmer" style="width:85%;"></div>'
                + '</div></div>'
            ).join('');
        }

        const podeIA = !(window.AcheIAkiLGPD && typeof window.AcheIAkiLGPD.podeUsarIA === 'function')
            || window.AcheIAkiLGPD.podeUsarIA();
        const vidAtual = (podeIA && window.PZAlgoritmo && typeof window.PZAlgoritmo.getVisitorId === 'function')
            ? window.PZAlgoritmo.getVisitorId()
            : '';
        const telAlg = (podeIA && ((STATE.comprador && STATE.comprador.telefone) || (window.PedidoZapCelulas && window.PedidoZapCelulas.obterTelefonePreferido()))) || '';
        const algParams = new URLSearchParams({
            action: 'recomendar',
            visitor_id: vidAtual,
            telefone: telAlg,
            lat: String(l.lat || 0),
            lng: String(l.lng || 0),
            cidade: ($('mkt-cidade') && $('mkt-cidade').value) || l.cidade || '',
            bairro: ($('mkt-bairro') && $('mkt-bairro').value) || l.bairro || '',
            nicho: STATE.nichoAtivo || '',
        });

        Promise.all([
            fetch('marketplace_api.php?' + params.toString()).then((r) => r.json()),
            fetch('algoritmo_api.php?' + algParams.toString()).then((r) => r.json()).catch(() => null),
        ])
            .then(([mktRes, algRes]) => {
                if (!mktRes || !mktRes.success) {
                    if (grid) grid.innerHTML = '<div class="mkt-empty">Não foi possível carregar as empresas agora.</div>';
                    return;
                }
                const mapaAlg = {};
                if (algRes && algRes.success && Array.isArray(algRes.lojas)) {
                    algRes.lojas.forEach((al, idx) => {
                        mapaAlg[al.slug] = Object.assign({ ordem_alg: idx }, al);
                    });
                }
                STATE.lojas = (Array.isArray(mktRes.lojas) ? mktRes.lojas : []).map((loja) => {
                    const al = mapaAlg[loja.slug];
                    if (!al) return loja;
                    return Object.assign({}, loja, {
                        match_pct: al.match_pct,
                        compras_cliente: al.compras_cliente || 0,
                        eh_loja_habitual: !!al.eh_loja_habitual,
                        eh_favorita: !!al.eh_favorita,
                        motivo_algoritmo: al.motivo_algoritmo || '',
                        ordem_alg: al.ordem_alg,
                    });
                });
                const ord = ($('mkt-ordenar') && $('mkt-ordenar').value) || 'algoritmo';
                if (ord === 'algoritmo' && Object.keys(mapaAlg).length) {
                    STATE.lojas.sort((a, b) => {
                        const oa = a.ordem_alg != null ? a.ordem_alg : 9999;
                        const ob = b.ordem_alg != null ? b.ordem_alg : 9999;
                        return oa - ob;
                    });
                }

                STATE.comparativoSegmentos = Array.isArray(mktRes.comparativo_segmentos) ? mktRes.comparativo_segmentos : [];
                STATE.alertaExpansao = mktRes.alerta_expansao || null;
                STATE.alternativas = Array.isArray(mktRes.recomendacoes_alternativas) ? mktRes.recomendacoes_alternativas : [];
                STATE.totalLojasRegiao = Number(mktRes.total_lojas_regiao || 0);
                STATE.totalLojasPlataforma = Number(mktRes.total_lojas_plataforma || 0);
                STATE.pratosIa = (algRes && algRes.success && Array.isArray(algRes.para_voce_pratos)) ? algRes.para_voce_pratos : [];

                if (mktRes.filtros_disponiveis) {
                    preencherSelect($('mkt-cidade'), mktRes.filtros_disponiveis.cidades || [], 'Todas as cidades');
                    preencherSelect($('mkt-bairro'), mktRes.filtros_disponiveis.bairros || [], 'Todos os bairros');
                }

                renderizarComparativoSegmentos();
                renderizarExpansao();
                renderizarRadarIa(algRes);
                renderizarPratosIa(algRes);
                renderizarGridLojas(STATE.lojas, grid);
                renderizarFavoritos();
            })
            .catch(() => {
                if (grid) grid.innerHTML = '<div class="mkt-empty">Falha de conexão com o Marketplace.</div>';
            });
    }

    function preencherSelect(select, itens, placeholder) {
        if (!select) return;
        const atual = select.value;
        select.innerHTML = '<option value="">' + esc(placeholder) + '</option>'
            + itens.map((v) => '<option value="' + esc(v) + '">' + esc(v) + '</option>').join('');
        if (itens.includes(atual)) select.value = atual;
    }

    function renderizarComparativoSegmentos() {
        const box = $('mkt-comparativo-box');
        const lista = $('mkt-comparativo-lista');
        if (!box || !lista) return;
        if (!STATE.comparativoSegmentos.length) {
            box.hidden = true;
            return;
        }
        box.hidden = false;
        lista.innerHTML = STATE.comparativoSegmentos.map((seg) => {
            const ativo = STATE.nichoAtivo === seg.nicho ? 'ativo' : '';
            const total = seg.total_lojas != null ? seg.total_lojas : (seg.qtd_lojas || 0);
            const media = seg.preco_medio_segmento != null ? seg.preco_medio_segmento : (seg.preco_medio || 0);
            const melhor = seg.melhor_preco_medio != null ? seg.melhor_preco_medio : (seg.menor_preco_medio || 0);
            const ecoPct = seg.economia_melhor_pct || 0;
            const eco = ecoPct > 0 ? ' · até ' + ecoPct + '% mais barato' : '';
            return '<button type="button" class="mkt-comp-card ' + ativo + '" data-nicho-pick="' + esc(seg.nicho) + '">'
                + '<div class="mkt-comp-top"><span>' + esc(seg.nicho_rotulo || seg.nicho) + '</span><span>' + total + ' loja(s)</span></div>'
                + '<div class="mkt-comp-preco">Média ' + brl(media) + ' · Melhor: ' + brl(melhor) + eco + '</div>'
                + '<div class="mkt-comp-loja">Mais econômica: ' + esc(seg.melhor_loja_nome || seg.loja_mais_barata_nome || '—') + '</div>'
                + '</button>';
        }).join('');

        lista.querySelectorAll('[data-nicho-pick]').forEach((btn) => {
            btn.addEventListener('click', () => selecionarSegmento(btn.dataset.nichoPick));
        });
    }

    function renderizarExpansao() {
        const box = $('mkt-expansao-box');
        if (!box) return;
        if (!STATE.alertaExpansao) {
            box.hidden = true;
            return;
        }
        const a = STATE.alertaExpansao;
        const ehRaioEstendido = a.tipo === 'raio_cnpj_estendido';
        let html = '<div class="mkt-expansao-title"><i class="fa-solid ' + (ehRaioEstendido ? 'fa-truck-fast' : 'fa-wand-magic-sparkles') + '"></i> '
            + (ehRaioEstendido ? 'IA encontrou empresas com entrega estendida na sua região' : 'IA sugere segmentos disponíveis com melhor preço')
            + '</div><p class="mkt-expansao-msg">' + esc(a.mensagem || '') + '</p>';

        if (Array.isArray(a.opcoes) && a.opcoes.length) {
            html += '<div class="mkt-expansao-chips">' + a.opcoes.map((op) => {
                if (op.slug) {
                    return '<button type="button" class="mkt-exp-chip" data-abrir-loja="' + esc(op.slug) + '">'
                        + esc(op.nome) + ' (' + op.distancia_km + ' km · raio até ' + op.raio_entrega_km + ' km)</button>';
                }
                if (op.nicho) {
                    return '<button type="button" class="mkt-exp-chip" data-mudar-nicho="' + esc(op.nicho) + '">'
                        + esc(op.nicho_rotulo) + ' · ' + esc(op.melhor_loja_nome) + ' (' + brl(op.melhor_preco_medio) + ')</button>';
                }
                return '';
            }).join('') + '</div>';
        }
        box.innerHTML = html;
        box.hidden = false;

        box.querySelectorAll('[data-abrir-loja]').forEach((b) => {
            b.addEventListener('click', () => abrirPaginaEmpresa(b.dataset.abrirLoja));
        });
        box.querySelectorAll('[data-mudar-nicho]').forEach((b) => {
            b.addEventListener('click', () => selecionarSegmento(b.dataset.mudarNicho));
        });
    }

    function renderizarRadarIa(algRes) {
        const box = $('mkt-ia-radar');
        if (!box) return;
        const podeIA = !(window.AcheIAkiLGPD && typeof window.AcheIAkiLGPD.podeUsarIA === 'function')
            || window.AcheIAkiLGPD.podeUsarIA();
        if (!podeIA || !algRes || !algRes.success) {
            box.hidden = true;
            return;
        }
        const hab = algRes.loja_habitual || null;
        const perf = algRes.perfil_usuario || {};
        const buscas = Array.isArray(perf.buscas_frequentes) ? perf.buscas_frequentes : [];
        const interesses = Array.isArray(perf.interesses_detectados) ? perf.interesses_detectados : [];
        const necessidades = Array.isArray(perf.necessidades_detectadas) ? perf.necessidades_detectadas : [];

        if (!hab && !buscas.length && !interesses.length && !necessidades.length) {
            box.hidden = true;
            return;
        }

        let col1 = '';
        if (hab) {
            const subHab = hab.compras_cliente > 0
                ? (hab.compras_cliente + ' pedido(s) feitos · ' + (hab.aberto ? 'Aberta agora' : 'Fechada no momento'))
                : ('Sua favorita · ' + (hab.aberto ? 'Aberta agora' : 'Fechada no momento'));
            col1 = '<div class="mkt-radar-col">'
                + '<h3><i class="fa-solid fa-heart-circle-check" style="color:#fb923c;"></i> Onde você mais pede</h3>'
                + '<p>Toque para abrir rápido a loja que você já conhece:</p>'
                + '<div class="mkt-radar-loja">'
                + '<div><strong>' + esc(hab.nome) + '</strong><small>' + esc(subHab) + (hab.distancia_km != null ? ' · ' + hab.distancia_km + ' km' : '') + '</small></div>'
                + '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
                + '<button type="button" class="mkt-radar-chip" data-radar-ver="' + esc(hab.slug) + '">Ver detalhes</button>'
                + '<a href="' + esc(hab.url_loja) + '" class="mkt-banner-btn-rastreio" style="padding:7px 12px;font-size:0.82rem;">Pedir de novo →</a>'
                + '</div></div></div>';
        } else {
            col1 = '<div class="mkt-radar-col">'
                + '<h3><i class="fa-solid fa-star" style="color:#fb923c;"></i> Sugestões pra você agora</h3>'
                + '<p>Momento atual: <strong>' + esc(algRes.turno_rotulo || 'Para Você') + '</strong>. '
                + (necessidades.length ? esc(necessidades.join(' · ')) : 'Veja os produtos e ofertas que mais combinam com você.') + '</p>'
                + '</div>';
        }

        const chipsBusca = buscas.map((b) => '<button type="button" class="mkt-radar-chip" data-radar-busca="' + esc(b) + '"><i class="fa-solid fa-magnifying-glass"></i> ' + esc(b) + '</button>');
        const chipsInteresse = interesses.filter((t) => !buscas.includes(t)).slice(0, 4).map((t) => '<button type="button" class="mkt-radar-chip" data-radar-busca="' + esc(t) + '"><i class="fa-solid fa-star"></i> ' + esc(t) + '</button>');
        const todosChips = chipsBusca.concat(chipsInteresse);

        const col2 = '<div class="mkt-radar-col">'
            + '<h3><i class="fa-solid fa-fire-flame-curved" style="color:#fb923c;"></i> O que você costuma buscar</h3>'
            + '<p>' + (necessidades.length ? esc(necessidades.join(' · ')) : 'Toque em um botão para procurar na hora:') + '</p>'
            + (todosChips.length ? '<div class="mkt-radar-chips">' + todosChips.join('') + '</div>' : '<small style="color:#d6d3d1;">Suas buscas aparecerão aqui.</small>')
            + '</div>';

        box.innerHTML = col1 + col2;
        box.hidden = false;

        box.querySelectorAll('[data-radar-ver]').forEach((b) => {
            b.addEventListener('click', () => abrirPaginaEmpresa(b.dataset.radarVer));
        });
        box.querySelectorAll('[data-radar-busca]').forEach((b) => {
            b.addEventListener('click', () => {
                if ($('mkt-busca')) {
                    $('mkt-busca').value = b.dataset.radarBusca;
                    carregarMarketplace();
                }
            });
        });
    }

    function renderizarPratosIa(algRes) {
        const sec = $('mkt-secao-pratos');
        const lista = $('mkt-pratos-lista');
        if (!sec || !lista) return;
        if (!STATE.pratosIa.length) {
            sec.hidden = true;
            return;
        }
        sec.hidden = false;
        if (algRes && algRes.turno_rotulo && $('mkt-pratos-titulo')) {
            $('mkt-pratos-titulo').innerHTML = '<i class="fa-solid fa-fire-flame-curved"></i> Para você — ' + esc(algRes.turno_rotulo);
        }
        lista.innerHTML = STATE.pratosIa.slice(0, 12).map((p) => {
            const img = p.imagem ? '<img src="' + esc(p.imagem) + '" alt="' + esc(p.nome) + '">' : '<div style="display:grid;place-items:center;height:100%;font-size:1.75rem;color:#9ca3af;"><i class="fa-solid fa-utensils"></i></div>';
            const tag = p.ja_pedido ? 'Você já pediu' : (p.motivo || p.loja_nome || 'Destaque IA');
            const slugAlvo = p.tenant || p.loja_slug || 'default';
            return '<div class="mkt-prato-card" data-loja-prato="' + esc(slugAlvo) + '" data-prato-nome="' + esc(p.nome) + '" data-prato-preco="' + esc(p.preco) + '" data-prato-nicho="' + esc(p.nicho || '') + '">'
                + '<div class="mkt-prato-img">' + img + '<span class="mkt-prato-tag">' + esc(tag) + '</span></div>'
                + '<div class="mkt-prato-body">'
                + '<div class="mkt-prato-nome">' + esc(p.nome) + '</div>'
                + '<div class="mkt-prato-loja">' + esc(p.loja_nome) + (p.distancia_km != null ? ' · ' + p.distancia_km + ' km' : '') + '</div>'
                + '<div class="mkt-prato-foot"><span>' + brl(p.preco) + '</span><span style="font-size:0.78rem;color:#ea580c;">Pedir →</span></div>'
                + '</div></div>';
        }).join('');

        lista.querySelectorAll('[data-loja-prato]').forEach((card) => {
            card.addEventListener('click', () => {
                if (window.PZAlgoritmo && window.PZAlgoritmo.registrar) {
                    window.PZAlgoritmo.registrar('click_produto', {
                        tenant: card.dataset.lojaPrato,
                        nicho: card.dataset.pratoNicho || '',
                        produto_nome: card.dataset.pratoNome || '',
                        preco: Number(card.dataset.pratoPreco || 0),
                    });
                }
                abrirPaginaEmpresa(card.dataset.lojaPrato);
            });
        });
    }

    function cardLojaHtml(loja) {
        const favs = (STATE.comprador && Array.isArray(STATE.comprador.favoritos)) ? STATE.comprador.favoritos : [];
        const ehFav = favs.includes(loja.slug);
        const bgStyle = loja.hero_url
            ? ''
            : 'background:linear-gradient(135deg,' + esc(loja.cor_primaria || '#ea1d2c') + ',' + esc(loja.cor_secundaria || '#1e293b') + ');';
        const capaImg = loja.hero_url ? '<img class="mkt-capa-bg" src="' + esc(loja.hero_url) + '" alt="' + esc(loja.nome) + '">' : '';
        const logoEl = loja.logo_url
            ? '<img class="mkt-logo" src="' + esc(loja.logo_url) + '" alt="' + esc(loja.nome) + '">'
            : '<div class="mkt-logo" style="color:' + esc(loja.cor_primaria || '#ea1d2c') + '">' + esc((loja.nome || 'L').slice(0, 1).toUpperCase()) + '</div>';

        const badges = [];
        if (loja.compras_cliente > 0) {
            badges.push('<span class="mkt-tag habitual"><i class="fa-solid fa-repeat"></i> Você já pediu ' + loja.compras_cliente + 'x</span>');
        } else if (loja.eh_loja_habitual) {
            badges.push('<span class="mkt-tag habitual"><i class="fa-solid fa-heart"></i> Sua preferida</span>');
        }
        if (loja.match_pct >= 60) {
            badges.push('<span class="mkt-tag ia-match"><i class="fa-solid fa-star"></i> Combina com você</span>');
        }
        if (loja.economia_vs_segmento_pct >= 5) {
            badges.push('<span class="mkt-tag eco"><i class="fa-solid fa-tag"></i> ' + loja.economia_vs_segmento_pct + '% mais barato</span>');
        } else if (loja.melhor_preco_segmento) {
            badges.push('<span class="mkt-tag eco"><i class="fa-solid fa-award"></i> Melhor preço da região</span>');
        }
        if (loja.entrega_estendida) {
            badges.push('<span class="mkt-tag estendida"><i class="fa-solid fa-truck-fast"></i> Entrega regional até ' + loja.raio_entrega_km + ' km</span>');
        }
        if (loja.fora_raio) {
            badges.push('<span class="mkt-tag alerta"><i class="fa-solid fa-circle-exclamation"></i> Acima do raio (' + loja.raio_entrega_km + ' km)</span>');
        }

        const cnpjBadge = loja.cnpj_verificado
            ? '<span class="mkt-cnpj-badge" title="CNPJ validado"><i class="fa-solid fa-circle-check"></i> CNPJ</span>'
            : '';

        const freteTxt = Number(loja.taxa_entrega_base || 0) === 0
            ? 'Frete grátis'
            : ('Frete ' + brl(loja.taxa_entrega_base) + (loja.frete && loja.frete.modo === 'distancia' && loja.distancia_km == null ? ' (informe CEP)' : ''));
        const distTxt = loja.distancia_km != null ? ' · ' + loja.distancia_km + ' km' : '';
        const pausaBadge = (loja.pausada_ate && Number(loja.pausada_ate) * 1000 > Date.now())
            ? 'Pausada (alta demanda)'
            : (loja.aberto ? 'Aberta agora' : 'Fechada');
        const avalRec = (Array.isArray(loja.avaliacoes_recentes) && loja.avaliacoes_recentes.find((a) => a && a.comentario)) || null;
        const quoteHtml = avalRec
            ? '<div class="mkt-card-quote">“' + esc(String(avalRec.comentario).slice(0, 92)) + (String(avalRec.comentario).length > 92 ? '…' : '') + '” — <strong>' + esc(avalRec.cliente_nome || 'Cliente') + '</strong></div>'
            : '';

        return '<article class="mkt-card">'
            + '<div class="mkt-card-capa" style="' + bgStyle + '">'
            + capaImg
            + '<div class="mkt-card-capa-badges">'
            + '<span class="mkt-status ' + (loja.aberto ? 'on' : 'off') + '">' + esc(pausaBadge) + '</span>'
            + '<button type="button" class="mkt-fav-btn ' + (ehFav ? 'ativo' : '') + '" data-fav="' + esc(loja.slug) + '" title="Salvar nos favoritos">'
            + '<i class="' + (ehFav ? 'fa-solid' : 'fa-regular') + ' fa-heart"></i></button>'
            + '</div></div>'
            + '<div class="mkt-card-body">'
            + '<div class="mkt-card-head">' + logoEl
            + '<div class="mkt-title-wrap"><h3>' + esc(loja.nome) + '</h3>'
            + '<div class="mkt-sub-meta"><span>' + esc(loja.nicho_rotulo || loja.nicho) + '</span><span>•</span><span>' + esc(loja.cidade || 'Local') + (loja.bairro ? ', ' + esc(loja.bairro) : '') + '</span>' + cnpjBadge + '</div>'
            + '</div></div>'
            + (badges.length ? '<div class="mkt-badges-row">' + badges.join('') + '</div>' : '')
            + (loja.motivo_algoritmo ? '<div class="mkt-card-motivo-ia"><i class="fa-solid fa-sparkles"></i> ' + esc(loja.motivo_algoritmo) + '</div>' : '')
            + '<div class="mkt-meta">'
            + '<button type="button" class="mkt-rating-btn" data-ver-opinioes="' + esc(loja.slug) + '" title="Ver todas as estrelas e opiniões">★ ' + esc(loja.nota_media) + ' (' + esc(loja.total_avaliacoes || 0) + ')</button>'
            + '<span><i class="fa-regular fa-clock"></i> ' + esc(loja.tempo_espera_txt || ((loja.tempo_medio_min || 35) + ' min')) + '</span>'
            + '<span><i class="fa-solid fa-motorcycle"></i> ' + esc(freteTxt + distTxt) + '</span>'
            + (Number(loja.preco_medio || 0) > 0 ? '<span>Média ' + brl(loja.preco_medio) + '</span>' : '')
            + '</div>'
            + quoteHtml
            + '<div class="mkt-card-actions">'
            + '<button type="button" class="mkt-btn-outline" data-ver-loja="' + esc(loja.slug) + '"><i class="fa-solid fa-circle-info"></i> Detalhes e notas</button>'
            + '<a class="mkt-btn-go" href="' + esc(loja.url_loja) + '" data-open-loja="' + esc(loja.slug) + '">Ver cardápio e pedir →</a>'
            + '</div></div></article>';
    }

    function rotuloNichoLegivel(nicho) {
        const mapa = {
            lanchonete: 'Lanches',
            pizzaria: 'Pizzas',
            marmitaria: 'Marmitas',
            pensao: 'Comida caseira',
            acai: 'Açaí',
            confeitaria: 'Doces e confeitaria',
        };
        const k = String(nicho || '').toLowerCase();
        return mapa[k] || (nicho ? nicho.charAt(0).toUpperCase() + nicho.slice(1) : 'este segmento');
    }

    function renderizarGridLojas(lojas, gridEl) {
        if (!gridEl) return;
        const resumo = $('mkt-lojas-resumo');
        if (gridEl === $('mkt-grid-lojas') && resumo) {
            resumo.textContent = lojas.length + ' loja(s) perto de você';
        }
        if (!lojas.length) {
            // Aba Favoritos vazia
            if (gridEl === $('mkt-grid-favoritos')) {
                gridEl.innerHTML = '<div class="aki-empty-state">'
                    + '<div class="aki-empty-ico"><i class="fa-regular fa-heart"></i></div>'
                    + '<h3>Você ainda não favoritou nenhuma loja</h3>'
                    + '<p>Toque no coração de qualquer empresa em Explorar para deixar suas preferidas sempre à mão.</p>'
                    + '<div class="aki-empty-actions">'
                    + '<button type="button" class="mkt-btn-prim" id="aki-fav-ir-explorar"><i class="fa-solid fa-compass"></i> Explorar lojas perto de mim</button>'
                    + '</div></div>';
                const btnIr = $('aki-fav-ir-explorar');
                if (btnIr) btnIr.addEventListener('click', () => trocarAba('descobrir'));
                return;
            }

            const cidSel = ($('mkt-cidade') && $('mkt-cidade').value) || '';
            const baiSel = ($('mkt-bairro') && $('mkt-bairro').value) || '';
            const buscaTxt = ($('mkt-busca') && $('mkt-busca').value.trim()) || '';
            const soAbertas = Boolean($('mkt-abertas') && $('mkt-abertas').checked);
            const soGratis = Boolean($('mkt-gratis') && $('mkt-gratis').checked);
            const locAtual = obterLocalAtual();
            const nomeLocal = [baiSel || locAtual.bairro, cidSel || locAtual.cidade].filter(Boolean).join(', ') || 'a sua região';

            const totalRegiao = Number(STATE.totalLojasRegiao || 0);
            const totalPlat = Number(STATE.totalLojasPlataforma || 0);
            const nichoNome = STATE.nichoAtivo ? rotuloNichoLegivel(STATE.nichoAtivo) : '';

            let icone = 'fa-map-location-dot';
            let titulo = '';
            let desc = '';
            let botoes = '';
            let extraAlts = '';

            if (totalRegiao === 0 || (STATE.nichoAtivo && totalRegiao === 0)) {
                // Cenário 1: Não existem empresas na localidade inteira do cliente
                icone = 'fa-map-location-dot';
                titulo = 'Ainda não temos lojas atendendo ' + esc(nomeLocal);
                desc = 'O AcheIAki está expandindo para novas cidades e bairros toda semana. No momento, nenhuma empresa parceira entrega exatamente neste endereço.'
                    + (STATE.nichoAtivo ? ' Você também havia selecionado o segmento <strong>' + esc(nichoNome) + '</strong>.' : '');
                botoes = '<button type="button" class="mkt-btn-prim" id="aki-empty-trocar-loc"><i class="fa-solid fa-location-crosshairs"></i> Trocar meu endereço / CEP</button>'
                    + (totalPlat > 0 && (cidSel || baiSel)
                        ? '<button type="button" class="mkt-btn-outline" id="aki-empty-todas-cidades"><i class="fa-solid fa-globe"></i> Ver lojas de outras cidades</button>'
                        : '')
                    + '<a href="cadastro.html?modo=cadastrar" class="mkt-btn-outline" style="text-decoration:none;"><i class="fa-solid fa-store"></i> Indicar ou cadastrar uma loja (CNPJ)</a>';
            } else if (STATE.nichoAtivo) {
                // Cenário 2: Há lojas na localidade, mas não há do nicho escolhido
                icone = 'fa-utensils';
                titulo = 'Ainda não encontramos opções de ' + esc(nichoNome) + ' perto de ' + esc(nomeLocal);
                desc = 'Temos <strong>' + totalRegiao + '</strong> loja(s) parceira(s) atendendo sua região, mas nenhuma do segmento <strong>' + esc(nichoNome) + '</strong> está disponível para este endereço agora.';
                botoes = '<button type="button" class="mkt-btn-prim" id="aki-empty-ver-todos-nichos"><i class="fa-solid fa-border-all"></i> Ver todas as lojas da minha região</button>'
                    + '<button type="button" class="mkt-btn-outline" id="aki-empty-trocar-loc"><i class="fa-solid fa-location-dot"></i> Testar outro endereço</button>';

                if (STATE.alternativas.length) {
                    extraAlts = '<div class="aki-empty-alts">'
                        + '<span>Outras opções que entregam perto de você agora:</span>'
                        + '<div class="mkt-expansao-chips" style="justify-content:center;margin-top:10px;">'
                        + STATE.alternativas.map((alt) => {
                            const segLbl = rotuloNichoLegivel(alt.nicho_rotulo || alt.nicho);
                            const precoInfo = Number(alt.preco_medio || 0) > 0 ? ' · Média ' + brl(alt.preco_medio) : '';
                            const freteInfo = Number(alt.taxa_entrega_base || 0) === 0 ? ' · Frete grátis' : '';
                            return '<button type="button" class="mkt-exp-chip" data-alt-slug="' + esc(alt.slug) + '">'
                                + esc(alt.nome) + ' · ' + esc(segLbl) + esc(precoInfo || freteInfo) + '</button>';
                        }).join('')
                        + '</div></div>';
                }
            } else {
                // Cenário 3: Filtros (abertas, grátis, busca) zeraram o resultado
                icone = 'fa-filter-circle-xmark';
                const motivos = [];
                if (buscaTxt) motivos.push('busca "' + esc(buscaTxt) + '"');
                if (soAbertas) motivos.push('Só abertas agora');
                if (soGratis) motivos.push('Entrega grátis');
                titulo = 'Nenhuma loja encontrada com os filtros ativos';
                desc = 'Existem <strong>' + totalRegiao + '</strong> loja(s) atendendo ' + esc(nomeLocal) + ', mas nenhuma combina com ' + (motivos.length ? motivos.join(' e ') : 'os filtros marcados') + ' no momento.';
                botoes = '<button type="button" class="mkt-btn-prim" id="aki-empty-limpar-filtros"><i class="fa-solid fa-rotate-left"></i> Limpar filtros e mostrar todas</button>';
            }

            gridEl.innerHTML = '<div class="aki-empty-state">'
                + '<div class="aki-empty-ico"><i class="fa-solid ' + icone + '"></i></div>'
                + '<h3>' + titulo + '</h3>'
                + '<p>' + desc + '</p>'
                + '<div class="aki-empty-actions">' + botoes + '</div>'
                + extraAlts
                + '</div>';

            if ($('aki-empty-trocar-loc')) $('aki-empty-trocar-loc').addEventListener('click', () => {
                if (window.PedidoZapLocalizacao && window.PedidoZapLocalizacao.abrirGate) {
                    window.PedidoZapLocalizacao.abrirGate();
                } else if (STATE.comprador) {
                    trocarAba('conta');
                }
            });
            if ($('aki-empty-todas-cidades')) $('aki-empty-todas-cidades').addEventListener('click', () => {
                if ($('mkt-cidade')) $('mkt-cidade').value = '';
                if ($('mkt-bairro')) $('mkt-bairro').value = '';
                if ($('mkt-busca')) $('mkt-busca').value = '';
                selecionarSegmento('');
            });
            if ($('aki-empty-ver-todos-nichos')) $('aki-empty-ver-todos-nichos').addEventListener('click', () => {
                selecionarSegmento('');
            });
            if ($('aki-empty-limpar-filtros')) $('aki-empty-limpar-filtros').addEventListener('click', () => {
                if ($('mkt-busca')) $('mkt-busca').value = '';
                if ($('mkt-abertas')) $('mkt-abertas').checked = false;
                if ($('mkt-gratis')) $('mkt-gratis').checked = false;
                if ($('mkt-cidade')) $('mkt-cidade').value = '';
                if ($('mkt-bairro')) $('mkt-bairro').value = '';
                selecionarSegmento('');
            });
            gridEl.querySelectorAll('[data-alt-slug]').forEach((b) => {
                b.addEventListener('click', () => abrirPaginaEmpresa(b.dataset.altSlug));
            });
            return;
        }
        gridEl.innerHTML = lojas.map(cardLojaHtml).join('');
        ligarEventosGrid(gridEl);
    }

    function ligarEventosGrid(container) {
        container.querySelectorAll('[data-ver-loja]').forEach((btn) => {
            btn.addEventListener('click', () => abrirPaginaEmpresa(btn.dataset.verLoja, false));
        });
        container.querySelectorAll('[data-ver-opinioes]').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                abrirPaginaEmpresa(btn.getAttribute('data-ver-opinioes'), true);
            });
        });
        container.querySelectorAll('[data-fav]').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                alternarFavorito(btn.dataset.fav);
            });
        });
        container.querySelectorAll('[data-open-loja]').forEach((a) => {
            a.addEventListener('click', () => {
                if (window.PZAlgoritmo && window.PZAlgoritmo.registrar) {
                    const lj = STATE.lojas.find((x) => x.slug === a.dataset.openLoja) || {};
                    window.PZAlgoritmo.registrar('abrir_loja', { tenant: a.dataset.openLoja, nicho: lj.nicho || '' });
                }
            });
        });
    }

    function renderizarFavoritos() {
        const grid = $('mkt-grid-favoritos');
        if (!grid) return;
        const favs = (STATE.comprador && Array.isArray(STATE.comprador.favoritos)) ? STATE.comprador.favoritos : [];
        const lojasFav = STATE.lojas.filter((l) => favs.includes(l.slug));
        if (!lojasFav.length) {
            grid.innerHTML = '<div class="mkt-empty">Você ainda não favoritou nenhuma empresa. Toque no ❤️ em qualquer loja para salvar aqui!</div>';
            return;
        }
        grid.innerHTML = lojasFav.map(cardLojaHtml).join('');
        ligarEventosGrid(grid);
    }

    function alternarFavorito(slug) {
        if (!STATE.token) {
            verificarGateObrigatorio(true);
            toast('Entre com Google + WhatsApp para favoritar empresas.');
            return;
        }
        fetch('comprador_api.php?action=alternar_favorito', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: STATE.token, slug }),
        })
            .then((r) => r.json())
            .then((d) => {
                if (d && d.success && STATE.comprador) {
                    STATE.comprador.favoritos = d.favoritos || [];
                    salvarSessaoComprador(STATE.token, STATE.comprador);
                    atualizarUiConta();
                    renderizarGridLojas(STATE.lojas, $('mkt-grid-lojas'));
                    renderizarFavoritos();
                    if (window.PZAlgoritmo && window.PZAlgoritmo.registrar && (d.favoritos || []).includes(slug)) {
                        const lj = STATE.lojas.find((x) => x.slug === slug) || {};
                        window.PZAlgoritmo.registrar('favorito', { tenant: slug, nicho: lj.nicho || '' });
                    }
                    toast('Favoritos atualizados!');
                }
            });
    }

    // =========================================================
    // VISUALIZADOR DA PÁGINA COMPLETA DA EMPRESA CNPJ
    // =========================================================
    function montarEstrelasTexto(n) {
        const nota = Math.max(1, Math.min(5, Math.round(Number(n) || 5)));
        return '★'.repeat(nota) + '☆'.repeat(5 - nota);
    }

    function renderizarBlocoReputacaoModal(loja) {
        const res = loja.resumo_avaliacoes || {};
        const total = Number(res.total != null ? res.total : (loja.total_avaliacoes || 0));
        const notaMedia = Number(res.nota_media != null ? res.nota_media : (loja.nota_media || 4.8)).toFixed(1);
        const dist = res.distribuicao || { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 };
        const pct = res.percentuais || { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 };
        const crit = res.criterios || {};
        const topTags = Array.isArray(res.top_tags) ? res.top_tags : [];

        const barrasHtml = [5, 4, 3, 2, 1].map((s) =>
            '<div class="mkt-rep-bar-row">'
            + '<span>' + s + '★</span>'
            + '<div class="mkt-rep-track"><div class="mkt-rep-fill" style="width:' + (pct[s] || 0) + '%"></div></div>'
            + '<span style="text-align:right;">' + (dist[s] || 0) + '</span>'
            + '</div>'
        ).join('');

        const critHtml = ['comida', 'entrega', 'custo'].map((k) => {
            if (!crit[k]) return '';
            const label = k === 'comida' ? 'Comida' : (k === 'entrega' ? 'Entrega' : 'Custo-benefício');
            return '<span class="mkt-rep-crit">' + label + ': ★ ' + esc(crit[k]) + '</span>';
        }).filter(Boolean).join('');

        const tagsHtml = topTags.length
            ? '<div class="mkt-rev-tags">' + topTags.map((t) => '<span class="mkt-rev-tag">' + esc(t.tag) + ' (' + esc(t.qtd) + ')</span>').join('') + '</div>'
            : '';

        return '<section class="mkt-rep-box" id="mkt-loja-reputacao">'
            + '<div class="mkt-rep-head">'
            + '<h3 style="margin:0;"><i class="fa-solid fa-star" style="color:#f59e0b"></i> Estrelas &amp; O que os clientes pensam</h3>'
            + '<button type="button" class="mkt-btn-prim" id="mkt-btn-avaliar-loja" data-av-direto-slug="' + esc(loja.slug) + '" data-av-direto-nome="' + esc(loja.nome) + '" style="padding:8px 14px;font-size:0.84rem;"><i class="fa-solid fa-pen"></i> Deixar avaliação</button>'
            + '</div>'
            + '<div class="mkt-rep-overview">'
            + '<div class="mkt-rep-score">'
            + '<strong>' + esc(notaMedia) + '</strong>'
            + '<div class="mkt-rep-stars">' + montarEstrelasTexto(notaMedia) + '</div>'
            + '<small>' + esc(total) + ' avaliação(ões) reais</small>'
            + '</div>'
            + '<div class="mkt-rep-bars">' + barrasHtml + '</div>'
            + '</div>'
            + (critHtml ? '<div class="mkt-rep-criterios">' + critHtml + '</div>' : '')
            + tagsHtml
            + '<div class="mkt-rep-filtros" id="mkt-rep-filtros">'
            + '<button type="button" class="mkt-rep-filtro-btn ativo" data-rep-filtro="todas">Todas</button>'
            + '<button type="button" class="mkt-rep-filtro-btn" data-rep-filtro="5">5★</button>'
            + '<button type="button" class="mkt-rep-filtro-btn" data-rep-filtro="4">4★</button>'
            + '<button type="button" class="mkt-rep-filtro-btn" data-rep-filtro="baixas">3★ ou menos</button>'
            + '<button type="button" class="mkt-rep-filtro-btn" data-rep-filtro="resposta">Com resposta da loja</button>'
            + '</div>'
            + '<div class="mkt-rev-list" id="mkt-rep-lista"></div>'
            + '</section>';
    }

    function pintarListaReputacao(avals, filtro) {
        const listaEl = $('mkt-rep-lista');
        if (!listaEl) return;
        const filtradas = (avals || []).filter((av) => {
            if (!av) return false;
            if (filtro === '5') return Number(av.nota) === 5;
            if (filtro === '4') return Number(av.nota) === 4;
            if (filtro === 'baixas') return Number(av.nota) <= 3;
            if (filtro === 'resposta') return Boolean(av.resposta_loja && av.resposta_loja.trim());
            return true;
        });
        if (!filtradas.length) {
            listaEl.innerHTML = '<div class="mkt-empty" style="padding:18px;">Nenhuma avaliação com este filtro ainda. Seja o primeiro a opinar!</div>';
            return;
        }
        listaEl.innerHTML = filtradas.map((av) => {
            const tags = Array.isArray(av.melhorias_tags) && av.melhorias_tags.length
                ? '<div class="mkt-rev-tags">' + av.melhorias_tags.map((t) => '<span class="mkt-rev-tag">' + esc(t) + '</span>').join('') + '</div>'
                : '';
            const verif = av.verificada ? '<span class="mkt-rev-verif"><i class="fa-solid fa-check"></i> Pedido verificado</span>' : '';
            const resp = av.resposta_loja
                ? '<div class="mkt-rev-reply"><strong>Resposta de ' + esc(av.resposta_autor || 'Loja') + ':</strong> ' + esc(av.resposta_loja) + '</div>'
                : '';
            const dataFmt = av.criado_em ? '<small style="color:#64748b;">' + esc(String(av.criado_em).slice(0, 10).split('-').reverse().join('/')) + '</small>' : '';
            return '<article class="mkt-rev-card">'
                + '<div class="mkt-rev-top">'
                + '<div><strong>' + esc(av.cliente_nome || 'Cliente') + '</strong> ' + verif + '<div style="color:#f59e0b;font-size:0.95rem;margin-top:2px;">' + montarEstrelasTexto(av.nota || 5) + '</div></div>'
                + dataFmt
                + '</div>'
                + (av.comentario ? '<p style="margin:0;color:#1e293b;font-size:0.9rem;line-height:1.45;">' + esc(av.comentario) + '</p>' : '')
                + tags
                + resp
                + '</article>';
        }).join('');
    }

    function abrirPaginaEmpresa(slug, irParaAvaliacoes) {
        const modal = $('mkt-modal-loja');
        const box = $('mkt-loja-conteudo');
        if (!modal || !box) return;
        STATE.lojaAtualSlug = slug;
        modal.hidden = false;
        box.innerHTML = '<div class="mkt-empty">Carregando página completa criada pela empresa...</div>';

        const l = obterLocalAtual();
        const url = 'marketplace_api.php?action=detalhar_loja&slug=' + encodeURIComponent(slug)
            + '&lat=' + encodeURIComponent(l.lat || 0)
            + '&lng=' + encodeURIComponent(l.lng || 0);

        fetch(url)
            .then((r) => r.json())
            .then((res) => {
                if (!res || !res.success || !res.loja) {
                    box.innerHTML = '<div class="mkt-empty">Não foi possível carregar os dados desta empresa.</div>';
                    return;
                }
                renderizarPaginaCompletaEmpresa(res.loja, box);
                if (irParaAvaliacoes) {
                    setTimeout(() => {
                        const secRep = $('mkt-loja-reputacao');
                        if (secRep) secRep.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 80);
                }
                if (window.PZAlgoritmo && window.PZAlgoritmo.registrar) {
                    window.PZAlgoritmo.registrar('view_loja', { tenant: slug, nicho: res.loja.nicho || '' });
                }
            })
            .catch(() => {
                box.innerHTML = '<div class="mkt-empty">Erro ao carregar página da empresa.</div>';
            });
    }

    function renderizarPaginaCompletaEmpresa(loja, box) {
        const corPrim = loja.cor_primaria || '#ea1d2c';
        const corSec = loja.cor_secundaria || '#0f172a';
        const heroBg = loja.hero_url
            ? 'background-image:url(' + JSON.stringify(loja.hero_url) + ');background-color:' + esc(corSec) + ';'
            : 'background:linear-gradient(135deg,' + esc(corPrim) + ',' + esc(corSec) + ');';
        const logoHtml = loja.logo_url
            ? '<img class="mkt-loja-logo-lg" src="' + esc(loja.logo_url) + '" alt="' + esc(loja.nome) + '">'
            : '<div class="mkt-loja-logo-lg" style="color:' + esc(corPrim) + '">' + esc((loja.nome || 'L').slice(0, 1).toUpperCase()) + '</div>';

        const end = loja.endereco || {};
        const endLinha = [end.rua, end.numero, end.bairro, (end.cidade || '') + (end.estado ? '/' + end.estado : ''), end.cep ? 'CEP ' + end.cep : ''].filter(Boolean).join(', ');
        const pagamentosLista = Array.isArray(loja.pagamentos) ? loja.pagamentos.join(' • ') : 'Consulte na loja';
        const cuponsHtml = (Array.isArray(loja.cupons_lista) && loja.cupons_lista.length)
            ? '<div class="mkt-cupons-row">' + loja.cupons_lista.map((c) => {
                const desc = c.tipo === 'percentual' ? (c.valor + '% OFF') : (c.tipo === 'frete_gratis' ? 'Frete grátis' : (brl(c.valor) + ' OFF'));
                return '<button type="button" class="mkt-cupom-chip" data-copy-cupom="' + esc(c.codigo) + '">🎟 ' + esc(c.codigo) + ' (' + esc(desc) + ') — toque para copiar</button>';
            }).join('') + '</div>'
            : '';

        // Agrupar todos os itens por categoria mantendo ordem original
        const categoriasMeta = (loja.categorias && typeof loja.categorias === 'object') ? loja.categorias : {};
        const grupos = {};
        (loja.itens || []).forEach((it) => {
            const catId = it.categoria || 'cardapio';
            if (!grupos[catId]) grupos[catId] = [];
            grupos[catId].push(it);
        });
        const chavesCat = Object.keys(grupos);
        const catPills = chavesCat.map((cid) => {
            const rot = (categoriasMeta[cid] && categoriasMeta[cid].nome) ? categoriasMeta[cid].nome : cid;
            return '<a class="mkt-cat-pill" href="#mkt-cat-' + esc(cid) + '">' + esc(rot) + ' (' + grupos[cid].length + ')</a>';
        }).join('');

        const cardapioHtml = chavesCat.length
            ? chavesCat.map((cid) => {
                const rot = (categoriasMeta[cid] && categoriasMeta[cid].nome) ? categoriasMeta[cid].nome : cid;
                const cards = grupos[cid].map((it) => {
                    const img = it.imagem ? '<img class="mkt-item-thumb" src="' + esc(it.imagem) + '" alt="' + esc(it.nome) + '">' : '';
                    const orig = it.preco_original && it.preco_original > it.preco ? '<del>' + brl(it.preco_original) + '</del>' : '';
                    const extras = [];
                    if (Array.isArray(it.tamanhos) && it.tamanhos.length) extras.push(it.tamanhos.length + ' tamanho(s)');
                    if (Array.isArray(it.opcionais) && it.opcionais.length) extras.push(it.opcionais.length + ' grupo(s) de opcionais');
                    return '<div class="mkt-item-box">' + img
                        + '<div class="mkt-item-txt">'
                        + '<h4>' + esc(it.nome) + (it.destaque ? ' <span style="color:#ea1d2c;font-size:0.75rem;">★ Destaque</span>' : '') + '</h4>'
                        + (it.descricao ? '<p>' + esc(it.descricao) + '</p>' : '')
                        + (extras.length ? '<small style="color:#64748b;">' + esc(extras.join(' · ')) + '</small>' : '')
                        + '<div class="mkt-item-preco">' + orig + brl(it.preco) + '</div>'
                        + '</div></div>';
                }).join('');
                return '<div id="mkt-cat-' + esc(cid) + '"><h3 style="margin:12px 0 8px;">' + esc(rot) + '</h3><div class="mkt-itens-grid">' + cards + '</div></div>';
            }).join('')
            : '<div class="mkt-empty">Esta empresa ainda está cadastrando os itens do cardápio.</div>';

        const avalsHtml = renderizarBlocoReputacaoModal(loja);

        const distInfo = loja.distancia_km != null
            ? loja.distancia_km + ' km de você (raio até ' + loja.raio_entrega_km + ' km' + (loja.entrega_estendida ? ' — entrega regional' : '') + ')'
            : 'Raio de entrega até ' + loja.raio_entrega_km + ' km';

        const compSegTxt = loja.media_preco_segmento > 0
            ? 'Média da loja: ' + brl(loja.preco_medio) + ' vs média do segmento: ' + brl(loja.media_preco_segmento)
              + (loja.economia_vs_segmento_pct > 0 ? ' (' + loja.economia_vs_segmento_pct + '% mais barata!)' : '')
            : 'Média do cardápio: ' + brl(loja.preco_medio);

        box.innerHTML = ''
            + '<div class="mkt-loja-hero" style="' + heroBg + '">'
            + '<div class="mkt-loja-hero-inner">' + logoHtml
            + '<div>'
            + '<span class="mkt-status ' + (loja.aberto ? 'on' : 'off') + '">' + (loja.aberto ? 'Aberta agora' : 'Fechada no momento') + '</span> '
            + (loja.cnpj_verificado ? '<span class="mkt-status" style="background:#ecfdf5;color:#15803d;">✔ Loja Verificada (CNPJ)</span>' : '')
            + '<h2>' + esc(loja.hero_titulo || loja.nome) + '</h2>'
            + '<p>' + esc(loja.hero_subtitulo || loja.slogan || loja.nicho_rotulo) + '</p>'
            + '</div></div></div>'
            + '<div class="mkt-loja-body">'
            + '<div class="mkt-loja-info-grid">'
            + '<div class="mkt-info-pill"><strong>Razão Social &amp; CNPJ</strong>' + esc(loja.razao_social || loja.nome) + '<br>' + esc(loja.cnpj ? fmtCnpj(loja.cnpj) : 'CNPJ não informado') + '</div>'
            + '<div class="mkt-info-pill"><strong>Endereço &amp; Distância</strong>' + esc(endLinha || 'Endereço não cadastrado') + '<br><span style="color:#ea580c;font-weight:700;">' + esc(distInfo) + '</span></div>'
            + '<div class="mkt-info-pill"><strong>Horários &amp; Entrega</strong>' + esc((loja.horarios && loja.horarios.TEXTO) || loja.tempo_espera_txt) + '<br>Frete base: ' + (Number(loja.taxa_entrega_base || 0) === 0 ? 'Grátis' : brl(loja.taxa_entrega_base)) + ' · ' + esc(loja.tempo_espera_txt) + '</div>'
            + '<div class="mkt-info-pill"><strong>Pagamentos &amp; Preço IA</strong>' + esc(pagamentosLista) + '<br><span style="color:#15803d;font-weight:700;">' + esc(compSegTxt) + '</span></div>'
            + '</div>'
            + cuponsHtml
            + (catPills ? '<div class="mkt-cat-pills">' + catPills + '</div>' : '')
            + cardapioHtml
            + avalsHtml
            + '</div>'
            + '<div class="mkt-loja-footer-cta">'
            + '<div><strong>' + esc(loja.nome) + '</strong><br><small style="color:#64748b;">' + (loja.fora_raio ? 'Atenção: fora do raio padrão de entrega (' + loja.raio_entrega_km + ' km), confira retirada ou frete na loja.' : 'Atendimento dentro do raio definido pela empresa.') + '</small></div>'
            + '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
            + (loja.whatsapp ? '<a class="mkt-btn-outline" target="_blank" rel="noopener" href="https://wa.me/55' + esc(loja.whatsapp.replace(/\D/g, '')) + '"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>' : '')
            + '<a class="mkt-btn-prim" style="background:' + esc(corPrim) + ';text-decoration:none;" href="' + esc(loja.url_loja) + '"><i class="fa-solid fa-cart-shopping"></i> Fazer Pedido na Loja →</a>'
            + '</div></div>';

        box.querySelectorAll('[data-copy-cupom]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const cod = btn.dataset.copyCupom;
                if (navigator.clipboard) navigator.clipboard.writeText(cod).catch(() => {});
                toast('Cupom ' + cod + ' copiado! Cole no carrinho da loja.');
            });
        });

        const avalsOriginais = Array.isArray(loja.avaliacoes) ? loja.avaliacoes : [];
        pintarListaReputacao(avalsOriginais, 'todas');
        box.querySelectorAll('#mkt-rep-filtros [data-rep-filtro]').forEach((fb) => {
            fb.addEventListener('click', () => {
                box.querySelectorAll('#mkt-rep-filtros [data-rep-filtro]').forEach((x) => x.classList.remove('ativo'));
                fb.classList.add('ativo');
                pintarListaReputacao(avalsOriginais, fb.getAttribute('data-rep-filtro'));
            });
        });
        const btnAvDireto = $('mkt-btn-avaliar-loja');
        if (btnAvDireto) {
            btnAvDireto.addEventListener('click', () => {
                abrirModalAvaliar(btnAvDireto.getAttribute('data-av-direto-slug'), btnAvDireto.getAttribute('data-av-direto-nome'), '');
            });
        }
    }

    // =========================================================
    // PEDIDOS EM TEMPO REAL
    // =========================================================
    function rotuloStatusPedido(st) {
        const s = String(st || 'novo').toLowerCase();
        if (s === 'preparando' || s === 'preparo' || s === 'cozinha') return 'Na cozinha (preparando)';
        if (s === 'em-rota' || s === 'em_entrega' || s === 'entrega' || s === 'saiu' || s === 'pronto') return 'Saiu para entrega';
        if (s === 'concluido' || s === 'entregue' || s === 'finalizado') return 'Entregue';
        if (s === 'cancelado') return 'Cancelado';
        return 'Recebido pela loja';
    }

    function atualizarBannerPedidoAtivo(peds) {
        const banner = $('mkt-banner-pedido-ativo');
        if (!banner) return;
        const lista = Array.isArray(peds) ? peds.slice() : [];
        if (!lista.length && window.PedidoZapCelulas) {
            window.PedidoZapCelulas.obterUltimosPedidos().forEach((loc) => lista.push(loc));
        }
        const ativos = lista.filter((p) => {
            const st = String((p && p.status) || 'novo').toLowerCase();
            return !['concluido', 'entregue', 'finalizado', 'cancelado'].includes(st);
        });
        if (!ativos.length) {
            banner.hidden = true;
            banner.innerHTML = '';
            return;
        }
        const ativo = ativos[0];
        const rastreioUrl = ativo.url_rastreio || (window.PedidoZapCelulas
            ? window.PedidoZapCelulas.urlComTenant('rastreio.html?id=' + encodeURIComponent(ativo.id) + (ativo.codigo ? '&c=' + encodeURIComponent(ativo.codigo) : ''), ativo.tenant || 'default')
            : ('rastreio.html?id=' + encodeURIComponent(ativo.id)));
        const mais = ativos.length > 1 ? ' · +' + (ativos.length - 1) + ' ativo(s)' : '';
        banner.innerHTML = ''
            + '<div class="mkt-banner-ativo-info">'
            + '<span class="mkt-live-dot" aria-hidden="true"></span>'
            + '<div class="mkt-banner-ativo-txt">'
            + '<strong>Pedido #' + esc(ativo.codigo || ativo.id) + ' · ' + esc(ativo.loja_nome || ativo.tenant || 'Loja') + '</strong>'
            + '<small>' + esc(rotuloStatusPedido(ativo.status)) + (ativo.total ? ' · ' + brl(ativo.total) : '') + esc(mais) + '</small>'
            + '</div>'
            + '</div>'
            + '<div class="mkt-banner-ativo-acoes">'
            + '<a class="mkt-banner-btn-rastreio" href="' + esc(rastreioUrl) + '"><i class="fa-solid fa-route"></i> Rastrear ao vivo</a>'
            + '<button type="button" class="mkt-banner-btn-lista" id="mkt-banner-ir-pedidos"><i class="fa-solid fa-motorcycle"></i> Meus pedidos</button>'
            + '</div>';
        banner.hidden = false;
        const btnIr = $('mkt-banner-ir-pedidos');
        if (btnIr) btnIr.addEventListener('click', () => trocarAba('pedidos'));
    }

    function buscarPedidos(silencioso) {
        const telInput = $('mkt-tel-pedido');
        const telPref = (window.PedidoZapCelulas && window.PedidoZapCelulas.obterTelefonePreferido()) || '';
        const tel = (telInput && telInput.value.trim()) || (STATE.comprador && STATE.comprador.telefone) || telPref;
        const box = $('mkt-lista-pedidos');
        if (!tel.replace(/\D/g, '')) {
            atualizarBannerPedidoAtivo([]);
            if (!silencioso && box) box.innerHTML = '<div class="mkt-empty">Informe seu WhatsApp com DDD para acompanhar seus pedidos em tempo real.</div>';
            return;
        }
        if (telInput && !telInput.value.trim()) telInput.value = tel;
        const locais = (window.PedidoZapCelulas && typeof window.PedidoZapCelulas.obterUltimosPedidos === 'function')
            ? window.PedidoZapCelulas.obterUltimosPedidos()
            : [];
        const codigosLocais = locais.map((x) => x && x.codigo).filter(Boolean).join(',');
        const pedUrl = 'marketplace_api.php?action=meus_pedidos&telefone=' + encodeURIComponent(tel)
            + (STATE.token ? '&token=' + encodeURIComponent(STATE.token) : '')
            + (codigosLocais ? '&codigos=' + encodeURIComponent(codigosLocais) : '');
        fetch(pedUrl)
            .then((r) => r.json())
            .then((d) => {
                const peds = (d && d.success && Array.isArray(d.pedidos)) ? d.pedidos : [];
                if ($('mkt-badge-ped')) $('mkt-badge-ped').textContent = peds.length;
                atualizarBannerPedidoAtivo(peds);
                if (!box) return;
                if (!peds.length) {
                    box.innerHTML = '<div class="mkt-empty">Nenhum pedido encontrado para este WhatsApp ainda.</div>';
                    return;
                }
                const mapaPassos = { novo: 1, preparando: 2, preparo: 2, cozinha: 2, entrega: 3, saiu: 3, em_entrega: 3, 'em-rota': 3, pronto: 3, concluido: 4, entregue: 4, finalizado: 4 };
                box.innerHTML = peds.map((p) => {
                    const st = String(p.status || 'novo').toLowerCase();
                    const passo = mapaPassos[st] || 1;
                    const clsSt = (st === 'concluido' || st === 'entregue' || st === 'finalizado') ? 'concluido' : (st === 'cancelado' ? 'cancelado' : '');
                    const passosHtml = ['Recebido', 'Na Cozinha', 'Em Rota', 'Concluído'].map((nome, idx) =>
                        '<div class="mkt-step ' + ((passo >= idx + 1 && st !== 'cancelado') ? 'feito' : '') + '"><div class="mkt-dot"></div>' + nome + '</div>'
                    ).join('');
                    const itensResumo = (p.itens || []).map((it) => (it.quantidade || 1) + 'x ' + esc(it.nome || '')).join(', ');
                    return '<article class="mkt-ped-card">'
                        + '<div class="mkt-ped-top">'
                        + '<div><strong>' + esc(p.loja_nome || p.tenant) + '</strong> · Pedido <strong>#' + esc(p.codigo || p.id) + '</strong><br><small style="color:#64748b;">' + esc(p.criado_em || '') + ' · ' + esc(itensResumo || 'Itens do pedido') + '</small></div>'
                        + '<div style="text-align:right;"><span class="mkt-ped-status ' + clsSt + '">' + esc(st.toUpperCase()) + '</span><br><strong>' + brl(p.total) + '</strong></div>'
                        + '</div>'
                        + '<div class="mkt-timeline">' + passosHtml + '</div>'
                        + '<div class="mkt-ped-links">'
                        + (p.url_rastreio
                            ? '<a class="mkt-btn-outline" href="' + esc(p.url_rastreio) + '" style="text-decoration:none;"><i class="fa-solid fa-route"></i> Rastrear no mapa</a>'
                            : '<span style="font-size:0.8rem;color:#64748b;align-self:center;"><i class="fa-solid fa-lock"></i> Entre na conta para rastrear endereço</span>')
                        + '<button type="button" class="mkt-btn-outline" data-ver-loja-ped="' + esc(p.tenant) + '"><i class="fa-solid fa-store"></i> Ver loja</button>'
                        + '<button type="button" class="mkt-btn-outline" data-av-slug="' + esc(p.tenant) + '" data-av-nome="' + esc(p.loja_nome) + '" data-av-cod="' + esc(p.codigo || p.id) + '"><i class="fa-solid fa-star"></i> Avaliar</button>'
                        + '</div></article>';
                }).join('');

                box.querySelectorAll('[data-ver-loja-ped]').forEach((b) => {
                    b.addEventListener('click', () => abrirPaginaEmpresa(b.dataset.verLojaPed));
                });
                box.querySelectorAll('[data-av-slug]').forEach((b) => {
                    b.addEventListener('click', () => abrirModalAvaliar(b.dataset.avSlug, b.dataset.avNome, b.dataset.avCod));
                });
            })
            .catch(() => {});
    }

    function setEstrelasModal(n) {
        const val = Math.max(1, Math.min(5, Number(n) || 5));
        if ($('mkt-av-nota')) $('mkt-av-nota').value = String(val);
        document.querySelectorAll('#mkt-av-stars [data-av-star]').forEach((b) => {
            b.classList.toggle('ativo', Number(b.getAttribute('data-av-star')) <= val);
        });
        const labels = { 1: '1/5 · Precisa melhorar', 2: '2/5 · Abaixo do esperado', 3: '3/5 · Razoável', 4: '4/5 · Muito bom', 5: '5/5 · Excelente' };
        if ($('mkt-av-nota-label')) $('mkt-av-nota-label').textContent = labels[val] || '';
    }

    function abrirModalAvaliar(slug, nome, codigo) {
        $('mkt-av-slug').value = slug || 'default';
        $('mkt-av-codigo').value = codigo || '';
        $('mkt-av-loja-nome').textContent = (nome || slug) + (codigo ? ' · Pedido #' + codigo : ' · Avaliação pública do estabelecimento');
        $('mkt-av-msg').textContent = '';
        if ($('mkt-av-coment')) $('mkt-av-coment').value = '';
        setEstrelasModal(5);
        document.querySelectorAll('#mkt-av-tags .mkt-tag-chip-btn').forEach((c) => c.classList.remove('ativo'));
        const wrapNome = $('mkt-av-nome-wrap');
        if (wrapNome) {
            const precisaNome = !(STATE.comprador && STATE.comprador.nome);
            wrapNome.hidden = !precisaNome;
            if (!precisaNome && $('mkt-av-cliente-nome')) $('mkt-av-cliente-nome').value = STATE.comprador.nome;
        }
        $('mkt-modal-avaliar').hidden = false;
    }

    // =========================================================
    // ABA MINHA CONTA & ENDEREÇOS
    // =========================================================
    function renderizarAbaConta() {
        const c = STATE.comprador;
        if (!c) return;
        if ($('mkt-p-nome')) $('mkt-p-nome').value = c.nome || '';
        if ($('mkt-p-cpf')) $('mkt-p-cpf').value = c.cpf || '';
        if ($('mkt-p-tel')) $('mkt-p-tel').value = c.telefone || '';
        if ($('mkt-p-email')) $('mkt-p-email').value = c.email || '';
        if ($('mkt-conta-ia-chk')) {
            const lgpd = c.consentimento_lgpd || {};
            $('mkt-conta-ia-chk').checked = lgpd.ia_personalizacao !== undefined
                ? !!lgpd.ia_personalizacao
                : (window.AcheIAkiLGPD ? window.AcheIAkiLGPD.podeUsarIA() : true);
        }

        const listEl = $('mkt-enderecos-lista');
        if (!listEl) return;
        const ends = Array.isArray(c.enderecos) ? c.enderecos : [];
        if (!ends.length) {
            listEl.innerHTML = '<div class="mkt-alerta-erro">Nenhum endereço cadastrado ainda.</div>';
            return;
        }
        listEl.innerHTML = ends.map((e, idx) => {
            const ativo = idx === 0;
            const resumo = [e.rua, e.numero, e.bairro, (e.cidade || '') + (e.estado ? '/' + e.estado : ''), e.cep ? 'CEP ' + e.cep : ''].filter(Boolean).join(', ');
            return '<div class="mkt-end-item ' + (ativo ? 'ativo' : '') + '">'
                + '<div><strong>' + esc(e.rotulo || 'Endereço') + (ativo ? ' (Ativo)' : '') + '</strong><br><small style="color:#475569;">' + esc(resumo) + '</small></div>'
                + '<div style="display:flex;gap:6px;flex-wrap:wrap;">'
                + (!ativo ? '<button type="button" class="mkt-btn-sec-sm" data-end-ativar="' + idx + '">Usar este</button>' : '')
                + (ends.length > 1 ? '<button type="button" class="mkt-btn-sec-sm" data-end-del="' + idx + '">Remover</button>' : '')
                + '</div></div>';
        }).join('');

        listEl.querySelectorAll('[data-end-ativar]').forEach((b) => {
            b.addEventListener('click', () => {
                fetch('comprador_api.php?action=definir_endereco_ativo', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: STATE.token, index: Number(b.dataset.endAtivar) }),
                }).then((r) => r.json()).then((d) => {
                    if (d && d.success && d.comprador) {
                        salvarSessaoComprador(STATE.token, d.comprador);
                        atualizarUiConta();
                        carregarMarketplace();
                        toast('Endereço principal atualizado!');
                    }
                });
            });
        });
        listEl.querySelectorAll('[data-end-del]').forEach((b) => {
            b.addEventListener('click', () => {
                fetch('comprador_api.php?action=remover_endereco', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: STATE.token, index: Number(b.dataset.endDel) }),
                }).then((r) => r.json()).then((d) => {
                    if (d && d.success && d.comprador) {
                        salvarSessaoComprador(STATE.token, d.comprador);
                        atualizarUiConta();
                        toast('Endereço removido.');
                    } else if (d && d.message) {
                        toast(d.message);
                    }
                });
            });
        });
    }

    // =========================================================
    // NAVEGAÇÃO DE ABAS E SEGMENTOS
    // =========================================================
    function selecionarSegmento(nicho) {
        STATE.nichoAtivo = nicho || '';
        document.querySelectorAll('#mkt-segmentos .mkt-seg').forEach((b) => {
            b.classList.toggle('ativo', (b.dataset.nicho || '') === STATE.nichoAtivo);
        });
        if (STATE.nichoAtivo && window.PZAlgoritmo && window.PZAlgoritmo.registrar) {
            window.PZAlgoritmo.registrar('busca', { nicho: STATE.nichoAtivo, termo: STATE.nichoAtivo });
        }
        carregarMarketplace();
    }

    function trocarAba(aba) {
        STATE.abaAtiva = aba;
        document.querySelectorAll('#mkt-tabs .mkt-tab').forEach((t) => {
            t.classList.toggle('ativo', t.dataset.tab === aba);
        });
        ['descobrir', 'favoritos', 'pedidos', 'conta'].forEach((nome) => {
            const p = $('mkt-pane-' + nome);
            if (p) p.hidden = (nome !== aba);
        });
        clearInterval(STATE.pedidosTimer);
        if (aba === 'pedidos') {
            buscarPedidos(false);
            STATE.pedidosTimer = setInterval(() => buscarPedidos(true), 12000);
        }
        if (aba === 'favoritos') renderizarFavoritos();
        if (aba === 'conta') {
            if (!STATE.token) verificarGateObrigatorio(true);
            else renderizarAbaConta();
        }
    }

    // =========================================================
    // EVENTOS & BOOT
    // =========================================================
    function initEventos() {
        document.querySelectorAll('#mkt-tabs .mkt-tab').forEach((btn) => {
            btn.addEventListener('click', () => trocarAba(btn.dataset.tab));
        });
        document.querySelectorAll('#mkt-segmentos .mkt-seg').forEach((btn) => {
            btn.addEventListener('click', () => selecionarSegmento(btn.dataset.nicho || ''));
        });

        let timerBusca = null;
        let ultimaBuscaLogada = '';
        if ($('mkt-busca')) $('mkt-busca').addEventListener('input', () => {
            clearTimeout(timerBusca);
            timerBusca = setTimeout(() => {
                const q = $('mkt-busca').value.trim();
                if (q.length >= 3 && q.toLowerCase() !== ultimaBuscaLogada && window.PZAlgoritmo && window.PZAlgoritmo.registrar) {
                    ultimaBuscaLogada = q.toLowerCase();
                    window.PZAlgoritmo.registrar('busca', { termo: q, nicho: STATE.nichoAtivo || '' });
                }
                carregarMarketplace();
            }, 300);
        });
        ['mkt-cidade', 'mkt-bairro', 'mkt-ordenar', 'mkt-abertas', 'mkt-gratis'].forEach((id) => {
            if ($(id)) $(id).addEventListener('change', carregarMarketplace);
        });

        if ($('mkt-btn-local')) $('mkt-btn-local').addEventListener('click', () => {
            if (STATE.comprador && STATE.comprador.perfil_completo) trocarAba('conta');
            else if (window.PedidoZapLocalizacao && window.PedidoZapLocalizacao.abrirGate) window.PedidoZapLocalizacao.abrirGate();
        });
        if ($('mkt-btn-gps-topo')) $('mkt-btn-gps-topo').addEventListener('click', () => {
            if (window.PedidoZapLocalizacao && window.PedidoZapLocalizacao.abrirGate) window.PedidoZapLocalizacao.abrirGate();
        });
        if ($('mkt-btn-conta')) $('mkt-btn-conta').addEventListener('click', () => {
            if (STATE.comprador && STATE.comprador.perfil_completo) trocarAba('conta');
            else verificarGateObrigatorio(true, 'cliente');
        });
        if ($('mkt-btn-trocar-perfil')) $('mkt-btn-trocar-perfil').addEventListener('click', () => {
            verificarGateObrigatorio(true, 'escolha');
        });

        // Atalhos gigantes no topo da tela principal ("Na cara do usuário")
        document.querySelectorAll('[data-atalho-nicho]').forEach((btn) => {
            btn.addEventListener('click', () => {
                selecionarSegmento(btn.dataset.atalhoNicho || '');
                const grid = $('mkt-grid-lojas');
                if (grid) grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });
        if ($('mkt-atalho-baratos')) $('mkt-atalho-baratos').addEventListener('click', () => {
            if ($('mkt-ordenar')) $('mkt-ordenar').value = 'preco';
            selecionarSegmento('');
            const grid = $('mkt-grid-lojas');
            if (grid) grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        if ($('mkt-atalho-pedidos')) $('mkt-atalho-pedidos').addEventListener('click', () => trocarAba('pedidos'));

        // Navegação dentro do Portal Obrigatório ("Sou Cliente" vs "Sou Empresa CNPJ")
        if ($('mkt-escolha-cliente')) $('mkt-escolha-cliente').addEventListener('click', () => modoPortalEtapa('cliente'));
        if ($('mkt-escolha-empresa')) $('mkt-escolha-empresa').addEventListener('click', () => modoPortalEtapa('empresa'));
        if ($('mkt-voltar-empresa')) $('mkt-voltar-empresa').addEventListener('click', () => modoPortalEtapa('escolha'));
        if ($('mkt-voltar-cliente')) $('mkt-voltar-cliente').addEventListener('click', () => modoPortalEtapa('empresa'));
        if ($('mkt-gate-pular-demo')) $('mkt-gate-pular-demo').addEventListener('click', () => {
            sessionStorage.setItem('aki_demo_gate_ok', '1');
            $('mkt-modal-conta').hidden = true;
        });

        // Mostrar/ocultar senha
        document.querySelectorAll('[data-ver-senha]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const inp = $(btn.dataset.verSenha);
                if (!inp) return;
                const mostrando = inp.type === 'text';
                inp.type = mostrando ? 'password' : 'text';
                btn.innerHTML = mostrando
                    ? '<i class="fa-regular fa-eye"></i> Ver'
                    : '<i class="fa-regular fa-eye-slash"></i> Ocultar';
            });
        });

        if ($('mkt-fechar-conta')) $('mkt-fechar-conta').addEventListener('click', () => {
            if (window.PedidoZapApresentacao && window.PedidoZapApresentacao.ativo()) {
                sessionStorage.setItem('aki_demo_gate_ok', '1');
                $('mkt-modal-conta').hidden = true;
            } else if (STATE.comprador && STATE.comprador.perfil_completo) {
                $('mkt-modal-conta').hidden = true;
            }
        });
        if ($('mkt-fechar-loja')) $('mkt-fechar-loja').addEventListener('click', () => { $('mkt-modal-loja').hidden = true; });
        if ($('mkt-modal-loja')) $('mkt-modal-loja').addEventListener('click', (e) => { if (e.target === $('mkt-modal-loja')) $('mkt-modal-loja').hidden = true; });
        if ($('mkt-fechar-avaliar')) $('mkt-fechar-avaliar').addEventListener('click', () => { $('mkt-modal-avaliar').hidden = true; });

        // Tabs Login vs Cadastro no Gate
        document.querySelectorAll('#mkt-auth-tabs button').forEach((b) => {
            b.addEventListener('click', () => {
                document.querySelectorAll('#mkt-auth-tabs button').forEach((x) => x.classList.remove('ativo'));
                b.classList.add('ativo');
                const modo = b.dataset.auth;
                $('mkt-form-login').hidden = (modo !== 'login');
                $('mkt-form-cadastro').hidden = (modo !== 'cadastrar');
                $('mkt-conta-msg').textContent = '';
            });
        });

        // Máscaras CPF / CEP + Auto ViaCEP
        maskCpf($('mkt-cad-cpf'));
        maskCpf($('mkt-p-cpf'));
        maskCep($('mkt-cad-cep'), (via) => {
            if (via.logradouro && !$('mkt-cad-rua').value) $('mkt-cad-rua').value = via.logradouro;
            if (via.bairro && !$('mkt-cad-bairro').value) $('mkt-cad-bairro').value = via.bairro;
            if (via.localidade) $('mkt-cad-cidade').value = via.localidade;
            if (via.uf) $('mkt-cad-estado').value = via.uf;
            if (window.PedidoZapLocalizacao && window.PedidoZapLocalizacao.resolverEnderecoViaServidor) {
                const q = [via.logradouro, via.bairro, via.localidade, via.uf, 'Brasil'].filter(Boolean).join(', ');
                window.PedidoZapLocalizacao.resolverEnderecoViaServidor(q).then((g) => {
                    if (g && g.lat && g.lng) {
                        $('mkt-cad-lat').value = g.lat;
                        $('mkt-cad-lng').value = g.lng;
                    }
                }).catch(() => {});
            }
        });
        maskCep($('mkt-ne-cep'), (via) => {
            if (via.logradouro) $('mkt-ne-rua').value = via.logradouro;
            if (via.bairro) $('mkt-ne-bairro').value = via.bairro;
            if (via.localidade) $('mkt-ne-cidade').value = via.localidade;
            if (via.uf) $('mkt-ne-estado').value = via.uf;
        });

        async function preencherViaGpsELocal(statusEl, campos) {
            capturarGps(statusEl, async (lat, lng) => {
                if (campos.lat) campos.lat.value = lat;
                if (campos.lng) campos.lng.value = lng;
                let rev = {};
                try {
                    const r = await fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng), { headers: { Accept: 'application/json' } });
                    if (r.ok) {
                        const d = await r.json();
                        rev = (d && d.address) || {};
                    }
                } catch (e) {}
                const cidade = rev.city || rev.town || rev.village || rev.municipality || '';
                const bairro = rev.suburb || rev.neighbourhood || rev.quarter || '';
                const rua = rev.road || '';
                const cep = (rev.postcode || '').replace(/\D/g, '').slice(0, 8);
                if (campos.cidade && cidade) campos.cidade.value = cidade;
                if (campos.bairro && bairro) campos.bairro.value = bairro;
                if (campos.rua && rua) campos.rua.value = rua;
                if (campos.cep && cep.length === 8) campos.cep.value = cep.slice(0, 5) + '-' + cep.slice(5);
                if (window.PedidoZapLocalizacao && typeof window.PedidoZapLocalizacao.salvar === 'function') {
                    window.PedidoZapLocalizacao.salvar({ lat, lng, cidade, bairro, fonte: 'gps', label: [rua, bairro, cidade].filter(Boolean).join(', ') });
                }
                if (statusEl) {
                    statusEl.style.color = '#15803d';
                    statusEl.textContent = 'Localização encontrada' + (cidade ? ': ' + [bairro, cidade].filter(Boolean).join(', ') : '.');
                }
            });
        }

        if ($('mkt-cad-gps-btn')) $('mkt-cad-gps-btn').addEventListener('click', () => {
            preencherViaGpsELocal($('mkt-cad-gps-status'), {
                lat: $('mkt-cad-lat'),
                lng: $('mkt-cad-lng'),
                cidade: $('mkt-cad-cidade'),
                bairro: $('mkt-cad-bairro'),
                rua: $('mkt-cad-rua'),
                cep: $('mkt-cad-cep')
            });
        });

        if ($('mkt-conta-gps-btn')) $('mkt-conta-gps-btn').addEventListener('click', () => {
            preencherViaGpsELocal($('mkt-conta-gps-status'), {
                cidade: $('mkt-ne-cidade'),
                bairro: $('mkt-ne-bairro'),
                rua: $('mkt-ne-rua'),
                cep: $('mkt-ne-cep')
            });
        });

        if ($('mkt-btn-demo-admin')) {
            $('mkt-btn-demo-admin').addEventListener('click', async () => {
                const btn = $('mkt-btn-demo-admin');
                btn.disabled = true;
                try {
                    const r = await fetch('login.php', {
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
                        try { sessionStorage.setItem('admin_permissoes', JSON.stringify(d.permissoes || {})); } catch (err) {}
                        window.location.href = 'loja/loja-demonstracao-acheiaki/admin.html?tab=cardapio';
                        return;
                    }
                } catch (err) { /* fallback */ }
                btn.disabled = false;
                window.location.href = 'cadastro.html?modo=entrar';
            });
        }

        // Login ("Já tenho conta": empresa por usuário/nome da empresa + senha, ou comprador com senha)
        if ($('mkt-form-login')) $('mkt-form-login').addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);
            const ident = String(fd.get('telefone') || '').trim();
            const senha = String(fd.get('senha') || '');
            const msg = $('mkt-conta-msg');
            const chkLogin = $('mkt-login-termos');
            if (chkLogin && !chkLogin.checked) {
                msg.style.color = '#dc2626';
                msg.textContent = 'Marque a caixinha para aceitar continuar usando o sistema.';
                return;
            }
            if (window.AcheIAkiLGPD && typeof window.AcheIAkiLGPD.salvar === 'function') {
                window.AcheIAkiLGPD.salvar(true);
            }
            msg.style.color = '#334155';
            msg.textContent = 'Verificando sua conta...';

            try {
                const rEmp = await fetch('login.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user: ident, pass: senha, global_login: true }),
                });
                const dEmp = await rEmp.json();
                if (dEmp && dEmp.success) {
                    sessionStorage.setItem('admin_logado', 'true');
                    sessionStorage.setItem('admin_user', dEmp.user || ident);
                    sessionStorage.setItem('admin_pass', dEmp.token || senha);
                    sessionStorage.setItem('admin_role', dEmp.role || 'lojista');
                    sessionStorage.setItem('admin_nome', dEmp.nome || dEmp.user || ident);
                    sessionStorage.setItem('admin_funcao', dEmp.funcao || '');
                    sessionStorage.setItem('pedidozap_tenant_slug', dEmp.tenant || 'default');
                    try { sessionStorage.setItem('admin_permissoes', JSON.stringify(dEmp.permissoes || {})); } catch (err) {}
                    if (dEmp.role === 'master') {
                        sessionStorage.setItem('admin_master', '1');
                        window.location.href = 'superadmin.html';
                    } else {
                        sessionStorage.removeItem('admin_master');
                        window.location.href = dEmp.url_admin || ('admin.html?tenant=' + encodeURIComponent(dEmp.tenant || 'default'));
                    }
                    return;
                }
            } catch (err) { /* segue para tentativa de comprador ou exibe erro */ }

            // Fallback se for comprador com e-mail/WhatsApp + senha cadastrada
            const ehCompradorIdent = ident.includes('@') || ident.replace(/\D/g, '').length >= 10;
            if (ehCompradorIdent) {
                try {
                    const rComp = await fetch('comprador_api.php?action=login', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ telefone: ident, senha }),
                    });
                    const dComp = await rComp.json();
                    if (dComp && dComp.success) {
                        msg.textContent = '';
                        salvarSessaoComprador(dComp.token, dComp.comprador);
                        atualizarUiConta();
                        carregarMarketplace();
                        toast('Bem-vindo de volta!');
                        return;
                    }
                } catch (err) {}
            }

            msg.style.color = '#dc2626';
            msg.textContent = 'WhatsApp/E-mail ou senha incorretos. Se for novo, toque em "SOU NOVO (ME CADASTRAR)".';
        });

        // Cadastro simples obrigatório do comprador
        if ($('mkt-form-cadastro')) $('mkt-form-cadastro').addEventListener('submit', (e) => {
            e.preventDefault();
            const msg = $('mkt-conta-msg');
            const nome = $('mkt-cad-nome').value.trim();
            const cpf = $('mkt-cad-cpf').value.trim();
            const telefone = $('mkt-cad-tel').value.trim();
            const email = $('mkt-cad-email').value.trim();
            const senha = $('mkt-cad-senha') ? $('mkt-cad-senha').value : '';
            const endereco = {
                rotulo: 'Principal',
                cep: $('mkt-cad-cep').value.trim(),
                rua: $('mkt-cad-rua').value.trim(),
                numero: $('mkt-cad-numero').value.trim(),
                complemento: $('mkt-cad-comp').value.trim(),
                bairro: $('mkt-cad-bairro').value.trim(),
                cidade: $('mkt-cad-cidade').value.trim(),
                estado: $('mkt-cad-estado').value.trim().toUpperCase(),
                lat: parseFloat($('mkt-cad-lat').value || '0') || 0,
                lng: parseFloat($('mkt-cad-lng').value || '0') || 0,
            };

            if (!nome || nome.length < 2) {
                msg.style.color = '#dc2626';
                msg.textContent = 'Digite o seu nome para continuar.';
                return;
            }
            if (telefone.replace(/\D/g, '').length < 10) {
                msg.style.color = '#dc2626';
                msg.textContent = 'Digite seu WhatsApp com DDD (ex.: 62 99999-9999).';
                return;
            }
            if (!email || email.indexOf('@') === -1) {
                msg.style.color = '#dc2626';
                msg.textContent = 'Digite um e-mail válido.';
                return;
            }
            if (!STATE.modoEdicaoGate && senha.length < 6) {
                msg.style.color = '#dc2626';
                msg.textContent = 'Crie uma senha com pelo menos 6 letras ou números.';
                return;
            }

            const locSalva = obterLocalAtual();
            if (!endereco.lat && locSalva.lat) endereco.lat = locSalva.lat;
            if (!endereco.lng && locSalva.lng) endereco.lng = locSalva.lng;
            if (endereco.lat && endereco.lng && window.PedidoZapLocalizacao && typeof window.PedidoZapLocalizacao.salvar === 'function') {
                window.PedidoZapLocalizacao.salvar({
                    lat: endereco.lat,
                    lng: endereco.lng,
                    cidade: endereco.cidade,
                    bairro: endereco.bairro,
                    fonte: 'cadastro',
                    label: [endereco.rua, endereco.bairro, endereco.cidade].filter(Boolean).join(', ')
                });
            }

            const chkTermos = $('mkt-cad-termos');
            if (chkTermos && !chkTermos.checked) {
                msg.style.color = '#dc2626';
                msg.textContent = 'Marque a caixinha para aceitar os Termos e entrar.';
                return;
            }
            const iaOptIn = $('mkt-cad-ia') ? $('mkt-cad-ia').checked : true;
            if (window.AcheIAkiLGPD && typeof window.AcheIAkiLGPD.salvar === 'function') {
                window.AcheIAkiLGPD.salvar(iaOptIn);
            }

            msg.style.color = '#334155';
            msg.textContent = 'Criando sua conta...';

            let url = 'comprador_api.php?action=cadastrar';
            let payload = { nome, cpf, telefone, email, senha, endereco, ia_personalizacao: iaOptIn };
            if (STATE.modoEdicaoGate) {
                url = 'comprador_api.php?action=atualizar_perfil';
                payload = { token: STATE.token, nome, cpf, email, endereco };
            } else if (senha === '') {
                url = 'comprador_api.php?action=entrar_google';
                payload = { nome, cpf, telefone, email, endereco, ia_personalizacao: iaOptIn };
            }

            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            }).then((r) => r.json()).then((d) => {
                if (!d.success) {
                    msg.style.color = '#dc2626';
                    msg.textContent = d.message || 'Erro ao criar conta. Tente novamente.';
                    return;
                }
                msg.textContent = '';
                salvarSessaoComprador(d.token || STATE.token, d.comprador);
                atualizarUiConta();
                carregarMarketplace();
                toast('Tudo pronto! Boas compras no AcheIAki.');
            });
        });

        // Editar perfil na aba Conta
        if ($('mkt-form-perfil')) $('mkt-form-perfil').addEventListener('submit', (e) => {
            e.preventDefault();
            const msg = $('mkt-p-msg');
            const cpf = $('mkt-p-cpf').value.trim();
            if (cpf !== '' && !validarCpfLocal(cpf)) {
                msg.style.color = '#dc2626';
                msg.textContent = 'CPF inválido (ou deixe em branco).';
                return;
            }
            fetch('comprador_api.php?action=atualizar_perfil', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    token: STATE.token,
                    nome: $('mkt-p-nome').value.trim(),
                    cpf,
                    email: $('mkt-p-email').value.trim(),
                }),
            }).then((r) => r.json()).then((d) => {
                msg.style.color = d.success ? '#16a34a' : '#dc2626';
                msg.textContent = d.success ? 'Dados salvos!' : (d.message || 'Erro ao atualizar.');
                if (d.success && d.comprador) {
                    salvarSessaoComprador(STATE.token, d.comprador);
                    atualizarUiConta();
                }
            });
        });

        // Novo endereço na aba Conta
        if ($('mkt-form-novo-end')) $('mkt-form-novo-end').addEventListener('submit', (e) => {
            e.preventDefault();
            const msg = $('mkt-ne-msg');
            fetch('comprador_api.php?action=salvar_endereco', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    token: STATE.token,
                    endereco: {
                        rotulo: $('mkt-ne-rotulo').value.trim() || 'Casa',
                        cep: $('mkt-ne-cep').value.trim(),
                        rua: $('mkt-ne-rua').value.trim(),
                        numero: $('mkt-ne-numero').value.trim(),
                        bairro: $('mkt-ne-bairro').value.trim(),
                        cidade: $('mkt-ne-cidade').value.trim(),
                        estado: $('mkt-ne-estado').value.trim().toUpperCase(),
                        complemento: $('mkt-ne-comp').value.trim(),
                    },
                }),
            }).then((r) => r.json()).then((d) => {
                msg.style.color = d.success ? '#16a34a' : '#dc2626';
                msg.textContent = d.success ? 'Endereço salvo e definido como ativo!' : (d.message || 'Erro ao salvar endereço.');
                if (d.success && d.comprador) {
                    salvarSessaoComprador(STATE.token, d.comprador);
                    atualizarUiConta();
                    carregarMarketplace();
                    e.target.reset();
                }
            });
        });

        if ($('mkt-btn-sair')) $('mkt-btn-sair').addEventListener('click', () => {
            STATE.token = '';
            STATE.comprador = null;
            localStorage.removeItem('pz_comprador_token');
            localStorage.removeItem('pz_comprador_info');
            atualizarUiConta();
            trocarAba('descobrir');
        });

        // Controles LGPD do Titular na aba Minha Conta
        if ($('mkt-conta-ia-chk')) $('mkt-conta-ia-chk').addEventListener('change', (ev) => {
            const ativo = !!ev.target.checked;
            if (window.AcheIAkiLGPD && typeof window.AcheIAkiLGPD.salvar === 'function') {
                window.AcheIAkiLGPD.salvar(ativo);
            }
            const lgpdMsg = $('mkt-lgpd-msg');
            if (lgpdMsg) {
                lgpdMsg.style.color = '#c2410c';
                lgpdMsg.textContent = ativo
                    ? 'Permissão ativada. Vamos mostrar melhores preços e ofertas na sua região.'
                    : 'Recomendações personalizadas desativadas conforme a LGPD.';
            }
        });

        if ($('mkt-btn-exportar-lgpd')) $('mkt-btn-exportar-lgpd').addEventListener('click', () => {
            if (!STATE.token) return;
            const lgpdMsg = $('mkt-lgpd-msg');
            fetch('comprador_api.php?action=exportar_dados_lgpd&token=' + encodeURIComponent(STATE.token))
                .then((r) => r.json())
                .then((d) => {
                    if (!d || !d.success) {
                        if (lgpdMsg) { lgpdMsg.style.color = '#dc2626'; lgpdMsg.textContent = (d && d.message) || 'Erro ao exportar dados.'; }
                        return;
                    }
                    const blob = new Blob([JSON.stringify(d.portabilidade_lgpd, null, 2)], { type: 'application/json' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'meus-dados-acheiaki-lgpd.json';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    URL.revokeObjectURL(url);
                    if (lgpdMsg) { lgpdMsg.style.color = '#16a34a'; lgpdMsg.textContent = 'Arquivo JSON com seus dados baixado!'; }
                });
        });

        if ($('mkt-btn-excluir-lgpd')) $('mkt-btn-excluir-lgpd').addEventListener('click', () => {
            if (!STATE.token) return;
            if (!confirm('Tem certeza que deseja excluir permanentemente sua conta e dados pessoais do AcheIAki (LGPD)? Esta ação não pode ser desfeita.')) return;
            fetch('comprador_api.php?action=excluir_conta_lgpd', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token: STATE.token })
            }).then((r) => r.json()).then((d) => {
                if (d && d.success) {
                    if (window.AcheIAkiLGPD && typeof window.AcheIAkiLGPD.salvar === 'function') {
                        window.AcheIAkiLGPD.salvar(false);
                    }
                    STATE.token = '';
                    STATE.comprador = null;
                    localStorage.removeItem('pz_comprador_token');
                    localStorage.removeItem('pz_comprador_info');
                    atualizarUiConta();
                    trocarAba('descobrir');
                    toast('Sua conta foi excluída conforme a LGPD.');
                } else {
                    toast((d && d.message) || 'Não foi possível excluir agora.');
                }
            });
        });

        if ($('mkt-buscar-pedidos')) $('mkt-buscar-pedidos').addEventListener('click', () => buscarPedidos(false));

        document.querySelectorAll('#mkt-av-stars [data-av-star]').forEach((sb) => {
            sb.addEventListener('click', () => setEstrelasModal(sb.getAttribute('data-av-star')));
        });
        document.querySelectorAll('#mkt-av-tags .mkt-tag-chip-btn').forEach((tb) => {
            tb.addEventListener('click', () => tb.classList.toggle('ativo'));
        });

        if ($('mkt-form-avaliar')) $('mkt-form-avaliar').addEventListener('submit', (e) => {
            e.preventDefault();
            const msg = $('mkt-av-msg');
            msg.textContent = 'Enviando...';
            const tagsSel = Array.from(document.querySelectorAll('#mkt-av-tags .mkt-tag-chip-btn.ativo')).map((b) => b.getAttribute('data-av-tag')).filter(Boolean);
            const nomeAv = ($('mkt-av-cliente-nome') && $('mkt-av-cliente-nome').value.trim()) || (STATE.comprador && STATE.comprador.nome) || 'Cliente';
            const telPref = (STATE.comprador && STATE.comprador.telefone) || (window.PedidoZapCelulas && window.PedidoZapCelulas.obterTelefonePreferido()) || '';
            const slugAv = $('mkt-av-slug').value;
            fetch('marketplace_api.php?action=avaliar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    tenant: slugAv,
                    pedido_id: $('mkt-av-codigo').value,
                    cliente_nome: nomeAv,
                    telefone: telPref,
                    comprador_id: (STATE.comprador && STATE.comprador.id) || 0,
                    nota: Number($('mkt-av-nota').value || 5),
                    nota_comida: Number(($('mkt-av-comida') && $('mkt-av-comida').value) || 5),
                    nota_entrega: Number(($('mkt-av-entrega') && $('mkt-av-entrega').value) || 5),
                    nota_custo: Number(($('mkt-av-custo') && $('mkt-av-custo').value) || 5),
                    melhorias_tags: tagsSel,
                    comentario: $('mkt-av-coment').value.trim(),
                }),
            }).then((r) => r.json()).then((d) => {
                msg.style.color = d.success ? '#16a34a' : '#dc2626';
                msg.textContent = d.success ? 'Avaliação publicada! Obrigado.' : (d.message || 'Não foi possível publicar.');
                if (d.success) {
                    const notaNum = Number($('mkt-av-nota').value || 5);
                    if (window.PZAlgoritmo && window.PZAlgoritmo.registrar) {
                        const lj = STATE.lojas.find((x) => x.slug === slugAv) || {};
                        window.PZAlgoritmo.registrar(notaNum >= 4 ? 'avaliacao_positiva' : (notaNum <= 2 ? 'avaliacao_negativa' : 'view_loja'), {
                            tenant: slugAv,
                            nicho: lj.nicho || '',
                        });
                    }
                    if (window.PedidoZapCelulas) window.PedidoZapCelulas.emitir('avaliacao_publicada', { tenant: slugAv });
                    carregarMarketplace();
                    if ($('mkt-modal-loja') && !$('mkt-modal-loja').hidden) {
                        abrirPaginaEmpresa(slugAv, true);
                    }
                    setTimeout(() => { $('mkt-modal-avaliar').hidden = true; }, 900);
                }
            });
        });

        const formInd = $('mkt-form-indicar-loja');
        if (formInd) {
            formInd.addEventListener('submit', async (e) => {
                e.preventDefault();
                const nomeLoja = ($('ind-nome-loja') && $('ind-nome-loja').value || '').trim();
                const bairroLoja = ($('ind-bairro-loja') && $('ind-bairro-loja').value || '').trim();
                const contatoLoja = ($('ind-contato-loja') && $('ind-contato-loja').value || '').trim();
                const msgEl = $('ind-loja-msg');
                if (!nomeLoja || nomeLoja.length < 2) {
                    if (msgEl) { msgEl.style.color = '#b91c1c'; msgEl.textContent = 'Digite o nome da loja para indicar.'; }
                    return;
                }
                try {
                    const res = await fetch('marketplace_api.php?action=indicar_loja', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            nome_loja: nomeLoja,
                            bairro: bairroLoja,
                            contato: contatoLoja,
                            quem_indicou: (STATE.comprador && STATE.comprador.nome) ? STATE.comprador.nome : 'Morador'
                        })
                    });
                    const d = await res.json();
                    if (msgEl) {
                        msgEl.style.color = d.success ? '#15803d' : '#b91c1c';
                        msgEl.textContent = d.message || (d.success ? 'Indicação enviada!' : 'Erro ao enviar.');
                    }
                    if (d.success) {
                        formInd.reset();
                        toast(d.message || 'Indicação salva com sucesso!');
                    }
                } catch (err) {
                    if (msgEl) { msgEl.style.color = '#b91c1c'; msgEl.textContent = 'Sem conexão agora. Tente novamente.'; }
                }
            });
        }

        window.addEventListener('pz:localizacao-atualizada', () => {
            atualizarPillLocal();
            carregarMarketplace();
        });

        window.addEventListener('acheiaki:lgpd-atualizado', () => {
            if ($('mkt-conta-ia-chk') && window.AcheIAkiLGPD) {
                $('mkt-conta-ia-chk').checked = window.AcheIAkiLGPD.podeUsarIA();
            }
            carregarMarketplace();
        });

        if (window.PedidoZapCelulas && typeof window.PedidoZapCelulas.escutar === 'function') {
            window.PedidoZapCelulas.escutar((ev) => {
                if (!ev || !ev.tipo) return;
                if (ev.tipo === 'pedido_criado' || ev.tipo === 'pedido_status' || ev.tipo === 'pedido_gps') {
                    buscarPedidos(true);
                } else if (ev.tipo === 'loja_atualizada' || ev.tipo === 'avaliacao_publicada') {
                    carregarMarketplace();
                    if (ev.tipo === 'avaliacao_publicada' && $('mkt-modal-loja') && !$('mkt-modal-loja').hidden) {
                        const d = ev.dados || ev.payload || {};
                        const slugRefresh = STATE.lojaAtualSlug || d.tenant || '';
                        if (slugRefresh) abrirPaginaEmpresa(slugRefresh, true);
                    }
                } else if (ev.tipo === 'comprador_atualizado') {
                    carregarPerfil().then(() => {
                        atualizarPillLocal();
                        buscarPedidos(true);
                    });
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        initEventos();
        inicializarGoogleLogin();
        const qs = new URLSearchParams(window.location.search);
        const abaUrl = qs.get('tab');
        if (['descobrir', 'favoritos', 'pedidos', 'conta'].includes(abaUrl)) {
            trocarAba(abaUrl);
        }
        carregarPerfil().then(() => {
            const escolhaUrl = qs.get('escolha');
            if (!STATE.comprador && (escolhaUrl === 'cliente' || escolhaUrl === 'empresa')) {
                verificarGateObrigatorio(true, escolhaUrl);
            }
            atualizarPillLocal();
            carregarMarketplace();
            buscarPedidos(true);
        });
    });
})();

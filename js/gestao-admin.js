/**
 * Food Gestão — Módulos Executivos e Operacionais Integrados no Admin
 * Prioridade no Agente IA (Visão Geral & Copiloto) + Estoque/CMV + Caixa/DRE +
 * Clientes/Cupons/Recuperação + Entregadores + Equipe RBAC/Auditoria/Backup/Checklist.
 */
(function (global) {
    'use strict';

    const state = {
        sessao: null,
        resumo: null,
        estoque: { itens: [], fornecedores: [], movimentacoes: [] },
        fichas: { fichas: [], cardapio: {}, insumos: [] },
        caixa: { atual: null, totais: {}, historico: [] },
        financeiro: { contas: [], dre: null, inicio: '', fim: '' },
        clientes: [],
        marketing: { cupons: [], recuperacao: [] },
        entregadores: { entregadores: [], relatorio: [] },
        equipe: { funcionarios: [], perfis: {} },
        auditoria: [],
        checks: [],
        subtabs: {
            estoque: 'insumos',
            financeiro: 'caixa',
            clientes: 'crm',
            equipe: 'funcionarios'
        },
        fichaIngredientesDraft: [],
        assistenteHub: null
    };

    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function brl(v) {
        return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function credenciais() {
        try {
            return {
                user: sessionStorage.getItem('admin_user') || '',
                pass: sessionStorage.getItem('admin_pass') || ''
            };
        } catch (e) {
            return { user: '', pass: '' };
        }
    }

    function temPermissao(perm) {
        if (!state.sessao || state.sessao.role !== 'funcionario') return true;
        const p = state.sessao.permissoes || {};
        return !!p[perm];
    }

    async function apiGestao(action, body) {
        const auth = credenciais();
        if (!auth.user || !auth.pass) return { success: false, message: 'Não autenticado.' };
        const base = typeof global.TENANT !== 'undefined' && global.TENANT.apiUrl
            ? global.TENANT.apiUrl('gestao_api.php?action=' + encodeURIComponent(action))
            : 'gestao_api.php?action=' + encodeURIComponent(action);
        const resp = await fetch(base, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ auth }, body || {}))
        });
        const data = await resp.json().catch(() => ({ success: false, message: 'Resposta inválida.' }));
        if (data && typeof data === 'object') data._httpStatus = resp.status;
        return data;
    }

    function renderUpgradeBox(root, res) {
        if (!root || !res || res.codigo_erro !== 'upgrade_plano') return false;
        const nomeNec = esc(res.plano_necessario_nome || 'superior');
        const preco = res.preco_necessario ? brl(res.preco_necessario) + '/mês' : '';
        root.innerHTML = `
            <div style="background:#fff7ed;border:1px solid #fdba74;border-radius:14px;padding:20px;max-width:640px;">
                <h4 style="margin:0 0 8px;color:#9a3412;"><i class="fa-solid fa-crown"></i> Recurso disponível a partir do plano ${nomeNec}</h4>
                <p style="margin:0 0 14px;color:#7c2d12;line-height:1.5;">${esc(res.message || 'Faça upgrade do seu plano para liberar este módulo.')}</p>
                <button type="button" class="fg-btn fg-btn-prim" id="fg-btn-ir-upgrade">
                    <i class="fa-solid fa-arrow-up-right-dots"></i> Ver planos e fazer upgrade ${preco ? '(' + esc(preco) + ')' : ''}
                </button>
            </div>
        `;
        const b = root.querySelector('#fg-btn-ir-upgrade');
        if (b) {
            b.addEventListener('click', () => {
                const abaConta = document.querySelector('[data-tab="conta"]');
                if (abaConta) abaConta.click();
            });
        }
        return true;
    }

    async function apiAgenteDiagnostico() {
        const auth = credenciais();
        if (!auth.user || !auth.pass) return null;
        const base = typeof global.TENANT !== 'undefined' && global.TENANT.apiUrl
            ? global.TENANT.apiUrl('agente_api.php?action=diagnostico')
            : 'agente_api.php?action=diagnostico';
        try {
            const r = await fetch(base, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ auth })
            });
            return r.json();
        } catch (e) {
            return null;
        }
    }

    function aplicarVisibilidadeRBAC() {
        if (!state.sessao) return;
        const perms = state.sessao.permissoes || {};
        const mapaTabPerm = {
            'visao-geral': 'visao_geral',
            'estoque': 'estoque',
            'financeiro': 'financeiro',
            'clientes': 'clientes',
            'entregadores': 'entrega',
            'equipe': 'equipe',
            'cardapio': 'cardapio',
            'frete': 'entrega',
            'pagamentos': 'config',
            'relatorios': 'relatorios',
            'comandas': 'cozinha',
            'identidade': 'config',
            'contato': 'config',
            'textos': 'config',
            'segmento': 'cardapio',
            'conta': 'config',
            'seguranca': 'config'
        };
        if (state.sessao.role === 'funcionario') {
            document.querySelectorAll('#admin-tabs button[data-tab]').forEach((btn) => {
                const tab = btn.getAttribute('data-tab');
                const p = mapaTabPerm[tab];
                if (p && !perms[p]) {
                    btn.style.display = 'none';
                }
            });
            const badge = document.getElementById('fg-sessao-badge');
            if (badge) {
                badge.hidden = false;
                badge.textContent = 'Perfil: ' + (state.sessao.nome || state.sessao.user) + ' (' + (state.sessao.funcao || 'funcionário') + ')';
            }
        }
    }

    // =====================================================================
    // 1. VISÃO GERAL & HUB CENTRAL DO AGENTE IA (PRIORIDADE)
    // =====================================================================
    async function carregarVisaoGeral() {
        const root = document.getElementById('fg-visao-geral-root');
        if (!root) return;

        const [resResumo, diag, resSumidos] = await Promise.all([
            apiGestao('resumo'),
            apiAgenteDiagnostico(),
            apiGestao('clientes_sumidos', { dias: 15 })
        ]);

        if (resResumo && resResumo.sessao) {
            state.sessao = resResumo.sessao;
            aplicarVisibilidadeRBAC();
        }
        if (resResumo && resResumo.success) {
            state.resumo = resResumo.resumo;
            const boxTeto = document.getElementById('aki-banner-teto-plano');
            const cons = resResumo.consumo_plano || null;
            if (boxTeto && cons && cons.alerta_conquista) {
                boxTeto.hidden = false;
                const atingiu = !!cons.atingiu_pedidos;
                boxTeto.innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                    <div>
                        <strong style="color:#9a3412;font-size:1.05rem;">${atingiu ? '🏆 Meta atingida! Você completou ' + cons.pedidos_mes + '/' + cons.max_pedidos_mes + ' pedidos do Plano ' + esc(cons.plano_nome) : '🚀 Sua loja está voando: ' + cons.pedidos_mes + ' de ' + cons.max_pedidos_mes + ' pedidos usados no mês (' + cons.pedidos_pct + '%)'}</strong>
                        <p style="margin:4px 0 0;color:#7c2d12;font-size:0.9rem;">${atingiu ? 'Novos pedidos automáticos foram pausados no teto do plano. Libere agora para não perder vendas hoje!' : 'Faça upgrade para o Scale IA e venda ilimitado com IA 24h por R$ 150/mês (0% de comissão).'}</p>
                    </div>
                    <button type="button" class="fg-btn fg-btn-prim fg-ir-plano-pro" style="background:#ea580c;">Liberar no Plano ${cons.proximo_plano === 'pro' ? 'Pro' : 'Scale IA'} →</button>
                </div>`;
                const btnTeto = boxTeto.querySelector('.fg-ir-plano-pro');
                if (btnTeto) btnTeto.addEventListener('click', () => {
                    const abaConta = document.querySelector('#admin-tabs [data-tab="conta"]');
                    if (abaConta) abaConta.click();
                });
            }
        }

        const r = state.resumo || {
            hoje: { faturamento_bruto: 0, pedidos: 0, ticket_medio: 0, resultado_operacional: 0 },
            mes: { faturamento_bruto: 0, resultado_operacional: 0, lucratividade_pct: 0, por_produto: [] },
            caixa: { aberto: false, totais: { total_geral_esperado: 0 } },
            pedidos_abertos: 0,
            estoque_baixo: [],
            alertas_ia: []
        };

        const alertas = (diag && Array.isArray(diag.alertas)) ? diag.alertas : (r.alertas_ia || []);
        if (diag && diag.ia_pessoal) {
            global.__pzIaPessoalStatus = diag.ia_pessoal;
            global.dispatchEvent(new CustomEvent('pz:ia-pessoal-atualizada', { detail: diag.ia_pessoal }));
        }
        let modoIA = '100% Local (Sem API externa)';
        if (diag && diag.ia_pessoal && diag.ia_pessoal.ativa) {
            modoIA = diag.ia_pessoal.voz_ativa ? 'IA Pessoal do Cliente (API + Voz)' : 'IA Pessoal do Cliente (API)';
        } else if (diag && diag.modo_ia === 'gemini_hibrido') {
            modoIA = 'Híbrido (Local + Gemini)';
        }
        const permFin = temPermissao('financeiro') || temPermissao('ia_financeiro');
        const permEst = temPermissao('estoque') || temPermissao('ia_estoque');

        root.innerHTML = `
            <div class="fg-ia-hub">
                <div class="fg-ia-hub-topo">
                    <div>
                        <h3 style="margin:0;color:#fff;font-size:1.25rem;">
                            <i class="fa-solid fa-robot"></i> ${(diag && esc(diag.nome_agente)) || 'Copiloto Food Gestão IA'}
                        </h3>
                        <p style="margin:4px 0 0;opacity:0.92;font-size:0.92rem;">
                            Converse com os dados reais da loja ou execute ações operacionais por comando natural.
                        </p>
                    </div>
                    <span class="fg-ia-badge" id="fg-ia-hub-badge"><i class="fa-solid fa-bolt"></i> Motor: ${esc(modoIA)}</span>
                </div>
                <div class="fg-ia-chat-box">
                    <div class="fg-ia-mensagens" id="fg-hub-ia-msgs"></div>
                    <div class="fg-ia-chips" id="fg-hub-ia-atalhos"></div>
                    <form class="fg-ia-form" id="fg-hub-ia-form">
                        <input type="text" id="fg-hub-ia-input" placeholder="Ex.: Quanto vendi hoje? | Abrir caixa com 100 | O que preciso comprar? | Qual prato dá mais lucro?" autocomplete="off">
                        <button type="button" id="fg-hub-ia-voz" class="pz-btn-voz" hidden aria-label="Comando por voz"><i class="fa-solid fa-microphone"></i></button>
                        <button type="submit"><i class="fa-solid fa-paper-plane"></i> Enviar</button>
                    </form>
                </div>
            </div>

            ${alertas.length ? `
                <h4 style="margin:0 0 8px;color:#0f172a;"><i class="fa-solid fa-triangle-exclamation"></i> Alertas proativos da IA</h4>
                <div class="fg-alertas-lista">
                    ${alertas.map((a) => `
                        <div class="fg-alerta-item ${esc(a.nivel || 'dica')}">
                            <strong>${esc(a.titulo)}</strong>
                            <span style="font-size:0.86rem;color:#334155;">${esc(a.mensagem)}</span>
                            ${a.comando_ia ? `<button type="button" class="fg-alerta-btn" data-cmd="${esc(a.comando_ia)}">Perguntar à IA</button>` : ''}
                        </div>
                    `).join('')}
                </div>
            ` : ''}

            <div class="fg-grid-kpis">
                <div class="fg-kpi destaque-ia">
                    <div class="fg-kpi-label">Pedidos Hoje</div>
                    <div class="fg-kpi-valor">${r.hoje.pedidos}</div>
                    <div class="fg-kpi-sub">${r.pedidos_abertos} em andamento agora</div>
                </div>
                ${permFin ? `
                    <div class="fg-kpi destaque-ok">
                        <div class="fg-kpi-label">Faturamento Hoje</div>
                        <div class="fg-kpi-valor">${brl(r.hoje.faturamento_bruto)}</div>
                        <div class="fg-kpi-sub">Ticket médio: ${brl(r.hoje.ticket_medio)}</div>
                    </div>
                    <div class="fg-kpi">
                        <div class="fg-kpi-label">Resultado no Mês (DRE)</div>
                        <div class="fg-kpi-valor">${brl(r.mes.resultado_operacional)}</div>
                        <div class="fg-kpi-sub">Lucratividade: ${r.mes.lucratividade_pct}%</div>
                    </div>
                    <div class="fg-kpi ${r.caixa.aberto ? 'destaque-ok' : 'destaque-alerta'}">
                        <div class="fg-kpi-label">Caixa do Dia</div>
                        <div class="fg-kpi-valor">${r.caixa.aberto ? 'ABERTO' : 'FECHADO'}</div>
                        <div class="fg-kpi-sub">Esperado: ${brl(r.caixa.totais ? r.caixa.totais.total_geral_esperado : 0)}</div>
                    </div>
                ` : ''}
                ${permEst ? `
                    <div class="fg-kpi ${r.estoque_baixo.length ? 'destaque-alerta' : 'destaque-ok'}">
                        <div class="fg-kpi-label">Estoque Crítico</div>
                        <div class="fg-kpi-valor">${r.estoque_baixo.length}</div>
                        <div class="fg-kpi-sub">${r.estoque_baixo.length ? 'Item(ns) abaixo do mínimo' : 'Tudo abastecido'}</div>
                    </div>
                ` : ''}
            </div>

            ${permFin && r.mes.por_produto && r.mes.por_produto.length ? `
                <div class="fg-tabela-wrap" style="margin-top:8px;">
                    <table class="fg-tabela">
                        <thead>
                            <tr><th>Top Produtos (Mês)</th><th>Qtd</th><th>Faturamento</th><th>CMV Ficha</th><th>Lucro</th><th>Margem</th></tr>
                        </thead>
                        <tbody>
                            ${r.mes.por_produto.slice(0, 6).map((p) => `
                                <tr>
                                    <td><strong>${esc(p.nome)}</strong> ${p.tem_ficha ? '' : '<span class="fg-pill warn">Sem ficha</span>'}</td>
                                    <td>${p.quantidade}</td>
                                    <td>${brl(p.faturamento)}</td>
                                    <td>${brl(p.cmv)}</td>
                                    <td><strong>${brl(p.lucro)}</strong></td>
                                    <td><span class="fg-pill ${p.margem_pct >= 30 ? 'ok' : 'warn'}">${p.margem_pct}%</span></td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            ` : ''}

            ${(() => {
                const econ = r.economia_taxas || { hoje: 0, semana: 0, mes: 0, taxa_ref_pct: 20 };
                const baseCli = r.base_clientes || { total: 0, com_whatsapp: 0, novos_semana: 0, sumidos_15d: 0 };
                const orig = r.origem_pedidos || { link_direto: 0, marketplace: 0, balcao: 0, recuperacao_ia: 0 };
                const term = r.termometro_preco || { status: 'sem_dados', preco_medio_loja: 0, preco_ref_regional: 42, mensagem: '' };
                const sumLista = (resSumidos && Array.isArray(resSumidos.clientes)) ? resSumidos.clientes : (baseCli.sumidos_preview || []);
                const sumLiberado = !resSumidos || resSumidos.liberado !== false;
                const sumTotal = (resSumidos && resSumidos.total != null) ? Number(resSumidos.total) : Number(baseCli.sumidos_15d || 0);
                return `
                    <div style="margin-top:16px;background:#fff7ed;border:1px solid #fdba74;border-radius:16px;padding:16px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
                            <div>
                                <h4 style="margin:0;color:#9a3412;font-size:1.05rem;"><i class="fa-solid fa-sack-dollar"></i> Painel Anti-Cancelamento: quanto você economiza no AcheIAki</h4>
                                <small style="color:#7c2d12;">Economia automática comparada a ~20% de comissão de aplicativos tradicionais.</small>
                            </div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <button type="button" class="fg-btn fg-btn-sec" id="fg-btn-copiar-resumo-wpp" style="font-size:0.82rem;"><i class="fa-brands fa-whatsapp"></i> Copiar Resumo Semanal</button>
                                <button type="button" class="fg-btn fg-btn-prim" id="fg-btn-otimizar-ia" style="font-size:0.82rem;background:#ea580c;"><i class="fa-solid fa-wand-magic-sparkles"></i> Otimizar Cardápio com IA</button>
                            </div>
                        </div>

                        <div class="fg-grid-kpis" style="margin-bottom:12px;">
                            <div class="fg-kpi destaque-ok" style="background:#ecfdf5;border-color:#86efac;">
                                <div class="fg-kpi-label">1. Dinheiro Salvo em Taxas (Mês)</div>
                                <div class="fg-kpi-valor" style="color:#15803d;">${brl(econ.mes)}</div>
                                <div class="fg-kpi-sub">Hoje: ${brl(econ.hoje)} · 7 dias: ${brl(econ.semana)}</div>
                            </div>
                            <div class="fg-kpi" style="background:#fff;">
                                <div class="fg-kpi-label">2. Origem dos Pedidos (Mês)</div>
                                <div class="fg-kpi-valor" style="font-size:1.05rem;">${orig.link_direto || 0} Direto · ${orig.marketplace || 0} Bairro</div>
                                <div class="fg-kpi-sub">IA/Robô: ${orig.recuperacao_ia || 0} · Balcão: ${orig.balcao || 0}</div>
                            </div>
                            <div class="fg-kpi" style="background:#fff;">
                                <div class="fg-kpi-label">3. Base Própria Capturada</div>
                                <div class="fg-kpi-valor">${baseCli.com_whatsapp || 0} clientes</div>
                                <div class="fg-kpi-sub">
                                    <button type="button" id="fg-btn-exportar-csv" style="border:none;background:#231915;color:#fff;padding:4px 10px;border-radius:8px;font-size:0.75rem;font-weight:700;cursor:pointer;margin-top:4px;">
                                        <i class="fa-solid fa-download"></i> ${sumLiberado ? 'Baixar WhatsApp (CSV)' : '🔒 Exportar CSV (Plano Scale IA)'}
                                    </button>
                                </div>
                            </div>
                            <div class="fg-kpi ${term.status === 'atencao' ? 'destaque-alerta' : 'destaque-ok'}" style="background:#fff;">
                                <div class="fg-kpi-label">4. Termômetro de Preço</div>
                                <div class="fg-kpi-valor" style="font-size:1.05rem;color:${term.status === 'atencao' ? '#c2410c' : '#15803d'};">${term.status === 'atencao' ? ('+' + term.diferenca_pct + '% vs média') : (term.preco_medio_loja > 0 ? 'Competitivo' : 'Sem dados')}</div>
                                <div class="fg-kpi-sub">${esc(term.mensagem || 'Média do bairro: ' + brl(term.preco_ref_regional))}</div>
                            </div>
                        </div>

                        <div id="fg-ia-otim-feedback" hidden style="background:#fff;border:1px solid #fed7aa;border-radius:12px;padding:12px;margin-bottom:12px;font-size:0.86rem;"></div>

                        ${sumTotal > 0 ? `
                            <div style="background:#fff;border:1px solid #fed7aa;border-radius:12px;padding:12px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                                    <strong style="color:#9a3412;"><i class="fa-solid fa-user-clock"></i> Você tem ${sumTotal} cliente(s) sumido(s) há +15 dias prontos para receber oferta</strong>
                                    ${sumLiberado
                                        ? '<button type="button" id="fg-btn-disparar-sumidos-agora" class="fg-btn fg-btn-prim" style="font-size:0.78rem;padding:6px 12px;background:#15803d;"><i class="fa-brands fa-whatsapp"></i> Disparar Cupom VOLTA10 em lote</button>'
                                        : '<button type="button" class="fg-btn fg-btn-prim fg-ir-plano-pro" style="font-size:0.78rem;padding:6px 12px;">🔒 Desbloquear no Plano Scale IA</button>'}
                                </div>
                                <div style="display:grid;gap:8px;">
                                    ${sumLista.slice(0, 5).map((s) => `
                                        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;padding:8px 10px;background:#faf7f2;border-radius:10px;border:1px solid rgba(35,25,21,0.08);">
                                            <div>
                                                <strong>${esc(s.nome)}</strong> · <small style="color:#9a3412;font-weight:700;">${s.dias_sem_pedir} dias sem pedir</small><br>
                                                <small style="color:#6b615b;">${esc(s.telefone)} · Já gastou ${brl(s.total_gasto)} (${s.total_pedidos} pedido(s))</small>
                                            </div>
                                            ${sumLiberado && s.whatsapp_url
                                                ? `<a href="${esc(s.whatsapp_url)}" target="_blank" rel="noopener" style="background:#15803d;color:#fff;padding:7px 12px;border-radius:8px;text-decoration:none;font-size:0.8rem;font-weight:700;"><i class="fa-brands fa-whatsapp"></i> Chamar agora</a>`
                                                : `<button type="button" class="fg-ir-plano-pro" style="border:none;background:#ea580c;color:#fff;padding:7px 12px;border-radius:8px;font-size:0.8rem;font-weight:700;cursor:pointer;">🔒 Liberar no Scale IA</button>`}
                                        </div>
                                    `).join('')}
                                </div>
                            </div>
                        ` : ''}

                        <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding-top:10px;border-top:1px dashed #fdba74;">
                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <button type="button" id="fg-btn-indicar-vizinho" style="border:1px solid #15803d;background:#ecfdf5;color:#15803d;padding:7px 12px;border-radius:9px;font-weight:700;font-size:0.8rem;cursor:pointer;"><i class="fa-solid fa-handshake"></i> Indicar loja vizinha (+7 dias grátis)</button>
                                <button type="button" id="fg-btn-clonar-filial" style="border:1px solid #cbd5e1;background:#fff;color:#231915;padding:7px 12px;border-radius:9px;font-weight:700;font-size:0.8rem;cursor:pointer;"><i class="fa-solid fa-clone"></i> Clonar Cardápio p/ Nova Filial</button>
                            </div>
                            <small style="color:#7c2d12;">1 CNPJ / endereço físico = 1 assinatura ativa · Sincronize cardápios entre filiais.</small>
                        </div>

                        <div style="margin-top:12px;background:#231915;color:#faf7f2;border-radius:12px;padding:12px 14px;">
                            <div style="font-size:0.78rem;font-weight:800;color:#fb923c;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px;">Em breve na sua região (já incluso para lojas ativas)</div>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:8px;font-size:0.8rem;">
                                <div style="background:rgba(255,255,255,0.06);padding:8px 10px;border-radius:8px;border:1px solid rgba(251,146,60,0.22);">🛵 <strong>Motoboys Parceiros</strong><br><small style="color:#d6d3d1;">Chame entregador do bairro em 1 clique</small></div>
                                <div style="background:rgba(255,255,255,0.06);padding:8px 10px;border-radius:8px;border:1px solid rgba(251,146,60,0.22);">💳 <strong>Pix Automático + Split</strong><br><small style="color:#d6d3d1;">Sem conferir comprovante no WhatsApp</small></div>
                                <div style="background:rgba(255,255,255,0.06);padding:8px 10px;border-radius:8px;border:1px solid rgba(251,146,60,0.22);">🎟️ <strong>Cashback do Bairro</strong><br><small style="color:#d6d3d1;">Cliente ganha pontos e volta na sua loja</small></div>
                                <div style="background:rgba(255,255,255,0.06);padding:8px 10px;border-radius:8px;border:1px solid rgba(251,146,60,0.22);">📡 <strong>Radar de Fome Ao Vivo</strong><br><small style="color:#d6d3d1;">Veja onde estão buscando lanches agora</small></div>
                            </div>
                        </div>
                    </div>
                `;
            })()}
        `;

        root.querySelectorAll('.fg-ir-plano-pro').forEach((btn) => {
            btn.addEventListener('click', () => {
                const abaConta = document.querySelector('#admin-tabs [data-tab="conta"]');
                if (abaConta) abaConta.click();
            });
        });

        const btnDispararSum = document.getElementById('fg-btn-disparar-sumidos-agora');
        if (btnDispararSum) {
            btnDispararSum.addEventListener('click', async () => {
                btnDispararSum.disabled = true;
                try {
                    const resCamp = await apiGestao('aprovar_campanha_sumidos');
                    if (resCamp && resCamp.codigo_erro === 'upgrade_plano') {
                        alert(resCamp.message || 'Disponível no Plano Scale IA.');
                        const abaConta = document.querySelector('#admin-tabs [data-tab="conta"]');
                        if (abaConta) abaConta.click();
                        return;
                    }
                    alert((resCamp && resCamp.message) || 'Campanha processada!');
                } finally {
                    btnDispararSum.disabled = false;
                }
            });
        }

        const btnCopiarWpp = document.getElementById('fg-btn-copiar-resumo-wpp');
        if (btnCopiarWpp) {
            btnCopiarWpp.addEventListener('click', async () => {
                const txt = r.resumo_semanal_wpp || ('Resumo AcheIAki: ' + brl((r.economia_taxas || {}).mes || 0) + ' economizados em taxas.');
                try {
                    await navigator.clipboard.writeText(txt);
                    btnCopiarWpp.textContent = '✓ Resumo copiado!';
                    setTimeout(() => { btnCopiarWpp.innerHTML = '<i class="fa-brands fa-whatsapp"></i> Copiar Resumo Semanal'; }, 2500);
                } catch (e) {
                    alert(txt);
                }
            });
        }

        const btnCsv = document.getElementById('fg-btn-exportar-csv');
        if (btnCsv) {
            btnCsv.addEventListener('click', async () => {
                const resCsv = await apiGestao('clientes_csv');
                if (!resCsv || !resCsv.success) {
                    if (resCsv && resCsv.codigo_erro === 'upgrade_plano') {
                        alert(resCsv.message || 'Disponível no Plano Scale IA.');
                        const abaConta = document.querySelector('#admin-tabs [data-tab="conta"]');
                        if (abaConta) abaConta.click();
                        return;
                    }
                    return alert((resCsv && resCsv.message) || 'Não foi possível exportar.');
                }
                const blob = new Blob(["\uFEFF" + (resCsv.csv || '')], { type: 'text/csv;charset=utf-8;' });
                const urlBlob = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = urlBlob;
                a.download = resCsv.filename || 'clientes-acheiaki.csv';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(urlBlob);
            });
        }

        const btnOtimIa = document.getElementById('fg-btn-otimizar-ia');
        if (btnOtimIa) {
            btnOtimIa.addEventListener('click', async () => {
                const box = document.getElementById('fg-ia-otim-feedback');
                btnOtimIa.disabled = true;
                const prev = await apiGestao('otimizar_cardapio_ia', { aplicar: false });
                btnOtimIa.disabled = false;
                if (!box) return;
                const qtdDesc = (prev && prev.sugestoes_descricao) ? prev.sugestoes_descricao.length : 0;
                const qtdPrc = (prev && prev.alertas_preco) ? prev.alertas_preco.length : 0;
                box.hidden = false;
                if (!qtdDesc && !qtdPrc) {
                    box.innerHTML = '<strong style="color:#15803d;">✅ Catálogo aprovado pela IA!</strong> Todos os itens já têm descrição vendedora e preços competitivos.';
                    return;
                }
                box.innerHTML = '<div><strong>✨ Diagnóstico da IA no Catálogo:</strong> ' + qtdDesc + ' item(ns) sem descrição vendedora e ' + qtdPrc + ' preço(s) acima da média regional.</div>'
                    + (qtdDesc ? '<div style="margin-top:8px;"><button type="button" id="fg-btn-aplicar-otim" class="fg-btn fg-btn-prim" style="font-size:0.8rem;padding:6px 12px;">Aplicar ' + qtdDesc + ' descrição(ões) vendedora(s) agora</button></div>' : '')
                    + (qtdPrc ? '<ul style="margin:6px 0 0;padding-left:18px;color:#9a3412;">' + prev.alertas_preco.slice(0, 4).map((p) => '<li><strong>' + esc(p.nome) + '</strong>: está ' + brl(p.preco_atual) + ' (sugestão ~' + brl(p.preco_sugerido) + ' p/ vender mais)</li>').join('') + '</ul>' : '');
                const bAp = document.getElementById('fg-btn-aplicar-otim');
                if (bAp) {
                    bAp.addEventListener('click', async () => {
                        const ap = await apiGestao('otimizar_cardapio_ia', { aplicar: true });
                        box.innerHTML = '<strong style="color:#15803d;">✅ ' + (ap.alterados || 0) + ' descrição(ões) atualizada(s) no seu catálogo!</strong>';
                    });
                }
            });
        }

        const btnIndViz = document.getElementById('fg-btn-indicar-vizinho');
        if (btnIndViz) {
            btnIndViz.addEventListener('click', () => {
                const msg = 'Oi! Estou usando o AcheIAki na minha loja para receber pedidos direto sem pagar 25% de taxa pros apps. Cadastra a sua loja com 30 dias grátis no Scale IA por aqui: ' + window.location.origin + '/cadastro.html';
                window.open('https://wa.me/?text=' + encodeURIComponent(msg), '_blank', 'noopener');
            });
        }

        const btnClonar = document.getElementById('fg-btn-clonar-filial');
        if (btnClonar) {
            btnClonar.addEventListener('click', async () => {
                const slugDest = prompt('Informe o endereço (slug) da filial já cadastrada na mesma rede para receber uma cópia deste cardápio:\n(Obs.: cada unidade física mantém sua própria assinatura ativa.)');
                if (!slugDest || !slugDest.trim()) return;
                const rCl = await apiGestao('rede_clonar_cardapio', { destino: slugDest.trim() });
                alert((rCl && rCl.message) || 'Operação concluída.');
            });
        }

        const AssistCls = global.PedidoZapAssistenteCore && global.PedidoZapAssistenteCore.AssistenteIA;
        if (AssistCls) {
            state.assistenteHub = new AssistCls({
                contexto: 'admin',
                elMensagens: document.getElementById('fg-hub-ia-msgs'),
                elAtalhos: document.getElementById('fg-hub-ia-atalhos'),
                silencioso: true
            });
            state.assistenteHub.renderAtalhos(
                (diag && diag.sugestoes && diag.sugestoes.length)
                    ? diag.sugestoes
                    : ['Quanto vendi hoje?', 'O que preciso comprar de estoque?', 'Qual meu produto mais lucrativo?', 'Analisar minha loja']
            );
            state.assistenteHub.falarRico({
                resposta: 'Olá! Estou conectado ao estoque, caixa, fichas técnicas, pedidos, CRM e DRE da sua loja. Escolha um atalho abaixo ou digite sua pergunta/comando.',
                origem_dados: ['pedidos.json', 'estoque.json', 'caixa.json', 'financeiro.json']
            });

            const form = document.getElementById('fg-hub-ia-form');
            const inp = document.getElementById('fg-hub-ia-input');
            const btnVoz = document.getElementById('fg-hub-ia-voz');
            if (btnVoz && inp && global.PedidoZapAssistenteCore && global.PedidoZapAssistenteCore.anexarControleVoz) {
                global.PedidoZapAssistenteCore.anexarControleVoz(btnVoz, inp, state.assistenteHub);
            }
            if (form && inp) {
                form.addEventListener('submit', (e) => {
                    e.preventDefault();
                    const v = inp.value;
                    inp.value = '';
                    state.assistenteHub.receber(v);
                });
            }
            root.querySelectorAll('.fg-alerta-btn').forEach((btn) => {
                btn.addEventListener('click', () => {
                    state.assistenteHub.receber(btn.dataset.cmd);
                });
            });
        }
    }

    // =====================================================================
    // 2. MÓDULO ESTOQUE, FORNECEDORES & FICHAS TÉCNICAS / CMV
    // =====================================================================
    async function carregarEstoque() {
        const root = document.getElementById('fg-estoque-root');
        if (!root) return;
        const [resEst, resFichas] = await Promise.all([
            apiGestao('estoque_listar'),
            apiGestao('fichas_listar')
        ]);
        if (renderUpgradeBox(root, resEst)) return;
        if (resEst && resEst.success) {
            state.estoque = { itens: resEst.itens || [], fornecedores: resEst.fornecedores || [], movimentacoes: resEst.movimentacoes || [] };
        }
        if (resFichas && resFichas.success) {
            state.fichas = resFichas;
        }
        renderEstoque();
    }

    function renderEstoque() {
        const root = document.getElementById('fg-estoque-root');
        if (!root) return;
        const aba = state.subtabs.estoque;
        const itens = state.estoque.itens || [];
        const forns = state.estoque.fornecedores || [];

        const tabsHtml = `
            <div class="fg-subtabs">
                <button type="button" class="fg-subtab-btn ${aba === 'insumos' ? 'ativo' : ''}" data-st="insumos">Insumos & Movimentações</button>
                <button type="button" class="fg-subtab-btn ${aba === 'fichas' ? 'ativo' : ''}" data-st="fichas">Fichas Técnicas & CMV</button>
                <button type="button" class="fg-subtab-btn ${aba === 'fornecedores' ? 'ativo' : ''}" data-st="fornecedores">Fornecedores</button>
                <button type="button" class="fg-subtab-btn ${aba === 'historico' ? 'ativo' : ''}" data-st="historico">Histórico de Entradas/Baixas</button>
            </div>
        `;

        let bodyHtml = '';
        if (aba === 'insumos') {
            bodyHtml = `
                <form id="fg-form-insumo" class="fg-form-grid">
                    <div><label>Nome do insumo</label><input type="text" id="fg-ins-nome" placeholder="Ex.: Carne Bovina, Pão Brioche" required></div>
                    <div><label>Unidade</label>
                        <select id="fg-ins-un">
                            <option value="un">Unidade (un)</option><option value="kg">Quilo (kg)</option>
                            <option value="g">Grama (g)</option><option value="litro">Litro (L)</option><option value="ml">Mililitro (ml)</option>
                        </select>
                    </div>
                    <div><label>Qtd atual</label><input type="number" step="0.01" id="fg-ins-qtd" value="0"></div>
                    <div><label>Estoque mínimo</label><input type="number" step="0.01" id="fg-ins-min" value="5"></div>
                    <div><label>Custo unitário (R$)</label><input type="number" step="0.01" id="fg-ins-custo" value="0"></div>
                    <div><button type="submit" class="fg-btn fg-btn-prim"><i class="fa-solid fa-plus"></i> Salvar Insumo</button></div>
                </form>

                ${itens.length ? `
                <form id="fg-form-mov-estoque" class="fg-form-grid" style="background:#f8fafc;padding:12px;border-radius:12px;border:1px solid #e2e8f0;">
                    <div><label>Movimentar insumo</label>
                        <select id="fg-mov-item">
                            ${itens.map((i) => `<option value="${esc(i.id)}">${esc(i.nome)} (${i.quantidade_atual} ${esc(i.unidade)})</option>`).join('')}
                        </select>
                    </div>
                    <div><label>Tipo</label>
                        <select id="fg-mov-tipo">
                            <option value="entrada">Entrada / Compra (+)</option>
                            <option value="perda">Perda / Desperdício (-)</option>
                            <option value="ajuste_saida">Ajuste Saída (-)</option>
                            <option value="ajuste_entrada">Ajuste Entrada (+)</option>
                        </select>
                    </div>
                    <div><label>Quantidade</label><input type="number" step="0.01" id="fg-mov-qtd" required placeholder="Ex.: 10"></div>
                    <div><label>Novo custo unit. (opcional)</label><input type="number" step="0.01" id="fg-mov-custo" placeholder="R$"></div>
                    <div><label>Motivo / NF</label><input type="text" id="fg-mov-motivo" placeholder="Ex.: Compra fornecedor"></div>
                    <div><button type="submit" class="fg-btn fg-btn-laranja">Registrar Movimentação</button></div>
                </form>` : ''}

                <div class="fg-tabela-wrap">
                    <table class="fg-tabela">
                        <thead><tr><th>Insumo</th><th>Saldo</th><th>Mínimo</th><th>Custo Unit.</th><th>Status</th><th>Ações</th></tr></thead>
                        <tbody>
                            ${itens.length ? itens.map((i) => {
                                const critico = Number(i.estoque_minimo) > 0 && Number(i.quantidade_atual) <= Number(i.estoque_minimo);
                                return `<tr>
                                    <td><strong>${esc(i.nome)}</strong></td>
                                    <td>${Number(i.quantidade_atual)} ${esc(i.unidade)}</td>
                                    <td>${Number(i.estoque_minimo)} ${esc(i.unidade)}</td>
                                    <td>${brl(i.custo_unitario)}</td>
                                    <td><span class="fg-pill ${critico ? 'bad' : 'ok'}">${critico ? 'Repor' : 'OK'}</span></td>
                                    <td><button type="button" class="fg-btn fg-btn-danger fg-btn-sm fg-del-ins" data-id="${esc(i.id)}">Excluir</button></td>
                                </tr>`;
                            }).join('') : '<tr><td colspan="6">Nenhum insumo cadastrado ainda.</td></tr>'}
                        </tbody>
                    </table>
                </div>
            `;
        } else if (aba === 'fichas') {
            const cardapio = state.fichas.cardapio || {};
            const produtos = [];
            Object.keys(cardapio).forEach((cat) => {
                (Array.isArray(cardapio[cat]) ? cardapio[cat] : []).forEach((p) => {
                    if (p && p.id && p.nome) {
                        produtos.push({ id: p.id, nome: p.nome, preco: Number(p.preco || (p.tamanhos && p.tamanhos[0] && p.tamanhos[0].preco) || 0) });
                    }
                });
            });
            const fichas = state.fichas.fichas || [];
            bodyHtml = `
                <div style="background:#f0fdfa;border:1px solid #99f6e4;padding:12px;border-radius:12px;margin-bottom:14px;">
                    <strong>Receita / Ficha Técnica:</strong> Vincule os insumos do estoque a cada prato. Quando um pedido vai para <em>Em Preparo</em> no Kanban, os ingredientes são baixados automaticamente e o CMV calcula seu lucro real!
                </div>
                <div class="fg-form-grid">
                    <div><label>Produto do cardápio</label>
                        <select id="fg-ft-produto">
                            ${produtos.length ? produtos.map((p) => `<option value="${esc(p.id)}" data-nome="${esc(p.nome)}" data-preco="${p.preco}">${esc(p.nome)} (${brl(p.preco)})</option>`).join('') : '<option value="">Cadastre pratos no Cardápio primeiro</option>'}
                        </select>
                    </div>
                    <div><label>Rendimento (porções)</label><input type="number" step="0.1" id="fg-ft-rend" value="1"></div>
                    <div><label>Adicionar ingrediente</label>
                        <select id="fg-ft-insumo">
                            ${itens.map((i) => `<option value="${esc(i.id)}" data-un="${esc(i.unidade)}" data-nome="${esc(i.nome)}">${esc(i.nome)} (${esc(i.unidade)} - ${brl(i.custo_unitario)})</option>`).join('')}
                        </select>
                    </div>
                    <div><label>Qtd usada na receita</label><input type="number" step="0.001" id="fg-ft-qtd" placeholder="Ex.: 150 ou 0.15"></div>
                    <div><label>Unidade da receita</label>
                        <select id="fg-ft-un">
                            <option value="g">g</option><option value="kg">kg</option><option value="un">un</option><option value="ml">ml</option><option value="litro">litro</option>
                        </select>
                    </div>
                    <div><button type="button" id="fg-ft-add-ing" class="fg-btn fg-btn-sec"><i class="fa-solid fa-plus"></i> Incluir Ingrediente</button></div>
                </div>
                <div id="fg-ft-draft-lista" style="margin-bottom:12px;">
                    ${state.fichaIngredientesDraft.length ? state.fichaIngredientesDraft.map((ing, idx) => `
                        <span class="fg-pill info" style="margin-right:6px;margin-bottom:6px;">
                            ${esc(ing.nome)}: ${ing.quantidade} ${esc(ing.unidade)}
                            <button type="button" data-idx="${idx}" class="fg-ft-rm-ing" style="border:none;background:transparent;cursor:pointer;font-weight:900;">×</button>
                        </span>
                    `).join('') : '<span style="color:#64748b;font-size:0.88rem;">Nenhum ingrediente adicionado ao rascunho ainda.</span>'}
                </div>
                <p><button type="button" id="fg-ft-salvar" class="fg-btn fg-btn-prim"><i class="fa-solid fa-floppy-disk"></i> Salvar Ficha Técnica</button></p>

                <div class="fg-tabela-wrap">
                    <table class="fg-tabela">
                        <thead><tr><th>Produto</th><th>Ingredientes</th><th>Custo Unitário (CMV)</th><th>Ações</th></tr></thead>
                        <tbody>
                            ${fichas.length ? fichas.map((f) => `
                                <tr>
                                    <td><strong>${esc(f.produto_nome)}</strong></td>
                                    <td>${(f.ingredientes || []).map((x) => `${esc(x.nome)} (${x.quantidade} ${esc(x.unidade)})`).join(', ')}</td>
                                    <td><strong>${brl(f.custo_unitario)}</strong></td>
                                    <td><button type="button" class="fg-btn fg-btn-danger fg-btn-sm fg-del-ficha" data-pid="${esc(f.produto_id)}">Excluir</button></td>
                                </tr>
                            `).join('') : '<tr><td colspan="4">Nenhuma ficha técnica cadastrada ainda.</td></tr>'}
                        </tbody>
                    </table>
                </div>
            `;
        } else if (aba === 'fornecedores') {
            bodyHtml = `
                <form id="fg-form-forn" class="fg-form-grid">
                    <div><label>Nome / Empresa</label><input type="text" id="fg-forn-nome" required placeholder="Distribuidora Central"></div>
                    <div><label>Telefone / WhatsApp</label><input type="text" id="fg-forn-tel" placeholder="11999998888"></div>
                    <div><label>Categoria</label><input type="text" id="fg-forn-cat" placeholder="Carnes, Bebidas, Embalagens"></div>
                    <div><button type="submit" class="fg-btn fg-btn-prim">Salvar Fornecedor</button></div>
                </form>
                <div class="fg-tabela-wrap">
                    <table class="fg-tabela">
                        <thead><tr><th>Fornecedor</th><th>Telefone</th><th>Categoria</th><th>Ações</th></tr></thead>
                        <tbody>
                            ${forns.length ? forns.map((f) => `
                                <tr>
                                    <td><strong>${esc(f.nome)}</strong></td>
                                    <td>${esc(f.telefone || '—')}</td>
                                    <td>${esc(f.categoria || '—')}</td>
                                    <td><button type="button" class="fg-btn fg-btn-danger fg-btn-sm fg-del-forn" data-id="${esc(f.id)}">Excluir</button></td>
                                </tr>
                            `).join('') : '<tr><td colspan="4">Nenhum fornecedor cadastrado.</td></tr>'}
                        </tbody>
                    </table>
                </div>
            `;
        } else {
            const movs = state.estoque.movimentacoes || [];
            bodyHtml = `
                <div class="fg-tabela-wrap">
                    <table class="fg-tabela">
                        <thead><tr><th>Data/Hora</th><th>Insumo</th><th>Tipo</th><th>Qtd</th><th>Saldo após</th><th>Motivo</th></tr></thead>
                        <tbody>
                            ${movs.length ? movs.slice(0, 40).map((m) => `
                                <tr>
                                    <td>${esc(m.data_hora)}</td>
                                    <td><strong>${esc(m.item_nome)}</strong></td>
                                    <td><span class="fg-pill info">${esc(m.tipo)}</span></td>
                                    <td>${m.quantidade}</td>
                                    <td>${m.saldo_apos}</td>
                                    <td>${esc(m.motivo || '—')}</td>
                                </tr>
                            `).join('') : '<tr><td colspan="6">Nenhuma movimentação registrada ainda.</td></tr>'}
                        </tbody>
                    </table>
                </div>
            `;
        }

        root.innerHTML = tabsHtml + bodyHtml;

        root.querySelectorAll('.fg-subtab-btn').forEach((b) => {
            b.addEventListener('click', () => { state.subtabs.estoque = b.dataset.st; renderEstoque(); });
        });

        const fIns = document.getElementById('fg-form-insumo');
        if (fIns) {
            fIns.addEventListener('submit', async (e) => {
                e.preventDefault();
                const res = await apiGestao('estoque_salvar', {
                    nome: document.getElementById('fg-ins-nome').value,
                    unidade: document.getElementById('fg-ins-un').value,
                    quantidade_atual: document.getElementById('fg-ins-qtd').value,
                    estoque_minimo: document.getElementById('fg-ins-min').value,
                    custo_unitario: document.getElementById('fg-ins-custo').value
                });
                if (!res.success) return alert(res.message || 'Erro ao salvar insumo.');
                carregarEstoque();
            });
        }

        const fMov = document.getElementById('fg-form-mov-estoque');
        if (fMov) {
            fMov.addEventListener('submit', async (e) => {
                e.preventDefault();
                const res = await apiGestao('estoque_movimentar', {
                    item_id: document.getElementById('fg-mov-item').value,
                    tipo: document.getElementById('fg-mov-tipo').value,
                    quantidade: document.getElementById('fg-mov-qtd').value,
                    custo_unitario: document.getElementById('fg-mov-custo').value,
                    motivo: document.getElementById('fg-mov-motivo').value
                });
                if (!res.success) return alert(res.message || 'Erro ao movimentar.');
                carregarEstoque();
            });
        }

        root.querySelectorAll('.fg-del-ins').forEach((b) => {
            b.addEventListener('click', async () => {
                if (!confirm('Excluir este insumo?')) return;
                await apiGestao('estoque_excluir', { id: b.dataset.id });
                carregarEstoque();
            });
        });

        const btnAddIng = document.getElementById('fg-ft-add-ing');
        if (btnAddIng) {
            btnAddIng.addEventListener('click', () => {
                const sel = document.getElementById('fg-ft-insumo');
                const opt = sel && sel.options[sel.selectedIndex];
                const qtd = parseFloat(document.getElementById('fg-ft-qtd').value || '0');
                if (!opt || !opt.value || !(qtd > 0)) return alert('Selecione um insumo e informe a quantidade.');
                state.fichaIngredientesDraft.push({
                    item_id: opt.value,
                    nome: opt.dataset.nome,
                    quantidade: qtd,
                    unidade: document.getElementById('fg-ft-un').value
                });
                renderEstoque();
            });
        }
        root.querySelectorAll('.fg-ft-rm-ing').forEach((b) => {
            b.addEventListener('click', () => {
                state.fichaIngredientesDraft.splice(parseInt(b.dataset.idx, 10), 1);
                renderEstoque();
            });
        });
        const btnSalvaFt = document.getElementById('fg-ft-salvar');
        if (btnSalvaFt) {
            btnSalvaFt.addEventListener('click', async () => {
                const sel = document.getElementById('fg-ft-produto');
                const opt = sel && sel.options[sel.selectedIndex];
                if (!opt || !opt.value) return alert('Selecione um produto.');
                const res = await apiGestao('ficha_salvar', {
                    produto_id: opt.value,
                    produto_nome: opt.dataset.nome,
                    rendimento: document.getElementById('fg-ft-rend').value,
                    ingredientes: state.fichaIngredientesDraft
                });
                if (!res.success) return alert(res.message || 'Erro ao salvar ficha.');
                state.fichaIngredientesDraft = [];
                carregarEstoque();
            });
        }
        root.querySelectorAll('.fg-del-ficha').forEach((b) => {
            b.addEventListener('click', async () => {
                await apiGestao('ficha_excluir', { produto_id: b.dataset.pid });
                carregarEstoque();
            });
        });
        const fForn = document.getElementById('fg-form-forn');
        if (fForn) {
            fForn.addEventListener('submit', async (e) => {
                e.preventDefault();
                await apiGestao('fornecedor_salvar', {
                    nome: document.getElementById('fg-forn-nome').value,
                    telefone: document.getElementById('fg-forn-tel').value,
                    categoria: document.getElementById('fg-forn-cat').value
                });
                carregarEstoque();
            });
        }
        root.querySelectorAll('.fg-del-forn').forEach((b) => {
            b.addEventListener('click', async () => {
                await apiGestao('fornecedor_excluir', { id: b.dataset.id });
                carregarEstoque();
            });
        });
    }

    // =====================================================================
    // 3. MÓDULO FINANCEIRO: CAIXA, CONTAS A PAGAR/RECEBER & DRE
    // =====================================================================
    async function carregarFinanceiro() {
        const root = document.getElementById('fg-financeiro-root');
        if (!root) return;
        const [resCx, resFin] = await Promise.all([
            apiGestao('caixa_listar'),
            apiGestao('financeiro_listar', {
                inicio: state.financeiro.inicio || undefined,
                fim: state.financeiro.fim || undefined
            })
        ]);
        if (renderUpgradeBox(root, resCx) || renderUpgradeBox(root, resFin)) return;
        if (resCx && resCx.success) {
            state.caixa = resCx;
        }
        if (resFin && resFin.success) {
            state.financeiro.contas = resFin.contas || [];
            state.financeiro.dre = resFin.dre || null;
        }
        renderFinanceiro();
    }

    function renderFinanceiro() {
        const root = document.getElementById('fg-financeiro-root');
        if (!root) return;
        const aba = state.subtabs.financeiro;
        const cx = state.caixa.atual;
        const tot = state.caixa.totais || {};
        const dre = state.financeiro.dre;

        let html = `
            <div class="fg-subtabs">
                <button type="button" class="fg-subtab-btn ${aba === 'caixa' ? 'ativo' : ''}" data-st="caixa">Caixa do Dia</button>
                <button type="button" class="fg-subtab-btn ${aba === 'contas' ? 'ativo' : ''}" data-st="contas">Contas a Pagar / Receber</button>
                <button type="button" class="fg-subtab-btn ${aba === 'dre' ? 'ativo' : ''}" data-st="dre">DRE Gerencial & Lucratividade</button>
            </div>
        `;

        if (aba === 'caixa') {
            if (!cx || cx.status !== 'aberto') {
                html += `
                    <form id="fg-form-abrir-cx" class="fg-form-grid">
                        <div><label>Saldo inicial (troco em dinheiro)</label><input type="number" step="0.01" id="fg-cx-saldo" value="100.00" required></div>
                        <div><label>Observação</label><input type="text" id="fg-cx-obs" placeholder="Abertura de turno"></div>
                        <div><button type="submit" class="fg-btn fg-btn-prim"><i class="fa-solid fa-cash-register"></i> Abrir Caixa</button></div>
                    </form>
                `;
            } else {
                html += `
                    <div class="fg-grid-kpis">
                        <div class="fg-kpi destaque-ok"><div class="fg-kpi-label">Dinheiro Esperado</div><div class="fg-kpi-valor">${brl(tot.dinheiro_esperado)}</div><div class="fg-kpi-sub">Inicial ${brl(tot.saldo_inicial)}</div></div>
                        <div class="fg-kpi"><div class="fg-kpi-label">PIX no Turno</div><div class="fg-kpi-valor">${brl(tot.pix_esperado)}</div></div>
                        <div class="fg-kpi"><div class="fg-kpi-label">Cartão no Turno</div><div class="fg-kpi-valor">${brl(tot.cartao_esperado)}</div></div>
                        <div class="fg-kpi destaque-ia"><div class="fg-kpi-label">Total Movimento</div><div class="fg-kpi-valor">${brl(tot.total_geral_esperado)}</div><div class="fg-kpi-sub">Sangrias: ${brl(tot.sangrias)} | Supr.: ${brl(tot.suprimentos)}</div></div>
                    </div>

                    <form id="fg-form-mov-cx" class="fg-form-grid" style="background:#f8fafc;padding:12px;border-radius:12px;border:1px solid #e2e8f0;">
                        <div><label>Movimento</label><select id="fg-cx-mov-tipo"><option value="suprimento">Suprimento (+)</option><option value="sangria">Sangria (-)</option></select></div>
                        <div><label>Valor (R$)</label><input type="number" step="0.01" id="fg-cx-mov-valor" required></div>
                        <div><label>Descrição / Motivo</label><input type="text" id="fg-cx-mov-desc" required placeholder="Ex.: Pagamento gelo / Troco"></div>
                        <div><button type="submit" class="fg-btn fg-btn-sec">Registrar</button></div>
                    </form>

                    <form id="fg-form-fechar-cx" class="fg-form-grid" style="background:#fef2f2;padding:12px;border-radius:12px;border:1px solid #fecaca;">
                        <div><label>Dinheiro contado (R$)</label><input type="number" step="0.01" id="fg-cx-f-din" value="${Number(tot.dinheiro_esperado || 0).toFixed(2)}"></div>
                        <div><label>PIX conferido (R$)</label><input type="number" step="0.01" id="fg-cx-f-pix" value="${Number(tot.pix_esperado || 0).toFixed(2)}"></div>
                        <div><label>Cartão conferido (R$)</label><input type="number" step="0.01" id="fg-cx-f-car" value="${Number(tot.cartao_esperado || 0).toFixed(2)}"></div>
                        <div><button type="submit" class="fg-btn fg-btn-danger">Fechar Caixa & Apurar Quebra</button></div>
                    </form>
                `;
            }

            const hist = state.caixa.historico || [];
            html += `
                <h4 style="margin-top:16px;">Histórico de Fechamentos</h4>
                <div class="fg-tabela-wrap"><table class="fg-tabela">
                    <thead><tr><th>Abertura</th><th>Fechamento</th><th>Esperado</th><th>Contado</th><th>Diferença</th></tr></thead>
                    <tbody>
                        ${hist.length ? hist.slice(0, 15).map((h) => `
                            <tr>
                                <td>${esc(h.aberto_em)} (${esc(h.aberto_por)})</td>
                                <td>${esc(h.fechado_em || '—')}</td>
                                <td>${brl(h.resumo_fechamento && h.resumo_fechamento.esperado && h.resumo_fechamento.esperado.total_geral_esperado)}</td>
                                <td>${brl(h.resumo_fechamento && h.resumo_fechamento.contado && h.resumo_fechamento.contado.total)}</td>
                                <td><span class="fg-pill ${(h.resumo_fechamento && Math.abs(h.resumo_fechamento.diferenca_total) < 0.05) ? 'ok' : 'warn'}">${brl(h.resumo_fechamento && h.resumo_fechamento.diferenca_total)}</span></td>
                            </tr>
                        `).join('') : '<tr><td colspan="5">Nenhum fechamento registrado ainda.</td></tr>'}
                    </tbody>
                </table></div>
            `;
        } else if (aba === 'contas') {
            const contas = state.financeiro.contas || [];
            html += `
                <form id="fg-form-conta" class="fg-form-grid">
                    <div><label>Tipo</label><select id="fg-fin-tipo"><option value="pagar">Conta a Pagar</option><option value="receber">Receita / A Receber</option></select></div>
                    <div><label>Descrição</label><input type="text" id="fg-fin-desc" required placeholder="Ex.: Aluguel, Energia, Gás"></div>
                    <div><label>Categoria</label><input type="text" id="fg-fin-cat" value="Operacional"></div>
                    <div><label>Valor (R$)</label><input type="number" step="0.01" id="fg-fin-val" required></div>
                    <div><label>Vencimento</label><input type="date" id="fg-fin-venc" value="${new Date().toISOString().slice(0, 10)}"></div>
                    <div><label>Status</label><select id="fg-fin-st"><option value="pendente">Pendente</option><option value="pago">Já Pago/Recebido</option></select></div>
                    <div><button type="submit" class="fg-btn fg-btn-prim">Lançar Conta</button></div>
                </form>

                <div class="fg-tabela-wrap"><table class="fg-tabela">
                    <thead><tr><th>Vencimento</th><th>Tipo</th><th>Descrição</th><th>Valor</th><th>Status</th><th>Ações</th></tr></thead>
                    <tbody>
                        ${contas.length ? contas.slice(0, 60).map((c) => `
                            <tr>
                                <td>${esc(c.vencimento)}</td>
                                <td><span class="fg-pill ${c.tipo === 'receber' ? 'ok' : 'warn'}">${c.tipo === 'receber' ? 'Receita' : 'Despesa'}</span></td>
                                <td><strong>${esc(c.descricao)}</strong> <small style="color:#64748b;">(${esc(c.categoria)})</small></td>
                                <td><strong>${brl(c.valor)}</strong></td>
                                <td><span class="fg-pill ${c.status === 'pago' ? 'ok' : (c.status === 'cancelado' ? 'bad' : 'info')}">${esc(c.status)}</span></td>
                                <td>
                                    ${c.status === 'pendente' ? `<button type="button" class="fg-btn fg-btn-prim fg-btn-sm fg-pagar-conta" data-id="${esc(c.id)}">Quitar</button>` : ''}
                                    <button type="button" class="fg-btn fg-btn-danger fg-btn-sm fg-del-conta" data-id="${esc(c.id)}">Excluir</button>
                                </td>
                            </tr>
                        `).join('') : '<tr><td colspan="6">Nenhum lançamento financeiro ainda.</td></tr>'}
                    </tbody>
                </table></div>
            `;
        } else if (dre) {
            html += `
                <div class="fg-form-grid">
                    <div><label>Início</label><input type="date" id="fg-dre-ini" value="${esc(dre.periodo.inicio)}"></div>
                    <div><label>Fim</label><input type="date" id="fg-dre-fim" value="${esc(dre.periodo.fim)}"></div>
                    <div><button type="button" id="fg-dre-filtrar" class="fg-btn fg-btn-prim">Atualizar DRE</button></div>
                </div>
                <div class="fg-dre-grid">
                    <div class="fg-kpi">
                        <h4 style="margin-top:0;">Demonstrativo do Resultado (DRE)</h4>
                        <div class="fg-dre-linha"><span>(+) Faturamento Bruto (${dre.pedidos} ped.)</span><strong>${brl(dre.faturamento_bruto)}</strong></div>
                        <div class="fg-dre-linha"><span>(-) CMV Produtos (Fichas)</span><span>${brl(dre.cmv_produtos)}</span></div>
                        <div class="fg-dre-linha"><span>(-) Perdas de Estoque</span><span>${brl(dre.perdas_estoque)}</span></div>
                        <div class="fg-dre-linha"><span>(=) Margem de Contribuição (${dre.margem_contribuicao_pct}%)</span><strong>${brl(dre.margem_contribuicao)}</strong></div>
                        <div class="fg-dre-linha"><span>(-) Despesas Operacionais</span><span>${brl(dre.despesas_totais)}</span></div>
                        <div class="fg-dre-linha" style="border:none;font-size:1.05rem;"><span>(=) Resultado Operacional (${dre.lucratividade_pct}%)</span><strong>${brl(dre.resultado_operacional)}</strong></div>
                    </div>
                    <div class="fg-kpi">
                        <h4 style="margin-top:0;">Por Forma de Pagamento</h4>
                        ${(dre.por_pagamento || []).length ? dre.por_pagamento.map((p) => `
                            <div class="fg-dre-linha"><span>${esc(p.forma)} (${p.pedidos})</span><strong>${brl(p.valor)}</strong></div>
                        `).join('') : '<p style="color:#64748b;">Sem pedidos no período.</p>'}
                        <h4 style="margin:14px 0 6px;">Por Canal</h4>
                        ${(dre.por_canal || []).length ? dre.por_canal.map((c) => `
                            <div class="fg-dre-linha"><span>${esc(c.canal)} (${c.pedidos})</span><strong>${brl(c.valor)}</strong></div>
                        `).join('') : '<p style="color:#64748b;">Sem dados por canal.</p>'}
                    </div>
                </div>
            `;
        }

        root.innerHTML = html;

        root.querySelectorAll('.fg-subtab-btn').forEach((b) => {
            b.addEventListener('click', () => { state.subtabs.financeiro = b.dataset.st; renderFinanceiro(); });
        });
        const fAb = document.getElementById('fg-form-abrir-cx');
        if (fAb) {
            fAb.addEventListener('submit', async (e) => {
                e.preventDefault();
                const res = await apiGestao('caixa_abrir', {
                    saldo_inicial: document.getElementById('fg-cx-saldo').value,
                    observacao: document.getElementById('fg-cx-obs').value
                });
                if (!res.success) return alert(res.message);
                carregarFinanceiro();
            });
        }
        const fMv = document.getElementById('fg-form-mov-cx');
        if (fMv) {
            fMv.addEventListener('submit', async (e) => {
                e.preventDefault();
                const res = await apiGestao('caixa_movimentar', {
                    tipo: document.getElementById('fg-cx-mov-tipo').value,
                    valor: document.getElementById('fg-cx-mov-valor').value,
                    descricao: document.getElementById('fg-cx-mov-desc').value
                });
                if (!res.success) return alert(res.message);
                carregarFinanceiro();
            });
        }
        const fFc = document.getElementById('fg-form-fechar-cx');
        if (fFc) {
            fFc.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (!confirm('Confirma o fechamento do caixa atual?')) return;
                const res = await apiGestao('caixa_fechar', {
                    contado: {
                        dinheiro: document.getElementById('fg-cx-f-din').value,
                        pix: document.getElementById('fg-cx-f-pix').value,
                        cartao: document.getElementById('fg-cx-f-car').value
                    }
                });
                if (!res.success) return alert(res.message);
                alert('Caixa fechado! Diferença total: ' + brl(res.fechamento.diferenca_total));
                carregarFinanceiro();
            });
        }
        const fConta = document.getElementById('fg-form-conta');
        if (fConta) {
            fConta.addEventListener('submit', async (e) => {
                e.preventDefault();
                await apiGestao('financeiro_salvar', {
                    tipo: document.getElementById('fg-fin-tipo').value,
                    descricao: document.getElementById('fg-fin-desc').value,
                    categoria: document.getElementById('fg-fin-cat').value,
                    valor: document.getElementById('fg-fin-val').value,
                    vencimento: document.getElementById('fg-fin-venc').value,
                    status: document.getElementById('fg-fin-st').value
                });
                carregarFinanceiro();
            });
        }
        root.querySelectorAll('.fg-pagar-conta').forEach((b) => {
            b.addEventListener('click', async () => { await apiGestao('financeiro_pagar', { id: b.dataset.id }); carregarFinanceiro(); });
        });
        root.querySelectorAll('.fg-del-conta').forEach((b) => {
            b.addEventListener('click', async () => { await apiGestao('financeiro_excluir', { id: b.dataset.id }); carregarFinanceiro(); });
        });
        const btnDre = document.getElementById('fg-dre-filtrar');
        if (btnDre) {
            btnDre.addEventListener('click', () => {
                state.financeiro.inicio = document.getElementById('fg-dre-ini').value;
                state.financeiro.fim = document.getElementById('fg-dre-fim').value;
                carregarFinanceiro();
            });
        }
    }

    // =====================================================================
    // 4. MÓDULO CLIENTES, FIDELIDADE, CUPONS & RECUPERAÇÃO DE CARRINHO
    // =====================================================================
    async function carregarClientes() {
        const root = document.getElementById('fg-clientes-root');
        if (!root) return;
        const [resCli, resMkt] = await Promise.all([
            apiGestao('clientes_listar'),
            apiGestao('marketing_listar')
        ]);
        if (renderUpgradeBox(root, resCli) || renderUpgradeBox(root, resMkt)) return;
        if (resCli && resCli.success) state.clientes = resCli.clientes || [];
        if (resMkt && resMkt.success) state.marketing = { cupons: resMkt.cupons || [], recuperacao: resMkt.recuperacao || [] };
        renderClientes();
    }

    function renderClientes() {
        const root = document.getElementById('fg-clientes-root');
        if (!root) return;
        const aba = state.subtabs.clientes;
        let html = `
            <div class="fg-subtabs">
                <button type="button" class="fg-subtab-btn ${aba === 'crm' ? 'ativo' : ''}" data-st="crm">CRM & Fidelidade (${state.clientes.length})</button>
                <button type="button" class="fg-subtab-btn ${aba === 'cupons' ? 'ativo' : ''}" data-st="cupons">Cupons Promocionais (${state.marketing.cupons.length})</button>
                <button type="button" class="fg-subtab-btn ${aba === 'recuperacao' ? 'ativo' : ''}" data-st="recuperacao">Carrinhos Abandonados (${state.marketing.recuperacao.filter((x) => x.status === 'abandonado').length})</button>
            </div>
        `;

        if (aba === 'crm') {
            html += `
                <div class="fg-tabela-wrap"><table class="fg-tabela">
                    <thead><tr><th>Cliente</th><th>Telefone</th><th>Pedidos</th><th>Total Gasto</th><th>Pontos Fidelidade</th><th>Ação</th></tr></thead>
                    <tbody>
                        ${state.clientes.length ? state.clientes.slice(0, 60).map((c) => `
                            <tr>
                                <td><strong>${esc(c.nome)}</strong></td>
                                <td>${esc(c.telefone)}</td>
                                <td>${c.total_pedidos || 0}</td>
                                <td><strong>${brl(c.total_gasto)}</strong></td>
                                <td><span class="fg-pill ok">${c.pontos_fidelidade || 0} pts</span></td>
                                <td>${c.telefone ? `<a class="fg-btn fg-btn-prim fg-btn-sm" target="_blank" href="https://wa.me/55${esc(c.telefone)}?text=${encodeURIComponent('Olá ' + (c.nome || '') + '! Sentimos sua falta por aqui. Temos novidades no cardápio hoje!')}">WhatsApp</a>` : '—'}</td>
                            </tr>
                        `).join('') : '<tr><td colspan="6">Nenhum cliente sincronizado ainda (os pedidos criam o CRM automaticamente).</td></tr>'}
                    </tbody>
                </table></div>
            `;
        } else if (aba === 'cupons') {
            html += `
                <form id="fg-form-cupom" class="fg-form-grid">
                    <div><label>Código do Cupom</label><input type="text" id="fg-cup-cod" required placeholder="EX.: VOLTA10"></div>
                    <div><label>Tipo</label><select id="fg-cup-tipo"><option value="percentual">% Desconto</option><option value="valor">R$ Fixo</option><option value="frete_gratis">Frete Grátis</option></select></div>
                    <div><label>Valor (% ou R$)</label><input type="number" step="0.01" id="fg-cup-val" value="10"></div>
                    <div><label>Pedido mínimo (R$)</label><input type="number" step="0.01" id="fg-cup-min" value="0"></div>
                    <div><label>Validade</label><input type="date" id="fg-cup-validade"></div>
                    <div><button type="submit" class="fg-btn fg-btn-prim">Salvar Cupom</button></div>
                </form>
                <div class="fg-tabela-wrap"><table class="fg-tabela">
                    <thead><tr><th>Código</th><th>Regra</th><th>Mínimo</th><th>Usos</th><th>Ações</th></tr></thead>
                    <tbody>
                        ${state.marketing.cupons.length ? state.marketing.cupons.map((c) => `
                            <tr>
                                <td><strong>${esc(c.codigo)}</strong></td>
                                <td>${c.tipo === 'percentual' ? c.valor + '% OFF' : (c.tipo === 'frete_gratis' ? 'Frete Grátis' : brl(c.valor) + ' OFF')}</td>
                                <td>${brl(c.minimo_pedido)}</td>
                                <td>${c.usos || 0}</td>
                                <td><button type="button" class="fg-btn fg-btn-danger fg-btn-sm fg-del-cupom" data-cod="${esc(c.codigo)}">Excluir</button></td>
                            </tr>
                        `).join('') : '<tr><td colspan="5">Nenhum cupom cadastrado (FIDELIDADE10 também funciona por padrão).</td></tr>'}
                    </tbody>
                </table></div>
            `;
        } else {
            const recs = state.marketing.recuperacao || [];
            html += `
                <div class="fg-tabela-wrap"><table class="fg-tabela">
                    <thead><tr><th>Atualizado</th><th>Cliente</th><th>Itens</th><th>Valor</th><th>Status</th><th>Recuperar</th></tr></thead>
                    <tbody>
                        ${recs.length ? recs.map((r) => `
                            <tr>
                                <td>${esc(r.atualizado_em)}</td>
                                <td><strong>${esc(r.cliente || 'Visitante')}</strong><br><small>${esc(r.celular || 'Sem telefone')}</small></td>
                                <td>${(r.itens_resumo || []).map(esc).join(', ')}</td>
                                <td><strong>${brl(r.total)}</strong></td>
                                <td><span class="fg-pill ${r.status === 'convertido' ? 'ok' : 'warn'}">${esc(r.status)}</span></td>
                                <td>${r.celular ? `<a class="fg-btn fg-btn-laranja fg-btn-sm" target="_blank" href="https://wa.me/55${esc(r.celular)}?text=${encodeURIComponent('Oi ' + (r.cliente || '') + '! Vimos que você deixou itens no carrinho (' + brl(r.total) + '). Use o cupom FIDELIDADE10 para concluir seu pedido!')}">Chamar WhatsApp</a>` : 'Sem WhatsApp'}</td>
                            </tr>
                        `).join('') : '<tr><td colspan="6">Nenhum carrinho abandonado registrado.</td></tr>'}
                    </tbody>
                </table></div>
            `;
        }

        root.innerHTML = html;
        root.querySelectorAll('.fg-subtab-btn').forEach((b) => {
            b.addEventListener('click', () => { state.subtabs.clientes = b.dataset.st; renderClientes(); });
        });
        const fCup = document.getElementById('fg-form-cupom');
        if (fCup) {
            fCup.addEventListener('submit', async (e) => {
                e.preventDefault();
                await apiGestao('cupom_salvar', {
                    codigo: document.getElementById('fg-cup-cod').value,
                    tipo: document.getElementById('fg-cup-tipo').value,
                    valor: document.getElementById('fg-cup-val').value,
                    minimo_pedido: document.getElementById('fg-cup-min').value,
                    validade: document.getElementById('fg-cup-validade').value
                });
                carregarClientes();
            });
        }
        root.querySelectorAll('.fg-del-cupom').forEach((b) => {
            b.addEventListener('click', async () => { await apiGestao('cupom_excluir', { codigo: b.dataset.cod }); carregarClientes(); });
        });
    }

    // =====================================================================
    // 5. MÓDULO ENTREGADORES
    // =====================================================================
    async function carregarEntregadores() {
        const root = document.getElementById('fg-entregadores-root');
        if (!root) return;
        const res = await apiGestao('entregadores_listar');
        if (renderUpgradeBox(root, res)) return;
        if (res && res.success) {
            state.entregadores = { entregadores: res.entregadores || [], relatorio: res.relatorio || [] };
        }
        root.innerHTML = `
            <form id="fg-form-ent" class="fg-form-grid">
                <div><label>Nome do entregador</label><input type="text" id="fg-ent-nome" required placeholder="Ex.: Carlos Motoboy"></div>
                <div><label>WhatsApp</label><input type="text" id="fg-ent-tel" placeholder="11999990000"></div>
                <div><label>Veículo</label><select id="fg-ent-veic"><option value="Moto">Moto</option><option value="Bike">Bike</option><option value="Carro">Carro</option></select></div>
                <div><label>Taxa fixa repasse (R$)</label><input type="number" step="0.01" id="fg-ent-taxa" value="0"></div>
                <div><button type="submit" class="fg-btn fg-btn-prim">Cadastrar Entregador</button></div>
            </form>
            <div class="fg-tabela-wrap"><table class="fg-tabela">
                <thead><tr><th>Entregador</th><th>Veículo</th><th>WhatsApp</th><th>Entregas</th><th>Taxas Acumuladas</th></tr></thead>
                <tbody>
                    ${state.entregadores.entregadores.length ? state.entregadores.entregadores.map((e) => {
                        const st = (state.entregadores.relatorio || []).find((r) => r.id === e.id) || { entregas: 0, taxas_entrega: 0 };
                        return `<tr>
                            <td><strong>${esc(e.nome)}</strong></td>
                            <td>${esc(e.veiculo)}</td>
                            <td>${esc(e.telefone || '—')}</td>
                            <td>${st.entregas}</td>
                            <td><strong>${brl(st.taxas_entrega)}</strong></td>
                        </tr>`;
                    }).join('') : '<tr><td colspan="5">Nenhum entregador cadastrado ainda.</td></tr>'}
                </tbody>
            </table></div>
        `;
        const fEnt = document.getElementById('fg-form-ent');
        if (fEnt) {
            fEnt.addEventListener('submit', async (e) => {
                e.preventDefault();
                await apiGestao('entregador_salvar', {
                    nome: document.getElementById('fg-ent-nome').value,
                    telefone: document.getElementById('fg-ent-tel').value,
                    veiculo: document.getElementById('fg-ent-veic').value,
                    taxa_padrao: document.getElementById('fg-ent-taxa').value
                });
                carregarEntregadores();
            });
        }
    }

    // =====================================================================
    // 6. EQUIPE RBAC, AUDITORIA, BACKUP E CHECKLIST DE HOMOLOGAÇÃO (0/14)
    // =====================================================================
    async function carregarEquipe() {
        const root = document.getElementById('fg-equipe-root');
        if (!root) return;
        const [resEq, resAud, resChk] = await Promise.all([
            apiGestao('funcionarios_listar'),
            apiGestao('auditoria_listar'),
            apiGestao('homologacao_status')
        ]);
        if (renderUpgradeBox(root, resEq)) return;
        if (resEq && resEq.success) state.equipe = { funcionarios: resEq.funcionarios || [], perfis: resEq.perfis || {} };
        if (resAud && resAud.success) state.auditoria = resAud.auditoria || [];
        if (resChk && resChk.success) state.checks = resChk.checks || [];
        renderEquipe();
    }

    function renderEquipe() {
        const root = document.getElementById('fg-equipe-root');
        if (!root) return;
        const aba = state.subtabs.equipe;
        const concluídos = state.checks.filter((c) => c.ok).length;

        let html = `
            <div class="fg-subtabs">
                <button type="button" class="fg-subtab-btn ${aba === 'funcionarios' ? 'ativo' : ''}" data-st="funcionarios">Funcionários & RBAC</button>
                <button type="button" class="fg-subtab-btn ${aba === 'auditoria' ? 'ativo' : ''}" data-st="auditoria">Auditoria (${state.auditoria.length})</button>
                <button type="button" class="fg-subtab-btn ${aba === 'checklist' ? 'ativo' : ''}" data-st="checklist">Homologação (${concluídos}/${state.checks.length || 14})</button>
            </div>
        `;

        if (aba === 'funcionarios') {
            html += `
                <form id="fg-form-func" class="fg-form-grid">
                    <div><label>Nome completo</label><input type="text" id="fg-fn-nome" required placeholder="Ex.: João Silva"></div>
                    <div><label>Usuário de login</label><input type="text" id="fg-fn-login" required placeholder="joao"></div>
                    <div><label>Senha inicial</label><input type="password" id="fg-fn-senha" required placeholder="Mínimo 4 dígitos"></div>
                    <div><label>Perfil / Função</label>
                        <select id="fg-fn-funcao">
                            <option value="atendente">Atendente (Sem Financeiro)</option>
                            <option value="cozinha">Cozinha (Pedidos & Estoque)</option>
                            <option value="entregador">Entregador</option>
                            <option value="gerente">Gerente (Quase tudo)</option>
                            <option value="administrador">Administrador Total</option>
                        </select>
                    </div>
                    <div><button type="submit" class="fg-btn fg-btn-prim">Criar Usuário</button></div>
                </form>
                <p><button type="button" id="fg-btn-backup" class="fg-btn fg-btn-sec"><i class="fa-solid fa-download"></i> Exportar Backup Completo (JSON)</button></p>
                <div class="fg-tabela-wrap"><table class="fg-tabela">
                    <thead><tr><th>Nome</th><th>Usuário</th><th>Função</th><th>Permissões IA</th><th>Ações</th></tr></thead>
                    <tbody>
                        ${state.equipe.funcionarios.length ? state.equipe.funcionarios.map((f) => `
                            <tr>
                                <td><strong>${esc(f.nome)}</strong></td>
                                <td><code>${esc(f.login)}</code></td>
                                <td><span class="fg-pill info">${esc(f.funcao)}</span></td>
                                <td>${f.permissoes && f.permissoes.ia_financeiro ? 'IA Financeira + ' : ''}${f.permissoes && f.permissoes.ia_estoque ? 'IA Estoque + ' : ''}IA Operação</td>
                                <td><button type="button" class="fg-btn fg-btn-danger fg-btn-sm fg-del-func" data-id="${esc(f.id)}">Excluir</button></td>
                            </tr>
                        `).join('') : '<tr><td colspan="5">Nenhum funcionário cadastrado (apenas o usuário proprietário).</td></tr>'}
                    </tbody>
                </table></div>
            `;
        } else if (aba === 'auditoria') {
            html += `
                <div class="fg-tabela-wrap"><table class="fg-tabela">
                    <thead><tr><th>Data/Hora</th><th>Usuário</th><th>Módulo</th><th>Ação</th><th>Detalhes</th></tr></thead>
                    <tbody>
                        ${state.auditoria.length ? state.auditoria.map((a) => `
                            <tr>
                                <td>${esc(a.data_hora)}</td>
                                <td><strong>${esc(a.usuario)}</strong></td>
                                <td><span class="fg-pill info">${esc(a.modulo)}</span></td>
                                <td>${esc(a.acao)}</td>
                                <td>${esc(a.detalhes)}</td>
                            </tr>
                        `).join('') : '<tr><td colspan="5">Nenhum evento registrado na auditoria ainda.</td></tr>'}
                    </tbody>
                </table></div>
            `;
        } else {
            html += `
                <div class="fg-tabela-wrap">
                    ${state.checks.map((c) => `
                        <div class="fg-check-item">
                            <div>
                                <strong>${c.id}. ${esc(c.titulo)}</strong><br>
                                <small style="color:#64748b;">${esc(c.detalhe)}</small>
                            </div>
                            <span class="fg-pill ${c.ok ? 'ok' : 'warn'}">${c.ok ? 'Validado' : 'Pendente'}</span>
                        </div>
                    `).join('')}
                </div>
            `;
        }

        root.innerHTML = html;
        root.querySelectorAll('.fg-subtab-btn').forEach((b) => {
            b.addEventListener('click', () => { state.subtabs.equipe = b.dataset.st; renderEquipe(); });
        });
        const fFunc = document.getElementById('fg-form-func');
        if (fFunc) {
            fFunc.addEventListener('submit', async (e) => {
                e.preventDefault();
                const res = await apiGestao('funcionario_salvar', {
                    nome: document.getElementById('fg-fn-nome').value,
                    login: document.getElementById('fg-fn-login').value,
                    senha: document.getElementById('fg-fn-senha').value,
                    funcao: document.getElementById('fg-fn-funcao').value
                });
                if (!res.success) return alert(res.message);
                carregarEquipe();
            });
        }
        root.querySelectorAll('.fg-del-func').forEach((b) => {
            b.addEventListener('click', async () => { await apiGestao('funcionario_excluir', { id: b.dataset.id }); carregarEquipe(); });
        });
        const btnBkp = document.getElementById('fg-btn-backup');
        if (btnBkp) {
            btnBkp.addEventListener('click', async () => {
                const res = await apiGestao('backup_exportar');
                if (!res.success) return alert(res.message || 'Erro ao exportar backup.');
                const blob = new Blob([JSON.stringify(res.backup, null, 2)], { type: 'application/json' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'backup-food-gestao-' + new Date().toISOString().slice(0, 10) + '.json';
                a.click();
                URL.revokeObjectURL(url);
            });
        }
    }

    // =====================================================================
    // 7. IA PESSOAL DO CLIENTE (CHAVE + URL + VOZ + PROMPTS) NA ABA SEGURANÇA
    // =====================================================================
    function mostrarFeedbackIaPessoal(msg, tipo) {
        const fb = document.getElementById('fg-ia-feedback');
        if (!fb) return;
        if (!msg) {
            fb.hidden = true;
            fb.textContent = '';
            return;
        }
        fb.hidden = false;
        fb.className = 'fg-feedback ' + (tipo === 'erro' ? 'erro' : 'ok');
        fb.textContent = msg;
    }

    function aplicarEstadoVisualIaPessoal(info) {
        const st = info || {};
        const ativa = Boolean(st.ativa);
        const vozAtiva = Boolean(st.ativa && st.voz_ativa);
        const badge = document.getElementById('fg-ia-pessoal-status');
        if (badge) {
            if (ativa) {
                badge.textContent = vozAtiva ? 'Ativa (API + Comando por Voz)' : 'Ativa (Somente texto/API)';
                badge.style.background = '#dcfce7';
                badge.style.color = '#166534';
            } else {
                badge.textContent = 'Desativada (sem chave cadastrada)';
                badge.style.background = '#f3f4f6';
                badge.style.color = '#374151';
            }
        }
        const btnLimpar = document.getElementById('fg-ia-limpar');
        if (btnLimpar) btnLimpar.hidden = !ativa && !st.key_configurada;

        const inpKey = document.getElementById('fg-ia-key');
        if (inpKey) {
            inpKey.placeholder = st.key_configurada
                ? ('Chave salva (termina em ' + (st.key_final || '••••') + '). Deixe em branco para manter')
                : 'Cole sua chave API (fica salva só no servidor da sua loja)';
        }

        const resumoPublico = {
            ativa,
            voz_ativa: Boolean(st.voz_ativa),
            ler_resposta_voz: Boolean(st.ler_resposta_voz),
            modelo: st.modelo || ''
        };
        global.__pzIaPessoalStatus = resumoPublico;
        global.dispatchEvent(new CustomEvent('pz:ia-pessoal-atualizada', { detail: resumoPublico }));

        const hubBadge = document.getElementById('fg-ia-hub-badge');
        if (hubBadge) {
            let txt = '100% Local (Sem API externa)';
            if (ativa) txt = vozAtiva ? 'IA Pessoal do Cliente (API + Voz)' : 'IA Pessoal do Cliente (API)';
            hubBadge.innerHTML = '<i class="fa-solid fa-bolt"></i> Motor: ' + esc(txt);
        }
    }

    async function carregarIaPessoalSeguranca() {
        if (!document.getElementById('fg-ia-url')) return;
        const res = await apiGestao('ia_pessoal_obter');
        if (!res || !res.success || !res.ia_pessoal) return;
        const info = res.ia_pessoal;

        const elProv = document.getElementById('fg-ia-provedor');
        const elUrl = document.getElementById('fg-ia-url');
        const elKey = document.getElementById('fg-ia-key');
        const elMod = document.getElementById('fg-ia-modelo');
        const elPrompt = document.getElementById('fg-ia-prompt');
        const elVoz = document.getElementById('fg-ia-voz');
        const elFalar = document.getElementById('fg-ia-falar');

        if (elProv) elProv.value = info.provedor || 'auto';
        if (elUrl) elUrl.value = info.api_url || '';
        if (elKey) elKey.value = '';
        if (elMod) elMod.value = info.modelo || '';
        if (elPrompt) elPrompt.value = info.prompt_sistema || '';
        if (elVoz) elVoz.checked = info.voz_ativa !== false;
        if (elFalar) elFalar.checked = Boolean(info.ler_resposta_voz);

        aplicarEstadoVisualIaPessoal(info);
    }

    function lerFormularioIaPessoal() {
        return {
            provedor: (document.getElementById('fg-ia-provedor') || {}).value || 'auto',
            api_url: ((document.getElementById('fg-ia-url') || {}).value || '').trim(),
            api_key: ((document.getElementById('fg-ia-key') || {}).value || '').trim(),
            modelo: ((document.getElementById('fg-ia-modelo') || {}).value || '').trim(),
            prompt_sistema: ((document.getElementById('fg-ia-prompt') || {}).value || '').trim(),
            voz_ativa: Boolean((document.getElementById('fg-ia-voz') || {}).checked),
            ler_resposta_voz: Boolean((document.getElementById('fg-ia-falar') || {}).checked)
        };
    }

    function vincularIaPessoalSeguranca() {
        const btnSalvar = document.getElementById('fg-ia-salvar');
        const btnTestar = document.getElementById('fg-ia-testar');
        const btnLimpar = document.getElementById('fg-ia-limpar');

        if (btnSalvar && !btnSalvar.dataset.ligado) {
            btnSalvar.dataset.ligado = '1';
            btnSalvar.addEventListener('click', async () => {
                mostrarFeedbackIaPessoal('');
                const payload = lerFormularioIaPessoal();
                if (payload.api_url && !/^https?:\/\//i.test(payload.api_url)) {
                    mostrarFeedbackIaPessoal('Informe uma URL válida começando com https:// ou http://.', 'erro');
                    return;
                }
                const res = await apiGestao('ia_pessoal_salvar', payload);
                if (!res || !res.success) {
                    mostrarFeedbackIaPessoal((res && res.message) || 'Erro ao salvar IA pessoal.', 'erro');
                    return;
                }
                const elKey = document.getElementById('fg-ia-key');
                if (elKey) elKey.value = '';
                aplicarEstadoVisualIaPessoal(res.ia_pessoal);
                mostrarFeedbackIaPessoal(
                    res.ia_pessoal && res.ia_pessoal.ativa
                        ? 'IA pessoal salva e ativada na sua loja! O microfone de comandos por voz já está disponível no Agente IA.'
                        : 'Preferências salvas. A IA pessoal continuará desativada enquanto chave e URL não estiverem preenchidas.',
                    'ok'
                );
            });
        }

        if (btnTestar && !btnTestar.dataset.ligado) {
            btnTestar.dataset.ligado = '1';
            btnTestar.addEventListener('click', async () => {
                mostrarFeedbackIaPessoal('Testando conexão com a URL da IA pessoal...', 'ok');
                const payload = lerFormularioIaPessoal();
                const res = await apiGestao('ia_pessoal_testar', payload);
                if (!res || !res.success) {
                    mostrarFeedbackIaPessoal((res && res.message) || 'Falha ao conectar na URL/Chave informada.', 'erro');
                    return;
                }
                const tempo = res.latencia_ms ? (' (' + res.latencia_ms + ' ms)') : '';
                mostrarFeedbackIaPessoal('Conexão validada com sucesso' + tempo + ': ' + (res.texto || 'OK'), 'ok');
            });
        }

        if (btnLimpar && !btnLimpar.dataset.ligado) {
            btnLimpar.dataset.ligado = '1';
            btnLimpar.addEventListener('click', async () => {
                if (!confirm('Remover a chave e desativar a IA pessoal e os comandos de voz desta loja?')) return;
                const res = await apiGestao('ia_pessoal_salvar', {
                    limpar_chave: true,
                    api_url: '',
                    api_key: '',
                    modelo: '',
                    prompt_sistema: '',
                    voz_ativa: false,
                    ler_resposta_voz: false
                });
                if (!res || !res.success) {
                    mostrarFeedbackIaPessoal((res && res.message) || 'Erro ao remover chave.', 'erro');
                    return;
                }
                ['fg-ia-url', 'fg-ia-key', 'fg-ia-modelo', 'fg-ia-prompt'].forEach((id) => {
                    const el = document.getElementById(id);
                    if (el) el.value = '';
                });
                aplicarEstadoVisualIaPessoal(res.ia_pessoal);
                mostrarFeedbackIaPessoal('Chave removida. O sistema voltou para o modo local sem IA externa.', 'ok');
            });
        }
    }

    // =====================================================================
    // 8. DISPUTA DE TERRITÓRIO & MELHORIA CONTÍNUA DOS PRODUTOS
    // =====================================================================
    async function carregarTerritorio() {
        const root = document.getElementById('fg-territorio-root');
        if (!root) return;
        root.innerHTML = '<p style="color:#64748b;">Calculando ranking e diagnóstico territorial...</p>';

        const res = await apiGestao('territorio_analise');
        if (!res || !res.success || !res.loja) {
            root.innerHTML = '<p class="fg-feedback erro">Não foi possível carregar os dados territoriais agora.</p>';
            return;
        }

        const l = res.loja;
        const rk = res.ranking || {};
        const dicas = res.dicas || [];
        const produtos = res.top_produtos_para_melhorar || [];
        const temIaPessoal = Boolean(global.__pzIaPessoalStatus && global.__pzIaPessoalStatus.ativa);

        const barraPilar = (titulo, valor, max) => {
            const pct = Math.max(0, Math.min(100, Math.round((Number(valor || 0) / max) * 100)));
            return `
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px;">
                    <div style="display:flex;justify-content:space-between;font-weight:700;font-size:0.9rem;">
                        <span>${esc(titulo)}</span><span>${esc(valor)} / ${max}</span>
                    </div>
                    <div style="height:8px;background:#e2e8f0;border-radius:999px;margin-top:6px;overflow:hidden;">
                        <div style="height:100%;width:${pct}%;background:${pct >= 75 ? '#16a34a' : (pct >= 45 ? '#f59e0b' : '#dc2626')};"></div>
                    </div>
                </div>
            `;
        };

        root.innerHTML = `
            ${(l.cadastro_completo === false && Array.isArray(l.pendencias_cadastro) && l.pendencias_cadastro.length) ? `
                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:14px 16px;margin-bottom:16px;color:#991b1b;">
                    <strong><i class="fa-solid fa-triangle-exclamation"></i> Cadastro obrigatório incompleto no Marketplace</strong>
                    <div style="font-size:0.9rem;margin-top:6px;">Para sua empresa aparecer com selo verificado e máxima relevância, regularize as pendências:</div>
                    <ul style="margin:8px 0 0 18px;font-size:0.88rem;line-height:1.5;">
                        ${l.pendencias_cadastro.map((p) => `<li>${esc(p)}</li>`).join('')}
                    </ul>
                </div>
            ` : ''}
            <div class="fg-grid-kpis" style="margin-bottom:16px;">
                <div class="fg-kpi destaque-ia">
                    <div class="fg-kpi-label">Score no Território</div>
                    <div class="fg-kpi-valor">${esc(l.score_territorio)} / 100</div>
                    <div class="fg-kpi-sub">Líder local: ${esc(rk.score_lider || l.score_territorio)} / 100</div>
                </div>
                <div class="fg-kpi destaque-ok">
                    <div class="fg-kpi-label">Posição no Ranking</div>
                    <div class="fg-kpi-valor">#${esc(rk.posicao || 1)} de ${esc(rk.total_lojas_territorio || 1)}</div>
                    <div class="fg-kpi-sub">${esc(rk.escopo || 'Território')}</div>
                </div>
                <div class="fg-kpi">
                    <div class="fg-kpi-label">Média Regional (Anônima)</div>
                    <div class="fg-kpi-valor">${brl(rk.media_preco_regiao || l.preco_medio)}</div>
                    <div class="fg-kpi-sub">Entrega média: ${esc(rk.media_tempo_regiao || l.tempo_medio_min)} min · Frete: ${brl(rk.media_frete_regiao || 0)}</div>
                </div>
                <div class="fg-kpi">
                    <div class="fg-kpi-label">Avaliação Pública</div>
                    <div class="fg-kpi-valor">${esc(l.nota_media)} ★</div>
                    <div class="fg-kpi-sub">${esc(l.total_avaliacoes || 0)} avaliação(ões) no Marketplace</div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-bottom:18px;">
                ${barraPilar('Cardápio & Fotos', (l.pilares && l.pilares.cardapio) || 0, 30)}
                ${barraPilar('Agilidade Operacional', (l.pilares && l.pilares.operacao) || 0, 25)}
                ${barraPilar('Competitividade & Ofertas', (l.pilares && l.pilares.competitividade) || 0, 25)}
                ${barraPilar('Reputação & Retenção', (l.pilares && l.pilares.reputacao) || 0, 20)}
            </div>

            <div style="background:#f0fdfa;border:1px solid #99f6e4;border-radius:12px;padding:16px;margin-bottom:18px;">
                <h4 style="margin-top:0;"><i class="fa-solid fa-map-pin"></i> Território de Atuação no Marketplace</h4>
                <div class="grade-admin">
                    <div class="form-group-admin"><label for="fg-ter-cidade">Cidade</label><input type="text" id="fg-ter-cidade" value="${esc(l.cidade || '')}" placeholder="Ex.: Goiânia"></div>
                    <div class="form-group-admin"><label for="fg-ter-bairro">Bairro</label><input type="text" id="fg-ter-bairro" value="${esc(l.bairro || '')}" placeholder="Ex.: Setor Bueno"></div>
                    <div class="form-group-admin"><label for="fg-ter-estado">UF</label><input type="text" id="fg-ter-estado" value="${esc(l.estado || '')}" maxlength="2" placeholder="GO"></div>
                    <div class="form-group-admin"><label for="fg-ter-cep">CEP</label><input type="text" id="fg-ter-cep" value="${esc(l.cep || '')}" placeholder="00000-000"></div>
                    <div class="form-group-admin"><label for="fg-ter-raio">Raio de entrega (km — até 300 km)</label><input type="number" id="fg-ter-raio" min="1" max="300" value="${esc(l.raio_entrega_km || 10)}"></div>
                    <div class="form-group-admin"><label for="fg-ter-tempo">Tempo estimado (min)</label><input type="number" id="fg-ter-tempo" min="10" max="180" value="${esc(l.tempo_medio_min || 35)}"></div>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <button type="button" id="fg-ter-salvar" class="btn-save-file" style="width:auto;min-height:46px;background:#0f766e;"><i class="fa-solid fa-save"></i> Salvar território no Marketplace</button>
                    <button type="button" id="fg-ter-gps" class="btn-save-file" style="width:auto;min-height:46px;background:#2563eb;"><i class="fa-solid fa-location-crosshairs"></i> Capturar GPS da Empresa</button>
                    <span class="fg-pill ${l.localizacao_ativa ? 'ok' : 'urgente'}">${l.localizacao_ativa ? ('GPS ativo (' + Number(l.lat).toFixed(4) + ', ' + Number(l.lng).toFixed(4) + ')') : 'GPS pendente (obrigatório)'}</span>
                </div>
                <span id="fg-ter-fb" style="display:inline-block;margin-top:8px;font-weight:700;"></span>
            </div>

            ${res.algoritmo_metricas ? `
                <div style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:12px;padding:14px;margin-bottom:18px;">
                    <h4 style="margin:0 0 8px;color:#312e81;"><i class="fa-solid fa-fire"></i> Entrega pelo Algoritmo de Recomendações (Estilo TikTok)</h4>
                    <div class="fg-grid-kpis">
                        <div class="fg-kpi"><div class="fg-kpi-label">Impressões na Região</div><div class="fg-kpi-valor">${esc(res.algoritmo_metricas.impressoes || 0)}</div></div>
                        <div class="fg-kpi"><div class="fg-kpi-label">Cliques nos Produtos</div><div class="fg-kpi-valor">${esc(res.algoritmo_metricas.cliques || 0)}</div><div class="fg-kpi-sub">CTR: ${esc(res.algoritmo_metricas.ctr_pct || 0)}%</div></div>
                        <div class="fg-kpi"><div class="fg-kpi-label">Adições ao Carrinho</div><div class="fg-kpi-valor">${esc(res.algoritmo_metricas.add_carrinho || 0)}</div><div class="fg-kpi-sub">Conversão: ${esc(res.algoritmo_metricas.conversao_pct || 0)}%</div></div>
                        <div class="fg-kpi"><div class="fg-kpi-label">Tempo de Atenção</div><div class="fg-kpi-valor">${esc(res.algoritmo_metricas.atencao_media_seg || 0)}s</div><div class="fg-kpi-sub">Média dos visitantes</div></div>
                    </div>
                </div>
            ` : ''}

            <h4 style="margin:0 0 10px;"><i class="fa-solid fa-wand-magic-sparkles"></i> O que melhorar na sua empresa e produtos agora</h4>
            <div style="display:grid;gap:10px;margin-bottom:18px;">
                ${dicas.map((d) => `
                    <div class="fg-alerta-item ${esc(d.prioridade === 'alta' ? 'urgente' : (d.prioridade === 'media' ? 'atencao' : 'dica'))}" style="justify-content:space-between;">
                        <div style="flex:1;">
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                <strong>${esc(d.titulo)}</strong>
                                <span class="fg-pill info">${esc(d.impacto_pontos)}</span>
                            </div>
                            <div style="font-size:0.9rem;color:#334155;margin-top:4px;">${esc(d.motivo)}</div>
                            <div style="font-size:0.88rem;color:#0f766e;font-weight:700;margin-top:4px;">Ação recomendada: ${esc(d.acao_sugerida)}</div>
                        </div>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            ${d.aba_destino && d.aba_destino !== 'territorio' ? `<button type="button" class="fg-alerta-btn" data-ter-aba="${esc(d.aba_destino)}">Abrir ${esc(d.aba_destino)}</button>` : ''}
                            ${d.comando_ia ? (temIaPessoal
                                ? `<button type="button" class="fg-alerta-btn" style="background:#0f766e;color:#fff;" data-ter-ia="${esc(d.comando_ia)}">Aplicar com minha IA</button>`
                                : `<span style="font-size:0.78rem;color:#64748b;align-self:center;">IA pessoal opcional (Trocar senha &amp; IA)</span>`) : ''}
                        </div>
                    </div>
                `).join('')}
            </div>

            ${produtos.length ? `
                <h4 style="margin:0 0 8px;"><i class="fa-solid fa-tags"></i> Produtos que merecem melhoria prioritária</h4>
                <div class="fg-tabela-wrap">
                    <table class="fg-tabela">
                        <thead><tr><th>Produto</th><th>Categoria</th><th>Preço</th><th>Oportunidade</th></tr></thead>
                        <tbody>
                            ${produtos.map((p) => `
                                <tr>
                                    <td><strong>${esc(p.nome)}</strong></td>
                                    <td>${esc(p.categoria)}</td>
                                    <td>${brl(p.preco)}</td>
                                    <td>${(p.problemas || []).map((pr) => `<span class="fg-pill warn" style="margin-right:4px;">${esc(pr)}</span>`).join('')}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            ` : ''}

            <div class="fg-ter-box" style="margin-top:16px;">
                <h4>★ Avaliações e Feedbacks Públicos dos Clientes (${Number((l.resumo_avaliacoes && l.resumo_avaliacoes.total) || (Array.isArray(l.avaliacoes) ? l.avaliacoes.filter((x) => x && x.id).length : 0))})</h4>
                ${Array.isArray(l.avaliacoes) && l.avaliacoes.length ? `
                    <div style="display:grid;gap:10px;margin-top:10px;">
                        ${l.avaliacoes.slice(0, 20).map((av) => {
                            const avId = String(av.id || '');
                            const nota = Number(av.nota || 5);
                            const nomeCliente = av.cliente_nome || av.autor || 'Cliente';
                            const verificada = Boolean(av.verificada || av.compra_verificada);
                            const tagsLista = Array.isArray(av.melhorias_tags) ? av.melhorias_tags : (Array.isArray(av.tags) ? av.tags : []);
                            const estrelas = '★'.repeat(Math.max(1, Math.min(5, Math.round(nota)))) + '☆'.repeat(Math.max(0, 5 - Math.round(nota)));
                            const tagsHtml = tagsLista.length
                                ? tagsLista.map((tg) => `<span style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:999px;padding:2px 8px;font-size:0.75rem;margin-right:4px;">${esc(tg)}</span>`).join('')
                                : '';
                            return `
                                <div style="border:1px solid #e5e7eb;border-radius:10px;padding:12px;background:#fff;">
                                    <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;align-items:center;">
                                        <div>
                                            <strong style="color:#f59e0b;">${estrelas} (${nota.toFixed(1)})</strong>
                                            <span style="margin-left:6px;font-weight:700;">${esc(nomeCliente)}</span>
                                            ${verificada ? '<span style="margin-left:6px;color:#059669;font-size:0.75rem;font-weight:700;">✓ Compra verificada</span>' : ''}
                                        </div>
                                        <small style="color:#6b7280;">${esc((av.criado_em || '').slice(0, 10))}</small>
                                    </div>
                                    ${av.comentario ? `<p style="margin:6px 0;color:#374151;">${esc(av.comentario)}</p>` : '<p style="margin:6px 0;color:#9ca3af;font-style:italic;">Sem comentário escrito.</p>'}
                                    ${tagsHtml ? `<div style="margin:4px 0;">${tagsHtml}</div>` : ''}
                                    ${avId ? `
                                        <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                            <input type="text" class="fg-input-resp-av" data-resp-input-id="${esc(avId)}" value="${esc(av.resposta_loja || '')}" placeholder="Responder publicamente a este cliente..." style="flex:1;min-width:200px;padding:7px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:0.86rem;">
                                            <button type="button" class="btn-sec" data-resp-av-id="${esc(avId)}" style="padding:7px 12px;font-size:0.85rem;">${av.resposta_loja ? 'Atualizar resposta' : 'Responder'}</button>
                                        </div>
                                    ` : '<small style="color:#64748b;">Depoimento em destaque configurado no editor da loja.</small>'}
                                </div>
                            `;
                        }).join('')}
                    </div>
                ` : '<p class="texto-ajuda">Esta loja ainda não recebeu avaliações de clientes.</p>'}
            </div>
        `;

        box.querySelectorAll('[data-resp-av-id]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                const avId = btn.getAttribute('data-resp-av-id');
                const input = Array.from(box.querySelectorAll('.fg-input-resp-av')).find((el) => el.getAttribute('data-resp-input-id') === avId);
                const resposta = input ? input.value.trim() : '';
                if (!avId || !resposta) {
                    toast('Digite uma resposta antes de enviar.', 'aviso');
                    return;
                }
                btn.disabled = true;
                try {
                    await apiGestao('responder_avaliacao', { id: avId, resposta });
                    toast('Resposta publicada na vitrine e no AcheiAki!');
                    if (window.PedidoZapCelulas && typeof window.PedidoZapCelulas.emitir === 'function') {
                        window.PedidoZapCelulas.emitir('avaliacao_publicada', { tenant: window.TENANT && window.TENANT.slug ? window.TENANT.slug : '' });
                    }
                    await carregarTerritorio();
                } catch (err) {
                    toast(err.message || 'Erro ao responder avaliação.', 'erro');
                } finally {
                    btn.disabled = false;
                }
            });
        });

        const btnSalvar = document.getElementById('fg-ter-salvar');
        if (btnSalvar) {
            btnSalvar.addEventListener('click', async () => {
                const fb = document.getElementById('fg-ter-fb');
                if (fb) fb.textContent = 'Salvando...';
                const r = await apiGestao('territorio_salvar_endereco', {
                    cidade: (document.getElementById('fg-ter-cidade') || {}).value || '',
                    bairro: (document.getElementById('fg-ter-bairro') || {}).value || '',
                    estado: (document.getElementById('fg-ter-estado') || {}).value || '',
                    cep: (document.getElementById('fg-ter-cep') || {}).value || '',
                    raio_entrega_km: Number((document.getElementById('fg-ter-raio') || {}).value || 7),
                    tempo_entrega_min: Number((document.getElementById('fg-ter-tempo') || {}).value || 35)
                });
                if (r && r.success) {
                    const boxAlerta = document.getElementById('fg-vendedor-loc-alerta');
                    if (boxAlerta && r.loja && r.loja.localizacao_ativa) boxAlerta.hidden = true;
                    carregarTerritorio();
                } else if (fb) {
                    fb.textContent = (r && r.message) || 'Erro ao salvar território.';
                }
            });
        }

        const btnGpsTer = document.getElementById('fg-ter-gps');
        if (btnGpsTer) {
            btnGpsTer.addEventListener('click', () => capturarGpsEmpresa());
        }

        root.querySelectorAll('button[data-ter-aba]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const aba = btn.getAttribute('data-ter-aba');
                if (global.__pzAtivarAbaAdmin) global.__pzAtivarAbaAdmin(aba);
            });
        });
        root.querySelectorAll('button[data-ter-ia]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const cmd = btn.getAttribute('data-ter-ia');
                if (global.__pzAtivarAbaAdmin) global.__pzAtivarAbaAdmin('visao-geral');
                if (state.assistenteHub) state.assistenteHub.receber(cmd);
                else if (global.PedidoZapAssistente) {
                    global.PedidoZapAssistente.abrirPainel();
                    global.PedidoZapAssistente.receber(cmd);
                }
            });
        });
    }

    function capturarGpsEmpresa() {
        if (!navigator.geolocation) {
            alert('Este navegador não tem suporte a GPS automático. Preencha Cidade, Bairro e CEP em Território & Melhorias.');
            return;
        }
        const btnTopo = document.getElementById('fg-btn-ativar-gps-loja');
        if (btnTopo) {
            btnTopo.disabled = true;
            btnTopo.textContent = 'Capturando GPS...';
        }
        navigator.geolocation.getCurrentPosition(async (pos) => {
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            let cidade = (document.getElementById('fg-ter-cidade') || {}).value || '';
            let bairro = (document.getElementById('fg-ter-bairro') || {}).value || '';
            let estado = (document.getElementById('fg-ter-estado') || {}).value || '';
            try {
                const rGeo = await fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng));
                if (rGeo.ok) {
                    const d = await rGeo.json();
                    const addr = (d && d.address) || {};
                    if (!cidade) cidade = addr.city || addr.town || addr.village || addr.municipality || '';
                    if (!bairro) bairro = addr.suburb || addr.neighbourhood || '';
                    if (!estado && addr.state_code) estado = String(addr.state_code).toUpperCase().slice(0, 2);
                }
            } catch (e) { /* ignore */ }

            const res = await apiGestao('territorio_salvar_endereco', {
                lat,
                lng,
                cidade,
                bairro,
                estado
            });
            if (btnTopo) {
                btnTopo.disabled = false;
                btnTopo.innerHTML = '<i class="fa-solid fa-location-dot"></i> Ativar localização GPS agora';
            }
            if (res && res.success) {
                const box = document.getElementById('fg-vendedor-loc-alerta');
                if (box) box.hidden = true;
                carregarTerritorio();
            } else {
                alert((res && res.message) || 'Não foi possível salvar a localização da empresa.');
            }
        }, () => {
            if (btnTopo) {
                btnTopo.disabled = false;
                btnTopo.innerHTML = '<i class="fa-solid fa-location-dot"></i> Ativar localização GPS agora';
            }
            alert('Permissão de GPS negada. Vá em "Território & Melhorias" e salve seu endereço completo com CEP.');
        }, { enableHighAccuracy: true, timeout: 10000 });
    }

    async function verificarLocalizacaoObrigatoriaVendedor() {
        const box = document.getElementById('fg-vendedor-loc-alerta');
        if (!box) return;
        const btnGps = document.getElementById('fg-btn-ativar-gps-loja');
        const btnIr = document.getElementById('fg-btn-ir-territorio');
        if (btnGps && !btnGps.dataset.ligado) {
            btnGps.dataset.ligado = '1';
            btnGps.addEventListener('click', capturarGpsEmpresa);
        }
        if (btnIr && !btnIr.dataset.ligado) {
            btnIr.dataset.ligado = '1';
            btnIr.addEventListener('click', () => {
                if (global.__pzAtivarAbaAdmin) global.__pzAtivarAbaAdmin('territorio');
            });
        }
        if (global.PedidoZapApresentacao && global.PedidoZapApresentacao.ativo()) {
            box.hidden = true;
            return;
        }
        const res = await apiGestao('territorio_analise');
        if (res && res.success && res.loja) {
            box.hidden = Boolean(res.loja.localizacao_ativa);
        }
    }

    // =====================================================================
    // ABA INTEGRAÇÕES PDV / ERP, FISCAL NFC-E / SAT, PUSH & ALTA ESCALA
    // =====================================================================
    async function carregarIntegracoes() {
        const box = document.getElementById('fg-integracoes-root');
        if (!box) return;
        box.innerHTML = '<div class="fg-vazio">Carregando conectores PDV, NFC-e, Push e status de escala...</div>';
        const res = await apiGestao('infra_resumo');
        if (!res || !res.success) {
            box.innerHTML = `<div class="fg-vazio">${esc((res && res.message) || 'Sem permissão para visualizar integrações.')}</div>`;
            return;
        }
        renderIntegracoes(res, box);
    }

    function renderIntegracoes(res, box) {
        const pdv = res.pdv || {};
        const fiscal = res.fiscal || {};
        const infra = res.infra || {};
        const cacheInfo = infra.cache || {};
        const queueInfo = infra.queue || {};
        const mediaInfo = infra.media || {};
        const rtInfo = infra.realtime || {};
        const notas = Array.isArray(res.notas_fiscais) ? res.notas_fiscais : [];
        const drivers = pdv.drivers_suportados || {
            open_delivery: 'Open Delivery Padrão',
            saipos: 'Saipos PDV',
            consumer: 'Consumer / MenuDino',
            linx: 'Linx Degust / Microvix',
            totvs: 'TOTVS Chef / Bematech',
            stone: 'Stone / Ton PDV'
        };
        const endpointPdv = new URL('pdv_api.php', global.location.href).href;
        const specUrl = new URL('pdv_api.php?action=spec', global.location.href).href;
        const mobileGuideUrl = new URL('pdv_api.php?action=mobile_guide', global.location.href).href;

        box.innerHTML = `
            <div class="fg-ter-kpis" style="margin-bottom:14px;">
                <div class="fg-kpi"><span>Cache Memória</span><strong>${esc(cacheInfo.driver || 'l1_memoria')}</strong><small>${cacheInfo.redis_conectado ? 'Redis conectado' : 'L1 + Disco rápido'}</small></div>
                <div class="fg-kpi"><span>Fila Assíncrona</span><strong>${Number(queueInfo.pendentes || 0)} pendente(s)</strong><small>Driver: ${esc(queueInfo.driver || 'sql')}</small></div>
                <div class="fg-kpi"><span>Mídia WebP / CDN</span><strong>${mediaInfo.webp_suportado ? 'WebP Ativo' : 'GD Básico'}</strong><small>${mediaInfo.s3_r2_ativo ? 'R2 / S3 conectado' : 'Proxy WebP automático'}</small></div>
                <div class="fg-kpi"><span>Tempo Real & Push</span><strong>${rtInfo.ws_habilitado ? 'WebSocket + SSE' : 'SSE Tempo Real'}</strong><small>${Number(res.push_inscritos || 0)} dispositivo(s) · Workers: ${esc(infra.php_workers || '1')}</small></div>
            </div>

            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
                <button type="button" class="btn-sec" id="fg-int-drenar"><i class="fa-solid fa-bolt"></i> Processar fila assíncrona agora</button>
                <a class="btn-sec" href="${esc(specUrl)}" target="_blank" rel="noopener"><i class="fa-solid fa-file-code"></i> Documentação OpenAPI PDV (JSON)</a>
                <a class="btn-sec" href="${esc(mobileGuideUrl)}" target="_blank" rel="noopener"><i class="fa-solid fa-mobile-screen-button"></i> Projeto Nativo iOS/Android (Capacitor)</a>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:16px;">
                <section class="fg-ter-box">
                    <h4><i class="fa-solid fa-cash-register"></i> Conector PDV / ERP (Open Delivery)</h4>
                    <label style="display:block;margin:8px 0;"><input type="checkbox" id="fg-pdv-ativo" ${pdv.habilitado ? 'checked' : ''}> Ativar API aberta para PDV / ERP externo</label>
                    <label style="display:block;font-weight:700;margin-top:8px;">Sistema PDV / ERP</label>
                    <select id="fg-pdv-driver" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;margin-top:4px;">
                        ${Object.keys(drivers).map((k) => `<option value="${esc(k)}" ${pdv.driver === k ? 'selected' : ''}>${esc(drivers[k])}</option>`).join('')}
                    </select>
                    <label style="display:block;font-weight:700;margin-top:10px;">Endpoint base Open Delivery</label>
                    <input type="text" readonly value="${esc(endpointPdv)}" style="width:100%;padding:8px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;font-size:0.82rem;">
                    <label style="display:block;font-weight:700;margin-top:10px;">API Key (Authorization Bearer / X-Api-Key)</label>
                    <div style="display:flex;gap:6px;margin-top:4px;">
                        <input type="text" id="fg-pdv-apikey" readonly value="${esc(pdv.api_key || '(Será gerada ao salvar)')}" style="flex:1;padding:8px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;font-size:0.82rem;">
                        <button type="button" class="btn-sec" id="fg-pdv-regen">Gerar nova</button>
                    </div>
                    <label style="display:block;font-weight:700;margin-top:10px;">Webhook URL do PDV (opcional)</label>
                    <input type="url" id="fg-pdv-webhook" value="${esc(pdv.webhook_url || '')}" placeholder="https://seu-pdv.com.br/webhook/acheiaki" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;margin-top:4px;">
                    <small style="display:block;color:#64748b;margin-top:4px;">Secret HMAC-SHA256: <code>${esc(pdv.webhook_secret || 'gerado ao salvar')}</code> · Último envio: ${esc(pdv.ultimo_webhook_status || 'nunca')}</small>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
                        <button type="button" class="btn-prim" id="fg-pdv-salvar">Salvar Conector PDV</button>
                        <button type="button" class="btn-sec" id="fg-pdv-testar">Testar Webhook</button>
                    </div>
                </section>

                <section class="fg-ter-box">
                    <h4><i class="fa-solid fa-file-invoice-dollar"></i> Emissão Fiscal (NFC-e Modelo 65 / SAT)</h4>
                    <label style="display:block;margin:8px 0;"><input type="checkbox" id="fg-fisc-ativo" ${fiscal.habilitado ? 'checked' : ''}> Habilitar emissão fiscal vinculada aos pedidos</label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <div>
                            <label style="font-weight:700;font-size:0.85rem;">Provedor / Engine</label>
                            <select id="fg-fisc-prov" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;">
                                <option value="local_homologacao" ${fiscal.provedor === 'local_homologacao' ? 'selected' : ''}>Homologação Interna</option>
                                <option value="focusnfe" ${fiscal.provedor === 'focusnfe' ? 'selected' : ''}>Focus NFe</option>
                                <option value="nuvemfiscal" ${fiscal.provedor === 'nuvemfiscal' ? 'selected' : ''}>Nuvem Fiscal</option>
                                <option value="plugnotas" ${fiscal.provedor === 'plugnotas' ? 'selected' : ''}>PlugNotas</option>
                                <option value="sat_local" ${fiscal.provedor === 'sat_local' ? 'selected' : ''}>Bridge SAT Local</option>
                            </select>
                        </div>
                        <div>
                            <label style="font-weight:700;font-size:0.85rem;">Ambiente</label>
                            <select id="fg-fisc-amb" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;">
                                <option value="homologacao" ${fiscal.ambiente === 'homologacao' ? 'selected' : ''}>Homologação</option>
                                <option value="producao" ${fiscal.ambiente === 'producao' ? 'selected' : ''}>Produção SEFAZ</option>
                            </select>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px;">
                        <div><label style="font-weight:700;font-size:0.85rem;">CNPJ Emitente</label><input type="text" id="fg-fisc-cnpj" value="${esc(fiscal.cnpj || '')}" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;"></div>
                        <div><label style="font-weight:700;font-size:0.85rem;">Inscrição Estadual</label><input type="text" id="fg-fisc-ie" value="${esc(fiscal.ie || '')}" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;"></div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-top:8px;">
                        <div><label style="font-weight:700;font-size:0.82rem;">NCM padrão</label><input type="text" id="fg-fisc-ncm" value="${esc(fiscal.ncm_padrao || '21069090')}" style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:8px;"></div>
                        <div><label style="font-weight:700;font-size:0.82rem;">CFOP padrão</label><input type="text" id="fg-fisc-cfop" value="${esc(fiscal.cfop_padrao || '5102')}" style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:8px;"></div>
                        <div>
                            <label style="font-weight:700;font-size:0.82rem;">Automação</label>
                            <select id="fg-fisc-auto" style="width:100%;padding:7px;border:1px solid #cbd5e1;border-radius:8px;">
                                <option value="ao_concluir" ${fiscal.emissao_automatica === 'ao_concluir' ? 'selected' : ''}>Ao concluir</option>
                                <option value="ao_preparar" ${fiscal.emissao_automatica === 'ao_preparar' ? 'selected' : ''}>Ao aceitar</option>
                                <option value="manual" ${fiscal.emissao_automatica === 'manual' ? 'selected' : ''}>Manual</option>
                            </select>
                        </div>
                    </div>
                    <label style="display:block;font-weight:700;margin-top:8px;font-size:0.85rem;">Token do Provedor Fiscal (FocusNFe / PlugNotas)</label>
                    <input type="password" id="fg-fisc-token" placeholder="${fiscal.tem_provedor_token ? 'Token configurado (digite para substituir)' : 'Opcional em homologação'}" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;">
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                        <button type="button" class="btn-prim" id="fg-fisc-salvar">Salvar Config. Fiscal</button>
                        <input type="text" id="fg-fisc-pedidoid" placeholder="Nº pedido para emitir" style="width:150px;padding:7px;border:1px solid #cbd5e1;border-radius:8px;">
                        <button type="button" class="btn-sec" id="fg-fisc-emitir">Emitir NFC-e</button>
                    </div>
                </section>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:16px;margin-top:16px;">
                <section class="fg-ter-box">
                    <h4><i class="fa-solid fa-bell"></i> Campanha Push em Tempo Real</h4>
                    <input type="text" id="fg-push-titulo" value="Cupom relâmpago liberado!" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;margin-bottom:8px;" placeholder="Título do alerta">
                    <textarea id="fg-push-msg" rows="2" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;" placeholder="Ex.: Seu cupom de R$ 15 vence em 2 horas!">Seu cupom de R$ 15 vence em 2 horas. Aproveite agora!</textarea>
                    <button type="button" class="btn-prim" id="fg-push-enviar" style="margin-top:8px;">Disparar Push para Clientes</button>
                </section>

                <section class="fg-ter-box">
                    <h4><i class="fa-solid fa-receipt"></i> Últimas NFC-e / SAT Emitidas (${notas.length})</h4>
                    ${notas.length ? `
                        <div style="max-height:180px;overflow:auto;">
                            ${notas.map((n) => `
                                <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #e5e7eb;font-size:0.85rem;">
                                    <span><strong>#${esc(n.numero)}</strong> · Ped #${esc(n.order_id)} (${esc(n.status)})</span>
                                    <a href="${esc(n.danfe_url || ('fiscal_api.php?action=danfe&order_id=' + encodeURIComponent(n.order_id)))}" target="_blank" rel="noopener" style="color:#c2410c;font-weight:700;">Ver DANFE</a>
                                </div>
                            `).join('')}
                        </div>
                    ` : '<p class="texto-ajuda">Nenhuma nota emitida ainda nesta loja.</p>'}
                </section>
            </div>
        `;

        document.getElementById('fg-int-drenar')?.addEventListener('click', async () => {
            const r = await apiGestao('worker_drenar');
            toast(r && r.success ? `Fila processada: ${(r.resultado && r.resultado.processados) || 0} job(s).` : 'Erro ao processar fila.');
            carregarIntegracoes();
        });

        document.getElementById('fg-pdv-salvar')?.addEventListener('click', async () => {
            const r = await apiGestao('pdv_config_salvar', {
                habilitado: document.getElementById('fg-pdv-ativo')?.checked,
                driver: document.getElementById('fg-pdv-driver')?.value,
                webhook_url: document.getElementById('fg-pdv-webhook')?.value
            });
            toast(r && r.success ? 'Conector PDV salvo!' : ((r && r.message) || 'Erro ao salvar PDV.'), r && r.success ? 'ok' : 'erro');
            carregarIntegracoes();
        });

        document.getElementById('fg-pdv-regen')?.addEventListener('click', async () => {
            if (!confirm('Gerar nova API Key substituirá a atual nos seus sistemas PDV. Continuar?')) return;
            await apiGestao('pdv_config_salvar', { gerar_nova_chave: true });
            toast('Nova API Key gerada!');
            carregarIntegracoes();
        });

        document.getElementById('fg-pdv-testar')?.addEventListener('click', async () => {
            const r = await apiGestao('pdv_testar_webhook');
            toast(r && r.success ? 'Webhook entregue com sucesso!' : ((r && r.message) || 'Falha ao enviar webhook.'), r && r.success ? 'ok' : 'erro');
            carregarIntegracoes();
        });

        document.getElementById('fg-fisc-salvar')?.addEventListener('click', async () => {
            const r = await apiGestao('fiscal_config_salvar', {
                habilitado: document.getElementById('fg-fisc-ativo')?.checked,
                provedor: document.getElementById('fg-fisc-prov')?.value,
                ambiente: document.getElementById('fg-fisc-amb')?.value,
                cnpj: document.getElementById('fg-fisc-cnpj')?.value,
                ie: document.getElementById('fg-fisc-ie')?.value,
                ncm_padrao: document.getElementById('fg-fisc-ncm')?.value,
                cfop_padrao: document.getElementById('fg-fisc-cfop')?.value,
                emissao_automatica: document.getElementById('fg-fisc-auto')?.value,
                provedor_token: document.getElementById('fg-fisc-token')?.value
            });
            toast(r && r.success ? 'Configuração fiscal salva!' : 'Erro ao salvar configuração fiscal.', r && r.success ? 'ok' : 'erro');
            carregarIntegracoes();
        });

        document.getElementById('fg-fisc-emitir')?.addEventListener('click', async () => {
            const pid = (document.getElementById('fg-fisc-pedidoid')?.value || '').trim();
            if (!pid) { toast('Informe o número do pedido.', 'aviso'); return; }
            const r = await apiGestao('fiscal_emitir_pedido', { order_id: pid, forcar: true });
            toast(r && r.success ? `NFC-e #${r.nota && r.nota.numero} emitida!` : ((r && r.message) || 'Erro na emissão.'), r && r.success ? 'ok' : 'erro');
            carregarIntegracoes();
        });

        document.getElementById('fg-push-enviar')?.addEventListener('click', async () => {
            const titulo = (document.getElementById('fg-push-titulo')?.value || '').trim();
            const mensagem = (document.getElementById('fg-push-msg')?.value || '').trim();
            if (!mensagem) { toast('Escreva a mensagem do push.', 'aviso'); return; }
            const r = await apiGestao('push_campanha_enviar', { titulo, mensagem });
            toast(r && r.success ? 'Campanha Push disparada em tempo real!' : 'Erro ao disparar push.', r && r.success ? 'ok' : 'erro');
            carregarIntegracoes();
        });
    }

    // =====================================================================
    // BOOTSTRAP E INTEGRAÇÃO COM AS ABAS DO ADMIN.HTML
    // =====================================================================
    function aoAtivarAba(aba) {
        if (aba === 'visao-geral') carregarVisaoGeral();
        else if (aba === 'territorio') carregarTerritorio();
        else if (aba === 'estoque') carregarEstoque();
        else if (aba === 'financeiro') carregarFinanceiro();
        else if (aba === 'clientes') carregarClientes();
        else if (aba === 'entregadores') carregarEntregadores();
        else if (aba === 'equipe') carregarEquipe();
        else if (aba === 'integracoes') carregarIntegracoes();
        else if (aba === 'seguranca') carregarIaPessoalSeguranca();
    }

    function boot() {
        vincularIaPessoalSeguranca();

        document.querySelectorAll('#admin-tabs button[data-tab]').forEach((btn) => {
            btn.addEventListener('click', () => {
                aoAtivarAba(btn.getAttribute('data-tab'));
            });
        });

        global.addEventListener('pz:agente-mutacao', () => {
            const ativo = document.querySelector('#admin-tabs button.ativo');
            if (ativo) aoAtivarAba(ativo.getAttribute('data-tab'));
        });

        const esperarLogin = () => {
            const auth = credenciais();
            if (auth.user && auth.pass && global.__pzAdmin) {
                carregarVisaoGeral();
                carregarIaPessoalSeguranca();
                verificarLocalizacaoObrigatoriaVendedor();
                return;
            }
            setTimeout(esperarLogin, 350);
        };
        esperarLogin();
    }

    global.FoodGestaoAdmin = {
        carregarVisaoGeral,
        carregarTerritorio,
        carregarEstoque,
        carregarFinanceiro,
        carregarClientes,
        carregarEntregadores,
        carregarEquipe,
        carregarIntegracoes,
        carregarIaPessoalSeguranca,
        aoAtivarAba
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})(typeof window !== 'undefined' ? window : globalThis);

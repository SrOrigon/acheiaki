/**
 * Perfil qualificado: CNPJ + Google Maps + sugestões para a loja.
 */
(function (global) {
    'use strict';

    function apiUrl(path) {
        if (typeof TENANT !== 'undefined' && TENANT.apiUrl) return TENANT.apiUrl(path);
        return path;
    }

    async function chamarApi(acao, corpo) {
        try {
            const res = await fetch(apiUrl('api_perfil_cliente.php'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(Object.assign({ acao: acao }, corpo || {}))
            });
            const data = await res.json();
            if (data && typeof data === 'object') return data;
            return { success: false, message: 'Resposta inválida do servidor.' };
        } catch (e) {
            return { success: false, message: 'Sem conexão com o servidor. Verifique PHP e internet.' };
        }
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    const PerfilUI = {
        contexto: 'cadastro',
        perfilAtual: null,
        integracoes: null,
        onPerfil: null,

        init(opts) {
            this.contexto = opts.contexto || 'cadastro';
            this.onPerfil = opts.onPerfil || null;
            const root = document.getElementById(opts.rootId || 'pz-perfil-root');
            if (!root) return;
            this.integracoes = { local_offline: true };

            root.innerHTML = ''
                + '<h3><i class="fa-solid fa-id-card"></i> Perfil qualificado da empresa (100% local)</h3>'
                + '<p class="pz-perfil-sub">Informe o nome do comércio ou CNPJ (opcional). Analisamos tudo localmente no próprio sistema, sem depender de APIs externas.</p>'
                + '<div class="pz-perfil-grid">'
                + '<input type="text" id="pz-perfil-cnpj" inputmode="numeric" placeholder="CNPJ (opcional) — só números">'
                + '<input type="text" id="pz-perfil-busca" placeholder="Nome do comércio + tipo de comida (ex.: Pensão da Maria)">'
                + '</div>'
                + '<div class="pz-perfil-acoes">'
                + '<button type="button" id="pz-btn-cnpj">Validar CNPJ</button>'
                + '<button type="button" id="pz-btn-maps">Analisar comércio</button>'
                + '<button type="button" class="sec" id="pz-btn-unir">Gerar perfil completo</button>'
                + '</div>'
                + '<div id="pz-perfil-aviso"></div>'
                + '<div class="pz-perfil-resultados" id="pz-perfil-resultados"></div>'
                + '<div class="pz-perfil-melhorias" id="pz-perfil-melhorias"></div>'
                + '<div id="pz-perfil-resumo"></div>';

            document.getElementById('pz-perfil-aviso').innerHTML =
                '<div class="pz-perfil-aviso">Modo independente ativo: validação de CNPJ, detecção de segmento e sugestões de melhorias funcionam diretamente no código sem APIs externas.</div>';

            document.getElementById('pz-btn-cnpj').addEventListener('click', () => this.validarCnpj());
            document.getElementById('pz-btn-maps').addEventListener('click', () => this.buscarMaps());
            document.getElementById('pz-btn-unir').addEventListener('click', () => this.perfilCompleto());

            if (this.contexto === 'admin') {
                const extra = document.createElement('div');
                extra.className = 'pz-perfil-acoes';
                extra.innerHTML = '<button type="button" id="pz-btn-aplicar">Aplicar melhorias na loja</button>';
                root.appendChild(extra);
                document.getElementById('pz-btn-aplicar').addEventListener('click', () => this.aplicarNoAdmin());
            }
        },

        notificar(perfil) {
            this.perfilAtual = perfil;
            if (typeof this.onPerfil === 'function') this.onPerfil(perfil);
            global.__pzPerfilCadastro = perfil;
        },

        preencherFormularioCadastro(perfil) {
            if (!perfil) return;
            const nome = document.getElementById('nome');
            const nicho = document.getElementById('nicho');
            const zap = document.getElementById('whatsapp');
            if (nome && perfil.nome_exibicao) nome.value = perfil.nome_exibicao;
            if (nicho && perfil.nicho_sugerido) nicho.value = perfil.nicho_sugerido;
            if (zap && perfil.telefone) zap.value = perfil.telefone.replace(/\D/g, '').replace(/^55/, '');
        },

        renderMelhorias(perfil) {
            const box = document.getElementById('pz-perfil-melhorias');
            const resumo = document.getElementById('pz-perfil-resumo');
            if (!box || !perfil) return;
            const ms = perfil.melhorias || [];
            box.innerHTML = '<h4 style="margin:12px 0 8px;">Melhorias sugeridas para sua loja</h4>'
                + ms.map((m) => '<div class="pz-melhoria ' + esc(m.prioridade || '') + '"><div><strong>' + esc(m.titulo) + '</strong><br><small>' + esc(m.descricao) + '</small></div></div>').join('');
            resumo.innerHTML = '<div class="pz-perfil-resumo"><strong>Segmento:</strong> ' + esc(perfil.nicho_rotulo || perfil.nicho_sugerido)
                + (perfil.cnpj ? '<br><strong>CNPJ:</strong> ' + esc(perfil.cnpj) : '')
                + (perfil.razao_social ? '<br><strong>Razão social:</strong> ' + esc(perfil.razao_social) : '')
                + '</div>';
        },

        montarPerfilLocal(nomeBruto, cnpjBruto) {
            const core = global.PedidoZapAssistenteCore || {};
            const nichos = core.NICHOS_INFO || {
                lanchonete: { rotulo: 'Lanchonete / hamburgueria', palavras: ['lanche', 'burger', 'hamburguer'] },
                marmitaria: { rotulo: 'Marmitaria / quentinha', palavras: ['marmita', 'quentinha'] },
                pensao: { rotulo: 'Pensão / comida caseira', palavras: ['pensao', 'caseira', 'restaurante', 'almoco'] },
                pizzaria: { rotulo: 'Pizzaria', palavras: ['pizza', 'pizzaria'] },
                acai: { rotulo: 'Açaiteria / sorveteria', palavras: ['acai', 'sorvete'] },
                confeitaria: { rotulo: 'Doceria / confeitaria', palavras: ['doce', 'bolo', 'confeitaria'] }
            };
            const norm = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            const texto = norm(nomeBruto);
            let nicho = 'lanchonete';
            for (const [id, info] of Object.entries(nichos)) {
                if ((info.palavras || []).some((p) => texto.includes(norm(p)))) {
                    nicho = id;
                    break;
                }
            }
            const cnpjNum = String(cnpjBruto || '').replace(/\D/g, '');
            const cnpjValido = cnpjNum.length === 14 && (typeof core.validarCnpjLocal === 'function' ? core.validarCnpjLocal(cnpjNum) : true);
            const nomeLimpo = String(nomeBruto || '').trim();
            const melhorias = [
                {
                    id: 'nicho_segmento',
                    prioridade: 'alta',
                    titulo: 'Segmento ideal: ' + ((nichos[nicho] && nichos[nicho].rotulo) || nicho),
                    descricao: 'Categorias e textos do cardápio preparados para o seu tipo de negócio.',
                    auto_aplicavel: true
                },
                {
                    id: 'cardapio_inicial',
                    prioridade: 'alta',
                    titulo: 'Cardápio inicial pronto para editar',
                    descricao: 'Use a Assistente IA e diga "montar cardápio inicial" para preencher pratos e preços base.',
                    auto_aplicavel: false
                },
                {
                    id: 'pix_entrega',
                    prioridade: 'media',
                    titulo: 'Ative PIX e taxa de entrega clara',
                    descricao: 'Lojas com chave PIX visível e entrega bem definida convertem mais pedidos no WhatsApp.',
                    auto_aplicavel: false
                }
            ];
            if (nomeLimpo) {
                melhorias.unshift({
                    id: 'nome_loja',
                    prioridade: 'alta',
                    titulo: 'Nome da loja: ' + nomeLimpo,
                    descricao: 'Aplicar "' + nomeLimpo + '" como nome exibido no cardápio.',
                    auto_aplicavel: true
                });
            }
            return {
                nome_exibicao: nomeLimpo,
                nicho_sugerido: nicho,
                nicho_rotulo: (nichos[nicho] && nichos[nicho].rotulo) || nicho,
                cnpj: cnpjValido ? cnpjNum : '',
                razao_social: '',
                melhorias: melhorias,
                fontes: ['analise_local']
            };
        },

        async validarCnpj() {
            const cnpj = (document.getElementById('pz-perfil-cnpj').value || '').replace(/\D/g, '');
            const nome = (document.getElementById('pz-perfil-busca').value || '').trim();
            const core = global.PedidoZapAssistenteCore || {};
            if (cnpj.length !== 14 || (typeof core.validarCnpjLocal === 'function' && !core.validarCnpjLocal(cnpj))) {
                alert('CNPJ inválido. Confira os 14 dígitos.');
                return;
            }
            const perfil = this.montarPerfilLocal(nome, cnpj);
            this.notificar(perfil);
            this.preencherFormularioCadastro(perfil);
            document.getElementById('pz-perfil-resultados').innerHTML = '';
            this.renderMelhorias(perfil);
        },

        async buscarMaps() {
            const q = (document.getElementById('pz-perfil-busca').value || '').trim();
            const cnpj = (document.getElementById('pz-perfil-cnpj').value || '').replace(/\D/g, '');
            if (q.length < 2) {
                alert('Digite o nome do comércio e o tipo de comida para analisar.');
                return;
            }
            const perfil = this.montarPerfilLocal(q, cnpj);
            this.notificar(perfil);
            this.preencherFormularioCadastro(perfil);
            document.getElementById('pz-perfil-resultados').innerHTML = '';
            this.renderMelhorias(perfil);
        },

        async detalharPlace() {},

        async perfilCompleto() {
            const cnpj = (document.getElementById('pz-perfil-cnpj').value || '').replace(/\D/g, '');
            const nome = (document.getElementById('pz-perfil-busca').value || '').trim();
            if (!nome && cnpj.length !== 14) {
                alert('Informe o nome do comércio ou um CNPJ válido.');
                return;
            }
            const perfil = this.montarPerfilLocal(nome, cnpj);
            this.notificar(perfil);
            this.preencherFormularioCadastro(perfil);
            this.renderMelhorias(perfil);
        },

        async aplicarNoAdmin() {
            if (!this.perfilAtual) {
                alert('Analise o comércio ou valide o CNPJ antes.');
                return;
            }
            const adm = global.__pzAdmin;
            if (!adm || !adm.config) {
                alert('Aguarde o painel carregar.');
                return;
            }
            const p = this.perfilAtual;
            if (p.nome_exibicao) {
                adm.config.NOME_LOJA = p.nome_exibicao;
                const inpNome = document.getElementById('input-nome-loja');
                if (inpNome) inpNome.value = p.nome_exibicao;
            }
            if (p.nicho_sugerido) {
                adm.config.NICHO = p.nicho_sugerido;
                const selNicho = document.getElementById('input-nicho');
                if (selNicho) selNicho.value = p.nicho_sugerido;
                const btnNicho = document.getElementById('btn-aplicar-nicho');
                if (btnNicho) btnNicho.click();
            }
            adm.config.PERFIL_QUALIFICADO = p;
            if (typeof adm.salvar === 'function') adm.salvar();
            if (adm.toast) adm.toast('Perfil aplicado! Clique em Salvar no topo para publicar.', 'success');
        }
    };

    function boot() {
        const cadastroRoot = document.getElementById('pz-perfil-root');
        if (cadastroRoot) {
            PerfilUI.init({
                contexto: 'cadastro',
                rootId: 'pz-perfil-root',
                onPerfil(perfil) {
                    PerfilUI.preencherFormularioCadastro(perfil);
                }
            });
        }
        const adminRoot = document.getElementById('pz-perfil-admin');
        if (adminRoot) {
            PerfilUI.init({
                contexto: 'admin',
                rootId: 'pz-perfil-admin'
            });
            const syncPerfilSalvo = () => {
                if (!global.__pzAdmin || !global.__pzAdmin.config) return;
                const salvo = global.__pzAdmin.config.PERFIL_QUALIFICADO;
                if (salvo && typeof salvo === 'object') {
                    PerfilUI.perfilAtual = salvo;
                    global.__pzPerfilCadastro = salvo;
                    PerfilUI.renderMelhorias(salvo);
                }
            };
            if (global.__pzAdmin) syncPerfilSalvo();
            else {
                const t = setInterval(() => {
                    if (global.__pzAdmin) {
                        clearInterval(t);
                        syncPerfilSalvo();
                    }
                }, 400);
                setTimeout(() => clearInterval(t), 15000);
            }
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();

    global.PedidoZapPerfil = PerfilUI;
    global.PedidoZapPerfilApi = { chamarApi: chamarApi };
})(typeof window !== 'undefined' ? window : globalThis);

(function () {
    if (sessionStorage.getItem('admin_master') !== '1' || !sessionStorage.getItem('admin_pass')) {
        window.location.href = 'index.html';
        return;
    }

    const auth = {
        user: sessionStorage.getItem('admin_user') || '',
        pass: sessionStorage.getItem('admin_pass') || ''
    };
    let lojas = [];
    let nichos = {};

    function api(body) {
        return fetch('saas_admin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ auth: auth }, body))
        }).then((res) => res.json());
    }

    function escapar(texto) {
        return String(texto || '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    function desenhar() {
        const filtro = document.getElementById('filtro-status').value;
        const linhas = lojas.filter((loja) => !filtro || loja.status === filtro || loja.status_efetivo === filtro);
        document.getElementById('tabela-lojas').innerHTML = linhas.map((loja) => {
            return '<tr>'
                + '<td><strong>' + escapar(loja.nome_empresa) + '</strong><br><code>' + escapar(loja.slug) + '</code><br>'
                + escapar(loja.nicho || '') + (loja.login ? '<br>Login: ' + escapar(loja.login) : '') + '</td>'
                + '<td>' + escapar(loja.plano || 'basico') + '<br>' + escapar(loja.vencimento || 'sem data') + '</td>'
                + '<td><span class="pill ' + escapar(loja.status_efetivo || loja.status) + '">'
                + escapar(loja.motivo === 'vencido' ? 'venceu' : (loja.status || 'ativo')) + '</span>'
                + (loja.em_teste ? '<br>teste' : '')
                + (loja.assinatura_status === 'authorized' ? '<br>💳 assinatura ativa' : (loja.assinatura_status ? '<br>💳 ' + escapar(loja.assinatura_status) : ''))
                + (loja.ultimo_pagamento ? '<br>pago em ' + escapar(loja.ultimo_pagamento) : '') + '</td>'
                + '<td class="acoes">'
                + '<button type="button" data-acao="entrar" data-slug="' + escapar(loja.slug) + '">Acessar painel</button>'
                + '<button type="button" class="sec" data-acao="destaque" data-slug="' + escapar(loja.slug) + '">'
                + (loja.destaque ? '★ Destacada' : '☆ Destacar Mkt') + '</button>'
                + '<button type="button" class="sec" data-acao="senha" data-slug="' + escapar(loja.slug) + '">Nova senha</button>'
                + '<button type="button" class="' + (loja.status_efetivo === 'bloqueado' ? 'sec' : 'perigo') + '" data-acao="bloquear" data-slug="' + escapar(loja.slug) + '">'
                + (loja.status_efetivo === 'bloqueado' ? 'Reativar por 30 dias' : 'Bloquear') + '</button>'
                + '</td></tr>';
        }).join('') || '<tr><td colspan="4">Nenhuma loja neste filtro.</td></tr>';
    }

    function carregar() {
        return api({ acao: 'listar' }).then((data) => {
            if (!data.success) {
                alert(data.message || 'Não foi possível carregar as lojas.');
                return;
            }
            lojas = data.lojas || [];
            nichos = data.nichos || {};
            const select = document.getElementById('novo-nicho');
            select.innerHTML = Object.keys(nichos).map((chave) => {
                return '<option value="' + chave + '">' + escapar(nichos[chave].rotulo || chave) + '</option>';
            }).join('');
            const saas = data.saas || {};
            document.getElementById('saas-nome').value = saas.nome_plataforma || '';
            document.getElementById('saas-zap').value = saas.suporte_whatsapp || '';
            const campoDominio = document.getElementById('saas-dominio');
            if (campoDominio) campoDominio.value = saas.dominio_base || '';
            const campoGsc = document.getElementById('saas-gsc-token');
            if (campoGsc) campoGsc.value = saas.google_site_verification || '';
            const baseAtual = window.location.origin;
            const elSitemap = document.getElementById('aki-seo-sitemap-url');
            if (elSitemap) elSitemap.textContent = baseAtual + '/sitemap.xml';
            const elRobots = document.getElementById('aki-seo-robots-url');
            if (elRobots) elRobots.textContent = baseAtual + '/robots.txt';
            const campoDpo = document.getElementById('saas-dpo-email');
            if (campoDpo) campoDpo.value = saas.dpo_email || '';
            const campoCnpj = document.getElementById('saas-cnpj');
            if (campoCnpj) campoCnpj.value = saas.cnpj_plataforma || '';
            mostrarCobranca(saas);
            mostrarIntegracoes(data.integracoes || {});
            mostrarBanco(data.db_config || {});
            desenhar();
        });
    }

    function mostrarBanco(db) {
        const st = document.getElementById('db-status');
        if (!st) return;
        const set = (id, v) => { const el = document.getElementById(id); if (el && v !== undefined) el.value = v; };
        set('db-driver', db.driver || 'mysql');
        set('db-host', db.host || 'localhost');
        set('db-port', db.port || (db.driver === 'pgsql' ? '5432' : '3306'));
        set('db-name', db.database || '');
        set('db-user', db.username || '');
        const chk = document.getElementById('db-enabled');
        if (chk) chk.checked = Boolean(db.enabled);
        const inpPass = document.getElementById('db-pass');
        if (inpPass) {
            inpPass.value = '';
            inpPass.placeholder = db.password_configurada ? 'Senha salva. Deixe em branco para manter.' : 'Senha do banco';
        }
        if (db.conectado) {
            st.textContent = 'Conectado com sucesso' + (db.env_managed ? ' (via variáveis do Railway)' : '') + ' (' + (db.driver === 'pgsql' ? 'PostgreSQL' : 'MySQL/MariaDB') + ': ' + db.database + ').';
            st.style.color = '#166534';
        } else if (db.enabled) {
            st.textContent = 'Falha ao conectar: ' + (db.erro_conexao || 'verifique host, banco, usuário e senha.') + ' (fallback JSON em uso).';
            st.style.color = '#b91c1c';
        } else {
            st.textContent = 'Modo atual: JSON atômico em disco (conecte MySQL ou PostgreSQL no Railway para persistência em nuvem).';
            st.style.color = '#374151';
        }
    }

    function mostrarIntegracoes(integracoes) {
        const st = document.getElementById('int-status');
        if (!st) return;
        const okMaps = integracoes.google_maps_configurado && integracoes.google_maps_habilitado;
        const provIa = integracoes.ia_provider || 'local';
        const okGemini = !!integracoes.gemini_configurado;
        st.textContent = 'CNPJ: ' + (integracoes.cnpj_habilitado ? 'ativo' : 'off')
            + ' · Google Maps: ' + (okMaps ? 'configurado' : 'pendente')
            + ' · Agente IA: ' + (provIa === 'gemini' ? (okGemini ? 'Gemini híbrido ativo' : 'Gemini selecionado (falta chave)') : '100% local');
        const campo = document.getElementById('int-google-key');
        if (campo) campo.placeholder = okMaps ? 'Chave salva. Digite outra para trocar.' : 'Cole a chave aqui';
        const selIa = document.getElementById('int-ia-provider');
        if (selIa) selIa.value = provIa;
        const campoIa = document.getElementById('int-ia-key');
        if (campoIa) campoIa.placeholder = okGemini ? 'Chave Gemini salva. Deixe em branco para manter.' : 'Opcional (AIza...)';
    }

    function mostrarCobranca(saas) {
        const valor = document.getElementById('saas-mensalidade');
        if (!valor) return;
        valor.value = saas.mensalidade_valor ? String(saas.mensalidade_valor).replace('.', ',') : '';
        document.getElementById('saas-mp-token').value = '';
        document.getElementById('saas-mp-token').placeholder = saas.mp_configurado
            ? 'Token salvo (termina em ' + saas.mp_token_final + '). Deixe vazio para manter.'
            : 'APP_USR-...';
        document.getElementById('saas-webhook').textContent = saas.webhook_url || '';
        let texto = saas.mp_configurado
            ? 'Mercado Pago conectado' + (saas.mp_modo_teste ? ' (MODO TESTE: cobranças não são reais)' : '') + '.'
            : 'Mercado Pago ainda não conectado: os lojistas não verão o botão de pagamento.';
        if (saas.mp_configurado && !saas.mensalidade_valor) texto += ' Defina o valor da mensalidade.';
        document.getElementById('saas-mp-status').textContent = texto;
    }

    document.getElementById('filtro-status').addEventListener('change', desenhar);
    document.getElementById('btn-sair').addEventListener('click', () => {
        sessionStorage.removeItem('admin_logado');
        sessionStorage.removeItem('admin_master');
        sessionStorage.removeItem('admin_user');
        sessionStorage.removeItem('admin_pass');
        window.location.href = 'index.html';
    });

    document.getElementById('btn-criar').addEventListener('click', () => {
        api({
            acao: 'criar',
            nome_empresa: document.getElementById('novo-nome').value.trim(),
            razao_social: document.getElementById('novo-razao').value.trim(),
            slug: document.getElementById('novo-slug').value.trim().toLowerCase(),
            nicho: document.getElementById('novo-nicho').value,
            login: document.getElementById('novo-login').value.trim(),
            senha: document.getElementById('novo-senha').value,
            plano: document.getElementById('novo-plano').value,
            vencimento: document.getElementById('novo-vencimento').value
        }).then((data) => {
            if (!data.success) {
                alert(data.message || 'Não foi possível criar a loja.');
                return;
            }
            lojas = data.lojas || lojas;
            document.getElementById('novo-senha').value = '';
            desenhar();
            alert('Loja criada. O endereço fica em /loja/' + data.slug + '/');
        });
    });

    document.getElementById('master-user').value = auth.user;
    document.getElementById('btn-master').addEventListener('click', () => {
        const novoUser = document.getElementById('master-user').value.trim();
        const novaSenha = document.getElementById('master-senha').value;
        if (!novoUser || !novaSenha) {
            alert('Informe o usuário e a nova senha do painel master.');
            return;
        }
        api({ acao: 'trocar_master', novo_user: novoUser, nova_senha: novaSenha }).then((data) => {
            if (!data.success) {
                alert('Não foi possível atualizar o acesso master.');
                return;
            }
            auth.user = novoUser;
            auth.pass = novaSenha;
            fetch('login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ user: novoUser, pass: novaSenha })
            }).then((r) => r.json()).then((login) => {
                if (login && login.token) auth.pass = login.token;
                sessionStorage.setItem('admin_pass', auth.pass);
            }).catch(() => sessionStorage.setItem('admin_pass', auth.pass));
            sessionStorage.setItem('admin_user', novoUser);
            document.getElementById('master-senha').value = '';
            alert('Acesso master atualizado. A senha anterior deixa de funcionar.');
        });
    });

    document.getElementById('btn-integracoes').addEventListener('click', () => {
        const key = (document.getElementById('int-google-key').value || '').trim();
        const iaProv = (document.getElementById('int-ia-provider') || {}).value || 'local';
        const iaKey = ((document.getElementById('int-ia-key') || {}).value || '').trim();
        const body = { acao: 'salvar_integracoes', integracoes: { cnpj_habilitado: true, ia_provider: iaProv } };
        if (key) body.integracoes.google_maps_api_key = key;
        if (iaKey) body.integracoes.ia_api_key = iaKey;
        api(body).then((data) => {
            if (data.integracoes) mostrarIntegracoes(data.integracoes);
            document.getElementById('int-google-key').value = '';
            const campoIa = document.getElementById('int-ia-key');
            if (campoIa) campoIa.value = '';
            alert(data.success ? 'Integrações salvas.' : 'Não foi possível salvar.');
        });
    });

    document.getElementById('btn-saas').addEventListener('click', () => {
        api({
            acao: 'salvar_saas',
            saas: {
                nome_plataforma: document.getElementById('saas-nome').value.trim(),
                suporte_whatsapp: document.getElementById('saas-zap').value.trim(),
                dominio_base: (document.getElementById('saas-dominio') || {}).value || '',
                google_site_verification: ((document.getElementById('saas-gsc-token') || {}).value || '').trim(),
                dpo_email: ((document.getElementById('saas-dpo-email') || {}).value || '').trim(),
                cnpj_plataforma: ((document.getElementById('saas-cnpj') || {}).value || '').trim(),
                mensalidade_valor: (document.getElementById('saas-mensalidade') || {}).value || '',
                mp_access_token: ((document.getElementById('saas-mp-token') || {}).value || '').trim()
            }
        }).then((data) => {
            if (data.saas) mostrarCobranca(data.saas);
            alert(data.success ? 'Configurações salvas.' : 'Falha ao salvar.');
        });
    });

    document.getElementById('tabela-lojas').addEventListener('click', (evento) => {
        const botao = evento.target.closest('button');
        if (!botao) return;
        const slug = botao.dataset.slug;
        const loja = lojas.find((item) => item.slug === slug);
        if (!loja) return;
        if (botao.dataset.acao === 'entrar') {
            sessionStorage.setItem('pedidozap_tenant_slug', slug);
            window.location.href = 'admin.html?tenant=' + encodeURIComponent(slug);
            return;
        }
        if (botao.dataset.acao === 'destaque') {
            api({ acao: 'atualizar', slug: slug, destaque: !loja.destaque }).then((data) => {
                if (data.success) {
                    lojas = data.lojas || lojas;
                    desenhar();
                }
            });
            return;
        }
        if (botao.dataset.acao === 'senha') {
            const senha = prompt('Nova senha do lojista ' + (loja.login || ''));
            if (!senha) return;
            api({ acao: 'redefinir_senha', slug: slug, login: loja.login, senha: senha }).then((data) => {
                alert(data.success ? 'Senha redefinida. A senha anterior não fica visível.' : (data.message || 'Falha ao redefinir.'));
            });
            return;
        }
        if (botao.dataset.acao === 'bloquear') {
            const status = loja.status === 'bloqueado' || loja.status_efetivo === 'bloqueado' ? 'ativo' : 'bloqueado';
            const extra = {};
            if (status === 'ativo') {
                const hoje = new Date();
                hoje.setDate(hoje.getDate() + 30);
                const mes = String(hoje.getMonth() + 1).padStart(2, '0');
                const dia = String(hoje.getDate()).padStart(2, '0');
                extra.vencimento = hoje.getFullYear() + '-' + mes + '-' + dia;
            }
            api(Object.assign({ acao: 'atualizar', slug: slug, status: status }, extra)).then((data) => {
                if (data.success) {
                    lojas = data.lojas || lojas;
                    desenhar();
                }
            });
        }
    });

    const btnDbSalvar = document.getElementById('btn-db-salvar');
    if (btnDbSalvar) {
        btnDbSalvar.addEventListener('click', () => {
            api({
                acao: 'salvar_db',
                migrar: true,
                db: {
                    driver: (document.getElementById('db-driver') || {}).value || 'mysql',
                    host: (document.getElementById('db-host') || {}).value || 'localhost',
                    port: (document.getElementById('db-port') || {}).value || '3306',
                    database: (document.getElementById('db-name') || {}).value || '',
                    username: (document.getElementById('db-user') || {}).value || '',
                    password: (document.getElementById('db-pass') || {}).value || '',
                    enabled: Boolean((document.getElementById('db-enabled') || {}).checked)
                }
            }).then((data) => {
                if (data.db_config) mostrarBanco(data.db_config);
                alert(data.message || (data.success ? 'Banco atualizado.' : 'Erro ao salvar banco.'));
            });
        });
    }

    const btnDbMigrar = document.getElementById('btn-db-migrar');
    if (btnDbMigrar) {
        btnDbMigrar.addEventListener('click', () => {
            api({ acao: 'migrar_sql' }).then((data) => {
                alert(data.message || (data.success ? 'Migração concluída.' : 'Erro na migração.'));
            });
        });
    }

    function formatarBytes(b) {
        const n = Number(b) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1).replace('.', ',') + ' KB';
        return (n / (1024 * 1024)).toFixed(2).replace('.', ',') + ' MB';
    }

    function carregarUsoBanco() {
        const resumo = document.getElementById('uso-banco-resumo');
        const corpo = document.getElementById('uso-banco-tabela');
        if (!resumo || !corpo) return;
        resumo.textContent = 'Carregando...';
        api({ acao: 'uso_banco' }).then((data) => {
            if (!data.success) {
                resumo.textContent = data.message || 'Não foi possível ler o uso do banco.';
                corpo.innerHTML = '';
                return;
            }
            const sistema = Number(data.bytes_banco_total || 0) - Number(data.bytes_dados_sistema || 0);
            resumo.innerHTML = 'Dados de clientes e lojas: <strong>' + formatarBytes(data.bytes_dados_sistema)
                + '</strong> · Arquivos internos do próprio PostgreSQL (catálogo/WAL): <strong>' + formatarBytes(Math.max(0, sistema)) + '</strong>';
            corpo.innerHTML = (data.tabelas || []).map((t) => '<tr><td>' + escapar(t.rotulo) + '</td><td>'
                + Number(t.linhas || 0).toLocaleString('pt-BR') + '</td><td>' + formatarBytes(t.bytes) + '</td></tr>').join('');
        }).catch(() => {
            resumo.textContent = 'Sem conexão com o servidor.';
        });
    }

    const btnUsoBanco = document.getElementById('btn-uso-banco');
    if (btnUsoBanco) btnUsoBanco.addEventListener('click', carregarUsoBanco);

    carregar();
    carregarUsoBanco();
})();

# AcheIAki — O Marketplace Inteligente da sua Região & Gestão Food IA

Este documento resume a evolução aplicada ao sistema multi-tenant **AcheIAki** (*Ache + IA + ki* — o marketplace inteligente da sua região com algoritmo de preço e território e **Agente IA Integrado** no centro da operação).

## 1. Prioridade Máxima: Agente IA Integrado (`agente_api.php`)

- **Central Executiva no Admin (`Visão Geral & IA`)**: Primeira aba de [admin.html](admin.html), combinando indicadores do dia/mês, alertas proativos automáticos (caixa fechado, insumos no mínimo, contas vencendo em 7 dias, produtos com margem baixa) e chat direto conectado aos dados reais da loja.
- **Execução Real de Ferramentas & Fonte dos Números**: Cada resposta financeira, operacional ou de estoque cita explicitamente os arquivos consultados (`origem_dados`) e executa ações reais no servidor com atualização automática da interface (`pz:agente-mutacao`):
  - Caixa: `"Abrir caixa com 100 reais"`, `"Sangria de 50 reais gelo"`, `"Suprimento de 80 reais"`
  - Financeiro: `"Lançar despesa Gás 110 reais"`, `"Quanto vendi hoje / na semana / no mês?"`, `"Quais contas vencem esta semana?"`
  - Estoque & CMV: `"O que preciso comprar de estoque?"`, `"Entrada de estoque 10 Carne"`, `"Qual meu produto mais lucrativo?"`
  - Operação & Marketing: `"Quais pedidos estão em aberto?"`, `"Mover pedido #123 para preparo"`, `"Despachar pedido #123 com Carlos"`, `"Criar cupom VOLTA10 10%"`
- **Copiloto de Turno em `painel.html`**: O mesmo widget inteligente acompanha operadores no Kanban de pedidos para consultas e movimentações rápidas.
- **Atendente Virtual na Vitrine (`js/vitrine-agente.js` + `index.html`)**: Responde dúvidas reais dos clientes sobre cardápio, preços, entregas, formas de pagamento, horários e cupons ativos (configurável em `Trocar senha & IA`).
- **Modo Híbrido Opcional**: Funciona 100% local por padrão; o SuperAdmin pode ativar motor híbrido Gemini em [superadmin.html](superadmin.html) mantendo a chave restrita ao servidor.

## 2. Módulos de Gestão Food Implementados (Multi-Tenant via `Database.php`)

| Módulo | Backend | Interface / Fluxo | Arquivos por Loja (`Database`) |
| --- | --- | --- | --- |
| Segurança, RBAC & Auditoria | [saas_lib.php](saas_lib.php), [login.php](login.php), [gestao_lib.php](gestao_lib.php) | Sem senhas padrão expostas (`Admin/Admin` removido); perfis Administrador, Gerente, Atendente, Cozinha e Entregador | `funcionarios.json`, `auditoria.json` |
| Módulo 1 — Estoque & Fornecedores | [gestao_lib.php](gestao_lib.php), [gestao_api.php](gestao_api.php) | Cadastro de insumos, fornecedores, compras/entradas, perdas e histórico | `estoque.json`, `movimentacoes_estoque.json` |
| Módulo 2 — Ficha Técnica & CMV | [gestao_lib.php](gestao_lib.php), [update_order_status.php](update_order_status.php) | Receitas por prato com conversão automática (`g`/`kg`, `ml`/`litro`), baixa idempotente no preparo e estorno no cancelamento | `fichas_tecnicas.json` |
| Módulo 3 & 4 — Caixa, Contas & DRE | [gestao_lib.php](gestao_lib.php), [gestao_api.php](gestao_api.php) | Abertura, sangria, suprimento, fechamento com apuração Dinheiro/PIX/Cartão, Contas a Pagar/Receber, lançamento automático de vendas concluídas e DRE completo | `caixa.json`, `financeiro.json` |
| Módulo 5 & 6 — CRM, Fidelidade, Cupons & Recuperação | [save_order.php](save_order.php), [recuperacao_api.php](recuperacao_api.php), [js/script.js](js/script.js) | Sincronização automática de clientes e pontos, validação server-side de cupons no checkout e rastreamento de carrinhos abandonados com chamada WhatsApp | `clientes.json`, `marketing.json`, `recuperacao.json` |
| Módulo 7 — Entregadores | [gestao_lib.php](gestao_lib.php), [js/painel.js](js/painel.js) | Cadastro de motoboys, vínculo direto nos cards do Kanban e relatório de entregas/taxas | `entregadores.json`, `pedidos.json` |
| Módulo 8–10 — Backup, Planos & Checklist | [gestao_api.php](gestao_api.php), [superadmin.html](superadmin.html) | Exportação de snapshot JSON completo, planos Essencial/Profissional/Avançado e Checklist interativo 0/14 em `Equipe & Backup` | Snapshot completo multi-tenant |

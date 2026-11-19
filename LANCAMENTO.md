# Checklist de lançamento — AcheIAki (Railway)

## Infra & Automação Técnica
- [x] Deploy ativo: `https://acheiaki-production.up.railway.app`
- [x] `/health.php` → `sql_connected: true`, `persistencia_oficial: postgresql`, `worker.ativo: true`
- [x] Otimização WebP automática (`scripts/compress_assets.php` no build Docker + `router.php` + `upload_produto_imagem.php`)
- [x] Variáveis obrigatórias configuradas: `ACHEIAKI_MASTER_*`, `ACHEIAKI_APP_SECRET`, `DATABASE_URL`
- [ ] Postgres volume / backup automático verificado no painel do Railway

## Operação & Testes em Produção
- [ ] Executar no shell do Railway: `php verificar_sistema.php` e `php go_live_smoke.php https://acheiaki-production.up.railway.app`
- [ ] SuperAdmin (`superadmin.html`): preencher **CNPJ da plataforma**, **E-mail DPO** e **Domínio final** (se houver domínio próprio)
- [ ] 1 a 3 lojas piloto: CNPJ, cardápio, WhatsApp, frete, teste real no 4G + impressão térmica
- [ ] Opcional (quando ativar recursos extras): `ACHEIAKI_MP_ACCESS_TOKEN` + webhook `/mp_webhook.php`, `REDIS_URL`, `ACHEIAKI_VAPID_*`, `PUSHER_*`

## Já não usamos
- Hostinger / JSON como banco oficial — somente PostgreSQL em produção.
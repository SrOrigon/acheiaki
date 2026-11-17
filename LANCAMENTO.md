# Checklist de lançamento — AcheIAki (Railway)

## Infra
- [ ] Deploy ativo: `https://acheiaki-production.up.railway.app`
- [ ] `health.php` → `sql_connected: true`, `persistencia_oficial: postgresql`
- [ ] Postgres volume (~5 GB) com backup habilitado no Railway
- [ ] Variáveis: `ACHEIAKI_MASTER_*`, `ACHEIAKI_APP_SECRET`, `DATABASE_URL`

## Operação
- [ ] SuperAdmin: senha master trocada, domínio futuro (se houver)
- [ ] 1 loja piloto: CNPJ, cardápio, WhatsApp, frete, 1 pedido teste
- [ ] `php go_live_smoke.php https://acheiaki-production.up.railway.app` (no container ou após deploy)

## Comercial (quando quiser cobrar)
- [ ] Mercado Pago: `ACHEIAKI_MP_ACCESS_TOKEN` + webhook `/mp_webhook.php`

## Já não usamos
- Hostinger / JSON como banco oficial — somente PostgreSQL em produção.
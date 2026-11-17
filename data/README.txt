AcheIAki — pasta data/

PRODUÇÃO (Railway):
  Dados oficiais ficam no PostgreSQL (DATABASE_URL).
  Esta pasta no container é só estrutura vazia / logs / rate limit.

DESENVOLVIMENTO LOCAL (opcional):
  JSON em data/ e data/tenants/{slug}/ quando não há banco SQL.

Não commite: auth.json, master_auth.json, integracoes.json, tenants/, logs/
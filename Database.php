<?php
/**
 * Multi-tenant Database Handler — PedidoZap / Food Gestão
 * Produção: PostgreSQL (Railway) via PDO. JSON em data/ apenas para dev local sem SQL.
 */
require_once __DIR__ . '/db_pdo.php';
require_once __DIR__ . '/cache_lib.php';

class Database
{
    private static $basePath = __DIR__ . '/data/';
    private static $tenant = 'default';

    public static function setTenant($slug)
    {
        self::$tenant = self::sanitizeTenant($slug);
    }

    public static function getTenant()
    {
        return self::$tenant;
    }

    private static function sanitizeTenant($slug)
    {
        $slug = strtolower(trim((string) $slug));
        if ($slug === '' || $slug === 'default') {
            return 'default';
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug)) {
            return 'default';
        }
        return $slug;
    }

    private static function resolvePath($filename)
    {
        if (self::$tenant === 'default') {
            return self::$basePath . $filename;
        }

        $dir = self::$basePath . 'tenants/' . self::$tenant . '/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir . $filename;
    }

    /**
     * Caminho legado (tenant default) para fallback de cardápio/config.
     */
    private static function defaultPath($filename)
    {
        return self::$basePath . $filename;
    }

    /**
     * Leitura com lock compartilhado (usa L1/Redis cache + PDO SQL quando configurado).
     */
    public static function read($filename)
    {
        $cacheKey = 'kv:' . self::$tenant . ':' . $filename;
        $cached = pz_cache_get($cacheKey, null);
        if (is_array($cached)) {
            return $cached;
        }

        $sqlRes = pz_db_kv_read(self::$tenant, $filename);
        if (is_array($sqlRes)) {
            pz_cache_set($cacheKey, $sqlRes, 20);
            return $sqlRes;
        }
        if (function_exists('pz_db_sql_modo_ativo') && pz_db_sql_modo_ativo()) {
            return [];
        }

        $diskRes = self::readFromDisk($filename);
        pz_cache_set($cacheKey, $diskRes, 20);
        return $diskRes;
    }

    private static function readFromDisk($filename)
    {
        $path = self::resolvePath($filename);
        if (!file_exists($path)) {
            return [];
        }

        $fp = fopen($path, 'r');
        if (!$fp) {
            return [];
        }

        flock($fp, LOCK_SH);
        $size = filesize($path);
        $content = ($size !== false && $size > 0) ? fread($fp, $size) : '';
        flock($fp, LOCK_UN);
        fclose($fp);

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Escrita atômica (grava em PDO SQL quando configurado, invalida cache e espelha no JSON local se possível).
     */
    public static function write($filename, $data)
    {
        pz_cache_del('kv:' . self::$tenant . ':' . $filename);
        if (in_array($filename, ['config.json', 'cardapio.json', 'avaliacoes.json'], true)) {
            pz_cache_del('pub_store_json_' . self::$tenant);
            pz_cache_del('mkt_lojas_all');
        }
        $sqlOk = pz_db_kv_write(self::$tenant, $filename, $data);
        if ($sqlOk !== null) {
            if (function_exists('pz_db_should_mirror_json_files') && pz_db_should_mirror_json_files()) {
                $path = self::resolvePath($filename);
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                $json = json_encode($data, JSON_UNESCAPED_UNICODE);
                @file_put_contents($path, $json, LOCK_EX);
            }
            if ($sqlOk) {
                pz_cache_set('kv:' . self::$tenant . ':' . $filename, $data, 20);
            }
            return (bool) $sqlOk;
        }

        $path = self::resolvePath($filename);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $ok = file_put_contents($path, $json, LOCK_EX) !== false;
        if ($ok) {
            pz_cache_set('kv:' . self::$tenant . ':' . $filename, $data, 20);
        }
        return $ok;
    }

    /**
     * Read-modify-write em uma única transação (FOR UPDATE no SQL ou LOCK_EX em arquivo).
     *
     * @param callable $mutator Recebe o array por referência; deve retornar true em sucesso.
     */
    public static function mutate($filename, callable $mutator)
    {
        pz_cache_del('kv:' . self::$tenant . ':' . $filename);
        if (in_array($filename, ['config.json', 'cardapio.json', 'avaliacoes.json'], true)) {
            pz_cache_del('pub_store_json_' . self::$tenant);
            pz_cache_del('mkt_lojas_all');
        }
        $pdo = pz_pdo();
        if ($pdo) {
            $sqlCheck = pz_db_kv_read(self::$tenant, $filename);
            if ($sqlCheck === false) {
                $arquivoAtual = self::read($filename);
                if (!empty($arquivoAtual)) {
                    pz_db_kv_write(self::$tenant, $filename, $arquivoAtual);
                }
            }
            $sqlMut = pz_db_kv_mutate(self::$tenant, $filename, $mutator);
            pz_cache_del('kv:' . self::$tenant . ':' . $filename);
            if ($sqlMut !== null) {
                return (bool) $sqlMut;
            }
        }

        $path = self::resolvePath($filename);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fp = fopen($path, 'c+');
        if (!$fp) {
            return false;
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }

        try {
            clearstatcache(true, $path);
            $size = filesize($path);
            $content = ($size !== false && $size > 0) ? fread($fp, $size) : '';
            $data = json_decode($content, true);
            if (!is_array($data)) {
                $data = [];
            }

            $result = $mutator($data);
            if ($result === false) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return false;
            }

            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $json);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            return $result === true || $result === null ? true : (bool) $result;
        } catch (Throwable $e) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }
    }

    private static function bumpOrdersVersion($slug = null)
    {
        $t = $slug !== null ? self::sanitizeTenant($slug) : self::$tenant;
        $ver = (string) round(microtime(true) * 1000);
        pz_cache_set('orders_ver_' . $t, $ver, 86400);
        return $ver;
    }

    public static function getOrdersVersion($slug = null)
    {
        $t = $slug !== null ? self::sanitizeTenant($slug) : self::$tenant;
        $ver = pz_cache_get('orders_ver_' . $t, null);
        if (is_string($ver) && $ver !== '') {
            return $ver;
        }
        $anterior = self::$tenant;
        self::setTenant($t);
        $orders = self::read('pedidos.json');
        self::setTenant($anterior);
        $maxMs = 0;
        if (is_array($orders)) {
            foreach ($orders as $o) {
                if (is_array($o)) {
                    $m = (int) ($o['updated_ms'] ?? 0);
                    if ($m > $maxMs) {
                        $maxMs = $m;
                    }
                }
            }
        }
        $ver = $maxMs > 0 ? (string) $maxMs : '1';
        pz_cache_set('orders_ver_' . $t, $ver, 86400);
        return $ver;
    }

    public static function saveOrderAtomic(array $order, $checkoutToken = '')
    {
        if (self::$tenant !== 'default' && !isset($order['tenant'])) {
            $order['tenant'] = self::$tenant;
        }
        $token = trim((string) $checkoutToken);
        $agoraIso = date('c');
        $agoraMs = (int) round(microtime(true) * 1000);
        $order['checkout_token'] = $token;
        $order['created_at'] = $order['created_at'] ?? $agoraIso;
        $order['updated_at'] = $agoraIso;
        $order['updated_ms'] = $agoraMs;

        $duplicado = null;
        $ok = self::mutate('pedidos.json', function (&$orders) use ($order, $token, &$duplicado) {
            if (!is_array($orders)) {
                $orders = [];
            }
            if ($token !== '') {
                foreach ($orders as $existing) {
                    if (is_array($existing) && (string) ($existing['checkout_token'] ?? '') === $token) {
                        $duplicado = $existing;
                        return false;
                    }
                }
            }
            array_unshift($orders, $order);
            if (count($orders) > 500) {
                $orders = array_slice($orders, 0, 500);
            }
            return true;
        });

        if ($duplicado !== null) {
            return ['success' => true, 'deduplicated' => true, 'order' => $duplicado];
        }
        if (!$ok) {
            return ['success' => false, 'deduplicated' => false, 'order' => null];
        }

        // Espelha em tabela relacional transacional se PDO estiver ativo
        $pdo = function_exists('pz_pdo') ? pz_pdo() : null;
        if ($pdo instanceof PDO) {
            try {
                $pdo->beginTransaction();
                $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'pgsql') {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS pz_orders_tx (
                        id VARCHAR(64) NOT NULL,
                        tenant_slug VARCHAR(64) NOT NULL,
                        checkout_token VARCHAR(96) DEFAULT '',
                        status VARCHAR(24) NOT NULL,
                        items_count INTEGER NOT NULL,
                        total_amount NUMERIC(12,2) NOT NULL,
                        created_at VARCHAR(40) NOT NULL,
                        updated_at VARCHAR(40) NOT NULL,
                        PRIMARY KEY (tenant_slug, id)
                    )");
                    $st = $pdo->prepare('INSERT INTO pz_orders_tx (id, tenant_slug, checkout_token, status, items_count, total_amount, created_at, updated_at)
                        VALUES (?,?,?,?,?,?,?,?)
                        ON CONFLICT (tenant_slug, id) DO UPDATE SET status = EXCLUDED.status, updated_at = EXCLUDED.updated_at');
                } else {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS pz_orders_tx (
                        id VARCHAR(64) NOT NULL,
                        tenant_slug VARCHAR(64) NOT NULL,
                        checkout_token VARCHAR(96) DEFAULT '',
                        status VARCHAR(24) NOT NULL,
                        items_count INT NOT NULL,
                        total_amount DECIMAL(12,2) NOT NULL,
                        created_at VARCHAR(40) NOT NULL,
                        updated_at VARCHAR(40) NOT NULL,
                        PRIMARY KEY (tenant_slug, id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                    $st = $pdo->prepare('INSERT INTO pz_orders_tx (id, tenant_slug, checkout_token, status, items_count, total_amount, created_at, updated_at)
                        VALUES (?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = VALUES(updated_at)');
                }
                $st->execute([
                    (string) $order['id'],
                    self::$tenant,
                    $token,
                    (string) ($order['status'] ?? 'novo'),
                    count((array) ($order['itens'] ?? [])),
                    round((float) ($order['total'] ?? 0), 2),
                    $agoraIso,
                    $agoraIso,
                ]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        }

        self::bumpOrdersVersion();
        return ['success' => true, 'deduplicated' => false, 'order' => $order];
    }

    public static function saveOrder($order)
    {
        $res = self::saveOrderAtomic(is_array($order) ? $order : [], (string) ($order['checkout_token'] ?? ''));
        return !empty($res['success']);
    }

    public static function patchOrder($id, array $fields)
    {
        $permitidos = [
            'rastreio_externo',
            'comanda_impressa_id',
            'comanda_impressa_em',
            'comanda_id',
            'cozinha_desde',
            'aceito_em',
            'em_preparo_em',
            'pronto_em',
            'saiu_entrega_em',
            'cancelado_em',
            'etapa_detalhada',
            'entregador_id',
            'entregador_nome',
            'saiu_em',
            'entregue_em',
            'valor_recebido_entrega',
            'cancelado',
            'motivo_cancelamento',
        ];
        $ok = self::mutate('pedidos.json', function (&$orders) use ($id, $fields, $permitidos) {
            foreach ($orders as &$order) {
                if ((string) ($order['id'] ?? '') === (string) $id) {
                    foreach ($permitidos as $campo) {
                        if (array_key_exists($campo, $fields)) {
                            $order[$campo] = $fields[$campo];
                        }
                    }
                    $order['updated_at'] = date('c');
                    $order['updated_ms'] = (int) round(microtime(true) * 1000);
                    return true;
                }
            }
            unset($order);
            return false;
        });
        if ($ok) {
            self::bumpOrdersVersion();
        }
        return $ok;
    }

    public static function normalizeStatusName($raw)
    {
        $k = strtolower(trim((string) $raw));
        $map = [
            'pendente' => 'novo',
            'novo' => 'novo',
            'aceito' => 'preparo',
            'em_preparo' => 'preparo',
            'preparo' => 'preparo',
            'pronto' => 'em-rota',
            'saiu_entrega' => 'em-rota',
            'em-rota' => 'em-rota',
            'entregue' => 'concluido',
            'concluido' => 'concluido',
            'cancelado' => 'arquivado',
            'arquivado' => 'arquivado',
        ];
        return $map[$k] ?? '';
    }

    public static function transitionOrderStatus($id, $rawStatus, array $opts = [])
    {
        $novoStatus = self::normalizeStatusName($rawStatus);
        $rawKey = strtolower(trim((string) $rawStatus));
        if ($novoStatus === '') {
            return ['ok' => false, 'code' => 400, 'message' => 'Status inválido.'];
        }

        $rank = [
            'novo' => 0,
            'preparo' => 1,
            'em-rota' => 2,
            'concluido' => 3,
            'arquivado' => 4,
        ];
        $allowRollback = !empty($opts['forcar_rollback']);
        $actorRole = strtolower(trim((string) ($opts['role'] ?? 'painel')));
        $agoraSql = date('Y-m-d H:i:s');
        $agoraIso = date('c');
        $agoraMs = (int) round(microtime(true) * 1000);

        $erroMsg = '';
        $erroCode = 404;
        $pedidoFinal = null;

        $ok = self::mutate('pedidos.json', function (&$orders) use ($id, $novoStatus, $rawKey, $rank, $allowRollback, $actorRole, $agoraSql, $agoraIso, $agoraMs, &$erroMsg, &$erroCode, &$pedidoFinal) {
            foreach ($orders as &$order) {
                if ((string) ($order['id'] ?? '') !== (string) $id) {
                    continue;
                }
                $anterior = (string) ($order['status'] ?? 'novo');
                if ($anterior === $novoStatus) {
                    if ($rawKey === 'pronto' && empty($order['pronto_em'])) {
                        $order['pronto_em'] = $agoraIso;
                    }
                    $order['updated_at'] = $agoraIso;
                    $order['updated_ms'] = $agoraMs;
                    $pedidoFinal = $order;
                    return true;
                }

                // Trava contra retrocesso acidental por concorrência (cozinha/motoboy vs painel)
                $rAnt = $rank[$anterior] ?? 0;
                $rNov = $rank[$novoStatus] ?? 0;
                if ($rNov < $rAnt) {
                    if (!$allowRollback || in_array($actorRole, ['cozinha', 'motoboy', 'entregador'], true) || in_array($anterior, ['concluido', 'arquivado'], true) && $actorRole !== 'painel_admin') {
                        $erroCode = 409;
                        $erroMsg = 'Transição bloqueada: o pedido #' . $id . ' já está em "' . $anterior . '" e não pode voltar para "' . $novoStatus . '".';
                        return false;
                    }
                }
                if ($anterior === 'arquivado' && $novoStatus !== 'arquivado' && !$allowRollback) {
                    $erroCode = 409;
                    $erroMsg = 'Pedido encerrado/cancelado não pode avançar automaticamente.';
                    return false;
                }

                $order['status'] = $novoStatus;
                $order['etapa_detalhada'] = $rawKey;
                $order['updated_at'] = $agoraIso;
                $order['updated_ms'] = $agoraMs;

                if ($novoStatus === 'preparo') {
                    if (empty($order['aceito_em'])) {
                        $order['aceito_em'] = $agoraIso;
                    }
                    if (empty($order['em_preparo_em'])) {
                        $order['em_preparo_em'] = $agoraIso;
                    }
                    $order['cozinha_desde'] = $order['cozinha_desde'] ?? $agoraSql;
                    if (empty($order['comanda_id'])) {
                        $order['comanda_id'] = date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
                        $order['comanda_impressa_id'] = '';
                    }
                } elseif ($novoStatus === 'em-rota') {
                    if (empty($order['aceito_em'])) {
                        $order['aceito_em'] = $agoraIso;
                    }
                    if (empty($order['pronto_em'])) {
                        $order['pronto_em'] = $agoraIso;
                    }
                    if (empty($order['saiu_entrega_em'])) {
                        $order['saiu_entrega_em'] = $agoraIso;
                    }
                    if (empty($order['saiu_em'])) {
                        $order['saiu_em'] = $agoraSql;
                    }
                } elseif ($novoStatus === 'concluido') {
                    if (empty($order['entregue_em'])) {
                        $order['entregue_em'] = $agoraSql;
                    }
                    unset($order['location']);
                } elseif ($novoStatus === 'arquivado') {
                    if (!empty($order['cancelado']) && empty($order['cancelado_em'])) {
                        $order['cancelado_em'] = $agoraIso;
                    }
                    unset($order['location']);
                }

                $pedidoFinal = $order;
                return true;
            }
            unset($order);
            $erroCode = 404;
            $erroMsg = 'Pedido não encontrado.';
            return false;
        });

        if ($ok) {
            self::bumpOrdersVersion();
            return ['ok' => true, 'code' => 200, 'order' => $pedidoFinal, 'status' => $novoStatus];
        }

        return ['ok' => false, 'code' => $erroCode, 'message' => $erroMsg ?: 'Não foi possível atualizar o status.'];
    }

    public static function updateOrderStatus($id, $status)
    {
        $res = self::transitionOrderStatus($id, $status, ['forcar_rollback' => true, 'role' => 'painel']);
        return !empty($res['ok']);
    }

    public static function garantirComandaCozinha($id)
    {
        $ok = false;
        self::mutate('pedidos.json', function (&$orders) use ($id, &$ok) {
            foreach ($orders as &$order) {
                if ((string) ($order['id'] ?? '') !== (string) $id) {
                    continue;
                }
                if (($order['status'] ?? '') !== 'preparo') {
                    return false;
                }
                if (!empty($order['comanda_id'])) {
                    $ok = true;
                    return true;
                }
                $order['cozinha_desde'] = $order['cozinha_desde'] ?? date('Y-m-d H:i:s');
                $order['comanda_id'] = date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
                $order['comanda_impressa_id'] = '';
                $ok = true;
                return true;
            }
            unset($order);
            return false;
        });
        return $ok;
    }

    public static function findOrder($id)
    {
        foreach (self::read('pedidos.json') as $order) {
            if (is_array($order) && (string) ($order['id'] ?? '') === (string) $id) {
                return $order;
            }
        }
        return null;
    }

    public static function updateOrderLocation($id, $lat, $lng)
    {
        $ok = self::mutate('pedidos.json', function (&$orders) use ($id, $lat, $lng) {
            foreach ($orders as &$order) {
                if ((string) ($order['id'] ?? '') === (string) $id) {
                    if (($order['status'] ?? '') === 'concluido' || ($order['status'] ?? '') === 'arquivado') {
                        return false;
                    }
                    $order['location'] = [
                        'lat' => $lat,
                        'lng' => $lng,
                        'last_update' => date('Y-m-d H:i:s'),
                    ];
                    $order['updated_at'] = date('c');
                    $order['updated_ms'] = (int) round(microtime(true) * 1000);
                    return true;
                }
            }
            unset($order);
            return false;
        });
        if ($ok) {
            self::bumpOrdersVersion();
        }
        return $ok;
    }

    /**
     * Remove todos os pedidos arquivados (lixeira).
     */
    public static function deleteArchivedOrders()
    {
        $ok = self::mutate('pedidos.json', function (&$orders) {
            $orders = array_values(array_filter($orders, function ($p) {
                return ($p['status'] ?? 'novo') !== 'arquivado';
            }));
            return true;
        });
        if ($ok) {
            self::bumpOrdersVersion();
        }
        return $ok;
    }

    /**
     * Leitura sem fallback para o tenant default (isolamento na vitrine/API).
     */
    public static function readStrict($filename)
    {
        $sqlRes = pz_db_kv_read(self::$tenant, $filename);
        if (is_array($sqlRes)) {
            return $sqlRes;
        }
        if (function_exists('pz_db_sql_modo_ativo') && pz_db_sql_modo_ativo()) {
            return [];
        }

        return self::readFromDisk($filename);
    }

    public static function tenantDataExists($slug = null)
    {
        $slug = self::sanitizeTenant($slug ?? self::$tenant);
        if (function_exists('pz_db_sql_modo_ativo') && pz_db_sql_modo_ativo()) {
            $cfg = pz_db_kv_read($slug, 'config.json');
            $menu = pz_db_kv_read($slug, 'cardapio.json');
            if (is_array($cfg) && $cfg !== []) {
                return true;
            }
            if (is_array($menu) && $menu !== []) {
                return true;
            }
            return false;
        }
        if (is_array(pz_db_kv_read($slug, 'config.json')) || is_array(pz_db_kv_read($slug, 'cardapio.json'))) {
            return true;
        }
        if ($slug === 'default') {
            $base = self::$basePath;
            return file_exists($base . 'config.json')
                || file_exists($base . 'cardapio.json');
        }

        $dir = self::$basePath . 'tenants/' . $slug . '/';
        return is_dir($dir) && (
            file_exists($dir . 'config.json')
            || file_exists($dir . 'cardapio.json')
            || file_exists($dir . 'meta.json')
        );
    }

    public static function getTenantDirectory($slug = null)
    {
        $slug = self::sanitizeTenant($slug ?? self::$tenant);
        if ($slug === 'default') {
            return self::$basePath;
        }
        $dir = self::$basePath . 'tenants/' . $slug . '/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Registra/atualiza metadados do estabelecimento em data/tenants_registry.json
     */
    public static function registerTenant($slug, array $meta = [])
    {
        $slug = self::sanitizeTenant($slug);
        if ($slug === 'default') {
            return true;
        }

        $previousTenant = self::$tenant;

        self::$tenant = $slug;
        $metaWritten = self::write('meta.json', array_merge($meta, [
            'slug' => $slug,
            'updated_at' => date('Y-m-d H:i:s'),
        ]));

        self::$tenant = 'default';
        $ok = self::mutate('tenants_registry.json', function (&$registry) use ($slug, $meta) {
            if (!is_array($registry)) {
                $registry = [];
            }
            $existing = $registry[$slug] ?? [];
            $registry[$slug] = array_merge($existing, $meta, [
                'slug' => $slug,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        });

        self::$tenant = $previousTenant;
        return $ok && $metaWritten;
    }

    public static function emptyCardapioStructure()
    {
        return [
            'hamburgueres' => [],
            'porcoes' => [],
            'bebidas' => [],
            'acai' => [],
            'combos' => [],
            'promocoes' => [],
        ];
    }
}

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

    public static function saveOrder($order)
    {
        if (self::$tenant !== 'default' && !isset($order['tenant'])) {
            $order['tenant'] = self::$tenant;
        }

        return self::mutate('pedidos.json', function (&$orders) use ($order) {
            if (!is_array($orders)) {
                $orders = [];
            }
            array_unshift($orders, $order);
            if (count($orders) > 500) {
                $orders = array_slice($orders, 0, 500);
            }
            return true;
        });
    }

    public static function patchOrder($id, array $fields)
    {
        $permitidos = [
            'rastreio_externo',
            'comanda_impressa_id',
            'comanda_id',
            'cozinha_desde',
            'entregador_id',
            'entregador_nome',
            'saiu_em',
            'entregue_em',
            'valor_recebido_entrega',
            'cancelado',
            'motivo_cancelamento',
        ];
        return self::mutate('pedidos.json', function (&$orders) use ($id, $fields, $permitidos) {
            foreach ($orders as &$order) {
                if ((string) ($order['id'] ?? '') === (string) $id) {
                    foreach ($permitidos as $campo) {
                        if (array_key_exists($campo, $fields)) {
                            $order[$campo] = $fields[$campo];
                        }
                    }
                    return true;
                }
            }
            unset($order);
            return false;
        });
    }

    public static function updateOrderStatus($id, $status)
    {
        return self::mutate('pedidos.json', function (&$orders) use ($id, $status) {
            foreach ($orders as &$order) {
                if ((string) ($order['id'] ?? '') === (string) $id) {
                    $anterior = (string) ($order['status'] ?? 'novo');
                    $order['status'] = $status;
                    if ($status === 'preparo' && $anterior !== 'preparo') {
                        $order['cozinha_desde'] = date('Y-m-d H:i:s');
                        $order['comanda_id'] = date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
                        $order['comanda_impressa_id'] = '';
                    }
                    if ($status === 'concluido' || $status === 'arquivado') {
                        unset($order['location']);
                    }
                    return true;
                }
            }
            unset($order);
            return false;
        });
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
        return self::mutate('pedidos.json', function (&$orders) use ($id, $lat, $lng) {
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
                    return true;
                }
            }
            unset($order);
            return false;
        });
    }

    /**
     * Remove todos os pedidos arquivados (lixeira).
     */
    public static function deleteArchivedOrders()
    {
        return self::mutate('pedidos.json', function (&$orders) {
            $orders = array_values(array_filter($orders, function ($p) {
                return ($p['status'] ?? 'novo') !== 'arquivado';
            }));
            return true;
        });
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

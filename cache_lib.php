<?php
/**
 * Camada de Cache em Memória + Redis (cliente RESP puro em PHP, zero extensão extra) + Disco.
 * Mantém leitura de vitrine/cardápio extremamente rápida em picos de concorrência.
 */

if (!isset($GLOBALS['PZ_L1_CACHE'])) {
    $GLOBALS['PZ_L1_CACHE'] = [];
}

class PzRedisClient
{
    private static $conn = null;
    private static $checked = false;
    private static $available = false;

    public static function config()
    {
        $url = '';
        foreach (['REDIS_URL', 'REDIS_PUBLIC_URL', 'ACHEIAKI_REDIS_URL'] as $k) {
            $v = function_exists('pz_db_env_val') ? pz_db_env_val($k) : (string) (getenv($k) ?: '');
            if ($v !== '') {
                $url = $v;
                break;
            }
        }
        if ($url !== '') {
            $p = @parse_url($url);
            if (is_array($p) && !empty($p['host'])) {
                return [
                    'host' => (string) $p['host'],
                    'port' => (int) ($p['port'] ?? 6379),
                    'pass' => isset($p['pass']) ? rawurldecode((string) $p['pass']) : '',
                    'tls' => strtolower((string) ($p['scheme'] ?? 'redis')) === 'rediss',
                ];
            }
        }
        $host = function_exists('pz_db_env_val') ? pz_db_env_val('REDISHOST') : (string) (getenv('REDISHOST') ?: '');
        if ($host === '') {
            $host = function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_REDIS_HOST') : (string) (getenv('ACHEIAKI_REDIS_HOST') ?: '');
        }
        if ($host === '') {
            return null;
        }
        $port = (int) ((function_exists('pz_db_env_val') ? pz_db_env_val('REDISPORT') : getenv('REDISPORT')) ?: 6379);
        $pass = function_exists('pz_db_env_val') ? pz_db_env_val('REDISPASSWORD') : (string) (getenv('REDISPASSWORD') ?: '');
        return ['host' => $host, 'port' => $port, 'pass' => $pass, 'tls' => false];
    }

    public static function connect()
    {
        if (self::$checked) {
            return self::$available ? self::$conn : null;
        }
        self::$checked = true;
        $cfg = self::config();
        if (!$cfg) {
            return null;
        }
        $proto = !empty($cfg['tls']) ? 'tls://' : 'tcp://';
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($proto . $cfg['host'] . ':' . $cfg['port'], $errno, $errstr, 0.8);
        if (!$fp) {
            return null;
        }
        stream_set_timeout($fp, 1);
        self::$conn = $fp;
        if ($cfg['pass'] !== '') {
            $authRes = self::cmd(['AUTH', $cfg['pass']]);
            if ($authRes === false) {
                @fclose($fp);
                self::$conn = null;
                return null;
            }
        }
        self::$available = true;
        return self::$conn;
    }

    public static function available()
    {
        return self::connect() !== null;
    }

    public static function cmd(array $args)
    {
        $fp = self::connect();
        if (!$fp && empty(self::$conn)) {
            return false;
        }
        $fp = self::$conn;
        if (!$fp) {
            return false;
        }
        $out = '*' . count($args) . "\r\n";
        foreach ($args as $a) {
            $s = (string) $a;
            $out .= '$' . strlen($s) . "\r\n" . $s . "\r\n";
        }
        $written = @fwrite($fp, $out);
        if ($written === false) {
            self::reset();
            return false;
        }
        return self::readReply($fp);
    }

    private static function readReply($fp)
    {
        $line = @fgets($fp);
        if ($line === false) {
            self::reset();
            return false;
        }
        $prefix = $line[0] ?? '';
        $payload = rtrim(substr($line, 1), "\r\n");
        if ($prefix === '+') {
            return $payload;
        }
        if ($prefix === '-') {
            return false;
        }
        if ($prefix === ':') {
            return (int) $payload;
        }
        if ($prefix === '$') {
            $len = (int) $payload;
            if ($len < 0) {
                return null;
            }
            $buf = '';
            while (strlen($buf) < $len) {
                $chunk = @fread($fp, $len - strlen($buf));
                if ($chunk === false || $chunk === '') {
                    self::reset();
                    return false;
                }
                $buf .= $chunk;
            }
            @fread($fp, 2); // \r\n
            return $buf;
        }
        if ($prefix === '*') {
            $count = (int) $payload;
            if ($count < 0) {
                return null;
            }
            $arr = [];
            for ($i = 0; $i < $count; $i++) {
                $arr[] = self::readReply($fp);
            }
            return $arr;
        }
        return false;
    }

    private static function reset()
    {
        if (self::$conn) {
            @fclose(self::$conn);
        }
        self::$conn = null;
        self::$available = false;
    }
}

function pz_cache_dir()
{
    $dir = __DIR__ . '/data/cache/kv';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function pz_cache_disk_path($key)
{
    return pz_cache_dir() . '/' . sha1((string) $key) . '.json';
}

function pz_cache_get($key, $default = null)
{
    $k = 'pz:' . (string) $key;
    if (array_key_exists($k, $GLOBALS['PZ_L1_CACHE'])) {
        $entry = $GLOBALS['PZ_L1_CACHE'][$k];
        if ($entry['exp'] === 0 || $entry['exp'] >= time()) {
            return $entry['val'];
        }
        unset($GLOBALS['PZ_L1_CACHE'][$k]);
    }

    if (function_exists('apcu_fetch')) {
        $ok = false;
        $val = apcu_fetch($k, $ok);
        if ($ok) {
            $GLOBALS['PZ_L1_CACHE'][$k] = ['val' => $val, 'exp' => time() + 15];
            return $val;
        }
    }

    if (PzRedisClient::available()) {
        $raw = PzRedisClient::cmd(['GET', $k]);
        if (is_string($raw) && $raw !== '') {
            $dec = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $GLOBALS['PZ_L1_CACHE'][$k] = ['val' => $dec, 'exp' => time() + 15];
                return $dec;
            }
        }
    }

    $path = pz_cache_disk_path($k);
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        $dec = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($dec) && array_key_exists('val', $dec)) {
            $exp = (int) ($dec['exp'] ?? 0);
            if ($exp === 0 || $exp >= time()) {
                $GLOBALS['PZ_L1_CACHE'][$k] = ['val' => $dec['val'], 'exp' => $exp];
                return $dec['val'];
            }
            @unlink($path);
        }
    }

    return $default;
}

function pz_cache_set($key, $value, $ttl = 60)
{
    $k = 'pz:' . (string) $key;
    $ttl = max(1, (int) $ttl);
    $exp = time() + $ttl;
    $GLOBALS['PZ_L1_CACHE'][$k] = ['val' => $value, 'exp' => $exp];

    if (function_exists('apcu_store')) {
        @apcu_store($k, $value, $ttl);
    }

    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
    if ($encoded !== false && PzRedisClient::available()) {
        PzRedisClient::cmd(['SET', $k, $encoded, 'EX', (string) $ttl]);
        return true;
    }

    $diskPayload = json_encode(['exp' => $exp, 'val' => $value], JSON_UNESCAPED_UNICODE);
    if ($diskPayload !== false) {
        @file_put_contents(pz_cache_disk_path($k), $diskPayload, LOCK_EX);
    }
    return true;
}

function pz_cache_del($key)
{
    $k = 'pz:' . (string) $key;
    unset($GLOBALS['PZ_L1_CACHE'][$k]);
    if (function_exists('apcu_delete')) {
        @apcu_delete($k);
    }
    if (PzRedisClient::available()) {
        PzRedisClient::cmd(['DEL', $k]);
    }
    $path = pz_cache_disk_path($k);
    if (is_file($path)) {
        @unlink($path);
    }
}

function pz_cache_l1_clear()
{
    $GLOBALS['PZ_L1_CACHE'] = [];
}

function pz_cache_backend_info()
{
    $redis = PzRedisClient::available();
    $apcu = function_exists('apcu_enabled') && @apcu_enabled();
    return [
        'driver' => $redis ? 'redis' : ($apcu ? 'apcu+disco' : 'l1_memoria+disco'),
        'redis_conectado' => $redis,
        'apcu_ativo' => $apcu,
        'l1_itens' => count($GLOBALS['PZ_L1_CACHE']),
    ];
}

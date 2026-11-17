<?php
/**
 * Camada PDO Relacional (MySQL / MariaDB Hostinger e PostgreSQL opcional).
 * Funciona de forma híbrida: se o banco SQL não estiver configurado em
 * data/db_config.json, retorna null e o sistema continua operando em JSON
 * automaticamente sem quebrar a vitrine ou o painel.
 */

function pz_db_config_path()
{
    return __DIR__ . '/data/db_config.json';
}

function pz_db_env_val($key)
{
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return (string) $v;
    }
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return (string) $_SERVER[$key];
    }
    return '';
}

function pz_db_ler_env()
{
    $url = '';
    foreach (['MYSQL_URL', 'DATABASE_URL', 'MYSQL_PUBLIC_URL', 'POSTGRES_URL', 'DATABASE_PUBLIC_URL', 'ACHEIAKI_DATABASE_URL'] as $k) {
        $cand = pz_db_env_val($k);
        if ($cand !== '') {
            $url = $cand;
            break;
        }
    }
    if ($url !== '') {
        $p = @parse_url($url);
        if (is_array($p) && !empty($p['host'])) {
            $scheme = strtolower((string) ($p['scheme'] ?? 'mysql'));
            $driver = (strpos($scheme, 'pg') !== false || strpos($scheme, 'postgres') !== false) ? 'pgsql' : 'mysql';
            $db = ltrim((string) ($p['path'] ?? ''), '/');
            if ($db !== '' && !empty($p['user'])) {
                return [
                    'enabled' => true,
                    'env_managed' => true,
                    'driver' => $driver,
                    'host' => (string) $p['host'],
                    'port' => (string) ($p['port'] ?? ($driver === 'pgsql' ? '5432' : '3306')),
                    'database' => $db,
                    'username' => rawurldecode((string) $p['user']),
                    'password' => rawurldecode((string) ($p['pass'] ?? '')),
                    'charset' => 'utf8mb4',
                    'atualizado_em' => 'env',
                ];
            }
        }
    }

    // Variáveis individuais Railway MySQL / PostgreSQL
    $mysqlHost = pz_db_env_val('MYSQLHOST');
    $mysqlDb = pz_db_env_val('MYSQLDATABASE');
    $mysqlUser = pz_db_env_val('MYSQLUSER');
    if ($mysqlHost !== '' && $mysqlDb !== '' && $mysqlUser !== '') {
        return [
            'enabled' => true,
            'env_managed' => true,
            'driver' => 'mysql',
            'host' => $mysqlHost,
            'port' => pz_db_env_val('MYSQLPORT') ?: '3306',
            'database' => $mysqlDb,
            'username' => $mysqlUser,
            'password' => pz_db_env_val('MYSQLPASSWORD'),
            'charset' => 'utf8mb4',
            'atualizado_em' => 'env',
        ];
    }

    $pgHost = pz_db_env_val('PGHOST');
    $pgDb = pz_db_env_val('PGDATABASE');
    $pgUser = pz_db_env_val('PGUSER');
    if ($pgHost !== '' && $pgDb !== '' && $pgUser !== '') {
        return [
            'enabled' => true,
            'env_managed' => true,
            'driver' => 'pgsql',
            'host' => $pgHost,
            'port' => pz_db_env_val('PGPORT') ?: '5432',
            'database' => $pgDb,
            'username' => $pgUser,
            'password' => pz_db_env_val('PGPASSWORD'),
            'charset' => 'utf8mb4',
            'atualizado_em' => 'env',
        ];
    }

    $customHost = pz_db_env_val('ACHEIAKI_DB_HOST');
    $customDb = pz_db_env_val('ACHEIAKI_DB_NAME');
    $customUser = pz_db_env_val('ACHEIAKI_DB_USER');
    if ($customHost !== '' && $customDb !== '' && $customUser !== '') {
        $drv = strtolower(pz_db_env_val('ACHEIAKI_DB_DRIVER')) === 'pgsql' ? 'pgsql' : 'mysql';
        return [
            'enabled' => true,
            'env_managed' => true,
            'driver' => $drv,
            'host' => $customHost,
            'port' => pz_db_env_val('ACHEIAKI_DB_PORT') ?: ($drv === 'pgsql' ? '5432' : '3306'),
            'database' => $customDb,
            'username' => $customUser,
            'password' => pz_db_env_val('ACHEIAKI_DB_PASS'),
            'charset' => 'utf8mb4',
            'atualizado_em' => 'env',
        ];
    }

    return null;
}

function pz_db_ler_config()
{
    $envCfg = pz_db_ler_env();
    if (is_array($envCfg)) {
        return $envCfg;
    }

    $path = pz_db_config_path();
    $padrao = [
        'enabled' => false,
        'env_managed' => false,
        'driver' => 'mysql', // mysql (Railway / Hostinger MariaDB/MySQL) | pgsql (PostgreSQL)
        'host' => 'localhost',
        'port' => '3306',
        'database' => '',
        'username' => '',
        'password' => '',
        'charset' => 'utf8mb4',
        'atualizado_em' => '',
    ];
    if (!is_file($path)) {
        return $padrao;
    }
    $raw = @file_get_contents($path);
    $cfg = json_decode($raw ?: '', true);
    if (!is_array($cfg)) {
        return $padrao;
    }
    return array_merge($padrao, $cfg);
}

function pz_db_config_publica()
{
    $cfg = pz_db_ler_config();
    $pass = (string) ($cfg['password'] ?? '');
    unset($cfg['password']);
    $cfg['password_configurada'] = $pass !== '';
    $cfg['conectado'] = false;
    $cfg['erro_conexao'] = '';
    if (!empty($cfg['enabled']) && $cfg['database'] !== '' && $cfg['username'] !== '') {
        try {
            $pdo = pz_pdo(true);
            $cfg['conectado'] = $pdo instanceof PDO;
        } catch (Throwable $e) {
            $cfg['erro_conexao'] = $e->getMessage();
        }
    }
    return $cfg;
}

function pz_db_salvar_config(array $dados)
{
    $atual = pz_db_ler_config();
    $driver = strtolower(trim((string) ($dados['driver'] ?? $atual['driver'])));
    if (!in_array($driver, ['mysql', 'pgsql'], true)) {
        $driver = 'mysql';
    }
    $novo = [
        'enabled' => isset($dados['enabled']) ? (bool) $dados['enabled'] : (bool) $atual['enabled'],
        'driver' => $driver,
        'host' => trim((string) ($dados['host'] ?? $atual['host'])) ?: 'localhost',
        'port' => trim((string) ($dados['port'] ?? $atual['port'])) ?: ($driver === 'pgsql' ? '5432' : '3306'),
        'database' => trim((string) ($dados['database'] ?? $atual['database'])),
        'username' => trim((string) ($dados['username'] ?? $atual['username'])),
        'password' => $atual['password'],
        'charset' => 'utf8mb4',
        'atualizado_em' => date('Y-m-d H:i:s'),
    ];
    if (array_key_exists('password', $dados) && trim((string) $dados['password']) !== '') {
        $novo['password'] = (string) $dados['password'];
    }
    if (!empty($dados['desativar'])) {
        $novo['enabled'] = false;
    }
    $dir = dirname(pz_db_config_path());
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $json = json_encode($novo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $GLOBALS['__pz_pdo_cache'] = null;
    $GLOBALS['__pz_pdo_tried'] = false;
    return @file_put_contents(pz_db_config_path(), $json, LOCK_EX) !== false;
}

function pz_pdo($throwOnError = false)
{
    static $schemaOk = false;
    if (isset($GLOBALS['__pz_pdo_cache']) && $GLOBALS['__pz_pdo_cache'] instanceof PDO) {
        return $GLOBALS['__pz_pdo_cache'];
    }
    if (!empty($GLOBALS['__pz_pdo_tried']) && !$throwOnError) {
        return null;
    }
    $GLOBALS['__pz_pdo_tried'] = true;

    $cfg = pz_db_ler_config();
    if (empty($cfg['enabled']) || $cfg['database'] === '' || $cfg['username'] === '') {
        return null;
    }

    $driver = $cfg['driver'] === 'pgsql' ? 'pgsql' : 'mysql';
    if (!class_exists('PDO') || !in_array($driver, PDO::getAvailableDrivers(), true)) {
        if ($throwOnError) {
            throw new RuntimeException("Driver PDO '{$driver}' não está disponível neste PHP.");
        }
        return null;
    }

    $host = $cfg['host'] ?: 'localhost';
    $port = $cfg['port'] ?: ($driver === 'pgsql' ? '5432' : '3306');
    $db = $cfg['database'];

    $dsn = $driver === 'pgsql'
        ? "pgsql:host={$host};port={$port};dbname={$db}"
        : "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $cfg['username'], (string) $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $GLOBALS['__pz_pdo_cache'] = $pdo;
        if (!$schemaOk) {
            $schemaOk = true;
            pz_db_executar_schema($pdo, $driver);
            pz_db_auto_seed_se_vazio($pdo);
        }
        return $pdo;
    } catch (Throwable $e) {
        if ($throwOnError) {
            throw $e;
        }
        return null;
    }
}

function pz_db_executar_schema(PDO $pdo, $driver = 'mysql')
{
    $isPg = $driver === 'pgsql';
    $autoId = $isPg ? 'SERIAL PRIMARY KEY' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $longText = $isPg ? 'TEXT' : 'LONGTEXT';
    $engine = $isPg ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $queries = [
        "CREATE TABLE IF NOT EXISTS pz_kv_store (
            tenant_slug VARCHAR(80) NOT NULL,
            doc_name VARCHAR(120) NOT NULL,
            payload_json {$longText} NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (tenant_slug, doc_name)
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS pz_tenants_index (
            slug VARCHAR(80) PRIMARY KEY,
            nome_loja VARCHAR(160) NOT NULL,
            nicho VARCHAR(60) DEFAULT 'lanchonete',
            cidade VARCHAR(120) DEFAULT '',
            bairro VARCHAR(120) DEFAULT '',
            estado VARCHAR(10) DEFAULT '',
            cep VARCHAR(20) DEFAULT '',
            lat DOUBLE PRECISION DEFAULT 0,
            lng DOUBLE PRECISION DEFAULT 0,
            raio_entrega_km DOUBLE PRECISION DEFAULT 5,
            taxa_entrega_base DOUBLE PRECISION DEFAULT 0,
            tempo_medio_min INT DEFAULT 35,
            aberto TINYINT DEFAULT 1,
            status VARCHAR(30) DEFAULT 'ativo',
            plano VARCHAR(40) DEFAULT 'essencial',
            nota_media DOUBLE PRECISION DEFAULT 5.0,
            total_avaliacoes INT DEFAULT 0,
            score_territorio INT DEFAULT 50,
            destaque TINYINT DEFAULT 0,
            logo_url VARCHAR(500) DEFAULT '',
            cor_primaria VARCHAR(30) DEFAULT '#f97136',
            atualizado_em DATETIME NOT NULL
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS pz_compradores (
            id {$autoId},
            telefone VARCHAR(30) NOT NULL UNIQUE,
            nome VARCHAR(140) NOT NULL,
            cpf VARCHAR(20) DEFAULT '',
            email VARCHAR(160) DEFAULT '',
            senha_hash VARCHAR(255) NOT NULL,
            enderecos_json {$longText},
            favoritos_json TEXT,
            perfil_completo TINYINT DEFAULT 1,
            criado_em DATETIME NOT NULL,
            atualizado_em DATETIME NOT NULL
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS pz_pedidos_mkt (
            id {$autoId},
            codigo_pedido VARCHAR(80) NOT NULL,
            tenant_slug VARCHAR(80) NOT NULL,
            comprador_id INT DEFAULT 0,
            cliente_telefone VARCHAR(30) DEFAULT '',
            cliente_nome VARCHAR(140) DEFAULT '',
            bairro_entrega VARCHAR(120) DEFAULT '',
            cidade_entrega VARCHAR(120) DEFAULT '',
            status VARCHAR(40) DEFAULT 'novo',
            subtotal DOUBLE PRECISION DEFAULT 0,
            taxa_entrega DOUBLE PRECISION DEFAULT 0,
            total DOUBLE PRECISION DEFAULT 0,
            forma_pagamento VARCHAR(60) DEFAULT '',
            tempo_preparo_min INT DEFAULT 0,
            criado_em DATETIME NOT NULL,
            atualizado_em DATETIME NOT NULL
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS pz_avaliacoes (
            id {$autoId},
            tenant_slug VARCHAR(80) NOT NULL,
            pedido_codigo VARCHAR(80) NOT NULL,
            comprador_id INT DEFAULT 0,
            cliente_nome VARCHAR(140) DEFAULT '',
            nota INT NOT NULL,
            comentario TEXT,
            melhorias_tags_json TEXT,
            criado_em DATETIME NOT NULL
        ){$engine}",
        "CREATE TABLE IF NOT EXISTS pz_media_store (
            tenant_slug VARCHAR(80) NOT NULL,
            media_name VARCHAR(160) NOT NULL,
            mime_type VARCHAR(80) NOT NULL,
            bytes_b64 {$longText} NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (tenant_slug, media_name)
        ){$engine}",
    ];

    if ($isPg) {
        $queries = array_map(function ($sql) {
            return str_replace(['DATETIME', 'TINYINT'], ['TIMESTAMP', 'SMALLINT'], $sql);
        }, $queries);
    }

    foreach ($queries as $sql) {
        $pdo->exec($sql);
    }

    // Migração incremental segura caso pz_compradores já exista sem cpf/perfil_completo
    $alterCols = [
        "ALTER TABLE pz_compradores ADD COLUMN cpf VARCHAR(20) DEFAULT ''",
        "ALTER TABLE pz_compradores ADD COLUMN perfil_completo " . ($isPg ? 'SMALLINT' : 'TINYINT') . " DEFAULT 1",
    ];
    foreach ($alterCols as $altSql) {
        try {
            $pdo->exec($altSql);
        } catch (Throwable $e) {
            // coluna já existe
        }
    }
    return true;
}

function pz_db_kv_read($tenant, $filename)
{
    $pdo = pz_pdo();
    if (!$pdo) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT payload_json FROM pz_kv_store WHERE tenant_slug = ? AND doc_name = ? LIMIT 1');
    $stmt->execute([$tenant, $filename]);
    $row = $stmt->fetch();
    if (!$row) {
        return false; // registro ainda não existe no SQL
    }
    $decoded = json_decode((string) $row['payload_json'], true);
    return is_array($decoded) ? $decoded : [];
}

function pz_db_kv_write($tenant, $filename, $data)
{
    $pdo = pz_pdo();
    if (!$pdo) {
        return null;
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    $agora = date('Y-m-d H:i:s');
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'pgsql') {
        $sql = 'INSERT INTO pz_kv_store (tenant_slug, doc_name, payload_json, updated_at)
                VALUES (?, ?, ?, ?)
                ON CONFLICT (tenant_slug, doc_name)
                DO UPDATE SET payload_json = EXCLUDED.payload_json, updated_at = EXCLUDED.updated_at';
    } else {
        $sql = 'INSERT INTO pz_kv_store (tenant_slug, doc_name, payload_json, updated_at)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json), updated_at = VALUES(updated_at)';
    }
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([$tenant, $filename, $json, $agora]);
}

function pz_db_kv_mutate($tenant, $filename, callable $mutator)
{
    $pdo = pz_pdo();
    if (!$pdo) {
        return null;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $agora = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT payload_json FROM pz_kv_store WHERE tenant_slug = ? AND doc_name = ? FOR UPDATE');
        $stmt->execute([$tenant, $filename]);
        $row = $stmt->fetch();
        $data = [];
        if ($row && isset($row['payload_json'])) {
            $decoded = json_decode((string) $row['payload_json'], true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        $res = $mutator($data);
        if ($res === false) {
            $pdo->rollBack();
            return false;
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($driver === 'pgsql') {
            $sql = 'INSERT INTO pz_kv_store (tenant_slug, doc_name, payload_json, updated_at)
                    VALUES (?, ?, ?, ?)
                    ON CONFLICT (tenant_slug, doc_name)
                    DO UPDATE SET payload_json = EXCLUDED.payload_json, updated_at = EXCLUDED.updated_at';
        } else {
            $sql = 'INSERT INTO pz_kv_store (tenant_slug, doc_name, payload_json, updated_at)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json), updated_at = VALUES(updated_at)';
        }
        $up = $pdo->prepare($sql);
        $up->execute([$tenant, $filename, $json, $agora]);
        $pdo->commit();
        return $res === true || $res === null ? true : (bool) $res;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

function pz_migrar_json_para_sql(PDO $pdo)
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    pz_db_executar_schema($pdo, $driver);
    $base = __DIR__ . '/data/';
    $importados = 0;

    $importarDir = function ($tenantSlug, $dir) use ($pdo, &$importados) {
        if (!is_dir($dir)) {
            return;
        }
        $arquivos = glob($dir . '*.json') ?: [];
        foreach ($arquivos as $arq) {
            $nome = basename($arq);
            if ($tenantSlug === 'default' && $nome === 'db_config.json') {
                continue;
            }
            $raw = @file_get_contents($arq);
            $decoded = json_decode($raw ?: '', true);
            if (!is_array($decoded)) {
                $decoded = [];
            }
            if (pz_db_kv_write($tenantSlug, $nome, $decoded)) {
                $importados++;
            }
        }
    };

    $importarDir('default', $base);
    $tenantsDir = glob($base . 'tenants/*', GLOB_ONLYDIR) ?: [];
    foreach ($tenantsDir as $tDir) {
        $slug = basename($tDir);
        $importarDir($slug, $tDir . '/');
    }

    return $importados;
}

function pz_db_auto_seed_se_vazio(PDO $pdo)
{
    static $seeded = false;
    if ($seeded) {
        return;
    }
    $seeded = true;
    try {
        $qtd = (int) $pdo->query('SELECT COUNT(*) FROM pz_kv_store')->fetchColumn();
        if ($qtd === 0) {
            pz_migrar_json_para_sql($pdo);
        }
    } catch (Throwable $e) {
        // segue normalmente
    }
}

function pz_db_media_save($tenant, $mediaName, $mimeType, $rawBytes)
{
    $pdo = pz_pdo();
    if (!$pdo) {
        return null;
    }
    $b64 = base64_encode((string) $rawBytes);
    $agora = date('Y-m-d H:i:s');
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'pgsql') {
        $sql = 'INSERT INTO pz_media_store (tenant_slug, media_name, mime_type, bytes_b64, updated_at)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT (tenant_slug, media_name)
                DO UPDATE SET mime_type = EXCLUDED.mime_type, bytes_b64 = EXCLUDED.bytes_b64, updated_at = EXCLUDED.updated_at';
    } else {
        $sql = 'INSERT INTO pz_media_store (tenant_slug, media_name, mime_type, bytes_b64, updated_at)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE mime_type = VALUES(mime_type), bytes_b64 = VALUES(bytes_b64), updated_at = VALUES(updated_at)';
    }
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([$tenant, $mediaName, $mimeType, $b64, $agora]);
}

function pz_db_media_read($tenant, $mediaName)
{
    $pdo = pz_pdo();
    if (!$pdo) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT mime_type, bytes_b64 FROM pz_media_store WHERE tenant_slug = ? AND media_name = ? LIMIT 1');
        $stmt->execute([$tenant, $mediaName]);
        $row = $stmt->fetch();
        if (!$row || empty($row['bytes_b64'])) {
            return null;
        }
        $bytes = base64_decode((string) $row['bytes_b64'], true);
        if ($bytes === false) {
            return null;
        }
        return ['mime' => (string) ($row['mime_type'] ?: 'image/jpeg'), 'bytes' => $bytes];
    } catch (Throwable $e) {
        return null;
    }
}

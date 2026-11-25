<?php
/**
 * Sistema de Filas Assíncronas (Redis List -> SQL -> Disco).
 * Processa webhooks de PDV, emissão NFC-e, campanhas push, Mercado Pago e cálculos
 * sem travar o checkout nem o carregamento da vitrine.
 */
require_once __DIR__ . '/cache_lib.php';

if (!isset($GLOBALS['PZ_QUEUE_SHUTDOWN_REGISTERED'])) {
    $GLOBALS['PZ_QUEUE_SHUTDOWN_REGISTERED'] = false;
}
if (!isset($GLOBALS['PZ_QUEUE_PENDING_DRAIN'])) {
    $GLOBALS['PZ_QUEUE_PENDING_DRAIN'] = false;
}

function pz_queue_dir()
{
    $dir = __DIR__ . '/data/queue';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function pz_queue_table_ensure($pdo)
{
    static $ready = false;
    if ($ready || !$pdo) {
        return $ready;
    }
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $pdo->exec('CREATE TABLE IF NOT EXISTS pz_async_jobs (
                id VARCHAR(64) PRIMARY KEY,
                tenant_slug VARCHAR(64) NOT NULL DEFAULT \'default\',
                job_type VARCHAR(64) NOT NULL,
                payload_json TEXT NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT \'pending\',
                attempts INTEGER NOT NULL DEFAULT 0,
                last_error TEXT DEFAULT \'\',
                available_at BIGINT NOT NULL,
                created_at VARCHAR(32) NOT NULL
            )');
        } else {
            $pdo->exec('CREATE TABLE IF NOT EXISTS pz_async_jobs (
                id VARCHAR(64) PRIMARY KEY,
                tenant_slug VARCHAR(64) NOT NULL DEFAULT "default",
                job_type VARCHAR(64) NOT NULL,
                payload_json MEDIUMTEXT NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT "pending",
                attempts INT NOT NULL DEFAULT 0,
                last_error TEXT NULL,
                available_at BIGINT NOT NULL,
                created_at VARCHAR(32) NOT NULL,
                INDEX idx_status_avail (status, available_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        }
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function pz_queue_push($type, array $payload = [], $tenant = 'default', $delaySeconds = 0, $scheduleShutdownDrain = true)
{
    $job = [
        'id' => 'job_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)),
        'tenant' => preg_replace('/[^a-z0-9_-]/i', '', (string) $tenant) ?: 'default',
        'type' => (string) $type,
        'payload' => $payload,
        'attempts' => 0,
        'available_at' => time() + max(0, (int) $delaySeconds),
        'created_at' => date('c'),
    ];

    $pushed = false;
    if ($delaySeconds === 0 && PzRedisClient::available()) {
        $res = PzRedisClient::cmd(['RPUSH', 'acheiaki:jobs', json_encode($job, JSON_UNESCAPED_UNICODE)]);
        if ($res !== false) {
            $pushed = true;
        }
    }

    if (!$pushed && function_exists('pz_pdo')) {
        $pdo = pz_pdo();
        if ($pdo && pz_queue_table_ensure($pdo)) {
            try {
                $st = $pdo->prepare('INSERT INTO pz_async_jobs (id, tenant_slug, job_type, payload_json, status, attempts, available_at, created_at) VALUES (?,?,?,?,?,?,?,?)');
                $st->execute([
                    $job['id'],
                    $job['tenant'],
                    $job['type'],
                    json_encode($job['payload'], JSON_UNESCAPED_UNICODE),
                    'pending',
                    0,
                    $job['available_at'],
                    $job['created_at'],
                ]);
                $pushed = true;
            } catch (Throwable $e) {
                $pushed = false;
            }
        }
    }

    if (!$pushed) {
        $file = pz_queue_dir() . '/' . $job['available_at'] . '_' . $job['id'] . '.json';
        @file_put_contents($file, json_encode($job, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    if ($scheduleShutdownDrain && $delaySeconds === 0) {
        pz_queue_register_shutdown_drain();
    }

    return $job['id'];
}

function pz_queue_register_shutdown_drain()
{
    if (getenv('PZ_WORKER_DAEMON') === '1' || ($_ENV['PZ_WORKER_DAEMON'] ?? '') === '1') {
        return;
    }
    $GLOBALS['PZ_QUEUE_PENDING_DRAIN'] = true;
    if ($GLOBALS['PZ_QUEUE_SHUTDOWN_REGISTERED']) {
        return;
    }
    $GLOBALS['PZ_QUEUE_SHUTDOWN_REGISTERED'] = true;
    register_shutdown_function(function () {
        if (empty($GLOBALS['PZ_QUEUE_PENDING_DRAIN'])) {
            return;
        }
        $GLOBALS['PZ_QUEUE_PENDING_DRAIN'] = false;
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        @ignore_user_abort(true);
        pz_queue_run_batch(6, 3);
    });
}

function pz_queue_pop()
{
    if (PzRedisClient::available()) {
        $raw = PzRedisClient::cmd(['LPOP', 'acheiaki:jobs']);
        if (is_string($raw) && $raw !== '') {
            $job = json_decode($raw, true);
            if (is_array($job)) {
                $job['_source'] = 'redis';
                return $job;
            }
        }
    }

    if (function_exists('pz_pdo')) {
        $pdo = pz_pdo();
        if ($pdo && pz_queue_table_ensure($pdo)) {
            try {
                $now = time();
                $st = $pdo->prepare('SELECT id, tenant_slug, job_type, payload_json, attempts, available_at, created_at FROM pz_async_jobs WHERE status = ? AND available_at <= ? ORDER BY available_at ASC LIMIT 1');
                $st->execute(['pending', $now]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $upd = $pdo->prepare('UPDATE pz_async_jobs SET status = ?, attempts = attempts + 1 WHERE id = ? AND status = ?');
                    $upd->execute(['processing', $row['id'], 'pending']);
                    if ($upd->rowCount() > 0) {
                        return [
                            'id' => $row['id'],
                            'tenant' => $row['tenant_slug'],
                            'type' => $row['job_type'],
                            'payload' => json_decode((string) $row['payload_json'], true) ?: [],
                            'attempts' => ((int) $row['attempts']) + 1,
                            'available_at' => (int) $row['available_at'],
                            'created_at' => $row['created_at'],
                            '_source' => 'sql',
                        ];
                    }
                }
            } catch (Throwable $e) {
                // fallback para disco
            }
        }
    }

    $files = @glob(pz_queue_dir() . '/*.json') ?: [];
    sort($files);
    $now = time();
    foreach ($files as $f) {
        $base = basename($f);
        $parts = explode('_', $base, 2);
        $ts = is_numeric($parts[0] ?? '') ? (int) $parts[0] : 0;
        if ($ts > $now) {
            continue;
        }
        $lock = $f . '.lock';
        if (!@rename($f, $lock)) {
            continue;
        }
        $job = json_decode(@file_get_contents($lock) ?: '', true);
        @unlink($lock);
        if (is_array($job)) {
            $job['_source'] = 'disk';
            return $job;
        }
    }

    return null;
}

function pz_queue_finish(array $job, $ok, $err = '')
{
    $source = $job['_source'] ?? 'disk';
    $attempts = (int) ($job['attempts'] ?? 1);
    if ($source === 'sql' && function_exists('pz_pdo')) {
        $pdo = pz_pdo();
        if ($pdo) {
            try {
                if ($ok) {
                    $st = $pdo->prepare('DELETE FROM pz_async_jobs WHERE id = ?');
                    $st->execute([$job['id']]);
                } elseif ($attempts < 3) {
                    $st = $pdo->prepare('UPDATE pz_async_jobs SET status = ?, available_at = ?, last_error = ? WHERE id = ?');
                    $st->execute(['pending', time() + (30 * $attempts), substr((string) $err, 0, 500), $job['id']]);
                } else {
                    $st = $pdo->prepare('UPDATE pz_async_jobs SET status = ?, last_error = ? WHERE id = ?');
                    $st->execute(['failed', substr((string) $err, 0, 500), $job['id']]);
                }
            } catch (Throwable $e) {
                // ignora
            }
        }
        return;
    }

    if (!$ok && $attempts < 3) {
        $job['attempts'] = $attempts + 1;
        $job['last_error'] = (string) $err;
        $job['available_at'] = time() + (30 * $attempts);
        @file_put_contents(
            pz_queue_dir() . '/' . $job['available_at'] . '_' . $job['id'] . '.json',
            json_encode($job, JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}

function pz_queue_executar_job(array $job)
{
    $type = (string) ($job['type'] ?? '');
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
    $tenant = (string) ($job['tenant'] ?? 'default');

    switch ($type) {
        case 'pdv.webhook':
            require_once __DIR__ . '/pdv_lib.php';
            return pz_pdv_entregar_webhook($tenant, $payload['event'] ?? 'order.created', $payload['data'] ?? []);

        case 'fiscal.emit_nfce':
            require_once __DIR__ . '/fiscal_lib.php';
            return pz_fiscal_emitir_pedido($tenant, (string) ($payload['order_id'] ?? ''), !empty($payload['forcar']));

        case 'push.order_status':
        case 'push.campaign':
            require_once __DIR__ . '/push_lib.php';
            return pz_push_processar_job($tenant, $type, $payload);

        case 'whatsapp.order_status':
            require_once __DIR__ . '/whatsapp_lib.php';
            return pz_wpp_notificar_status_pedido(
                $tenant,
                is_array($payload['pedido'] ?? null) ? $payload['pedido'] : [],
                (string) ($payload['status'] ?? 'novo')
            );

        case 'whatsapp.post_delivery_viral':
            require_once __DIR__ . '/whatsapp_lib.php';
            return pz_wpp_notificar_pos_entrega(
                $tenant,
                is_array($payload['pedido'] ?? null) ? $payload['pedido'] : []
            );

        case 'whatsapp.reactivate_customer':
            require_once __DIR__ . '/whatsapp_lib.php';
            return pz_wpp_reativar_cliente_sumido(
                $tenant,
                (string) ($payload['telefone'] ?? ''),
                (string) ($payload['nome'] ?? 'Cliente'),
                (string) ($payload['cupom'] ?? 'VOLTA10')
            );

        case 'alg.recompute':
            if (is_file(__DIR__ . '/territorio_lib.php')) {
                require_once __DIR__ . '/territorio_lib.php';
                pz_cache_del('mkt_lojas_all');
            }
            return ['success' => true];

        case 'mp.sync_subscription':
            require_once __DIR__ . '/saas_lib.php';
            if (!empty($payload['id'])) {
                if (($payload['kind'] ?? '') === 'authorized_payment') {
                    [$codigo, $pag] = pedidozap_mp_request('GET', '/authorized_payments/' . $payload['id']);
                    if ($codigo === 200 && !empty($pag['preapproval_id'])) {
                        return pedidozap_mp_sincronizar_assinatura($pag['preapproval_id']);
                    }
                } else {
                    return pedidozap_mp_sincronizar_assinatura($payload['id']);
                }
            }
            return ['success' => true];

        case 'mp.sync_payment':
            require_once __DIR__ . '/saas_lib.php';
            if (!empty($payload['id'])) {
                return pedidozap_mp_sincronizar_pagamento((string) $payload['id']);
            }
            return ['success' => true];

        default:
            return ['success' => true, 'ignored' => true];
    }
}

function pz_queue_run_batch($maxJobs = 10, $maxSeconds = 8)
{
    $inicio = microtime(true);
    $processados = 0;
    $falhas = 0;

    while ($processados + $falhas < $maxJobs && (microtime(true) - $inicio) < $maxSeconds) {
        $job = pz_queue_pop();
        if (!$job) {
            break;
        }
        try {
            $res = pz_queue_executar_job($job);
            $ok = !is_array($res) || !array_key_exists('success', $res) || !empty($res['success']);
            pz_queue_finish($job, $ok, is_array($res) ? (string) ($res['message'] ?? '') : '');
            if ($ok) {
                $processados++;
            } else {
                $falhas++;
            }
        } catch (Throwable $e) {
            pz_queue_finish($job, false, $e->getMessage());
            $falhas++;
        }
    }

    return ['processados' => $processados, 'falhas' => $falhas];
}

function pz_queue_stats()
{
    $pendentes = 0;
    $driver = 'disk';

    if (PzRedisClient::available()) {
        $driver = 'redis';
        $len = PzRedisClient::cmd(['LLEN', 'acheiaki:jobs']);
        $pendentes += is_numeric($len) ? (int) $len : 0;
    }

    if (function_exists('pz_pdo')) {
        $pdo = pz_pdo();
        if ($pdo && pz_queue_table_ensure($pdo)) {
            $driver = $driver === 'redis' ? 'redis+sql' : 'sql';
            try {
                $pendentes += (int) $pdo->query("SELECT COUNT(*) FROM pz_async_jobs WHERE status = 'pending'")->fetchColumn();
            } catch (Throwable $e) {
                // ignora
            }
        }
    }

    $diskFiles = @glob(pz_queue_dir() . '/*.json') ?: [];
    $pendentes += count($diskFiles);

    return ['driver' => $driver, 'pendentes' => $pendentes];
}

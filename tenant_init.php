<?php
/**
 * Resolve o tenant (estabelecimento) a partir da requisição HTTP.
 */
require_once __DIR__ . '/Database.php';
if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set(getenv('TZ') ?: 'America/Sao_Paulo');
}

function pedidozap_bootstrap_tenant(?array $jsonBody = null): void
{
    $slug = $_GET['tenant'] ?? $_SERVER['HTTP_X_TENANT_SLUG'] ?? '';

    if ($slug === '' && $jsonBody !== null && !empty($jsonBody['tenant'])) {
        $slug = $jsonBody['tenant'];
    }

    if ($slug === '') {
        $slug = pedidozap_slug_do_host();
    }

    $slug = pedidozap_slug_hospedagem_temporaria($slug);

    Database::setTenant($slug !== '' ? $slug : 'default');
}

function pedidozap_host_de_plataforma($host)
{
    $host = strtolower((string) $host);
    return substr($host, -strlen('.up.railway.app')) === '.up.railway.app'
        || substr($host, -strlen('.railway.app')) === '.railway.app';
}

function pedidozap_slug_hospedagem_temporaria($slug)
{
    $slug = strtolower(trim((string) $slug));
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host);
    $primeiro = strtok($host, '.');
    if ($slug !== '' && $slug !== 'default' && $slug === $primeiro && pedidozap_host_de_plataforma($host)) {
        if (!Database::tenantDataExists($slug)) {
            return 'default';
        }
    }
    return $slug;
}

function pedidozap_slug_do_host()
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host);
    if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
        return '';
    }

    $base = '';
    $antT = Database::getTenant();
    Database::setTenant('default');
    $sqlSaas = Database::read('saas_config.json');
    Database::setTenant($antT);
    if (is_array($sqlSaas) && !empty($sqlSaas['dominio_base'])) {
        $base = strtolower(trim((string) $sqlSaas['dominio_base']));
        $base = preg_replace('/^www\./', '', $base);
    }
    if ($base === '') {
        $arquivo = __DIR__ . '/data/saas_config.json';
        if (is_file($arquivo)) {
            $json = json_decode(file_get_contents($arquivo) ?: '', true);
            if (is_array($json)) {
                $base = strtolower(trim((string) ($json['dominio_base'] ?? '')));
                $base = preg_replace('/^www\./', '', $base);
            }
        }
    }

    if (pedidozap_host_de_plataforma($host) || $base === '') {
        return '';
    }

    $sub = '';
    if (strlen($host) > strlen($base) + 1 && substr($host, -strlen('.' . $base)) === '.' . $base) {
        $sub = substr($host, 0, -strlen('.' . $base));
    }

    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $sub)) {
        return '';
    }
    if (in_array($sub, ['www', 'mail', 'ftp', 'cpanel', 'webmail', 'app'], true)) {
        return '';
    }
    return $sub;
}

/**
 * CORS restrito ao próprio domínio da plataforma (impede que clones HTML em outros domínios
 * consumam as APIs do AcheIAki diretamente pelo navegador).
 */
function pz_cors_same_origin(string $methods = 'GET, POST, OPTIONS', string $headers = 'Content-Type'): void
{
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: ' . $methods);
    header('Access-Control-Allow-Headers: ' . $headers);

    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        return;
    }

    $p = @parse_url($origin);
    $scheme = strtolower((string) ($p['scheme'] ?? ''));
    $origHost = strtolower((string) ($p['host'] ?? ''));
    if ($origHost === '') {
        return;
    }

    // App nativo Capacitor / desenvolvimento local
    if (in_array($scheme, ['capacitor', 'ionic'], true) || in_array($origHost, ['localhost', '127.0.0.1'], true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        return;
    }

    $reqHost = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? ''));
    if (strpos($reqHost, ',') !== false) {
        $reqHost = trim(explode(',', $reqHost)[0]);
    }
    $reqHost = preg_replace('/:\d+$/', '', $reqHost);

    $permitido = ($reqHost !== '' && $origHost === $reqHost);
    if (!$permitido) {
        $antT = Database::getTenant();
        Database::setTenant('default');
        $saas = Database::read('saas_config.json');
        Database::setTenant($antT);
        $domBase = strtolower(trim((string) (is_array($saas) ? ($saas['dominio_base'] ?? '') : '')));
        $domBase = preg_replace('/^www\./', '', $domBase);
        if ($domBase !== '' && ($origHost === $domBase || substr($origHost, -strlen('.' . $domBase)) === '.' . $domBase)) {
            $permitido = true;
        }
    }

    if ($permitido) {
        header('Access-Control-Allow-Origin: ' . $origin);
    }
}

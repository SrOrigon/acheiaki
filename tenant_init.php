<?php
/**
 * Resolve o tenant (estabelecimento) a partir da requisição HTTP.
 */
require_once __DIR__ . '/Database.php';

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
    return substr($host, -strlen('.hostingersite.com')) === '.hostingersite.com'
        || substr($host, -strlen('.hostinger.com')) === '.hostinger.com'
        || substr($host, -strlen('.up.railway.app')) === '.up.railway.app'
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
    $arquivo = __DIR__ . '/data/saas_config.json';
    if (is_file($arquivo)) {
        $json = json_decode(file_get_contents($arquivo) ?: '', true);
        if (is_array($json)) {
            $base = strtolower(trim((string) ($json['dominio_base'] ?? '')));
            $base = preg_replace('/^www\./', '', $base);
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

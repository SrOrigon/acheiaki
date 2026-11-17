<?php
/**
 * Gera sitemap.xml dinâmico para o Google Search Console com base no domínio atual
 * e inclui automaticamente todas as páginas públicas + todas as lojas ativas (/loja/{slug}/).
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/saas_lib.php';

function aki_seo_base_url(): string
{
    $rawHost = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '';
    if (strpos($rawHost, ',') !== false) {
        $rawHost = trim(explode(',', $rawHost)[0]);
    }
    $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', trim((string) $rawHost));
    if ($host === '') {
        $saas = pedidozap_saas_config();
        $host = !empty($saas['dominio_base']) ? $saas['dominio_base'] : 'acheiaki.com.br';
    }

    $isLocal = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/i', $host) === 1;
    $https = (!$isLocal)
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    return ($https ? 'https' : 'http') . '://' . $host;
}

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: all');

$base = aki_seo_base_url();
$hoje = gmdate('Y-m-d');

$urls = [
    ['loc' => $base . '/', 'changefreq' => 'daily', 'priority' => '1.0', 'lastmod' => $hoje],
    ['loc' => $base . '/marketplace.html', 'changefreq' => 'daily', 'priority' => '1.0', 'lastmod' => $hoje],
    ['loc' => $base . '/cadastro.html', 'changefreq' => 'weekly', 'priority' => '0.9', 'lastmod' => $hoje],
    ['loc' => $base . '/hamburgueres.html', 'changefreq' => 'weekly', 'priority' => '0.8', 'lastmod' => $hoje],
    ['loc' => $base . '/porcoes.html', 'changefreq' => 'weekly', 'priority' => '0.7', 'lastmod' => $hoje],
    ['loc' => $base . '/bebidas.html', 'changefreq' => 'weekly', 'priority' => '0.7', 'lastmod' => $hoje],
    ['loc' => $base . '/combos.html', 'changefreq' => 'weekly', 'priority' => '0.8', 'lastmod' => $hoje],
    ['loc' => $base . '/acai.html', 'changefreq' => 'weekly', 'priority' => '0.8', 'lastmod' => $hoje],
    ['loc' => $base . '/termos.html', 'changefreq' => 'monthly', 'priority' => '0.4', 'lastmod' => $hoje],
    ['loc' => $base . '/privacidade.html', 'changefreq' => 'monthly', 'priority' => '0.4', 'lastmod' => $hoje],
];

$registro = pedidozap_ler_registro();
if (is_array($registro)) {
    foreach ($registro as $slug => $meta) {
        if (!is_array($meta)) {
            continue;
        }
        $slugLimpo = strtolower(trim((string) ($meta['slug'] ?? $slug)));
        if ($slugLimpo === '' || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slugLimpo)) {
            continue;
        }
        $efetivo = pedidozap_status_efetivo($meta);
        if (($efetivo['status'] ?? 'ativo') === 'bloqueado') {
            continue;
        }
        $updated = (string) ($meta['updated_at'] ?? $meta['criado_em'] ?? '');
        $lastmod = preg_match('/^\d{4}-\d{2}-\d{2}/', $updated) ? substr($updated, 0, 10) : $hoje;
        $urls[] = [
            'loc' => $base . '/loja/' . rawurlencode($slugLimpo) . '/',
            'changefreq' => 'daily',
            'priority' => '0.9',
            'lastmod' => $lastmod,
        ];
    }
}

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach ($urls as $u) {
    $loc = htmlspecialchars($u['loc'], ENT_XML1, 'UTF-8');
    echo "  <url>\n";
    echo "    <loc>{$loc}</loc>\n";
    echo "    <lastmod>{$u['lastmod']}</lastmod>\n";
    echo "    <changefreq>{$u['changefreq']}</changefreq>\n";
    echo "    <priority>{$u['priority']}</priority>\n";
    echo "  </url>\n";
}
echo "</urlset>\n";

<?php
/**
 * Router de produção para PHP built-in server (Railway / Docker).
 * Replica as regras de segurança e rewrite multi-tenant do .htaccess.
 */
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$decodedPath = rawurldecode($uriPath);

// Cabeçalhos básicos de segurança em todas as respostas
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(self), microphone=(self)');

// Bloqueia acesso direto a arquivos sensíveis, dados internos e configurações
$bloqueados = [
    '#^/data(/|$)#i',
    '#^/\.#',
    '#^/(router\.php|auth_config\.php|verificar_sistema\.php|go_live_smoke\.php|Dockerfile|Procfile|nixpacks\.toml|composer\.(json|lock))$#i',
    '#\.(md|log|sql|bak|env)$#i',
];
foreach ($bloqueados as $re) {
    if (preg_match($re, $decodedPath)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Acesso negado.';
        return true;
    }
}

// Sitemap e Robots dinâmicos para Google Search Console (funciona em qualquer domínio)
if ($decodedPath === '/sitemap.xml') {
    require __DIR__ . '/sitemap.php';
    return true;
}
if ($decodedPath === '/robots.txt') {
    require __DIR__ . '/robots.php';
    return true;
}

// Helper leve para injetar meta tag do Google Search Console caso configurada no Painel Master
$akiServeHtmlWithSeo = static function (string $filePath) {
    $html = @file_get_contents($filePath);
    if ($html === false) {
        http_response_code(404);
        echo 'Página não encontrada.';
        return;
    }
    try {
        require_once __DIR__ . '/tenant_init.php';
        require_once __DIR__ . '/saas_lib.php';
        $saas = pedidozap_saas_config();
        $gsc = trim((string) ($saas['google_site_verification'] ?? ''));
        if ($gsc !== '' && stripos($html, 'google-site-verification') === false) {
            $meta = '<meta name="google-site-verification" content="' . htmlspecialchars($gsc, ENT_QUOTES, 'UTF-8') . '">' . "\n</head>";
            $html = preg_replace('/<\/head>/i', $meta, $html, 1) ?? $html;
        }
    } catch (Throwable $e) {
        // segue normalmente sem bloquear o HTML
    }
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
};

// Raiz redireciona para o Marketplace AcheIAki
if ($decodedPath === '/' || $decodedPath === '') {
    $akiServeHtmlWithSeo(__DIR__ . '/marketplace.html');
    return true;
}
if ($decodedPath === '/marketplace.html' || $decodedPath === '/cadastro.html') {
    $akiServeHtmlWithSeo(__DIR__ . $decodedPath);
    return true;
}

// Otimização automática para WebP/AVIF e CDN (Cloudflare R2 / S3) nas imagens pesadas de assets/images e assets/lojas
if (preg_match('#^(?:/loja/[a-zA-Z0-9_-]+)?/assets/images/([a-zA-Z0-9._-]+\.(?:png|jpg|jpeg))$#i', $decodedPath, $mAsset)) {
    $diskAsset = __DIR__ . '/assets/images/' . $mAsset[1];
    if (is_file($diskAsset)) {
        require_once __DIR__ . '/media_cdn_lib.php';
        $cdnUrl = pz_media_cdn_url('assets/images/' . $mAsset[1]);
        if ($cdnUrl !== ('assets/images/' . $mAsset[1])) {
            header('Location: ' . $cdnUrl, true, 302);
            return true;
        }
        $acceptHeader = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (strpos($acceptHeader, 'image/webp') !== false || strpos($acceptHeader, 'image/avif') !== false || !empty($_GET['w']) || !empty($_GET['fmt'])) {
            $_GET['t'] = 'assets';
            $_GET['f'] = $mAsset[1];
            require __DIR__ . '/media.php';
            return true;
        }
    }
}

// Fallback de imagens de produtos salvas no SQL (Railway ephemeral disk) ou WebP sob demanda
if (preg_match('#^/assets/lojas/([a-zA-Z0-9_-]+)/([a-zA-Z0-9._-]+\.(?:jpg|jpeg|png|webp|gif))$#i', $decodedPath, $mImg)) {
    $disk = __DIR__ . $decodedPath;
    $acceptHeader = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (!is_file($disk) || strpos($acceptHeader, 'image/webp') !== false || !empty($_GET['w']) || !empty($_GET['fmt'])) {
        $_GET['t'] = $mImg[1];
        $_GET['f'] = $mImg[2];
        require __DIR__ . '/media.php';
        return true;
    }
}

// Helper de entrega de ativos estáticos com ETag + Cache-Control (reduz banda/egress no Railway)
$akiServeStaticAsset = static function (string $filePath) {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimes = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'json' => 'application/json; charset=utf-8',
    ];
    if (!isset($mimes[$ext])) {
        return false;
    }
    $mtime = @filemtime($filePath) ?: 0;
    $size = @filesize($filePath) ?: 0;
    $etag = '"' . sha1($filePath . '|' . $mtime . '|' . $size) . '"';
    $isMedia = in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'ico', 'woff2', 'woff'], true);
    $maxAge = $isMedia ? 2592000 : (!empty($_GET['v']) ? 86400 : 3600);
    header('Content-Type: ' . $mimes[$ext]);
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=' . $maxAge);
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        return true;
    }
    readfile($filePath);
    return true;
};

// Multi-tenant: /loja/{slug}/(css|js|assets)/...
if (preg_match('#^/loja/([a-zA-Z0-9_-]+)/(css|js|assets)/(.*)$#i', $decodedPath, $m)) {
    $rel = '/' . $m[2] . '/' . $m[3];
    $alvo = realpath(__DIR__ . $rel);
    $raiz = realpath(__DIR__);
    if ($alvo && $raiz && strpos($alvo, $raiz) === 0 && is_file($alvo)) {
        if ($akiServeStaticAsset($alvo)) {
            return true;
        }
        return false;
    }
    if (preg_match('#^/assets/lojas/([a-zA-Z0-9_-]+)/([a-zA-Z0-9._-]+)$#i', $rel, $mSql)) {
        $_GET['t'] = $mSql[1];
        $_GET['f'] = $mSql[2];
        require __DIR__ . '/media.php';
        return true;
    }
    http_response_code(404);
    return true;
}

// Multi-tenant: /loja/{slug}/{arquivo}.php
if (preg_match('#^/loja/([a-zA-Z0-9_-]+)/([A-Za-z0-9_-]+\.php)$#i', $decodedPath, $m)) {
    $phpFile = __DIR__ . '/' . $m[2];
    if (is_file($phpFile) && basename($phpFile) !== 'router.php' && basename($phpFile) !== 'auth_config.php') {
        $_GET['tenant'] = $m[1];
        require $phpFile;
        return true;
    }
    http_response_code(404);
    return true;
}

// Multi-tenant: /loja/{slug}/ ou /loja/{slug}/{arquivo}.html
if (preg_match('#^/loja/([a-zA-Z0-9_-]+)(?:/([A-Za-z0-9_-]+\.html)?)?$#i', $decodedPath, $m)) {
    $htmlName = !empty($m[2]) ? $m[2] : 'index.html';
    $htmlFile = __DIR__ . '/' . $htmlName;
    if (is_file($htmlFile)) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($htmlFile);
        return true;
    }
    http_response_code(404);
    return true;
}

// Arquivo estático ou PHP direto na raiz
$target = realpath(__DIR__ . $decodedPath);
$root = realpath(__DIR__);
if ($target && $root && strpos($target, $root) === 0 && is_file($target)) {
    if ($akiServeStaticAsset($target)) {
        return true;
    }
    return false; // deixa o servidor PHP nativo servir normalmente
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Página não encontrada.';
return true;

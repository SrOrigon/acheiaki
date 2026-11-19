<?php
/**
 * Credenciais do lojista por tenant, com senha hasheada.
 * O arquivo auth.json fica em data/ (já bloqueado por .htaccess).
 */

function pedidozap_auth_file()
{
    $dir = rtrim(Database::getTenantDirectory(), '/\\');
    return $dir . DIRECTORY_SEPARATOR . 'auth.json';
}

function pedidozap_ler_auth()
{
    $data = Database::readStrict('auth.json');
    if (is_array($data) && !empty($data['user']) && !empty($data['pass_hash'])) {
        return $data;
    }

    $path = pedidozap_auth_file();
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        $fileData = json_decode($raw ?: '', true);
        if (is_array($fileData) && !empty($fileData['user']) && !empty($fileData['pass_hash'])) {
            return $fileData;
        }
    }

    if (Database::getTenant() === 'default' && is_file(__DIR__ . '/auth_config.php')) {
        $legacy = require __DIR__ . '/auth_config.php';
        if (is_array($legacy) && !empty($legacy['USER']) && isset($legacy['PASS'])) {
            return [
                'user' => (string) $legacy['USER'],
                'pass_hash' => '',
                'pass_plain_fallback' => (string) $legacy['PASS'],
            ];
        }
    }

    return null;
}

function pedidozap_senha_confere($pass, $hash)
{
    $pass = (string) $pass;
    $hash = (string) $hash;
    if ($hash === '') {
        return false;
    }
    return password_verify($pass, $hash);
}

function pedidozap_salvar_credenciais($user, $pass)
{
    $user = trim((string) $user);
    $pass = (string) $pass;
    if ($user === '' || $pass === '') {
        return false;
    }

    $payload = [
        'user' => $user,
        'pass_hash' => password_hash($pass, PASSWORD_DEFAULT),
        'updated_at' => date('c'),
    ];

    return Database::write('auth.json', $payload);
}

function pedidozap_verificar_credenciais($user, $pass)
{
    $auth = pedidozap_ler_auth();
    if (!$auth) {
        return false;
    }

    $user = (string) $user;
    $pass = (string) $pass;
    if (strcasecmp((string) $auth['user'], $user) !== 0) {
        return false;
    }

    if (!empty($auth['pass_hash'])) {
        return pedidozap_senha_confere($pass, $auth['pass_hash']);
    }

    if (isset($auth['pass_plain_fallback']) && hash_equals($auth['pass_plain_fallback'], $pass)) {
        pedidozap_salvar_credenciais($user, $pass);
        return true;
    }

    return false;
}

function pedidozap_usuario_atual()
{
    $auth = pedidozap_ler_auth();
    return $auth['user'] ?? '';
}

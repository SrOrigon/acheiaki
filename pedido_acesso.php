<?php
/**
 * Quem pode ver/alterar um pedido sem login de lojista:
 * - o cliente, com o código secreto do link de rastreio;
 * - o entregador, com a chave do link do entregador da loja.
 */
require_once __DIR__ . '/saas_lib.php';

function pz_arquivo_entregador()
{
    return rtrim(Database::getTenantDirectory(), '/\\') . DIRECTORY_SEPARATOR . 'entregador.json';
}

function pz_chave_entregador($renovar = false)
{
    if (!$renovar) {
        $data = Database::readStrict('entregador.json');
        if (is_array($data) && !empty($data['chave'])) {
            return (string) $data['chave'];
        }
        $path = pz_arquivo_entregador();
        if (is_file($path)) {
            $fileData = json_decode(@file_get_contents($path) ?: '', true);
            if (is_array($fileData) && !empty($fileData['chave'])) {
                Database::write('entregador.json', $fileData);
                return (string) $fileData['chave'];
            }
        }
    }
    $chave = bin2hex(random_bytes(12));
    Database::write('entregador.json', ['chave' => $chave, 'criado_em' => date('c')]);
    return $chave;
}

function pz_entregador_ok($chave)
{
    $chave = (string) $chave;
    if ($chave === '') {
        return false;
    }
    $data = Database::readStrict('entregador.json');
    if (!is_array($data) || empty($data['chave'])) {
        $path = pz_arquivo_entregador();
        if (!is_file($path)) {
            return false;
        }
    }
    return hash_equals(pz_chave_entregador(), $chave);
}

function pz_arquivo_cozinha()
{
    return rtrim(Database::getTenantDirectory(), '/\\') . DIRECTORY_SEPARATOR . 'cozinha.json';
}

function pz_chave_cozinha($renovar = false)
{
    if (!$renovar) {
        $data = Database::readStrict('cozinha.json');
        if (is_array($data) && !empty($data['chave'])) {
            return (string) $data['chave'];
        }
        $path = pz_arquivo_cozinha();
        if (is_file($path)) {
            $fileData = json_decode(@file_get_contents($path) ?: '', true);
            if (is_array($fileData) && !empty($fileData['chave'])) {
                Database::write('cozinha.json', $fileData);
                return (string) $fileData['chave'];
            }
        }
    }
    $chave = bin2hex(random_bytes(12));
    Database::write('cozinha.json', ['chave' => $chave, 'criado_em' => date('c')]);
    return $chave;
}

function pz_cozinha_ok($chave)
{
    $chave = (string) $chave;
    if ($chave === '') {
        return false;
    }
    $data = Database::readStrict('cozinha.json');
    if (!is_array($data) || empty($data['chave'])) {
        $path = pz_arquivo_cozinha();
        if (!is_file($path)) {
            return false;
        }
    }
    return hash_equals(pz_chave_cozinha(), $chave);
}

function pz_admin_ok()
{
    $user = (string) ($_GET['user'] ?? '');
    $pass = (string) ($_GET['pass'] ?? '');
    return $pass !== '' && pedidozap_autorizado($user, $pass) !== false;
}

function pz_pode_ver_pedido(array $pedido, $codigo, $chaveEntregador)
{
    if (pz_entregador_ok($chaveEntregador) || pz_admin_ok()) {
        return true;
    }
    if (empty($pedido['codigo'])) {
        return false;
    }
    $codigo = (string) $codigo;
    return $codigo !== '' && hash_equals((string) $pedido['codigo'], $codigo);
}

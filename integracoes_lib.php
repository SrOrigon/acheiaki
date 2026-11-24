<?php
/**
 * Chaves de APIs externas (somente servidor). Nunca expor a chave do Google no navegador.
 */

function pedidozap_integracoes_config()
{
    $data = [];
    if (class_exists('Database')) {
        $anterior = Database::getTenant();
        Database::setTenant('default');
        $sqlData = Database::readStrict('integracoes.json');
        Database::setTenant($anterior);
        if (is_array($sqlData) && !empty($sqlData)) {
            $data = $sqlData;
        }
    }
    if (empty($data)) {
        $path = __DIR__ . '/data/integracoes.json';
        if (is_file($path)) {
            $fileData = json_decode(@file_get_contents($path) ?: '', true);
            if (is_array($fileData)) {
                $data = $fileData;
            }
        }
    }
    $cfg = array_merge([
        'google_maps_api_key' => '',
        'google_maps_habilitado' => false,
        'google_oauth_client_id' => '',
        'cnpj_habilitado' => true,
        'ia_provider' => 'local',
        'ia_api_key' => '',
        'wpp_provider' => 'evolution',
        'wpp_api_url' => '',
        'wpp_api_key' => '',
        'wpp_webhook_secret' => '',
        'wpp_phone_id' => '',
    ], $data);

    if (function_exists('pz_db_env_val')) {
        $gClient = pz_db_env_val('ACHEIAKI_GOOGLE_CLIENT_ID');
        if ($gClient !== '') {
            $cfg['google_oauth_client_id'] = $gClient;
        }
        $gMaps = pz_db_env_val('ACHEIAKI_GOOGLE_MAPS_KEY');
        if ($gMaps !== '') {
            $cfg['google_maps_api_key'] = $gMaps;
            $cfg['google_maps_habilitado'] = true;
        }
        $iaKey = pz_db_env_val('ACHEIAKI_IA_API_KEY');
        if ($iaKey !== '') {
            $cfg['ia_api_key'] = $iaKey;
        }
        $wppProv = strtolower(pz_db_env_val('ACHEIAKI_WPP_PROVIDER'));
        if (in_array($wppProv, ['evolution', 'meta', 'zapi'], true)) {
            $cfg['wpp_provider'] = $wppProv;
        }
        $wppUrl = pz_db_env_val('ACHEIAKI_WPP_API_URL');
        if ($wppUrl !== '') {
            $cfg['wpp_api_url'] = rtrim($wppUrl, '/');
        }
        $wppKey = pz_db_env_val('ACHEIAKI_WPP_API_KEY');
        if ($wppKey !== '') {
            $cfg['wpp_api_key'] = $wppKey;
        }
        $wppSecret = pz_db_env_val('ACHEIAKI_WPP_WEBHOOK_SECRET');
        if ($wppSecret !== '') {
            $cfg['wpp_webhook_secret'] = $wppSecret;
        }
    }
    return $cfg;
}

function pedidozap_integracoes_publicas()
{
    $cfg = pedidozap_integracoes_config();
    $temChaveGoogle = trim((string) ($cfg['google_maps_api_key'] ?? '')) !== '';
    $temChaveIa = trim((string) ($cfg['ia_api_key'] ?? '')) !== '';
    $providerIa = $cfg['ia_provider'] ?? 'local';
    $geminiAtivo = $providerIa === 'gemini' && $temChaveIa;
    $wppUrl = trim((string) ($cfg['wpp_api_url'] ?? ''));
    $wppKey = trim((string) ($cfg['wpp_api_key'] ?? ''));
    $wppProv = strtolower(trim((string) ($cfg['wpp_provider'] ?? 'evolution')));
    $wppConfigurado = $wppProv === 'meta'
        ? ($wppKey !== '' && trim((string) ($cfg['wpp_phone_id'] ?? '')) !== '')
        : ($wppUrl !== '' && $wppKey !== '');
    $baseUrl = function_exists('pedidozap_base_url') ? pedidozap_base_url() : '';

    return [
        'google_maps_configurado' => $temChaveGoogle,
        'google_maps_habilitado' => $temChaveGoogle && (!empty($cfg['google_maps_habilitado']) || $temChaveGoogle),
        'google_oauth_client_id' => trim((string) ($cfg['google_oauth_client_id'] ?? '')),
        'cnpj_habilitado' => !empty($cfg['cnpj_habilitado']),
        'ia_provider' => $providerIa,
        'ia_api_configurado' => $temChaveIa,
        'gemini_configurado' => $geminiAtivo,
        'wpp_provider' => in_array($wppProv, ['evolution', 'meta', 'zapi'], true) ? $wppProv : 'evolution',
        'wpp_api_url' => $wppUrl,
        'wpp_api_key_configurada' => $wppKey !== '',
        'wpp_webhook_secret_configurado' => trim((string) ($cfg['wpp_webhook_secret'] ?? '')) !== '',
        'wpp_phone_id' => trim((string) ($cfg['wpp_phone_id'] ?? '')),
        'wpp_configurado' => $wppConfigurado,
        'wpp_webhook_url' => ($baseUrl !== '' ? $baseUrl : '') . '/whatsapp_webhook.php',
    ];
}

function pedidozap_salvar_integracoes(array $patch)
{
    $atual = pedidozap_integracoes_config();
    if (array_key_exists('google_maps_api_key', $patch)) {
        $atual['google_maps_api_key'] = trim((string) $patch['google_maps_api_key']);
    }
    if (array_key_exists('google_maps_habilitado', $patch)) {
        $atual['google_maps_habilitado'] = !empty($patch['google_maps_habilitado']);
    }
    if (array_key_exists('cnpj_habilitado', $patch)) {
        $atual['cnpj_habilitado'] = !empty($patch['cnpj_habilitado']);
    }
    if (array_key_exists('ia_provider', $patch)) {
        $prov = strtolower(trim((string) $patch['ia_provider']));
        $atual['ia_provider'] = in_array($prov, ['local', 'gemini'], true) ? $prov : 'local';
    }
    if (array_key_exists('ia_api_key', $patch)) {
        $k = trim((string) $patch['ia_api_key']);
        if ($k !== '') {
            $atual['ia_api_key'] = $k;
        }
    }
    if (!empty($patch['ia_remover_key'])) {
        $atual['ia_api_key'] = '';
        $atual['ia_provider'] = 'local';
    }
    if (array_key_exists('wpp_provider', $patch)) {
        $wp = strtolower(trim((string) $patch['wpp_provider']));
        if (in_array($wp, ['evolution', 'meta', 'zapi'], true)) {
            $atual['wpp_provider'] = $wp;
        }
    }
    if (array_key_exists('wpp_api_url', $patch)) {
        $wu = rtrim(trim((string) $patch['wpp_api_url']), '/');
        if ($wu === '' || preg_match('#^https?://#i', $wu)) {
            $atual['wpp_api_url'] = $wu;
        }
    }
    if (array_key_exists('wpp_api_key', $patch)) {
        $wk = trim((string) $patch['wpp_api_key']);
        if ($wk !== '') {
            $atual['wpp_api_key'] = $wk;
        }
    }
    if (array_key_exists('wpp_webhook_secret', $patch)) {
        $ws = trim((string) $patch['wpp_webhook_secret']);
        if ($ws !== '') {
            $atual['wpp_webhook_secret'] = $ws;
        }
    }
    if (array_key_exists('wpp_phone_id', $patch)) {
        $atual['wpp_phone_id'] = preg_replace('/[^a-zA-Z0-9._-]/', '', trim((string) $patch['wpp_phone_id']));
    }
    if (class_exists('Database')) {
        $anterior = Database::getTenant();
        Database::setTenant('default');
        $ok = Database::write('integracoes.json', $atual);
        Database::setTenant($anterior);
        return $ok;
    }
    $json = json_encode($atual, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return file_put_contents(__DIR__ . '/data/integracoes.json', $json, LOCK_EX) !== false;
}

function pedidozap_http_json($url, $headers = [], $timeout = 10)
{
    $headerLines = array_merge([
        'Accept: application/json',
        'User-Agent: AcheIAkiPlatform/1.0',
    ], $headers);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'header' => implode("\r\n", $headerLines) . "\r\n",
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return null;
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

function pedidozap_somente_digitos($s)
{
    return preg_replace('/\D/', '', (string) $s);
}

function pedidozap_validar_cnpj($cnpj)
{
    $cnpj = pedidozap_somente_digitos($cnpj);
    if (strlen($cnpj) !== 14) {
        return false;
    }
    if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
        return false;
    }
    $calc = function ($base, $pesos) {
        $soma = 0;
        foreach ($pesos as $i => $p) {
            $soma += (int) $base[$i] * $p;
        }
        $resto = $soma % 11;
        return $resto < 2 ? 0 : 11 - $resto;
    };
    $d1 = $calc($cnpj, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
    $d2 = $calc($cnpj, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
    return ((int) $cnpj[12] === $d1) && ((int) $cnpj[13] === $d2);
}

function pedidozap_consultar_cnpj($cnpj)
{
    $cfg = pedidozap_integracoes_config();
    if (empty($cfg['cnpj_habilitado'])) {
        return ['success' => false, 'message' => 'Consulta de CNPJ desativada.'];
    }
    $cnpj = pedidozap_somente_digitos($cnpj);
    if (!pedidozap_validar_cnpj($cnpj)) {
        return ['success' => false, 'message' => 'CNPJ inválido. Confira os números.'];
    }
    $url = 'https://brasilapi.com.br/api/cnpj/v1/' . $cnpj;
    $data = pedidozap_http_json($url, [], 12);
    if (!$data || empty($data['cnpj'])) {
        return ['success' => false, 'message' => 'CNPJ não encontrado na base pública.'];
    }
    $situacao = strtoupper((string) ($data['descricao_situacao_cadastral'] ?? ''));
    if ($situacao !== '' && $situacao !== 'ATIVA') {
        return [
            'success' => false,
            'message' => 'Empresa com situação cadastral: ' . $situacao . '. Verifique antes de cadastrar.',
            'situacao' => $situacao,
        ];
    }
    $fone = pedidozap_somente_digitos($data['ddd_telefone_1'] ?? '');
    if (strlen($fone) < 10) {
        $fone = pedidozap_somente_digitos(($data['ddd'] ?? '') . ($data['telefone_1'] ?? ''));
    }
    if (strlen($fone) >= 10 && strlen($fone) <= 11) {
        $fone = '55' . $fone;
    }
    return [
        'success' => true,
        'empresa' => [
            'cnpj' => $cnpj,
            'razao_social' => $data['razao_social'] ?? '',
            'nome_fantasia' => $data['nome_fantasia'] ?? ($data['razao_social'] ?? ''),
            'cnae' => (int) ($data['cnae_fiscal'] ?? 0),
            'cnae_descricao' => $data['cnae_fiscal_descricao'] ?? '',
            'porte' => $data['porte'] ?? '',
            'municipio' => $data['municipio'] ?? '',
            'uf' => $data['uf'] ?? '',
            'telefone' => $fone,
            'endereco' => [
                'rua' => trim(($data['descricao_tipo_de_logradouro'] ?? '') . ' ' . ($data['logradouro'] ?? '')),
                'numero' => (string) ($data['numero'] ?? ''),
                'bairro' => $data['bairro'] ?? '',
                'cidade' => $data['municipio'] ?? '',
                'estado' => $data['uf'] ?? '',
                'cep' => pedidozap_somente_digitos($data['cep'] ?? ''),
            ],
        ],
    ];
}

function pedidozap_google_maps_chave()
{
    $cfg = pedidozap_integracoes_config();
    $key = trim((string) ($cfg['google_maps_api_key'] ?? ''));
    if ($key === '') {
        return '';
    }
    if (array_key_exists('google_maps_habilitado', $cfg) && empty($cfg['google_maps_habilitado'])) {
        return '';
    }
    return $key;
}

function pedidozap_buscar_comercios_google($texto, $lat = null, $lng = null)
{
    $key = pedidozap_google_maps_chave();
    if ($key === '') {
        return [
            'success' => false,
            'message' => 'Google Maps não configurado. Coloque a chave em data/integracoes.json e ative google_maps_habilitado.',
            'codigo' => 'maps_nao_configurado',
        ];
    }
    $query = trim((string) $texto);
    if (strlen($query) < 3) {
        return ['success' => false, 'message' => 'Digite o nome do comércio e a cidade.'];
    }
    $params = [
        'query' => $query,
        'language' => 'pt-BR',
        'region' => 'br',
        'key' => $key,
    ];
    if ($lat !== null && $lng !== null) {
        $params['location'] = round((float) $lat, 6) . ',' . round((float) $lng, 6);
        $params['radius'] = 25000;
    }
    $url = 'https://maps.googleapis.com/maps/api/place/textsearch/json?' . http_build_query($params);
    $data = pedidozap_http_json($url, [], 12);
    if (!$data || ($data['status'] ?? '') !== 'OK') {
        $st = $data['status'] ?? 'ERRO';
        $msg = $data['error_message'] ?? 'Não foi possível buscar comércios.';
        if ($st === 'ZERO_RESULTS') {
            $msg = 'Nenhum comércio encontrado. Tente incluir o bairro ou cidade.';
        }
        return ['success' => false, 'message' => $msg, 'status_google' => $st];
    }
    $resultados = [];
    foreach (array_slice($data['results'] ?? [], 0, 8) as $item) {
        $resultados[] = [
            'place_id' => $item['place_id'] ?? '',
            'nome' => $item['name'] ?? '',
            'endereco' => $item['formatted_address'] ?? '',
            'tipos' => $item['types'] ?? [],
            'nota' => $item['rating'] ?? null,
            'avaliacoes' => $item['user_ratings_total'] ?? null,
            'aberto_agora' => $item['opening_hours']['open_now'] ?? null,
            'lat' => $item['geometry']['location']['lat'] ?? null,
            'lng' => $item['geometry']['location']['lng'] ?? null,
        ];
    }
    return ['success' => true, 'resultados' => $resultados];
}

function pedidozap_detalhar_comercio_google($placeId)
{
    $key = pedidozap_google_maps_chave();
    if ($key === '' || trim((string) $placeId) === '') {
        return ['success' => false, 'message' => 'Google Maps não configurado ou lugar inválido.'];
    }
    $params = [
        'place_id' => $placeId,
        'fields' => 'name,formatted_address,formatted_phone_number,international_phone_number,opening_hours,types,geometry,website,rating,user_ratings_total,business_status,url',
        'language' => 'pt-BR',
        'key' => $key,
    ];
    $url = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query($params);
    $data = pedidozap_http_json($url, [], 12);
    if (!$data || ($data['status'] ?? '') !== 'OK' || empty($data['result'])) {
        return ['success' => false, 'message' => $data['error_message'] ?? 'Detalhes não encontrados.'];
    }
    $r = $data['result'];
    $fone = pedidozap_somente_digitos($r['international_phone_number'] ?? ($r['formatted_phone_number'] ?? ''));
    return [
        'success' => true,
        'comercio' => [
            'place_id' => $placeId,
            'nome' => $r['name'] ?? '',
            'endereco_formatado' => $r['formatted_address'] ?? '',
            'telefone' => $fone,
            'website' => $r['website'] ?? '',
            'tipos' => $r['types'] ?? [],
            'nota' => $r['rating'] ?? null,
            'avaliacoes_total' => $r['user_ratings_total'] ?? null,
            'status_negocio' => $r['business_status'] ?? '',
            'maps_url' => $r['url'] ?? '',
            'lat' => $r['geometry']['location']['lat'] ?? null,
            'lng' => $r['geometry']['location']['lng'] ?? null,
            'horarios_texto' => $r['opening_hours']['weekday_text'] ?? [],
        ],
    ];
}

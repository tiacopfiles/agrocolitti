<?php

/**
 * SicoobAuthService (procedural, no padrao do projeto).
 *
 * Decide o modo de autenticacao pelo SICOOB_AMBIENTE:
 *  - sandbox  : Bearer fixo do portal + header client_id. Sem certificado.
 *  - producao : OAuth client_credentials em SICOOB_AUTH_URL com certificado
 *               mTLS. Token dura ~300s -> cacheado por ~280s em arquivo temp.
 *
 * NUNCA loga a senha do certificado nem o access_token.
 */

require_once __DIR__ . '/sicoob_config.php';

if (!function_exists('sicoobAuthCacheFile')) {
    function sicoobAuthCacheFile(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'sicoob_token_cache.json';
    }
}

if (!function_exists('sicoobAuthTokenProducao')) {
    /**
     * Gera (ou reaproveita do cache) o access_token de producao via mTLS.
     */
    function sicoobAuthTokenProducao(array $config): string
    {
        // Os escopos entram na chave do cache: se SICOOB_SCOPES mudar (ex.: liberar
        // boletos_baixa), um token antigo mais restrito NAO sera reaproveitado.
        $escopos = ($config['scopes'] ?? '') !== '' ? $config['scopes'] : 'boletos_inclusao boletos_consulta';

        $cacheFile = sicoobAuthCacheFile();
        if (is_file($cacheFile)) {
            $cache = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cache)
                && ($cache['client_id'] ?? '') === $config['client_id']
                && ($cache['scopes'] ?? '') === $escopos
                && (int) ($cache['expira_em'] ?? 0) > time()
                && !empty($cache['access_token'])
            ) {
                return (string) $cache['access_token'];
            }
        }

        $certPath = $config['cert_path'];
        $keyPath  = $config['cert_key_path'];
        if ($certPath === '' || !is_file($certPath)) {
            throw new RuntimeException('SICOOB: certificado (.pem) nao encontrado em ' . $certPath . '.');
        }
        if ($keyPath === '' || !is_file($keyPath)) {
            throw new RuntimeException('SICOOB: chave do certificado (.key) nao encontrada em ' . $keyPath . '.');
        }

        $ch = curl_init($config['auth_url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'client_credentials',
                'client_id'  => $config['client_id'],
                // Escopos do app no portal Sicoob (validado: "boletos_inclusao boletos_consulta").
                // Para cancelar boletos e preciso liberar e incluir "boletos_baixa" aqui.
                'scope'      => $escopos,
            ]),
            CURLOPT_SSLCERT        => $certPath,
            CURLOPT_SSLKEY         => $keyPath,
            CURLOPT_SSLKEYPASSWD   => $config['cert_password'],
        ]);
        $body  = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code >= 400) {
            // Nao expor token nem senha; apenas o codigo HTTP.
            throw new RuntimeException('SICOOB: falha ao obter token de producao (HTTP ' . $code . '). ' . $error);
        }

        $json = json_decode((string) $body, true);
        $token = is_array($json) ? (string) ($json['access_token'] ?? '') : '';
        if ($token === '') {
            throw new RuntimeException('SICOOB: resposta de autenticacao sem access_token.');
        }

        $expiraEm = time() + (int) (($json['expires_in'] ?? 300) - 20);
        @file_put_contents($cacheFile, json_encode([
            'client_id'    => $config['client_id'],
            'scopes'       => $escopos,
            'access_token' => $token,
            'expira_em'    => $expiraEm,
        ]), LOCK_EX);
        @chmod($cacheFile, 0600);

        return $token;
    }
}

if (!function_exists('sicoobAuthContexto')) {
    /**
     * Retorna headers + opcoes de cURL (certificado em producao) para as
     * chamadas a API de cobranca. Usado por sicoob_boleto_service.php.
     *
     * @return array{headers: string[], curl: array<int,mixed>}
     */
    function sicoobAuthContexto(array $config): array
    {
        $headers = [
            'client_id: ' . $config['client_id'],
            'Accept: application/json',
        ];
        $curl = [];

        if ($config['ambiente'] === 'producao') {
            $token = sicoobAuthTokenProducao($config);
            $headers[] = 'Authorization: Bearer ' . $token;
            $curl[CURLOPT_SSLCERT]      = $config['cert_path'];
            $curl[CURLOPT_SSLKEY]       = $config['cert_key_path'];
            $curl[CURLOPT_SSLKEYPASSWD] = $config['cert_password'];
        } else {
            $bearer = $config['sandbox_bearer'];
            if ($bearer === '') {
                throw new RuntimeException('SICOOB: SICOOB_SANDBOX_BEARER nao configurado.');
            }
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }

        return ['headers' => $headers, 'curl' => $curl];
    }
}

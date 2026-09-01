<?php

/**
 * Configuracao da integracao de Boleto SICOOB.
 *
 * Diferente da Focus NFe (que guarda config na tabela focus_config), o SICOOB
 * le tudo do .env — conforme o pacote de integracao. O .env ja e carregado em
 * $_ENV por bootstrap/conexao.php. NUNCA hardcode credencial aqui.
 */

if (!function_exists('sicoobEnv')) {
    function sicoobEnv(string $chave, string $default = ''): string
    {
        $valor = $_ENV[$chave] ?? getenv($chave);
        if ($valor === false || $valor === null) {
            return $default;
        }
        return trim((string) $valor);
    }
}

if (!function_exists('sicoobBoletoConfig')) {
    /**
     * Monta o array de configuracao conforme o ambiente ativo.
     * Espelha focusNfeLoadConfig(): retorna tudo que o service precisa.
     */
    function sicoobBoletoConfig(?string $ambiente = null): array
    {
        $ambiente = $ambiente ?: sicoobEnv('SICOOB_AMBIENTE', 'sandbox');
        $ambiente = $ambiente === 'producao' ? 'producao' : 'sandbox';

        if ($ambiente === 'producao') {
            $apiUrl   = sicoobEnv('SICOOB_API_URL_PROD');
            $clientId = sicoobEnv('SICOOB_CLIENT_ID_PROD');
        } else {
            $apiUrl   = sicoobEnv('SICOOB_API_URL_SANDBOX');
            $clientId = sicoobEnv('SICOOB_CLIENT_ID_SANDBOX');
        }

        if ($apiUrl === '') {
            throw new RuntimeException('SICOOB: API URL nao configurada para o ambiente ' . $ambiente . '.');
        }
        if ($clientId === '') {
            throw new RuntimeException('SICOOB: client_id nao configurado para o ambiente ' . $ambiente . '.');
        }

        return [
            'ambiente'         => $ambiente,
            'api_url'          => $apiUrl,
            'client_id'        => $clientId,
            'sandbox_bearer'   => sicoobEnv('SICOOB_SANDBOX_BEARER'),
            'auth_url'         => sicoobEnv('SICOOB_AUTH_URL'),
            'scopes'           => sicoobEnv('SICOOB_SCOPES', 'boletos_inclusao boletos_consulta'),
            'cert_path'        => sicoobEnv('SICOOB_CERT_PATH'),
            'cert_key_path'    => sicoobEnv('SICOOB_CERT_KEY_PATH'),
            'cert_password'    => sicoobEnv('SICOOB_CERT_PASSWORD'),
            'cooperativa'      => sicoobEnv('SICOOB_COOPERATIVA'),
            'numero_conta'     => sicoobEnv('SICOOB_NUMERO_CONTA'),
            'numero_cliente'   => sicoobEnv('SICOOB_NUMERO_CLIENTE'),
            'codigo_beneficiario' => sicoobEnv('SICOOB_CODIGO_BENEFICIARIO'),
            'codigo_modalidade' => (int) (sicoobEnv('SICOOB_CODIGO_MODALIDADE', '1') ?: 1),
            // --- Regras de cobranca (defaults espelham o boleto real da NK; CONFIRMAR com o Sicoob) ---
            'dias_vencimento'  => (int) (sicoobEnv('SICOOB_DIAS_VENCIMENTO', '30') ?: 30),
            // Juros: tipo 0=isento, 1=valor fixo/dia (R$), 2=taxa mensal (%). Real: ~0,07%/dia ≈ 2,10%/mes.
            'juros_tipo'       => (int) sicoobEnv('SICOOB_JUROS_TIPO', '2'),
            'juros_valor'      => (float) (sicoobEnv('SICOOB_JUROS_VALOR', '2.10') ?: 0),
            // Multa: tipo 0=isento, 1=valor fixo (R$), 2=percentual (%). Real: 5%.
            'multa_tipo'       => (int) sicoobEnv('SICOOB_MULTA_TIPO', '2'),
            'multa_valor'      => (float) (sicoobEnv('SICOOB_MULTA_VALOR', '5.00') ?: 0),
            // Juros em %/dia usado apenas no TEXTO da instrucao (espelha "Juros 0,07%/dia" do boleto real).
            'juros_dia'        => (float) (sicoobEnv('SICOOB_JUROS_DIA', '0.07') ?: 0),
        ];
    }
}

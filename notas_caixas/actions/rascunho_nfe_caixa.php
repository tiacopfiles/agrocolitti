<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/permissions.php';
require __DIR__ . '/../../focus/focus_nfe_caixas.php';

header('Content-Type: application/json; charset=utf-8');

if (!userCanAccess('notas_caixas')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Sem permissao para emitir NF-e de caixas.']);
    exit;
}

$notaId = (int) ($_POST['nota_id'] ?? $_GET['nota_id'] ?? 0);
$ambiente = (string) ($_POST['ambiente'] ?? $_GET['ambiente'] ?? 'producao');

try {
    if ($notaId <= 0) {
        throw new RuntimeException('Informe a nota de caixas.');
    }
    $config = focusNfeLoadConfig($conexao, $ambiente);
    [$payload, $nota, $itens] = focusNfeMontarPayloadCaixa($conexao, $config, $notaId);

    $destinatario = [
        'nome' => $payload['nome_destinatario'] ?? '',
        'cpf' => $payload['cpf_destinatario'] ?? '',
        'cnpj' => $payload['cnpj_destinatario'] ?? '',
        'ie' => $payload['inscricao_estadual_destinatario'] ?? '',
        'indicador_ie' => $payload['indicador_inscricao_estadual_destinatario'] ?? '',
        'logradouro' => $payload['logradouro_destinatario'] ?? '',
        'numero' => $payload['numero_destinatario'] ?? '',
        'complemento' => $payload['complemento_destinatario'] ?? '',
        'bairro' => $payload['bairro_destinatario'] ?? '',
        'municipio' => $payload['municipio_destinatario'] ?? '',
        'uf' => $payload['uf_destinatario'] ?? '',
        'cep' => $payload['cep_destinatario'] ?? '',
        'telefone' => $payload['telefone_destinatario'] ?? '',
        'email' => $payload['email_destinatario'] ?? '',
    ];

    echo json_encode([
        'ok' => true,
        'nota_id' => $notaId,
        'os_label' => (string) ($nota['numero_os'] ?? ''),
        'geral' => [
            'natureza_operacao' => $payload['natureza_operacao'] ?? '',
            'uf_destinatario' => $payload['uf_destinatario'] ?? '',
        ],
        'destinatario' => $destinatario,
        'items' => array_values($payload['items'] ?? []),
        'informacoes_adicionais_contribuinte' => $payload['informacoes_adicionais_contribuinte'] ?? '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    exit;
}
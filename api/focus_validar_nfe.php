<?php
require __DIR__ . '/../config/conexao.php';
require __DIR__ . '/../auth/proteger.php';
require __DIR__ . '/../config/permissions.php';
require __DIR__ . '/../focus/focus_nfe_service.php';

requireModule('exportar_nfe');

header('Content-Type: application/json; charset=utf-8');

try {
    $vendaId = isset($_GET['venda_id']) && $_GET['venda_id'] !== '' ? (int) $_GET['venda_id'] : null;
    $numeroOs = isset($_GET['numero_os']) && trim((string) $_GET['numero_os']) !== '' ? trim((string) $_GET['numero_os']) : null;
    $ambiente = $_GET['ambiente'] ?? 'producao';

    $config = focusNfeLoadConfig($conexao, $ambiente);
    [$payload] = focusNfeMontarPayloadVenda($conexao, $config, $vendaId, $numeroOs);

    echo json_encode([
        'ok' => true,
        'ref' => focusNfeCriarRef($vendaId, $numeroOs),
        'ambiente' => $ambiente,
        'destinatario' => [
            'nome' => $payload['nome_destinatario'],
            'uf' => $payload['uf_destinatario'],
            'municipio' => $payload['municipio_destinatario'],
        ],
        'itens' => array_map(static function (array $item): array {
            return [
                'numero_item' => $item['numero_item'],
                'produto_id' => $item['produto_id_origem'] ?? '',
                'produto_nome' => $item['produto_nome_origem'] ?? $item['descricao'],
                'codigo_produto_esperado' => $item['codigo_produto_esperado'] ?? $item['codigo_produto'],
                'descricao_esperada' => $item['descricao_esperada'] ?? $item['descricao'],
                'codigo_produto' => $item['codigo_produto'],
                'descricao' => $item['descricao'],
                'cfop' => $item['cfop'],
                'ncm' => $item['codigo_ncm'],
                'icms_cst' => $item['icms_situacao_tributaria'],
                'valor_bruto' => $item['valor_bruto'],
            ];
        }, $payload['items']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'erro' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

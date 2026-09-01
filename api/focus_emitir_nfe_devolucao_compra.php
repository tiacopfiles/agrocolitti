<?php
require __DIR__ . '/../config/conexao.php';
require __DIR__ . '/../auth/proteger.php';
require __DIR__ . '/../config/permissions.php';
require __DIR__ . '/../focus/focus_nfe_operacoes.php';

requireModule('exportar_nfe');
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'erro' => 'Metodo nao permitido.']);
        exit;
    }
    validarTokenCsrf();
    $numeroOs = trim((string) ($_POST['numero_os'] ?? ''));
    $ambiente = $_POST['ambiente'] ?? 'producao';
    $localDestino = focusNfeLocalDestinoFromPost($_POST);
    $resultado = focusNfeEmitirCompraOperacao($conexao, $numeroOs, 'devolucao_compra', $ambiente, $localDestino);
    $httpCode = (int) ($resultado['http_code'] ?? 0);
    http_response_code($httpCode >= 400 ? 422 : 200);
    echo json_encode([
        'ok' => $httpCode > 0 && $httpCode < 400,
        'documento_id' => $resultado['documento_id'],
        'ref' => $resultado['ref'],
        'http_code' => $httpCode,
        'response' => $resultado['response'],
        'erro' => $resultado['error'] ?: null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

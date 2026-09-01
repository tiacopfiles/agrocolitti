<?php
require __DIR__ . '/../config/conexao.php';
require __DIR__ . '/../auth/proteger.php';
require __DIR__ . '/../config/permissions.php';
require __DIR__ . '/../focus/focus_nfe_service.php';
require __DIR__ . '/../config/nfe_email_helper.php';

requireModule('exportar_nfe');

header('Content-Type: application/json; charset=utf-8');

try {
    $ref = trim((string) ($_GET['ref'] ?? $_POST['ref'] ?? ''));
    $ambiente = trim((string) ($_GET['ambiente'] ?? $_POST['ambiente'] ?? 'producao'));

    if ($ref === '') {
        throw new RuntimeException('Informe a referencia da NF-e.');
    }

    $resultado = focusNfeConsultarRef($conexao, $ref, $ambiente);
    $httpCode = (int) ($resultado['http_code'] ?? 0);
    $statusFocus = is_array($resultado['response'] ?? null) ? strtolower((string) ($resultado['response']['status'] ?? '')) : '';
    $emailXml = null;
    if ($httpCode > 0 && $httpCode < 400 && str_contains($statusFocus, 'autoriz')) {
        $emailXml = nfeEmailTentarEnviarXml($conexao, (int) ($resultado['documento_id'] ?? 0), focusNfeLoadConfig($conexao, $ambiente));
    }
    http_response_code($httpCode >= 400 ? 422 : 200);
    echo json_encode([
        'ok' => $httpCode > 0 && $httpCode < 400,
        'documento_id' => $resultado['documento_id'],
        'ref' => $resultado['ref'],
        'http_code' => $httpCode,
        'response' => $resultado['response'],
        'erro' => $resultado['error'] ?: null,
        'email_xml' => $emailXml,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'erro' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

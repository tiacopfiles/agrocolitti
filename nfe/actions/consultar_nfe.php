<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/permissions.php';
require __DIR__ . '/../../focus/focus_nfe_service.php';
require __DIR__ . '/../../config/nfe_email_helper.php';

requireModule('historico_nfe', '../historico.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../historico.php');
    exit;
}

validarTokenCsrf();

$ref = trim((string) ($_POST['ref'] ?? ''));
$ambiente = trim((string) ($_POST['ambiente'] ?? 'producao'));

try {
    if ($ref === '') {
        throw new RuntimeException('Informe a referencia da NF-e.');
    }
    $resultado = focusNfeConsultarRef($conexao, $ref, $ambiente);
    $httpCode = (int) ($resultado['http_code'] ?? 0);
    if ($httpCode > 0 && $httpCode < 400) {
        $statusFocus = is_array($resultado['response'] ?? null) ? strtolower((string) ($resultado['response']['status'] ?? '')) : '';
        $emailXml = str_contains($statusFocus, 'autoriz')
            ? nfeEmailTentarEnviarXml($conexao, (int) ($resultado['documento_id'] ?? 0), focusNfeLoadConfig($conexao, $ambiente))
            : null;
        $redirect = '../historico.php?msg=consultada&ref=' . urlencode($ref);
        if ($emailXml) {
            $redirect .= '&email_xml=' . urlencode((string) $emailXml['status']) . '&email_msg=' . urlencode((string) $emailXml['mensagem']);
        }
        header('Location: ' . $redirect);
        exit;
    }
    $mensagem = 'Focus NFe retornou HTTP ' . $httpCode . '.';
    if (is_array($resultado['response'])) {
        $mensagemFocus = $resultado['response']['mensagem_sefaz'] ?? $resultado['response']['mensagem'] ?? $resultado['response']['erro'] ?? '';
        if (is_string($mensagemFocus) && $mensagemFocus !== '') {
            $mensagem .= ' ' . $mensagemFocus;
        }
    }
    if (!empty($resultado['error'])) {
        $mensagem .= ' ' . $resultado['error'];
    }
    header('Location: ../historico.php?msg=erro&detalhe=' . urlencode($mensagem));
    exit;
} catch (Throwable $e) {
    header('Location: ../historico.php?msg=erro&detalhe=' . urlencode($e->getMessage()));
    exit;
}

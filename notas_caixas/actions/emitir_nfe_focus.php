<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/permissions.php';
require __DIR__ . '/../../focus/focus_nfe_caixas.php';

requireModule('notas_caixas', '../historico.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../historico.php');
    exit;
}

validarTokenCsrf();

$notaId = (int) ($_POST['nota_id'] ?? 0);
$ambiente = (string) ($_POST['ambiente'] ?? 'producao');

try {
    if ($notaId <= 0) {
        throw new RuntimeException('Informe a nota de caixas.');
    }
    $opcoes = focusNfeOpcoesCaixaFromPost($_POST);
    $resultado = focusNfeEmitirNotaCaixaRevisada($conexao, $notaId, $opcoes, $ambiente);
    $httpCode = (int) ($resultado['http_code'] ?? 0);
    $ref = (string) ($resultado['ref'] ?? '');
    $statusFocus = is_array($resultado['response']) ? strtolower((string) ($resultado['response']['status'] ?? '')) : '';
    $mensagemSefaz = is_array($resultado['response']) ? (string) ($resultado['response']['mensagem_sefaz'] ?? '') : '';

    if ($httpCode === 201 && !str_contains($statusFocus, 'erro') && !str_contains($statusFocus, 'reje')) {
        header('Location: ../historico.php?msg=nfe_enviada&ref=' . urlencode($ref));
        exit;
    }
    if ($httpCode === 202 && !str_contains($statusFocus, 'erro') && !str_contains($statusFocus, 'reje')) {
        header('Location: ../historico.php?msg=nfe_processando&ref=' . urlencode($ref));
        exit;
    }

    $mensagem = 'Focus NFe retornou HTTP ' . $httpCode . '.';
    if (is_array($resultado['response'])) {
        $mensagemFocus = $resultado['response']['mensagem'] ?? $resultado['response']['erro'] ?? $resultado['response']['message'] ?? '';
        if ($mensagemSefaz !== '') {
            $mensagemFocus = $mensagemSefaz;
        }
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

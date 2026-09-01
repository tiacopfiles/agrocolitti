<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/permissions.php';
require __DIR__ . '/../../focus/focus_nfe_operacoes.php';

requireModule('exportar_nfe', '../historico_compras.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../historico_compras.php');
    exit;
}

validarTokenCsrf();

$numeroOs = trim((string) ($_POST['numero_os'] ?? ''));
$tipo = trim((string) ($_POST['tipo_emissao'] ?? 'compra'));
$ambiente = $_POST['ambiente'] ?? 'producao';
if (!in_array($tipo, ['compra', 'devolucao_compra', 'saida_abate_sem_entrada'], true)) {
    $tipo = 'compra';
}

try {
    if ($numeroOs === '') {
        throw new RuntimeException('Informe a OS da compra.');
    }
    $localDestino = focusNfeLocalDestinoFromPost($_POST);
    $informacoesAdicionais = focusNfeInformacoesAdicionaisFromPost($_POST);
    $opcoes = focusNfeOpcoesCompraFromPost($_POST);
    $resultado = focusNfeEmitirCompraRevisada($conexao, $numeroOs, $tipo, $opcoes, $ambiente);
    $httpCode = (int) ($resultado['http_code'] ?? 0);
    $ref = (string) ($resultado['ref'] ?? '');

    $statusFocus = is_array($resultado['response']) ? strtolower((string) ($resultado['response']['status'] ?? '')) : '';
    $mensagemSefaz = is_array($resultado['response']) ? (string) ($resultado['response']['mensagem_sefaz'] ?? '') : '';

    if ($httpCode === 201 && !str_contains($statusFocus, 'erro') && !str_contains($statusFocus, 'reje')) {
        header('Location: ../historico_compras.php?msg=nfe_enviada&ref=' . urlencode($ref));
        exit;
    }
    if ($httpCode === 202 && !str_contains($statusFocus, 'erro') && !str_contains($statusFocus, 'reje')) {
        header('Location: ../historico_compras.php?msg=nfe_processando&ref=' . urlencode($ref));
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
    header('Location: ../historico_compras.php?msg=erro&detalhe=' . urlencode($mensagem));
    exit;
} catch (Throwable $e) {
    header('Location: ../historico_compras.php?msg=erro&detalhe=' . urlencode($e->getMessage()));
    exit;
}

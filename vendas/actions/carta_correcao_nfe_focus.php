<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/permissions.php';
require __DIR__ . '/../../focus/focus_nfe_service.php';

requireModule('exportar_nfe', '../historico_vendas.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../historico_vendas.php');
    exit;
}

validarTokenCsrf();

$documentoId = (int) ($_POST['documento_id'] ?? 0);
$correcao = trim((string) ($_POST['correcao'] ?? ''));
$ambiente = (string) ($_POST['ambiente'] ?? 'producao');

try {
    if ($documentoId <= 0) {
        throw new RuntimeException('Informe a NF-e para carta de correcao.');
    }
    $resultado = focusNfeEmitirCartaCorrecaoDocumento($conexao, $documentoId, $correcao, $ambiente);
    if ((int) ($resultado['http_code'] ?? 0) < 400) {
        header('Location: ../historico_vendas.php?msg=nfe_cce_enviada&ref=' . urlencode((string) $resultado['ref']));
        exit;
    }

    $mensagem = 'Focus NFe retornou HTTP ' . (int) ($resultado['http_code'] ?? 0) . '.';
    if (is_array($resultado['response'])) {
        $mensagemFocus = $resultado['response']['mensagem_sefaz'] ?? $resultado['response']['mensagem'] ?? $resultado['response']['erro'] ?? '';
        if (is_array($resultado['response']['erros'] ?? null)) {
            $mensagemFocus = implode('; ', array_map(static fn($erro) => is_array($erro) ? (string) ($erro['mensagem'] ?? json_encode($erro, JSON_UNESCAPED_UNICODE)) : (string) $erro, $resultado['response']['erros']));
        }
        if (is_string($mensagemFocus) && $mensagemFocus !== '') {
            $mensagem .= ' ' . $mensagemFocus;
        }
    }
    if (!empty($resultado['error'])) {
        $mensagem .= ' ' . $resultado['error'];
    }
    header('Location: ../historico_vendas.php?msg=erro&detalhe=' . urlencode($mensagem));
    exit;
} catch (Throwable $e) {
    header('Location: ../historico_vendas.php?msg=erro&detalhe=' . urlencode($e->getMessage()));
    exit;
}

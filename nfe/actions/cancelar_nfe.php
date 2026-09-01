<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/permissions.php';
require __DIR__ . '/../../focus/focus_nfe_service.php';

requireModule('historico_nfe', '../historico.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../historico.php');
    exit;
}

validarTokenCsrf();

$documentoId = (int) ($_POST['documento_id'] ?? 0);
$justificativa = trim((string) ($_POST['justificativa'] ?? ''));

try {
    if ($documentoId <= 0) {
        throw new RuntimeException('Informe a NF-e para cancelamento.');
    }
    $stmt = $conexao->prepare("SELECT ambiente FROM nfe_documentos WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $documentoId);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$doc) {
        throw new RuntimeException('NF-e nao encontrada.');
    }
    $resultado = focusNfeCancelarDocumento($conexao, $documentoId, $justificativa, (string) $doc['ambiente']);
    $statusFocus = is_array($resultado['response']) ? strtolower((string) ($resultado['response']['status'] ?? '')) : '';
    if ((int) ($resultado['http_code'] ?? 0) < 400 && str_contains($statusFocus, 'cancel')) {
        header('Location: ../historico.php?msg=nfe_cancelada&ref=' . urlencode((string) $resultado['ref']));
        exit;
    }
    $mensagem = 'Focus NFe retornou HTTP ' . (int) ($resultado['http_code'] ?? 0) . '.';
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

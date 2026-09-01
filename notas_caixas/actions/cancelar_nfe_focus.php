<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/permissions.php';
require __DIR__ . '/../../focus/focus_nfe_service.php';

requireModule('notas_caixas', '../historico.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../historico.php');
    exit;
}

validarTokenCsrf();

$documentoId = (int) ($_POST['documento_id'] ?? 0);
$justificativa = trim((string) ($_POST['justificativa'] ?? ''));
$ambiente = (string) ($_POST['ambiente'] ?? 'producao');

try {
    if ($documentoId <= 0) {
        throw new RuntimeException('Informe a NF-e para cancelamento.');
    }
    $resultado = focusNfeCancelarDocumento($conexao, $documentoId, $justificativa, $ambiente);
    $statusFocus = is_array($resultado['response']) ? strtolower((string) ($resultado['response']['status'] ?? '')) : '';
    if ((int) ($resultado['http_code'] ?? 0) < 400 && str_contains($statusFocus, 'cancel')) {
        $stmt = $conexao->prepare("UPDATE nfe_caixa_notas n INNER JOIN nfe_documentos d ON d.origem_id = n.id AND d.origem_tipo = 'nota_caixa' SET n.status = 'cancelada' WHERE d.id = ?");
        $stmt->bind_param('i', $documentoId);
        $stmt->execute();
        $stmt->close();
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
    header('Location: ../historico.php?msg=erro&detalhe=' . urlencode($mensagem));
    exit;
} catch (Throwable $e) {
    header('Location: ../historico.php?msg=erro&detalhe=' . urlencode($e->getMessage()));
    exit;
}

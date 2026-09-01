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

$documentoId = (int) ($_POST['documento_id'] ?? 0);
try {
    $stmt = $conexao->prepare("SELECT id, ref, ambiente, status, tipo_emissao FROM nfe_documentos WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $documentoId);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    if (!$doc || ($doc['tipo_emissao'] ?? '') !== 'venda' || ($doc['status'] ?? '') !== 'autorizada') {
        throw new RuntimeException('Somente NF-e de venda autorizada pode ter o XML enviado.');
    }

    $ambiente = (string) $doc['ambiente'];
    $consulta = focusNfeConsultarRef($conexao, (string) $doc['ref'], $ambiente);
    if ((int) ($consulta['http_code'] ?? 0) >= 400) {
        throw new RuntimeException('Nao foi possivel atualizar o XML na Focus antes do envio.');
    }
    $resultado = nfeEmailTentarEnviarXml($conexao, $documentoId, focusNfeLoadConfig($conexao, $ambiente), true);
    $statusEmail = (string) ($resultado['status'] ?? 'falhou');
    $mensagem = (string) ($resultado['mensagem'] ?? 'Nao foi possivel enviar o XML por e-mail.');
    header('Location: ../historico.php?email_xml=' . urlencode($statusEmail) . '&email_msg=' . urlencode($mensagem));
    exit;
} catch (Throwable $e) {
    header('Location: ../historico.php?email_xml=falhou&email_msg=' . urlencode($e->getMessage()));
    exit;
}

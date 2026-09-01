<?php
require "../../config/conexao.php";
require "../../auth/proteger.php";
require "../../config/permissions.php";

requireModule('contas', '../../index.php');

function contasFilaJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$rawBody = file_get_contents('php://input');
$jsonBody = json_decode((string) $rawBody, true);
if (is_array($jsonBody) && isset($jsonBody['csrf_token'])) {
    $_POST['csrf_token'] = (string) $jsonBody['csrf_token'];
}
validarTokenCsrf();

try {
    if (!is_array($jsonBody)) {
        contasFilaJson(['ok' => false, 'erro' => 'Requisicao invalida.'], 400);
    }

    $tipo = trim((string) ($jsonBody['tipo'] ?? ''));
    $origemId = (int) ($jsonBody['id'] ?? $jsonBody['origem_id'] ?? 0);
    $motivo = trim((string) ($jsonBody['motivo'] ?? 'Removido da fila pelo usuario.'));

    if (!in_array($tipo, ['pagar', 'receber'], true)) {
        contasFilaJson(['ok' => false, 'erro' => 'Tipo de integracao invalido.'], 400);
    }
    if ($origemId <= 0) {
        contasFilaJson(['ok' => false, 'erro' => 'ID de origem invalido.'], 400);
    }

    if ($tipo === 'pagar') {
        $destinoBanco = 'contas';
        $sqlDoc = "
            SELECT id, COALESCE(numero_os, ref, id) AS origem_ref
              FROM nfe_documentos
             WHERE id = ?
               AND tipo_emissao IN ('compra','saida_abate_sem_entrada')
             LIMIT 1
        ";
    } else {
        $destinoBanco = 'contasareceber';
        $sqlDoc = "
            SELECT id, COALESCE(numero_os, ref, id) AS origem_ref
              FROM nfe_documentos
             WHERE id = ?
               AND tipo_emissao = 'venda'
             LIMIT 1
        ";
    }

    $stmtDoc = $conexao->prepare($sqlDoc);
    $stmtDoc->bind_param('i', $origemId);
    $stmtDoc->execute();
    $doc = $stmtDoc->get_result()->fetch_assoc();
    $stmtDoc->close();

    if (!$doc) {
        contasFilaJson(['ok' => false, 'erro' => 'Documento de origem nao encontrado para esta fila.'], 404);
    }

    $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
    $origemRef = (string) ($doc['origem_ref'] ?? $origemId);
    $erroMensagem = $motivo !== '' ? $motivo : 'Removido da fila pelo usuario.';
    $payloadJson = json_encode([
        'acao' => 'cancelar_fila',
        'tipo' => $tipo,
        'origem_tabela' => 'nfe_documentos',
        'origem_id' => $origemId,
        'origem_ref' => $origemRef,
        'usuario_id' => $usuarioId,
        'motivo' => $erroMensagem,
        'observacao' => 'Remove apenas da fila AgroColitti; nao exclui lancamento ja criado no sistema legado.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $conexao->prepare("
        INSERT INTO contas_integracoes
            (tipo, origem_tabela, origem_id, origem_ref, destino_banco, destino_tabela, status, payload_json, erro_mensagem, criado_por)
        VALUES
            (?, 'nfe_documentos', ?, ?, ?, 'lancamentos', 'cancelado', ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            destino_banco = VALUES(destino_banco),
            destino_tabela = VALUES(destino_tabela),
            status = 'cancelado',
            payload_json = VALUES(payload_json),
            erro_mensagem = VALUES(erro_mensagem),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param('sissssi', $tipo, $origemId, $origemRef, $destinoBanco, $payloadJson, $erroMensagem, $usuarioId);
    $stmt->execute();
    $integracaoId = (int) ($conexao->insert_id ?: 0);
    $stmt->close();

    if ($integracaoId <= 0) {
        $stmtFind = $conexao->prepare("SELECT id FROM contas_integracoes WHERE tipo = ? AND origem_tabela = 'nfe_documentos' AND origem_id = ? LIMIT 1");
        $stmtFind->bind_param('si', $tipo, $origemId);
        $stmtFind->execute();
        $row = $stmtFind->get_result()->fetch_assoc();
        $stmtFind->close();
        $integracaoId = (int) ($row['id'] ?? 0);
    }

    $evento = 'cancelado_fila';
    $stmtEvt = $conexao->prepare("INSERT INTO contas_integracao_eventos (integracao_id, lote_id, evento, detalhe, payload_json, usuario_id) VALUES (?, NULL, ?, ?, ?, ?)");
    $stmtEvt->bind_param('isssi', $integracaoId, $evento, $erroMensagem, $payloadJson, $usuarioId);
    $stmtEvt->execute();
    $stmtEvt->close();

    contasFilaJson([
        'ok' => true,
        'status' => 'cancelado',
        'origem_id' => $origemId,
        'integracao_id' => $integracaoId,
        'mensagem' => 'Registro removido da fila. Se ja havia lancamento no contas legado, ele nao foi excluido automaticamente.',
    ]);
} catch (Throwable $e) {
    contasFilaJson(['ok' => false, 'erro' => $e->getMessage()], 500);
}

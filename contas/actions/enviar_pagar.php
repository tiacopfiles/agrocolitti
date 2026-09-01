<?php
require "../../config/conexao.php";
require "../../auth/proteger.php";
require "../../config/permissions.php";
require "../lib/contas_pagar_integration.php";

requireModule('contas', '../../index.php');
$rawBody = file_get_contents('php://input');
$jsonBody = json_decode((string) $rawBody, true);
if (is_array($jsonBody) && isset($jsonBody['csrf_token'])) {
    $_POST['csrf_token'] = (string) $jsonBody['csrf_token'];
}
validarTokenCsrf();

try {
    contasAssertEnvioHabilitado();
    contasAssertAgroAllowed($conexao);
    $destino = contasConnectDestino();

    $items = contasRequestItems();
    if (!$items) contasJsonResponse(['ok' => false, 'erro_critico' => 'Nenhum item recebido para envio.'], 400);

    $ids = [];
    foreach ($items as $item) $ids[] = (int) ($item['id'] ?? $item['origem_id'] ?? 0);
    $ids = array_values(array_unique(array_filter($ids)));
    if (!$ids) contasJsonResponse(['ok' => false, 'erro_critico' => 'IDs invalidos para envio.'], 400);

    $docs = contasLoadNfeDocs($conexao, $ids);
    $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
    $payloadLote = json_encode(['ids' => $ids, 'usuario_id' => $usuarioId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $totalItens = count($ids);

    $stmtLote = $conexao->prepare("INSERT INTO contas_integracao_lotes (tipo, status, total_itens, payload_json, criado_por) VALUES ('pagar', 'processando', ?, ?, ?)");
    $stmtLote->bind_param('isi', $totalItens, $payloadLote, $usuarioId);
    $stmtLote->execute();
    $loteId = (int) $stmtLote->insert_id;
    $stmtLote->close();

    $resultados = [];
    $enviados = 0;
    $erros = 0;

    foreach ($ids as $id) {
        if (!isset($docs[$id])) {
            $erros++;
            $resultados[] = ['origem_id' => $id, 'status' => 'erro', 'mensagem' => 'NF-e nao encontrada.'];
            continue;
        }

        $input = contasFindInputItem($items, $id);
        $item = contasBuildItem($conexao, $docs[$id], $input, $destino);

        if ($item['status'] !== 'apto') {
            $erros++;
            $payloadJson = json_encode($item['snapshot'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $erroMsg = implode(' | ', $item['erros']);
            $stmt = $conexao->prepare("
                INSERT INTO contas_integracoes
                    (lote_id, tipo, origem_tabela, origem_id, origem_ref, status, ndocumento, fornecedor_nome, cnpj, dataemissao, vencimento, competencia, valor, desconto_funrural, desconto_devolucao, desconto_total, valortotal, payload_json, erro_mensagem, criado_por)
                VALUES
                    (?, 'pagar', 'nfe_documentos', ?, ?, 'erro', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    lote_id = VALUES(lote_id), status = 'erro', payload_json = VALUES(payload_json), erro_mensagem = VALUES(erro_mensagem), updated_at = CURRENT_TIMESTAMP
            ");
            $lanc = $item['lancamento'];
            $calc = $item['snapshot']['calculo'];
            $stmt->bind_param(
                'iisssssssdddddssi',
                $loteId,
                $id,
                $item['origem_ref'],
                $lanc['ndocumento'],
                $item['fornecedor'],
                $item['cnpj'],
                $lanc['dataemissao'],
                $lanc['vencimento'],
                $lanc['competencia'],
                $lanc['valor'],
                $calc['funrural_final'],
                $calc['devolucao_final'],
                $calc['desconto_total'],
                $lanc['valortotal'],
                $payloadJson,
                $erroMsg,
                $usuarioId
            );
            $stmt->execute();
            $integracaoId = (int) ($conexao->insert_id ?: ($docs[$id]['integracao_id'] ?? 0));
            $stmt->close();
            contasRecordEvento($conexao, $integracaoId ?: null, $loteId, 'bloqueado', $erroMsg, $item['snapshot'] ?? []);
            $resultados[] = ['origem_id' => $id, 'status' => 'bloqueado', 'mensagem' => $erroMsg, 'erros' => $item['erros']];
            continue;
        }

        $lanc = $item['lancamento'];
        $calc = $item['snapshot']['calculo'];
        $payloadJson = json_encode($item['snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $destino->begin_transaction();
            $fornecedorDestino = contasEnsureFornecedorDestino(
                $destino,
                (string) ($item['snapshot']['fornecedor']['cnpj_cpf'] ?? $lanc['cnpj']),
                (string) $lanc['nomefantasia']
            );
            $item = contasApplyFornecedorDestino($item, $fornecedorDestino);
            $lanc = $item['lancamento'];

            $stmtDest = $destino->prepare("
                INSERT INTO lancamentos
                    (ndocumento, tipo, nomefantasia, vencimento, dataemissao, obs, valor, datapgto, categoria, desconto, valortotal, parcela, nparcela, conta, situacao, acrescimo, competencia, centrocusto, cnpj)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, NULL, NULL, ?, ?, NULL, ?, ?, ?)
            ");
            $stmtDest->bind_param(
                'ssssssdsddsssss',
                $lanc['ndocumento'],
                $lanc['tipo'],
                $lanc['nomefantasia'],
                $lanc['vencimento'],
                $lanc['dataemissao'],
                $lanc['obs'],
                $lanc['valor'],
                $lanc['categoria'],
                $lanc['desconto'],
                $lanc['valortotal'],
                $lanc['conta'],
                $lanc['situacao'],
                $lanc['competencia'],
                $lanc['centrocusto'],
                $lanc['cnpj']
            );
            $stmtDest->execute();
            $destinoId = (int) $stmtDest->insert_id;
            $stmtDest->close();

            $conferenciaDestino = contasGarantirFornecedorNoLancamento($destino, $destinoId, (string) $lanc['nomefantasia'], (string) $lanc['cnpj']);
            $item['snapshot']['destino_conferencia'] = [
                'nomefantasia' => $conferenciaDestino['nomefantasia'] ?? null,
                'cnpj' => $conferenciaDestino['cnpj'] ?? null,
            ];

            $item['snapshot']['destino'] = ['banco' => CONTAS_DESTINO_DB, 'tabela' => 'lancamentos', 'id' => $destinoId];
            $payloadJson = json_encode($item['snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $stmt = $conexao->prepare("
                INSERT INTO contas_integracoes
                    (lote_id, tipo, origem_tabela, origem_id, origem_ref, destino_id, status, ndocumento, fornecedor_nome, cnpj, dataemissao, vencimento, competencia, valor, desconto_funrural, desconto_devolucao, desconto_total, valortotal, payload_json, criado_por, enviado_por, enviado_em)
                VALUES
                    (?, 'pagar', 'nfe_documentos', ?, ?, ?, 'enviado', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    lote_id = VALUES(lote_id), destino_id = VALUES(destino_id), status = 'enviado', ndocumento = VALUES(ndocumento), fornecedor_nome = VALUES(fornecedor_nome), cnpj = VALUES(cnpj),
                    dataemissao = VALUES(dataemissao), vencimento = VALUES(vencimento), competencia = VALUES(competencia), valor = VALUES(valor), desconto_funrural = VALUES(desconto_funrural),
                    desconto_devolucao = VALUES(desconto_devolucao), desconto_total = VALUES(desconto_total), valortotal = VALUES(valortotal), payload_json = VALUES(payload_json),
                    erro_mensagem = NULL, enviado_por = VALUES(enviado_por), enviado_em = NOW(), updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->bind_param(
                'iisissssssdddddsii',
                $loteId,
                $id,
                $item['origem_ref'],
                $destinoId,
                $lanc['ndocumento'],
                $item['fornecedor'],
                $item['cnpj'],
                $lanc['dataemissao'],
                $lanc['vencimento'],
                $lanc['competencia'],
                $lanc['valor'],
                $calc['funrural_final'],
                $calc['devolucao_final'],
                $calc['desconto_total'],
                $lanc['valortotal'],
                $payloadJson,
                $usuarioId,
                $usuarioId
            );
            $stmt->execute();
            $integracaoId = (int) ($conexao->insert_id ?: ($docs[$id]['integracao_id'] ?? 0));
            $stmt->close();

            $destino->commit();
            contasRecordEvento($conexao, $integracaoId ?: null, $loteId, 'enviado', 'Lancamento enviado ao contas #' . $destinoId, $item['snapshot']);

            $enviados++;
            $resultados[] = ['origem_id' => $id, 'status' => 'enviado', 'destino_id' => $destinoId, 'mensagem' => 'Enviado ao contas #' . $destinoId];
        } catch (Throwable $e) {
            $destino->rollback();
            $erros++;
            $erroMsg = 'Erro ao inserir no contas: ' . $e->getMessage();
            contasRecordEvento($conexao, null, $loteId, 'erro_insert', $erroMsg, $item['snapshot'] ?? []);
            $resultados[] = ['origem_id' => $id, 'status' => 'erro', 'mensagem' => $erroMsg];
        }
    }

    $statusLote = $erros > 0 ? ($enviados > 0 ? 'concluido_com_erros' : 'erro') : 'concluido';
    $stmtUpd = $conexao->prepare("UPDATE contas_integracao_lotes SET status = ?, total_enviados = ?, total_erros = ?, enviado_em = NOW() WHERE id = ?");
    $stmtUpd->bind_param('siii', $statusLote, $enviados, $erros, $loteId);
    $stmtUpd->execute();
    $stmtUpd->close();

    contasJsonResponse([
        'ok' => $erros === 0,
        'lote_id' => $loteId,
        'resumo' => ['selecionados' => count($ids), 'enviados' => $enviados, 'erros' => $erros],
        'itens' => $resultados,
    ]);
} catch (Throwable $e) {
    contasJsonResponse(['ok' => false, 'erro_critico' => $e->getMessage()], 500);
}

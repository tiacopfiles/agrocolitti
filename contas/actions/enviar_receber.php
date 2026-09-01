<?php
require "../../config/conexao.php";
require "../../auth/proteger.php";
require "../../config/permissions.php";
require "../lib/contas_receber_integration.php";
require "../../comissoes/frete_helper.php";
require "../../comissoes/vendedores_helper.php";

requireModule('contas', '../../index.php');
$rawBody = file_get_contents('php://input');
$jsonBody = json_decode((string) $rawBody, true);
if (is_array($jsonBody) && isset($jsonBody['csrf_token'])) {
    $_POST['csrf_token'] = (string) $jsonBody['csrf_token'];
}
validarTokenCsrf();

try {
    contasReceberAssertEnvioHabilitado();
    contasReceberAssertAgroAllowed($conexao);
    comissoesVendedoresGarantirEstrutura($conexao);
    $destino = contasReceberConnectDestino();

    $items = contasReceberRequestItems();
    if (!$items) contasReceberJsonResponse(['ok' => false, 'erro_critico' => 'Nenhum item recebido para envio.'], 400);

    $ids = [];
    foreach ($items as $item) $ids[] = (int) ($item['id'] ?? $item['origem_id'] ?? 0);
    $ids = array_values(array_unique(array_filter($ids)));
    if (!$ids) contasReceberJsonResponse(['ok' => false, 'erro_critico' => 'IDs invalidos para envio.'], 400);

    $docs = contasReceberLoadNfeDocs($conexao, $ids);
    $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
    $payloadLote = json_encode(['ids' => $ids, 'usuario_id' => $usuarioId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $totalItens = count($ids);

    $stmtLote = $conexao->prepare("INSERT INTO contas_integracao_lotes (tipo, status, total_itens, payload_json, criado_por) VALUES ('receber', 'processando', ?, ?, ?)");
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
            $resultados[] = ['origem_id' => $id, 'status' => 'erro', 'mensagem' => 'NF-e autorizada nao encontrada.'];
            continue;
        }

        $input = contasReceberFindInputItem($items, $id);
        $item = contasReceberBuildItem($conexao, $docs[$id], $input, $destino);

        if ($item['status'] !== 'apto') {
            $payloadJson = json_encode($item['snapshot'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $erroMsg = implode(' | ', $item['erros']);
            $lanc = $item['lancamento'];
            $calc = $item['snapshot']['calculo'];

            // Uma NF-e ja enviada nao pode ser rebaixada para erro por uma reexecucao.
            // O status da integracao representa somente o financeiro ja confirmado.
            if (!empty($docs[$id]['integracao_id']) && ($docs[$id]['integracao_status'] ?? '') === 'enviado') {
                $resultados[] = [
                    'origem_id' => $id,
                    'status' => 'ja_enviado',
                    'destino_id' => (int) ($docs[$id]['integracao_destino_id'] ?? 0),
                    'mensagem' => 'Financeiro ja enviado ao contas a receber; reenvio bloqueado.',
                    'erros' => $item['erros'],
                ];
                continue;
            }

            $erros++;

            $stmt = $conexao->prepare("
                INSERT INTO contas_integracoes
                    (lote_id, tipo, origem_tabela, origem_id, origem_ref, destino_banco, status, ndocumento, fornecedor_nome, cnpj, dataemissao, vencimento, competencia, valor, desconto_funrural, desconto_devolucao, desconto_total, valortotal, payload_json, erro_mensagem, criado_por)
                VALUES
                    (?, 'receber', 'nfe_documentos', ?, ?, 'contasareceber', 'erro', ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    lote_id = VALUES(lote_id), destino_banco = 'contasareceber', status = 'erro', payload_json = VALUES(payload_json), erro_mensagem = VALUES(erro_mensagem), updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->bind_param(
                'iisssssssdddssi',
                $loteId,
                $id,
                $item['origem_ref'],
                $lanc['ndocumento'],
                $item['cliente'],
                $item['cnpj'],
                $lanc['dataemissao'],
                $lanc['vencimento'],
                $lanc['competencia'],
                $lanc['valor'],
                $calc['desconto'],
                $lanc['valortotal'],
                $payloadJson,
                $erroMsg,
                $usuarioId
            );
            $stmt->execute();
            $integracaoId = (int) ($conexao->insert_id ?: ($docs[$id]['integracao_id'] ?? 0));
            $stmt->close();
            contasReceberRecordEvento($conexao, $integracaoId ?: null, $loteId, 'bloqueado', $erroMsg, $item['snapshot'] ?? []);
            $resultados[] = ['origem_id' => $id, 'status' => 'bloqueado', 'mensagem' => $erroMsg, 'erros' => $item['erros']];
            continue;
        }

        $lanc = $item['lancamento'];
        $calc = $item['snapshot']['calculo'];

        try {
            $numeroOs = trim((string) ($docs[$id]['numero_os'] ?? ''));
            if ($numeroOs === '') {
                throw new RuntimeException('Numero da OS ausente; nao foi possivel vincular a comissao de frete.');
            }

            $stmtVendaFrete = $conexao->prepare("
                SELECT id, entreposto
                FROM vendas
                WHERE numero_os = ? AND TRIM(COALESCE(entreposto, '')) <> ''
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmtVendaFrete->bind_param('s', $numeroOs);
            $stmtVendaFrete->execute();
            $vendaFrete = $stmtVendaFrete->get_result()->fetch_assoc();
            $stmtVendaFrete->close();

            $vendaIdFrete = (int) ($vendaFrete['id'] ?? 0);
            $entrepostoFrete = comissoesFreteNormalizarEntreposto((string) ($vendaFrete['entreposto'] ?? ''));
            $resolucaoVendedor = comissoesVendedoresResolverPorOs($conexao, $numeroOs);

            // Fallback: venda arquivada no fechamento de ciclo sai de `vendas` e vai
            // para ciclo_snapshot_registros; o entreposto fica salvo no payload_json.
            if ($entrepostoFrete === null) {
                $stmtSnapFrete = $conexao->prepare("
                    SELECT payload_json
                    FROM ciclo_snapshot_registros
                    WHERE numero_os = ? AND origem_tabela = 'vendas'
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $stmtSnapFrete->bind_param('s', $numeroOs);
                $stmtSnapFrete->execute();
                $snapFrete = $stmtSnapFrete->get_result()->fetch_assoc();
                $stmtSnapFrete->close();
                if ($snapFrete) {
                    $snapDataFrete = json_decode((string) $snapFrete['payload_json'], true);
                    $entrepostoSnap = comissoesFreteNormalizarEntreposto((string) ($snapDataFrete['entreposto'] ?? ''));
                    if ($entrepostoSnap !== null) {
                        $entrepostoFrete = $entrepostoSnap;
                        // venda nao existe mais em `vendas` (FK) -> registra com venda_id nulo
                        $vendaIdFrete = 0;
                    }
                }
            }

            $conexao->begin_transaction();
            $destino->begin_transaction();

            $clienteDestino = contasReceberEnsureClienteDestino($destino, contasReceberNormalizeDoc($lanc['cnpj']), $lanc['nomefantasia']);
            if (!empty($clienteDestino['nome'])) {
                $lanc['nomefantasia'] = (string) $clienteDestino['nome'];
                $item['snapshot']['lancamento']['nomefantasia'] = $lanc['nomefantasia'];
                $item['snapshot']['cliente']['cliente_destino'] = $clienteDestino;
                $item['snapshot']['cliente']['nome_destino'] = $lanc['nomefantasia'];
            }

            $stmtDest = $destino->prepare("
                INSERT INTO lancamentos
                    (ndocumento, tipo, nomefantasia, vencimento, dataemissao, obs, valor, datapgto, categoria, desconto, valortotal, parcela, nparcela, conta, situacao, acrescimo, competencia, centrocusto, cnpj)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?)
            ");
            $stmtDest->bind_param(
                'ssssssdssddssdsss',
                $lanc['ndocumento'],
                $lanc['tipo'],
                $lanc['nomefantasia'],
                $lanc['vencimento'],
                $lanc['dataemissao'],
                $lanc['obs'],
                $lanc['valor'],
                $lanc['datapgto'],
                $lanc['categoria'],
                $lanc['desconto'],
                $lanc['valortotal'],
                $lanc['conta'],
                $lanc['situacao'],
                $lanc['acrescimo'],
                $lanc['competencia'],
                $lanc['centrocusto'],
                $lanc['cnpj']
            );
            $stmtDest->execute();
            $destinoId = (int) $stmtDest->insert_id;
            $stmtDest->close();

            $item['snapshot']['destino'] = ['banco' => CONTAS_RECEBER_DESTINO_DB, 'tabela' => 'lancamentos', 'id' => $destinoId];
            $payloadJson = json_encode($item['snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $stmt = $conexao->prepare("
                INSERT INTO contas_integracoes
                    (lote_id, tipo, origem_tabela, origem_id, origem_ref, destino_banco, destino_id, status, ndocumento, fornecedor_nome, cnpj, dataemissao, vencimento, competencia, valor, desconto_funrural, desconto_devolucao, desconto_total, valortotal, payload_json, criado_por, enviado_por, enviado_em)
                VALUES
                    (?, 'receber', 'nfe_documentos', ?, ?, 'contasareceber', ?, 'enviado', ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    lote_id = VALUES(lote_id), destino_banco = 'contasareceber', destino_id = VALUES(destino_id), status = 'enviado',
                    ndocumento = VALUES(ndocumento), fornecedor_nome = VALUES(fornecedor_nome), cnpj = VALUES(cnpj),
                    dataemissao = VALUES(dataemissao), vencimento = VALUES(vencimento), competencia = VALUES(competencia),
                    valor = VALUES(valor), desconto_total = VALUES(desconto_total), valortotal = VALUES(valortotal),
                    payload_json = VALUES(payload_json), erro_mensagem = NULL, enviado_por = VALUES(enviado_por), enviado_em = NOW(), updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->bind_param(
                'iisissssssdddsii',
                $loteId,
                $id,
                $item['origem_ref'],
                $destinoId,
                $lanc['ndocumento'],
                $lanc['nomefantasia'],
                $lanc['cnpj'],
                $lanc['dataemissao'],
                $lanc['vencimento'],
                $lanc['competencia'],
                $lanc['valor'],
                $calc['desconto'],
                $lanc['valortotal'],
                $payloadJson,
                $usuarioId,
                $usuarioId
            );
            $stmt->execute();
            $integracaoId = (int) ($conexao->insert_id ?: ($docs[$id]['integracao_id'] ?? 0));
            $stmt->close();

            // Entrega propria (Loja/Ceasa/retirada) nao tem freteiro -> envia sem comissao.
            if ($entrepostoFrete !== null) {
                comissoesFreteRegistrarEnvio(
                    $conexao,
                    $numeroOs,
                    $entrepostoFrete,
                    $vendaIdFrete,
                    $id,
                    $integracaoId,
                    (float) $lanc['valortotal']
                );
            }

            $destino->commit();
            $conexao->commit();
            contasReceberRecordEventoSeguro($conexao, $integracaoId ?: null, $loteId, 'enviado', 'Lancamento enviado ao contas a receber #' . $destinoId, $item['snapshot']);

            $resultadoComissao = ['status' => 'nao_aplicavel'];
            if (($resolucaoVendedor['status'] ?? '') === 'resolvido') {
                try {
                    $criada = comissoesVendedoresRegistrarEnvio(
                        $conexao,
                        $numeroOs,
                        (string) $resolucaoVendedor['vendedor'],
                        $resolucaoVendedor['vendedor_usuario_id'] ?? null,
                        $resolucaoVendedor['venda_id'] ?? null,
                        $id,
                        $integracaoId,
                        (float) $lanc['valortotal']
                    );
                    $resultadoComissao = ['status' => $criada ? 'criada' : 'ja_contabilizada'];
                } catch (Throwable $comissaoErro) {
                    $erroComissao = 'Falha tecnica na comissao do vendedor: ' . $comissaoErro->getMessage();
                    contasReceberRecordEventoSeguro($conexao, $integracaoId ?: null, $loteId, 'comissao_vendedor_erro', $erroComissao, [
                        'numero_os' => $numeroOs,
                        'nfe_documento_id' => $id,
                        'contas_integracao_id' => $integracaoId,
                        'resolucao_vendedor' => $resolucaoVendedor,
                    ]);
                    $resultadoComissao = ['status' => 'erro_tecnico', 'mensagem' => $erroComissao];
                    $erros++;
                }
            } elseif (in_array(($resolucaoVendedor['status'] ?? ''), ['ambiguo', 'json_invalido'], true)) {
                $detalhe = 'Comissao de vendedor nao criada: resolucao ' . (string) $resolucaoVendedor['status'] . '.';
                contasReceberRecordEventoSeguro($conexao, $integracaoId ?: null, $loteId, 'comissao_vendedor_inconsistente', $detalhe, [
                    'numero_os' => $numeroOs,
                    'nfe_documento_id' => $id,
                    'contas_integracao_id' => $integracaoId,
                    'resolucao_vendedor' => $resolucaoVendedor,
                ]);
                $resultadoComissao = ['status' => (string) $resolucaoVendedor['status']];
            }

            $enviados++;
            $resultados[] = ['origem_id' => $id, 'status' => 'enviado', 'destino_id' => $destinoId, 'comissao_vendedor' => $resultadoComissao, 'mensagem' => 'Enviado ao contas a receber #' . $destinoId];
        } catch (Throwable $e) {
            $destino->rollback();
            $conexao->rollback();
            $erros++;
            $erroMsg = 'Erro ao enviar ao contas a receber: ' . $e->getMessage();
            contasReceberRecordEvento($conexao, null, $loteId, 'erro_insert', $erroMsg, $item['snapshot'] ?? []);
            $resultados[] = ['origem_id' => $id, 'status' => 'erro', 'mensagem' => $erroMsg];
        }
    }

    $statusLote = $erros > 0 ? ($enviados > 0 ? 'concluido_com_erros' : 'erro') : 'concluido';
    $stmtUpd = $conexao->prepare("UPDATE contas_integracao_lotes SET status = ?, total_enviados = ?, total_erros = ?, enviado_em = NOW() WHERE id = ?");
    $stmtUpd->bind_param('siii', $statusLote, $enviados, $erros, $loteId);
    $stmtUpd->execute();
    $stmtUpd->close();

    contasReceberJsonResponse([
        'ok' => $erros === 0,
        'lote_id' => $loteId,
        'resumo' => ['selecionados' => count($ids), 'enviados' => $enviados, 'erros' => $erros],
        'itens' => $resultados,
    ]);
} catch (Throwable $e) {
    contasReceberJsonResponse(['ok' => false, 'erro_critico' => $e->getMessage()], 500);
}

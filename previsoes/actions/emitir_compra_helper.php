<?php

function emitirCompraDiretoHistorico(mysqli $conexao, string $numeroOs, int $usuarioMontagemId, string $responsavel = ''): int
{
    $numeroOs = trim($numeroOs);
    if ($numeroOs === '') {
        throw new RuntimeException('Informe a OS para emitir.');
    }

    pedidoCompraTabelaSnapshotGarantida($conexao);
    cadastroFiscalExigirOsCompra($conexao, $numeroOs, 'anexado');

    $stmtItens = $conexao->prepare("
        SELECT *
        FROM previsao_fornecedor
        WHERE numero_os = ? AND status = 'anexado'
          AND (usuario_montagem_id = ? OR usuario_montagem_id IS NULL)
        FOR UPDATE
    ");
    $stmtItens->bind_param('si', $numeroOs, $usuarioMontagemId);
    $stmtItens->execute();
    $itens = $stmtItens->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtItens->close();

    if (empty($itens)) {
        throw new RuntimeException("Nenhuma compra anexada encontrada para OS '{$numeroOs}'.");
    }

    foreach ($itens as $item) {
        $id = (int) $item['id'];
        $produtoId = (int) $item['produto_id'];
        $fornecedorId = (int) $item['fornecedor_id'];
        $quantidade = (float) $item['quantidade_prevista'];
        if ($quantidade <= 0) {
            throw new RuntimeException("Quantidade invalida na OS '{$numeroOs}'.");
        }

        $cicloBaseId = isset($item['ciclo_id']) ? (int) $item['ciclo_id'] : null;
        $cicloEntradaId = colunaExiste($conexao, 'entradas', 'ciclo_id') ? ($cicloBaseId ?: getCicloAtivoId($conexao)) : null;

        $stmtUpdate = $conexao->prepare("
            UPDATE previsao_fornecedor
            SET status = 'concluido', quantidade_recebida = ?, motivo_abatimento = NULL
            WHERE id = ? AND status = 'anexado'
        ");
        $stmtUpdate->bind_param('di', $quantidade, $id);
        $stmtUpdate->execute();
        if ($stmtUpdate->affected_rows <= 0) {
            $stmtUpdate->close();
            throw new RuntimeException("Compra anexada nao encontrada para emitir.");
        }
        $stmtUpdate->close();

        if ($cicloEntradaId !== null) {
            $stmtEntrada = $conexao->prepare("
                INSERT INTO entradas
                    (ciclo_id, produto_id, fornecedor_id, quantidade, tipo, data_entrada, motivo_abatimento, numero_os)
                VALUES (?, ?, ?, ?, 'entrada_fornecedor', NOW(), NULL, ?)
            ");
            $stmtEntrada->bind_param('iiids', $cicloEntradaId, $produtoId, $fornecedorId, $quantidade, $numeroOs);
        } else {
            $stmtEntrada = $conexao->prepare("
                INSERT INTO entradas
                    (produto_id, fornecedor_id, quantidade, tipo, data_entrada, motivo_abatimento, numero_os)
                VALUES (?, ?, ?, 'entrada_fornecedor', NOW(), NULL, ?)
            ");
            $stmtEntrada->bind_param('iids', $produtoId, $fornecedorId, $quantidade, $numeroOs);
        }
        $stmtEntrada->execute();
        $stmtEntrada->close();
    }

    pedidoCompraAtualizarSnapshotSePossivel($conexao, $numeroOs, $responsavel);

    return count($itens);
}

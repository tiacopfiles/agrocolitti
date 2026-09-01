<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/ciclo_helper.php";
require __DIR__ . "/../../config/previsao_fornecedor_schema.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../pedido_compra_helper.php";
require __DIR__ . "/../../config/cadastro_fiscal_helper.php";

garantirFluxoFinanceiroPrevisaoFornecedor($conexao);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../previsao_fornecedor.php");
    exit;
}

$numeroOs = trim((string) ($_POST['numero_os'] ?? ''));
if ($numeroOs === '') {
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=" . urlencode("OS invalida."));
    exit;
}

$recebimentos = is_array($_POST['recebimento'] ?? null) ? $_POST['recebimento'] : [];

pedidoCompraTabelaSnapshotGarantida($conexao);
$conexao->begin_transaction();

try {
    cadastroFiscalExigirOsCompra($conexao, $numeroOs, 'pendente');
    $stmtItens = $conexao->prepare("
        SELECT *
        FROM previsao_fornecedor
        WHERE numero_os = ? AND status = 'pendente'
        FOR UPDATE
    ");
    $stmtItens->bind_param("s", $numeroOs);
    $stmtItens->execute();
    $itens = $stmtItens->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtItens->close();

    if (empty($itens)) {
        throw new Exception("Nenhuma previsao pendente encontrada para OS '$numeroOs'.");
    }

    foreach ($itens as $item) {
        $id = (int) $item['id'];
        $produtoId = (int) $item['produto_id'];
        $fornecedorId = (int) $item['fornecedor_id'];
        $revendaSaoPaulo = !empty($item['revenda_sao_paulo']);
        $quantidadePrevista = (float) $item['quantidade_prevista'];
        $dadosRecebimento = is_array($recebimentos[$id] ?? null) ? $recebimentos[$id] : [];
        $quantidadeRealInformada = isset($dadosRecebimento['quantidade_recebida'])
            ? (float) str_replace(',', '.', (string) $dadosRecebimento['quantidade_recebida'])
            : 0.0;
        $quantidadeAbatida = isset($dadosRecebimento['quantidade_abatida'])
            ? (float) str_replace(',', '.', (string) $dadosRecebimento['quantidade_abatida'])
            : 0.0;
        $quantidadeAbatida = max(0.0, $quantidadeAbatida);
        $excluirItem = !empty($dadosRecebimento['excluir']);
        if ($excluirItem) {
            $stmtDelete = $conexao->prepare("DELETE FROM previsao_fornecedor WHERE id = ? AND status = 'pendente'");
            $stmtDelete->bind_param("i", $id);
            $stmtDelete->execute();
            $stmtDelete->close();
            continue;
        }

        $quantidade = max(0.0, ($quantidadeRealInformada > 0 ? $quantidadeRealInformada : $quantidadePrevista) - $quantidadeAbatida);
        $motivoFinal = $quantidadeAbatida > 0 ? trim((string) ($dadosRecebimento['motivo'] ?? '')) : null;

        if ($quantidadeAbatida > 0 && $motivoFinal === '') {
            throw new Exception("Informe o motivo para todos os produtos com abate.");
        }
        if ($quantidade <= 0) {
            throw new Exception("Quantidade recebida invalida na OS '$numeroOs'. Para produto que nao chegou, marque a opcao de excluir.");
        }

        $cicloBaseId = isset($item['ciclo_id']) ? (int) $item['ciclo_id'] : null;
        $cicloEntradaId = colunaExiste($conexao, 'entradas', 'ciclo_id') ? ($cicloBaseId ?: getCicloAtivoId($conexao)) : null;
        $cicloMovId = colunaExiste($conexao, 'movimentacoes', 'ciclo_id') ? ($cicloBaseId ?: getCicloAtivoId($conexao)) : null;
        $cicloAbateId = colunaExiste($conexao, 'abates', 'ciclo_id') ? ($cicloBaseId ?: getCicloAtivoId($conexao)) : null;

        $stmtUpdate = $conexao->prepare("
            UPDATE previsao_fornecedor
            SET status = 'concluido', motivo_abatimento = ?, quantidade_recebida = ?
            WHERE id = ? AND status = 'pendente'
        ");
        $stmtUpdate->bind_param("sdi", $motivoFinal, $quantidade, $id);
        $stmtUpdate->execute();
        if ($stmtUpdate->affected_rows <= 0) {
            $stmtUpdate->close();
            throw new Exception("Previsao pendente nao encontrada para finalizar");
        }
        $stmtUpdate->close();

        if ($cicloEntradaId !== null) {
            $stmtEntrada = $conexao->prepare("
                INSERT INTO entradas
                    (ciclo_id, produto_id, fornecedor_id, quantidade, tipo, data_entrada, motivo_abatimento, numero_os)
                VALUES (?, ?, ?, ?, 'entrada_fornecedor', NOW(), ?, ?)
            ");
            $stmtEntrada->bind_param("iiidss", $cicloEntradaId, $produtoId, $fornecedorId, $quantidade, $motivoFinal, $numeroOs);
        } else {
            $stmtEntrada = $conexao->prepare("
                INSERT INTO entradas
                    (produto_id, fornecedor_id, quantidade, tipo, data_entrada, motivo_abatimento, numero_os)
                VALUES (?, ?, ?, 'entrada_fornecedor', NOW(), ?, ?)
            ");
            $stmtEntrada->bind_param("iidss", $produtoId, $fornecedorId, $quantidade, $motivoFinal, $numeroOs);
        }
        $stmtEntrada->execute();
        $stmtEntrada->close();

        if (!$revendaSaoPaulo) {
            if ($cicloMovId !== null) {
                $stmtMov = $conexao->prepare("
                    INSERT INTO movimentacoes (ciclo_id, produto_id, tipo, quantidade, data_movimentacao)
                    VALUES (?, ?, 'entrada_fornecedor', ?, NOW())
                ");
                $stmtMov->bind_param("iid", $cicloMovId, $produtoId, $quantidade);
            } else {
                $stmtMov = $conexao->prepare("
                    INSERT INTO movimentacoes (produto_id, tipo, quantidade, data_movimentacao)
                    VALUES (?, 'entrada_fornecedor', ?, NOW())
                ");
                $stmtMov->bind_param("id", $produtoId, $quantidade);
            }
            $stmtMov->execute();
            $stmtMov->close();
        }

        if ($quantidadeAbatida > 0) {
            if ($cicloAbateId !== null) {
                $stmtAbate = $conexao->prepare("
                    INSERT INTO abates (ciclo_id, tipo, previsao_id, quantidade_original, quantidade_abatida, quantidade_final, motivo)
                    VALUES (?, 'fornecedor', ?, ?, ?, ?, ?)
                ");
                $stmtAbate->bind_param("iiddds", $cicloAbateId, $id, $quantidadePrevista, $quantidadeAbatida, $quantidade, $motivoFinal);
            } else {
                $stmtAbate = $conexao->prepare("
                    INSERT INTO abates (tipo, previsao_id, quantidade_original, quantidade_abatida, quantidade_final, motivo)
                    VALUES ('fornecedor', ?, ?, ?, ?, ?)
                ");
                $stmtAbate->bind_param("iddds", $id, $quantidadePrevista, $quantidadeAbatida, $quantidade, $motivoFinal);
            }
            $stmtAbate->execute();
            $stmtAbate->close();
        }
    }

    $responsavel = $_SESSION['usuario_nome'] ?? $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '';
    pedidoCompraAtualizarSnapshotSePossivel($conexao, $numeroOs, (string) $responsavel);

    $conexao->commit();

    header("Location: ../previsao_fornecedor.php?msg=entrada_registrada&total=" . count($itens));
    exit;
} catch (Exception $e) {
    $conexao->rollback();
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}

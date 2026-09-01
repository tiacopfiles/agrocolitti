<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/ciclo_helper.php";
require __DIR__ . "/../../config/log_helper.php";
require __DIR__ . "/../../auth/proteger.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../vendas.php");
    exit;
}

validarTokenCsrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    header("Location: ../vendas.php?msg=erro&detalhe=ID+invalido");
    exit;
}
$recebimentos = is_array($_POST['recebimento'] ?? null) ? $_POST['recebimento'] : [];

$conexao->begin_transaction();

try {
    // Bloqueia a linha para leitura exclusiva durante a transação
    $stmtVenda = $conexao->prepare("SELECT * FROM vendas WHERE id = ? FOR UPDATE");
    $stmtVenda->bind_param("i", $id);
    $stmtVenda->execute();
    $venda = $stmtVenda->get_result()->fetch_assoc();
    $stmtVenda->close();

    if (!$venda) {
        throw new Exception("Venda não encontrada.");
    }

    if ($venda['status'] !== 'pendente') {
        throw new Exception("Esta venda já foi processada (status: {$venda['status']}).");
    }

    // ── Validação de estoque ──────────────────────────────────────────────────
    // Calcula o estoque disponível para o produto ANTES de confirmar a venda.
    $produtoId       = (int) $venda['produto_id'];
    $quantidadeVenda = (float) $venda['quantidade'];
    $cicloId         = isset($venda['ciclo_id']) ? (int) $venda['ciclo_id'] : null;
    $dadosRecebimento = is_array($recebimentos[$id] ?? null) ? $recebimentos[$id] : [];

    if (!empty($dadosRecebimento['excluir'])) {
        $stmtDelete = $conexao->prepare("DELETE FROM vendas WHERE id = ? AND status = 'pendente'");
        $stmtDelete->bind_param("i", $id);
        $stmtDelete->execute();
        $stmtDelete->close();
        $conexao->commit();
        header("Location: ../vendas.php?msg=excluido");
        exit;
    }

    $quantidadeEntregue = isset($dadosRecebimento['quantidade_entregue'])
        ? (float) str_replace(',', '.', (string) $dadosRecebimento['quantidade_entregue'])
        : 0.0;
    $quantidadeAbatida = isset($dadosRecebimento['quantidade_abatida'])
        ? max(0.0, (float) str_replace(',', '.', (string) $dadosRecebimento['quantidade_abatida']))
        : 0.0;
    $quantidadeVenda = max(0.0, ($quantidadeEntregue > 0 ? $quantidadeEntregue : $quantidadeVenda) - $quantidadeAbatida);
    $motivoAbate = $quantidadeAbatida > 0 ? trim((string) ($dadosRecebimento['motivo'] ?? '')) : null;

    if ($quantidadeAbatida > 0 && $motivoAbate === '') {
        throw new Exception("Informe o motivo do abatimento.");
    }
    if ($quantidadeVenda <= 0) {
        throw new Exception("Quantidade final invalida. Para produto que nao saiu, marque a opcao de excluir.");
    }


        throw new Exception(
        );
    }

    if (strtolower((string) ($venda['tipo'] ?? '')) === 'kg') {
        $stmtQtd = $conexao->prepare("UPDATE vendas SET quantidade = ?, pedido = ? WHERE id = ?");
        $stmtQtd->bind_param("ddi", $quantidadeVenda, $quantidadeVenda, $id);
    } else {
        $stmtQtd = $conexao->prepare("UPDATE vendas SET quantidade = ? WHERE id = ?");
        $stmtQtd->bind_param("di", $quantidadeVenda, $id);
    }
    $stmtQtd->execute();
    $stmtQtd->close();

    // ── Confirma a venda ──────────────────────────────────────────────────────
    $stmtUpdate = $conexao->prepare("UPDATE vendas SET status = 'concluido' WHERE id = ?");
    $stmtUpdate->bind_param("i", $id);
    $stmtUpdate->execute();
    $stmtUpdate->close();

    // ── Registra movimentação ─────────────────────────────────────────────────
    $cicloMovimentacaoId = colunaExiste($conexao, 'movimentacoes', 'ciclo_id')
        ? ($cicloId ?: getCicloAtivoId($conexao))
        : null;

    if ($cicloMovimentacaoId !== null) {
        $stmtMov = $conexao->prepare("
            INSERT INTO movimentacoes (ciclo_id, produto_id, tipo, quantidade, data_movimentacao)
            VALUES (?, ?, 'venda', ?, NOW())
        ");
        $stmtMov->bind_param("iid", $cicloMovimentacaoId, $produtoId, $quantidadeVenda);
    } else {
        $stmtMov = $conexao->prepare("
            INSERT INTO movimentacoes (produto_id, tipo, quantidade, data_movimentacao)
            VALUES (?, 'venda', ?, NOW())
        ");
        $stmtMov->bind_param("id", $produtoId, $quantidadeVenda);
    }
    $stmtMov->execute();
    $stmtMov->close();

    $conexao->commit();

    registrarLog(
        $conexao,
        'venda_confirmada',
        'vendas',
        $id,
        "Venda #{$id} confirmada — produto_id {$produtoId}, cliente_id {$venda['cliente_id']}, quantidade {$quantidadeVenda} kg"
    );

    header("Location: ../vendas.php?msg=confirmado");
    exit;

} catch (Exception $e) {
    $conexao->rollback();
    $msg = urlencode($e->getMessage());
    header("Location: ../vendas.php?msg=erro&detalhe={$msg}");
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Calcula o estoque disponível de um produto no ciclo, descontando apenas
 * as vendas já confirmadas (status = 'concluido'). Vendas pendentes/anexadas
 * não deduzem estoque — só confirmadas fazem.
 *
 * Fórmula: estoque_inicial + entradas_confirmadas − vendas_concluidas
 */
{
    // Estoque inicial do ciclo
    if ($cicloId && colunaExiste($conexao, 'estoque_inicial', 'ciclo_id')) {
        $stmtEI = $conexao->prepare("
            SELECT IFNULL(SUM(quantidade), 0) AS total
            FROM estoque_inicial
            WHERE produto_id = ? AND ciclo_id = ?
        ");
        $stmtEI->bind_param("ii", $produtoId, $cicloId);
    } else {
        $stmtEI = $conexao->prepare("
            SELECT IFNULL(SUM(quantidade), 0) AS total
            FROM estoque_inicial
            WHERE produto_id = ?
        ");
        $stmtEI->bind_param("i", $produtoId);
    }
    $stmtEI->execute();
    $estoqueInicial = (float) ($stmtEI->get_result()->fetch_assoc()['total'] ?? 0);
    $stmtEI->close();

    // Entradas confirmadas no ciclo
    if ($cicloId && colunaExiste($conexao, 'entradas', 'ciclo_id')) {
        $stmtEnt = $conexao->prepare("
            SELECT IFNULL(SUM(quantidade), 0) AS total
            FROM entradas
            WHERE produto_id = ? AND ciclo_id = ?
        ");
        $stmtEnt->bind_param("ii", $produtoId, $cicloId);
    } else {
        $stmtEnt = $conexao->prepare("
            SELECT IFNULL(SUM(quantidade), 0) AS total
            FROM entradas
            WHERE produto_id = ?
        ");
        $stmtEnt->bind_param("i", $produtoId);
    }
    $stmtEnt->execute();
    $totalEntradas = (float) ($stmtEnt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmtEnt->close();

    // Vendas já concluídas no ciclo (já saíram do estoque)
    if ($cicloId && colunaExiste($conexao, 'vendas', 'ciclo_id')) {
        $stmtVend = $conexao->prepare("
            SELECT IFNULL(SUM(quantidade), 0) AS total
            FROM vendas
            WHERE produto_id = ? AND ciclo_id = ? AND status = 'concluido'
        ");
        $stmtVend->bind_param("ii", $produtoId, $cicloId);
    } else {
        $stmtVend = $conexao->prepare("
            SELECT IFNULL(SUM(quantidade), 0) AS total
            FROM vendas
            WHERE produto_id = ? AND status = 'concluido'
        ");
        $stmtVend->bind_param("i", $produtoId);
    }
    $stmtVend->execute();
    $totalVendido = (float) ($stmtVend->get_result()->fetch_assoc()['total'] ?? 0);
    $stmtVend->close();

    return $estoqueInicial + $totalEntradas - $totalVendido;
}

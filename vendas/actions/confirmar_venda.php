<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/ciclo_helper.php";
require __DIR__ . "/../../config/log_helper.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../../config/cadastro_fiscal_helper.php";
require __DIR__ . "/../../config/produto_vinculo_helper.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../vendas.php");
    exit;
}

validarTokenCsrf();

function quantidadeComercialConfirmacaoVenda(array $venda): float
{
    $tipo = strtolower((string) ($venda['tipo'] ?? 'kg'));
    if ($tipo === 'kg') {
        return (float) ($venda['quantidade'] ?? $venda['pedido'] ?? 0);
    }
    return (float) ($venda['pedido'] ?? 0);
}

function quantidadeEstoqueConfirmacaoVenda(array $venda, float $quantidadeComercial): float
{
    $tipo = strtolower((string) ($venda['tipo'] ?? 'kg'));
    if ($tipo === 'bandeja') {
        $gramagem = (float) ($venda['gramagem'] ?? 0);
        if ($gramagem <= 0) {
            throw new Exception('Venda em bandeja sem gramagem cadastrada.');
        }
        return ($quantidadeComercial * $gramagem) / 1000;
    }
    if ($tipo === 'caixa') {
        $kgCaixa = (float) ($venda['kg_caixa'] ?? 0);
        if ($kgCaixa <= 0) {
            throw new Exception('Venda em caixa sem kg por caixa cadastrado.');
        }
        return $quantidadeComercial * $kgCaixa;
    }
    return $quantidadeComercial;
}
$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    header("Location: ../vendas.php?msg=erro&detalhe=ID+invalido");
    exit;
}
$recebimentos = is_array($_POST['recebimento'] ?? null) ? $_POST['recebimento'] : [];
$caixaId  = (int) ($_POST['caixa_id']   ?? 0) ?: null;
$numCaixas = (int) ($_POST['num_caixas'] ?? 0);

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
    cadastroFiscalExigirVenda($conexao, (int) $venda['cliente_id'], (int) $venda['produto_id']);
    $produtoId       = (int) $venda['produto_id'];
    $produtoEstoqueId = produtoEstoqueId($conexao, $produtoId);
    $quantidadeVenda = quantidadeComercialConfirmacaoVenda($venda);
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
    $quantidadeVendaComercial = max(0.0, ($quantidadeEntregue > 0 ? $quantidadeEntregue : $quantidadeVenda) - $quantidadeAbatida);
    $quantidadeVenda = quantidadeEstoqueConfirmacaoVenda($venda, $quantidadeVendaComercial);
    $motivoAbate = $quantidadeAbatida > 0 ? trim((string) ($dadosRecebimento['motivo'] ?? '')) : null;

    if ($quantidadeAbatida > 0 && $motivoAbate === '') {
        throw new Exception("Informe o motivo do abatimento.");
    }
    if ($quantidadeVenda <= 0) {
        throw new Exception("Quantidade final invalida. Para produto que nao saiu, marque a opcao de excluir.");
    }

    // Validação de estoque removida para permitir estoque negativo conforme solicitado.
    $stmtQtd = $conexao->prepare("UPDATE vendas SET quantidade = ?, pedido = ? WHERE id = ?");

    $stmtQtd->bind_param("ddi", $quantidadeVenda, $quantidadeVendaComercial, $id);
    $stmtQtd->execute();
    $stmtQtd->close();

    // ── Garante coluna data_confirmacao
    $conexao->query("ALTER TABLE vendas ADD COLUMN IF NOT EXISTS data_confirmacao DATETIME NULL DEFAULT NULL");

    // ── Confirma a venda; embalagem e caixas são opcionais e podem ficar neutras.
    if (strtolower((string) ($venda['tipo_comercial'] ?? '')) === 'oba_embalado') {
        $stmtUpdate = $conexao->prepare("UPDATE vendas SET status = 'concluido', caixa_id = ?, data_confirmacao = NOW() WHERE id = ?");
        $stmtUpdate->bind_param("ii", $caixaId, $id);
    } else {
        $stmtUpdate = $conexao->prepare("UPDATE vendas SET status = 'concluido', caixa_id = ?, num_caixas = ?, data_confirmacao = NOW() WHERE id = ?");
        $stmtUpdate->bind_param("iii", $caixaId, $numCaixas, $id);
    }
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
        $stmtMov->bind_param("iid", $cicloMovimentacaoId, $produtoEstoqueId, $quantidadeVenda);
    } else {
        $stmtMov = $conexao->prepare("
            INSERT INTO movimentacoes (produto_id, tipo, quantidade, data_movimentacao)
            VALUES (?, 'venda', ?, NOW())
        ");
        $stmtMov->bind_param("id", $produtoEstoqueId, $quantidadeVenda);
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
function calcularEstoqueDisponivel(mysqli $conexao, int $produtoId, ?int $cicloId): float
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

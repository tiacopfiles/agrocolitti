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
$numero_os = trim($_POST['numero_os'] ?? '');
if ($numero_os === '') {
    header("Location: ../vendas.php?msg=erro&detalhe=" . urlencode("OS inválida."));
    exit;
}
$recebimentos = is_array($_POST['recebimento'] ?? null) ? $_POST['recebimento'] : [];
$caixaId   = (int) ($_POST['caixa_id']   ?? 0) ?: null;
$numCaixas = (int) ($_POST['num_caixas'] ?? 0);

// A data de confirmacao alimenta a data impressa no pedido Word do historico.
$conexao->query("ALTER TABLE vendas ADD COLUMN IF NOT EXISTS data_confirmacao DATETIME NULL DEFAULT NULL");

$conexao->begin_transaction();

try {
    cadastroFiscalExigirOsVenda($conexao, $numero_os, 'pendente');
    $stmtVendas = $conexao->prepare("
        SELECT * FROM vendas
        WHERE numero_os = ? AND status = 'pendente'
        FOR UPDATE
    ");
    $stmtVendas->bind_param("s", $numero_os);
    $stmtVendas->execute();
    $vendas = $stmtVendas->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtVendas->close();

    if (empty($vendas)) {
        throw new Exception("Nenhuma venda pendente encontrada para OS '$numero_os'.");
    }

    $temColunaMovCiclo = colunaExiste($conexao, 'movimentacoes', 'ciclo_id');
    $cicloAtivoId = $temColunaMovCiclo ? getCicloAtivoId($conexao) : null;

    foreach ($vendas as $venda) {
        $vendaId = (int) $venda['id'];
        $dadosRecebimento = is_array($recebimentos[$vendaId] ?? null) ? $recebimentos[$vendaId] : [];
        if (!empty($dadosRecebimento['excluir'])) {
            $stmtDelete = $conexao->prepare("DELETE FROM vendas WHERE id = ? AND status = 'pendente'");
            $stmtDelete->bind_param("i", $vendaId);
            $stmtDelete->execute();
            $stmtDelete->close();
            continue;
        }

        $quantidadeOriginal = quantidadeComercialConfirmacaoVenda($venda);
        $quantidadeEntregue = isset($dadosRecebimento['quantidade_entregue'])
            ? (float) str_replace(',', '.', (string) $dadosRecebimento['quantidade_entregue'])
            : 0.0;
        $quantidadeAbatida = isset($dadosRecebimento['quantidade_abatida'])
            ? max(0.0, (float) str_replace(',', '.', (string) $dadosRecebimento['quantidade_abatida']))
            : 0.0;
        $quantidadeFinalComercial = max(0.0, ($quantidadeEntregue > 0 ? $quantidadeEntregue : $quantidadeOriginal) - $quantidadeAbatida);
        $quantidadeFinal = quantidadeEstoqueConfirmacaoVenda($venda, $quantidadeFinalComercial);
        $motivoAbate = $quantidadeAbatida > 0 ? trim((string) ($dadosRecebimento['motivo'] ?? '')) : null;
        if ($quantidadeAbatida > 0 && $motivoAbate === '') {
            throw new Exception("Informe o motivo para todos os produtos com abate.");
        }
        if ($quantidadeFinal <= 0) {
            throw new Exception("Quantidade final invalida na OS '$numero_os'. Para produto que nao saiu, marque a opcao de excluir.");
        }

        $stmtQtd = $conexao->prepare("UPDATE vendas SET quantidade = ?, pedido = ? WHERE id = ?");
        $stmtQtd->bind_param("ddi", $quantidadeFinal, $quantidadeFinalComercial, $vendaId);
        $stmtQtd->execute();
        $stmtQtd->close();

        if (strtolower((string) ($venda['tipo_comercial'] ?? '')) === 'oba_embalado') {
            $stmtUpdate = $conexao->prepare("UPDATE vendas SET status = 'concluido', caixa_id = ?, data_confirmacao = NOW() WHERE id = ?");
            $stmtUpdate->bind_param("ii", $caixaId, $vendaId);
        } else {
            $stmtUpdate = $conexao->prepare("UPDATE vendas SET status = 'concluido', caixa_id = ?, num_caixas = ?, data_confirmacao = NOW() WHERE id = ?");
            $stmtUpdate->bind_param("iii", $caixaId, $numCaixas, $vendaId);
        }
        $stmtUpdate->execute();
        $stmtUpdate->close();

        $cicloMovId = $temColunaMovCiclo
            ? ((isset($venda['ciclo_id']) && (int) $venda['ciclo_id'] > 0 ? (int) $venda['ciclo_id'] : $cicloAtivoId))
            : null;

        $produtoId = (int) $venda['produto_id'];
        $produtoEstoqueId = produtoEstoqueId($conexao, $produtoId);
        $qtd       = $quantidadeFinal;

        if ($cicloMovId !== null) {
            $stmtMov = $conexao->prepare("
                INSERT INTO movimentacoes (ciclo_id, produto_id, tipo, quantidade, data_movimentacao)
                VALUES (?, ?, 'venda', ?, NOW())
            ");
            $stmtMov->bind_param("iid", $cicloMovId, $produtoEstoqueId, $qtd);
        } else {
            $stmtMov = $conexao->prepare("
                INSERT INTO movimentacoes (produto_id, tipo, quantidade, data_movimentacao)
                VALUES (?, 'venda', ?, NOW())
            ");
            $stmtMov->bind_param("id", $produtoEstoqueId, $qtd);
        }
        $stmtMov->execute();
        $stmtMov->close();

        registrarLog($conexao, 'venda_confirmada', 'vendas', $venda['id'],
            "Venda #{$venda['id']} confirmada via OS '$numero_os'");
    }

    $conexao->commit();

    header("Location: ../vendas.php?msg=confirmado");
    exit;
} catch (Exception $e) {
    $conexao->rollback();
    header("Location: ../vendas.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}

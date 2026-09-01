<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../auth/proteger.php";

if (!in_array($_SESSION['usuario_nivel'], ['admin', 'operacional'], true)) {
    die("Sem permissao.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../entradas.php");
    exit;
}

function normalizarTipoMovimentacao(string $tipoEntrada): string
{
    return $tipoEntrada === 'colheita' ? 'entrada_colheita' : 'entrada_fornecedor';
}

function buscarMovimentacaoRelacionada(mysqli $conexao, array $entrada): ?array
{
    $tipoMovimentacao = normalizarTipoMovimentacao((string) $entrada['tipo']);
    $sql = "
        SELECT id
        FROM movimentacoes
        WHERE ciclo_id <=> ?
          AND produto_id = ?
          AND tipo = ?
          AND quantidade = ?
        ORDER BY ABS(TIMESTAMPDIFF(SECOND, data_movimentacao, ?)) ASC, id DESC
        LIMIT 1
    ";

    $stmt = $conexao->prepare($sql);
    $stmt->bind_param(
        "iisds",
        $entrada['ciclo_id'],
        $entrada['produto_id'],
        $tipoMovimentacao,
        $entrada['quantidade'],
        $entrada['data_entrada']
    );
    $stmt->execute();
    $movimentacao = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $movimentacao;
}

$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    header("Location: ../entradas.php?msg=erro&detalhe=ID+invalido");
    exit;
}

$conexao->begin_transaction();

try {
    // Busca a entrada antes de excluir para localizar a movimentacao correspondente.
    $stmtEntrada = $conexao->prepare("SELECT * FROM entradas WHERE id = ? FOR UPDATE");
    $stmtEntrada->bind_param("i", $id);
    $stmtEntrada->execute();
    $entradaAtual = $stmtEntrada->get_result()->fetch_assoc();
    $stmtEntrada->close();

    if (!$entradaAtual) {
        throw new Exception("Entrada nao encontrada.");
    }

    $movimentacao = buscarMovimentacaoRelacionada($conexao, $entradaAtual);

    // Exclui a movimentacao primeiro para manter o estoque consistente.
    if ($movimentacao) {
        $stmtDeleteMov = $conexao->prepare("DELETE FROM movimentacoes WHERE id = ?");
        $stmtDeleteMov->bind_param("i", $movimentacao['id']);
        $stmtDeleteMov->execute();
        $stmtDeleteMov->close();
    }

    $stmtDeleteEntrada = $conexao->prepare("DELETE FROM entradas WHERE id = ?");
    $stmtDeleteEntrada->bind_param("i", $id);
    $stmtDeleteEntrada->execute();
    $stmtDeleteEntrada->close();

    $conexao->commit();

    header("Location: ../entradas.php?msg=excluido");
    exit;
} catch (Throwable $e) {
    $conexao->rollback();
    header("Location: ../entradas.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}

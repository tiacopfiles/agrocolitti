<?php
require_once __DIR__ . "/../../config/conexao.php";
require_once __DIR__ . "/../../config/ciclo_helper.php";
require_once __DIR__ . "/../../auth/proteger.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../estoque_inicial_aba.php");
    exit;
}

$cicloId = getCicloAtivoId($conexao);
if (!$cicloId) {
    header("Location: ../estoque_inicial_aba.php?msg=erro&detalhe=" . urlencode("Nenhum ciclo ativo."));
    exit;
}

$qtds = isset($_POST['qtd']) && is_array($_POST['qtd']) ? $_POST['qtd'] : array();
if (empty($qtds)) {
    header("Location: ../estoque_inicial_aba.php");
    exit;
}

$conexao->begin_transaction();

try {
    // Esta tela altera a base inicial, nao o valor exibido apos movimentacoes.
    $stmtInicial = $conexao->prepare("
        UPDATE estoque_inicial
        SET quantidade = ?
        WHERE ciclo_id = ? AND produto_id = ?
    ");

    $valoresDesejados = array();
    foreach ($qtds as $produtoId => $quantidade) {
        $produtoId = (int) $produtoId;
        $quantidade = (float) str_replace(',', '.', (string) $quantidade);
        if ($produtoId <= 0 || $quantidade < 0) continue;

        $stmtInicial->bind_param("dii", $quantidade, $cicloId, $produtoId);
        $stmtInicial->execute();
        $valoresDesejados[$produtoId] = $quantidade;
    }
    $stmtInicial->close();

    $conexao->commit();

    header("Location: ../estoque_inicial_aba.php?msg=ok");
    exit;

} catch (Throwable $e) {
    $conexao->rollback();
    header("Location: ../estoque_inicial_aba.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}

<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/ciclo_helper.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../../comissoes/meeiros_helper_v2.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../previsao_colheita.php");
    exit;
}

comissoesMeeirosGarantirEstrutura($conexao);

$produto_id          = intval($_POST['produto_id'] ?? 0);
$ciclo_id            = getCicloIdParaTabela($conexao, 'previsao_colheita');
$meeiro_id           = intval($_POST['meeiro_id'] ?? 0);
$quantidade_prevista = (float) str_replace(',', '.', (string) ($_POST['quantidade_prevista'] ?? 0));
$data_prevista       = trim($_POST['data_prevista'] ?? '');
$observacao          = trim($_POST['observacao'] ?? '');
$meieiro             = comissoesMeeirosNomePorId($conexao, $meeiro_id);

if ($produto_id <= 0 || $meeiro_id <= 0 || $meieiro === '' || $quantidade_prevista <= 0 || $data_prevista === '') {
    header("Location: ../previsao_colheita.php?msg=erro&detalhe=Dados+invalidos");
    exit;
}

if ($ciclo_id !== null) {
    $stmt = $conexao->prepare("
        INSERT INTO previsao_colheita (ciclo_id, produto_id, meeiro_id, quantidade_prevista, data_prevista, observacao, meieiro)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iiidsss", $ciclo_id, $produto_id, $meeiro_id, $quantidade_prevista, $data_prevista, $observacao, $meieiro);
} else {
    $stmt = $conexao->prepare("
        INSERT INTO previsao_colheita (produto_id, meeiro_id, quantidade_prevista, data_prevista, observacao, meieiro)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iidsss", $produto_id, $meeiro_id, $quantidade_prevista, $data_prevista, $observacao, $meieiro);
}

if ($stmt->execute()) {
    header("Location: ../previsao_colheita.php?msg=salvo");
} else {
    header("Location: ../previsao_colheita.php?msg=erro&detalhe=Erro+ao+salvar");
}
exit;

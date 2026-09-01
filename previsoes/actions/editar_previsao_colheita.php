<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../../comissoes/meeiros_helper_v2.php";

if (!in_array($_SESSION['usuario_nivel'], ['admin', 'colheita', 'operacional'], true)) {
    die("Sem permissao.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../previsao_colheita.php");
    exit;
}

comissoesMeeirosGarantirEstrutura($conexao);

$id = (int) ($_POST['id'] ?? 0);
$produtoId = (int) ($_POST['produto_id'] ?? 0);
$meeiroId = (int) ($_POST['meeiro_id'] ?? 0);
$quantidadePrevista = (float) str_replace(',', '.', (string) ($_POST['quantidade_prevista'] ?? 0));
$dataPrevista = trim($_POST['data_prevista'] ?? '');
$observacao = trim($_POST['observacao'] ?? '');
$meieiro = comissoesMeeirosNomePorId($conexao, $meeiroId);

if ($id <= 0 || $produtoId <= 0 || $meeiroId <= 0 || $meieiro === '' || $quantidadePrevista <= 0 || $dataPrevista === '') {
    header("Location: ../previsao_colheita.php?msg=erro&detalhe=Dados+invalidos");
    exit;
}

$stmt = $conexao->prepare("
    UPDATE previsao_colheita
    SET produto_id = ?, meeiro_id = ?, quantidade_prevista = ?, data_prevista = ?, observacao = ?, meieiro = ?
    WHERE id = ? AND status = 'pendente'
");
$stmt->bind_param("iidsssi", $produtoId, $meeiroId, $quantidadePrevista, $dataPrevista, $observacao, $meieiro, $id);
$stmt->execute();
$alteradas = $stmt->affected_rows;
$stmt->close();

if ($alteradas > 0) {
    header("Location: ../previsao_colheita.php?msg=atualizado");
    exit;
}

$stmtValidacao = $conexao->prepare("SELECT id FROM previsao_colheita WHERE id = ? LIMIT 1");
$stmtValidacao->bind_param("i", $id);
$stmtValidacao->execute();
$existe = $stmtValidacao->get_result()->fetch_assoc();
$stmtValidacao->close();

if (!$existe) {
    header("Location: ../previsao_colheita.php?msg=erro&detalhe=Previsao+nao+encontrada");
    exit;
}

header("Location: ../previsao_colheita.php?msg=erro&detalhe=Apenas+previsoes+pendentes+podem+ser+editadas");
exit;

<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../auth/proteger.php";

if (!in_array($_SESSION['usuario_nivel'], ['admin', 'colheita', 'operacional'], true)) {
    die("Sem permissao.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../previsao_colheita.php");
    exit;
}

$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0) {
    header("Location: ../previsao_colheita.php?msg=erro&detalhe=ID+invalido");
    exit;
}

// Remove somente previsoes pendentes para nao apagar itens ja confirmados.
$stmt = $conexao->prepare("DELETE FROM previsao_colheita WHERE id = ? AND status = 'pendente'");
$stmt->bind_param("i", $id);
$stmt->execute();
$removidas = $stmt->affected_rows;
$stmt->close();

if ($removidas > 0) {
    header("Location: ../previsao_colheita.php?msg=excluido");
    exit;
}

header("Location: ../previsao_colheita.php?msg=erro&detalhe=Apenas+previsoes+pendentes+podem+ser+excluidas");
exit;

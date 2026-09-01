<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../../config/permissions.php";

requireModule('estoque', '../estoque_inicial_aba.php');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id > 0) {
    $stmt = $conexao->prepare("DELETE FROM estoque_inicial WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
}

header("Location: ../estoque_inicial_aba.php");
exit;
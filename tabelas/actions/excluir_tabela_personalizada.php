<?php
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/permissions.php';
require __DIR__ . '/../../config/tabelas_preco_flex_schema.php';

requirePermission(PERM_ADMIN, '../tabelas_personalizadas.php');
garantirTabelasPrecoFlex($conexao);

$id = (int) ($_POST['id'] ?? 0);
if ($id > 0) {
    $stmt = $conexao->prepare("UPDATE tabelas_preco SET ativo = 0 WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
}

header('Location: ../tabelas_personalizadas.php?msg=excluido');

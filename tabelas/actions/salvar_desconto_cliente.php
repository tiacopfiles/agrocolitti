<?php
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/conexao.php';

if ($_SESSION['usuario_nivel'] !== 'admin') {
    header('Location: ../tabela_embalado.php?msg=erro&detalhe=Acesso+negado');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../tabela_embalado.php');
    exit;
}

$cliente_id          = (int) ($_POST['cliente_id'] ?? 0);
$desconto_financeiro = isset($_POST['desconto_financeiro']) ? (float) $_POST['desconto_financeiro'] : -1;

if ($cliente_id <= 0 || $desconto_financeiro < 0) {
    header('Location: ../tabela_embalado.php?msg=erro&detalhe=Dados+invalidos');
    exit;
}

$stmt = $conexao->prepare("UPDATE clientes SET desconto_financeiro = ? WHERE id = ?");
$stmt->bind_param('di', $desconto_financeiro, $cliente_id);
$stmt->execute();
$stmt->close();

header('Location: ../tabela_embalado.php?msg=salvo');
exit;

<?php
require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../auth/proteger.php';
require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../meeiros_helper_v2.php';

requirePermission(PERM_ADMIN, '../../index.php');
comissoesMeeirosGarantirEstrutura($conexao);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../tabela_precos_meeiros.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: ../tabela_precos_meeiros.php?msg=erro');
    exit;
}

$stmt = $conexao->prepare("DELETE FROM tabela_precos_meeiros WHERE id = ?");
$stmt->bind_param('i', $id);
$ok = $stmt->execute();
$stmt->close();

header('Location: ../tabela_precos_meeiros.php?msg=' . ($ok ? 'apagado' : 'erro'));
exit;

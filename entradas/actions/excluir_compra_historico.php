<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../../previsoes/pedido_compra_helper.php";
require __DIR__ . "/compra_historico_helper.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../historico_compras.php");
    exit;
}

validarTokenCsrf();

if (!in_array($_SESSION['usuario_nivel'] ?? '', ['admin', 'ti'], true)) {
    header("Location: ../historico_compras.php?msg=erro&detalhe=" . urlencode("Sem permissao para excluir compra."));
    exit;
}

$numeroOs = trim((string) ($_POST['numero_os'] ?? ''));
if ($numeroOs === '') {
    header("Location: ../historico_compras.php?msg=erro&detalhe=" . urlencode("OS invalida."));
    exit;
}

$conexao->begin_transaction();

try {
    compraHistoricoRemoverOperacionalOs($conexao, $numeroOs);
    $conexao->commit();

    header("Location: ../historico_compras.php?msg=compra_excluida");
    exit;
} catch (Throwable $e) {
    $conexao->rollback();
    header("Location: ../historico_compras.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}


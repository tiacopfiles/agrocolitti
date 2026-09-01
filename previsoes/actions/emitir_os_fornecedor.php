<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/ciclo_helper.php";
require __DIR__ . "/../../config/previsao_fornecedor_schema.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../pedido_compra_helper.php";
require __DIR__ . "/../../config/cadastro_fiscal_helper.php";
require __DIR__ . "/emitir_compra_helper.php";

garantirFluxoFinanceiroPrevisaoFornecedor($conexao);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../previsao_fornecedor.php");
    exit;
}

validarTokenCsrf();

if (!in_array($_SESSION['usuario_nivel'] ?? '', ['admin', 'fornecedor', 'operacional'], true)) {
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=" . urlencode("Sem permissao para emitir compra."));
    exit;
}

$numeroOs = trim((string) ($_POST['numero_os'] ?? ''));
$usuarioMontagemId = (int) ($_SESSION['usuario_id'] ?? 0);
$responsavel = (string) ($_SESSION['usuario_nome'] ?? $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '');

$conexao->begin_transaction();

try {
    $total = emitirCompraDiretoHistorico($conexao, $numeroOs, $usuarioMontagemId, $responsavel);
    $conexao->commit();

    header("Location: ../previsao_fornecedor.php?msg=emitido&os=" . rawurlencode($numeroOs) . "&total=" . $total);
    exit;
} catch (Throwable $e) {
    $conexao->rollback();
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}

<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/ciclo_helper.php";
require __DIR__ . "/../../config/previsao_fornecedor_schema.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../pedido_compra_helper.php";
require __DIR__ . "/../../config/cadastro_fiscal_helper.php";

function garantirColunasMontagemCompraEnvio(mysqli $conexao): void
{
    $colunas = [
        'usuario_montagem_id' => "ALTER TABLE previsao_fornecedor ADD COLUMN usuario_montagem_id INT NULL DEFAULT NULL AFTER foto",
        'usuario_montagem_nome' => "ALTER TABLE previsao_fornecedor ADD COLUMN usuario_montagem_nome VARCHAR(120) NULL DEFAULT NULL AFTER usuario_montagem_id",
    ];

    foreach ($colunas as $coluna => $sql) {
        $resultado = $conexao->query("SHOW COLUMNS FROM previsao_fornecedor LIKE '{$coluna}'");
        $existe = $resultado && $resultado->num_rows > 0;
        if ($resultado instanceof mysqli_result) {
            $resultado->free();
        }
        if (!$existe) {
            $conexao->query($sql);
        }
    }
}

if (!in_array($_SESSION['usuario_nivel'], ['admin', 'fornecedor', 'operacional'], true)) {
    die("Sem permissao.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../previsao_fornecedor.php");
    exit;
}

garantirFluxoFinanceiroPrevisaoFornecedor($conexao);
garantirColunasMontagemCompraEnvio($conexao);
$usuarioMontagemId = (int) ($_SESSION['usuario_id'] ?? 0);

$numero_os = trim($_POST['numero_os'] ?? '');

if ($numero_os === '') {
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=" . urlencode("Número de OS inválido."));
    exit;
}

$conexao->begin_transaction();

try {
    cadastroFiscalExigirOsCompra($conexao, $numero_os, 'anexado');
    $stmtCount = $conexao->prepare("
        SELECT COUNT(*) AS total
        FROM previsao_fornecedor
        WHERE numero_os = ? AND status = 'anexado' AND (usuario_montagem_id = ? OR usuario_montagem_id IS NULL)
    ");
    $stmtCount->bind_param("si", $numero_os, $usuarioMontagemId);
    $stmtCount->execute();
    $total = (int) $stmtCount->get_result()->fetch_assoc()['total'];
    $stmtCount->close();

    if ($total <= 0) {
        throw new Exception("Não há previsões anexadas para a OS '$numero_os'.");
    }

    $stmtUpdate = $conexao->prepare("
        UPDATE previsao_fornecedor
        SET status = 'pendente'
        WHERE numero_os = ? AND status = 'anexado' AND (usuario_montagem_id = ? OR usuario_montagem_id IS NULL)
    ");
    $stmtUpdate->bind_param("si", $numero_os, $usuarioMontagemId);
    $stmtUpdate->execute();
    $stmtUpdate->close();

    $responsavel = $_SESSION['usuario_nome'] ?? $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '';
    pedidoCompraObterSnapshot($conexao, $numero_os, (string) $responsavel, true);

    $conexao->commit();

    header("Location: ../previsao_fornecedor.php?msg=enviado_lote&total={$total}");
    exit;
} catch (Exception $e) {
    $conexao->rollback();
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}

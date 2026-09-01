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
    header("Location: ../historico_compras.php?msg=erro&detalhe=" . urlencode("Sem permissao para cancelar compra."));
    exit;
}

$numeroOs = trim((string) ($_POST['numero_os'] ?? ''));
$motivo = trim((string) ($_POST['motivo_cancelamento'] ?? ''));
if ($numeroOs === '' || $motivo === '') {
    header("Location: ../historico_compras.php?msg=erro&detalhe=" . urlencode("Informe a OS e o motivo do cancelamento."));
    exit;
}

compraHistoricoGarantirTabelaReversoes($conexao);

$conexao->begin_transaction();

try {
    $snapshot = pedidoCompraObterSnapshot($conexao, $numeroOs, '');
    $valorCompra = (float) ($snapshot['valor_total'] ?? 0);
    if ($valorCompra <= 0) {
        throw new RuntimeException('Valor da compra nao encontrado para reversao.');
    }

    $fornecedor = $snapshot['fornecedor']['nome'] ?? '';
    $usuario = $_SESSION['usuario_nome'] ?? $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '';
    $valorReversao = -abs($valorCompra);

    $stmtReversao = $conexao->prepare("
        INSERT INTO financeiro_reversoes (origem, numero_os, contraparte, valor, motivo, usuario)
        VALUES ('compra_cancelada', ?, ?, ?, ?, ?)
    ");
    $stmtReversao->bind_param('ssdss', $numeroOs, $fornecedor, $valorReversao, $motivo, $usuario);
    $stmtReversao->execute();
    $stmtReversao->close();

    compraHistoricoRemoverOperacionalOs($conexao, $numeroOs);

    $conexao->commit();

    header("Location: ../historico_compras.php?msg=compra_cancelada");
    exit;
} catch (Throwable $e) {
    $conexao->rollback();
    header("Location: ../historico_compras.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}

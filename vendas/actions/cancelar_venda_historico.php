<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/log_helper.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/venda_historico_helper.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../historico_vendas.php");
    exit;
}

validarTokenCsrf();

if (!in_array($_SESSION['usuario_nivel'] ?? '', ['admin', 'ti'], true)) {
    header("Location: ../historico_vendas.php?msg=erro&detalhe=" . urlencode("Sem permissao para cancelar venda."));
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$motivo = trim((string) ($_POST['motivo_cancelamento'] ?? ''));
if ($id <= 0 || $motivo === '') {
    header("Location: ../historico_vendas.php?msg=erro&detalhe=" . urlencode("Informe a venda e o motivo do cancelamento."));
    exit;
}

vendaHistoricoGarantirTabelaReversoes($conexao);
$conexao->begin_transaction();

try {
    $venda = vendaHistoricoBuscarVenda($conexao, $id, true);
    $valorVenda = vendaHistoricoValorFinal($venda);
    if ($valorVenda <= 0) {
        throw new RuntimeException('Valor da venda nao encontrado para reversao.');
    }

    $numeroOs = trim((string) ($venda['numero_os'] ?? ''));
    if ($numeroOs === '') {
        $numeroOs = 'VENDA-' . (int) $venda['id'];
    }

    $cliente = (string) ($venda['cliente_nome'] ?? '');
    $usuario = $_SESSION['usuario_nome'] ?? $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '';
    $valorReversao = -abs($valorVenda);

    $stmtReversao = $conexao->prepare("
        INSERT INTO financeiro_reversoes (origem, numero_os, contraparte, valor, motivo, usuario)
        VALUES ('venda_cancelada', ?, ?, ?, ?, ?)
    ");
    $stmtReversao->bind_param('ssdss', $numeroOs, $cliente, $valorReversao, $motivo, $usuario);
    $stmtReversao->execute();
    $stmtReversao->close();

    vendaHistoricoRemoverOperacional($conexao, $venda);

    registrarLog(
        $conexao,
        'venda_historico_cancelada',
        'vendas',
        $id,
        "Venda #$id da OS '$numeroOs' cancelada no historico. Motivo: $motivo"
    );

    $conexao->commit();
    header("Location: ../historico_vendas.php?msg=venda_cancelada");
    exit;
} catch (Throwable $e) {
    $conexao->rollback();
    header("Location: ../historico_vendas.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}

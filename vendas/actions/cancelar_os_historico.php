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
    header("Location: ../historico_vendas.php?msg=erro&detalhe=" . urlencode("Sem permissao para cancelar OS."));
    exit;
}

$numeroOs = trim((string) ($_POST['numero_os'] ?? ''));
$motivo   = trim((string) ($_POST['motivo_cancelamento'] ?? ''));

if ($numeroOs === '' || $motivo === '') {
    header("Location: ../historico_vendas.php?msg=erro&detalhe=" . urlencode("Informe a OS e o motivo do cancelamento."));
    exit;
}

vendaHistoricoGarantirTabelaReversoes($conexao);
$conexao->begin_transaction();

try {
    // Busca todas as vendas da OS (com lock)
    $vendas = vendaHistoricoBuscarVendasOs($conexao, $numeroOs, true);

    // Calcula valor total da OS para reversão financeira
    $valorTotalOs = 0.0;
    $clienteNome  = '';
    foreach ($vendas as $venda) {
        $valorTotalOs += vendaHistoricoValorFinal($venda);
        if ($clienteNome === '') {
            $clienteNome = (string) ($venda['cliente_nome'] ?? '');
        }
    }

    $usuario      = $_SESSION['usuario_nome'] ?? $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '';
    $valorReversao = -abs($valorTotalOs);

    // Registra reversão financeira consolidada da OS
    $stmtReversao = $conexao->prepare("
        INSERT INTO financeiro_reversoes (origem, numero_os, contraparte, valor, motivo, usuario)
        VALUES ('os_cancelada', ?, ?, ?, ?, ?)
    ");
    $stmtReversao->bind_param('ssdss', $numeroOs, $clienteNome, $valorReversao, $motivo, $usuario);
    $stmtReversao->execute();
    $stmtReversao->close();

    // Remove todos os itens da OS (estoque + vendas)
    vendaHistoricoRemoverOperacionalOs($conexao, $numeroOs);

    registrarLog(
        $conexao,
        'os_historico_cancelada',
        'vendas',
        0,
        "OS '$numeroOs' cancelada no historico. Itens: " . count($vendas) . ". Motivo: $motivo"
    );

    $conexao->commit();
    header("Location: ../historico_vendas.php?msg=venda_cancelada");
    exit;

} catch (Throwable $e) {
    $conexao->rollback();
    header("Location: ../historico_vendas.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}

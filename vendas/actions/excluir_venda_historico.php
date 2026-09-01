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
    header("Location: ../historico_vendas.php?msg=erro&detalhe=" . urlencode("Sem permissao para excluir venda."));
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$numeroOs = trim((string) ($_POST['numero_os'] ?? ''));
if ($id <= 0 && $numeroOs === '') {
    header("Location: ../historico_vendas.php?msg=erro&detalhe=" . urlencode("Venda invalida."));
    exit;
}

$conexao->begin_transaction();

try {
    if ($numeroOs !== '') {
        $vendas = vendaHistoricoBuscarVendasOs($conexao, $numeroOs, true);
        foreach ($vendas as $venda) {
            vendaHistoricoRemoverOperacional($conexao, $venda);
        }
        registrarLog(
            $conexao,
            'venda_historico_excluida',
            'vendas',
            0,
            "OS '$numeroOs' excluida do historico com " . count($vendas) . " venda(s)"
        );
    } else {
        $venda = vendaHistoricoBuscarVenda($conexao, $id, true);
        vendaHistoricoRemoverOperacional($conexao, $venda);
        registrarLog(
            $conexao,
            'venda_historico_excluida',
            'vendas',
            $id,
            "Venda #$id excluida do historico"
        );
    }

    $conexao->commit();
    header("Location: ../historico_vendas.php?msg=venda_excluida");
    exit;
} catch (Throwable $e) {
    $conexao->rollback();
    header("Location: ../historico_vendas.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}

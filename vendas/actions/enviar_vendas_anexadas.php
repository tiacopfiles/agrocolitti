<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/log_helper.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../../config/cadastro_fiscal_helper.php";

function garantirColunasMontagemVendaEnvioCliente(mysqli $conexao): void
{
    $colunas = [
        'usuario_montagem_id' => "ALTER TABLE vendas ADD COLUMN usuario_montagem_id INT NULL DEFAULT NULL",
        'usuario_montagem_nome' => "ALTER TABLE vendas ADD COLUMN usuario_montagem_nome VARCHAR(120) NULL DEFAULT NULL",
    ];

    foreach ($colunas as $coluna => $sql) {
        $resultado = $conexao->query("SHOW COLUMNS FROM vendas LIKE '{$coluna}'");
        $existe = $resultado && $resultado->num_rows > 0;
        if ($resultado instanceof mysqli_result) {
            $resultado->free();
        }
        if (!$existe) {
            $conexao->query($sql);
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../vendas.php");
    exit;
}

validarTokenCsrf();
garantirColunasMontagemVendaEnvioCliente($conexao);

$cliente_id = intval($_POST["cliente_id"] ?? 0);
$usuarioMontagemId = (int) ($_SESSION['usuario_id'] ?? 0);

if ($cliente_id <= 0) {
    header("Location: ../vendas.php?msg=erro&detalhe=" . urlencode("Cliente inválido para envio."));
    exit;
}

$conexao->begin_transaction();

try {
    $stmtCliente = $conexao->prepare("SELECT id FROM clientes WHERE id = ?");
    $stmtCliente->bind_param("i", $cliente_id);
    $stmtCliente->execute();
    $cliente = $stmtCliente->get_result()->fetch_assoc();

    if (!$cliente) {
        throw new Exception("Cliente não encontrado.");
    }

    cadastroFiscalExigirPessoaSelecionada($conexao, 'clientes', $cliente_id, 'Cliente');

    $stmtVendas = $conexao->prepare("
        SELECT id, produto_id
        FROM vendas
        WHERE cliente_id = ? AND status = 'anexado'
          AND (usuario_montagem_id = ? OR usuario_montagem_id IS NULL)
        FOR UPDATE
    ");
    $stmtVendas->bind_param("ii", $cliente_id, $usuarioMontagemId);
    $stmtVendas->execute();
    $resultVendas = $stmtVendas->get_result();

    $total = $resultVendas->num_rows;

    if ($total <= 0) {
        throw new Exception("Não há vendas anexadas para este cliente.");
    }

    while ($venda = $resultVendas->fetch_assoc()) {
        cadastroFiscalExigirProdutoSelecionado($conexao, (int) $venda['produto_id']);
    }

    $stmtUpdate = $conexao->prepare("
        UPDATE vendas
        SET status = 'pendente'
        WHERE cliente_id = ? AND status = 'anexado'
          AND (usuario_montagem_id = ? OR usuario_montagem_id IS NULL)
    ");
    $stmtUpdate->bind_param("ii", $cliente_id, $usuarioMontagemId);
    $stmtUpdate->execute();

    registrarLog(
        $conexao,
        'vendas_anexadas_enviadas',
        'vendas',
        0,
        "Vendas anexadas do cliente_id $cliente_id enviadas para pendente. Total: $total"
    );

    $conexao->commit();

    header("Location: ../vendas.php?msg=enviado_lote&total={$total}");
    exit;
} catch (Exception $e) {
    $conexao->rollback();
    $msg = urlencode($e->getMessage());
    header("Location: ../vendas.php?msg=erro&detalhe=$msg&cliente_id={$cliente_id}");
    exit;
}

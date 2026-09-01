<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/previsao_fornecedor_schema.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../pedido_compra_helper.php";

function garantirColunasMontagemCompraExclusaoOs(mysqli $conexao): void
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

garantirFluxoFinanceiroPrevisaoFornecedor($conexao);
garantirColunasMontagemCompraExclusaoOs($conexao);
pedidoCompraTabelaSnapshotGarantida($conexao);

if (!in_array($_SESSION['usuario_nivel'] ?? '', ['admin', 'fornecedor', 'operacional'], true)) {
    die("Sem permissao.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../previsao_fornecedor.php");
    exit;
}

$numeroOs = trim((string) ($_POST['numero_os'] ?? ''));
$statusPermitido = trim((string) ($_POST['status'] ?? ''));
$usuarioMontagemId = (int) ($_SESSION['usuario_id'] ?? 0);

if ($numeroOs === '' || !in_array($statusPermitido, ['anexado', 'pendente'], true)) {
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=" . urlencode("OS invalida para exclusao."));
    exit;
}

$conexao->begin_transaction();

try {
    $stmtItens = $conexao->prepare("
        SELECT id, foto
        FROM previsao_fornecedor
        WHERE numero_os = ? AND status = ? AND (status <> 'anexado' OR usuario_montagem_id = ? OR usuario_montagem_id IS NULL)
        FOR UPDATE
    ");
    $stmtItens->bind_param('ssi', $numeroOs, $statusPermitido, $usuarioMontagemId);
    $stmtItens->execute();
    $itens = $stmtItens->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtItens->close();

    if (!$itens) {
        throw new Exception("Nenhuma previsao encontrada para a OS '$numeroOs'.");
    }

    $stmtDelete = $conexao->prepare("DELETE FROM previsao_fornecedor WHERE numero_os = ? AND status = ? AND (status <> 'anexado' OR usuario_montagem_id = ? OR usuario_montagem_id IS NULL)");
    $stmtDelete->bind_param('ssi', $numeroOs, $statusPermitido, $usuarioMontagemId);
    $stmtDelete->execute();
    $stmtDelete->close();

    $stmtDoc = $conexao->prepare("DELETE FROM pedido_compra_documentos WHERE numero_os = ?");
    if ($stmtDoc) {
        $stmtDoc->bind_param('s', $numeroOs);
        $stmtDoc->execute();
        $stmtDoc->close();
    }

    $conexao->commit();

    $pastaUploads = dirname(__DIR__, 2) . "/storage/uploads/previsoes/";
    foreach ($itens as $item) {
        if (!empty($item['foto'])) {
            $arquivo = $pastaUploads . basename((string) $item['foto']);
            if (is_file($arquivo)) {
                unlink($arquivo);
            }
        }

        foreach (glob($pastaUploads . "previsao_" . (int) $item['id'] . "_*") ?: [] as $legado) {
            if (is_file($legado)) {
                unlink($legado);
            }
        }
    }

    header("Location: ../previsao_fornecedor.php?msg=excluido");
    exit;
} catch (Exception $e) {
    $conexao->rollback();
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}

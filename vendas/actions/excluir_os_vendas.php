<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/log_helper.php";
require __DIR__ . "/../../auth/proteger.php";

function garantirColunasMontagemVendaExclusaoOs(mysqli $conexao): void
{
    $colunas = [
        'usuario_montagem_id' => "ALTER TABLE vendas ADD COLUMN usuario_montagem_id INT NULL DEFAULT NULL",
        'usuario_montagem_nome' => "ALTER TABLE vendas ADD COLUMN usuario_montagem_nome VARCHAR(120) NULL DEFAULT NULL AFTER usuario_montagem_id",
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../vendas.php");
    exit;
}

validarTokenCsrf();
garantirColunasMontagemVendaExclusaoOs($conexao);

$numeroOs = trim((string) ($_POST['numero_os'] ?? ''));
$statusPermitido = trim((string) ($_POST['status'] ?? ''));
$usuarioMontagemId = (int) ($_SESSION['usuario_id'] ?? 0);

if ($numeroOs === '' || !in_array($statusPermitido, ['anexado', 'pendente'], true)) {
    header("Location: ../vendas.php?msg=erro&detalhe=" . urlencode("OS invalida para exclusao."));
    exit;
}

$conexao->begin_transaction();

try {
    $stmtItens = $conexao->prepare("
        SELECT id, foto
        FROM vendas
        WHERE numero_os = ? AND status = ?
          AND (status <> 'anexado' OR usuario_montagem_id = ? OR usuario_montagem_id IS NULL)
        FOR UPDATE
    ");
    $stmtItens->bind_param('ssi', $numeroOs, $statusPermitido, $usuarioMontagemId);
    $stmtItens->execute();
    $itens = $stmtItens->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtItens->close();

    if (!$itens) {
        throw new Exception("Nenhuma venda encontrada para a OS '$numeroOs'.");
    }

    $stmtDelete = $conexao->prepare("DELETE FROM vendas WHERE numero_os = ? AND status = ? AND (status <> 'anexado' OR usuario_montagem_id = ? OR usuario_montagem_id IS NULL)");
    $stmtDelete->bind_param('ssi', $numeroOs, $statusPermitido, $usuarioMontagemId);
    $stmtDelete->execute();
    $stmtDelete->close();

    $conexao->commit();

    $pasta = dirname(__DIR__, 2) . "/storage/uploads/vendas/";
    foreach ($itens as $item) {
        if (!empty($item['foto'])) {
            $caminho = $pasta . basename((string) $item['foto']);
            if (is_file($caminho)) {
                unlink($caminho);
            }
        }
    }

    registrarLog(
        $conexao,
        'os_vendas_excluida',
        'vendas',
        0,
        "OS '$numeroOs' excluida por inteiro com status '$statusPermitido' (" . count($itens) . " item(s))"
    );

    header("Location: ../vendas.php?msg=excluido");
    exit;
} catch (Exception $e) {
    $conexao->rollback();
    header("Location: ../vendas.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}

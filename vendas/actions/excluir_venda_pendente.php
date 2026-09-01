<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/log_helper.php";
require __DIR__ . "/../../auth/proteger.php";

function garantirColunasMontagemVendaExclusaoItem(mysqli $conexao): void
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

function removerFotoVenda(?string $arquivo, int $vendaId): void
{
    $pasta = dirname(__DIR__, 2) . "/storage/uploads/vendas/";

    if (!empty($arquivo)) {
        $caminho = $pasta . basename($arquivo);
        if (is_file($caminho)) {
            unlink($caminho);
        }
    }

    foreach (glob($pasta . "venda_" . $vendaId . "_*") ?: [] as $legado) {
        if (is_file($legado)) {
            unlink($legado);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../vendas.php");
    exit;
}

validarTokenCsrf();
garantirColunasMontagemVendaExclusaoItem($conexao);

$id = intval($_POST['id'] ?? 0);
$usuarioMontagemId = (int) ($_SESSION['usuario_id'] ?? 0);

if ($id <= 0) {
    header("Location: ../vendas.php?msg=erro&detalhe=ID+invalido");
    exit;
}

$conexao->begin_transaction();

try {
    $stmtVenda = $conexao->prepare("
        SELECT id, status, foto
        FROM vendas
        WHERE id = ? AND (status <> 'anexado' OR usuario_montagem_id = ? OR usuario_montagem_id IS NULL)
        FOR UPDATE
    ");
    $stmtVenda->bind_param("ii", $id, $usuarioMontagemId);
    $stmtVenda->execute();
    $venda = $stmtVenda->get_result()->fetch_assoc();

    if (!$venda) {
        throw new Exception("Venda nao encontrada.");
    }

    if (!in_array($venda['status'], ['anexado', 'pendente', 'concluido'], true)) {
        throw new Exception("Esta venda não pode ser excluída (status: {$venda['status']}).");
    }

    $stmtDelete = $conexao->prepare("DELETE FROM vendas WHERE id = ?");
    $stmtDelete->bind_param("i", $id);
    $stmtDelete->execute();

    $conexao->commit();

    removerFotoVenda($venda['foto'] ?? null, $id);

    registrarLog(
        $conexao,
        'venda_excluida',
        'vendas',
        $id,
        "Venda #$id excluída (estava pendente)"
    );

    $redirectTo = $_POST['redirect_to'] ?? '';
    if ($redirectTo !== '' && strpos($redirectTo, '://') === false) {
        header("Location: $redirectTo");
    } else {
        header("Location: ../vendas.php?msg=excluido");
    }
    exit;
} catch (Exception $e) {
    $conexao->rollback();
    $msg = urlencode($e->getMessage());
    $redirectTo = $_POST['redirect_to'] ?? '';
    if ($redirectTo !== '' && strpos($redirectTo, '://') === false) {
        header("Location: $redirectTo?msg=erro&detalhe=$msg");
    } else {
        header("Location: ../vendas.php?msg=erro&detalhe=$msg");
    }
    exit;
}

<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/previsao_fornecedor_schema.php";
require __DIR__ . "/../../auth/proteger.php";

function garantirColunasMontagemCompraExclusaoItem(mysqli $conexao): void
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
garantirColunasMontagemCompraExclusaoItem($conexao);

if (!in_array($_SESSION['usuario_nivel'], ['admin', 'fornecedor', 'operacional'], true)) {
    die("Sem permissao.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../previsao_fornecedor.php");
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$usuarioMontagemId = (int) ($_SESSION['usuario_id'] ?? 0);

if ($id <= 0) {
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=ID+invalido");
    exit;
}

// Remove somente previsoes ainda nao concluidas para nao apagar recebimentos ja registrados.
$stmtBusca = $conexao->prepare("
    SELECT foto
    FROM previsao_fornecedor
    WHERE id = ? AND status IN ('anexado', 'pendente')
      AND (status <> 'anexado' OR usuario_montagem_id = ? OR usuario_montagem_id IS NULL)
    LIMIT 1
");
$stmtBusca->bind_param("ii", $id, $usuarioMontagemId);
$stmtBusca->execute();
$previsao = $stmtBusca->get_result()->fetch_assoc();
$stmtBusca->close();

if (!$previsao) {
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=Apenas+previsoes+nao+concluidas+podem+ser+excluidas");
    exit;
}

$stmtDelete = $conexao->prepare("
    DELETE FROM previsao_fornecedor
    WHERE id = ? AND status IN ('anexado', 'pendente')
      AND (status <> 'anexado' OR usuario_montagem_id = ? OR usuario_montagem_id IS NULL)
");
$stmtDelete->bind_param("ii", $id, $usuarioMontagemId);
$stmtDelete->execute();
$removidas = $stmtDelete->affected_rows;
$stmtDelete->close();

if ($removidas > 0) {
    $pastaUploads = dirname(__DIR__, 2) . "/storage/uploads/previsoes/";

    if (!empty($previsao['foto'])) {
        $arquivo = $pastaUploads . basename((string) $previsao['foto']);
        if (is_file($arquivo)) {
            unlink($arquivo);
        }
    }

    foreach (glob($pastaUploads . "previsao_" . $id . "_*") ?: [] as $legado) {
        if (is_file($legado)) {
            unlink($legado);
        }
    }
}

if ($removidas > 0) {
    header("Location: ../previsao_fornecedor.php?msg=excluido");
    exit;
}

header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=Erro+ao+excluir+previsao");
exit;

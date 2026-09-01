<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/log_helper.php";
require __DIR__ . "/../../auth/proteger.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../historico_vendas.php");
    exit;
}

validarTokenCsrf();

if (!in_array($_SESSION['usuario_nivel'] ?? '', ['admin', 'ti'], true)) {
    header("Location: ../historico_vendas.php?msg=erro&detalhe=" . urlencode("Sem permissao para editar valor."));
    exit;
}

function garantirColunasValorFinalVenda(mysqli $conexao): void
{
    $cols = [
        'valor_final_original' => "ALTER TABLE vendas ADD COLUMN valor_final_original DECIMAL(12,2) NULL DEFAULT NULL",
        'valor_final_editado' => "ALTER TABLE vendas ADD COLUMN valor_final_editado DECIMAL(12,2) NULL DEFAULT NULL",
        'valor_final_editado_por' => "ALTER TABLE vendas ADD COLUMN valor_final_editado_por VARCHAR(120) NULL DEFAULT NULL",
        'valor_final_editado_em' => "ALTER TABLE vendas ADD COLUMN valor_final_editado_em DATETIME NULL DEFAULT NULL",
    ];
    foreach ($cols as $col => $sql) {
        $colEsc = $conexao->real_escape_string($col);
        $res = $conexao->query("SHOW COLUMNS FROM vendas LIKE '{$colEsc}'");
        $exists = $res && $res->num_rows > 0;
        if ($res instanceof mysqli_result) $res->free();
        if (!$exists) $conexao->query($sql);
    }
}

$id = (int) ($_POST['id'] ?? 0);
$valorNovo = isset($_POST['valor_final']) ? round((float) str_replace(',', '.', (string) $_POST['valor_final']), 2) : -1;

if ($id <= 0 || $valorNovo < 0) {
    header("Location: ../historico_vendas.php?msg=erro&detalhe=" . urlencode("Dados invalidos."));
    exit;
}

garantirColunasValorFinalVenda($conexao);

$stmt = $conexao->prepare("SELECT id, pedido, preco, frete, valor_final_editado FROM vendas WHERE id = ? AND status = 'concluido' LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$venda = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$venda) {
    header("Location: ../historico_vendas.php?msg=erro&detalhe=" . urlencode("Venda concluida nao encontrada."));
    exit;
}

$valorOriginal = ((float) ($venda['pedido'] ?? 0) * (float) ($venda['preco'] ?? 0)) + max(0, (float) ($venda['frete'] ?? 0));
$valorAnterior = $venda['valor_final_editado'] !== null ? (float) $venda['valor_final_editado'] : $valorOriginal;
$usuario = $_SESSION['usuario_nome'] ?? $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '';

$stmt = $conexao->prepare("
    UPDATE vendas
    SET valor_final_original = COALESCE(valor_final_original, ?),
        valor_final_editado = ?,
        valor_final_editado_por = ?,
        valor_final_editado_em = NOW()
    WHERE id = ? AND status = 'concluido'
");
$stmt->bind_param('ddsi', $valorOriginal, $valorNovo, $usuario, $id);
$stmt->execute();
$stmt->close();

registrarLog($conexao, 'valor_final_venda_editado', 'vendas', $id, "Valor final alterado de R$ {$valorAnterior} para R$ {$valorNovo}");

header("Location: ../historico_vendas.php?msg=valor_atualizado");
exit;

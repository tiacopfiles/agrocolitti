<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../../config/cadastro_fiscal_helper.php";
require __DIR__ . "/../../config/produto_vinculo_helper.php";

if (!in_array($_SESSION['usuario_nivel'], ['admin', 'operacional'], true)) {
    die("Sem permissao.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../entradas.php");
    exit;
}

function normalizarTipoMovimentacao(string $tipoEntrada): string
{
    return $tipoEntrada === 'colheita' ? 'entrada_colheita' : 'entrada_fornecedor';
}

// Localiza a movimentacao mais provavel vinculada a uma entrada ja existente.
function buscarMovimentacaoRelacionada(mysqli $conexao, array $entrada): ?array
{
    $tipoMovimentacao = normalizarTipoMovimentacao((string) $entrada['tipo']);
    $produtoEstoqueId = produtoEstoqueId($conexao, (int) $entrada['produto_id']);
    $sql = "
        SELECT id, ciclo_id
        FROM movimentacoes
        WHERE ciclo_id <=> ?
          AND produto_id = ?
          AND tipo = ?
          AND quantidade = ?
        ORDER BY ABS(TIMESTAMPDIFF(SECOND, data_movimentacao, ?)) ASC, id DESC
        LIMIT 1
    ";

    $stmt = $conexao->prepare($sql);
    $stmt->bind_param(
        "iisds",
        $entrada['ciclo_id'],
        $produtoEstoqueId,
        $tipoMovimentacao,
        $entrada['quantidade'],
        $entrada['data_entrada']
    );
    $stmt->execute();
    $movimentacao = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $movimentacao;
}

$id = (int) ($_POST['id'] ?? 0);
$produtoId = (int) ($_POST['produto_id'] ?? 0);
$fornecedorId = isset($_POST['fornecedor_id']) && $_POST['fornecedor_id'] !== '' ? (int) $_POST['fornecedor_id'] : null;
$numeroOs = trim($_POST['numero_os'] ?? '');
$quantidade = (float) ($_POST['quantidade'] ?? 0);
$tipo = trim($_POST['tipo'] ?? '');
$dataEntrada = trim($_POST['data_entrada'] ?? '');
$motivoAbatimento = trim($_POST['motivo_abatimento'] ?? '');
$motivoAbatimento = $motivoAbatimento !== '' ? $motivoAbatimento : null;

if ($id <= 0 || $produtoId <= 0 || $quantidade <= 0 || $dataEntrada === '') {
    header("Location: ../entradas.php?msg=erro&detalhe=Dados+invalidos");
    exit;
}

if (!in_array($tipo, ['entrada_fornecedor', 'colheita'], true)) {
    header("Location: ../entradas.php?msg=erro&detalhe=Tipo+de+entrada+invalido");
    exit;
}

if ($tipo === 'colheita') {
    $fornecedorId = null;
    $numeroOs = '';
}
if ($tipo === 'entrada_fornecedor') {
    try {
        cadastroFiscalExigirCompra($conexao, (int) $fornecedorId, $produtoId);
    } catch (Throwable $e) {
        header("Location: ../entradas.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
        exit;
    }
}

$conexao->begin_transaction();

try {
    // Busca a entrada atual antes de alterar, para sincronizar a movimentacao correspondente.
    $stmtEntrada = $conexao->prepare("SELECT * FROM entradas WHERE id = ? FOR UPDATE");
    $stmtEntrada->bind_param("i", $id);
    $stmtEntrada->execute();
    $entradaAtual = $stmtEntrada->get_result()->fetch_assoc();
    $stmtEntrada->close();

    if (!$entradaAtual) {
        throw new Exception("Entrada nao encontrada.");
    }

    $movimentacao = buscarMovimentacaoRelacionada($conexao, $entradaAtual);
    $tipoMovimentacaoNovo = normalizarTipoMovimentacao($tipo);
    $produtoEstoqueId = produtoEstoqueId($conexao, $produtoId);

    // Atualiza o historico da entrada exibido na tela de entradas.
    $stmtUpdateEntrada = $conexao->prepare("
        UPDATE entradas
        SET produto_id = ?, fornecedor_id = ?, numero_os = ?, quantidade = ?, tipo = ?, data_entrada = ?, motivo_abatimento = ?
        WHERE id = ?
    ");
    $stmtUpdateEntrada->bind_param(
        "iisdsssi",
        $produtoId,
        $fornecedorId,
        $numeroOs,
        $quantidade,
        $tipo,
        $dataEntrada,
        $motivoAbatimento,
        $id
    );
    $stmtUpdateEntrada->execute();
    $stmtUpdateEntrada->close();

    // Mantem a tabela de movimentacoes alinhada com a entrada editada.
    if ($movimentacao) {
        $stmtUpdateMov = $conexao->prepare("
            UPDATE movimentacoes
            SET ciclo_id = ?, produto_id = ?, tipo = ?, quantidade = ?, data_movimentacao = ?
            WHERE id = ?
        ");
        $stmtUpdateMov->bind_param(
            "iisdsi",
            $entradaAtual['ciclo_id'],
            $produtoEstoqueId,
            $tipoMovimentacaoNovo,
            $quantidade,
            $dataEntrada,
            $movimentacao['id']
        );
        $stmtUpdateMov->execute();
        $stmtUpdateMov->close();
    }

    $conexao->commit();

    header("Location: ../entradas.php?msg=atualizado");
    exit;
} catch (Throwable $e) {
    $conexao->rollback();
    header("Location: ../entradas.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}

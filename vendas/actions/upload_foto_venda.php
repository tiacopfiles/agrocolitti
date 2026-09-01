<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/log_helper.php";
require __DIR__ . "/../../auth/proteger.php";

function normalizarNomeArquivoUpload(string $valor): string
{
    $valor = trim($valor);
    $valor = preg_replace('/[^\pL\pN]+/u', '_', $valor) ?? 'arquivo';
    $valor = trim($valor, '_');
    return $valor !== '' ? mb_strtolower($valor, 'UTF-8') : 'arquivo';
}

function gerarNomeArquivoUploadUnico(string $pasta, string $nomeBase, string $extensao): string
{
    $nomeBase = normalizarNomeArquivoUpload($nomeBase);
    $nomeArquivo = $nomeBase . '.' . $extensao;
    $contador = 2;

    while (is_file($pasta . $nomeArquivo)) {
        $nomeArquivo = $nomeBase . '_' . $contador . '.' . $extensao;
        $contador++;
    }

    return $nomeArquivo;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    header("Location: ../vendas.php");
    exit;
}

$id   = intval($_POST['id']);
$foto = $_FILES['foto'] ?? null;

if (empty($foto) || $foto['error'] !== UPLOAD_ERR_OK) {
    header("Location: ../vendas.php?msg=erro&detalhe=Erro+no+upload+do+arquivo");
    exit;
}

$ext = strtolower(pathinfo($foto['name'], PATHINFO_EXTENSION));

if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
    header("Location: ../vendas.php?msg=erro&detalhe=Formato+de+arquivo+não+permitido");
    exit;
}

$pasta = dirname(__DIR__, 2) . "/storage/uploads/vendas/";

if (!is_dir($pasta)) {
    mkdir($pasta, 0777, true);
}

$stmtVenda = $conexao->prepare("
    SELECT v.foto, c.nome AS cliente_nome
    FROM vendas v
    LEFT JOIN clientes c ON c.id = v.cliente_id
    WHERE v.id = ?
    LIMIT 1
");
$stmtVenda->bind_param('i', $id);
$stmtVenda->execute();
$venda = $stmtVenda->get_result()->fetch_assoc();
$stmtVenda->close();

if (!$venda) {
    header("Location: ../vendas.php?msg=erro&detalhe=Venda+nao+encontrada");
    exit;
}

if (!empty($venda['foto'])) {
    $fotoAnterior = $pasta . basename((string) $venda['foto']);
    if (is_file($fotoAnterior)) {
        unlink($fotoAnterior);
    }
}

$anterioresLegado = glob($pasta . "venda_" . $id . "_*") ?: [];
foreach ($anterioresLegado as $ant) {
    if (is_file($ant)) {
        unlink($ant);
    }
}

$nomeArquivo = gerarNomeArquivoUploadUnico($pasta, ((string) ($venda['cliente_nome'] ?? 'cliente')) . '_' . date('Ymd'), $ext);
$caminho     = $pasta . $nomeArquivo;

if (move_uploaded_file($foto['tmp_name'], $caminho)) {
    // Salva o caminho da foto no banco de dados
    $checkCol = $conexao->query("SHOW COLUMNS FROM vendas LIKE 'foto'");
    if ($checkCol && $checkCol->num_rows > 0) {
        $stmtFoto = $conexao->prepare("UPDATE vendas SET foto = ? WHERE id = ?");
        $stmtFoto->bind_param('si', $nomeArquivo, $id);
        $stmtFoto->execute();
        $stmtFoto->close();

        registrarLog(
            $conexao,
            'foto_venda_atualizada',
            'vendas',
            $id,
            "Foto da venda #$id atualizada para $nomeArquivo"
        );
    }
    if ($checkCol instanceof mysqli_result) $checkCol->free();
    header("Location: ../vendas.php?msg=foto");
    exit;
}

<?php
require __DIR__ . "/../../config/conexao.php";
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
    header("Location: ../previsao_fornecedor.php");
    exit;
}

$id   = intval($_POST['id']);
$foto = $_FILES['foto'] ?? null;

if (!$foto || $foto['error'] !== UPLOAD_ERR_OK) {
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=Erro+no+upload+da+foto");
    exit;
}

$ext = strtolower(pathinfo($foto['name'], PATHINFO_EXTENSION));

$permitidas = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'avif'];

if (!in_array($ext, $permitidas)) {
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=Formato+de+imagem+inválido");
    exit;
}

$pasta = dirname(__DIR__, 2) . "/storage/uploads/previsoes/";

if (!is_dir($pasta)) {
    mkdir($pasta, 0755, true);
}

$stmtPrevisao = $conexao->prepare("
    SELECT pf.foto, f.nome AS fornecedor_nome
    FROM previsao_fornecedor pf
    LEFT JOIN fornecedores f ON f.id = pf.fornecedor_id
    WHERE pf.id = ?
    LIMIT 1
");
$stmtPrevisao->bind_param('i', $id);
$stmtPrevisao->execute();
$previsao = $stmtPrevisao->get_result()->fetch_assoc();
$stmtPrevisao->close();

if (!$previsao) {
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=Previsao+nao+encontrada");
    exit;
}

if (!empty($previsao['foto'])) {
    $fotoAnterior = $pasta . basename((string) $previsao['foto']);
    if (is_file($fotoAnterior)) {
        unlink($fotoAnterior);
    }
}

$anterioresLegado = glob($pasta . "previsao_" . $id . "_*") ?: [];
foreach ($anterioresLegado as $ant) {
    if (is_file($ant)) {
        unlink($ant);
    }
}

$nomeArquivo = gerarNomeArquivoUploadUnico($pasta, ((string) ($previsao['fornecedor_nome'] ?? 'fornecedor')) . '_' . date('Ymd'), $ext);

$caminho = $pasta . $nomeArquivo;

/* ==============================
SALVA O ARQUIVO
============================== */

if (move_uploaded_file($foto['tmp_name'], $caminho)) {
    $stmtFoto = $conexao->prepare("UPDATE previsao_fornecedor SET foto = ? WHERE id = ?");
    $stmtFoto->bind_param('si', $nomeArquivo, $id);
    $stmtFoto->execute();
    $stmtFoto->close();

    header("Location: ../previsao_fornecedor.php?msg=foto_salva");
    exit;
}

header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=Erro+ao+salvar+a+imagem");
exit;

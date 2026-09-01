<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/previsao_fornecedor_schema.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../pedido_compra_helper.php";
require __DIR__ . "/../../config/cadastro_fiscal_helper.php";

function garantirColunasMontagemCompraEditar(mysqli $conexao): void
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

function garantirColunaFotoPrevisaoEditar(mysqli $conexao): void
{
    $resultado = $conexao->query("SHOW COLUMNS FROM previsao_fornecedor LIKE 'foto'");
    $existe = $resultado && $resultado->num_rows > 0;

    if ($resultado instanceof mysqli_result) {
        $resultado->free();
    }

    if (!$existe) {
        $conexao->query("ALTER TABLE previsao_fornecedor ADD COLUMN foto VARCHAR(255) DEFAULT NULL");
    }
}

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

function salvarFotoPrevisaoEdicao(mysqli $conexao, int $id): bool
{
    $foto = $_FILES['foto'] ?? null;

    if (!$foto || (int) ($foto['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return false;
    }

    if ((int) $foto['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Erro no upload da foto.');
    }

    $ext = strtolower(pathinfo((string) $foto['name'], PATHINFO_EXTENSION));
    $permitidas = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'avif'];
    if (!in_array($ext, $permitidas, true)) {
        throw new Exception('Formato de imagem invalido.');
    }

    $pasta = dirname(__DIR__, 2) . '/storage/uploads/previsoes/';
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
        throw new Exception('Previsao nao encontrada para anexar foto.');
    }

    $nomeArquivo = gerarNomeArquivoUploadUnico($pasta, ((string) ($previsao['fornecedor_nome'] ?? 'fornecedor')) . '_' . date('Ymd'), $ext);
    if (!move_uploaded_file($foto['tmp_name'], $pasta . $nomeArquivo)) {
        throw new Exception('Erro ao salvar a imagem.');
    }

    $stmtFoto = $conexao->prepare("UPDATE previsao_fornecedor SET foto = ? WHERE id = ?");
    $stmtFoto->bind_param('si', $nomeArquivo, $id);
    $stmtFoto->execute();
    $stmtFoto->close();

    if (!empty($previsao['foto'])) {
        $fotoAnterior = $pasta . basename((string) $previsao['foto']);
        if (is_file($fotoAnterior)) {
            unlink($fotoAnterior);
        }
    }

    return true;
}

function buscarEntradaCompraConcluida(mysqli $conexao, array $previsao): ?array
{
    $stmt = $conexao->prepare("
        SELECT *
        FROM entradas
        WHERE tipo = 'entrada_fornecedor'
          AND numero_os = ?
          AND produto_id = ?
          AND fornecedor_id <=> ?
        ORDER BY ABS(quantidade - ?) ASC, id DESC
        LIMIT 1
        FOR UPDATE
    ");
    $quantidade = (float) ($previsao['quantidade_recebida'] ?: $previsao['quantidade_prevista']);
    $stmt->bind_param('siid', $previsao['numero_os'], $previsao['produto_id'], $previsao['fornecedor_id'], $quantidade);
    $stmt->execute();
    $entrada = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $entrada;
}

function buscarMovimentacaoCompraConcluida(mysqli $conexao, array $entrada): ?array
{
    $stmt = $conexao->prepare("
        SELECT id
        FROM movimentacoes
        WHERE produto_id = ?
          AND tipo = 'entrada_fornecedor'
          AND quantidade = ?
        ORDER BY ABS(TIMESTAMPDIFF(SECOND, data_movimentacao, ?)) ASC, id DESC
        LIMIT 1
    ");
    $stmt->bind_param('ids', $entrada['produto_id'], $entrada['quantidade'], $entrada['data_entrada']);

    $stmt->execute();
    $movimentacao = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $movimentacao;
}

function sincronizarCompraConcluida(mysqli $conexao, array $previsaoAntes, int $produtoId, int $fornecedorId, string $numeroOs, float $quantidade, string $dataPrevista): void
{
    if (($previsaoAntes['status'] ?? '') !== 'concluido') {
        return;
    }

    $entrada = buscarEntradaCompraConcluida($conexao, $previsaoAntes);
    if (!$entrada) {
        throw new Exception('Entrada da compra concluida nao encontrada para atualizar o estoque.');
    }

    $movimentacao = buscarMovimentacaoCompraConcluida($conexao, $entrada);
    $dataEntrada = $dataPrevista . ' 00:00:00';

    $stmtEntrada = $conexao->prepare("
        UPDATE entradas
        SET produto_id = ?, fornecedor_id = ?, numero_os = ?, quantidade = ?, data_entrada = ?
        WHERE id = ?
    ");
    $stmtEntrada->bind_param('iisdsi', $produtoId, $fornecedorId, $numeroOs, $quantidade, $dataEntrada, $entrada['id']);
    $stmtEntrada->execute();
    $stmtEntrada->close();

    if ($movimentacao) {
        $stmtMov = $conexao->prepare("
            UPDATE movimentacoes
            SET produto_id = ?, quantidade = ?, data_movimentacao = ?
            WHERE id = ?
        ");
        $stmtMov->bind_param('idsi', $produtoId, $quantidade, $dataEntrada, $movimentacao['id']);
        $stmtMov->execute();
        $stmtMov->close();
    }
}

garantirFluxoFinanceiroPrevisaoFornecedor($conexao);
garantirColunasMontagemCompraEditar($conexao);
garantirColunaFotoPrevisaoEditar($conexao);

if (!in_array($_SESSION['usuario_nivel'], ['admin', 'fornecedor', 'operacional'], true)) {
    die("Sem permissao.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../previsao_fornecedor.php");
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$produtoId = (int) ($_POST['produto_id'] ?? 0);
$fornecedorId = (int) ($_POST['fornecedor_id'] ?? 0);
$numeroOs = trim($_POST['numero_os'] ?? '');
$quantidadePrevista = (float) ($_POST['quantidade_prevista'] ?? 0);
$preco = isset($_POST['preco']) && $_POST['preco'] !== '' ? round((float) $_POST['preco'], 2) : null;
$dataPrevista = trim($_POST['data_prevista'] ?? '');
$usuarioMontagemId = (int) ($_SESSION['usuario_id'] ?? 0);
$redirectTo = trim((string) ($_POST['redirect_to'] ?? '../previsao_fornecedor.php'));
if ($redirectTo === '' || strpos($redirectTo, '://') !== false || substr($redirectTo, 0, 2) === '//') {
    $redirectTo = '../previsao_fornecedor.php';
}

if ($id <= 0 || $produtoId <= 0 || $fornecedorId <= 0 || $quantidadePrevista <= 0 || $preco === null || $preco < 0 || $dataPrevista === '') {
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=Dados+invalidos");
    exit;
}

try {
    cadastroFiscalExigirCompra($conexao, $fornecedorId, $produtoId);
} catch (Throwable $e) {
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}

$stmtValidacao = $conexao->prepare("
    SELECT *
    FROM previsao_fornecedor
    WHERE id = ? AND (status <> 'anexado' OR usuario_montagem_id = ? OR usuario_montagem_id IS NULL)
    LIMIT 1
");
$stmtValidacao->bind_param("ii", $id, $usuarioMontagemId);
$stmtValidacao->execute();
$existe = $stmtValidacao->get_result()->fetch_assoc();
$stmtValidacao->close();

if (!$existe) {
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=Previsao+nao+encontrada");
    exit;
}

if (!in_array($existe['status'], ['anexado', 'pendente', 'concluido'], true)) {
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=Esta+compra+nao+pode+ser+editada");
    exit;
}

$numeroOsAnterior = trim((string) ($existe['numero_os'] ?? ''));
$responsavel = $_SESSION['usuario_nome'] ?? $_SESSION['usuario_login'] ?? '';

pedidoCompraTabelaSnapshotGarantida($conexao);
$conexao->begin_transaction();

try {
    $stmt = $conexao->prepare("
        UPDATE previsao_fornecedor
        SET produto_id = ?, fornecedor_id = ?, numero_os = ?, quantidade_prevista = ?, quantidade_recebida = CASE WHEN status = 'concluido' THEN ? ELSE quantidade_recebida END, preco = ?, data_prevista = ?
        WHERE id = ? AND status IN ('anexado', 'pendente', 'concluido')
          AND (status <> 'anexado' OR usuario_montagem_id = ? OR usuario_montagem_id IS NULL)
    ");
    $stmt->bind_param("iisdddsii", $produtoId, $fornecedorId, $numeroOs, $quantidadePrevista, $quantidadePrevista, $preco, $dataPrevista, $id, $usuarioMontagemId);
    $stmt->execute();
    $stmt->close();

    $fotoAtualizada = salvarFotoPrevisaoEdicao($conexao, $id);
    sincronizarCompraConcluida($conexao, $existe, $produtoId, $fornecedorId, $numeroOs, $quantidadePrevista, $dataPrevista);

    pedidoCompraAtualizarSnapshotSePossivel($conexao, $numeroOs, $responsavel);
    if ($numeroOsAnterior !== '' && $numeroOsAnterior !== $numeroOs) {
        pedidoCompraAtualizarSnapshotSePossivel($conexao, $numeroOsAnterior, $responsavel);
    }

    $conexao->commit();
    $sep = strpos($redirectTo, '?') !== false ? '&' : '?';
    header("Location: {$redirectTo}{$sep}msg=" . ($fotoAtualizada ? "foto_salva" : "atualizado"));
    exit;
} catch (Throwable $e) {
    $conexao->rollback();
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=Falha+ao+atualizar+previsao");
    exit;
}

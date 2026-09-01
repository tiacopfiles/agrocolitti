<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/ciclo_helper.php";
require __DIR__ . "/../../config/previsao_fornecedor_schema.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../pedido_compra_helper.php";
require __DIR__ . "/../../config/cadastro_fiscal_helper.php";
require __DIR__ . "/emitir_compra_helper.php";

garantirFluxoFinanceiroPrevisaoFornecedor($conexao);

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

function buscarNomeFornecedorUpload(mysqli $conexao, int $fornecedorId): string
{
    $stmt = $conexao->prepare("SELECT nome FROM fornecedores WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $fornecedorId);
    $stmt->execute();
    $fornecedor = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (string) ($fornecedor['nome'] ?? 'fornecedor');
}

function garantirColunasMontagemCompra(mysqli $conexao): void
{
    $colunas = [
        'usuario_montagem_id' => "ALTER TABLE previsao_fornecedor ADD COLUMN usuario_montagem_id INT NULL DEFAULT NULL AFTER foto",
        'usuario_montagem_nome' => "ALTER TABLE previsao_fornecedor ADD COLUMN usuario_montagem_nome VARCHAR(120) NULL DEFAULT NULL AFTER usuario_montagem_id",
    ];
    foreach ($colunas as $coluna => $sql) {
        $resultado = $conexao->query("SHOW COLUMNS FROM previsao_fornecedor LIKE '" . $conexao->real_escape_string($coluna) . "'");
        $existe = $resultado && $resultado->num_rows > 0;
        if ($resultado instanceof mysqli_result) $resultado->free();
        if (!$existe) $conexao->query($sql);
    }
}

garantirColunasMontagemCompra($conexao);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../previsao_fornecedor.php");
    exit;
}

$produto_id          = intval($_POST['produto_id']);
$fornecedor_id       = intval($_POST['fornecedor_id']);
$ciclo_id            = getCicloIdParaTabela($conexao, 'previsao_fornecedor');
$numero_os           = trim($_POST['numero_os'] ?? '');
$quantidade_prevista = floatval($_POST['quantidade_prevista']);
$preco               = isset($_POST['preco']) && $_POST['preco'] !== '' ? round((float) $_POST['preco'], 2) : null;
$data_prevista       = $_POST['data_prevista'];
$forma_raw           = strtolower(trim((string) ($_POST['forma_pagamento'] ?? '')));
$forma_pagamento     = in_array($forma_raw, ['boleto', 'deposito', 'pix'], true) ? $forma_raw : null;
$prazo_pagamento     = trim((string) ($_POST['prazo_pagamento'] ?? ''));
if ($prazo_pagamento === '') { $prazo_pagamento = null; }
$entreposto          = trim((string) ($_POST['entreposto'] ?? ''));
if ($entreposto === '') { $entreposto = null; }
$acao_final          = strtolower(trim((string) ($_POST['acao_final'] ?? 'adicionar_item')));
if (!in_array($acao_final, ['adicionar_item', 'enviar_os', 'salvar_rascunho', 'emitir_os'], true)) {
    $acao_final = 'adicionar_item';
}
$adicionarPendente = (($_POST['adicionar_pendente'] ?? '') === '1');
$revendaSaoPaulo = (($_POST['revenda_sao_paulo'] ?? '') === '1') ? 1 : 0;

if ($produto_id <= 0 || $fornecedor_id <= 0 || $numero_os === '' || $quantidade_prevista <= 0 || $preco === null || $preco < 0 || empty($data_prevista)) {
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=Dados+inválidos");
    exit;
}

try {
    cadastroFiscalExigirCompra($conexao, $fornecedor_id, $produto_id);
    if ($acao_final === 'enviar_os') {
        cadastroFiscalExigirOsCompra($conexao, $numero_os, 'anexado');
    }
} catch (Throwable $e) {
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}

$usuarioMontagemId = (int) ($_SESSION['usuario_id'] ?? 0);
$usuarioMontagemNome = (string) ($_SESSION['usuario_nome'] ?? $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '');

if ($adicionarPendente && $numero_os !== '') {
    $stmtOsPendente = $conexao->prepare("
        SELECT fornecedor_id, data_prevista, forma_pagamento, revenda_sao_paulo
        FROM previsao_fornecedor
        WHERE numero_os = ? AND status = 'pendente'
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmtOsPendente->bind_param('s', $numero_os);
    $stmtOsPendente->execute();
    $osPendente = $stmtOsPendente->get_result()->fetch_assoc();
    $stmtOsPendente->close();

    if (!$osPendente) {
        header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=OS+pendente+nao+encontrada");
        exit;
    }

    if ((int) ($osPendente['fornecedor_id'] ?? 0) !== $fornecedor_id) {
        header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=Fornecedor+da+OS+pendente+nao+pode+ser+alterado");
        exit;
    }

    if ($forma_pagamento === null && !empty($osPendente['forma_pagamento'])) {
        $forma_pagamento = (string) $osPendente['forma_pagamento'];
    }

    $revendaSaoPaulo = !empty($osPendente['revenda_sao_paulo']) ? 1 : 0;
}

if (!$adicionarPendente && $numero_os !== '') {
    $stmtOsAnexada = $conexao->prepare("
        SELECT revenda_sao_paulo
        FROM previsao_fornecedor
        WHERE numero_os = ? AND status = 'anexado'
          AND (usuario_montagem_id = ? OR usuario_montagem_id IS NULL)
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmtOsAnexada->bind_param('si', $numero_os, $usuarioMontagemId);
    $stmtOsAnexada->execute();
    $osAnexada = $stmtOsAnexada->get_result()->fetch_assoc();
    $stmtOsAnexada->close();

    if ($osAnexada) {
        $revendaSaoPaulo = !empty($osAnexada['revenda_sao_paulo']) ? 1 : 0;
    }
}

// Processar foto ANTES de inserir (para salvar o nome junto)
$nomeArquivo = null;

if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
    $foto      = $_FILES['foto'];
    $ext       = strtolower(pathinfo($foto['name'], PATHINFO_EXTENSION));
    $permitidas = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'avif'];

    if (in_array($ext, $permitidas)) {
        $pasta = dirname(__DIR__, 2) . "/storage/uploads/previsoes/";
        if (!is_dir($pasta)) mkdir($pasta, 0755, true);
        $fornecedorNome = buscarNomeFornecedorUpload($conexao, $fornecedor_id);
        $nomeArquivo = gerarNomeArquivoUploadUnico($pasta, $fornecedorNome . '_' . date('Ymd'), $ext);
        if (!move_uploaded_file($foto['tmp_name'], $pasta . $nomeArquivo)) {
            $nomeArquivo = null; // falhou silenciosamente, continua sem foto
        }
    }
}

$statusInicial = $adicionarPendente ? 'pendente' : 'anexado';

if ($ciclo_id !== null) {
    $stmt = $conexao->prepare("
        INSERT INTO previsao_fornecedor
            (ciclo_id, produto_id, fornecedor_id, numero_os, quantidade_prevista, preco, data_prevista, forma_pagamento, revenda_sao_paulo, prazo_pagamento, entreposto, status, foto)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iiisddssissss", $ciclo_id, $produto_id, $fornecedor_id, $numero_os, $quantidade_prevista, $preco, $data_prevista, $forma_pagamento, $revendaSaoPaulo, $prazo_pagamento, $entreposto, $statusInicial, $nomeArquivo);
} else {
    $stmt = $conexao->prepare("
        INSERT INTO previsao_fornecedor
            (produto_id, fornecedor_id, numero_os, quantidade_prevista, preco, data_prevista, forma_pagamento, revenda_sao_paulo, prazo_pagamento, entreposto, status, foto)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iisddssissss", $produto_id, $fornecedor_id, $numero_os, $quantidade_prevista, $preco, $data_prevista, $forma_pagamento, $revendaSaoPaulo, $prazo_pagamento, $entreposto, $statusInicial, $nomeArquivo);
}

if ($stmt->execute()) {
    $novoId = (int) $conexao->insert_id;
    $stmtUsuario = $conexao->prepare("
        UPDATE previsao_fornecedor
        SET usuario_montagem_id = ?, usuario_montagem_nome = ?
        WHERE id = ?
    ");
    $stmtUsuario->bind_param('isi', $usuarioMontagemId, $usuarioMontagemNome, $novoId);
    $stmtUsuario->execute();
    $stmtUsuario->close();

    if ($adicionarPendente && $numero_os !== '') {
        $responsavel = $_SESSION['usuario_nome'] ?? $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '';
        pedidoCompraObterSnapshot($conexao, $numero_os, (string) $responsavel, true);
        header("Location: ../previsao_fornecedor.php?msg=produto_adicionado&download_numero_os=" . rawurlencode($numero_os));
        exit;
    }

    if ($acao_final === 'emitir_os' && $numero_os !== '') {
        $conexao->begin_transaction();
        try {
            $responsavel = $_SESSION['usuario_nome'] ?? $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '';
            $total = emitirCompraDiretoHistorico($conexao, $numero_os, $usuarioMontagemId, (string) $responsavel);
            $conexao->commit();

            header("Location: ../previsao_fornecedor.php?msg=emitido&os=" . rawurlencode($numero_os) . "&total={$total}");
            exit;
        } catch (Throwable $e) {
            $conexao->rollback();
            header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
            exit;
        }
    }

    if ($acao_final === 'enviar_os' && $numero_os !== '') {
        $stmtCount = $conexao->prepare("
            SELECT COUNT(*) AS total
            FROM previsao_fornecedor
            WHERE numero_os = ? AND status = 'anexado'
              AND (usuario_montagem_id = ? OR usuario_montagem_id IS NULL)
        ");
        $stmtCount->bind_param("si", $numero_os, $usuarioMontagemId);
        $stmtCount->execute();
        $total = (int) ($stmtCount->get_result()->fetch_assoc()['total'] ?? 0);
        $stmtCount->close();

        if ($total > 0) {
            $stmtUpdate = $conexao->prepare("
                UPDATE previsao_fornecedor
                SET status = 'pendente'
                WHERE numero_os = ? AND status = 'anexado'
                  AND (usuario_montagem_id = ? OR usuario_montagem_id IS NULL)
            ");
            $stmtUpdate->bind_param("si", $numero_os, $usuarioMontagemId);
            $stmtUpdate->execute();
            $stmtUpdate->close();

            $responsavel = $_SESSION['usuario_nome'] ?? $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '';
            pedidoCompraObterSnapshot($conexao, $numero_os, (string) $responsavel, true);
        }

        header("Location: ../previsao_fornecedor.php?msg=enviado_lote&total={$total}");
        exit;
    }

    if ($acao_final === 'salvar_rascunho') {
        $params = [
            'msg' => 'rascunho_salvo',
            'fornecedor_id' => $fornecedor_id,
            'numero_os' => $numero_os,
            'data_prevista' => $data_prevista,
            'forma_pagamento' => $forma_pagamento,
            'prazo_pagamento' => $prazo_pagamento,
            'entreposto' => $entreposto,
            'revenda_sao_paulo' => $revendaSaoPaulo,
            'etapa' => 'finalizar',
        ];
        header("Location: ../previsao_fornecedor.php?" . http_build_query($params));
        exit;
    }

    $params = [
        'msg' => 'salvo',
        'fornecedor_id' => $fornecedor_id,
        'numero_os' => $numero_os,
        'data_prevista' => $data_prevista,
        'forma_pagamento' => $forma_pagamento,
        'revenda_sao_paulo' => $revendaSaoPaulo,
        'etapa' => 'produto',
    ];
    header("Location: ../previsao_fornecedor.php?" . http_build_query($params));
} else {
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=Erro+ao+salvar");
}
exit;

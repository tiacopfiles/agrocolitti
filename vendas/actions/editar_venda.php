<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/ciclo_helper.php";
require __DIR__ . "/../../config/calculos_preco.php";
require __DIR__ . "/../../config/log_helper.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../../config/cadastro_fiscal_helper.php";
require __DIR__ . "/../../config/produto_vinculo_helper.php";

function colunaTipoExiste(mysqli $conexao): bool
{
    static $existe = null;

    if ($existe !== null) {
        return $existe;
    }

    $resultado = $conexao->query("SHOW COLUMNS FROM vendas LIKE 'tipo'");
    $existe = $resultado && $resultado->num_rows > 0;

    if ($resultado instanceof mysqli_result) {
        $resultado->free();
    }

    return $existe;
}

function colunaPrecoExiste(mysqli $conexao): bool
{
    static $existe = null;

    if ($existe !== null) {
        return $existe;
    }

    $resultado = $conexao->query("SHOW COLUMNS FROM vendas LIKE 'preco'");
    $existe = $resultado && $resultado->num_rows > 0;

    if ($resultado instanceof mysqli_result) {
        $resultado->free();
    }

    return $existe;
}

function colunaTipoComercialExiste(mysqli $conexao): bool
{
    static $existe = null;

    if ($existe !== null) {
        return $existe;
    }

    $resultado = $conexao->query("SHOW COLUMNS FROM vendas LIKE 'tipo_comercial'");
    $existe = $resultado && $resultado->num_rows > 0;

    if ($resultado instanceof mysqli_result) {
        $resultado->free();
    }

    return $existe;
}

function garantirColunaFreteVenda(mysqli $conexao): void
{
    $resultado = $conexao->query("SHOW COLUMNS FROM vendas LIKE 'frete'");
    $existe = $resultado && $resultado->num_rows > 0;

    if ($resultado instanceof mysqli_result) {
        $resultado->free();
    }

    if (!$existe) {
        $conexao->query("ALTER TABLE vendas ADD COLUMN frete DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    }
}

function garantirColunasMontagemVendaEditar(mysqli $conexao): void
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

function garantirColunaFotoVendaEditar(mysqli $conexao): void
{
    $resultado = $conexao->query("SHOW COLUMNS FROM vendas LIKE 'foto'");
    $existe = $resultado && $resultado->num_rows > 0;

    if ($resultado instanceof mysqli_result) {
        $resultado->free();
    }

    if (!$existe) {
        $conexao->query("ALTER TABLE vendas ADD COLUMN foto VARCHAR(255) DEFAULT NULL");
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

function salvarFotoVendaEdicao(mysqli $conexao, int $id): bool
{
    $foto = $_FILES['foto'] ?? null;

    if (!$foto || (int) ($foto['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return false;
    }

    if ((int) $foto['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Erro no upload da foto.');
    }

    $ext = strtolower(pathinfo((string) $foto['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        throw new Exception('Formato de foto nao permitido.');
    }

    $pasta = dirname(__DIR__, 2) . '/storage/uploads/vendas/';
    if (!is_dir($pasta)) {
        mkdir($pasta, 0755, true);
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
        throw new Exception('Venda nao encontrada para anexar foto.');
    }

    $nomeArquivo = gerarNomeArquivoUploadUnico($pasta, ((string) ($venda['cliente_nome'] ?? 'cliente')) . '_' . date('Ymd'), $ext);
    if (!move_uploaded_file($foto['tmp_name'], $pasta . $nomeArquivo)) {
        throw new Exception('Erro ao salvar a foto.');
    }

    $stmtFoto = $conexao->prepare("UPDATE vendas SET foto = ? WHERE id = ?");
    $stmtFoto->bind_param('si', $nomeArquivo, $id);
    $stmtFoto->execute();
    $stmtFoto->close();

    if (!empty($venda['foto'])) {
        $fotoAnterior = $pasta . basename((string) $venda['foto']);
        if (is_file($fotoAnterior)) {
            unlink($fotoAnterior);
        }
    }

    return true;
}

function buscarMovimentacaoVendaEdicao(mysqli $conexao, array $venda): ?array
{
    $temCiclo = colunaExiste($conexao, 'movimentacoes', 'ciclo_id') && array_key_exists('ciclo_id', $venda);
    $dataRef = (string) ($venda['data_venda'] ?? date('Y-m-d H:i:s'));
    $produtoEstoqueId = produtoEstoqueId($conexao, (int) $venda['produto_id']);

    if ($temCiclo) {
        $stmt = $conexao->prepare("
            SELECT id
            FROM movimentacoes
            WHERE ciclo_id <=> ?
              AND produto_id = ?
              AND tipo = 'venda'
              AND quantidade = ?
            ORDER BY ABS(TIMESTAMPDIFF(SECOND, data_movimentacao, ?)) ASC, id DESC
            LIMIT 1
        ");
        $stmt->bind_param('iids', $venda['ciclo_id'], $produtoEstoqueId, $venda['quantidade'], $dataRef);
    } else {
        $stmt = $conexao->prepare("
            SELECT id
            FROM movimentacoes
            WHERE produto_id = ?
              AND tipo = 'venda'
              AND quantidade = ?
            ORDER BY ABS(TIMESTAMPDIFF(SECOND, data_movimentacao, ?)) ASC, id DESC
            LIMIT 1
        ");
        $stmt->bind_param('ids', $produtoEstoqueId, $venda['quantidade'], $dataRef);
    }

    $stmt->execute();
    $movimentacao = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $movimentacao;
}

function sincronizarMovimentacaoVendaConcluida(mysqli $conexao, array $vendaAntes, int $produtoId, float $quantidadeTotal): void
{
    if (($vendaAntes['status'] ?? '') !== 'concluido') {
        return;
    }

    $movimentacao = buscarMovimentacaoVendaEdicao($conexao, $vendaAntes);
    $produtoEstoqueId = produtoEstoqueId($conexao, $produtoId);
    if ($movimentacao) {
        $stmtMov = $conexao->prepare("
            UPDATE movimentacoes
            SET produto_id = ?, quantidade = ?
            WHERE id = ?
        ");
        $stmtMov->bind_param('idi', $produtoEstoqueId, $quantidadeTotal, $movimentacao['id']);
        $stmtMov->execute();
        $stmtMov->close();
        return;
    }

    $cicloMovId = colunaExiste($conexao, 'movimentacoes', 'ciclo_id')
        ? ((int) ($vendaAntes['ciclo_id'] ?? 0) ?: getCicloAtivoId($conexao))
        : null;

    if ($cicloMovId !== null) {
        $stmtMov = $conexao->prepare("
            INSERT INTO movimentacoes (ciclo_id, produto_id, tipo, quantidade, data_movimentacao)
            VALUES (?, ?, 'venda', ?, NOW())
        ");
        $stmtMov->bind_param('iid', $cicloMovId, $produtoEstoqueId, $quantidadeTotal);
    } else {
        $stmtMov = $conexao->prepare("
            INSERT INTO movimentacoes (produto_id, tipo, quantidade, data_movimentacao)
            VALUES (?, 'venda', ?, NOW())
        ");
        $stmtMov->bind_param('id', $produtoEstoqueId, $quantidadeTotal);
    }
    $stmtMov->execute();
    $stmtMov->close();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../vendas.php");
    exit;
}

validarTokenCsrf();
garantirColunaFreteVenda($conexao);
garantirColunasMontagemVendaEditar($conexao);
garantirColunaFotoVendaEditar($conexao);

// Colunas extras de vendas são criadas via database/migrations.sql.
// ALTER TABLE foi removido do runtime.

$id = (int) ($_POST['id'] ?? 0);
$usuarioMontagemId = (int) ($_SESSION['usuario_id'] ?? 0);
$clienteId = (int) ($_POST['cliente_id'] ?? 0);
$produtoId = (int) ($_POST['produto_id'] ?? 0);
$previsaoEntrega = trim((string) ($_POST['previsao_entrega'] ?? ($_POST['data_venda'] ?? '')));
$tipo = strtolower(trim($_POST['tipo'] ?? ''));
$tipo_comercial_raw = strtolower(trim($_POST['tipo_comercial'] ?? ''));
$tipo_comercial = in_array($tipo_comercial_raw, ['embalado', 'oba_embalado', 'atacado', 'atacado_convencional', 'shopper'], true) ? $tipo_comercial_raw : null;
if (in_array($tipo_comercial, ['embalado', 'oba_embalado'], true)) {
    $tipo = 'bandeja';
} elseif (in_array($tipo_comercial, ['atacado', 'atacado_convencional', 'shopper'], true)) {
    $tipo = in_array($tipo, ['caixa', 'unidade'], true) ? $tipo : 'kg';
}
$prazo_raw = trim($_POST['prazo_escolhido'] ?? '');
$prazoEscolhido = in_array($prazo_raw, ['5_dias', '30_dias'], true) ? $prazo_raw : null;
$pedido              = (float) ($_POST['pedido'] ?? 0);
$numCaixasItem       = max(0, (int) ($_POST['num_caixas_item'] ?? 0));
$bandejasPorCaixaItem = max(0, (int) ($_POST['bandejas_por_caixa_item'] ?? 0));
    $frete               = max(0.0, (float) str_replace(',', '.', (string) ($_POST['frete'] ?? 0)));
$preco               = isset($_POST['preco']) ? (float) $_POST['preco'] : -1;
$preco_post_original = $preco >= 0 ? arredondarMoeda($preco) : -1.0;
$preco_editado_flag  = ($_POST['preco_editado_manualmente'] ?? '0') === '1';
$pesoUnitario        = isset($_POST['peso_unitario']) ? (float) $_POST['peso_unitario'] : 0;
$kgCaixa = null;
$gramagem = null;
$precoBaseSalvar = null;
$descontoPctSalvar = null;
$tipoAplicacaoSalvar = null;

if ($tipo_comercial === 'oba_embalado') {
    if ($numCaixasItem <= 0) {
        header("Location: ../vendas.php?msg=erro&detalhe=" . urlencode("Informe o numero de caixas deste produto para a Tabela OBA."));
        exit;
    }
    if ($bandejasPorCaixaItem <= 0) {
        header("Location: ../vendas.php?msg=erro&detalhe=" . urlencode("Selecione a quantidade de bandejas por caixa para a Tabela OBA."));
        exit;
    }
    $pedido = (float) ($bandejasPorCaixaItem * $numCaixasItem);
}

if ($id <= 0 || $clienteId <= 0 || $produtoId <= 0 || $pedido <= 0 || $preco < 0 || $previsaoEntrega === '') {
    header("Location: ../vendas.php?msg=erro&detalhe=Dados+invalidos");
    exit;
}
try {
    cadastroFiscalExigirVenda($conexao, $clienteId, $produtoId);
} catch (Throwable $e) {
    header("Location: ../vendas.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}

if (!colunaPrecoExiste($conexao)) {
    header("Location: ../vendas.php?msg=erro&detalhe=A+coluna+preco+nao+existe+na+tabela+vendas.+Execute+o+ALTER+TABLE+antes+de+editar");
    exit;
}

if (!in_array($tipo, ['bandeja', 'caixa', 'kg', 'unidade'], true)) {
    header("Location: ../vendas.php?msg=erro&detalhe=Selecione+um+tipo+valido");
    exit;
}

if ($tipo_comercial === 'embalado' || $tipo_comercial === 'oba_embalado') {
    try {
        $precoResolvido = $tipo_comercial === 'oba_embalado'
            ? resolverPrecoVendaObaEmbalado($conexao, $produtoId)
            : resolverPrecoVendaEmbalado($conexao, $produtoId, $clienteId);
        $preco = $precoResolvido['preco_venda'];
        $precoBaseSalvar = $precoResolvido['preco_base'];
        $descontoPctSalvar = $precoResolvido['percentual'];
        $tipoAplicacaoSalvar = $precoResolvido['tipo_aplicacao'];

        if ($pesoUnitario <= 0) {
            $pesoUnitario = $precoResolvido['gramagem_kg'] * 1000;
        }
    } catch (Throwable $e) {
        header("Location: ../vendas.php?msg=erro&detalhe=" . urlencode($e->getMessage()) . "&cliente_id=" . $clienteId);
        exit;
    }
} elseif (in_array($tipo_comercial, ['atacado', 'atacado_convencional', 'shopper'], true)) {
    if ($prazoEscolhido !== null) {
        try {
            if ($tipo_comercial === 'atacado_convencional') {
                $precoResolvido = resolverPrecoVendaAtacadoConvencional($conexao, $produtoId, $clienteId, $prazoEscolhido);
            } elseif ($tipo_comercial === 'shopper') {
                $precoResolvido = resolverPrecoVendaShopper($conexao, $produtoId, $prazoEscolhido);
            } else {
                $precoResolvido = resolverPrecoVendaAtacado($conexao, $produtoId, $prazoEscolhido);
            }
            $preco = $precoResolvido['preco_venda'];
            $precoBaseSalvar = $tipo_comercial === 'atacado_convencional'
                ? ($prazoEscolhido === '30_dias' ? $precoResolvido['custo_30_dias'] : $precoResolvido['custo_5_dias'])
                : ($tipo_comercial === 'shopper'
                    ? ($prazoEscolhido === '30_dias' ? $precoResolvido['custo_30_dias'] : $precoResolvido['custo_5_dias'])
                    : $preco);
            $descontoPctSalvar = $tipo_comercial === 'atacado_convencional' ? $precoResolvido['percentual'] : null;
            $tipoAplicacaoSalvar = $tipo_comercial === 'atacado_convencional' ? $precoResolvido['tipo_aplicacao'] : null;

            if ($pesoUnitario <= 0) {
                $pesoUnitario = $precoResolvido['kg_caixa'];
            }
        } catch (Throwable $e) {
            header("Location: ../vendas.php?msg=erro&detalhe=" . urlencode($e->getMessage()) . "&cliente_id=" . $clienteId);
            exit;
        }
    }
}

// ── Detecta preço manual (mesma lógica de salvar_venda.php) ──────────────────
$preco_manual = 0;
if (
    $tipo_comercial === null
    || ($preco_editado_flag && $preco_post_original >= 0)
    || (in_array($tipo_comercial, ['atacado', 'atacado_convencional', 'shopper'], true) && $prazoEscolhido === null && $preco_post_original >= 0)
) {
    $preco_manual = 1;
    if ($preco_post_original >= 0) {
        $preco = $preco_post_original;
    }
} elseif (isset($precoResolvido) && in_array($tipo_comercial, ['embalado', 'oba_embalado', 'atacado', 'atacado_convencional', 'shopper'], true)) {
    $precoCalculado = arredondarMoeda($precoResolvido['preco_venda']);
    $precoDiferente = $preco_post_original >= 0 && abs($preco_post_original - $precoCalculado) >= 0.005;
    if ($preco_editado_flag || $precoDiferente) {
        $preco_manual = 1;
        if ($preco_post_original >= 0) {
            $preco = $preco_post_original;
        }
    }
}

$preco = arredondarMoeda($preco);

if ($tipo === 'caixa') {
    if ($pesoUnitario <= 0) {
        header("Location: ../vendas.php?msg=erro&detalhe=Informe+o+kg+por+caixa");
        exit;
    }

    $kgCaixa = $pesoUnitario;
    $quantidadeTotal = $pedido * $kgCaixa;
} elseif ($tipo === 'bandeja') {
    if ($pesoUnitario <= 0) {
        header("Location: ../vendas.php?msg=erro&detalhe=Informe+a+gramagem+por+bandeja");
        exit;
    }

    $gramagem = $pesoUnitario;
    $quantidadeTotal = ($pedido * $gramagem) / 1000;
} elseif ($tipo === 'unidade') {
    $quantidadeTotal = $pedido;
} else {
    $quantidadeTotal = $pedido;
}

$conexao->begin_transaction();

try {
    $stmtBusca = $conexao->prepare("
        SELECT *
        FROM vendas
        WHERE id = ? AND (status <> 'anexado' OR usuario_montagem_id = ? OR usuario_montagem_id IS NULL)
        FOR UPDATE
    ");
    $stmtBusca->bind_param("ii", $id, $usuarioMontagemId);
    $stmtBusca->execute();
    $venda = $stmtBusca->get_result()->fetch_assoc();
    $stmtBusca->close();

    if (!$venda) {
        throw new Exception("Venda nao encontrada.");
    }

    if (!in_array($venda['status'], ['anexado', 'pendente', 'concluido'], true)) {
        throw new Exception("Esta venda não pode ser editada (status: {$venda['status']}).");
    }

    // Recalcula os campos derivados para manter o estoque e os totais corretos.
    if (colunaTipoExiste($conexao)) {
        $stmtUpdate = $conexao->prepare("
            UPDATE vendas
            SET produto_id = ?, cliente_id = ?, tipo = ?, gramagem = ?, kg_caixa = ?, pedido = ?, preco = ?, quantidade = ?, previsao_entrega = ?
            WHERE id = ?
        ");
        $stmtUpdate->bind_param("iisdddddsi", $produtoId, $clienteId, $tipo, $gramagem, $kgCaixa, $pedido, $preco, $quantidadeTotal, $previsaoEntrega, $id);
    } else {
        $stmtUpdate = $conexao->prepare("
            UPDATE vendas
            SET produto_id = ?, cliente_id = ?, gramagem = ?, kg_caixa = ?, pedido = ?, preco = ?, quantidade = ?, previsao_entrega = ?
            WHERE id = ?
        ");
        $stmtUpdate->bind_param("iidddddsi", $produtoId, $clienteId, $gramagem, $kgCaixa, $pedido, $preco, $quantidadeTotal, $previsaoEntrega, $id);
    }

    $stmtUpdate->execute();
    $stmtUpdate->close();

    $stmtFrete = $conexao->prepare("UPDATE vendas SET frete = ? WHERE id = ?");
    $stmtFrete->bind_param('di', $frete, $id);
    $stmtFrete->execute();
    $stmtFrete->close();

    if ($tipo_comercial === 'oba_embalado') {
        $stmtCaixasItem = $conexao->prepare("UPDATE vendas SET num_caixas = ?, bandejas_por_caixa = ? WHERE id = ?");
        $stmtCaixasItem->bind_param('iii', $numCaixasItem, $bandejasPorCaixaItem, $id);
        $stmtCaixasItem->execute();
        $stmtCaixasItem->close();
    }

    if (colunaTipoComercialExiste($conexao)) {
        if ($tipo_comercial !== null) {
            $stmtExtra = $conexao->prepare("
                UPDATE vendas
                SET tipo_comercial          = ?,
                    prazo_escolhido         = ?,
                    preco_base              = ?,
                    desconto_percentual     = ?,
                    tipo_aplicacao_desconto = ?,
                    preco_manual            = ?
                WHERE id = ?
            ");
            $stmtExtra->bind_param(
                'ssddsii',
                $tipo_comercial,
                $prazoEscolhido,
                $precoBaseSalvar,
                $descontoPctSalvar,
                $tipoAplicacaoSalvar,
                $preco_manual,
                $id
            );
        } else {
            // tipo_comercial nulo → preço foi digitado manualmente
            $stmtExtra = $conexao->prepare("
                UPDATE vendas
                SET tipo_comercial          = NULL,
                    prazo_escolhido         = NULL,
                    preco_base              = NULL,
                    desconto_percentual     = NULL,
                    tipo_aplicacao_desconto = NULL,
                    preco_manual            = 1
                WHERE id = ?
            ");
            $stmtExtra->bind_param('i', $id);
        }
        $stmtExtra->execute();
        $stmtExtra->close();
    }

    $fotoAtualizada = salvarFotoVendaEdicao($conexao, $id);
    sincronizarMovimentacaoVendaConcluida($conexao, $venda, $produtoId, $quantidadeTotal);

    $conexao->commit();

    registrarLog(
        $conexao,
        'venda_editada',
        'vendas',
        $id,
        "Venda #$id editada — produto_id $produtoId, cliente_id $clienteId, quantidade $quantidadeTotal, preço R$ $preco"
    );

    $redirectTo = $_POST['redirect_to'] ?? '';
    $msgRetorno = $fotoAtualizada ? 'foto' : 'atualizado';
    if ($redirectTo !== '' && strpos($redirectTo, '://') === false) {
        $sep = strpos($redirectTo, '?') !== false ? '&' : '?';
        header("Location: {$redirectTo}{$sep}msg={$msgRetorno}");
    } else {
        header("Location: ../vendas.php?msg={$msgRetorno}&cliente_id=" . $clienteId);
    }
    exit;
} catch (Throwable $e) {
    $conexao->rollback();
    header("Location: ../vendas.php?msg=erro&detalhe=" . urlencode($e->getMessage()) . "&cliente_id=" . $clienteId);
    exit;
}

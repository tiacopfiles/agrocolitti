<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/log_helper.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../../config/cadastro_fiscal_helper.php";
require __DIR__ . "/venda_historico_helper.php";

$redirectTo = trim((string) ($_POST['redirect_to'] ?? '../historico_vendas.php'));
if (!preg_match('~^\.\./historico_vendas\.php(?:\?[^\r\n#]*)?$~', $redirectTo)) {
    $redirectTo = '../historico_vendas.php';
}
function redirecionarEdicaoHistoricoVenda(string $redirectTo, array $params = []): void
{
    $separador = str_contains($redirectTo, '?') ? '&' : '?';
    header('Location: ' . $redirectTo . ($params ? $separador . http_build_query($params) : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionarEdicaoHistoricoVenda('../historico_vendas.php');
}

validarTokenCsrf();

$id         = (int) ($_POST['id'] ?? 0);
$produtoId  = (int) ($_POST['produto_id'] ?? 0);
$tipoComercialRaw = strtolower(trim((string) ($_POST['tipo_comercial'] ?? '')));
$tipoComercial = in_array($tipoComercialRaw, ['embalado', 'oba_embalado', 'atacado', 'atacado_convencional', 'shopper'], true) ? $tipoComercialRaw : null;
$tipo       = strtolower(trim((string) ($_POST['tipo'] ?? 'kg')));
$tipo       = normalizarTipoVenda($tipo);
// Uma alteracao explicita de bandeja para unidade deixa de usar as tabelas
// comerciais de embalados, que por definicao trabalham somente com bandejas.
if ($tipo === 'unidade' && in_array($tipoComercial, ['embalado', 'oba_embalado'], true)) {
    $tipoComercial = null;
}
$pedido     = (float) str_replace(',', '.', (string) ($_POST['pedido'] ?? $_POST['quantidade'] ?? 0));
$numCaixasItem = max(0, (int) ($_POST['num_caixas_item'] ?? 0));
$bandejasPorCaixaItem = max(0, (int) ($_POST['bandejas_por_caixa_item'] ?? 0));
if ($tipoComercial === 'oba_embalado') {
    $tipo = 'bandeja';
    if ($numCaixasItem > 0 && $bandejasPorCaixaItem > 0) {
        $pedido = (float) ($numCaixasItem * $bandejasPorCaixaItem);
    }
} elseif ($tipoComercial === 'embalado') {
    $tipo = 'bandeja';
}
$preco      = (float) str_replace(',', '.', (string) ($_POST['preco'] ?? 0));
$gramagem   = (float) str_replace(',', '.', (string) ($_POST['gramagem'] ?? 0));
$kgCaixa    = (float) str_replace(',', '.', (string) ($_POST['kg_caixa'] ?? 0));

if ($id <= 0) {
    redirecionarEdicaoHistoricoVenda($redirectTo, ['msg' => 'erro', 'detalhe' => 'ID inválido.']);
}
if ($produtoId <= 0) {
    redirecionarEdicaoHistoricoVenda($redirectTo, ['msg' => 'erro', 'detalhe' => 'Produto inválido.']);
}
if ($pedido <= 0 && $tipoComercial !== 'oba_embalado') {
    redirecionarEdicaoHistoricoVenda($redirectTo, ['msg' => 'erro', 'detalhe' => 'Quantidade inválida.']);
}

try {
    cadastroFiscalExigirProdutoSelecionado($conexao, $produtoId);
} catch (Throwable $e) {
    redirecionarEdicaoHistoricoVenda($redirectTo, ['msg' => 'erro', 'detalhe' => $e->getMessage()]);
}

$conexao->begin_transaction();

try {
    $resultado = vendaHistoricoAtualizarItem($conexao, $id, $produtoId, $tipo, $pedido, $preco, $gramagem, $kgCaixa, null, $tipoComercial, $numCaixasItem, $bandejasPorCaixaItem);

    $conexao->commit();

    registrarLog(
        $conexao,
        'venda_editada_historico',
        'vendas',
        $id,
        "Venda #{$id} editada no historico — produto_id {$produtoId}, tipo_comercial " . ($tipoComercial ?? 'manual') . ", tipo {$tipo}, pedido {$pedido}, quantidade {$resultado['quantidade']}, preco {$preco}"
    );

    redirecionarEdicaoHistoricoVenda($redirectTo, ['msg' => 'valor_atualizado']);

} catch (Exception $e) {
    $conexao->rollback();
    redirecionarEdicaoHistoricoVenda($redirectTo, ['msg' => 'erro', 'detalhe' => $e->getMessage()]);
}

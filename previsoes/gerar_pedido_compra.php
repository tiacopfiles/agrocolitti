<?php
require "../config/conexao.php";
require "../config/previsao_fornecedor_schema.php";
require "../auth/proteger.php";
require __DIR__ . "/pedido_compra_helper.php";
require_once "../vendor/autoload.php";

$numeroOs = trim((string) ($_GET['numero_os'] ?? ''));
$responsavel = $_SESSION['usuario_nome'] ?? $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '';

garantirFluxoFinanceiroPrevisaoFornecedor($conexao);

if (!function_exists('pedidoCompraProdutoSemGramagemDocx')) {
    function pedidoCompraProdutoSemGramagemDocx(string $nome): string
    {
        $nome = trim($nome);
        $unidades = '(?:kg|kgs|quilo|quilos|g|gr|grs|grama|gramas)';
        $limpo = preg_replace('/^\s*\d+(?:[\.,]\d+)?\s*' . $unidades . '\b\s*[-_\/]*\s*/iu', '', $nome);
        $limpo = preg_replace('/\s*[-_\/]*\s*\d+(?:[\.,]\d+)?\s*' . $unidades . '\b\s*$/iu', '', (string) $limpo);
        $limpo = trim((string) $limpo);
        return $limpo !== '' ? $limpo : $nome;
    }
}

try {
    $pedido = pedidoCompraObterSnapshot($conexao, $numeroOs, (string) $responsavel);
    if (empty($pedido['responsavel']) && $responsavel !== '') {
        $pedido['responsavel'] = (string) $responsavel;
    }
} catch (Throwable $e) {
    http_response_code(404);
    exit($e->getMessage());
}

$fornecedor = $pedido['fornecedor'] ?? [];

$templatePath = __DIR__ . '/../templates/modelo_pedido_compra_TEMPLATE.docx';
if (!file_exists($templatePath)) {
    http_response_code(500);
    exit('Template nao encontrado: ' . $templatePath);
}

$tp = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);

$tp->setValue('PEDIDO', pedidoCompraDocxTexto((string) ($pedido['numero_pedido'] ?? '')));
$tp->setValue('DATA_COMPRA', pedidoCompraDocxTexto((string) ($pedido['data_compra_br'] ?? '')));
$tp->setValue('DATA_PAGAMENTO', pedidoCompraDocxTexto((string) ($pedido['data_pagamento_br'] ?? '')));
$tp->setValue('PAGAMENTO', pedidoCompraDocxTexto((string) ($pedido['pagamento'] ?? '')));
$prazoDoc = (string) ($pedido['prazo_pagamento'] ?? '');
$tp->setValue('PRAZO_PAGAMENTO', pedidoCompraDocxTexto($prazoDoc !== '' ? $prazoDoc : '-'));
$tp->setValue('PREVISAO_ENTREGA', pedidoCompraDocxTexto((string) ($pedido['previsao_entrega_br'] ?? '')));
$tp->setValue('ENTREPOSTO', pedidoCompraDocxTexto((string) ($pedido['entreposto'] ?? '')));
$tp->setValue('FORNECEDOR', pedidoCompraDocxTexto((string) ($fornecedor['nome'] ?? '')));
$tp->setValue('CNPJ', pedidoCompraDocxTexto((string) ($fornecedor['cnpj'] ?? '')));
$tp->setValue('ENDERECO', pedidoCompraDocxTexto((string) ($fornecedor['endereco'] ?? '')));
$tp->setValue('CEP', pedidoCompraDocxTexto((string) ($fornecedor['cep'] ?? '')));
$tp->setValue('CONTATO', pedidoCompraDocxTexto((string) ($fornecedor['contato'] ?? '')));
$tp->setValue('CONSIDERACOES', pedidoCompraDocxTexto((string) ($pedido['observacoes'] ?? '')));

$itens = $pedido['itens'] ?? [];
$totalLinhas = max(1, count($itens));
$tp->cloneRow('item_descricao', $totalLinhas);
$tp->cloneRow('conf_descricao', $totalLinhas);

for ($n = 1; $n <= $totalLinhas; $n++) {
    $item = $itens[$n - 1] ?? [];
    $produtoDocx = pedidoCompraProdutoSemGramagemDocx((string) ($item['produto'] ?? ''));
    $preco = (float) ($item['preco_unitario'] ?? 0);
    $total = (float) ($item['valor_total'] ?? 0);
    $valorTexto = pedidoCompraMoeda($preco);
    if ($total > 0) {
        $valorTexto .= ' / Total ' . pedidoCompraMoeda($total);
    }

    $tp->setValue("item_descricao#$n", pedidoCompraDocxTexto($produtoDocx));
    $tp->setValue("item_quantidade#$n", pedidoCompraDocxTexto(pedidoCompraQuantidadeComUnidade((float) ($item['quantidade'] ?? 0), $item['unidade'] ?? 'kg')));
    $tp->setValue("item_valor#$n", pedidoCompraDocxTexto($valorTexto));
    $tp->setValue("conf_descricao#$n", pedidoCompraDocxTexto($produtoDocx));
    $quantidadeReal = isset($item['quantidade_recebida']) && $item['quantidade_recebida'] !== null
        ? pedidoCompraQuantidadeComUnidade((float) $item['quantidade_recebida'], $item['unidade'] ?? 'kg')
        : '';
    $descarte = isset($item['descarte']) && $item['descarte'] !== null
        ? '-' . pedidoCompraQuantidadeComUnidade((float) $item['descarte'], $item['unidade'] ?? 'kg')
        : '';
    $tp->setValue("conf_quantidade_real#$n", pedidoCompraDocxTexto($quantidadeReal));
    $tp->setValue("conf_descarte#$n", pedidoCompraDocxTexto($descarte));
    $tp->setValue("conf_entrada#$n", '');
    $tp->setValue("conf_falta_peso#$n", '');
    $tp->setValue("conf_revenda#$n", '');
}

$arquivo = 'Pedido_Compra_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $numeroOs) . '.docx';

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $arquivo . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$tp->saveAs('php://output');
exit;

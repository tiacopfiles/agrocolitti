<?php
ob_start();

require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../venda_helper.php";
require __DIR__ . "/../../config/dompdf_loader.php";

carregarDompdfSeNecessario();

use Dompdf\Dompdf;
use Dompdf\Options;

$numeroOs = trim((string) ($_GET['numero_os'] ?? ''));
$id = (int) ($_GET['id'] ?? 0);

if ($numeroOs === '' && $id <= 0) {
    http_response_code(400);
    exit('Informe a OS ou a venda.');
}

function h(?string $valor): string { return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8'); }
function moeda(float $valor): string { return 'R$ ' . number_format($valor, 2, ',', '.'); }
function numero(float $valor): string { return number_format($valor, 2, ',', '.'); }
function dataBr(?string $data): string {
    if (!$data) return '';
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', substr($data, 0, 10));
    return $dt ? $dt->format('d/m/Y') : (string) $data;
}
function valorFinalDocumentoVenda(array $v): float {
    if (array_key_exists('valor_final_editado', $v) && $v['valor_final_editado'] !== null && $v['valor_final_editado'] !== '') {
        return (float) $v['valor_final_editado'];
    }

    return calcularPrecoTotalVenda($v);
}

function quantidadeDocumentoVenda(array $item): string {
    $pedido = (float) ($item['pedido'] ?? 0);
    $tipo = normalizarTipoVenda(
        $item['tipo'] ?? null,
        $item['produto_unidade'] ?? null,
        isset($item['gramagem']) ? (float) $item['gramagem'] : null,
        isset($item['kg_caixa']) ? (float) $item['kg_caixa'] : null
    );

    $texto = numero($pedido);
    return $tipo === 'kg' ? $texto . ' Kg' : $texto;
}

if ($numeroOs !== '') {
    $stmt = $conexao->prepare("
        SELECT v.*, p.nome AS produto_nome, p.unidade AS produto_unidade, c.nome AS cliente_nome, c.documento, c.telefone, c.endereco
        FROM vendas v
        LEFT JOIN produtos p ON v.produto_id = p.id
        LEFT JOIN clientes c ON v.cliente_id = c.id
        WHERE v.numero_os = ?
        ORDER BY v.id ASC
    ");
    $stmt->bind_param('s', $numeroOs);
} else {
    $stmt = $conexao->prepare("
        SELECT v.*, p.nome AS produto_nome, p.unidade AS produto_unidade, c.nome AS cliente_nome, c.documento, c.telefone, c.endereco
        FROM vendas v
        LEFT JOIN produtos p ON v.produto_id = p.id
        LEFT JOIN clientes c ON v.cliente_id = c.id
        WHERE v.id = ?
        ORDER BY v.id ASC
    ");
    $stmt->bind_param('i', $id);
}
$stmt->execute();
$itens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$itens) {
    http_response_code(404);
    exit('Pedido de venda nao encontrado.');
}

$primeiro = $itens[0];
$numeroOsExibir = $numeroOs !== '' ? $numeroOs : (string) ($primeiro['numero_os'] ?? $primeiro['id']);
$dataVenda = dataBr($primeiro['data_venda'] ?? '');
$totalPedido = 0.0;
$linhasProdutos = '';
$vendedor = !empty($primeiro['vendedor'])
    ? (string) $primeiro['vendedor']
    : ($_SESSION['usuario_nome'] ?? $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '');
$prazoRaw = strtolower(trim((string) ($primeiro['prazo_escolhido'] ?? '')));
$prazoFallback = match ($prazoRaw) {
    '5_dias' => '5 dias',
    '30_dias' => '30 dias',
    default => '',
};
$prazoTexto = !empty($primeiro['prazo_pagamento']) ? (string) $primeiro['prazo_pagamento'] : $prazoFallback;
$formaDb = strtolower(trim((string) ($primeiro['forma_pagamento'] ?? '')));
if ($formaDb === 'boleto') {
    $formaPagamento = '( X ) BOLETO    (   ) DEPOSITO    (   ) PIX';
} elseif ($formaDb === 'deposito') {
    $formaPagamento = '(   ) BOLETO    ( X ) DEPOSITO    (   ) PIX';
} elseif ($formaDb === 'pix') {
    $formaPagamento = '(   ) BOLETO    (   ) DEPOSITO    ( X ) PIX';
} elseif ($prazoRaw === '5_dias') {
    $formaPagamento = '( X ) BOLETO    (   ) DEPOSITO    (   ) PIX';
} elseif ($prazoRaw === '30_dias') {
    $formaPagamento = '(   ) BOLETO    ( X ) DEPOSITO    (   ) PIX';
} else {
    $formaPagamento = '(   ) BOLETO    (   ) DEPOSITO    (   ) PIX';
}
$previsaoEntrega = dataBr($primeiro['previsao_entrega'] ?? '') ?: date('d/m/Y');
$consideracoes = (string) ($primeiro['consideracoes'] ?? '');

foreach ($itens as $item) {
    $precoTotal = valorFinalDocumentoVenda($item);
    $totalPedido += $precoTotal;
    $tipo = normalizarTipoVenda($item['tipo'] ?? null, $item['produto_unidade'] ?? null, isset($item['gramagem']) ? (float) $item['gramagem'] : null, isset($item['kg_caixa']) ? (float) $item['kg_caixa'] : null);
    $bandeja = strtolower($tipo) === 'bandeja' ? 'X' : '';
    $caixa = strtolower($tipo) === 'caixa' ? 'X' : '';

    $linhasProdutos .= '<tr>'
        . '<td class="descricao">' . h($item['produto_nome'] ?? '-') . '</td>'
        . '<td class="check">' . h($bandeja) . '</td>'
        . '<td class="check">' . h($caixa) . '</td>'
        . '<td>' . h(quantidadeDocumentoVenda($item)) . '</td>'
        . '<td>' . moeda((float) ($item['preco'] ?? 0)) . '</td>'
        . '</tr>';
}

$html = '<!doctype html><html><head><meta charset="UTF-8"><style>
@page{margin:22px 24px}body{font-family:DejaVu Sans,Arial,sans-serif;color:#111;font-size:10px}
.titulo{text-align:center;font-size:13px;font-weight:bold;margin:0 0 8px}.os{text-align:right;font-weight:bold;font-size:10px;margin-bottom:6px}
.grid,.produtos{width:100%;border-collapse:collapse;margin-bottom:8px}.grid td,.produtos th,.produtos td{border:1px solid #222;padding:5px;text-align:left;vertical-align:middle}
.grid td{height:24px}.label{font-weight:bold;display:inline-block;min-width:96px}.produtos th{font-weight:bold}.produtos th,.produtos td{text-align:center}
.produtos .descricao{text-align:left;width:46%}.produtos .check{width:9%;font-weight:bold}.total{width:100%;text-align:right;font-weight:bold;font-size:11px;margin-top:8px}
.consideracoes{border:1px solid #222;min-height:78px;padding:6px;margin-top:10px}.secao{font-weight:bold;margin:10px 0 4px}
</style></head><body>
<div class="titulo">PEDIDO DE VENDA</div><div class="os">OS: ' . h($numeroOsExibir) . '</div>
<table class="grid">
<tr><td><span class="label">VENDEDOR:</span> ' . h($vendedor) . '</td><td><span class="label">CLIENTE:</span> ' . h($primeiro['cliente_nome'] ?? '') . '</td></tr>
<tr><td><span class="label">CNPJ:</span> ' . h($primeiro['documento'] ?? '') . '</td><td><span class="label">CONTATO:</span> ' . h($primeiro['telefone'] ?? '') . '</td></tr>
<tr><td colspan="2"><span class="label">ENDERECO:</span> ' . h($primeiro['endereco'] ?? '') . '</td></tr>
<tr><td><span class="label">PRAZO DE PAGAMENTO:</span> ' . h($prazoTexto) . '</td><td><span class="label">FORMA DE PAGAMENTO:</span> ' . h($formaPagamento) . '</td></tr>
<tr><td><span class="label">DATA DA VENDA:</span> ' . h($dataVenda) . '</td><td><span class="label">PREVISAO DE ENTREGA:</span> ' . h($previsaoEntrega) . '</td></tr>
</table>
<div class="secao">ITENS SOLICITADOS</div><table class="produtos"><thead><tr><th>DESCRICAO:</th><th>BANDEJA</th><th>CAIXA</th><th>QUANTIDADE:</th><th>VALOR:</th></tr></thead><tbody>' . $linhasProdutos . '</tbody></table>
<div class="total">VALOR ESTIMADO TOTAL DO PEDIDO: ' . moeda($totalPedido) . '</div>
<div class="consideracoes"><strong>CONSIDERACOES:</strong><br>' . nl2br(h($consideracoes)) . '</div></body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

while (ob_get_level() > 0) ob_end_clean();
$dompdf->stream('pedido_venda_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $numeroOsExibir) . '.pdf', ['Attachment' => false]);
exit;

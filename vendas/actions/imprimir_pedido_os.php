<?php
ob_start();

require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../venda_helper.php";
require __DIR__ . "/../../config/dompdf_loader.php";

carregarDompdfSeNecessario();

use Dompdf\Dompdf;
use Dompdf\Options;

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../vendas.php");
    exit;
}

validarTokenCsrf();

$numeroOs = trim((string) ($_POST['numero_os'] ?? ''));
$id = (int) ($_POST['id'] ?? 0);

if ($numeroOs === '' && $id <= 0) {
    header("Location: ../vendas.php?msg=erro&detalhe=" . urlencode("Informe a OS para impressão."));
    exit;
}

function h(?string $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function moeda(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function numero(float $valor): string
{
    return number_format($valor, 2, ',', '.');
}

function dataBr(?string $data): string
{
    if (!$data) {
        return '';
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $data);
    return $dt ? $dt->format('d/m/Y') : $data;
}

function valorFinalDocumentoVenda(array $v): float
{
    if (array_key_exists('valor_final_editado', $v) && $v['valor_final_editado'] !== null && $v['valor_final_editado'] !== '') {
        return (float) $v['valor_final_editado'];
    }

    return calcularPrecoTotalVenda($v);
}

function quantidadeDocumentoVenda(array $item): string
{
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
        WHERE v.numero_os = ? AND v.status = 'pendente'
        ORDER BY v.id ASC
    ");
    $stmt->bind_param("s", $numeroOs);
} else {
    $stmt = $conexao->prepare("
        SELECT v.*, p.nome AS produto_nome, p.unidade AS produto_unidade, c.nome AS cliente_nome, c.documento, c.telefone, c.endereco
        FROM vendas v
        LEFT JOIN produtos p ON v.produto_id = p.id
        LEFT JOIN clientes c ON v.cliente_id = c.id
        WHERE v.id = ? AND v.status = 'pendente'
        ORDER BY v.id ASC
    ");
    $stmt->bind_param("i", $id);
}

$stmt->execute();
$resultado = $stmt->get_result();
$itens = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

if (!$itens) {
    header("Location: ../vendas.php?msg=erro&detalhe=" . urlencode("Nenhuma venda pendente encontrada para impressão."));
    exit;
}

$primeiro = $itens[0];
$numeroOsExibir = $numeroOs !== '' ? $numeroOs : (string) ($primeiro['numero_os'] ?? $primeiro['id']);
$vendedor = $_SESSION['usuario_nome'] ?? '';
$dataVenda = dataBr($primeiro['data_venda'] ?? '');
$previsaoEntrega = dataBr($primeiro['previsao_entrega'] ?? '');
$formaDb = strtolower(trim((string) ($primeiro['forma_pagamento'] ?? '')));
$formaPagamento = match ($formaDb) {
    'boleto' => '( X ) BOLETO   ( ) DEPÓSITO   ( ) PIX',
    'deposito' => '( ) BOLETO   ( X ) DEPÓSITO   ( ) PIX',
    'pix' => '( ) BOLETO   ( ) DEPÓSITO   ( X ) PIX',
    default => '( ) BOLETO   ( ) DEPÓSITO   ( ) PIX',
};
$totalKg = 0.0;
$totalPedido = 0.0;
$linhasProdutos = '';

foreach ($itens as $item) {
    $quantidadeKg = calcularQuantidadeFinalVenda($item);
    $precoTotal = valorFinalDocumentoVenda($item);
    $totalPedido += $precoTotal;

    $tipo = normalizarTipoVenda(
        $item['tipo'] ?? null,
        $item['produto_unidade'] ?? null,
        isset($item['gramagem']) ? (float) $item['gramagem'] : null,
        isset($item['kg_caixa']) ? (float) $item['kg_caixa'] : null
    );
    $ehUnidade = $tipo === 'unidade';
    if (!$ehUnidade) {
        $totalKg += $quantidadeKg;
    }

    $linhasProdutos .= '<tr>'
        . '<td>' . h($item['produto_nome'] ?? '-') . '</td>'
        . '<td>' . h(ucfirst($tipo)) . '</td>'
        . '<td>' . h(quantidadeDocumentoVenda($item)) . '</td>'
        . '<td>' . ($ehUnidade ? '-' : numero($quantidadeKg) . ' kg') . '</td>'
        . '<td>' . moeda((float) ($item['preco'] ?? 0)) . '</td>'
        . '<td>' . moeda($precoTotal) . '</td>'
        . '</tr>';
}

$templatePath = __DIR__ . '/../../storage/templates/modelo_pedido_venda.docx';
$modeloInfo = file_exists($templatePath) ? 'Modelo base: modelo_pedido_venda.docx' : 'Modelo base não encontrado';

$html = '<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 26px 28px; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #111; font-size: 11px; }
        h1 { text-align: center; font-size: 18px; margin: 0 0 14px; }
        .os { text-align: right; font-weight: bold; font-size: 13px; margin-bottom: 10px; }
        .grid { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .grid td { border: 1px solid #222; padding: 7px; vertical-align: top; }
        .label { font-weight: bold; display: inline-block; min-width: 118px; }
        .produtos { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .produtos th, .produtos td { border: 1px solid #222; padding: 7px; text-align: left; }
        .produtos th { background: #e8f5e9; }
        .totais { margin-top: 10px; text-align: right; font-size: 12px; font-weight: bold; }
        .consideracoes { border: 1px solid #222; min-height: 68px; padding: 8px; margin-top: 12px; }
        .rodape { margin-top: 10px; color: #666; font-size: 9px; text-align: right; }
    </style>
</head>
<body>
    <h1>PEDIDO DE VENDA</h1>
    <div class="os">OS: ' . h($numeroOsExibir) . '</div>
    <table class="grid">
        <tr>
            <td><span class="label">VENDEDOR:</span> ' . h($vendedor) . '</td>
            <td><span class="label">FORNECEDOR:</span> ' . h($primeiro['cliente_nome'] ?? '') . '</td>
        </tr>
        <tr>
            <td><span class="label">CNPJ/CPF:</span> ' . h($primeiro['documento'] ?? '') . '</td>
            <td><span class="label">CONTATO:</span> ' . h($primeiro['telefone'] ?? '') . '</td>
        </tr>
        <tr>
            <td colspan="2"><span class="label">ENDEREÇO:</span> ' . h($primeiro['endereco'] ?? '') . '</td>
        </tr>
        <tr>
            <td><span class="label">PRAZO DE PAGAMENTO:</span> ' . h($primeiro['prazo_escolhido'] ?? '') . '</td>
            <td><span class="label">FORMA DE PAGAMENTO:</span> ' . h($formaPagamento) . '</td>
        </tr>
        <tr>
            <td><span class="label">DATA DA VENDA:</span> ' . h($dataVenda) . '</td>
            <td><span class="label">PREVISÃO DE ENTREGA:</span> ' . h($previsaoEntrega) . '</td>
        </tr>
    </table>

    <strong>ITENS SOLICITADOS</strong>
    <table class="produtos">
        <thead>
            <tr>
                <th>DESCRIÇÃO</th>
                <th>TIPO</th>
                <th>QUANTIDADE</th>
                <th>QUANTIDADE KG</th>
                <th>VALOR UNIT.</th>
                <th>VALOR TOTAL</th>
            </tr>
        </thead>
        <tbody>' . $linhasProdutos . '</tbody>
    </table>

    <div class="totais">
        Total kg: ' . numero($totalKg) . ' kg<br>
        Total do pedido: ' . moeda($totalPedido) . '
    </div>

    <div class="consideracoes">
        <strong>CONSIDERAÇÕES:</strong>
    </div>
    <div class="rodape">' . h($modeloInfo) . '</div>
</body>
</html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

while (ob_get_level() > 0) {
    ob_end_clean();
}

$dompdf->stream('pedido_os_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $numeroOsExibir) . '.pdf', ['Attachment' => false]);
exit;

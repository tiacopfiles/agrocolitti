<?php
require "../config/conexao.php";
require "../auth/proteger.php";
require __DIR__ . "/venda_helper.php";

// Gera o pedido de venda a partir de uma OS inteira ou, no modo legado,
// a partir de uma venda individual. O arquivo .docx depende do PHP ZipArchive.
$venda_id  = isset($_GET['venda_id'])  ? (int)   $_GET['venda_id']  : 0;
$numero_os = isset($_GET['numero_os']) ? trim((string) $_GET['numero_os']) : '';

if ($numero_os !== '') {
    // Modo OS: busca todos os itens da OS
    $stmtRef = $conexao->prepare("
        SELECT v.*, p.nome AS produto_nome, p.unidade AS produto_unidade,
               c.nome AS cliente_nome, c.documento AS cliente_cnpj,
               c.endereco AS cliente_endereco, c.telefone AS cliente_telefone
        FROM vendas v
        LEFT JOIN produtos p ON v.produto_id = p.id
        LEFT JOIN clientes c ON v.cliente_id = c.id
        WHERE v.numero_os = ?
        ORDER BY v.id ASC
        LIMIT 1
    ");
    $stmtRef->bind_param('s', $numero_os);
    $stmtRef->execute();
    $venda = $stmtRef->get_result()->fetch_assoc();
    $stmtRef->close();

    if (!$venda) {
        http_response_code(404);
        exit('OS não encontrada.');
    }

    $stmtItens = $conexao->prepare("
        SELECT v.*, p.nome AS produto_nome, p.unidade AS produto_unidade
        FROM vendas v
        LEFT JOIN produtos p ON v.produto_id = p.id
        WHERE v.numero_os = ?
        ORDER BY v.id ASC
    ");
    $stmtItens->bind_param('s', $numero_os);
    $stmtItens->execute();
    $itens = $stmtItens->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtItens->close();
    $venda_id = (int) $venda['id'];
} else {
    // Modo legado: busca por venda_id
    if ($venda_id <= 0) {
        http_response_code(400);
        exit('ID inválido.');
    }

    $stmt = $conexao->prepare("
        SELECT v.*, p.nome AS produto_nome, p.unidade AS produto_unidade,
               c.nome AS cliente_nome, c.documento AS cliente_cnpj,
               c.endereco AS cliente_endereco, c.telefone AS cliente_telefone
        FROM vendas v
        LEFT JOIN produtos  p ON v.produto_id  = p.id
        LEFT JOIN clientes  c ON v.cliente_id  = c.id
        WHERE v.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $venda_id);
    $stmt->execute();
    $venda = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$venda) {
        http_response_code(404);
        exit('Venda não encontrada.');
    }

    if (!empty($venda['numero_os'])) {
        $stmtItens = $conexao->prepare("
            SELECT v.*, p.nome AS produto_nome, p.unidade AS produto_unidade
            FROM vendas v
            LEFT JOIN produtos p ON v.produto_id = p.id
            WHERE v.numero_os = ?
            ORDER BY v.id ASC
        ");
        $stmtItens->bind_param('s', $venda['numero_os']);
        $stmtItens->execute();
        $itens = $stmtItens->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtItens->close();
    } else {
        $stmtItens = $conexao->prepare("
            SELECT v.*, p.nome AS produto_nome, p.unidade AS produto_unidade
            FROM vendas v
            LEFT JOIN produtos p ON v.produto_id = p.id
            WHERE v.cliente_id = ?
              AND v.data_venda  = ?
              AND v.status      = 'pendente'
            ORDER BY v.id ASC
        ");
        $stmtItens->bind_param('is', $venda['cliente_id'], $venda['data_venda']);
        $stmtItens->execute();
        $itens = $stmtItens->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtItens->close();
    }

    if (empty($itens)) {
        $itens = [$venda];
    }
}

function calcularTotalItem(array $v): float
{
    if (array_key_exists('valor_final_editado', $v) && $v['valor_final_editado'] !== null && $v['valor_final_editado'] !== '') {
        return (float) $v['valor_final_editado'];
    }

    $preco  = (float) ($v['preco']  ?? 0);
    $pedido = (float) ($v['pedido'] ?? 0);
    $tipo   = strtolower(trim((string) ($v['tipo'] ?? '')));

    if ($tipo === 'bandeja') {
        return $preco * $pedido;
    }
    if ($tipo === 'caixa') {
        $kgCaixa = (float) ($v['kg_caixa'] ?? $v['peso_unitario'] ?? 0);
        return $preco * ($pedido * $kgCaixa);
    }
    return $preco * $pedido;
}

function formatarQuantidadePedidoVenda(array $item): string
{
    $pedido = (float) ($item['pedido'] ?? 0);
    $tipo = normalizarTipoVenda(
        $item['tipo'] ?? null,
        $item['produto_unidade'] ?? null,
        isset($item['gramagem']) ? (float) $item['gramagem'] : null,
        isset($item['kg_caixa']) ? (float) $item['kg_caixa'] : null
    );

    if ($tipo === 'caixa') {
        $kgCaixa = (float) ($item['kg_caixa'] ?? 0);
        return number_format($pedido * $kgCaixa, 2, ',', '.') . ' Kg';
    }

    $texto = number_format($pedido, 2, ',', '.');
    return $tipo === 'kg' ? $texto . ' Kg' : $texto;
}

$vendedor = !empty($venda['vendedor'])
    ? htmlspecialchars_decode((string) $venda['vendedor'])
    : ($_SESSION['usuario_nome'] ?? $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '');

// Prazo de pagamento: usa o campo de texto livre (Step 3) de qualquer item da OS.
// Varre em ordem reversa para pegar o último preenchido.
// NÃO usa prazo_escolhido (Step 2) como fallback — esse campo é exclusivo para cálculo de preço atacado.
$prazoTexto = '';
foreach (array_reverse($itens) as $_prazoItem) {
    $pp = trim((string) ($_prazoItem['prazo_pagamento'] ?? ''));
    if ($pp !== '') { $prazoTexto = $pp; break; }
}
if ($prazoTexto !== '' && preg_match('/^\d+$/', $prazoTexto)) {
    $prazoTexto .= ' dias';
}

// Forma de pagamento: assim como o prazo, usa o ultimo valor preenchido na OS.
$formaDb = '';
foreach (array_reverse($itens) as $_it) {
    $f = strtolower(trim((string) ($_it['forma_pagamento'] ?? '')));
    if ($f !== '') { $formaDb = $f; break; }
}
if ($formaDb === 'boleto') {
    $boleto = '( X )'; $deposito = '(   )'; $pix = '(   )';
} elseif ($formaDb === 'deposito') {
    $boleto = '(   )'; $deposito = '( X )'; $pix = '(   )';
} elseif ($formaDb === 'pix') {
    $boleto = '(   )'; $deposito = '(   )'; $pix = '( X )';
} else {
    $boleto = '(   )'; $deposito = '(   )'; $pix = '(   )';
}
$formaPagamento = "$boleto BOLETO    $deposito DEPÓSITO    $pix PIX";

$dataVenda = '';
if (!empty($venda['data_confirmacao'])) {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $venda['data_confirmacao']);
    if (!$dt) $dt = DateTimeImmutable::createFromFormat('Y-m-d', $venda['data_confirmacao']);
    if ($dt) $dataVenda = $dt->format('d/m/Y');
}

$previsaoEntrega = '';
if (!empty($venda['previsao_entrega'])) {
    $dtEntrega = DateTimeImmutable::createFromFormat('Y-m-d', $venda['previsao_entrega']);
    if ($dtEntrega) $previsaoEntrega = $dtEntrega->format('d/m/Y');
}
if ($previsaoEntrega === '') {
    $previsaoEntrega = date('d/m/Y');
}

$entreposto = trim((string) ($venda['entreposto'] ?? ''));
if ($entreposto === '') {
    foreach (array_reverse($itens) as $_it) {
        $valorEntreposto = trim((string) ($_it['entreposto'] ?? ''));
        if ($valorEntreposto !== '') { $entreposto = $valorEntreposto; break; }
    }
}

// Busca considerações no $venda; se vazio, percorre os itens
$consideracoes = trim((string) ($venda['consideracoes'] ?? ''));
if ($consideracoes === '') {
    foreach ($itens as $_it) {
        $c = trim((string) ($_it['consideracoes'] ?? ''));
        if ($c !== '') { $consideracoes = $c; break; }
    }
}
$consideracoes = str_replace(["\r\n", "\r", "\n"], ' ', $consideracoes);

require_once '../vendor/autoload.php';

$templatePath = __DIR__ . '/../templates/modelo_pedido_venda_TEMPLATE.docx';

if (!file_exists($templatePath)) {
    http_response_code(500);
    exit('Template não encontrado: ' . $templatePath);
}

$tp = new \PhpOffice\PhpWord\TemplateProcessor($templatePath);

$tp->setValue('VENDEDOR',         htmlspecialchars_decode($vendedor));
$tp->setValue('FORNECEDOR',       htmlspecialchars_decode($venda['cliente_nome']     ?? ''));
$tp->setValue('CNPJ',             htmlspecialchars_decode($venda['cliente_cnpj']     ?? ''));
$tp->setValue('ENDERECO',         htmlspecialchars_decode($venda['cliente_endereco'] ?? ''));
$tp->setValue('CONTATO',          htmlspecialchars_decode($venda['cliente_telefone'] ?? ''));
$tp->setValue('PRAZO_PAGAMENTO',  $prazoTexto);
$tp->setValue('FORMA_PAGAMENTO',  $formaPagamento);
$tp->setValue('DATA_VENDA',       $dataVenda);
$tp->setValue('PREVISAO_ENTREGA', $previsaoEntrega);
$tp->setValue('ENTREPOSTO',       htmlspecialchars_decode($entreposto));
$tp->setValue('CONSIDERACOES',    htmlspecialchars_decode($consideracoes));

$totalLinhas = count($itens);
$tp->cloneRow('item_descricao', $totalLinhas);

$totalGeral = 0.0;
foreach (range(1, $totalLinhas) as $n) {
    $item = $itens[$n - 1] ?? null;

    if ($item) {
        $tipo = normalizarTipoVenda(
            $item['tipo'] ?? null,
            $item['produto_unidade'] ?? null,
            isset($item['gramagem']) ? (float) $item['gramagem'] : null,
            isset($item['kg_caixa']) ? (float) $item['kg_caixa'] : null
        );
        $pedido = (float) ($item['pedido'] ?? 0);
        $preco  = (float) ($item['preco']  ?? 0);
        $totalGeral += calcularTotalItem($item);

        $tipoComercial = strtolower(trim((string) ($item['tipo_comercial'] ?? '')));
        $bandeja = ($tipo === 'bandeja') ? 'X' : '';
        $kg      = ($tipo === 'kg')      ? 'X' : '';
        if ($tipoComercial === 'oba_embalado') {
            $bandejasPorCaixa = (int) ($item['bandejas_por_caixa'] ?? 0);
            if ($bandejasPorCaixa <= 0) {
                $bandejasPorCaixa = (int) ($item['num_caixas'] ?? 0);
            }
            $caixa = $bandejasPorCaixa > 0 ? 'C/' . $bandejasPorCaixa : '';
        } else {
            $caixa = ($tipo === 'caixa') ? 'X' : '';
        }

        $nomeProduto = htmlspecialchars_decode($item['produto_nome'] ?? '');
        $tp->setValue("item_descricao#$n",  $nomeProduto);
        $tp->setValue("item_bandeja#$n",    $bandeja);
        $tp->setValue("item_kg#$n",         $kg);
        $tp->setValue("item_caixa#$n",      $caixa);
        $tp->setValue("item_quantidade#$n", formatarQuantidadePedidoVenda($item));
        $tp->setValue("item_valor#$n",      'R$ ' . number_format($preco, 2, ',', '.'));
    } else {
        $tp->setValue("item_descricao#$n",  '');
        $tp->setValue("item_bandeja#$n",    '');
        $tp->setValue("item_kg#$n",         '');
        $tp->setValue("item_caixa#$n",      '');
        $tp->setValue("item_quantidade#$n", '');
        $tp->setValue("item_valor#$n",      '');
    }
}

$tp->setValue('VALOR_TOTAL', 'R$ ' . number_format($totalGeral, 2, ',', '.'));

$filename = 'Pedido_Venda_' . $venda_id . '.docx';

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$tp->saveAs('php://output');
exit;

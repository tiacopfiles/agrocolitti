<?php



require "../config/conexao.php";



require "../config/ciclo_helper.php";



require "../auth/proteger.php";



require "../config/layout_helper.php";
require "../config/cadastro_fiscal_helper.php";
require "../comissoes/vendedores_helper.php";







function garantirColunasMontagemVendaTela(mysqli $conexao): void



{



    $colunas = [



        'usuario_montagem_id' => "ALTER TABLE vendas ADD COLUMN usuario_montagem_id INT NULL DEFAULT NULL AFTER foto",



        'usuario_montagem_nome' => "ALTER TABLE vendas ADD COLUMN usuario_montagem_nome VARCHAR(120) NULL DEFAULT NULL AFTER usuario_montagem_id",



    ];



    foreach ($colunas as $coluna => $sql) {



        $resultado = $conexao->query("SHOW COLUMNS FROM vendas LIKE '" . $conexao->real_escape_string($coluna) . "'");



        $existe = $resultado && $resultado->num_rows > 0;



        if ($resultado instanceof mysqli_result) $resultado->free();



        if (!$existe) $conexao->query($sql);



    }



}







function formatarTipoVenda(array $venda): string



{



    if (!empty($venda['tipo'])) {



        return ucfirst((string) $venda['tipo']);



    }







    if (isset($venda['gramagem']) && (float) $venda['gramagem'] > 0) {



        return "Bandeja";



    }







    if (isset($venda['kg_caixa']) && (float) $venda['kg_caixa'] > 0) {



        return "Caixa";



    }







    return "Kg";



}

function nomeProdutoOba(array $v): string
{
    if (($v['tipo_comercial'] ?? '') !== 'oba_embalado') {
        return $v['produto_nome'] ?? '-';
    }
    $nome = trim($v['produto_nome'] ?? '');
    $cx   = (int)   ($v['bandejas_por_caixa'] ?? 0);
    $cxStr   = $cx  > 0 ? ' CX C/' . $cx   : '';
    return ($nome !== '' ? $nome : '-') . $cxStr;
}

function removerGramagemInicialProduto(string $nome): string
{
    $unidades = '(?:kg|kgs|quilo|quilos|g|gr|grs|grama|gramas)';
    $limpo = preg_replace('/^\s*\d+(?:[\.,]\d+)?\s*' . $unidades . '\b\s*[-_\/]*\s*/iu', '', $nome);
    $limpo = preg_replace('/\s*[-_\/]*\s*\d+(?:[\.,]\d+)?\s*' . $unidades . '\b\s*$/iu', '', (string) $limpo);
    $limpo = trim((string) $limpo);
    return $limpo !== '' ? $limpo : $nome;
}

function chaveProdutoManualVenda(string $nome): string
{
    $nome = removerGramagemInicialProduto($nome);
    $nome = preg_replace('/\s+/u', ' ', trim($nome));
    if (function_exists('mb_strtolower')) {
        return mb_strtolower((string) $nome, 'UTF-8');
    }
    return strtolower((string) $nome);
}







function calcularQuantidadeFinalVenda(array $venda): float



{



    $pedido = isset($venda['pedido']) ?(float) $venda['pedido'] : 0.0;



    $tipo = strtolower((string) ($venda['tipo'] ?? ''));







    if ($tipo === '') {



        if (isset($venda['gramagem']) && (float) $venda['gramagem'] > 0) {



            $tipo = 'bandeja';



        } elseif (isset($venda['kg_caixa']) && (float) $venda['kg_caixa'] > 0) {



            $tipo = 'caixa';



        } else {



            $tipo = 'kg';



        }



    }







    if ($tipo === 'caixa') {



        return $pedido * (float) ($venda['kg_caixa'] ?? 0);



    }







    if ($tipo === 'bandeja') {



        return ($pedido * (float) ($venda['gramagem'] ?? 0)) / 1000;



    }







    return $pedido;



}







function calcularPrecoTotalVenda(array $venda): float



{



    $preco  = (float) ($venda['preco'] ?? 0);



    $pedido = (float) ($venda['pedido'] ?? 0);







    $tipo = strtolower(trim((string) ($venda['tipo'] ?? '')));



    if ($tipo === '' && isset($venda['gramagem']) && (float) $venda['gramagem'] > 0) {



        $tipo = 'bandeja';



    }







    // Bandeja: preço é por unidade (por bandeja), total = pedido x preço



    if (in_array($tipo, ['bandeja', 'unidade'], true)) {



        return $preco * $pedido;



    }







    // Caixa / Kg: preço é R$/kg, total = preço x quantidade_kg



    return $preco * calcularQuantidadeFinalVenda($venda);



}







function calcularPrecoTabelaVenda(array $venda): ?float



{



    if (trim((string) ($venda['tipo_comercial'] ?? '')) === '') {



        return null;



    }







    if (!isset($venda['preco_base']) || $venda['preco_base'] === null || $venda['preco_base'] === '') {



        return null;



    }







    $precoBase = (float) $venda['preco_base'];



    $percentual = isset($venda['desconto_percentual']) ? (float) $venda['desconto_percentual'] : 0.0;



    $tipoAplicacao = (string) ($venda['tipo_aplicacao_desconto'] ?? 'acrescimo');







    if ($percentual <= 0) {



        return round($precoBase, 2);



    }







    if ($tipoAplicacao === 'desconto') {



        return round($precoBase * (1.0 - $percentual), 2);



    }







    return round($precoBase * (1.0 + $percentual), 2);



}







$clienteSelecionado = isset($_GET['cliente_id']) ?(int) $_GET['cliente_id'] : 0;



$produtoSelecionado = isset($_GET['produto_id']) ?(int) $_GET['produto_id'] : 0;



$tipoComercialSelecionado = strtolower(trim((string) ($_GET['tipo_comercial'] ?? '')));



if (!in_array($tipoComercialSelecionado, ['', 'embalado', 'atacado', 'atacado_convencional', 'oba_embalado', 'shopper'], true)) {



    $tipoComercialSelecionado = '';



}







$tipoSelecionado = strtolower(trim((string) ($_GET['tipo'] ?? '')));



if (!in_array($tipoSelecionado, ['', 'bandeja', 'kg', 'unidade'], true)) {



    $tipoSelecionado = '';



}







$prazoSelecionado = trim((string) ($_GET['prazo_escolhido'] ?? ''));



if (!in_array($prazoSelecionado, ['', '5_dias', '30_dias'], true)) {



    $prazoSelecionado = '';



}







$vendedorSelecionado = comissoesVendedoresNormalizarNome((string) ($_GET['vendedor'] ?? '')) ?? '';



$numeroOsSelecionado = trim((string) ($_GET['numero_os'] ?? ''));



if ($numeroOsSelecionado === '') {



    $resultadoProximaOs = $conexao->query("



        SELECT MAX(CAST(numero_os AS UNSIGNED)) AS ultima_os



        FROM vendas



        WHERE numero_os REGEXP '^[0-9]+$'



    ");



    $linhaProximaOs = $resultadoProximaOs ?$resultadoProximaOs->fetch_assoc() : null;



    $numeroOsSelecionado = (string) (((int) ($linhaProximaOs['ultima_os'] ?? 0)) + 1);



}



$formaPagamentoSelecionada = strtolower(trim((string) ($_GET['forma_pagamento'] ?? '')));



if (!in_array($formaPagamentoSelecionada, ['', 'boleto', 'deposito', 'pix'], true)) {



    $formaPagamentoSelecionada = '';



}

$opcoesPrazoPagamento = ['5 dias', '7 dias', '15 dias', '21 dias', '30 dias', '35 dias', '45 dias'];
$prazoPagamentoSelecionado = trim((string) ($_GET['prazo_pagamento'] ?? ''));
if (!in_array($prazoPagamentoSelecionado, array_merge([''], $opcoesPrazoPagamento), true)) {
    $prazoPagamentoSelecionado = '';
}

$entrepostoSelecionado = trim((string) ($_GET['entreposto'] ?? ''));

$consideracoesSelecionadas = trim((string) ($_GET['consideracoes'] ?? ''));



$etapaInicialVenda = trim((string) ($_GET['etapa'] ?? ''));



if (!in_array($etapaInicialVenda, ['cliente', 'produto', 'finalizar'], true)) {



    $etapaInicialVenda = 'cliente';



}

$prazoLocked = (
    $etapaInicialVenda === 'produto'
    && $numeroOsSelecionado !== ''
    && in_array($tipoComercialSelecionado, ['atacado', 'atacado_convencional'], true)
    && $prazoSelecionado !== ''
);







$autoDownloadPedidoVendaUrl = '';



if (!empty($_GET['download_numero_os'])) {



    $autoDownloadPedidoVendaUrl = 'gerar_pedido_venda.php?numero_os=' . rawurlencode((string) $_GET['download_numero_os']);



} elseif (!empty($_GET['download_venda_id'])) {



    $autoDownloadPedidoVendaUrl = 'gerar_pedido_venda.php?venda_id=' . (int) $_GET['download_venda_id'];



}



$redirectEdicaoVenda = trim((string) ($_GET['redirect_to'] ?? ''));



if ($redirectEdicaoVenda === '' || strpos($redirectEdicaoVenda, '://') !== false || substr($redirectEdicaoVenda, 0, 2) === '//') {



    $redirectEdicaoVenda = '../vendas.php';



}







$dataHoje = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');



$previsaoEntregaSelecionada = trim((string) ($_GET['previsao_entrega'] ?? ($_GET['data_venda'] ?? '')));



$dataValida = DateTimeImmutable::createFromFormat('Y-m-d', $previsaoEntregaSelecionada);



if (!$dataValida || $dataValida->format('Y-m-d') !== $previsaoEntregaSelecionada) {



    $previsaoEntregaSelecionada = $dataHoje;



}







$cicloAtivo = getCicloAtivo($conexao);



$cicloAtivoId = getCicloAtivoId($conexao);



$filtroCicloV = '';



garantirColunasMontagemVendaTela($conexao);



$usuarioMontagemId = (int) ($_SESSION['usuario_id'] ?? 0);



$filtroMontagemVendaUsuario = "AND (v.usuario_montagem_id = {$usuarioMontagemId} OR v.usuario_montagem_id IS NULL)";







// Colunas extras de vendas são criadas via database/migrations.sql.



// ALTER TABLE foi removido do runtime para evitar lock em produção.







// ===============================



// BUSCAR PRODUTOS + ESTOQUE



// ===============================



$produtos = calcularEstoqueProdutosDoCiclo($conexao, $cicloAtivoId, true, false);

function produtoCodigoAntigo(array $produto): bool
{
    foreach (['codigo_interno', 'nfe_codigo_interno', 'codigo_barras'] as $campoCodigo) {
        $codigo = preg_replace('/\D+/', '', (string) ($produto[$campoCodigo] ?? ''));
        if ($codigo !== '') {
            return strlen($codigo) < 13;
        }
    }
    return false;
}

$produtoFiscalStatus = [];
$produtoCodigoAntigoStatus = [];
$produtosFiscalResult = $conexao->query("SELECT * FROM produtos");
if ($produtosFiscalResult) {
    while ($produtoFiscal = $produtosFiscalResult->fetch_assoc()) {
        $produtoIdFiscal = (int) $produtoFiscal['id'];
        $produtoFiscalStatus[$produtoIdFiscal] = cadastroFiscalProdutoCompleto($produtoFiscal);
        $produtoCodigoAntigoStatus[$produtoIdFiscal] = produtoCodigoAntigo($produtoFiscal);
    }
}

// Mapear tipo_comercial -> produto_ids ativos (filtro JS)
$produtosPorTipo = array(
    'embalado'             => array(),
    'atacado'              => array(),
    'atacado_convencional' => array(),
    'oba_embalado'         => array(),
    'shopper'              => array(),
);
$consultasProdutosPorTipo = array(
    'embalado' => "
        SELECT pe.produto_id
        FROM preco_embalado pe
        INNER JOIN produtos p ON p.id = pe.produto_id
        WHERE pe.ativo = 1
          AND COALESCE(p.escopo_produto, 'normal') IN ('normal', 'ambos')
    ",
    'atacado' => "
        SELECT pa.produto_id
        FROM preco_atacado pa
        INNER JOIN produtos p ON p.id = pa.produto_id
        WHERE pa.ativo = 1
          AND COALESCE(p.escopo_produto, 'normal') IN ('normal', 'ambos')
    ",
    'atacado_convencional' => "
        SELECT pac.produto_id
        FROM preco_atacado_convencional pac
        INNER JOIN produtos p ON p.id = pac.produto_id
        WHERE pac.ativo = 1
          AND COALESCE(p.escopo_produto, 'normal') IN ('normal', 'ambos')
    ",
    'oba_embalado' => "
        SELECT po.produto_id
        FROM preco_oba_embalado po
        INNER JOIN produtos p ON p.id = po.produto_id
        WHERE po.ativo = 1
          AND COALESCE(p.escopo_produto, 'normal') IN ('oba', 'ambos')
          AND (p.escopo_produto = 'oba' OR p.produto_principal_id IS NULL)
    ",
    'shopper' => "
        SELECT ps.produto_id
        FROM preco_shopper ps
        INNER JOIN produtos p ON p.id = ps.produto_id
        WHERE ps.ativo = 1
          AND COALESCE(p.escopo_produto, 'normal') IN ('normal', 'ambos')
    ",
);
foreach ($consultasProdutosPorTipo as $_tc => $_sqlProdutosTipo) {
    $_r = $conexao->query($_sqlProdutosTipo);
    if ($_r) {
        while ($_row = $_r->fetch_assoc()) {
            $produtosPorTipo[$_tc][] = (int) $_row['produto_id'];
        }
    }
    unset($_r, $_row);
}
unset($_tc, $_sqlProdutosTipo, $consultasProdutosPorTipo);






// ===============================



// BUSCAR CLIENTES



// ===============================



$clientesResult = $conexao->query("SELECT * FROM clientes ORDER BY nome");



$clientes = $clientesResult ?$clientesResult->fetch_all(MYSQLI_ASSOC) : [];

$clientesPorTipoComercial = array(
    'embalado'             => array(),
    'atacado_convencional' => array(),
    'oba_embalado'         => array(),
    'shopper'              => array(),
);
if (tabelaExiste($conexao, 'cliente_percentual_financeiro')) {
    $consultasClientesPorTipo = array(
        'embalado' => "SELECT DISTINCT cliente_id FROM cliente_percentual_financeiro WHERE tabela = 'embalado'",
        'atacado' => "SELECT DISTINCT cliente_id FROM cliente_percentual_financeiro WHERE tabela = 'atacado'",
        'atacado_convencional' => "SELECT DISTINCT cliente_id FROM cliente_percentual_financeiro WHERE tabela = 'convencional'",
    );
    foreach ($consultasClientesPorTipo as $_tcCliente => $_sqlClientesTipo) {
        $_rClientesTipo = $conexao->query($_sqlClientesTipo);
        if ($_rClientesTipo) {
            while ($_rowClienteTipo = $_rClientesTipo->fetch_assoc()) {
                $clientesPorTipoComercial[$_tcCliente][] = (int) $_rowClienteTipo['cliente_id'];
            }
        }
        unset($_rClientesTipo, $_rowClienteTipo);
    }
    unset($_tcCliente, $_sqlClientesTipo, $consultasClientesPorTipo);
}

$consultasClientesExclusivos = array(
    'oba_embalado' => "
        SELECT id AS cliente_id
        FROM clientes
        WHERE nome LIKE '%GRUPO FARTURA%' OR nome LIKE '%OBA%'
        ORDER BY nome
    ",
    'shopper' => "
        SELECT id AS cliente_id
        FROM clientes
        WHERE nome LIKE '%SHOPPER%'
        ORDER BY nome
    ",
);
foreach ($consultasClientesExclusivos as $_tcCliente => $_sqlClientesTipo) {
    $_rClientesTipo = $conexao->query($_sqlClientesTipo);
    if ($_rClientesTipo) {
        while ($_rowClienteTipo = $_rClientesTipo->fetch_assoc()) {
            $clientesPorTipoComercial[$_tcCliente][] = (int) $_rowClienteTipo['cliente_id'];
        }
    }
    unset($_rClientesTipo, $_rowClienteTipo);
}
unset($_tcCliente, $_sqlClientesTipo, $consultasClientesExclusivos);







$vendaAbrirEdicao = null;



$editarVendaId = (int) ($_GET['editar_venda_id'] ?? 0);



if ($editarVendaId > 0) {



    $stmtEditarVenda = $conexao->prepare("



        SELECT *



        FROM vendas



        WHERE id = ? AND status IN ('anexado', 'pendente', 'concluido')



        LIMIT 1



    ");



    $stmtEditarVenda->bind_param('i', $editarVendaId);



    $stmtEditarVenda->execute();



    $vendaEditarRow = $stmtEditarVenda->get_result()->fetch_assoc();



    $stmtEditarVenda->close();







    if ($vendaEditarRow) {



        $pesoUnitarioEditar = '';



        $tipoEditar = strtolower((string) ($vendaEditarRow['tipo'] ?? ''));



        if ($tipoEditar === 'bandeja') {



            $pesoUnitarioEditar = (float) ($vendaEditarRow['gramagem'] ?? 0);



        } elseif ($tipoEditar === 'caixa') {



            $pesoUnitarioEditar = (float) ($vendaEditarRow['kg_caixa'] ?? 0);



        }



        $vendaAbrirEdicao = [



            'id' => (int) $vendaEditarRow['id'],



            'data_venda' => (string) ($vendaEditarRow['data_venda'] ?? ''),



            'previsao_entrega' => (string) ($vendaEditarRow['previsao_entrega'] ?? ''),



            'produto_id' => (int) $vendaEditarRow['produto_id'],



            'cliente_id' => (int) $vendaEditarRow['cliente_id'],



            'tipo' => (string) ($vendaEditarRow['tipo'] ?? 'kg'),



            'tipo_comercial' => (string) ($vendaEditarRow['tipo_comercial'] ?? ''),



            'prazo_escolhido' => (string) ($vendaEditarRow['prazo_escolhido'] ?? ''),



            'pedido' => (float) ($vendaEditarRow['pedido'] ?? 0),



            'preco' => (float) ($vendaEditarRow['preco'] ?? 0),



            'frete' => (float) ($vendaEditarRow['frete'] ?? 0),



            'peso_unitario' => $pesoUnitarioEditar,



        ];



    }



}







// ===============================



// LISTAR VENDAS ANEXADAS



// ===============================



// Filtro de cliente via prepared statement para evitar interpolacao direta.



if ($clienteSelecionado > 0) {



    $stmtAnexadas = $conexao->prepare("



        SELECT v.*, p.nome AS produto_nome, c.nome AS cliente_nome



        FROM vendas v



        LEFT JOIN produtos p ON v.produto_id = p.id



        LEFT JOIN clientes c ON v.cliente_id = c.id



        WHERE v.status = 'anexado'



        {$filtroCicloV}



        {$filtroMontagemVendaUsuario}



          AND v.cliente_id = ?



        ORDER BY c.nome ASC, v.id DESC



    ");



    $stmtAnexadas->bind_param('i', $clienteSelecionado);



    $stmtAnexadas->execute();



    $vendasAnexadas = $stmtAnexadas->get_result();



    $stmtAnexadas->close();



} else {



    $vendasAnexadas = $conexao->query("



        SELECT v.*, p.nome AS produto_nome, c.nome AS cliente_nome



        FROM vendas v



        LEFT JOIN produtos p ON v.produto_id = p.id



        LEFT JOIN clientes c ON v.cliente_id = c.id



        WHERE v.status = 'anexado'



        {$filtroCicloV}



        {$filtroMontagemVendaUsuario}



        ORDER BY c.nome ASC, v.id DESC



    ");



}



$vendasAnexadasRows = $vendasAnexadas ?$vendasAnexadas->fetch_all(MYSQLI_ASSOC) : [];



$vendasAnexadasPorOs = [];



foreach ($vendasAnexadasRows as $row) {



    $osKey = trim((string) ($row['numero_os'] ?? ''));



    $vendasAnexadasPorOs[$osKey !== '' ?$osKey : '__sem_os_' . $row['id']][] = $row;



}







$registroResumo = $conexao->query("



    SELECT



        v.numero_os,



        MAX(v.cliente_id) AS cliente_id,



        MAX(v.vendedor) AS vendedor,



        MAX(v.tipo_comercial) AS tipo_comercial,



        MAX(v.data_venda) AS data_venda,



        MAX(v.previsao_entrega) AS previsao_entrega,

        MAX(v.entreposto) AS entreposto,



        MAX(v.forma_pagamento) AS forma_pagamento,



        MAX(v.prazo_pagamento) AS prazo_pagamento,



        MAX(c.nome) AS cliente_nome,



        COUNT(*) AS total_itens,



        SUM(v.quantidade) AS total_kg,



        SUM(



            CASE



                WHEN v.tipo = 'bandeja' THEN COALESCE(v.preco, 0) * COALESCE(v.pedido, 0)



                ELSE COALESCE(v.preco, 0) * COALESCE(v.quantidade, 0)



            END + COALESCE(v.frete, 0)



        ) AS total_valor



    FROM vendas v



    LEFT JOIN clientes c ON v.cliente_id = c.id



    WHERE v.status = 'anexado'



    {$filtroCicloV}



    {$filtroMontagemVendaUsuario}



    GROUP BY v.numero_os



    ORDER BY v.numero_os ASC



");







// ===============================



// LISTAR VENDAS PENDENTES (agrupadas por OS)



// ===============================



$vendasPendentesRaw = $conexao->query("



    SELECT v.*, p.nome AS produto_nome, c.nome AS cliente_nome



    FROM vendas v



    LEFT JOIN produtos p ON v.produto_id = p.id



    LEFT JOIN clientes c ON v.cliente_id = c.id



    WHERE v.status = 'pendente'

      AND (
          v.numero_os IS NULL
          OR v.numero_os = ''
          OR v.numero_os NOT REGEXP '^[0-9]+$'
          OR CAST(v.numero_os AS UNSIGNED) > 1397
      )



    {$filtroCicloV}



    ORDER BY COALESCE(v.numero_os, CONCAT('zz', v.id)), v.id ASC



");







$vendasPorOs = [];



if ($vendasPendentesRaw) {



    while ($row = $vendasPendentesRaw->fetch_assoc()) {



        $osKey = $row['numero_os'] !== null && $row['numero_os'] !== ''



            ?$row['numero_os']



            : '__sem_os_' . $row['id'];



        $vendasPorOs[$osKey][] = $row;



    }



}

// Conta itens pendentes da OS atual (para validação JS do wizard)
$itensOsAtual = count($vendasAnexadasPorOs[$numeroOsSelecionado] ?? []);

?>



<!DOCTYPE html>



<html>







<head>



    <meta charset="UTF-8">



    <meta name="viewport" content="width=device-width, initial-scale=1.0">



    <title>Vendas</title>



    <style>



        * {



            box-sizing: border-box;



        }







        body {



            margin: 0;



            font-family: 'Segoe UI', Arial, sans-serif;



            background: #f4f6f9;



            color: #222;



        }







        .header {



            background: #1b5e20;



            color: white;



            padding: 15px 20px;



            border-bottom: 3px solid #2e7d32;



        }







        .header-top {



            display: flex;



            justify-content: space-between;



            align-items: center;



            flex-wrap: wrap;



            gap: 10px;



        }







        .header h1 {



            margin: 0;



            font-size: 20px;



            font-weight: 600;



        }







        .voltar {



            background: white;



            color: #1b5e20;



            padding: 6px 14px;



            border-radius: 6px;



            text-decoration: none;



            font-weight: 600;



            font-size: 14px;



        }







        .voltar:hover {



            background: #e8f5e9;



        }







        .user-info {



            margin-top: 5px;



            font-size: 14px;



        }







        .user-info a {



            color: white;



            text-decoration: none;



            margin-left: 10px;



            font-weight: 600;



        }







        .container {



            padding: 30px;



            max-width: 1200px;



            margin: auto;



        }







        .card {



            background: white;



            padding: 25px;



            border-radius: 10px;



            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);



            margin-bottom: 30px;



        }







        .section-title {



            margin: 0 0 20px 0;



            font-size: 18px;



            color: #1b5e20;



            font-weight: 600;



        }







        .form-title {



            margin: 0 0 20px 0;



            font-size: 22px;



            color: #111;



            font-weight: 700;



            grid-column: 1 / -1;



            line-height: 1.2;



        }







        /* ── Layout side-by-side ≥1280px ── */
        .vendas-side-layout {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .vendas-side-left  { min-width: 0; display: flex; flex-direction: column; gap: 16px; }
        .vendas-side-right { min-width: 0; }

                /* ── Lista de Montagem: flexbox, sem overflow ── */
        .montagem-lista { width: 100%; overflow: hidden; }
        .montagem-row {
            display: flex;
            align-items: center;
            width: 100%;
            gap: 4px;
            padding: 6px 8px;
            box-sizing: border-box;
        }
        .montagem-header {
            background: #2e7d32;
            color: #fff;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .04em;
            border-radius: 4px 4px 0 0;
        }
        .montagem-row:not(.montagem-header) { border-bottom: 1px solid #f0f0f0; }
        .montagem-row:not(.montagem-header):hover { background: #f9fafb; }
        .col-toggle { flex: 0 0 18px; text-align: center; font-size: 10px; }
        .col-os     { flex: 0 0 36px; font-size: 12px; text-align: center; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .col-cliente{ flex: 1 1 0;    font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
        .col-itens  { flex: 0 0 32px; font-size: 12px; text-align: center; white-space: nowrap; overflow: hidden; }
        .col-total  { flex: 0 0 72px; font-size: 12px; text-align: right;  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .col-acoes  { flex: 0 0 52px; display: flex; gap: 4px; justify-content: center; align-items: center; }
        .col-acoes .btn-edit,
        .col-acoes .btn-delete { width: 22px !important; height: 22px !important; font-size: 11px !important; padding: 0 !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; min-width: 0 !important; }
        .col-acoes form { margin: 0; display: inline-flex; }
        .btn-toggle-os { background: none; border: none; cursor: pointer; font-size: 10px; padding: 0 2px; color: inherit; line-height: 1; }
        .montagem-os-item-row.hidden { display: none !important; }
        .montagem-os-item-row { background: #f8f8f8; }
        .montagem-os-item-row .col-cliente { font-style: italic; color: #555; }

        @media (min-width: 1280px) {
            .vendas-side-layout {
                flex-direction: row;
                align-items: flex-start;
                gap: 20px;
            }
            .vendas-side-left  { flex: 0 0 60%; }
            .vendas-side-right { flex: 0 0 calc(40% - 20px); display: flex; flex-direction: column; gap: 16px; align-self: flex-start; }
            .vendas-side-right .card { margin-bottom: 0; }
            .montagem-lista { max-height: 55vh; overflow-y: auto; }
            #previewVendaAoVivo { margin-bottom: 0; }
        }

        .form-venda-wrapper {



            display: block;



        }



        .form-venda {



            display: grid;



            grid-template-columns: 1fr 1fr;



            gap: 15px;



        }







        .form-card .form-venda {



            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));



            align-items: start;



            position: relative;



            min-height: 270px;



            padding-bottom: 64px;



        }







        .modal-box .form-venda {



            grid-template-columns: 1fr 1fr;



        }







        .form-group {



            display: flex;



            flex-direction: column;



            position: relative;



        }



        /* Conteúdo dinâmico não deve alterar altura do grid row */



        #preco_sugestao {
            position: relative;
            width: 100%;
            z-index: 20;
            background: #fff;
            border-radius: 4px;
            padding-top: 4px;
        }



        #estoqueProduto {
            position: relative;
            width: 100%;
            z-index: 10;
        }



        /* Grupo oculto: mantém espaço no grid mas fica invisível (evita campos pularem) */

        .form-group-oculto {

            visibility: hidden;

            pointer-events: none;

        }

        /* Gramagem some do grid completamente quando oculto (sem furo) */

        #grupoPesoUnitario.form-group-oculto,
        #grupoPrazoAtacado.form-group-oculto,
        .grupo-caixas-oba.form-group-oculto,
        #grupoQuantidade.form-group-oculto {

            display: none !important;

        }

        .form-group-oculto input,

        .form-group-oculto select,

        .form-group-oculto textarea {

            tabIndex: -1;

        }











        label {



            font-weight: 600;



            font-size: 14px;



            color: #333;



        }







        input,



        select {



            width: 100%;



            padding: 10px;



            margin-top: 5px;



            border-radius: 6px;



            border: 1px solid #d0d0d0;



            font-size: 14px;



            transition: 0.2s;



        }







        input[type="checkbox"] {



            width: auto;



            margin: 0;



        }







        input:focus,



        select:focus {



            outline: none;



            border-color: #2e7d32;



            box-shadow: 0 0 0 2px rgba(46, 125, 50, 0.15);



        }







        .estoque-info {



            margin-top: 5px;



            font-size: 13px;



            color: #1b5e20;



            font-weight: 600;



        }







        button {



            background: #2e7d32;



            color: white;



            border: none;



            padding: 10px 16px;



            border-radius: 6px;



            cursor: pointer;



            font-weight: 600;



            transition: 0.2s;



        }







        button:hover {



            background: #1b5e20;



        }














.btn-confirmar {



            background: #2e7d32;



            color: white;



            border: none;



            width: 38px;



            height: 38px;



            padding: 0;



            border-radius: 6px;



            cursor: pointer;



            font-weight: 600;



            font-size: 14px;



            display: inline-flex;



            align-items: center;



            justify-content: center;



        }







        .btn-confirmar:hover {



            background: #1b5e20;



        }







        .btn-confirmar:disabled {



            background: #aaa;



            cursor: not-allowed;



        }







        form input[type="file"] {



            width: auto;



            display: inline-block;



            margin-bottom: 5px;



        }







        .btn-delete {



            background: #c62828;



            color: white;



            width: 38px;



            height: 38px;



            padding: 0;



            border-radius: 6px;



            border: none;



            display: inline-flex;



            align-items: center;



            justify-content: center;



            cursor: pointer;



        }







        .btn-delete:hover {



            background: #a61c1c;



        }







        .os-group-header td {



            background: #e8f5e9;



            font-weight: 600;



            border-top: 2px solid #2e7d32;



        }







        .os-item-row td {



            background: #f9fbe7;



            font-size: 13px;



            padding-left: 28px !important;



        }







        .os-item-row.hidden,



        .montagem-os-item-row.hidden { display: none; }







        .live-item-preview {



            display: none;



            border: 1px solid #a5d6a7;



            background: #f4fbf5;



        }







        .live-item-preview.active {



            display: block;



        }







        .live-item-preview .preview-status {



            display: inline-flex;



            align-items: center;



            gap: 6px;



            color: #1b5e20;



            font-size: 13px;



            font-weight: 700;



            margin: 0 0 12px;



        }







        .live-item-preview table td {



            background: #fff;



        }







        .live-item-preview .preview-note {



            color: #4b6350;



            font-size: 13px;



            margin: 10px 0 0;



        }







        .live-montagem-preview {



            display: none;



            margin-top: 14px;



        }







        .live-montagem-preview.active {



            display: block;



        }







        .live-montagem-row {



            background: #fffde7 !important;



        }







        .live-montagem-row .preview-status {



            display: inline-flex;



            align-items: center;



            gap: 6px;



            background: #fff8d1;



            color: #806100;



            border-radius: 999px;



            padding: 4px 9px;



            font-size: 12px;



            font-weight: 700;



        }







        .btn-toggle-os {



            background: none;



            border: 1px solid #2e7d32;



            color: #2e7d32;



            padding: 3px 8px;



            border-radius: 4px;



            cursor: pointer;



            font-size: 14px;



            font-weight: 700;



            line-height: 1;



        }







        .btn-toggle-os:hover { background: #e8f5e9; }







        .modal-enviar-os {



            display: none;



            position: fixed;



            inset: 0;



            background: rgba(0,0,0,.45);



            z-index: 1000;



            align-items: center;



            justify-content: center;



        }







        .modal-enviar-os.active { display: flex; }







        .modal-enviar-os .modal-box {



            background: white;



            border-radius: 10px;



            padding: 30px;



            max-width: 380px;



            width: 90%;



            box-shadow: 0 8px 32px rgba(0,0,0,.2);



        }







        .modal-enviar-os h3 { margin: 0 0 16px 0; font-size: 18px; }







        .modal-enviar-os input {



            width: 100%;



            padding: 10px;



            border: 1px solid #d0d0d0;



            border-radius: 6px;



            font-size: 15px;



            margin-bottom: 16px;



        }







        .modal-enviar-os .modal-actions { display: flex; gap: 10px; justify-content: flex-end; }







        .btn-imprimir {



            background: #e65100;



            color: white;



            width: 34px;



            height: 34px;



            padding: 0;



            border-radius: 6px;



            border: none;



            text-decoration: none;



            font-weight: 600;



            font-size: 15px;



            display: inline-flex;



            align-items: center;



            justify-content: center;



            cursor: pointer;



            flex-shrink: 0;



        }







        .btn-imprimir:hover {



            background: #bf360c;



        }







        textarea {



            width: 100%;



            padding: 10px;



            margin-top: 5px;



            border-radius: 6px;



            border: 1px solid #d0d0d0;



            font-size: 14px;



            font-family: inherit;



            resize: vertical;



        }







        textarea:focus {



            outline: none;



            border-color: #2e7d32;



            box-shadow: 0 0 0 2px rgba(46, 125, 50, 0.15);



        }







        .btn-edit {



            background: #1565c0;



            color: white;



            width: 38px;



            height: 38px;



            padding: 0;



            border-radius: 6px;



            border: none;



            display: inline-flex;



            align-items: center;



            justify-content: center;



            cursor: pointer;



        }







        .btn-edit:hover {



            background: #0d47a1;



        }







        .acoes {



            display: flex;



            gap: 8px;



            justify-content: center;



            flex-wrap: wrap;



            align-items: center;



        }







        .acoes a,



        .acoes button {



            width: 34px !important;



            height: 34px !important;



            display: inline-flex !important;



            align-items: center !important;



            justify-content: center !important;



            font-size: 15px;



            padding: 0 !important;



            flex-shrink: 0;



        }

        td:has(.acoes) {

            vertical-align: middle;

        }

        .acoes form {

            display: inline-flex !important;

            align-items: center !important;

            margin: 0 !important;

        }







        .preco-manual {



            color: #c62828 !important;



            font-weight: 800;



        }







        .preco-tabela-ref {



            display: block;



            margin-top: 3px;



            color: #555;



            font-size: 11px;



            font-weight: 700;



            line-height: 1.2;



        }







        .modal-bg {



            display: none;



            position: fixed;



            inset: 0;



            background: rgba(0, 0, 0, 0.6);



            padding: 20px;



            z-index: 999;



        }







        .modal-box {



            background: white;



            width: 520px;



            max-width: 100%;



            margin: auto;



            margin-top: 7vh;



            padding: 25px;



            border-radius: 12px;



            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);



        }







        .modal-box.preview-pedido {



            width: min(1320px, 98vw);



            height: min(94vh, 940px);



            display: flex;



            flex-direction: column;



            gap: 14px;



            margin-top: 2vh;



        }







        .modal-confirmar-venda {



            width: min(760px, 96vw);



            max-height: 88vh;



            overflow: auto;



        }







        .recebimento-os-header {
            display: none;
        }

        .recebimento-os-item {
            border: 1px solid #e5ece6;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 12px;
            background: #fbfdfb;
            transition: border-color .15s ease, background .15s ease, opacity .15s ease;
        }

        .recebimento-os-item:last-child {
            margin-bottom: 0;
        }

        .recebimento-os-item.modo-abate {
            border-color: #f0c987;
            background: #fffaf2;
        }

        .recebimento-os-item.modo-excluir {
            border-color: #e0a3a3;
            background: #fdf3f3;
            opacity: .8;
        }

        .recebimento-os-cab {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 12px;
        }

        .recebimento-os-campos {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            align-items: start;
        }

        .recebimento-os-campo label,
        .recebimento-os-motivo label {
            font-weight: bold;
            font-size: 13px;
        }

        .recebimento-os-campo input,
        .recebimento-os-motivo textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 14px;
            margin-top: 4px;
        }

        @media (max-width:600px) {
            .recebimento-os-campos {
                grid-template-columns: 1fr;
            }

            .recebimento-os-cab {
                flex-direction: column;
            }
        }







        .recebimento-os-produto {



            display: flex;



            flex-direction: column;



            gap: 4px;



        }

        .recebimento-os-campo {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .recebimento-os-excluir {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 13px;
            color: #9a3a3a;
            background: #fff;
            border: 1px solid #e6d2d2;
            border-radius: 8px;
            padding: 7px 11px;
            margin: 0;
            cursor: pointer;
            font-weight: 600;
            white-space: nowrap;
        }

        .recebimento-os-excluir input {
            width: auto;
            margin: 0;
        }







        .recebimento-os-produto span,



        .recebimento-os-item small {



            color: #64748b;



            font-size: 12px;



        }







        .recebimento-os-motivo {



            display: none;



            grid-column: 1 / -1;



        }







        .recebimento-os-item.modo-abate .recebimento-os-motivo {



            display: block;



        }







        .preview-pedido iframe {



            width: 100%;



            flex: 1;



            border: 1px solid #d9e2d9;



            border-radius: 8px;



            background: #fff;



        }







        .preview-pedido .preview-actions {



            display: flex;



            justify-content: space-between;



            gap: 10px;



        }







        .wizard-progress {



            display: flex;



            align-items: center;



            gap: 0;



            margin: 2px 0 22px;



            overflow-x: auto;



            padding-bottom: 2px;



        }







        .wizard-step-indicator {



            position: relative;



            flex: 1 1 0;



            min-width: 126px;



            border: 1px solid #cfd8cf;



            border-radius: 6px;



            padding: 10px 26px 10px 12px;



            background: #f7faf7;



            color: #496149;



            font-size: 13px;



            font-weight: 700;



            text-align: center;



            white-space: nowrap;



        }







        .wizard-step-indicator + .wizard-step-indicator {



            margin-left: 18px;



        }







        .wizard-step-indicator:not(:last-child)::after {



            content: "";



            position: absolute;



            top: 50%;



            right: -18px;



            width: 18px;



            height: 2px;



            background: #b8c8b8;



            transform: translateY(-50%);



        }







        .wizard-step-indicator:not(:last-child)::before {



            content: "";



            position: absolute;



            top: 50%;



            right: -20px;



            border-top: 5px solid transparent;



            border-bottom: 5px solid transparent;



            border-left: 7px solid #b8c8b8;



            transform: translateY(-50%);



        }







        .wizard-step-indicator.active {



            background: #1b5e20;



            border-color: #1b5e20;



            color: #fff;



        }







        .wizard-hidden {



            display: none !important;



        }







        .wizard-nav {



            position: absolute;



            left: 0;



            right: 0;



            bottom: 0;



            display: flex;



            justify-content: flex-end;



            align-items: center;



            gap: 10px;



            grid-column: 1/-1;



            margin-top: 0;



            padding-top: 12px;



            border-top: 1px solid #edf2ed;



        }







                #vendaAcoesFinais { display: none; }

        .wizard-actions-final {

            display: flex;

            flex-direction: row;

            align-items: center;

            gap: 10px;

            flex-wrap: nowrap;

        }







        .wizard-actions-final button {
            flex: 0 0 38px;
            width: 38px;
            height: 38px;
            min-width: 38px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }







        .table-container {



            overflow-x: auto;



        }







        table {



            width: 100%;



            border-collapse: collapse;



            background: white;



            border-radius: 10px;



            overflow: hidden;



            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);



            min-width: 600px;



        }







        table th {



            background: #1b5e20;



            color: white;



            padding: 12px;



            font-weight: 600;



            font-size: 14px;



        }







        table td {



            padding: 12px;



            text-align: center;



            border-bottom: 1px solid #eee;



            font-size: 14px;



        }







        table tr:hover {



            background: #f1f8f4;



        }







        .msg-sucesso {



            background: #e8f5e9;



            color: #2e7d32;



            border: 1px solid #a5d6a7;



            padding: 12px 16px;



            border-radius: 8px;



            margin-bottom: 20px;



            font-weight: 600;



        }







        .msg-erro {



            background: #ffebee;



            color: #c62828;



            border: 1px solid #ef9a9a;



            padding: 12px 16px;



            border-radius: 8px;



            margin-bottom: 20px;



            font-weight: 600;



        }







        @media (max-width:600px) {



            .form-card .form-venda,



            .form-venda {



                grid-template-columns: 1fr;



            }







            button {



                width: 100%;



            }







            .container {



                padding: 20px;



            }



        }







        #foto {



            width: 137px;



        }







        #bandeja {



            width: 50px;



        }







        #bandeja_p {



            display: flex;



            justify-content: center;



        }







        .btn-preco-sugestao {



            background: #e8f5e9;



            color: #1b5e20;



            border: 1px solid #a5d6a7;



            padding: 6px 12px;



            border-radius: 6px;



            cursor: pointer;



            font-size: 13px;



            font-weight: 600;



        }







        .btn-preco-sugestao:hover {



            background: #c8e6c9;



        }







        .btn-preco-desc {



            background: #e3f2fd;



            color: #1565c0;



            border-color: #90caf9;



        }







        .btn-preco-desc:hover {



            background: #bbdefb;



        }







        .btn-foto {



            background: #7b1fa2;



            color: white;



            padding: 6px 10px;



            border-radius: 6px;



            border: none;



            cursor: pointer;



            font-size: 15px;



        }







        .btn-foto:hover {



            background: #6a1b9a;



        }







        #modalFoto {



            display: none;



            position: fixed;



            inset: 0;



            background: rgba(0, 0, 0, 0.85);



            z-index: 2000;



            align-items: center;



            justify-content: center;



        }







        #modalFoto.active {



            display: flex;



        }







        #modalFoto .foto-wrap {



            position: relative;



            max-width: 92vw;



            max-height: 92vh;



        }







        #modalFoto .foto-close {



            position: absolute;



            top: -38px;



            right: 0;



            background: white;



            color: #333;



            border: none;



            font-size: 18px;



            font-weight: bold;



            padding: 4px 12px;



            border-radius: 6px;



            cursor: pointer;



            line-height: 1.5;



        }







        #modalFotoImg {



            max-width: 90vw;



            max-height: 85vh;



            border-radius: 8px;



            display: block;



            box-shadow: 0 4px 24px rgba(0,0,0,.4);



        }







        #vendaToastRetomada {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(20px);
            background: #166534;
            color: #fff;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 4px 16px rgba(0,0,0,.18);
            opacity: 0;
            pointer-events: none;
            transition: opacity .3s ease, transform .3s ease;
            z-index: 9999;
            white-space: nowrap;
        }
        #vendaToastRetomada.visivel {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        .draft-resume-banner {



            display: none;



            align-items: center;



            justify-content: space-between;



            gap: 12px;



            background: #ecfdf3;



            border: 1px solid #86efac;



            color: #14532d;



            padding: 14px 16px;



            border-radius: 8px;



            margin-bottom: 18px;



            font-weight: 700;



            flex-wrap: wrap;



        }







        .draft-resume-banner a {



            background: #128a40;



            color: #fff;



            text-decoration: none;



            padding: 8px 12px;



            border-radius: 7px;



        }



    
        /* ============================================================
           fix-venda-form v1 - altura e alinhamento uniformes
           ============================================================ */

        /* 1. Inputs e selects: altura 40px uniforme */
        .form-venda input:not([type=checkbox]):not([type=radio]):not([type=file]),
        .form-venda select {
            height: 40px;
            box-sizing: border-box;
            padding-top: 0;
            padding-bottom: 0;
            margin-top: 5px;
            line-height: 40px;
        }

        /* 2. Textarea */
        .form-venda textarea {
            height: auto;
            min-height: 80px;
            box-sizing: border-box;
            padding: 8px 10px;
            margin-top: 5px;
            line-height: 1.45;
        }

        /* 3. Label alinhado ao topo */
        .form-venda label {
            display: block;
            line-height: 1.35;
            margin-bottom: 0;
            margin-top: 0;
        }

        /* 4. form-group alinha ao topo da linha do grid */
        .form-venda .form-group {
            align-self: start;
            justify-content: flex-start;
        }

        /* 5. Mensagens auxiliares */
        .form-venda .estoque-info,
        .form-venda small {
            margin-top: 4px;
            display: block;
        }

        /* ============================================================ */

        /* fix-info-area: reserva espaco para caixas de info dinamicas */
        .fix-info-area {
            grid-column: 1 / -1;
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-height: 0;
        }
        #preco_sugestao:empty,
        #resumoVenda:empty {
            display: none;
        }

        /* fix-preco-sugestao-compact: caixa verde menor */
        #preco_sugestao {
            align-self: flex-start;
            width: auto;
        }
        #preco_sugestao > div {
            padding: 4px 10px !important;
            font-size: 12px !important;
            border-radius: 5px !important;
            white-space: nowrap;
        }
        #preco_sugestao > div > strong,
        #preco_sugestao > div strong {
            font-size: 12px;
        }
        .btn-preco-sugestao {
            padding: 3px 9px;
            font-size: 12px;
        }
</style>



    <?php renderAppLayoutStyles(); ?>



    <script>



        // ── Utilitários ──────────────────────────────────────────────────────



        function fmtPreco(v) {



            return 'R$ ' + v.toFixed(2).replace('.', ',');



        }







        function escapeHtml(valor) {



            return String(valor ?? '').replace(/[&<>"']/g, function (char) {



                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];



            });



        }







        function textoOpcaoSelecionada(select) {



            if (!select || select.selectedIndex < 0) return '';



            return (select.options[select.selectedIndex]?.textContent || '').trim();



        }







        


        function extrairGramagemDoNomeProduto(textoProduto) {



            const texto = String(textoProduto || '').replace(/\([^)]*estoque[^)]*\)/ig, ' ');



            const regex = /(\d+(?:[\.,]\d+)?)\s*(kg|kgs|quilo|quilos|g|gr|grs|grama|gramas)\b/ig;



            let match;



            let ultima = null;



            while ((match = regex.exec(texto)) !== null) {



                ultima = match;



            }



            if (!ultima) return '';



            const valor = parseFloat(String(ultima[1]).replace(',', '.'));



            if (!Number.isFinite(valor) || valor <= 0) return '';



            const unidade = String(ultima[2]).toLowerCase();



            const gramas = unidade.startsWith('kg') || unidade.startsWith('quilo') ? valor * 1000 : valor;



            return Number.isInteger(gramas) ? String(gramas) : gramas.toFixed(2).replace(/\.00$/, '');



        }



        function aplicarGramagemProdutoSelecionado(forcar = false, prefixo = '') {



            const produtoSelect = document.getElementById(prefixo ? prefixo + '_produto_id' : 'produto');



            const tipoSelect = document.getElementById(prefixo ? prefixo + '_tipo' : 'tipo');



            const pesoInput = document.getElementById(prefixo ? prefixo + '_peso_unitario' : 'peso_unitario');



            if (!produtoSelect || !tipoSelect || !pesoInput || tipoSelect.value !== 'bandeja') return;



            if (!forcar && String(pesoInput.value || '').trim() !== '') return;



            const gramagem = extrairGramagemDoNomeProduto(textoOpcaoSelecionada(produtoSelect));



            if (gramagem !== '') {



                pesoInput.value = gramagem;



                if (typeof recalcTotal === 'function' && prefixo === '') recalcTotal();



                if (typeof recalcTotalEdicao === 'function' && prefixo === 'edit') recalcTotalEdicao();



            }



        }function mostrarEstoque() {



            const select  = document.getElementById("produto");



            const estoque = select.options[select.selectedIndex]?.getAttribute("data-estoque");



            document.getElementById("estoqueProduto").innerHTML =



                estoque ? "Estoque disponível: " + estoque + " kg" : "";



        }







        // ── Tipo comercial ───────────────────────────────────────────────────



        /* ── Filtro produtos por tipo comercial ───────────────────── */
        window.produtosPorTipo = <?= json_encode($produtosPorTipo) ?>;
        window.clientesPorTipoComercial = <?= json_encode($clientesPorTipoComercial) ?>;

        function tipoComercialMostraNomeAgranel(tc, tipoVenda = '') {
            // Embalado/bandeja e caixa/unidade sempre preservam o nome completo.
            // A limpeza continua restrita ao fluxo a granel/manual e atacado.
            if (['bandeja', 'caixa', 'unidade'].includes(tipoVenda)) return false;
            return tc === '' || tc === 'atacado' || tc === 'atacado_convencional';
        }

        function textoProdutoPorTipo(opt, tc, tipoVenda = '') {
            if (!opt) return '';
            if (tipoComercialMostraNomeAgranel(tc, tipoVenda) && opt.textAgranel) return opt.textAgranel;
            return opt.textOriginal || opt.text || '';
        }

        function atualizarTextosProdutosSelect(select, tc, tipoVenda = '') {
            if (!select) return;
            for (var i = 0; i < select.options.length; i++) {
                var opt = select.options[i];
                var original = opt.getAttribute('data-text-original') || opt.text;
                var agranel = opt.getAttribute('data-text-agranel') || original;
                opt.text = tipoComercialMostraNomeAgranel(tc, tipoVenda) ? agranel : original;
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            var sel = document.getElementById('produto');
            var clienteSel = document.getElementById('cliente_id');
            if (clienteSel) {
                window._allClienteOpts = [];
                for (var c = 0; c < clienteSel.options.length; c++) {
                    window._allClienteOpts.push({
                        value: clienteSel.options[c].value,
                        text: clienteSel.options[c].text,
                        disabled: clienteSel.options[c].disabled,
                        documento: clienteSel.options[c].getAttribute('data-documento'),
                        telefone: clienteSel.options[c].getAttribute('data-telefone'),
                        endereco: clienteSel.options[c].getAttribute('data-endereco')
                    });
                }
            }
            if (!sel) return;
            window._allProdutoOpts = [];
            for (var i = 0; i < sel.options.length; i++) {
                window._allProdutoOpts.push({
                    value:    sel.options[i].value,
                    text:     sel.options[i].text,
                    textOriginal: sel.options[i].getAttribute('data-text-original') || sel.options[i].text,
                    textAgranel: sel.options[i].getAttribute('data-text-agranel') || sel.options[i].text,
                    principal: sel.options[i].getAttribute('data-produto-principal') === '1',
                    manualKey: sel.options[i].getAttribute('data-produto-manual-chave') || '',
                    estoque:  sel.options[i].getAttribute('data-estoque'),
                    disabled: sel.options[i].disabled
                });
            }
            atualizarTextosProdutosSelect(document.getElementById('edit_produto_id'), document.getElementById('edit_tipo_comercial')?.value || '', document.getElementById('edit_tipo')?.value || '');
            var tcEl = document.getElementById('tipo_comercial');
            if (tcEl) {
                filtrarClientesPorTipoComercial(tcEl.value);
                filtrarProdutosPorTipoComercial(tcEl.value);
            }
        });

        function filtrarClientesPorTipoComercial(tc) {
            var sel = document.getElementById('cliente_id');
            if (!sel || !window._allClienteOpts) return;
            var allowed = window.clientesPorTipoComercial[tc] || null;
            var tiposComClienteRestrito = ['embalado', 'atacado_convencional', 'oba_embalado', 'shopper'];
            var filtraCliente = tiposComClienteRestrito.indexOf(tc) !== -1;
            var curVal = sel.value;
            while (sel.options.length) sel.remove(0);
            for (var i = 0; i < window._allClienteOpts.length; i++) {
                var opt = window._allClienteOpts[i];
                if (opt.value === '' || !filtraCliente || (allowed && allowed.indexOf(Number(opt.value)) !== -1)) {
                    var el = document.createElement('option');
                    el.value = opt.value;
                    el.text = opt.text;
                    if (opt.documento !== null) el.setAttribute('data-documento', opt.documento);
                    if (opt.telefone !== null) el.setAttribute('data-telefone', opt.telefone);
                    if (opt.endereco !== null) el.setAttribute('data-endereco', opt.endereco);
                    if (opt.disabled) el.disabled = true;
                    sel.appendChild(el);
                }
            }
            if (filtraCliente && curVal && (!allowed || allowed.indexOf(Number(curVal)) === -1)) {
                sel.value = '';
                atualizarClienteInfo('cliente_id', 'cliente_info');
            } else {
                sel.value = curVal;
            }
        }

        function filtrarProdutosPorTipoComercial(tc) {
            var sel     = document.getElementById('produto');
            if (!sel || !window._allProdutoOpts) return;
            var tipoVenda = document.getElementById('tipo')?.value || '';
            sel.disabled = false;
            sel.style.opacity = '';
            sel.style.cursor  = '';
            var modoManual = !tc;
            var allowed = tc ? (window.produtosPorTipo[tc] || null) : null;
            var curVal  = sel.value;
            var chavesManuaisUsadas = {};
            while (sel.options.length) sel.remove(0);
            for (var i = 0; i < window._allProdutoOpts.length; i++) {
                var opt = window._allProdutoOpts[i];
                var produtoPermitido = modoManual ? opt.principal : (!allowed || allowed.indexOf(Number(opt.value)) !== -1);
                if (modoManual && produtoPermitido && opt.manualKey) {
                    if (chavesManuaisUsadas[opt.manualKey]) {
                        produtoPermitido = false;
                    } else {
                        chavesManuaisUsadas[opt.manualKey] = true;
                    }
                }
                if (opt.value === '' || produtoPermitido) {
                    var el = document.createElement('option');
                    el.value = opt.value;
                    el.text  = textoProdutoPorTipo(opt, tc, tipoVenda);
                    el.setAttribute('data-text-original', opt.textOriginal || opt.text);
                    el.setAttribute('data-text-agranel', opt.textAgranel || opt.textOriginal || opt.text);
                    el.setAttribute('data-produto-principal', opt.principal ? '1' : '0');
                    el.setAttribute('data-produto-manual-chave', opt.manualKey || '');
                    if (opt.estoque !== null) el.setAttribute('data-estoque', opt.estoque);
                    if (opt.disabled) el.disabled = true;
                    sel.appendChild(el);
                }
            }
            var valorAtualAindaExiste = false;
            for (var j = 0; j < sel.options.length; j++) {
                if (sel.options[j].value === curVal) {
                    valorAtualAindaExiste = true;
                    break;
                }
            }
            if (curVal && !valorAtualAindaExiste) {
                sel.value = '';
                mostrarEstoque();
            } else {
                sel.value = curVal;
            }
        }

        function obaPopularBandejas(opcoes) {
            const sel = document.getElementById("bandejas_por_caixa_item");
            if (!sel) return;
            // Opcoes fixas: 6/C, 15/C, 18/C
            const lista = [6, 15, 18];
            const atual = sel.value;
            sel.innerHTML = "";
            const optVazia = document.createElement("option");
            optVazia.value = "";
            optVazia.text  = "Selecione";
            sel.appendChild(optVazia);
            lista.forEach(function (n) {
                const opt = document.createElement("option");
                opt.value = String(n);
                opt.text  = n + "/C";
                sel.appendChild(opt);
            });
            if (atual) { sel.value = atual; }
        }
        function recalcObaCaixas() {
            const selB = document.getElementById("bandejas_por_caixa_item");
            const inpC = document.getElementById("num_caixas_item");
            const pedido = document.getElementById("pedido");
            if (!selB || !inpC || !pedido) return;
            const bandejas = parseInt(selB.value || "0", 10) || 0;
            const caixas = parseFloat(inpC.value || "0") || 0;
            const totalBandejas = bandejas * caixas;
            pedido.value = totalBandejas > 0 ? totalBandejas : "";
            const ajuda = document.getElementById("obaCaixasAjuda");
            if (ajuda) {
                const gram = parseFloat(document.getElementById("peso_unitario")?.value || "0") || 0;
                const kg = totalBandejas * gram / 1000;
                ajuda.textContent = totalBandejas > 0
                    ? (totalBandejas + " bandejas • " + kg.toFixed(2).replace(".", ",") + " kg no estoque")
                    : "";
            }
            if (typeof recalcTotal === "function") recalcTotal();
        }
        function aplicarRegraClienteFartura() {
            // Cliente #218 (GRUPO FARTURA DE HORTIFRUT S.A.) -> Tipo Comercial = OBA Embalado automatico.
            // So aplica na Nova Venda. Seleciona OBA Embalado e exibe o Tipo como "Caixa" (apenas visual; backend forca bandeja).
            var cli = document.getElementById("cliente_id");
            var tc  = document.getElementById("tipo_comercial");
            if (!cli || !tc) { return; }
            if (parseInt(cli.value, 10) === 218) {
                if (tc.value !== "oba_embalado") {
                    tc.value = "oba_embalado";
                    if (typeof onTipoComercialChange === "function") { onTipoComercialChange(); }
                }
                // Etapa 2.Produto: exibir Tipo como "Caixa" (value=unidade). O backend ignora
                // esse campo para OBA (salvar_venda forca 'bandeja'), entao nao compromete o
                // calculo por num. de caixas x bandejas.
                var tipoSel = document.getElementById("tipo");
                if (tipoSel && tipoSel.value !== "unidade") {
                    tipoSel.value = "unidade";
                    if (typeof atualizarCamposTipo === "function") { atualizarCamposTipo(); }
                }
            }
        }

        function onTipoComercialChange() {



            const tc        = document.getElementById("tipo_comercial").value;

            filtrarClientesPorTipoComercial(tc);



            const grupoPeso = document.getElementById("grupoPesoUnitario");



            const grupoPrazo = document.getElementById("grupoPrazoAtacado");



            const pesoInput = document.getElementById("peso_unitario");



            const tipoSel   = document.getElementById("tipo");



            const prazoSel  = document.getElementById("prazo_escolhido");







            if (tc === "embalado") {



                // Embalado -> sempre bandeja; gramagem vem da tabela (campo oculto)



                tipoSel.value = "bandeja";



                atualizarCamposTipo();



                if (grupoPeso) grupoPeso.classList.add("form-group-oculto");



                if (grupoPrazo) grupoPrazo.classList.add("form-group-oculto");



                if (pesoInput) { pesoInput.required = false; }



                if (prazoSel) { prazoSel.required = false; prazoSel.value = ""; }



            } else if (tc === "atacado" || tc === "atacado_convencional" || tc === "shopper") {



                // Atacado / Shopper -> sempre kg



                tipoSel.value = "kg";



                atualizarCamposTipo();



                if (grupoPeso) grupoPeso.classList.add("form-group-oculto");



                if (grupoPrazo) grupoPrazo.classList.add("form-group-oculto");



                if (pesoInput) { pesoInput.required = false; }



                if (prazoSel) { prazoSel.required = false; }



            } else if (tc === "oba_embalado") {



                // OBA Embalado -> sempre bandeja; gramagem vem da tabela



                tipoSel.value = "bandeja";



                atualizarCamposTipo();



                if (grupoPeso) grupoPeso.classList.add("form-group-oculto");



                if (grupoPrazo) grupoPrazo.classList.add("form-group-oculto");



                if (pesoInput) { pesoInput.required = false; }



                if (prazoSel) { prazoSel.required = false; prazoSel.value = ""; }



            } else {



                // Manual → mostra campo peso/gramagem



                if (grupoPeso) grupoPeso.classList.remove("form-group-oculto");



                atualizarCamposTipo();



                if (grupoPrazo) grupoPrazo.classList.add("form-group-oculto");



                if (prazoSel) { prazoSel.required = false; prazoSel.value = ""; }



            }







            // Mostra/oculta os campos de caixas (Tabela OBA) e a quantidade manual
            (function () {
                const gruposCaixasOba = document.querySelectorAll(".grupo-caixas-oba");
                const grupoQtd       = document.getElementById("grupoQuantidade");
                const pedidoInput    = document.getElementById("pedido");
                const numCaixasInp   = document.getElementById("num_caixas_item");
                const bandejasSel    = document.getElementById("bandejas_por_caixa_item");
                const ehOba = (tc === "oba_embalado");
                gruposCaixasOba.forEach(function (g) { g.classList.toggle("form-group-oculto", !ehOba); });
                if (grupoQtd) grupoQtd.classList.toggle("form-group-oculto", ehOba);
                if (pedidoInput) pedidoInput.required = !ehOba;
                if (numCaixasInp) numCaixasInp.required = ehOba;
                if (bandejasSel) bandejasSel.required = ehOba;
            })();

            // Limpa seleção anterior de preço, snapshots e flag manual



            document.getElementById("preco_sugestao").innerHTML          = "";



            document.getElementById("preco").value                       = "";



            document.getElementById("preco_editado_manualmente").value   = "0";



            if (tc !== "atacado" && tc !== "atacado_convencional" && tc !== "shopper") {



                document.getElementById("prazo_escolhido").value = "";



            }



            document.getElementById("preco_base_snapshot").value      = "";



            document.getElementById("desconto_pct_snapshot").value    = "0";



            document.getElementById("tipo_aplicacao_snapshot").value  = "";



            document.getElementById("resumoVenda").innerHTML          = "";



            const labelUnid = document.getElementById('precoUnidLabel');



            if (labelUnid) labelUnid.textContent = (tc === "atacado" || tc === "atacado_convencional" || tc === "shopper") ? "(R$/kg)" : "";







            filtrarProdutosPorTipoComercial(tc);
            buscarPreco();



        }







        // ── Precificação automática (AJAX) ───────────────────────────────────



        function sincronizarPrazoPagamentoComPreco(recalcular = true) {
            const pagamento = document.getElementById('prazo_pagamento');
            const prazoTabela = document.getElementById('prazo_escolhido');
            if (!pagamento || !prazoTabela) return;

            const prazoAnterior = prazoTabela.value;
            prazoTabela.value = ({ '5 dias': '5_dias', '30 dias': '30_dias' })[pagamento.value] || '';

            const tipoComercial = document.getElementById('tipo_comercial')?.value || '';
            if (!['atacado', 'atacado_convencional', 'shopper'].includes(tipoComercial)) return;

            const preco = document.getElementById('preco');
            const precoManual = document.getElementById('preco_editado_manualmente');
            if (!prazoTabela.value) {
                if (prazoAnterior && preco && precoManual?.value === '0') {
                    preco.value = '';
                    recalcTotal();
                }
                return;
            }

            if (preco) preco.setCustomValidity('');
            aplicarPrazoAtacado();
            if (recalcular) buscarPreco();
        }

        function validarPrecoVendaFinal() {
            const tipoComercial = document.getElementById('tipo_comercial')?.value || '';
            const produto = document.getElementById('produto');
            if (!produto?.value || !['atacado', 'atacado_convencional', 'shopper'].includes(tipoComercial)) return true;

            const preco = document.getElementById('preco');
            if (preco && parseFloat(preco.value) > 0) {
                preco.setCustomValidity('');
                return true;
            }

            vendaWizardStep = 1;
            renderWizardVenda();
            if (preco) {
                preco.setCustomValidity('Para prazos sem valor tabelado, informe o preco manualmente.');
                preco.reportValidity();
            }
            return false;
        }

        async function buscarPreco() {



            const tc        = document.getElementById("tipo_comercial").value;



            const produtoId = document.getElementById("produto").value;



            const clienteId = document.getElementById("cliente_id").value;



            const prazo     = document.getElementById("prazo_escolhido").value;



            const sugestao  = document.getElementById("preco_sugestao");







            sugestao.innerHTML = "";



            if (!tc || !produtoId) return;



            if ((tc === "embalado" || tc === "atacado_convencional") && !clienteId) {  // oba_embalado e shopper nao precisam de cliente



                sugestao.innerHTML = '<span style="color:#888;font-size:13px;">Selecione o cliente para ver o preço.</span>';



                return;



            }







            const params = new URLSearchParams({ tipo: tc, produto_id: produtoId, cliente_id: clienteId, prazo: prazo });







            try {



                const resp = await fetch("../tabelas/api_preco.php?" + params);



                const data = await resp.json();







                // Produto indisponível



                if (data.indisponivel) {



                    sugestao.innerHTML =



                        '<span style="color:#c62828;font-size:13px;font-weight:700;">' +



                        'Atenção: Produto indisponível para venda ' + tc + '.</span>';



                    return;



                }







                // Erro genérico



                if (data.erro) {



                    sugestao.innerHTML =



                        '<span style="color:#999;font-size:13px;">(' + data.erro + ')</span>';



                    return;



                }







                // ATACADO / SHOPPER



                if (tc === "atacado" || tc === "atacado_convencional" || tc === "shopper") {



                    // Auto-preenche kg_caixa no campo oculto (para calcular quantidade_kg)



                    const pesoInput = document.getElementById("peso_unitario");



                    if (pesoInput && data.kg_caixa) {



                        pesoInput.value = data.kg_caixa.toFixed(2);



                    }







                    const p5  = data.prazo_5_dias;



                    const p30 = data.prazo_30_dias;



                    const prazoSel = document.getElementById("prazo_escolhido");







                    if (prazoSel) {



                        prazoSel.dataset.preco5 = String(p5);



                        prazoSel.dataset.preco30 = String(p30);



                    }







                    if (prazo === "5_dias" || prazo === "30_dias") {



                        const precoVenda = prazo === "30_dias" ? p30 : p5;



                        selecionarPreco(precoVenda, prazo);



                    } else {



                        document.getElementById("preco").value = "";



                    }







                    sugestao.innerHTML =



                        '<div style="margin-top:8px;font-size:13px;background:#eef6ff;border:1px solid #90caf9;border-radius:6px;padding:10px 14px;">' +



                        ((tc === "atacado" || tc === "atacado_convencional") && data.percentual > 0 ?



                             '<strong>Custo 5 dias:</strong> ' + fmtPreco(data.custo_5_dias) +



                              ' &nbsp;|&nbsp; <strong>Custo 30 dias:</strong> ' + fmtPreco(data.custo_30_dias) +



                              ' &nbsp;|&nbsp; '



                            : '') +



                        '<strong>Prazo 5 dias:</strong> ' + fmtPreco(p5) +



                        ' &nbsp;|&nbsp; <strong>Prazo 30 dias:</strong> ' + fmtPreco(p30) +



                        (prazo ? '' : ' &nbsp;|&nbsp; <span style="color:#666;">O preço tabelado será aplicado ao selecionar 5 ou 30 dias na etapa Finalizar. Para outros prazos, informe o preço manualmente.</span>') +



                        '</div>';



                }







                // EMBALADO / OBA EMBALADO



                else if (tc === "embalado" || tc === "oba_embalado") {



                    // Auto-preenche gramagem em gramas no campo oculto (para calcular kg total)



                    const pesoInput = document.getElementById("peso_unitario");



                    if (pesoInput && data.gramagem_kg) {



                        pesoInput.value = (data.gramagem_kg * 1000).toFixed(0);



                    }

                    // OBA: popula o dropdown de bandejas por caixa e recalcula a quantidade (bandejas x caixas)
                    if (tc === "oba_embalado") {
                        obaPopularBandejas([6, 15, 18]);
                        recalcObaCaixas();
                    }







                    const pBase  = data.preco_calculado;



                    const pFinal = data.preco_com_percentual;



                    const pct    = (parseFloat(data.percentual) * 100).toFixed(2);



                    const tipo   = data.tipo_aplicacao;



                    const temPct = parseFloat(data.percentual) > 0;







                    // Preenche snapshot nos campos hidden (para o backend)



                    document.getElementById('preco_base_snapshot').value     = pBase;



                    document.getElementById('desconto_pct_snapshot').value   = data.percentual;



                    document.getElementById('tipo_aplicacao_snapshot').value = tipo;







                    // Auto-seleciona o preço final do cliente



                    selecionarPreco(temPct ? pFinal : pBase, '');







                    // Atualiza label do campo preço



                    const labelUnid = document.getElementById('precoUnidLabel');



                    if (labelUnid) labelUnid.textContent = '(R$/bandeja)';







                    // Exibe detalhes do cálculo



                    let html = '<div style="margin-top:8px;font-size:13px;background:#f1f8e9;border:1px solid #a5d6a7;border-radius:6px;padding:10px 14px;">';



                    html += '<strong>Preço Base:</strong> ' + fmtPreco(pBase);



                    if (temPct) {



                        const cor   = tipo === 'desconto' ? '#c62828' : '#2e7d32';



                        const sinal = tipo === 'desconto' ? '−' : '+';



                        html += ' &nbsp;→&nbsp; <span style="color:' + cor + ';">' + sinal + pct + '% (' +



                                (tipo === 'desconto' ? 'desconto' : 'acréscimo') + ')</span>' +



                                ' &nbsp;→&nbsp; <strong>' + fmtPreco(pFinal) + ' (aplicado automaticamente)</strong>';



                    } else {



                        html += ' &nbsp;<span style="color:#888;">(sem ajuste para este cliente)</span>';



                    }



                    html += '</div>';



                    // Botões para troca manual



                    html += '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;">';



                    html += '<button type="button" class="btn-preco-sugestao"' +



                            ' onclick="selecionarPreco(' + pBase + ',\'\')">' +



                            'Usar Preço Base: ' + fmtPreco(pBase) + '</button>';



                    if (temPct) {



                        const labelBtn = tipo === 'desconto' ?



                             'Usar c/ Desconto (' + pct + '%): '



                            : 'Usar c/ Acréscimo (' + pct + '%): ';



                        html += '<button type="button" class="btn-preco-sugestao btn-preco-desc"' +



                                ' onclick="selecionarPreco(' + pFinal + ',\'\')">' +



                                labelBtn + fmtPreco(pFinal) + '</button>';



                    }



                    html += '</div>';



                    sugestao.innerHTML = html;



                }







            } catch (e) {



                sugestao.innerHTML = '<span style="color:#999;font-size:13px;">(Erro ao buscar preço)</span>';



            }



        }







        async function buscarPrecoEdicao() {



            const tc        = document.getElementById("edit_tipo_comercial")?.value || "";



            const produtoId = document.getElementById("edit_produto_id")?.value || "";



            const clienteId = document.getElementById("edit_cliente_id")?.value || "";



            const prazo     = document.getElementById("edit_prazo_escolhido")?.value || "";







            if (!produtoId) return;



            if ((tc === "embalado" || tc === "atacado_convencional") && !clienteId) return;



            if (tc !== "embalado" && tc !== "atacado" && tc !== "atacado_convencional") return;







            try {



                const params = new URLSearchParams({ tipo: tc, produto_id: produtoId, cliente_id: clienteId, prazo: prazo });



                const resp = await fetch("../tabelas/api_preco.php?" + params);



                const data = await resp.json();







                if (data.erro || data.indisponivel) return;







                if (tc === "embalado") {



                    const precoVenda = parseFloat(data.preco_venda);



                    const gramagemKg = parseFloat(data.gramagem_kg);







                    if (!Number.isNaN(precoVenda)) {



                        document.getElementById("edit_preco").value = precoVenda.toFixed(2);



                        // Preenchido pelo sistema → não é manual



                        document.getElementById("edit_preco_editado_manualmente").value = "0";



                    }







                    if (!Number.isNaN(gramagemKg) && gramagemKg > 0) {



                        document.getElementById("edit_peso_unitario").value = (gramagemKg * 1000).toFixed(0);



                    }



                } else if (tc === "atacado" || tc === "atacado_convencional") {



                    const prazoSel = document.getElementById("edit_prazo_escolhido");



                    const p5 = parseFloat(data.prazo_5_dias);



                    const p30 = parseFloat(data.prazo_30_dias);



                    const kgCaixa = parseFloat(data.kg_caixa);







                    if (prazoSel) {



                        prazoSel.dataset.preco5 = String(p5);



                        prazoSel.dataset.preco30 = String(p30);



                    }







                    if (!Number.isNaN(kgCaixa) && kgCaixa > 0) {



                        document.getElementById("edit_peso_unitario").value = kgCaixa.toFixed(2);



                    }







                    aplicarPrazoAtacadoEdicao();



                }



            } catch (e) {



                // Mantém o modal funcional mesmo se a consulta falhar.



            }



        }







        function aplicarPrazoAtacado() {



            const prazoSel = document.getElementById("prazo_escolhido");



            if (!prazoSel) return;







            const prazo = prazoSel.value;



            const preco5 = parseFloat(prazoSel.dataset.preco5 || '');



            const preco30 = parseFloat(prazoSel.dataset.preco30 || '');







            if (prazo === '5_dias' && !Number.isNaN(preco5)) {



                selecionarPreco(preco5, prazo);



            } else if (prazo === '30_dias' && !Number.isNaN(preco30)) {



                selecionarPreco(preco30, prazo);



            } else {



                document.getElementById("preco").value = "";



                recalcTotal();



            }



        }







        function aplicarPrazoAtacadoEdicao() {



            const prazoSel = document.getElementById("edit_prazo_escolhido");



            if (!prazoSel) return;







            const prazo  = prazoSel.value;



            const preco5 = parseFloat(prazoSel.dataset.preco5  || '');



            const preco30 = parseFloat(prazoSel.dataset.preco30 || '');







            if (prazo === '5_dias' && !Number.isNaN(preco5)) {



                document.getElementById("edit_preco").value = preco5.toFixed(2);



                document.getElementById("edit_preco_editado_manualmente").value = "0";



            } else if (prazo === '30_dias' && !Number.isNaN(preco30)) {



                document.getElementById("edit_preco").value = preco30.toFixed(2);



                document.getElementById("edit_preco_editado_manualmente").value = "0";



            }



        }







        function selecionarPreco(valor, prazo) {



            document.getElementById("preco").value = valor.toFixed(2);



            document.getElementById("prazo_escolhido").value = prazo || "";



            // Preço foi preenchido pelo sistema → não é manual



            document.getElementById("preco_editado_manualmente").value = "0";



            recalcTotal();



        }







        // ── Preview de total antes de salvar ─────────────────────────────────



        function recalcTotal() {



            const tipo   = document.getElementById("tipo")?.value  || "";



            const pedido = parseFloat(document.getElementById("pedido")?.value)        || 0;



            const preco  = parseFloat(document.getElementById("preco")?.value)         || 0;



            const peso   = parseFloat(document.getElementById("peso_unitario")?.value) || 0;



            const frete  = parseFloat(document.getElementById("frete")?.value) || 0;



            const resumo = document.getElementById("resumoVenda");



            if (!resumo) return;







            if (pedido <= 0 || preco <= 0) {



                resumo.innerHTML = "";



                atualizarPreviewVendaAoVivo();



                return;



            }







            let total   = 0;



            let descQtd = "";



            let unidPreco = "";







            if (tipo === "bandeja") {



                // Preço por unidade; total = pedido x preço



                total     = pedido * preco;



                const gStr = peso > 0 ? " de " + peso + " g" : "";



                descQtd   = pedido + " bandeja(s)" + gStr;



                unidPreco = "/bandeja";



            } else if (tipo === "caixa") {



                // Preço por kg; total = preço x (pedido x kg_caixa)



                const kgTotal = pedido * peso;



                total     = preco * kgTotal;



                descQtd   = pedido + " caixa(s)" + (peso > 0 ? " x " + peso + " kg = " + kgTotal.toFixed(3) + " kg" : "");



                unidPreco = "/kg";



            } else if (tipo === "unidade") {



                total     = pedido * preco;



                descQtd   = pedido + " unidade(s)";



                unidPreco = "/unidade";



            } else {



                // Kg: total = preço x pedido



                total     = preco * pedido;



                descQtd   = pedido + " kg";



                unidPreco = "/kg";



            }







            resumo.innerHTML =



                '<div style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:6px;' +



                'padding:10px 16px;font-size:13px;display:flex;gap:20px;flex-wrap:wrap;align-items:center;">' +



                '<span><strong>Qtd:</strong> ' + descQtd + '</span>' +



                '<span><strong>Preço unit.:</strong> ' + fmtPreco(preco) + unidPreco + '</span>' +



                '<span style="font-size:15px;font-weight:700;color:#1b5e20;"><strong>Total estimado: ' +



                fmtPreco(total + frete) + '</strong></span>' +



                '</div>';



            atualizarPreviewVendaAoVivo();



        }







        function calcularResumoVendaAtual() {



            const tipo = document.getElementById("tipo")?.value || "";



            const pedido = parseFloat(document.getElementById("pedido")?.value) || 0;



            const preco = parseFloat(document.getElementById("preco")?.value) || 0;



            const peso = parseFloat(document.getElementById("peso_unitario")?.value) || 0;



            const frete = parseFloat(document.getElementById("frete")?.value) || 0;



            let total = 0;



            let kgTotal = pedido;



            let quantidade = "";



            let unidadePreco = "/kg";







            if (!tipo || pedido <= 0 || preco <= 0) return null;







            if (tipo === "bandeja") {



                total = pedido * preco;



                kgTotal = peso > 0 ? (pedido * peso) / 1000 : 0;



                quantidade = pedido + " bandeja(s)" + (peso > 0 ? " de " + peso + " g" : "");



                unidadePreco = "/bandeja";



            } else if (tipo === "caixa") {



                kgTotal = peso > 0 ? pedido * peso : 0;



                total = preco * kgTotal;



                quantidade = pedido + " caixa(s)" + (peso > 0 ? " x " + peso + " kg" : "");



            } else if (tipo === "unidade") {



                kgTotal = 0;



                total = preco * pedido;



                quantidade = pedido + " unidade(s)";



                unidadePreco = "/unidade";



            } else {



                total = preco * pedido;



                quantidade = pedido + " kg";



            }







            return {



                quantidade: quantidade,



                kgTotal: kgTotal,



                preco: preco,



                unidadePreco: unidadePreco,



                frete: frete,



                total: total + frete



            };



        }







        function atualizarPreviewVendaAoVivo() {



            const preview = document.getElementById("previewVendaAoVivo");



            const tbody = document.getElementById("previewVendaAoVivoBody");



            if (!preview || !tbody) return;







            const produtoSelect = document.getElementById("produto");



            const clienteSelect = document.getElementById("cliente_id");



            const numeroOs = valorCampoVenda("numero_os").trim();



            const resumo = calcularResumoVendaAtual();



            const produtoId = produtoSelect?.value || "";



            const clienteId = clienteSelect?.value || "";







            if (!produtoId || !clienteId || !numeroOs || !resumo) {



                preview.classList.remove("active");



                tbody.innerHTML = "";



                atualizarMontagemAoVivoVenda(null);



                return;



            }







            tbody.innerHTML =



                '<tr>' +



                '<td>' + escapeHtml(numeroOs) + '</td>' +



                '<td>' + escapeHtml(textoOpcaoSelecionada(clienteSelect)) + '</td>' +



                '<td>' + escapeHtml(textoOpcaoSelecionada(produtoSelect)) + '</td>' +



                '<td>' + escapeHtml(resumo.quantidade) + '</td>' +



                '<td>' + (resumo.kgTotal > 0 ? resumo.kgTotal.toFixed(3).replace(".", ",") + " kg" : "-") + '</td>' +



                '<td>' + fmtPreco(resumo.preco) + resumo.unidadePreco + '</td>' +



                '<td>' + fmtPreco(resumo.total) + '</td>' +



                '<td><span class="preview-status">Preenchendo agora</span></td>' +



                '</tr>';



            preview.classList.add("active");



            atualizarMontagemAoVivoVenda({



                numeroOs: numeroOs,



                cliente: textoOpcaoSelecionada(clienteSelect),



                produto: textoOpcaoSelecionada(produtoSelect),



                tipo: valorCampoVenda("tipo") || "kg",



                pedido: resumo.quantidade,



                kgTotal: resumo.kgTotal,



                preco: resumo.preco,



                total: resumo.total



            });



        }







        function atualizarMontagemAoVivoVenda(item) {



            const bloco = document.getElementById('vendaMontagemAoVivo');



            const body = document.getElementById('vendaMontagemAoVivoBody');



            document.querySelectorAll('.live-montagem-inline-venda').forEach((row) => row.remove());



            document.querySelectorAll('[data-live-original-venda]').forEach((cell) => {



                cell.textContent = cell.getAttribute('data-live-original-venda');



                cell.removeAttribute('data-live-original-venda');



            });



            if (bloco) bloco.classList.remove('active');



            if (body) body.innerHTML = '';







            if (!item) {



                const vazio = document.getElementById('vendaMontagemVazia');



                if (vazio) vazio.style.display = '';



                return;



            }







            const osKey = item.numeroOs || '__sem_os';



            const rowsDaOs = Array.from(document.querySelectorAll('.montagem-os-item-row')).filter((row) => row.dataset.os === osKey);



            const botaoOs = Array.from(document.querySelectorAll('.btn-toggle-os[data-montagem-os]')).find((btn) => btn.dataset.montagemOs === osKey);



            const htmlLinha =



                '<div class="montagem-row montagem-os-item-row live-montagem-row">' +
                '<span class="col-toggle"></span>' +
                '<span class="col-os"></span>' +
                '<span class="col-cliente">' + escapeHtml(item.produto) + '</span>' +
                '<span class="col-itens">' + escapeHtml(item.pedido) + '</span>' +
                '<span class="col-total">' + fmtPreco(item.total) + '</span>' +
                '<span class="col-acoes"><span class="preview-status">Agora</span></span>' +
                '</div>';







            if (rowsDaOs.length > 0) {



                rowsDaOs.forEach((row) => row.classList.remove('hidden'));



                if (botaoOs) botaoOs.textContent = '▲';



                const header = rowsDaOs[0].previousElementSibling;



                const itemCell = header?.classList.contains('os-group-header') ?header.children[3] : null;



                if (itemCell && !itemCell.hasAttribute('data-live-original-venda')) {



                    itemCell.setAttribute('data-live-original-venda', itemCell.textContent.trim());



                    itemCell.textContent = itemCell.textContent.trim() + ' + 1 em preenchimento';



                }



                rowsDaOs[rowsDaOs.length - 1].insertAdjacentHTML('afterend', htmlLinha);



                const novaLinha = rowsDaOs[rowsDaOs.length - 1].nextElementSibling;



                if (novaLinha) {



                    novaLinha.classList.add('live-montagem-inline-venda');



                    novaLinha.dataset.os = osKey;



                }



                return;



            }







            if (bloco && body) {



                body.innerHTML =



                    '<div class="montagem-row os-group-header live-montagem-row live-montagem-inline-venda">' +
                    '<span class="col-toggle"><button type="button" class="btn-toggle-os">▲</button></span>' +
                    '<span class="col-os">' + escapeHtml(item.numeroOs) + '</span>' +
                    '<span class="col-cliente">' + escapeHtml(item.cliente) + '</span>' +
                    '<span class="col-itens"><span class="preview-status">…</span></span>' +
                    '<span class="col-total">' + fmtPreco(item.total) + '</span>' +
                    '<span class="col-acoes"><span class="preview-status">Rascunho</span></span>' +
                    '</div>' + htmlLinha;



                bloco.classList.add('active');



                const vazio = document.getElementById('vendaMontagemVazia');



                if (vazio) vazio.style.display = 'none';



            }



        }







        // ── Dados do cliente ─────────────────────────────────────────────────



        function atualizarClienteInfo(selectId, prefixo) {



            const select = document.getElementById(selectId);



            if (!select) return;



            const opt = select.options[select.selectedIndex];



            document.getElementById(prefixo + "_documento").value = opt?.getAttribute("data-documento") || "";



            document.getElementById(prefixo + "_telefone").value  = opt?.getAttribute("data-telefone")  || "";



            document.getElementById(prefixo + "_endereco").value  = opt?.getAttribute("data-endereco")  || "";



        }







        // ── Campos de tipo (bandeja / caixa / kg) ────────────────────────────



        function atualizarCamposTipo() {



            const tipo      = document.getElementById("tipo");



            const pesoLabel = document.getElementById("pesoLabel");



            const pesoInput = document.getElementById("peso_unitario");



            const ajuda     = document.getElementById("pesoAjuda");

            const grupoPeso = document.getElementById("grupoPesoUnitario");



            if (!tipo || !pesoLabel || !pesoInput || !ajuda) return;

            atualizarTextosProdutosSelect(
                document.getElementById('produto'),
                document.getElementById('tipo_comercial')?.value || '',
                tipo.value
            );







            if (tipo.value === "unidade") {
                if (grupoPeso) grupoPeso.classList.add("form-group-oculto");
                pesoInput.value = "";
                pesoInput.required = false;
                pesoInput.disabled = true;
                ajuda.textContent = "";
                return;
            }

            if (grupoPeso) grupoPeso.classList.remove("form-group-oculto");

            switch (tipo.value) {



                case "bandeja":



                    pesoLabel.textContent  = "Gramagem por Bandeja (g)";



                    pesoInput.placeholder  = "Ex.: 500";



                    pesoInput.step         = "0.01";



                    pesoInput.required     = true;



                    pesoInput.disabled     = false;



                    ajuda.textContent      = "Quantidade em kg = quantidade x gramagem / 1000";



                    aplicarGramagemProdutoSelecionado(false);



                    break;



                case "caixa":



                    pesoLabel.textContent  = "Kg por Caixa";



                    pesoInput.placeholder  = "Ex.: 20";



                    pesoInput.step         = "0.01";



                    pesoInput.required     = true;



                    pesoInput.disabled     = false;



                    ajuda.textContent      = "Quantidade em kg = quantidade x kg por caixa";



                    break;



                case "kg":



                    pesoLabel.textContent  = "Peso por Unidade";



                    pesoInput.placeholder  = "Não precisa preencher";



                    pesoInput.value        = "";



                    pesoInput.required     = false;



                    pesoInput.disabled     = true;



                    ajuda.textContent      = "Para vendas em kg, a quantidade informada já será usada como kg.";



                    break;



                default:



                    pesoLabel.textContent  = "Kg Caixa / Gramagem";



                    pesoInput.placeholder  = "";



                    pesoInput.required     = false;



                    pesoInput.disabled     = false;



                    ajuda.textContent      = "";



            }



        }







        // Ao trocar manualmente a unidade no modal de edicao, a escolha do usuario
        // deve prevalecer. O backend (editar_venda.php) reforca o "tipo" a partir do
        // "tipo_comercial" (embalado => bandeja; atacado => kg/caixa/unidade). Se a
        // nova unidade for incompativel com o tipo_comercial original, limpamos o
        // tipo_comercial (passa a venda manual) para que o tipo escolhido seja salvo.
        function onTipoEdicaoChange() {
            const tipoSel = document.getElementById('edit_tipo');
            const tcEl    = document.getElementById('edit_tipo_comercial');
            if (tipoSel && tcEl) {
                const tipo = tipoSel.value;
                const tc   = tcEl.value || '';
                const bandejaTipos = ['embalado', 'oba_embalado'];
                const atacadoTipos = ['atacado', 'atacado_convencional', 'shopper'];
                const incompat =
                    (bandejaTipos.indexOf(tc) !== -1 && tipo !== 'bandeja') ||
                    (atacadoTipos.indexOf(tc) !== -1 && ['kg', 'caixa', 'unidade'].indexOf(tipo) === -1);
                if (incompat) {
                    tcEl.value = '';
                    const flag = document.getElementById('edit_preco_editado_manualmente');
                    if (flag) flag.value = '1';
                }
            }
            atualizarCamposTipoEdicao();
            aplicarGramagemProdutoSelecionado(false, 'edit');
        }

        function atualizarCamposTipoEdicao() {



            const tipo      = document.getElementById("edit_tipo");



            const pesoLabel = document.getElementById("editPesoLabel");



            const pesoInput = document.getElementById("edit_peso_unitario");



            const ajuda     = document.getElementById("editPesoAjuda");



            const prazoGrupo = document.getElementById("editGrupoPrazoAtacado");



            const prazoSel   = document.getElementById("edit_prazo_escolhido");

            const grupoPeso  = document.getElementById("editGrupoPesoUnitario");



            if (!tipo || !pesoLabel || !pesoInput || !ajuda) return;

            atualizarTextosProdutosSelect(document.getElementById("edit_produto_id"), document.getElementById("edit_tipo_comercial")?.value || '', tipo.value);

            if (tipo.value === "unidade") {
                if (prazoGrupo) prazoGrupo.style.display = "none";
                if (prazoSel) { prazoSel.required = false; prazoSel.value = ""; }
                if (grupoPeso) grupoPeso.style.display = "none";
                pesoInput.value = "";
                pesoInput.required = false;
                pesoInput.disabled = true;
                ajuda.textContent = "";
                return;
            }

            if (grupoPeso) grupoPeso.style.display = "";

            switch (tipo.value) {



                case "bandeja":



                    if (prazoGrupo) prazoGrupo.style.display = "none";



                    if (prazoSel) { prazoSel.required = false; prazoSel.value = ""; }



                    pesoLabel.textContent = "Gramagem por Bandeja (g)";



                    pesoInput.placeholder = "Ex.: 500";



                    pesoInput.step        = "0.01";



                    pesoInput.required    = true;



                    pesoInput.disabled    = false;



                    ajuda.textContent     = "Quantidade em kg = quantidade x gramagem / 1000";



                    aplicarGramagemProdutoSelecionado(false, 'edit');



                    break;



                case "caixa":



                    if (["atacado", "atacado_convencional"].includes(document.getElementById("edit_tipo_comercial")?.value || "")) {



                        if (prazoGrupo) prazoGrupo.style.display = "";



                        if (prazoSel) prazoSel.required = true;



                    } else {



                        if (prazoGrupo) prazoGrupo.style.display = "none";



                        if (prazoSel) { prazoSel.required = false; prazoSel.value = ""; }



                    }



                    pesoLabel.textContent = "Kg por Caixa";



                    pesoInput.placeholder = "Ex.: 20";



                    pesoInput.step        = "0.01";



                    pesoInput.required    = true;



                    pesoInput.disabled    = false;



                    ajuda.textContent     = "Quantidade em kg = quantidade x kg por caixa";



                    break;



                case "kg":



                    // Mostra prazo para atacado/atacado_convencional

                    if (["atacado", "atacado_convencional"].includes(document.getElementById("edit_tipo_comercial")?.value || "")) {

                        if (prazoGrupo) prazoGrupo.style.display = "";

                        if (prazoSel) prazoSel.required = true;

                    } else {

                        if (prazoGrupo) prazoGrupo.style.display = "none";

                        if (prazoSel) { prazoSel.required = false; prazoSel.value = ""; }

                    }



                    pesoLabel.textContent = "Peso por Unidade";



                    pesoInput.placeholder = "Não precisa preencher";



                    pesoInput.value       = "";



                    pesoInput.required    = false;



                    pesoInput.disabled    = true;



                    ajuda.textContent     = "Para vendas em kg, a quantidade informada já será usada como kg.";



                    break;



            }



        }







        // ── Modal de edição ──────────────────────────────────────────────────



        function abrirModalEdicaoVenda(item) {



            // Reseta flag de edicao manual ao abrir o modal



            document.getElementById("edit_preco_editado_manualmente").value = "0";



            document.getElementById("edit_foto").value = "";

            // Guarda a OS deste item para reabrir o dropdown apos o reload (pos-save).
            if (item && item.numero_os !== undefined) {
                marcarVendasOsAberta(item.grupo || 'pendente', item.numero_os);
            }







            document.getElementById("edit_id").value          = item.id          || "";



            document.getElementById("edit_data_venda").value  = item.previsao_entrega || item.data_venda || "";



            document.getElementById("edit_produto_id").value  = item.produto_id  || "";



            document.getElementById("edit_cliente_id").value  = item.cliente_id  || "";



            document.getElementById("edit_tipo").value        = item.tipo        || "kg";



            document.getElementById("edit_tipo_comercial").value = item.tipo_comercial || "";



            document.getElementById("edit_prazo_escolhido").value = item.prazo_escolhido || "";



            document.getElementById("edit_pedido").value      = item.pedido ?? "";



            document.getElementById("edit_preco").value       = item.preco ?? "";



            document.getElementById("edit_frete").value       = item.frete ?? "0.00";



            document.getElementById("edit_peso_unitario").value = item.peso_unitario ?? "";



            document.getElementById("edit_redirect_to").value = redirectEdicaoVenda || "../vendas.php";







            atualizarCamposTipoEdicao();



            atualizarClienteInfo("edit_cliente_id", "edit_cliente_info");



            buscarPrecoEdicao();



            document.getElementById("modalEditarVenda").style.display = "block";



        }







        function fecharModalEdicaoVenda() {



            document.getElementById("modalEditarVenda").style.display = "none";



        }







        function abrirModalEnviarOs() {



            document.getElementById('modalEnviarOs').classList.add('active');



            document.getElementById('inputNumeroOs').value = '';



            document.getElementById('inputNumeroOs').focus();



        }







        function fecharModalEnviarOs() {



            document.getElementById('modalEnviarOs').classList.remove('active');



        }







        function confirmarEnvioOs() {



            const val = document.getElementById('inputNumeroOs').value.trim();



            if (!val) { alert('Informe o número da OS.'); return; }



            document.getElementById('hiddenNumeroOs').value = val;



            document.getElementById('formEnviarOs').submit();



        }







        const autoDownloadPedidoVendaUrl = <?= json_encode($autoDownloadPedidoVendaUrl) ?>;

        // Itens já salvos (pendentes) para esta OS — usado para liberar passo Produto quando há pelo menos 1 item
        const osItensExistentes = <?= (int) $itensOsAtual ?>;

        const etapaInicialVenda = <?= json_encode($etapaInicialVenda) ?>;
        const vendaAdicionarPendente = <?= json_encode(($_GET['adicionar_pendente'] ?? '') === '1') ?>;



        const vendaAbrirEdicao = <?= json_encode($vendaAbrirEdicao, JSON_UNESCAPED_UNICODE) ?>;



        const redirectEdicaoVenda = <?= json_encode($redirectEdicaoVenda) ?>;



        let formPreviewPedidoVenda = null;



        window._VENDA_UID = <?= json_encode((string)($_SESSION['usuario_id'] ?? '')) ?>;
        const _VENDA_DRAFT_KEY = 'agrocolitti_venda_draft_' + window._VENDA_UID;
        const _VENDA_PRAZO_KEY = 'acad_prazo_pag_' + window._VENDA_UID;

        let vendaWizardStep = 0;



        const vendaWizardSteps = [



            { label: 'Cliente', selectors: ['[name="previsao_entrega"]', '[name="tipo_comercial"]', '[name="cliente_id"]', '#cliente_info_documento', '#cliente_info_telefone', '#cliente_info_endereco', '[name="vendedor"]'] },



            { label: 'Produto', selectors: ['[name="produto_id"]', '[name="tipo"]', '#grupoNumCaixas', '#grupoBandejasPorCaixa', '[name="peso_unitario"]', '[name="pedido"]', '[name="preco"]', '#resumoVenda'] },



            { label: 'Finalizar', selectors: ['[name="numero_os"]', '[name="entreposto"]', '[name="forma_pagamento"]', '[name="prazo_pagamento"]', '[name="consideracoes"]', '[name="foto"]', '#vendaAcoesFinais'] }



        ];







        function iniciarDownloadPedidoVenda(url) {



            if (!url) return;



            const iframe = document.createElement('iframe');



            iframe.style.display = 'none';



            iframe.src = url;



            document.body.appendChild(iframe);



            setTimeout(() => iframe.remove(), 60000);







            const cleanUrl = new URL(window.location.href);



            cleanUrl.searchParams.delete('download_numero_os');



            cleanUrl.searchParams.delete('download_venda_id');



            window.history.replaceState({}, '', cleanUrl.toString());



        }







        function abrirPreviewConfirmacaoVenda(form, previewUrl, titulo) {



            formPreviewPedidoVenda = form;



            document.getElementById('previewPedidoVendaTitulo').textContent = titulo || 'Conferir Pedido';



            document.getElementById('previewPedidoVendaFrame').src = previewUrl;



            document.getElementById('modalPreviewPedidoVenda').style.display = 'block';



            return false;



        }







        function fecharPreviewPedidoVenda() {



            document.getElementById('modalPreviewPedidoVenda').style.display = 'none';



            document.getElementById('previewPedidoVendaFrame').src = 'about:blank';



            formPreviewPedidoVenda = null;



        }







        function confirmarPreviewPedidoVenda() {



            if (!formPreviewPedidoVenda) return;



            formPreviewPedidoVenda.submit();



        }







        function grupoWizardVenda(selector) {



            const el = document.querySelector(selector);



            return el ? el.closest('.form-group, .form-attachments') : null;



        }







        function camposEtapaVenda(index) {



            return vendaWizardSteps[index].selectors



                .map(grupoWizardVenda)



                .filter((el, pos, arr) => el && arr.indexOf(el) === pos);



        }







        function validarEtapaVenda() {

            const form = document.querySelector('form[action="actions/salvar_venda.php"]');

            // Passo Produto (índice 1): se já existem itens na OS e o usuário não selecionou
            // nenhum produto novo, permite avançar sem preencher os campos de produto.
            if (vendaWizardStep === 1 && osItensExistentes > 0) {
                const produtoSel = document.getElementById('produto');
                if (!produtoSel || !produtoSel.value) {
                    // Nenhum produto novo em preenchimento → pode ir para Finalizar
                    return true;
                }
                // Usuário selecionou um produto → valida normalmente abaixo
            }

            for (const group of camposEtapaVenda(vendaWizardStep)) {

                for (const field of group.querySelectorAll('input, select, textarea')) {

                    if (field.closest('.form-group-oculto')) continue;

                    if (vendaWizardStep === 1 && field.name === 'preco') {
                        const tipoComercial = document.getElementById('tipo_comercial')?.value || '';
                        const prazoTabela = document.getElementById('prazo_escolhido')?.value || '';
                        if (['atacado', 'atacado_convencional', 'shopper'].includes(tipoComercial) && !prazoTabela) continue;
                    }

                    if (!field.checkValidity()) {

                        field.reportValidity();

                        return false;

                    }

                }

            }

            return true;

        }







        function renderWizardVenda() {



            const progress = document.getElementById('wizardVendaProgress');



            progress.innerHTML = vendaWizardSteps.map((step, index) =>



                '<div class="wizard-step-indicator ' + (index === vendaWizardStep ? 'active' : '') + '">' + (index + 1) + '. ' + step.label + '</div>'



            ).join('');



            const allGroups = new Set(vendaWizardSteps.flatMap((step) => step.selectors.map(grupoWizardVenda).filter(Boolean)));



            allGroups.forEach((group) => group.classList.add('wizard-hidden'));



            camposEtapaVenda(vendaWizardStep).forEach((group) => group.classList.remove('wizard-hidden'));



            document.getElementById('wizardVendaPrev').style.visibility = vendaWizardStep === 0 ? 'hidden' : 'visible';



            document.getElementById('wizardVendaNext').classList.toggle('wizard-hidden', vendaWizardStep === vendaWizardSteps.length - 1);



            var _af = document.getElementById('vendaAcoesFinais');
            var _isFinal = vendaWizardStep === vendaWizardSteps.length - 1;
            _af.style.display = _isFinal ? 'flex' : 'none';
            _af.classList.toggle('wizard-hidden', !_isFinal);

            var elPrecoSugestao = document.getElementById('preco_sugestao');
            if (elPrecoSugestao) {
                elPrecoSugestao.style.display = (vendaWizardStep === 1) ? '' : 'none';
            }



        }







        function inicializarWizardVenda() {



            const etapaIndex = { cliente: 0, produto: 1, finalizar: 2 };



            vendaWizardStep = etapaIndex[etapaInicialVenda] || 0;



            document.getElementById('wizardVendaPrev').addEventListener('click', function () {



                vendaWizardStep = Math.max(0, vendaWizardStep - 1);



                renderWizardVenda();



            });



            document.getElementById('wizardVendaNext').addEventListener('click', function () {



                if (!validarEtapaVenda()) return;



                vendaWizardStep = Math.min(vendaWizardSteps.length - 1, vendaWizardStep + 1);



                renderWizardVenda();



            });



            renderWizardVenda();

            if (vendaAdicionarPendente) {
                requestAnimationFrame(function () {
                    const formCard = document.querySelector('.form-card');
                    const produto = document.getElementById('produto');
                    if (formCard) formCard.scrollIntoView({ block: 'start', behavior: 'smooth' });
                    if (produto) setTimeout(function () { produto.focus(); }, 250);
                });
            }



        }







        function etapaAtualVendaSlug() {



            return ['cliente', 'produto', 'finalizar'][vendaWizardStep] || 'cliente';



        }







        function formPrincipalVenda() {



            return document.querySelector('form[action="actions/salvar_venda.php"]');



        }







        function prepararAdicionarItemVenda(botao) {
            // Botao "+ adicionar outro produto" (etapa Finalizar). Usa formnovalidate para nao travar
            // em campos required escondidos de outras etapas (bug silencioso no mobile). O servidor valida.
            const form = botao.form;
            document.getElementById('acao_final_venda').value = 'adicionar_item';
            const produto = form ? form.querySelector('[name="produto_id"]') : null;
            if (!produto || produto.value === '') {
                // Sem produto preenchido (ex.: retomando uma OS na etapa Finalizar):
                // volta para a etapa Produto em vez de bloquear, permitindo adicionar um item.
                vendaWizardStep = 1;
                renderWizardVenda();
                var _selProd = document.getElementById('produto');
                if (_selProd) _selProd.focus();
                return false;
            }
            if (!validarPrecoVendaFinal()) return false;
            return true;
        }

        function prepararEnvioVenda(botao) {
            const form = botao.form;
            const produto = form ? form.querySelector('[name="produto_id"]') : null;
            const temNovoItem = produto && produto.value !== '';

            const entreposto = form ? form.querySelector('[name="entreposto"]') : null;
            const prazoPagamento = form ? form.querySelector('[name="prazo_pagamento"]') : null;
            const formaPagamento = form ? form.querySelector('[name="forma_pagamento"]') : null;

            if (entreposto) entreposto.setCustomValidity('');
            if (prazoPagamento) prazoPagamento.setCustomValidity('');
            if (formaPagamento) formaPagamento.setCustomValidity('');

            if (!entreposto || entreposto.value.trim() === '') {
                if (entreposto) {
                    entreposto.setCustomValidity('Selecione o entreposto.');
                    entreposto.reportValidity();
                }
                return false;
            }

            if (!prazoPagamento || prazoPagamento.value.trim() === '') {
                if (prazoPagamento) {
                    prazoPagamento.setCustomValidity('Selecione o prazo de pagamento.');
                    prazoPagamento.reportValidity();
                }
                return false;
            }

            if (!formaPagamento || formaPagamento.value.trim() === '') {
                if (formaPagamento) {
                    formaPagamento.setCustomValidity('Selecione a forma de pagamento.');
                    formaPagamento.reportValidity();
                }
                return false;
            }

            if (!validarPrecoVendaFinal()) return false;

            if (vendaAdicionarPendente) {
                document.getElementById('acao_final_venda').value = 'adicionar_item';
                if (form) {
                    form.action = 'actions/salvar_venda.php';
                    form.noValidate = true;
                }
                return true;
            }

            document.getElementById('acao_final_venda').value = 'enviar_os';
            if (form) {
                // O wizard esconde campos required de outras etapas; a validacao final fica no backend.
                form.noValidate = true;
            }

            if (form && osItensExistentes > 0 && !temNovoItem) {
                // Uma OS em montagem pode ser enviada sem incluir outro produto.
                form.action = 'actions/enviar_os.php';
                localStorage.removeItem(_VENDA_DRAFT_KEY);
                localStorage.removeItem(_VENDA_PRAZO_KEY);
                form.submit();
                return false;
            }

            return true;
        }

        function valorCampoVenda(nome) {



            const form = formPrincipalVenda();



            const campo = form ? form.querySelector('[name="' + nome + '"]') : null;



            return campo ? campo.value : '';



        }







        function salvarRascunhoVendaLocal() {



            const numeroOs = valorCampoVenda('numero_os').trim();



            if (!numeroOs) return;



            const dados = {



                numero_os: numeroOs,



                usuario_id: String(window._VENDA_UID || ''),



                tipo_fluxo: 'venda',



                cliente_id: valorCampoVenda('cliente_id'),



                vendedor: valorCampoVenda('vendedor'),



                tipo_comercial: valorCampoVenda('tipo_comercial'),



                tipo: valorCampoVenda('tipo'),



                prazo_escolhido: valorCampoVenda('prazo_escolhido'),



                previsao_entrega: valorCampoVenda('previsao_entrega'),

                entreposto: valorCampoVenda('entreposto'),



                forma_pagamento: valorCampoVenda('forma_pagamento'),

                prazo_pagamento: valorCampoVenda('prazo_pagamento'),

                consideracoes: valorCampoVenda('consideracoes'),



                etapa: etapaAtualVendaSlug(),



                atualizado_em: new Date().toISOString()



            };



            dados.url = montarUrlRascunhoVenda(dados);



            localStorage.setItem(_VENDA_DRAFT_KEY, JSON.stringify(dados));



            mostrarBannerRascunhoVenda();



        }







        function montarUrlRascunhoVenda(dados) {



            const params = new URLSearchParams();



            params.set('numero_os', dados.numero_os || '');



            ['cliente_id', 'vendedor', 'tipo_comercial', 'tipo', 'prazo_escolhido', 'previsao_entrega', 'entreposto', 'forma_pagamento', 'prazo_pagamento', 'consideracoes'].forEach(function (campo) {



                if (dados[campo]) params.set(campo, dados[campo]);



            });



            params.set('etapa', dados.etapa || 'produto');



            return 'vendas.php?' + params.toString();



        }







        function mostrarBannerRascunhoVenda() {



            const banner = document.getElementById('vendaDraftBanner');



            if (!banner) return;



            let dados = null;



            try { dados = JSON.parse(localStorage.getItem(_VENDA_DRAFT_KEY) || 'null'); } catch (e) {}



            const usuarioAtual = String(window._VENDA_UID || '');



            const usuarioRascunho = String(dados?.usuario_id || '');



            if (!dados || !dados.numero_os || !usuarioRascunho || usuarioRascunho !== usuarioAtual) {



                banner.style.display = 'none';



                return;



            }



            banner.querySelector('strong').textContent = 'Continuar editando OS ' + dados.numero_os;



            const linkRetomar = banner.querySelector('a');
            linkRetomar.href = montarUrlRascunhoVenda(dados);
            linkRetomar.onclick = function () {
                sessionStorage.setItem('acad_retomou_os', dados.numero_os || '');
            };



            banner.style.display = 'flex';



        }







        function cancelarFluxoNovaVenda() {



            if (!confirm('Cancelar este fluxo e começar uma nova venda em outra OS? Itens já anexados não serão excluídos.')) {



                return;



            }



            localStorage.removeItem(_VENDA_DRAFT_KEY);
            localStorage.removeItem(_VENDA_PRAZO_KEY);



            window.location.href = 'vendas.php';



        }







        function inicializarRascunhoVenda() {



            const form = formPrincipalVenda();



            if (!form) return;



            form.addEventListener('change', salvarRascunhoVendaLocal);



            form.addEventListener('input', salvarRascunhoVendaLocal);



            form.addEventListener('change', atualizarPreviewVendaAoVivo);



            form.addEventListener('input', atualizarPreviewVendaAoVivo);



            form.addEventListener('submit', function () {



                const acao = document.getElementById('acao_final_venda').value;



                if (acao === 'enviar_os') {



                    localStorage.removeItem(_VENDA_DRAFT_KEY);
                    localStorage.removeItem(_VENDA_PRAZO_KEY);



                    return;



                }



                salvarRascunhoVendaLocal();



            });



            mostrarBannerRascunhoVenda();

            // Persistir prazo_pagamento (Step 3) entre reloads
            // Se o campo já tem valor pré-preenchido via GET (continuação de OS), ele tem prioridade
            const campoPrazoPag = document.getElementById('prazo_pagamento');
            if (campoPrazoPag) {
                if (!campoPrazoPag.value) {
                    const valorSalvo = localStorage.getItem(_VENDA_PRAZO_KEY);
                    if (valorSalvo) campoPrazoPag.value = valorSalvo;
                }
                campoPrazoPag.addEventListener('change', function () {
                    localStorage.setItem(_VENDA_PRAZO_KEY, this.value);
                    sincronizarPrazoPagamentoComPreco(true);
                });
                sincronizarPrazoPagamentoComPreco(false);
            }

        }







        // ── Persistencia da OS aberta nos resumos (montagem / pendentes) ─────────
        // Mantem o dropdown da OS aberto e rola ate ele depois de salvar uma
        // edicao (a pagina recarrega), igual ao Historico de Vendas.
        const VENDAS_OS_ABERTA_KEY = 'agrocolitti_vendas_os_aberta';

        function marcarVendasOsAberta(grupo, osKey) {
            if (osKey === undefined || osKey === null || osKey === '') return;
            try { sessionStorage.setItem(VENDAS_OS_ABERTA_KEY, grupo + '|' + osKey); } catch (e) {}
        }

        function limparVendasOsAberta() {
            try { sessionStorage.removeItem(VENDAS_OS_ABERTA_KEY); } catch (e) {}
        }

        function reabrirVendasOsSalva() {
            let salvo = null;
            try { salvo = sessionStorage.getItem(VENDAS_OS_ABERTA_KEY); } catch (e) {}
            if (!salvo) return;
            const sep   = salvo.indexOf('|');
            const grupo = sep >= 0 ? salvo.slice(0, sep) : 'pendente';
            const osKey = sep >= 0 ? salvo.slice(sep + 1) : salvo;
            const rowClass = grupo === 'montagem' ? '.montagem-os-item-row' : '.os-item-row';
            const btnAttr  = grupo === 'montagem' ? 'data-montagem-os' : 'data-os';
            const rows = Array.from(document.querySelectorAll(rowClass + '[data-os]'))
                .filter(r => r.dataset.os === osKey);
            if (rows.length === 0) { limparVendasOsAberta(); return; }
            rows.forEach(r => r.classList.remove('hidden'));
            const btn = Array.from(document.querySelectorAll('.btn-toggle-os[' + btnAttr + ']'))
                .find(b => b.getAttribute(btnAttr) === osKey);
            if (btn) btn.textContent = '▲';
            const header = btn ? btn.closest('.os-group-header') : rows[0];
            if (header) requestAnimationFrame(() => header.scrollIntoView({block: 'center'}));
        }

        document.addEventListener('DOMContentLoaded', reabrirVendasOsSalva);

        function irParaAdicionarProdutoPendente(url) {
            if (!url) return false;
            window.location.href = url;
            return false;
        }

        function toggleOsItens(osKey) {



            const rows = document.querySelectorAll('.os-item-row[data-os="' + osKey + '"]');



            const btn  = document.querySelector('.btn-toggle-os[data-os="' + osKey + '"]');



            const hidden = rows[0]?.classList.contains('hidden');



            rows.forEach(r => r.classList.toggle('hidden', !hidden));



            if (btn) btn.textContent = hidden ? '▲' : '▼';

            if (hidden) marcarVendasOsAberta('pendente', osKey); else limparVendasOsAberta();

        }







        function toggleMontagemOsItens(osKey) {



            const rows = document.querySelectorAll('.montagem-os-item-row[data-os="' + osKey + '"]');



            const btn  = document.querySelector('.btn-toggle-os[data-montagem-os="' + osKey + '"]');



            const hidden = rows[0]?.classList.contains('hidden');



            rows.forEach(r => r.classList.toggle('hidden', !hidden));



            if (btn) btn.textContent = hidden ? '▲' : '▼';

            if (hidden) marcarVendasOsAberta('montagem', osKey); else limparVendasOsAberta();

        }







        function abrirModalConfirmarVendaOs(numeroOs, itens) {



            const modal = document.getElementById('modalConfirmarVendaAbate');



            const form = document.getElementById('formConfirmarVendaAbate');



            const lista = document.getElementById('confirmarVendaItens');



            document.getElementById('confirmarVendaTitulo').textContent = 'Confirmar OS ' + numeroOs;



            form.action = 'actions/confirmar_os.php';



            document.getElementById('confirmarVendaNumeroOs').value = numeroOs;



            document.getElementById('confirmarVendaId').value = '';



            lista.innerHTML = '';



            itens.forEach(function (item) {



                lista.appendChild(criarLinhaConfirmacaoVenda(item));



            });



            modal.style.display = 'block';



        }







        function abrirModalConfirmarVendaItem(item) {



            const modal = document.getElementById('modalConfirmarVendaAbate');



            const form = document.getElementById('formConfirmarVendaAbate');



            const lista = document.getElementById('confirmarVendaItens');



            document.getElementById('confirmarVendaTitulo').textContent = 'Confirmar venda';



            form.action = 'actions/confirmar_venda.php';



            document.getElementById('confirmarVendaNumeroOs').value = '';



            document.getElementById('confirmarVendaId').value = item.id || '';



            lista.innerHTML = '';



            lista.appendChild(criarLinhaConfirmacaoVenda(item));



            modal.style.display = 'block';



        }







        function criarLinhaConfirmacaoVenda(item) {



            const id = String(item.id);



            const qtd = Number(item.quantidade || 0);

            const unidadeItem = item.tipo === 'bandeja' ? ' bandeja(s)' : (item.tipo === 'caixa' ? ' caixa(s)' : (item.tipo === 'unidade' ? ' un.' : ' kg'));



            const linha = document.createElement('div');



            linha.className = 'recebimento-os-item';



                        linha.innerHTML =
                '<div class="recebimento-os-cab">' +
                '<div class="recebimento-os-produto">' +
                '<strong>' + escapeHtml(item.produto || '-') + '</strong>' +
                '<span>Pedido: ' + qtd.toFixed(2).replace('.', ',') + unidadeItem + '</span>' +
                '</div>' +
                '<label class="recebimento-os-excluir" title="Marque se o produto não saiu">' +
                '<input type="checkbox" name="recebimento[' + id + '][excluir]" value="1"> Não saiu' +
                '</label>' +
                '</div>' +
                '<div class="recebimento-os-campos">' +
                '<div class="recebimento-os-campo">' +
                '<label>Quantidade entregue</label>' +
                '<input type="number" step="0.01" min="0" name="recebimento[' + id + '][quantidade_entregue]" value="' + qtd.toFixed(2) + '" required placeholder="0">' +
                '<small>Final que saiu em ' + unidadeItem.trim() + '.</small>' +
                '</div>' +
                '<div class="recebimento-os-campo">' +
                '<label>Abate / descarte</label>' +
                '<input type="number" step="0.01" min="0" name="recebimento[' + id + '][quantidade_abatida]" value="0" placeholder="0">' +
                '<small>Produto ruim/devolvido em ' + unidadeItem.trim() + '.</small>' +
                '</div>' +
                '</div>' +
                '<div class="recebimento-os-motivo">' +
                '<label>Motivo do abate</label>' +
                '<textarea name="recebimento[' + id + '][motivo]" rows="2" placeholder="Motivo do abate..."></textarea>' +
                '</div>';



            linha.querySelectorAll('input').forEach(function (input) {



                input.addEventListener('input', function () { atualizarLinhaConfirmacaoVenda(linha); });



                input.addEventListener('change', function () { atualizarLinhaConfirmacaoVenda(linha); });



            });



            atualizarLinhaConfirmacaoVenda(linha);



            return linha;



        }







        function atualizarLinhaConfirmacaoVenda(linha) {



            const abateInput = linha.querySelector('[name$="[quantidade_abatida]"]');



            const entregueInput = linha.querySelector('[name$="[quantidade_entregue]"]');



            const excluirInput = linha.querySelector('[name$="[excluir]"]');



            const motivo = linha.querySelector('textarea');



            const abatida = parseFloat(abateInput.value || '0') || 0;



            const excluir = !!(excluirInput && excluirInput.checked);



            linha.classList.toggle('modo-abate', abatida > 0 && !excluir);

            linha.classList.toggle('modo-excluir', excluir);

            motivo.required = abatida > 0 && !excluir;



            abateInput.disabled = excluir;



            entregueInput.disabled = excluir;



        }







        function fecharModalConfirmarVendaAbate() {

            document.getElementById('modalConfirmarVendaAbate').style.display = 'none';

            document.getElementById('caixaId').value = '';

            document.getElementById('caixaQuantidade').value = '0';

        }

        function abrirModalFoto(filename) {



            document.getElementById('modalFotoImg').src = '../storage/uploads/vendas/' + encodeURIComponent(filename);



            document.getElementById('modalFoto').classList.add('active');



        }







        function fecharModalFoto() {



            document.getElementById('modalFoto').classList.remove('active');



            document.getElementById('modalFotoImg').src = '';



        }







        document.addEventListener('DOMContentLoaded', function () {



            if (vendaAbrirEdicao) {



                abrirModalEdicaoVenda(vendaAbrirEdicao);



            }



            iniciarDownloadPedidoVenda(autoDownloadPedidoVendaUrl);



            inicializarWizardVenda();



            inicializarRascunhoVenda();

            // Feedback de retomada de fluxo
            const _osRetomada = sessionStorage.getItem('acad_retomou_os');
            if (_osRetomada !== null) {
                sessionStorage.removeItem('acad_retomou_os');
                const _banner = document.getElementById('vendaDraftBanner');
                if (_banner) _banner.style.display = 'none';
                const _toast = document.getElementById('vendaToastRetomada');
                if (_toast) {
                    _toast.textContent = _osRetomada
                        ? '✓ Você voltou para a OS ' + _osRetomada
                        : '✓ Você voltou para o fluxo';
                    requestAnimationFrame(function () {
                        _toast.classList.add('visivel');
                        setTimeout(function () {
                            _toast.classList.remove('visivel');
                        }, 3000);
                    });
                }
            }



            document.getElementById('modalFoto').addEventListener('click', function (e) {



                if (e.target === this) fecharModalFoto();



            });



        });







        function inicializarFormularioVenda() {



            atualizarClienteInfo('cliente_id', 'cliente_info');



            atualizarClienteInfo('edit_cliente_id', 'edit_cliente_info');







            const tipoComercial = document.getElementById('tipo_comercial')?.value || '';



            if (tipoComercial) {



                onTipoComercialChange();



            } else {



                atualizarCamposTipo();



            }



            recalcTotal();



            atualizarPreviewVendaAoVivo();



        }



    </script>



</head>







<body onload="inicializarFormularioVenda()">







    <?php renderAppHeader('..'); ?>







    <div class="container page-container app-shell">







        <?php if ($cicloAtivo): ?>



            <div class="msg-sucesso">Ciclo ativo: <?= htmlspecialchars(getNomeCiclo($cicloAtivo)) ?></div>



        <?php endif; ?>







        <?php if (isset($_GET['msg'])): ?>



            <?php if ($_GET['msg'] === 'salvo'): ?>



                <div class="msg-sucesso">Venda anexada ao registro com sucesso!</div>



            <?php elseif ($_GET['msg'] === 'rascunho_salvo'): ?>



                <div class="msg-sucesso">Rascunho salvo com sucesso na lista de montagem!</div>



            <?php elseif ($_GET['msg'] === 'confirmado'): ?>



                <div class="msg-sucesso">Venda confirmada com sucesso!</div>



            <?php elseif ($_GET['msg'] === 'enviado_lote'): ?>

                <div class="msg-sucesso"><?= (int) ($_GET['total'] ?? 0) ?> venda(s) enviada(s) para pendentes com sucesso!</div>

            <?php elseif ($_GET['msg'] === 'produto_adicionado'): ?>

                <div class="msg-sucesso">Produto adicionado com sucesso na OS pendente <?= htmlspecialchars((string) ($_GET['numero_os'] ?? '')) ?>.</div>

            <?php elseif ($_GET['msg'] === 'os_duplicada'): ?>

                <div class="msg-sucesso">Venda copiada para pendentes como OS <?= htmlspecialchars((string) ($_GET['numero_os'] ?? '')) ?>.</div>



            <?php elseif ($_GET['msg'] === 'foto'): ?>



                <div class="msg-sucesso">Foto enviada com sucesso!</div>



            <?php elseif ($_GET['msg'] === 'atualizado'): ?>



                <div class="msg-sucesso">Venda atualizada com sucesso!</div>



            <?php elseif ($_GET['msg'] === 'excluido'): ?>



                <div class="msg-sucesso">Venda excluída com sucesso!</div>



            <?php elseif ($_GET['msg'] === 'erro'): ?>



                <div class="msg-erro"><?= htmlspecialchars($_GET['detalhe'] ?? 'Erro ao processar.'); ?></div>



            <?php endif; ?>



        <?php endif; ?>



        <div id="vendaToastRetomada"></div>

        <div id="vendaDraftBanner" class="draft-resume-banner">



            <strong>Continuar editando OS</strong>



            <a href="#">Continuar fluxo</a>



        </div>







        <div class="vendas-side-layout">
        <div class="vendas-side-left">

        <div class="card form-card" id="formAdicionarProdutoPendente">



            <h2 class="form-title">Salvar Novo Registro</h2>
            <?php if (($_GET['adicionar_pendente'] ?? '') === '1'): ?>
                <div class="msg-sucesso">Adicionando produto na OS pendente <?= htmlspecialchars($numeroOsSelecionado, ENT_QUOTES, 'UTF-8') ?>.</div>
            <?php endif; ?>



            <div class="wizard-progress" id="wizardVendaProgress"></div>



            <form action="actions/salvar_venda.php" method="POST" class="form-venda" enctype="multipart/form-data">



                <input type="hidden" name="csrf_token"                value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">



	                <input type="hidden" name="preco_base_snapshot"       id="preco_base_snapshot"       value="">



	                <input type="hidden" name="desconto_pct_snapshot"     id="desconto_pct_snapshot"     value="0">



	                <input type="hidden" name="tipo_aplicacao_snapshot"   id="tipo_aplicacao_snapshot"   value="">



	                <input type="hidden" name="preco_editado_manualmente" id="preco_editado_manualmente" value="0">



	                <input type="hidden" name="acao_final" id="acao_final_venda" value="adicionar_item">

	                <input type="hidden" name="adicionar_pendente" value="<?= ($_GET['adicionar_pendente'] ?? '') === '1' ? '1' : '0' ?>">



                <div class="form-group">



	                    <label for="vendedor">Vendedor</label>



	                    <select name="vendedor" id="vendedor" required>



	                        <option value="">Selecione o vendedor</option>



	                        <?php foreach (array_keys(comissoesVendedoresLista()) as $opcaoVendedor): ?>



	                            <option value="<?= htmlspecialchars($opcaoVendedor, ENT_QUOTES, 'UTF-8') ?>" <?= $vendedorSelecionado === $opcaoVendedor ? 'selected' : '' ?>><?= htmlspecialchars($opcaoVendedor, ENT_QUOTES, 'UTF-8') ?></option>



	                        <?php endforeach; ?>



	                    </select></div>



<div class="form-group">



                    <label>Previsão de Entrega</label>



                    <input type="date" name="previsao_entrega" value="<?= htmlspecialchars($previsaoEntregaSelecionada, ENT_QUOTES, 'UTF-8') ?>" required>



                </div>

<div class="form-group">
                    <label>Tipo Comercial</label>
                    <select id="tipo_comercial" name="tipo_comercial" onchange="onTipoComercialChange()">
                        <option value="" <?= $tipoComercialSelecionado === '' ? 'selected' : '' ?>>Manual (sem tabela)</option>
                        <option value="embalado" <?= $tipoComercialSelecionado === 'embalado' ? 'selected' : '' ?>>Embalado (Bandeja)</option>
                        <option value="atacado" <?= $tipoComercialSelecionado === 'atacado' ? 'selected' : '' ?>>Atacado (Kg)</option>
                        <option value="atacado_convencional" <?= $tipoComercialSelecionado === 'atacado_convencional' ? 'selected' : '' ?>>Atacado Convencional (Kg)</option>
                        <option value="oba_embalado" <?= $tipoComercialSelecionado === 'oba_embalado' ? 'selected' : '' ?>>OBA Embalado (Bandeja)</option>
                        <option value="shopper" <?= $tipoComercialSelecionado === 'shopper' ? 'selected' : '' ?>>Tabela Shopper (Kg)</option>
                    </select>

                </div>



<div class="form-group">



                    <label>Cliente</label>



                    <select name="cliente_id" id="cliente_id" onchange="atualizarClienteInfo('cliente_id', 'cliente_info'); aplicarRegraClienteFartura(); buscarPreco();" required>



                        <option value="">Selecione</option>



                        <?php foreach ($clientes as $c): ?>
                            <?php $fiscalOk = cadastroFiscalPessoaCompleta($c); ?>



                            <option value="<?= $c['id'] ?>" <?= $clienteSelecionado === (int) $c['id'] ?'selected' : '' ?> <?= $fiscalOk ? '' : 'disabled' ?>



                                data-documento="<?= htmlspecialchars($c['documento'] ?? '', ENT_QUOTES, 'UTF-8') ?>"



                                data-telefone="<?= htmlspecialchars($c['telefone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"



                                data-endereco="<?= htmlspecialchars($c['endereco'] ?? '', ENT_QUOTES, 'UTF-8') ?>">



                                <?= htmlspecialchars($c['nome']) ?><?= $fiscalOk ? '' : ' - fiscal incompleto' ?>



                            </option>



                        <?php endforeach; ?>



                    </select>



                </div>



<div class="form-group">



                    <label>CPF/CNPJ</label>



                    <input type="text" id="cliente_info_documento" readonly>



                </div>



<div class="form-group">



                    <label>Telefone</label>



                    <input type="text" id="cliente_info_telefone" readonly>



                </div>



<div class="form-group">



                    <label>Endereço</label>



                    <input type="text" id="cliente_info_endereco" readonly>



                </div>



<div class="form-group">



                    <label>Produto</label>



                    <select name="produto_id" id="produto" onchange="mostrarEstoque(); aplicarGramagemProdutoSelecionado(true); buscarPreco();" required>



                        <option value="">Selecione</option>



                        <?php foreach ($produtos as $p): ?>
                            <?php $produtoIdOpcao = (int) $p['produto_id']; $produtoPrincipalOpcao = empty($p['produto_principal_id']); $fiscalOk = $produtoFiscalStatus[$produtoIdOpcao] ?? false; $codigoAntigo = $produtoCodigoAntigoStatus[$produtoIdOpcao] ?? false; $produtoNomeOriginal = (string) $p['produto']; $produtoNomeAgranel = removerGramagemInicialProduto($produtoNomeOriginal); $produtoChaveManual = chaveProdutoManualVenda($produtoNomeOriginal); $produtoSufixos = ($fiscalOk ? '' : ' - fiscal incompleto') . ($codigoAntigo ? ' - codigo antigo' : '') . ' (Estoque: ' . number_format($p['estoque'], 2, ',', '.') . ' kg)'; $produtoTextoOriginal = $produtoNomeOriginal . $produtoSufixos; $produtoTextoAgranel = $produtoNomeAgranel . $produtoSufixos; $produtoEhAgranel = !in_array($tipoSelecionado, ['bandeja', 'caixa', 'unidade'], true) && in_array($tipoComercialSelecionado, ['', 'atacado', 'atacado_convencional'], true); $produtoTextoInicial = $produtoEhAgranel ? $produtoTextoAgranel : $produtoTextoOriginal; ?>



                            <option value="<?= $p['produto_id'] ?>" data-estoque="<?= $p['estoque'] ?>" data-text-original="<?= htmlspecialchars($produtoTextoOriginal, ENT_QUOTES) ?>" data-text-agranel="<?= htmlspecialchars($produtoTextoAgranel, ENT_QUOTES) ?>" data-produto-principal="<?= $produtoPrincipalOpcao ? '1' : '0' ?>" data-produto-manual-chave="<?= htmlspecialchars($produtoChaveManual, ENT_QUOTES, 'UTF-8') ?>" <?= $produtoSelecionado === (int) $p['produto_id'] ?'selected' : '' ?> <?= $fiscalOk ? '' : 'disabled' ?>>



                                <?= htmlspecialchars($produtoTextoInicial) ?>



                            </option>



                        <?php endforeach; ?>



                    </select>



                    <div id="estoqueProduto" class="estoque-info"></div>



                </div>





<input type="hidden" name="prazo_escolhido" id="prazo_escolhido" value="<?= htmlspecialchars($prazoSelecionado, ENT_QUOTES, 'UTF-8') ?>">

<div class="form-group">



                    <label>Tipo</label>



                    <select name="tipo" id="tipo" onchange="atualizarCamposTipo(); aplicarGramagemProdutoSelecionado(false);" required>



                        <option value="" <?= $tipoSelecionado === '' ?'selected' : '' ?>>Selecione</option>



                        <option value="bandeja" <?= $tipoSelecionado === 'bandeja' ?'selected' : '' ?>>Bandeja</option>







                        <option value="kg" <?= $tipoSelecionado === 'kg' ?'selected' : '' ?>>Kg</option>

                        <option value="unidade" <?= $tipoSelecionado === 'unidade' ?'selected' : '' ?>>Caixa</option>



                    </select>



                </div>



<div class="form-group<?= $tipoComercialSelecionado !== '' ? ' form-group-oculto' : '' ?>" id="grupoPesoUnitario">



                    <label id="pesoLabel">Kg Caixa/Gramagem</label>



                    <input type="number" step="0.01" name="peso_unitario" id="peso_unitario" oninput="recalcTotal()">



                    <small id="pesoAjuda" class="estoque-info"></small>



                </div>



                <div class="form-group form-group-oculto grupo-caixas-oba" id="grupoNumCaixas">
                    <label>Nº de caixas</label>
                    <input type="number" step="1" min="1" name="num_caixas_item" id="num_caixas_item" placeholder="Ex.: 10" oninput="recalcObaCaixas()">
                </div>

                <div class="form-group form-group-oculto grupo-caixas-oba" id="grupoBandejasPorCaixa">
                    <label>Bandejas por caixa</label>
                    <select name="bandejas_por_caixa_item" id="bandejas_por_caixa_item" onchange="recalcObaCaixas()">
                        <option value="">Selecione</option>
                        <option value="6">6/C</option>
                        <option value="15">15/C</option>
                        <option value="18">18/C</option>
                    </select>
                    <small id="obaCaixasAjuda" class="estoque-info"></small>
                </div>

<div class="form-group" id="grupoQuantidade">



                    <label>Quantidade</label>



                    <input type="number" step="0.01" name="pedido" id="pedido" required oninput="recalcTotal()">



                </div>



<div class="form-group">



                    <label>Preço <span id="precoUnidLabel" style="font-weight:400;color:#888;font-size:12px;"></span></label>



                    <input type="number" step="0.01" min="0" name="preco" id="preco" required



                        oninput="recalcTotal(); document.getElementById('preco_editado_manualmente').value = '1';">



                </div>



<div class="fix-info-area" style="grid-column:1/-1;">
                    <div id="preco_sugestao"></div>
                    <div id="resumoVenda"></div>
                </div>

<div class="form-group">



                    <label>Número de OS</label>



	                    <input type="text" name="numero_os" placeholder="Ex.: 001, 2024-15" required



                        value="<?= htmlspecialchars($numeroOsSelecionado, ENT_QUOTES, 'UTF-8') ?>" readonly>



                </div>



<div class="form-group">

                    <label>Entreposto</label>

                    <select name="entreposto" required>
                        <option value="">Selecione o entreposto</option>
                        <?php foreach (['Rodrigo', 'Nivaldo', 'Dezao', 'Botan', 'Danylo', 'Pato Roco', 'CD'] as $opcaoEntreposto): ?>
                            <option value="<?= htmlspecialchars($opcaoEntreposto, ENT_QUOTES, 'UTF-8') ?>" <?= $entrepostoSelecionado === $opcaoEntreposto ? 'selected' : '' ?>><?= htmlspecialchars($opcaoEntreposto, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>

                </div>



<div class="form-group">



                    <label>Forma de Pagamento</label>



                    <select name="forma_pagamento" required>



                        <option value="" <?= $formaPagamentoSelecionada === '' ?'selected' : '' ?>>Selecione</option>



                        <option value="boleto" <?= $formaPagamentoSelecionada === 'boleto' ?'selected' : '' ?>>Boleto</option>



                        <option value="deposito" <?= $formaPagamentoSelecionada === 'deposito' ?'selected' : '' ?>>Depósito</option>



                        <option value="pix" <?= $formaPagamentoSelecionada === 'pix' ?'selected' : '' ?>>PIX</option>



                    </select>



                </div>



<div class="form-group">



                    <label>Prazo de Pagamento</label>



                    <select name="prazo_pagamento" id="prazo_pagamento" required>
                        <option value="" <?= $prazoPagamentoSelecionado === '' ? 'selected' : '' ?>>Selecione</option>
                        <?php foreach ($opcoesPrazoPagamento as $opcaoPrazoPagamento): ?>
                            <option value="<?= htmlspecialchars($opcaoPrazoPagamento, ENT_QUOTES, 'UTF-8') ?>" <?= $prazoPagamentoSelecionado === $opcaoPrazoPagamento ? 'selected' : '' ?>><?= htmlspecialchars($opcaoPrazoPagamento, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>



                </div>



<div class="form-group" style="grid-column:1/-1;">



                    <label>Considerações</label>



                    <textarea name="consideracoes" rows="3"><?= htmlspecialchars($consideracoesSelecionadas, ENT_QUOTES, 'UTF-8') ?></textarea>



                </div>



<div class="form-group form-attachments" style="grid-column:1/-1;">



                    <div class="attachments-title">Anexos</div>



                    <label class="upload-dropzone">



                        <input type="file" name="foto" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp">



                        <span class="upload-icon"><i class="bi bi-camera"></i></span>



                        <strong>Foto do Pedido (opcional)</strong>



                        <small>Clique para enviar ou arraste o arquivo aqui</small>



                    </label>



                </div>



	                <div class="wizard-nav" id="wizardVendaNav">



	                    <button type="button" class="btn-delete" id="wizardVendaPrev" title="Voltar"><i class="bi bi-arrow-left"></i></button>



	                    <button type="button" class="btn-confirmar" id="wizardVendaNext" title="Proxima etapa"><i class="bi bi-arrow-right"></i></button>



	                

	                    <div class="wizard-actions-final wizard-hidden" id="vendaAcoesFinais" style="display:none;">



                    <button type="button" class="btn-secondary" title="Cancelar e descartar rascunho"
                        onclick="cancelarFluxoNovaVenda()">
                        <i class="bi bi-x-lg"></i>
                    </button>

	                    <button type="submit" class="btn-secondary" title="Adicionar outro produto" formnovalidate
                        onclick="return prepararAdicionarItemVenda(this)">
                        <i class="bi bi-plus-lg"></i>
                    </button>

	                    <button type="submit" id="btnSalvar" class="btn-confirmar" title="Enviar Venda" formnovalidate
                        onclick="return prepararEnvioVenda(this)">
                        <i class="bi bi-send"></i>
                    </button>




	                </div>

	                </div>



            </form>



        </div>







        <!-- Modal Enviar OS -->



        <div id="modalEnviarOs" class="modal-enviar-os">



            <div class="modal-box">



                <h3>Enviar OS para Pendentes</h3>



                <p style="margin:0 0 12px 0;font-size:14px;color:#555;">Digite o número da OS a ser enviada:</p>



                <input type="text" id="inputNumeroOs" placeholder="Ex.: 001" onkeydown="if(event.key==='Enter') confirmarEnvioOs()">



                <form id="formEnviarOs" action="actions/enviar_os.php" method="POST">



                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">



                    <input type="hidden" name="numero_os" id="hiddenNumeroOs">



                </form>



                <div class="modal-actions">



                    <button type="button" class="btn-delete" title="Cancelar" onclick="fecharModalEnviarOs()"><i class="bi bi-x-lg"></i></button>



                    <button type="button" class="btn-confirmar" title="Enviar" onclick="confirmarEnvioOs()"><i class="bi bi-send"></i></button>



                </div>



            </div>



        </div>

        </div><!-- /vendas-side-left -->

        <div class="vendas-side-right">

        <div class="card section-card">



            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">



	                <h2 style="margin:0;">Lista de Montagem</h2>



            </div>



            <?php if ($registroResumo && $registroResumo->num_rows > 0): ?>



                <div class="montagem-lista">
                    <div class="montagem-row montagem-header">
                        <span class="col-toggle"></span>
                        <span class="col-os">OS</span>
                        <span class="col-cliente">Cliente</span>
                        <span class="col-itens">It.</span>
                        <span class="col-total">Total (R$)</span>
                        <span class="col-acoes">Ações</span>
                    </div>



	                            <?php while ($resumo = $registroResumo->fetch_assoc()): ?>



	                                <?php



                                    $numeroOsResumo = trim((string) ($resumo['numero_os'] ?? ''));



                                    $osKeyResumo = $numeroOsResumo !== '' ?$numeroOsResumo : '__sem_os';



                                    $osEscapeResumo = htmlspecialchars(addslashes($osKeyResumo), ENT_QUOTES, 'UTF-8');



                                    $itensResumo = $vendasAnexadasPorOs[$osKeyResumo] ?? [];



                                    $editOsUrl = "vendas.php?" . http_build_query([



                                        'numero_os'      => $numeroOsResumo,



                                        'cliente_id'     => $resumo['cliente_id'],



                                        'vendedor'       => $resumo['vendedor'],



                                        'tipo_comercial' => $resumo['tipo_comercial'],



                                        'data_venda'     => $resumo['data_venda'],



                                        'previsao_entrega' => $resumo['previsao_entrega'],

                                        'entreposto'      => $resumo['entreposto'],



                                        'forma_pagamento'=> $resumo['forma_pagamento'],



                                        'prazo_pagamento'=> $resumo['prazo_pagamento'],



                                        'etapa'          => 'finalizar'



                                    ]);



                                    ?>



                                <div class="montagem-row os-group-header">



                                    <span class="col-toggle"><?php if (!empty($itensResumo)): ?><button type="button" class="btn-toggle-os" data-montagem-os="<?= htmlspecialchars($osKeyResumo, ENT_QUOTES) ?>" onclick="toggleMontagemOsItens('<?= $osEscapeResumo ?>')" title="Ver produtos">▼</button><?php endif; ?></span>



                                    <span class="col-os"><?= htmlspecialchars($numeroOsResumo !== '' ? $numeroOsResumo : '—') ?></span>



                                    <span class="col-cliente" title="<?= htmlspecialchars($resumo['cliente_nome'] ?? '-') ?>"><?= htmlspecialchars($resumo['cliente_nome'] ?? '-') ?></span>



                                    <span class="col-itens"><?= (int) $resumo['total_itens'] ?></span>



                                    <span class="col-total">R$ <?= number_format((float) $resumo['total_valor'], 2, ',', '.') ?></span>



                                    <span class="col-acoes"><a href="<?= $editOsUrl ?>" class="btn-edit" title="Continuar esta OS"><i class="bi bi-pencil-square"></i></a><?php if ($numeroOsResumo !== ''): ?><form action="actions/excluir_os_vendas.php" method="POST" style="margin:0;" onsubmit="return confirm('Deseja excluir a OS <?= htmlspecialchars($numeroOsResumo, ENT_QUOTES) ?> inteira?');"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>"><input type="hidden" name="numero_os" value="<?= htmlspecialchars($numeroOsResumo, ENT_QUOTES) ?>"><input type="hidden" name="status" value="anexado"><button type="submit" class="btn-delete" title="Excluir OS inteira"><i class="bi bi-trash"></i></button></form><?php endif; ?></span>
                                </div>



                                <?php foreach ($itensResumo as $v): ?>



                                    <?php



                                    $pesoUnitario = null;



                                    if (!empty($v['tipo']) && $v['tipo'] === 'caixa') {



                                        $pesoUnitario = $v['kg_caixa'];



                                    } elseif (!empty($v['tipo']) && $v['tipo'] === 'bandeja') {



                                        $pesoUnitario = $v['gramagem'];



                                    }



                                    $quantidadeFinal = calcularQuantidadeFinalVenda($v);



                                    $precoTotal = calcularPrecoTotalVenda($v);



                                    $precoDivergenteTabela = !empty($v['preco_manual'])



                                        && (int) $v['preco_manual'] === 1



                                        && trim((string) ($v['tipo_comercial'] ?? '')) !== '';



                                    $precoTabela = $precoDivergenteTabela ? calcularPrecoTabelaVenda($v) : null;



                                    $itemJson = htmlspecialchars(json_encode([



                                        'id' => (int) $v['id'],



                                        'data_venda' => (string) ($v['data_venda'] ?? ''),



                                        'previsao_entrega' => (string) ($v['previsao_entrega'] ?? ''),



                                        'produto_id' => (int) $v['produto_id'],



                                        'cliente_id' => (int) $v['cliente_id'],



                                        'tipo' => (string) ($v['tipo'] ?? 'kg'),



                                        'tipo_comercial' => (string) ($v['tipo_comercial'] ?? ''),



                                        'prazo_escolhido' => (string) ($v['prazo_escolhido'] ?? ''),



                                        'pedido' => (float) ($v['pedido'] ?? 0),



                                        'preco' => (float) ($v['preco'] ?? 0),



                                        'frete' => (float) ($v['frete'] ?? 0),



                                        'peso_unitario' => $pesoUnitario !== null ?(float) $pesoUnitario : '',

                                        'numero_os' => $osKeyResumo,

                                        'grupo' => 'montagem',



                                    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');



                                    ?>



                                    <div class="montagem-row montagem-os-item-row hidden" data-os="<?= htmlspecialchars($osKeyResumo, ENT_QUOTES) ?>">



                                        <span class="col-toggle"></span><span class="col-os"></span>



                                        <span class="col-cliente"><?= htmlspecialchars(nomeProdutoOba($v)) ?></span>



                                        <span class="col-itens"><?= (float)$v['pedido'] ?></span>



                                        <span class="col-total">R$ <?= number_format($precoTotal + (float) ($v['frete'] ?? 0), 2, ',', '.') ?></span>



                                        <span class="col-acoes"><button type="button" class="btn-edit" onclick='abrirModalEdicaoVenda(<?= $itemJson ?>)' title="Editar"><i class="bi bi-pencil"></i></button><form action="actions/excluir_venda_pendente.php" method="POST" style="margin:0;" onsubmit="return confirm('Deseja excluir esta venda?');"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><button type="submit" class="btn-delete" title="Excluir"><i class="bi bi-trash"></i></button></form></span>
                                    </div>



                                <?php endforeach; ?>



                            <?php endwhile; ?>



                                        </div>



            <?php else: ?>



                <div class="msg-sucesso" id="vendaMontagemVazia">Nenhuma venda anexada no momento.</div>



            <?php endif; ?>







            <div class="montagem-lista live-montagem-preview" id="vendaMontagemAoVivo">
                <div class="montagem-row montagem-header">
                    <span class="col-toggle"></span>
                    <span class="col-os">OS</span>
                    <span class="col-cliente">Cliente</span>
                    <span class="col-itens">It.</span>
                    <span class="col-total">Total (R$)</span>
                    <span class="col-acoes">Ações</span>
                </div>
                <div id="vendaMontagemAoVivoBody"></div>
            </div>







        </div><!-- /card lista-montagem -->


        <div class="card section-card live-item-preview" id="previewVendaAoVivo">



            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:12px;flex-wrap:wrap;">



                <h2 style="margin:0;">Item em preenchimento</h2>



                <span class="preview-status"><i class="bi bi-pencil-square"></i> Atualizando ao vivo</span>



            </div>



            <div class="table-container">



                <table data-no-responsive="1">



                    <thead>



                        <tr>



                            <th>OS</th>



                            <th>Cliente</th>



                            <th>Produto</th>



                            <th>Quantidade</th>



                            <th>Total (kg)</th>



                            <th>Preco</th>



                            <th>Total</th>



                            <th>Status</th>



                        </tr>



                    </thead>



                    <tbody id="previewVendaAoVivoBody"></tbody>



                </table>



            </div>



            <p class="preview-note">Este item ja entra na OS ao clicar em +, salvar rascunho ou enviar venda.</p>



        </div>

        </div><!-- /vendas-side-right -->

        </div><!-- /vendas-side-layout -->

        <div class="card section-card">



            <h2>Vendas Pendentes</h2>



            <?php if (empty($vendasPorOs)): ?>



                <div class="msg-sucesso">Nenhuma venda pendente no momento.</div>



            <?php else: ?>



            <div class="table-container">



                <table data-no-responsive="1">



                    <thead>



                        <tr>



                            <th style="width:36px;"></th>



                            <th>OS</th>



                            <th>Produto</th>



                            <th>Cliente</th>



                            <th>Tipo</th>



                            <th>Preço Unit.</th>



                            <th>Quantidade</th>



                            <th>Total</th>



                            <th>Ação</th>



                        </tr>



                    </thead>



                    <tbody>



                    <?php foreach ($vendasPorOs as $osKey => $osItens): ?>



                        <?php



                        $osNum       = $osItens[0]['numero_os'] ?? '';



                        $osLabel = $osNum !== '' ?'OS ' . htmlspecialchars($osNum) : '— sem OS —';



                        $osUrlParam = $osNum !== '' ?urlencode($osNum) : '';



                        $temMaisItens = count($osItens) > 1;



                        $osEscape    = htmlspecialchars(addslashes($osKey), ENT_QUOTES, 'UTF-8');



                        $totalOsPreco = array_sum(array_map('calcularPrecoTotalVenda', $osItens));



                        $itensConfirmacaoOsJson = htmlspecialchars(json_encode(array_map(static fn($row) => [



                            'id' => (int) $row['id'],



                            'produto' => (string) nomeProdutoOba($row),



                            'quantidade' => strtolower((string) ($row['tipo'] ?? 'kg')) === 'bandeja' ? (float) ($row['pedido'] ?? 0) : calcularQuantidadeFinalVenda($row),

                            'tipo' => (string) ($row['tipo'] ?? 'kg'),



                        ], $osItens), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');







                        $_firstV = $osItens[0];
                        $_addProdOsUrl = '';
                        if ($osUrlParam !== '') {
                            $_addProdOsUrl = '/agrocolitti/vendas/vendas.php?' . http_build_query([
                                'numero_os'        => $osNum,
                                'cliente_id'       => isset($_firstV['cliente_id'])       ? $_firstV['cliente_id']       : '',
                                'vendedor'         => isset($_firstV['vendedor'])         ? $_firstV['vendedor']         : '',
                                'tipo_comercial'   => isset($_firstV['tipo_comercial'])   ? $_firstV['tipo_comercial']   : '',
                                'data_venda'       => isset($_firstV['data_venda'])       ? $_firstV['data_venda']       : '',
                                'previsao_entrega' => isset($_firstV['previsao_entrega']) ? $_firstV['previsao_entrega'] : '',
                                'entreposto'       => isset($_firstV['entreposto'])       ? $_firstV['entreposto']       : '',
                                'forma_pagamento'  => isset($_firstV['forma_pagamento'])  ? $_firstV['forma_pagamento']  : '',
                                'prazo_pagamento'  => isset($_firstV['prazo_pagamento'])  ? $_firstV['prazo_pagamento']  : '',
                                'etapa'            => 'produto',
                                'adicionar_pendente' => '1',
                            ]) . '#formAdicionarProdutoPendente';
                        }
                        $_firstConfJson = htmlspecialchars(json_encode([
                            'id'         => (int) $_firstV['id'],
                            'produto'    => (string) nomeProdutoOba($_firstV),
                            'quantidade' => strtolower((string) ($_firstV['tipo'] ?? 'kg')) === 'bandeja' ? (float) ($_firstV['pedido'] ?? 0) : calcularQuantidadeFinalVenda($_firstV),
                            'tipo'       => (string) ($_firstV['tipo'] ?? 'kg'),
                        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr class="os-group-header">
                            <td style="text-align:center;">
                                <button class="btn-toggle-os" data-os="<?= htmlspecialchars($osKey, ENT_QUOTES) ?>"
                                        onclick="toggleOsItens('<?= $osEscape ?>')">&#9660;</button>
                            </td>
                            <td><?= $osLabel ?></td>
                            <td style="color:#888;font-size:0.9em;"><?= count($osItens) ?> produto(s)</td>
                            <td><?= htmlspecialchars($_firstV['cliente_nome'] ?? '-') ?></td>
                            <td>&mdash;</td>
                            <td>&mdash;</td>
                            <td>&mdash;</td>
                            <td>R$ <?= number_format($totalOsPreco, 2, ',', '.') ?></td>
                            <td><div class="acoes">
                                <?php if ($osUrlParam !== ''): ?>
                                <a href="gerar_pedido_venda.php?numero_os=<?= $osUrlParam ?>" class="btn-imprimir" download title="Gerar Pedido Word"><i class="bi bi-file-earmark-text"></i></a>
                                <button type="button" class="btn-confirmar" title="Confirmar OS inteira"
                                        onclick='abrirModalConfirmarVendaOs(<?= json_encode((string) $osNum) ?>, <?= $itensConfirmacaoOsJson ?>)'><i class="bi bi-check2-all"></i></button>
                                <a href="<?= htmlspecialchars($_addProdOsUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-edit" title="Adicionar produto à OS"><i class="bi bi-plus-lg"></i></a>
                                <form action="actions/excluir_os_vendas.php" method="POST" style="display:inline-block;"
                                      onsubmit="return confirm('Deseja excluir a OS <?= htmlspecialchars($osNum, ENT_QUOTES) ?> inteira?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
                                    <input type="hidden" name="numero_os" value="<?= htmlspecialchars($osNum, ENT_QUOTES) ?>">
                                    <input type="hidden" name="status" value="pendente">
                                    <button type="submit" class="btn-delete" title="Excluir OS inteira"><i class="bi bi-trash"></i></button>
                                </form>
                                <?php else: ?>
                                <a href="gerar_pedido_venda.php?venda_id=<?= (int) $_firstV['id'] ?>" class="btn-imprimir" download title="Gerar Pedido Word"><i class="bi bi-file-earmark-text"></i></a>
                                <button type="button" class="btn-confirmar" title="Confirmar Venda"
                                        onclick='abrirModalConfirmarVendaItem(<?= $_firstConfJson ?>)'><i class="bi bi-check-lg"></i></button>
                                <?php endif; ?>
                            </div></td>
                        </tr>
                        <?php foreach ($osItens as $v): ?>
                            <?php
                            $pesoUnitario = null;
                            if (!empty($v['tipo']) && $v['tipo'] === 'caixa')   $pesoUnitario = $v['kg_caixa'];
                            if (!empty($v['tipo']) && $v['tipo'] === 'bandeja') $pesoUnitario = $v['gramagem'];
                            $quantidadeFinal  = calcularQuantidadeFinalVenda($v);
                            $sufixoQuantidade = strtolower((string) ($v['tipo'] ?? 'kg')) === 'unidade' ? ' un.' : ' kg';
                            $precoTotal       = calcularPrecoTotalVenda($v);
                            $precoDivergenteTabela = !empty($v['preco_manual'])
                                && (int) $v['preco_manual'] === 1
                                && trim((string) ($v['tipo_comercial'] ?? '')) !== '';
                            $precoTabela = $precoDivergenteTabela ? calcularPrecoTabelaVenda($v) : null;
                            $itemJson = htmlspecialchars(json_encode([
                                'id'              => (int) $v['id'],
                                'data_venda'      => (string) ($v['data_venda'] ?? ''),
                                'previsao_entrega'=> (string) ($v['previsao_entrega'] ?? ''),
                                'produto_id'      => (int) $v['produto_id'],
                                'cliente_id'      => (int) $v['cliente_id'],
                                'tipo'            => (string) ($v['tipo'] ?? 'kg'),
                                'tipo_comercial'  => (string) ($v['tipo_comercial'] ?? ''),
                                'prazo_escolhido' => (string) ($v['prazo_escolhido'] ?? ''),
                                'pedido'          => (float) ($v['pedido'] ?? 0),
                                'preco'           => (float) ($v['preco'] ?? 0),
                                'frete'           => (float) ($v['frete'] ?? 0),
                                'peso_unitario'   => $pesoUnitario !== null ? (float) $pesoUnitario : '',
                                'numero_os'       => $osKey,
                                'grupo'           => 'pendente',
                            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr class="os-item-row hidden" data-os="<?= htmlspecialchars($osKey, ENT_QUOTES) ?>">
                                <td style="text-align:center;"></td>
                                <td></td>
                                <td><?= htmlspecialchars(nomeProdutoOba($v)) ?></td>
                                <td><?= htmlspecialchars($v['cliente_nome'] ?? '-') ?></td>
                                <td><?= htmlspecialchars(formatarTipoVenda($v)) ?></td>
                                <td<?= $precoDivergenteTabela ? ' class="preco-manual"' : '' ?>>
                                    R$ <?= number_format((float) ($v['preco'] ?? 0), 2, ',', '.') ?>
                                    <?php if ($precoDivergenteTabela): ?><span title="Preço diferente da tabela" style="font-size:11px;">✎</span><?php endif; ?>
                                    <?php if ($precoTabela !== null): ?><span class="preco-tabela-ref">Tabela: R$ <?= number_format($precoTabela, 2, ',', '.') ?></span><?php endif; ?>
                                </td>
                                <td><?= number_format($quantidadeFinal, 2, ',', '.') . $sufixoQuantidade ?></td>
                                <td>R$ <?= number_format($precoTotal, 2, ',', '.') ?></td>
                                <td><div class="acoes">
                                    <button type="button" class="btn-edit" onclick='abrirModalEdicaoVenda(<?= $itemJson ?>)' title="Editar"><i class="bi bi-pencil"></i></button>
                                    <form action="actions/excluir_venda_pendente.php" method="POST" style="display:inline-block;"
                                          onsubmit="return confirm('Deseja excluir este item?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
                                        <input type="hidden" name="id" value="<?= $v['id'] ?>">
                                        <button type="submit" class="btn-delete" title="Excluir item"><i class="bi bi-trash"></i></button>
                                    </form>
                                    <?php if (!empty($v['foto'] ?? '')): ?>
                                    <button type="button" class="btn-foto" title="Ver Foto"
                                        onclick="abrirModalFoto('<?= htmlspecialchars($v['foto'] ?? '', ENT_QUOTES) ?>')">
                                        <i class="bi bi-image"></i>
                                    </button>
                                    <?php endif; ?>
                                </div></td>
                            </tr>
                        <?php endforeach; ?>



                    <?php endforeach; ?>



                    </tbody>



                </table>



            </div>



            <?php endif; ?>



        </div>







    </div>







    <div id="modalConfirmarVendaAbate" class="modal-bg">



        <div class="modal-box modal-confirmar-venda">



            <h3 id="confirmarVendaTitulo">Confirmar venda</h3>



            <form method="POST" action="actions/confirmar_venda.php" id="formConfirmarVendaAbate"



                  onsubmit="return confirm('Confirmar com as quantidades informadas?');">



                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">



                <input type="hidden" name="numero_os" id="confirmarVendaNumeroOs">



                <input type="hidden" name="id" id="confirmarVendaId">



                <div id="confirmarVendaItens"></div>







                <input type="hidden" id="caixaId" name="caixa_id" value="">

                <input type="hidden" id="caixaQuantidade" name="num_caixas" value="0">

                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">



                    <button type="button" class="btn-delete" title="Cancelar" onclick="fecharModalConfirmarVendaAbate()"><i class="bi bi-x-lg"></i></button>



                    <button type="submit" class="btn-confirmar" title="Confirmar"><i class="bi bi-check-lg"></i></button>



                </div>



            </form>



        </div>



    </div>







    <div id="modalEditarVenda" class="modal-bg">



        <div class="modal-box">



            <h3>Editar Venda</h3>







            <!-- O modal de edicao usa o mesmo calculo de tipo do formulario principal. -->



            <form action="actions/editar_venda.php" method="POST" class="form-venda" enctype="multipart/form-data">



                <input type="hidden" name="csrf_token"                value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">



                <input type="hidden" name="id"                        id="edit_id">



                <input type="hidden" name="tipo_comercial"            id="edit_tipo_comercial">



                <input type="hidden" name="preco_editado_manualmente" id="edit_preco_editado_manualmente" value="0">



                <input type="hidden" name="redirect_to"                id="edit_redirect_to" value="../vendas.php">







                <div class="form-group">



                    <label>Previsão de Entrega</label>



                    <input type="date" name="previsao_entrega" id="edit_data_venda" required>



                </div>







                <div class="form-group">



                    <label>Produto</label>



                    <select name="produto_id" id="edit_produto_id" onchange="aplicarGramagemProdutoSelecionado(true, 'edit'); buscarPrecoEdicao()" required>



                        <option value="">Selecione</option>



                        <?php foreach ($produtos as $p): ?>
                            <?php $produtoIdOpcao = (int) $p['produto_id']; $produtoPrincipalOpcao = empty($p['produto_principal_id']); $fiscalOk = $produtoFiscalStatus[$produtoIdOpcao] ?? false; $codigoAntigo = $produtoCodigoAntigoStatus[$produtoIdOpcao] ?? false; $produtoNomeOriginal = (string) $p['produto']; $produtoNomeAgranel = removerGramagemInicialProduto($produtoNomeOriginal); $produtoChaveManual = chaveProdutoManualVenda($produtoNomeOriginal); $produtoSufixos = ($fiscalOk ? '' : ' - fiscal incompleto') . ($codigoAntigo ? ' - codigo antigo' : '') . ' (Estoque: ' . number_format($p['estoque'], 2, ',', '.') . ' kg)'; $produtoTextoOriginal = $produtoNomeOriginal . $produtoSufixos; $produtoTextoAgranel = $produtoNomeAgranel . $produtoSufixos; ?>



                            <option value="<?= $p['produto_id'] ?>" data-text-original="<?= htmlspecialchars($produtoTextoOriginal, ENT_QUOTES) ?>" data-text-agranel="<?= htmlspecialchars($produtoTextoAgranel, ENT_QUOTES) ?>" data-produto-principal="<?= $produtoPrincipalOpcao ? '1' : '0' ?>" data-produto-manual-chave="<?= htmlspecialchars($produtoChaveManual, ENT_QUOTES, 'UTF-8') ?>" <?= $fiscalOk ? '' : 'disabled' ?>>



                                <?= htmlspecialchars($produtoTextoOriginal) ?>



                            </option>



                        <?php endforeach; ?>



                    </select>



                </div>







                <div class="form-group">



                    <label>Cliente</label>



                    <select name="cliente_id" id="edit_cliente_id" onchange="atualizarClienteInfo('edit_cliente_id', 'edit_cliente_info'); buscarPrecoEdicao();" required>



                        <option value="">Selecione</option>



                        <?php foreach ($clientes as $c): ?>
                            <?php $fiscalOk = cadastroFiscalPessoaCompleta($c); ?>



                            <option value="<?= $c['id'] ?>" <?= $fiscalOk ? '' : 'disabled' ?>



                                data-documento="<?= htmlspecialchars($c['documento'] ?? '', ENT_QUOTES, 'UTF-8') ?>"



                                data-telefone="<?= htmlspecialchars($c['telefone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"



                                data-endereco="<?= htmlspecialchars($c['endereco'] ?? '', ENT_QUOTES, 'UTF-8') ?>">



                                <?= htmlspecialchars($c['nome']) ?><?= $fiscalOk ? '' : ' - fiscal incompleto' ?>



                            </option>



                        <?php endforeach; ?>



                    </select>



                </div>



                <div class="form-group" id="editGrupoPrazoAtacado" style="display:none;">



                    <label>Prazo de Pagamento</label>



                    <select name="prazo_escolhido" id="edit_prazo_escolhido" onchange="aplicarPrazoAtacadoEdicao(); buscarPrecoEdicao();">



                        <option value="">Selecione</option>



                        <option value="5_dias">5 dias</option>



                        <option value="30_dias">30 dias</option>



                    </select>



                </div>



                <div class="form-group">



                    <label>CPF/CNPJ</label>



                    <input type="text" id="edit_cliente_info_documento" readonly>



                </div>



                <div class="form-group">



                    <label>Telefone</label>



                    <input type="text" id="edit_cliente_info_telefone" readonly>



                </div>



                <div class="form-group">



                    <label>Endereço</label>



                    <input type="text" id="edit_cliente_info_endereco" readonly>



                </div>







                <div class="form-group">



                    <label>Tipo</label>



                    <select name="tipo" id="edit_tipo" onchange="onTipoEdicaoChange();" required>



                        <option value="">Selecione</option>



                        <option value="bandeja">Bandeja</option>







                        <option value="kg">Kg</option>

                        <option value="caixa">Caixa</option>

                        <option value="unidade">Unidade</option>



                    </select>



                </div>







                <div class="form-group">



                    <label>Quantidade</label>



                    <input type="number" step="0.01" name="pedido" id="edit_pedido" required>



                </div>







                <div class="form-group">



                    <label>Preço</label>



                    <input type="number" step="0.01" min="0" name="preco" id="edit_preco" required



                        oninput="document.getElementById('edit_preco_editado_manualmente').value = '1';">



                </div>







                <div class="form-group">



                    <label>Frete</label>



                    <input type="number" step="0.01" min="0" name="frete" id="edit_frete" value="0.00">



                </div>







                <div class="form-group" id="editGrupoPesoUnitario">



                    <label id="editPesoLabel">Kg Caixa/Gramagem</label>



                    <input type="number" step="0.01" name="peso_unitario" id="edit_peso_unitario">



                    <small id="editPesoAjuda" class="estoque-info"></small>



                </div>







                <div class="form-group">



                    <label>Anexar foto</label>



                    <input type="file" name="foto" id="edit_foto" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp">



                    <small class="estoque-info">Opcional. Ao enviar uma nova foto, ela substitui a foto atual.</small>



                </div>







                <div class="form-group" style="justify-content:flex-end; gap:10px;">



                    <button type="submit" class="btn-confirmar" title="Salvar"><i class="bi bi-check-lg"></i></button>



                    <button type="button" class="btn-delete" title="Cancelar" onclick="fecharModalEdicaoVenda()"><i class="bi bi-x-lg"></i></button>



                </div>



            </form>



        </div>



    </div>







    <div id="modalFoto">



        <div class="foto-wrap">



            <button class="foto-close" onclick="fecharModalFoto()" title="Fechar">&#10005;</button>



            <img id="modalFotoImg" src="" alt="Foto da venda">



        </div>



    </div>







    <div id="modalPreviewPedidoVenda" class="modal-bg">



        <div class="modal-box preview-pedido">



            <h3 id="previewPedidoVendaTitulo" style="margin:0;">Conferir Pedido</h3>



            <iframe id="previewPedidoVendaFrame" src="about:blank" title="Preview do pedido de venda"></iframe>



            <div class="preview-actions">



                <button type="button" class="btn-delete" title="Voltar" onclick="fecharPreviewPedidoVenda()"><i class="bi bi-x-lg"></i></button>



                <button type="button" class="btn-confirmar" title="Confirmar" onclick="confirmarPreviewPedidoVenda()"><i class="bi bi-check-lg"></i></button>



            </div>



        </div>



    </div>










</body>







</html>




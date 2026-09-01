<?php

require "config/conexao.php";

require "config/ciclo_helper.php";

require "auth/proteger.php";

require "config/layout_helper.php";

    







date_default_timezone_set('America/Sao_Paulo');











$hoje = date('Y-m-d');

$totalEstoque = 0;

$estoqueProdutos = [];

$cicloAtivo = getCicloAtivo($conexao);

$cicloAtivoId = $cicloAtivo ? (int) $cicloAtivo['id'] : null;

$filtroCicloPF = montarClausulaCiclo($conexao, 'previsao_fornecedor', 'pf', $cicloAtivoId);

$filtroCicloPC = montarClausulaCiclo($conexao, 'previsao_colheita', 'pc', $cicloAtivoId);

$filtroCicloV = montarClausulaCiclo($conexao, 'vendas', 'v', $cicloAtivoId);





//DATA DO SERVIDOR

$data = new DateTime();



$dias = [

    'Sunday' => 'Domingo',

    'Monday' => 'Segunda-feira',

    'Tuesday' => 'Terça-feira',

    'Wednesday' => 'Quarta-feira',

    'Thursday' => 'Quinta-feira',

    'Friday' => 'Sexta-feira',

    'Saturday' => 'Sábado'

];



$diaSemana = $dias[$data->format('l')];

































// ===============================

// MÉTRICAS

// ===============================



// $entradaHoje = $conexao->query("

//     SELECT IFNULL(SUM(quantidade),0) as total

//     FROM movimentacoes

//     WHERE tipo IN ('entrada_fornecedor','entrada_colheita')

//     AND DATE(data_movimentacao)='$hoje'

// ")->fetch_assoc()['total'];





// --------------------------

// Previsao Colheita (confirmado — acumulado do ciclo)

// --------------------------

$stmtColheita = $conexao->prepare("

    SELECT

        IFNULL(SUM(quantidade_prevista),0) AS total_kg,

        COUNT(*) AS total_registros

    FROM previsao_colheita pc

    WHERE status='confirmado'

    {$filtroCicloPC}

");

$stmtColheita->execute();

$resultColheita = $stmtColheita->get_result();

$entradaColheita = $resultColheita->fetch_assoc();



// --------------------------

// Previsao Fornecedor (concluido — acumulado do ciclo)

// --------------------------

$stmtFornecedor = $conexao->prepare("

    SELECT

        IFNULL(SUM(quantidade_recebida),0) AS total_kg,

        COUNT(*) AS total_registros

    FROM previsao_fornecedor pf

    WHERE status='concluido'

    {$filtroCicloPF}

");

$stmtFornecedor->execute();

$resultFornecedor = $stmtFornecedor->get_result();

$entradaFornecedor = $resultFornecedor->fetch_assoc();



// --------------------------

// Totais combinados

// --------------------------

$totalEntradaKg = $entradaColheita['total_kg'] + $entradaFornecedor['total_kg'];

$totalEntradaRegistros = $entradaColheita['total_registros'] + $entradaFornecedor['total_registros'];



$totalEntradaKgFmt = number_format($totalEntradaKg,2,',','.');





// VENDAS DO CICLO ATIVO (acumulado — sem filtro por data)

$stmtVendaHoje = $conexao->prepare("

    SELECT

        IFNULL(SUM(quantidade),0) AS total_kg,

        COUNT(*) AS total_vendas

    FROM vendas v

    WHERE status='concluido'

    {$filtroCicloV}

");

$stmtVendaHoje->execute();

$resultVendaHoje = $stmtVendaHoje->get_result();

$vendaHoje = $resultVendaHoje->fetch_assoc();



// Para exibir no dashboard

$totalKgHoje = number_format($vendaHoje['total_kg'], 2, ',', '.');

$totalVendasHoje = $vendaHoje['total_vendas'];



$stmtMaisVendido = $conexao->prepare("

    SELECT p.nome, COUNT(*) as total

    FROM vendas v

    JOIN produtos p ON p.id = v.produto_id

    WHERE v.status = 'concluido'

    {$filtroCicloV}

    GROUP BY v.produto_id

    ORDER BY total DESC

    LIMIT 3

");



$stmtMaisVendido->execute();

$resultMaisVendido = $stmtMaisVendido->get_result();



$maisVendidos = [];



while ($row = $resultMaisVendido->fetch_assoc()) {

    $maisVendidos[] = $row;

}











// PREVISAO COLHEITA DASHBOARD (pendentes)

$stmtColheitaPendente = $conexao->prepare(

    'SELECT

    COUNT(*) AS total_colheita

    FROM previsao_colheita pc

    WHERE status ="pendente"

    ' . $filtroCicloPC . '

    '

    );

$stmtColheitaPendente->execute();

$resultColheitaPendente = $stmtColheitaPendente->get_result();

$colheita = $resultColheitaPendente->fetch_assoc();



$totalColheita = $colheita['total_colheita'];







// PREVISAO COMPRA DASHBOARD (pendentes)

$stmtFornecedorPendente = $conexao->prepare(

    'SELECT

    COUNT(*) AS total_fornecedor

    FROM previsao_fornecedor pf

    WHERE status ="pendente"

    ' . $filtroCicloPF . '

    '

    );

$stmtFornecedorPendente->execute();

$resultFornecedorPendente = $stmtFornecedorPendente->get_result();

$fornecedor = $resultFornecedorPendente->fetch_assoc();



$totalFornecedor = $fornecedor['total_fornecedor'];











// ===============================

// ESTOQUE POR PRODUTO

// ===============================



$estoqueCalculado = calcularEstoqueProdutosDoCiclo($conexao, $cicloAtivoId);



// Limiares de classificação (ajuste aqui se necessário)

$thresholdCritico = 10;   // <= 10 kg  → Crítico  (vermelho)

$thresholdAtencao = 100;  // <= 100 kg → Atenção  (amarelo); > 100 → Normal (verde)



$contCritico   = 0;

$contAtencao   = 0;

$contNormal    = 0;

$contEmEstoque = 0;

$contZerado    = 0;

$contNegativo  = 0;

$maxEstoque    = 0;



foreach ($estoqueCalculado as $item) {

    $q = (float) $item['estoque'];

    $totalEstoque += $q;

    if ($q > 0) {

        $contEmEstoque++;

    } elseif ($q < 0) {

        $contNegativo++;

    } else {

        $contZerado++;

    }

    if ($q <= $thresholdCritico)     { $contCritico++; }

    elseif ($q <= $thresholdAtencao) { $contAtencao++; }

    else                              { $contNormal++;  }

    if ($q > $maxEstoque) $maxEstoque = $q;

}



$totalBaixoEstoque = $contCritico; // mantém compatibilidade com os cards do topo



// Ordena por situacao real: positivo, negativo e zerado; dentro de cada grupo, alfabetico.

usort($estoqueCalculado, static function (array $a, array $b): int {

    $ordem = static function (float $q): int {

        if ($q > 0) return 0;

        if ($q < 0) return 1;

        return 2;

    };

    $oa = $ordem((float) $a['estoque']);

    $ob = $ordem((float) $b['estoque']);

    return $oa !== $ob ? $oa <=> $ob : strcmp($a['produto'], $b['produto']);

});

$totalPendencias = (int) $totalColheita + (int) $totalFornecedor;

$totalMovimentoHoje = (float) $totalEntradaKg + (float) $vendaHoje['total_kg'];

$maiorFonteEntrada = (float) $entradaColheita['total_kg'] >= (float) $entradaFornecedor['total_kg'] ? 'Colheita' : 'Fornecedor';





// Estoque futuro = compras futuras + colheitas futuras. Nao soma estoque atual.

$totalEstoqueFuturo = array_sum(array_map(static function (array $item): float {

    return (float) ($item['estoque_futuro'] ?? 0);

}, $estoqueCalculado));





?>



<!DOCTYPE html>

<html>



<head>



    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">



    <title>Sistema AgroColitti</title>



    <style>

        :root {

            --verde-escuro: #1b5e20;

            --verde: #2e7d32;

            --verde-suave: #e8f5e9;

            --cinza-fundo: #f4f6f9;

            --cinza-borda: #dfe5e1;

            --cinza-texto: #667085;

            --sombra-card: 0 10px 25px rgba(27, 94, 32, 0.08);

        }



        body {

            margin: 0;

            font-family:'Segoe UI', Arial, sans-serif;

            background: #f4f6f9;

            color: #1f2937;

        }



        /* HEADER */



        .header {

            background: #1b5e20;

            color: white;

            padding: 15px 25px;

            display: flex;

            justify-content: space-between;

            align-items: center;

            flex-wrap: wrap;

        }



        .header h1 {

            margin: 0;

            font-size: 20px;

            font-weight:600;

        }



        .user-info {

            font-size: 14px;

        }



        .user-info a {

            color: white;

            text-decoration: none;

            margin-left: 10px;

            font-weight: bold;

        }



        /* MENU MOBILE */



        .menu-btn {

            display: none;

            font-size: 24px;

            cursor: pointer;

        }



        /* NAVBAR */



        .navbar {

            background: #18531c;

            padding: 10px 20px;

            display: flex;

            gap: 10px;

            flex-wrap: wrap;

        }



        .navbar a {

            color: white;

            text-decoration: none;

            font-weight: bold;

            padding: 6px 10px;

            border-radius: 6px;

            font-weight:600;

        }



        .navbar a:hover {

            background: rgba(255, 255, 255, 0.2);

        }



        /* CONTAINER */



        .container {

            padding: 30px;

            max-width: 1200px;

            margin: auto;

        }



        .page-intro {

            background: #1b5e20;

            color: white;

            padding: 26px 28px;

            border-radius: 16px;

            box-shadow: 0 8px 18px rgba(27, 94, 32, 0.10);

            display: grid;

            grid-template-columns: minmax(0, 1.6fr) minmax(280px, 1fr);

            gap: 24px;

            margin-bottom: 28px;

            position: relative;

        }



        .intro-copy {

            position: relative;

            z-index: 1;

        }



        .page-intro h2 {

            margin: 0 0 10px;

            font-size: 30px;

            line-height: 1.1;

        }



        .page-intro p {

            margin: 0;

            max-width: 640px;

            color: rgba(255, 255, 255, 0.88);

            line-height: 1.6;

        }



        .intro-meta {

            display: flex;

            flex-wrap: wrap;

            gap: 10px;

            margin-top: 18px;

        }



        .meta-pill {

            background: rgba(255, 255, 255, 0.13);

            border: 1px solid rgba(255, 255, 255, 0.14);

            border-radius: 999px;

            padding: 8px 14px;

            font-size: 13px;

            font-weight: 600;

            backdrop-filter: blur(4px);

        }



        .closing-panel {

            position: relative;

            z-index: 1;

            background: rgba(255, 255, 255, 0.10);

            border: 1px solid rgba(255, 255, 255, 0.14);

            border-radius: 14px;

            padding: 20px;

        }



        .closing-panel h3 {

            margin: 0 0 14px;

            font-size: 16px;

        }



        .closing-list {

            display: grid;

            gap: 12px;

        }



        .closing-item {

            background: rgba(255, 255, 255, 0.08);

            border-radius: 14px;

            padding: 12px 14px;

        }



        .closing-label {

            display: block;

            font-size: 12px;

            text-transform: uppercase;

            letter-spacing: 0.06em;

            color: rgba(255, 255, 255, 0.72);

            margin-bottom: 4px;

        }



        .closing-value {

            font-size: 21px;

            font-weight: 700;

        }



        .dashboard-grid {

            display: grid;

            grid-template-columns: minmax(0, 1.5fr) minmax(280px, 0.9fr);

            gap: 22px;

            margin-bottom: 24px;

        }



        .section-card {

            background: rgba(255, 255, 255, 0.92);

            border: 1px solid rgba(27, 94, 32, 0.08);

            border-radius: 16px;

            box-shadow: var(--sombra-card);

            padding: 24px;

        }



        .section-header {

            display: flex;

            justify-content: space-between;

            align-items: flex-start;

            gap: 14px;

            margin-bottom: 18px;

        }



        .section-header h3,

        .section-header h2 {

            margin: 0;

            color: var(--verde-escuro);

        }



        .section-header p {

            margin: 6px 0 0;

            color: var(--cinza-texto);

            line-height: 1.5;

        }



        .summary-grid {

            display: grid;

            grid-template-columns: repeat(3, minmax(0, 1fr));

            gap: 16px;

        }



        .summary-card {

            background: #ffffff;

            border: 1px solid var(--cinza-borda);

            border-radius: 14px;

            padding: 18px;

            text-decoration: none;

            color: inherit;

            transition: transform 0.18s ease, box-shadow 0.18s ease;

        }



        .summary-card:hover {

            transform: translateY(-2px);

            box-shadow: 0 14px 28px rgba(27, 94, 32, 0.12);

        }



        .summary-label {

            display: block;

            font-size: 13px;

            font-weight: 700;

            letter-spacing: 0.04em;

            text-transform: uppercase;

            color: var(--cinza-texto);

            margin-bottom: 10px;

        }



        .summary-value {

            margin: 0;

            font-size: 28px;

            line-height: 1;

            color: var(--verde);

        }



        .summary-meta {

            margin-top: 12px;

            font-size: 14px;

            color: #4b5563;

            line-height: 1.55;

        }



        .summary-footnote {

            margin-top: 10px;

            padding-top: 10px;

            border-top: 1px dashed rgba(27, 94, 32, 0.14);

            color: var(--cinza-texto);

            font-size: 13px;

        }



        .dashboard-date {

            margin: 6px 0 18px;

            font-size: 16px;

            color: #111827;

        }



        .summary-card-wide {

            grid-column: 1 / -1;

        }



        .insights-grid {

            display: grid;

            grid-template-columns: repeat(3, minmax(0, 1fr));

            gap: 14px;

        }



        .insight-card {

            background: #f8fbf8;

            border: 1px solid var(--cinza-borda);

            border-radius: 18px;

            padding: 18px;

        }



        .insight-card h4 {

            margin: 0 0 6px;

            font-size: 15px;

            color: var(--verde-escuro);

        }



        .insight-card p {

            margin: 0;

            font-size: 14px;

            line-height: 1.6;

            color: #475467;

        }



        .insight-highlight {

            display: block;

            margin-top: 10px;

            font-size: 24px;

            font-weight: 700;

            color: var(--verde);

        }



        .action-list {

            display: grid;

            gap: 14px;

        }



        .action-item {

            background: #f8fbf8;

            border: 1px solid var(--cinza-borda);

            border-radius: 18px;

            padding: 18px;

        }



        .action-item h4 {

            margin: 0 0 6px;

            font-size: 15px;

            color: var(--verde-escuro);

        }



        .action-item p {

            margin: 0;

            font-size: 14px;

            color: #475467;

            line-height: 1.6;

        }



        .priority-chip {

            display: inline-flex;

            align-items: center;

            gap: 8px;

            padding: 7px 12px;

            border-radius: 999px;

            background: var(--verde-suave);

            color: var(--verde-escuro);

            font-weight: 700;

            font-size: 12px;

            text-transform: uppercase;

            letter-spacing: 0.04em;

            margin-bottom: 10px;

        }



        .stock-section {

            background: rgba(255, 255, 255, 0.95);

            border: 1px solid rgba(27, 94, 32, 0.08);

            border-radius: 16px;

            box-shadow: var(--sombra-card);

            padding: 26px;

        }



        .stock-topbar {

            display: flex;

            justify-content: space-between;

            gap: 16px;

            align-items: center;

            margin-bottom: 20px;

        }



        .stock-topbar h2 {

            margin: 0;

            color: var(--verde-escuro);

        }



        .stock-topbar p {

            margin: 6px 0 0;

            color: var(--cinza-texto);

        }



        .stock-alert {

            background: #f8fbf8;

            border: 1px solid var(--cinza-borda);

            border-radius: 16px;

            padding: 12px 16px;

            min-width: 210px;

            text-align: right;

        }



        .stock-alert strong {

            display: block;

            color: var(--verde-escuro);

            font-size: 14px;

        }



        .stock-alert span {

            color: var(--cinza-texto);

            font-size: 13px;

        }



        /* PESQUISA */



        .search-box {

            margin-top: 20px;

            margin-bottom: 10px;

        }



        .search-box input {

            width: 98%;

            padding: 10px;

            border-radius: 12px;

            border: 1px solid var(--cinza-borda);

            font-size: 14px;

            background: #fbfdfb;

        }



        /* TABELA */



        .table-responsive {

            width: 100%;

            overflow-x: auto;

        }



        table {

            width: 100%;

            margin-top: 10px;

            border-collapse: collapse;

            background: white;

            border-radius: 18px;

            overflow: hidden;

            box-shadow: 0 10px 25px rgba(27, 94, 32, 0.08);

        }



        table th {

            background: #2e7d32;

            color: white;

            padding: 12px;

        }



        table td {

            padding: 12px;

            border-bottom: 1px solid #eee;

            text-align: center;

        }



        .alerta {

            color: #c62828;

            font-weight: bold;

        }



        /* BOTÕES */



        .btn-danger {

            background: #c62828;

            color: white;

            border: none;

            padding: 10px 16px;

            border-radius: 10px;

            cursor: pointer;

            font-weight: bold;

            margin-bottom: 20px;

        }



        .btn-exportar {

            background: #2e7d32;

            color: white;

            border: none;

            padding: 12px 20px;

            border-radius: 12px;

            cursor: pointer;

            font-weight: bold;

            box-shadow: 0 10px 20px rgba(46, 125, 50, 0.18);

        }



        /* CENTRALIZAR BOTÃO */



        .exportar-box {

            text-align: center;

            margin-top: 25px;

        }







a{



text-decoration: none;

color: black;



}





        @media print {



    .btn-danger,

    .btn-exportar,

    .navbar,

    .menu-btn,

    .header,

    .search-box {

        display: none;

    }



    body {

        background: white;

    }



    .container {

        padding: 0;

    }

}













        /* RESPONSIVO */



        /* ── Estoque — nova visualização ──────────────────────────────── */

        .est-title    { margin:0; color:var(--verde-escuro); font-size:22px; font-weight:700; }

        .est-subtitle { margin:6px 0 0; color:var(--cinza-texto); font-size:14px; }



        .est-pagination {

            display: flex;

            align-items: center;

            justify-content: space-between;

            margin-top: 16px;

            gap: 12px;

            flex-wrap: wrap;

        }

        .est-page-btn {

            border: 1px solid var(--cinza-borda);

            background: #fff;

            border-radius: 8px;

            padding: 8px 18px;

            font-size: 13px;

            font-weight: 600;

            cursor: pointer;

            color: #374151;

            transition: background .15s;

        }

        .est-page-btn:hover:not(:disabled) { background: #f3f4f6; }

        .est-page-btn:disabled { opacity: .4; cursor: not-allowed; }

        #est-page-info { font-size: 13px; color: var(--cinza-texto); }



        .est-controls {

            display: flex;

            gap: 14px;

            align-items: center;

            flex-wrap: wrap;

            margin-bottom: 18px;

        }

        .est-tabs { display:flex; gap:8px; flex-wrap:wrap; }

        .est-tab {

            border: 1px solid var(--cinza-borda);

            background: #fff;

            border-radius: 999px;

            padding: 7px 15px;

            font-size: 13px;

            font-weight: 600;

            cursor: pointer;

            color: #374151;

            transition: background .15s, border-color .15s;

        }

        .est-tab:hover       { background:#f3f4f6; }

        .est-tab-active      { background:#e8f5e9; border-color:#a5d6a7; color:#1b5e20; }

        .est-search          { flex:1; min-width:200px; }

        .est-search input {

            width: 100%;

            padding: 9px 16px;

            border-radius: 999px;

            border: 1px solid var(--cinza-borda);

            font-size: 14px;

            background: #fbfdfb;

            box-sizing: border-box;

        }

        .est-search input:focus { outline:none; border-color:#2e7d32; box-shadow:0 0 0 2px rgba(46,125,50,.12); }



        #tabelaProdutos { box-shadow:none; border-radius:12px; overflow:hidden; margin-top:0; }

        #tabelaProdutos th { background:#2e7d32; color:#fff; padding:11px 14px; font-size:13px; text-align:left; }

        #tabelaProdutos td { padding:11px 14px; text-align:left; font-size:14px; }

        #tabelaProdutos tr:last-child td { border-bottom:none; }

        .sort-th { cursor:pointer; user-select:none; white-space:nowrap; }

        .sort-th:hover { background:#256427; }

        .sort-icon { font-size:11px; opacity:.7; margin-left:4px; }



        .est-nome        { font-weight:600; color:#111827; }

        .est-qtd         { font-weight:700; color:#1f2937; white-space:nowrap; }

        .est-qtd-critico { color:#c62828 !important; }

        .est-futuro      { font-weight:700; color:#166534; white-space:nowrap; }

        .est-futuro small { display:block; color:#6b7280; font-size:11px; font-weight:600; margin-top:2px; }



        .est-bar-cell { width:160px; min-width:120px; }

        .est-bar-bg   { background:#f3f4f6; border-radius:999px; height:10px; overflow:hidden; }

        .est-bar-fill { height:100%; border-radius:999px; transition:width .3s ease; }

        .bar-critico  { background:#ef5350; }

        .bar-atencao  { background:#ffa726; }

        .bar-normal   { background:#66bb6a; }



        .est-badge {

            display: inline-block;

            padding: 4px 12px;

            border-radius: 999px;

            font-size: 12px;

            font-weight: 700;

            text-transform: uppercase;

            letter-spacing: .04em;

            white-space: nowrap;

        }

        .badge-critico { background:#ffebee; color:#c62828; border:1px solid #ffcdd2; }

        .badge-atencao { background:#fff8e1; color:#e65100; border:1px solid #ffe082; }

        .badge-normal  { background:#f1f8e9; color:#2e7d32; border:1px solid #c5e1a5; }

        .badge-positivo { background:#ecfdf3; color:#166534; border:1px solid #bbf7d0; }

        .badge-negativo { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }

        .badge-zerado   { background:#f3f4f6; color:#4b5563; border:1px solid #d1d5db; }

        /* ── fim estoque ──────────────────────────────────────────────── */



        @media (max-width:768px) {



            .menu-btn {

                display: block;



            }



              .navbar {

        display: flex;

        flex-direction: column;

        max-height: 0;

        overflow: hidden;

        opacity: 0;

        transition:opacity 0.20s ease;

    }



    .navbar.active {
        max-height: 600px;
        opacity: 1;
    }



            .page-intro,

            .dashboard-grid,

            .summary-grid,

            .insights-grid {

                grid-template-columns: 1fr;

            }



            .summary-card-wide {

                grid-column: auto;

            }



            .btn-danger,

            .btn-exportar {

                width: 100%;

            }



            .stock-topbar {

                flex-direction: column;

                align-items: flex-start;

            }



            .stock-alert {

                width: 100%;

                text-align: left;

            }



            .est-controls     { flex-direction: column; align-items: stretch; }

            .est-bar-cell, table .est-bar-cell { display: none !important; }

            .search-box input { width: 94%; }

}

















    </style>

    <?php renderAppLayoutStyles(); ?>



    <script>

        function toggleMenu() {

            document.getElementById("navbar").classList.toggle("active");

        }



        function gerarPDF() { window.print(); }



        // ── Estoque — filtro + paginação + ordenação ─────────────────────

        let estoqueStatusFiltro = 'todos';

        let estoquePagina       = 1;

        const estoquePorPagina  = 20;

        let sortCol = null, sortDir = 1;



        function filtrarEstoqueStatus(status) {

            estoqueStatusFiltro = status;

            estoquePagina = 1;

            document.querySelectorAll('.est-tab').forEach(function(btn) {

                btn.classList.toggle('est-tab-active', btn.dataset.status === status);

            });

            aplicarFiltroEstoque();

        }



        function pesquisarProduto() {

            estoquePagina = 1;

            aplicarFiltroEstoque();

        }



        function aplicarFiltroEstoque() {

            const busca = (document.getElementById('pesquisa')?.value || '').toLowerCase().trim();

            const rows  = Array.from(document.querySelectorAll('#tabelaProdutos tbody tr'));



            const visiveis = rows.filter(function(tr) {

                const estoque = parseFloat(tr.dataset.estoque || 0);

                const status  = tr.dataset.status || '';

                const nome    = tr.dataset.nome   || '';



                let okStatus = true;

                if      (estoqueStatusFiltro === 'positivo')  okStatus = estoque > 0;

                else if (estoqueStatusFiltro === 'negativo')  okStatus = estoque < 0;

                else if (estoqueStatusFiltro === 'zerado')    okStatus = estoque === 0;

                else if (estoqueStatusFiltro !== 'todos')     okStatus = status === estoqueStatusFiltro;



                const okBusca = !busca || nome.includes(busca);

                return okStatus && okBusca;

            });



            const total      = visiveis.length;

            const totalPages = Math.max(1, Math.ceil(total / estoquePorPagina));

            if (estoquePagina > totalPages) estoquePagina = totalPages;



            const start = (estoquePagina - 1) * estoquePorPagina;

            const end   = start + estoquePorPagina;



            rows.forEach(function(tr) { tr.style.display = 'none'; });

            visiveis.slice(start, end).forEach(function(tr) { tr.style.display = ''; });



            const info = document.getElementById('est-page-info');

            if (info) info.textContent = 'Página ' + estoquePagina + ' de ' + totalPages + ' · ' + total + ' produto(s)';



            const prevBtn = document.getElementById('est-prev');

            const nextBtn = document.getElementById('est-next');

            if (prevBtn) prevBtn.disabled = estoquePagina <= 1;

            if (nextBtn) nextBtn.disabled = estoquePagina >= totalPages;

        }



        function estoquePagAnterior() {

            if (estoquePagina > 1) { estoquePagina--; aplicarFiltroEstoque(); }

        }



        function estoquePagProxima() {

            estoquePagina++;

            aplicarFiltroEstoque();

        }



        function sortEstoque(col) {

            if (sortCol === col) { sortDir *= -1; } else { sortCol = col; sortDir = 1; }

            const tbody = document.querySelector('#tabelaProdutos tbody');

            if (!tbody) return;

            Array.from(tbody.querySelectorAll('tr'))

                .sort(function(a, b) {

                    if (col === 'nome')

                        return sortDir * (a.dataset.nome || '').localeCompare(b.dataset.nome || '', 'pt-BR');

                    if (col === 'estoque')

                        return sortDir * (parseFloat(a.dataset.estoque) - parseFloat(b.dataset.estoque));

                    if (col === 'status') {

                        const o = {positivo:0, negativo:1, zerado:2};

                        return sortDir * ((o[a.dataset.status] ?? 9) - (o[b.dataset.status] ?? 9));

                    }

                    return 0;

                })

                .forEach(function(tr) { tbody.appendChild(tr); });



            document.querySelectorAll('.sort-icon').forEach(function(el) { el.textContent = '↕'; });

            const icon = document.querySelector('.sort-icon[data-col="' + col + '"]');

            if (icon) icon.textContent = sortDir === 1 ? '↑' : '↓';



            estoquePagina = 1;

            aplicarFiltroEstoque();

        }



        document.addEventListener('DOMContentLoaded', function() { aplicarFiltroEstoque(); });

    </script>



</head>



<body>



    <?php renderAppHeader('.'); ?>



    <div class="container page-container app-shell">



        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'fechado'): ?>

            <div style="background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600;">

                Ciclo fechado e próximo ciclo aberto com sucesso.

            </div>

        <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'erro'): ?>

            <div style="background:#ffebee;color:#c62828;border:1px solid #ef9a9a;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600;">

                <?= htmlspecialchars($_GET['detalhe'] ?? 'Erro ao processar o ciclo.'); ?>

            </div>

        <?php endif; ?>



        <?php if (($_SESSION['usuario_nivel'] ?? '') === 'admin'): ?>

            <form method="POST" action="reset/reset_sistema.php" onsubmit="return confirm('Deseja zerar vendas, estoque inicial, entradas e movimentacoes de estoque? Compras futuras e cadastros serao preservados.');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf(), ENT_QUOTES) ?>">

                <button type="submit" name="resetar_sistema" class="btn-danger">

                    Zerar Dados Operacionais

                </button>

            </form>

        <?php endif; ?>



        <div class="dashboard-date"><?= $data->format('d-m-Y'); ?>(<?= $diaSemana ?>)</div>



        <section class="section-card" style="margin-bottom:24px;">

            <div class="summary-grid">

                <a class="summary-card" href="#destino">

                    <span class="summary-label"><i class="bi bi-box"></i> Total em Estoque</span>

                    <p class="summary-value"><?= number_format($totalEstoque, 2, ',', '.'); ?> kg</p>

                    <div class="summary-footnote"><?= $totalBaixoEstoque ?> alerta(s) de estoque</div>

                </a>



                <a class="summary-card" href="entradas/entradas.php">

                    <span class="summary-label"><i class="bi bi-arrow-down-circle"></i> Entradas</span>

                    <p class="summary-value"><?= $totalEntradaKgFmt; ?> kg</p>

                    <div class="summary-meta"><?= $totalEntradaRegistros; ?> registro(s)</div>

                    <div class="summary-footnote">Colheita: <?= number_format($entradaColheita['total_kg'], 2, ',', '.'); ?> kg | Fornecedor: <?= number_format($entradaFornecedor['total_kg'], 2, ',', '.'); ?> kg</div>

                </a>



                <a class="summary-card" href="vendas/vendas.php">

                    <span class="summary-label"><i class="bi bi-cart-check"></i> Vendas</span>

                    <p class="summary-value"><?= $totalKgHoje; ?> kg</p>

                    <div class="summary-meta"><?= $totalVendasHoje; ?> venda(s)</div>

                    <div class="summary-footnote"><?= !empty($maisVendidos) ? htmlspecialchars($maisVendidos[0]['nome']) : 'Sem destaque' ?></div>

                </a>



                <a class="summary-card" href="previsoes/previsao_colheita.php">

                    <span class="summary-label"><i class="bi bi-calendar2-event"></i> Colheita Futura</span>

                    <p class="summary-value"><?= $totalColheita ?> Colheitas</p>

                    <div class="summary-footnote"><?= $cicloAtivo ? htmlspecialchars(getNomeCiclo($cicloAtivo)) : 'Sem ciclo ativo' ?></div>

                </a>



                <a class="summary-card" href="previsoes/previsao_fornecedor.php">

                    <span class="summary-label"><i class="bi bi-truck"></i> Compras Pendentes</span>

                    <p class="summary-value"><?= $totalFornecedor ?> Compras</p>

                    <div class="summary-meta">

                        Pendências: <?= $totalPendencias ?> | Movimento do dia: <?= number_format($totalMovimentoHoje, 2, ',', '.'); ?> kg

                    </div>

                    <div class="summary-footnote">Fonte principal: <?= htmlspecialchars($maiorFonteEntrada) ?></div>

                </a>



                  <a class="summary-card" href="index.php">

                    <span class="summary-label"><i class="bi bi-graph-up-arrow"></i> Previs&atilde;o de Estoque</span>

                    <?php if (empty($totalEstoqueFuturo)): ?>

                    <p class="summary-value">Sem estoque futuro</p>

                    <?php else: ?>

                    <p class="summary-value"><?= number_format((float) $totalEstoqueFuturo, 2, ',', '.'); ?> kg previstos</p>

                    <?php endif; ?>

                     <div class="summary-footnote"><?= $cicloAtivo ? htmlspecialchars(getNomeCiclo($cicloAtivo)) : 'Sem ciclo ativo' ?></div>

                </a>

            </div>

        </section>



        <section class="stock-section" id="destino">



            <!-- Cabeçalho -->

            <div style="margin-bottom:20px;">

                <h2 class="est-title">Estoque por Produto</h2>

                <p class="est-subtitle">

                    <?= count($estoqueCalculado) ?> produto(s) ·

                    <?= $cicloAtivo ? htmlspecialchars(getNomeCiclo($cicloAtivo)) : 'Sem ciclo ativo' ?>

                </p>

            </div>



            <!-- Filtros de status + busca -->

            <div class="est-controls">

                <div class="est-tabs">

                    <button class="est-tab est-tab-active" data-status="todos"    onclick="filtrarEstoqueStatus('todos')">Todos (<?= count($estoqueCalculado) ?>)</button>

                    <button class="est-tab"                data-status="positivo" onclick="filtrarEstoqueStatus('positivo')">Positivo (<?= $contEmEstoque ?>)</button>

                    <button class="est-tab"                data-status="negativo" onclick="filtrarEstoqueStatus('negativo')">Negativo (<?= $contNegativo ?>)</button>

                    <button class="est-tab"                data-status="zerado"   onclick="filtrarEstoqueStatus('zerado')">Zerado (<?= $contZerado ?>)</button>

                </div>

                <div class="est-search">

                    <input type="text" id="pesquisa" onkeyup="pesquisarProduto()" placeholder="🔍  Buscar produto...">

                </div>

            </div>



            <!-- Tabela -->

            <div class="table-responsive">

                <table id="tabelaProdutos" data-no-responsive="1">

                    <thead>

                        <tr>

                            <th class="sort-th" onclick="sortEstoque('nome')">Produto <span class="sort-icon" data-col="nome">↕</span></th>

                            <th class="sort-th" onclick="sortEstoque('estoque')">Estoque Atual <span class="sort-icon" data-col="estoque">↕</span></th>

                            <th title="Compras futuras + colheitas futuras">Estoque Futuro<br><small>Compras + colheitas</small></th>

                            <th>Distribuição</th>

                            <th class="sort-th" onclick="sortEstoque('status')">Situação <span class="sort-icon" data-col="status">↕</span></th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($estoqueCalculado as $item):

                            $qtd = (float) $item['estoque'];

                            $qtdFuturo = (float) ($item['estoque_futuro'] ?? 0);

                            $pct = $maxEstoque > 0 ? min(100, (int) round((max(0, $qtd) / $maxEstoque) * 100)) : 0;

                            if ($qtd > 0) {

                                $statusKey   = 'positivo';

                                $statusLabel = 'Positivo';

                                $badgeClass  = 'badge-positivo';

                                $barClass    = 'bar-normal';

                            } elseif ($qtd < 0) {

                                $statusKey   = 'negativo';

                                $statusLabel = 'Negativo';

                                $badgeClass  = 'badge-negativo';

                                $barClass    = 'bar-critico';

                            } else {

                                $statusKey   = 'zerado';

                                $statusLabel = 'Zerado';

                                $badgeClass  = 'badge-zerado';

                                $barClass    = 'bar-atencao';

                            }

                        ?>

                        <tr data-nome="<?= htmlspecialchars(mb_strtolower($item['produto']), ENT_QUOTES, 'UTF-8') ?>"

                            data-estoque="<?= $qtd ?>"

                            data-status="<?= $statusKey ?>">

                            <td class="est-nome"><?= htmlspecialchars($item['produto']) ?></td>

                            <td class="est-qtd <?= $statusKey === 'negativo' ? 'est-qtd-critico' : '' ?>">

                                <?= number_format($qtd, 2, ',', '.') ?> <?= htmlspecialchars($item['unidade'] ?? 'kg') ?>

                            </td>

                            <td class="est-futuro" title="Compras futuras + colheitas futuras. Não soma estoque atual nem desconta vendas.">

                                <?= number_format($qtdFuturo, 2, ',', '.') ?> <?= htmlspecialchars($item['unidade'] ?? 'kg') ?>

                                <small>entrada futura</small>

                            </td>

                            <td class="est-bar-cell">

                                <div class="est-bar-bg">

                                    <div class="est-bar-fill <?= $barClass ?>" style="width:<?= $pct ?>%"></div>

                                </div>

                            </td>

                            <td><span class="est-badge <?= $badgeClass ?>"><?= $statusLabel ?></span></td>

                        </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>



            <!-- Paginação -->

            <div class="est-pagination">

                <button id="est-prev" class="est-page-btn" onclick="estoquePagAnterior()" disabled>← Anterior</button>

                <span id="est-page-info"></span>

                <button id="est-next" class="est-page-btn" onclick="estoquePagProxima()">Próxima →</button>

            </div>



        </section>



        <div class="exportar-box">

<!-- 

            <a href

            ="estoque/exportar_estoque_excel.php">

                <button class="btn-exportar">

                     Exportar Estoque para Excel

                </button>

            </a>



        </div> -->



                      <div class="exportar-box">



            <a href

            ="dashboard/actions/export_api.php">

                <button class="btn-exportar">

                    Gerar Relatórios Gerais

                </button>

            </a>



        </div>



                   <!-- /*formulário para gerar backup do banco de dados -->

                      <div class="exportar-box">

             









        </div>



<div class="exportar-box">

        <button onclick="gerarPDF()" class="btn-exportar">

     Gerar PDF da Tela

</button>

</div>



    </div>



</body>



</html>

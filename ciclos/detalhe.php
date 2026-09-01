<?php
require_once __DIR__ . "/../config/conexao.php";
require_once __DIR__ . "/../config/ciclo_helper.php";
require_once __DIR__ . "/../auth/proteger.php";
require_once __DIR__ . "/../config/permissions.php";
require_once __DIR__ . "/../config/layout_helper.php";

requireModule('ciclos', '../ciclos/index.php');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header("Location: index.php?msg=erro&detalhe=Ciclo+invalido");
    exit;
}

$stmtCiclo = $conexao->prepare("SELECT * FROM ciclos WHERE id = ? LIMIT 1");
$stmtCiclo->bind_param("i", $id);
$stmtCiclo->execute();
$ciclo = $stmtCiclo->get_result()->fetch_assoc();
$stmtCiclo->close();

if (!$ciclo) {
    header("Location: index.php?msg=erro&detalhe=Ciclo+nao+encontrado");
    exit;
}

$snapshot = null;
if (tabelaSnapshotDisponivel($conexao)) {
    $stmtSnapshot = $conexao->prepare("SELECT * FROM ciclo_snapshot WHERE ciclo_id = ? LIMIT 1");
    $stmtSnapshot->bind_param("i", $id);
    $stmtSnapshot->execute();
    $snapshot = $stmtSnapshot->get_result()->fetch_assoc() ?: null;
    $stmtSnapshot->close();
}

$statusCiclo = strtolower(trim((string) ($ciclo['status'] ?? '')));
$cicloAberto = ($statusCiclo === 'aberto');
$resumo = (!$cicloAberto && $snapshot) ? $snapshot : calcularResumoCiclo($conexao, $id);
if (tabelaExiste($conexao, 'ciclo_snapshot_registros')) {
    $stmtArquivoResumo = $conexao->prepare("\n        SELECT tipo, IFNULL(SUM(quantidade), 0) AS total, COUNT(*) AS qtd\n        FROM ciclo_snapshot_registros\n        WHERE ciclo_id = ? AND acao_fechamento = 'arquivar_zerar'\n        GROUP BY tipo\n    ");
    $stmtArquivoResumo->bind_param('i', $id);
    $stmtArquivoResumo->execute();
    $arquivoResumo = $stmtArquivoResumo->get_result();
    while ($linhaArquivo = $arquivoResumo->fetch_assoc()) {
        if ($linhaArquivo['tipo'] === 'venda') {
            $resumo['total_vendas_kg'] = $cicloAberto
                ? (float) ($resumo['total_vendas_kg'] ?? 0) + (float) $linhaArquivo['total']
                : (float) $linhaArquivo['total'];
            $resumo['qtd_vendas'] = $cicloAberto
                ? (int) ($resumo['qtd_vendas'] ?? 0) + (int) $linhaArquivo['qtd']
                : (int) $linhaArquivo['qtd'];
        } elseif ($linhaArquivo['tipo'] === 'entrada') {
            $resumo['total_entradas_kg'] = $cicloAberto
                ? (float) ($resumo['total_entradas_kg'] ?? 0) + (float) $linhaArquivo['total']
                : (float) $linhaArquivo['total'];
            $resumo['qtd_entradas'] = $cicloAberto
                ? (int) ($resumo['qtd_entradas'] ?? 0) + (int) $linhaArquivo['qtd']
                : (int) $linhaArquivo['qtd'];
        }
    }
    $stmtArquivoResumo->close();
}
$estoqueFinal = [];

if (!empty($snapshot['estoque_final_json'])) {
    $estoqueFinal = json_decode($snapshot['estoque_final_json'], true) ?: [];
} else {
    $estoqueFinal = $resumo['estoque_final'] ?? [];
}

$produtosResult = $conexao->query("SELECT id, nome FROM produtos ORDER BY nome");
$produtosList = [];
while ($p = $produtosResult->fetch_assoc()) {
    $produtosList[] = $p;
}

$clientesResult = $conexao->query("SELECT id, nome, documento, telefone, endereco FROM clientes ORDER BY nome");
$clientesList = [];
while ($c = $clientesResult->fetch_assoc()) {
    $clientesList[] = $c;
}

$filtroVendas = montarClausulaCiclo($conexao, 'vendas', 'v', $id);
$filtroEntradas = montarClausulaCiclo($conexao, 'entradas', 'e', $id);
$filtroColheitas = montarClausulaCiclo($conexao, 'previsao_colheita', 'pc', $id);
$filtroAbates = montarClausulaCiclo($conexao, 'abates', 'a', $id);
$abateOsFornecedorExpr = colunaExiste($conexao, 'previsao_fornecedor', 'numero_os') ? 'pf.numero_os' : 'NULL';
$abateOsColheitaExpr = colunaExiste($conexao, 'previsao_colheita', 'numero_os') ? 'pc.numero_os' : 'NULL';

$vendas = $conexao->query("
    SELECT v.*, p.nome AS produto_nome, c.nome AS cliente_nome
    FROM vendas v
    LEFT JOIN produtos p ON p.id = v.produto_id
    LEFT JOIN clientes c ON c.id = v.cliente_id
    WHERE 1 = 1 {$filtroVendas}
    ORDER BY v.data_venda DESC, v.id DESC
");

$entradas = $conexao->query("
    SELECT e.*, p.nome AS produto_nome, f.nome AS fornecedor_nome
    FROM entradas e
    LEFT JOIN produtos p ON p.id = e.produto_id
    LEFT JOIN fornecedores f ON f.id = e.fornecedor_id
    WHERE 1 = 1 {$filtroEntradas}
    ORDER BY e.data_entrada DESC, e.id DESC
");

$abates = $conexao->query("
    SELECT a.*, p.nome AS produto_nome, COALESCE({$abateOsFornecedorExpr}, {$abateOsColheitaExpr}) AS numero_os
    FROM abates a
    LEFT JOIN previsao_fornecedor pf ON a.tipo = 'fornecedor' AND pf.id = a.previsao_id
    LEFT JOIN previsao_colheita pc ON a.tipo = 'colheita' AND pc.id = a.previsao_id
    LEFT JOIN produtos p ON p.id = COALESCE(pf.produto_id, pc.produto_id)
    WHERE 1 = 1 {$filtroAbates}
    ORDER BY a.criado_em DESC, a.id DESC
");

$colheitasInfo = ['qtd' => 0, 'total' => 0.0];
$colheitasResult = $conexao->query("
    SELECT COUNT(*) AS qtd, COALESCE(SUM(pc.quantidade_prevista), 0) AS total
    FROM previsao_colheita pc
    WHERE 1 = 1 {$filtroColheitas}
");
if ($colheitasResult) {
    $colheitasInfo = $colheitasResult->fetch_assoc() ?: $colheitasInfo;
}

function cicloFetchAll(?mysqli_result $result): array
{
    $rows = [];
    if (!$result) {
        return $rows;
    }
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

function cicloAgruparPorOs(array $rows): array
{
    $grupos = [];
    foreach ($rows as $row) {
        $numeroOs = trim((string) ($row['numero_os'] ?? ''));
        if ($numeroOs !== '') {
            $key = $numeroOs;
            $label = 'OS ' . $numeroOs;
        } elseif (($row['tipo'] ?? '') === 'colheita') {
            $key = 'COLHEITA';
            $label = 'Colheita';
        } elseif (($row['tipo'] ?? '') === 'entrada_fornecedor') {
            $key = 'ENTRADA_FORNECEDOR_SEM_OS';
            $label = 'Entrada fornecedor sem OS';
        } else {
            $key = 'SEM_OS';
            $label = 'Sem OS';
        }
        if (!isset($grupos[$key])) {
            $grupos[$key] = [
                'numero_os' => $numeroOs,
                'label' => $label,
                'itens' => [],
                'quantidade' => 0.0,
                'valor' => 0.0,
            ];
        }
        $grupos[$key]['itens'][] = $row;
        $grupos[$key]['quantidade'] += (float) ($row['quantidade'] ?? $row['quantidade_abatida'] ?? 0);
        $grupos[$key]['valor'] += (float) ($row['preco'] ?? 0);
    }
    return $grupos;
}

$vendasLista = cicloFetchAll($vendas);
$entradasLista = cicloFetchAll($entradas);
$abatesLista = cicloFetchAll($abates);
$vendasPorOs = cicloAgruparPorOs($vendasLista);
$entradasPorOs = cicloAgruparPorOs($entradasLista);
$abatesPorOs = cicloAgruparPorOs($abatesLista);

// === LAYOUT NOVO (UX/UI): preparação de dados para os gráficos =================
// Camada de APRESENTAÇÃO. Reaproveita $vendasLista, $estoqueFinal e $resumo já
// carregados acima — nenhuma query/lógica nova.
$vpAgrupado = [];
foreach ($vendasLista as $rowVp) {
    $nomeVp = $rowVp['produto_nome'] ?: 'Sem produto';
    $vpAgrupado[$nomeVp] = ($vpAgrupado[$nomeVp] ?? 0) + (float) ($rowVp['quantidade'] ?? 0);
}
arsort($vpAgrupado);
$vpLabels = [];
$vpData = [];
foreach (array_slice($vpAgrupado, 0, 7, true) as $nomeVp => $kgVp) {
    $vpLabels[] = $nomeVp;
    $vpData[]   = round((float) $kgVp, 2);
}

$efLabels = [];
$efData = [];
foreach ($estoqueFinal as $itemEf) {
    $nomeEf = $itemEf['produto'] ?? null;
    $kgEf   = (float) ($itemEf['estoque'] ?? 0);
    if ($nomeEf !== null && $kgEf > 0) {
        $efLabels[] = $nomeEf;
        $efData[]   = round($kgEf, 2);
    }
}
$efLabels = array_slice($efLabels, 0, 7);
$efData   = array_slice($efData, 0, 7);

$propTotais = [
    round((float) ($resumo['total_vendas_kg'] ?? 0), 2),
    round((float) ($resumo['total_entradas_kg'] ?? 0), 2),
    round((float) ($resumo['total_abates_kg'] ?? 0), 2),
];
$temGraficos = !empty($vpData) || !empty($efData) || array_sum($propTotais) > 0;

// LAYOUT NOVO (UX/UI) — correção #2: para ciclo FECHADO, as abas leem do arquivo
// histórico (ciclo_snapshot_registros), pois as tabelas operacionais já foram
// zeradas no fechamento. O ciclo ABERTO continua usando as queries ao vivo acima.
$arqVendasPorOs = [];
$arqEntradasPorOs = [];
$arqAbatesPorOs = [];
$arqDisponivel = false;
if (tabelaExiste($conexao, 'ciclo_snapshot_registros')) {
    $chkArq = $conexao->query("SHOW TABLES LIKE 'ciclo_snapshot_registros'");
    $arqDisponivel = ($chkArq && $chkArq->num_rows > 0);
    if ($arqDisponivel) {
        $agruparArquivo = static function (mysqli $conexao, int $cicloId, string $tipo) use ($cicloAberto): array {
            $grupos = [];
            $stmt = $conexao->prepare("
                SELECT numero_os, produto_nome, pessoa_nome, data_registro, quantidade, valor, status
                FROM ciclo_snapshot_registros
                WHERE ciclo_id = ? AND tipo = ?" . ($cicloAberto ? " AND acao_fechamento = 'arquivar_zerar'" : '') . "
                ORDER BY numero_os ASC, data_registro DESC, id DESC
            ");
            $stmt->bind_param('is', $cicloId, $tipo);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $os = trim((string) ($row['numero_os'] ?? ''));
                $key = $os !== '' ? $os : 'SEM_OS';
                if (!isset($grupos[$key])) {
                    $grupos[$key] = [
                        'label' => $os !== '' ? ('OS ' . $os) : 'Sem OS',
                        'itens' => [],
                        'quantidade' => 0.0,
                        'valor' => 0.0,
                    ];
                }
                $grupos[$key]['itens'][] = $row;
                $grupos[$key]['quantidade'] += (float) ($row['quantidade'] ?? 0);
                $grupos[$key]['valor'] += (float) ($row['valor'] ?? 0);
            }
            $stmt->close();
            return $grupos;
        };
        $arqVendasPorOs   = $agruparArquivo($conexao, (int) $id, 'venda');
        $arqEntradasPorOs = $agruparArquivo($conexao, (int) $id, 'entrada');
        $arqAbatesPorOs   = $agruparArquivo($conexao, (int) $id, 'abate');
    }
}
if ($cicloAberto) {
    foreach ($arqVendasPorOs as $grupoArquivo) {
        foreach ($grupoArquivo['itens'] as $linhaArquivo) {
            $nomeArquivo = $linhaArquivo['produto_nome'] ?: 'Sem produto';
            $vpAgrupado[$nomeArquivo] = ($vpAgrupado[$nomeArquivo] ?? 0) + (float) ($linhaArquivo['quantidade'] ?? 0);
        }
    }
    arsort($vpAgrupado);
    $vpLabels = [];
    $vpData = [];
    foreach (array_slice($vpAgrupado, 0, 7, true) as $nomeVp => $kgVp) {
        $vpLabels[] = $nomeVp;
        $vpData[] = round((float) $kgVp, 2);
    }
    $temGraficos = !empty($vpData) || !empty($efData) || array_sum($propTotais) > 0;
}
// === FIM da preparação de dados ===============================================
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalhe do Ciclo</title>
    <!-- LAYOUT NOVO (UX/UI): Chart.js 4.4.0 — mesma versão já usada no sistema -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        /* ===== LAYOUT NOVO (UX/UI) — escopo .cic-det; paleta ORIGINAL do sistema ===== */
        .cic-det{
            --g50:#f1f8f4;--g100:#dcefe1;--g200:#a5d6a7;--g300:#8cc79e;--g500:#3f9357;
            --g600:#2e7d32;--g700:#1f6d23;--g800:#1b5e20;
            --ink-1:#222;--ink-2:#3a3f3a;--ink-3:#5F5E5A;--ink-4:#9ba29a;
            --line-1:#e2e5df;--line-2:#eef0ec;--surf:#fff;--surf-2:#fbfcfb;
            --sh-1:0 1px 2px rgba(20,24,20,.05),0 1px 1px rgba(20,24,20,.03);
            --sh-2:0 2px 8px rgba(20,24,20,.07);--sh-3:0 8px 24px rgba(20,24,20,.10);
            --r:12px;--ease:cubic-bezier(.2,0,0,1);
            max-width:1320px;margin:0 auto;color:var(--ink-1);font-family:'Segoe UI',Arial,sans-serif;font-size:14px;
        }
        .cic-det *{box-sizing:border-box;}
        .cic-det h1,.cic-det h2,.cic-det h3,.cic-det p{margin:0;}

        .cd-back{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--ink-3);cursor:pointer;text-decoration:none;margin-bottom:14px;}
        .cd-back:hover{color:var(--g700);}

        .cd-head{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin-bottom:18px;flex-wrap:wrap;}
        .cd-eyebrow{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-3);}
        .cd-title-row{display:flex;align-items:center;gap:12px;margin-top:6px;flex-wrap:wrap;}
        .cd-title-row h1{font-size:28px;font-weight:700;letter-spacing:-.01em;}
        .cd-status{display:inline-flex;align-items:center;gap:6px;padding:4px 11px;border-radius:999px;font-size:12px;font-weight:600;border:1px solid var(--g200);background:var(--g50);color:var(--g800);}
        .cd-status .dot{width:7px;height:7px;border-radius:50%;background:var(--g600);}
        .cd-status.fechado{border-color:#cdd3cb;background:#f3f5f2;color:var(--ink-2);}
        .cd-status.fechado .dot{background:var(--ink-4);}

        .cd-btn{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border-radius:8px;background:var(--surf);border:1px solid var(--line-1);color:var(--ink-1);font:600 14px 'Segoe UI',Arial,sans-serif;cursor:pointer;text-decoration:none;transition:background .15s var(--ease);}
        .cd-btn:hover{background:#f4f6f4;}

        /* KPIs */
        .cd-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:18px;}
        .cd-kpi{background:var(--surf);border:1px solid var(--line-1);border-radius:var(--r);padding:18px 20px;box-shadow:var(--sh-1);}
        .cd-kpi-top{display:flex;justify-content:space-between;align-items:center;}
        .cd-kpi-label{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-3);}
        .cd-kpi-ic{width:30px;height:30px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:15px;}
        .cd-kpi-val{font-size:28px;font-weight:700;color:var(--g800);margin-top:10px;font-variant-numeric:tabular-nums;}
        .cd-kpi-val small{font-size:13px;color:var(--ink-3);font-weight:500;margin-left:4px;}
        .cd-kpi-sub{font-size:12px;color:var(--ink-3);margin-top:6px;}
        .cd-kpi-link{display:inline-flex;align-items:center;gap:6px;margin-top:12px;font-size:12px;font-weight:600;color:var(--g700);text-decoration:none;}
        .cd-kpi-link:hover{color:var(--g800);}

        /* gráficos */
        .cd-charts3{display:grid;grid-template-columns:1.3fr 1fr 1fr;gap:18px;margin-bottom:18px;}
        .cd-card{background:var(--surf);border:1px solid var(--line-1);border-radius:var(--r);box-shadow:var(--sh-1);}
        .cd-card-h{padding:18px 22px 6px;}
        .cd-card-h h3{font-size:16px;font-weight:600;}
        .cd-card-h p{margin-top:4px;color:var(--ink-3);font-size:12px;}
        .cd-canvas{padding:8px 18px 18px;height:248px;}

        /* tabs */
        .cd-tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;}
        .cd-tab{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:8px;background:var(--surf);border:1px solid var(--line-1);color:var(--ink-2);font:600 13px 'Segoe UI',Arial,sans-serif;cursor:pointer;transition:all .15s var(--ease);}
        .cd-tab:hover{background:#f4f6f4;}
        .cd-tab.active{background:var(--g800);border-color:var(--g800);color:#fff;}
        .cd-tab .cnt{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:18px;padding:0 6px;border-radius:999px;font-size:11px;font-weight:700;background:var(--line-2);color:var(--ink-2);}
        .cd-tab.active .cnt{background:rgba(255,255,255,.22);color:#fff;}
        .cd-panel{margin-bottom:18px;}

        /* tabelas (escopo .cic-det) */
        .cd-tablecard{background:var(--surf);border:1px solid var(--line-1);border-radius:var(--r);box-shadow:var(--sh-1);overflow:hidden;}
        .cic-det .table-container{overflow-x:auto;}
        .cic-det table{width:100%;min-width:680px;border-collapse:collapse;background:var(--surf);box-shadow:none;border-radius:0;}
        .cic-det table th{background:#f6f7f5;color:var(--ink-2);text-align:left;font-weight:600;font-size:11px;letter-spacing:.06em;text-transform:uppercase;padding:11px 16px;border-bottom:1px solid var(--line-1);}
        .cic-det table td{padding:12px 16px;text-align:left;border-bottom:1px solid var(--line-2);font-size:13px;}
        .cic-det table tr:hover{background:var(--g50);}
        .cic-det .empty{color:var(--ink-3);font-style:italic;padding:14px 4px;}

        /* botões de ação (cores originais preservadas) */
        .btn-edit,.btn-delete,.btn-action{border:none;min-height:32px;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;line-height:1;}
        .btn-edit,.btn-action.primary{background:#1565c0;color:#fff;}
        .btn-edit:hover,.btn-action.primary:hover{background:#0d47a1;}
        .btn-delete,.btn-action.danger{background:#c62828;color:#fff;}
        .btn-delete:hover,.btn-action.danger:hover{background:#b71c1c;}
        .acoes-cell{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
        .acoes-cell form{margin:0;}

        /* os-groups (acordeão por OS) */
        .os-groups{display:grid;gap:12px;}
        .os-group{border:1px solid var(--line-1);border-radius:var(--r);background:var(--surf);box-shadow:var(--sh-1);overflow:hidden;}
        .os-group summary{list-style:none;display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 18px;cursor:pointer;color:var(--g800);font-weight:700;}
        .os-group summary:hover{background:var(--g50);}
        .os-group summary::-webkit-details-marker{display:none;}
        .os-group summary::after{content:'\F285';font-family:'bootstrap-icons';font-size:12px;color:var(--ink-3);font-weight:400;transition:transform .2s ease;}
        .os-group[open] summary::after{transform:rotate(90deg);}
        .os-title{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
        .os-label{font-size:14px;}
        .os-meta{color:var(--ink-3);font-size:12px;font-weight:600;}
        .os-total{margin-left:auto;color:var(--g800);font-size:14px;font-weight:700;white-space:nowrap;font-variant-numeric:tabular-nums;}
        .os-body{padding:0;border-top:1px solid var(--line-2);}
        .compact-table table{min-width:760px;border:0;}

        /* modal (estrutura/IDs preservados; visual modernizado) */
        .modal-bg{display:none;position:fixed;inset:0;background:rgba(20,24,20,.45);z-index:1000;justify-content:center;align-items:center;padding:24px;}
        .modal-bg.aberto{display:flex;}
        .modal-box{background:#fff;border-radius:16px;padding:0;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 24px 48px rgba(20,24,20,.18);}
        .modal-box h3{margin:0;font-size:18px;color:var(--ink-1);font-weight:600;padding:18px 22px;border-bottom:1px solid var(--line-2);}
        .modal-box form{padding:22px;}
        .form-group{display:flex;flex-direction:column;gap:6px;margin-bottom:14px;}
        .form-group label{font-size:12px;font-weight:600;color:var(--ink-2);}
        .form-group input,.form-group select{padding:9px 12px;border:1px solid var(--line-1);border-radius:8px;font-size:14px;background:var(--surf);}
        .form-group input:read-only{background:#f5f5f5;}

        @media (max-width:1024px){.cd-charts3{grid-template-columns:1fr;}}
        @media (max-width:768px){
            .cd-kpis{grid-template-columns:1fr;}
            .cd-head{flex-direction:column;align-items:flex-start;}
        }
    </style>
    <?php renderAppLayoutStyles(); ?>
</head>
<body>
    <?php renderAppHeader('..'); ?>

    <div class="container page-container app-shell">
    <!-- LAYOUT NOVO (UX/UI): wrapper de escopo do detalhe -->
    <div class="cic-det">

        <a class="cd-back" href="index.php"><i class="bi bi-arrow-left"></i>Voltar para Ciclos</a>

        <!-- LAYOUT NOVO (UX/UI): cabeçalho do ciclo + status + auditar -->
        <div class="cd-head">
            <div>
                <span class="cd-eyebrow">Ciclo</span>
                <div class="cd-title-row">
                    <h1><?= htmlspecialchars(getNomeCiclo($ciclo)) ?></h1>
                    <span class="cd-status <?= $cicloAberto ? '' : 'fechado' ?>"><span class="dot"></span><?= $cicloAberto ? 'Aberto' : htmlspecialchars(ucfirst($ciclo['status'] ?? 'Fechado')) ?></span>
                </div>
            </div>
            <a class="cd-btn" href="<?= $cicloAberto ? 'registros.php?ciclo_id=' . (int) $id : 'arquivados.php?ciclo_id=' . (int) $id ?>"><i class="bi bi-list-check"></i>Auditar registros</a>
        </div>

        <!-- LAYOUT NOVO (UX/UI): KPI cards (dados de $resumo) -->
        <div class="cd-kpis">
            <div class="cd-kpi">
                <div class="cd-kpi-top">
                    <span class="cd-kpi-label">Vendas</span>
                    <div class="cd-kpi-ic" style="background:#e8f5e9;color:#2e7d32;"><i class="bi bi-cart-check"></i></div>
                </div>
                <div class="cd-kpi-val"><?= number_format((float) ($resumo['total_vendas_kg'] ?? 0), 2, ',', '.') ?><small>kg</small></div>
                <div class="cd-kpi-sub"><?= (int) ($resumo['qtd_vendas'] ?? 0) ?> venda(s) registrada(s)</div>
                <a class="cd-kpi-link" href="<?= $cicloAberto ? 'vendas/?ciclo_id=' . (int) $id : 'arquivados.php?ciclo_id=' . (int) $id . '&tipo=venda' ?>"><i class="bi bi-search"></i>Auditar vendas</a>
            </div>
            <div class="cd-kpi">
                <div class="cd-kpi-top">
                    <span class="cd-kpi-label">Entradas</span>
                    <div class="cd-kpi-ic" style="background:#f5e8db;color:#a05a2c;"><i class="bi bi-box-arrow-in-down"></i></div>
                </div>
                <div class="cd-kpi-val"><?= number_format((float) ($resumo['total_entradas_kg'] ?? 0), 2, ',', '.') ?><small>kg</small></div>
                <div class="cd-kpi-sub"><?= (int) ($resumo['qtd_entradas'] ?? 0) ?> entrada(s) registrada(s)</div>
                <a class="cd-kpi-link" href="<?= $cicloAberto ? 'entradas/?ciclo_id=' . (int) $id : 'arquivados.php?ciclo_id=' . (int) $id . '&tipo=entrada' ?>"><i class="bi bi-search"></i>Auditar entradas</a>
            </div>
            <div class="cd-kpi">
                <div class="cd-kpi-top">
                    <span class="cd-kpi-label">Colheitas</span>
                    <div class="cd-kpi-ic" style="background:#e8f5e9;color:#3f9357;"><i class="bi bi-basket"></i></div>
                </div>
                <div class="cd-kpi-val"><?= number_format((float) ($colheitasInfo['total'] ?? 0), 2, ',', '.') ?><small>kg</small></div>
                <div class="cd-kpi-sub"><?= (int) ($colheitasInfo['qtd'] ?? 0) ?> colheita(s) registrada(s)</div>
                <a class="cd-kpi-link" href="<?= $cicloAberto ? 'colheitas/?ciclo_id=' . (int) $id : 'arquivados.php?ciclo_id=' . (int) $id . '&tipo=colheita' ?>"><i class="bi bi-search"></i>Auditar colheitas</a>
            </div>
            <div class="cd-kpi">
                <div class="cd-kpi-top">
                    <span class="cd-kpi-label">Abates</span>
                    <div class="cd-kpi-ic" style="background:#fbe6e3;color:#a93b2e;"><i class="bi bi-exclamation-triangle"></i></div>
                </div>
                <div class="cd-kpi-val"><?= number_format((float) ($resumo['total_abates_kg'] ?? 0), 2, ',', '.') ?><small>kg</small></div>
                <div class="cd-kpi-sub"><?= (int) ($resumo['qtd_abates'] ?? 0) ?> abate(s) registrado(s)</div>
                <a class="cd-kpi-link" href="<?= $cicloAberto ? 'abates/?ciclo_id=' . (int) $id : 'arquivados.php?ciclo_id=' . (int) $id . '&tipo=abate' ?>"><i class="bi bi-search"></i>Auditar abates</a>
            </div>
        </div>

        <?php if ($temGraficos): ?>
            <!-- LAYOUT NOVO (UX/UI): gráficos do ciclo (dados reais já carregados) -->
            <div class="cd-charts3">
                <div class="cd-card">
                    <div class="cd-card-h"><h3>Vendas por produto</h3><p>kg no ciclo</p></div>
                    <div class="cd-canvas">
                        <?php if (!empty($vpData)): ?><canvas id="chartVendasProduto"></canvas><?php else: ?><p class="empty">Sem vendas no ciclo.</p><?php endif; ?>
                    </div>
                </div>
                <div class="cd-card">
                    <div class="cd-card-h"><h3>Estoque final</h3><p>por produto</p></div>
                    <div class="cd-canvas">
                        <?php if (!empty($efData)): ?><canvas id="chartEstoqueFinal"></canvas><?php else: ?><p class="empty">Sem estoque final.</p><?php endif; ?>
                    </div>
                </div>
                <div class="cd-card">
                    <div class="cd-card-h"><h3>Proporção do ciclo</h3><p>vendas · entradas · abates</p></div>
                    <div class="cd-canvas"><canvas id="chartProporcao"></canvas></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- LAYOUT NOVO (UX/UI): abas (substituem os dropdowns; mesmos dados) -->
        <div class="cd-tabs">
            <button type="button" class="cd-tab active" data-tab="estoque" onclick="cdShowTab(this,'estoque')"><i class="bi bi-box-seam"></i>Estoque Final</button>
<button type="button" class="cd-tab" data-tab="vendas" onclick="cdShowTab(this,'vendas')"><i class="bi bi-cart-check"></i>Vendas <span class="cnt"><?= $cicloAberto ? count($vendasPorOs) + count($arqVendasPorOs) : count($arqVendasPorOs) ?></span></button>
<button type="button" class="cd-tab" data-tab="entradas" onclick="cdShowTab(this,'entradas')"><i class="bi bi-box-arrow-in-down"></i>Entradas <span class="cnt"><?= $cicloAberto ? count($entradasPorOs) + count($arqEntradasPorOs) : count($arqEntradasPorOs) ?></span></button>
            <button type="button" class="cd-tab" data-tab="abates" onclick="cdShowTab(this,'abates')"><i class="bi bi-exclamation-triangle"></i>Abates <span class="cnt"><?= $cicloAberto ? count($abatesPorOs) : count($arqAbatesPorOs) ?></span></button>
        </div>

        <!-- ESTOQUE FINAL -->
        <div id="panel-estoque" class="cd-panel">
            <div class="cd-tablecard">
                <div class="table-container">
                    <table data-no-responsive="1">
                        <tr>
                            <th>Produto</th>
                            <th>Estoque</th>
                            <th>Unidade</th>
                        </tr>
                        <?php if (!empty($estoqueFinal)): ?>
                            <?php foreach ($estoqueFinal as $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['produto'] ?? '-') ?></td>
                                    <td><?= number_format((float) ($item['estoque'] ?? 0), 2, ',', '.') ?></td>
                                    <td><?= htmlspecialchars($item['unidade'] ?? 'kg') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="empty">Nenhum estoque final registrado para este ciclo.</td></tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- VENDAS -->
        <div id="panel-vendas" class="cd-panel" style="display:none;">
            <div class="os-groups">
                <?php if ($cicloAberto && !empty($arqVendasPorOs)): ?>
                    <?php foreach ($arqVendasPorOs as $grupo): ?>
                        <details class="os-group">
                            <summary><span class="os-title"><span class="os-label"><?= htmlspecialchars($grupo['label']) ?></span><span class="os-meta"><?= count($grupo['itens']) ?> venda(s) · arquivado</span></span><span class="os-total"><?= number_format((float) $grupo['quantidade'], 2, ',', '.') ?> kg</span></summary>
                            <div class="os-body"><div class="table-container compact-table"><table data-no-responsive="1"><tr><th>Produto</th><th>Cliente</th><th>Data</th><th>Quantidade</th><th>Preço</th><th>Status</th></tr>
                            <?php foreach ($grupo['itens'] as $linha): ?><tr><td><?= htmlspecialchars($linha['produto_nome'] ?? '-') ?></td><td><?= htmlspecialchars($linha['pessoa_nome'] ?? '-') ?></td><td><?= !empty($linha['data_registro']) ? date('d/m/Y', strtotime($linha['data_registro'])) : '-' ?></td><td class="num"><?= number_format((float) ($linha['quantidade'] ?? 0), 2, ',', '.') ?> kg</td><td class="num"><?= $linha['valor'] !== null ? 'R$ ' . number_format((float) $linha['valor'], 2, ',', '.') : '-' ?></td><td><?= htmlspecialchars($linha['status'] ?? '-') ?></td></tr><?php endforeach; ?>
                            </table></div></div>
                        </details>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if (!$cicloAberto): /* CICLO FECHADO: lê do arquivo histórico (read-only) */ ?>
                    <?php if (!empty($arqVendasPorOs)): ?>
                        <?php foreach ($arqVendasPorOs as $grupo): ?>
                            <details class="os-group">
                                <summary>
                                    <span class="os-title">
                                        <span class="os-label"><?= htmlspecialchars($grupo['label']) ?></span>
                                        <span class="os-meta"><?= count($grupo['itens']) ?> venda(s) · arquivado</span>
                                    </span>
                                    <span class="os-total"><?= number_format((float) $grupo['quantidade'], 2, ',', '.') ?> kg</span>
                                </summary>
                                <div class="os-body">
                                    <div class="table-container compact-table">
                                        <table data-no-responsive="1">
                                            <tr><th>Produto</th><th>Cliente</th><th>Data</th><th>Quantidade</th><th>Valor</th><th>Status</th></tr>
                                            <?php foreach ($grupo['itens'] as $linha): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($linha['produto_nome'] ?? '-') ?></td>
                                                    <td><?= htmlspecialchars($linha['pessoa_nome'] ?? '-') ?></td>
                                                    <td><?= !empty($linha['data_registro']) ? date('d/m/Y', strtotime($linha['data_registro'])) : '-' ?></td>
                                                    <td class="num"><?= number_format((float) ($linha['quantidade'] ?? 0), 2, ',', '.') ?> kg</td>
                                                    <td class="num"><?= $linha['valor'] !== null ? 'R$ ' . number_format((float) $linha['valor'], 2, ',', '.') : '-' ?></td>
                                                    <td><?= htmlspecialchars($linha['status'] ?? '-') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </table>
                                    </div>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty"><?= $arqDisponivel ? 'Nenhuma venda arquivada para este ciclo.' : 'Arquivo histórico indisponível.' ?></div>
                    <?php endif; ?>
                <?php elseif (!empty($vendasPorOs)): ?>
                    <?php foreach ($vendasPorOs as $grupo): ?>
                        <details class="os-group">
                            <summary>
                                <span class="os-title">
                                    <span class="os-label"><?= htmlspecialchars($grupo['label']) ?></span>
                                    <span class="os-meta"><?= count($grupo['itens']) ?> venda(s)</span>
                                </span>
                                <span class="os-total"><?= number_format((float) $grupo['quantidade'], 2, ',', '.') ?> kg</span>
                            </summary>
                            <div class="os-body">
                                <div class="table-container compact-table">
                                    <table data-no-responsive="1">
                                        <tr>
                                            <th>ID</th>
                                            <th>Produto</th>
                                            <th>Cliente</th>
                                            <th>Tipo</th>
                                            <th>Quantidade</th>
                                            <th>Preço</th>
                                            <th>Status</th>
                                            <th>Data</th>
                                            <th>Ações</th>
                                        </tr>
                                        <?php foreach ($grupo['itens'] as $linha): ?>
                                            <?php
                                                $precoManualCiclo = !empty($linha['preco_manual']) && (int) $linha['preco_manual'] === 1;
                                                $itemJson = htmlspecialchars(json_encode([
                                                    'id'             => (int) $linha['id'],
                                                    'produto_id'     => (int) ($linha['produto_id'] ?? 0),
                                                    'cliente_id'     => (int) ($linha['cliente_id'] ?? 0),
                                                    'tipo'           => $linha['tipo'] ?? 'kg',
                                                    'tipo_comercial' => $linha['tipo_comercial'] ?? '',
                                                    'prazo_escolhido'=> $linha['prazo_escolhido'] ?? '',
                                                    'pedido'         => $linha['pedido'] ?? 0,
                                                    'preco'          => $linha['preco'] ?? 0,
                                                    'peso_unitario'  => $linha['kg_caixa'] ?? ($linha['gramagem'] ?? ''),
                                                    'data_venda'     => $linha['data_venda'] ?? '',
                                                ]), ENT_QUOTES);
                                            ?>
                                            <tr>
                                                <td><?= (int) $linha['id'] ?></td>
                                                <td><?= htmlspecialchars($linha['produto_nome'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($linha['cliente_nome'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($linha['tipo'] ?? '-') ?></td>
                                                <td><?= number_format((float) $linha['quantidade'], 2, ',', '.') ?> kg</td>
                                                <td<?= $precoManualCiclo ? ' style="color:#c62828;font-weight:700;"' : '' ?>>
                                                    R$ <?= number_format((float) ($linha['preco'] ?? 0), 2, ',', '.') ?>
                                                    <?php if ($precoManualCiclo): ?><span title="Preço inserido manualmente" style="font-size:11px;margin-left:3px;">✎</span><?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($linha['status'] ?? '-') ?></td>
                                                <td><?= !empty($linha['data_venda']) ? date('d/m/Y', strtotime($linha['data_venda'])) : '-' ?></td>
                                                <td>
                                                    <div class="acoes-cell">
                                                        <button type="button" class="btn-action primary" onclick='abrirModalEditarVenda(<?= $itemJson ?>)'>Editar</button>
                                                        <form action="../vendas/actions/excluir_venda_pendente.php" method="POST"
                                                              onsubmit="return confirm('Deseja excluir esta venda?');">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
                                                            <input type="hidden" name="id" value="<?= (int) $linha['id'] ?>">
                                                            <input type="hidden" name="redirect_to" value="../../ciclos/detalhe.php?id=<?= $id ?>">
                                                            <button type="submit" class="btn-action danger">Excluir</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            </div>
                        </details>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty">Nenhuma venda encontrada para este ciclo.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ENTRADAS -->
        <div id="panel-entradas" class="cd-panel" style="display:none;">
            <div class="os-groups">
                <?php if ($cicloAberto && !empty($arqEntradasPorOs)): ?>
                    <?php foreach ($arqEntradasPorOs as $grupo): ?>
                        <details class="os-group">
                            <summary><span class="os-title"><span class="os-label"><?= htmlspecialchars($grupo['label']) ?></span><span class="os-meta"><?= count($grupo['itens']) ?> entrada(s) · arquivado</span></span><span class="os-total"><?= number_format((float) $grupo['quantidade'], 2, ',', '.') ?> kg</span></summary>
                            <div class="os-body"><div class="table-container compact-table"><table data-no-responsive="1"><tr><th>Produto</th><th>Fornecedor</th><th>Data</th><th>Quantidade</th></tr>
                            <?php foreach ($grupo['itens'] as $linha): ?><tr><td><?= htmlspecialchars($linha['produto_nome'] ?? '-') ?></td><td><?= htmlspecialchars($linha['pessoa_nome'] ?? '-') ?></td><td><?= !empty($linha['data_registro']) ? date('d/m/Y', strtotime($linha['data_registro'])) : '-' ?></td><td class="num"><?= number_format((float) ($linha['quantidade'] ?? 0), 2, ',', '.') ?> kg</td></tr><?php endforeach; ?>
                            </table></div></div>
                        </details>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if (!$cicloAberto): /* CICLO FECHADO: lê do arquivo histórico (read-only) */ ?>
                    <?php if (!empty($arqEntradasPorOs)): ?>
                        <?php foreach ($arqEntradasPorOs as $grupo): ?>
                            <details class="os-group">
                                <summary>
                                    <span class="os-title">
                                        <span class="os-label"><?= htmlspecialchars($grupo['label']) ?></span>
                                        <span class="os-meta"><?= count($grupo['itens']) ?> entrada(s) · arquivado</span>
                                    </span>
                                    <span class="os-total"><?= number_format((float) $grupo['quantidade'], 2, ',', '.') ?> kg</span>
                                </summary>
                                <div class="os-body">
                                    <div class="table-container compact-table">
                                        <table data-no-responsive="1">
                                            <tr><th>Produto</th><th>Origem</th><th>Data</th><th>Quantidade</th></tr>
                                            <?php foreach ($grupo['itens'] as $linha): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($linha['produto_nome'] ?? '-') ?></td>
                                                    <td><?= htmlspecialchars($linha['pessoa_nome'] ?? '-') ?></td>
                                                    <td><?= !empty($linha['data_registro']) ? date('d/m/Y', strtotime($linha['data_registro'])) : '-' ?></td>
                                                    <td class="num"><?= number_format((float) ($linha['quantidade'] ?? 0), 2, ',', '.') ?> kg</td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </table>
                                    </div>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty"><?= $arqDisponivel ? 'Nenhuma entrada arquivada para este ciclo.' : 'Arquivo histórico indisponível.' ?></div>
                    <?php endif; ?>
                <?php elseif (!empty($entradasPorOs)): ?>
                    <?php foreach ($entradasPorOs as $grupo): ?>
                        <details class="os-group">
                            <summary>
                                <span class="os-title">
                                    <span class="os-label"><?= htmlspecialchars($grupo['label']) ?></span>
                                    <span class="os-meta"><?= count($grupo['itens']) ?> entrada(s)</span>
                                </span>
                                <span class="os-total"><?= number_format((float) $grupo['quantidade'], 2, ',', '.') ?> kg</span>
                            </summary>
                            <div class="os-body">
                                <div class="table-container compact-table">
                                    <table data-no-responsive="1">
                                        <tr>
                                            <th>ID</th>
                                            <th>Produto</th>
                                            <th>Fornecedor</th>
                                            <th>Tipo</th>
                                            <th>Quantidade</th>
                                            <th>Data</th>
                                        </tr>
                                        <?php foreach ($grupo['itens'] as $linha): ?>
                                            <tr>
                                                <td><?= (int) $linha['id'] ?></td>
                                                <td><?= htmlspecialchars($linha['produto_nome'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($linha['fornecedor_nome'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($linha['tipo'] ?? '-') ?></td>
                                                <td><?= number_format((float) $linha['quantidade'], 2, ',', '.') ?> kg</td>
                                                <td><?= !empty($linha['data_entrada']) ? date('d/m/Y H:i', strtotime($linha['data_entrada'])) : '-' ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            </div>
                        </details>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty">Nenhuma entrada encontrada para este ciclo.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ABATES -->
        <div id="panel-abates" class="cd-panel" style="display:none;">
            <div class="os-groups">
                <?php if (!$cicloAberto): /* CICLO FECHADO: lê do arquivo histórico (read-only) */ ?>
                    <?php if (!empty($arqAbatesPorOs)): ?>
                        <?php foreach ($arqAbatesPorOs as $grupo): ?>
                            <details class="os-group">
                                <summary>
                                    <span class="os-title">
                                        <span class="os-label"><?= htmlspecialchars($grupo['label']) ?></span>
                                        <span class="os-meta"><?= count($grupo['itens']) ?> abate(s) · arquivado</span>
                                    </span>
                                    <span class="os-total"><?= number_format((float) $grupo['quantidade'], 2, ',', '.') ?> kg abatidos</span>
                                </summary>
                                <div class="os-body">
                                    <div class="table-container compact-table">
                                        <table data-no-responsive="1">
                                            <tr><th>Produto</th><th>Origem</th><th>Data</th><th>Qtd. abatida</th></tr>
                                            <?php foreach ($grupo['itens'] as $linha): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($linha['produto_nome'] ?? '-') ?></td>
                                                    <td><?= htmlspecialchars($linha['pessoa_nome'] ?? '-') ?></td>
                                                    <td><?= !empty($linha['data_registro']) ? date('d/m/Y', strtotime($linha['data_registro'])) : '-' ?></td>
                                                    <td class="num"><?= number_format((float) ($linha['quantidade'] ?? 0), 2, ',', '.') ?> kg</td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </table>
                                    </div>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty"><?= $arqDisponivel ? 'Nenhum abate arquivado para este ciclo.' : 'Arquivo histórico indisponível.' ?></div>
                    <?php endif; ?>
                <?php elseif (!empty($abatesPorOs)): ?>
                    <?php foreach ($abatesPorOs as $grupo): ?>
                        <details class="os-group">
                            <summary>
                                <span class="os-title">
                                    <span class="os-label"><?= htmlspecialchars($grupo['label']) ?></span>
                                    <span class="os-meta"><?= count($grupo['itens']) ?> abate(s)</span>
                                </span>
                                <span class="os-total"><?= number_format((float) $grupo['quantidade'], 2, ',', '.') ?> kg abatidos</span>
                            </summary>
                            <div class="os-body">
                                <div class="table-container compact-table">
                                    <table data-no-responsive="1">
                                        <tr>
                                            <th>ID</th>
                                            <th>Tipo</th>
                                            <th>Produto</th>
                                            <th>Qtd. original</th>
                                            <th>Qtd. abatida</th>
                                            <th>Qtd. final</th>
                                            <th>Motivo</th>
                                        </tr>
                                        <?php foreach ($grupo['itens'] as $linha): ?>
                                            <tr>
                                                <td><?= (int) $linha['id'] ?></td>
                                                <td><?= htmlspecialchars($linha['tipo'] ?? '-') ?></td>
                                                <td><?= htmlspecialchars($linha['produto_nome'] ?? '-') ?></td>
                                                <td><?= number_format((float) $linha['quantidade_original'], 2, ',', '.') ?></td>
                                                <td><?= number_format((float) $linha['quantidade_abatida'], 2, ',', '.') ?></td>
                                                <td><?= number_format((float) $linha['quantidade_final'], 2, ',', '.') ?></td>
                                                <td><?= htmlspecialchars($linha['motivo'] ?? '-') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            </div>
                        </details>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty">Nenhum abate encontrado para este ciclo.</div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /.cic-det -->
    </div>

    <div id="modalEditarVenda" class="modal-bg">
        <div class="modal-box">
            <h3>Editar Venda</h3>
            <form action="../vendas/actions/editar_venda.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
                <input type="hidden" name="id" id="edit_id">
                <input type="hidden" name="tipo_comercial" id="edit_tipo_comercial">
                <input type="hidden" name="preco_editado_manualmente" id="edit_preco_editado_manualmente" value="0">
                <input type="hidden" name="redirect_to" value="../../ciclos/detalhe.php?id=<?= $id ?>">

                <div class="form-group">
                    <label>Data da Venda</label>
                    <input type="date" name="data_venda" id="edit_data_venda" required>
                </div>

                <div class="form-group">
                    <label>Produto</label>
                    <select name="produto_id" id="edit_produto_id" required>
                        <option value="">Selecione</option>
                        <?php foreach ($produtosList as $p): ?>
                            <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Cliente</label>
                    <select name="cliente_id" id="edit_cliente_id" required>
                        <option value="">Selecione</option>
                        <?php foreach ($clientesList as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"
                                data-documento="<?= htmlspecialchars($c['documento'] ?? '', ENT_QUOTES) ?>"
                                data-telefone="<?= htmlspecialchars($c['telefone'] ?? '', ENT_QUOTES) ?>"
                                data-endereco="<?= htmlspecialchars($c['endereco'] ?? '', ENT_QUOTES) ?>">
                                <?= htmlspecialchars($c['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Tipo</label>
                    <select name="tipo" id="edit_tipo" onchange="atualizarPesoEdicao()" required>
                        <option value="">Selecione</option>
                        <option value="bandeja">Bandeja</option>
                        <option value="caixa">Caixa</option>
                        <option value="kg">Kg</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Prazo de Pagamento</label>
                    <select name="prazo_escolhido" id="edit_prazo_escolhido">
                        <option value="">Selecione</option>
                        <option value="5_dias">5 dias</option>
                        <option value="30_dias">30 dias</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Quantidade</label>
                    <input type="number" step="0.01" min="0.01" name="pedido" id="edit_pedido" required>
                </div>

                <div class="form-group">
                    <label>Preço (R$)</label>
                    <input type="number" step="0.01" min="0" name="preco" id="edit_preco" required
                           oninput="document.getElementById('edit_preco_editado_manualmente').value='1';">
                </div>

                <div class="form-group" id="edit_grupo_peso">
                    <label id="edit_peso_label">Kg Caixa / Gramagem</label>
                    <input type="number" step="0.01" name="peso_unitario" id="edit_peso_unitario">
                </div>

                <div class="form-group" style="flex-direction:row; justify-content:flex-end; gap:10px;">
                    <button type="submit" class="btn-edit">Salvar Alterações</button>
                    <button type="button" class="btn-delete" onclick="fecharModalEditarVenda()">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // LAYOUT NOVO (UX/UI): alternância das abas (Estoque/Vendas/Entradas/Abates)
        function cdShowTab(btn, name) {
            document.querySelectorAll('.cd-tab').forEach(function (b) { b.classList.remove('active'); });
            document.querySelectorAll('.cd-panel').forEach(function (p) { p.style.display = 'none'; });
            btn.classList.add('active');
            var panel = document.getElementById('panel-' + name);
            if (panel) { panel.style.display = ''; }
        }

        function abrirModalEditarVenda(item) {
            document.getElementById('edit_preco_editado_manualmente').value = '0';
            document.getElementById('edit_id').value            = item.id          || '';
            document.getElementById('edit_data_venda').value   = item.data_venda  || '';
            document.getElementById('edit_produto_id').value   = item.produto_id  || '';
            document.getElementById('edit_cliente_id').value   = item.cliente_id  || '';
            document.getElementById('edit_tipo').value         = item.tipo        || 'kg';
            document.getElementById('edit_tipo_comercial').value = item.tipo_comercial || '';
            document.getElementById('edit_prazo_escolhido').value = item.prazo_escolhido || '';
            document.getElementById('edit_pedido').value       = item.pedido      ?? '';
            document.getElementById('edit_preco').value        = item.preco       ?? '';
            document.getElementById('edit_peso_unitario').value = item.peso_unitario ?? '';
            atualizarPesoEdicao();
            document.getElementById('modalEditarVenda').classList.add('aberto');
        }

        function fecharModalEditarVenda() {
            document.getElementById('modalEditarVenda').classList.remove('aberto');
        }

        function atualizarPesoEdicao() {
            const tipo = document.getElementById('edit_tipo').value;
            const grupo = document.getElementById('edit_grupo_peso');
            const label = document.getElementById('edit_peso_label');
            const input = document.getElementById('edit_peso_unitario');
            if (tipo === 'caixa') {
                label.textContent = 'Kg por Caixa';
                input.required = true;
                input.disabled = false;
                grupo.style.display = '';
            } else if (tipo === 'bandeja') {
                label.textContent = 'Gramagem por Bandeja';
                input.required = true;
                input.disabled = false;
                grupo.style.display = '';
            } else {
                input.required = false;
                input.disabled = true;
                grupo.style.display = 'none';
            }
        }

        document.getElementById('modalEditarVenda').addEventListener('click', function(e) {
            if (e.target === this) fecharModalEditarVenda();
        });

        // LAYOUT NOVO (UX/UI): gráficos do detalhe (Chart.js) com dados reais do PHP
        (function () {
            if (typeof Chart === 'undefined') { return; }
            Chart.defaults.font.family = "'Segoe UI', Arial, sans-serif";
            Chart.defaults.color = '#5F5E5A';
            var donutCores = ['#1b5e20','#2e7d32','#3f9357','#5fae78','#8cc79e','#b8dec3','#dcefe1'];
            var fmtKg = function (v) { return new Intl.NumberFormat('pt-BR', {maximumFractionDigits: 0}).format(v) + ' kg'; };

            var vpLabels = <?= json_encode($vpLabels, JSON_UNESCAPED_UNICODE) ?>;
            var vpData   = <?= json_encode($vpData) ?>;
            var efLabels = <?= json_encode($efLabels, JSON_UNESCAPED_UNICODE) ?>;
            var efData   = <?= json_encode($efData) ?>;
            var propData = <?= json_encode($propTotais) ?>;

            var elVp = document.getElementById('chartVendasProduto');
            if (elVp && vpData.length) {
                new Chart(elVp, {
                    type: 'bar',
                    data: {labels: vpLabels, datasets: [{data: vpData, backgroundColor: '#2e7d32', borderRadius: 4, maxBarThickness: 18}]},
                    options: {indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                        plugins: {legend: {display: false}, tooltip: {callbacks: {label: function (c) { return fmtKg(c.parsed.x); }}}},
                        scales: {x: {beginAtZero: true, grid: {color: '#eef0ec'}, ticks: {callback: function (v) { return (v / 1000) + 'k'; }}}, y: {grid: {display: false}}}
                    }
                });
            }

            var elEf = document.getElementById('chartEstoqueFinal');
            if (elEf && efData.length) {
                new Chart(elEf, {
                    type: 'doughnut',
                    data: {labels: efLabels, datasets: [{data: efData, backgroundColor: donutCores, borderColor: '#fff', borderWidth: 2}]},
                    options: {responsive: true, maintainAspectRatio: false, cutout: '60%',
                        plugins: {legend: {position: 'bottom', labels: {boxWidth: 10, boxHeight: 10, usePointStyle: true, padding: 8, font: {size: 11}}},
                            tooltip: {callbacks: {label: function (c) { return c.label + ': ' + fmtKg(c.parsed); }}}}
                    }
                });
            }

            var elProp = document.getElementById('chartProporcao');
            if (elProp) {
                new Chart(elProp, {
                    type: 'doughnut',
                    data: {labels: ['Vendas', 'Entradas', 'Abates'], datasets: [{data: propData, backgroundColor: ['#2e7d32', '#a05a2c', '#a93b2e'], borderColor: '#fff', borderWidth: 2}]},
                    options: {responsive: true, maintainAspectRatio: false, cutout: '60%',
                        plugins: {legend: {position: 'bottom', labels: {boxWidth: 10, boxHeight: 10, usePointStyle: true, padding: 8, font: {size: 11}}},
                            tooltip: {callbacks: {label: function (c) { return c.label + ': ' + fmtKg(c.parsed); }}}}
                    }
                });
            }
        })();
    </script>
</body>
</html>

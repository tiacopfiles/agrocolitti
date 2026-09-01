<?php
/*
 * AgroColitti – Dashboard Principal (agrocolitti02)
 * ─────────────────────────────────────────────────
 * SELECTs adicionados nesta versão (todos comentados inline):
 *   1. Sparkline entradas 7d  → tabela entradas (data_entrada, quantidade)
 *   2. Sparkline vendas 7d    → tabela vendas   (data_venda,   quantidade)
 *   3. Mov. 30 dias (gráfico) → entradas + movimentacoes (tipo=venda)
 *   4. Top 5 vendidos (ciclo) → vendas JOIN produtos
 *   5. Próximas entradas 14d  → previsao_colheita + previsao_fornecedor
 *   6. Entradas por produto   → entradas JOIN produtos (ciclo)
 *   7. Saídas por produto     → movimentacoes JOIN produtos (ciclo, tipo=venda)
 *
 * Tabelas INEXISTENTES no banco (aviso em comentário):
 *   - produtos.categoria      → derivado do nome do produto (heurística)
 *   - produtos_snapshot       → delta semana anterior não disponível
 *   - produtos.estoque_minimo → alertas baseados em estoque <= 0
 */

require "config/conexao.php";
require "config/ciclo_helper.php";
require "auth/proteger.php";
require "config/layout_helper.php";

date_default_timezone_set('America/Sao_Paulo');

$hoje        = date('Y-m-d');
$mesAtual    = date('Y-m');
$mesAnterior = date('Y-m', strtotime('-1 month'));

$data = new DateTime();
$diasSemana = [
    'Sunday'    => 'Domingo',    'Monday'  => 'Segunda-feira',
    'Tuesday'   => 'Terça-feira','Wednesday'=> 'Quarta-feira',
    'Thursday'  => 'Quinta-feira','Friday'  => 'Sexta-feira',
    'Saturday'  => 'Sábado',
];
$diaSemana = $diasSemana[$data->format('l')];

// ── Ciclo ─────────────────────────────────────────────────────────────────
$cicloAtivo    = getCicloAtivo($conexao);
$cicloAtivoId  = $cicloAtivo ? (int)$cicloAtivo['id'] : null;
$nomeCiclo     = $cicloAtivo ? getNomeCiclo($cicloAtivo) : 'Sem ciclo ativo';
$filtroCicloPF = '';
$filtroCicloPC = '';
$filtroCicloV  = '';

// ── Entradas operacionais existentes ─────────────────────────────────────
$stmtColheita = $conexao->prepare("
    SELECT IFNULL(SUM(quantidade),0) AS total_kg,
           COUNT(*) AS total_registros
    FROM entradas e
    WHERE tipo='colheita'");
$stmtColheita->execute();
$entradaColheita = $stmtColheita->get_result()->fetch_assoc();

// ── Fornecedor concluído (ciclo) ──────────────────────────────────────────
$stmtFornecedor = $conexao->prepare("
    SELECT IFNULL(SUM(quantidade),0) AS total_kg,
           COUNT(*) AS total_registros
    FROM entradas e
    WHERE tipo IN ('fornecedor','entrada_fornecedor')");
$stmtFornecedor->execute();
$entradaFornecedor = $stmtFornecedor->get_result()->fetch_assoc();

$totalEntradaKg        = (float)$entradaColheita['total_kg'] + (float)$entradaFornecedor['total_kg'];
$totalEntradaRegistros = (int)$entradaColheita['total_registros'] + (int)$entradaFornecedor['total_registros'];
$totalEntradaKgFmt     = number_format($totalEntradaKg, 2, ',', '.');

// ── Vendas do ciclo ───────────────────────────────────────────────────────
$stmtVendaHoje = $conexao->prepare("
    SELECT IFNULL(SUM(quantidade),0) AS total_kg,
           COUNT(*) AS total_vendas
    FROM vendas v
    WHERE status='concluido'");
$stmtVendaHoje->execute();
$vendaHoje       = $stmtVendaHoje->get_result()->fetch_assoc();
$totalKgHoje     = number_format((float)$vendaHoje['total_kg'], 2, ',', '.');
$totalVendasHoje = (int)$vendaHoje['total_vendas'];

// ── Mais vendidos (ciclo, top 3 para KPI) ───────────────────────────────
$stmtMV = $conexao->prepare("
    SELECT p.nome, SUM(v.quantidade) as total_kg
    FROM vendas v JOIN produtos p ON p.id=v.produto_id
    WHERE v.status='concluido'
    GROUP BY v.produto_id ORDER BY total_kg DESC LIMIT 3");
$stmtMV->execute();
$maisVendidos = [];
$rMV = $stmtMV->get_result();
while ($row = $rMV->fetch_assoc()) $maisVendidos[] = $row;

// ── Colheitas pendentes (ciclo) ───────────────────────────────────────────
$stmtCP = $conexao->prepare("
    SELECT COUNT(*) AS total_colheita,
           MIN(data_prevista) AS proxima_data,
           (SELECT p.nome FROM previsao_colheita pc2
            JOIN produtos p ON p.id=pc2.produto_id
            WHERE pc2.status='pendente' {$filtroCicloPC}
            ORDER BY pc2.data_prevista ASC LIMIT 1) AS proximo_produto
    FROM previsao_colheita pc WHERE status='pendente' {$filtroCicloPC}");
$stmtCP->execute();
$colheita = $stmtCP->get_result()->fetch_assoc();
$totalColheita = (int)$colheita['total_colheita'];

// ── Compras pendentes (ciclo) ─────────────────────────────────────────────
$stmtFP = $conexao->prepare("
    SELECT COUNT(*) AS total_fornecedor
    FROM previsao_fornecedor pf
    WHERE status='pendente' {$filtroCicloPF}");
$stmtFP->execute();
$totalFornecedor = (int)$stmtFP->get_result()->fetch_assoc()['total_fornecedor'];

// ── Estoque calculado do ciclo ────────────────────────────────────────────
$estoqueCalculado = calcularEstoqueProdutosDoCiclo($conexao, $cicloAtivoId, false, false);
$totalEstoque  = 0;
$thresholdCritico = 10; $thresholdAtencao = 100;
$contCritico = $contAtencao = $contNormal = $contEmEstoque = $contZerado = $contNegativo = $maxEstoque = 0;
foreach ($estoqueCalculado as $item) {
    $q = (float)$item['estoque'];
    $totalEstoque += $q;
    if ($q > 0)     $contEmEstoque++;
    elseif ($q < 0) $contNegativo++;
    else            $contZerado++;
    if ($q <= $thresholdCritico)     $contCritico++;
    elseif ($q <= $thresholdAtencao) $contAtencao++;
    else                             $contNormal++;
    if ($q > $maxEstoque) $maxEstoque = $q;
}
$totalBaixoEstoque = $contCritico;
usort($estoqueCalculado, static function ($a, $b) {
    $o = static function ($q) { return $q > 0 ? 0 : ($q < 0 ? 1 : 2); };
    $oa = $o((float)$a['estoque']); $ob = $o((float)$b['estoque']);
    return $oa !== $ob ? $oa <=> $ob : strcmp($a['produto'], $b['produto']);
});
$totalPendencias    = $totalColheita + $totalFornecedor;
$maiorFonteEntrada  = (float)$entradaColheita['total_kg'] >= (float)$entradaFornecedor['total_kg'] ? 'Colheita' : 'Fornecedor';
$totalEstoqueFuturo = array_sum(array_map(static function ($i) { return (float)($i['estoque_futuro'] ?? 0); }, $estoqueCalculado));
$totalProdutos      = count($estoqueCalculado);
$pctPositivo        = $totalProdutos > 0 ? round($contEmEstoque / $totalProdutos * 100) : 0;

// ── [NOVO 1] Sparkline entradas 7 dias ───────────────────────────────────
$spark7Entradas = array_fill(0, 7, 0.0);
$rSpkE = $conexao->query("SELECT DATE(data_entrada) as d, SUM(quantidade) as t FROM entradas WHERE data_entrada >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(data_entrada)");
if ($rSpkE) while ($row = $rSpkE->fetch_assoc()) {
    $idx = (int)((strtotime($row['d']) - strtotime(date('Y-m-d', strtotime('-6 days')))) / 86400);
    if ($idx >= 0 && $idx < 7) $spark7Entradas[$idx] = (float)$row['t'];
}

// ── [NOVO 2] Sparkline vendas 7 dias ─────────────────────────────────────
$spark7Vendas = array_fill(0, 7, 0.0);
$rSpkV = $conexao->query("SELECT DATE(data_venda) as d, SUM(quantidade) as t FROM vendas WHERE status='concluido' AND data_venda >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(data_venda)");
if ($rSpkV) while ($row = $rSpkV->fetch_assoc()) {
    $idx = (int)((strtotime($row['d']) - strtotime(date('Y-m-d', strtotime('-6 days')))) / 86400);
    if ($idx >= 0 && $idx < 7) $spark7Vendas[$idx] = (float)$row['t'];
}

// ── [NOVO 3] Movimentação 30 dias para gráfico de linha ──────────────────
$diasLabels30 = []; $entradasDias30 = []; $saidasDias30 = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $diasLabels30[]   = date('d/m', strtotime($d));
    $entradasDias30[$d] = 0.0;
    $saidasDias30[$d]   = 0.0;
}
$rEnt30 = $conexao->query("SELECT DATE(data_entrada) as d, SUM(quantidade) as t FROM entradas WHERE data_entrada >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(data_entrada)");
if ($rEnt30) while ($row = $rEnt30->fetch_assoc()) { if (isset($entradasDias30[$row['d']])) $entradasDias30[$row['d']] = (float)$row['t']; }
$rSai30 = $conexao->query("SELECT DATE(data_venda) as d, SUM(quantidade) as t FROM vendas WHERE status='concluido' AND data_venda >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(data_venda)");
if ($rSai30) while ($row = $rSai30->fetch_assoc()) { if (isset($saidasDias30[$row['d']])) $saidasDias30[$row['d']] = (float)$row['t']; }

// ── [NOVO 4] Top 5 mais vendidos (ciclo) ─────────────────────────────────
$top5 = [];
$stmtTop5 = $conexao->prepare("SELECT p.nome, IFNULL(SUM(v.quantidade),0) as kg FROM vendas v JOIN produtos p ON p.id=v.produto_id WHERE v.status='concluido' GROUP BY v.produto_id ORDER BY kg DESC LIMIT 5");
$stmtTop5->execute();
$rTop5 = $stmtTop5->get_result();
while ($row = $rTop5->fetch_assoc()) $top5[] = $row;
$maxTop5kg = !empty($top5) ? max(array_column($top5, 'kg')) : 1;

// ── [NOVO 5] Próximas entradas (14 dias) ─────────────────────────────────
$proximasEntradas = [];
$stmtPE = $conexao->prepare("
    (SELECT pc.data_prevista as data, p.nome as produto, pc.quantidade_prevista as quantidade,
            'colheita' as tipo, pc.meieiro as origem
     FROM previsao_colheita pc JOIN produtos p ON p.id=pc.produto_id
     WHERE pc.status='pendente' AND pc.data_prevista >= CURDATE()
       AND pc.data_prevista <= DATE_ADD(CURDATE(), INTERVAL 14 DAY) {$filtroCicloPC})
    UNION ALL
    (SELECT pf.data_prevista, p.nome, pf.quantidade_prevista, 'fornecedor', f.nome
     FROM previsao_fornecedor pf
     JOIN produtos p ON p.id=pf.produto_id
     JOIN fornecedores f ON f.id=pf.fornecedor_id
     WHERE pf.status='pendente' AND pf.data_prevista >= CURDATE()
       AND pf.data_prevista <= DATE_ADD(CURDATE(), INTERVAL 14 DAY) {$filtroCicloPF})
    ORDER BY data ASC LIMIT 5");
$stmtPE->execute();
$rPE = $stmtPE->get_result();
while ($row = $rPE->fetch_assoc()) $proximasEntradas[] = $row;

// ── [NOVO 6+7] Entradas e saídas por produto no ciclo ────────────────────
$entradasPorProduto = [];
$rEntr = $conexao->query("SELECT produto_id, SUM(quantidade) as t FROM entradas GROUP BY produto_id");
if ($rEntr) while ($row = $rEntr->fetch_assoc()) $entradasPorProduto[(int)$row['produto_id']] = (float)$row['t'];
$saidasPorProduto = [];
$rSaidas = $conexao->query("SELECT produto_id, SUM(quantidade) as t FROM vendas WHERE status='concluido' GROUP BY produto_id");
if ($rSaidas) while ($row = $rSaidas->fetch_assoc()) $saidasPorProduto[(int)$row['produto_id']] = (float)$row['t'];

// Registros arquivados por "Arquivar e zerar" pertencem exclusivamente a
// Ciclos. O dashboard representa apenas a operacao posterior ao ultimo
// fechamento operacional e, por isso, nao soma o snapshot aqui.

// ── [NFe] Notas Fiscais Eletrônicas ──────────────────────────────────────
$nfeStatus = ['autorizada'=>0,'cancelada'=>0,'rejeitada'=>0,'enviada'=>0,'processando'=>0,'erro'=>0,'rascunho'=>0];
$rNfeStatus = $conexao->query("SELECT status, COUNT(*) as c FROM nfe_documentos WHERE ambiente='producao' GROUP BY status");
if ($rNfeStatus) while ($row = $rNfeStatus->fetch_assoc()) {
    if (array_key_exists($row['status'], $nfeStatus)) $nfeStatus[$row['status']] = (int)$row['c'];
}
$nfeTotalDecididas = $nfeStatus['autorizada'] + $nfeStatus['cancelada'] + $nfeStatus['rejeitada'];
$nfeTaxaAut = $nfeTotalDecididas > 0 ? round($nfeStatus['autorizada'] / $nfeTotalDecididas * 100) : null;

$nfePesoAutorizado = 0.0;
$rNfePeso = $conexao->query("SELECT IFNULL(SUM(peso_liquido),0) as p FROM nfe_documentos WHERE status='autorizada' AND ambiente='producao'");
if ($rNfePeso) $nfePesoAutorizado = (float)$rNfePeso->fetch_assoc()['p'];

// Emissões 30 dias para gráfico
$nfeDias30Labels = []; $nfeDias30Aut = []; $nfeDias30Can = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $nfeDias30Labels[] = date('d/m', strtotime($d));
    $nfeDias30Aut[$d]  = 0;
    $nfeDias30Can[$d]  = 0;
}
$rNfe30 = $conexao->query("
    SELECT DATE(IFNULL(autorizada_em, created_at)) as d, status, COUNT(*) as c
    FROM nfe_documentos
    WHERE ambiente='producao'
      AND IFNULL(autorizada_em, created_at) >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY DATE(IFNULL(autorizada_em, created_at)), status");
if ($rNfe30) while ($row = $rNfe30->fetch_assoc()) {
    if ($row['status'] === 'autorizada' && isset($nfeDias30Aut[$row['d']]))
        $nfeDias30Aut[$row['d']] = (int)$row['c'];
    elseif (in_array($row['status'], ['cancelada','rejeitada']) && isset($nfeDias30Can[$row['d']]))
        $nfeDias30Can[$row['d']] += (int)$row['c'];
}

// Últimas 5 NFe em produção
$nfeRecentes = [];
$rNfeRec = $conexao->query("
    SELECT d.id, d.numero_nfe, d.status, d.tipo_emissao, d.emitida_em, d.cancelada_em,
           IFNULL(c.nome, f.nome) as destinatario
    FROM nfe_documentos d
    LEFT JOIN clientes c ON c.id = d.cliente_id
    LEFT JOIN fornecedores f ON f.id = d.fornecedor_id
    WHERE d.ambiente = 'producao'
    ORDER BY d.created_at DESC LIMIT 5");
if ($rNfeRec) while ($row = $rNfeRec->fetch_assoc()) $nfeRecentes[] = $row;

$nfeChartLabels = json_encode($nfeDias30Labels, JSON_UNESCAPED_UNICODE);
$nfeChartAut    = json_encode(array_values($nfeDias30Aut));
$nfeChartCan    = json_encode(array_values($nfeDias30Can));

// ── Alertas (produtos negativos e zerados) ────────────────────────────────
$alertas = [];
foreach ($estoqueCalculado as $item) {
    $q = (float)$item['estoque'];
    if ($q < 0) {
        $alertas[] = ['tipo' => 'critico', 'titulo' => $item['produto'], 'qtd' => $q, 'sub' => number_format($q, 2, ',', '.') . ' kg (negativo)'];
    } elseif ($q == 0) {
        $alertas[] = ['tipo' => 'atencao', 'titulo' => $item['produto'], 'qtd' => $q, 'sub' => 'Estoque zerado'];
    }
    if (count($alertas) >= 5) break;
}
if (empty($alertas)) {
    $alertas[] = ['tipo' => 'info', 'titulo' => 'Estoque saudável', 'qtd' => null, 'sub' => 'Nenhum produto crítico no momento'];
}

// ── JSON para Chart.js ────────────────────────────────────────────────────
$chartLabels30  = json_encode($diasLabels30, JSON_UNESCAPED_UNICODE);
$chartEntradas30 = json_encode(array_values($entradasDias30));
$chartSaidas30   = json_encode(array_values($saidasDias30));
$top5Nomes = json_encode(array_column($top5, 'nome'), JSON_UNESCAPED_UNICODE);
$top5Kgs   = json_encode(array_column($top5, 'kg'));

// ── Sparkline SVG helper ──────────────────────────────────────────────────
function sparklineSVG(array $vals, string $color, string $type = 'line', int $w = 140, int $h = 36): string {
    $n = count($vals);
    if ($n < 2) return '<svg width="'.$w.'" height="'.$h.'"></svg>';
    $max = max($vals) ?: 1;
    if ($type === 'bar') {
        $bw = floor(($w - ($n - 1) * 2) / $n);
        $svg = '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" xmlns="http://www.w3.org/2000/svg">';
        for ($i = 0; $i < $n; $i++) {
            $bh  = max(3, round(($vals[$i] / $max) * $h));
            $x   = $i * ($bw + 2);
            $y   = $h - $bh;
            $svg .= '<rect x="'.$x.'" y="'.$y.'" width="'.$bw.'" height="'.$bh.'" rx="2" fill="'.$color.'" opacity=".8"/>';
        }
        return $svg . '</svg>';
    }
    // Line + area
    $pts = '';
    $area = 'M0,'.$h;
    for ($i = 0; $i < $n; $i++) {
        $x = round($i / ($n - 1) * $w, 2);
        $y = round($h - ($vals[$i] / $max) * ($h - 4) - 2, 2);
        $pts .= ($i === 0 ? 'M' : 'L') . $x . ',' . $y . ' ';
        $area .= ' L' . $x . ',' . $y;
    }
    $area .= ' L'.$w.','.$h.' Z';
    return '<svg width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">'
        . '<path d="'.$area.'" fill="'.$color.'" opacity=".12"/>'
        . '<path d="'.$pts.'" fill="none" stroke="'.$color.'" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</svg>';
}

// ── Dia atual do ciclo ────────────────────────────────────────────────────
$diaCiclo = 'N/A';
if ($cicloAtivo && isset($cicloAtivo['aberto_em'])) {
    $aberto = new DateTime($cicloAtivo['aberto_em']);
    $hoje2  = new DateTime();
    $diaCiclo = (int)$aberto->diff($hoje2)->days + 1;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — AgroColitti</title>
<link rel="stylesheet" href="assets/css/dashboard.css">
<?php renderAppLayoutStyles(); ?>
</head>
<body>
<?php renderAppHeader('.'); ?>

<div class="container page-container app-shell">

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'fechado'): ?>
<div class="msg-sucesso">Ciclo fechado e próximo ciclo aberto com sucesso.</div>
<?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'erro'): ?>
<div class="msg-erro"><?= htmlspecialchars($_GET['detalhe'] ?? 'Erro ao processar o ciclo.') ?></div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════
     1. HEADER DA PÁGINA
     ════════════════════════════════════════════════════════════════ -->
<div class="db-page-header">
  <div>
    <h1 class="db-page-title">Dashboard</h1>
    <div class="db-page-meta">
      <span><?= htmlspecialchars($nomeCiclo) ?> · dia <?= $diaCiclo ?></span>
      <span><?= $data->format('d/m/Y') ?> (<?= $diaSemana ?>)</span>
      <span>Atualizado agora</span>
    </div>
  </div>
  <div class="db-page-actions">
    <a href="dashboard/actions/export_api.php" class="btn btn-secondary btn-sm">
      <i class="bi bi-download"></i> Exportar
    </a>
    <button onclick="window.print()" class="btn btn-secondary btn-sm">
      <i class="bi bi-printer"></i> PDF
    </button>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     2. KPIs PRINCIPAIS (4 cards)
     ════════════════════════════════════════════════════════════════ -->
<div class="db-kpi-grid">

  <!-- Total em Estoque -->
  <a class="db-kpi-card" href="#destino">
    <div class="db-kpi-top">
      <span class="db-kpi-label">Total em Estoque</span>
      <span class="db-kpi-icon"><i class="bi bi-box-seam"></i></span>
    </div>
    <div class="db-kpi-value"><?= number_format($totalEstoque, 0, ',', '.') ?><span class="db-kpi-unit">kg</span></div>
    <div class="db-kpi-context">
      <?= $totalBaixoEstoque ?> alerta(s) crítico(s)
      &nbsp;&middot;&nbsp; <?= $contNegativo ?> negativo(s)
      &nbsp;&middot;&nbsp; <?= $contZerado ?> zerado(s)
    </div>
    <div class="db-kpi-spark"><?= sparklineSVG($spark7Entradas, '#166534', 'line') ?></div>
  </a>

  <!-- Entradas operacionais -->
  <a class="db-kpi-card" href="entradas/entradas.php">
    <div class="db-kpi-top">
      <span class="db-kpi-label">Entradas</span>
      <span class="db-kpi-icon"><i class="bi bi-arrow-down-circle"></i></span>
    </div>
    <div class="db-kpi-value"><?= number_format($totalEntradaKg, 0, ',', '.') ?><span class="db-kpi-unit">kg</span></div>
    <div class="db-kpi-context">
      <?= $totalEntradaRegistros ?> registros
      &nbsp;&middot;&nbsp; Colheita: <?= number_format((float)$entradaColheita['total_kg'], 0, ',', '.') ?> kg
      &nbsp;&middot;&nbsp; Forn.: <?= number_format((float)$entradaFornecedor['total_kg'], 0, ',', '.') ?> kg
    </div>
    <div class="db-kpi-spark"><?= sparklineSVG($spark7Entradas, '#e89020', 'bar') ?></div>
  </a>

  <!-- Vendas operacionais -->
  <a class="db-kpi-card" href="vendas/vendas.php">
    <div class="db-kpi-top">
      <span class="db-kpi-label">Vendas</span>
      <span class="db-kpi-icon"><i class="bi bi-cart-check"></i></span>
    </div>
    <div class="db-kpi-value"><?= number_format((float)$vendaHoje['total_kg'], 0, ',', '.') ?><span class="db-kpi-unit">kg</span></div>
    <div class="db-kpi-context">
      <?= $totalVendasHoje ?> venda(s)
      &nbsp;&middot;&nbsp; Mais: <?= htmlspecialchars($maisVendidos[0]['nome'] ?? '—') ?>
    </div>
    <div class="db-kpi-spark"><?= sparklineSVG($spark7Vendas, '#9f2f37', 'bar') ?></div>
  </a>

  <!-- Previsão de Estoque -->
  <a class="db-kpi-card" href="previsoes/previsao_colheita.php">
    <div class="db-kpi-top">
      <span class="db-kpi-label">Previsão de Estoque</span>
      <span class="db-kpi-icon"><i class="bi bi-graph-up-arrow"></i></span>
    </div>
    <div class="db-kpi-value"><?= $totalEstoqueFuturo > 0 ? number_format($totalEstoqueFuturo, 0, ',', '.') : '—' ?><span class="db-kpi-unit"><?= $totalEstoqueFuturo > 0 ? 'kg' : '' ?></span></div>
    <div class="db-kpi-context">
      Entradas futuras previstas
      &nbsp;&middot;&nbsp; <?= htmlspecialchars($nomeCiclo) ?>
    </div>
    <div class="db-kpi-spark"><?= sparklineSVG(array_fill(0, 7, $totalEstoqueFuturo / 7), '#2563eb', 'line') ?></div>
  </a>
</div>

<!-- ════════════════════════════════════════════════════════════════
     3. KPIs SECUNDÁRIOS (3 cards)
     ════════════════════════════════════════════════════════════════ -->
<div class="db-kpi-sec-grid">

  <a class="db-kpi-sec" href="previsoes/previsao_colheita.php">
    <span class="db-kpi-sec-icon"><i class="bi bi-calendar2-event"></i></span>
    <div class="db-kpi-sec-body">
      <div class="db-kpi-sec-label">Colheita Futura</div>
      <div class="db-kpi-sec-value"><?= $totalColheita ?> colheita(s)</div>
      <div class="db-kpi-sec-sub">
        <?php if ($colheita['proxima_data']): ?>
          Próxima: <?= date('d/m', strtotime($colheita['proxima_data'])) ?>
          &nbsp;&middot;&nbsp; <?= htmlspecialchars($colheita['proximo_produto'] ?? '') ?>
        <?php else: ?>
          Nenhuma pendente no ciclo
        <?php endif; ?>
      </div>
    </div>
  </a>

  <a class="db-kpi-sec" href="previsoes/previsao_fornecedor.php">
    <span class="db-kpi-sec-icon"><i class="bi bi-truck"></i></span>
    <div class="db-kpi-sec-body">
      <div class="db-kpi-sec-label">Compras Pendentes</div>
      <div class="db-kpi-sec-value"><?= $totalFornecedor ?> compra(s)</div>
      <div class="db-kpi-sec-sub">
        Total pendências: <?= $totalPendencias ?>
        &nbsp;&middot;&nbsp; Fonte: <?= htmlspecialchars($maiorFonteEntrada) ?>
      </div>
    </div>
  </a>

  <a class="db-kpi-sec" href="vendas/vendas.php">
    <span class="db-kpi-sec-icon"><i class="bi bi-receipt"></i></span>
    <div class="db-kpi-sec-body">
      <div class="db-kpi-sec-label">Produtos no Estoque</div>
      <div class="db-kpi-sec-value"><?= $contEmEstoque ?> / <?= $totalProdutos ?></div>
      <div class="db-kpi-sec-sub">
        <?= $pctPositivo ?>% positivos
        &nbsp;&middot;&nbsp; <?= $contAtencao ?> em atenção
        &nbsp;&middot;&nbsp; <?= $contCritico ?> crítico(s)
      </div>
    </div>
  </a>
</div>

<!-- ════════════════════════════════════════════════════════════════
     4. GRÁFICOS (linha duplo + barras vendidos)
     ════════════════════════════════════════════════════════════════ -->
<div class="db-charts-row">

  <!-- 4a. Linha dupla: entradas vs saídas 30d -->
  <div class="db-chart-card">
    <div class="db-chart-header">
      <h3 class="db-chart-title">Movimentação — Entradas vs. Saídas</h3>
      <div class="db-chart-legend">
        <span class="db-chart-legend-item"><span class="db-chart-legend-dot" style="background:#2e7d32"></span>Entradas (kg)</span>
        <span class="db-chart-legend-item"><span class="db-chart-legend-dot" style="background:#e89020"></span>Saídas (kg)</span>
      </div>
      <div class="db-chart-tabs">
        <button class="db-chart-tab active" onclick="setChartRange(7, this)">7d</button>
        <button class="db-chart-tab" onclick="setChartRange(15, this)">15d</button>
        <button class="db-chart-tab" onclick="setChartRange(30, this)">30d</button>
      </div>
    </div>
    <div class="db-chart-canvas-wrap">
      <canvas id="chartMov"></canvas>
    </div>
  </div>

  <!-- 4b. Top 5 mais vendidos (barras horizontais + donut) -->
  <div class="db-chart-card">
    <div class="db-chart-header">
      <h3 class="db-chart-title">Mais vendidos</h3>
    </div>
    <?php if (!empty($top5)): ?>
    <div class="db-sold-list">
      <?php foreach ($top5 as $item): ?>
      <?php $pctBar = $maxTop5kg > 0 ? round((float)$item['kg'] / $maxTop5kg * 100) : 0; ?>
      <div class="db-sold-item">
        <div class="db-sold-row">
          <span class="db-sold-name"><?= htmlspecialchars($item['nome']) ?></span>
          <span class="db-sold-val"><?= number_format((float)$item['kg'], 1, ',', '.') ?> kg</span>
        </div>
        <div class="db-sold-bar-bg">
          <div class="db-sold-bar-fill" style="width:<?= $pctBar ?>%"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="db-no-data"><i class="bi bi-bar-chart" style="font-size:28px;display:block;margin-bottom:8px;"></i>Nenhuma venda registrada após a última zeragem</div>
    <?php endif; ?>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     5. SAÚDE DO ESTOQUE (barra empilhada)
     ════════════════════════════════════════════════════════════════ -->
<div class="db-health-card">
  <div class="db-chart-header" style="margin-bottom:0">
    <h3 class="db-chart-title">Saúde do Estoque</h3>
    <span style="font-size:13px;color:var(--color-muted)"><?= $totalProdutos ?> produtos cadastrados</span>
  </div>
  <div class="db-health-bar">
    <?php if ($contEmEstoque > 0): ?>
    <div class="db-health-seg db-health-seg-pos" style="flex:<?= $contEmEstoque ?>">
      <?= $contEmEstoque ?>
    </div>
    <?php endif; ?>
    <?php if ($contAtencao > 0): ?>
    <div class="db-health-seg db-health-seg-att" style="flex:<?= $contAtencao ?>">
      <?= $contAtencao ?>
    </div>
    <?php endif; ?>
    <?php if ($contZerado > 0): ?>
    <div class="db-health-seg db-health-seg-zero" style="flex:<?= $contZerado ?>">
      <?= $contZerado ?>
    </div>
    <?php endif; ?>
    <?php if ($contNegativo > 0): ?>
    <div class="db-health-seg db-health-seg-neg" style="flex:<?= $contNegativo ?>">
      <?= $contNegativo ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="db-health-tiles">
    <div class="db-health-tile">
      <div class="db-health-tile-val" style="color:#16a34a"><?= $contEmEstoque ?></div>
      <div class="db-health-tile-lbl" style="color:#16a34a">Positivo</div>
      <div class="db-health-tile-desc">Estoque acima de zero — produtos disponíveis para venda.</div>
    </div>
    <div class="db-health-tile">
      <div class="db-health-tile-val" style="color:var(--color-accent-orange)"><?= $contAtencao ?></div>
      <div class="db-health-tile-lbl" style="color:var(--color-accent-orange)">Atenção</div>
      <div class="db-health-tile-desc">Entre 1 kg e <?= $thresholdAtencao ?> kg — reposição recomendada.</div>
    </div>
    <div class="db-health-tile">
      <div class="db-health-tile-val" style="color:#94a3b8"><?= $contZerado ?></div>
      <div class="db-health-tile-lbl" style="color:#94a3b8">Zerado</div>
      <div class="db-health-tile-desc">Estoque exatamente em zero — sem disponibilidade.</div>
    </div>
    <div class="db-health-tile">
      <div class="db-health-tile-val" style="color:#dc2626"><?= $contNegativo ?></div>
      <div class="db-health-tile-lbl" style="color:#dc2626">Negativo</div>
      <div class="db-health-tile-desc">Saídas superiores às entradas — inconsistência de dados.</div>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     6. BOTTOM ROW: Próximas Entradas / Alertas / Mais vendidos
     ════════════════════════════════════════════════════════════════ -->
<div class="db-bottom-row">

  <!-- 6a. Próximas entradas (14 dias) -->
  <div class="db-panel">
    <h3 class="db-panel-title"><i class="bi bi-calendar-event" style="color:var(--color-primary)"></i>&nbsp; Próximas Entradas (14 dias)</h3>
    <?php if (!empty($proximasEntradas)): ?>
    <div class="db-entry-list">
      <?php foreach ($proximasEntradas as $pe): ?>
      <div class="db-entry-item">
        <span class="db-entry-icon <?= $pe['tipo'] === 'colheita' ? 'db-entry-icon-colheita' : 'db-entry-icon-compra' ?>">
          <i class="bi bi-<?= $pe['tipo'] === 'colheita' ? 'flower1' : 'truck' ?>"></i>
        </span>
        <div class="db-entry-body">
          <div class="db-entry-name"><?= htmlspecialchars($pe['produto']) ?></div>
          <div class="db-entry-sub"><?= date('d/m', strtotime($pe['data'])) ?> &middot; <?= htmlspecialchars($pe['origem'] ?? '') ?></div>
        </div>
        <span class="db-entry-kg"><?= number_format((float)$pe['quantidade'], 1, ',', '.') ?> kg</span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="db-no-data"><i class="bi bi-calendar-x" style="font-size:24px;display:block;margin-bottom:8px"></i>Nenhuma entrada prevista nos próximos 14 dias</div>
    <?php endif; ?>
  </div>

  <!-- 6b. Alertas de estoque -->
  <div class="db-panel">
    <h3 class="db-panel-title"><i class="bi bi-bell" style="color:#b91c1c"></i>&nbsp; Alertas de Estoque</h3>
    <div class="db-alert-list">
      <?php foreach ($alertas as $al): ?>
      <div class="db-alert-item">
        <?php if ($al['tipo'] === 'critico'): ?>
          <i class="bi bi-exclamation-octagon-fill db-alert-icon db-alert-critico"></i>
        <?php elseif ($al['tipo'] === 'atencao'): ?>
          <i class="bi bi-exclamation-triangle-fill db-alert-icon db-alert-atencao"></i>
        <?php else: ?>
          <i class="bi bi-info-circle-fill db-alert-icon db-alert-info"></i>
        <?php endif; ?>
        <div class="db-alert-body">
          <div class="db-alert-title"><?= htmlspecialchars($al['titulo']) ?></div>
          <div class="db-alert-sub"><?= htmlspecialchars($al['sub']) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($contNegativo > 0 || $contZerado > 0): ?>
    <a href="#destino" class="btn btn-secondary btn-sm" style="margin-top:14px;align-self:flex-start">
      Ver todos <i class="bi bi-arrow-right"></i>
    </a>
    <?php endif; ?>
  </div>

  <!-- 6c. Ações rápidas -->
  <div class="db-panel">
    <h3 class="db-panel-title"><i class="bi bi-lightning-charge" style="color:var(--color-accent-orange)"></i>&nbsp; Ações Rápidas</h3>
    <div style="display:flex;flex-direction:column;gap:8px">
      <a href="vendas/vendas.php" class="btn btn-primary btn-sm"><i class="bi bi-cart-plus"></i> Nova Venda</a>
      <a href="previsoes/previsao_colheita.php" class="btn btn-secondary btn-sm"><i class="bi bi-flower1"></i> Confirmar Colheita</a>
      <a href="previsoes/previsao_fornecedor.php" class="btn btn-secondary btn-sm"><i class="bi bi-truck"></i> Registrar Compra</a>
      <a href="estoque/estoque_inicial_aba.php" class="btn btn-secondary btn-sm"><i class="bi bi-box-seam"></i> Ajustar Estoque</a>
      <a href="entradas/entradas.php" class="btn btn-secondary btn-sm"><i class="bi bi-clock-history"></i> Histórico de Entradas</a>
      <a href="dashboard/actions/export_api.php" class="btn btn-secondary btn-sm"><i class="bi bi-file-earmark-text"></i> Relatórios Gerais</a>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     7. PAINEL NFe
     ════════════════════════════════════════════════════════════════ -->
<div class="section-card db-nfe-section">

  <div class="db-nfe-header">
    <h2 class="db-panel-title" style="margin:0;font-size:16px">
      <i class="bi bi-file-earmark-text" style="color:var(--color-primary)"></i>&nbsp; Notas Fiscais Eletrônicas
    </h2>
    <div class="db-nfe-header-actions">
      <?php if ($nfeTaxaAut !== null): ?>
      <span class="db-nfe-taxa">Taxa de autorização:&nbsp;<strong><?= $nfeTaxaAut ?>%</strong></span>
      <?php endif; ?>
      <a href="nfe/historico.php" class="btn btn-secondary btn-sm"><i class="bi bi-clock-history"></i> Histórico</a>
      <a href="nfe/index.php"     class="btn btn-primary btn-sm"><i class="bi bi-plus-circle"></i> Emitir NFe</a>
    </div>
  </div>

  <!-- 6 tiles de status -->
  <div class="db-nfe-status-grid">
    <div class="db-nfe-tile db-nfe-tile-aut">
      <div class="db-nfe-tile-val"><?= $nfeStatus['autorizada'] ?></div>
      <div class="db-nfe-tile-lbl">Autorizadas</div>
    </div>
    <div class="db-nfe-tile db-nfe-tile-can">
      <div class="db-nfe-tile-val"><?= $nfeStatus['cancelada'] ?></div>
      <div class="db-nfe-tile-lbl">Canceladas</div>
    </div>
    <div class="db-nfe-tile db-nfe-tile-rej">
      <div class="db-nfe-tile-val"><?= $nfeStatus['rejeitada'] ?></div>
      <div class="db-nfe-tile-lbl">Rejeitadas</div>
    </div>
    <div class="db-nfe-tile db-nfe-tile-proc">
      <div class="db-nfe-tile-val"><?= $nfeStatus['processando'] + $nfeStatus['enviada'] ?></div>
      <div class="db-nfe-tile-lbl">Em processamento</div>
    </div>
    <div class="db-nfe-tile db-nfe-tile-err">
      <div class="db-nfe-tile-val"><?= $nfeStatus['erro'] ?></div>
      <div class="db-nfe-tile-lbl">Erros</div>
    </div>
    <div class="db-nfe-tile db-nfe-tile-peso">
      <div class="db-nfe-tile-val"><?= number_format($nfePesoAutorizado, 0, ',', '.') ?><span style="font-size:13px;font-weight:400"> kg</span></div>
      <div class="db-nfe-tile-lbl">Peso autorizado</div>
    </div>
  </div>

  <!-- Gráfico + tabela de recentes -->
  <div class="db-nfe-body">

    <div class="db-nfe-chart-wrap">
      <div class="db-nfe-chart-header">
        <span style="font-size:13px;font-weight:600;color:var(--color-text)">Emissões — últimos 30 dias</span>
        <div style="display:flex;gap:10px;font-size:12px;color:var(--color-muted)">
          <span><i class="bi bi-square-fill" style="color:#2e7d32;font-size:9px"></i> Autorizadas</span>
          <span><i class="bi bi-square-fill" style="color:#c62828;font-size:9px"></i> Canceladas/Rej.</span>
        </div>
      </div>
      <div style="height:160px;position:relative">
        <canvas id="chartNfe"></canvas>
      </div>
    </div>

    <div class="db-nfe-table-wrap">
      <div style="font-size:13px;font-weight:600;color:var(--color-text);margin-bottom:10px">Últimas NFe emitidas</div>
      <?php if (empty($nfeRecentes)): ?>
        <p style="color:var(--color-muted);font-size:13px;margin:0">Nenhuma NFe emitida em produção ainda.</p>
      <?php else: ?>
      <table class="db-nfe-table">
        <thead>
          <tr><th>Nº</th><th>Destinatário</th><th>Status</th><th>Data</th></tr>
        </thead>
        <tbody>
          <?php foreach ($nfeRecentes as $nf):
            $nfDate = $nf['cancelada_em'] ?: $nf['emitida_em'];
            switch ($nf['status']) {
                case 'autorizada':  $nfBadge = 'badge-normal';   $nfIcon = 'bi-check-circle-fill'; break;
                case 'cancelada':   $nfBadge = 'badge-negativo'; $nfIcon = 'bi-x-circle-fill';     break;
                case 'rejeitada':   $nfBadge = 'badge-zerado';   $nfIcon = 'bi-exclamation-circle-fill'; break;
                case 'processando':
                case 'enviada':     $nfBadge = 'badge-info';     $nfIcon = 'bi-hourglass-split';   break;
                default:            $nfBadge = 'badge-zerado';   $nfIcon = 'bi-dash-circle';       break;
            }
          ?>
          <tr>
            <td class="db-nfe-num"><?= htmlspecialchars($nf['numero_nfe'] ?? '—') ?></td>
            <td class="db-nfe-dest" title="<?= htmlspecialchars($nf['destinatario'] ?? '') ?>">
              <?= htmlspecialchars(mb_substr($nf['destinatario'] ?? '—', 0, 20)) ?>
            </td>
            <td>
              <span class="est-badge <?= $nfBadge ?>" style="font-size:10px;padding:2px 7px">
                <i class="bi <?= $nfIcon ?>"></i> <?= ucfirst($nf['status']) ?>
              </span>
            </td>
            <td class="db-nfe-date"><?= $nfDate ? date('d/m H:i', strtotime($nfDate)) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
      <a href="nfe/historico.php" class="btn btn-secondary btn-sm" style="margin-top:12px;align-self:flex-start">
        Ver histórico completo <i class="bi bi-arrow-right"></i>
      </a>
    </div>

  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     8. TABELA DE ESTOQUE POR PRODUTO
     ════════════════════════════════════════════════════════════════ -->
<section class="stock-section section-card" id="destino">

  <div style="margin-bottom:16px">
    <h2 class="est-title">Estoque por Produto</h2>
    <p class="est-subtitle">
      <?= $totalProdutos ?> produto(s) &middot;
      <?= htmlspecialchars($nomeCiclo) ?>
    </p>
  </div>

  <div class="db-table-toolbar">
    <div class="est-tabs">
      <button class="est-tab est-tab-active" data-status="todos"    onclick="filtrarEstoqueStatus('todos')">Todos (<?= $totalProdutos ?>)</button>
      <button class="est-tab"                data-status="positivo" onclick="filtrarEstoqueStatus('positivo')">Positivo (<?= $contEmEstoque ?>)</button>
      <button class="est-tab"                data-status="negativo" onclick="filtrarEstoqueStatus('negativo')">Negativo (<?= $contNegativo ?>)</button>
      <button class="est-tab"                data-status="zerado"   onclick="filtrarEstoqueStatus('zerado')">Zerado (<?= $contZerado ?>)</button>
    </div>
    <div class="est-search" style="max-width:280px">
      <input type="text" id="pesquisa" onkeyup="pesquisarProduto()" placeholder="Buscar produto...">
    </div>
  </div>

  <div class="table-container">
    <table id="tabelaProdutos">
      <thead>
        <tr>
          <th class="sort-th" onclick="sortEstoque('nome')" style="text-align:left">Produto <span class="sort-icon" data-col="nome">↕</span></th>
          <th class="sort-th" onclick="sortEstoque('estoque')">Estoque Atual <span class="sort-icon" data-col="estoque">↕</span></th>
          <th>Entradas</th>
          <th>Saídas</th>
          <th title="Compras futuras + colheitas futuras">Est. Futuro</th>
          <th>Distribuição</th>
          <th class="sort-th" onclick="sortEstoque('status')">Situação <span class="sort-icon" data-col="status">↕</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($estoqueCalculado as $item):
          $pid     = (int)($item['produto_id'] ?? 0);
          $qtd     = (float)$item['estoque'];
          $qtdFut  = (float)($item['estoque_futuro'] ?? 0);
          $qtdEntrada = (float)($entradasPorProduto[$pid] ?? 0);
          $qtdSaida   = (float)($saidasPorProduto[$pid] ?? 0);
          $pct = $maxEstoque > 0 ? min(100, (int)round((max(0, $qtd) / $maxEstoque) * 100)) : 0;
          if ($qtd > 0)     { $statusKey = 'positivo'; $statusLabel = 'Positivo'; $badgeClass = 'badge-normal';   $barClass = 'bar-normal'; }
          elseif ($qtd < 0) { $statusKey = 'negativo'; $statusLabel = 'Negativo'; $badgeClass = 'badge-negativo'; $barClass = 'bar-critico'; }
          else              { $statusKey = 'zerado';   $statusLabel = 'Zerado';   $badgeClass = 'badge-zerado';   $barClass = 'bar-atencao'; }
          $unidade = htmlspecialchars($item['unidade'] ?? 'kg');
        ?>
        <tr data-nome="<?= htmlspecialchars(mb_strtolower($item['produto']), ENT_QUOTES, 'UTF-8') ?>"
            data-estoque="<?= $qtd ?>"
            data-status="<?= $statusKey ?>">
          <td style="text-align:left">
            <span class="est-nome"><?= htmlspecialchars($item['produto']) ?></span>
          </td>
          <td class="est-qtd <?= $statusKey === 'negativo' ? 'est-qtd-critico' : '' ?>">
            <?= number_format($qtd, 2, ',', '.') ?> <?= $unidade ?>
          </td>
          <td class="db-col-entrada">
            <?= $qtdEntrada > 0 ? number_format($qtdEntrada, 2, ',', '.') . ' ' . $unidade : '<span style="color:#94a3b8">—</span>' ?>
          </td>
          <td class="db-col-venda">
            <?= $qtdSaida > 0 ? number_format($qtdSaida, 2, ',', '.') . ' ' . $unidade : '<span style="color:#94a3b8">—</span>' ?>
          </td>
          <td class="est-futuro">
            <?= number_format($qtdFut, 2, ',', '.') ?> <?= $unidade ?>
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

  <div class="est-pagination">
    <button id="est-prev" class="est-page-btn" onclick="estoquePagAnterior()" disabled>← Anterior</button>
    <span id="est-page-info"></span>
    <button id="est-next" class="est-page-btn" onclick="estoquePagProxima()">Próxima →</button>
  </div>
</section>

<div style="display:flex;gap:12px;justify-content:center;margin-top:20px;flex-wrap:wrap">
  <a href="dashboard/actions/export_api.php" class="btn btn-secondary">
    <i class="bi bi-file-earmark-text"></i> Relatórios Gerais
  </a>
  <button onclick="window.print()" class="btn btn-secondary">
    <i class="bi bi-printer"></i> Gerar PDF
  </button>
</div>

</div><!-- /container -->

<!-- ════════════════════════════════════════════════════════════════
     SCRIPTS
     ════════════════════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ── Dados ──────────────────────────────────────────────────────────────────
const LABELS30   = <?= $chartLabels30 ?>;
const ENTRADAS30 = <?= $chartEntradas30 ?>;
const SAIDAS30   = <?= $chartSaidas30 ?>;

// Cores padrão do sistema
const COR_VERDE  = '#2e7d32';
const COR_LARANJ = '#e89020';

Chart.defaults.font.family = "'Segoe UI', Arial, sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#64748b';

// ── Gráfico de linha dupla: Movimentação ──────────────────────────────────
let chartMov;
function buildChartMov(dias) {
    const labels = LABELS30.slice(-dias);
    const ent    = ENTRADAS30.slice(-dias);
    const sai    = SAIDAS30.slice(-dias);
    const ctx    = document.getElementById('chartMov').getContext('2d');
    if (chartMov) chartMov.destroy();
    chartMov = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'Entradas (kg)',
                    data: ent,
                    borderColor: COR_VERDE,
                    backgroundColor: 'rgba(46,125,50,.10)',
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBackgroundColor: COR_VERDE,
                    fill: true,
                    tension: .35,
                },
                {
                    label: 'Saídas (kg)',
                    data: sai,
                    borderColor: COR_LARANJ,
                    backgroundColor: 'rgba(232,144,32,.10)',
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBackgroundColor: COR_LARANJ,
                    fill: true,
                    tension: .35,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ctx.dataset.label + ': ' + ctx.parsed.y.toFixed(2).replace('.', ',') + ' kg'
                    }
                }
            },
            scales: {
                x: { grid: { color: 'rgba(0,0,0,.04)', drawBorder: false }, ticks: { maxTicksLimit: 10 } },
                y: { grid: { color: 'rgba(0,0,0,.04)', drawBorder: false }, beginAtZero: true,
                     ticks: { callback: v => v.toLocaleString('pt-BR') + ' kg' } }
            }
        }
    });
}
buildChartMov(7);

function setChartRange(dias, btn) {
    document.querySelectorAll('.db-chart-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    buildChartMov(dias);
}

// ── Estoque: filtro + paginação + ordenação ───────────────────────────────
let estoqueStatusFiltro = 'todos';
let estoquePagina       = 1;
const estoquePorPagina  = 20;
let sortCol = null, sortDir = 1;

function filtrarEstoqueStatus(status) {
    estoqueStatusFiltro = status;
    estoquePagina = 1;
    document.querySelectorAll('.est-tab').forEach(btn => {
        btn.classList.toggle('est-tab-active', btn.dataset.status === status);
    });
    aplicarFiltroEstoque();
}

function pesquisarProduto() { estoquePagina = 1; aplicarFiltroEstoque(); }

function aplicarFiltroEstoque() {
    const busca = (document.getElementById('pesquisa')?.value || '').toLowerCase().trim();
    const rows  = Array.from(document.querySelectorAll('#tabelaProdutos tbody tr'));
    const visiveis = rows.filter(tr => {
        const estoque = parseFloat(tr.dataset.estoque || 0);
        const status  = tr.dataset.status || '';
        const nome    = tr.dataset.nome   || '';
        let okStatus = true;
        if      (estoqueStatusFiltro === 'positivo') okStatus = estoque > 0;
        else if (estoqueStatusFiltro === 'negativo') okStatus = estoque < 0;
        else if (estoqueStatusFiltro === 'zerado')   okStatus = estoque === 0;
        return okStatus && (!busca || nome.includes(busca));
    });
    const total      = visiveis.length;
    const totalPages = Math.max(1, Math.ceil(total / estoquePorPagina));
    if (estoquePagina > totalPages) estoquePagina = totalPages;
    const start = (estoquePagina - 1) * estoquePorPagina;
    rows.forEach(tr => tr.style.display = 'none');
    visiveis.slice(start, start + estoquePorPagina).forEach(tr => tr.style.display = '');
    const info = document.getElementById('est-page-info');
    if (info) info.textContent = 'Página ' + estoquePagina + ' de ' + totalPages + ' · ' + total + ' produto(s)';
    if (document.getElementById('est-prev')) document.getElementById('est-prev').disabled = estoquePagina <= 1;
    if (document.getElementById('est-next')) document.getElementById('est-next').disabled = estoquePagina >= totalPages;
}

function estoquePagAnterior() { if (estoquePagina > 1) { estoquePagina--; aplicarFiltroEstoque(); } }
function estoquePagProxima()   { estoquePagina++; aplicarFiltroEstoque(); }

function sortEstoque(col) {
    if (sortCol === col) sortDir *= -1; else { sortCol = col; sortDir = 1; }
    const tbody = document.querySelector('#tabelaProdutos tbody');
    if (!tbody) return;
    Array.from(tbody.querySelectorAll('tr'))
        .sort((a, b) => {
            if (col === 'nome')    return sortDir * (a.dataset.nome || '').localeCompare(b.dataset.nome || '', 'pt-BR');
            if (col === 'estoque') return sortDir * (parseFloat(a.dataset.estoque) - parseFloat(b.dataset.estoque));
            if (col === 'status')  { const o = {positivo:0, negativo:1, zerado:2}; return sortDir * ((o[a.dataset.status]??9)-(o[b.dataset.status]??9)); }
            return 0;
        })
        .forEach(tr => tbody.appendChild(tr));
    document.querySelectorAll('.sort-icon').forEach(el => el.textContent = '↕');
    const icon = document.querySelector('.sort-icon[data-col="' + col + '"]');
    if (icon) icon.textContent = sortDir === 1 ? '↑' : '↓';
    estoquePagina = 1;
    aplicarFiltroEstoque();
}

// ── Gráfico NFe (barras empilhadas 30 dias) ──────────────────────────────
const NFE_LABELS = <?= $nfeChartLabels ?>;
const NFE_AUT    = <?= $nfeChartAut ?>;
const NFE_CAN    = <?= $nfeChartCan ?>;

new Chart(document.getElementById('chartNfe').getContext('2d'), {
    type: 'bar',
    data: {
        labels: NFE_LABELS,
        datasets: [
            {
                label: 'Autorizadas',
                data: NFE_AUT,
                backgroundColor: 'rgba(46,125,50,.78)',
                borderRadius: 3,
                borderSkipped: false,
            },
            {
                label: 'Canceladas / Rejeitadas',
                data: NFE_CAN,
                backgroundColor: 'rgba(198,40,40,.65)',
                borderRadius: 3,
                borderSkipped: false,
            },
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: { mode: 'index', intersect: false }
        },
        scales: {
            x: {
                stacked: true,
                grid: { display: false },
                ticks: { maxTicksLimit: 10, font: { size: 11 } }
            },
            y: {
                stacked: true,
                beginAtZero: true,
                grid: { color: 'rgba(0,0,0,.04)' },
                ticks: {
                    stepSize: 1,
                    callback: v => Number.isInteger(v) ? v : ''
                }
            }
        }
    }
});

document.addEventListener('DOMContentLoaded', () => aplicarFiltroEstoque());
</script>
</body>
</html>

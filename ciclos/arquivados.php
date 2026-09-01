<?php
require_once __DIR__ . "/../config/conexao.php";
require_once __DIR__ . "/../config/ciclo_helper.php";
require_once __DIR__ . "/../auth/proteger.php";
require_once __DIR__ . "/../config/permissions.php";
require_once __DIR__ . "/../config/layout_helper.php";

requireModule('ciclos', '../index.php');

// Ciclo alvo: parametro ou o ativo
$cicloId = isset($_GET['ciclo_id']) ? (int) $_GET['ciclo_id'] : 0;
if ($cicloId <= 0) {
    $ativo = getCicloAtivo($conexao);
    $cicloId = $ativo ? (int) $ativo['id'] : 0;
}

$ciclo = null;
if ($cicloId > 0) {
    $stmt = $conexao->prepare("SELECT * FROM ciclos WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $cicloId);
    $stmt->execute();
    $ciclo = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

// Lista de ciclos para o seletor
$ciclosLista = array();
$resC = $conexao->query("SELECT id, nome, ano, mes, status FROM ciclos ORDER BY ano DESC, mes DESC, id DESC");
if ($resC) { while ($c = $resC->fetch_assoc()) { $ciclosLista[] = $c; } }

$tipoLabels = array(
    'venda' => 'Vendas',
    'entrada' => 'Entradas / compras',
    'colheita' => 'Colheitas',
    'movimentacao' => 'Movimentações de estoque',
    'nfe' => 'NF-e',
    'boleto' => 'Boletos',
    'previsao_fornecedor' => 'Previsões de fornecedor',
    'previsao_colheita' => 'Previsões de colheita',
    'abate' => 'Abates',
);

$resumo = array();
$linhas = array();
$tabelaSnapshotExiste = false;
$chk = $conexao->query("SHOW TABLES LIKE 'ciclo_snapshot_registros'");
$tabelaSnapshotExiste = ($chk && $chk->num_rows > 0);

if ($tabelaSnapshotExiste && $cicloId > 0) {
    $tipoFiltro = trim((string) ($_GET['tipo'] ?? ''));
    $colheitaSql = "(tipo = 'previsao_colheita'
        OR (tipo IN ('entrada','abate') AND (
            payload_json LIKE '%\"tipo\":\"colheita\"%'
            OR payload_json LIKE '%\"tipo\": \"colheita\"%'
            OR payload_json LIKE '%\"tipo\":\"entrada_colheita\"%'
            OR payload_json LIKE '%\"tipo\": \"entrada_colheita\"%'
        )))";
    $tipoAgrupadoSql = "CASE WHEN {$colheitaSql} THEN 'colheita' ELSE tipo END";

    // Resumo por tipo
    $stmt = $conexao->prepare("
        SELECT {$tipoAgrupadoSql} AS tipo, COUNT(*) qtd,
               IFNULL(SUM(quantidade),0) total_qtd,
               IFNULL(SUM(valor),0) total_valor
        FROM ciclo_snapshot_registros
        WHERE ciclo_id = ?
        GROUP BY {$tipoAgrupadoSql}
        ORDER BY qtd DESC
    ");
    $stmt->bind_param('i', $cicloId);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($x = $r->fetch_assoc()) { $resumo[] = $x; }
    $stmt->close();

    // Listagem (limitada) — filtra por tipo se informado
    if ($tipoFiltro !== '') {
        if ($tipoFiltro === 'colheita') {
            $whereTipoFiltro = "AND {$colheitaSql}";
            $bindTipo = false;
        } else {
            $whereTipoFiltro = "AND {$tipoAgrupadoSql} = ?";
            $bindTipo = true;
        }
        $stmt = $conexao->prepare("
            SELECT {$tipoAgrupadoSql} AS tipo, numero_os, produto_nome, pessoa_nome, data_registro, quantidade, valor, status, origem_tabela, origem_id
            FROM ciclo_snapshot_registros
            WHERE ciclo_id = ? {$whereTipoFiltro}
            ORDER BY data_registro DESC, id DESC
            LIMIT 500
        ");
        if ($bindTipo) {
            $stmt->bind_param('is', $cicloId, $tipoFiltro);
        } else {
            $stmt->bind_param('i', $cicloId);
        }
    } else {
        $stmt = $conexao->prepare("
            SELECT {$tipoAgrupadoSql} AS tipo, numero_os, produto_nome, pessoa_nome, data_registro, quantidade, valor, status, origem_tabela, origem_id
            FROM ciclo_snapshot_registros
            WHERE ciclo_id = ?
            ORDER BY data_registro DESC, id DESC
            LIMIT 500
        ");
        $stmt->bind_param('i', $cicloId);
    }
    $stmt->execute();
    $r = $stmt->get_result();
    while ($x = $r->fetch_assoc()) { $linhas[] = $x; }
    $stmt->close();
}

function arqNum($v) { return number_format((float) $v, 2, ',', '.'); }

// LAYOUT NOVO (UX/UI): dados do gráfico de composição (derivado de $resumo, sem query nova).
$arqChartLabels = [];
$arqChartData = [];
foreach ($resumo as $rs) {
    $arqChartLabels[] = $tipoLabels[$rs['tipo']] ?? $rs['tipo'];
    $arqChartData[]   = (int) $rs['qtd'];
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Arquivados do Ciclo</title>
<!-- LAYOUT NOVO (UX/UI): Chart.js 4.4.0 — mesma versão já usada no sistema -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ===== LAYOUT NOVO (UX/UI) — escopo .cic-arq; paleta ORIGINAL do sistema ===== */
.cic-arq{
    --g50:#f1f8f4;--g100:#dcefe1;--g200:#a5d6a7;--g300:#8cc79e;--g600:#2e7d32;--g700:#1f6d23;--g800:#1b5e20;
    --ink-1:#222;--ink-2:#3a3f3a;--ink-3:#5F5E5A;--line-1:#e2e5df;--line-2:#eef0ec;--surf:#fff;--surf-2:#fbfcfb;
    --sh-1:0 1px 2px rgba(20,24,20,.05),0 1px 1px rgba(20,24,20,.03);--sh-2:0 2px 8px rgba(20,24,20,.07);
    --r:12px;--ease:cubic-bezier(.2,0,0,1);
    max-width:1320px;margin:0 auto;color:var(--ink-1);font-family:'Segoe UI',Arial,sans-serif;font-size:14px;
}
.cic-arq *{box-sizing:border-box;}
.cic-arq h1,.cic-arq h2,.cic-arq h3,.cic-arq p{margin:0;}
.cd-back{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--ink-3);text-decoration:none;margin-bottom:14px;}
.cd-back:hover{color:var(--g700);}
.cd-head{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin-bottom:18px;flex-wrap:wrap;}
.cd-eyebrow{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-3);}
.cd-head h1{font-size:28px;font-weight:700;letter-spacing:-.01em;margin-top:6px;}
.cd-head p{margin-top:6px;color:var(--ink-3);font-size:14px;max-width:560px;}
.cd-select{padding:9px 14px;border-radius:8px;border:1px solid var(--line-1);background:var(--surf);font:600 14px 'Segoe UI',Arial,sans-serif;color:var(--ink-1);cursor:pointer;}
.cd-card{background:var(--surf);border:1px solid var(--line-1);border-radius:var(--r);box-shadow:var(--sh-1);margin-bottom:18px;}
.cd-card-pad{padding:18px 22px;}
.cd-sub-h{font-size:16px;font-weight:600;color:var(--ink-2);margin-bottom:12px;}
.cd-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;}
.cd-kpi{background:var(--surf-2);border:1px solid var(--line-1);border-radius:var(--r);box-shadow:var(--sh-1);padding:16px 18px;transition:transform .18s var(--ease),box-shadow .18s var(--ease);}
.cd-kpi:hover{transform:translateY(-2px);box-shadow:var(--sh-2);}
.cd-kpi a{text-decoration:none;color:inherit;display:block;}
.cd-kpi .t{display:flex;justify-content:space-between;align-items:flex-start;}
.cd-kpi .t span{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-3);}
.cd-kpi .ic{width:28px;height:28px;border-radius:6px;background:var(--g50);color:var(--g700);display:flex;align-items:center;justify-content:center;font-size:14px;}
.cd-kpi .n{font-size:26px;font-weight:700;color:var(--g800);margin-top:8px;font-variant-numeric:tabular-nums;}
.cd-kpi .s{font-size:12px;color:var(--ink-3);margin-top:4px;}
.cd-canvas{height:300px;padding:8px 18px 18px;}
.cd-tablecard{overflow:hidden;}
.cd-table-h{padding:16px 20px;border-bottom:1px solid var(--line-2);display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.cd-table-h h3{font-size:16px;font-weight:600;}
.cic-arq .table-wrap{overflow-x:auto;}
.cic-arq table{width:100%;border-collapse:collapse;min-width:760px;font-size:13px;}
.cic-arq thead th{background:var(--g600);color:#fff;text-align:left;font-weight:600;font-size:11px;letter-spacing:.06em;text-transform:uppercase;padding:11px 18px;}
.cic-arq tbody td{padding:12px 18px;border-bottom:1px solid var(--line-2);text-align:left;}
.cic-arq tbody tr:hover td{background:var(--g50);}
.cd-badge{display:inline-flex;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:600;background:var(--g50);border:1px solid var(--g200);color:var(--g800);}
.num{font-variant-numeric:tabular-nums;}
.empty{color:var(--ink-3);font-style:italic;padding:16px;}
.lnk{color:var(--g700);text-decoration:none;font-weight:600;}
.notice{background:var(--surf);border:1px solid var(--line-1);border-radius:var(--r);box-shadow:var(--sh-1);padding:22px;color:var(--ink-3);}
@media (max-width:768px){.cd-head{flex-direction:column;align-items:flex-start;}}
</style>
<?php renderAppLayoutStyles(); ?>
</head>
<body>
<?php renderAppHeader('..'); ?>
<main class="container page-container app-shell">
<!-- LAYOUT NOVO (UX/UI): wrapper de escopo dos arquivados -->
<div class="cic-arq">

  <a class="cd-back" href="index.php"><i class="bi bi-arrow-left"></i>Voltar para Ciclos</a>

  <div class="cd-head">
    <div>
      <span class="cd-eyebrow"><i class="bi bi-archive"></i> Arquivo do ciclo</span>
      <h1>Arquivados do Ciclo</h1>
      <p>Tudo que foi limpo do operacional (zerar contagem ou fechamento de mês) fica preservado aqui, sem perda de informação.</p>
    </div>
    <form method="GET">
      <select class="cd-select" name="ciclo_id" onchange="this.form.submit()">
        <?php foreach ($ciclosLista as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= ((int) $c['id'] === $cicloId ? 'selected' : '') ?>>
            <?= htmlspecialchars($c['nome'] ?: ($c['mes'] . '/' . $c['ano'])) ?> (<?= htmlspecialchars($c['status']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if (!$tabelaSnapshotExiste): ?>
    <div class="notice">Ainda não há registros arquivados (a tabela de arquivamento será criada no primeiro Zerar/fechamento).</div>
  <?php elseif (!$resumo): ?>
    <div class="notice">Nenhum registro arquivado para este ciclo ainda.</div>
  <?php else: ?>

    <!-- LAYOUT NOVO (UX/UI): resumo por tipo (cards clicáveis = filtro) -->
    <h3 class="cd-sub-h">Resumo por tipo</h3>
    <div class="cd-cards" style="margin-bottom:18px;">
      <?php foreach ($resumo as $rs): $tp = $rs['tipo']; ?>
        <div class="cd-kpi">
          <a href="?ciclo_id=<?= $cicloId ?>&tipo=<?= urlencode($tp) ?>">
            <div class="t">
              <span><?= htmlspecialchars($tipoLabels[$tp] ?? $tp) ?></span>
              <div class="ic"><i class="bi bi-box-seam"></i></div>
            </div>
            <div class="n"><?= (int) $rs['qtd'] ?></div>
            <div class="s">Qtd <?= arqNum($rs['total_qtd']) ?> · R$ <?= arqNum($rs['total_valor']) ?></div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- LAYOUT NOVO (UX/UI): gráfico de composição -->
    <div class="cd-card">
      <div class="cd-card-pad" style="padding-bottom:6px;"><h3 style="font-size:16px;font-weight:600;">Composição dos arquivados</h3><p style="color:var(--ink-3);font-size:12px;margin-top:4px;">registros por tipo</p></div>
      <div class="cd-canvas"><canvas id="chartArquivados"></canvas></div>
    </div>

    <!-- LAYOUT NOVO (UX/UI): tabela de registros -->
    <div class="cd-card cd-tablecard">
      <div class="cd-table-h">
        <h3>Registros<?php if (!empty($_GET['tipo'])): ?> — <?= htmlspecialchars($tipoLabels[$_GET['tipo']] ?? $_GET['tipo']) ?> <a class="lnk" href="?ciclo_id=<?= $cicloId ?>">(ver todos)</a><?php endif; ?></h3>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Tipo</th><th>OS</th><th>Produto</th><th>Pessoa</th><th>Data</th><th>Qtd.</th><th>Valor</th><th>Status</th></tr></thead>
          <tbody>
          <?php if (!$linhas): ?>
            <tr><td colspan="8" class="empty">Sem registros.</td></tr>
          <?php endif; ?>
          <?php foreach ($linhas as $l): ?>
            <tr>
              <td><span class="cd-badge"><?= htmlspecialchars($tipoLabels[$l['tipo']] ?? $l['tipo']) ?></span></td>
              <td class="num"><?= htmlspecialchars($l['numero_os'] ?: '-') ?></td>
              <td><?= htmlspecialchars($l['produto_nome'] ?: '-') ?></td>
              <td><?= htmlspecialchars($l['pessoa_nome'] ?: '-') ?></td>
              <td><?= !empty($l['data_registro']) ? htmlspecialchars(date('d/m/Y', strtotime((string) $l['data_registro']))) : '-' ?></td>
              <td class="num"><?= $l['quantidade'] !== null ? arqNum($l['quantidade']) : '-' ?></td>
              <td class="num"><?= $l['valor'] !== null ? ('R$ ' . arqNum($l['valor'])) : '-' ?></td>
              <td><?= htmlspecialchars($l['status'] ?: '-') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($linhas) >= 500): ?><p class="empty" style="padding:12px 20px;">Mostrando os primeiros 500 registros.</p><?php endif; ?>
    </div>

    <!-- LAYOUT NOVO (UX/UI): inicialização do gráfico -->
    <script>
    (function(){
        if (typeof Chart === 'undefined') { return; }
        var el = document.getElementById('chartArquivados');
        if (!el) { return; }
        Chart.defaults.font.family = "'Segoe UI', Arial, sans-serif";
        Chart.defaults.color = '#5F5E5A';
        new Chart(el, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($arqChartLabels, JSON_UNESCAPED_UNICODE) ?>,
                datasets: [{ data: <?= json_encode($arqChartData) ?>,
                    backgroundColor: ['#1b5e20','#2e7d32','#3f9357','#5fae78','#8cc79e','#b8dec3','#dcefe1','#a05a2c','#a93b2e'],
                    borderColor: '#fff', borderWidth: 2 }]
            },
            options: { responsive: true, maintainAspectRatio: false, cutout: '58%',
                plugins: { legend: { position: 'right', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, padding: 10, font: { size: 12 } } } }
            }
        });
    })();
    </script>

  <?php endif; ?>

</div><!-- /.cic-arq -->
</main>
</body>
</html>

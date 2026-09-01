<?php
// --- SEÇÃO: autenticação e conexão PDO ---
require_once __DIR__ . '/../auth/proteger.php';

if (file_exists(__DIR__ . '/../includes/db.php')) {
    require_once __DIR__ . '/../includes/db.php';
} else {
    require_once __DIR__ . '/../config/conexao.php';
    // Reaproveita a mesma configuracao do mysqli para evitar divergencia de conexao.
    $dsn = sprintf(
        'mysql:host=%s;%sdbname=%s;charset=utf8mb4',
        $host,
        isset($porta) && $porta !== '' ? 'port=' . (int) $porta . ';' : '',
        $banco
    );
    $pdo = new PDO(
        $dsn,
        $usuario,
        $senha,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

require_once __DIR__ . '/../includes/ciclo_helper.php';
require_once __DIR__ . '/../config/layout_helper.php';
require_once __DIR__ . '/../config/permissions.php';

requireModule('ciclos', '../index.php');

// --- SEÇÃO: utilidades locais ---
function formatDataHoraCiclo(?string $valor): string
{
    return empty($valor) ? '-' : date('d/m/Y H:i', strtotime($valor));
}

function formatDataCiclo(?string $valor): string
{
    return empty($valor) ? '-' : date('d/m/Y', strtotime($valor));
}

function estoqueSnapshotParaArray(?string $json): array
{
    if (empty($json)) {
        return [];
    }

    $dados = json_decode($json, true);
    return is_array($dados) ? $dados : [];
}

// --- SEÇÃO: ciclo aberto ---
$cicloAtivo = getCicloAtivo($pdo);
$metricasAtivo = ['total_vendas_kg' => 0, 'total_entradas_kg' => 0, 'total_abates_kg' => 0];
$diasDecorridos = 0;
$diasParaProximoMes = 0;

if ($cicloAtivo) {
    $criadoEm = new DateTime($cicloAtivo['criado_em'] ?? 'now');
    $hoje = new DateTime('today');
    $diasDecorridos = (int) $criadoEm->diff($hoje)->days;

    $proximoMes = new DateTime(sprintf('%04d-%02d-01', (int) $cicloAtivo['ano'], (int) $cicloAtivo['mes']));
    $proximoMes->modify('first day of next month');
    $diasParaProximoMes = (int) $hoje->diff($proximoMes)->days;

    $usaCicloVendas = colunaExistePdo($pdo, 'vendas', 'ciclo_id');
    $usaCicloEntradas = colunaExistePdo($pdo, 'entradas', 'ciclo_id');
    $usaCicloAbates = colunaExistePdo($pdo, 'abates', 'ciclo_id');
    // LAYOUT NOVO (UX/UI) — correção de dados: Vendas conta apenas status 'concluido',
    // igual a calcularResumoCiclo()/snapshot, para o Dashboard bater com o Detalhe.
    $sqlMetricasAtivo = "SELECT
        (SELECT COALESCE(SUM(quantidade), 0) FROM vendas WHERE status = 'concluido'" . ($usaCicloVendas ? " AND ciclo_id = :ciclo_vendas" : "") . ") AS total_vendas_kg,
        (SELECT COALESCE(SUM(quantidade), 0) FROM entradas" . ($usaCicloEntradas ? " WHERE ciclo_id = :ciclo_entradas" : "") . ") AS total_entradas_kg,
        (SELECT COALESCE(SUM(quantidade_abatida), 0) FROM abates" . ($usaCicloAbates ? " WHERE ciclo_id = :ciclo_abates" : "") . ") AS total_abates_kg";

    $stmtMetricasAtivo = $pdo->prepare($sqlMetricasAtivo);
    $paramsMetricas = [];
    if ($usaCicloVendas) {
        $paramsMetricas[':ciclo_vendas'] = (int) $cicloAtivo['id'];
    }
    if ($usaCicloEntradas) {
        $paramsMetricas[':ciclo_entradas'] = (int) $cicloAtivo['id'];
    }
    if ($usaCicloAbates) {
        $paramsMetricas[':ciclo_abates'] = (int) $cicloAtivo['id'];
    }
    $stmtMetricasAtivo->execute($paramsMetricas);
    $metricasAtivo = $stmtMetricasAtivo->fetch() ?: $metricasAtivo;
}

// --- SEÇÃO: ciclos fechados ---
$stmtFechados = $pdo->prepare(
    "SELECT
        c.id,
        c.nome,
        c.ano,
        c.mes,
        c.status,
        COALESCE(csr.total_vendas_kg, cs.total_vendas_kg, 0) AS total_vendas_kg,
        COALESCE(csr.total_entradas_kg, cs.total_entradas_kg, 0) AS total_entradas_kg,
        COALESCE(cs.total_abates_kg, 0) AS total_abates_kg,
        COALESCE(csr.qtd_vendas, cs.qtd_vendas, 0) AS qtd_vendas,
        COALESCE(csr.qtd_entradas, cs.qtd_entradas, 0) AS qtd_entradas,
        COALESCE(cs.qtd_abates, 0) AS qtd_abates,
        COALESCE(cs.total_previsoes_forn, 0) AS total_previsoes_forn,
        COALESCE(cs.total_previsoes_colh, 0) AS total_previsoes_colh,
        cs.estoque_final_json
    FROM ciclos c
    LEFT JOIN ciclo_snapshot cs ON cs.ciclo_id = c.id
    LEFT JOIN (
        SELECT ciclo_id,
               SUM(CASE WHEN tipo = 'venda' THEN quantidade END) AS total_vendas_kg,
               SUM(CASE WHEN tipo = 'entrada' THEN quantidade END) AS total_entradas_kg,
               SUM(CASE WHEN tipo = 'venda' THEN 1 END) AS qtd_vendas,
               SUM(CASE WHEN tipo = 'entrada' THEN 1 END) AS qtd_entradas
        FROM ciclo_snapshot_registros
        WHERE acao_fechamento = 'arquivar_zerar'
        GROUP BY ciclo_id
    ) csr ON csr.ciclo_id = c.id
    WHERE c.status = :status
    ORDER BY c.ano DESC, c.mes DESC, c.id DESC"
);
$stmtFechados->execute([':status' => 'fechado']);
$ciclosFechados = $stmtFechados->fetchAll();
$permiteGerenciarCiclo = ($_SESSION['usuario_nivel'] ?? '') === 'admin';

// === LAYOUT NOVO (UX/UI): preparação de dados para os gráficos =================
// Camada de APRESENTAÇÃO apenas. Reaproveita os arrays já carregados acima
// ($ciclosFechados, $cicloAtivo, $metricasAtivo) — nenhuma query/lógica nova.
$serieCiclos = array_reverse(array_slice($ciclosFechados, 0, 6)); // 6 últimos, ordem cronológica

$chartLabels = [];
$chartVendas = [];
$chartEntradas = [];
$chartAbates = [];
foreach ($serieCiclos as $cic) {
    $chartLabels[]   = nomeMesCurto($cic['mes']) . '/' . substr((string) $cic['ano'], -2);
    $chartVendas[]   = round((float) $cic['total_vendas_kg'], 2);
    $chartEntradas[] = round((float) $cic['total_entradas_kg'], 2);
    $chartAbates[]   = round((float) $cic['total_abates_kg'], 2);
}
if ($cicloAtivo) {
    $chartLabels[]   = nomeMesCurto($cicloAtivo['mes']) . '/' . substr((string) $cicloAtivo['ano'], -2);
    $chartVendas[]   = round((float) $metricasAtivo['total_vendas_kg'], 2);
    $chartEntradas[] = round((float) $metricasAtivo['total_entradas_kg'], 2);
    $chartAbates[]   = round((float) $metricasAtivo['total_abates_kg'], 2);
}

// Donut "Composição do estoque": usa o snapshot do fechamento mais recente que tiver dados.
$estoqueComposicao = [];
foreach ($ciclosFechados as $cic) {
    $itens = estoqueSnapshotParaArray($cic['estoque_final_json'] ?? null);
    if (!empty($itens)) {
        foreach ($itens as $it) {
            $nomeProd = $it['produto'] ?? null;
            $kgProd   = (float) ($it['estoque'] ?? 0);
            if ($nomeProd !== null && $kgProd > 0) {
                $estoqueComposicao[] = ['produto' => $nomeProd, 'kg' => round($kgProd, 2)];
            }
        }
        break;
    }
}
usort($estoqueComposicao, static fn($a, $b) => $b['kg'] <=> $a['kg']);
$estLabels = [];
$estData = [];
foreach (array_slice($estoqueComposicao, 0, 6) as $e) {
    $estLabels[] = $e['produto'];
    $estData[]   = $e['kg'];
}
$restoEstoque = array_slice($estoqueComposicao, 6);
if (!empty($restoEstoque)) {
    $estLabels[] = 'Outros';
    $estData[]   = round(array_sum(array_column($restoEstoque, 'kg')), 2);
}

// KPIs do ciclo aberto + variação vs. último ciclo fechado (real).
$ultimoFechado = $ciclosFechados[0] ?? null;
$kpiDelta = static function (float $atual, ?array $ref, string $campo): ?float {
    if (!$ref || (float) $ref[$campo] <= 0) {
        return null;
    }
    return ($atual - (float) $ref[$campo]) / (float) $ref[$campo] * 100;
};
$kpis = [
    [
        'label' => 'Vendas', 'icon' => 'cart-check',
        'iconBg' => '#e8f5e9', 'iconFg' => '#2e7d32',
        'value' => (float) $metricasAtivo['total_vendas_kg'],
        'delta' => $kpiDelta((float) $metricasAtivo['total_vendas_kg'], $ultimoFechado, 'total_vendas_kg'),
        'series' => $chartVendas,
    ],
    [
        'label' => 'Entradas', 'icon' => 'box-arrow-in-down',
        'iconBg' => '#f5e8db', 'iconFg' => '#a05a2c',
        'value' => (float) $metricasAtivo['total_entradas_kg'],
        'delta' => $kpiDelta((float) $metricasAtivo['total_entradas_kg'], $ultimoFechado, 'total_entradas_kg'),
        'series' => $chartEntradas,
    ],
    [
        'label' => 'Abates', 'icon' => 'exclamation-triangle',
        'iconBg' => '#fbe6e3', 'iconFg' => '#a93b2e',
        'value' => (float) $metricasAtivo['total_abates_kg'],
        'delta' => $kpiDelta((float) $metricasAtivo['total_abates_kg'], $ultimoFechado, 'total_abates_kg'),
        'series' => $chartAbates,
    ],
];

// Progresso do mês (barra do banner).
$diasTotaisMes = $diasDecorridos + $diasParaProximoMes;
$progressoPct = $diasTotaisMes > 0 ? min(100, max(0, round($diasDecorridos / $diasTotaisMes * 100))) : 0;
// === FIM da preparação de dados ===============================================

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ciclos mensais</title>
    <!-- LAYOUT NOVO (UX/UI): Chart.js 4.4.0 — mesma versão já usada no sistema -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        /* ===== LAYOUT NOVO (UX/UI) — escopo .cic-dash; paleta ORIGINAL do sistema ===== */
        .cic-dash{
            --g50:#f1f8f4;--g100:#dcefe1;--g200:#a5d6a7;--g300:#8cc79e;--g500:#3f9357;
            --g600:#2e7d32;--g700:#1f6d23;--g800:#1b5e20;
            --ink-1:#222;--ink-2:#3a3f3a;--ink-3:#5F5E5A;--ink-4:#9ba29a;
            --line-1:#e2e5df;--line-2:#eef0ec;--surf:#fff;--surf-2:#fbfcfb;
            --sh-1:0 1px 2px rgba(20,24,20,.05),0 1px 1px rgba(20,24,20,.03);
            --sh-2:0 2px 8px rgba(20,24,20,.07);
            --sh-3:0 8px 24px rgba(20,24,20,.10);
            --r:12px;--ease:cubic-bezier(.2,0,0,1);
            max-width:1320px;margin:0 auto;color:var(--ink-1);
            font-family:'Segoe UI',Arial,sans-serif;font-size:14px;
        }
        .cic-dash *{box-sizing:border-box;}
        .cic-dash h1,.cic-dash h2,.cic-dash h3,.cic-dash p{margin:0;}

        /* mensagens (estilo original preservado) */
        .cic-dash .msg-sucesso{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600;}
        .cic-dash .msg-erro{background:#ffebee;color:#c62828;border:1px solid #ef9a9a;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600;}

        /* page header */
        .cd-head{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin-bottom:22px;flex-wrap:wrap;}
        .cd-eyebrow{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-3);}
        .cd-head h1{font-size:28px;font-weight:700;letter-spacing:-.01em;margin-top:6px;}
        .cd-head p{margin-top:6px;color:var(--ink-3);font-size:14px;}
        .cd-actions{display:flex;gap:8px;flex-wrap:wrap;}

        /* botões (cores originais) */
        .cd-btn{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border-radius:8px;background:var(--surf);border:1px solid var(--line-1);color:var(--ink-1);font:600 14px 'Segoe UI',Arial,sans-serif;cursor:pointer;text-decoration:none;transition:background .15s var(--ease);}
        .cd-btn:hover{background:#f4f6f4;}
        .cd-btn-primary{background:var(--g800);border-color:var(--g800);color:#fff;}
        .cd-btn-primary:hover{background:var(--g700);}
        .cd-btn-light{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);color:#fff;}
        .cd-btn-light:hover{background:rgba(255,255,255,.22);}
        .cd-btn-white{background:#fff;border:none;color:var(--g800);}

        /* banner ciclo aberto */
        .cd-banner{background:var(--g800);color:#fff;border-radius:16px;padding:22px 26px;margin-bottom:18px;display:flex;justify-content:space-between;gap:24px;flex-wrap:wrap;}
        .cd-banner .cd-eyebrow{color:rgba(255,255,255,.6);}
        .cd-banner-title{font-size:26px;font-weight:700;letter-spacing:-.01em;margin:6px 0 4px;}
        .cd-banner-sub{color:rgba(255,255,255,.78);font-size:13px;}
        .cd-banner-left{min-width:240px;}
        .cd-banner-right{flex:1;min-width:260px;display:flex;flex-direction:column;justify-content:center;}
        .cd-prog-top{display:flex;justify-content:space-between;font-size:12px;color:rgba(255,255,255,.78);margin-bottom:8px;}
        .cd-prog-track{height:10px;border-radius:999px;background:rgba(255,255,255,.18);overflow:hidden;}
        .cd-prog-fill{height:100%;background:var(--g300);border-radius:999px;}
        .cd-prog-foot{display:flex;justify-content:space-between;font-size:11px;color:rgba(255,255,255,.55);margin-top:6px;}

        /* KPI cards */
        .cd-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:18px;}
        .cd-kpi{background:var(--surf);border:1px solid var(--line-1);border-radius:var(--r);padding:18px 20px;box-shadow:var(--sh-1);transition:transform .18s var(--ease),box-shadow .18s var(--ease);}
        .cd-kpi:hover{transform:translateY(-2px);box-shadow:var(--sh-3);}
        .cd-kpi-top{display:flex;justify-content:space-between;align-items:center;}
        .cd-kpi-label{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-3);}
        .cd-kpi-ic{width:30px;height:30px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:15px;}
        .cd-kpi-mid{display:flex;align-items:flex-end;justify-content:space-between;gap:8px;margin-top:12px;}
        .cd-kpi-val{font-size:28px;font-weight:700;line-height:1;color:var(--g800);font-variant-numeric:tabular-nums;}
        .cd-kpi-val small{font-size:13px;color:var(--ink-3);font-weight:500;margin-left:4px;}
        .cd-spark{width:96px;height:30px;flex:none;}
        .cd-kpi-delta{display:flex;align-items:center;gap:5px;margin-top:10px;font-size:11px;font-weight:600;}
        .cd-delta-up{color:#2e7d32;}.cd-delta-down{color:#b91c1c;}.cd-delta-flat{color:var(--ink-3);}
        .cd-kpi-delta .ref{color:var(--ink-3);font-weight:400;}

        /* cards genéricos / gráficos */
        .cd-card{background:var(--surf);border:1px solid var(--line-1);border-radius:var(--r);box-shadow:var(--sh-1);}
        .cd-card-h{padding:18px 22px 6px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;}
        .cd-card-h h3{font-size:18px;font-weight:600;}
        .cd-card-h p{margin-top:4px;color:var(--ink-3);font-size:12px;}
        .cd-legend{display:flex;gap:14px;font-size:12px;color:var(--ink-2);flex-wrap:wrap;}
        .cd-legend i{font-size:9px;margin-right:5px;}
        .cd-canvas{padding:8px 18px 18px;}
        .cd-charts{display:grid;grid-template-columns:1.4fr 1fr;gap:18px;margin-bottom:18px;}

        /* ciclos fechados */
        .cd-closed-h{padding:18px 22px 14px;border-bottom:1px solid var(--line-2);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;}
        .cd-closed-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;padding:18px 22px;}
        .cd-closed-card{border:1px solid var(--line-1);border-radius:var(--r);padding:16px 18px;cursor:pointer;background:var(--surf-2);text-decoration:none;color:inherit;display:block;transition:transform .18s var(--ease),box-shadow .18s var(--ease),border-color .18s;}
        .cd-closed-card:hover{transform:translateY(-2px);box-shadow:var(--sh-2);border-color:var(--g300);}
        .cd-cc-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;}
        .cd-cc-mes{font-size:16px;font-weight:700;color:var(--ink-1);}
        .cd-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:600;border:1px solid #a5d6a7;background:#e8f5e9;color:#2e7d32;}
        .cd-cc-rows{display:flex;flex-direction:column;gap:9px;}
        .cd-cc-row{display:flex;justify-content:space-between;font-size:13px;}
        .cd-cc-row span{color:var(--ink-3);}
        .cd-cc-row i{margin-right:6px;}
        .cd-cc-row b{font-variant-numeric:tabular-nums;}
        .cd-cc-foot{display:flex;justify-content:space-between;border-top:1px dashed var(--line-1);margin-top:13px;padding-top:12px;font-size:12px;color:var(--ink-3);}
        .cd-cc-foot .lk{color:var(--g700);font-weight:600;}
        .cd-empty{color:var(--ink-3);font-style:italic;padding:8px 0;}

        @media (max-width:1024px){.cd-charts{grid-template-columns:1fr;}}
        @media (max-width:768px){
            .cd-kpis{grid-template-columns:1fr;}
            .cd-head{flex-direction:column;align-items:flex-start;}
            .cd-banner{flex-direction:column;}
        }
    </style>
    <?php renderAppLayoutStyles(); ?>
</head>
<body>
    <div class="page">
        <?php renderAppHeader('..'); ?>
        <div class="content-area page-container app-shell">
        <!-- LAYOUT NOVO (UX/UI): wrapper de escopo do dashboard -->
        <div class="cic-dash">

        <?php /* mensagens de status — lógica original preservada */ ?>
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'fechado'): ?>
            <div class="msg-sucesso">Ciclo encerrado e próximo ciclo aberto com sucesso.</div>
        <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'desfeito'): ?>
            <div class="msg-sucesso">Fechamento desfeito e ciclo anterior reaberto com sucesso.</div>
        <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'erro'): ?>
            <div class="msg-erro"><?= htmlspecialchars($_GET['detalhe'] ?? 'Erro ao processar o ciclo.'); ?></div>
        <?php endif; ?>

        <!-- LAYOUT NOVO (UX/UI): cabeçalho da página -->
        <div class="cd-head">
            <div>
                <span class="cd-eyebrow">Ciclos mensais</span>
                <h1>Visão do ciclo</h1>
                <p>Acompanhe o ciclo em aberto e o histórico de fechamentos.</p>
            </div>
            <div class="cd-actions">
                <a class="cd-btn" href="arquivados.php<?= $cicloAtivo ? '?ciclo_id=' . (int) $cicloAtivo['id'] : '' ?>"><i class="bi bi-archive"></i>Arquivados</a>
                <?php if ($permiteGerenciarCiclo): ?>
                    <a class="cd-btn cd-btn-primary" href="novo_ciclo.php"><i class="bi bi-box-arrow-in-right"></i>Prévia do novo ciclo</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($cicloAtivo): ?>
            <!-- LAYOUT NOVO (UX/UI): banner do ciclo aberto + progresso (dados reais) -->
            <div class="cd-banner">
                <div class="cd-banner-left">
                    <span class="cd-eyebrow">Ciclo em aberto</span>
                    <div class="cd-banner-title"><?= htmlspecialchars($cicloAtivo['nome'] ?: (nomeMesCurto($cicloAtivo['mes']) . ' ' . $cicloAtivo['ano'])) ?></div>
                    <div class="cd-banner-sub">Criado em <?= formatDataHoraCiclo($cicloAtivo['criado_em'] ?? null) ?> · <?= $diasDecorridos ?> de <?= $diasTotaisMes ?> dias decorridos</div>
                    <div class="cd-actions" style="margin-top:16px;">
                        <a class="cd-btn cd-btn-white" href="detalhe.php?id=<?= (int) $cicloAtivo['id'] ?>"><i class="bi bi-eye"></i>Ver detalhes</a>
                        <?php if ($permiteGerenciarCiclo): ?>
                            <form method="POST" action="actions/desfazer_fechamento.php" style="margin:0;" onsubmit="return confirm('Deseja desfazer o fechamento do ciclo atual?');">
                                <button type="submit" class="cd-btn cd-btn-light"><i class="bi bi-arrow-counterclockwise"></i>Voltar um ciclo</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="cd-banner-right">
                    <div class="cd-prog-top"><span>Progresso do mês</span><span style="font-weight:600;color:#fff;">Faltam <?= $diasParaProximoMes ?> dia(s) para virar o mês</span></div>
                    <div class="cd-prog-track"><div class="cd-prog-fill" style="width:<?= $progressoPct ?>%;"></div></div>
                    <div class="cd-prog-foot"><span>Início</span><span>Hoje · <?= $diasDecorridos ?>º dia</span><span>Virada</span></div>
                </div>
            </div>

            <!-- LAYOUT NOVO (UX/UI): KPI cards do ciclo aberto -->
            <div class="cd-kpis">
                <?php foreach ($kpis as $i => $k): ?>
                    <div class="cd-kpi">
                        <div class="cd-kpi-top">
                            <span class="cd-kpi-label"><?= htmlspecialchars($k['label']) ?></span>
                            <div class="cd-kpi-ic" style="background:<?= $k['iconBg'] ?>;color:<?= $k['iconFg'] ?>;"><i class="bi bi-<?= $k['icon'] ?>"></i></div>
                        </div>
                        <div class="cd-kpi-mid">
                            <div class="cd-kpi-val"><?= number_format($k['value'], 2, ',', '.') ?><small>kg</small></div>
                            <canvas class="cd-spark" id="spark<?= $i ?>" width="96" height="30"></canvas>
                        </div>
                        <div class="cd-kpi-delta">
                            <?php if ($k['delta'] === null): ?>
                                <span class="cd-delta-flat"><i class="bi bi-dash"></i>sem referência anterior</span>
                            <?php else:
                                $up = $k['delta'] >= 0;
                                $cls = abs($k['delta']) < 0.05 ? 'cd-delta-flat' : ($up ? 'cd-delta-up' : 'cd-delta-down');
                                $ic = abs($k['delta']) < 0.05 ? 'dash' : ($up ? 'arrow-up' : 'arrow-down'); ?>
                                <span class="<?= $cls ?>"><i class="bi bi-<?= $ic ?>"></i><?= ($up ? '+' : '') . number_format($k['delta'], 1, ',', '.') ?>%</span>
                                <span class="ref">vs. último fechado</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($chartLabels)): ?>
            <!-- LAYOUT NOVO (UX/UI): gráficos comparativo + composição do estoque -->
            <div class="cd-charts">
                <div class="cd-card">
                    <div class="cd-card-h">
                        <div><h3>Vendas × Entradas × Abates</h3><p>Últimos ciclos · kg</p></div>
                        <div class="cd-legend">
                            <span><i class="bi bi-circle-fill" style="color:#2e7d32;"></i>Vendas</span>
                            <span><i class="bi bi-circle-fill" style="color:#a05a2c;"></i>Entradas</span>
                            <span><i class="bi bi-circle-fill" style="color:#a93b2e;"></i>Abates</span>
                        </div>
                    </div>
                    <div class="cd-canvas" style="height:286px;"><canvas id="chartComparativo"></canvas></div>
                </div>
                <div class="cd-card">
                    <div class="cd-card-h"><div><h3>Composição do estoque</h3><p>Último fechamento · kg em estoque</p></div></div>
                    <div class="cd-canvas" style="height:286px;">
                        <?php if (!empty($estData)): ?>
                            <canvas id="chartEstoque"></canvas>
                        <?php else: ?>
                            <p class="cd-empty">Sem snapshot de estoque disponível ainda.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- LAYOUT NOVO (UX/UI): evolução de vendas -->
            <div class="cd-card" style="margin-bottom:18px;">
                <div class="cd-card-h"><div><h3>Evolução de vendas</h3><p>kg vendidos ao longo dos ciclos</p></div></div>
                <div class="cd-canvas" style="height:240px;"><canvas id="chartLinha"></canvas></div>
            </div>
        <?php endif; ?>

        <!-- LAYOUT NOVO (UX/UI): ciclos fechados (mesmos links/dados originais) -->
        <div class="cd-card">
            <div class="cd-closed-h">
                <div><h3>Ciclos fechados</h3><p><?= count($ciclosFechados) ?> ciclo(s) arquivado(s)</p></div>
            </div>
            <div class="cd-closed-grid">
                <?php if (!empty($ciclosFechados)): ?>
                    <?php foreach ($ciclosFechados as $ciclo): ?>
                        <a class="cd-closed-card" href="detalhe.php?id=<?= (int) $ciclo['id'] ?>">
                            <div class="cd-cc-top">
                                <span class="cd-cc-mes"><?= htmlspecialchars($ciclo['nome'] ?: (nomeMesCurto($ciclo['mes']) . ' ' . $ciclo['ano'])) ?></span>
                                <span class="cd-badge">Fechado</span>
                            </div>
                            <div class="cd-cc-rows">
                                <div class="cd-cc-row"><span><i class="bi bi-cart-check" style="color:#2e7d32;"></i>Vendas</span><b><?= formatKg((float) $ciclo['total_vendas_kg']) ?></b></div>
                                <div class="cd-cc-row"><span><i class="bi bi-box-arrow-in-down" style="color:#a05a2c;"></i>Entradas</span><b><?= formatKg((float) $ciclo['total_entradas_kg']) ?></b></div>
                                <div class="cd-cc-row"><span><i class="bi bi-exclamation-triangle" style="color:#a93b2e;"></i>Abates</span><b><?= formatKg((float) $ciclo['total_abates_kg']) ?></b></div>
                            </div>
                            <div class="cd-cc-foot">
                                <span><?= (int) $ciclo['qtd_vendas'] ?> vendas · <?= (int) $ciclo['qtd_entradas'] ?> entradas</span>
                                <span class="lk">Ver detalhes →</span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="cd-empty">Nenhum ciclo fechado encontrado.</p>
                <?php endif; ?>
            </div>
        </div>

        </div><!-- /.cic-dash -->
        </div>
    </div>

    <!-- LAYOUT NOVO (UX/UI): inicialização dos gráficos (Chart.js) com dados reais do PHP -->
    <script>
    (function(){
        if (typeof Chart === 'undefined') { return; }
        var labels   = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>;
        var vendas   = <?= json_encode($chartVendas) ?>;
        var entradas = <?= json_encode($chartEntradas) ?>;
        var abates   = <?= json_encode($chartAbates) ?>;
        var estLabels = <?= json_encode($estLabels, JSON_UNESCAPED_UNICODE) ?>;
        var estData   = <?= json_encode($estData) ?>;
        var donutCores = ['#1b5e20','#2e7d32','#3f9357','#5fae78','#8cc79e','#b8dec3','#dcefe1'];

        Chart.defaults.font.family = "'Segoe UI', Arial, sans-serif";
        Chart.defaults.color = '#5F5E5A';
        var fmtKg = function(v){ return new Intl.NumberFormat('pt-BR',{maximumFractionDigits:0}).format(v) + ' kg'; };

        var elComp = document.getElementById('chartComparativo');
        if (elComp) {
            new Chart(elComp, {
                type:'bar',
                data:{labels:labels,datasets:[
                    {label:'Vendas',data:vendas,backgroundColor:'#2e7d32',borderRadius:4,maxBarThickness:16},
                    {label:'Entradas',data:entradas,backgroundColor:'#a05a2c',borderRadius:4,maxBarThickness:16},
                    {label:'Abates',data:abates,backgroundColor:'#a93b2e',borderRadius:4,maxBarThickness:16}
                ]},
                options:{responsive:true,maintainAspectRatio:false,
                    plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return c.dataset.label+': '+fmtKg(c.parsed.y);}}}},
                    scales:{x:{grid:{display:false}},y:{beginAtZero:true,grid:{color:'#eef0ec'},ticks:{callback:function(v){return (v/1000)+'k';}}}}
                }
            });
        }

        var elEst = document.getElementById('chartEstoque');
        if (elEst && estData.length) {
            new Chart(elEst, {
                type:'doughnut',
                data:{labels:estLabels,datasets:[{data:estData,backgroundColor:donutCores,borderColor:'#fff',borderWidth:2}]},
                options:{responsive:true,maintainAspectRatio:false,cutout:'62%',
                    plugins:{legend:{position:'right',labels:{boxWidth:10,boxHeight:10,usePointStyle:true,padding:10,font:{size:11}}},
                    tooltip:{callbacks:{label:function(c){return c.label+': '+fmtKg(c.parsed);}}}}
                }
            });
        }

        var elLin = document.getElementById('chartLinha');
        if (elLin) {
            new Chart(elLin, {
                type:'line',
                data:{labels:labels,datasets:[{label:'Vendas',data:vendas,borderColor:'#2e7d32',backgroundColor:'rgba(46,125,50,.12)',fill:true,tension:.35,borderWidth:2,pointRadius:3,pointBackgroundColor:'#2e7d32'}]},
                options:{responsive:true,maintainAspectRatio:false,
                    plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return fmtKg(c.parsed.y);}}}},
                    scales:{x:{grid:{display:false}},y:{grid:{color:'#eef0ec'},ticks:{callback:function(v){return (v/1000)+'k';}}}}
                }
            });
        }

        // sparklines dos KPI cards
        var sparks = [
            {id:'spark0',data:vendas,color:'#2e7d32'},
            {id:'spark1',data:entradas,color:'#a05a2c'},
            {id:'spark2',data:abates,color:'#a93b2e'}
        ];
        sparks.forEach(function(s){
            var el = document.getElementById(s.id);
            if (!el || !s.data.length) { return; }
            new Chart(el, {
                type:'line',
                data:{labels:s.data.map(function(_,i){return i;}),datasets:[{data:s.data,borderColor:s.color,backgroundColor:'transparent',borderWidth:2,pointRadius:0,tension:.4}]},
                options:{responsive:false,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{enabled:false}},scales:{x:{display:false},y:{display:false}},elements:{line:{capBezierPoints:true}}}
            });
        });
    })();
    </script>
</body>
</html>

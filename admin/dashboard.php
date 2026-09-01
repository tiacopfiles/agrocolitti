<?php
require_once '../bootstrap/conexao.php';
require_once 'includes/admin_guard.php';
require_once 'includes/admin_layout.php';

// -------------------€--------------------------------------------------------------------------------------------------------------------------------------------------------
$vendas_hoje = (int) $conexao->query("SELECT COUNT(*) AS c FROM vendas WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['c'];
$vendas_mes  = (int) $conexao->query("SELECT COUNT(*) AS c FROM vendas WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")->fetch_assoc()['c'];
$prod_ativos = (int) $conexao->query("SELECT COUNT(*) AS c FROM produtos WHERE ativo=1")->fetch_assoc()['c'];
$usu_ativos  = (int) $conexao->query("SELECT COUNT(*) AS c FROM usuarios WHERE ativo=1")->fetch_assoc()['c'];
$faturamento = (float) $conexao->query("SELECT COALESCE(SUM(preco*pedido),0) AS v FROM vendas WHERE status='concluido'")->fetch_assoc()['v'];
$clientes_at = (int) $conexao->query("SELECT COUNT(*) AS c FROM clientes WHERE ativo=1")->fetch_assoc()['c'];

// -------------------ança -------------------------------------------------------------------------------------------------------------------------------------
$acessos_hoje  = (int) $conexao->query("SELECT COUNT(*) AS c FROM logs_auditoria WHERE acao='login_sucesso' AND DATE(criado_em)=CURDATE()")->fetch_assoc()['c'];
$falhas_hoje   = (int) $conexao->query("SELECT COUNT(*) AS c FROM logs_auditoria WHERE acao='login_falha' AND DATE(criado_em)=CURDATE()")->fetch_assoc()['c'];
$acoes_hoje    = (int) $conexao->query("SELECT COUNT(*) AS c FROM logs_auditoria WHERE DATE(criado_em)=CURDATE()")->fetch_assoc()['c'];

// šltimos logins bem-sucedidos
$Últimos_logins_res = $conexao->query("
    SELECT usuario_nome, ip, DATE_FORMAT(criado_em,'%d/%m %H:%i:%s') AS hora, descricao
    FROM logs_auditoria
    WHERE acao = 'login_sucesso'
    ORDER BY criado_em DESC LIMIT 6
");
$Últimos_logins = [];
while ($r = $Últimos_logins_res->fetch_assoc()) $Últimos_logins[] = $r;

// IPs mais ativos hoje
$ips_ativos_res = $conexao->query("
    SELECT ip, COUNT(*) AS total
    FROM logs_auditoria
    WHERE DATE(criado_em) = CURDATE() AND ip IS NOT NULL AND ip != 'desconhecido'
    GROUP BY ip ORDER BY total DESC LIMIT 6
");
$ips_ativos = [];
while ($r = $ips_ativos_res->fetch_assoc()) $ips_ativos[] = $r;

// Tentativas de falha -------------------
$falhas_res = $conexao->query("
    SELECT ip, descricao, DATE_FORMAT(criado_em,'%d/%m %H:%i:%s') AS hora
    FROM logs_auditoria
    WHERE acao = 'login_falha'
    ORDER BY criado_em DESC LIMIT 5
");
$falhas_lista = [];
while ($r = $falhas_res->fetch_assoc()) $falhas_lista[] = $r;

// Saúde do sistema: alerta se mais de 5 falhas hoje ou usurios inativos > ativos
$sistema_alerta = ($falhas_hoje >= 5);
$sistema_status = $sistema_alerta ? 'ATENÇÃO' : 'OK';
$sistema_cor    = $sistema_alerta ? 'var(--amber)' : 'var(--green)';

// -------------------: vendas por dia (ºltimos 7 dias) ---------------------------------------------------------
$diasLabels  = [];
$diasTotais  = [];
$mapaVendas  = [];
$res = $conexao->query("
    SELECT DATE(created_at) AS dia, COUNT(*) AS total
    FROM vendas
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
");
while ($r = $res->fetch_assoc()) { $mapaVendas[$r['dia']] = (int)$r['total']; }
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $diasLabels[] = date('d/m', strtotime($d));
    $diasTotais[] = $mapaVendas[$d] ?? 0;
}

// -------------------: produtos mais vendidos ----------------------------------------------------------------------------
$prodNomes  = [];
$prodTotais = [];
$res2 = $conexao->query("
    SELECT p.nome, COALESCE(SUM(v.pedido),0) AS total
    FROM produtos p
    LEFT JOIN vendas v ON p.id = v.produto_id
    WHERE p.ativo = 1
    GROUP BY p.id, p.nome
    ORDER BY total DESC
    LIMIT 7
");
while ($r = $res2->fetch_assoc()) {
    $prodNomes[]  = $r['nome'];
    $prodTotais[] = round((float)$r['total'], 2);
}

// -------------------: acoes por dia (ºltimos 7 dias) ---------------------------------------------------------
$acoesLabels = [];
$acoesTotais = [];
$mapaacoes   = [];
$res3 = $conexao->query("
    SELECT DATE(criado_em) AS dia, COUNT(*) AS total
    FROM logs_auditoria
    WHERE criado_em >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(criado_em)
");
while ($r = $res3->fetch_assoc()) { $mapaacoes[$r['dia']] = (int)$r['total']; }
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $acoesLabels[] = date('d/m', strtotime($d));
    $acoesTotais[] = $mapaacoes[$d] ?? 0;
}

// ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------
$logs_res = $conexao->query("
    SELECT usuario_nome, acao, tabela, descricao,
           DATE_FORMAT(criado_em,'%d/%m %H:%i') AS hora
    FROM logs_auditoria ORDER BY criado_em DESC LIMIT 8
");
$logs_lista = [];
while ($r = $logs_res->fetch_assoc()) $logs_lista[] = $r;

// -------------------s -------------------------------------------------------------------------------------------------------------------------------------
$top_usu_res = $conexao->query("
    SELECT usuario_nome, COUNT(*) AS c
    FROM logs_auditoria
    WHERE usuario_nome IS NOT NULL AND criado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY usuario_nome ORDER BY c DESC LIMIT 5
");
$top_usuarios = [];
while ($r = $top_usu_res->fetch_assoc()) $top_usuarios[] = $r;
$max_acoes = $top_usuarios[0]['c'] ?? 1;

function dotClass(string $acao): string {
    if (str_contains($acao,'cria') || str_contains($acao,'criado')) return 'c';
    if (str_contains($acao,'edit') || str_contains($acao,'atualiz')) return 'e';
    if (str_contains($acao,'exclu') || str_contains($acao,'delet')) return 'x';
    return 'o';
}

adminHead('Dashboard');
?>
<body>
<?php adminSidebar('dashboard'); ?>
<?php adminOpenMain('Dashboard', 'Visão geral do sistema ------------------->

<!-- Métricas operacionais -->
<div class="stats-grid">
    <div class="stat-card" style="--c:var(--cyan)">
        <div class="stat-ico cyan"><i class="fa-solid fa-right-to-bracket"></i></div>
        <div class="stat-body">
            <div class="stat-val"><?= $acessos_hoje ?></div>
            <div class="stat-lbl">Acessos Hoje</div>
        </div>
    </div>
    <div class="stat-card" style="--c:var(--purple)">
        <div class="stat-ico purple"><i class="fa-solid fa-cart-shopping"></i></div>
        <div class="stat-body">
            <div class="stat-val"><?= $vendas_hoje ?></div>
            <div class="stat-lbl">Vendas Hoje</div>
        </div>
    </div>
    <div class="stat-card" style="--c:var(--green)">
        <div class="stat-ico green"><i class="fa-solid fa-money-bill-wave"></i></div>
        <div class="stat-body">
            <div class="stat-val">R$<?= number_format($faturamento, 0, ',', '.') ?></div>
            <div class="stat-lbl">Faturamento Total</div>
        </div>
    </div>
    <div class="stat-card" style="--c:var(--amber)">
        <div class="stat-ico amber"><i class="fa-solid fa-bolt"></i></div>
        <div class="stat-body">
            <div class="stat-val"><?= $acoes_hoje ?></div>
            <div class="stat-lbl">acoes Hoje</div>
        </div>
    </div>
    <div class="stat-card" style="--c:var(--pink)">
        <div class="stat-ico" style="background:rgba(244,114,182,.1);color:var(--pink)"><i class="fa-solid fa-users"></i></div>
        <div class="stat-body">
            <div class="stat-val"><?= $usu_ativos ?></div>
            <div class="stat-lbl">Usurios Ativos</div>
        </div>
    </div>
    <div class="stat-card" style="--c:<?= $falhas_hoje > 0 ? 'var(--red)' : 'var(--green)' ?>">
        <div class="stat-ico <?= $falhas_hoje > 0 ? 'red' : 'green' ?>">
            <i class="fa-solid fa-<?= $falhas_hoje > 0 ? 'triangle-exclamation' : 'shield-check' ?>"></i>
        </div>
        <div class="stat-body">
            <div class="stat-val"><?= $falhas_hoje ?></div>
            <div class="stat-lbl">Falhas de Login Hoje</div>
        </div>
    </div>
</div>

<!-- Grficos: linha + barra -->
<div class="panels-grid">
    <div class="panel">
        <div class="panel-hdr">
            <div class="panel-title"><i class="fa-solid fa-chart-line"></i> Vendas -------------------
        </div>
        <div class="panel-body" style="height:220px;position:relative;">
            <canvas id="chartVendas"></canvas>
        </div>
    </div>
    <div class="panel">
        <div class="panel-hdr">
            <div class="panel-title"><i class="fa-solid fa-ranking-star"></i> Produtos mais vendidos</div>
        </div>
        <div class="panel-body" style="height:220px;position:relative;">
            <canvas id="chartProdutos"></canvas>
        </div>
    </div>
</div>

<!-- Grfico acoes + Live feed -->
<div class="panels-grid" style="grid-template-columns: 1fr 1.4fr;">
    <div class="panel">
        <div class="panel-hdr">
            <div class="panel-title"><i class="fa-solid fa-shield-halved"></i> acoes do sistema</div>
        </div>
        <div class="panel-body" style="height:220px;position:relative;">
            <canvas id="chartacoes"></canvas>
        </div>
    </div>
    <div class="panel">
        <div class="panel-hdr">
            <div class="panel-title">
                <span class="live-dot"></span>
                Atividade em tempo real
            </div>
            <span class="panel-pill" id="feed-updated">ao vivo</span>
        </div>
        <div class="panel-body" style="max-height:260px;overflow-y:auto;padding:12px 16px;">
            <div class="log-feed" id="live-feed">
                <?php foreach ($logs_lista as $l): ?>
                <div class="log-item">
                    <div class="log-dot <?= dotClass($l['acao']) ?>"></div>
                    <div class="log-meta">
                        <div class="log-action">
                            <?= htmlspecialchars($l['usuario_nome'] ?? '-------------------
                            <span style="color:var(--txt3);font-weight:400">-------------------$l['acao']) ?></span>
                        </div>
                        <div class="log-desc"><?= htmlspecialchars($l['descricao'] ?? '') ?></div>
                    </div>
                    <div class="log-time"><?= $l['hora'] ?></div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($logs_lista)): ?>
                    <div class="empty">Nenhuma atividade registrada ainda.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Top usurios -->
<?php if (!empty($top_usuarios)): ?>
<div class="panel" style="margin-top:18px;">
    <div class="panel-hdr">
        <div class="panel-title"><i class="fa-solid fa-trophy"></i> Usurios mais ativos (7 dias)</div>
    </div>
    <div class="panel-body">
        <?php foreach ($top_usuarios as $i => $u): ?>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
            <div style="width:24px;text-align:center;font-size:12px;font-weight:700;color:var(--txt3);">#<?= $i+1 ?></div>
            <div style="flex:1;">
                <div style="font-size:13px;font-weight:600;color:var(--txt1);margin-bottom:4px;">
                    <?= htmlspecialchars($u['usuario_nome']) ?>
                    <span style="font-size:11px;color:var(--txt3);font-weight:400;"><?= $u['c'] ?> acoes</span>
                </div>
                <div style="height:5px;background:rgba(255,255,255,.06);border-radius:3px;overflow:hidden;">
                    <div style="height:100%;width:<?= round($u['c']/$max_acoes*100) ?>%;background:linear-gradient(90deg,var(--cyan),var(--purple));border-radius:3px;transition:width .6s ease;"></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- -------------------”€-------------------------------------------------------------------------------------------------------------------------------------
<div style="margin-top:20px;">
    <div class="sec-title" style="margin-bottom:14px;">
        <i class="fa-solid fa-lock"></i> segurança &amp; Acesso
        <span style="margin-left:auto;font-size:12px;font-weight:500;padding:3px 12px;border-radius:20px;
              background:rgba(<?= $sistema_alerta ? '251,191,36' : '52,211,153' ?>,.12);
              color:<?= $sistema_cor ?>;
              border:1px solid rgba(<?= $sistema_alerta ? '251,191,36' : '52,211,153' ?>,.25);">
            <i class="fa-solid fa-circle" style="font-size:8px;"></i>
            Sistema <?= $sistema_status ?>
        </span>
    </div>

    <div class="panels-grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr));">

        <!-- šltimos logins -->
        <div class="panel">
            <div class="panel-hdr">
                <div class="panel-title"><i class="fa-solid fa-key"></i> šltimos Acessos</div>
                <span class="panel-pill"><?= count($Últimos_logins) ?></span>
            </div>
            <div class="panel-body" style="padding:0;">
                <?php if (empty($Últimos_logins)): ?>
                    <div class="empty" style="padding:20px;">Nenhum acesso registrado ainda.</div>
                <?php else: ?>
                <table class="adm-tbl">
                    <thead><tr><th>Usurio</th><th>IP</th><th>Horrio</th></tr></thead>
                    <tbody>
                    <?php foreach ($Últimos_logins as $l): ?>
                    <tr>
                        <td style="font-weight:600;">
                            <i class="fa-solid fa-circle-check" style="color:var(--green);font-size:10px;margin-right:5px;"></i>
                            <?= htmlspecialchars($l['usuario_nome'] ?? '-------------------
                        </td>
                        <td style="font-family:monospace;font-size:11px;color:var(--txt3);">
                            <?= htmlspecialchars($l['ip'] ?? '-------------------
                        </td>
                        <td style="font-size:11px;color:var(--txt3);white-space:nowrap;">
                            <?= $l['hora'] ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- IPs mais ativos hoje -->
        <div class="panel">
            <div class="panel-hdr">
                <div class="panel-title"><i class="fa-solid fa-network-wired"></i> IPs Mais Ativos Hoje</div>
            </div>
            <div class="panel-body">
                <?php if (empty($ips_ativos)): ?>
                    <div class="empty" style="padding:12px 0;">Nenhuma atividade hoje.</div>
                <?php else: ?>
                <?php
                $max_ip = $ips_ativos[0]['total'] ?? 1;
                foreach ($ips_ativos as $ip_row):
                    $pct = $max_ip > 0 ? round($ip_row['total'] / $max_ip * 100) : 0;
                ?>
                <div style="margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                        <span style="font-family:monospace;font-size:12px;color:var(--txt2);">
                            <?= htmlspecialchars($ip_row['ip']) ?>
                        </span>
                        <span style="font-size:11px;font-weight:700;color:var(--cyan);"><?= $ip_row['total'] ?> req.</span>
                    </div>
                    <div style="height:4px;background:rgba(255,255,255,.06);border-radius:3px;overflow:hidden;">
                        <div style="height:100%;width:<?= $pct ?>%;background:linear-gradient(90deg,var(--cyan),var(--purple));border-radius:3px;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Falhas de login -->
        <div class="panel">
            <div class="panel-hdr">
                <div class="panel-title">
                    <i class="fa-solid fa-triangle-exclamation" style="color:var(--red);"></i>
                    Tentativas de Login Falhas
                </div>
                <?php if ($falhas_hoje > 0): ?>
                <span class="panel-pill" style="background:rgba(248,113,113,.12);color:var(--red);border-color:rgba(248,113,113,.25);">
                    <?= $falhas_hoje ?> hoje
                </span>
                <?php endif; ?>
            </div>
            <div class="panel-body" style="padding:0;">
                <?php if (empty($falhas_lista)): ?>
                    <div class="empty" style="padding:20px;">
                        <i class="fa-solid fa-shield-check" style="font-size:24px;color:var(--green);display:block;margin-bottom:8px;"></i>
                        Nenhuma tentativa suspeita.
                    </div>
                <?php else: ?>
                <table class="adm-tbl">
                    <thead><tr><th>IP</th><th>Detalhe</th><th>Horrio</th></tr></thead>
                    <tbody>
                    <?php foreach ($falhas_lista as $f): ?>
                    <tr>
                        <td style="font-family:monospace;font-size:11px;color:var(--red);">
                            <?= htmlspecialchars($f['ip'] ?? '-------------------
                        </td>
                        <td style="font-size:11px;color:var(--txt3);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= htmlspecialchars($f['descricao'] ?? '-------------------
                        </td>
                        <td style="font-size:11px;color:var(--txt3);white-space:nowrap;">
                            <?= $f['hora'] ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<!-- -------------------”€-------------------------------------------------------------------------------------------------------------------------------------
<div style="margin-top:20px;">
    <div class="sec-title" style="margin-bottom:14px;">
        <i class="fa-solid fa-database"></i> Backup &amp; Sistema
    </div>
    <div class="panels-grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">

        <?php
        // Dados de backup simulados (substituir por dados reais quando houver rotina de backup)
        $backups = [
            ['label'=>'Banco de Dados',   'icon'=>'fa-database',     'status'=>'OK', 'data'=>date('d/m/Y', strtotime('-1 day')),  'cor'=>'var(--green)'],
            ['label'=>'Arquivos do Sistema','icon'=>'fa-folder',     'status'=>'OK', 'data'=>date('d/m/Y', strtotime('-2 days')), 'cor'=>'var(--green)'],
            ['label'=>'Logs',              'icon'=>'fa-file-lines',  'status'=>'OK', 'data'=>date('d/m/Y'),                       'cor'=>'var(--green)'],
            ['label'=>'Config',            'icon'=>'fa-gear',        'status'=>'OK', 'data'=>date('d/m/Y', strtotime('-3 days')), 'cor'=>'var(--green)'],
        ];
        foreach ($backups as $bk):
        ?>
        <div class="stat-card" style="--c:<?= $bk['cor'] ?>">
            <div class="stat-ico green">
                <i class="fa-solid <?= $bk['icon'] ?>"></i>
            </div>
            <div class="stat-body">
                <div style="font-size:13px;font-weight:700;color:var(--txt1);"><?= $bk['label'] ?></div>
                <div style="font-size:11px;color:var(--txt3);margin-top:2px;">šltimo: <?= $bk['data'] ?></div>
                <div style="margin-top:5px;">
                    <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;
                        background:rgba(52,211,153,.12);color:var(--green);border:1px solid rgba(52,211,153,.2);">
                        -------------------
                    </span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

    </div>
</div>

<?php adminFooter(); ?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
if (!window.Chart) {
    document.querySelectorAll('canvas').forEach(canvas => {
        const box = canvas.parentElement;
        if (box) box.innerHTML = '<div class="empty"><i class="fa-solid fa-chart-simple" style="font-size:28px;margin-bottom:10px;display:block;"></i>gráfico indisponível. Verifique a conexao com Chart.js.</div>';
    });
} else {
Chart.defaults.color = '#475569';
Chart.defaults.borderColor = 'rgba(148,163,184,.07)';
Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";
Chart.defaults.font.size = 11;

const gradCyan = (ctx) => {
    const g = ctx.chart.ctx.createLinearGradient(0,0,0,200);
    g.addColorStop(0,'rgba(0,212,255,.35)');
    g.addColorStop(1,'rgba(0,212,255,.02)');
    return g;
};
const gradPurple = (ctx) => {
    const g = ctx.chart.ctx.createLinearGradient(0,0,0,200);
    g.addColorStop(0,'rgba(167,139,250,.35)');
    g.addColorStop(1,'rgba(167,139,250,.02)');
    return g;
};
const gradGreen = (ctx) => {
    const g = ctx.chart.ctx.createLinearGradient(0,0,0,200);
    g.addColorStop(0,'rgba(52,211,153,.35)');
    g.addColorStop(1,'rgba(52,211,153,.02)');
    return g;
};

// -------------------€-------------------------------------------------------------------------------------------------------------------------------------
new Chart(document.getElementById('chartVendas'), {
    type: 'line',
    data: {
        labels: <?= json_encode($diasLabels) ?>,
        datasets: [{
            label: 'Vendas',
            data: <?= json_encode($diasTotais) ?>,
            borderColor: '#00d4ff',
            backgroundColor: gradCyan,
            borderWidth: 2,
            pointBackgroundColor: '#00d4ff',
            pointRadius: 4,
            tension: .4,
            fill: true,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { color: 'rgba(148,163,184,.06)' }, ticks: { color: '#475569' } },
            y: { grid: { color: 'rgba(148,163,184,.06)' }, ticks: { color: '#475569', stepSize: 1 }, beginAtZero: true }
        }
    }
});

// -------------------didos ------------------------------------------------------------------------------------------------------------------
new Chart(document.getElementById('chartProdutos'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($prodNomes) ?>,
        datasets: [{
            label: 'Vendido (unid.)',
            data: <?= json_encode($prodTotais) ?>,
            backgroundColor: 'rgba(167,139,250,.25)',
            borderColor: '#a78bfa',
            borderWidth: 1,
            borderRadius: 5,
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { color: 'rgba(148,163,184,.06)' }, ticks: { color: '#475569' }, beginAtZero: true },
            y: { grid: { display: false }, ticks: { color: '#94a3b8' } }
        }
    }
});

// --------------------------------------------------------------------------------------------------------------------------------------------------------
new Chart(document.getElementById('chartacoes'), {
    type: 'line',
    data: {
        labels: <?= json_encode($acoesLabels) ?>,
        datasets: [{
            label: 'acoes',
            data: <?= json_encode($acoesTotais) ?>,
            borderColor: '#34d399',
            backgroundColor: gradGreen,
            borderWidth: 2,
            pointBackgroundColor: '#34d399',
            pointRadius: 4,
            tension: .4,
            fill: true,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { color: 'rgba(148,163,184,.06)' }, ticks: { color: '#475569' } },
            y: { grid: { color: 'rgba(148,163,184,.06)' }, ticks: { color: '#475569', stepSize: 1 }, beginAtZero: true }
        }
    }
});

// -------------------aliza a cada 30s ----------------------------------------------------------------------------
function esc(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function dotCls(acao) {
    if (/cria/.test(acao)) return 'c';
    if (/edit|atualiz/.test(acao)) return 'e';
    if (/exclu|delet/.test(acao)) return 'x';
    return 'o';
}
function refreshFeed() {
    fetch('api/logs_recentes.php')
        .then(r => r.json())
        .then(data => {
            const feed = document.getElementById('live-feed');
            if (!feed || !data.length) return;
            feed.innerHTML = data.map(l => `
                <div class="log-item">
                    <div class="log-dot ${dotCls(esc(l.acao))}"></div>
                    <div class="log-meta">
                        <div class="log-action">
                            ${esc(l.usuario_nome) || '-------------------
                            <span style="color:var(--txt3);font-weight:400">-------------------
                        </div>
                        <div class="log-desc">${esc(l.descricao)}</div>
                        <div class="log-desc" style="font-size:10px;color:var(--txt3);margin-top:3px;">
                            Nivel: ${esc(l.usuario_nivel) || '------------------- || '-------------------.sistema_origem) || '-------------------
                        </div>
                    </div>
                    <div class="log-time">${esc(l.hora)}</div>
                </div>
            `).join('');
            document.getElementById('feed-updated').textContent =
                new Date().toLocaleTimeString('pt-BR', {hour:'2-digit',minute:'2-digit'});
        })
        .catch(() => {});
}
setInterval(refreshFeed, 30000);
}
</script>
<?php adminFooter(); ?>

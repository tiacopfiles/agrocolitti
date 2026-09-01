<?php
require_once '../bootstrap/conexao.php';
require_once 'includes/admin_guard.php';
require_once 'includes/admin_layout.php';

// Filtros
$fStatus  = isset($_GET['status'])   && $_GET['status'] !== ''   ? trim($_GET['status'])   : null;
$fProd    = isset($_GET['produto_id']) && $_GET['produto_id'] !== '' ? (int)$_GET['produto_id'] : null;
$fDi      = isset($_GET['data_ini']) && $_GET['data_ini'] !== '' ? security_date_ymd(trim($_GET['data_ini'])) : null;
$fDf      = isset($_GET['data_fim']) && $_GET['data_fim'] !== '' ? security_date_ymd(trim($_GET['data_fim'])) : null;
$pagina   = max(1,(int)($_GET['pagina']??1));
$pp       = 50;

$wheres=[]; $params=[]; $tipos='';
if ($fStatus) { $wheres[]='v.status=?'; $params[]=$fStatus; $tipos.='s'; }
if ($fProd)   { $wheres[]='v.produto_id=?'; $params[]=$fProd; $tipos.='i'; }
if ($fDi)     { $wheres[]='v.data_venda >= ?'; $params[]=$fDi; $tipos.='s'; }
if ($fDf)     { $wheres[]='v.data_venda <= ?'; $params[]=$fDf; $tipos.='s'; }
$wc = $wheres ? 'WHERE '.implode(' AND ',$wheres) : '';

$sqlC = "SELECT COUNT(*) AS c FROM vendas v $wc";
if ($params) {
    $st=$conexao->prepare($sqlC); $st->bind_param($tipos,...$params);
    $st->execute(); $total=(int)$st->get_result()->fetch_assoc()['c']; $st->close();
} else { $total=(int)$conexao->query($sqlC)->fetch_assoc()['c']; }
$totalPag = max(1,(int)ceil($total/$pp));
$pagina   = min($pagina,$totalPag);
$offset   = ($pagina-1)*$pp;

$sqlV = "
    SELECT v.*, p.nome AS produto_nome, c.nome AS cliente_nome
    FROM vendas v
    LEFT JOIN produtos p ON p.id = v.produto_id
    LEFT JOIN clientes c ON c.id = v.cliente_id
    $wc
    ORDER BY v.created_at DESC
    LIMIT ? OFFSET ?
";
$stV=$conexao->prepare($sqlV);
$stV->bind_param($tipos.'ii',...[...$params,$pp,$offset]);
$stV->execute(); $vendas=$stV->get_result(); $stV->close();

// Métricas resumo
$fat_total   = (float)$conexao->query("SELECT COALESCE(SUM(preco*pedido),0) AS v FROM vendas WHERE status='concluido'")->fetch_assoc()['v'];
$v_pendentes = (int)$conexao->query("SELECT COUNT(*) AS c FROM vendas WHERE status IN ('pendente','anexado')")->fetch_assoc()['c'];
$v_concluido = (int)$conexao->query("SELECT COUNT(*) AS c FROM vendas WHERE status='concluido'")->fetch_assoc()['c'];
$v_hoje      = (int)$conexao->query("SELECT COUNT(*) AS c FROM vendas WHERE DATE(created_at)=CURDATE()")->fetch_assoc()['c'];

$produtosQ = $conexao->query("SELECT id, nome FROM produtos WHERE ativo=1 ORDER BY nome");

function statusBadge(string $s): string {
    $map = [
        'concluido'=>'<span class="badge b-g">Concluído</span>',
        'pendente' =>'<span class="badge b-y">Pendente</span>',
        'anexado'  =>'<span class="badge b-c">Anexado</span>',
    ];
    return $map[$s] ?? '<span class="badge b-gr">'.htmlspecialchars($s).'</span>';
}

function qsP(int $p): string { $q=$_GET; $q['pagina']=$p; return '?'.http_build_query($q); }

adminHead('Vendas');
?>
<body>
<?php adminSidebar('vendas'); ?>
<?php adminOpenMain('Vendas', 'Histórico e monitoramento de vendas'); ?>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(175px,1fr));margin-bottom:20px;">
    <div class="stat-card" style="--c:var(--green)">
        <div class="stat-ico green"><i class="fa-solid fa-money-bill-wave"></i></div>
        <div class="stat-body"><div class="stat-val">R$<?= number_format($fat_total,0,',','.') ?></div><div class="stat-lbl">Faturamento Total</div></div>
    </div>
    <div class="stat-card" style="--c:var(--cyan)">
        <div class="stat-ico cyan"><i class="fa-solid fa-clock"></i></div>
        <div class="stat-body"><div class="stat-val"><?= $v_hoje ?></div><div class="stat-lbl">Vendas Hoje</div></div>
    </div>
    <div class="stat-card" style="--c:var(--amber)">
        <div class="stat-ico amber"><i class="fa-solid fa-hourglass-half"></i></div>
        <div class="stat-body"><div class="stat-val"><?= $v_pendentes ?></div><div class="stat-lbl">Pendentes/Anexadas</div></div>
    </div>
    <div class="stat-card" style="--c:var(--purple)">
        <div class="stat-ico purple"><i class="fa-solid fa-circle-check"></i></div>
        <div class="stat-body"><div class="stat-val"><?= $v_concluido ?></div><div class="stat-lbl">Concluídas</div></div>
    </div>
</div>

<!-- Filtros -->
<div class="panel" style="margin-bottom:18px;">
    <div class="panel-hdr">
        <div class="panel-title"><i class="fa-solid fa-filter"></i> Filtros</div>
        <a href="vendas.php" class="btn btn-p" style="font-size:11px;"><i class="fa-solid fa-rotate-left"></i> Limpar</a>
    </div>
    <div class="panel-body">
        <form method="GET">
            <div class="filters">
                <div class="form-grp">
                    <label class="form-lbl">Status</label>
                    <select name="status" class="form-sel">
                        <option value="">Todos</option>
                        <option value="concluido" <?= $fStatus==='concluido'?'selected':'' ?>>Concluído</option>
                        <option value="pendente" <?= $fStatus==='pendente'?'selected':'' ?>>Pendente</option>
                        <option value="anexado" <?= $fStatus==='anexado'?'selected':'' ?>>Anexado</option>
                    </select>
                </div>
                <div class="form-grp">
                    <label class="form-lbl">Produto</label>
                    <select name="produto_id" class="form-sel">
                        <option value="">Todos</option>
                        <?php while ($pr=$produtosQ->fetch_assoc()): ?>
                        <option value="<?= $pr['id'] ?>" <?= $fProd===(int)$pr['id']?'selected':'' ?>>
                            <?= htmlspecialchars($pr['nome']) ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-grp">
                    <label class="form-lbl">Data início</label>
                    <input type="date" name="data_ini" class="form-inp" value="<?= htmlspecialchars($fDi??'') ?>">
                </div>
                <div class="form-grp">
                    <label class="form-lbl">Data fim</label>
                    <input type="date" name="data_fim" class="form-inp" value="<?= htmlspecialchars($fDf??'') ?>">
                </div>
                <div style="align-self:flex-end;">
                    <button type="submit" class="btn btn-c"><i class="fa-solid fa-magnifying-glass"></i> Filtrar</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Tabela -->
<div class="panel">
    <div class="panel-hdr">
        <div class="panel-title"><i class="fa-solid fa-chart-line"></i> Registros de Vendas</div>
        <span class="panel-pill"><?= number_format($total) ?> registros</span>
    </div>
    <div class="panel-body" style="padding:0;">
        <?php if ($total===0): ?>
            <div class="empty"><i class="fa-solid fa-inbox" style="font-size:32px;margin-bottom:12px;display:block;"></i>Nenhuma venda encontrada.</div>
        <?php else: ?>
        <div class="tbl-wrap">
            <table class="adm-tbl">
                <thead>
                    <tr>
                        <th>ID</th><th>Data</th><th>Produto</th><th>Cliente</th>
                        <th>Tipo</th><th>Pedido</th><th>Preço</th><th>Quantidade</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($v=$vendas->fetch_assoc()): ?>
                    <tr>
                        <td style="color:var(--txt3);font-size:11px;">#<?= $v['id'] ?></td>
                        <td style="font-size:11px;color:var(--txt2);white-space:nowrap;">
                            <?= $v['data_venda'] ? date('d/m/Y', strtotime($v['data_venda'])) : '—' ?>
                        </td>
                        <td style="font-weight:600;"><?= htmlspecialchars($v['produto_nome'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($v['cliente_nome'] ?? '—') ?></td>
                        <td><span class="badge b-gr"><?= htmlspecialchars($v['tipo'] ?? '—') ?></span></td>
                        <td style="font-variant-numeric:tabular-nums;"><?= number_format((float)$v['pedido'],2,',','.') ?></td>
                        <td style="font-variant-numeric:tabular-nums;color:var(--green);">
                            R$ <?= number_format((float)$v['preco'],2,',','.') ?>
                        </td>
                        <td style="font-variant-numeric:tabular-nums;"><?= number_format((float)$v['quantidade'],2,',','.') ?></td>
                        <td><?= statusBadge($v['status'] ?? '') ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPag>1): ?>
        <div class="pagi" style="padding:16px 0;">
            <?php if ($pagina>1): ?>
                <a href="<?= qsP(1) ?>">«</a><a href="<?= qsP($pagina-1) ?>">‹</a>
            <?php else: ?>
                <span class="dis">«</span><span class="dis">‹</span>
            <?php endif; ?>
            <?php for($p=max(1,$pagina-2);$p<=min($totalPag,$pagina+2);$p++): ?>
                <?= $p===$pagina ? "<span class=\"cur\">$p</span>" : "<a href=\"".qsP($p)."\">$p</a>" ?>
            <?php endfor; ?>
            <?php if ($pagina<$totalPag): ?>
                <a href="<?= qsP($pagina+1) ?>">›</a><a href="<?= qsP($totalPag) ?>">»</a>
            <?php else: ?>
                <span class="dis">›</span><span class="dis">»</span>
            <?php endif; ?>
        </div>
        <div style="text-align:center;font-size:11px;color:var(--txt3);padding-bottom:14px;">
            Página <?= $pagina ?> de <?= $totalPag ?> · <?= number_format($total) ?> registros
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php adminFooter(); ?>

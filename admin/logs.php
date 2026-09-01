<?php
require_once '../bootstrap/conexao.php';
require_once '../bootstrap/security.php';
require_once 'includes/admin_guard.php';
require_once 'includes/admin_layout.php';

// ── Filtros ───────────────────────────────────────────────────────────────────
start_secure_session();

$fUid    = isset($_GET['usuario_id'])  && $_GET['usuario_id']  !== '' ? security_int($_GET['usuario_id'], 0, 1) : null;
$fAcao   = isset($_GET['acao'])        && $_GET['acao']        !== '' ? security_trimmed_string($_GET['acao'], 100) : null;
$fDi     = security_date_ymd($_GET['data_inicio'] ?? null);
$fDf     = security_date_ymd($_GET['data_fim'] ?? null);
$fBusca  = isset($_GET['busca'])       && $_GET['busca']       !== '' ? security_trimmed_string($_GET['busca'], 120) : null;
$fTabela = isset($_GET['tabela'])      && $_GET['tabela']      !== '' ? security_trimmed_string($_GET['tabela'], 100) : null;
$fNivel  = isset($_GET['usuario_nivel']) && $_GET['usuario_nivel'] !== '' ? security_trimmed_string($_GET['usuario_nivel'], 50) : null;
$fAut    = isset($_GET['autorizado']) && $_GET['autorizado'] !== '' && in_array($_GET['autorizado'], ['0','1'], true) ? (int) $_GET['autorizado'] : null;
$pagina  = security_int($_GET['pagina'] ?? 1, 1, 1);
$pp      = 50;

$wheres = []; $params = []; $tipos = '';

if ($fUid !== null)   { $wheres[] = 'usuario_id = ?';        $params[] = $fUid;   $tipos .= 'i'; }
if ($fAcao !== null)  { $wheres[] = 'acao = ?';              $params[] = $fAcao;  $tipos .= 's'; }
if ($fDi !== null)    { $wheres[] = 'DATE(criado_em) >= ?';  $params[] = $fDi;    $tipos .= 's'; }
if ($fDf !== null)    { $wheres[] = 'DATE(criado_em) <= ?';  $params[] = $fDf;    $tipos .= 's'; }
if ($fBusca !== null) { $wheres[] = 'descricao LIKE ?';      $params[] = '%'.$fBusca.'%'; $tipos .= 's'; }
if ($fTabela !== null){ $wheres[] = 'tabela = ?';            $params[] = $fTabela;$tipos .= 's'; }
if ($fNivel !== null) { $wheres[] = 'usuario_nivel = ?';     $params[] = $fNivel; $tipos .= 's'; }
if ($fAut !== null)   { $wheres[] = 'autorizado = ?';        $params[] = $fAut;   $tipos .= 'i'; }

$wc = $wheres ? 'WHERE ' . implode(' AND ', $wheres) : '';

// total
$sqlC = "SELECT COUNT(*) AS c FROM logs_auditoria $wc";
if ($params) {
    $st = $conexao->prepare($sqlC); $st->bind_param($tipos, ...$params);
    $st->execute(); $total = (int)$st->get_result()->fetch_assoc()['c']; $st->close();
} else {
    $total = (int)$conexao->query($sqlC)->fetch_assoc()['c'];
}
$totalPag = max(1, (int)ceil($total/$pp));
$pagina   = min($pagina, $totalPag);
$offset   = ($pagina-1)*$pp;

// registros
$sqlL = "SELECT * FROM logs_auditoria $wc ORDER BY criado_em DESC LIMIT ? OFFSET ?";
$stL  = $conexao->prepare($sqlL);
$stL->bind_param($tipos.'ii', ...[...$params, $pp, $offset]);
$stL->execute();
$logs = $stL->get_result();
$stL->close();

// selects de filtros
$usuariosQ = $conexao->query("SELECT DISTINCT usuario_id, usuario_nome FROM logs_auditoria WHERE usuario_nome IS NOT NULL ORDER BY usuario_nome");
$acoesQ    = $conexao->query("SELECT DISTINCT acao FROM logs_auditoria ORDER BY acao");
$tabelasQ  = $conexao->query("SELECT DISTINCT tabela FROM logs_auditoria WHERE tabela IS NOT NULL ORDER BY tabela");
$niveisQ   = $conexao->query("SELECT DISTINCT usuario_nivel FROM logs_auditoria WHERE usuario_nivel IS NOT NULL ORDER BY usuario_nivel");

// resumo rápido
$totalHoje = (int)$conexao->query("SELECT COUNT(*) AS c FROM logs_auditoria WHERE DATE(criado_em)=CURDATE()")->fetch_assoc()['c'];
$total7d   = (int)$conexao->query("SELECT COUNT(*) AS c FROM logs_auditoria WHERE criado_em >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetch_assoc()['c'];

function badgeLog(string $acao): string {
    if (str_contains($acao,'cria')) return '<span class="badge b-g">'.$acao.'</span>';
    if (str_contains($acao,'edit')||str_contains($acao,'atualiz')) return '<span class="badge b-y">'.$acao.'</span>';
    if (str_contains($acao,'exclu')||str_contains($acao,'delet')) return '<span class="badge b-r">'.$acao.'</span>';
    return '<span class="badge b-c">'.$acao.'</span>';
}

function qsP(int $p): string { $q = $_GET; $q['pagina'] = $p; return '?'.http_build_query($q); }

adminHead('Logs do Sistema');
?>
<body>
<?php adminSidebar('logs'); ?>
<?php adminOpenMain('Logs do Sistema', 'Histórico completo de auditoria'); ?>

<!-- Resumo -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:20px;">
    <div class="stat-card" style="--c:var(--cyan)">
        <div class="stat-ico cyan"><i class="fa-solid fa-clock"></i></div>
        <div class="stat-body">
            <div class="stat-val"><?= $totalHoje ?></div>
            <div class="stat-lbl">Ações hoje</div>
        </div>
    </div>
    <div class="stat-card" style="--c:var(--purple)">
        <div class="stat-ico purple"><i class="fa-solid fa-calendar-week"></i></div>
        <div class="stat-body">
            <div class="stat-val"><?= $total7d ?></div>
            <div class="stat-lbl">Últimos 7 dias</div>
        </div>
    </div>
    <div class="stat-card" style="--c:var(--green)">
        <div class="stat-ico green"><i class="fa-solid fa-list"></i></div>
        <div class="stat-body">
            <div class="stat-val"><?= number_format($total) ?></div>
            <div class="stat-lbl">Com filtros</div>
        </div>
    </div>
</div>

<!-- Filtros -->
<div class="panel" style="margin-bottom:18px;">
    <div class="panel-hdr">
        <div class="panel-title"><i class="fa-solid fa-filter"></i> Filtros</div>
        <a href="logs.php" class="btn btn-p"><i class="fa-solid fa-rotate-left"></i> Limpar</a>
    </div>
    <div class="panel-body">
        <form method="GET">
            <div class="filters">
                <div class="form-grp">
                    <label class="form-lbl">Usuário</label>
                    <select name="usuario_id" class="form-sel">
                        <option value="">Todos</option>
                        <?php while ($u = $usuariosQ->fetch_assoc()): ?>
                        <option value="<?= $u['usuario_id'] ?>" <?= $fUid === (int)$u['usuario_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['usuario_nome']) ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-grp">
                    <label class="form-lbl">Ação</label>
                    <select name="acao" class="form-sel">
                        <option value="">Todas</option>
                        <?php while ($a = $acoesQ->fetch_assoc()): ?>
                        <option value="<?= htmlspecialchars($a['acao']) ?>" <?= $fAcao === $a['acao'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['acao']) ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-grp">
                    <label class="form-lbl">Tabela</label>
                    <select name="tabela" class="form-sel">
                        <option value="">Todas</option>
                        <?php while ($t = $tabelasQ->fetch_assoc()): ?>
                        <option value="<?= htmlspecialchars($t['tabela']) ?>" <?= $fTabela === $t['tabela'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['tabela']) ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-grp">
                    <label class="form-lbl">Nivel</label>
                    <select name="usuario_nivel" class="form-sel">
                        <option value="">Todos</option>
                        <?php while ($n = $niveisQ->fetch_assoc()): ?>
                        <option value="<?= htmlspecialchars($n['usuario_nivel']) ?>" <?= $fNivel === $n['usuario_nivel'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($n['usuario_nivel']) ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-grp">
                    <label class="form-lbl">Autorizacao</label>
                    <select name="autorizado" class="form-sel">
                        <option value="">Todas</option>
                        <option value="1" <?= $fAut === 1 ? 'selected' : '' ?>>Autorizada</option>
                        <option value="0" <?= $fAut === 0 ? 'selected' : '' ?>>Negada/Falha</option>
                    </select>
                </div>
                <div class="form-grp">
                    <label class="form-lbl">Data início</label>
                    <input type="date" name="data_inicio" class="form-inp" value="<?= htmlspecialchars($fDi ?? '') ?>">
                </div>
                <div class="form-grp">
                    <label class="form-lbl">Data fim</label>
                    <input type="date" name="data_fim" class="form-inp" value="<?= htmlspecialchars($fDf ?? '') ?>">
                </div>
                <div class="form-grp" style="min-width:200px;">
                    <label class="form-lbl">Buscar descrição</label>
                    <input type="text" name="busca" class="form-inp" placeholder="palavra-chave..." value="<?= htmlspecialchars($fBusca ?? '') ?>">
                </div>
                <div style="align-self:flex-end;">
                    <button type="submit" class="btn btn-c"><i class="fa-solid fa-magnifying-glass"></i> Buscar</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Tabela -->
<div class="panel">
    <div class="panel-hdr">
        <div class="panel-title"><i class="fa-solid fa-shield-halved"></i> Registros</div>
        <span class="panel-pill"><?= number_format($total) ?> registros</span>
    </div>
    <div class="panel-body" style="padding:0;">
        <?php if ($total === 0): ?>
            <div class="empty"><i class="fa-solid fa-inbox" style="font-size:32px;margin-bottom:12px;display:block;"></i>Nenhum registro encontrado.</div>
        <?php else: ?>
        <div class="tbl-wrap">
            <table class="adm-tbl">
                <thead>
                    <tr>
                        <th>Data / Hora</th>
                        <th>Usuario</th>
                        <th>Nivel</th>
                        <th>Acao</th>
                        <th>Autorizacao</th>
                        <th>Tabela</th>
                        <th>ID</th>
                        <th>Descricao</th>
                        <th>IP</th>
                        <th>Origem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($l = $logs->fetch_assoc()): ?>
                    <tr>
                        <td style="white-space:nowrap;font-size:11px;color:var(--txt3);font-family:'Courier New',monospace;">
                            <?= date('d/m/Y H:i:s', strtotime($l['criado_em'])) ?>
                        </td>
                        <td style="white-space:nowrap;font-weight:600;">
                            <?= htmlspecialchars($l['usuario_nome'] ?? '—') ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <?= !empty($l['usuario_nivel']) ? '<span class="badge b-c">' . htmlspecialchars($l['usuario_nivel']) . '</span>' : '<span style="color:var(--txt3)">—</span>' ?>
                        </td>
                        <td><?= badgeLog(htmlspecialchars($l['acao'])) ?></td>
                        <td>
                            <?php if (isset($l['autorizado']) && $l['autorizado'] !== null): ?>
                                <?= (int) $l['autorizado'] === 1 ? '<span class="badge b-g">Autorizada</span>' : '<span class="badge b-r">Negada/Falha</span>' ?>
                            <?php else: ?>
                                <span style="color:var(--txt3)">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($l['tabela']): ?>
                                <span class="badge b-gr"><?= htmlspecialchars($l['tabela']) ?></span>
                            <?php else: echo '<span style="color:var(--txt3)">—</span>'; endif; ?>
                        </td>
                        <td style="text-align:center;font-size:11px;color:var(--txt3);">
                            <?= $l['registro_id'] ?? '—' ?>
                        </td>
                        <td style="max-width:280px;font-size:12px;color:var(--txt2);word-break:break-word;">
                            <?= $l['descricao'] ? htmlspecialchars($l['descricao']) : '<span style="color:var(--txt3)">—</span>' ?>
                        </td>
                        <td style="font-size:11px;color:var(--txt3);white-space:nowrap;font-family:'Courier New',monospace;">
                            <?= htmlspecialchars($l['ip'] ?? '—') ?>
                        </td>
                        <td style="font-size:11px;color:var(--txt3);white-space:nowrap;" title="<?= htmlspecialchars($l['user_agent'] ?? '') ?>">
                            <?= htmlspecialchars($l['sistema_origem'] ?? '—') ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPag > 1): ?>
        <div class="pagi" style="padding:16px 0;">
            <?php if ($pagina > 1): ?>
                <a href="<?= qsP(1) ?>">«</a>
                <a href="<?= qsP($pagina-1) ?>">‹</a>
            <?php else: ?>
                <span class="dis">«</span><span class="dis">‹</span>
            <?php endif; ?>
            <?php for ($p = max(1,$pagina-2); $p <= min($totalPag,$pagina+2); $p++): ?>
                <?= $p === $pagina ? "<span class=\"cur\">$p</span>" : "<a href=\"".qsP($p)."\">$p</a>" ?>
            <?php endfor; ?>
            <?php if ($pagina < $totalPag): ?>
                <a href="<?= qsP($pagina+1) ?>">›</a>
                <a href="<?= qsP($totalPag) ?>">»</a>
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

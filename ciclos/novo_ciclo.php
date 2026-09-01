<?php
require_once __DIR__ . "/../config/conexao.php";
require_once __DIR__ . "/../config/ciclo_helper.php";
require_once __DIR__ . "/../support/ciclo_fechamento_helper.php";
require_once __DIR__ . "/../auth/proteger.php";
require_once __DIR__ . "/../config/permissions.php";
require_once __DIR__ . "/../config/layout_helper.php";

requireModule('ciclos', '../ciclos/index.php');

if (($_SESSION['usuario_nivel'] ?? '') !== 'admin') {
    header("Location: index.php?msg=erro&detalhe=" . urlencode("Apenas administradores podem iniciar novo ciclo."));
    exit;
}

$cicloAtual = getCicloAtivo($conexao);
if (!$cicloAtual) {
    header("Location: index.php?msg=erro&detalhe=" . urlencode("Nenhum ciclo ativo encontrado."));
    exit;
}

cicloFechamentoGarantirTabelas($conexao);
$mapa = cicloFechamentoPlanoMensal($conexao, $cicloAtual);
$totais = cicloFechamentoTotais($mapa);
$periodo = $mapa['periodo'];
$podeFechar = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))
    ->format('Y-m-d H:i:s') >= $periodo['fim'];

function cicloPreviewNumero($valor): string
{
    return number_format((float) $valor, 2, ',', '.');
}

function cicloPreviewTabelaResumo(array $totaisGrupo): void
{
    if (!$totaisGrupo) {
        echo '<p class="empty">Nenhum registro neste grupo.</p>';
        return;
    }
    echo '<table><thead><tr><th>Tipo</th><th>Registros/itens</th><th>OS/processos</th><th>Quantidade</th><th>Valor</th></tr></thead><tbody>';
    foreach ($totaisGrupo as $tipo => $t) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($tipo) . '</td>';
        echo '<td>' . (int) $t['qtd'] . '</td>';
        echo '<td>' . (int) ($t['processos'] ?? $t['qtd']) . '</td>';
        echo '<td>' . cicloPreviewNumero($t['quantidade']) . '</td>';
        echo '<td>R$ ' . cicloPreviewNumero($t['valor']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

function cicloPreviewLinhas(array $linhas): void
{
    if (!$linhas) {
        echo '<p class="empty">Nenhum registro.</p>';
        return;
    }
    echo '<div class="table-wrap"><table><thead><tr><th>Tipo</th><th>ID</th><th>OS</th><th>Produto</th><th>Pessoa</th><th>Status</th><th>Qtd.</th></tr></thead><tbody>';
    foreach (array_slice($linhas, 0, 120) as $r) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($r['tipo']) . '</td>';
        echo '<td>' . (int) $r['origem_id'] . '</td>';
        echo '<td>' . htmlspecialchars($r['numero_os'] ?: '-') . '</td>';
        echo '<td>' . htmlspecialchars($r['produto_nome'] ?: '-') . '</td>';
        echo '<td>' . htmlspecialchars($r['pessoa_nome'] ?: '-') . '</td>';
        echo '<td>' . htmlspecialchars($r['status'] ?: '-') . '</td>';
        echo '<td>' . cicloPreviewNumero($r['quantidade'] ?? 0) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    if (count($linhas) > 120) {
        echo '<p class="empty">Mostrando 120 de ' . count($linhas) . ' registros.</p>';
    }
}

// LAYOUT NOVO (UX/UI): totais agregados para o resumo visual (deriva de $totais).
$arqTotReg = 0; $arqTotProc = 0; $arqTotKg = 0.0;
foreach ($totais['arquivar'] as $t) { $arqTotReg += (int) $t['qtd']; $arqTotProc += (int) ($t['processos'] ?? $t['qtd']); $arqTotKg += (float) ($t['quantidade'] ?? 0); }
$carrTotReg = 0; $carrTotProc = 0; $carrTotKg = 0.0;
foreach ($totais['carregar'] as $t) { $carrTotReg += (int) $t['qtd']; $carrTotProc += (int) ($t['processos'] ?? $t['qtd']); $carrTotKg += (float) ($t['quantidade'] ?? 0); }
$transTotReg = 0; $transTotProc = 0; $transTotKg = 0.0;
foreach ($totais['transferir'] as $t) { $transTotReg += (int) $t['qtd']; $transTotProc += (int) ($t['processos'] ?? $t['qtd']); $transTotKg += (float) ($t['quantidade'] ?? 0); }
$temInconsistencias = !empty($mapa['inconsistencias']);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prévia do novo ciclo</title>
    <style>
        /* ===== LAYOUT NOVO (UX/UI) — escopo .cic-prev; paleta ORIGINAL do sistema ===== */
        .cic-prev{
            --g50:#f1f8f4;--g100:#dcefe1;--g200:#a5d6a7;--g600:#2e7d32;--g700:#1f6d23;--g800:#1b5e20;
            --clay:#a93b2e;--clay-soft:#fbe6e3;
            --warn:#b45309;--warn-bg:#fff8e1;--warn-bd:#ffe082;
            --ink-1:#222;--ink-2:#3a3f3a;--ink-3:#5F5E5A;--line-1:#e2e5df;--line-2:#eef0ec;--surf:#fff;--surf-2:#fbfcfb;
            --sh-1:0 1px 2px rgba(20,24,20,.05),0 1px 1px rgba(20,24,20,.03);--r:12px;--ease:cubic-bezier(.2,0,0,1);
            max-width:1320px;margin:0 auto;color:var(--ink-1);font-family:'Segoe UI',Arial,sans-serif;font-size:14px;
        }
        .cic-prev *{box-sizing:border-box;}
        .cic-prev h1,.cic-prev h2,.cic-prev h3,.cic-prev p{margin:0;}
        .cd-back{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--ink-3);text-decoration:none;margin-bottom:14px;}
        .cd-back:hover{color:var(--g700);}
        .cd-head{margin-bottom:18px;}
        .cd-eyebrow{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-3);}
        .cd-head h1{font-size:28px;font-weight:700;letter-spacing:-.01em;margin-top:6px;}
        .cd-head p{margin-top:6px;color:var(--ink-3);font-size:14px;}
        .cd-warn{display:flex;gap:12px;align-items:flex-start;background:var(--warn-bg);border:1px solid var(--warn-bd);border-radius:var(--r);padding:16px 18px;margin-bottom:18px;}
        .cd-warn i{color:var(--warn);font-size:18px;margin-top:1px;}
        .cd-warn p{font-size:13px;color:#7a4708;line-height:1.55;}
        .cd-grid2{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin-bottom:18px;}
        .cd-sumcard{background:var(--surf);border:1px solid var(--line-1);border-radius:var(--r);box-shadow:var(--sh-1);padding:20px 22px;}
        .cd-sumcard.arquivar{border-left:3px solid var(--clay);}
        .cd-sumcard.preservar,.cd-sumcard.transferir{border-left:3px solid var(--g600);}
        .cd-sum-h{display:flex;align-items:center;gap:10px;margin-bottom:14px;}
        .cd-sum-ic{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;}
        .cd-sumcard.arquivar .cd-sum-ic{background:var(--clay-soft);color:var(--clay);}
        .cd-sumcard.preservar .cd-sum-ic,.cd-sumcard.transferir .cd-sum-ic{background:var(--g50);color:var(--g700);}
        .cd-sum-h h2{font-size:16px;font-weight:600;}
        .cd-bignums{display:flex;gap:28px;margin-bottom:14px;}
        .cd-bignum .v{font-size:30px;font-weight:700;font-variant-numeric:tabular-nums;}
        .cd-sumcard.preservar .cd-bignum .v,.cd-sumcard.transferir .cd-bignum .v{color:var(--g800);}
        .cd-bignum .l{font-size:12px;color:var(--ink-3);}
        .cd-card{background:var(--surf);border:1px solid var(--line-1);border-radius:var(--r);box-shadow:var(--sh-1);padding:18px 22px;margin-bottom:18px;}
        .cd-card h2{font-size:16px;font-weight:600;margin-bottom:12px;}
        /* tabelas impressas pelos helpers PHP */
        .cic-prev .table-wrap{overflow-x:auto;}
        .cic-prev table{width:100%;border-collapse:collapse;font-size:13px;margin-top:4px;}
        .cic-prev th{background:#f6f7f5;color:var(--ink-2);text-align:left;font-weight:600;font-size:11px;letter-spacing:.06em;text-transform:uppercase;padding:10px 14px;border-bottom:1px solid var(--line-1);}
        .cic-prev td{padding:10px 14px;border-bottom:1px solid var(--line-2);text-align:left;}
        .cic-prev tr:hover td{background:var(--g50);}
        .cic-prev .empty{color:var(--ink-3);font-style:italic;padding:8px 2px;}
        /* confirmação */
        .cd-confirm{background:var(--surf);border:1px solid var(--line-1);border-radius:var(--r);box-shadow:var(--sh-1);padding:20px 22px;display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;}
        .cd-check{display:flex;align-items:flex-start;gap:10px;cursor:pointer;max-width:600px;}
        .cd-check input{width:18px;height:18px;margin-top:1px;accent-color:var(--g700);cursor:pointer;}
        .cd-check span{font-size:13px;color:var(--ink-2);line-height:1.5;}
        .cd-acts{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
        .cd-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border-radius:8px;border:1px solid var(--line-1);background:var(--surf);color:var(--ink-1);font:600 14px 'Segoe UI',Arial,sans-serif;cursor:pointer;text-decoration:none;}
        .cd-btn:hover{background:#f4f6f4;}
        .cd-btn-danger{background:var(--clay);border-color:var(--clay);color:#fff;}
        .cd-btn-danger:hover{background:#8f3025;}
        .cd-btn-danger:disabled{opacity:.5;cursor:not-allowed;}
        @media (max-width:900px){.cd-grid2{grid-template-columns:1fr;}}
    </style>
    <?php renderAppLayoutStyles(); ?>
</head>
<body>
<?php renderAppHeader('..'); ?>
<main class="container page-container app-shell">
<!-- LAYOUT NOVO (UX/UI): wrapper de escopo da prévia -->
<div class="cic-prev">

    <a class="cd-back" href="index.php"><i class="bi bi-arrow-left"></i>Voltar para Ciclos</a>

    <div class="cd-head">
        <span class="cd-eyebrow">Fechamento de ciclo</span>
        <h1>Prévia do novo ciclo</h1>
        <p>Ciclo atual: <strong style="color:var(--ink-1);"><?= htmlspecialchars(getNomeCiclo($cicloAtual)) ?></strong></p>
    </div>

    <div class="cd-warn">
        <i class="bi bi-info-circle-fill"></i>
        <p>Esta prévia <strong>não apaga nada</strong>. A competência vai de <?= htmlspecialchars(date('d/m/Y', strtotime($periodo['inicio']))) ?> até <?= htmlspecialchars(date('d/m/Y', strtotime($periodo['fim'] . ' -1 day'))) ?>. Pendências antigas e operações do mês seguinte serão carregadas para o novo ciclo.</p>
    </div>

    <!-- LAYOUT NOVO (UX/UI): resumo visual + tabelas dos helpers (preservadas) -->
    <div class="cd-grid2">
        <div class="cd-sumcard arquivar">
            <div class="cd-sum-h"><div class="cd-sum-ic"><i class="bi bi-archive-fill"></i></div><h2>Será arquivado</h2></div>
            <div class="cd-bignums">
                <div class="cd-bignum"><div class="v"><?= (int) $arqTotReg ?></div><div class="l">registros/itens</div></div>
                <div class="cd-bignum"><div class="v"><?= (int) $arqTotProc ?></div><div class="l">OS/processos</div></div>
                <div class="cd-bignum"><div class="v"><?= cicloPreviewNumero($arqTotKg) ?></div><div class="l">qtd</div></div>
            </div>
            <?php cicloPreviewTabelaResumo($totais['arquivar']); ?>
        </div>
        <div class="cd-sumcard preservar">
            <div class="cd-sum-h"><div class="cd-sum-ic"><i class="bi bi-bookmark-check-fill"></i></div><h2>Pendências carregadas</h2></div>
            <div class="cd-bignums">
                <div class="cd-bignum"><div class="v"><?= (int) $carrTotReg ?></div><div class="l">registros/itens</div></div>
                <div class="cd-bignum"><div class="v"><?= (int) $carrTotProc ?></div><div class="l">OS/processos</div></div>
                <div class="cd-bignum"><div class="v"><?= cicloPreviewNumero($carrTotKg) ?></div><div class="l">qtd</div></div>
            </div>
            <?php cicloPreviewTabelaResumo($totais['carregar']); ?>
        </div>
        <div class="cd-sumcard transferir">
            <div class="cd-sum-h"><div class="cd-sum-ic"><i class="bi bi-arrow-right-circle-fill"></i></div><h2>Do próximo mês</h2></div>
            <div class="cd-bignums">
                <div class="cd-bignum"><div class="v"><?= (int) $transTotReg ?></div><div class="l">registros/itens</div></div>
                <div class="cd-bignum"><div class="v"><?= (int) $transTotProc ?></div><div class="l">OS/processos</div></div>
                <div class="cd-bignum"><div class="v"><?= cicloPreviewNumero($transTotKg) ?></div><div class="l">qtd</div></div>
            </div>
            <?php cicloPreviewTabelaResumo($totais['transferir']); ?>
        </div>
    </div>

    <div class="cd-card">
        <h2>Detalhes do que será arquivado</h2>
        <?php cicloPreviewLinhas($mapa['arquivar']); ?>
    </div>

    <div class="cd-card">
        <h2>Detalhes das pendências carregadas</h2>
        <?php cicloPreviewLinhas($mapa['carregar']); ?>
    </div>

    <div class="cd-card">
        <h2>Detalhes das operações do próximo mês</h2>
        <?php cicloPreviewLinhas($mapa['transferir']); ?>
    </div>

    <?php if ($temInconsistencias): ?>
    <div class="cd-card">
        <h2>Inconsistências que bloqueiam o fechamento</h2>
        <?php cicloPreviewLinhas($mapa['inconsistencias']); ?>
    </div>
    <?php endif; ?>

    <!-- LAYOUT NOVO (UX/UI): confirmação (form/ação/campos preservados) -->
    <form method="POST" action="actions/ciclos.php" onsubmit="return confirm('Confirma o fechamento mensal? Nenhum histórico operacional será apagado.');">
        <input type="hidden" name="confirmar_fechamento" value="1">
        <div class="cd-confirm">
            <label class="cd-check">
                <input type="checkbox" id="dryRunCheck" name="dry_run_conferido" value="1" required <?= (!$podeFechar || $temInconsistencias) ? 'disabled' : '' ?> onchange="document.getElementById('btnFechar').disabled = !this.checked;">
                <span>Conferi a prévia e confirmo o <strong>fechamento mensal</strong>. Registros finalizados ficam no mês correto; pendências e operações do próximo mês seguem operacionais no novo ciclo.</span>
            </label>
            <div class="cd-acts">
                <a class="cd-btn" href="index.php">Voltar sem fechar</a>
                <button type="submit" id="btnFechar" class="cd-btn cd-btn-danger" disabled><i class="bi bi-lock-fill"></i><?= !$podeFechar ? 'Disponível após o fim do mês' : 'Fechar ciclo real' ?></button>
            </div>
        </div>
    </form>

</div><!-- /.cic-prev -->
</main>
</body>
</html>

<?php
require "../config/conexao.php";
require "../config/ciclo_helper.php";
require "../auth/proteger.php";
require "../config/permissions.php";
require "../config/layout_helper.php";

requireModule('historico_nfe', '../index.php');

function boletoSituacaoLabel(string $s): string
{
    $map = ['rascunho' => 'Rascunho', 'emitido' => 'Emitido', 'registrado' => 'Registrado', 'erro' => 'Erro', 'baixado' => 'Baixado', 'cancelado' => 'Cancelado'];
    return $map[$s] ?? ($s !== '' ? $s : '-');
}
function boletoSituacaoCor(string $s): string
{
    if ($s === 'registrado' || $s === 'emitido') return '#2e7d32';
    if ($s === 'baixado' || $s === 'cancelado') return '#6b7280';
    if ($s === 'erro') return '#c62828';
    return '#b8860b';
}

$busca = trim((string) ($_GET['busca'] ?? ''));
$situacao = trim((string) ($_GET['situacao'] ?? ''));
$dataIni = trim((string) ($_GET['data_ini'] ?? ''));
$dataFim = trim((string) ($_GET['data_fim'] ?? ''));

$where = ['1=1'];
$params = [];
$types = '';
if ($busca !== '') {
    $where[] = "(b.numero_os LIKE ? OR b.seu_numero LIKE ? OR b.nosso_numero LIKE ? OR b.pagador_nome LIKE ? OR b.pagador_cpf_cnpj LIKE ?)";
    $like = '%' . $busca . '%';
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}
if ($situacao !== '') { $where[] = 'b.situacao = ?'; $params[] = $situacao; $types .= 's'; }
if ($dataIni !== '') { $where[] = 'DATE(b.created_at) >= ?'; $params[] = $dataIni; $types .= 's'; }
if ($dataFim !== '') { $where[] = 'DATE(b.created_at) <= ?'; $params[] = $dataFim; $types .= 's'; }

// Filtro padrao por CICLO ativo (zera ao virar o mes, sem apagar nada). ?todos=1 ou datas mostram o historico completo.
$verTodos = (($_GET['todos'] ?? '') === '1');
$cicloHistAtivo = getCicloAtivo($conexao);
if (!$verTodos && $dataIni === '' && $dataFim === '' && $cicloHistAtivo && !empty($cicloHistAtivo['aberto_em'])) {
    $where[] = 'b.created_at >= ?';
    $params[] = (string) $cicloHistAtivo['aberto_em'];
    $types .= 's';
}

$sql = "SELECT b.* FROM boletos b WHERE " . implode(' AND ', $where) . " ORDER BY b.id DESC";
$stmt = $conexao->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$boletos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$situacoes = ['rascunho', 'emitido', 'registrado', 'erro', 'baixado', 'cancelado'];
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Histórico de Boletos</title>
<style>
body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;color:#222}.container{padding:30px;max-width:1400px;margin:auto}
.card{background:#fff;padding:24px;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,.06);margin-bottom:24px}.filters{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:12px;align-items:end}
label{font-weight:700;font-size:14px}input,select{width:100%;box-sizing:border-box;padding:10px;border:1px solid #ccc;border-radius:6px;margin-top:5px;font-family:inherit}
.btn{border:0;border-radius:6px;padding:10px 14px;color:#fff;background:#2e7d32;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px}.btn-secondary{background:#6b7280}.btn-boleto{background:#00735e}.btn-doc{background:#e65100}.btn-sicoob{background:#7c3aed}
.msg-sucesso{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600}.msg-erro{background:#ffebee;color:#c62828;border:1px solid #ef9a9a;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600}
.table-container{overflow-x:auto}table{width:100%;min-width:1100px;border-collapse:collapse;background:#fff}th{background:#1b5e20;color:#fff;padding:12px;text-align:center}td{padding:11px;border-bottom:1px solid #eee;text-align:center;vertical-align:top}.acoes{display:flex;gap:7px;justify-content:center;flex-wrap:wrap}.status{font-weight:800;text-transform:uppercase;font-size:12px}.muted{color:#64748b;font-size:12px}
@media(max-width:900px){.filters{grid-template-columns:1fr}.container{padding:18px}}
</style>
<?php renderAppLayoutStyles(); ?>
</head>
<body>
<?php renderAppHeader('..'); ?>
<div class="container page-container app-shell">
<?php if (($_GET['msg'] ?? '') === 'boleto_excluido'): ?><div class="msg-sucesso">Registro de boleto excluido do sistema.</div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'cancelado'): ?><div class="msg-sucesso">Boleto baixado/cancelado no Sicoob com sucesso.</div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'sincronizado'): ?><div class="msg-sucesso"><?= htmlspecialchars($_GET['detalhe'] ?? 'Sincronizacao concluida.') ?></div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'erro_sync'): ?><div class="msg-erro"><?= htmlspecialchars($_GET['detalhe'] ?? 'Erro na sincronizacao.') ?></div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'erro'): ?><div class="msg-erro"><?= htmlspecialchars($_GET['detalhe'] ?? 'Erro ao processar.') ?></div><?php endif; ?>
<div class="card">
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px"><h2 style="margin:0">Histórico de Boletos</h2><a class="btn btn-sicoob" href="sincronizar_sicoob.php" title="Buscar status atualizado no Sicoob para todos os boletos registrados"><i class="bi bi-arrow-repeat"></i> Sincronizar com Sicoob</a></div>
<?php if (!$verTodos && $dataIni === '' && $dataFim === '' && $cicloHistAtivo && !empty($cicloHistAtivo['aberto_em'])): ?>
<div style="background:#e8f5e9;border:1px solid #c8e6c9;color:#2e7d32;padding:9px 14px;border-radius:7px;margin-bottom:14px;font-size:13px;font-weight:600;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
  <span><i class="bi bi-funnel"></i> Mostrando o ciclo atual (<?= htmlspecialchars(getNomeCiclo($cicloHistAtivo)) ?>). O histórico anterior está em Ciclos.</span>
  <a href="?todos=1" style="color:#1b5e20;font-weight:700;text-decoration:underline">Ver todos os boletos</a>
</div>
<?php endif; ?>
<form method="GET" class="filters">
  <div><label>Busca</label><input name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="OS, pagador, nº doc ou nosso número"></div>
  <div><label>Situação</label><select name="situacao"><option value="">Todas</option><?php foreach ($situacoes as $s): ?><option value="<?= htmlspecialchars($s) ?>" <?= $situacao === $s ? 'selected' : '' ?>><?= htmlspecialchars(boletoSituacaoLabel($s)) ?></option><?php endforeach; ?></select></div>
  <div><label>Data início</label><input type="date" name="data_ini" value="<?= htmlspecialchars($dataIni) ?>"></div>
  <div><label>Data fim</label><input type="date" name="data_fim" value="<?= htmlspecialchars($dataFim) ?>"></div>
  <button class="btn" type="submit"><i class="bi bi-search"></i></button>
</form>
</div>
<div class="card"><div class="table-container"><table>
<thead><tr><th>Data</th><th>OS</th><th>Nº Doc</th><th>Pagador</th><th>Nosso Número</th><th>Valor</th><th>Vencimento</th><th>Situação</th><th>Ações</th></tr></thead>
<tbody>
<?php if (!$boletos): ?><tr><td colspan="9">Nenhum boleto encontrado.</td></tr><?php endif; ?>
<?php foreach ($boletos as $b): ?>
<?php
$sit = (string) ($b['situacao'] ?? '');
$pdfRel = (string) ($b['pdf_path'] ?? '');
?>
<tr>
  <td><?= !empty($b['created_at']) ? date('d/m/Y H:i', strtotime((string) $b['created_at'])) : '-' ?></td>
  <td><?= htmlspecialchars((string) ($b['numero_os'] ?: '-')) ?></td>
  <td><?= htmlspecialchars((string) ($b['seu_numero'] ?: '-')) ?></td>
  <td><?= htmlspecialchars((string) ($b['pagador_nome'] ?: '-')) ?></td>
  <td><?= htmlspecialchars((string) ($b['nosso_numero'] ?: '-')) ?></td>
  <td>R$ <?= number_format((float) ($b['valor'] ?? 0), 2, ',', '.') ?></td>
  <td><?= !empty($b['data_vencimento']) ? date('d/m/Y', strtotime((string) $b['data_vencimento'])) : '-' ?></td>
  <td><span class="status" style="color:<?= boletoSituacaoCor($sit) ?>"><?= htmlspecialchars(boletoSituacaoLabel($sit)) ?></span></td>
  <td><div class="acoes">
    <a class="btn btn-boleto" href="../boletos/ver_boleto.php?id=<?= (int) $b['id'] ?>" title="Ver boleto / linha digitável / PIX"><i class="bi bi-eye"></i></a>
    <?php if (($sit === 'erro' || $sit === 'rascunho') && (string) ($b['numero_os'] ?? '') !== ''): ?><a class="btn" style="background:#e65100" href="rascunho_boleto.php?numero_os=<?= urlencode((string) $b['numero_os']) ?>" title="Tentar criar o boleto novamente"><i class="bi bi-arrow-clockwise"></i></a><?php endif; ?>
    <?php if ($pdfRel !== ''): ?><a class="btn btn-doc" href="pdf_boleto.php?id=<?= (int) $b['id'] ?>" target="_blank" rel="noopener" title="Baixar PDF"><i class="bi bi-file-earmark-pdf"></i></a><?php endif; ?>
  </div></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div></div>
</div>
</body>
</html>
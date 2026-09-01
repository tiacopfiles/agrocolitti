<?php
require "../config/conexao.php";
require "../config/ciclo_helper.php";
require "../auth/proteger.php";
require "../config/permissions.php";
require "../config/layout_helper.php";

requireModule('historico_nfe', '../index.php');

function nfeHistoricoTipoLabel(string $tipo): string
{
    return match ($tipo) {
        'venda' => 'Venda',
        'compra' => 'Compra',
        'devolucao_compra' => 'Devolucao de compra',
        'saida_abate_sem_entrada' => 'Saida apartada de abate',
        'caixa_retorno' => 'Retorno de caixas',
        default => $tipo !== '' ? $tipo : 'NF-e',
	    };
}

function nfeHistoricoValor(?string $payloadJson): float
{
    $payload = $payloadJson ? json_decode($payloadJson, true) : null;
    if (!is_array($payload)) return 0.0;
    $total = 0.0;
    foreach (($payload['items'] ?? []) as $item) {
        if (is_array($item)) {
            $total += (float) ($item['valor_bruto'] ?? 0);
        }
    }
    return $total;
}

function nfeHistoricoArquivoUrl(string $baseUrl, ?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
}

function nfeHistoricoFormaPagamentoVenda(array $formaPorVendaId, array $formaPorOs, array $doc): string
{
    $vendaId = (int) ($doc['venda_id'] ?? 0);
    if ($vendaId > 0 && isset($formaPorVendaId[$vendaId])) {
        return strtolower(trim((string) $formaPorVendaId[$vendaId]));
    }

    $numeroOs = trim((string) ($doc['numero_os'] ?? ''));
    if ($numeroOs !== '' && isset($formaPorOs[$numeroOs])) {
        return strtolower(trim((string) $formaPorOs[$numeroOs]));
    }

    return '';
}

function nfeHistoricoBoletoResumo(array $boletosPorOs, array $boletosPorNfeId, array $doc, bool $boletoObrigatorio = false, string $formaPagamento = ''): array
{
    $boletos = [];
    $numeroOs = trim((string) ($doc['numero_os'] ?? ''));
    $docId = (int) ($doc['id'] ?? 0);
    if ($numeroOs !== '' && isset($boletosPorOs[$numeroOs])) {
        $boletos = array_merge($boletos, $boletosPorOs[$numeroOs]);
    }
    if ($docId > 0 && isset($boletosPorNfeId[$docId])) {
        $boletos = array_merge($boletos, $boletosPorNfeId[$docId]);
    }
    $boleto = $boletos[0] ?? null;
    if (!$boleto && !$boletoObrigatorio) {
        return [
            'tem_boleto' => false,
            'boleto' => null,
            'classe' => 'pend',
            'rotulo' => 'Pendente',
        ];
    }

    return [
        'tem_boleto' => $boleto !== null,
        'boleto' => $boleto,
        'classe' => $boleto ? 'ok' : 'pend',
        'rotulo' => $boleto ? ('Boleto ' . ($boleto['situacao'] ?? 'gerado')) : 'Boleto pendente',
    ];
}

$busca = trim((string) ($_GET['busca'] ?? ''));
$tipo = trim((string) ($_GET['tipo'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$dataIni = trim((string) ($_GET['data_ini'] ?? ''));
$dataFim = trim((string) ($_GET['data_fim'] ?? ''));

$where = ["NOT (d.tipo_emissao = 'venda' AND d.status = 'rascunho')"];
$params = [];
$types = '';
if ($busca !== '') {
    $where[] = "(d.numero_os LIKE ? OR d.ref LIKE ? OR d.chave_nfe LIKE ? OR c.nome LIKE ? OR f.nome LIKE ?)";
    $like = '%' . $busca . '%';
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}
if ($tipo !== '') {
    $where[] = 'd.tipo_emissao = ?';
    $params[] = $tipo;
    $types .= 's';
}
if ($status !== '') {
    $where[] = 'd.status = ?';
    $params[] = $status;
    $types .= 's';
}
if ($dataIni !== '') {
    $where[] = 'DATE(COALESCE(d.autorizada_em, d.emitida_em, d.updated_at, d.created_at)) >= ?';
    $params[] = $dataIni;
    $types .= 's';
}
if ($dataFim !== '') {
    $where[] = 'DATE(COALESCE(d.autorizada_em, d.emitida_em, d.updated_at, d.created_at)) <= ?';
    $params[] = $dataFim;
    $types .= 's';
}

// Filtro padrao por CICLO ativo: por padrao a tela mostra apenas o ciclo corrente, entao
// ao virar o mes ela "zera" sozinha. Nada e apagado — o historico anterior fica no modulo
// Ciclos e pode ser consultado aqui informando datas ou usando ?todos=1.
$verTodos = (($_GET['todos'] ?? '') === '1');
$cicloHistAtivo = getCicloAtivo($conexao);
if (!$verTodos && $dataIni === '' && $dataFim === '' && $cicloHistAtivo && !empty($cicloHistAtivo['aberto_em'])) {
    $where[] = 'COALESCE(d.autorizada_em, d.emitida_em, d.updated_at, d.created_at) >= ?';
    $params[] = (string) $cicloHistAtivo['aberto_em'];
    $types .= 's';
}

$baseUrlFocus = '';
$cfg = $conexao->query("SELECT base_url FROM focus_config WHERE ambiente = 'producao' LIMIT 1");
if ($cfg && ($rowCfg = $cfg->fetch_assoc())) {
    $baseUrlFocus = (string) ($rowCfg['base_url'] ?? '');
}

$sql = "
    SELECT d.*,
           c.nome AS cliente_nome, c.nfe_nome_razao_social AS cliente_razao,
           c.email_nfe, c.email_nfe_2, c.email_nfe_3,
           f.nome AS fornecedor_nome, f.nfe_nome_razao_social AS fornecedor_razao
    FROM nfe_documentos d
    LEFT JOIN clientes c ON c.id = d.cliente_id
    LEFT JOIN fornecedores f ON f.id = d.fornecedor_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY COALESCE(d.autorizada_em, d.emitida_em, d.updated_at, d.created_at) DESC, d.id DESC
";
$stmt = $conexao->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$docs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$paripassuPorNfe = [];
$temParipassuOperacoes = false;
$resTabelaParipassu = $conexao->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paripassu_operacoes' LIMIT 1");
if ($resTabelaParipassu) {
    $temParipassuOperacoes = (bool) $resTabelaParipassu->fetch_row();
    $resTabelaParipassu->close();
}
if ($docs && $temParipassuOperacoes) {
    $idsNfe = array_values(array_unique(array_filter(array_map(static fn(array $d): int => (int) ($d['id'] ?? 0), $docs))));
    if ($idsNfe) {
        $marks = implode(',', array_fill(0, count($idsNfe), '?'));
        $stmt = $conexao->prepare("SELECT o.id,o.nfe_documento_id,o.status,o.tentativa FROM paripassu_operacoes o JOIN (SELECT nfe_documento_id,MAX(id) id FROM paripassu_operacoes WHERE CAST(tipo_operacao AS BINARY)=_binary'pedido' AND nfe_documento_id IN ($marks) GROUP BY nfe_documento_id) x ON x.id=o.id");
        $stmt->bind_param(str_repeat('i', count($idsNfe)), ...$idsNfe); $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $rowP) $paripassuPorNfe[(int) $rowP['nfe_documento_id']] = $rowP;
        $stmt->close();
    }
}
$formaPagamentoPorVendaId = [];
$formaPagamentoPorOs = [];
$resFormasVenda = $conexao->query("SELECT id, numero_os, forma_pagamento FROM vendas WHERE status = 'concluido'");
if ($resFormasVenda) {
    while ($rowForma = $resFormasVenda->fetch_assoc()) {
        $forma = strtolower(trim((string) ($rowForma['forma_pagamento'] ?? '')));
        $vendaIdForma = (int) ($rowForma['id'] ?? 0);
        if ($vendaIdForma > 0) {
            $formaPagamentoPorVendaId[$vendaIdForma] = $forma;
        }
        $numeroOsForma = trim((string) ($rowForma['numero_os'] ?? ''));
        if ($numeroOsForma !== '' && !isset($formaPagamentoPorOs[$numeroOsForma])) {
            $formaPagamentoPorOs[$numeroOsForma] = $forma;
        }
    }
}
// Boletos SICOOB ja gerados por OS (para o botao de boleto refletir status).
$boletosPorOs = [];
$boletosPorNfeId = [];
$resBol = $conexao->query("SELECT id, numero_os, venda_id, nfe_documento_id, situacao, valor FROM boletos WHERE situacao IN ('registrado','emitido','baixado') ORDER BY id DESC");
if ($resBol) {
    while ($rowBol = $resBol->fetch_assoc()) {
        $numeroOsBoleto = trim((string) ($rowBol['numero_os'] ?? ''));
        if ($numeroOsBoleto !== '') {
            $boletosPorOs[$numeroOsBoleto][] = $rowBol;
        }
        $nfeDocIdBoleto = (int) ($rowBol['nfe_documento_id'] ?? 0);
        if ($nfeDocIdBoleto > 0) {
            $boletosPorNfeId[$nfeDocIdBoleto][] = $rowBol;
        }
    }
}
$tipos = ['venda', 'compra', 'devolucao_compra', 'saida_abate_sem_entrada', 'caixa_retorno'];
$statuses = ['rascunho', 'enviada', 'processando', 'autorizada', 'rejeitada', 'cancelada', 'erro_cancelamento'];
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Histórico de NF-e</title>
<style>
body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;color:#222}.container{padding:30px;max-width:1400px;margin:auto}
.card{background:#fff;padding:24px;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,.06);margin-bottom:24px}.filters{display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr auto;gap:12px;align-items:end}
label{font-weight:700;font-size:14px}input,select,textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #ccc;border-radius:6px;margin-top:5px;font-family:inherit}
	.btn{border:0;border-radius:6px;padding:10px 14px;color:#fff;background:#2e7d32;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px}.btn-secondary{background:#6b7280}.btn-nfe{background:#7c3aed}.btn-danger{background:#b91c1c}.btn-doc{background:#e65100}.btn-boleto{background:#00735e}.btn-xml{background:#374151}
.filters-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap}.modal-options{display:grid;gap:14px;margin-top:12px}.modal-options label{display:block}.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:18px;flex-wrap:wrap}
.msg-sucesso{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600}.msg-erro{background:#ffebee;color:#c62828;border:1px solid #ef9a9a;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600}
	.table-container{overflow-x:auto}table{width:100%;min-width:1360px;border-collapse:collapse;background:#fff}th{background:#1b5e20;color:#fff;padding:12px;text-align:center}td{padding:11px;border-bottom:1px solid #eee;text-align:center;vertical-align:top}.acoes{display:flex;gap:7px;justify-content:center;flex-wrap:wrap}.status{font-weight:800;text-transform:uppercase;font-size:12px}.muted{color:#64748b;font-size:12px}.key{font-size:11px;word-break:break-all;max-width:220px}
	.badge-fluxo{display:inline-block;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:800;white-space:nowrap;border:1px solid #ffe082;background:#fff8e1;color:#a86a00}
	.badge-fluxo.ok{background:#e8f5e9;color:#2e7d32;border-color:#a5d6a7}
	.badge-fluxo.cancel{background:#eceff1;color:#455a64;border-color:#b0bec5}
	.badge-status{display:inline-block;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:800;text-transform:none;white-space:nowrap;border:1px solid #ffe082;background:#fff8e1;color:#a86a00}
	.badge-status.ok{background:#e8f5e9;color:#2e7d32;border-color:#a5d6a7}
	.badge-status.erro{background:#ffebee;color:#b91c1c;border-color:#ef9a9a}
	.badge-status.cancel{background:#eceff1;color:#455a64;border-color:#b0bec5}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1400;padding:20px;overflow-y:auto}.modal[style*="block"]{display:flex!important;align-items:center;justify-content:center}.modal-box{background:#fff;max-width:460px;width:min(100%,460px);padding:24px;border-radius:10px}
@media(max-width:900px){.filters{grid-template-columns:1fr}.container{padding:18px}}
</style>
<?php renderAppLayoutStyles(); ?>
<script>
function abrirModalCancelarNfe(data){
  document.getElementById('cancelar_nfe_documento_id').value=data.id||'';
  document.getElementById('cancelar_nfe_ref_label').textContent=data.ref||'-';
  document.getElementById('cancelar_nfe_numero_label').textContent=data.numero||'-';
  document.getElementById('cancelar_nfe_justificativa').value='';
  document.getElementById('modalCancelarNfe').style.display='block';
}
function fecharModalCancelarNfe(){document.getElementById('modalCancelarNfe').style.display='none'}
function abrirModalBaixarXmls(){document.getElementById('modalBaixarXmls').style.display='block'}
function fecharModalBaixarXmls(){document.getElementById('modalBaixarXmls').style.display='none'}
function confirmarCancelamentoNfe(){
  const texto=document.getElementById('cancelar_nfe_justificativa').value.trim();
  if(texto.length<15||texto.length>255){alert('A justificativa deve ter entre 15 e 255 caracteres.');return false;}
  return confirm('Cancelar esta NF-e na SEFAZ pela Focus?');
}
</script>
</head>
<body>
<?php renderAppHeader('..'); ?>
<div class="container page-container app-shell">
<?php if (($_GET['msg'] ?? '') === 'consultada'): ?><div class="msg-sucesso">NF-e consultada e atualizada com sucesso. Ref: <?= htmlspecialchars($_GET['ref'] ?? '-') ?></div><?php endif; ?>
<?php if (($_GET['email_xml'] ?? '') === 'enviado'): ?><div class="msg-sucesso"><?= htmlspecialchars($_GET['email_msg'] ?? 'XML enviado por e-mail com sucesso.') ?></div><?php endif; ?>
<?php if (($_GET['email_xml'] ?? '') === 'aguardando_xml'): ?><div class="msg-sucesso"><?= htmlspecialchars($_GET['email_msg'] ?? 'NF-e autorizada; aguardando a Focus disponibilizar o XML.') ?></div><?php endif; ?>
<?php if (($_GET['email_xml'] ?? '') === 'falhou'): ?><div class="msg-erro"><?= htmlspecialchars($_GET['email_msg'] ?? 'A NF-e foi autorizada, mas o XML nao foi enviado por e-mail.') ?></div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'nfe_cancelada'): ?><div class="msg-sucesso">NF-e cancelada com sucesso. Ref: <?= htmlspecialchars($_GET['ref'] ?? '-') ?></div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'erro'): ?><div class="msg-erro"><?= htmlspecialchars($_GET['detalhe'] ?? 'Erro ao processar.') ?></div><?php endif; ?>
<div class="card">
<h2>Histórico de NF-e</h2>
<?php if (!$verTodos && $dataIni === '' && $dataFim === '' && $cicloHistAtivo && !empty($cicloHistAtivo['aberto_em'])): ?>
<div style="background:#e8f5e9;border:1px solid #c8e6c9;color:#2e7d32;padding:9px 14px;border-radius:7px;margin-bottom:14px;font-size:13px;font-weight:600;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
  <span><i class="bi bi-funnel"></i> Mostrando o ciclo atual (<?= htmlspecialchars(getNomeCiclo($cicloHistAtivo)) ?>). O histórico anterior está em Ciclos.</span>
  <a href="?todos=1" style="color:#1b5e20;font-weight:700;text-decoration:underline">Ver todas as notas</a>
</div>
<?php endif; ?>
<form method="GET" class="filters">
  <div><label>Busca</label><input name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="OS, pessoa, ref ou chave"></div>
  <div><label>Tipo</label><select name="tipo"><option value="">Todos</option><?php foreach ($tipos as $t): ?><option value="<?= htmlspecialchars($t) ?>" <?= $tipo === $t ? 'selected' : '' ?>><?= htmlspecialchars(nfeHistoricoTipoLabel($t)) ?></option><?php endforeach; ?></select></div>
  <div><label>Status</label><select name="status"><option value="">Todos</option><?php foreach ($statuses as $s): ?><option value="<?= htmlspecialchars($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option><?php endforeach; ?></select></div>
  <div><label>Data início</label><input type="date" name="data_ini" value="<?= htmlspecialchars($dataIni) ?>"></div>
  <div><label>Data fim</label><input type="date" name="data_fim" value="<?= htmlspecialchars($dataFim) ?>"></div>
  <button class="btn" type="submit"><i class="bi bi-search"></i></button>
</form>
<div class="filters-actions"><button class="btn btn-xml" type="button" onclick="abrirModalBaixarXmls()"><i class="bi bi-file-zip"></i> Baixar XMLs</button></div>
</div>
<div class="card"><div class="table-container"><table>
<thead><tr><th>Data</th><th>OS</th><th>Tipo</th><th>Pessoa</th><th>Ref Focus</th><th>NF-e</th><th>Chave</th><th>Valor</th><th>Status</th><th>PariPassu</th><th>Boleto</th><th>Ações</th></tr></thead>
<tbody>
<?php if (!$docs): ?><tr><td colspan="12">Nenhuma NF-e encontrada.</td></tr><?php endif; ?>
<?php foreach ($docs as $doc): ?>
<?php
$pessoa = (string) (($doc['cliente_razao'] ?: $doc['cliente_nome']) ?: ($doc['fornecedor_razao'] ?: $doc['fornecedor_nome']) ?: '-');
$valor = nfeHistoricoValor($doc['payload_json'] ?? null);
$danfeUrl = nfeHistoricoArquivoUrl($baseUrlFocus, $doc['caminho_danfe'] ?? '');
$xmlUrl = nfeHistoricoArquivoUrl($baseUrlFocus, $doc['caminho_xml'] ?? '');
$numero = trim((string) ($doc['numero_nfe'] ?? '')) !== '' ? ((string) $doc['numero_nfe'] . ' / ' . (string) ($doc['serie'] ?: '1')) : '-';
$cancelarData = ['id' => (int) $doc['id'], 'ref' => (string) $doc['ref'], 'numero' => $numero];
$formaPagamentoDoc = nfeHistoricoFormaPagamentoVenda($formaPagamentoPorVendaId, $formaPagamentoPorOs, $doc);
$boletoObrigatorioDoc = $formaPagamentoDoc === 'boleto';
$resumoBoletoDoc = nfeHistoricoBoletoResumo($boletosPorOs, $boletosPorNfeId, $doc, $boletoObrigatorioDoc, $formaPagamentoDoc);
$boletoDoc = $resumoBoletoDoc['boleto'];
$mostraBoleto = (($doc['tipo_emissao'] ?? '') === 'venda' && ($doc['status'] ?? '') === 'autorizada');
$statusDoc = strtolower((string) ($doc['status'] ?? ''));
$statusClasse = $statusDoc === 'autorizada' ? 'ok' : (in_array($statusDoc, ['rejeitada', 'erro'], true) ? 'erro' : ($statusDoc === 'cancelada' ? 'cancel' : ''));
$statusRotulo = $statusDoc === 'autorizada' ? 'Autorizado' : (string) ($doc['status'] ?? '-');
$operacaoParipassu = $paripassuPorNfe[(int) $doc['id']] ?? null;
$statusParipassu = (string) ($operacaoParipassu['status'] ?? '');
$rotuloParipassu = 'Não iniciado';
if ($statusParipassu === 'validado_localmente') $rotuloParipassu = 'Dry-run criado';
elseif (in_array($statusParipassu, ['pronto_para_envio', 'aguardando_envio'], true)) $rotuloParipassu = 'Aguardando envio';
elseif (in_array($statusParipassu, ['confirmado_remotamente', 'confirmado'], true)) $rotuloParipassu = 'Confirmado';
elseif (str_starts_with($statusParipassu, 'bloqueado')) $rotuloParipassu = 'Bloqueado';
elseif (in_array($statusParipassu, ['resultado_remoto_incerto', 'resultado_indeterminado', 'resposta_incerta', 'aguardando_conciliacao'], true)) $rotuloParipassu = 'Divergente';
elseif ($statusParipassu !== '') $rotuloParipassu = $statusParipassu;
$classeParipassu = in_array($statusParipassu, ['validado_localmente','confirmado_remotamente','confirmado'], true) ? 'ok' : (str_starts_with($statusParipassu, 'bloqueado') || in_array($statusParipassu, ['resultado_remoto_incerto','resultado_indeterminado','resposta_incerta','aguardando_conciliacao'], true) ? 'erro' : 'cancel');
$emailsXml = array_values(array_unique(array_filter(array_map(static fn($email): string => strtolower(trim((string) $email)), [$doc['email_nfe'] ?? '', $doc['email_nfe_2'] ?? '', $doc['email_nfe_3'] ?? '']), static fn(string $email): bool => $email !== '')));
$emailXmlJaEnviado = ($doc['email_xml_status'] ?? '') === 'enviado';
$emailXmlEnviadoEm = !empty($doc['email_xml_enviado_em']) ? date('d/m/Y H:i', strtotime((string) $doc['email_xml_enviado_em'])) : '';
$confirmacaoEmailXml = ($emailXmlJaEnviado ? 'O XML ja foi enviado' . ($emailXmlEnviadoEm !== '' ? ' em ' . $emailXmlEnviadoEm : '') . '. Deseja reenviar' : 'Enviar o XML') . ' para: ' . implode(', ', $emailsXml) . '?';
?>
<tr>
  <td><?= !empty($doc['emitida_em']) ? date('d/m/Y H:i', strtotime((string) $doc['emitida_em'])) : (!empty($doc['created_at']) ? date('d/m/Y H:i', strtotime((string) $doc['created_at'])) : '-') ?></td>
  <td><?= htmlspecialchars((string) ($doc['numero_os'] ?: '-')) ?></td>
  <td><?= htmlspecialchars(nfeHistoricoTipoLabel((string) ($doc['tipo_emissao'] ?? ''))) ?></td>
  <td><?= htmlspecialchars($pessoa) ?></td>
  <td><?= htmlspecialchars((string) $doc['ref']) ?></td>
  <td><?= htmlspecialchars($numero) ?></td>
  <td><div class="key"><?= htmlspecialchars((string) ($doc['chave_nfe'] ?: '-')) ?></div></td>
  <td>R$ <?= number_format($valor, 2, ',', '.') ?></td>
  <td><span class="badge-status <?= htmlspecialchars($statusClasse) ?>"><?= htmlspecialchars($statusRotulo) ?></span><?php if (!empty($doc['mensagem_sefaz'])): ?><br><span class="muted"><?= htmlspecialchars((string) $doc['mensagem_sefaz']) ?></span><?php endif; ?></td>
  <td><span class="badge-status <?= htmlspecialchars($classeParipassu) ?>"><?= htmlspecialchars($rotuloParipassu) ?></span><?php if ($operacaoParipassu): ?><br><a class="muted" href="../paripassu/pedidos.php?operacao_id=<?= (int) $operacaoParipassu['id'] ?>">Ver operação</a><?php endif; ?></td>
  <td><?php if ($mostraBoleto): ?><span class="badge-fluxo <?= $resumoBoletoDoc['classe'] ?>"><?= htmlspecialchars($resumoBoletoDoc['rotulo']) ?></span><?php else: ?><span class="badge-fluxo cancel">Nao se aplica</span><?php endif; ?></td>
  <td><div class="acoes">
    <form method="POST" action="actions/consultar_nfe.php" style="margin:0;"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>"><input type="hidden" name="ref" value="<?= htmlspecialchars((string) $doc['ref'], ENT_QUOTES) ?>"><input type="hidden" name="ambiente" value="<?= htmlspecialchars((string) $doc['ambiente'], ENT_QUOTES) ?>"><button class="btn btn-nfe" type="submit" title="Consultar Focus"><i class="bi bi-arrow-repeat"></i></button></form>
    <?php if ($danfeUrl !== ''): ?><a class="btn btn-doc" href="<?= htmlspecialchars($danfeUrl) ?>" target="_blank" rel="noopener" title="Abrir DANFE/PDF"><i class="bi bi-file-earmark-pdf"></i></a><?php endif; ?>
    <?php if ($xmlUrl !== ''): ?><a class="btn btn-xml" href="<?= htmlspecialchars($xmlUrl) ?>" target="_blank" rel="noopener" title="Abrir XML"><i class="bi bi-filetype-xml"></i></a><?php endif; ?>
    <?php if (($doc['tipo_emissao'] ?? '') === 'venda' && ($doc['status'] ?? '') === 'autorizada' && $emailsXml): ?><form method="POST" action="actions/reenviar_email_xml.php" style="margin:0" onsubmit="return confirm(<?= htmlspecialchars(json_encode($confirmacaoEmailXml, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>)"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>"><input type="hidden" name="documento_id" value="<?= (int) $doc['id'] ?>"><button class="btn btn-nfe" type="submit" title="<?= htmlspecialchars($emailXmlJaEnviado ? ('XML enviado' . ($emailXmlEnviadoEm !== '' ? ' em ' . $emailXmlEnviadoEm : '') . ' — reenviar') : 'Enviar XML por e-mail', ENT_QUOTES) ?>"><i class="bi <?= $emailXmlJaEnviado ? 'bi-envelope-check' : 'bi-envelope-arrow-up' ?>"></i></button></form><?php endif; ?>
    <?php if (($doc['status'] ?? '') === 'autorizada'): ?><button class="btn btn-danger" type="button" title="Cancelar NF-e" onclick="abrirModalCancelarNfe(<?= htmlspecialchars(json_encode($cancelarData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>)"><i class="bi bi-x-octagon"></i></button><?php endif; ?><?php if ($mostraBoleto): $osB = (string) ($doc['numero_os'] ?? ''); ?><?php if ($boletoDoc): ?><a class="btn btn-boleto" href="../boletos/ver_boleto.php?id=<?= (int) $boletoDoc['id'] ?>" title="Boleto SICOOB ja gerado (R$ <?= number_format((float) $boletoDoc['valor'], 2, ',', '.') ?>) - ver"><i class="bi bi-upc-scan"></i></a><?php else: ?><a class="btn btn-boleto" href="../boletos/rascunho_boleto.php?numero_os=<?= urlencode($osB) ?>" title="Criar Boleto SICOOB"><i class="bi bi-upc-scan"></i></a><?php endif; ?><?php endif; ?>
  </div></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div></div>
</div>
<div id="modalBaixarXmls" class="modal"><div class="modal-box"><h3>Baixar XMLs em ZIP</h3><form method="POST" action="actions/baixar_xmls.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>"><div class="modal-options"><div><label>Tipo de nota</label><select name="tipo"><option value="todos">Venda e compra</option><option value="venda">Somente venda</option><option value="compra">Somente compra</option></select></div><div><label>Status</label><select name="status"><option value="todos">Autorizadas e canceladas</option><option value="autorizada">Somente autorizadas</option><option value="cancelada">Somente canceladas</option></select></div><div><label>Data inicio</label><input type="date" name="data_ini"></div><div><label>Data fim</label><input type="date" name="data_fim"></div></div><div class="modal-actions"><button type="button" class="btn btn-secondary" onclick="fecharModalBaixarXmls()"><i class="bi bi-x-lg"></i></button><button class="btn btn-xml" type="submit"><i class="bi bi-download"></i> Baixar ZIP</button></div></form></div></div>
<div id="modalCancelarNfe" class="modal"><div class="modal-box"><h3>Cancelar NF-e</h3><form method="POST" action="actions/cancelar_nfe.php" onsubmit="return confirmarCancelamentoNfe()"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>"><input type="hidden" name="documento_id" id="cancelar_nfe_documento_id"><p><strong>Nota:</strong> <span id="cancelar_nfe_numero_label">-</span></p><p><strong>Ref:</strong> <span id="cancelar_nfe_ref_label">-</span></p><label>Justificativa</label><textarea name="justificativa" id="cancelar_nfe_justificativa" minlength="15" maxlength="255" required></textarea><div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;"><button type="button" class="btn btn-secondary" onclick="fecharModalCancelarNfe()"><i class="bi bi-x-lg"></i></button><button class="btn btn-danger" type="submit"><i class="bi bi-x-octagon"></i> Cancelar NF-e</button></div></form></div></div>
</body>
</html>

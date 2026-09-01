<?php
require "../config/conexao.php";
require "../auth/proteger.php";
require "../config/permissions.php";
require "../config/layout_helper.php";
require "../focus/focus_nfe_caixas.php";

requireModule('notas_caixas', '../index.php');
caixaNotasGarantirTabelas($conexao);

$busca = trim((string) ($_GET['busca'] ?? ''));
$where = ['1=1'];
$params = [];
$types = '';
if ($busca !== '') {
    $where[] = "(n.numero_os LIKE ? OR c.nome LIKE ? OR c.nfe_nome_razao_social LIKE ?)";
    $like = '%' . $busca . '%';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}

$sql = "
    SELECT n.*, c.nome AS cliente_nome, c.nfe_nome_razao_social,
           d.id AS documento_id, d.ref, d.status AS nfe_status, d.numero_nfe, d.serie, d.mensagem_sefaz,
           d.caminho_danfe, d.caminho_xml
    FROM nfe_caixa_notas n
    LEFT JOIN clientes c ON c.id = n.cliente_id
    LEFT JOIN nfe_documentos d ON d.origem_tipo = 'nota_caixa' AND d.origem_id = n.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY n.created_at DESC, n.id DESC
";
$stmt = $conexao->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$notas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$baseUrlFocus = '';
$cfgCaixa = $conexao->query("SELECT base_url FROM focus_config WHERE ambiente = 'producao' LIMIT 1");
if ($cfgCaixa && ($rowCfgCaixa = $cfgCaixa->fetch_assoc())) {
    $baseUrlFocus = (string) ($rowCfgCaixa['base_url'] ?? '');
}
if (!function_exists('caixaArquivoUrl')) {
    function caixaArquivoUrl($baseUrl, $path) {
        $path = trim((string) $path);
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path;
        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Histórico de Notas de Caixas</title>
<style>
body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;color:#222}.container{padding:30px;max-width:1200px;margin:auto}
.card{background:#fff;padding:24px;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,.06);margin-bottom:24px}.filters{display:grid;grid-template-columns:1fr auto auto;gap:12px;align-items:end}
label{font-weight:700;font-size:14px}input,textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #ccc;border-radius:6px;margin-top:5px;font-family:inherit}.btn{border:0;border-radius:6px;padding:10px 14px;color:#fff;background:#2e7d32;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px}
.btn-secondary{background:#6b7280}.btn-doc{background:#e65100}.btn-xml{background:#0277bd}.btn-nfe{background:#7c3aed}.btn-danger{background:#b91c1c}.btn-muted{background:#64748b}.acoes{display:flex;gap:8px;justify-content:center;flex-wrap:wrap}
.msg-sucesso{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600}.msg-erro{background:#ffebee;color:#c62828;border:1px solid #ef9a9a;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600}
.table-container{overflow-x:auto}table{width:100%;min-width:980px;border-collapse:collapse;background:#fff}th{background:#1b5e20;color:#fff;padding:12px;text-align:center}td{padding:12px;border-bottom:1px solid #eee;text-align:center}.status{font-weight:800;text-transform:uppercase;font-size:12px}.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1400;padding:20px}.modal[style*="block"]{display:flex!important;align-items:center;justify-content:center}.modal-box{background:#fff;max-width:420px;width:min(100%,420px);padding:24px;border-radius:10px}
@media(max-width:760px){.filters{grid-template-columns:1fr}.container{padding:18px}}
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
function nfeCaixaNumero(v, dec){ var n = Number(v); if (isNaN(n)) n = 0; return n.toFixed(dec); }
function attrCaixa(v){ return String(v == null ? '' : v).replace(/"/g, '&quot;'); }
function recalcItemCaixa(card){
    var q = Number(card.querySelector('.cit-qtd').value || 0);
    var vu = Number(card.querySelector('.cit-vu').value || 0);
    if (isNaN(q)) q = 0; if (isNaN(vu)) vu = 0;
    card.querySelector('.cit-vb').value = (q * vu).toFixed(2);
    card.querySelector('.cit-hq').value = q.toFixed(4);
    card.querySelector('.cit-hvu').value = vu.toFixed(4);
}
function criarCardItemCaixa(item, idx){
    var div = document.createElement('div');
    div.className = 'nfe-citem';
    var nome = item.descricao || ('Item ' + (idx + 1));
    div.innerHTML =
        '<div style="display:flex;justify-content:flex-start;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:6px;">' +
        '<strong>' + String(nome).replace(/</g, '&lt;') + '</strong>' +
        '<label class="cit-del">excluir <input type="checkbox" onchange="var c=this.closest(\'.nfe-citem\');c.querySelector(\'.cit-excluir\').value=this.checked?\'1\':\'0\';c.style.opacity=this.checked?0.45:1;"></label>' +
        '</div>' +
        '<input type="hidden" class="cit-excluir" name="items[' + idx + '][excluir]" value="0">' +
        '<div class="nfe-cgrid">' +
        '<div><label>Descricao</label><input name="items[' + idx + '][descricao]" value="' + attrCaixa(item.descricao) + '"></div>' +
        '<div><label>NCM</label><input name="items[' + idx + '][codigo_ncm]" value="' + attrCaixa(item.codigo_ncm) + '"></div>' +
        '<div><label>CFOP</label><input name="items[' + idx + '][cfop]" value="' + attrCaixa(item.cfop) + '"></div>' +
        '<div><label>Unidade</label><input name="items[' + idx + '][unidade_comercial]" value="' + attrCaixa(item.unidade_comercial || 'UN') + '"></div>' +
        '<div><label>Quantidade</label><input type="number" step="0.0001" class="cit-qtd" oninput="recalcItemCaixa(this.closest(\'.nfe-citem\'))" name="items[' + idx + '][quantidade_comercial]" value="' + nfeCaixaNumero(item.quantidade_comercial, 4) + '"></div>' +
        '<div><label>Valor unitario</label><input type="number" step="0.0001" class="cit-vu" oninput="recalcItemCaixa(this.closest(\'.nfe-citem\'))" name="items[' + idx + '][valor_unitario_comercial]" value="' + nfeCaixaNumero(item.valor_unitario_comercial, 4) + '"></div>' +
        '<div><label>Valor bruto</label><input type="number" step="0.01" class="cit-vb" name="items[' + idx + '][valor_bruto]" value="' + nfeCaixaNumero(item.valor_bruto, 2) + '"></div>' +
        '<div><label>CST ICMS</label><input name="items[' + idx + '][icms_situacao_tributaria]" value="' + attrCaixa(item.icms_situacao_tributaria || '41') + '"></div>' +
        '<div><label>Origem ICMS</label><input name="items[' + idx + '][icms_origem]" value="' + attrCaixa(item.icms_origem || '0') + '"></div>' +
        '<div><label>CST PIS</label><input name="items[' + idx + '][pis_situacao_tributaria]" value="' + attrCaixa(item.pis_situacao_tributaria || '06') + '"></div>' +
        '<div><label>CST COFINS</label><input name="items[' + idx + '][cofins_situacao_tributaria]" value="' + attrCaixa(item.cofins_situacao_tributaria || '06') + '"></div>' +
        '<div><label>cBenef</label><input name="items[' + idx + '][codigo_beneficio_fiscal]" value="' + attrCaixa(item.codigo_beneficio_fiscal || 'SP070100') + '"></div>' +
        '</div>' +
        '<input type="hidden" class="cit-hq" name="items[' + idx + '][quantidade_tributavel]" value="' + nfeCaixaNumero(item.quantidade_comercial, 4) + '">' +
        '<input type="hidden" class="cit-hvu" name="items[' + idx + '][valor_unitario_tributavel]" value="' + nfeCaixaNumero(item.valor_unitario_comercial, 4) + '">' +
        '<input type="hidden" name="items[' + idx + '][unidade_tributavel]" value="' + attrCaixa(item.unidade_comercial || 'UN') + '">';
    return div;
}
function carregarRascunhoNfeCaixa(){
    var id = document.getElementById('nfe_caixa_nota_id').value;
    var box = document.getElementById('nfe_caixa_items_box');
    var msg = document.getElementById('nfe_caixa_rascunho_msg');
    box.innerHTML = '';
    msg.style.display = 'block'; msg.style.color = '#555'; msg.textContent = 'Gerando rascunho...';
    var fd = new FormData();
    fd.append('nota_id', id); fd.append('ambiente', 'producao');
    fetch('actions/rascunho_nfe_caixa.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) {
                msg.style.color = '#b91c1c';
                msg.textContent = 'Nao foi possivel gerar o rascunho: ' + ((d && d.erro) ? d.erro : 'erro desconhecido');
                return;
            }
            var dd = d.destinatario || {};
            ['nome','cpf','cnpj','ie','indicador_ie','logradouro','numero','complemento','bairro','municipio','uf','cep','telefone','email'].forEach(function (c) {
                var el = document.getElementById('nfe_caixa_dest_' + c);
                if (el) el.value = (dd[c] != null ? dd[c] : '');
            });
            (d.items || []).forEach(function (item, idx) { box.appendChild(criarCardItemCaixa(item, idx)); });
            if (!d.items || d.items.length === 0) { msg.style.color = '#b91c1c'; msg.textContent = 'Rascunho gerado sem itens.'; }
            else { msg.style.display = 'none'; }
        })
        .catch(function (e) { msg.style.color = '#b91c1c'; msg.textContent = 'Falha ao gerar rascunho: ' + e; });
}
function abrirModalNfeCaixa(notaId, osLabel){
    document.getElementById('nfe_caixa_nota_id').value = notaId || '';
    document.getElementById('nfe_caixa_os_label').textContent = osLabel || '-';
    document.getElementById('nfe_caixa_informacoes_adicionais').value = '';
    carregarRascunhoNfeCaixa();
    document.getElementById('modalNfeCaixa').style.display = 'block';
}
function fecharModalNfeCaixa(){ document.getElementById('modalNfeCaixa').style.display = 'none'; }
function confirmarEmissaoNfeCaixa(){
    var box = document.getElementById('nfe_caixa_items_box');
    var ativos = box.querySelectorAll('.cit-excluir');
    var n = 0;
    ativos.forEach(function (el) { if (el.value !== '1') n++; });
    if (n === 0) { alert('A nota precisa de pelo menos um produto.'); return false; }
    return confirm('Emitir NF-e de retorno de caixas com os dados revisados?');
}
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
<?php if (($_GET['msg'] ?? '') === 'criada'): ?><div class="msg-sucesso">Nota de caixas criada com sucesso.</div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'nfe_enviada'): ?><div class="msg-sucesso">NF-e autorizada/recebida pela Focus com sucesso. Ref: <?= htmlspecialchars($_GET['ref'] ?? '-') ?></div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'nfe_processando'): ?><div class="msg-sucesso">NF-e aceita pela Focus e em processamento. Ref: <?= htmlspecialchars($_GET['ref'] ?? '-') ?></div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'nfe_cancelada'): ?><div class="msg-sucesso">NF-e cancelada com sucesso. Ref: <?= htmlspecialchars($_GET['ref'] ?? '-') ?></div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'erro'): ?><div class="msg-erro"><?= htmlspecialchars($_GET['detalhe'] ?? 'Erro ao processar.') ?></div><?php endif; ?>
<div class="card">
<h2>Histórico de Devolução de Caixas</h2>
<form method="GET" class="filters">
  <div><label>Busca</label><input name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="OS ou cliente"></div>
  <a class="btn btn-secondary" href="nova.php"><i class="bi bi-plus-square"></i> Nova</a>
  <button class="btn" type="submit"><i class="bi bi-search"></i></button>
</form>
</div>
<div class="card"><div class="table-container"><table>
<thead><tr><th>Data</th><th>OS</th><th>Cliente</th><th>Qtd caixas</th><th>Peso</th><th>Valor</th><th>Status</th><th>Documentos</th></tr></thead>
<tbody>
<?php if (!$notas): ?><tr><td colspan="8">Nenhuma nota de caixas encontrada.</td></tr><?php endif; ?>
<?php foreach ($notas as $nota): ?>
<?php
$status = (string) ($nota['nfe_status'] ?: $nota['status']);
$clienteNome = (string) ($nota['nfe_nome_razao_social'] ?: $nota['cliente_nome'] ?: '-');
?>
<tr>
  <td><?= !empty($nota['data_nota']) ? date('d/m/Y', strtotime((string) $nota['data_nota'])) : '-' ?></td>
  <td><?= htmlspecialchars((string) $nota['numero_os']) ?></td>
  <td><?= htmlspecialchars($clienteNome) ?></td>
  <td><?= (int) $nota['quantidade_total'] ?></td>
  <td><?= number_format((float) $nota['peso_bruto'], 3, ',', '.') ?> kg</td>
  <td>R$ <?= number_format((float) $nota['valor_total'], 2, ',', '.') ?></td>
  <td><span class="status"><?= htmlspecialchars($status) ?></span><?php if (!empty($nota['mensagem_sefaz'])): ?><br><small><?= htmlspecialchars((string) $nota['mensagem_sefaz']) ?></small><?php endif; ?></td>
  <td><div class="acoes">
    <button class="btn btn-nfe" type="button" title="Emitir NF-e" onclick="abrirModalNfeCaixa(<?= (int) $nota['id'] ?>, '<?= htmlspecialchars(addslashes((string) $nota['numero_os']), ENT_QUOTES) ?>')"><i class="bi bi-receipt"></i></button>
    <?php
    $danfeUrlCaixa = caixaArquivoUrl($baseUrlFocus, $nota['caminho_danfe'] ?? '');
    $xmlUrlCaixa = caixaArquivoUrl($baseUrlFocus, $nota['caminho_xml'] ?? '');
    ?>
    <?php if ($danfeUrlCaixa !== ''): ?>
    <a class="btn btn-doc" href="<?= htmlspecialchars($danfeUrlCaixa) ?>" target="_blank" rel="noopener" title="Baixar DANFE (PDF)"><i class="bi bi-file-earmark-pdf"></i></a>
    <?php endif; ?>
    <?php if ($xmlUrlCaixa !== ''): ?>
    <a class="btn btn-xml" href="<?= htmlspecialchars($xmlUrlCaixa) ?>" target="_blank" rel="noopener" title="Baixar XML"><i class="bi bi-filetype-xml"></i></a>
    <?php endif; ?>
    <?php if (($nota['nfe_status'] ?? '') === 'autorizada'): ?>
    <?php $cancelarData = ['id' => (int) $nota['documento_id'], 'ref' => (string) $nota['ref'], 'numero' => trim((string) ($nota['numero_nfe'] ?? '')) !== '' ? ('NF-e ' . $nota['numero_nfe'] . '/' . ($nota['serie'] ?: '1')) : (string) $nota['ref']]; ?>
    <button class="btn btn-danger" type="button" title="Cancelar NF-e" onclick="abrirModalCancelarNfe(<?= htmlspecialchars(json_encode($cancelarData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>)"><i class="bi bi-x-octagon"></i></button>
    <?php elseif (($nota['nfe_status'] ?? '') === 'cancelada'): ?>
    <button class="btn btn-muted" type="button" title="NF-e cancelada"><i class="bi bi-x-octagon"></i></button>
    <?php endif; ?>
  </div></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div></div>
</div>
<style>
#modalNfeCaixa .modal-box{max-width:920px;width:100%;}
#modalNfeCaixa textarea{width:100%;padding:8px;border:1px solid #ccc;border-radius:6px;margin-top:5px;}
.nfe-csec{margin-top:14px;}
.nfe-csec h4{margin:0 0 6px 0;font-size:14px;color:#1b5e20;}
.nfe-cgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;min-width:0;}
.nfe-cgrid label{font-size:12px;color:#555;display:block;margin-bottom:2px;}
.nfe-cgrid input{width:100%;padding:6px;border:1px solid #ccc;border-radius:5px;font-size:13px;box-sizing:border-box;}
.nfe-citem{border:1px solid #e0e0e0;border-radius:8px;padding:10px;margin-bottom:10px;background:#fafafa;max-width:100%;box-sizing:border-box;}.cit-del{display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#b91c1c;border:1px solid #fca5a5;background:#fff5f5;border-radius:6px;padding:3px 8px;cursor:pointer;white-space:nowrap;}.cit-del input{margin:0;cursor:pointer;}
#nfe_caixa_items_box{max-height:42vh;overflow-y:auto;overflow-x:hidden;padding-right:10px;}
</style>
<div id="modalNfeCaixa" class="modal"><div class="modal-box"><h3>Revisar e emitir NF-e de caixas</h3><form method="POST" action="actions/emitir_nfe_focus.php" onsubmit="return confirmarEmissaoNfeCaixa();"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>"><input type="hidden" name="ambiente" value="producao"><input type="hidden" name="nota_id" id="nfe_caixa_nota_id"><p><strong>OS:</strong> <span id="nfe_caixa_os_label">-</span></p><p id="nfe_caixa_rascunho_msg" style="display:none;margin-top:4px;font-size:13px;"></p><div class="nfe-csec"><h4>Destinatario (cliente)</h4><div class="nfe-cgrid"><div><label>Nome/Razao</label><input name="destinatario[nome]" id="nfe_caixa_dest_nome"></div><div><label>CPF</label><input name="destinatario[cpf]" id="nfe_caixa_dest_cpf"></div><div><label>CNPJ</label><input name="destinatario[cnpj]" id="nfe_caixa_dest_cnpj"></div><div><label>IE</label><input name="destinatario[ie]" id="nfe_caixa_dest_ie"></div><div><label>Indicador IE</label><input name="destinatario[indicador_ie]" id="nfe_caixa_dest_indicador_ie"></div><div><label>Telefone</label><input name="destinatario[telefone]" id="nfe_caixa_dest_telefone"></div><div><label>Email</label><input name="destinatario[email]" id="nfe_caixa_dest_email"></div><div><label>CEP</label><input name="destinatario[cep]" id="nfe_caixa_dest_cep"></div><div><label>UF</label><input name="destinatario[uf]" id="nfe_caixa_dest_uf" maxlength="2"></div><div><label>Municipio</label><input name="destinatario[municipio]" id="nfe_caixa_dest_municipio"></div><div><label>Logradouro</label><input name="destinatario[logradouro]" id="nfe_caixa_dest_logradouro"></div><div><label>Numero</label><input name="destinatario[numero]" id="nfe_caixa_dest_numero"></div><div><label>Bairro</label><input name="destinatario[bairro]" id="nfe_caixa_dest_bairro"></div><div><label>Complemento</label><input name="destinatario[complemento]" id="nfe_caixa_dest_complemento"></div></div></div><div class="nfe-csec"><h4>Itens da nota</h4><div id="nfe_caixa_items_box"></div></div><label style="margin-top:12px;display:block;">Observacao para informacoes adicionais do DANFE</label><textarea name="informacoes_adicionais_contribuinte" id="nfe_caixa_informacoes_adicionais" maxlength="2000" placeholder="Texto exibido no campo Informacoes Adicionais da nota"></textarea><div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;"><button type="button" class="btn btn-secondary" onclick="fecharModalNfeCaixa()"><i class="bi bi-x-lg"></i></button><button class="btn btn-nfe" type="submit"><i class="bi bi-receipt"></i> Emitir NF-e</button></div></form></div></div>
<div id="modalCancelarNfe" class="modal"><div class="modal-box"><h3>Cancelar NF-e</h3><form method="POST" action="actions/cancelar_nfe_focus.php" onsubmit="return confirmarCancelamentoNfe()"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>"><input type="hidden" name="ambiente" value="producao"><input type="hidden" name="documento_id" id="cancelar_nfe_documento_id"><p><strong>Nota:</strong> <span id="cancelar_nfe_numero_label">-</span></p><p><strong>Ref:</strong> <span id="cancelar_nfe_ref_label">-</span></p><label>Justificativa</label><textarea name="justificativa" id="cancelar_nfe_justificativa" minlength="15" maxlength="255" required></textarea><div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;"><button type="button" class="btn btn-secondary" onclick="fecharModalCancelarNfe()"><i class="bi bi-x-lg"></i></button><button class="btn btn-danger" type="submit"><i class="bi bi-x-octagon"></i> Cancelar NF-e</button></div></form></div></div>
</body>
</html>

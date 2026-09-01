<?php
require "../config/conexao.php";
require "../config/ciclo_helper.php";
require "../auth/proteger.php";
require "../config/layout_helper.php";
require "../previsoes/pedido_compra_helper.php";

$busca = trim((string) ($_GET['busca'] ?? ''));
$dataIni = trim((string) ($_GET['data_ini'] ?? ''));
$dataFim = trim((string) ($_GET['data_fim'] ?? ''));
$filtrosRetornoHistoricoCompra = array_filter([
    'busca' => $busca,
    'data_ini' => $dataIni,
    'data_fim' => $dataFim,
], static fn($valor) => $valor !== '');
$redirectHistoricoCompra = '../../entradas/historico_compras.php'
    . ($filtrosRetornoHistoricoCompra ? '?' . http_build_query($filtrosRetornoHistoricoCompra) : '');
$where = ["e.tipo = 'entrada_fornecedor'", "e.numero_os IS NOT NULL", "e.numero_os <> ''"];
$params = [];
$types = '';
if ($busca !== '') { $where[] = "(e.numero_os LIKE ? OR p.nome LIKE ? OR f.nome LIKE ?)"; $like = "%{$busca}%"; array_push($params, $like, $like, $like); $types .= 'sss'; }
if ($dataIni !== '') { $where[] = "DATE(e.data_entrada) >= ?"; $params[] = $dataIni; $types .= 's'; }
if ($dataFim !== '') { $where[] = "DATE(e.data_entrada) <= ?"; $params[] = $dataFim; $types .= 's'; }

$sql = "
    SELECT e.numero_os, MIN(e.data_entrada) AS data_entrada, MAX(f.nome) AS fornecedor,
           MAX(COALESCE(NULLIF(TRIM(f.nfe_estado), ''), 'SP')) AS fornecedor_uf,
           COUNT(*) AS total_itens, SUM(e.quantidade) AS total_kg,
           GROUP_CONCAT(DISTINCT NULLIF(TRIM(e.motivo_abatimento), '') SEPARATOR '; ') AS motivos_abatimento
    FROM entradas e
    LEFT JOIN produtos p ON p.id = e.produto_id
    LEFT JOIN fornecedores f ON f.id = e.fornecedor_id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY e.numero_os
    ORDER BY data_entrada DESC
";
$stmt = $conexao->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$compras = $stmt->get_result();
$sqlItens = "
    SELECT e.*, p.nome AS produto, f.nome AS fornecedor
    FROM entradas e
    LEFT JOIN produtos p ON p.id = e.produto_id
    LEFT JOIN fornecedores f ON f.id = e.fornecedor_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY e.numero_os ASC, e.id ASC
";
$stmtItens = $conexao->prepare($sqlItens);
if ($params) $stmtItens->bind_param($types, ...$params);
$stmtItens->execute();
$itensCompra = $stmtItens->get_result();
$itensCompraPorOs = [];
while ($itemCompra = $itensCompra->fetch_assoc()) {
    $itensCompraPorOs[(string) $itemCompra['numero_os']][] = $itemCompra;
}
$docsCompraAutorizadaPorOs = [];
$nfeDocsCompraPorOs = [];
$devolucaoCompraPorOs = []; // OS que ja possui devolucao/saida de abate autorizada (so 1 por OS)
$docsCompraRes = $conexao->query("
    SELECT id, ref, numero_os, status, chave_nfe, numero_nfe, serie, tipo_emissao
    FROM nfe_documentos
    WHERE tipo_emissao IN ('compra', 'devolucao_compra', 'saida_abate_sem_entrada')
      AND numero_os IS NOT NULL
      AND numero_os <> ''
    ORDER BY id DESC
");
if ($docsCompraRes) {
    while ($docCompra = $docsCompraRes->fetch_assoc()) {
        $numeroOsDoc = (string) $docCompra['numero_os'];
        $nfeDocsCompraPorOs[$numeroOsDoc][] = $docCompra;
        if (($docCompra['tipo_emissao'] ?? '') === 'compra' && ($docCompra['status'] ?? '') === 'autorizada' && trim((string) ($docCompra['chave_nfe'] ?? '')) !== '') {
            $docsCompraAutorizadaPorOs[$numeroOsDoc] = (string) $docCompra['chave_nfe'];
        }
        if (in_array($docCompra['tipo_emissao'] ?? '', ['devolucao_compra', 'saida_abate_sem_entrada'], true) && ($docCompra['status'] ?? '') === 'autorizada') {
            $devolucaoCompraPorOs[$numeroOsDoc] = true;
        }
    }
}
$podeEditarValor = in_array($_SESSION['usuario_nivel'] ?? '', ['admin', 'ti'], true);
$listaProdutosCompra = $conexao->query("SELECT id, nome FROM produtos ORDER BY nome")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Histórico de Compras</title>
<style>
body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;color:#222}.container{padding:30px;max-width:1200px;margin:auto}
.card{background:white;padding:24px;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,.06);margin-bottom:24px}.filters{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:12px;align-items:end}
label{font-weight:700;font-size:14px}input{width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;margin-top:5px}.btn{border:0;border-radius:6px;padding:10px 14px;color:white;background:#2e7d32;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px}
textarea{width:100%;min-height:96px;padding:10px;border:1px solid #ccc;border-radius:6px;margin-top:5px;resize:vertical;font-family:inherit}
.btn-secondary{background:#6b7280}.btn-edit{background:#1565c0}.btn-print{background:#374151}.btn-doc{background:#e65100}.btn-doc:hover{background:#bf360c}.btn-danger{background:#b91c1c}.btn-warning{background:#92400e}.btn-nfe{background:#7c3aed}.btn-nfe:hover{background:#5b21b6}
.badge-nfe{display:inline-block;margin-left:8px;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:800;white-space:nowrap}.badge-nfe.ok{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7}.badge-nfe.pend{background:#fff8e1;color:#a86a00;border:1px solid #ffe082}.badge-nfe.erro{background:#ffebee;color:#b91c1c;border:1px solid #ef9a9a}.badge-nfe.cancel{background:#eceff1;color:#455a64;border:1px solid #b0bec5}
.msg-sucesso{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600}.msg-erro{background:#ffebee;color:#c62828;border:1px solid #ef9a9a;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600}
.table-container{overflow-x:auto}table{width:100%;min-width:980px;border-collapse:collapse;background:white}th{background:#1b5e20;color:white;padding:12px;text-align:center}td{padding:12px;border-bottom:1px solid #eee;text-align:center}.acoes{display:flex;gap:8px;justify-content:center;flex-wrap:wrap}.alterado{color:#c62828;font-weight:800;font-size:12px;display:block}
.os-group-header td{background:#e8f5e9;font-weight:700;border-top:2px solid #2e7d32}.historico-item-row td{background:#f9fbe7;font-size:13px}.historico-item-row.hidden{display:none}.btn-toggle-os{background:white;color:#1b5e20;border:1px solid #2e7d32;width:32px;height:32px;padding:0;font-weight:800}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1400;padding:20px;overflow-y:auto}.modal[style*="block"]{display:flex!important;align-items:center;justify-content:center}.modal-box{background:white;max-width:420px;width:min(100%,420px);margin:auto;padding:24px;border-radius:10px}
@media(max-width:800px){.filters{grid-template-columns:1fr}}
@media(max-width:600px){.modal{padding:72px 16px 18px;align-items:flex-start}.modal[style*="block"]{align-items:flex-start}.modal-box{width:100%!important;max-width:420px!important;margin:0 auto!important;padding:22px 18px!important}.modal-box input,.modal-box select,.modal-box textarea{width:100%!important;min-width:0}.modal-box form>div[style*="justify-content:flex-end"]{justify-content:center!important;flex-wrap:wrap}.modal-box form>div[style*="justify-content:flex-end"] .btn{min-height:42px}}
</style>
<?php renderAppLayoutStyles(); ?>
<script>
const HISTORICO_COMPRAS_OS_ABERTA_KEY = 'historico_compras_os_aberta';

function marcarHistoricoCompraOsAtiva(os) {
    os = String(os || '').trim();
    if (os !== '') sessionStorage.setItem(HISTORICO_COMPRAS_OS_ABERTA_KEY, os);
}

function encontrarElementoHistoricoCompra(seletor, atributo, os) {
    return Array.from(document.querySelectorAll(seletor)).find(function (elemento) {
        return elemento.getAttribute(atributo) === os;
    }) || null;
}

function definirHistoricoCompraOsAberta(os, aberta, centralizar) {
    os = String(os || '');
    const linhas = Array.from(document.querySelectorAll('.historico-item-row[data-os]')).filter(function (linha) {
        return linha.dataset.os === os;
    });
    const botao = encontrarElementoHistoricoCompra('.btn-toggle-os[data-historico-os]', 'data-historico-os', os);
    const cabecalho = encontrarElementoHistoricoCompra('.os-group-header[data-os]', 'data-os', os);
    if (!cabecalho || linhas.length === 0) return false;

    linhas.forEach(function (linha) { linha.classList.toggle('hidden', !aberta); });
    if (botao) botao.textContent = aberta ? '▲' : '▼';
    if (aberta) sessionStorage.setItem(HISTORICO_COMPRAS_OS_ABERTA_KEY, os);
    else sessionStorage.removeItem(HISTORICO_COMPRAS_OS_ABERTA_KEY);

    if (aberta && centralizar) {
        requestAnimationFrame(function () { cabecalho.scrollIntoView({block: 'center'}); });
    }
    return true;
}

function formatarMoedaCompra(valor) {
    return Number(valor || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
function valorMoedaCompra(valor) {
    valor = String(valor || '').trim().replace(/^R\$\s*/i, '');
    if (valor === '') return 0;
    if (valor.indexOf(',') !== -1) valor = valor.replace(/\./g, '').replace(',', '.');
    else if (/^\d{1,3}(\.\d{3})+$/.test(valor)) valor = valor.replace(/\./g, '');
    var numero = Number(valor);
    return Number.isFinite(numero) && numero >= 0 ? numero : 0;
}
function atualizarResumoOutrasDespesas() {
    var itens = Number(document.getElementById('valor_itens_compra').value || 0);
    var despesas = valorMoedaCompra(document.getElementById('outras_despesas_compra').value);
    document.getElementById('valor_total_compra').value = formatarMoedaCompra(itens + despesas);
}
function abrirModalValorCompra(os, valorItens, outrasDespesas) {
    marcarHistoricoCompraOsAtiva(os);
    document.getElementById('valor_compra_os').value = os;
    document.getElementById('valor_itens_compra').value = Number(valorItens || 0).toFixed(2);
    document.getElementById('valor_itens_compra_exibicao').value = formatarMoedaCompra(valorItens);
    document.getElementById('outras_despesas_compra').value = formatarMoedaCompra(outrasDespesas);
    atualizarResumoOutrasDespesas();
    document.getElementById('modalValorCompra').style.display = 'block';
}
function fecharModalValorCompra(){ document.getElementById('modalValorCompra').style.display = 'none'; }
function abrirModalItemCompra(data) {
    marcarHistoricoCompraOsAtiva(data.os);
    document.getElementById('edit_item_compra_id').value = data.id;
    document.getElementById('edit_item_compra_produto').value = data.produto_id;
    document.getElementById('edit_item_compra_quantidade').value = Number(data.quantidade || 0).toFixed(2);
    document.getElementById('modalItemCompra').style.display = 'block';
}
function fecharModalItemCompra(){ document.getElementById('modalItemCompra').style.display = 'none'; }
function abrirModalCancelarCompra(os, fornecedor, valor) {
    document.getElementById('cancelar_compra_os').value = os;
    document.getElementById('cancelar_compra_os_label').textContent = os || '-';
    document.getElementById('cancelar_compra_fornecedor').textContent = fornecedor || '-';
    document.getElementById('cancelar_compra_valor').textContent = 'R$ ' + Number(valor || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('cancelar_compra_motivo').value = '';
    document.getElementById('modalCancelarCompra').style.display = 'block';
}
function fecharModalCancelarCompra(){ document.getElementById('modalCancelarCompra').style.display = 'none'; }
function confirmarCancelamentoCompra() {
    return confirm('Cancelar esta compra, remover a entrada do estoque e registrar reversao financeira?');
}
function abrirModalNfeCompra(numeroOs, tipoEmissao, localDestino, possuiEntradaAutorizada = true) {
    marcarHistoricoCompraOsAtiva(numeroOs);
    const devolucao = tipoEmissao === 'devolucao_compra';
    const saidaApartada = tipoEmissao === 'saida_abate_sem_entrada';
    document.getElementById('nfe_compra_numero_os').value = numeroOs || '';
    document.getElementById('nfe_compra_tipo_emissao').value = tipoEmissao || 'compra';
    document.getElementById('nfe_compra_local_destino').value = String(localDestino || '1');
    document.getElementById('nfe_compra_titulo').textContent = devolucao ? 'Emitir NF-e de devolucao de compra' : (saidaApartada ? 'Emitir NF-e apartada de abate' : 'Emitir NF-e de entrada');
    document.getElementById('nfe_compra_os_label').textContent = numeroOs || '-';
    document.getElementById('nfe_compra_informacoes_adicionais').value = '';
    document.getElementById('nfe_compra_chave_referenciada').value = '';
    const avisoSemEntrada = document.getElementById('nfe_compra_aviso_sem_entrada');
    const emitirSemEntrada = saidaApartada && !possuiEntradaAutorizada;
    avisoSemEntrada.style.display = emitirSemEntrada ? 'block' : 'none';
    document.getElementById('nfe_compra_referencia_box').style.display = emitirSemEntrada ? 'block' : 'none';
    document.getElementById('modalNfeCompra').dataset.saidaApartada = emitirSemEntrada ? '1' : '0';
    carregarRascunhoNfeCompra(); document.getElementById('modalNfeCompra').style.display = 'block';
}
function fecharModalNfeCompra(){ document.getElementById('modalNfeCompra').style.display = 'none'; }
function nfeCompraNumero(v, dec){ var n = Number(v); if (isNaN(n)) n = 0; return n.toFixed(dec); }
function attrCompra(v){ return String(v == null ? '' : v).replace(/"/g, '&quot;'); }
function recalcItemCompra(card){
    var q = Number(card.querySelector('.cit-qtd').value || 0);
    var vu = Number(card.querySelector('.cit-vu').value || 0);
    if (isNaN(q)) q = 0; if (isNaN(vu)) vu = 0;
    card.querySelector('.cit-vb').value = (q * vu).toFixed(2);
    card.querySelector('.cit-hq').value = q.toFixed(4);
    card.querySelector('.cit-hvu').value = vu.toFixed(4);
    atualizarResumoNfeCompra();
}
function nfeCompraMoeda(v){ return 'R$ ' + Number(v || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
function garantirResumoNfeCompra(){
    if (document.getElementById('nfe_compra_valor_itens')) return;
    var resumo = document.createElement('div');
    resumo.className = 'nfe-cvalores';
    resumo.innerHTML = '<div><span>Valor dos itens</span><strong id="nfe_compra_valor_itens">R$ 0,00</strong></div><div><span>Outras despesas</span><strong id="nfe_compra_outras_despesas">R$ 0,00</strong></div><div><span>Total estimado da NF-e</span><strong id="nfe_compra_total_estimado">R$ 0,00</strong></div>';
    var itens = document.getElementById('nfe_compra_items_box');
    itens.parentNode.insertAdjacentElement('afterend', resumo);
}
function atualizarResumoNfeCompra(){
    garantirResumoNfeCompra();
    var modal = document.getElementById('modalNfeCompra');
    var valorItens = 0;
    document.querySelectorAll('#nfe_compra_items_box .nfe-citem').forEach(function(card){
        if (card.querySelector('.cit-excluir').value !== '1') valorItens += Number(card.querySelector('.cit-vb').value || 0);
    });
    valorItens = Math.round(valorItens * 100) / 100;
    var baseOrigem = Number(modal.dataset.valorItensOrigem || 0);
    var despesaOrigem = Number(modal.dataset.outrasDespesasOrigem || 0);
    var despesas = baseOrigem > 0 ? Math.min(despesaOrigem, Math.round(despesaOrigem * valorItens / baseOrigem * 100) / 100) : 0;
    document.getElementById('nfe_compra_valor_itens').textContent = nfeCompraMoeda(valorItens);
    document.getElementById('nfe_compra_outras_despesas').textContent = nfeCompraMoeda(despesas);
    document.getElementById('nfe_compra_total_estimado').textContent = nfeCompraMoeda(valorItens + despesas);
}
function criarCardItemCompra(item, idx){
    var div = document.createElement('div');
    div.className = 'nfe-citem';
    var nome = item.descricao || ('Item ' + (idx + 1));
    var excluido = String(item.excluir || '0') === '1';
    div.innerHTML =
        '<div style="display:flex;justify-content:flex-start;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:6px;">' +
        '<strong>' + String(nome).replace(/</g, '&lt;') + '</strong>' +
        '<label class="cit-del">excluir <input type="checkbox" ' + (excluido ? 'checked ' : '') + 'onchange="var c=this.closest(\'.nfe-citem\');c.querySelector(\'.cit-excluir\').value=this.checked?\'1\':\'0\';c.style.opacity=this.checked?0.45:1;atualizarResumoNfeCompra();"></label>' +
        '</div>' +
        '<input type="hidden" class="cit-excluir" name="items[' + idx + '][excluir]" value="' + (excluido ? '1' : '0') + '">' +
        '<div class="nfe-cgrid">' +
        '<div><label>Codigo interno</label><input name="items[' + idx + '][codigo_produto]" value="' + attrCompra(item.codigo_produto) + '"></div>' +
        '<div><label>Descricao</label><input name="items[' + idx + '][descricao]" value="' + attrCompra(item.descricao) + '"></div>' +
        '<div><label>NCM</label><input name="items[' + idx + '][codigo_ncm]" value="' + attrCompra(item.codigo_ncm) + '"></div>' +
        '<div><label>CFOP</label><input name="items[' + idx + '][cfop]" value="' + attrCompra(item.cfop) + '"></div>' +
        '<div><label>Unidade</label><input name="items[' + idx + '][unidade_comercial]" value="' + attrCompra(item.unidade_comercial || 'KG') + '"></div>' +
        '<div><label>Quantidade</label><input type="number" step="0.0001" class="cit-qtd" oninput="recalcItemCompra(this.closest(\'.nfe-citem\'))" name="items[' + idx + '][quantidade_comercial]" value="' + nfeCompraNumero(item.quantidade_comercial, 4) + '"></div>' +
        '<div><label>Valor unitario</label><input type="number" step="0.0001" class="cit-vu" oninput="recalcItemCompra(this.closest(\'.nfe-citem\'))" name="items[' + idx + '][valor_unitario_comercial]" value="' + nfeCompraNumero(item.valor_unitario_comercial, 4) + '"></div>' +
        '<div><label>Valor bruto</label><input type="number" step="0.01" class="cit-vb" oninput="atualizarResumoNfeCompra()" name="items[' + idx + '][valor_bruto]" value="' + nfeCompraNumero(item.valor_bruto, 2) + '"></div>' +
        '<div><label>CST ICMS</label><input name="items[' + idx + '][icms_situacao_tributaria]" value="' + attrCompra(item.icms_situacao_tributaria || '40') + '"></div>' +
        '<div><label>Origem ICMS</label><input name="items[' + idx + '][icms_origem]" value="' + attrCompra(item.icms_origem || '0') + '"></div>' +
        '<div><label>CST PIS</label><input name="items[' + idx + '][pis_situacao_tributaria]" value="' + attrCompra(item.pis_situacao_tributaria || '70') + '"></div>' +
        '<div><label>CST COFINS</label><input name="items[' + idx + '][cofins_situacao_tributaria]" value="' + attrCompra(item.cofins_situacao_tributaria || '70') + '"></div>' +
        '<div><label>cBenef</label><input name="items[' + idx + '][codigo_beneficio_fiscal]" value="' + attrCompra(item.codigo_beneficio_fiscal || 'SP010360') + '"></div>' +
        '</div>' +
        '<input type="hidden" class="cit-hq" name="items[' + idx + '][quantidade_tributavel]" value="' + nfeCompraNumero(item.quantidade_comercial, 4) + '">' +
        '<input type="hidden" class="cit-hvu" name="items[' + idx + '][valor_unitario_tributavel]" value="' + nfeCompraNumero(item.valor_unitario_comercial, 4) + '">' +
        '<input type="hidden" name="items[' + idx + '][unidade_tributavel]" value="' + attrCompra(item.unidade_comercial || 'KG') + '">';
    if (excluido) {
        div.style.display = 'none';
    }
    return div;
}
function aplicarDadosRascunhoNfeCompra(d, localFallback) {
    var box = document.getElementById('nfe_compra_items_box');
    var dd = d.destinatario || {};
    ['nome','cpf','cnpj','ie','indicador_ie','logradouro','numero','complemento','bairro','municipio','uf','cep','telefone','email'].forEach(function (c) {
        var el = document.getElementById('nfe_compra_dest_' + c);
        if (el) el.value = (dd[c] != null ? dd[c] : '');
    });
    if (d.geral && document.getElementById('nfe_compra_local_destino')) {
        document.getElementById('nfe_compra_local_destino').value = String(d.geral.local_destino || localFallback || '1');
    }
    box.innerHTML = '';
    (d.items || []).forEach(function (item, idx) { box.appendChild(criarCardItemCompra(item, idx)); });
    var valores = d.valores || {};
    document.getElementById('modalNfeCompra').dataset.valorItensOrigem = String(valores.valor_itens_origem || 0);
    document.getElementById('modalNfeCompra').dataset.outrasDespesasOrigem = String(valores.outras_despesas_origem || 0);
    atualizarResumoNfeCompra();
    document.getElementById('nfe_compra_informacoes_adicionais').value = d.informacoes_adicionais_contribuinte || '';
    document.getElementById('nfe_compra_chave_referenciada').value = d.chave_nfe_referenciada || '';
}
function carregarRascunhoNfeCompra(ignorarRascunho){
    var os = document.getElementById('nfe_compra_numero_os').value;
    var tipo = document.getElementById('nfe_compra_tipo_emissao').value;
    var local = document.getElementById('nfe_compra_local_destino').value;
    var box = document.getElementById('nfe_compra_items_box');
    var msg = document.getElementById('nfe_compra_rascunho_msg');
    box.innerHTML = '';
    msg.style.display = 'block'; msg.style.color = '#555'; msg.textContent = 'Gerando rascunho...';
    var fd = new FormData();
    fd.append('numero_os', os); fd.append('tipo_emissao', tipo); fd.append('local_destino', local); fd.append('ambiente', 'producao');
    fd.append('_acao', 'carregar');
    if (ignorarRascunho) fd.append('_ignorar_rascunho', '1');
    fetch('actions/rascunho_nfe_compra.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d || !d.ok) {
                msg.style.color = '#b91c1c';
                msg.textContent = 'Nao foi possivel gerar o rascunho: ' + ((d && d.erro) ? d.erro : 'erro desconhecido');
                return;
            }
            aplicarDadosRascunhoNfeCompra(d, local);
            if (!d.items || d.items.length === 0) {
                msg.style.color = '#b91c1c'; msg.textContent = 'Rascunho gerado sem itens.';
            } else if (d.rascunho_carregado) {
                msg.style.color = '#166534'; msg.textContent = 'Rascunho salvo carregado.';
            } else {
                msg.style.display = 'none';
            }
        })
        .catch(function (e) { msg.style.color = '#b91c1c'; msg.textContent = 'Falha ao gerar rascunho: ' + e; });
}
async function salvarRascunhoNfeCompra(form) {
    var msg = document.getElementById('nfe_compra_rascunho_msg');
    var btn = document.getElementById('btn_salvar_rascunho_nfe_compra');
    var textoOriginal = btn ? btn.innerHTML : '';
    var fd = new FormData(form);
    fd.append('_acao', 'salvar');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Salvando...';
    }
    try {
        var resp = await fetch('actions/rascunho_nfe_compra.php', {
            method: 'POST',
            body: fd,
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
        });
        var d = await resp.json();
        if (!d || !d.ok) {
            msg.style.display = 'block';
            msg.style.color = '#b91c1c';
            msg.textContent = (d && d.erro) ? d.erro : 'Nao foi possivel salvar o rascunho.';
            return;
        }
        aplicarDadosRascunhoNfeCompra(d, document.getElementById('nfe_compra_local_destino').value);
        msg.style.display = 'block';
        msg.style.color = '#166534';
        msg.textContent = d.mensagem || 'Rascunho salvo.';
    } catch (e) {
        msg.style.display = 'block';
        msg.style.color = '#b91c1c';
        msg.textContent = 'Falha ao comunicar com o servidor para salvar o rascunho.';
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = textoOriginal;
        }
    }
}
function confirmarEmissaoNfeCompra() {
    if (document.getElementById('modalNfeCompra').dataset.saidaApartada === '1') {
        var chave = String(document.getElementById('nfe_compra_chave_referenciada').value || '').replace(/\D/g, '');
        if (chave.length !== 44) {
            alert('Informe a chave de acesso de 44 digitos da NF-e do fornecedor.');
            document.getElementById('nfe_compra_chave_referenciada').focus();
            return false;
        }
        return confirm('Emitir a NF-e de devolucao referenciando a chave informada?');
    }
    return confirm('Emitir NF-e com os dados revisados?');
}
function abrirModalCancelarCompra(os, fornecedor, valor) {
    document.getElementById('cancelar_compra_os').value = os;
    document.getElementById('cancelar_compra_os_label').textContent = os || '-';
    document.getElementById('cancelar_compra_fornecedor').textContent = fornecedor || '-';
    document.getElementById('cancelar_compra_valor').textContent = 'R$ ' + Number(valor || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('cancelar_compra_motivo').value = '';
    document.getElementById('modalCancelarCompra').style.display = 'block';
}
function fecharModalCancelarCompra(){ document.getElementById('modalCancelarCompra').style.display = 'none'; }
function confirmarCancelamentoCompra() {
    return confirm('Cancelar esta compra, remover a entrada do estoque e registrar reversao financeira?');
}
function abrirModalCancelarNfe(data) {
    document.getElementById('cancelar_nfe_documento_id').value = data.id || '';
    document.getElementById('cancelar_nfe_ref_label').textContent = data.ref || '-';
    document.getElementById('cancelar_nfe_numero_label').textContent = data.numero || '-';
    document.getElementById('cancelar_nfe_justificativa').value = '';
    document.getElementById('modalCancelarNfe').style.display = 'block';
}
function fecharModalCancelarNfe() { document.getElementById('modalCancelarNfe').style.display = 'none'; }
function confirmarCancelamentoNfe() {
    const texto = document.getElementById('cancelar_nfe_justificativa').value.trim();
    if (texto.length < 15 || texto.length > 255) {
        alert('A justificativa deve ter entre 15 e 255 caracteres.');
        return false;
    }
    return confirm('Cancelar esta NF-e na SEFAZ pela Focus?');
}
function abrirModalCartaCorrecaoNfe(data) {
    document.getElementById('cce_nfe_documento_id').value = data.id || '';
    document.getElementById('cce_nfe_ref_label').textContent = data.ref || '-';
    document.getElementById('cce_nfe_numero_label').textContent = data.numero || '-';
    document.getElementById('cce_nfe_correcao').value = '';
    document.getElementById('modalCartaCorrecaoNfe').style.display = 'block';
}
function fecharModalCartaCorrecaoNfe() { document.getElementById('modalCartaCorrecaoNfe').style.display = 'none'; }
function confirmarCartaCorrecaoNfe() {
    const texto = document.getElementById('cce_nfe_correcao').value.trim();
    if (texto.length < 15 || texto.length > 1000) {
        alert('A carta de correcao deve ter entre 15 e 1000 caracteres.');
        return false;
    }
    return confirm('Enviar carta de correcao desta NF-e pela Focus?');
}
function toggleHistoricoOs(osKey, tipo) {
    if (tipo !== 'historico') return;
    const primeiraLinha = Array.from(document.querySelectorAll('.historico-item-row[data-os]')).find(function (linha) {
        return linha.dataset.os === String(osKey);
    });
    definirHistoricoCompraOsAberta(osKey, !!primeiraLinha?.classList.contains('hidden'), false);
}

document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('click', function (event) {
        const cabecalho = event.target.closest('.os-group-header[data-os]');
        const item = event.target.closest('.historico-item-row[data-os]');
        const linha = cabecalho || item;
        if (linha && !event.target.closest('.btn-toggle-os')) marcarHistoricoCompraOsAtiva(linha.dataset.os);
    });
    const osSalva = sessionStorage.getItem(HISTORICO_COMPRAS_OS_ABERTA_KEY);
    if (osSalva && !definirHistoricoCompraOsAberta(osSalva, true, true)) {
        sessionStorage.removeItem(HISTORICO_COMPRAS_OS_ABERTA_KEY);
    }
    if (window.NFE_CONSULTA_REF) consultarSituacaoNfeCompra(false);
});

let nfeCompraConsultaTentativas = 0;
async function consultarSituacaoNfeCompra(manual) {
    const ref = window.NFE_CONSULTA_REF || '';
    if (!ref) return;
    const botao = document.getElementById('btn_atualizar_nfe_compra');
    const texto = document.getElementById('nfe_consulta_texto_compra');
    if (manual) nfeCompraConsultaTentativas = 0;
    if (botao) botao.disabled = true;
    if (texto) texto.textContent = 'Consultando situacao da NF-e...';
    try {
        const resposta = await fetch('../api/focus_consultar_nfe.php?ref=' + encodeURIComponent(ref) + '&ambiente=producao', {headers:{'Accept':'application/json'}, cache:'no-store'});
        const dados = await resposta.json();
        const focus = dados && dados.response && typeof dados.response === 'object' ? dados.response : {};
        const status = String(focus.status || '').toLowerCase();
        const mensagem = String(focus.mensagem_sefaz || focus.mensagem || focus.erro || dados.erro || '');
        if (status.includes('autoriz')) { location.href = 'historico_compras.php?msg=nfe_enviada&ref=' + encodeURIComponent(ref); return; }
        if (status.includes('cancel')) { location.href = 'historico_compras.php?msg=nfe_cancelada&ref=' + encodeURIComponent(ref); return; }
        if (status.includes('erro') || status.includes('reje') || status.includes('deneg')) {
            location.href = 'historico_compras.php?msg=erro&detalhe=' + encodeURIComponent(mensagem || 'NF-e rejeitada pela SEFAZ.') + '&ref=' + encodeURIComponent(ref); return;
        }
        nfeCompraConsultaTentativas++;
        if (nfeCompraConsultaTentativas < 20) {
            if (texto) texto.textContent = 'NF-e em processamento. Nova consulta em 3 segundos...';
            setTimeout(function(){ consultarSituacaoNfeCompra(false); }, 3000);
        } else {
            if (texto) texto.textContent = 'A NF-e continua em processamento. Atualize a situacao manualmente.';
            if (botao) { botao.style.display = 'inline-flex'; botao.disabled = false; }
        }
    } catch (erro) {
        nfeCompraConsultaTentativas++;
        if (nfeCompraConsultaTentativas < 20) setTimeout(function(){ consultarSituacaoNfeCompra(false); }, 3000);
        else if (botao) { botao.style.display = 'inline-flex'; botao.disabled = false; }
        if (texto) texto.textContent = 'Nao foi possivel atualizar agora. A emissao nao sera repetida.';
    } finally {
        if (botao && nfeCompraConsultaTentativas < 20) botao.disabled = false;
    }
}
</script>
</head>
<body>
<?php renderAppHeader('..'); ?>
<div class="container page-container app-shell">
<?php if (($_GET['msg'] ?? '') === 'valor_atualizado'): ?><div class="msg-sucesso">Valor final atualizado com sucesso.</div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'item_atualizado'): ?><div class="msg-sucesso">Produto e quantidade recebida atualizados com sucesso.</div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'compra_excluida'): ?><div class="msg-sucesso">Compra excluida do sistema com sucesso.</div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'compra_cancelada'): ?><div class="msg-sucesso">Compra cancelada e reversao financeira registrada com sucesso.</div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'nfe_enviada'): ?><div class="msg-sucesso">NF-e autorizada/recebida pela Focus com sucesso. Ref: <?= htmlspecialchars($_GET['ref'] ?? '-') ?></div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'nfe_processando'): ?><div class="msg-sucesso"><span id="nfe_consulta_texto_compra">NF-e aceita pela Focus e em processamento. Consultando situacao...</span> Ref: <?= htmlspecialchars($_GET['ref'] ?? '-') ?> <button type="button" class="btn btn-secondary" id="btn_atualizar_nfe_compra" style="display:none;margin-left:8px" onclick="consultarSituacaoNfeCompra(true)"><i class="bi bi-arrow-clockwise"></i> Atualizar situacao</button></div><script>window.NFE_CONSULTA_REF=<?= json_encode((string)($_GET['ref'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;</script><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'nfe_cancelada'): ?><div class="msg-sucesso">NF-e cancelada com sucesso na Focus/SEFAZ. Ref: <?= htmlspecialchars($_GET['ref'] ?? '-') ?></div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'nfe_cce_enviada'): ?><div class="msg-sucesso">Carta de correcao enviada com sucesso pela Focus/SEFAZ. Ref: <?= htmlspecialchars($_GET['ref'] ?? '-') ?></div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'erro'): ?><div class="msg-erro"><?= htmlspecialchars($_GET['detalhe'] ?? 'Erro ao processar.') ?></div><?php endif; ?>
<div class="card">
<h2>Histórico de Compras</h2>
<form method="GET" class="filters">
<div><label>Busca</label><input name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="OS, produto ou fornecedor"></div>
<div><label>Data início</label><input type="date" name="data_ini" value="<?= htmlspecialchars($dataIni) ?>"></div>
<div><label>Data fim</label><input type="date" name="data_fim" value="<?= htmlspecialchars($dataFim) ?>"></div>
<button class="btn" type="submit"><i class="bi bi-search"></i></button>
</form>
</div>
<div class="card"><div class="table-container"><table>
<thead><tr><th style="width:44px;"></th><th>Data</th><th>OS</th><th>Fornecedor</th><th>Itens</th><th>Total kg</th><th>Motivo do Abatimento</th><th>Valor final</th><th>Documentos</th><th>Ação</th></tr></thead>
<tbody>
<?php if ($compras->num_rows === 0): ?><tr><td colspan="10">Nenhuma compra confirmada encontrada.</td></tr><?php endif; ?>
<?php while ($c = $compras->fetch_assoc()): ?>
<?php
$numeroOs = (string) $c['numero_os'];
$osKey = htmlspecialchars($numeroOs, ENT_QUOTES, 'UTF-8');
$osEscape = htmlspecialchars(addslashes($numeroOs), ENT_QUOTES, 'UTF-8');
$itensOs = $itensCompraPorOs[$numeroOs] ?? [];
$snapshot = pedidoCompraObterSnapshot($conexao, $numeroOs, '');
$valorItens = (float) ($snapshot['valor_itens'] ?? $snapshot['valor_total_original'] ?? $snapshot['valor_total'] ?? 0);
$outrasDespesas = (float) ($snapshot['outras_despesas'] ?? 0);
$valorFinal = (float) ($snapshot['valor_total'] ?? ($valorItens + $outrasDespesas));
$alterado = !empty($snapshot['valor_total_editado']);
$motivosAbatimento = trim((string) ($c['motivos_abatimento'] ?? ''));
$temAbatimentoNfe = $motivosAbatimento !== '';
$compraNfeAutorizada = !empty($docsCompraAutorizadaPorOs[$numeroOs]);
$devolucaoJaEmitida = !empty($devolucaoCompraPorOs[$numeroOs]); // so 1 devolucao por OS
$docsNfeCompraOs = $nfeDocsCompraPorOs[$numeroOs] ?? [];
$statusNfeCompraAtual = strtolower((string) ($docsNfeCompraOs[0]['status'] ?? ''));
$classeNfeCompra = $statusNfeCompraAtual === 'autorizada' ? 'ok' : (in_array($statusNfeCompraAtual, ['processando','enviada'], true) ? 'pend' : ($statusNfeCompraAtual === 'rejeitada' ? 'erro' : ($statusNfeCompraAtual === 'cancelada' ? 'cancel' : 'pend')));
$rotuloNfeCompra = $statusNfeCompraAtual === 'autorizada' ? 'NF-e autorizada' : (in_array($statusNfeCompraAtual, ['processando','enviada'], true) ? 'NF-e processando' : ($statusNfeCompraAtual === 'rejeitada' ? 'NF-e rejeitada' : ($statusNfeCompraAtual === 'cancelada' ? 'NF-e cancelada' : 'Sem NF-e')));
$localDestinoCompra = strtoupper(trim((string) ($c['fornecedor_uf'] ?? 'SP'))) === 'SP' ? '1' : '2';
foreach (($snapshot['itens'] ?? []) as $snapshotItemAbate) {
    if ((float) ($snapshotItemAbate['descarte'] ?? 0) > 0) {
        $temAbatimentoNfe = true;
        if ($motivosAbatimento === '' && trim((string) ($snapshotItemAbate['motivo_abatimento'] ?? '')) !== '') {
            $motivosAbatimento = trim((string) $snapshotItemAbate['motivo_abatimento']);
        }
    }
}
?>
<tr class="os-group-header" data-os="<?= $osKey ?>">
<td><button type="button" class="btn-toggle-os" data-historico-os="<?= $osKey ?>" onclick="toggleHistoricoOs('<?= $osEscape ?>', 'historico')">▼</button></td>
<td><?= !empty($c['data_entrada']) ? date('d/m/Y H:i', strtotime($c['data_entrada'])) : '-' ?></td>
<td><?= htmlspecialchars($numeroOs) ?><span class="badge-nfe <?= $classeNfeCompra ?>"><?= htmlspecialchars($rotuloNfeCompra) ?></span></td>
<td><?= htmlspecialchars($c['fornecedor'] ?? '-') ?></td>
<td><?= (int) $c['total_itens'] ?></td>
<td><?= number_format((float) $c['total_kg'], 2, ',', '.') ?> kg</td>
<td><?= $motivosAbatimento !== '' ? htmlspecialchars($motivosAbatimento) : '-' ?></td>
<td><div>Itens: R$ <?= number_format($valorItens, 2, ',', '.') ?></div><div>Outras despesas: R$ <?= number_format($outrasDespesas, 2, ',', '.') ?></div><strong>Total: R$ <?= number_format($valorFinal, 2, ',', '.') ?></strong><?php if ($alterado): ?><span class="alterado">legado</span><?php endif; ?></td>
<td><div class="acoes"><a class="btn btn-doc" download title="Baixar Pedido" href="../previsoes/gerar_pedido_compra.php?numero_os=<?= rawurlencode($numeroOs) ?>"><i class="bi bi-file-earmark-text"></i></a><button class="btn btn-nfe" type="button" title="Emitir NF-e de entrada" onclick="abrirModalNfeCompra(<?= htmlspecialchars(json_encode($numeroOs), ENT_QUOTES) ?>, 'compra', '<?= $localDestinoCompra ?>')"><i class="bi bi-receipt"></i></button><?php if ($temAbatimentoNfe && $devolucaoJaEmitida): ?><button class="btn btn-secondary" type="button" disabled title="Ja existe uma devolucao emitida para esta OS"><i class="bi bi-arrow-return-left"></i></button><?php elseif ($temAbatimentoNfe && $compraNfeAutorizada): ?><button class="btn btn-warning" type="button" title="Emitir NF-e de devolucao vinculada" onclick="abrirModalNfeCompra(<?= htmlspecialchars(json_encode($numeroOs), ENT_QUOTES) ?>, 'devolucao_compra', '<?= $localDestinoCompra ?>', true)"><i class="bi bi-arrow-return-left"></i></button><?php elseif ($temAbatimentoNfe): ?><button class="btn btn-warning" type="button" title="Emitir NF-e apartada de saida do abate" onclick="abrirModalNfeCompra(<?= htmlspecialchars(json_encode($numeroOs), ENT_QUOTES) ?>, 'saida_abate_sem_entrada', '<?= $localDestinoCompra ?>', false)"><i class="bi bi-arrow-return-left"></i></button><?php endif; ?><?php foreach ($docsNfeCompraOs as $docNfeCompra): ?><?php if (($docNfeCompra['status'] ?? '') === 'autorizada'): ?><?php $rotuloTipoNfe = ($docNfeCompra['tipo_emissao'] ?? '') === 'devolucao_compra' ? 'Devolucao' : (($docNfeCompra['tipo_emissao'] ?? '') === 'saida_abate_sem_entrada' ? 'Saida apartada' : 'Entrada'); $cancelarNfeData = ['id' => (int) $docNfeCompra['id'], 'ref' => (string) $docNfeCompra['ref'], 'numero' => $rotuloTipoNfe . ' ' . (trim((string) ($docNfeCompra['numero_nfe'] ?? '')) !== '' ? ('NF-e ' . $docNfeCompra['numero_nfe'] . '/' . ($docNfeCompra['serie'] ?: '1')) : (string) $docNfeCompra['ref'])]; $cceNfeData = $cancelarNfeData; ?><button class="btn btn-nfe" type="button" title="Carta de correcao <?= htmlspecialchars($rotuloTipoNfe) ?>" onclick="abrirModalCartaCorrecaoNfe(<?= htmlspecialchars(json_encode($cceNfeData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>)"><i class="bi bi-pencil-square"></i></button><button class="btn btn-danger" type="button" title="Cancelar <?= htmlspecialchars($rotuloTipoNfe) ?>" onclick="abrirModalCancelarNfe(<?= htmlspecialchars(json_encode($cancelarNfeData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>)"><i class="bi bi-x-octagon"></i></button><?php elseif (in_array(($docNfeCompra['status'] ?? ''), ['enviada','processando'], true)): ?><a class="btn btn-secondary" title="Atualizar situacao da NF-e" href="historico_compras.php?msg=nfe_processando&ref=<?= rawurlencode((string)$docNfeCompra['ref']) ?>"><i class="bi bi-arrow-clockwise"></i></a><?php elseif (($docNfeCompra['status'] ?? '') === 'cancelada'): ?><button class="btn btn-secondary" type="button" title="NF-e ja cancelada"><i class="bi bi-x-octagon"></i></button><?php endif; ?><?php endforeach; ?></div></td>
<td>
<?php if ($podeEditarValor): ?>
<div class="acoes">
<button class="btn btn-edit" type="button" title="Editar outras despesas" onclick="abrirModalValorCompra('<?= htmlspecialchars($numeroOs, ENT_QUOTES) ?>', <?= htmlspecialchars((string) $valorItens) ?>, <?= htmlspecialchars((string) $outrasDespesas) ?>)"><i class="bi bi-pencil"></i></button>
<button class="btn btn-warning" type="button" title="Cancelar compra" onclick="abrirModalCancelarCompra('<?= htmlspecialchars($numeroOs, ENT_QUOTES) ?>', '<?= htmlspecialchars((string) ($c['fornecedor'] ?? '-'), ENT_QUOTES) ?>', <?= htmlspecialchars((string) $valorFinal) ?>)"><i class="bi bi-arrow-counterclockwise"></i></button>
<form method="POST" action="actions/excluir_compra_historico.php" style="margin:0;" onsubmit="return confirm('Excluir esta compra do sistema sem reversao financeira? Esta acao remove entradas, movimentacoes, previsao e documento da OS.');">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
<input type="hidden" name="numero_os" value="<?= htmlspecialchars($numeroOs, ENT_QUOTES) ?>">
<button class="btn btn-danger" type="submit" title="Excluir do sistema"><i class="bi bi-trash"></i></button>
</form>
</div>
<?php endif; ?>
</td>
</tr>
<?php foreach ($itensOs as $item): ?>
<tr class="historico-item-row hidden" data-os="<?= $osKey ?>">
<td></td>
<td><?= !empty($item['data_entrada']) ? date('d/m/Y H:i', strtotime($item['data_entrada'])) : '-' ?></td>
<td></td>
<td><?= htmlspecialchars($item['produto'] ?? '-') ?></td>
<td>1 item</td>
<td><?= number_format((float) $item['quantidade'], 2, ',', '.') ?> kg</td>
<td><?= trim((string) ($item['motivo_abatimento'] ?? '')) !== '' ? htmlspecialchars((string) $item['motivo_abatimento']) : '-' ?></td>
<td colspan="2">Produto vinculado a OS <?= htmlspecialchars($numeroOs) ?></td>
<td><?php if ($podeEditarValor): ?><?php $itemCompraJs = ['id'=>(int)$item['id'],'os'=>$numeroOs,'produto_id'=>(int)$item['produto_id'],'quantidade'=>(float)$item['quantidade']]; ?><button class="btn btn-edit" type="button" title="Editar produto e quantidade" onclick="abrirModalItemCompra(<?= htmlspecialchars(json_encode($itemCompraJs), ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button><?php endif; ?></td>
</tr>
<?php endforeach; ?>
<?php endwhile; ?>
</tbody>
</table></div></div>
</div>
<div id="modalValorCompra" class="modal"><div class="modal-box"><h3>Outras despesas da nota</h3><form method="POST" action="../previsoes/actions/editar_valor_final_compra.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>"><input type="hidden" name="redirect_to" value="<?= htmlspecialchars($redirectHistoricoCompra, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="numero_os" id="valor_compra_os"><input type="hidden" id="valor_itens_compra"><label>Total dos itens</label><input type="text" id="valor_itens_compra_exibicao" readonly><label style="display:block;margin-top:12px;">Outras despesas</label><input type="text" name="outras_despesas" id="outras_despesas_compra" inputmode="decimal" autocomplete="off" placeholder="0,00" oninput="atualizarResumoOutrasDespesas()"><small>Informe em reais. Ex.: 1234,56 ou 1.234,56.</small><label style="display:block;margin-top:12px;">Total final</label><input type="text" id="valor_total_compra" readonly><div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;"><button type="button" class="btn btn-secondary" onclick="fecharModalValorCompra()"><i class="bi bi-x-lg"></i></button><button class="btn" type="submit"><i class="bi bi-check-lg"></i> Salvar</button></div></form></div></div>
<div id="modalItemCompra" class="modal"><div class="modal-box"><h3>Editar item da compra</h3><form method="POST" action="actions/editar_item_compra_historico.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>"><input type="hidden" name="redirect_to" value="<?= htmlspecialchars(str_replace('../../entradas/', '../', $redirectHistoricoCompra), ENT_QUOTES) ?>"><input type="hidden" name="id" id="edit_item_compra_id"><label>Produto</label><select name="produto_id" id="edit_item_compra_produto" required style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;margin-top:5px;"><?php foreach ($listaProdutosCompra as $produtoCompra): ?><option value="<?= (int)$produtoCompra['id'] ?>"><?= htmlspecialchars($produtoCompra['nome']) ?></option><?php endforeach; ?></select><label style="display:block;margin-top:12px;">Quantidade recebida (kg)</label><input type="number" step="0.01" min="0.01" name="quantidade" id="edit_item_compra_quantidade" required><div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;"><button type="button" class="btn btn-secondary" onclick="fecharModalItemCompra()"><i class="bi bi-x-lg"></i></button><button class="btn" type="submit"><i class="bi bi-check-lg"></i> Salvar</button></div></form></div></div>
<style>
#modalNfeCompra .modal-box{max-width:920px;width:100%;}
#modalNfeCompra textarea{width:100%;padding:8px;border:1px solid #ccc;border-radius:6px;margin-top:5px;}
.nfe-csec{margin-top:14px;}
.nfe-csec h4{margin:0 0 6px 0;font-size:14px;color:#1b5e20;}
.nfe-cgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;min-width:0;}
.nfe-cgrid label{font-size:12px;color:#555;display:block;margin-bottom:2px;}
.nfe-cgrid input{width:100%;padding:6px;border:1px solid #ccc;border-radius:5px;font-size:13px;box-sizing:border-box;}
.nfe-citem{border:1px solid #e0e0e0;border-radius:8px;padding:10px;margin-bottom:10px;background:#fafafa;max-width:100%;box-sizing:border-box;}.cit-del{display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#b91c1c;border:1px solid #fca5a5;background:#fff5f5;border-radius:6px;padding:3px 8px;cursor:pointer;white-space:nowrap;}.cit-del input{margin:0;cursor:pointer;}
#nfe_compra_items_box{max-height:42vh;overflow-y:auto;overflow-x:hidden;padding-right:10px;}
.nfe-cvalores{display:grid;grid-template-columns:repeat(3,minmax(150px,1fr));gap:8px;margin:12px 0;padding:10px;border:1px solid #bbf7d0;border-radius:8px;background:#f0fdf4;}.nfe-cvalores span{display:block;font-size:12px;color:#166534;}.nfe-cvalores strong{display:block;font-size:16px;color:#14532d;margin-top:2px;}
</style>
<div id="modalNfeCompra" class="modal"><div class="modal-box"><h3 id="nfe_compra_titulo">Emitir NF-e de entrada</h3><form method="POST" action="actions/emitir_nfe_focus.php" onsubmit="return confirmarEmissaoNfeCompra();"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>"><input type="hidden" name="ambiente" value="producao"><input type="hidden" name="tipo_emissao" id="nfe_compra_tipo_emissao" value="compra"><input type="hidden" name="numero_os" id="nfe_compra_numero_os"><p><strong>OS:</strong> <span id="nfe_compra_os_label">-</span></p><p id="nfe_compra_aviso_sem_entrada" style="display:none;padding:10px;border-radius:6px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;font-size:13px;"><strong>Atencao:</strong> informe a chave da NF-e original do fornecedor para referenciar esta devolucao.</p><label>Local de destino</label><select name="local_destino" id="nfe_compra_local_destino" onchange="carregarRascunhoNfeCompra(true)" required style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;margin-top:5px;"><option value="1">Dentro do estado</option><option value="2">Fora do estado</option><option value="3">Exterior (requer dados adicionais)</option></select><p id="nfe_compra_rascunho_msg" style="display:none;margin-top:8px;font-size:13px;"></p><div class="nfe-csec"><h4>Destinatario (fornecedor)</h4><div class="nfe-cgrid"><div><label>Nome/Razao</label><input name="destinatario[nome]" id="nfe_compra_dest_nome"></div><div><label>CPF</label><input name="destinatario[cpf]" id="nfe_compra_dest_cpf"></div><div><label>CNPJ</label><input name="destinatario[cnpj]" id="nfe_compra_dest_cnpj"></div><div><label>IE</label><input name="destinatario[ie]" id="nfe_compra_dest_ie"></div><div><label>Indicador IE</label><input name="destinatario[indicador_ie]" id="nfe_compra_dest_indicador_ie"></div><div><label>Telefone</label><input name="destinatario[telefone]" id="nfe_compra_dest_telefone"></div><div><label>Email</label><input name="destinatario[email]" id="nfe_compra_dest_email"></div><div><label>CEP</label><input name="destinatario[cep]" id="nfe_compra_dest_cep"></div><div><label>UF</label><input name="destinatario[uf]" id="nfe_compra_dest_uf" maxlength="2"></div><div><label>Municipio</label><input name="destinatario[municipio]" id="nfe_compra_dest_municipio"></div><div><label>Logradouro</label><input name="destinatario[logradouro]" id="nfe_compra_dest_logradouro"></div><div><label>Numero</label><input name="destinatario[numero]" id="nfe_compra_dest_numero"></div><div><label>Bairro</label><input name="destinatario[bairro]" id="nfe_compra_dest_bairro"></div><div><label>Complemento</label><input name="destinatario[complemento]" id="nfe_compra_dest_complemento"></div></div></div><div class="nfe-csec"><h4>Itens da nota</h4><div id="nfe_compra_items_box"></div></div><div id="nfe_compra_referencia_box" style="display:none;margin-top:12px;"><label for="nfe_compra_chave_referenciada">Chave de acesso da NF-e do fornecedor</label><input type="text" inputmode="numeric" autocomplete="off" name="chave_nfe_referenciada" id="nfe_compra_chave_referenciada" maxlength="54" placeholder="44 digitos" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;margin-top:5px;"><small style="display:block;margin-top:5px;color:#666;">A chave sera enviada como documento fiscal referenciado da devolucao.</small></div><label style="margin-top:12px;display:block;">Observacao para informacoes adicionais do DANFE</label><textarea name="informacoes_adicionais_contribuinte" id="nfe_compra_informacoes_adicionais" maxlength="2000" placeholder="Texto exibido no campo Informacoes Adicionais da nota"></textarea><div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;"><button type="button" class="btn btn-secondary" onclick="fecharModalNfeCompra()"><i class="bi bi-x-lg"></i></button><button type="button" class="btn btn-secondary" id="btn_salvar_rascunho_nfe_compra" onclick="salvarRascunhoNfeCompra(this.form)"><i class="bi bi-save"></i> Salvar rascunho</button><button class="btn btn-nfe" type="submit"><i class="bi bi-receipt"></i> Emitir NF-e</button></div></form></div></div>
<div id="modalCancelarNfe" class="modal"><div class="modal-box"><h3>Cancelar NF-e</h3><form method="POST" action="actions/cancelar_nfe_focus.php" onsubmit="return confirmarCancelamentoNfe()"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>"><input type="hidden" name="ambiente" value="producao"><input type="hidden" name="documento_id" id="cancelar_nfe_documento_id"><p><strong>Nota:</strong> <span id="cancelar_nfe_numero_label">-</span></p><p><strong>Ref:</strong> <span id="cancelar_nfe_ref_label">-</span></p><label>Justificativa</label><textarea name="justificativa" id="cancelar_nfe_justificativa" minlength="15" maxlength="255" required></textarea><div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;"><button type="button" class="btn btn-secondary" onclick="fecharModalCancelarNfe()"><i class="bi bi-x-lg"></i></button><button class="btn btn-danger" type="submit"><i class="bi bi-x-octagon"></i> Cancelar NF-e</button></div></form></div></div>
<div id="modalCartaCorrecaoNfe" class="modal"><div class="modal-box"><h3>Carta de correcao</h3><form method="POST" action="actions/carta_correcao_nfe_focus.php" onsubmit="return confirmarCartaCorrecaoNfe()"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>"><input type="hidden" name="ambiente" value="producao"><input type="hidden" name="documento_id" id="cce_nfe_documento_id"><p><strong>Nota:</strong> <span id="cce_nfe_numero_label">-</span></p><p><strong>Ref:</strong> <span id="cce_nfe_ref_label">-</span></p><label>Texto da correcao</label><textarea name="correcao" id="cce_nfe_correcao" minlength="15" maxlength="1000" required placeholder="Informe o texto que sera enviado como Carta de Correcao Eletronica"></textarea><p style="font-size:12px;color:#555;margin:8px 0 0;">Nao use para alterar valores, impostos, destinatario/remetente ou data de emissao/saida.</p><div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;"><button type="button" class="btn btn-secondary" onclick="fecharModalCartaCorrecaoNfe()"><i class="bi bi-x-lg"></i></button><button class="btn btn-nfe" type="submit"><i class="bi bi-send"></i> Enviar carta</button></div></form></div></div>
<div id="modalCancelarCompra" class="modal"><div class="modal-box"><h3>Cancelar compra</h3><form method="POST" action="actions/cancelar_compra_historico.php" onsubmit="return confirmarCancelamentoCompra()"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>"><input type="hidden" name="numero_os" id="cancelar_compra_os"><p><strong>OS:</strong> <span id="cancelar_compra_os_label">-</span></p><p><strong>Fornecedor:</strong> <span id="cancelar_compra_fornecedor">-</span></p><p><strong>Reversao financeira:</strong> <span id="cancelar_compra_valor">R$ 0,00</span></p><label>Motivo do cancelamento</label><textarea name="motivo_cancelamento" id="cancelar_compra_motivo" required></textarea><div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;"><button type="button" class="btn btn-secondary" onclick="fecharModalCancelarCompra()"><i class="bi bi-x-lg"></i></button><button class="btn btn-warning" type="submit"><i class="bi bi-arrow-counterclockwise"></i></button></div></form></div></div>
</body>
</html>

<?php


require '../auth/proteger.php';


require '../config/conexao.php';


require '../config/ciclo_helper.php';


require '../config/layout_helper.php';


require '../config/calculos_preco.php';


require '../config/permissions.php';
require __DIR__ . '/produto_modal_helper.php';





requireModule('tabelas', '../index.php');





// Tabela e colunas de preco_atacado_convencional criadas via database/migrations.sql.





$cicloAtivo = getCicloAtivo($conexao);


$cfg        = getConfigPrecificacao($conexao);





// Clientes com percentual financeiro


$resClientes = $conexao->query("


    SELECT c.id, c.nome,


           COALESCE(cpf.percentual, 0)              AS percentual,


           COALESCE(cpf.tipo_aplicacao, 'acrescimo') AS tipo_aplicacao


    FROM   clientes c


    INNER  JOIN cliente_percentual_financeiro cpf ON cpf.cliente_id = c.id AND cpf.tabela = 'convencional'


    WHERE  c.ativo = 1


    ORDER  BY c.nome


");


$clientes = $resClientes ? $resClientes->fetch_all(MYSQLI_ASSOC) : [];

// Todos os clientes ativos (para o painel de adicionar novo)
$resTodosCliConv = $conexao->query("SELECT id, nome FROM clientes WHERE ativo=1 ORDER BY nome");
$todosCliConv = $resTodosCliConv ? $resTodosCliConv->fetch_all(MYSQLI_ASSOC) : [];





// Carrega produtos com seus dados de preço


$resultado = $conexao->query("


    SELECT p.id,


           COALESCE(NULLIF(pa.nome_tabela, ''), p.nome) AS nome,


           p.unidade,


           pa.categoria, pa.gramagem, pa.valor_mp, pa.unidade_comercial, pa.kg_caixa,


           pa.acrescimo_35, pa.frete_kauauti, pa.frete_nivaldo,


           pa.prazo_5_dias, pa.prazo_30_dias,


           CASE WHEN pa.valor_mp > 0 THEN COALESCE(pa.ativo, 1) ELSE 0 END AS disponivel


    FROM   produtos p


    LEFT  JOIN preco_atacado_convencional pa ON pa.produto_id = p.id


    WHERE  p.ativo = 1
      AND  (p.produto_principal_id IS NULL OR pa.produto_id IS NOT NULL)


    ORDER  BY pa.id

");

$produtos = $resultado ?$resultado->fetch_all(MYSQLI_ASSOC) : [];





$categoriasProdutos = [];


foreach ($produtos as $produtoFiltro) {


    $categoriaFiltro = trim((string) ($produtoFiltro['categoria'] ?? ''));


    if ($categoriaFiltro !== '') {


        $categoriasProdutos[$categoriaFiltro] = true;


    }


}


ksort($categoriasProdutos, SORT_NATURAL | SORT_FLAG_CASE);


?>


<!DOCTYPE html>


<html lang="pt-br">


<head>


<meta charset="UTF-8">


<meta name="viewport" content="width=device-width, initial-scale=1.0">


<title>Tabela Atacado Convencional</title>


<style>


* { box-sizing: border-box; }


body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f9; color: #222; }


.container { padding: 24px 20px 32px; max-width: 1820px; margin: 0 auto; width: 100%; }


.card { background: #fff; padding: 22px 20px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,.06); margin-bottom: 24px; }


.section-title { margin: 0 0 6px; font-size: 18px; color: #1b5e20; font-weight: 700; }


.descricao { color: #555; font-size: 14px; margin: 0 0 16px; }


.formula-box { background: #f1f8e9; border: 1px solid #aed581; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 13px; color: #33691e; line-height: 1.8; }


.formula-box strong { color: #1b5e20; }


.formula-box .col-ref { font-family: monospace; background: #dcedc8; border-radius: 3px; padding: 1px 5px; font-size: 12px; }


.table-wrap { overflow-x: auto; overflow-y: hidden; }

.table-wrap.columns-compact { overflow-x: auto; }

table { width: 100%; border-collapse: collapse; min-width: 1080px; }

#atacadoConvencionalTable.is-compact-columns { min-width: 980px; table-layout: auto; }

#atacadoConvencionalTable.is-compact-columns .optional-col { display: none; }

th { background: #1b5e20; color: #fff; padding: 10px 8px; font-size: 12px; white-space: nowrap; text-align: left; line-height: 1.3; }


th.editavel { background: #2e7d32; }


th.calculado { background: #4a148c; }


th.preco-col { background: #b71c1c; }
th.preco-ajustado { background: #e65100; }
.col-ajustado { display: none; }
.col-ajustado.visivel { display: table-cell; }
.collapsible-toggle { display: inline-flex; align-items: center; gap: 8px; background: #f1f8e9; color: #1b5e20; border: 1px solid #c5e1a5; padding: 8px 12px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 13px; }
.collapsible-toggle:hover { background: #dcedc8; }
.collapsible-toggle .chevron { font-size: 12px; transition: transform .2s ease; }
.collapsible-toggle[aria-expanded="true"] .chevron { transform: rotate(180deg); }
.collapsible-content.is-collapsed { display: none; }
.clientes-toolbar { display: flex; justify-content: space-between; align-items: end; gap: 12px; flex-wrap: wrap; margin: 14px 0 12px; }
.clientes-toolbar .toolbar-search { flex: 1 1 640px; min-width: 540px; max-width: 860px; }
.clientes-toolbar .toolbar-search input { width: 100% !important; }
.input-pct { width: 84px; }
.btn-add-cliente-sec { background:#1565c0; color:#fff; border:none; height:36px; padding:0 14px; border-radius:6px; cursor:pointer; font-size:13px; font-weight:700; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; flex-shrink:0; }
.btn-add-cliente-sec:hover { background:#0d47a1; }
.btn-add-cliente-sec.painel-ativo { background:#0d47a1; }
.addcli-panel { display:none; margin:2px 0 14px; background:#f8fbff; border:1px solid #bbdefb; border-radius:8px; padding:14px; max-width:500px; }
.addcli-panel.ativo { display:block; }
.addcli-panel label { font-size:13px; font-weight:700; color:#0d47a1; display:block; margin-bottom:6px; }
.addcli-busca { width:100% !important; margin-bottom:8px !important; font-size:13px !important; }
.addcli-lista { max-height:240px; overflow-y:auto; border:1px solid #e0e0e0; border-radius:6px; background:#fff; }
.addcli-item { padding:10px 14px; cursor:pointer; font-size:13px; border-bottom:1px solid #f0f0f0; }
.addcli-item:last-child { border-bottom:none; }
.addcli-item:hover { background:#e3f2fd; color:#0d47a1; font-weight:600; }
.addcli-vazio { padding:12px 14px; color:#9e9e9e; font-size:13px; text-align:center; }
.btn-filtro-ajuste { background:#e65100; color:#fff; border:none; padding:8px 14px; border-radius:6px; cursor:pointer; font-size:13px; font-weight:700; white-space:nowrap; display:inline-flex; align-items:center; gap:6px; }
.btn-filtro-ajuste:hover { background:#bf360c; }
.btn-filtro-ajuste.ativo { background:#1b5e20; }
.btn-excluir-cli { background:#c62828; color:#fff; border:none; height:30px; width:32px; border-radius:6px; cursor:pointer; font-size:13px; vertical-align:middle; margin-left:4px; }
.btn-excluir-cli:hover { background:#8e0000; }
.cli-nome-cell { cursor:pointer; }
.cli-nome-cell .cli-nome-text { border-bottom:1px dashed #90a4ae; }
.cli-nome-cell:hover .cli-nome-text { color:#1565c0; border-bottom-color:#1565c0; }
.cli-sub-box { display:flex; gap:6px; align-items:center; }
.cli-sub-box select { font-size:13px; padding:4px; max-width:260px; }
.cli-sub-cancel { background:#9e9e9e; color:#fff; border:none; border-radius:5px; cursor:pointer; padding:5px 9px; font-size:12px; line-height:1; }
.cli-sub-cancel:hover { background:#757575; }
.btn-filtro-ajuste.ativo:hover { background:#2e7d32; }


td { padding: 8px 8px; border-bottom: 1px solid #eee; font-size: 12px; vertical-align: middle; line-height: 1.3; }


tr:hover td { background: #f1f8f4; }


tr.sem-preco td:first-child::after { content: ' - sem preço'; color: #e65100; font-size: 11px; font-weight: 700; }


tr.indisponivel td:first-child { color: #999; text-decoration: line-through; }


input[type=number], input[type=text] { width: 84px; min-height: 34px; padding: 6px 8px; border: 1px solid #ccc; border-radius: 5px; font-size: 12px; }


input[type=number]:focus, input[type=text]:focus { outline: none; border-color: #2e7d32; box-shadow: 0 0 0 2px rgba(46,125,50,.15); }


select { min-height: 34px; padding: 6px 8px; border: 1px solid #ccc; border-radius: 5px; font-size: 12px; background: #fff; }


.calc-val { font-weight: 600; font-size: 12px; white-space: nowrap; color: #333; }


.preco-base { color: #4a148c; font-size: 13px; font-weight: 700; }


.preco-final { color: #b71c1c; font-size: 13px; font-weight: 700; }


.btn-salvar { background:#2e7d32; color:#fff; border:none; width:34px; height:34px; padding:0; border-radius:6px; cursor:pointer; font-weight:700; font-size:15px; display:inline-flex; align-items:center; justify-content:center; line-height:1; }


.btn-salvar:hover { background: #1b5e20; }


.btn-salvar:disabled { background: #aaa; cursor: not-allowed; }


.save-status { font-size: 11px; margin-left: 5px; display: block; margin-top: 3px; }


.save-ok  { color: #2e7d32; }


.save-err { color: #c62828; }


.msg-sucesso { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-weight: 700; }


.legend { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 12px; font-size: 12px; }


.legend span { display: flex; align-items: center; gap: 5px; }


.leg-dot { width: 12px; height: 12px; border-radius: 2px; flex-shrink: 0; }


.table-wrap thead th { position: sticky; top: 0; z-index: 3; box-shadow: inset 0 -1px 0 rgba(255,255,255,.14); }


.toolbar { display: flex; flex-wrap: wrap; gap: 14px; align-items: end; justify-content: space-between; margin: 16px 0 14px; }


.toolbar-group { display: flex; flex-direction: column; gap: 6px; min-width: 180px; }


.toolbar-group label { font-size: 13px; color: #444; font-weight: 700; }


.toolbar-group input, .toolbar-group select { width: 100% !important; min-height: 40px; padding: 8px 10px; font-size: 13px; }


.toolbar-search { flex: 1 1 640px; min-width: 540px; max-width: 860px; }


.toolbar-search input { width: 100% !important; }


.toolbar-filters { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 14px; margin-left: auto; }


.toolbar-meta { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin: 8px 0 12px; flex-wrap: wrap; font-size: 12px; color: #555; }


.table-empty { display: none; margin: 10px 0 0; padding: 10px 12px; border-radius: 8px; background: #fff8e1; border: 1px solid #ffe082; color: #8d6e63; font-size: 12px; }


.pagination { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 14px; flex-wrap: wrap; }


.pagination-info { font-size: 12px; color: #555; }


.pagination-actions { display: flex; gap: 6px; flex-wrap: wrap; }


.page-btn { background: #f1f8e9; color: #1b5e20; border: 1px solid #c5e1a5; padding: 6px 10px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 700; }


.page-btn:hover { background: #dcedc8; }


.page-btn:disabled { opacity: .55; cursor: not-allowed; }


.page-btn.active { background: #2e7d32; border-color: #2e7d32; color: #fff; }


.export-btn, .column-toggle-btn { background:#2e7d32; color:#fff; border:none; width:34px; height:34px; padding:0; border-radius:6px; cursor:pointer; font-size:15px; display:inline-flex; align-items:center; justify-content:center; line-height:1; text-decoration:none; }

.export-btn { background:#1b5e20; }

.column-toggle-btn { background:#1565c0; width:auto; min-width:34px; padding:0 10px; gap:6px; font-size:13px; font-weight:700; white-space:nowrap; }

.column-toggle-btn[aria-pressed="true"] { background:#455a64; }

.export-btn:hover, .column-toggle-btn:hover { filter:brightness(.92); color:#fff; }

.action-buttons { display:flex; gap:6px; align-items:center; }
.export-actions { display:inline-flex; align-items:center; gap:6px; flex:0 0 auto; }
.export-btn-excel { background:#1b5e20; color:#fff; border-color:#1b5e20; }
.export-btn-pdf { background:#b71c1c; color:#fff; border-color:#b71c1c; }


.print-value { display:none; }


.print-commercial { display:none; }


.availability-print-header { display:none; }


@media print {


    body { background: #fff !important; }


    .app-header, .toolbar, .toolbar-meta, .pagination, .table-empty, .legend, .descricao, .section-title, .msg-sucesso, .formula-box, .print-hidden, .no-print, .print-area { display: none !important; }


    .container { padding: 0 !important; max-width: none !important; width: 100% !important; }


    .card { box-shadow: none !important; border-radius: 0 !important; padding: 0 !important; margin: 0 !important; }


    .print-commercial, .availability-print-header { display:block !important; }


    .availability-print-header { margin: 0 0 10px; }


    .print-logos { display:grid; grid-template-columns:1fr 1fr 1fr; align-items:center; gap:12px; min-height:58px; }


    .print-logo-slot { min-height:54px; display:flex; align-items:center; justify-content:center; border:0; color:#777; font-size:10px; text-align:center; }


    .print-logo-slot:first-child { justify-content:flex-start; }


    .print-logo-slot:last-child { justify-content:flex-end; }


    .print-logo-slot img { max-height:54px; max-width:150px; object-fit:contain; }
    .print-logo-slot.convencional-full { grid-column:1 / -1; justify-content:center; min-height:142px; background:#000; overflow:hidden; }
    .print-logo-slot.convencional-full img.logo-convencional { width:100%; height:auto; max-width:100%; max-height:142px; object-fit:contain; display:block; }


    .print-title { text-align:center; font-weight:800; font-size:15px; margin: 8px 0 10px; letter-spacing:0; }


    .print-commercial table { min-width:0 !important; width:100% !important; border-collapse:collapse !important; }


    .print-commercial th, .print-commercial td { border:1px solid #333 !important; padding:4px 6px !important; font-size:10.5px !important; color:#111 !important; line-height:1.2 !important; }


    .print-commercial th { background:#d9ead3 !important; font-weight:800 !important; text-transform:uppercase; }


    .print-commercial .num { text-align:right; white-space:nowrap; }


}


@media (min-width: 1500px) {


    .container { max-width: 1920px; padding-left: 18px; padding-right: 18px; }


    .card { padding-left: 18px; padding-right: 18px; }


    table { min-width: 1040px; }


    th { padding: 9px 7px; font-size: 11.5px; }


    td { padding: 7px 7px; font-size: 11.5px; }


    input[type=number], input[type=text] { width: 78px; min-height: 32px; }


    select { min-height: 32px; font-size: 11.5px; }


    .calc-val { font-size: 11.5px; }


}


@media (max-width: 1100px) {


    .container { padding: 18px 12px 24px; }


    .card { padding: 18px 14px; }


}











@media (max-width: 760px) {


    .toolbar { flex-direction: column; align-items: stretch; }


    .toolbar-group { min-width: 0; width: 100%; }


    .toolbar-search { min-width: 0; max-width: 100%; width: 100%; flex: 1 1 auto; }


    .clientes-toolbar .toolbar-search { min-width: 0; }


    .toolbar-filters { flex-direction: column; align-items: stretch; width: 100%; }


    .toolbar-filters > * { width: 100%; }


    .btn-action, .btn-toggle-col, [class*="btn-"] { width: 100%; text-align: center; }


}


</style>


<?php renderAppLayoutStyles(); ?>


<script>


function atualizarValoresParaImpressao() {


    document.querySelectorAll('.print-area input, .print-area select').forEach(function (campo) {


        let span = campo.parentElement.querySelector(':scope > .print-value');


        if (!span) {


            span = document.createElement('span');


            span.className = 'print-value';


            campo.insertAdjacentElement('afterend', span);


        }


        if (campo.tagName === 'SELECT') {


            const option = campo.options[campo.selectedIndex];


            span.textContent = option ? option.textContent.trim() : '';


        } else {


            span.textContent = campo.value || '-';


        }


    });


}


function imprimirTabelaAtual() {


    atualizarValoresParaImpressao();


    window.print();


}


window.addEventListener('beforeprint', atualizarValoresParaImpressao);


// -- Espelho exato das fórmulas de calculos_preco.php -------------------------


function calcAtacadoConvencionalJS(valor_mp, kg_caixa, frete_nivaldo_manual, frete_kauauti_manual) {


    const acrescimo_35  = valor_mp * 1.35;


    const frete_kauauti = frete_kauauti_manual;


    const frete_nivaldo = frete_nivaldo_manual;


    // Fórmula exata da planilha: =E7+I7+J7*5/100+I7+J7


    const prazo5_bruto  = acrescimo_35 + frete_kauauti + (frete_nivaldo * 5/100) + frete_kauauti + frete_nivaldo;


    const prazo30_bruto = prazo5_bruto * 1.05;


    return {


        acrescimo_35:  acrescimo_35,


        frete_kauauti: frete_kauauti,


        frete_nivaldo: frete_nivaldo,


        prazo_5_dias:  Math.round(prazo5_bruto  * 100) / 100,


        prazo_30_dias: Math.round(prazo30_bruto * 100) / 100,


    };


}





function fmt(v) { return 'R$ ' + v.toFixed(2).replace('.', ','); }



function aplicarPercentualFinanceiroJS(preco, pct, tipo) {

    if (tipo === 'desconto') return Math.round(preco * (1 - pct) * 100) / 100;

    return Math.round(preco * (1 + pct) * 100) / 100;

}



function atualizarPrecosClienteAtacadoConvencional() {

    const sel = document.getElementById('atacadoConvencionalCliente');

    const pct  = parseFloat(sel?.dataset.percentual || '0');

    const tipo = sel?.dataset.tipo || 'acrescimo';

    const temAjuste = pct > 0;



    document.querySelectorAll('.col-ajustado').forEach(el => {

        el.classList.toggle('visivel', temAjuste);

    });



    document.querySelectorAll('#atacadoConvencionalTbody tr[data-produto-row="1"]').forEach(tr => {

        const p5  = parseFloat(tr.dataset.p5  || '0');

        const p30 = parseFloat(tr.dataset.p30 || '0');

        const pid = tr.dataset.pid;

        const p5adj  = document.getElementById('p5adj_'  + pid);

        const p30adj = document.getElementById('p30adj_' + pid);

        if (p5adj)  p5adj.textContent  = (temAjuste && p5  > 0) ? fmt(aplicarPercentualFinanceiroJS(p5,  pct, tipo)) : '-';

        if (p30adj) p30adj.textContent = (temAjuste && p30 > 0) ? fmt(aplicarPercentualFinanceiroJS(p30, pct, tipo)) : '-';

    });

}



// ── Painel: Adicionar novo cliente na seção de desconto/acréscimo ─────────────
const _CONV_TODOS_CLI = <?= json_encode(array_map(fn($c)=>['id'=>(int)$c['id'],'nome'=>$c['nome']], $todosCliConv), JSON_UNESCAPED_UNICODE) ?>;
const _convJaAdicionados = new Set(<?= json_encode(array_column($clientes, 'id')) ?>);

function _convEscHtml(s){return String(s).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
function _convEscAttr(s){return _convEscHtml(s);}

function togglePainelAddClienteConv() {
    const panel = document.getElementById('painelAddClienteConv');
    const btn   = document.getElementById('btnAddClienteConv');
    if (!panel) return;
    const ativo = panel.classList.toggle('ativo');
    btn.classList.toggle('painel-ativo', ativo);
    if (ativo) {
        const busca = document.getElementById('addConvBusca');
        if (busca) { busca.value = ''; busca.focus(); }
        renderListaAddConv('');
    }
}

function renderListaAddConv(termo) {
    const lista = document.getElementById('addConvLista');
    if (!lista) return;
    const t = (termo||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
    const disponiveis = _CONV_TODOS_CLI.filter(c => {
        if (_convJaAdicionados.has(c.id)) return false;
        if (!t) return true;
        return c.nome.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().includes(t);
    });
    if (!disponiveis.length) {
        lista.innerHTML = '<div class="addcli-vazio">Nenhum cliente disponível para adicionar.</div>';
        return;
    }
    lista.innerHTML = disponiveis.map(c =>
        `<div class="addcli-item" data-add-cid="${c.id}" data-add-nome="${_convEscAttr(c.nome)}">${_convEscHtml(c.nome)}</div>`
    ).join('');
}

async function adicionarClienteConv(cid, nome) {
    if (_convJaAdicionados.has(cid)) return;
    _convJaAdicionados.add(cid);

    // Fechar painel
    document.getElementById('painelAddClienteConv')?.classList.remove('ativo');
    document.getElementById('btnAddClienteConv')?.classList.remove('painel-ativo');

    // Persistir o vínculo imediatamente (percentual 0) para o cliente já aparecer
    // na listagem de vendas desta tabela. O usuário pode ajustar o % e salvar depois.
    try {
        const fd = new FormData();
        fd.append('cliente_id',     cid);
        fd.append('percentual',     '0');
        fd.append('tipo_aplicacao', 'acrescimo');
        fd.append('tabela',         'convencional');
        const r = await fetch('actions/salvar_percentual_cliente.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (!d.ok) {
            _convJaAdicionados.delete(cid);
            alert(d.erro || 'Não foi possível adicionar o cliente.');
            return;
        }
    } catch (e) {
        _convJaAdicionados.delete(cid);
        alert('Falha na rede ao adicionar o cliente.');
        return;
    }

    // Criar nova linha na tabela
    const tbody = document.getElementById('atacadoConvencionalClientesTbody');
    if (!tbody) return;
    const tr = document.createElement('tr');
    tr.setAttribute('data-cliente-row', '1');
    tr.setAttribute('data-cid', cid);
    tr.innerHTML = `
        <td class="cli-nome-cell" data-cid="${cid}" data-nome="${_convEscAttr(nome)}" onclick="abrirSubstituirConv(this)" title="Clique para substituir por outro cliente"><span class="cli-nome-text">${_convEscHtml(nome)}</span></td>
        <td>
            <input type="number" step="0.01" min="0" max="100" class="input-pct"
                   id="pct_${cid}" value="0.00" placeholder="0,00"
                   oninput="atualizarExemploConv(${cid})">
            <span style="font-size:12px;color:#555;margin-left:3px;">%</span>
        </td>
        <td id="exemploConv_${cid}" style="color:#555;font-size:12px;">
            R$ 10,00 -&gt; <strong>R$ 10,00</strong>
            <span style="color:#999;">(sem ajuste)</span>
        </td>
        <td style="white-space:nowrap;" class="print-hidden">
            <button id="pbtn_${cid}" class="btn-salvar"
                    onclick="salvarPercentualConvencional(${cid})" title="Salvar">
                <i class="bi bi-check-lg"></i>
            </button>
            <button type="button" class="btn-excluir-cli" onclick="removerClienteConv(${cid})" title="Remover cliente desta tabela"><i class="bi bi-trash"></i></button>
            <span id="pstat_${cid}" class="save-status"></span>
        </td>`;
    tbody.appendChild(tr);

    // Garantir que a linha fique visível mesmo com filtros ativos
    const busca = document.getElementById('atacadoConvencionalClientesBusca');
    if (busca) busca.dispatchEvent(new Event('input'));

    // Foco e scroll
    setTimeout(() => {
        document.getElementById(`pct_${cid}`)?.focus();
        tr.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 80);
}

async function removerClienteConv(cid) {
    const tbody = document.getElementById('atacadoConvencionalClientesTbody');
    const tr = tbody ? tbody.querySelector(`tr[data-cid="${cid}"]`) : null;
    const nome = tr ? (tr.querySelector('.cli-nome-cell')?.getAttribute('data-nome') || 'este cliente') : 'este cliente';
    if (!confirm(`Remover "${nome}" da tabela Atacado Convencional?\n\nEle deixará de aparecer na listagem de vendas desta tabela.`)) return;
    try {
        const fd = new FormData();
        fd.append('cliente_id', cid);
        fd.append('tabela', 'convencional');
        const r = await fetch('actions/excluir_percentual_cliente.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) {
            tr?.remove();
            _convJaAdicionados.delete(Number(cid));
        } else {
            alert('Erro ao remover: ' + (d.erro || 'desconhecido'));
        }
    } catch (e) {
        alert('Falha de rede ao remover.');
    }
}

function abrirSubstituirConv(cell) {
    if (cell.querySelector('select')) return;
    const cid = parseInt(cell.getAttribute('data-cid'));
    const nomeAtual = cell.getAttribute('data-nome') || '';
    const original = cell.innerHTML;
    const opts = _CONV_TODOS_CLI
        .filter(c => c.id !== cid && !_convJaAdicionados.has(c.id))
        .map(c => `<option value="${c.id}">${_convEscHtml(c.nome)}</option>`)
        .join('');
    const box = document.createElement('div');
    box.className = 'cli-sub-box';
    box.innerHTML = `<select><option value="">— substituir por… —</option>${opts}</select>` +
                    `<button type="button" class="cli-sub-cancel" title="Cancelar">&times;</button>`;
    cell.innerHTML = '';
    cell.appendChild(box);
    const sel = box.querySelector('select');
    sel.focus();
    box.querySelector('.cli-sub-cancel').addEventListener('click', (e) => {
        e.stopPropagation();
        cell.innerHTML = original;
    });
    sel.addEventListener('change', async () => {
        const novo = parseInt(sel.value);
        if (!novo) return;
        const nomeNovo = sel.options[sel.selectedIndex].text;
        if (!confirm(`Substituir "${nomeAtual}" por "${nomeNovo}" no Atacado Convencional?\n\nO percentual atual é preservado e "${nomeAtual}" deixará de aparecer nas vendas desta tabela.`)) {
            cell.innerHTML = original;
            return;
        }
        try {
            const fd = new FormData();
            fd.append('cliente_origem_id', cid);
            fd.append('cliente_destino_id', novo);
            fd.append('tabela', 'convencional');
            const r = await fetch('actions/substituir_percentual_cliente.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d.ok) {
                location.reload();
            } else {
                alert('Erro ao substituir: ' + (d.erro || 'desconhecido'));
                cell.innerHTML = original;
            }
        } catch (e) {
            alert('Falha de rede ao substituir.');
            cell.innerHTML = original;
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const addLista = document.getElementById('addConvLista');
    if (addLista) {
        addLista.addEventListener('click', function (e) {
            const item = e.target.closest('.addcli-item[data-add-cid]');
            if (!item) return;
            adicionarClienteConv(parseInt(item.getAttribute('data-add-cid')), item.getAttribute('data-add-nome'));
        });
    }
});

function atualizarExemploConv(cid) {
    const el = document.getElementById(`exemploConv_${cid}`);
    const pct = parseFloat(document.getElementById(`pct_${cid}`)?.value || '0') / 100;
    if (!el) return;
    const final = 10 * (1 + pct);
    const pctStr = (pct * 100).toFixed(2).replace('.', ',');
    el.innerHTML = pct > 0
        ? `R$ 10,00 -&gt; <strong>R$ ${final.toFixed(2).replace('.', ',')}</strong> <span style="color:#2e7d32;">(+${pctStr}%)</span>`
        : `R$ 10,00 -&gt; <strong>R$ 10,00</strong> <span style="color:#999;">(sem ajuste)</span>`;
}



async function salvarPercentualConvencional(cid) {

    const btn  = document.getElementById('pbtn_'  + cid);

    const stat = document.getElementById('pstat_' + cid);

    const pct  = document.getElementById('pct_'   + cid).value;

    const tipo = 'acrescimo';



    btn.disabled = true;

    stat.textContent = 'Salvando...';

    stat.className = 'save-status';



    try {

        const fd = new FormData();

        fd.append('cliente_id',     cid);

        fd.append('percentual',     pct);

        fd.append('tipo_aplicacao', tipo);
        fd.append('tabela',         'convencional');



        const r = await fetch('actions/salvar_percentual_cliente.php', { method: 'POST', body: fd });

        const d = await r.json();



        if (d.ok) {

            stat.textContent = 'OK Salvo';

            stat.className = 'save-status save-ok';

        } else {

            stat.textContent = 'Erro ' + (d.erro || 'Erro');

            stat.className = 'save-status save-err';

        }

    } catch(e) {

        stat.textContent = 'Erro Falha na rede';

        stat.className = 'save-status save-err';

    } finally {

        btn.disabled = false;

        setTimeout(() => { stat.textContent = ''; }, 4000);

    }

}



function recalcAtacadoConvencional(pid) {


    const mp  = parseFloat(document.getElementById('mp_'  + pid).value) || 0;


    const kgc = parseFloat(document.getElementById('kgc_' + pid).value) || 0;


    const fkEl = document.getElementById('fk_input_' + pid);


    const fnEl = document.getElementById('fn_input_' + pid);


    const fk = fkEl && fkEl.value !== '' ? parseFloat(fkEl.value) || 0 : (kgc > 0 ? (6.5 / kgc) : 0);


    const fn = fnEl && fnEl.value !== '' ? parseFloat(fnEl.value) || 0 : 0.30;





    if (mp <= 0 && kgc <= 0) {


        ['ac35_','fk_','fn_','p5_','p30_'].forEach(p => {


            document.getElementById(p + pid).textContent = '-';


        });


        return;


    }





    const c = calcAtacadoConvencionalJS(mp, kgc, fn, fk);

    document.getElementById('ac35_' + pid).textContent = fmt(c.acrescimo_35);

    document.getElementById('fk_'   + pid).textContent = fmt(c.frete_kauauti);

    document.getElementById('fn_'   + pid).textContent = fmt(c.frete_nivaldo);

    document.getElementById('p5_'   + pid).textContent = fmt(c.prazo_5_dias);

    document.getElementById('p30_'  + pid).textContent = fmt(c.prazo_30_dias);



    // Atualizar data attrs e precos ajustados por cliente

    const row = document.getElementById('p5_' + pid)?.closest('tr');

    if (row) {

        row.dataset.p5  = c.prazo_5_dias;

        row.dataset.p30 = c.prazo_30_dias;

    }

    const sel  = document.getElementById('atacadoConvencionalCliente');

    const pct  = parseFloat(sel?.dataset.percentual || '0');

    const tipo = sel?.dataset.tipo || 'acrescimo';

    const p5adj  = document.getElementById('p5adj_'  + pid);

    const p30adj = document.getElementById('p30adj_' + pid);

    if (p5adj)  p5adj.textContent  = pct > 0 ? fmt(aplicarPercentualFinanceiroJS(c.prazo_5_dias,  pct, tipo)) : '-';

    if (p30adj) p30adj.textContent = pct > 0 ? fmt(aplicarPercentualFinanceiroJS(c.prazo_30_dias, pct, tipo)) : '-';

}





async function salvarAtacadoConvencional(pid) {


    const btn  = document.getElementById('btn_' + pid);


    const stat = document.getElementById('stat_' + pid);


    const cat  = document.getElementById('cat_' + pid).value;


    const gram = document.getElementById('gram_' + pid).value;


    const mp   = document.getElementById('mp_'  + pid).value;


    const unidade = document.getElementById('unidade_' + pid)?.value?.trim() || '';


    const kgc  = document.getElementById('kgc_' + pid).value;


    const fk   = document.getElementById('fk_input_' + pid).value;


    const fn   = document.getElementById('fn_input_' + pid).value;


    const disp = document.getElementById('disp_' + pid).value;





    btn.disabled = true;


    stat.textContent = 'Salvando...';


    stat.className = 'save-status';





    try {


        const fd = new FormData();


        fd.append('produto_id', pid);


        fd.append('categoria',  cat);


        fd.append('gramagem',   gram);


        fd.append('valor_mp',   mp);


        fd.append('unidade_comercial', unidade);


        fd.append('kg_caixa',   kgc);


        fd.append('frete_kauauti', fk);


        fd.append('frete_nivaldo', fn);


        fd.append('ativo',      disp);





        const r = await fetch('actions/salvar_preco_atacado_convencional.php', { method: 'POST', body: fd });


        const d = await r.json();





        if (d.ok) {


            stat.textContent = 'OK Salvo';


            stat.className = 'save-status save-ok';


        } else {


            stat.textContent = 'Erro ' + (d.erro || 'Erro');


            stat.className = 'save-status save-err';


        }


    } catch(e) {


        stat.textContent = 'Erro Falha na rede';


        stat.className = 'save-status save-err';


    } finally {


        btn.disabled = false;


        setTimeout(() => { stat.textContent = ''; }, 4000);


    }


}





function normalizarBuscaTexto(texto) {


    return (texto || '')


        .toString()


        .normalize('NFD')


        .replace(/[\u0300-\u036f]/g, '')


        .toLowerCase()


        .trim();


}





function configurarTabelaAtacadoConvencional() {


    const tbody = document.getElementById('atacadoConvencionalTbody');


    if (!tbody) return;





    const rows = Array.from(tbody.querySelectorAll('tr[data-produto-row="1"]'));


    const busca = document.getElementById('atacadoConvencionalBusca');


    const disponibilidade = document.getElementById('atacadoConvencionalDisponibilidade');


    const categoria = document.getElementById('atacadoConvencionalCategoria');


    const preco = document.getElementById('atacadoConvencionalPreco');


    const pageSize = document.getElementById('atacadoConvencionalPageSize');


    const resumo = document.getElementById('atacadoConvencionalResumo');


    const empty = document.getElementById('atacadoConvencionalEmpty');


    const pagInfo = document.getElementById('atacadoConvencionalPaginacaoInfo');


    const pagActions = document.getElementById('atacadoConvencionalPaginacao');





    let paginaAtual = 1;





    function linhaTemPreco(row) {


        const mp = parseFloat(row.querySelector('input[id^="mp_"]')?.value || '0');


        return mp > 0;


    }





    function linhaCategoria(row) {


        return normalizarBuscaTexto(row.querySelector('input[id^="cat_"]')?.value || '');


    }





    function linhaDisponivel(row) {


        return row.querySelector('select[id^="disp_"]')?.value || '1';


    }





    function linhaProduto(row) {


        return normalizarBuscaTexto(row.querySelector('td')?.textContent || '');


    }





    function renderPaginacao(totalFiltrados, totalPaginas) {


        pagActions.innerHTML = '';


        pagInfo.textContent = totalFiltrados > 0 ?


             `Página ${paginaAtual} de ${totalPaginas}`


            : 'Página 0 de 0';





        const prev = document.createElement('button');


        prev.type = 'button';


        prev.className = 'page-btn';


        prev.textContent = 'Anterior';


        prev.disabled = paginaAtual <= 1;


        prev.onclick = () => { paginaAtual--; aplicar(); };


        pagActions.appendChild(prev);





        const inicio = Math.max(1, paginaAtual - 2);


        const fim = Math.min(totalPaginas, paginaAtual + 2);


        for (let p = inicio; p <= fim; p++) {


            const btn = document.createElement('button');


            btn.type = 'button';


            btn.className = 'page-btn' + (p === paginaAtual ? ' active' : '');


            btn.textContent = String(p);


            btn.onclick = () => { paginaAtual = p; aplicar(); };


            pagActions.appendChild(btn);


        }





        const next = document.createElement('button');


        next.type = 'button';


        next.className = 'page-btn';


        next.textContent = 'Próxima';


        next.disabled = paginaAtual >= totalPaginas;


        next.onclick = () => { paginaAtual++; aplicar(); };


        pagActions.appendChild(next);


    }





    function aplicar(resetPage = false) {


        if (resetPage) paginaAtual = 1;





        const termo = normalizarBuscaTexto(busca?.value || '');


        const filtroDisp = disponibilidade?.value || 'todos';


        const filtroCat = normalizarBuscaTexto(categoria?.value || '');


        const filtroPreco = preco?.value || 'todos';


        const tamanho = parseInt(pageSize?.value || '25', 10);





        const filtradas = rows.filter((row) => {


            if (termo && !linhaProduto(row).includes(termo)) return false;


            if (filtroDisp !== 'todos' && linhaDisponivel(row) !== filtroDisp) return false;


            if (filtroCat && linhaCategoria(row) !== filtroCat) return false;


            if (filtroPreco === 'com_preco' && !linhaTemPreco(row)) return false;


            if (filtroPreco === 'sem_preco' && linhaTemPreco(row)) return false;


            return true;


        });





        const totalFiltrados = filtradas.length;


        const totalPaginas = Math.max(1, Math.ceil(totalFiltrados / tamanho));


        if (paginaAtual > totalPaginas) paginaAtual = totalPaginas;





        const inicio = (paginaAtual - 1) * tamanho;


        const fim = inicio + tamanho;


        const pagina = new Set(filtradas.slice(inicio, fim));





        rows.forEach((row) => {


            row.style.display = pagina.has(row) ?'' : 'none';


        });





        if (resumo) {


            resumo.textContent = `${totalFiltrados} produto(s) encontrado(s)`;


        }





        if (empty) {


            empty.style.display = totalFiltrados === 0 ? 'block' : 'none';


        }





        renderPaginacao(totalFiltrados, totalPaginas);


    }





    [busca, disponibilidade, categoria, preco, pageSize].forEach((el) => {


        if (!el) return;


        const evento = el.tagName === 'INPUT' ? 'input' : 'change';


        el.addEventListener(evento, () => aplicar(true));


    });






    aplicar(true);


}



function configurarColunasAtacadoConvencional() {

    const table = document.getElementById('atacadoConvencionalTable');

    const button = document.getElementById('toggleColunasAtacadoConvencional');

    const tableWrap = table?.closest('.table-wrap');

    if (!table || !button) return;



    const storageKey = 'agrocolitti.atacadoConvencional.colunasEnxutas';



    function aplicarModoCompacto(compacto) {

        table.classList.toggle('is-compact-columns', compacto);

        tableWrap?.classList.toggle('columns-compact', compacto);

        button.setAttribute('aria-pressed', compacto ? 'true' : 'false');

        button.title = compacto ? 'Mostrar todas as colunas' : 'Ocultar colunas calculadas';

        button.innerHTML = compacto

            ? '<i class="bi bi-eye"></i><span>Mostrar colunas</span>'

            : '<i class="bi bi-eye-slash"></i><span>Ocultar colunas</span>';

        localStorage.setItem(storageKey, compacto ? '1' : '0');

    }



    aplicarModoCompacto(localStorage.getItem(storageKey) === '1');

    button.addEventListener('click', () => {

        aplicarModoCompacto(!table.classList.contains('is-compact-columns'));

    });

}



document.addEventListener('DOMContentLoaded', () => {

    configurarTabelaAtacadoConvencional();

    configurarColunasAtacadoConvencional();



    // Seletor de cliente

    const clienteSel = document.getElementById('atacadoConvencionalCliente');

    if (clienteSel) {

        clienteSel.addEventListener('change', function() {

            const opt = this.options[this.selectedIndex];

            this.dataset.percentual = opt?.dataset.percentual || '0';

            this.dataset.tipo       = opt?.dataset.tipo       || 'acrescimo';

            atualizarPrecosClienteAtacadoConvencional();

        });

    }



    // Toggle card clientes

    const cToggle  = document.getElementById('atacadoConvencionalClientesToggle');

    const cContent = document.getElementById('atacadoConvencionalClientesContent');

    if (cToggle && cContent) {

        cToggle.addEventListener('click', () => {

            const expandido = cToggle.getAttribute('aria-expanded') === 'true';

            cToggle.setAttribute('aria-expanded', expandido ? 'false' : 'true');

            cContent.classList.toggle('is-collapsed', expandido);

        });

    }



    // Busca e filtro de clientes

    const cBusca  = document.getElementById('atacadoConvencionalClientesBusca');

    const cFiltro = document.getElementById('atacadoConvencionalClientesFiltroAjuste');

    const cTbody  = document.getElementById('atacadoConvencionalClientesTbody');

    const cEmpty  = document.getElementById('atacadoConvencionalClientesEmpty');

    if (cBusca && cTbody) {

        const cRows = Array.from(cTbody.querySelectorAll('tr[data-cliente-row="1"]'));

        let apenasComAjuste = false;



        function aplicarFiltroClientesConvencional() {

            const termo = normalizarBuscaTexto(cBusca.value);

            let visiveis = 0;

            cRows.forEach(row => {

                const nome = normalizarBuscaTexto(row.querySelector('td')?.textContent || '');

                const pctInput = row.querySelector('input[id^="pct_"]');

                const temAjuste = pctInput && parseFloat(pctInput.value || '0') > 0;

                const mostrar = (!termo || nome.includes(termo)) && (!apenasComAjuste || temAjuste);

                row.style.display = mostrar ? '' : 'none';

                if (mostrar) visiveis++;

            });

            if (cEmpty) cEmpty.style.display = visiveis === 0 ? 'block' : 'none';

            const resumo = document.getElementById('atacadoConvencionalClientesResumo');

            if (resumo) resumo.textContent = visiveis + ' cliente(s) encontrado(s)';

        }



        cBusca.addEventListener('input', aplicarFiltroClientesConvencional);



        if (cFiltro) {

            cFiltro.addEventListener('click', function() {

                apenasComAjuste = !apenasComAjuste;

                this.classList.toggle('ativo', apenasComAjuste);

                this.innerHTML = apenasComAjuste

                    ? '<i class="bi bi-funnel-fill"></i> Mostrar todos'

                    : '<i class="bi bi-funnel"></i> Somente com ajuste';

                aplicarFiltroClientesConvencional();

            });

        }

    }

});

</script>

</head>


<body>


<?php renderAppHeader('..'); ?>





<div class="container">





<?php if ($cicloAtivo): ?>


    <div class="msg-sucesso">Ciclo ativo: <?= htmlspecialchars(getNomeCiclo($cicloAtivo)) ?></div>


<?php endif; ?>





<div class="card">


    <h2 class="section-title">Tabela de Preços - Atacado Convencional</h2>


    <p class="descricao">


        Informe os campos do Atacado Convencional no mesmo padrão da planilha. Os custos calculados são atualizados automaticamente.


        Na tela de vendas, o sistema aplica o percentual financeiro do cliente sobre o prazo selecionado.


    </p>





    <div class="legend">


        <span><span class="leg-dot" style="background:#2e7d32;"></span> Coluna editável</span>


        <span><span class="leg-dot" style="background:#4a148c;"></span> Calculado automaticamente</span>


        <span><span class="leg-dot" style="background:#b71c1c;"></span> Preços finais por prazo</span>


    </div>





    <div class="toolbar no-print">


        <div class="toolbar-group toolbar-search">


            <label for="atacadoConvencionalBusca">Buscar produto</label>


            <input type="text" id="atacadoConvencionalBusca" placeholder="Digite parte do nome do produto">


        </div>


        <div class="toolbar-filters">


            <div class="toolbar-group print-hidden">


                <label for="tabelaAtacadoConvencionalAcoes">Tabelas</label>


                <select id="tabelaAtacadoConvencionalAcoes" onchange="if(this.value) window.location.href=this.value;">


                    <option value="">Atacado Convencional</option>


                    <option value="tabela_atacado.php">Atacado</option>


                    <option value="tabela_embalado.php">Embalado</option>

                    <option value="tabela_oba_embalado.php">OBA Embalado</option>

                    <option value="tabela_shopper.php">Shopper</option>


                    <option value="criar_tabela_personalizada.php">+ Criar nova tabela</option>


                    <option value="tabelas_personalizadas.php">Editar tabelas</option>


                </select>


            </div>


            <div class="toolbar-group">


                <label for="atacadoConvencionalDisponibilidade">Disponibilidade</label>

                <select id="atacadoConvencionalDisponibilidade">

                    <option value="todos">Todos</option>

                    <option value="1" selected>Disponível</option>

                    <option value="0">Indisponível</option>

                </select>

            </div>


            <div class="toolbar-group">


                <label for="atacadoConvencionalCategoria">Categoria</label>


                <select id="atacadoConvencionalCategoria">


                    <option value="">Todas</option>


                    <?php foreach (array_keys($categoriasProdutos) as $categoriaOption): ?>


                        <option value="<?= htmlspecialchars($categoriaOption, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($categoriaOption) ?></option>


                    <?php endforeach; ?>


                </select>


            </div>


            <div class="toolbar-group">


                <label for="atacadoConvencionalPreco">Preço</label>

                <select id="atacadoConvencionalPreco">

                    <option value="todos">Todos</option>

                    <option value="com_preco" selected>Com preço</option>

                    <option value="sem_preco">Sem preço</option>

                </select>

            </div>


            <div class="toolbar-group">

                <label for="atacadoConvencionalPageSize">Itens por página</label>

                <select id="atacadoConvencionalPageSize">

                    <option value="25" selected>25</option>

                    <option value="50">50</option>

                    <option value="100">100</option>

                </select>

            </div>

            <div class="toolbar-group">

                <label for="atacadoConvencionalCliente">Cliente</label>

                <select id="atacadoConvencionalCliente" data-percentual="0" data-tipo="acrescimo">

                    <option value="" data-percentual="0" data-tipo="acrescimo">Sem cliente</option>

                    <?php foreach ($clientes as $c): ?>

                    <option value="<?= $c['id'] ?>"

                        data-percentual="<?= number_format((float)$c['percentual'], 4, '.', '') ?>"

                        data-tipo="<?= htmlspecialchars($c['tipo_aplicacao']) ?>">

                        <?= htmlspecialchars($c['nome']) ?><?php if ((float)$c['percentual'] > 0): ?> (<?= $c['tipo_aplicacao'] === 'desconto' ? '-' : '+' ?><?= number_format((float)$c['percentual'] * 100, 1, ',', '') ?>%)<?php endif; ?>

                    </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="toolbar-group print-hidden">


                <label>&nbsp;</label>

                <div class="action-buttons">
                    <?php renderBotaoAdicionarProdutoTabela(); ?>

                    <button type="button" id="toggleColunasAtacadoConvencional" class="column-toggle-btn" aria-pressed="false" title="Ocultar colunas calculadas"><i class="bi bi-eye-slash"></i><span>Ocultar colunas</span></button>

                    </div>

            </div>


        </div>


    </div>





    <div class="toolbar-meta">
        <div>
            <div id="atacadoConvencionalResumo">0 produto(s) encontrado(s)</div>
            <div>Busca, filtros e paginação atuam só na navegação da tela.</div>
        </div>
        <div class="export-actions">
            <a class="export-btn export-btn-excel" href="exportar_disponibilidade_xlsx.php?tipo=atacado_convencional" data-export-financeiro data-tipo-tabela="atacado_convencional" title="Baixar tabela em Excel"><i class="bi bi-file-earmark-excel"></i></a>
            <a class="export-btn export-btn-pdf" href="exportar_disponibilidade_pdf.php?tipo=atacado_convencional" data-export-financeiro data-tipo-tabela="atacado_convencional" title="Baixar tabela em PDF"><i class="bi bi-file-earmark-pdf"></i></a>
        </div>    </div>





    <div class="table-wrap print-area">


    <table id="atacadoConvencionalTable" data-no-responsive="1">


        <thead>


            <tr>


                <th class="editavel">Produto</th>


                <th class="editavel">CAT</th>


                <th class="editavel">Gramagem</th>


                <th class="editavel">Valor MP (R$/kg)</th>


                <th class="editavel">Unidade</th>


                <th class="editavel">KG da Caixa</th>


                <th class="calculado optional-col">Acréscimo 35%</th>

                <th class="calculado">Fr. Kauauti</th>


                <th class="calculado">Fr. Nivaldo</th>


                <th class="preco-col">Custo 5 dias</th>

                <th class="preco-col">Custo 30 dias</th>

                <th class="preco-ajustado col-ajustado">P5 c/ Cliente</th>

                <th class="preco-ajustado col-ajustado">P30 c/ Cliente</th>

                <th class="editavel">Disponível</th>


                <th class="editavel print-hidden">Ação</th>


            </tr>


        </thead>


        <tbody id="atacadoConvencionalTbody">


        <?php foreach ($produtos as $p):


            $pid    = (int) $p['id'];


            $cat    = (string) ($p['categoria'] ?? '');


            $gram   = (float) ($p['gramagem'] ?? 0);


            $vmp    = (float) ($p['valor_mp'] ?? 0);


            $kgc    = (float) ($p['kg_caixa'] ?? 20);


            $unidade = (string) (($p['unidade_comercial'] ?? '') !== '' ? $p['unidade_comercial'] : ($p['unidade'] ?? 'kg'));


            $disp   = (int)   ($p['disponivel'] ?? 1);


            $temDados = $vmp > 0;


            $freteKauauti = (float) ($p['frete_kauauti'] ?? 0);

            $freteNivaldo = (float) ($p['frete_nivaldo'] ?? 0);

            $calc = ($vmp > 0 || $kgc > 0) ?calcAtacadoConvencionalManual($vmp, $freteKauauti, $freteNivaldo, $cfg) : null;

        ?>


        <tr class="<?= !$temDados ?'sem-preco' : '' ?> <?= !$disp ?'indisponivel' : '' ?>" data-produto-row="1"

            data-pid="<?= $pid ?>"

            data-p5="<?= $calc ?number_format((float)$calc['prazo_5_dias'], 2, '.', '') : '0' ?>"

            data-p30="<?= $calc ?number_format((float)$calc['prazo_30_dias'], 2, '.', '') : '0' ?>">

            <td><?= htmlspecialchars($p['nome']) ?></td>


            <td>


                <input type="text" id="cat_<?= $pid ?>"


                    value="<?= htmlspecialchars($cat) ?>"


                    placeholder="ex: folhosa">


            </td>


            <td>


                <input type="number" step="0.001" min="0" id="gram_<?= $pid ?>"


                    value="<?= $gram > 0 ?number_format($gram, 3, '.', '') : '' ?>"


                    placeholder="0,000">


            </td>


            <td>


                <input type="number" step="0.01" min="0" id="mp_<?= $pid ?>"


                    value="<?= $vmp > 0 ?number_format($vmp, 2, '.', '') : '' ?>"


                    placeholder="0,00"


                    oninput="recalcAtacadoConvencional(<?= $pid ?>)">


            </td>


            <td>
                <input type="text" id="unidade_<?= $pid ?>"
                    value="<?= htmlspecialchars($unidade) ?>"
                    placeholder="kg">
            </td>


            <td>


                <input type="number" step="0.01" min="0.01" id="kgc_<?= $pid ?>"


                    value="<?= number_format($kgc, 2, '.', '') ?>"


                    placeholder="20"


                    oninput="recalcAtacadoConvencional(<?= $pid ?>)">


            </td>


            <td class="optional-col"><span id="ac35_<?= $pid ?>" class="calc-val">

                <?= $calc ?fmt_php($calc['acrescimo_35']) : '-' ?>

            </span></td>

            <td><input type="number" step="0.01" min="0" id="fk_input_<?= $pid ?>" value="<?= $calc ?number_format((float) $calc['frete_kauavuti'], 2, '.', '') : '' ?>" oninput="recalcAtacadoConvencional(<?= $pid ?>)"><span id="fk_<?= $pid ?>" class="calc-val" style="display:none;"></span></td>


            <td><input type="number" step="0.01" min="0" id="fn_input_<?= $pid ?>" value="<?= $calc ?number_format((float) $calc['frete_nivaldo'], 2, '.', '') : '' ?>" oninput="recalcAtacadoConvencional(<?= $pid ?>)"><span id="fn_<?= $pid ?>" class="calc-val" style="display:none;"></span></td>


            <td><span id="p5_<?= $pid ?>" class="calc-val preco-base">


                <?= $calc ?fmt_php($calc['prazo_5_dias']) : '-' ?>


            </span></td>


            <td><span id="p30_<?= $pid ?>" class="calc-val preco-final">

                <?= $calc ?fmt_php($calc['prazo_30_dias']) : '-' ?>

            </span></td>

            <td class="col-ajustado"><span id="p5adj_<?= $pid ?>" class="calc-val" style="color:#e65100;font-weight:700;">-</span></td>

            <td class="col-ajustado"><span id="p30adj_<?= $pid ?>" class="calc-val" style="color:#e65100;font-weight:700;">-</span></td>

            <td>

                <select id="disp_<?= $pid ?>">


                    <option value="1" <?= $disp ?'selected' : '' ?>>Disponível</option>


                    <option value="0" <?= !$disp ?'selected' : '' ?>>Indisponível</option>


                </select>


            </td>


            <td style="white-space:nowrap;" class="print-hidden">


                <button id="btn_<?= $pid ?>" class="btn-salvar"


                    onclick="salvarAtacadoConvencional(<?= $pid ?>)" title="Salvar"><i class="bi bi-check-lg"></i></button>


                <button type="button" class="btn-excluir-produto-tabela" onclick="excluirProdutoTabela(<?= $pid ?>, <?= htmlspecialchars(json_encode((string) $p['nome'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>)" title="Excluir produto"><i class="bi bi-trash"></i></button>
                <span id="stat_<?= $pid ?>" class="save-status"></span>


            </td>


        </tr>


        <?php endforeach; ?>


        </tbody>


    </table>


    </div>


    <div class="availability-print-header">


        <div class="print-logos">


            <div class="print-logo-slot convencional-full"><img class="logo-convencional" src="../assets/img/disponibilidade/convencional_banner.png" alt="Grupo AgroColitti Frutas e Legumes Premium"></div>


        </div>


        <div class="print-title">TABELA DE DISPONIBILIDADE - <?= date('d/m/Y') ?></div>


    </div>


    <div class="print-commercial">


        <table>


            <thead>


                <tr>


                    <th>PRODUTO</th>


                    <th>CAT</th>


                    <th>UNIDADE</th>


                    <th>DISPONIBILIDADE</th>


                    <th>PRAZO 5 DIAS</th>


                    <th>PRAZO 30 DIAS</th>


                </tr>


            </thead>


            <tbody>


                <?php foreach ($produtos as $p):


                    $vmpPrint = (float) ($p['valor_mp'] ?? 0);


                    $kgcPrint = (float) ($p['kg_caixa'] ?? 20);


                    $freteKauautiPrint = (float) ($p['frete_kauauti'] ?? 0);

                    $freteNivaldoPrint = (float) ($p['frete_nivaldo'] ?? 0);

                    $calcPrint = ($vmpPrint > 0 || $kgcPrint > 0) ?calcAtacadoConvencionalManual($vmpPrint, $freteKauautiPrint, $freteNivaldoPrint, $cfg) : null;

                    $dispPrint = (int) ($p['disponivel'] ?? 1);


                ?>


                <tr>


                    <td><?= htmlspecialchars($p['nome']) ?></td>


                    <td><?= htmlspecialchars((string) ($p['categoria'] ?? '')) ?></td>


                    <td><?= htmlspecialchars((string) (($p['unidade_comercial'] ?? '') !== '' ? $p['unidade_comercial'] : ($p['unidade'] ?? 'kg'))) ?></td>


                    <td><?= $dispPrint ?'Disponível' : 'Indisponível' ?></td>


                    <td class="num"><?= $calcPrint ?fmt_php($calcPrint['prazo_5_dias']) : '-' ?></td>


                    <td class="num"><?= $calcPrint ?fmt_php($calcPrint['prazo_30_dias']) : '-' ?></td>


                </tr>


                <?php endforeach; ?>


            </tbody>


        </table>


    </div>





    <div id="atacadoConvencionalEmpty" class="table-empty">Nenhum produto encontrado com os filtros atuais.</div>





    <div class="pagination">


        <div id="atacadoConvencionalPaginacaoInfo" class="pagination-info">Página 0 de 0</div>


        <div id="atacadoConvencionalPaginacao" class="pagination-actions"></div>


    </div>





    <p class="no-print" style="margin-top:14px;font-size:12px;color:#888;">

        * Na tela de <a href="../vendas/vendas.php?tipo_comercial=atacado_convencional" style="color:#1b5e20;">Nova Venda</a>, o Atacado Convencional usa o

        <strong>prazo selecionado</strong>. Selecione um cliente acima para ver o preço com desconto/acréscimo financeiro aplicado.

    </p>

</div>



<!-- -- SEÇÃO 2: Percentual Financeiro por Cliente ----------------------------- -->

<div class="card clientes-card">

    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">

        <div>

            <h2 class="section-title" style="margin-bottom:4px;">Desconto / Acréscimo Financeiro por Cliente</h2>

            <p class="descricao" style="margin-bottom:0;">Configure o percentual financeiro de cada cliente para o Atacado Convencional.</p>

        </div>

        <button type="button" id="atacadoConvencionalClientesToggle" class="collapsible-toggle" aria-expanded="false">

            <span>Mostrar clientes</span>

            <span class="chevron">v</span>

        </button>

    </div>



    <div id="atacadoConvencionalClientesContent" class="collapsible-content is-collapsed">



    <div class="clientes-toolbar">

        <div class="toolbar-group toolbar-search">

            <label for="atacadoConvencionalClientesBusca">Buscar cliente</label>

            <input type="text" id="atacadoConvencionalClientesBusca" placeholder="Digite parte do nome do cliente">

        </div>

        <button type="button" id="atacadoConvencionalClientesFiltroAjuste" class="btn-filtro-ajuste" title="Mostrar somente clientes com desconto ou acréscimo">

            <i class="bi bi-funnel"></i> Somente com ajuste

        </button>

        <button type="button" id="btnAddClienteConv" class="btn-add-cliente-sec" onclick="togglePainelAddClienteConv()">

            <i class="bi bi-person-plus-fill"></i> Adicionar cliente

        </button>

        <span id="atacadoConvencionalClientesResumo" style="font-size:12px;color:#555;align-self:center;white-space:nowrap;"></span>

    </div>

    <div id="painelAddClienteConv" class="addcli-panel">

        <label><i class="bi bi-search"></i> Selecione o cliente para adicionar</label>

        <input type="text" class="addcli-busca" id="addConvBusca"
               placeholder="Buscar cliente..." oninput="renderListaAddConv(this.value)">

        <div class="addcli-lista" id="addConvLista"></div>

    </div>



    <div class="table-wrap">

    <table style="min-width:auto;">

        <thead>

            <tr>

                <th>Cliente</th>

                <th>Percentual (%)</th>

                                <th>Preço Base x Ajuste (exemplo R$ 10,00)</th>

                <th>Ação</th>

            </tr>

        </thead>

        <tbody id="atacadoConvencionalClientesTbody">

        <?php foreach ($clientes as $c):

            $cid  = (int)   $c['id'];

            $pct  = (float) ($c['percentual'] ?? 0);

            $tipo = $c['tipo_aplicacao'] ?? 'acrescimo';

            $exemploBase = 10.00;

            $exemploFinal = aplicarPercentualFinanceiro($exemploBase, $pct, $tipo);

        ?>

        <tr data-cliente-row="1" data-cid="<?= $cid ?>">

            <td class="cli-nome-cell" data-cid="<?= $cid ?>" data-nome="<?= htmlspecialchars($c['nome'], ENT_QUOTES) ?>" onclick="abrirSubstituirConv(this)" title="Clique para substituir por outro cliente"><span class="cli-nome-text"><?= htmlspecialchars($c['nome']) ?></span></td>

            <td>

                <input type="number" step="0.01" min="0" max="100" class="input-pct"

                    id="pct_<?= $cid ?>"

                    value="<?= number_format($pct * 100, 2, '.', '') ?>"

                    placeholder="0,00">

                <span style="font-size:12px;color:#555;margin-left:3px;">%</span>

            </td>



            <td style="color:#555;font-size:12px;">

                <?= fmt_php($exemploBase) ?> ->

                <strong><?= fmt_php($exemploFinal) ?></strong>

                <?php if ($pct > 0): ?>

                    <span style="color:<?= $tipo === 'desconto' ? '#c62828' : '#2e7d32' ?>;">

                        (<?= $tipo === 'desconto' ? '-' : '+' ?><?= number_format($pct * 100, 2, ',', '') ?>%)

                    </span>

                <?php else: ?>

                    <span style="color:#999;">(sem ajuste)</span>

                <?php endif; ?>

            </td>

            <td style="white-space:nowrap;" class="print-hidden">

                <button id="pbtn_<?= $cid ?>" class="btn-salvar"

                    onclick="salvarPercentualConvencional(<?= $cid ?>)" title="Salvar"><i class="bi bi-check-lg"></i></button>

                <button type="button" class="btn-excluir-cli" onclick="removerClienteConv(<?= $cid ?>)" title="Remover cliente desta tabela"><i class="bi bi-trash"></i></button>

                <span id="pstat_<?= $cid ?>" class="save-status"></span>

            </td>

        </tr>

        <?php endforeach; ?>

        </tbody>

    </table>

    </div>

    <div id="atacadoConvencionalClientesEmpty" class="table-empty">Nenhum cliente encontrado com a busca atual.</div>

    </div>

</div>



</div>

<script src="exportacao_financeira_modal.js"></script>
<?php renderModalAdicionarProdutoTabela('atacado_convencional'); ?>
</body>

</html>


<?php


function fmt_php(float $v): string {

    return 'R$ ' . number_format($v, 2, ',', '.');

}



function calcAtacadoConvencionalManual(float $valor_mp, float $frete_kauauti, float $frete_nivaldo, array $cfg): array

{

    $acrescimo35 = $valor_mp * (1 + ($cfg['percentual_acrescimo_atacado'] / 100));

    $prazo5 = $acrescimo35

        + $frete_kauauti

        + ($frete_nivaldo * ($cfg['percentual_prazo_5'] / 100))

        + $frete_kauauti

        + $frete_nivaldo;



    return [

        'acrescimo_35' => round($acrescimo35, 4),

        'frete_kauavuti' => round($frete_kauauti, 4),

        'frete_nivaldo' => round($frete_nivaldo, 4),

        'prazo_5_dias' => round($prazo5, 2),

        'prazo_30_dias' => round($prazo5 * (1 + ($cfg['percentual_prazo_30'] / 100)), 2),

    ];

}

?>




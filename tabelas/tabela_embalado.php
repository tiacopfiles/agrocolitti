<?php

require '../auth/proteger.php';

require '../config/conexao.php';

require '../config/ciclo_helper.php';

require '../config/layout_helper.php';

require '../config/calculos_preco.php';

require '../config/permissions.php';
require __DIR__ . '/produto_modal_helper.php';



requireModule('tabelas', '../index.php');



// Tabelas preco_embalado e cliente_percentual_financeiro criadas via database/migrations.sql.



$cicloAtivo = getCicloAtivo($conexao);

$cfg        = getConfigPrecificacao($conexao);



// Produtos com preços

$resProdutos = $conexao->query("

    SELECT p.id, p.nome,

           pe.gramagem, pe.valor_mp, pe.qtd_por_caixa, pe.categoria,

           pe.custo_mp, pe.frete_kauauti, pe.frete_nivaldo,

           pe.mao_de_obra, pe.preco_calculado,

           pe.custo_p_grama, pe.custo_bd, pe.kg_da_caixa,

           CASE WHEN pe.gramagem > 0 AND pe.valor_mp > 0 THEN COALESCE(pe.ativo, 1) ELSE 0 END AS disponivel

    FROM   produtos p

    LEFT   JOIN preco_embalado pe ON pe.produto_id = p.id

    WHERE  p.ativo = 1
      AND  (p.produto_principal_id IS NULL OR pe.produto_id IS NOT NULL)

    ORDER  BY p.nome

");

$produtos = $resProdutos ? $resProdutos->fetch_all(MYSQLI_ASSOC) : [];



// Clientes com percentual financeiro - somente os da aba EMBALADO

$resClientes = $conexao->query("

    SELECT c.id, c.nome,

           cpf.percentual,

           cpf.tipo_aplicacao

    FROM   clientes c

    INNER  JOIN cliente_percentual_financeiro cpf ON cpf.cliente_id = c.id

    WHERE  c.ativo = 1

    AND    cpf.tabela = 'embalado'

    ORDER  BY c.nome

");

$clientes = $resClientes ? $resClientes->fetch_all(MYSQLI_ASSOC) : [];

// Todos os clientes ativos para painel de adicionar novo
$resTodosCliEmb = $conexao->query("SELECT id, nome FROM clientes WHERE ativo=1 ORDER BY nome");
$todosCliEmb = $resTodosCliEmb ? $resTodosCliEmb->fetch_all(MYSQLI_ASSOC) : [];

// Todos os clientes ativos (para coluna dinâmica na tabela)
$resTodosClientes = $conexao->query("
    SELECT c.id, c.nome,
           COALESCE(cpf.percentual, 0) AS percentual,
           COALESCE(cpf.tipo_aplicacao, 'acrescimo') AS tipo_aplicacao
    FROM clientes c
    LEFT JOIN cliente_percentual_financeiro cpf
           ON cpf.cliente_id = c.id AND cpf.tabela = 'embalado'
    WHERE c.ativo = 1
    ORDER BY c.nome
");
$todosClientes = $resTodosClientes ? $resTodosClientes->fetch_all(MYSQLI_ASSOC) : [];



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

<title>Tabela Embalado</title>

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

table { width: 100%; border-collapse: collapse; min-width: 1220px; }

#embaladoTable.is-compact-columns { min-width: 960px; table-layout: auto; }

#embaladoTable.is-compact-columns .optional-col { display: none; }

th { background: #1b5e20; color: #fff; padding: 10px 8px; font-size: 12px; white-space: nowrap; text-align: left; line-height: 1.3; }

th.editavel { background: #2e7d32; }

th.calculado { background: #4a148c; }

th.preco-col { background: #b71c1c; }

td { padding: 8px 8px; border-bottom: 1px solid #eee; font-size: 12px; vertical-align: middle; line-height: 1.3; }

tr:hover td { background: #f1f8f4; }

tr.sem-preco td:first-child::after { content: ' - sem preço'; color: #e65100; font-size: 11px; font-weight: 700; }

tr.indisponivel td:first-child { color: #999; text-decoration: line-through; }

input[type=number] { width: 84px; min-height: 34px; padding: 6px 8px; border: 1px solid #ccc; border-radius: 5px; font-size: 12px; }

input[type=text] { width: 104px; min-height: 34px; padding: 6px 8px; border: 1px solid #ccc; border-radius: 5px; font-size: 12px; }

input[type=number]:focus, input[type=text]:focus { outline: none; border-color: #2e7d32; box-shadow: 0 0 0 2px rgba(46,125,50,.15); }

.input-pct { width: 84px; }

.cliente-nome-cell { cursor:pointer; color:#0d47a1; font-weight:600; }
.cliente-nome-cell:hover { text-decoration:underline; }
.cliente-substituir-select { min-width:260px; max-width:100%; height:34px; border:1px solid #cfd8dc; border-radius:6px; padding:0 8px; }

select { min-height: 34px; padding: 6px 8px; border: 1px solid #ccc; border-radius: 5px; font-size: 12px; background: #fff; }

.calc-val { font-weight: 600; font-size: 12px; white-space: nowrap; color: #333; }

.preco-base { color: #4a148c; font-size: 13px; font-weight: 700; }

.preco-final { color: #b71c1c; font-size: 13px; font-weight: 700; }

.btn-salvar { background:#2e7d32; color:#fff; border:none; width:34px; height:34px; padding:0; border-radius:6px; cursor:pointer; font-weight:700; font-size:15px; display:inline-flex; align-items:center; justify-content:center; line-height:1; }

.btn-salvar:hover { background: #1b5e20; }

.btn-salvar:disabled { background: #aaa; cursor: not-allowed; }
.btn-excluir-cliente { background:#c62828; color:#fff; border:none; width:34px; height:34px; padding:0; border-radius:6px; cursor:pointer; font-weight:700; font-size:15px; display:inline-flex; align-items:center; justify-content:center; line-height:1; margin-left:6px; }
.btn-excluir-cliente:hover { background:#8e0000; }
.btn-excluir-cliente:disabled { background:#aaa; cursor:not-allowed; }

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

    .app-header, .toolbar, .toolbar-meta, .pagination, .table-empty, .legend, .descricao, .section-title, .msg-sucesso, .formula-box, .print-hidden, .no-print, .print-area, .clientes-card { display: none !important; }

    .container { padding: 0 !important; max-width: none !important; width: 100% !important; }

    .card { box-shadow: none !important; border-radius: 0 !important; padding: 0 !important; margin: 0 !important; }

    .availability-print-header { display:block !important; margin: 0 0 10px !important; page-break-inside: avoid; }

    .availability-print-header .print-logos { display:grid; grid-template-columns: 1fr 1fr 1fr; align-items:center; gap:12px; margin-bottom:8px; }

    .availability-print-header .print-logo { min-height:56px; display:flex; align-items:center; justify-content:center; }

    .availability-print-header .print-logo:first-child { justify-content:flex-start; }

    .availability-print-header .print-logo:last-child { justify-content:flex-end; }

    .availability-print-header img { max-height:58px; max-width:160px; object-fit:contain; }

    .availability-print-header .print-title { text-align:center; font-weight:700; font-size:14px; margin:4px 0 2px; color:#111; }

    .availability-print-header .print-subtitle { text-align:center; font-size:10px; color:#333; margin:0 0 6px; }

    .print-commercial { display:block !important; width:100% !important; }

    .print-commercial table { width:100% !important; min-width:0 !important; border-collapse:collapse !important; table-layout:fixed; }

    .print-commercial th { background:#c6e0b4 !important; color:#111 !important; font-weight:700 !important; border:1px solid #333 !important; padding:5px 6px !important; font-size:10px !important; text-align:center !important; }

    .print-commercial td { border:1px solid #333 !important; padding:4px 6px !important; font-size:10px !important; color:#111 !important; background:#fff !important; line-height:1.2 !important; }

    .print-commercial .num { text-align:right !important; white-space:nowrap; }

}

.collapsible-toggle { display: inline-flex; align-items: center; gap: 8px; background: #f1f8e9; color: #1b5e20; border: 1px solid #c5e1a5; padding: 8px 12px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 13px; }

.collapsible-toggle:hover { background: #dcedc8; }

.collapsible-toggle .chevron { font-size: 12px; transition: transform .2s ease; }

.collapsible-toggle[aria-expanded="true"] .chevron { transform: rotate(180deg); }

.collapsible-content.is-collapsed { display: none; }

.clientes-toolbar { display: flex; justify-content: space-between; align-items: end; gap: 12px; flex-wrap: wrap; margin: 14px 0 12px; }

.clientes-toolbar .toolbar-search { flex: 1 1 640px; min-width: 540px; max-width: 860px; }

.btn-filtro-ajuste { background:#e65100; color:#fff; border:none; padding:8px 14px; border-radius:6px; cursor:pointer; font-size:13px; font-weight:700; white-space:nowrap; display:inline-flex; align-items:center; gap:6px; }

.btn-filtro-ajuste:hover { background:#bf360c; }

.btn-filtro-ajuste.ativo { background:#1b5e20; }

.btn-filtro-ajuste.ativo:hover { background:#2e7d32; }

.clientes-toolbar .toolbar-search input { width: 100% !important; }

/* ── Botão Adicionar Cliente na tabela ─────────────────── */
.btn-add-cliente { background:#00695c; color:#fff; border:none; height:34px; padding:0 12px; border-radius:6px; cursor:pointer; font-size:13px; font-weight:700; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
.btn-add-cliente:hover { background:#004d40; }

/* ── Modal ─────────────────────────────────────────────── */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.48); z-index:2000; align-items:center; justify-content:center; }
.modal-overlay.ativo { display:flex; }
.modal-box { background:#fff; border-radius:12px; padding:22px 20px 18px; width:100%; max-width:460px; box-shadow:0 10px 36px rgba(0,0,0,.25); position:relative; max-height:82vh; display:flex; flex-direction:column; gap:0; }
.modal-titulo { margin:0 28px 12px 0; font-size:16px; color:#004d40; font-weight:700; }
.modal-busca { width:100% !important; margin-bottom:10px !important; font-size:13px !important; }
.modal-lista { overflow-y:auto; flex:1; border:1px solid #e0e0e0; border-radius:6px; }
.modal-item { padding:10px 12px; cursor:pointer; font-size:13px; border-bottom:1px solid #f0f0f0; display:flex; justify-content:space-between; align-items:center; gap:8px; }
.modal-item:last-child { border-bottom:none; }
.modal-item:hover { background:#e0f2f1; }
.modal-item.ja-adicionado { opacity:.45; cursor:not-allowed; background:#f9f9f9; }
.modal-item .pct-badge { font-size:11px; font-weight:700; padding:2px 7px; border-radius:4px; white-space:nowrap; flex-shrink:0; }
.modal-item .pct-badge.com-pct { background:#e8f5e9; color:#1b5e20; }
.modal-item .pct-badge.sem-pct { background:#f5f5f5; color:#9e9e9e; }
.modal-item .modal-item-nome { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.modal-btn-fechar { position:absolute; top:12px; right:14px; background:none; border:none; font-size:22px; cursor:pointer; color:#777; line-height:1; padding:0; }
.modal-btn-fechar:hover { color:#c62828; }
.modal-vazio { padding:14px 12px; text-align:center; color:#999; font-size:13px; }

/* ── Painel adicionar cliente na seção de desconto ─── */
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

/* ── Colunas dinâmicas de cliente ──────────────────────── */
th.cliente-col { background:#00695c; color:#fff; white-space:nowrap; }
th.cliente-col .btn-remover-col { background:rgba(255,255,255,.22); border:none; color:#fff; width:18px; height:18px; border-radius:3px; cursor:pointer; font-size:13px; line-height:1; display:inline-flex; align-items:center; justify-content:center; margin-left:5px; padding:0; vertical-align:middle; flex-shrink:0; }
th.cliente-col .btn-remover-col:hover { background:rgba(255,255,255,.45); }
td.cliente-preco { color:#00695c; font-weight:700; font-size:12px; white-space:nowrap; }

@media (min-width: 1500px) {

    .container { max-width: 1920px; padding-left: 18px; padding-right: 18px; }

    .card { padding-left: 18px; padding-right: 18px; }

    table { min-width: 1180px; }

    th { padding: 9px 7px; font-size: 11.5px; }

    td { padding: 7px 7px; font-size: 11.5px; }

    input[type=number] { width: 78px; min-height: 32px; }

    input[type=text] { width: 96px; min-height: 32px; }

    .input-pct { width: 78px; }

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

// -- Dados de clientes para colunas dinâmicas ----------------------------------
const TODOS_CLIENTES_EMBALADO = <?= json_encode(array_map(fn($c) => [
    'id'            => (int)   $c['id'],
    'nome'          => $c['nome'],
    'percentual'    => (float) $c['percentual'],
    'tipo_aplicacao'=> $c['tipo_aplicacao'],
], $todosClientes), JSON_UNESCAPED_UNICODE) ?>;

// -- Configuração espelhada do PHP (getConfigPrecificacao defaults) -------------

const CFG = {

    frete_total_kauavuti:      6.5,

    divisor_kauavuti_embalado: 20.0,

    frete_nivaldo_base:        0.30,

    custo_fixo_embalado:       1.25,

};



/**

 * Espelho de calcEmbalado() - mesma lógica do PHP.

 * Inclui todos os campos mostrados na planilha (colunas B...N).

 */

function calcEmbadadoJS(gramagem, valor_mp, qtd_por_caixa, frete_kauavuti_manual, frete_nivaldo_manual) {

    // D: valor_materia_x_gramagem

    const valor_materia_x_gramagem = valor_mp * gramagem;



    // E: custo_p_grama = valor_mp / 1000 * 10

    const custo_p_grama = (valor_mp / 1000) * 10;



    // G: custo_bd = custo_p_grama * gramagem * 100

    const custo_bd = custo_p_grama * gramagem * 100;



    // I: kg_da_caixa = qtd_por_caixa * gramagem

    const kg_da_caixa = qtd_por_caixa * gramagem;



    // J: frete_kauavuti = frete_total / divisor

    const frete_kauavuti = frete_kauavuti_manual;



    // K: frete_nivaldo = base * gramagem

    const frete_nivaldo = frete_nivaldo_manual;



    // L: custo_fixo

    const custo_fixo = CFG.custo_fixo_embalado;



    // N: preco_produto_base = 2*D + J + K + L (2x material cost per Excel formula)

    const preco_produto_base = Math.round(

        (2 * valor_materia_x_gramagem + frete_kauavuti + frete_nivaldo + custo_fixo) * 100

    ) / 100;



    return {

        valor_materia_x_gramagem,

        custo_p_grama,

        custo_bd,

        kg_da_caixa,

        frete_kauavuti,

        frete_nivaldo,

        custo_fixo,

        preco_produto_base,

    };

}



function roundTo(value, casas) {

    const fator = 10 ** casas;

    return Math.round(value * fator) / fator;

}



function roundInt(value) {

    return Math.round(value);

}



function fmt(v) {

    return 'R$ ' + v.toFixed(2).replace('.', ',');

}

function fmtSmall(v, casas) {

    return v.toFixed(casas ?? 4).replace('.', ',');

}



function recalcEmbalado(pid) {

    const gram = parseFloat(document.getElementById('gram_' + pid).value) || 0;

    const mp   = parseFloat(document.getElementById('mp_'   + pid).value) || 0;

    const qtd  = parseFloat(document.getElementById('qtd_'  + pid).value) || 0;

    const fkEl = document.getElementById('fk_input_' + pid);

    const fnEl = document.getElementById('fn_input_' + pid);

    const fk = fkEl && fkEl.value !== '' ? parseFloat(fkEl.value) || 0 : (CFG.divisor_kauavuti_embalado > 0 ? CFG.frete_total_kauavuti / CFG.divisor_kauavuti_embalado : 0);

    const fn = fnEl && fnEl.value !== '' ? parseFloat(fnEl.value) || 0 : (CFG.frete_nivaldo_base * gram);



    const ids = ['vmg_','cpg_','cbd_','kgc_','fk_','fn_','mob_','preco_base_'];

    if (gram <= 0 || mp <= 0) {

        ids.forEach(p => {

            const el = document.getElementById(p + pid);

            if (el) el.textContent = '-';

        });

        return;

    }



    const c = calcEmbadadoJS(gram, mp, qtd, fk, fn);



    const set = (id, val, casas) => {

        const el = document.getElementById(id + pid);

        if (el) el.textContent = casas !== undefined ? fmtSmall(val, casas) : fmt(val);

    };



    set('vmg_',       roundTo(c.valor_materia_x_gramagem, 2));

    set('cpg_',       roundTo(c.custo_p_grama, 4), 4);

    set('cbd_',       roundTo(c.custo_bd, 2));

    const kgCaixaEl = document.getElementById('kgc_' + pid);

    if (kgCaixaEl) kgCaixaEl.textContent = String(roundInt(c.kg_da_caixa));

    set('fk_',        roundTo(c.frete_kauavuti, 2));

    set('fn_',        roundTo(c.frete_nivaldo, 2));

    set('mob_',       roundTo(c.custo_fixo, 2));

    set('preco_base_', c.preco_produto_base);

    // Atualiza colunas de clientes adicionadas dinamicamente
    atualizarColunasClienteParaProduto(pid, c.preco_produto_base);

}



// ── Gestão de colunas dinâmicas de clientes ───────────────────────────────────

const _clientesAdicionados = {}; // id -> { nome, percentual, tipo }

function aplicarPctCliente(precoBase, percentual, tipo) {
    if (precoBase <= 0) return 0;
    if (tipo === 'desconto') return precoBase * (1 - percentual);
    return precoBase * (1 + percentual);
}

function lerPrecoBase(pid) {
    const el = document.getElementById('preco_base_' + pid);
    if (!el) return 0;
    return parseFloat(el.textContent.replace(/[R$\s.]/g, '').replace(',', '.')) || 0;
}

function atualizarColunasClienteParaProduto(pid, precoBase) {
    Object.entries(_clientesAdicionados).forEach(([cid, c]) => {
        const td = document.getElementById(`preco_cli_${cid}_${pid}`);
        if (!td) return;
        const pf = aplicarPctCliente(precoBase, c.percentual, c.tipo);
        td.textContent = precoBase > 0 ? fmt(pf) : '-';
    });
}

function adicionarColunaCliente(id, nome, percentual, tipo) {
    if (_clientesAdicionados[id]) return; // já existe
    _clientesAdicionados[id] = { nome, percentual, tipo };

    // Cabeçalho: inserir após "N - Preço Base"
    const thPrecoBase = document.getElementById('th-preco-base');
    if (thPrecoBase) {
        const th = document.createElement('th');
        th.className = 'cliente-col';
        th.id = `th-cliente-${id}`;
        const pctLabel = percentual > 0
            ? ` <small style="font-weight:400;opacity:.85;">(${tipo === 'desconto' ? '-' : '+'}${(percentual*100).toFixed(1)}%)</small>`
            : '';
        th.innerHTML = `${nome}${pctLabel} <button class="btn-remover-col" onclick="removerColunaCliente(${id})" title="Remover coluna">×</button>`;
        thPrecoBase.insertAdjacentElement('afterend', th);
    }

    // TD em cada linha de produto
    document.querySelectorAll('[id^="preco_base_"]').forEach(span => {
        const pid = span.id.replace('preco_base_', '');
        const precoBase = lerPrecoBase(pid);
        const precoFinal = aplicarPctCliente(precoBase, percentual, tipo);

        const td = document.createElement('td');
        td.className = 'cliente-preco';
        td.id = `preco_cli_${id}_${pid}`;
        td.textContent = precoBase > 0 ? fmt(precoFinal) : '-';

        span.closest('td').insertAdjacentElement('afterend', td);
    });
}

function removerColunaCliente(id) {
    delete _clientesAdicionados[id];

    const th = document.getElementById(`th-cliente-${id}`);
    if (th) th.remove();

    document.querySelectorAll(`[id^="preco_cli_${id}_"]`).forEach(td => td.remove());

    // Reabrir item no modal se estiver aberto
    const item = document.querySelector(`.modal-item[data-cliente-id="${id}"]`);
    if (item) item.classList.remove('ja-adicionado');
}

function abrirModalAdicionarCliente() {
    const overlay = document.getElementById('modalAdicionarCliente');
    if (!overlay) return;
    const busca = document.getElementById('modalClienteBusca');
    if (busca) { busca.value = ''; }
    renderModalLista('');
    overlay.classList.add('ativo');
    busca?.focus();
}

function fecharModalAdicionarCliente() {
    const overlay = document.getElementById('modalAdicionarCliente');
    if (overlay) overlay.classList.remove('ativo');
}

function renderModalLista(termo) {
    const lista = document.getElementById('modalClienteLista');
    if (!lista) return;
    const t = (termo || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

    const filtrados = TODOS_CLIENTES_EMBALADO.filter(c => {
        if (!t) return true;
        return c.nome.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().includes(t);
    });

    if (filtrados.length === 0) {
        lista.innerHTML = '<div class="modal-vazio">Nenhum cliente encontrado.</div>';
        return;
    }

    lista.innerHTML = filtrados.map(c => {
        const jaAdicionado = !!_clientesAdicionados[c.id];
        const pctValor = (c.percentual * 100).toFixed(1);
        const pctText  = c.percentual > 0
            ? `${c.tipo_aplicacao === 'desconto' ? '-' : '+'}${pctValor}%`
            : 'sem %';
        const pctClass = c.percentual > 0 ? 'com-pct' : 'sem-pct';
        return `<div class="modal-item${jaAdicionado ? ' ja-adicionado' : ''}" data-cliente-id="${c.id}"
                     ${jaAdicionado ? '' : `onclick="selecionarClienteModal(${c.id}, ${JSON.stringify(c.nome)}, ${c.percentual}, ${JSON.stringify(c.tipo_aplicacao)})"`}>
                    <span class="modal-item-nome">${c.nome}</span>
                    <span class="pct-badge ${pctClass}">${pctText}</span>
                </div>`;
    }).join('');
}

function selecionarClienteModal(id, nome, percentual, tipo) {
    adicionarColunaCliente(id, nome, percentual, tipo);
    // Marca como adicionado no modal
    const item = document.querySelector(`.modal-item[data-cliente-id="${id}"]`);
    if (item) {
        item.classList.add('ja-adicionado');
        item.onclick = null;
    }
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') fecharModalAdicionarCliente();
});



async function salvarEmbalado(pid) {

    const btn  = document.getElementById('btn_'  + pid);

    const stat = document.getElementById('stat_' + pid);

    const gram = document.getElementById('gram_' + pid).value;

    const mp   = document.getElementById('mp_'   + pid).value;

    const qtd  = document.getElementById('qtd_'  + pid).value;

    const cat  = document.getElementById('cat_'  + pid).value;

    const fk   = document.getElementById('fk_input_' + pid).value;

    const fn   = document.getElementById('fn_input_' + pid).value;

    const disp = document.getElementById('disp_' + pid).value;



    if (!gram || !mp || parseFloat(gram) <= 0 || parseFloat(mp) < 0) {

        stat.textContent = 'Erro Preencha gramagem e valor MP';

        stat.className = 'save-status save-err';

        return;

    }



    btn.disabled = true;

    stat.textContent = 'Salvando...';

    stat.className = 'save-status';



    try {

        const fd = new FormData();

        fd.append('produto_id',    pid);

        fd.append('gramagem',      gram);

        fd.append('valor_mp',      mp);

        fd.append('qtd_por_caixa', qtd);

        fd.append('categoria',     cat);

        fd.append('frete_kauauti', fk);

        fd.append('frete_nivaldo', fn);

        fd.append('ativo',         disp);



        const r = await fetch('actions/salvar_preco_embalado.php', { method: 'POST', body: fd });

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



// ── Painel: Adicionar novo cliente na seção de desconto/acréscimo ─────────────
const _EMB_TODOS_CLI = <?= json_encode(array_map(fn($c)=>['id'=>(int)$c['id'],'nome'=>$c['nome']], $todosCliEmb), JSON_UNESCAPED_UNICODE) ?>;
const _embJaAdicionados = new Set(<?= json_encode(array_column($clientes, 'id')) ?>);

function escapeHtmlClienteEmb(texto) {
    return String(texto || '').replace(/[&<>"']/g, ch => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[ch]));
}

function clienteNomeEmb(cid) {
    return (_EMB_TODOS_CLI.find(c => Number(c.id) === Number(cid)) || {}).nome || '';
}

async function salvarPercentualClienteEmb(cid, pct) {
    const fd = new FormData();
    fd.append('cliente_id', cid);
    fd.append('percentual', pct);
    fd.append('tipo_aplicacao', 'acrescimo');
    fd.append('tabela', 'embalado');
    const r = await fetch('actions/salvar_percentual_cliente.php', { method: 'POST', body: fd });
    return await r.json();
}

function montarLinhaClienteEmb(cid, nome, pctValor) {
    const pctNum = parseFloat(pctValor || '0') || 0;
    const final = 10 * (1 + (pctNum / 100));
    return `
        <td class="cliente-nome-cell" data-cliente-cell="${cid}" onclick="abrirSubstituirClienteEmb(${cid})" title="Clique para substituir cliente">${escapeHtmlClienteEmb(nome)}</td>
        <td>
            <input type="number" step="0.01" min="0" max="100" class="input-pct"
                   id="pct_${cid}" value="${pctNum.toFixed(2)}" placeholder="0,00"
                   oninput="atualizarExemploEmb(${cid})">
            <span style="font-size:12px;color:#555;margin-left:3px;">%</span>
        </td>
        <td id="exemploEmb_${cid}" style="color:#555;font-size:12px;">
            R$ 10,00 -&gt; <strong>R$ ${final.toFixed(2).replace('.', ',')}</strong>
            ${pctNum > 0 ? `<span style="color:#2e7d32;">(+${pctNum.toFixed(2).replace('.', ',')}%)</span>` : '<span style="color:#999;">(sem ajuste)</span>'}
        </td>
        <td style="white-space:nowrap;" class="print-hidden">
            <button id="pbtn_${cid}" class="btn-salvar"
                    onclick="salvarPercentual(${cid})" title="Salvar">
                <i class="bi bi-check-lg"></i>
            </button>
            <button id="delbtn_${cid}" class="btn-excluir-cliente"
                    onclick="excluirClienteEmb(${cid})" title="Remover cliente">
                <i class="bi bi-trash"></i>
            </button>
            <span id="pstat_${cid}" class="save-status"></span>
        </td>`;
}

function togglePainelAddClienteEmb() {
    const panel = document.getElementById('painelAddClienteEmb');
    const btn   = document.getElementById('btnAddClienteEmb');
    if (!panel) return;
    const ativo = panel.classList.toggle('ativo');
    btn.classList.toggle('painel-ativo', ativo);
    if (ativo) {
        const busca = document.getElementById('addEmbBusca');
        if (busca) { busca.value = ''; busca.focus(); }
        renderListaAddEmb('');
    }
}

function renderListaAddEmb(termo) {
    const lista = document.getElementById('addEmbLista');
    if (!lista) return;
    const t = (termo||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
    const disponiveis = _EMB_TODOS_CLI.filter(c => {
        if (_embJaAdicionados.has(c.id)) return false;
        if (!t) return true;
        return c.nome.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().includes(t);
    });
    if (!disponiveis.length) {
        lista.innerHTML = '<div class="addcli-vazio">Nenhum cliente disponível para adicionar.</div>';
        return;
    }
    lista.innerHTML = disponiveis.map(c =>
        `<div class="addcli-item" onclick="adicionarClienteEmb(${c.id})">${escapeHtmlClienteEmb(c.nome)}</div>`
    ).join('');
}

async function adicionarClienteEmb(cid, nome) {
    nome = nome || clienteNomeEmb(cid);
    if (_embJaAdicionados.has(cid)) return;
    _embJaAdicionados.add(cid);

    document.getElementById('painelAddClienteEmb')?.classList.remove('ativo');
    document.getElementById('btnAddClienteEmb')?.classList.remove('painel-ativo');

    try {
        const salvo = await salvarPercentualClienteEmb(cid, 0);
        if (!salvo.ok) {
            _embJaAdicionados.delete(cid);
            alert(salvo.erro || 'Nao foi possivel adicionar o cliente.');
            renderListaAddEmb(document.getElementById('addEmbBusca')?.value || '');
            return;
        }
    } catch (e) {
        _embJaAdicionados.delete(cid);
        alert('Falha na rede ao adicionar o cliente.');
        renderListaAddEmb(document.getElementById('addEmbBusca')?.value || '');
        return;
    }

    const tbody = document.getElementById('clientesTbody');
    if (!tbody) return;
    const tr = document.createElement('tr');
    tr.setAttribute('data-cliente-row', '1');
    tr.id = `cliente_row_${cid}`;
    tr.setAttribute('data-cliente-id', String(cid));
    tr.innerHTML = montarLinhaClienteEmb(cid, nome, 0);
    tbody.appendChild(tr);

    const busca = document.getElementById('clientesBusca');
    if (busca) busca.dispatchEvent(new Event('input'));

    setTimeout(() => {
        document.getElementById(`pct_${cid}`)?.focus();
        tr.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 80);
}

async function excluirClienteEmb(cid) {
    const nome = clienteNomeEmb(cid) || document.querySelector(`[data-cliente-cell="${cid}"]`)?.textContent || 'este cliente';
    if (!confirm(`Remover ${nome} da Tabela Embalado?`)) return;

    const btn = document.getElementById('delbtn_' + cid);
    const stat = document.getElementById('pstat_' + cid);
    if (btn) btn.disabled = true;
    if (stat) {
        stat.textContent = 'Removendo...';
        stat.className = 'save-status';
    }

    try {
        const fd = new FormData();
        fd.append('cliente_id', cid);
        fd.append('tabela', 'embalado');
        const r = await fetch('actions/excluir_percentual_cliente.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (!d.ok) throw new Error(d.erro || 'Erro');
        _embJaAdicionados.delete(cid);
        document.getElementById(`cliente_row_${cid}`)?.remove();
        renderListaAddEmb(document.getElementById('addEmbBusca')?.value || '');
        const busca = document.getElementById('clientesBusca');
        if (busca) busca.dispatchEvent(new Event('input'));
    } catch (e) {
        if (stat) {
            stat.textContent = 'Erro ' + (e.message || 'Falha na rede');
            stat.className = 'save-status save-err';
        }
        if (btn) btn.disabled = false;
    }
}

function abrirSubstituirClienteEmb(cid) {
    const cell = document.querySelector(`[data-cliente-cell="${cid}"]`);
    if (!cell || cell.querySelector('select')) return;
    const nomeAtual = cell.textContent;
    const select = document.createElement('select');
    select.className = 'cliente-substituir-select';
    select.innerHTML = '<option value="">Selecione o cliente</option>' + _EMB_TODOS_CLI
        .filter(c => Number(c.id) === Number(cid) || !_embJaAdicionados.has(Number(c.id)))
        .map(c => `<option value="${c.id}" ${Number(c.id) === Number(cid) ? 'selected' : ''}>${escapeHtmlClienteEmb(c.nome)}</option>`)
        .join('');
    select.onchange = () => substituirClienteEmb(cid, Number(select.value));
    select.onblur = () => {
        if (document.body.contains(select)) cell.textContent = nomeAtual;
    };
    cell.innerHTML = '';
    cell.appendChild(select);
    select.focus();
}

async function substituirClienteEmb(cidOrigem, cidDestino) {
    if (!cidDestino || cidDestino === cidOrigem || _embJaAdicionados.has(cidDestino)) {
        const cell = document.querySelector(`[data-cliente-cell="${cidOrigem}"]`);
        if (cell) cell.textContent = clienteNomeEmb(cidOrigem) || cell.textContent;
        return;
    }

    const fd = new FormData();
    fd.append('cliente_origem_id', cidOrigem);
    fd.append('cliente_destino_id', cidDestino);
    fd.append('tabela', 'embalado');

    try {
        const r = await fetch('actions/substituir_percentual_cliente.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (!d.ok) throw new Error(d.erro || 'Erro');

        const pct = parseFloat(document.getElementById('pct_' + cidOrigem)?.value || '0') || 0;
        const tr = document.getElementById(`cliente_row_${cidOrigem}`);
        if (tr) {
            tr.id = `cliente_row_${cidDestino}`;
            tr.setAttribute('data-cliente-id', String(cidDestino));
            tr.innerHTML = montarLinhaClienteEmb(cidDestino, d.cliente_nome || clienteNomeEmb(cidDestino), pct);
        }
        _embJaAdicionados.delete(cidOrigem);
        _embJaAdicionados.add(cidDestino);
        renderListaAddEmb(document.getElementById('addEmbBusca')?.value || '');
        const busca = document.getElementById('clientesBusca');
        if (busca) busca.dispatchEvent(new Event('input'));
    } catch (e) {
        alert(e.message || 'Nao foi possivel substituir o cliente.');
        const cell = document.querySelector(`[data-cliente-cell="${cidOrigem}"]`);
        if (cell) cell.textContent = clienteNomeEmb(cidOrigem) || cell.textContent;
    }
}

function atualizarExemploEmb(cid) {
    const el  = document.getElementById(`exemploEmb_${cid}`);
    const pct = parseFloat(document.getElementById(`pct_${cid}`)?.value || '0') / 100;
    if (!el) return;
    const final = 10 * (1 + pct);
    const pctStr = (pct * 100).toFixed(2).replace('.', ',');
    el.innerHTML = pct > 0
        ? `R$ 10,00 -&gt; <strong>R$ ${final.toFixed(2).replace('.', ',')}</strong> <span style="color:#2e7d32;">(+${pctStr}%)</span>`
        : `R$ 10,00 -&gt; <strong>R$ 10,00</strong> <span style="color:#999;">(sem ajuste)</span>`;
}



async function salvarPercentual(cid) {

    const btn  = document.getElementById('pbtn_'  + cid);

    const stat = document.getElementById('pstat_' + cid);

    const pct  = document.getElementById('pct_'   + cid).value;

    const tipo = 'acrescimo';



    btn.disabled = true;

    stat.textContent = 'Salvando...';

    stat.className = 'save-status';



    try {

        const d = await salvarPercentualClienteEmb(cid, pct);



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



function configurarTabelaEmbalado() {

    const tbody = document.getElementById('embaladoTbody');

    if (!tbody) return;



    const rows = Array.from(tbody.querySelectorAll('tr[data-produto-row="1"]'));

    const busca = document.getElementById('embaladoBusca');

    const disponibilidade = document.getElementById('embaladoDisponibilidade');

    const categoria = document.getElementById('embaladoCategoria');

    const preco = document.getElementById('embaladoPreco');

    const pageSize = document.getElementById('embaladoPageSize');

    const resumo = document.getElementById('embaladoResumo');

    const empty = document.getElementById('embaladoEmpty');

    const pagInfo = document.getElementById('embaladoPaginacaoInfo');

    const pagActions = document.getElementById('embaladoPaginacao');



    let paginaAtual = 1;



    function linhaTemPreco(row) {

        const gram = parseFloat(row.querySelector('input[id^="gram_"]')?.value || '0');

        const mp = parseFloat(row.querySelector('input[id^="mp_"]')?.value || '0');

        return gram > 0 && mp > 0;

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

        pagInfo.textContent = totalFiltrados > 0

            ? `Página ${paginaAtual} de ${totalPaginas}`

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

            row.style.display = pagina.has(row) ? '' : 'none';

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



function configurarBlocoClientesEmbalado() {

    const toggle = document.getElementById('clientesToggle');

    const content = document.getElementById('clientesContent');

    if (!toggle || !content) return;



    toggle.addEventListener('click', () => {

        const expandido = toggle.getAttribute('aria-expanded') === 'true';

        toggle.setAttribute('aria-expanded', expandido ? 'false' : 'true');

        content.classList.toggle('is-collapsed', expandido);

    });

}



function configurarBuscaClientesEmbalado() {

    const busca  = document.getElementById('clientesBusca');

    const filtro = document.getElementById('clientesFiltroAjuste');

    const tbody  = document.getElementById('clientesTbody');

    const empty  = document.getElementById('clientesEmpty');

    if (!busca || !tbody) return;



    const rows = Array.from(tbody.querySelectorAll('tr[data-cliente-row="1"]'));

    let apenasComAjuste = false;



    function aplicarBusca() {

        const termo = normalizarBuscaTexto(busca.value || '');

        let visiveis = 0;



        rows.forEach((row) => {

            const nome = normalizarBuscaTexto(row.querySelector('td')?.textContent || '');

            const pctInput = row.querySelector('input[id^="pct_"]');

            const temAjuste = pctInput && parseFloat(pctInput.value || '0') > 0;

            const mostrar = (!termo || nome.includes(termo)) && (!apenasComAjuste || temAjuste);

            row.style.display = mostrar ? '' : 'none';

            if (mostrar) visiveis++;

        });



        if (empty) {

            empty.style.display = visiveis === 0 ? 'block' : 'none';

        }

        const resumo = document.getElementById('clientesResumo');

        if (resumo) resumo.textContent = visiveis + ' cliente(s) encontrado(s)';

    }



    busca.addEventListener('input', aplicarBusca);



    if (filtro) {

        filtro.addEventListener('click', function() {

            apenasComAjuste = !apenasComAjuste;

            this.classList.toggle('ativo', apenasComAjuste);

            this.innerHTML = apenasComAjuste

                ? '<i class="bi bi-funnel-fill"></i> Mostrar todos'

                : '<i class="bi bi-funnel"></i> Somente com ajuste';

            aplicarBusca();

        });

    }



    aplicarBusca();

}



function configurarColunasEmbalado() {

    const table = document.getElementById('embaladoTable');

    const button = document.getElementById('toggleColunasEmbalado');

    const tableWrap = table?.closest('.table-wrap');

    if (!table || !button) return;



    const storageKey = 'agrocolitti.embalado.colunasEnxutas';



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

    configurarTabelaEmbalado();

    configurarBlocoClientesEmbalado();

    configurarBuscaClientesEmbalado();

    configurarColunasEmbalado();

});

</script>

</head>

<body>

<?php renderAppHeader('..'); ?>



<div class="container">



<?php if ($cicloAtivo): ?>

    <div class="msg-sucesso">Ciclo ativo: <?= htmlspecialchars(getNomeCiclo($cicloAtivo)) ?></div>

<?php endif; ?>



<!-- -- SEÇÃO 1: Preços dos Produtos ------------------------------------------ -->

<div class="card">

    <h2 class="section-title">Tabela de Preços - Embalado</h2>

    <p class="descricao">

        Informe os campos editáveis (fundo verde). Os demais campos são calculados automaticamente.

        <strong>Preço Base</strong> = custo MP + fretes + mão de obra.

        <strong>Preço Final por Cliente</strong> é exibido na tela de Nova Venda após aplicar o percentual financeiro do cliente (tabela abaixo).

    </p>



    <div class="legend">

        <span><span class="leg-dot" style="background:#2e7d32;"></span> Coluna editável</span>

        <span><span class="leg-dot" style="background:#4a148c;"></span> Calculado automaticamente</span>

        <span><span class="leg-dot" style="background:#b71c1c;"></span> Preço Base (resultado final)</span>

    </div>



    <div class="toolbar no-print">

        <div class="toolbar-group toolbar-search">

            <label for="embaladoBusca">Buscar produto</label>

            <input type="text" id="embaladoBusca" placeholder="Digite parte do nome do produto">

        </div>

        <div class="toolbar-filters">

            <div class="toolbar-group print-hidden">

                <label for="tabelaEmbaladoAcoes">Tabelas</label>

                <select id="tabelaEmbaladoAcoes" onchange="if(this.value) window.location.href=this.value;">

                    <option value="">Embalado</option>

                    <option value="tabela_atacado.php">Atacado</option>

                    <option value="tabela_atacado_convencional.php">Atacado Convencional</option>

                    <option value="tabela_oba_embalado.php">OBA Embalado</option>

                    <option value="tabela_shopper.php">Shopper</option>

                    <option value="criar_tabela_personalizada.php">+ Criar nova tabela</option>

                    <option value="tabelas_personalizadas.php">Editar tabelas</option>

                </select>

            </div>

            <div class="toolbar-group">

                <label for="embaladoDisponibilidade">Disponibilidade</label>

                <select id="embaladoDisponibilidade">

                    <option value="todos">Todos</option>

                    <option value="1" selected>Disponível</option>

                    <option value="0">Indisponível</option>

                </select>

            </div>

            <div class="toolbar-group">

                <label for="embaladoCategoria">Categoria</label>

                <select id="embaladoCategoria">

                    <option value="">Todas</option>

                    <?php foreach (array_keys($categoriasProdutos) as $categoriaOption): ?>

                        <option value="<?= htmlspecialchars($categoriaOption, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($categoriaOption) ?></option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="toolbar-group">

                <label for="embaladoPreco">Preço</label>

                <select id="embaladoPreco">

                    <option value="todos">Todos</option>

                    <option value="com_preco" selected>Com preço</option>

                    <option value="sem_preco">Sem preço</option>

                </select>

            </div>

            <div class="toolbar-group">

                <label for="embaladoPageSize">Itens por página</label>

                <select id="embaladoPageSize">

                    <option value="25" selected>25</option>

                    <option value="50">50</option>

                    <option value="100">100</option>

                </select>

            </div>

            <div class="toolbar-group print-hidden">

                <label>&nbsp;</label>

                <div class="action-buttons">
                    <?php renderBotaoAdicionarProdutoTabela(); ?>

                    <button type="button" id="toggleColunasEmbalado" class="column-toggle-btn" aria-pressed="false" title="Ocultar colunas calculadas"><i class="bi bi-eye-slash"></i><span>Ocultar colunas</span></button>

                    </div>

            </div>

        </div>

    </div>



    <div class="toolbar-meta">
        <div>
            <div id="embaladoResumo">0 produto(s) encontrado(s)</div>
            <div>Busca, filtros e paginação funcionam sem alterar os cálculos.</div>
        </div>
        <div class="export-actions">
            <a class="export-btn export-btn-excel" href="exportar_disponibilidade_xlsx.php?tipo=embalado" data-export-financeiro data-tipo-tabela="embalado" title="Baixar tabela em Excel"><i class="bi bi-file-earmark-excel"></i></a>
            <a class="export-btn export-btn-pdf" href="exportar_disponibilidade_pdf.php?tipo=embalado" data-export-financeiro data-tipo-tabela="embalado" title="Baixar tabela em PDF"><i class="bi bi-file-earmark-pdf"></i></a>
        </div>    </div>



    <div class="table-wrap print-area">

    <table id="embaladoTable" data-no-responsive="1">

        <thead>

            <tr>

                <!-- Editáveis -->

                <th class="editavel">Produto</th>

                <th class="editavel">Gramagem (kg)<br><small style="font-weight:400;">ex: 0,500</small></th>

                <th class="editavel">Valor MP (R$/kg)</th>

                <th class="editavel">Categoria<br><small style="font-weight:400;">texto livre</small></th>

                <th class="editavel">Qtd/Caixa<br><small style="font-weight:400;">unidades</small></th>

                <!-- Calculados -->

                <th class="calculado optional-col">D - Custo MP</th>

                <th class="calculado optional-col">E - Custo/g</th>

                <th class="calculado optional-col">G - Custo BD</th>

                <th class="calculado optional-col">I - Kg/Caixa</th>

                <th class="calculado">J - Fr. Kauauti</th>

                <th class="calculado">K - Fr. Nivaldo</th>

                <th class="calculado optional-col">L - Mão de Obra</th>

                <!-- Resultado -->

                <th id="th-preco-base" class="preco-col">N - Preço Base</th>

                <!-- Colunas dinâmicas de clientes inseridas aqui via JS -->

                <!-- Config -->

                <th class="editavel">Disponível</th>

                <th class="editavel print-hidden">Ação</th>

            </tr>

        </thead>

        <tbody id="embaladoTbody">

        <?php foreach ($produtos as $p):

            $pid   = (int)   $p['id'];

            $gram  = (float) ($p['gramagem']      ?? 0);

            $vmp   = (float) ($p['valor_mp']       ?? 0);

            $qtd   = (float) ($p['qtd_por_caixa']  ?? 0);

            $cat   = (string)($p['categoria']       ?? '');

            $disp  = (int)   ($p['disponivel']      ?? 1);

            $temDados = $gram > 0 && $vmp > 0;

            $calc  = $temDados ? calcEmbalado($gram, $vmp, $qtd, $cfg) : null;

        ?>

        <tr class="<?= !$temDados ? 'sem-preco' : '' ?> <?= !$disp ? 'indisponivel' : '' ?>" data-produto-row="1">

            <td><?= htmlspecialchars($p['nome']) ?></td>

            <td>

                <input type="number" step="0.001" min="0.001" id="gram_<?= $pid ?>"

                    value="<?= $gram > 0 ? number_format($gram, 3, '.', '') : '' ?>"

                    placeholder="0,500"

                    oninput="recalcEmbalado(<?= $pid ?>)">

            </td>

            <td>

                <input type="number" step="0.01" min="0" id="mp_<?= $pid ?>"

                    value="<?= $vmp > 0 ? number_format($vmp, 2, '.', '') : '' ?>"

                    placeholder="0,00"

                    oninput="recalcEmbalado(<?= $pid ?>)">

            </td>

            <td>

                <input type="text" id="cat_<?= $pid ?>"

                    value="<?= htmlspecialchars($cat) ?>"

                    placeholder="ex: folhosa">

            </td>

            <td>

                <input type="number" step="1" min="0" id="qtd_<?= $pid ?>"

                    value="<?= $qtd > 0 ? number_format($qtd, 0, '.', '') : '' ?>"

                    placeholder="0"

                    oninput="recalcEmbalado(<?= $pid ?>)">

            </td>

            <!-- Calculados -->

            <td class="optional-col"><span id="vmg_<?= $pid ?>" class="calc-val">

                <?= $calc ? fmt_php($calc['valor_materia_x_gramagem']) : '-' ?>

            </span></td>

            <td class="optional-col"><span id="cpg_<?= $pid ?>" class="calc-val">

                <?= $calc ? number_format($calc['custo_p_grama'], 4, ',', '') : '-' ?>

            </span></td>

            <td class="optional-col"><span id="cbd_<?= $pid ?>" class="calc-val">

                <?= $calc ? fmt_php($calc['custo_bd']) : '-' ?>

            </span></td>

            <td class="optional-col"><span id="kgc_<?= $pid ?>" class="calc-val">

                <?= $calc ? number_format($calc['kg_da_caixa'], 0, ',', '') : '-' ?>

            </span></td>

            <td><input type="number" step="0.01" min="0" id="fk_input_<?= $pid ?>" value="<?= $calc ? number_format((float) $calc['frete_kauavuti'], 2, '.', '') : '' ?>" oninput="recalcEmbalado(<?= $pid ?>)"><span id="fk_<?= $pid ?>" class="calc-val" style="display:none;"></span></td>

            <td><input type="number" step="0.01" min="0" id="fn_input_<?= $pid ?>" value="<?= $calc ? number_format((float) $calc['frete_nivaldo'], 2, '.', '') : '' ?>" oninput="recalcEmbalado(<?= $pid ?>)"><span id="fn_<?= $pid ?>" class="calc-val" style="display:none;"></span></td>

            <td class="optional-col"><span id="mob_<?= $pid ?>" class="calc-val">

                <?= $calc ? fmt_php($calc['custo_fixo_embalado']) : '-' ?>

            </span></td>

            <!-- Preço Base -->

            <td><span id="preco_base_<?= $pid ?>" class="calc-val preco-base">

                <?= $calc ? fmt_php($calc['preco_produto_base']) : '-' ?>

            </span></td>

            <!-- Disponibilidade -->

            <td>

                <select id="disp_<?= $pid ?>">

                    <option value="1" <?= $disp  ? 'selected' : '' ?>>Disponível</option>

                    <option value="0" <?= !$disp ? 'selected' : '' ?>>Indisponível</option>

                </select>

            </td>

            <td style="white-space:nowrap;" class="print-hidden">

                <button id="btn_<?= $pid ?>" class="btn-salvar"

                    onclick="salvarEmbalado(<?= $pid ?>)" title="Salvar"><i class="bi bi-check-lg"></i></button>

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

            <div class="print-logo"><img src="../assets/img/disponibilidade/embalado_colitti.png" alt="Colitti Orgânicos"></div>

            <div class="print-logo"><img src="../assets/img/disponibilidade/organico_portfolio.png" alt="Portfólio Orgânicos Colitti"></div>

            <div class="print-logo"><img src="../assets/img/disponibilidade/organico_brasil.png" alt="Produto Orgânico Brasil"></div>

        </div>

        <div class="print-title">TABELA DE DISPONIBILIDADE - <?= date('d/m/Y') ?></div>

        <div class="print-subtitle">UNIDADE: quantidade por caixa</div>

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

                $gramPrint = (float) ($p['gramagem'] ?? 0);

                $vmpPrint = (float) ($p['valor_mp'] ?? 0);

                $qtdPrint = (float) ($p['qtd_por_caixa'] ?? 0);

                $dispPrint = (int) ($p['disponivel'] ?? 1);

                $calcPrint = ($gramPrint > 0 && $vmpPrint > 0) ? calcEmbalado($gramPrint, $vmpPrint, $qtdPrint, $cfg) : null;

                $precoBasePrint = $calcPrint ? (float) $calcPrint['preco_produto_base'] : 0.0;

                $prazo30Print = $precoBasePrint > 0 ? $precoBasePrint * 1.05 : 0.0;

            ?>

            <tr>

                <td><?= htmlspecialchars($p['nome']) ?></td>

                <td><?= htmlspecialchars((string) ($p['categoria'] ?? '')) ?></td>

                <td class="num"><?= $qtdPrint > 0 ? number_format($qtdPrint, 0, ',', '.') : '-' ?></td>

                <td><?= $dispPrint ? 'Disponível' : 'Indisponível' ?></td>

                <td class="num"><?= $precoBasePrint > 0 ? fmt_php($precoBasePrint) : '-' ?></td>

                <td class="num"><?= $prazo30Print > 0 ? fmt_php($prazo30Print) : '-' ?></td>

            </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>



    <div id="embaladoEmpty" class="table-empty">Nenhum produto encontrado com os filtros atuais.</div>



    <div class="pagination">

        <div id="embaladoPaginacaoInfo" class="pagination-info">Página 0 de 0</div>

        <div id="embaladoPaginacao" class="pagination-actions"></div>

    </div>



    <p class="no-print" style="margin-top:14px;font-size:12px;color:#888;">

        * O <strong>Preço Final por Cliente</strong> (coluna O da planilha) é calculado na tela de

        <a href="../vendas/vendas.php" style="color:#1b5e20;">Nova Venda</a> aplicando o percentual financeiro

        cadastrado na tabela abaixo.

    </p>

</div>



<!-- -- SEÇÃO 2: Percentual Financeiro por Cliente ---------------------------- -->

<div class="card clientes-card">

    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">

        <div>

            <h2 class="section-title" style="margin-bottom:4px;">Desconto / Acréscimo Financeiro por Cliente</h2>

            <p class="descricao" style="margin-bottom:0;">Seção opcional para ajuste financeiro por cliente no Embalado.</p>

        </div>

        <button type="button" id="clientesToggle" class="collapsible-toggle" aria-expanded="false">

            <span>Mostrar clientes</span>

            <span class="chevron">v</span>

        </button>

    </div>



    <div id="clientesContent" class="collapsible-content is-collapsed">



    <div class="clientes-toolbar">

        <div class="toolbar-group toolbar-search">

            <label for="clientesBusca">Buscar cliente</label>

            <input type="text" id="clientesBusca" placeholder="Digite parte do nome do cliente">

        </div>

        <button type="button" id="clientesFiltroAjuste" class="btn-filtro-ajuste" title="Mostrar somente clientes com desconto ou acréscimo">

            <i class="bi bi-funnel"></i> Somente com ajuste

        </button>

        <button type="button" id="btnAddClienteEmb" class="btn-add-cliente-sec" onclick="togglePainelAddClienteEmb()">

            <i class="bi bi-person-plus-fill"></i> Adicionar cliente

        </button>

        <span id="clientesResumo" style="font-size:12px;color:#555;align-self:center;white-space:nowrap;"></span>

    </div>

    <div id="painelAddClienteEmb" class="addcli-panel">

        <label><i class="bi bi-search"></i> Selecione o cliente para adicionar</label>

        <input type="text" class="addcli-busca" id="addEmbBusca"
               placeholder="Buscar cliente..." oninput="renderListaAddEmb(this.value)">

        <div class="addcli-lista" id="addEmbLista"></div>

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

        <tbody id="clientesTbody">

        <?php foreach ($clientes as $c):

            $cid  = (int)   $c['id'];

            $pct  = (float) ($c['percentual'] ?? 0);

            $tipo = $c['tipo_aplicacao'] ?? 'acrescimo';

            $exemploBase = 10.00;

            $exemploFinal = aplicarPercentualFinanceiro($exemploBase, $pct, $tipo);

        ?>

        <tr data-cliente-row="1" id="cliente_row_<?= $cid ?>" data-cliente-id="<?= $cid ?>">

            <td class="cliente-nome-cell" data-cliente-cell="<?= $cid ?>" onclick="abrirSubstituirClienteEmb(<?= $cid ?>)" title="Clique para substituir cliente"><?= htmlspecialchars($c['nome']) ?></td>

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

                    onclick="salvarPercentual(<?= $cid ?>)" title="Salvar"><i class="bi bi-check-lg"></i></button>

                <button id="delbtn_<?= $cid ?>" class="btn-excluir-cliente" onclick="excluirClienteEmb(<?= $cid ?>)" title="Remover cliente"><i class="bi bi-trash"></i></button>

                <span id="pstat_<?= $cid ?>" class="save-status"></span>

            </td>

        </tr>

        <?php endforeach; ?>

        </tbody>

    </table>

    </div>

    <div id="clientesEmpty" class="table-empty">Nenhum cliente encontrado com a busca atual.</div>

    </div>

</div>



</div>

<!-- ── Modal: Adicionar Cliente ─────────────────────────────────────────────── -->
<div id="modalAdicionarCliente" class="modal-overlay" onclick="if(event.target===this)fecharModalAdicionarCliente()">
    <div class="modal-box">
        <button class="modal-btn-fechar" onclick="fecharModalAdicionarCliente()" title="Fechar">×</button>
        <h3 class="modal-titulo"><i class="bi bi-person-plus"></i> Adicionar coluna de cliente</h3>
        <input type="text" class="modal-busca" id="modalClienteBusca"
               placeholder="Buscar cliente..."
               oninput="renderModalLista(this.value)">
        <div class="modal-lista" id="modalClienteLista"></div>
        <p style="margin:10px 0 0;font-size:11px;color:#888;">
            Clique no cliente para adicionar a coluna. O preço é calculado como
            <strong>Preço Base × (1 + acréscimo%)</strong>. Remova a coluna com o botão ×.
        </p>
    </div>
</div>

<script src="exportacao_financeira_modal.js"></script>
<?php renderModalAdicionarProdutoTabela('embalado'); ?>
</body>

</html>

<?php

function fmt_php(float $v): string {

    return 'R$ ' . number_format($v, 2, ',', '.');

}

?>






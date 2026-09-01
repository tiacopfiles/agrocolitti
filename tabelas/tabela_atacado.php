<?php
require '../auth/proteger.php';
require '../config/conexao.php';
require '../config/ciclo_helper.php';
require '../config/layout_helper.php';
require '../config/calculos_preco.php';
require '../config/permissions.php';
require __DIR__ . '/produto_modal_helper.php';

requireModule('tabelas', '../index.php');

// Tabela e colunas de preco_atacado criadas via database/migrations.sql.

$cicloAtivo = getCicloAtivo($conexao);
$cfg        = getConfigPrecificacao($conexao);

$resClientes = $conexao->query("SELECT c.id, c.nome, COALESCE(cpf.percentual,0) percentual FROM clientes c INNER JOIN cliente_percentual_financeiro cpf ON cpf.cliente_id=c.id AND cpf.tabela='atacado' WHERE c.ativo=1 ORDER BY c.nome");
$clientesAtacado = $resClientes ? $resClientes->fetch_all(MYSQLI_ASSOC) : [];
$resTodosClientes = $conexao->query("SELECT id, nome FROM clientes WHERE ativo=1 ORDER BY nome");
$todosClientesAtacado = $resTodosClientes ? $resTodosClientes->fetch_all(MYSQLI_ASSOC) : [];

function colunaExisteAtacado(mysqli $conexao, string $tabela, string $coluna): bool
{
    $stmt = $conexao->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $tabela, $coluna);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $existe = $resultado && $resultado->num_rows > 0;
    $stmt->close();
    return $existe;
}

if (!colunaExisteAtacado($conexao, 'preco_atacado', 'unidade_comercial')) {
    $conexao->query("ALTER TABLE preco_atacado ADD COLUMN unidade_comercial VARCHAR(50) NOT NULL DEFAULT '' AFTER valor_mp");
}

function nomeProdutoAtacadoSemGramagem(string $nome): string
{
    $nome = trim($nome);
    $nome = preg_replace('/(^|\s)\d+(?:[.,]\d+)?\s*(?:kg|g|gr|grs|gramas?)\.?(?=\s|$)/iu', ' ', $nome);
    return trim(preg_replace('/\s+/', ' ', $nome));
}

// Carrega produtos com seus dados de preço
$resultado = $conexao->query("
    SELECT p.id, p.nome, p.unidade,
           pa.categoria, pa.gramagem, pa.valor_mp, pa.unidade_comercial, pa.kg_caixa,
           pa.acrescimo_35, pa.frete_kauauti, pa.frete_nivaldo,
           pa.prazo_5_dias, pa.prazo_30_dias,
           CASE WHEN pa.valor_mp > 0 THEN COALESCE(pa.ativo, 1) ELSE 0 END AS disponivel
    FROM   produtos p
    LEFT   JOIN preco_atacado pa ON pa.produto_id = p.id
    WHERE  p.ativo = 1
      AND  (p.produto_principal_id IS NULL OR pa.produto_id IS NOT NULL)
    ORDER  BY p.nome
");
$produtos = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];

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
<title>Tabela Atacado</title>
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
#atacadoTable.is-compact-columns { min-width: 980px; table-layout: auto; }
#atacadoTable.is-compact-columns .optional-col { display: none; }
th { background: #1b5e20; color: #fff; padding: 10px 8px; font-size: 12px; white-space: nowrap; text-align: left; line-height: 1.3; }
th.editavel { background: #2e7d32; }
th.calculado { background: #4a148c; }
th.preco-col { background: #b71c1c; }
.collapsible-toggle { display: inline-flex; align-items: center; gap: 8px; background: #f1f8e9; color: #1b5e20; border: 1px solid #c5e1a5; padding: 8px 12px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 13px; }
.collapsible-toggle:hover { background: #dcedc8; }
.collapsible-toggle .chevron { font-size: 12px; transition: transform .2s ease; }
.collapsible-toggle[aria-expanded="true"] .chevron { transform: rotate(180deg); }
.collapsible-content.is-collapsed { display: none; }
.btn-filtro-ajuste:hover { background:#bf360c; }
.btn-filtro-ajuste.ativo { background:#1b5e20; }
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
function calcAtacadoJS(valor_mp, kg_caixa, frete_nivaldo_manual, frete_kauauti_manual) {
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

function recalcAtacado(pid) {
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

    const c = calcAtacadoJS(mp, kgc, fn, fk);
    document.getElementById('ac35_' + pid).textContent = fmt(c.acrescimo_35);
    document.getElementById('fk_'   + pid).textContent = fmt(c.frete_kauauti);
    document.getElementById('fn_'   + pid).textContent = fmt(c.frete_nivaldo);
    document.getElementById('p5_'   + pid).textContent = fmt(c.prazo_5_dias);
    document.getElementById('p30_'  + pid).textContent = fmt(c.prazo_30_dias);

}

async function salvarAtacado(pid) {
    const btn  = document.getElementById('btn_' + pid);
    const stat = document.getElementById('stat_' + pid);
    const cat  = document.getElementById('cat_' + pid).value;
    const gram = document.getElementById('gram_' + pid).value;
    const mp   = document.getElementById('mp_'  + pid).value;
    const unidade = document.getElementById('unidade_' + pid).value;
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

        const r = await fetch('actions/salvar_preco_atacado.php', { method: 'POST', body: fd });
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

function configurarTabelaAtacado() {
    const tbody = document.getElementById('atacadoTbody');
    if (!tbody) return;

    const rows = Array.from(tbody.querySelectorAll('tr[data-produto-row="1"]'));
    const busca = document.getElementById('atacadoBusca');
    const disponibilidade = document.getElementById('atacadoDisponibilidade');
    const categoria = document.getElementById('atacadoCategoria');
    const preco = document.getElementById('atacadoPreco');
    const pageSize = document.getElementById('atacadoPageSize');
    const resumo = document.getElementById('atacadoResumo');
    const empty = document.getElementById('atacadoEmpty');
    const pagInfo = document.getElementById('atacadoPaginacaoInfo');
    const pagActions = document.getElementById('atacadoPaginacao');

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

function configurarColunasAtacado() {
    const table = document.getElementById('atacadoTable');
    const button = document.getElementById('toggleColunasAtacado');
    const tableWrap = table?.closest('.table-wrap');
    if (!table || !button) return;

    const storageKey = 'agrocolitti.atacado.colunasEnxutas';

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
    configurarTabelaAtacado();
    configurarColunasAtacado();

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
    <h2 class="section-title">Tabela de Preços - Atacado</h2>
    <p class="descricao">
        Informe os campos do Atacado no mesmo padrão da planilha. Os campos calculados são atualizados automaticamente.
        <strong>Prazo 5 dias</strong> e <strong>Prazo 30 dias</strong> são os preços usados na tela de vendas conforme o prazo selecionado.
    </p>

    <div class="legend">
        <span><span class="leg-dot" style="background:#2e7d32;"></span> Coluna editável</span>
        <span><span class="leg-dot" style="background:#4a148c;"></span> Calculado automaticamente</span>
        <span><span class="leg-dot" style="background:#b71c1c;"></span> Preços finais por prazo</span>
    </div>

    <div class="toolbar no-print">
        <div class="toolbar-group toolbar-search">
            <label for="atacadoBusca">Buscar produto</label>
            <input type="text" id="atacadoBusca" placeholder="Digite parte do nome do produto">
        </div>
        <div class="toolbar-filters">
            <div class="toolbar-group print-hidden">
                <label for="tabelaAtacadoAcoes">Tabelas</label>
                <select id="tabelaAtacadoAcoes" onchange="if(this.value) window.location.href=this.value;">
                    <option value="">Atacado</option>
                    <option value="tabela_atacado_convencional.php">Atacado Convencional</option>
                    <option value="tabela_embalado.php">Embalado</option>
                    <option value="tabela_oba_embalado.php">OBA Embalado</option>
                    <option value="tabela_shopper.php">Shopper</option>
                    <option value="criar_tabela_personalizada.php">+ Criar nova tabela</option>
                    <option value="tabelas_personalizadas.php">Editar tabelas</option>
                </select>
            </div>
            <div class="toolbar-group">
                <label for="atacadoDisponibilidade">Disponibilidade</label>
                <select id="atacadoDisponibilidade">
                    <option value="todos">Todos</option>
                    <option value="1" selected>Disponível</option>
                    <option value="0">Indisponível</option>
                </select>
            </div>
            <div class="toolbar-group">
                <label for="atacadoCategoria">Categoria</label>
                <select id="atacadoCategoria">
                    <option value="">Todas</option>
                    <?php foreach (array_keys($categoriasProdutos) as $categoriaOption): ?>
                        <option value="<?= htmlspecialchars($categoriaOption, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($categoriaOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="toolbar-group">
                <label for="atacadoPreco">Preço</label>
                <select id="atacadoPreco">
                    <option value="todos">Todos</option>
                    <option value="com_preco" selected>Com preço</option>
                    <option value="sem_preco">Sem preço</option>
                </select>
            </div>
            <div class="toolbar-group">
                <label for="atacadoPageSize">Itens por página</label>
                <select id="atacadoPageSize">
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <div class="toolbar-group print-hidden">
                <label>&nbsp;</label>
                <div class="action-buttons">
                    <?php renderBotaoAdicionarProdutoTabela(); ?>
                    <button type="button" id="toggleColunasAtacado" class="column-toggle-btn" aria-pressed="false" title="Ocultar colunas calculadas"><i class="bi bi-eye-slash"></i><span>Ocultar colunas</span></button>
                    </div>
            </div>
        </div>
    </div>

    <div class="toolbar-meta">
        <div>
            <div id="atacadoResumo">0 produto(s) encontrado(s)</div>
            <div>Busca, filtros e paginação atuam só na navegação da tela.</div>
        </div>
        <div class="export-actions">
            <a class="export-btn export-btn-excel" href="exportar_disponibilidade_xlsx.php?tipo=atacado" data-export-financeiro data-tipo-tabela="atacado" title="Baixar tabela em Excel"><i class="bi bi-file-earmark-excel"></i></a>
            <a class="export-btn export-btn-pdf" href="exportar_disponibilidade_pdf.php?tipo=atacado" data-export-financeiro data-tipo-tabela="atacado" title="Baixar tabela em PDF"><i class="bi bi-file-earmark-pdf"></i></a>
        </div>    </div>

    <div class="table-wrap print-area">
    <table id="atacadoTable" data-no-responsive="1">
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
                <th class="preco-col">Prazo 5 dias</th>
                <th class="preco-col">Prazo 30 dias</th>
                <th class="editavel">Disponível</th>
                <th class="editavel print-hidden">Ação</th>
            </tr>
        </thead>
        <tbody id="atacadoTbody">
        <?php foreach ($produtos as $p):
            $pid    = (int) $p['id'];
            $nomeTabela = nomeProdutoAtacadoSemGramagem((string) ($p['nome'] ?? ''));
            $cat    = (string) ($p['categoria'] ?? '');
            $gram   = (float) ($p['gramagem'] ?? 0);
            $vmp    = (float) ($p['valor_mp']  ?? 0);
            $kgc    = (float) ($p['kg_caixa']  ?? 20);
            $unidade = (string) (($p['unidade_comercial'] ?? '') !== '' ? $p['unidade_comercial'] : ($p['unidade'] ?? 'kg'));
            $disp   = (int)   ($p['disponivel'] ?? 1);
            $temDados = $vmp > 0;
            $freteNivaldo = (float) ($p['frete_nivaldo'] ?? $cfg['frete_nivaldo_atacado_padrao']);
            $calc   = ($vmp > 0 || $kgc > 0) ? calcAtacado($vmp, $kgc, $freteNivaldo, $cfg) : null;
        ?>
        <tr class="<?= !$temDados ? 'sem-preco' : '' ?> <?= !$disp ? 'indisponivel' : '' ?>" data-produto-row="1"
            data-pid="<?= $pid ?>">
            <td><?= htmlspecialchars($nomeTabela) ?></td>
            <td>
                <input type="text" id="cat_<?= $pid ?>"
                    value="<?= htmlspecialchars($cat) ?>"
                    placeholder="ex: folhosa">
            </td>
            <td>
                <input type="number" step="0.001" min="0" id="gram_<?= $pid ?>"
                    value="<?= $gram > 0 ? number_format($gram, 3, '.', '') : '' ?>"
                    placeholder="0,000">
            </td>
            <td>
                <input type="number" step="0.01" min="0" id="mp_<?= $pid ?>"
                    value="<?= $vmp > 0 ? number_format($vmp, 2, '.', '') : '' ?>"
                    placeholder="0,00"
                    oninput="recalcAtacado(<?= $pid ?>)">
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
                    oninput="recalcAtacado(<?= $pid ?>)">
            </td>
            <td class="optional-col"><span id="ac35_<?= $pid ?>" class="calc-val">
                <?= $calc ? fmt_php($calc['acrescimo_35']) : '-' ?>
            </span></td>
            <td><input type="number" step="0.01" min="0" id="fk_input_<?= $pid ?>" value="<?= $calc ? number_format((float) $calc['frete_kauavuti'], 2, '.', '') : '' ?>" oninput="recalcAtacado(<?= $pid ?>)"><span id="fk_<?= $pid ?>" class="calc-val" style="display:none;"></span></td>
            <td><input type="number" step="0.01" min="0" id="fn_input_<?= $pid ?>" value="<?= $calc ? number_format((float) $calc['frete_nivaldo'], 2, '.', '') : '' ?>" oninput="recalcAtacado(<?= $pid ?>)"><span id="fn_<?= $pid ?>" class="calc-val" style="display:none;"></span></td>
            <td><span id="p5_<?= $pid ?>" class="calc-val preco-base">
                <?= $calc ? fmt_php($calc['prazo_5_dias']) : '-' ?>
            </span></td>
            <td><span id="p30_<?= $pid ?>" class="calc-val preco-final">
                <?= $calc ? fmt_php($calc['prazo_30_dias']) : '-' ?>
            </span></td>
            <td>
                <select id="disp_<?= $pid ?>">
                    <option value="1" <?= $disp ? 'selected' : '' ?>>Disponível</option>
                    <option value="0" <?= !$disp ? 'selected' : '' ?>>Indisponível</option>
                </select>
            </td>
            <td style="white-space:nowrap;" class="print-hidden">
                <button id="btn_<?= $pid ?>" class="btn-salvar"
                    onclick="salvarAtacado(<?= $pid ?>)" title="Salvar"><i class="bi bi-check-lg"></i></button>
                <button type="button" class="btn-excluir-produto-tabela" onclick="excluirProdutoTabela(<?= $pid ?>, <?= htmlspecialchars(json_encode($nomeTabela, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>)" title="Excluir produto"><i class="bi bi-trash"></i></button>
                <span id="stat_<?= $pid ?>" class="save-status"></span>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="availability-print-header">
        <div class="print-logos">
            <div class="print-logo-slot"><img src="../assets/img/disponibilidade/organico_colitti.png" alt="Colitti Orgânicos"></div>
            <div class="print-logo-slot"><img src="../assets/img/disponibilidade/organico_portfolio.png" alt="Portfólio Orgânicos Colitti"></div>
            <div class="print-logo-slot"><img src="../assets/img/disponibilidade/organico_brasil.png" alt="Produto Orgânico Brasil"></div>
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
                    $nomeTabelaPrint = nomeProdutoAtacadoSemGramagem((string) ($p['nome'] ?? ''));
                    $vmpPrint = (float) ($p['valor_mp'] ?? 0);
                    $kgcPrint = (float) ($p['kg_caixa'] ?? 20);
                    $freteNivaldoPrint = (float) ($p['frete_nivaldo'] ?? $cfg['frete_nivaldo_atacado_padrao']);
                    $calcPrint = ($vmpPrint > 0 || $kgcPrint > 0) ? calcAtacado($vmpPrint, $kgcPrint, $freteNivaldoPrint, $cfg) : null;
                    $dispPrint = (int) ($p['disponivel'] ?? 1);
                ?>
                <tr>
                    <td><?= htmlspecialchars($nomeTabelaPrint) ?></td>
                    <td><?= htmlspecialchars((string) ($p['categoria'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) (($p['unidade_comercial'] ?? '') !== '' ? $p['unidade_comercial'] : ($p['unidade'] ?? 'kg'))) ?></td>
                    <td><?= $dispPrint ? 'Disponível' : 'Indisponível' ?></td>
                    <td class="num"><?= $calcPrint ? fmt_php($calcPrint['prazo_5_dias']) : '-' ?></td>
                    <td class="num"><?= $calcPrint ? fmt_php($calcPrint['prazo_30_dias']) : '-' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="atacadoEmpty" class="table-empty">Nenhum produto encontrado com os filtros atuais.</div>

    <div class="pagination">
        <div id="atacadoPaginacaoInfo" class="pagination-info">Página 0 de 0</div>
        <div id="atacadoPaginacao" class="pagination-actions"></div>
    </div>

    <p class="no-print" style="margin-top:14px;font-size:12px;color:#888;">
        * Na tela de <a href="../vendas/vendas.php" style="color:#1b5e20;">Nova Venda</a>, o Atacado usa o preço conforme o
        <strong>prazo selecionado</strong>. Selecione um cliente acima para ver o preço com desconto/acréscimo financeiro aplicado.
    </p>
</div>

<div class="card clientes-card no-print">
    <h2 class="section-title">Acréscimo Financeiro por Cliente</h2>
    <p class="descricao">Vincule clientes à tabela de Atacado e configure o acréscimo aplicado aos preços de 5 e 30 dias.</p>
    <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:16px;">
        <label style="flex:1;min-width:260px;">Adicionar cliente
            <select id="novoClienteAtacado" style="width:100%;padding:9px;"><option value="">Selecione...</option>
            <?php $idsAtacado = array_map('intval', array_column($clientesAtacado, 'id')); foreach ($todosClientesAtacado as $cli): if (in_array((int)$cli['id'], $idsAtacado, true)) continue; ?>
                <option value="<?= (int)$cli['id'] ?>"><?= htmlspecialchars($cli['nome']) ?></option>
            <?php endforeach; ?></select>
        </label>
        <button type="button" class="btn-salvar" style="width:42px;min-width:42px;height:42px;padding:0;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;" onclick="adicionarClienteAtacado()" title="Adicionar cliente" aria-label="Adicionar cliente"><i class="bi bi-person-plus-fill" aria-hidden="true"></i></button>
    </div>
    <div class="table-wrap"><table style="min-width:700px;"><thead><tr><th>Cliente</th><th>Acréscimo (%)</th><th>Exemplo sobre R$ 10,00</th><th>Ações</th></tr></thead><tbody>
    <?php foreach ($clientesAtacado as $cli): $pct=(float)$cli['percentual']; ?>
        <tr id="clienteAtacado_<?= (int)$cli['id'] ?>"><td><?= htmlspecialchars($cli['nome']) ?></td><td><input id="pctAtacado_<?= (int)$cli['id'] ?>" type="number" min="0" max="100" step="0.01" value="<?= number_format($pct*100,2,'.','') ?>"></td><td>R$ 10,00 → <strong>R$ <?= number_format(10*(1+$pct),2,',','.') ?></strong></td><td><button class="btn-salvar" onclick="salvarClienteAtacado(<?= (int)$cli['id'] ?>)"><i class="bi bi-check-lg"></i></button> <button class="btn-excluir-produto-tabela" onclick="removerClienteAtacado(<?= (int)$cli['id'] ?>)"><i class="bi bi-trash"></i></button></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>

</div>
<script>
async function postClienteAtacado(action, data) {
    const fd = new FormData(); Object.entries(data).forEach(([k,v]) => fd.append(k,v)); fd.append('tabela','atacado');
    const r = await fetch('actions/' + action + '.php', {method:'POST', body:fd}); const d = await r.json();
    if (!d.ok) throw new Error(d.erro || 'Não foi possível concluir.'); return d;
}
async function adicionarClienteAtacado(){const s=document.getElementById('novoClienteAtacado');if(!s.value)return;try{await postClienteAtacado('salvar_percentual_cliente',{cliente_id:s.value,percentual:0,tipo_aplicacao:'acrescimo'});location.reload();}catch(e){alert(e.message)}}
async function salvarClienteAtacado(id){try{await postClienteAtacado('salvar_percentual_cliente',{cliente_id:id,percentual:document.getElementById('pctAtacado_'+id).value,tipo_aplicacao:'acrescimo'});location.reload();}catch(e){alert(e.message)}}
async function removerClienteAtacado(id){if(!confirm('Remover este cliente da tabela de Atacado?'))return;try{await postClienteAtacado('excluir_percentual_cliente',{cliente_id:id});document.getElementById('clienteAtacado_'+id)?.remove();}catch(e){alert(e.message)}}
</script>
<script src="exportacao_financeira_modal.js"></script>
<?php renderModalAdicionarProdutoTabela('atacado'); ?>
</body>
</html>
<?php
function fmt_php(float $v): string {
    return 'R$ ' . number_format($v, 2, ',', '.');
}
?>



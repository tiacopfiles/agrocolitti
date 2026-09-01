<?php
require '../auth/proteger.php';
require '../config/conexao.php';
require '../config/ciclo_helper.php';
require '../config/layout_helper.php';
require '../config/permissions.php';
require __DIR__ . '/produto_modal_helper.php';

requireModule('tabelas', '../index.php');

$conexao->query("
    CREATE TABLE IF NOT EXISTS preco_shopper (
        id INT NOT NULL AUTO_INCREMENT,
        produto_id INT NOT NULL,
        categoria VARCHAR(100) NOT NULL DEFAULT '',
        gramagem DECIMAL(10,3) NOT NULL DEFAULT 0.000,
        valor_mp DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        unidade VARCHAR(60) NOT NULL DEFAULT '',
        kg_caixa DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        acrescimo_35 DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
        frete_kauauti DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
        frete_nivaldo DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
        custo_5_dias DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
        prazo_5_dias DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
        custo_30_dias DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
        prazo_30_dias DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_preco_shopper_produto (produto_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$cicloAtivo = getCicloAtivo($conexao);
$resultado = $conexao->query("
    SELECT p.id, p.nome,
           ps.categoria, ps.gramagem, ps.valor_mp, ps.unidade, ps.kg_caixa,
           ps.acrescimo_35, ps.frete_kauauti, ps.frete_nivaldo,
           ps.custo_5_dias, ps.prazo_5_dias, ps.custo_30_dias, ps.prazo_30_dias,
           CASE WHEN ps.prazo_5_dias > 0 OR ps.prazo_30_dias > 0 THEN COALESCE(ps.ativo, 1) ELSE 0 END AS disponivel
    FROM produtos p
    LEFT JOIN preco_shopper ps ON ps.produto_id = p.id
    WHERE p.ativo = 1
      AND (p.produto_principal_id IS NULL OR ps.produto_id IS NOT NULL)
    ORDER BY p.nome
");
$produtos = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];

$categoriasProdutos = [];
foreach ($produtos as $produto) {
    $categoria = trim((string) ($produto['categoria'] ?? ''));
    if ($categoria !== '') {
        $categoriasProdutos[$categoria] = true;
    }
}
ksort($categoriasProdutos, SORT_NATURAL | SORT_FLAG_CASE);

function fmt_shopper($valor): string {
    return 'R$ ' . number_format((float) $valor, 2, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tabela Shopper</title>
<style>
* { box-sizing:border-box; }
body { margin:0; font-family:'Segoe UI',Arial,sans-serif; background:#f4f6f9; color:#222; }
.container { padding:24px 20px 32px; max-width:1820px; margin:0 auto; width:100%; }
.card { background:#fff; padding:22px 20px; border-radius:10px; box-shadow:0 4px 12px rgba(0,0,0,.06); margin-bottom:24px; }
.section-title { margin:0 0 6px; font-size:18px; color:#1b5e20; font-weight:700; }
.descricao { color:#555; font-size:14px; margin:0 0 16px; }
.msg-sucesso { background:#e8f5e9; color:#2e7d32; border:1px solid #a5d6a7; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-weight:700; }
.legend { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:12px; font-size:12px; }
.legend span { display:flex; align-items:center; gap:5px; }
.leg-dot { width:12px; height:12px; border-radius:2px; }
.toolbar { display:flex; flex-wrap:wrap; gap:14px; align-items:end; justify-content:space-between; margin:16px 0 14px; }
.toolbar-group { display:flex; flex-direction:column; gap:6px; min-width:165px; }
.toolbar-group label { font-size:13px; color:#444; font-weight:700; }
.toolbar-group input,.toolbar-group select { width:100%!important; min-height:40px; padding:8px 10px; font-size:13px; border:1px solid #ccc; border-radius:6px; background:#fff; }
.toolbar-search { flex:1 1 430px; min-width:320px; max-width:680px; }
.toolbar-filters { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:14px; margin-left:auto; }
.toolbar-meta { display:flex; justify-content:space-between; gap:12px; align-items:center; margin:8px 0 12px; flex-wrap:wrap; font-size:12px; color:#555; }
.table-wrap { overflow-x:auto; overflow-y:hidden; }
table { width:100%; border-collapse:collapse; min-width:1440px; }
#shopperTable.is-compact-columns { min-width:1080px; }
#shopperTable.is-compact-columns .optional-col { display:none; }
th { background:#1b5e20; color:#fff; padding:10px 8px; font-size:12px; white-space:nowrap; text-align:left; line-height:1.3; position:sticky; top:0; z-index:3; box-shadow:inset 0 -1px 0 rgba(255,255,255,.14); }
th.editavel { background:#2e7d32; }
th.calculado { background:#4a148c; }
th.preco-col { background:#b71c1c; }
td { padding:8px; border-bottom:1px solid #eee; font-size:12px; vertical-align:middle; line-height:1.3; }
tr:hover td { background:#f1f8f4; }
tr.indisponivel td:first-child { color:#999; text-decoration:line-through; }
input[type=number] { width:82px; min-height:34px; padding:6px 8px; border:1px solid #ccc; border-radius:5px; font-size:12px; }
input[type=text] { width:105px; min-height:34px; padding:6px 8px; border:1px solid #ccc; border-radius:5px; font-size:12px; }
input[type=number]:focus,input[type=text]:focus { outline:none; border-color:#2e7d32; box-shadow:0 0 0 2px rgba(46,125,50,.15); }
select { min-height:34px; padding:6px 8px; border:1px solid #ccc; border-radius:5px; font-size:12px; background:#fff; }
.calc-val { font-weight:600; font-size:12px; white-space:nowrap; color:#333; }
.preco-base { color:#4a148c; font-size:13px; font-weight:700; }
.preco-final { color:#b71c1c; font-size:13px; font-weight:700; }
.btn-salvar { background:#2e7d32; color:#fff; border:none; width:34px; height:34px; padding:0; border-radius:6px; cursor:pointer; font-weight:700; font-size:15px; display:inline-flex; align-items:center; justify-content:center; }
.btn-salvar:hover { background:#1b5e20; }
.btn-salvar:disabled { background:#aaa; cursor:not-allowed; }
.save-status { font-size:11px; margin-left:5px; display:block; margin-top:3px; }
.save-ok { color:#2e7d32; }
.save-err { color:#c62828; }
.action-buttons { display:flex; gap:8px; align-items:center; }
.export-actions { display:inline-flex; align-items:center; gap:6px; flex:0 0 auto; }
.export-actions .export-btn { width:34px; height:34px; min-height:34px; padding:0; justify-content:center; gap:0; font-size:15px; }
.export-btn-excel { background:#1b5e20; color:#fff; border-color:#1b5e20; }
.export-btn-pdf { background:#b71c1c; color:#fff; border-color:#b71c1c; }
.column-toggle-btn, .export-btn { background:#f1f8e9; color:#1b5e20; border:1px solid #c5e1a5; padding:0 10px; min-height:40px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:700; display:inline-flex; align-items:center; gap:6px; text-decoration:none; }
.export-btn { width:40px; justify-content:center; padding:0; background:#1b5e20; color:#fff; border-color:#1b5e20; }
.export-actions .export-btn-excel { background:#1b5e20; color:#fff; border-color:#1b5e20; }
.export-actions .export-btn-pdf { background:#b71c1c; color:#fff; border-color:#b71c1c; }
.table-empty { display:none; margin:10px 0 0; padding:10px 12px; border-radius:8px; background:#fff8e1; border:1px solid #ffe082; color:#8d6e63; font-size:12px; }
.pagination { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:14px; flex-wrap:wrap; }
.pagination-info { font-size:12px; color:#555; }
.pagination-actions { display:flex; gap:6px; flex-wrap:wrap; }
.page-btn { background:#f1f8e9; color:#1b5e20; border:1px solid #c5e1a5; padding:6px 10px; border-radius:6px; cursor:pointer; font-size:12px; font-weight:700; }
.page-btn.active { background:#2e7d32; border-color:#2e7d32; color:#fff; }
.page-btn:disabled { opacity:.55; cursor:not-allowed; }
@media (max-width:760px) {
    .container { padding:18px 12px 24px; }
    .card { padding:18px 14px; }
    .toolbar { flex-direction:column; align-items:stretch; }
    .toolbar-search,.toolbar-group { min-width:0; max-width:100%; width:100%; }
    .toolbar-filters { flex-direction:column; align-items:stretch; width:100%; }
}
</style>
<?php renderAppLayoutStyles(); ?>
</head>
<body>
<?php renderAppHeader('..'); ?>
<div class="container">
<?php if ($cicloAtivo): ?>
    <div class="msg-sucesso">Ciclo ativo: <?= htmlspecialchars(getNomeCiclo($cicloAtivo)) ?></div>
<?php endif; ?>
<div class="card">
    <h2 class="section-title">Tabela de Preços - Shopper</h2>
    <p class="descricao">Informe os campos editáveis. Os valores de custo e prazo sao recalculados automaticamente conforme a tabela SHOPPER.</p>
    <div class="legend">
        <span><span class="leg-dot" style="background:#2e7d32;"></span> Coluna editável</span>
        <span><span class="leg-dot" style="background:#4a148c;"></span> Calculado automaticamente</span>
        <span><span class="leg-dot" style="background:#b71c1c;"></span> Preço final Shopper</span>
    </div>
    <div class="toolbar">
        <div class="toolbar-group toolbar-search">
            <label for="shopperBusca">Buscar produto</label>
            <input type="text" id="shopperBusca" placeholder="Digite parte do nome do produto">
        </div>
        <div class="toolbar-filters">
            <div class="toolbar-group">
                <label for="tabelaShopperAcoes">Tabelas</label>
                <select id="tabelaShopperAcoes" onchange="if(this.value) window.location.href=this.value;">
                    <option value="">Shopper</option>
                    <option value="tabela_atacado.php">Atacado</option>
                    <option value="tabela_atacado_convencional.php">Atacado Convencional</option>
                    <option value="tabela_embalado.php">Embalado</option>
                    <option value="tabela_oba_embalado.php">OBA Embalado</option>
                    <option value="criar_tabela_personalizada.php">+ Criar nova tabela</option>
                    <option value="tabelas_personalizadas.php">Editar tabelas</option>
                </select>
            </div>
            <div class="toolbar-group">
                <label for="shopperDisponibilidade">Disponibilidade</label>
                <select id="shopperDisponibilidade"><option value="todos">Todos</option><option value="1" selected>Disponível</option><option value="0">Indisponível</option></select>
            </div>
            <div class="toolbar-group">
                <label for="shopperCategoria">Categoria</label>
                <select id="shopperCategoria"><option value="">Todas</option><?php foreach (array_keys($categoriasProdutos) as $categoria): ?><option value="<?= htmlspecialchars($categoria, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($categoria) ?></option><?php endforeach; ?></select>
            </div>
            <div class="toolbar-group">
                <label for="shopperPreco">Preço</label>
                <select id="shopperPreco"><option value="todos">Todos</option><option value="com_preco" selected>Com preço</option><option value="sem_preco">Sem preço</option></select>
            </div>
            <div class="toolbar-group">
                <label for="shopperPageSize">Itens por pagina</label>
                <select id="shopperPageSize"><option value="25" selected>25</option><option value="50">50</option><option value="100">100</option></select>
            </div>
            <div class="toolbar-group">
                <label>&nbsp;</label>
                <div class="action-buttons"><?php renderBotaoAdicionarProdutoTabela(); ?><button type="button" id="toggleColunasShopper" class="column-toggle-btn" aria-pressed="false"><i class="bi bi-eye-slash"></i><span>Ocultar colunas</span></button></div>
            </div>
        </div>
    </div>
    <div class="toolbar-meta"><div><div id="shopperResumo">0 produto(s) encontrado(s)</div><div>Somente produtos visiveis na planilha SHOPPER.</div></div>
        <div class="export-actions">
            <a class="export-btn export-btn-excel" href="exportar_disponibilidade_xlsx.php?tipo=shopper" data-export-financeiro data-tipo-tabela="shopper" title="Baixar tabela em Excel"><i class="bi bi-file-earmark-excel"></i></a>
            <a class="export-btn export-btn-pdf" href="exportar_disponibilidade_pdf.php?tipo=shopper" data-export-financeiro data-tipo-tabela="shopper" title="Baixar tabela em PDF"><i class="bi bi-file-earmark-pdf"></i></a>
        </div>    </div>
    <div class="table-wrap">
    <table id="shopperTable" data-no-responsive="1">
        <thead><tr>
            <th class="editavel">Produto</th>
            <th class="editavel">CAT</th>
            <th class="editavel">Gramagem</th>
            <th class="editavel">Valor MP (R$/kg)</th>
            <th class="calculado optional-col">Acréscimo 35%</th>
            <th class="editavel">Unidade</th>
            <th class="editavel">Kg/Caixa</th>
            <th class="editavel">Fr. Kauauti</th>
            <th class="editavel">Fr. Nivaldo</th>
            <th class="calculado optional-col">Custo 5 dias</th>
            <th class="preco-col">Prazo 5 dias</th>
            <th class="calculado optional-col">Custo 30 dias</th>
            <th class="preco-col">Prazo 30 dias</th>
            <th class="editavel">Disponível</th>
            <th class="editavel">Ação</th>
        </tr></thead>
        <tbody id="shopperTbody">
        <?php foreach ($produtos as $p): $pid=(int)$p['id']; $disp=(int)$p['disponivel']; ?>
        <tr class="<?= !$disp ? 'indisponivel' : '' ?>" data-produto-row="1">
            <td><?= htmlspecialchars($p['nome']) ?></td>
            <td><input type="text" id="cat_<?= $pid ?>" value="<?= htmlspecialchars((string)$p['categoria']) ?>"></td>
            <td><input type="number" step="0.001" min="0" id="gram_<?= $pid ?>" value="<?= number_format((float)$p['gramagem'], 3, '.', '') ?>"></td>
            <td><input type="number" step="0.01" min="0" id="mp_<?= $pid ?>" value="<?= number_format((float)$p['valor_mp'], 2, '.', '') ?>" oninput="recalcShopper(<?= $pid ?>)"></td>
            <td class="optional-col"><span id="ac35_<?= $pid ?>" class="calc-val"><?= fmt_shopper($p['acrescimo_35']) ?></span></td>
            <td><input type="text" id="unidade_<?= $pid ?>" value="<?= htmlspecialchars((string)$p['unidade']) ?>"></td>
            <td><input type="number" step="0.01" min="0.01" id="kgc_<?= $pid ?>" value="<?= number_format((float)$p['kg_caixa'], 2, '.', '') ?>" oninput="recalcShopper(<?= $pid ?>)"></td>
            <td><input type="number" step="0.001" min="0" id="fk_<?= $pid ?>" value="<?= number_format((float)$p['frete_kauauti'], 3, '.', '') ?>" oninput="recalcShopper(<?= $pid ?>)"></td>
            <td><input type="number" step="0.01" min="0" id="fn_<?= $pid ?>" value="<?= number_format((float)$p['frete_nivaldo'], 2, '.', '') ?>" oninput="recalcShopper(<?= $pid ?>)"></td>
            <td class="optional-col"><span id="c5_<?= $pid ?>" class="calc-val"><?= fmt_shopper($p['custo_5_dias']) ?></span></td>
            <td><span id="p5_<?= $pid ?>" class="calc-val preco-final"><?= fmt_shopper($p['prazo_5_dias']) ?></span></td>
            <td class="optional-col"><span id="c30_<?= $pid ?>" class="calc-val"><?= fmt_shopper($p['custo_30_dias']) ?></span></td>
            <td><span id="p30_<?= $pid ?>" class="calc-val preco-final"><?= fmt_shopper($p['prazo_30_dias']) ?></span></td>
            <td><select id="disp_<?= $pid ?>"><option value="1" <?= $disp ? 'selected' : '' ?>>Disponível</option><option value="0" <?= !$disp ? 'selected' : '' ?>>Indisponível</option></select></td>
            <td><button id="btn_<?= $pid ?>" class="btn-salvar" onclick="salvarShopper(<?= $pid ?>)" title="Salvar"><i class="bi bi-check-lg"></i></button><button type="button" class="btn-excluir-produto-tabela" onclick="excluirProdutoTabela(<?= $pid ?>, <?= htmlspecialchars(json_encode((string) $p['nome'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>)" title="Excluir produto"><i class="bi bi-trash"></i></button><span id="stat_<?= $pid ?>" class="save-status"></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div id="shopperEmpty" class="table-empty">Nenhum produto encontrado com os filtros atuais.</div>
    <div class="pagination"><div id="shopperPaginacaoInfo" class="pagination-info">Página 0 de 0</div><div id="shopperPaginacao" class="pagination-actions"></div></div>
</div>
</div>
<script>
function fmtShopper(v) { return 'R$ ' + Number(v || 0).toFixed(2).replace('.', ','); }
function normalizarShopper(texto) { return (texto || '').toString().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim(); }
function linhaTemPrecoShopper(row) { return (parseFloat(row.querySelector('input[id^="kgc_"]')?.value || '0') || 0) > 0; }
function calcShopper(valorMp, freteKauauti, freteNivaldo) {
    const acrescimo35 = valorMp * 1.35;
    const custo5 = acrescimo35 + freteKauauti + (freteNivaldo * 0.05) + freteKauauti + freteNivaldo;
    const prazo5 = custo5 * 1.08;
    const custo30 = custo5 * 1.05;
    const prazo30 = custo30 * 1.08;
    return { acrescimo35, custo5, prazo5, custo30, prazo30 };
}
function recalcShopper(pid) {
    const mp = parseFloat(document.getElementById('mp_' + pid).value) || 0;
    const fk = parseFloat(document.getElementById('fk_' + pid).value) || 0;
    const fn = parseFloat(document.getElementById('fn_' + pid).value) || 0;
    const c = calcShopper(mp, fk, fn);
    document.getElementById('ac35_' + pid).textContent = fmtShopper(c.acrescimo35);
    document.getElementById('c5_' + pid).textContent = fmtShopper(c.custo5);
    document.getElementById('p5_' + pid).textContent = fmtShopper(c.prazo5);
    document.getElementById('c30_' + pid).textContent = fmtShopper(c.custo30);
    document.getElementById('p30_' + pid).textContent = fmtShopper(c.prazo30);
}
async function salvarShopper(pid) {
    const btn = document.getElementById('btn_' + pid);
    const stat = document.getElementById('stat_' + pid);
    btn.disabled = true; stat.textContent = 'Salvando...'; stat.className = 'save-status';
    const fd = new FormData();
    [['produto_id',pid],['categoria',document.getElementById('cat_'+pid).value],['gramagem',document.getElementById('gram_'+pid).value],['valor_mp',document.getElementById('mp_'+pid).value],['unidade',document.getElementById('unidade_'+pid).value],['kg_caixa',document.getElementById('kgc_'+pid).value],['frete_kauauti',document.getElementById('fk_'+pid).value],['frete_nivaldo',document.getElementById('fn_'+pid).value],['ativo',document.getElementById('disp_'+pid).value]].forEach(([k,v]) => fd.append(k,v));
    try {
        const resposta = await fetch('actions/salvar_preco_shopper.php', { method:'POST', body:fd });
        const dados = await resposta.json();
        stat.textContent = dados.ok ? 'OK Salvo' : 'Erro ' + (dados.erro || 'Erro');
        stat.className = 'save-status ' + (dados.ok ? 'save-ok' : 'save-err');
    } catch (e) { stat.textContent='Erro Falha na rede'; stat.className='save-status save-err'; }
    btn.disabled = false; setTimeout(() => { stat.textContent=''; }, 4000);
}
(function configurar() {
    const tbody=document.getElementById('shopperTbody'), rows=Array.from(tbody.querySelectorAll('tr[data-produto-row="1"]'));
    const busca=document.getElementById('shopperBusca'), disp=document.getElementById('shopperDisponibilidade'), cat=document.getElementById('shopperCategoria'), preco=document.getElementById('shopperPreco'), size=document.getElementById('shopperPageSize');
    const resumo=document.getElementById('shopperResumo'), empty=document.getElementById('shopperEmpty'), info=document.getElementById('shopperPaginacaoInfo'), actions=document.getElementById('shopperPaginacao');
    let pagina=1;
    function aplicar(reset=false) {
        if (reset) pagina=1;
        const termo=normalizarShopper(busca.value), categoria=normalizarShopper(cat.value), disponibilidade=disp.value, precoFiltro=preco.value, porPagina=parseInt(size.value,10);
        const filtradas=rows.filter(row => (!termo || normalizarShopper(row.cells[0].textContent).includes(termo)) && (!categoria || normalizarShopper(row.querySelector('input[id^="cat_"]').value)===categoria) && (disponibilidade==='todos' || row.querySelector('select[id^="disp_"]').value===disponibilidade) && (precoFiltro==='todos' || (precoFiltro==='com_preco' ? linhaTemPrecoShopper(row) : !linhaTemPrecoShopper(row))));
        const totalPaginas=Math.max(1,Math.ceil(filtradas.length/porPagina)); pagina=Math.min(pagina,totalPaginas);
        const paginaRows=new Set(filtradas.slice((pagina-1)*porPagina,pagina*porPagina));
        rows.forEach(row => row.style.display=paginaRows.has(row)?'':'none');
        resumo.textContent=`${filtradas.length} produto(s) encontrado(s)`; empty.style.display=filtradas.length?'none':'block';
        info.textContent=filtradas.length?`Página ${pagina} de ${totalPaginas}`:'Página 0 de 0'; actions.innerHTML='';
        const prev=document.createElement('button'); prev.className='page-btn'; prev.textContent='Anterior'; prev.disabled=pagina<=1; prev.onclick=()=>{pagina--;aplicar();}; actions.appendChild(prev);
        for(let p=1;p<=totalPaginas;p++){ if(totalPaginas>7 && Math.abs(p-pagina)>2 && p!==1 && p!==totalPaginas) continue; const b=document.createElement('button'); b.className='page-btn'+(p===pagina?' active':''); b.textContent=p; b.onclick=()=>{pagina=p;aplicar();}; actions.appendChild(b); }
        const next=document.createElement('button'); next.className='page-btn'; next.textContent='Próxima'; next.disabled=pagina>=totalPaginas; next.onclick=()=>{pagina++;aplicar();}; actions.appendChild(next);
    }
    [busca,disp,cat,preco,size].forEach(el => { el.addEventListener(el.tagName==='INPUT'?'input':'change',()=>aplicar(true)); });
    aplicar(true);
    document.getElementById('toggleColunasShopper').addEventListener('click', function () {
        const compacto=document.getElementById('shopperTable').classList.toggle('is-compact-columns');
        this.setAttribute('aria-pressed', compacto?'true':'false');
        this.innerHTML=compacto?'<i class="bi bi-eye"></i><span>Mostrar colunas</span>':'<i class="bi bi-eye-slash"></i><span>Ocultar colunas</span>';
    });
})();
</script>
<script src="exportacao_financeira_modal.js"></script>
<?php renderModalAdicionarProdutoTabela('shopper'); ?>
</body>
</html>



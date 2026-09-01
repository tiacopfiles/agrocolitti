<?php
require '../auth/proteger.php';
require '../config/conexao.php';
require '../config/ciclo_helper.php';
require '../config/layout_helper.php';
require '../config/calculos_preco.php';
require '../config/permissions.php';
require __DIR__ . '/produto_modal_helper.php';

requireModule('tabelas', '../index.php');

$conexao->query("
    CREATE TABLE IF NOT EXISTS preco_oba_embalado (
        id INT NOT NULL AUTO_INCREMENT,
        produto_id INT NOT NULL,
        gramagem DECIMAL(10,3) NOT NULL DEFAULT 0.000,
        valor_mp DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        qtd_por_caixa DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        categoria VARCHAR(100) NOT NULL DEFAULT '',
        custo_mp DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        frete_kauauti DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        frete_nivaldo DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        mao_de_obra DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        preco_calculado DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        custo_p_grama DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
        custo_bd DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        kg_da_caixa DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_preco_oba_produto (produto_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$cicloAtivo = getCicloAtivo($conexao);
$cfg = getConfigPrecificacao($conexao);

$resProdutos = $conexao->query("
    SELECT p.id, p.nome,
           po.gramagem, po.valor_mp, po.qtd_por_caixa, po.categoria,
           po.custo_mp, po.frete_kauauti, po.frete_nivaldo,
           po.mao_de_obra, po.preco_calculado,
           po.custo_p_grama, po.custo_bd, po.kg_da_caixa,
           CASE WHEN po.gramagem > 0 AND po.valor_mp > 0 THEN COALESCE(po.ativo, 1) ELSE 0 END AS disponivel
    FROM produtos p
    LEFT JOIN preco_oba_embalado po ON po.produto_id = p.id
    WHERE p.ativo = 1
      AND COALESCE(p.escopo_produto, 'normal') IN ('oba', 'ambos')
      AND (p.escopo_produto = 'oba' OR p.produto_principal_id IS NULL)
    ORDER BY p.nome
");
$produtos = $resProdutos ? $resProdutos->fetch_all(MYSQLI_ASSOC) : [];

$categoriasProdutos = [];
foreach ($produtos as $produtoFiltro) {
    $categoriaFiltro = trim((string) ($produtoFiltro['categoria'] ?? ''));
    if ($categoriaFiltro !== '') {
        $categoriasProdutos[$categoriaFiltro] = true;
    }
}
ksort($categoriasProdutos, SORT_NATURAL | SORT_FLAG_CASE);

function fmt_oba($v): string {
    return 'R$ ' . number_format((float) $v, 2, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>OBA Embalado</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6f9; color: #222; }
.container { padding: 24px 20px 32px; max-width: 1820px; margin: 0 auto; width: 100%; }
.card { background: #fff; padding: 22px 20px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,.06); margin-bottom: 24px; }
.section-title { margin: 0 0 6px; font-size: 18px; color: #1b5e20; font-weight: 700; }
.descricao { color: #555; font-size: 14px; margin: 0 0 16px; }
.msg-sucesso { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-weight: 700; }
.legend { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 12px; font-size: 12px; }
.legend span { display: flex; align-items: center; gap: 5px; }
.leg-dot { width: 12px; height: 12px; border-radius: 2px; flex-shrink: 0; }
.toolbar { display: flex; flex-wrap: wrap; gap: 14px; align-items: end; justify-content: space-between; margin: 16px 0 14px; }
.toolbar-group { display: flex; flex-direction: column; gap: 6px; min-width: 180px; }
.toolbar-group label { font-size: 13px; color: #444; font-weight: 700; }
.toolbar-group input, .toolbar-group select { width: 100% !important; min-height: 40px; padding: 8px 10px; font-size: 13px; border: 1px solid #ccc; border-radius: 6px; background: #fff; }
.toolbar-search { flex: 1 1 520px; min-width: 360px; max-width: 760px; }
.toolbar-filters { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 14px; margin-left: auto; }
.toolbar-meta { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin: 8px 0 12px; flex-wrap: wrap; font-size: 12px; color: #555; }
.table-wrap { overflow-x: auto; overflow-y: hidden; }
table { width: 100%; border-collapse: collapse; min-width: 1220px; }
#obaTable.is-compact-columns { min-width: 960px; table-layout: auto; }
#obaTable.is-compact-columns .optional-col { display: none; }
th { background: #1b5e20; color: #fff; padding: 10px 8px; font-size: 12px; white-space: nowrap; text-align: left; line-height: 1.3; position: sticky; top: 0; z-index: 3; box-shadow: inset 0 -1px 0 rgba(255,255,255,.14); }
th.editavel { background: #2e7d32; }
th.calculado { background: #4a148c; }
th.preco-col { background: #b71c1c; }
td { padding: 8px 8px; border-bottom: 1px solid #eee; font-size: 12px; vertical-align: middle; line-height: 1.3; }
tr:hover td { background: #f1f8f4; }
tr.indisponivel td:first-child { color: #999; text-decoration: line-through; }
input[type=number] { width: 84px; min-height: 34px; padding: 6px 8px; border: 1px solid #ccc; border-radius: 5px; font-size: 12px; }
input[type=text] { width: 104px; min-height: 34px; padding: 6px 8px; border: 1px solid #ccc; border-radius: 5px; font-size: 12px; }
input[type=number]:focus, input[type=text]:focus { outline: none; border-color: #2e7d32; box-shadow: 0 0 0 2px rgba(46,125,50,.15); }
select { min-height: 34px; padding: 6px 8px; border: 1px solid #ccc; border-radius: 5px; font-size: 12px; background: #fff; }
.calc-val { font-weight: 600; font-size: 12px; white-space: nowrap; color: #333; }
.preco-base { color: #4a148c; font-size: 13px; font-weight: 700; }
.preco-final { color: #b71c1c; font-size: 13px; font-weight: 700; }
.btn-salvar { background:#2e7d32; color:#fff; border:none; width:34px; height:34px; padding:0; border-radius:6px; cursor:pointer; font-weight:700; font-size:15px; display:inline-flex; align-items:center; justify-content:center; line-height:1; }
.btn-salvar:hover { background: #1b5e20; }
.btn-salvar:disabled { background: #aaa; cursor: not-allowed; }
.save-status { font-size: 11px; margin-left: 5px; display: block; margin-top: 3px; }
.save-ok { color: #2e7d32; }
.save-err { color: #c62828; }
.action-buttons { display: flex; gap: 8px; align-items: center; }
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
    <h2 class="section-title">Tabela de Preços - OBA Embalado</h2>
    <p class="descricao">
        Informe os campos editáveis (fundo verde). Os demais campos sao calculados automaticamente.
        <strong>Preço OBA</strong> aplica 5% sobre o preco base.
    </p>

    <div class="legend">
        <span><span class="leg-dot" style="background:#2e7d32;"></span> Coluna editável</span>
        <span><span class="leg-dot" style="background:#4a148c;"></span> Calculado automaticamente</span>
        <span><span class="leg-dot" style="background:#b71c1c;"></span> Preço OBA</span>
    </div>

    <div class="toolbar">
        <div class="toolbar-group toolbar-search">
            <label for="obaBusca">Buscar produto</label>
            <input type="text" id="obaBusca" placeholder="Digite parte do nome do produto">
        </div>
        <div class="toolbar-filters">
            <div class="toolbar-group">
                <label for="tabelaObaAcoes">Tabelas</label>
                <select id="tabelaObaAcoes" onchange="if(this.value) window.location.href=this.value;">
                    <option value="">OBA Embalado</option>
                    <option value="tabela_atacado.php">Atacado</option>
                    <option value="tabela_atacado_convencional.php">Atacado Convencional</option>
                    <option value="tabela_embalado.php">Embalado</option>
                    <option value="tabela_shopper.php">Shopper</option>
                    <option value="criar_tabela_personalizada.php">+ Criar nova tabela</option>
                    <option value="tabelas_personalizadas.php">Editar tabelas</option>
                </select>
            </div>
            <div class="toolbar-group">
                <label for="obaDisponibilidade">Disponibilidade</label>
                <select id="obaDisponibilidade">
                    <option value="todos">Todos</option>
                    <option value="1" selected>Disponível</option>
                    <option value="0">Indisponível</option>
                </select>
            </div>
            <div class="toolbar-group">
                <label for="obaCategoria">Categoria</label>
                <select id="obaCategoria">
                    <option value="">Todas</option>
                    <?php foreach (array_keys($categoriasProdutos) as $categoriaOption): ?>
                        <option value="<?= htmlspecialchars($categoriaOption, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($categoriaOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="toolbar-group">
                <label for="obaPreco">Preço</label>
                <select id="obaPreco">
                    <option value="todos" selected>Todos</option>
                    <option value="com_preco">Com preco</option>
                    <option value="sem_preco">Sem preco</option>
                </select>
            </div>
            <div class="toolbar-group">
                <label for="obaPageSize">Itens por pagina</label>
                <select id="obaPageSize">
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <div class="toolbar-group">
                <label>&nbsp;</label>
                <div class="action-buttons">
                    <?php renderBotaoAdicionarProdutoTabela(); ?>
                    <button type="button" id="toggleColunasOba" class="column-toggle-btn" aria-pressed="false" title="Ocultar colunas calculadas"><i class="bi bi-eye-slash"></i><span>Ocultar colunas</span></button>
                    </div>
            </div>
        </div>
    </div>

    <div class="toolbar-meta">
        <div>
            <div id="obaResumo">0 produto(s) encontrado(s)</div>
            <div>Busca, filtros e paginacao funcionam sem alterar os cálculos.</div>
        </div>
        <div class="export-actions">
            <a class="export-btn export-btn-excel" href="exportar_disponibilidade_xlsx.php?tipo=oba_embalado" data-export-financeiro data-tipo-tabela="oba_embalado" title="Baixar tabela em Excel"><i class="bi bi-file-earmark-excel"></i></a>
            <a class="export-btn export-btn-pdf" href="exportar_disponibilidade_pdf.php?tipo=oba_embalado" data-export-financeiro data-tipo-tabela="oba_embalado" title="Baixar tabela em PDF"><i class="bi bi-file-earmark-pdf"></i></a>
        </div>    </div>

    <div class="table-wrap">
    <table id="obaTable" data-no-responsive="1">
        <thead>
            <tr>
                <th class="editavel">Produto</th>
                <th class="editavel">Gramagem (kg)<br><small style="font-weight:400;">ex: 0,500</small></th>
                <th class="editavel">Valor MP (R$/kg)</th>
                <th class="editavel">Categoria<br><small style="font-weight:400;">texto livre</small></th>
                <th class="editavel">Qtd/Caixa<br><small style="font-weight:400;">unidades</small></th>
                <th class="calculado optional-col">D - Custo MP</th>
                <th class="calculado optional-col">E - Custo/g</th>
                <th class="calculado optional-col">G - Custo BD</th>
                <th class="calculado optional-col">I - Kg/Caixa</th>
                <th class="calculado">J - Fr. Kauauti</th>
                <th class="calculado">K - Fr. Nivaldo</th>
                <th class="calculado optional-col">L - Mao de Obra</th>
                <th class="preco-col">N - Preço Base</th>
                <th class="preco-col">O - Preço OBA (5%)</th>
                <th class="editavel">Disponível</th>
                <th class="editavel">Ação</th>
            </tr>
        </thead>
        <tbody id="obaTbody">
        <?php foreach ($produtos as $p):
            $pid = (int) $p['id'];
            $gram = (float) ($p['gramagem'] ?? 0);
            $vmp = (float) ($p['valor_mp'] ?? 0);
            $qtd = (float) ($p['qtd_por_caixa'] ?? 0);
            $cat = (string) ($p['categoria'] ?? '');
            $disp = (int) ($p['disponivel'] ?? 1);
            $temDados = $gram > 0 && $vmp > 0;
            $calc = $temDados ? calcEmbalado($gram, $vmp, $qtd, $cfg) : null;
            if ($calc) {
                $calc['frete_kauavuti'] = (float) ($p['frete_kauauti'] ?? 0);
                $calc['frete_nivaldo'] = (float) ($p['frete_nivaldo'] ?? 0);
                $calc['preco_produto_base'] = round((2 * $calc['valor_materia_x_gramagem']) + $calc['frete_kauavuti'] + $calc['frete_nivaldo'] + $calc['custo_fixo_embalado'], 2);
            }
            $precoOba = $calc ? round((float) $calc['preco_produto_base'] * 1.05, 2) : 0;
        ?>
        <tr class="<?= !$temDados ? 'sem-preco' : '' ?> <?= !$disp ? 'indisponivel' : '' ?>" data-produto-row="1">
            <td><?= htmlspecialchars($p['nome']) ?></td>
            <td><input type="number" step="0.001" min="0.001" id="gram_<?= $pid ?>" value="<?= $gram > 0 ? number_format($gram, 3, '.', '') : '' ?>" placeholder="0,500" oninput="recalcOba(<?= $pid ?>)"></td>
            <td><input type="number" step="0.01" min="0" id="mp_<?= $pid ?>" value="<?= $vmp > 0 ? number_format($vmp, 2, '.', '') : '' ?>" placeholder="0,00" oninput="recalcOba(<?= $pid ?>)"></td>
            <td><input type="text" id="cat_<?= $pid ?>" value="<?= htmlspecialchars($cat) ?>" placeholder="ex: folhosa"></td>
            <td><input type="number" step="1" min="0" id="qtd_<?= $pid ?>" value="<?= $qtd > 0 ? number_format($qtd, 0, '.', '') : '' ?>" placeholder="0" oninput="recalcOba(<?= $pid ?>)"></td>
            <td class="optional-col"><span id="vmg_<?= $pid ?>" class="calc-val"><?= $calc ? fmt_oba($calc['valor_materia_x_gramagem']) : '-' ?></span></td>
            <td class="optional-col"><span id="cpg_<?= $pid ?>" class="calc-val"><?= $calc ? number_format($calc['custo_p_grama'], 4, ',', '') : '-' ?></span></td>
            <td class="optional-col"><span id="cbd_<?= $pid ?>" class="calc-val"><?= $calc ? fmt_oba($calc['custo_bd']) : '-' ?></span></td>
            <td class="optional-col"><span id="kgc_<?= $pid ?>" class="calc-val"><?= $calc ? number_format($calc['kg_da_caixa'], 0, ',', '') : '-' ?></span></td>
            <td><input type="number" step="0.01" min="0" id="fk_input_<?= $pid ?>" value="<?= $calc ? number_format((float) $calc['frete_kauavuti'], 2, '.', '') : '0.00' ?>" oninput="recalcOba(<?= $pid ?>)"></td>
            <td><input type="number" step="0.01" min="0" id="fn_input_<?= $pid ?>" value="<?= $calc ? number_format((float) $calc['frete_nivaldo'], 2, '.', '') : '0.00' ?>" oninput="recalcOba(<?= $pid ?>)"></td>
            <td class="optional-col"><span id="mob_<?= $pid ?>" class="calc-val"><?= $calc ? fmt_oba($calc['custo_fixo_embalado']) : '-' ?></span></td>
            <td><span id="preco_base_<?= $pid ?>" class="calc-val preco-base"><?= $calc ? fmt_oba($calc['preco_produto_base']) : '-' ?></span></td>
            <td><span id="preco_oba_<?= $pid ?>" class="calc-val preco-final"><?= $calc ? fmt_oba($precoOba) : '-' ?></span></td>
            <td>
                <select id="disp_<?= $pid ?>">
                    <option value="1" <?= $disp ? 'selected' : '' ?>>Disponível</option>
                    <option value="0" <?= !$disp ? 'selected' : '' ?>>Indisponível</option>
                </select>
            </td>
            <td style="white-space:nowrap;">
                <button id="btn_<?= $pid ?>" class="btn-salvar" onclick="salvarOba(<?= $pid ?>)" title="Salvar"><i class="bi bi-check-lg"></i></button>
                <button type="button" class="btn-excluir-produto-tabela" onclick="excluirProdutoTabela(<?= $pid ?>, <?= htmlspecialchars(json_encode((string) $p['nome'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>)" title="Excluir produto"><i class="bi bi-trash"></i></button>
                <span id="stat_<?= $pid ?>" class="save-status"></span>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div id="obaEmpty" class="table-empty">Nenhum produto encontrado com os filtros atuais.</div>
    <div class="pagination">
        <div id="obaPaginacaoInfo" class="pagination-info">Página 0 de 0</div>
        <div id="obaPaginacao" class="pagination-actions"></div>
    </div>
</div>
</div>

<script>
const CFG = { custo_fixo_embalado: 1.25 };

function calcObaJS(gramagem, valorMp, qtdPorCaixa, freteKauauti, freteNivaldo) {
    const valorMateriaXGramagem = valorMp * gramagem;
    const custoPGrama = (valorMp / 1000) * 10;
    const custoBd = custoPGrama * gramagem * 100;
    const kgDaCaixa = qtdPorCaixa * gramagem;
    const precoBase = Math.round(((2 * valorMateriaXGramagem) + freteKauauti + freteNivaldo + CFG.custo_fixo_embalado) * 100) / 100;
    const precoOba = Math.round((precoBase * 1.05) * 100) / 100;
    return { valorMateriaXGramagem, custoPGrama, custoBd, kgDaCaixa, precoBase, precoOba };
}

function fmt(v) {
    return 'R$ ' + Number(v || 0).toFixed(2).replace('.', ',');
}

function fmtSmall(v, casas) {
    return Number(v || 0).toFixed(casas ?? 4).replace('.', ',');
}

function normalizarBuscaTexto(texto) {
    return (texto || '').toString().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
}

function recalcOba(pid) {
    const gram = parseFloat(document.getElementById('gram_' + pid).value) || 0;
    const mp = parseFloat(document.getElementById('mp_' + pid).value) || 0;
    const qtd = parseFloat(document.getElementById('qtd_' + pid).value) || 0;
    const fk = parseFloat(document.getElementById('fk_input_' + pid).value) || 0;
    const fn = parseFloat(document.getElementById('fn_input_' + pid).value) || 0;
    const ids = ['vmg_','cpg_','cbd_','kgc_','mob_','preco_base_','preco_oba_'];
    if (gram <= 0 || mp <= 0) {
        ids.forEach((prefix) => {
            const el = document.getElementById(prefix + pid);
            if (el) el.textContent = '-';
        });
        return;
    }

    const c = calcObaJS(gram, mp, qtd, fk, fn);
    document.getElementById('vmg_' + pid).textContent = fmt(c.valorMateriaXGramagem);
    document.getElementById('cpg_' + pid).textContent = fmtSmall(c.custoPGrama, 4);
    document.getElementById('cbd_' + pid).textContent = fmt(c.custoBd);
    document.getElementById('kgc_' + pid).textContent = String(Math.round(c.kgDaCaixa));
    document.getElementById('mob_' + pid).textContent = fmt(CFG.custo_fixo_embalado);
    document.getElementById('preco_base_' + pid).textContent = fmt(c.precoBase);
    document.getElementById('preco_oba_' + pid).textContent = fmt(c.precoOba);
}

async function salvarOba(pid) {
    const btn = document.getElementById('btn_' + pid);
    const stat = document.getElementById('stat_' + pid);
    const gram = document.getElementById('gram_' + pid).value;
    const mp = document.getElementById('mp_' + pid).value;
    const qtd = document.getElementById('qtd_' + pid).value;
    const cat = document.getElementById('cat_' + pid).value;
    const fk = document.getElementById('fk_input_' + pid).value;
    const fn = document.getElementById('fn_input_' + pid).value;
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
        fd.append('produto_id', pid);
        fd.append('gramagem', gram);
        fd.append('valor_mp', mp);
        fd.append('qtd_por_caixa', qtd);
        fd.append('categoria', cat);
        fd.append('frete_kauauti', fk);
        fd.append('frete_nivaldo', fn);
        fd.append('ativo', disp);

        const r = await fetch('actions/salvar_preco_oba_embalado.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.ok) {
            stat.textContent = 'OK Salvo';
            stat.className = 'save-status save-ok';
        } else {
            stat.textContent = 'Erro ' + (d.erro || 'Erro');
            stat.className = 'save-status save-err';
        }
    } catch (e) {
        stat.textContent = 'Erro Falha na rede';
        stat.className = 'save-status save-err';
    } finally {
        btn.disabled = false;
        setTimeout(() => { stat.textContent = ''; }, 4000);
    }
}

function configurarTabelaOba() {
    const tbody = document.getElementById('obaTbody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr[data-produto-row="1"]'));
    const busca = document.getElementById('obaBusca');
    const disponibilidade = document.getElementById('obaDisponibilidade');
    const categoria = document.getElementById('obaCategoria');
    const preco = document.getElementById('obaPreco');
    const pageSize = document.getElementById('obaPageSize');
    const resumo = document.getElementById('obaResumo');
    const empty = document.getElementById('obaEmpty');
    const pagInfo = document.getElementById('obaPaginacaoInfo');
    const pagActions = document.getElementById('obaPaginacao');
    let paginaAtual = 1;

    function linhaTemPreco(row) {
        const gram = parseFloat(row.querySelector('input[id^="gram_"]')?.value || '0');
        const mp = parseFloat(row.querySelector('input[id^="mp_"]')?.value || '0');
        return gram > 0 && mp > 0;
    }
    function linhaCategoria(row) { return normalizarBuscaTexto(row.querySelector('input[id^="cat_"]')?.value || ''); }
    function linhaDisponivel(row) { return row.querySelector('select[id^="disp_"]')?.value || '1'; }
    function linhaProduto(row) { return normalizarBuscaTexto(row.querySelector('td')?.textContent || ''); }

    function renderPaginacao(totalFiltrados, totalPaginas) {
        pagActions.innerHTML = '';
        pagInfo.textContent = totalFiltrados > 0 ? `Página ${paginaAtual} de ${totalPaginas}` : 'Página 0 de 0';
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
        rows.forEach((row) => { row.style.display = pagina.has(row) ? '' : 'none'; });
        resumo.textContent = `${totalFiltrados} produto(s) encontrado(s)`;
        empty.style.display = totalFiltrados === 0 ? 'block' : 'none';
        renderPaginacao(totalFiltrados, totalPaginas);
    }

    [busca, disponibilidade, categoria, preco, pageSize].forEach((el) => {
        if (!el) return;
        const evento = el.tagName === 'INPUT' ? 'input' : 'change';
        el.addEventListener(evento, () => aplicar(true));
    });

    aplicar(true);
}

function configurarToggleColunasOba() {
    const btn = document.getElementById('toggleColunasOba');
    const table = document.getElementById('obaTable');
    if (!btn || !table) return;
    btn.addEventListener('click', () => {
        const compacto = table.classList.toggle('is-compact-columns');
        btn.setAttribute('aria-pressed', compacto ? 'true' : 'false');
        btn.innerHTML = compacto
            ? '<i class="bi bi-eye"></i><span>Mostrar colunas</span>'
            : '<i class="bi bi-eye-slash"></i><span>Ocultar colunas</span>';
    });
}

document.addEventListener('DOMContentLoaded', () => {
    configurarTabelaOba();
    configurarToggleColunasOba();
});
</script>
<script src="exportacao_financeira_modal.js?v=20260713b"></script>
<?php renderModalAdicionarProdutoTabela('oba_embalado'); ?>
</body>
</html>



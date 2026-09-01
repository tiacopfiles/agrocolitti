<?php
require '../auth/proteger.php';
require '../config/conexao.php';
require '../config/layout_helper.php';
require '../config/permissions.php';
require '../config/tabelas_preco_flex_schema.php';

requireModule('tabelas', '../index.php');
requireTabelasEditPermission('tabelas_personalizadas.php');
garantirTabelasPrecoFlex($conexao);

$tabelaId = max(0, (int) ($_GET['id'] ?? 0));
$tabelaAtual = null;
$valoresColunas = ['Nome do item/produto', 'Preço'];
$linhasExistentes = [];
$msg = (string) ($_GET['msg'] ?? '');

if ($tabelaId > 0) {
    $stmt = $conexao->prepare("SELECT id, nome, tipo FROM tabelas_preco WHERE id = ? AND ativo = 1 LIMIT 1");
    $stmt->bind_param('i', $tabelaId);
    $stmt->execute();
    $resTabela = $stmt->get_result();
    $tabelaAtual = $resTabela ? $resTabela->fetch_assoc() : null;
    $stmt->close();

    if (!$tabelaAtual) {
        header('Location: tabelas_personalizadas.php?msg=erro');
        exit;
    }

    $colunas = [];
    $stmt = $conexao->prepare("SELECT id, nome_coluna, ordem FROM tabelas_preco_colunas WHERE tabela_id = ? ORDER BY ordem ASC, id ASC");
    $stmt->bind_param('i', $tabelaId);
    $stmt->execute();
    $resColunas = $stmt->get_result();
    while ($resColunas && ($coluna = $resColunas->fetch_assoc())) {
        $colunas[] = $coluna;
    }
    $stmt->close();

    if ($colunas) {
        $valoresColunas = array_map(static fn(array $coluna): string => (string) $coluna['nome_coluna'], $colunas);
    }

    $stmt = $conexao->prepare("SELECT id, dados_json, ordem FROM tabelas_preco_linhas WHERE tabela_id = ? ORDER BY ordem ASC, id ASC");
    $stmt->bind_param('i', $tabelaId);
    $stmt->execute();
    $resLinhas = $stmt->get_result();
    while ($resLinhas && ($linha = $resLinhas->fetch_assoc())) {
        $dados = json_decode((string) $linha['dados_json'], true);
        $linha['dados'] = is_array($dados) ? array_values($dados) : [];
        $linhasExistentes[] = $linha;
    }
    $stmt->close();
}

$tituloPagina = $tabelaId > 0 ? 'Editar tabela' : 'Criar nova tabela';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Criar Nova Tabela</title>
<style>
body { margin:0; font-family:'Segoe UI',Arial,sans-serif; background:#f4f6f9; color:#222; }
.container { padding:24px 20px 32px; max-width:1200px; margin:0 auto; }
.card { background:#fff; padding:22px 20px; border-radius:10px; box-shadow:0 4px 12px rgba(0,0,0,.06); margin-bottom:24px; }
.section-title { margin:0 0 14px; font-size:18px; color:#1b5e20; font-weight:700; }
label { display:block; font-size:13px; color:#444; font-weight:700; margin-bottom:6px; }
input { min-height:34px; padding:6px 8px; border:1px solid #ccc; border-radius:5px; font-size:12px; width:100%; }
table { width:100%; border-collapse:collapse; min-width:760px; }
th { background:#1b5e20; color:#fff; padding:10px 8px; font-size:12px; text-align:left; }
td { padding:8px; border-bottom:1px solid #eee; font-size:12px; vertical-align:middle; }
.table-wrap { overflow-x:auto; }
.toolbar { display:flex; gap:12px; align-items:end; flex-wrap:wrap; margin-bottom:16px; }
.toolbar > div { flex:1 1 220px; }
.btn { background:#2e7d32; color:#fff; border:none; padding:8px 13px; border-radius:6px; cursor:pointer; font-weight:700; font-size:12px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
.btn-secondary { background:#607d8b; }
.btn-small { padding:6px 10px; }
.actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:14px; }
.msg { background:#ffebee; color:#c62828; border:1px solid #ef9a9a; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-weight:700; }
.colunas-wrap { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px; margin-bottom:10px; }
</style>
<?php renderAppLayoutStyles(); ?>
<script>
let novaLinhaSeq = 0;
function addColuna() {
    const wrap = document.getElementById('colunasWrap');
    const input = document.createElement('input');
    input.name = 'colunas[]';
    input.required = true;
    input.placeholder = 'Nome da coluna';
    wrap.appendChild(input);

    const headRow = document.getElementById('colunasHead');
    const th = document.createElement('th');
    th.textContent = 'Nova coluna';
    headRow.insertBefore(th, headRow.lastElementChild);

    document.querySelectorAll('#linhasBody tr').forEach(function (tr) {
        const td = document.createElement('td');
        const rowName = tr.getAttribute('data-row-name');
        const field = document.createElement('input');
        field.name = rowName;
        td.appendChild(field);
        tr.insertBefore(td, tr.lastElementChild);
    });

    input.addEventListener('input', atualizarCabecalhos);
    input.focus();
    atualizarCabecalhos();
}
function atualizarCabecalhos() {
    const nomes = Array.from(document.querySelectorAll('input[name="colunas[]"]')).map(function (input) {
        return input.value.trim() || 'Coluna';
    });
    const ths = Array.from(document.querySelectorAll('#colunasHead th'));
    nomes.forEach(function (nome, idx) {
        if (ths[idx]) ths[idx].textContent = nome;
    });
}
function addLinha() {
    const tbody = document.getElementById('linhasBody');
    const colunas = Array.from(document.querySelectorAll('input[name="colunas[]"]'));
    const tr = document.createElement('tr');
    const linhaKey = 'nova_' + (++novaLinhaSeq);
    const rowName = 'linhas_novas[' + linhaKey + '][]';
    tr.setAttribute('data-row-name', rowName);

    colunas.forEach(function (coluna) {
        const td = document.createElement('td');
        const input = document.createElement('input');
        input.name = rowName;
        input.placeholder = coluna.value || 'Coluna';
        td.appendChild(input);
        tr.appendChild(td);
    });

    const tdAcoes = document.createElement('td');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-secondary btn-small';
    btn.innerHTML = '<i class="bi bi-trash"></i>';
    btn.title = 'Remover linha';
    btn.onclick = function () { tr.remove(); };
    tdAcoes.appendChild(btn);
    tr.appendChild(tdAcoes);
    tbody.appendChild(tr);
}
function removerLinhaExistente(btn, linhaId) {
    const form = btn.closest('form');
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'excluir_linhas[]';
    hidden.value = linhaId;
    form.appendChild(hidden);
    btn.closest('tr').remove();
}
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('input[name="colunas[]"]').forEach(function (input) {
        input.addEventListener('input', atualizarCabecalhos);
    });
    atualizarCabecalhos();
});
</script>
</head>
<body>
<?php renderAppHeader('..'); ?>
<div class="container">
<?php if ($msg === 'erro'): ?><div class="msg">Nao foi possivel salvar a tabela.</div><?php endif; ?>
<div class="card" id="form-tabela">
    <h2 class="section-title"><?= htmlspecialchars($tituloPagina) ?></h2>
    <form method="POST" action="actions/salvar_tabela_personalizada.php">
        <input type="hidden" name="id" value="<?= (int) $tabelaId ?>">
        <div class="toolbar">
            <div>
                <label>Nome da tabela</label>
                <input name="nome" required value="<?= htmlspecialchars((string) ($tabelaAtual['nome'] ?? '')) ?>" placeholder="Ex.: Tabela Especial">
            </div>
        </div>

        <label>Colunas</label>
        <div id="colunasWrap" class="colunas-wrap">
            <?php foreach ($valoresColunas as $colunaNome): ?>
                <input name="colunas[]" required value="<?= htmlspecialchars((string) $colunaNome) ?>" placeholder="Nome da coluna">
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-secondary btn-small" onclick="addColuna()"><i class="bi bi-plus-lg"></i> Coluna</button>

        <div class="table-wrap" style="margin-top:14px;">
            <table>
                <thead>
                    <tr id="colunasHead">
                        <?php foreach ($valoresColunas as $colunaNome): ?>
                            <th><?= htmlspecialchars((string) $colunaNome) ?></th>
                        <?php endforeach; ?>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody id="linhasBody">
                    <?php foreach ($linhasExistentes as $linha): ?>
                        <?php $linhaId = (int) $linha['id']; ?>
                        <tr data-row-name="linhas_existentes[<?= $linhaId ?>][]">
                            <?php foreach ($valoresColunas as $idx => $colunaNome): ?>
                                <td>
                                    <input name="linhas_existentes[<?= $linhaId ?>][]" value="<?= htmlspecialchars((string) ($linha['dados'][$idx] ?? '')) ?>" placeholder="<?= htmlspecialchars((string) $colunaNome) ?>">
                                </td>
                            <?php endforeach; ?>
                            <td>
                                <button type="button" class="btn btn-secondary btn-small" onclick="removerLinhaExistente(this, <?= $linhaId ?>)" title="Remover linha">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="actions">
            <button type="button" class="btn btn-secondary" onclick="addLinha()"><i class="bi bi-plus-lg"></i> Linha</button>
            <button type="submit" class="btn"><i class="bi bi-check-lg"></i> Salvar</button>
            <a class="btn btn-secondary" href="tabelas_personalizadas.php">Voltar</a>
        </div>
    </form>
</div>
</div>
</body>
</html>

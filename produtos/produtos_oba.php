<?php
require "../auth/proteger.php";
require "../config/conexao.php";
require "../config/ciclo_helper.php";
require "../config/layout_helper.php";
require "../config/permissions.php";
require "../config/cadastro_fiscal_helper.php";
require "../config/produto_vinculo_helper.php";

requirePermission(PERM_ADMIN, '../index.php');

function produtoObaTexto(array $post, string $campo): string
{
    return trim((string) ($post[$campo] ?? ''));
}

function produtoObaDados(array $post): array
{
    $nome = produtoObaTexto($post, 'nome');
    $codigo = produtoObaTexto($post, 'codigo_interno');
    $unidade = strtoupper(produtoObaTexto($post, 'unidade'));
    $descricao = produtoObaTexto($post, 'nfe_descricao');
    $ncm = cadastroFiscalDigitos($post['nfe_ncm'] ?? '');
    $tipo = produtoObaTexto($post, 'nfe_tipo');
    $principalId = (int) ($post['produto_principal_id'] ?? 0);

    if ($nome === '') {
        throw new InvalidArgumentException('Informe o nome do produto OBA.');
    }
    if (!in_array($unidade, ['KG', 'UN', 'CX'], true)) {
        throw new InvalidArgumentException('Selecione a unidade comercial.');
    }
    if ($principalId <= 0) {
        throw new InvalidArgumentException('Selecione o produto principal vinculado.');
    }
    if ($ncm !== '' && strlen($ncm) !== 8) {
        throw new InvalidArgumentException('O NCM deve ter 8 digitos ou ficar vazio.');
    }
    if ($tipo !== '' && !in_array($tipo, ['Mercadoria para Revenda', 'Producao propria'], true)) {
        throw new InvalidArgumentException('Selecione uma classificacao fiscal valida.');
    }

    return [
        'nome' => $nome,
        'codigo_interno' => $codigo,
        'nfe_codigo_interno' => $codigo,
        'unidade' => $unidade,
        'produto_principal_id' => $principalId,
        'ncm' => $ncm,
        'nfe_ncm' => $ncm,
        'nfe_descricao' => $descricao,
        'nfe_tipo' => $tipo,
        'nfe_unidade' => $unidade,
        'nfe_origem' => 'NACIONAL',
    ];
}

$erroCadastro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['criar']) || isset($_POST['editar']))) {
    validarTokenCsrf();
    try {
        $dados = produtoObaDados($_POST);
        if (isset($_POST['criar'])) {
            $stmt = $conexao->prepare("\n                INSERT INTO produtos\n                (nome, codigo_interno, nfe_codigo_interno, unidade, ativo, produto_principal_id, escopo_produto, ncm, nfe_ncm, nfe_descricao, nfe_tipo, nfe_unidade, nfe_origem)\n                VALUES (?, ?, ?, ?, 1, ?, 'oba', ?, ?, ?, ?, ?, ?)\n            ");
            $stmt->bind_param(
                'ssssissssss',
                $dados['nome'], $dados['codigo_interno'], $dados['nfe_codigo_interno'], $dados['unidade'],
                $dados['produto_principal_id'], $dados['ncm'], $dados['nfe_ncm'],
                $dados['nfe_descricao'], $dados['nfe_tipo'], $dados['nfe_unidade'], $dados['nfe_origem']
            );
        } else {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) throw new InvalidArgumentException('Produto invalido.');
            $stmt = $conexao->prepare("\n                UPDATE produtos\n                SET nome = ?, codigo_interno = ?, nfe_codigo_interno = ?, unidade = ?, produto_principal_id = ?, escopo_produto = 'oba',\n                    ncm = ?, nfe_ncm = ?, nfe_descricao = ?, nfe_tipo = ?, nfe_unidade = ?, nfe_origem = ?\n                WHERE id = ?\n            ");
            $stmt->bind_param(
                'ssssissssssi',
                $dados['nome'], $dados['codigo_interno'], $dados['nfe_codigo_interno'], $dados['unidade'],
                $dados['produto_principal_id'], $dados['ncm'], $dados['nfe_ncm'],
                $dados['nfe_descricao'], $dados['nfe_tipo'], $dados['nfe_unidade'], $dados['nfe_origem'], $id
            );
        }
        $stmt->execute();
        $stmt->close();
        header("Location: produtos_oba.php?msg=salvo");
        exit;
    } catch (Throwable $e) {
        $erroCadastro = $e->getMessage();
    }
}

if (isset($_GET['desativar']) || isset($_GET['reativar'])) {
    $id = (int) ($_GET['desativar'] ?? $_GET['reativar']);
    $ativo = isset($_GET['reativar']) ? 1 : 0;
    $stmt = $conexao->prepare("UPDATE produtos SET ativo = ? WHERE id = ? AND escopo_produto = 'oba'");
    $stmt->bind_param('ii', $ativo, $id);
    $stmt->execute();
    header("Location: produtos_oba.php");
    exit;
}

if (isset($_GET['excluir'])) {
    $id = (int) $_GET['excluir'];
    $stmt = $conexao->prepare("DELETE FROM produtos WHERE id = ? AND escopo_produto = 'oba'");
    $stmt->bind_param('i', $id);
    try {
        $stmt->execute();
        header("Location: produtos_oba.php?msg=excluido");
    } catch (mysqli_sql_exception $e) {
        $stmtDesativar = $conexao->prepare("UPDATE produtos SET ativo = 0 WHERE id = ? AND escopo_produto = 'oba'");
        $stmtDesativar->bind_param('i', $id);
        $stmtDesativar->execute();
        header("Location: produtos_oba.php?msg=produto_vinculado");
    }
    exit;
}

$produtosPrincipaisLista = [];
$resPrincipais = $conexao->query("\n    SELECT id, nome\n    FROM produtos\n    WHERE ativo = 1\n      AND produto_principal_id IS NULL\n      AND COALESCE(escopo_produto, 'normal') IN ('normal', 'ambos')\n    ORDER BY nome ASC\n");
if ($resPrincipais) {
    while ($row = $resPrincipais->fetch_assoc()) {
        $produtosPrincipaisLista[] = $row;
    }
}
$produtos = $conexao->query("\n    SELECT p.*, pp.nome AS produto_principal_nome\n    FROM produtos p\n    LEFT JOIN produtos pp ON pp.id = p.produto_principal_id\n    WHERE p.escopo_produto IN ('oba', 'ambos')\n    ORDER BY p.escopo_produto = 'ambos', pp.nome, p.nome\n");
$cicloAtivo = getCicloAtivo($conexao);
?>
<!DOCTYPE html>
<html lang="pt-br"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Produtos OBA</title>
<link rel="stylesheet" href="../assets/css/cadastro-fiscal.css?v=20260603-1">
<style>
*{box-sizing:border-box}body{font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;margin:0;color:#333}.container{padding:30px;max-width:1200px;margin:auto}.card{background:#fff;padding:25px;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,.06);margin-bottom:30px}.form-title{margin:0 0 20px}.msg,.msg-ciclo{padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600}.msg.ok,.msg-ciclo{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7}.msg.aviso{background:#fff8e1;color:#8a4b00;border:1px solid #ffe08a}.msg.erro{background:#ffebee;color:#b71c1c;border:1px solid #ef9a9a}
input,select{width:100%;padding:10px;margin-top:5px;border-radius:6px;border:1px solid #ccc;font-size:14px}label{font-size:13px;font-weight:700}button{padding:10px 16px;border:0;border-radius:6px;font-weight:600;cursor:pointer}.btn-primary,.btn-edit{background:#2e7d32;color:#fff}.btn-secondary{background:#e9ecef}.btn-danger,.btn-delete{background:#c0392b;color:#fff}.btn-success{background:#27ae60;color:#fff}
.table-responsive{overflow-x:auto}table{width:100%;min-width:920px;border-collapse:collapse}th{background:#1b5e20;color:#fff;padding:14px}td{padding:13px;text-align:center;border-bottom:1px solid #eee}.acoes{display:flex;gap:8px;justify-content:center}.acoes button{width:34px;height:34px;padding:0}.badge{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700}.badge.ativo{background:#e8f5e9;color:#1b5e20}.badge.inativo{background:#eee;color:#555}.badge.oba{background:#e3f2fd;color:#0d47a1}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.48);display:none;align-items:center;justify-content:center;padding:20px;z-index:1000}.modal-overlay.is-open{display:flex}.modal-card{background:#fff;width:min(780px,100%);max-height:92vh;overflow:auto;border-radius:12px;padding:24px}.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}.modal-header h2{margin:0}.modal-close{width:36px;height:36px;padding:0;background:#f1f3f4}.hint{font-size:12px;color:#667;margin-top:6px}.field-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.field-grid .full{grid-column:1/-1}.form-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:16px}@media(max-width:760px){.field-grid{grid-template-columns:1fr}.container{padding:16px}}
</style><?php renderAppLayoutStyles(); ?></head><body>
<?php renderAppHeader('..'); ?><div class="container">
<?php if ($cicloAtivo): ?><div class="msg-ciclo">Ciclo ativo: <?= htmlspecialchars(getNomeCiclo($cicloAtivo)) ?></div><?php endif; ?>
<?php if ($erroCadastro !== ''): ?><div class="msg erro"><?= htmlspecialchars($erroCadastro) ?></div><?php elseif (($_GET['msg'] ?? '') === 'salvo'): ?><div class="msg ok">Produto OBA salvo.</div><?php elseif (($_GET['msg'] ?? '') === 'excluido'): ?><div class="msg ok">Produto OBA excluido.</div><?php elseif (($_GET['msg'] ?? '') === 'produto_vinculado'): ?><div class="msg aviso">Produto com historico: foi desativado.</div><?php endif; ?>
<div class="card" id="cardNovoProduto"><h2 class="form-title">Novo Produto OBA</h2>
<form method="POST" class="field-grid">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
<label class="full">Nome do produto OBA<input type="text" name="nome" required placeholder="Ex.: Abobrinha Italiana Orgânica 600g"></label>
<label>Codigo do produto<input type="text" name="codigo_interno" maxlength="50"><span class="hint">Opcional; deixe vazio enquanto o fiscal nao estiver completo.</span></label>
<label>Unidade comercial<select name="unidade" required><option value="KG">Quilograma (KG)</option><option value="UN">Unidade (UN)</option><option value="CX">Caixa (CX)</option></select></label>
<label class="full">Produto principal vinculado<select name="produto_principal_id" required><option value="">Selecione o produto principal do estoque</option><?php foreach ($produtosPrincipaisLista as $principal): ?><option value="<?= (int) $principal['id'] ?>"><?= htmlspecialchars($principal['nome']) ?></option><?php endforeach; ?></select></label>
<label class="full">Descricao fiscal<input type="text" name="nfe_descricao" maxlength="120"><span class="hint">Opcional; sem descricao/NCM/codigo o produto aparecera como fiscal incompleto.</span></label>
<label>NCM<input type="text" name="nfe_ncm" maxlength="10" placeholder="8 digitos"></label>
<label>Classificacao fiscal<select name="nfe_tipo"><option value="">Pendente</option><option value="Mercadoria para Revenda">Mercadoria para revenda</option><option value="Producao propria">Producao propria</option></select></label>
<div class="form-actions full"><button type="submit" name="criar" class="btn-primary"><i class="bi bi-plus-lg"></i> Adicionar</button></div>
</form></div>
<div class="card"><h2 class="form-title">Produtos OBA cadastrados</h2><div class="table-responsive"><table><tr><th>ID</th><th>Codigo</th><th>Nome OBA</th><th>Produto principal</th><th>Unidade</th><th>NCM</th><th>Fiscal</th><th>Status</th><th>Acoes</th></tr>
<?php while ($p = $produtos->fetch_assoc()): $ncm = ($p['ncm'] ?? '') ?: ($p['nfe_ncm'] ?? ''); $ok = cadastroFiscalProdutoCompleto($p); $ehAmbos = ($p['escopo_produto'] ?? '') === 'ambos'; ?>
<tr><td><?= (int) $p['id'] ?></td><td><?= htmlspecialchars(($p['codigo_interno'] ?? '') ?: ($p['nfe_codigo_interno'] ?? '')) ?></td><td><?= htmlspecialchars($p['nome']) ?> <span class="badge <?= $ehAmbos ? 'ativo' : 'oba' ?>"><?= $ehAmbos ? 'Ambos' : 'OBA' ?></span></td><td><?= htmlspecialchars($ehAmbos ? 'Compartilhado com tabelas normais' : ($p['produto_principal_nome'] ?? '')) ?></td><td><?= htmlspecialchars($p['unidade'] ?? '') ?></td><td><?= htmlspecialchars($ncm) ?></td><td><span class="fiscal-badge <?= $ok ? 'ok' : '' ?>"><?= $ok ? 'Pronto NF-e' : 'Incompleto' ?></span></td><td><span class="badge <?= $p['ativo'] ? 'ativo' : 'inativo' ?>"><?= $p['ativo'] ? 'Ativo' : 'Inativo' ?></span></td><td><div class="acoes">
<?php if (!$ehAmbos): ?>
<button type="button" class="btn-edit js-open-edit" data-id="<?= (int) $p['id'] ?>" data-nome="<?= htmlspecialchars($p['nome'], ENT_QUOTES) ?>" data-codigo-interno="<?= htmlspecialchars(($p['codigo_interno'] ?? '') ?: ($p['nfe_codigo_interno'] ?? ''), ENT_QUOTES) ?>" data-unidade="<?= htmlspecialchars(strtoupper((string) ($p['unidade'] ?? 'KG')), ENT_QUOTES) ?>" data-produto-principal-id="<?= (int) ($p['produto_principal_id'] ?? 0) ?>" data-nfe-descricao="<?= htmlspecialchars($p['nfe_descricao'] ?? '', ENT_QUOTES) ?>" data-nfe-ncm="<?= htmlspecialchars($ncm, ENT_QUOTES) ?>" data-nfe-tipo="<?= htmlspecialchars($p['nfe_tipo'] ?? '', ENT_QUOTES) ?>"><i class="bi bi-pencil"></i></button>
<?php if ($p['ativo']): ?><a href="?desativar=<?= (int) $p['id'] ?>" onclick="return confirm('Desativar este produto OBA?')"><button type="button" class="btn-danger"><i class="bi bi-slash-circle"></i></button></a><?php else: ?><a href="?reativar=<?= (int) $p['id'] ?>" onclick="return confirm('Reativar este produto OBA?')"><button type="button" class="btn-success"><i class="bi bi-arrow-clockwise"></i></button></a><?php endif; ?>
<a href="?excluir=<?= (int) $p['id'] ?>" onclick="return confirm('Excluir este produto OBA definitivamente?')"><button type="button" class="btn-delete"><i class="bi bi-trash"></i></button></a>
<?php else: ?><span class="badge ativo" title="Produto compartilhado: edite pela tela Produtos.">Compartilhado</span><?php endif; ?></div></td></tr><?php endwhile; ?>
</table></div></div></div>
<div class="modal-overlay" id="modalEditarProduto"><div class="modal-card"><div class="modal-header"><h2>Editar Produto OBA</h2><button type="button" class="modal-close js-close-edit"><i class="bi bi-x-lg"></i></button></div>
<form method="POST" class="field-grid" id="formEditarProduto"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>"><input type="hidden" name="id">
<label class="full">Nome do produto OBA<input type="text" name="nome" required></label>
<label>Codigo do produto<input type="text" name="codigo_interno" maxlength="50"></label>
<label>Unidade comercial<select name="unidade" required><option value="KG">Quilograma (KG)</option><option value="UN">Unidade (UN)</option><option value="CX">Caixa (CX)</option></select></label>
<label class="full">Produto principal vinculado<select name="produto_principal_id" required><option value="">Selecione</option><?php foreach ($produtosPrincipaisLista as $principal): ?><option value="<?= (int) $principal['id'] ?>"><?= htmlspecialchars($principal['nome']) ?></option><?php endforeach; ?></select></label>
<label class="full">Descricao fiscal<input type="text" name="nfe_descricao" maxlength="120"></label>
<label>NCM<input type="text" name="nfe_ncm" maxlength="10"></label>
<label>Classificacao fiscal<select name="nfe_tipo"><option value="">Pendente</option><option value="Mercadoria para Revenda">Mercadoria para revenda</option><option value="Producao propria">Producao propria</option></select></label>
<div class="form-actions full"><button type="button" class="btn-secondary js-close-edit">Cancelar</button><button type="submit" name="editar" class="btn-edit">Salvar</button></div>
</form></div></div>
<script>
var modal=document.getElementById('modalEditarProduto'),formEditar=document.getElementById('formEditarProduto');
function datasetNome(nome){return nome.replace(/_([a-z])/g,function(_,l){return l.toUpperCase();});}
document.querySelectorAll('.js-open-edit').forEach(function(b){b.addEventListener('click',function(){Array.prototype.forEach.call(formEditar.elements,function(c){var k=datasetNome(c.name);if(k&&typeof b.dataset[k]!=='undefined')c.value=b.dataset[k];});modal.classList.add('is-open');});});
document.querySelectorAll('.js-close-edit').forEach(function(b){b.addEventListener('click',function(){modal.classList.remove('is-open');});});modal.addEventListener('click',function(e){if(e.target===modal)modal.classList.remove('is-open');});
</script></body></html>

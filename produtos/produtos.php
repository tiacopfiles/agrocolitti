<?php
require "../auth/proteger.php";
require "../config/conexao.php";
require "../config/ciclo_helper.php";
require "../config/layout_helper.php";
require "../config/permissions.php";
require "../config/cadastro_fiscal_helper.php";
require "../config/produto_vinculo_helper.php";

requirePermission(PERM_ADMIN, '../index.php');

$erroCadastro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['criar']) || isset($_POST['editar']))) {
    validarTokenCsrf();
    try {
        $dados = cadastroFiscalProduto($_POST);
        if (isset($_POST['criar'])) {
            $produtoPrincipalId = (int) ($_POST['produto_principal_id'] ?? 0) ?: null;
            $stmt = $conexao->prepare("
                INSERT INTO produtos
                (nome, codigo_interno, nfe_codigo_interno, unidade, ativo, produto_principal_id, ncm, nfe_ncm, nfe_descricao, nfe_tipo, nfe_unidade, nfe_origem, nfe_cst_pis, nfe_cst_cofins)
                VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                'ssssissssssss',
                $dados['nome'], $dados['codigo_interno'], $dados['nfe_codigo_interno'], $dados['unidade'],
                $produtoPrincipalId, $dados['ncm'], $dados['nfe_ncm'],
                $dados['nfe_descricao'], $dados['nfe_tipo'], $dados['nfe_unidade'], $dados['nfe_origem'], $dados['nfe_cst_pis'], $dados['nfe_cst_cofins']
            );
        } else {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) throw new InvalidArgumentException('Produto invalido.');
            $stmt = $conexao->prepare("
                UPDATE produtos
                SET nome = ?, codigo_interno = ?, nfe_codigo_interno = ?, unidade = ?, ncm = ?, nfe_ncm = ?, nfe_descricao = ?,
                    nfe_tipo = ?, nfe_unidade = ?, nfe_origem = ?, nfe_cst_pis = ?, nfe_cst_cofins = ?
                WHERE id = ?
            ");
            $stmt->bind_param(
                'ssssssssssssi',
                $dados['nome'], $dados['codigo_interno'], $dados['nfe_codigo_interno'], $dados['unidade'], $dados['ncm'], $dados['nfe_ncm'],
                $dados['nfe_descricao'], $dados['nfe_tipo'], $dados['nfe_unidade'], $dados['nfe_origem'], $dados['nfe_cst_pis'], $dados['nfe_cst_cofins'], $id
            );
        }
        $stmt->execute();
        $stmt->close();
        header("Location: produtos.php?msg=salvo");
        exit;
    } catch (Throwable $e) {
        $erroCadastro = $e->getMessage();
    }
}

if (isset($_GET['desativar']) || isset($_GET['reativar'])) {
    $id = (int) ($_GET['desativar'] ?? $_GET['reativar']);
    $ativo = isset($_GET['reativar']) ? 1 : 0;
    $stmt = $conexao->prepare("UPDATE produtos SET ativo = ? WHERE id = ?");
    $stmt->bind_param('ii', $ativo, $id);
    $stmt->execute();
    header("Location: produtos.php");
    exit;
}

if (isset($_GET['excluir'])) {
    $id = (int) $_GET['excluir'];
    $stmt = $conexao->prepare("DELETE FROM produtos WHERE id = ?");
    $stmt->bind_param('i', $id);
    try {
        $stmt->execute();
        header("Location: produtos.php?msg=excluido");
    } catch (mysqli_sql_exception $e) {
        if ((int) $e->getCode() === 1451) {
            $stmtDesativar = $conexao->prepare("UPDATE produtos SET ativo = 0 WHERE id = ?");
            $stmtDesativar->bind_param('i', $id);
            $stmtDesativar->execute();
            header("Location: produtos.php?msg=produto_vinculado");
        } else {
            header("Location: produtos.php?msg=erro_excluir");
        }
    }
    exit;
}

$produtosPrincipais = $conexao->query("
    SELECT id, nome
    FROM produtos
    WHERE ativo = 1 AND produto_principal_id IS NULL AND COALESCE(escopo_produto, 'normal') IN ('normal', 'ambos')
    ORDER BY nome ASC
");
$produtos = $conexao->query("
    SELECT p.*, pp.nome AS produto_principal_nome
    FROM produtos p
    LEFT JOIN produtos pp ON pp.id = p.produto_principal_id
        WHERE COALESCE(p.escopo_produto, 'normal') IN ('normal', 'ambos')
    ORDER BY COALESCE(pp.nome, p.nome), p.produto_principal_id IS NOT NULL, p.nome
");
$cicloAtivo = getCicloAtivo($conexao);
function produtoFiscalCompleto(array $produto): bool
{
    return strlen(cadastroFiscalDigitos(($produto['ncm'] ?? '') ?: ($produto['nfe_ncm'] ?? ''))) === 8
        && trim((string) (($produto['codigo_interno'] ?? '') ?: ($produto['nfe_codigo_interno'] ?? ''))) !== ''
        && trim((string) ($produto['nfe_descricao'] ?? '')) !== ''
        && in_array(trim((string) ($produto['nfe_tipo'] ?? '')), ['Mercadoria para Revenda', 'Producao propria'], true)
        && trim((string) ($produto['unidade'] ?? '')) !== '';
}
?>
<!DOCTYPE html>
<html lang="pt-br"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Produtos</title>
<link rel="stylesheet" href="../assets/css/cadastro-fiscal.css?v=20260603-1">
<style>
*{box-sizing:border-box}body{font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;margin:0;color:#333}.container{padding:30px;max-width:1200px;margin:auto}.card{background:#fff;padding:25px;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,.06);margin-bottom:30px}.form-title{margin:0 0 20px}.msg,.msg-ciclo{padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600}.msg.ok,.msg-ciclo{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7}.msg.aviso{background:#fff8e1;color:#8a4b00;border:1px solid #ffe08a}.msg.erro{background:#ffebee;color:#b71c1c;border:1px solid #ef9a9a}
input,select{width:100%;padding:10px;margin-top:5px;border-radius:6px;border:1px solid #ccc;font-size:14px}label{font-size:13px;font-weight:700}button{padding:10px 16px;border:0;border-radius:6px;font-weight:600;cursor:pointer}.btn-primary,.btn-edit{background:#2e7d32;color:#fff}.btn-secondary{background:#e9ecef}.btn-danger,.btn-delete{background:#c0392b;color:#fff}.btn-success{background:#27ae60;color:#fff}
.table-responsive{overflow-x:auto}table{width:100%;min-width:820px;border-collapse:collapse}th{background:#1b5e20;color:#fff;padding:14px}td{padding:13px;text-align:center;border-bottom:1px solid #eee}.acoes{display:flex;gap:8px;justify-content:center}.acoes button{width:34px;height:34px;padding:0}.badge{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700}.badge.ativo{background:#e8f5e9;color:#1b5e20}.badge.inativo{background:#eee;color:#555}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.48);display:none;align-items:center;justify-content:center;padding:20px;z-index:1000}.modal-overlay.is-open{display:flex}.modal-card{background:#fff;width:min(780px,100%);max-height:92vh;overflow:auto;border-radius:12px;padding:24px}.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}.modal-header h2{margin:0}.modal-close{width:36px;height:36px;padding:0;background:#f1f3f4}
</style><?php renderAppLayoutStyles(); ?></head><body>
<?php renderAppHeader('..'); ?><div class="container">
<?php if ($cicloAtivo): ?><div class="msg-ciclo">Ciclo ativo: <?= htmlspecialchars(getNomeCiclo($cicloAtivo)) ?></div><?php endif; ?>
<?php if ($erroCadastro !== ''): ?><div class="msg erro"><?= htmlspecialchars($erroCadastro) ?></div><?php elseif (($_GET['msg'] ?? '') === 'salvo'): ?><div class="msg ok">Produto salvo com informacoes fiscais.</div><?php elseif (($_GET['msg'] ?? '') === 'excluido'): ?><div class="msg ok">Produto excluido.</div><?php elseif (($_GET['msg'] ?? '') === 'produto_vinculado'): ?><div class="msg aviso">Produto vinculado a historico: foi desativado.</div><?php elseif (($_GET['msg'] ?? '') === 'erro_excluir'): ?><div class="msg erro">Nao foi possivel excluir o produto.</div><?php endif; ?>
<div class="card" id="cardNovoProduto"><h2 class="form-title" id="tituloNovoProduto">Novo Produto</h2>
<form method="POST" class="field-grid" data-fiscal-wizard>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
<input type="hidden" name="produto_principal_id" id="novo_produto_principal_id" value="">
<div class="msg aviso full" id="avisoProdutoVinculado" style="display:none;">Produto vinculado a <strong id="nomeProdutoPrincipalVinculado"></strong>. Ele aparece em vendas/compras/tabelas, mas movimenta o estoque do produto principal.</div>
<div class="wizard-steps full"><span class="wizard-step" data-wizard-step="1">1. Produto</span><span class="wizard-step" data-wizard-step="2">2. Fiscal</span><span class="wizard-step" data-wizard-step="3">3. Revisao</span></div>
<section class="wizard-panel field-grid full" data-wizard-panel="1"><label class="full">Nome do produto<input type="text" name="nome" required></label><label>Codigo do produto<input type="text" name="codigo_interno" required maxlength="50"></label><label>Unidade comercial<select name="unidade" required><option value="KG">Quilograma (KG)</option><option value="UN">Unidade (UN)</option><option value="CX">Caixa (CX)</option></select></label><label>Produto principal<select id="produtoPrincipalSelect"><option value="">Produto principal</option><?php if ($produtosPrincipais): while ($principal = $produtosPrincipais->fetch_assoc()): ?><option value="<?= (int) $principal['id'] ?>"><?= htmlspecialchars($principal['nome']) ?></option><?php endwhile; endif; ?></select></label><div class="wizard-actions"><button type="button" class="btn-primary" data-next>Proximo</button></div></section>
<section class="wizard-panel field-grid full" data-wizard-panel="2"><div class="fiscal-note">Campos necessarios para gerar o item da NF-e pela Focus.</div><label class="full">Descricao fiscal<input type="text" name="nfe_descricao" required maxlength="120"></label><label>NCM<input type="text" name="nfe_ncm" required minlength="8" maxlength="10" placeholder="8 digitos"></label><label>Classificacao fiscal<select name="nfe_tipo" required><option value="Mercadoria para Revenda">Mercadoria para revenda</option><option value="Producao propria">Producao propria</option></select></label><label>CST PIS<input type="text" name="nfe_cst_pis" required minlength="2" maxlength="2" inputmode="numeric" value="06"></label><label>CST COFINS<input type="text" name="nfe_cst_cofins" required minlength="2" maxlength="2" inputmode="numeric" value="06"></label><div class="wizard-actions"><button type="button" class="btn-secondary" data-back>Voltar</button><button type="button" class="btn-primary" data-next>Revisar</button></div></section>
<section class="wizard-panel field-grid full" data-wizard-panel="3"><div class="wizard-review"><h3>Revise antes de adicionar</h3><div class="wizard-review-grid"><div class="wizard-review-item">Produto<strong data-review-field="nome"></strong></div><div class="wizard-review-item">Codigo<strong data-review-field="codigo_interno"></strong></div><div class="wizard-review-item">Unidade<strong data-review-field="unidade"></strong></div><div class="wizard-review-item">Descricao fiscal<strong data-review-field="nfe_descricao"></strong></div><div class="wizard-review-item">NCM<strong data-review-field="nfe_ncm"></strong></div><div class="wizard-review-item">Classificacao<strong data-review-field="nfe_tipo"></strong></div></div></div><div class="wizard-actions"><button type="button" class="btn-secondary" data-back>Voltar</button><button type="submit" name="criar" class="btn-primary"><i class="bi bi-plus-lg"></i> Adicionar</button></div></section>
</form></div>
<div class="card"><h2 class="form-title">Produtos cadastrados</h2><div class="table-responsive"><table><tr><th>ID</th><th>Codigo</th><th>Nome</th><th>Unidade</th><th>NCM</th><th>Fiscal</th><th>Vinculo</th><th>Status</th><th>Acoes</th></tr>
<?php while ($p = $produtos->fetch_assoc()): $ncm = ($p['ncm'] ?? '') ?: ($p['nfe_ncm'] ?? ''); $ok = produtoFiscalCompleto($p); ?>
<tr><td><?= (int) $p['id'] ?></td><td><?= htmlspecialchars(($p['codigo_interno'] ?? '') ?: ($p['nfe_codigo_interno'] ?? '')) ?></td><td><?= htmlspecialchars($p['nome']) ?></td><td><?= htmlspecialchars($p['unidade'] ?? '') ?></td><td><?= htmlspecialchars($ncm) ?></td><td><span class="fiscal-badge <?= $ok ? 'ok' : '' ?>"><?= $ok ? 'Pronto NF-e' : 'Incompleto' ?></span></td><td><?= produtoEhVinculado($p) ? '<span class="badge inativo">Vinculado a ' . htmlspecialchars($p['produto_principal_nome'] ?? '') . '</span>' : '<span class="badge ativo">Principal</span>' ?></td><td><span class="badge <?= $p['ativo'] ? 'ativo' : 'inativo' ?>"><?= $p['ativo'] ? 'Ativo' : 'Inativo' ?></span></td><td><div class="acoes">
<?php if (!produtoEhVinculado($p)): ?><button type="button" class="btn-success js-criar-vinculado" title="Criar produto vinculado" data-id="<?= (int) $p['id'] ?>" data-nome="<?= htmlspecialchars($p['nome'], ENT_QUOTES) ?>" data-codigo-interno="<?= htmlspecialchars(($p['codigo_interno'] ?? '') ?: ($p['nfe_codigo_interno'] ?? ''), ENT_QUOTES) ?>" data-unidade="<?= htmlspecialchars(strtoupper((string) ($p['unidade'] ?? 'KG')), ENT_QUOTES) ?>"><i class="bi bi-plus-lg"></i></button><?php endif; ?>
<button type="button" class="btn-edit js-open-edit" data-id="<?= (int) $p['id'] ?>" data-nome="<?= htmlspecialchars($p['nome'], ENT_QUOTES) ?>" data-codigo-interno="<?= htmlspecialchars(($p['codigo_interno'] ?? '') ?: ($p['nfe_codigo_interno'] ?? ''), ENT_QUOTES) ?>" data-unidade="<?= htmlspecialchars(strtoupper((string) ($p['unidade'] ?? 'KG')), ENT_QUOTES) ?>" data-nfe-descricao="<?= htmlspecialchars($p['nfe_descricao'] ?? $p['nome'], ENT_QUOTES) ?>" data-nfe-ncm="<?= htmlspecialchars($ncm, ENT_QUOTES) ?>" data-nfe-tipo="<?= htmlspecialchars($p['nfe_tipo'] ?? 'Mercadoria para Revenda', ENT_QUOTES) ?>" data-nfe-cst-pis="<?= htmlspecialchars($p['nfe_cst_pis'] ?? '06', ENT_QUOTES) ?>" data-nfe-cst-cofins="<?= htmlspecialchars($p['nfe_cst_cofins'] ?? '06', ENT_QUOTES) ?>"><i class="bi bi-pencil"></i></button>
<?php if ($p['ativo']): ?><a href="?desativar=<?= (int) $p['id'] ?>" onclick="return confirm('Desativar este produto?')"><button type="button" class="btn-danger"><i class="bi bi-slash-circle"></i></button></a><?php else: ?><a href="?reativar=<?= (int) $p['id'] ?>" onclick="return confirm('Reativar este produto?')"><button type="button" class="btn-success"><i class="bi bi-arrow-clockwise"></i></button></a><?php endif; ?>
<a href="?excluir=<?= (int) $p['id'] ?>" onclick="return confirm('Excluir este produto definitivamente?')"><button type="button" class="btn-delete"><i class="bi bi-trash"></i></button></a></div></td></tr><?php endwhile; ?>
</table></div></div></div>
<div class="modal-overlay" id="modalEditarProduto"><div class="modal-card"><div class="modal-header"><h2>Editar produto</h2><button type="button" class="modal-close js-close-edit"><i class="bi bi-x-lg"></i></button></div>
<form method="POST" class="field-grid" data-fiscal-wizard id="formEditarProduto"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>"><input type="hidden" name="id">
<div class="wizard-steps full"><span class="wizard-step" data-wizard-step="1">1. Produto</span><span class="wizard-step" data-wizard-step="2">2. Fiscal</span><span class="wizard-step" data-wizard-step="3">3. Revisao</span></div>
<section class="wizard-panel field-grid full" data-wizard-panel="1"><label class="full">Nome do produto<input type="text" name="nome" required></label><label>Codigo do produto<input type="text" name="codigo_interno" required maxlength="50"></label><label>Unidade comercial<select name="unidade" required><option value="KG">Quilograma (KG)</option><option value="UN">Unidade (UN)</option><option value="CX">Caixa (CX)</option></select></label><div class="wizard-actions"><button type="button" class="btn-primary" data-next>Proximo</button></div></section>
<section class="wizard-panel field-grid full" data-wizard-panel="2"><div class="fiscal-note">Campos necessarios para gerar o item da NF-e pela Focus.</div><label class="full">Descricao fiscal<input type="text" name="nfe_descricao" required maxlength="120"></label><label>NCM<input type="text" name="nfe_ncm" required minlength="8" maxlength="10"></label><label>Classificacao fiscal<select name="nfe_tipo" required><option value="Mercadoria para Revenda">Mercadoria para revenda</option><option value="Producao propria">Producao propria</option></select></label><label>CST PIS<input type="text" name="nfe_cst_pis" required minlength="2" maxlength="2" inputmode="numeric"></label><label>CST COFINS<input type="text" name="nfe_cst_cofins" required minlength="2" maxlength="2" inputmode="numeric"></label><div class="wizard-actions"><button type="button" class="btn-secondary" data-back>Voltar</button><button type="button" class="btn-primary" data-next>Revisar</button></div></section>
<section class="wizard-panel field-grid full" data-wizard-panel="3"><div class="wizard-review"><h3>Revise as alteracoes</h3><div class="wizard-review-grid"><div class="wizard-review-item">Produto<strong data-review-field="nome"></strong></div><div class="wizard-review-item">Codigo<strong data-review-field="codigo_interno"></strong></div><div class="wizard-review-item">Unidade<strong data-review-field="unidade"></strong></div><div class="wizard-review-item">Descricao fiscal<strong data-review-field="nfe_descricao"></strong></div><div class="wizard-review-item">NCM<strong data-review-field="nfe_ncm"></strong></div><div class="wizard-review-item">Classificacao<strong data-review-field="nfe_tipo"></strong></div></div></div><div class="wizard-actions"><button type="button" class="btn-secondary" data-back>Voltar</button><button type="submit" name="editar" class="btn-edit">Salvar</button></div></section>
</form></div></div>
<script src="../assets/js/cadastro-fiscal.js?v=20260603-1"></script><script>
var modal=document.getElementById('modalEditarProduto'),formEditar=document.getElementById('formEditarProduto');
function datasetNome(nome){return nome.replace(/_([a-z])/g,function(_,l){return l.toUpperCase();});}
document.querySelectorAll('.js-open-edit').forEach(function(b){b.addEventListener('click',function(){Array.prototype.forEach.call(formEditar.elements,function(c){var k=datasetNome(c.name);if(k&&typeof b.dataset[k]!=='undefined')c.value=b.dataset[k];});if(formEditar.wizardExibir)formEditar.wizardExibir(1);modal.classList.add('is-open');});});
document.querySelectorAll('.js-close-edit').forEach(function(b){b.addEventListener('click',function(){modal.classList.remove('is-open');});});modal.addEventListener('click',function(e){if(e.target===modal)modal.classList.remove('is-open');});
var campoPrincipal=document.getElementById('novo_produto_principal_id'),selectPrincipal=document.getElementById('produtoPrincipalSelect'),avisoVinculado=document.getElementById('avisoProdutoVinculado'),nomePrincipal=document.getElementById('nomeProdutoPrincipalVinculado'),tituloNovo=document.getElementById('tituloNovoProduto');
function definirProdutoPrincipal(id,nome,unidade){
    campoPrincipal.value=id||'';
    if(selectPrincipal)selectPrincipal.value=id||'';
    if(id){tituloNovo.textContent='Novo Produto Vinculado';avisoVinculado.style.display='block';nomePrincipal.textContent=nome||'';}else{tituloNovo.textContent='Novo Produto';avisoVinculado.style.display='none';nomePrincipal.textContent='';}
    var unidadeCampo=document.querySelector('#cardNovoProduto select[name="unidade"]');
    if(unidadeCampo&&unidade)unidadeCampo.value=unidade;
}
if(selectPrincipal){selectPrincipal.addEventListener('change',function(){var opt=this.options[this.selectedIndex];definirProdutoPrincipal(this.value,opt?opt.textContent:'','');});}
document.querySelectorAll('.js-criar-vinculado').forEach(function(b){b.addEventListener('click',function(){definirProdutoPrincipal(b.dataset.id,b.dataset.nome,b.dataset.unidade);document.getElementById('cardNovoProduto').scrollIntoView({behavior:'smooth',block:'start'});var nome=document.querySelector('#cardNovoProduto input[name="nome"]');if(nome)nome.focus();});});
</script></body></html>


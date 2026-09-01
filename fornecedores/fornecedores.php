<?php
require "../auth/proteger.php";
require "../config/conexao.php";
require "../config/ciclo_helper.php";
require "../config/layout_helper.php";
require "../config/permissions.php";
require "../config/cadastro_fiscal_helper.php";

requireModule('fornecedores', '../index.php');

function fornecedorNomeArquivo(string $valor): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($valor)) ?: trim($valor);
    return trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $ascii) ?? 'arquivo', '_') ?: 'arquivo';
}

function fornecedorSalvarCertificado(array $arquivo, string $nome): ?string
{
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo((string) ($arquivo['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) return null;
    $pasta = __DIR__ . '/../storage/uploads/fornecedores/';
    if (!is_dir($pasta)) mkdir($pasta, 0755, true);
    $base = fornecedorNomeArquivo($nome) . '_' . date('Ymd');
    $arquivoNome = $base . '.' . $ext;
    $contador = 2;
    while (is_file($pasta . $arquivoNome)) $arquivoNome = $base . '_' . $contador++ . '.' . $ext;
    return move_uploaded_file($arquivo['tmp_name'], $pasta . $arquivoNome) ? $arquivoNome : null;
}

function fornecedorRemoverCertificado(?string $arquivo): void
{
    if (!$arquivo) return;
    $caminho = __DIR__ . '/../storage/uploads/fornecedores/' . basename($arquivo);
    if (is_file($caminho)) unlink($caminho);
}

function fornecedorFiscalCompleto(array $fornecedor): bool
{
    return cadastroFiscalPessoaCompleta($fornecedor);
}

$erroCadastro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['criar']) || isset($_POST['editar']))) {
    validarTokenCsrf();
    try {
        $dados = cadastroFiscalPessoa($_POST);
        if (isset($_POST['criar'])) {
            $stmt = $conexao->prepare("
                INSERT INTO fornecedores
                (nome, cnpj, telefone, endereco, ativo, certificado_arquivo, nfe_nome_razao_social, nfe_cpf,
                 nfe_cnpj, nfe_ie, nfe_indicador_ie_destinatario, nfe_telefone, nfe_endereco, nfe_numero,
                 nfe_complemento, nfe_bairro, nfe_cidade, nfe_estado, nfe_cep)
                VALUES (?, ?, ?, ?, 1, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                str_repeat('s', 17),
                $dados['nome'], $dados['documento'], $dados['telefone'], $dados['endereco'],
                $dados['nfe_nome_razao_social'], $dados['nfe_cpf'], $dados['nfe_cnpj'], $dados['nfe_ie'],
                $dados['nfe_indicador_ie_destinatario'], $dados['nfe_telefone'], $dados['nfe_endereco'],
                $dados['nfe_numero'], $dados['nfe_complemento'], $dados['nfe_bairro'],
                $dados['nfe_cidade'], $dados['nfe_estado'], $dados['nfe_cep']
            );
            $stmt->execute();
            $id = (int) $conexao->insert_id;
            $stmt->close();
            $certificado = fornecedorSalvarCertificado($_FILES['certificado'] ?? [], $dados['nome']);
            if ($certificado) {
                $arquivoStmt = $conexao->prepare("UPDATE fornecedores SET certificado_arquivo = ? WHERE id = ?");
                $arquivoStmt->bind_param('si', $certificado, $id);
                $arquivoStmt->execute();
                $arquivoStmt->close();
            }
        } else {
            $id = (int) ($_POST['id'] ?? 0);
            $ativo = (int) ($_POST['ativo'] ?? 1);
            if ($id <= 0) throw new InvalidArgumentException('Fornecedor invalido.');
            $atualStmt = $conexao->prepare("SELECT certificado_arquivo FROM fornecedores WHERE id = ? LIMIT 1");
            $atualStmt->bind_param('i', $id);
            $atualStmt->execute();
            $arquivo = (string) (($atualStmt->get_result()->fetch_assoc()['certificado_arquivo'] ?? ''));
            $atualStmt->close();
            $novoArquivo = fornecedorSalvarCertificado($_FILES['certificado'] ?? [], $dados['nome']);
            if (isset($_POST['remover_certificado']) || $novoArquivo) {
                fornecedorRemoverCertificado($arquivo);
                $arquivo = $novoArquivo ?? '';
            }
            $stmt = $conexao->prepare("
                UPDATE fornecedores
                SET nome = ?, cnpj = ?, telefone = ?, endereco = ?, nfe_nome_razao_social = ?, nfe_cpf = ?,
                    nfe_cnpj = ?, nfe_ie = ?, nfe_indicador_ie_destinatario = ?, nfe_telefone = ?,
                    nfe_endereco = ?, nfe_numero = ?, nfe_complemento = ?, nfe_bairro = ?, nfe_cidade = ?,
                    nfe_estado = ?, nfe_cep = ?, ativo = ?, certificado_arquivo = ?
                WHERE id = ?
            ");
            $stmt->bind_param(
                str_repeat('s', 17) . 'isi',
                $dados['nome'], $dados['documento'], $dados['telefone'], $dados['endereco'],
                $dados['nfe_nome_razao_social'], $dados['nfe_cpf'], $dados['nfe_cnpj'], $dados['nfe_ie'],
                $dados['nfe_indicador_ie_destinatario'], $dados['nfe_telefone'], $dados['nfe_endereco'],
                $dados['nfe_numero'], $dados['nfe_complemento'], $dados['nfe_bairro'], $dados['nfe_cidade'],
                $dados['nfe_estado'], $dados['nfe_cep'], $ativo, $arquivo, $id
            );
            $stmt->execute();
            $stmt->close();
        }
        header("Location: " . $_SERVER['PHP_SELF'] . '?msg=salvo');
        exit;
    } catch (Throwable $e) {
        $erroCadastro = $e->getMessage();
    }
}

if (isset($_GET['excluir'])) {
    $id = (int) $_GET['excluir'];
    $stmt = $conexao->prepare("SELECT certificado_arquivo FROM fornecedores WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id); $stmt->execute();
    $arquivo = (string) (($stmt->get_result()->fetch_assoc()['certificado_arquivo'] ?? '')); $stmt->close();
    $stmt = $conexao->prepare("DELETE FROM fornecedores WHERE id = ?");
    $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
    fornecedorRemoverCertificado($arquivo);
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}

$fornecedores = $conexao->query("SELECT * FROM fornecedores ORDER BY nome ASC");
$cicloAtivo = getCicloAtivo($conexao);
?>
<!DOCTYPE html>
<html lang="pt-br"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Fornecedores</title>
<link rel="stylesheet" href="../assets/css/cadastro-fiscal.css?v=20260603-1">
<style>
*{box-sizing:border-box}body{font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;margin:0;color:#333}.container{padding:30px;max-width:1240px;margin:auto}.card{background:#fff;padding:25px;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,.06);margin-bottom:30px}.form-title{margin:0 0 20px;color:#111}.msg,.msg-ciclo{padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600}.msg.ok,.msg-ciclo{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7}.msg.erro{background:#ffebee;color:#b71c1c;border:1px solid #ef9a9a}
input,select{width:100%;padding:10px;margin-top:5px;border-radius:6px;border:1px solid #ccc;font-size:14px}label{font-size:13px;font-weight:700;color:#333}button{padding:10px 16px;border:0;border-radius:6px;font-weight:600;cursor:pointer}.btn-primary,.btn-edit{background:#2e7d32;color:#fff}.btn-secondary{background:#e9ecef;color:#333}.btn-danger{background:#c0392b;color:#fff}
.table-responsive{overflow-x:auto}table{width:100%;min-width:1020px;border-collapse:collapse}th{background:#1b5e20;color:#fff;padding:14px}td{padding:13px;text-align:center;border-bottom:1px solid #eee}.acoes{display:flex;gap:8px;justify-content:center}.acoes button{width:34px;height:34px;padding:0}.arquivo-link{font-weight:600;color:#1b5e20;text-decoration:none}.status-badge{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700}.status-badge.ativo{background:#e8f5e9;color:#1b5e20}.status-badge.inativo{background:#ffebee;color:#b71c1c}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.48);display:none;align-items:center;justify-content:center;padding:20px;z-index:1000}.modal-overlay.is-open{display:flex}.modal-card{background:#fff;width:min(840px,100%);max-height:92vh;overflow:auto;border-radius:12px;padding:24px}.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}.modal-header h2{margin:0}.modal-close{width:36px;height:36px;padding:0;background:#f1f3f4}.checkbox-line{display:flex;align-items:center;gap:8px}.checkbox-line input{width:auto;margin:0}
</style><?php renderAppLayoutStyles(); ?></head><body>
<?php renderAppHeader('..'); ?><div class="container">
<?php if ($cicloAtivo): ?><div class="msg-ciclo">Ciclo ativo: <?= htmlspecialchars(getNomeCiclo($cicloAtivo)) ?></div><?php endif; ?>
<?php if ($erroCadastro !== ''): ?><div class="msg erro"><?= htmlspecialchars($erroCadastro) ?></div><?php elseif (($_GET['msg'] ?? '') === 'salvo'): ?><div class="msg ok">Fornecedor salvo com informacoes fiscais.</div><?php endif; ?>
<div class="card"><h2 class="form-title">Novo Fornecedor</h2>
<form method="POST" enctype="multipart/form-data" class="field-grid" data-fiscal-wizard>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
<div class="wizard-steps full"><span class="wizard-step" data-wizard-step="1">1. Fornecedor</span><span class="wizard-step" data-wizard-step="2">2. Fiscal</span><span class="wizard-step" data-wizard-step="3">3. Revisao</span></div>
<section class="wizard-panel field-grid full" data-wizard-panel="1"><label class="full">Nome do fornecedor<input type="text" name="nome" required></label><label>Telefone (opcional)<input type="text" name="telefone"></label><label>Certificado (opcional)<input type="file" name="certificado" accept=".pdf,.jpg,.jpeg,.png,.webp"></label><div class="wizard-actions"><button type="button" class="btn-primary" data-next>Proximo</button></div></section>
<?php include __DIR__ . '/../includes/cadastro_fiscal_pessoa_campos.php'; ?>
<section class="wizard-panel field-grid full" data-wizard-panel="3"><div class="wizard-review"><h3>Revise antes de adicionar</h3><div class="wizard-review-grid"><div class="wizard-review-item">Fornecedor<strong data-review-field="nome"></strong></div><div class="wizard-review-item">Documento<strong data-review-field="tipo_documento|documento_fiscal"></strong></div><div class="wizard-review-item">Razao social<strong data-review-field="nfe_nome_razao_social"></strong></div><div class="wizard-review-item">IE<strong data-review-field="nfe_indicador_ie_destinatario|nfe_ie"></strong></div><div class="wizard-review-item">Endereco fiscal<strong data-review-field="nfe_endereco|nfe_numero|nfe_bairro"></strong></div><div class="wizard-review-item">Cidade / UF / CEP<strong data-review-field="nfe_cidade|nfe_estado|nfe_cep"></strong></div></div></div><div class="wizard-actions"><button type="button" class="btn-secondary" data-back>Voltar</button><button type="submit" name="criar" class="btn-primary"><i class="bi bi-plus-lg"></i> Adicionar</button></div></section>
</form></div>
<div class="card"><h2 class="form-title">Fornecedores cadastrados</h2><div class="table-responsive"><table><tr><th>ID</th><th>Nome</th><th>CPF/CNPJ</th><th>Fiscal</th><th>Certificado</th><th>Status</th><th>Acoes</th></tr>
<?php while ($f = $fornecedores->fetch_assoc()): $docFiscal = ($f['nfe_cpf'] ?? '') ?: (($f['nfe_cnpj'] ?? '') ?: ($f['cnpj'] ?? '')); $ok = fornecedorFiscalCompleto($f); $arquivo = basename((string) ($f['certificado_arquivo'] ?? '')); ?>
<tr><td><?= (int) $f['id'] ?></td><td><?= htmlspecialchars($f['nome']) ?></td><td><?= htmlspecialchars($docFiscal) ?></td><td><span class="fiscal-badge <?= $ok ? 'ok' : '' ?>"><?= $ok ? 'Pronto NF-e' : 'Incompleto' ?></span></td><td><?= $arquivo !== '' ? '<a class="arquivo-link" target="_blank" href="../storage/uploads/fornecedores/' . rawurlencode($arquivo) . '">Ver arquivo</a>' : '-' ?></td><td><span class="status-badge <?= $f['ativo'] ? 'ativo' : 'inativo' ?>"><?= $f['ativo'] ? 'Ativo' : 'Inativo' ?></span></td><td><div class="acoes">
<button type="button" class="btn-edit js-open-edit" data-id="<?= (int) $f['id'] ?>" data-nome="<?= htmlspecialchars($f['nome'], ENT_QUOTES) ?>" data-telefone="<?= htmlspecialchars($f['telefone'] ?? '', ENT_QUOTES) ?>" data-ativo="<?= (int) $f['ativo'] ?>" data-certificado="<?= htmlspecialchars($arquivo, ENT_QUOTES) ?>"
 data-nfe-nome-razao-social="<?= htmlspecialchars($f['nfe_nome_razao_social'] ?? '', ENT_QUOTES) ?>" data-tipo-documento="<?= strlen(cadastroFiscalDigitos($docFiscal)) === 11 ? 'cpf' : 'cnpj' ?>" data-documento-fiscal="<?= htmlspecialchars($docFiscal, ENT_QUOTES) ?>" data-nfe-indicador-ie-destinatario="<?= htmlspecialchars(cadastroFiscalIndicadorNormalizado($f['nfe_indicador_ie_destinatario'] ?? ''), ENT_QUOTES) ?>" data-nfe-ie="<?= htmlspecialchars($f['nfe_ie'] ?? '', ENT_QUOTES) ?>" data-nfe-endereco="<?= htmlspecialchars($f['nfe_endereco'] ?? '', ENT_QUOTES) ?>" data-nfe-numero="<?= htmlspecialchars($f['nfe_numero'] ?? '', ENT_QUOTES) ?>" data-nfe-complemento="<?= htmlspecialchars($f['nfe_complemento'] ?? '', ENT_QUOTES) ?>" data-nfe-bairro="<?= htmlspecialchars($f['nfe_bairro'] ?? '', ENT_QUOTES) ?>" data-nfe-cidade="<?= htmlspecialchars($f['nfe_cidade'] ?? '', ENT_QUOTES) ?>" data-nfe-estado="<?= htmlspecialchars($f['nfe_estado'] ?? '', ENT_QUOTES) ?>" data-nfe-cep="<?= htmlspecialchars($f['nfe_cep'] ?? '', ENT_QUOTES) ?>"><i class="bi bi-pencil"></i></button>
<a href="?excluir=<?= (int) $f['id'] ?>" onclick="return confirm('Excluir fornecedor?')"><button type="button" class="btn-danger"><i class="bi bi-trash"></i></button></a></div></td></tr><?php endwhile; ?>
</table></div></div></div>
<div class="modal-overlay" id="modalEditarFornecedor"><div class="modal-card"><div class="modal-header"><h2>Editar fornecedor</h2><button type="button" class="modal-close js-close-edit"><i class="bi bi-x-lg"></i></button></div>
<form method="POST" enctype="multipart/form-data" class="field-grid" data-fiscal-wizard id="formEditarFornecedor"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>"><input type="hidden" name="id">
<div class="wizard-steps full"><span class="wizard-step" data-wizard-step="1">1. Fornecedor</span><span class="wizard-step" data-wizard-step="2">2. Fiscal</span><span class="wizard-step" data-wizard-step="3">3. Revisao</span></div>
<section class="wizard-panel field-grid full" data-wizard-panel="1"><label class="full">Nome do fornecedor<input type="text" name="nome" required></label><label>Telefone (opcional)<input type="text" name="telefone"></label><label>Status<select name="ativo"><option value="1">Ativo</option><option value="0">Inativo</option></select></label><label class="full">Trocar certificado (opcional)<input type="file" name="certificado" accept=".pdf,.jpg,.jpeg,.png,.webp"></label><label class="full checkbox-line"><input type="checkbox" name="remover_certificado" value="1"> Remover certificado atual</label><div class="wizard-actions"><button type="button" class="btn-primary" data-next>Proximo</button></div></section>
<?php include __DIR__ . '/../includes/cadastro_fiscal_pessoa_campos.php'; ?>
<section class="wizard-panel field-grid full" data-wizard-panel="3"><div class="wizard-review"><h3>Revise as alteracoes</h3><div class="wizard-review-grid"><div class="wizard-review-item">Fornecedor<strong data-review-field="nome"></strong></div><div class="wizard-review-item">Documento<strong data-review-field="tipo_documento|documento_fiscal"></strong></div><div class="wizard-review-item">Razao social<strong data-review-field="nfe_nome_razao_social"></strong></div><div class="wizard-review-item">IE<strong data-review-field="nfe_indicador_ie_destinatario|nfe_ie"></strong></div><div class="wizard-review-item">Endereco fiscal<strong data-review-field="nfe_endereco|nfe_numero|nfe_bairro"></strong></div><div class="wizard-review-item">Cidade / UF / CEP<strong data-review-field="nfe_cidade|nfe_estado|nfe_cep"></strong></div></div></div><div class="wizard-actions"><button type="button" class="btn-secondary" data-back>Voltar</button><button type="submit" name="editar" class="btn-edit">Salvar</button></div></section>
</form></div></div>
<script src="../assets/js/cadastro-fiscal.js?v=20260603-1"></script><script>
var modal=document.getElementById('modalEditarFornecedor'),formEditar=document.getElementById('formEditarFornecedor');
function datasetNome(nome){return nome.replace(/_([a-z])/g,function(_,l){return l.toUpperCase();});}
document.querySelectorAll('.js-open-edit').forEach(function(b){b.addEventListener('click',function(){Array.prototype.forEach.call(formEditar.elements,function(c){var k=datasetNome(c.name);if(k&&typeof b.dataset[k]!=='undefined')c.value=b.dataset[k];});var indicador=formEditar.querySelector('[name="nfe_indicador_ie_destinatario"]');if(indicador)indicador.dispatchEvent(new Event('change'));if(formEditar.wizardExibir)formEditar.wizardExibir(1);modal.classList.add('is-open');});});
document.querySelectorAll('.js-close-edit').forEach(function(b){b.addEventListener('click',function(){modal.classList.remove('is-open');});});modal.addEventListener('click',function(e){if(e.target===modal)modal.classList.remove('is-open');});
</script></body></html>

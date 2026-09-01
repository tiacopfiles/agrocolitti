<?php
require "../auth/proteger.php";
require "../config/conexao.php";
require "../config/ciclo_helper.php";
require "../config/layout_helper.php";
require "../config/permissions.php";
require "../config/cadastro_fiscal_helper.php";

requireModule('clientes', '../index.php');

$erroCadastro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['criar'])) {
        require __DIR__ . '/actions/criar_cliente.php';
    } elseif (isset($_POST['editar'])) {
        require __DIR__ . '/actions/editar_cliente.php';
    }
}

if (isset($_GET['excluir'])) {
    $idExcluir = (int) $_GET['excluir'];
    $stmt = $conexao->prepare("DELETE FROM clientes WHERE id = ?");
    $stmt->bind_param("i", $idExcluir);
    $stmt->execute();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

$clientes = $conexao->query("SELECT * FROM clientes ORDER BY nome ASC");
$cicloAtivo = getCicloAtivo($conexao);

function clienteFiscalCompleto(array $cliente): bool
{
    return cadastroFiscalPessoaCompleta($cliente);
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Clientes</title>
<link rel="stylesheet" href="../assets/css/cadastro-fiscal.css?v=20260603-1">
<style>
*{box-sizing:border-box}body{font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;margin:0;color:#333}
.container{padding:30px;max-width:1240px;margin:auto}.card{background:#fff;padding:25px;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,.06);margin-bottom:30px}
.form-title{margin:0 0 20px;color:#111}.msg{padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600}.msg.ok,.msg-ciclo{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7}.msg.erro{background:#ffebee;color:#b71c1c;border:1px solid #ef9a9a}.msg-ciclo{padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600}
input,select{width:100%;padding:10px;margin-top:5px;border-radius:6px;border:1px solid #ccc;font-size:14px}input:focus,select:focus{border-color:#1b5e20;outline:none}label{font-size:13px;font-weight:700;color:#333}
button{padding:10px 16px;border:0;border-radius:6px;font-weight:600;cursor:pointer}.btn-primary,.btn-edit{background:#2e7d32;color:#fff}.btn-secondary{background:#e9ecef;color:#333}.btn-danger{background:#c0392b;color:#fff}
.table-responsive{width:100%;overflow-x:auto}table{width:100%;min-width:960px;border-collapse:collapse}th{background:#1b5e20;color:#fff;padding:14px;font-size:14px}td{padding:13px;text-align:center;border-bottom:1px solid #eee;font-size:14px}.acoes{display:flex;gap:8px;justify-content:center}.acoes button{width:34px;height:34px;padding:0}.status-badge{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700}.status-badge.ativo{background:#e8f5e9;color:#1b5e20}.status-badge.inativo{background:#ffebee;color:#b71c1c}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.48);display:none;align-items:center;justify-content:center;padding:20px;z-index:1000}.modal-overlay.is-open{display:flex}.modal-card{background:#fff;width:min(820px,100%);max-height:92vh;overflow:auto;border-radius:12px;padding:24px}.modal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}.modal-header h2{margin:0}.modal-close{width:36px;height:36px;padding:0;background:#f1f3f4}
@media(max-width:768px){.container{padding:20px}.modal-card{padding:18px}}
</style>
<?php renderAppLayoutStyles(); ?>
</head>
<body>
<?php renderAppHeader('..'); ?>
<div class="container">

<?php if ($cicloAtivo): ?>
<div class="msg-ciclo">Ciclo ativo: <?= htmlspecialchars(getNomeCiclo($cicloAtivo)) ?></div>
<?php endif; ?>
<?php if ($erroCadastro !== ''): ?>
<div class="msg erro"><?= htmlspecialchars($erroCadastro) ?></div>
<?php elseif (($_GET['msg'] ?? '') === 'salvo'): ?>
<div class="msg ok">Cliente salvo com informacoes fiscais.</div>
<?php endif; ?>

<!-- ── Formulário Novo Cliente (wizard gerenciado pelo cadastro-fiscal.js) ── -->
<div class="card">
<h2 class="form-title">Novo Cliente</h2>
<form method="POST" class="field-grid" data-fiscal-wizard>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
<div class="wizard-steps full">
  <span class="wizard-step" data-wizard-step="1">1. Cliente</span>
  <span class="wizard-step" data-wizard-step="2">2. Fiscal</span>
  <span class="wizard-step" data-wizard-step="3">3. Revisao</span>
</div>
<section class="wizard-panel field-grid full" data-wizard-panel="1">
  <label class="full">Nome do cliente<input type="text" name="nome" required></label>
  <label>Telefone (opcional)<input type="text" name="telefone"></label>
  <label>E-mail para XML da NF-e (opcional)<input type="email" name="email_nfe" maxlength="255" autocomplete="email"></label>
  <label>Segundo e-mail para XML (opcional)<input type="email" name="email_nfe_2" maxlength="255"></label>
  <label>Terceiro e-mail para XML (opcional)<input type="email" name="email_nfe_3" maxlength="255"></label>
  <div class="wizard-actions"><button type="button" class="btn-primary" data-next>Proximo</button></div>
</section>
<?php include __DIR__ . '/../includes/cadastro_fiscal_pessoa_campos.php'; ?>
<section class="wizard-panel field-grid full" data-wizard-panel="3">
  <div class="wizard-review"><h3>Revise antes de adicionar</h3><div class="wizard-review-grid">
    <div class="wizard-review-item">Cliente<strong data-review-field="nome"></strong></div>
    <div class="wizard-review-item">E-mail do XML<strong data-review-field="email_nfe"></strong></div>
    <div class="wizard-review-item">E-mails adicionais<strong data-review-field="email_nfe_2|email_nfe_3"></strong></div>
    <div class="wizard-review-item">Documento<strong data-review-field="tipo_documento|documento_fiscal"></strong></div>
    <div class="wizard-review-item">Razao social<strong data-review-field="nfe_nome_razao_social"></strong></div>
    <div class="wizard-review-item">IE<strong data-review-field="nfe_indicador_ie_destinatario|nfe_ie"></strong></div>
    <div class="wizard-review-item">Endereco fiscal<strong data-review-field="nfe_endereco|nfe_numero|nfe_bairro"></strong></div>
    <div class="wizard-review-item">Cidade / UF / CEP<strong data-review-field="nfe_cidade|nfe_estado|nfe_cep"></strong></div>
  </div></div>
  <div class="wizard-actions">
    <button type="button" class="btn-secondary" data-back>Voltar</button>
    <button type="submit" name="criar" class="btn-primary"><i class="bi bi-plus-lg"></i> Adicionar</button>
  </div>
</section>
</form>
</div>

<!-- ── Tabela de clientes ─────────────────────────────────────────────────── -->
<div class="card"><h2 class="form-title">Clientes cadastrados</h2><div class="table-responsive"><table>
<tr><th>ID</th><th>Nome</th><th>CPF/CNPJ</th><th>E-mail XML</th><th>Fiscal</th><th>Status</th><th>Acoes</th></tr>
<?php while ($c = $clientes->fetch_assoc()):
    $docFiscal = ($c['nfe_cpf'] ?? '') ?: (($c['nfe_cnpj'] ?? '') ?: ($c['documento'] ?? ''));
    $ok = clienteFiscalCompleto($c);
?>
<tr>
  <td><?= (int) $c['id'] ?></td>
  <td><?= htmlspecialchars($c['nome']) ?></td>
  <td><?= htmlspecialchars($docFiscal) ?></td>
  <td><?= htmlspecialchars(implode(', ', array_values(array_filter([$c['email_nfe'] ?? '', $c['email_nfe_2'] ?? '', $c['email_nfe_3'] ?? '']))) ?: '-') ?></td>
  <td><span class="fiscal-badge <?= $ok ? 'ok' : '' ?>"><?= $ok ? 'Pronto NF-e' : 'Incompleto' ?></span></td>
  <td><span class="status-badge <?= $c['ativo'] ? 'ativo' : 'inativo' ?>"><?= $c['ativo'] ? 'Ativo' : 'Inativo' ?></span></td>
  <td><div class="acoes">
    <button type="button" class="btn-edit js-open-edit" title="Editar"
      data-id="<?= (int) $c['id'] ?>"
      data-nome="<?= htmlspecialchars($c['nome'], ENT_QUOTES) ?>"
      data-telefone="<?= htmlspecialchars($c['telefone'] ?? '', ENT_QUOTES) ?>"
      data-email-nfe="<?= htmlspecialchars($c['email_nfe'] ?? '', ENT_QUOTES) ?>"
      data-email-nfe2="<?= htmlspecialchars($c['email_nfe_2'] ?? '', ENT_QUOTES) ?>"
      data-email-nfe3="<?= htmlspecialchars($c['email_nfe_3'] ?? '', ENT_QUOTES) ?>"
      data-ativo="<?= (int) $c['ativo'] ?>"
      data-nfe-nome-razao-social="<?= htmlspecialchars($c['nfe_nome_razao_social'] ?? '', ENT_QUOTES) ?>"
      data-tipo-documento="<?= strlen(cadastroFiscalDigitos($docFiscal)) === 11 ? 'cpf' : 'cnpj' ?>"
      data-documento-fiscal="<?= htmlspecialchars($docFiscal, ENT_QUOTES) ?>"
      data-nfe-indicador-ie-destinatario="<?= htmlspecialchars(cadastroFiscalIndicadorNormalizado($c['nfe_indicador_ie_destinatario'] ?? ''), ENT_QUOTES) ?>"
      data-nfe-ie="<?= htmlspecialchars($c['nfe_ie'] ?? '', ENT_QUOTES) ?>"
      data-nfe-endereco="<?= htmlspecialchars($c['nfe_endereco'] ?? '', ENT_QUOTES) ?>"
      data-nfe-numero="<?= htmlspecialchars($c['nfe_numero'] ?? '', ENT_QUOTES) ?>"
      data-nfe-complemento="<?= htmlspecialchars($c['nfe_complemento'] ?? '', ENT_QUOTES) ?>"
      data-nfe-bairro="<?= htmlspecialchars($c['nfe_bairro'] ?? '', ENT_QUOTES) ?>"
      data-nfe-cidade="<?= htmlspecialchars($c['nfe_cidade'] ?? '', ENT_QUOTES) ?>"
      data-nfe-estado="<?= htmlspecialchars($c['nfe_estado'] ?? '', ENT_QUOTES) ?>"
      data-nfe-cep="<?= htmlspecialchars($c['nfe_cep'] ?? '', ENT_QUOTES) ?>"><i class="bi bi-pencil"></i></button>
    <a href="?excluir=<?= (int) $c['id'] ?>" onclick="return confirm('Excluir cliente?')">
      <button type="button" class="btn-danger"><i class="bi bi-trash"></i></button>
    </a>
  </div></td>
</tr>
<?php endwhile; ?>
</table></div></div>

</div><!-- /container -->

<!-- ── Modal Editar Cliente ───────────────────────────────────────────────── -->
<div class="modal-overlay" id="modalEditarCliente" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-header">
      <h2>Editar cliente</h2>
      <button type="button" class="modal-close js-close-edit"><i class="bi bi-x-lg"></i></button>
    </div>
    <!-- SEM data-fiscal-wizard: wizard gerenciado pelo JS abaixo -->
    <form method="POST" class="field-grid" id="formEditarCliente">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
      <input type="hidden" name="id">
      <div class="wizard-steps full">
        <span class="wizard-step" data-wizard-step="1">1. Cliente</span>
        <span class="wizard-step" data-wizard-step="2">2. Fiscal</span>
        <span class="wizard-step" data-wizard-step="3">3. Revisao</span>
      </div>
      <section class="wizard-panel field-grid full" data-wizard-panel="1">
        <label class="full">Nome do cliente<input type="text" name="nome" required></label>
        <label>Telefone (opcional)<input type="text" name="telefone"></label>
        <label>E-mail para XML da NF-e (opcional)<input type="email" name="email_nfe" maxlength="255" autocomplete="email"></label>
        <label>Segundo e-mail para XML (opcional)<input type="email" name="email_nfe_2" maxlength="255"></label>
        <label>Terceiro e-mail para XML (opcional)<input type="email" name="email_nfe_3" maxlength="255"></label>
        <label>Status
          <select name="ativo">
            <option value="1">Ativo</option>
            <option value="0">Inativo</option>
          </select>
        </label>
        <div class="wizard-actions"><button type="button" class="btn-primary" data-next>Proximo</button></div>
      </section>
      <?php include __DIR__ . '/../includes/cadastro_fiscal_pessoa_campos.php'; ?>
      <section class="wizard-panel field-grid full" data-wizard-panel="3">
        <div class="wizard-review"><h3>Revise as alteracoes</h3><div class="wizard-review-grid">
          <div class="wizard-review-item">Cliente<strong data-review-field="nome"></strong></div>
          <div class="wizard-review-item">E-mail do XML<strong data-review-field="email_nfe"></strong></div>
          <div class="wizard-review-item">E-mails adicionais<strong data-review-field="email_nfe_2|email_nfe_3"></strong></div>
          <div class="wizard-review-item">Documento<strong data-review-field="tipo_documento|documento_fiscal"></strong></div>
          <div class="wizard-review-item">Razao social<strong data-review-field="nfe_nome_razao_social"></strong></div>
          <div class="wizard-review-item">IE<strong data-review-field="nfe_indicador_ie_destinatario|nfe_ie"></strong></div>
          <div class="wizard-review-item">Endereco fiscal<strong data-review-field="nfe_endereco|nfe_numero|nfe_bairro"></strong></div>
          <div class="wizard-review-item">Cidade / UF / CEP<strong data-review-field="nfe_cidade|nfe_estado|nfe_cep"></strong></div>
        </div></div>
        <div class="wizard-actions">
          <button type="button" class="btn-secondary" data-back>Voltar</button>
          <button type="submit" name="editar" class="btn-edit">Salvar</button>
        </div>
      </section>
    </form>
  </div>
</div>

<script src="../assets/js/cadastro-fiscal.js?v=20260603-1"></script>
<script>
/* Wizard self-contained do modal de edição.
   Não usa data-fiscal-wizard para evitar conflito com o cadastro-fiscal.js. */
(function () {
    var modal  = document.getElementById('modalEditarCliente');
    var form   = document.getElementById('formEditarCliente');
    if (!modal || !form) return;

    var etapa  = 1;
    var panels = [].slice.call(form.querySelectorAll('[data-wizard-panel]'));
    var steps  = [].slice.call(form.querySelectorAll('[data-wizard-step]'));

    /* converte campo.name (snake_case) para chave do dataset (camelCase) */
    function toDataset(nome) {
        return nome.replace(/_([a-z0-9])/g, function (_, l) { return l.toUpperCase(); });
    }

    function atualizarIe() {
        var indicador = form.querySelector('[name="nfe_indicador_ie_destinatario"]');
        var ie        = form.querySelector('[name="nfe_ie"]');
        var wrap      = ie ? ie.closest('.ie-wrap') : null;
        if (!indicador || !wrap) return;
        var contribuinte = indicador.value === 'Contribuinte do ICMS';
        wrap.classList.toggle('is-hidden', !contribuinte);
        ie.required = contribuinte;
        if (!contribuinte) ie.value = '';
    }

    function montarRevisao() {
        form.querySelectorAll('[data-review-field]').forEach(function (saida) {
            var nomes  = saida.getAttribute('data-review-field').split('|');
            var textos = nomes.map(function (nome) {
                var campo = form.querySelector('[name="' + nome + '"]');
                if (!campo) return '';
                if (campo.tagName === 'SELECT') {
                    var opt = campo.options[campo.selectedIndex];
                    return opt ? opt.text : '';
                }
                return campo.value.trim();
            }).filter(Boolean);
            saida.textContent = textos.join(' - ') || '-';
        });
    }

    function exibir(n) {
        etapa = n;
        panels.forEach(function (p) {
            p.classList.toggle('is-active', Number(p.dataset.wizardPanel) === n);
        });
        steps.forEach(function (s) {
            s.classList.toggle('is-active', Number(s.dataset.wizardStep) === n);
        });
        if (n === 3) montarRevisao();
    }

    function painelValido() {
        var campos = panels[etapa - 1].querySelectorAll('input, select, textarea');
        for (var i = 0; i < campos.length; i++) {
            if (!campos[i].checkValidity()) {
                campos[i].reportValidity();
                return false;
            }
        }
        return true;
    }

    /* botões Próximo e Voltar */
    form.querySelectorAll('[data-next]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            atualizarIe();
            if (painelValido()) exibir(Math.min(3, etapa + 1));
        });
    });
    form.querySelectorAll('[data-back]').forEach(function (btn) {
        btn.addEventListener('click', function () { exibir(Math.max(1, etapa - 1)); });
    });

    /* mudança no indicador IE */
    var indicadorSel = form.querySelector('[name="nfe_indicador_ie_destinatario"]');
    if (indicadorSel) indicadorSel.addEventListener('change', atualizarIe);

    /* estado inicial (modal está oculto, mas as classes precisam estar corretas) */
    exibir(1);
    atualizarIe();

    /* abre o modal preenchendo os dados do cliente selecionado */
    document.querySelectorAll('.js-open-edit').forEach(function (botao) {
        botao.addEventListener('click', function () {
            /* 1. preenche todos os campos */
            [].forEach.call(form.elements, function (campo) {
                var chave = toDataset(campo.name);
                if (chave && typeof botao.dataset[chave] !== 'undefined') {
                    campo.value = botao.dataset[chave];
                }
            });
            /* 2. atualiza visibilidade do campo IE após preencher indicador */
            atualizarIe();
            /* 3. reseta para o passo 1 */
            exibir(1);
            /* 4. exibe o modal */
            modal.setAttribute('aria-hidden', 'false');
            modal.classList.add('is-open');
        });
    });

    /* fecha o modal */
    document.querySelectorAll('.js-close-edit').forEach(function (b) {
        b.addEventListener('click', fechar);
    });
    modal.addEventListener('click', function (e) { if (e.target === modal) fechar(); });

    function fechar() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }
})();
</script>
</body>
</html>

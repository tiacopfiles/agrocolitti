<?php
require "../config/conexao.php";
require "../auth/proteger.php";
require "../config/permissions.php";
require "../config/layout_helper.php";

requireModule('contas', '../index.php');

function contasPagarMoney(float $value): string
{
    return number_format($value, 2, '.', '');
}

function contasPagarMoneyBr(float $value): string
{
    return 'R$ ' . number_format($value, 2, ',', '.');
}

function contasPagarPayload(?string $json): array
{
    $payload = $json ? json_decode($json, true) : null;
    return is_array($payload) ? $payload : [];
}

function contasPagarSnapshot(?string $json): array
{
    $snapshot = $json ? json_decode($json, true) : null;
    return is_array($snapshot) ? $snapshot : [];
}

function contasPagarValor(array $payload, array $doc = []): float
{
    if (($doc['tipo_emissao'] ?? '') === 'saida_abate_sem_entrada') {
        $snapshot = contasPagarSnapshot($doc['pedido_snapshot_json'] ?? null);
        if (isset($snapshot['valor_total_original'])) return (float) $snapshot['valor_total_original'];
        if (isset($snapshot['valor_total'])) return (float) $snapshot['valor_total'];
    }
    if (isset($payload['valor_total'])) return (float) $payload['valor_total'];
    if (isset($payload['valor_produtos'])) return (float) $payload['valor_produtos'];

    $total = 0.0;
    foreach (($payload['items'] ?? $payload['itens'] ?? []) as $item) {
        if (is_array($item)) {
            $total += (float) ($item['valor_bruto'] ?? 0);
        }
    }
    return $total;
}

function contasPagarDocumento(array $doc, array $payload): string
{
    $numero = trim((string) ($doc['numero_nfe'] ?? ''));
    if ($numero === '') $numero = trim((string) ($payload['numero'] ?? ''));
    return $numero !== '' ? $numero : '-';
}

function contasPagarPessoa(array $doc, array $payload): string
{
    $nome = trim((string) ($doc['fornecedor_razao'] ?? ''));
    if ($nome === '') $nome = trim((string) ($doc['fornecedor_nome'] ?? ''));
    if ($nome === '') $nome = trim((string) ($payload['nome_destinatario'] ?? $payload['nome_emitente'] ?? ''));
    return $nome !== '' ? $nome : '-';
}

function contasPagarDocumentoFornecedor(array $doc, array $payload): string
{
    foreach (['fornecedor_nfe_cnpj', 'fornecedor_cnpj', 'fornecedor_nfe_cpf'] as $key) {
        $value = trim((string) ($doc[$key] ?? ''));
        if ($value !== '') return $value;
    }
    foreach (['cnpj_destinatario', 'cpf_destinatario', 'cnpj_emitente', 'cpf_emitente'] as $key) {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value !== '') return $value;
    }
    return '-';
}

function contasPagarCompetencia(?string $date): string
{
    if (!$date) return '-';
    $ts = strtotime($date);
    return $ts ? date('m/Y', $ts) : '-';
}

function contasPagarFunruralPercentual(string $nome): float
{
    return preg_match('/\b(LTDA|COOPERATIVA|COOP)\b/i', $nome) ? 0.0 : 1.63;
}

$busca = trim((string) ($_GET['busca'] ?? ''));
$dataIni = trim((string) ($_GET['data_ini'] ?? ''));
$dataFim = trim((string) ($_GET['data_fim'] ?? ''));
$somenteAutorizadas = ($_GET['status'] ?? 'autorizada') === 'autorizada';
$statusIntegracao = trim((string) ($_GET['integracao'] ?? 'pendente'));
$integracoesPermitidas = ['pendente', 'enviado', 'erro', 'cancelado', 'todos'];
if (!in_array($statusIntegracao, $integracoesPermitidas, true)) $statusIntegracao = 'pendente';

$where = ["d.tipo_emissao IN ('compra','saida_abate_sem_entrada')"];
$params = [];
$types = '';

if ($somenteAutorizadas) {
    $where[] = "d.status = 'autorizada'";
}
if ($busca !== '') {
    $where[] = "(d.numero_os LIKE ? OR d.numero_nfe LIKE ? OR d.ref LIKE ? OR d.chave_nfe LIKE ? OR f.nome LIKE ? OR f.nfe_nome_razao_social LIKE ? OR f.cnpj LIKE ? OR f.nfe_cnpj LIKE ? OR f.nfe_cpf LIKE ?)";
    $like = '%' . $busca . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    $types .= 'sssssssss';
}
if ($dataIni !== '') {
    $where[] = 'DATE(COALESCE(d.emitida_em, d.created_at)) >= ?';
    $params[] = $dataIni;
    $types .= 's';
}
if ($dataFim !== '') {
    $where[] = 'DATE(COALESCE(d.emitida_em, d.created_at)) <= ?';
    $params[] = $dataFim;
    $types .= 's';
}
if ($statusIntegracao === 'pendente') {
    $where[] = "(ci.id IS NULL OR ci.status IN ('pendente','preparado','erro'))";
} elseif ($statusIntegracao === 'enviado') {
    $where[] = "ci.status = 'enviado'";
} elseif ($statusIntegracao === 'erro') {
    $where[] = "ci.status = 'erro'";
} elseif ($statusIntegracao === 'cancelado') {
    $where[] = "ci.status = 'cancelado'";
}

$sql = "
    SELECT d.id, d.ref, d.numero_os, d.tipo_emissao, d.origem_tipo, d.origem_id, d.status, d.numero_nfe, d.serie, d.chave_nfe,
           d.payload_json, d.emitida_em, d.created_at,
           pcd.snapshot_json AS pedido_snapshot_json,
           f.nome AS fornecedor_nome, f.nfe_nome_razao_social AS fornecedor_razao,
           f.cnpj AS fornecedor_cnpj, f.nfe_cnpj AS fornecedor_nfe_cnpj, f.nfe_cpf AS fornecedor_nfe_cpf,
           ci.id AS integracao_id, ci.status AS integracao_status, ci.destino_id AS integracao_destino_id,
           ci.ndocumento AS integracao_ndocumento, ci.dataemissao AS integracao_dataemissao,
           ci.vencimento AS integracao_vencimento, ci.erro_mensagem AS integracao_erro
    FROM nfe_documentos d
    LEFT JOIN fornecedores f ON f.id = d.fornecedor_id
    LEFT JOIN pedido_compra_documentos pcd
           ON pcd.numero_os COLLATE utf8mb4_unicode_ci = d.numero_os COLLATE utf8mb4_unicode_ci
    LEFT JOIN contas_integracoes ci
           ON ci.tipo = 'pagar'
          AND ci.origem_tabela = 'nfe_documentos'
          AND ci.origem_id = d.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY COALESCE(d.emitida_em, d.created_at) DESC, d.id DESC
    LIMIT 200
";

$stmt = $conexao->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$docs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalBruto = 0.0;
$totalFunrural = 0.0;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Contas a pagar</title>
<style>
body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;color:#222}.container{padding:30px;max-width:1500px;margin:auto}
.page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:18px}.page-title h2{margin:0 0 6px;font-size:26px}.page-title p{margin:0;color:#64748b}
.card{background:#fff;padding:22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.06);margin-bottom:18px}.filters{display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr auto;gap:12px;align-items:end}
label{font-weight:700;font-size:13px;color:#334155}input,select{width:100%;box-sizing:border-box;padding:10px;border:1px solid #cbd5e1;border-radius:6px;margin-top:5px;font-family:inherit;background:#fff}
.btn{border:0;border-radius:6px;padding:10px 14px;color:#fff;background:#2e7d32;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:40px}.btn-secondary{background:#64748b}.btn-soft{background:#e8f5e9;color:#1b5e20;border:1px solid #a5d6a7}.btn-danger{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}.btn:disabled{opacity:.55;cursor:not-allowed}
.summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.metric{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px}.metric span{display:block;color:#64748b;font-size:12px;font-weight:700;text-transform:uppercase}.metric strong{display:block;margin-top:5px;font-size:20px;color:#0f172a}
.bulkbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.bulk-actions{display:flex;align-items:end;gap:10px;flex-wrap:wrap}.bulk-date{width:190px}
.table-container{overflow-x:auto}table{width:100%;min-width:1540px;border-collapse:collapse;background:#fff}th{background:#1b5e20;color:#fff;padding:11px;text-align:center;font-size:13px}td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:center;vertical-align:middle}td.left{text-align:left}.muted{color:#64748b;font-size:12px}.doc-key{font-size:11px;word-break:break-all;max-width:210px}.status{font-weight:800;text-transform:uppercase;font-size:12px;color:#166534}.status-enviado{color:#0369a1}.status-erro{color:#b91c1c}.status-preparado{color:#7c2d12}
.money-input{max-width:105px;text-align:right}.date-input{max-width:145px}.doc-input{max-width:150px}.line-total{font-weight:800;color:#0f5132;white-space:nowrap}.warn{color:#b45309;font-size:12px;margin-top:4px}.pill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;background:#eef6ee;color:#1b5e20;padding:4px 9px;font-size:12px;font-weight:700}
.row-actions{display:inline-flex;align-items:center;justify-content:center;gap:6px;white-space:nowrap}.row-actions .btn{min-height:34px;padding:7px 9px;font-size:12px}.row-actions .btn-icon{width:34px;padding:7px}
.empty{padding:34px;text-align:center;color:#64748b}.small-note{font-size:12px;color:#64748b;margin-top:8px}.row-check{width:18px;height:18px}
.result-box{display:none}.result-box.visible{display:block}.result-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px}.result-list{margin-top:14px;display:grid;gap:8px}.result-item{border:1px solid #e2e8f0;border-radius:7px;padding:10px;background:#f8fafc}.result-item.ok{border-color:#86efac;background:#f0fdf4}.result-item.err{border-color:#fecaca;background:#fef2f2}.result-item strong{display:block;margin-bottom:3px}
@media(max-width:1100px){.filters{grid-template-columns:1fr 1fr}}@media(max-width:900px){.container{padding:18px}.page-head{display:block}.filters,.summary{grid-template-columns:1fr}.bulkbar{align-items:stretch}.bulk-actions{display:grid;grid-template-columns:1fr}.bulk-date{width:100%}}
</style>
<?php renderAppLayoutStyles(); ?>
</head>
<body>
<?php renderAppHeader('..'); ?>
<div class="container page-container app-shell">
  <input type="hidden" id="csrfTokenContas" value="<?= htmlspecialchars(gerarTokenCsrf(), ENT_QUOTES) ?>">
  <div class="page-head">
    <div class="page-title">
      <h2>Contas a pagar</h2>
      <p>NF-e de compra autorizadas no AgroColitti para revisao antes do envio ao contas.</p>
    </div>
    <span class="pill"><i class="bi bi-check-circle"></i> Envio habilitado</span>
  </div>

  <div class="card">
    <form method="GET" class="filters">
      <div><label>Busca</label><input name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="Fornecedor, OS, NF-e, CNPJ ou chave"></div>
      <div><label>Data inicio</label><input type="date" name="data_ini" value="<?= htmlspecialchars($dataIni) ?>"></div>
      <div><label>Data fim</label><input type="date" name="data_fim" value="<?= htmlspecialchars($dataFim) ?>"></div>
      <div><label>Status</label><select name="status"><option value="autorizada" <?= $somenteAutorizadas ? 'selected' : '' ?>>Somente autorizadas</option><option value="todas" <?= !$somenteAutorizadas ? 'selected' : '' ?>>Todas as compras</option></select></div>
      <div><label>Integração</label><select name="integracao"><option value="pendente" <?= $statusIntegracao === 'pendente' ? 'selected' : '' ?>>Pendentes</option><option value="enviado" <?= $statusIntegracao === 'enviado' ? 'selected' : '' ?>>Enviados</option><option value="erro" <?= $statusIntegracao === 'erro' ? 'selected' : '' ?>>Erros/Bloqueados</option><option value="cancelado" <?= $statusIntegracao === 'cancelado' ? 'selected' : '' ?>>Excluídos</option><option value="todos" <?= $statusIntegracao === 'todos' ? 'selected' : '' ?>>Todos</option></select></div>
      <button class="btn" type="submit"><i class="bi bi-search"></i> Filtrar</button>
    </form>
  </div>

  <?php foreach ($docs as $calcDoc): ?>
    <?php $payloadCalc = contasPagarPayload($calcDoc['payload_json'] ?? null); $valorCalc = contasPagarValor($payloadCalc, $calcDoc); $nomeCalc = contasPagarPessoa($calcDoc, $payloadCalc); $funruralCalc = $valorCalc * contasPagarFunruralPercentual($nomeCalc) / 100; $totalBruto += $valorCalc; $totalFunrural += $funruralCalc; ?>
  <?php endforeach; ?>

  <div class="card">
    <div class="summary">
      <div class="metric"><span>Registros</span><strong><?= count($docs) ?></strong></div>
      <div class="metric"><span>Valor bruto</span><strong id="summaryGross"><?= contasPagarMoneyBr($totalBruto) ?></strong></div>
      <div class="metric"><span>Descontos previstos</span><strong id="summaryDiscount"><?= contasPagarMoneyBr($totalFunrural) ?></strong></div>
      <div class="metric"><span>Total liquido</span><strong id="summaryNet"><?= contasPagarMoneyBr($totalBruto - $totalFunrural) ?></strong></div>
    </div>
  </div>

  <div class="card">
    <div class="bulkbar">
      <div>
        <strong><span id="selectedCount">0</span> selecionada(s)</strong>
        <div class="small-note">Emissao, vencimento e numero do documento sao da nota do fornecedor. Valores usam ponto decimal. Exemplo: 1234.56</div>
      </div>
      <div class="bulk-actions">
        <div class="bulk-date"><label>Emissao em lote</label><input type="date" id="bulkIssueDate"></div>
        <button class="btn btn-secondary" type="button" id="applyIssueDate"><i class="bi bi-calendar-plus"></i> Aplicar</button>
        <div class="bulk-date"><label>Vencimento em lote</label><input type="date" id="bulkDueDate"></div>
        <button class="btn btn-secondary" type="button" id="applyDueDate"><i class="bi bi-calendar-check"></i> Aplicar</button>
        <button class="btn btn-soft" type="button" id="recalcRows"><i class="bi bi-calculator"></i> Recalcular</button>
        <button class="btn btn-soft" type="button" id="diagnoseSend" disabled><i class="bi bi-shield-check"></i> Diagnosticar</button>
        <button class="btn" type="button" id="prepareSend" disabled><i class="bi bi-send-check"></i> Enviar ao contas</button>
      </div>
    </div>
  </div>

  <div class="card result-box" id="integrationResult">
    <strong id="resultTitle">Resultado da integracao</strong>
    <div class="result-grid">
      <div class="metric"><span>Selecionados</span><strong id="resultSelected">0</strong></div>
      <div class="metric"><span>Aptos/Enviados</span><strong id="resultOk">0</strong></div>
      <div class="metric"><span>Bloqueios/Erros</span><strong id="resultErrors">0</strong></div>
    </div>
    <div class="result-list" id="resultList"></div>
  </div>

  <div class="card">
    <div class="table-container">
      <table data-no-responsive="1">
        <thead>
          <tr>
            <th><input type="checkbox" id="checkAll" aria-label="Selecionar todas"></th>
            <th>Emissao forn.</th>
            <th>OS</th>
            <th>NF-e Agro</th>
            <th>Doc fornecedor</th>
            <th>Fornecedor</th>
            <th>Documento</th>
            <th>Competencia</th>
            <th>Vencimento</th>
            <th>Valor bruto</th>
            <th>Funrural</th>
            <th>Devolucao</th>
            <th>Total liquido</th>
            <th>Status</th>
            <th>Ações</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$docs): ?>
          <tr><td colspan="15" class="empty">Nenhuma NF-e de compra encontrada para os filtros informados.</td></tr>
        <?php endif; ?>
        <?php foreach ($docs as $doc): ?>
          <?php
            $payload = contasPagarPayload($doc['payload_json'] ?? null);
            $valor = contasPagarValor($payload, $doc);
            $nome = contasPagarPessoa($doc, $payload);
            $documento = contasPagarDocumentoFornecedor($doc, $payload);
            $numero = contasPagarDocumento($doc, $payload);
            $funruralPct = contasPagarFunruralPercentual($nome);
            $funrural = $valor * $funruralPct / 100;
            $liquido = $valor - $funrural;
          ?>
          <?php
            $emissaoSql = !empty($doc['integracao_dataemissao']) ? (string) $doc['integracao_dataemissao'] : '';
            $vencimentoSql = !empty($doc['integracao_vencimento']) ? (string) $doc['integracao_vencimento'] : '';
            $competencia = $emissaoSql !== '' ? contasPagarCompetencia($emissaoSql) : '-';
            $integracaoStatus = (string) ($doc['integracao_status'] ?: 'pendente');
            $statusClass = $integracaoStatus === 'enviado' ? 'status-enviado' : ($integracaoStatus === 'erro' ? 'status-erro' : ($integracaoStatus === 'preparado' ? 'status-preparado' : ''));
            $isSaidaAbate = ($doc['tipo_emissao'] ?? '') === 'saida_abate_sem_entrada';
          ?>
          <tr data-row
              data-origin-id="<?= (int) $doc['id'] ?>"
              data-origin-ref="<?= htmlspecialchars((string) $doc['ref'], ENT_QUOTES) ?>"
              data-gross="<?= contasPagarMoney($valor) ?>"
              data-supplier="<?= htmlspecialchars($nome, ENT_QUOTES) ?>"
              data-cnpj="<?= htmlspecialchars($documento, ENT_QUOTES) ?>">
            <td><input class="row-check" type="checkbox" value="<?= (int) $doc['id'] ?>" aria-label="Selecionar NF-e <?= htmlspecialchars($numero) ?>" <?= in_array($integracaoStatus, ['enviado', 'ignorado', 'cancelado'], true) ? 'disabled' : '' ?>></td>
            <td><input class="date-input issue-date" type="date" value="<?= htmlspecialchars($emissaoSql, ENT_QUOTES) ?>" <?= $integracaoStatus === 'enviado' ? 'disabled' : '' ?>></td>
            <td><?= htmlspecialchars((string) ($doc['numero_os'] ?: '-')) ?></td>
            <td>
              <strong><?= htmlspecialchars($numero) ?></strong>
              <div class="muted">Serie <?= htmlspecialchars((string) ($doc['serie'] ?: '1')) ?></div>
              <?php if ($isSaidaAbate): ?><div class="warn">Abate sem entrada: valor do pedido original.</div><?php endif; ?>
              <div class="doc-key"><?= htmlspecialchars((string) ($doc['chave_nfe'] ?: $doc['ref'])) ?></div>
            </td>
            <td><input class="doc-input supplier-doc" type="text" value="<?= htmlspecialchars((string) ($doc['integracao_ndocumento'] ?? ''), ENT_QUOTES) ?>" placeholder="Nº nota forn." <?= $integracaoStatus === 'enviado' ? 'disabled' : '' ?>></td>
            <td class="left">
              <strong><?= htmlspecialchars($nome) ?></strong>
              <div class="muted">Tipo: Nota Fiscal | Conta: Agrocolitti R</div>
              <?php if ($funruralPct <= 0): ?><div class="warn">Sem Funrural pela regra LTDA/Cooperativa.</div><?php endif; ?>
            </td>
            <td><?= htmlspecialchars($documento) ?></td>
            <td data-competence-cell><?= htmlspecialchars($competencia) ?></td>
            <td><input class="date-input due-date" type="date" value="<?= htmlspecialchars($vencimentoSql, ENT_QUOTES) ?>" <?= $integracaoStatus === 'enviado' ? 'disabled' : '' ?>></td>
            <td><strong><?= contasPagarMoneyBr($valor) ?></strong><input type="hidden" class="gross-value" value="<?= contasPagarMoney($valor) ?>"></td>
            <td><input class="money-input funrural-value" type="text" inputmode="decimal" value="<?= contasPagarMoney($funrural) ?>" data-percent="<?= contasPagarMoney($funruralPct) ?>"></td>
            <td><input class="money-input return-value" type="text" inputmode="decimal" value="0.00"></td>
            <td class="line-total" data-line-total><?= contasPagarMoneyBr($liquido) ?></td>
            <td>
              <span class="status <?= $statusClass ?>"><?= htmlspecialchars($integracaoStatus) ?></span>
              <?php if (!empty($doc['integracao_destino_id'])): ?><div class="muted">Contas #<?= (int) $doc['integracao_destino_id'] ?></div><?php endif; ?>
              <?php if (!empty($doc['integracao_erro'])): ?><div class="warn integration-error-message"><?= htmlspecialchars((string) $doc['integracao_erro']) ?></div><?php endif; ?>
            </td>
            <td>
              <?php if ($integracaoStatus !== 'cancelado'): ?>
                <div class="row-actions">
                  <button class="btn btn-icon send-row" type="button" title="Enviar ao contas a pagar" aria-label="Enviar ao contas a pagar" <?= in_array($integracaoStatus, ['enviado', 'ignorado'], true) ? 'disabled' : '' ?>><i class="bi bi-send"></i></button>
                  <button class="btn btn-danger btn-icon cancel-row" type="button" title="Remover da fila" aria-label="Remover da fila"><i class="bi bi-trash"></i></button>
                </div>
              <?php else: ?>
                <span class="muted">Excluído</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
(function(){
  const rows = Array.from(document.querySelectorAll('[data-row]'));
  const money = new Intl.NumberFormat('pt-BR', { style:'currency', currency:'BRL' });
  const selectedCount = document.getElementById('selectedCount');
  const prepareSend = document.getElementById('prepareSend');
  const diagnoseSend = document.getElementById('diagnoseSend');
  const csrfToken = document.getElementById('csrfTokenContas').value;
  const resultBox = document.getElementById('integrationResult');
  const resultList = document.getElementById('resultList');

  function parseDecimal(value){
    const normalized = String(value || '').replace(',', '.').replace(/[^0-9.-]/g, '');
    const number = Number.parseFloat(normalized);
    return Number.isFinite(number) ? number : 0;
  }

  function toDotMoney(value){
    return Math.max(0, value).toFixed(2);
  }

  function dateToCompetence(value){
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return '-';
    return value.slice(5, 7) + '/' + value.slice(0, 4);
  }

  function normalizeInput(input){
    const value = toDotMoney(parseDecimal(input.value));
    input.value = value;
    return Number.parseFloat(value);
  }

  function recalcRow(row){
    const gross = parseDecimal(row.dataset.gross);
    const funrural = normalizeInput(row.querySelector('.funrural-value'));
    const returned = normalizeInput(row.querySelector('.return-value'));
    const net = Math.max(0, gross - funrural - returned);
    row.querySelector('[data-line-total]').textContent = money.format(net);
    row.querySelector('[data-competence-cell]').textContent = dateToCompetence(row.querySelector('.issue-date').value);
    return { gross, discount: funrural + returned, net };
  }

  function buildRowPayload(row){
    const values = recalcRow(row);
    const dueDate = row.querySelector('.due-date').value;
    const issueDate = row.querySelector('.issue-date').value;
    return {
      origem: {
        tabela: 'nfe_documentos',
        id: Number(row.dataset.originId || 0),
        ref: row.dataset.originRef || ''
      },
      lancamento: {
        ndocumento: row.querySelector('.supplier-doc').value.trim(),
        tipo: 'Nota Fiscal',
        nomefantasia: row.dataset.supplier || '',
        vencimento: dueDate,
        dataemissao: issueDate,
        obs: null,
        valor: toDotMoney(values.gross),
        datapgto: null,
        categoria: 'Despesas Sitio',
        desconto: toDotMoney(values.discount),
        valortotal: toDotMoney(values.net),
        parcela: null,
        nparcela: null,
        conta: 'Agro Colitti R',
        situacao: 'aberto',
        acrescimo: null,
        competencia: dateToCompetence(issueDate),
        centrocusto: 'Operacional',
        cnpj: row.dataset.cnpj || ''
      },
      calculo: {
        funrural: toDotMoney(normalizeInput(row.querySelector('.funrural-value'))),
        devolucao: toDotMoney(normalizeInput(row.querySelector('.return-value'))),
        desconto_total: toDotMoney(values.discount)
      }
    };
  }

  function selectedPayload(){
    return rows
      .filter(row => {
        const check = row.querySelector('.row-check');
        return check && check.checked && !check.disabled;
      })
      .map(row => {
        const payload = buildRowPayload(row);
        return {
          id: payload.origem.id,
          dataemissao: payload.lancamento.dataemissao,
          vencimento: payload.lancamento.vencimento,
          ndocumento: payload.lancamento.ndocumento,
          funrural: payload.calculo.funrural,
          devolucao: payload.calculo.devolucao
        };
      });
  }

  function refreshSummary(){
    let gross = 0, discount = 0, net = 0, selected = 0;
    rows.forEach(row => {
      const values = recalcRow(row);
      gross += values.gross;
      discount += values.discount;
      net += values.net;
      const check = row.querySelector('.row-check');
      if (check && check.checked && !check.disabled) selected++;
    });
    document.getElementById('summaryGross').textContent = money.format(gross);
    document.getElementById('summaryDiscount').textContent = money.format(discount);
    document.getElementById('summaryNet').textContent = money.format(net);
    selectedCount.textContent = selected;
    prepareSend.disabled = selected === 0;
    diagnoseSend.disabled = selected === 0;
  }

  function orientacoesBloqueio(erros){
    const orientacoes = [];
    (erros || []).forEach(erro => {
      const motivo = String(erro || '').toLowerCase();
      let orientacao = 'Revise os dados deste pedido e tente novamente.';
      if (motivo.includes('numero do documento do fornecedor')) orientacao = 'Preencha o campo "Doc fornecedor" com o numero da nota do fornecedor.';
      else if (motivo.includes('data de emissao')) orientacao = 'Preencha "Emissao forn." com a data da nota do fornecedor.';
      else if (motivo.includes('vencimento')) orientacao = 'Preencha o campo "Vencimento".';
      else if (motivo.includes('fornecedor ausente')) orientacao = 'Corrija o nome do fornecedor no cadastro da compra.';
      else if (motivo.includes('cnpj/cpf')) orientacao = 'Corrija o CPF/CNPJ do fornecedor no cadastro.';
      else if (motivo.includes('valor bruto') || motivo.includes('valor liquido')) orientacao = 'Revise o valor e os descontos para que o total seja maior que zero.';
      else if (motivo.includes('nao esta autorizada')) orientacao = 'Autorize a NF-e antes de enviar ao Contas.';
      else if (motivo.includes('duplicidade')) orientacao = 'Confira o ID informado no Contas; o mesmo documento, fornecedor e conta ja estao cadastrados.';
      else if (motivo.includes('ja enviada')) orientacao = 'Este pedido ja foi enviado; nao deve ser reenviado.';
      else if (motivo.includes('historico ignorado')) orientacao = 'O pedido foi removido da fila e precisa ser restaurado antes do envio.';
      if (!orientacoes.includes(orientacao)) orientacoes.push(orientacao);
    });
    return orientacoes.length ? 'Como resolver: ' + orientacoes.join(' ') : '';
  }

  function setResult(title, resumo, itens, mode){
    resultBox.classList.add('visible');
    document.getElementById('resultTitle').textContent = title;
    document.getElementById('resultSelected').textContent = resumo.selecionadas ?? resumo.selecionados ?? 0;
    document.getElementById('resultOk').textContent = resumo.aptas ?? resumo.enviados ?? 0;
    document.getElementById('resultErrors').textContent = resumo.bloqueadas ?? resumo.erros ?? 0;
    resultList.innerHTML = '';
    (itens || []).forEach(item => {
      const div = document.createElement('div');
      const ok = item.status === 'apto' || item.status === 'enviado';
      div.className = 'result-item ' + (ok ? 'ok' : 'err');
      const title = document.createElement('strong');
      title.textContent = 'NF-e origem #' + (item.origem_id || '-') + ' - ' + item.status;
      const msg = document.createElement('span');
      const rawErrors = item.erros && item.erros.length ? item.erros.join(' | ') : (item.mensagem || 'OK');
      const orientacao = orientacoesBloqueio(item.erros || []);
      const errors = rawErrors + (orientacao ? ' | ' + orientacao : '');
      msg.textContent = errors;

      const row = rows.find(candidate => Number(candidate.dataset.originId || 0) === Number(item.origem_id || 0));
      if (row) {
        const status = row.querySelector('.status');
        if (status) {
          status.textContent = item.status || (ok ? 'apto' : 'erro');
          status.classList.remove('status-enviado', 'status-erro', 'status-preparado');
          if (item.status === 'enviado') status.classList.add('status-enviado');
          if (!ok) status.classList.add('status-erro');

          let statusMessage = row.querySelector('.integration-error-message');
          if (ok) {
            if (statusMessage) statusMessage.remove();
          } else {
            if (!statusMessage) {
              statusMessage = document.createElement('div');
              statusMessage.className = 'warn integration-error-message';
              status.parentElement.appendChild(statusMessage);
            }
            statusMessage.textContent = errors;
          }
        }
      }

      div.appendChild(title);
      div.appendChild(msg);
      resultList.appendChild(div);
    });
    if (mode === 'sent') {
      setTimeout(() => window.location.reload(), 1600);
    }
  }

  async function postJson(url, payload){
    const response = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload)
    });
    const text = await response.text();
    let data = null;
    try {
      data = text ? JSON.parse(text) : {};
    } catch (err) {
      const preview = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 180);
      throw new Error(preview || 'Resposta inesperada do servidor. Verifique se a sessao ainda esta ativa.');
    }
    if (!response.ok && !data.erro_critico) throw new Error('Falha HTTP ' + response.status);
    return data;
  }

  async function diagnosticar(payload){
    const data = await postJson('actions/diagnostico_pagar.php', {items: payload});
    if (data.erro_critico) throw new Error(data.erro_critico);
    setResult(data.ok ? 'Diagnostico de pre-envio' : 'Diagnostico com erro', data.resumo || {}, data.itens || [], 'diagnostic');
    return data;
  }

  document.querySelectorAll('.money-input').forEach(input => {
    input.addEventListener('input', function(){
      this.value = this.value.replace(',', '.').replace(/[^0-9.]/g, '');
      refreshSummary();
    });
    input.addEventListener('blur', function(){ normalizeInput(this); refreshSummary(); });
  });

  document.querySelectorAll('.row-check').forEach(input => input.addEventListener('change', refreshSummary));
  document.querySelectorAll('.issue-date').forEach(input => input.addEventListener('change', refreshSummary));
  document.querySelectorAll('.due-date').forEach(input => input.addEventListener('change', refreshSummary));
  const checkAll = document.getElementById('checkAll');
  if (checkAll) {
    checkAll.addEventListener('change', function(){
      document.querySelectorAll('.row-check:not(:disabled)').forEach(input => { input.checked = checkAll.checked; });
      refreshSummary();
    });
  }

  document.getElementById('applyDueDate').addEventListener('click', function(){
    const value = document.getElementById('bulkDueDate').value;
    if (!value) { alert('Informe uma data de vencimento.'); return; }
    rows.forEach(row => {
      if (row.querySelector('.row-check').checked) row.querySelector('.due-date').value = value;
    });
    refreshSummary();
  });

  document.getElementById('applyIssueDate').addEventListener('click', function(){
    const value = document.getElementById('bulkIssueDate').value;
    if (!value) { alert('Informe uma data de emissao.'); return; }
    rows.forEach(row => {
      if (row.querySelector('.row-check').checked) row.querySelector('.issue-date').value = value;
    });
    refreshSummary();
  });

  document.getElementById('recalcRows').addEventListener('click', refreshSummary);
  document.querySelectorAll('.cancel-row').forEach(button => {
    button.addEventListener('click', async function(){
      const row = this.closest('[data-row]');
      const id = Number(row && row.dataset.originId || 0);
      if (!id) return;
      if (!confirm('Remover esta NF-e da fila de contas a pagar? Isso não exclui lançamento já criado no contas.')) return;
      this.disabled = true;
      try {
        const data = await postJson('actions/excluir_fila.php', {csrf_token: csrfToken, tipo: 'pagar', id});
        if (!data.ok) throw new Error(data.erro || 'Não foi possível remover da fila.');
        window.location.reload();
      } catch (err) {
        alert(err.message || 'Erro ao remover da fila.');
        this.disabled = false;
      }
    });
  });

  async function enviarPayload(payload, confirmMessage){
    const diag = await diagnosticar(payload);
    const aptas = Number(diag.resumo && diag.resumo.aptas || 0);
    if (aptas <= 0) {
      document.getElementById('resultTitle').textContent = 'Envio bloqueado - corrija os itens abaixo';
      resultBox.scrollIntoView({behavior: 'smooth', block: 'start'});
      return false;
    }
    if (!confirm(confirmMessage(aptas))) return false;
    const data = await postJson('actions/enviar_pagar.php', {csrf_token: csrfToken, items: payload});
    if (data.erro_critico) {
      alert(data.erro_critico);
    }
    setResult(data.ok ? 'Envio concluido' : 'Envio concluido com bloqueios/erros', data.resumo || {}, data.itens || [], 'sent');
    return true;
  }

  diagnoseSend.addEventListener('click', async function(){
    const payload = selectedPayload();
    diagnoseSend.disabled = true;
    try { await diagnosticar(payload); } catch (err) { alert(err.message || 'Erro no diagnostico.'); }
    refreshSummary();
  });

  document.querySelectorAll('.send-row').forEach(button => {
    button.addEventListener('click', async function(){
      const row = this.closest('[data-row]');
      if (!row) return;
      const item = buildRowPayload(row);
      const payload = [{
        id: item.origem.id,
        dataemissao: item.lancamento.dataemissao,
        vencimento: item.lancamento.vencimento,
        ndocumento: item.lancamento.ndocumento,
        funrural: item.calculo.funrural,
        devolucao: item.calculo.devolucao
      }];
      this.disabled = true;
      try {
        const sent = await enviarPayload(payload, () => 'Enviar este lancamento ao contas a pagar?');
        if (!sent) {
          this.disabled = false;
          refreshSummary();
        }
      } catch (err) {
        alert(err.message || 'Erro no envio.');
        this.disabled = false;
        refreshSummary();
      }
    });
  });

  prepareSend.addEventListener('click', async function(){
    const payload = selectedPayload();
    prepareSend.disabled = true;
    try {
      const sent = await enviarPayload(payload, aptas => 'Enviar ' + aptas + ' lancamento(s) ao contas real? Itens bloqueados nao serao enviados.');
      if (!sent) refreshSummary();
    } catch (err) {
      alert(err.message || 'Erro no envio.');
      refreshSummary();
    }
  });

  refreshSummary();
})();
</script>
</body>
</html>

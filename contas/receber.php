<?php
require "../config/conexao.php";
require "../auth/proteger.php";
require "../config/permissions.php";
require "../config/layout_helper.php";
require __DIR__ . "/lib/contas_receber_integration.php";

requireModule('contas', '../index.php');

function contasReceberTelaMoney(float $value): string
{
    return number_format($value, 2, '.', '');
}

function contasReceberTelaMoneyBr(float $value): string
{
    return 'R$ ' . number_format($value, 2, ',', '.');
}

function contasReceberTelaPayload(?string $json): array
{
    $payload = $json ? json_decode($json, true) : null;
    return is_array($payload) ? $payload : [];
}

function contasReceberTelaDoc(array $boleto, array $payload): string
{
    $doc = trim((string) ($boleto['pagador_cpf_cnpj'] ?? ''));
    if ($doc !== '') return $doc;
    foreach (['cnpj_destinatario', 'cpf_destinatario', 'destinatario_cnpj', 'destinatario_cpf'] as $key) {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value !== '') return $value;
    }
    return '-';
}

function contasReceberTelaCliente(array $boleto, array $payload): string
{
    $nome = trim((string) ($boleto['pagador_nome'] ?? ''));
    if ($nome === '') $nome = trim((string) ($payload['nome_destinatario'] ?? $payload['razao_social_destinatario'] ?? ''));
    return $nome !== '' ? $nome : '-';
}

function contasReceberTelaNfe(array $boleto, array $payload): string
{
    $numero = trim((string) ($boleto['numero_nfe'] ?? ''));
    if ($numero === '') $numero = trim((string) ($payload['numero'] ?? ''));
    if ($numero === '') $numero = trim((string) ($boleto['seu_numero'] ?? ''));
    return $numero !== '' ? $numero : '-';
}

function contasReceberTelaNfeValor(array $payload): float
{
    foreach (['valor_total', 'valor_produtos', 'total_nota', 'total'] as $key) {
        if (isset($payload[$key]) && (float) $payload[$key] > 0) return round((float) $payload[$key], 2);
    }
    $total = 0.0;
    foreach (($payload['items'] ?? $payload['itens'] ?? []) as $item) {
        if (!is_array($item)) continue;
        foreach (['valor_bruto', 'valor_total', 'valor_produtos'] as $key) {
            if (isset($item[$key]) && (float) $item[$key] > 0) {
                $total += (float) $item[$key];
                continue 2;
            }
        }
        $qtd = (float) ($item['quantidade_comercial'] ?? $item['quantidade'] ?? 0);
        $unitario = (float) ($item['valor_unitario_comercial'] ?? $item['valor_unitario'] ?? 0);
        if ($qtd > 0 && $unitario > 0) $total += $qtd * $unitario;
    }
    return round($total, 2);
}

function contasReceberTelaCompetencia(?string $date): string
{
    if (!$date) return '-';
    $ts = strtotime($date);
    return $ts ? date('m/Y', $ts) : '-';
}

$busca = trim((string) ($_GET['busca'] ?? ''));
$dataIni = trim((string) ($_GET['data_ini'] ?? ''));
$dataFim = trim((string) ($_GET['data_fim'] ?? ''));
$statusBoleto = trim((string) ($_GET['status'] ?? 'todos'));
$statusIntegracao = trim((string) ($_GET['integracao'] ?? 'pendente'));

if (!in_array($statusBoleto, ['todos', 'com_boleto', 'sem_boleto'], true)) $statusBoleto = 'todos';
$integracoesPermitidas = ['pendente', 'enviado', 'erro', 'cancelado', 'todos'];
if (!in_array($statusIntegracao, $integracoesPermitidas, true)) $statusIntegracao = 'pendente';

$where = ["d.tipo_emissao = 'venda'", "d.status = 'autorizada'"];
$params = [];
$types = '';

if ($statusBoleto === 'com_boleto') {
    $where[] = "b.id IS NOT NULL";
} elseif ($statusBoleto === 'sem_boleto') {
    $where[] = "b.id IS NULL";
}
if ($busca !== '') {
    $where[] = "(d.numero_os LIKE ? OR d.ref LIKE ? OR b.seu_numero LIKE ? OR b.pagador_nome LIKE ? OR b.pagador_cpf_cnpj LIKE ? OR d.numero_nfe LIKE ? OR d.chave_nfe LIKE ?)";
    $like = '%' . $busca . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
    $types .= 'sssssss';
}
if ($dataIni !== '') {
    $where[] = 'DATE(COALESCE(d.emitida_em, d.autorizada_em, d.created_at)) >= ?';
    $params[] = $dataIni;
    $types .= 's';
}
if ($dataFim !== '') {
    $where[] = 'DATE(COALESCE(d.emitida_em, d.autorizada_em, d.created_at)) <= ?';
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
    SELECT d.id, d.ref, d.numero_os, d.venda_id, d.cliente_id, d.id AS nfe_id,
           b.id AS boleto_id, b.situacao AS boleto_situacao,
           b.seu_numero, b.nosso_numero, b.valor AS boleto_valor, b.data_emissao AS boleto_data_emissao,
           b.data_vencimento AS boleto_data_vencimento, b.pagador_nome, b.pagador_cpf_cnpj,
           b.payload_envio AS boleto_payload_envio, b.payload_retorno AS boleto_payload_retorno,
           d.tipo_emissao AS nfe_tipo_emissao,
           d.status AS nfe_status,
           d.numero_nfe,
           d.serie,
           d.chave_nfe,
           d.payload_json AS nfe_payload_json,
           d.emitida_em AS nfe_emitida_em,
           d.autorizada_em,
           d.created_at AS nfe_created_at,
           ci.id AS integracao_id, ci.status AS integracao_status, ci.destino_id AS integracao_destino_id,
           ci.ndocumento AS integracao_ndocumento, ci.erro_mensagem AS integracao_erro
    FROM nfe_documentos d
    LEFT JOIN boletos b ON b.id = (
        SELECT bx.id
        FROM boletos bx
        WHERE bx.nfe_documento_id = d.id
           OR (bx.nfe_documento_id IS NULL AND bx.numero_os = d.numero_os)
        ORDER BY COALESCE(bx.data_emissao, bx.created_at) DESC, bx.id DESC
        LIMIT 1
    )
    LEFT JOIN contas_integracoes ci
           ON ci.tipo = 'receber'
          AND (
                (ci.origem_tabela = 'nfe_documentos' AND ci.origem_id = d.id)
                OR (b.id IS NOT NULL AND ci.origem_tabela = 'boletos' AND ci.origem_id = b.id)
              )
    WHERE " . implode(' AND ', $where) . "
    ORDER BY COALESCE(d.emitida_em, d.autorizada_em, d.created_at) DESC, d.id DESC
    LIMIT 200
";

$stmt = $conexao->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$boletos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalValor = 0.0;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Contas a receber</title>
<style>
body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;color:#222}.container{padding:30px;max-width:1520px;margin:auto}
.page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:18px}.page-title h2{margin:0 0 6px;font-size:26px}.page-title p{margin:0;color:#64748b}
.card{background:#fff;padding:22px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.06);margin-bottom:18px}.filters{display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr auto;gap:12px;align-items:end}
label{font-weight:700;font-size:13px;color:#334155}input,select{width:100%;box-sizing:border-box;padding:10px;border:1px solid #cbd5e1;border-radius:6px;margin-top:5px;font-family:inherit;background:#fff}
.btn{border:0;border-radius:6px;padding:10px 14px;color:#fff;background:#2e7d32;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:40px}.btn-secondary{background:#64748b}.btn-soft{background:#e8f5e9;color:#1b5e20;border:1px solid #a5d6a7}.btn-danger{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}.btn:disabled{opacity:.55;cursor:not-allowed}
.summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.metric{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px}.metric span{display:block;color:#64748b;font-size:12px;font-weight:700;text-transform:uppercase}.metric strong{display:block;margin-top:5px;font-size:20px;color:#0f172a}
.bulkbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.bulk-actions{display:flex;align-items:end;gap:10px;flex-wrap:wrap}.bulk-date{width:190px}
.table-container{overflow-x:auto}table{width:100%;min-width:1700px;border-collapse:collapse;background:#fff}th{background:#1b5e20;color:#fff;padding:11px;text-align:center;font-size:13px}td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:center;vertical-align:middle}td.left{text-align:left}.muted{color:#64748b;font-size:12px}.doc-key{font-size:11px;word-break:break-all;max-width:210px}.status{font-weight:800;text-transform:uppercase;font-size:12px;color:#166534}.status-enviado{color:#0369a1}.status-erro{color:#b91c1c}.status-preparado{color:#7c2d12}
  .money-input{max-width:105px;text-align:right}.date-input{max-width:145px}.obs-input{min-width:185px}.line-total{font-weight:800;color:#0f5132;white-space:nowrap}.warn{color:#b45309;font-size:12px;margin-top:4px}.pill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;background:#eef6ee;color:#1b5e20;padding:4px 9px;font-size:12px;font-weight:700}
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
      <h2>Contas a receber</h2>
      <p>NF-e autorizadas de vendas para revisao antes do envio ao contas a receber.</p>
    </div>
    <span class="pill"><i class="bi bi-check-circle"></i> Envio habilitado</span>
  </div>

  <div class="card">
    <form method="GET" class="filters">
      <div><label>Busca</label><input name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="Cliente, OS, boleto, NF-e, CPF/CNPJ ou chave"></div>
      <div><label>Data inicio</label><input type="date" name="data_ini" value="<?= htmlspecialchars($dataIni) ?>"></div>
      <div><label>Data fim</label><input type="date" name="data_fim" value="<?= htmlspecialchars($dataFim) ?>"></div>
      <div><label>Boleto</label><select name="status"><option value="todos" <?= $statusBoleto === 'todos' ? 'selected' : '' ?>>Todos</option><option value="com_boleto" <?= $statusBoleto === 'com_boleto' ? 'selected' : '' ?>>Com boleto</option><option value="sem_boleto" <?= $statusBoleto === 'sem_boleto' ? 'selected' : '' ?>>Sem boleto</option></select></div>
      <div><label>Integração</label><select name="integracao"><option value="pendente" <?= $statusIntegracao === 'pendente' ? 'selected' : '' ?>>Pendentes</option><option value="enviado" <?= $statusIntegracao === 'enviado' ? 'selected' : '' ?>>Enviados</option><option value="erro" <?= $statusIntegracao === 'erro' ? 'selected' : '' ?>>Erros/Bloqueados</option><option value="cancelado" <?= $statusIntegracao === 'cancelado' ? 'selected' : '' ?>>Excluídos</option><option value="todos" <?= $statusIntegracao === 'todos' ? 'selected' : '' ?>>Todos</option></select></div>
      <button class="btn" type="submit"><i class="bi bi-search"></i> Filtrar</button>
    </form>
  </div>

  <?php foreach ($boletos as $calc):
      $calcPayload = contasReceberTelaPayload($calc['nfe_payload_json'] ?? null);
      $calcValor = contasReceberTelaNfeValor($calcPayload);
      if ($calcValor <= 0) {
          $calcValor = contasReceberValorVendaAgro($conexao, $calc['numero_os'] ?? '', isset($calc['venda_id']) ? (int) $calc['venda_id'] : null);
      }
      $totalValor += $calcValor;
  endforeach; ?>

  <div class="card">
    <div class="summary">
      <div class="metric"><span>Registros</span><strong><?= count($boletos) ?></strong></div>
      <div class="metric"><span>Valor</span><strong id="summaryGross"><?= contasReceberTelaMoneyBr($totalValor) ?></strong></div>
      <div class="metric"><span>Acréscimos/Descontos</span><strong id="summaryAdjust">R$ 0,00</strong></div>
      <div class="metric"><span>Total</span><strong id="summaryNet"><?= contasReceberTelaMoneyBr($totalValor) ?></strong></div>
    </div>
  </div>

  <div class="card">
    <div class="bulkbar">
      <div>
        <strong><span id="selectedCount">0</span> selecionado(s)</strong>
        <div class="small-note">Valores devem ficar com ponto decimal para o contas a receber. Exemplo: 1234.56</div>
      </div>
      <div class="bulk-actions">
        <div class="bulk-date"><label>Vencimento em lote</label><input type="date" id="bulkDueDate"></div>
        <button class="btn btn-secondary" type="button" id="applyDueDate"><i class="bi bi-calendar-check"></i> Aplicar</button>
        <button class="btn btn-soft" type="button" id="recalcRows"><i class="bi bi-calculator"></i> Recalcular</button>
        <button class="btn btn-soft" type="button" id="diagnoseSend" disabled><i class="bi bi-shield-check"></i> Diagnosticar</button>
        <button class="btn" type="button" id="prepareSend" disabled><i class="bi bi-send-check"></i> Enviar ao receber</button>
      </div>
    </div>
  </div>

  <div class="card result-box" id="integrationResult">
    <strong id="resultTitle">Resultado da integração</strong>
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
            <th><input type="checkbox" id="checkAll" aria-label="Selecionar todos"></th>
            <th>Emissão NF-e</th>
            <th>OS</th>
            <th>NF-e / Doc</th>
            <th>Cliente</th>
            <th>CPF/CNPJ</th>
            <th>Competência</th>
            <th>Vencimento</th>
            <th>Obs</th>
            <th>Valor</th>
            <th>Acréscimo</th>
            <th>Desconto</th>
            <th>Total</th>
            <th>Status</th>
            <th>Ações</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$boletos): ?>
          <tr><td colspan="15" class="empty">Nenhuma NF-e de venda autorizada encontrada para os filtros informados.</td></tr>
        <?php endif; ?>
        <?php foreach ($boletos as $boleto): ?>
          <?php
            $payload = contasReceberTelaPayload($boleto['nfe_payload_json'] ?? null);
            $cliente = contasReceberTelaCliente($boleto, $payload);
            $documento = contasReceberTelaDoc($boleto, $payload);
            $numeroNfe = contasReceberTelaNfe($boleto, $payload);
            $emissaoRaw = (string) (($boleto['nfe_emitida_em'] ?? '') ?: ($boleto['autorizada_em'] ?? '') ?: ($boleto['nfe_created_at'] ?? '') ?: '');
            $emissaoSql = $emissaoRaw ? date('Y-m-d', strtotime($emissaoRaw)) : '';
            $competencia = contasReceberTelaCompetencia($emissaoSql);
            $valor = contasReceberTelaNfeValor($payload);
            if ($valor <= 0) {
                $valor = contasReceberValorVendaAgro($conexao, $boleto['numero_os'] ?? '', isset($boleto['venda_id']) ? (int) $boleto['venda_id'] : null);
            }
            $integracaoStatus = (string) ($boleto['integracao_status'] ?: 'pendente');
            $statusClass = $integracaoStatus === 'enviado' ? 'status-enviado' : ($integracaoStatus === 'erro' ? 'status-erro' : ($integracaoStatus === 'preparado' ? 'status-preparado' : ''));
            $vencimento = '';
            $obsPadrao = !empty($boleto['boleto_id']) ? 'Boleto' : 'Depósito Banco Sicoob';
          ?>
          <tr data-row
              data-origin-id="<?= (int) $boleto['id'] ?>"
              data-origin-ref="<?= htmlspecialchars((string) ($boleto['numero_os'] ?: $boleto['ref'] ?: $boleto['id']), ENT_QUOTES) ?>"
              data-gross="<?= contasReceberTelaMoney($valor) ?>"
              data-client="<?= htmlspecialchars($cliente, ENT_QUOTES) ?>"
              data-cnpj="<?= htmlspecialchars($documento, ENT_QUOTES) ?>"
              data-issue-date="<?= htmlspecialchars($emissaoSql, ENT_QUOTES) ?>"
              data-competence="<?= htmlspecialchars($competencia, ENT_QUOTES) ?>"
              data-has-boleto="<?= !empty($boleto['boleto_id']) ? '1' : '0' ?>">
            <td><input class="row-check" type="checkbox" value="<?= (int) $boleto['id'] ?>" aria-label="Selecionar NF-e <?= (int) $boleto['id'] ?>" <?= in_array($integracaoStatus, ['enviado', 'ignorado', 'cancelado'], true) ? 'disabled' : '' ?>></td>
            <td><?= $emissaoSql ? date('d/m/Y', strtotime($emissaoSql)) : '-' ?></td>
            <td><?= htmlspecialchars((string) ($boleto['numero_os'] ?: '-')) ?></td>
            <td>
              <strong><?= htmlspecialchars($numeroNfe) ?></strong>
              <div class="muted">Tipo: Nota Fiscal | Conta: Agro Colitti</div>
              <div class="doc-key"><?= htmlspecialchars((string) ($boleto['chave_nfe'] ?: $boleto['nosso_numero'] ?: $boleto['seu_numero'])) ?></div>
            </td>
            <td class="left"><strong><?= htmlspecialchars($cliente) ?></strong><div class="muted">Boleto: <?= htmlspecialchars((string) ($boleto['boleto_situacao'] ?: 'sem boleto')) ?></div></td>
            <td><?= htmlspecialchars($documento) ?></td>
            <td><?= htmlspecialchars($competencia) ?></td>
            <td><input class="date-input due-date" type="date" value="<?= htmlspecialchars($vencimento, ENT_QUOTES) ?>" <?= $integracaoStatus === 'enviado' ? 'disabled' : '' ?>></td>
            <td><select class="obs-input obs-value" <?= $integracaoStatus === 'enviado' ? 'disabled' : '' ?>><option value="Boleto">Boleto</option><option value="Depósito Banco Sicoob">Depósito Banco Sicoob</option></select></td>
            <td><input class="money-input gross-value" type="text" inputmode="decimal" value="<?= contasReceberTelaMoney($valor) ?>" <?= $integracaoStatus === 'enviado' ? 'disabled' : '' ?>></td>
            <td><input class="money-input add-value" type="text" inputmode="decimal" value="0.00" <?= $integracaoStatus === 'enviado' ? 'disabled' : '' ?>></td>
            <td><input class="money-input discount-value" type="text" inputmode="decimal" value="0.00" <?= $integracaoStatus === 'enviado' ? 'disabled' : '' ?>></td>
            <td class="line-total" data-line-total><?= contasReceberTelaMoneyBr($valor) ?></td>
            <td>
              <span class="status <?= $statusClass ?>"><?= htmlspecialchars($integracaoStatus) ?></span>
              <?php if (!empty($boleto['integracao_destino_id'])): ?><div class="muted">Receber #<?= (int) $boleto['integracao_destino_id'] ?></div><?php endif; ?>
              <?php if (!empty($boleto['integracao_erro'])): ?><div class="warn integration-error-message"><?= htmlspecialchars((string) $boleto['integracao_erro']) ?></div><?php endif; ?>
            </td>
            <td>
              <?php if ($integracaoStatus !== 'cancelado'): ?>
                <div class="row-actions">
                  <button class="btn btn-icon send-row" type="button" title="Enviar ao contas a receber" aria-label="Enviar ao contas a receber" <?= in_array($integracaoStatus, ['enviado', 'ignorado'], true) ? 'disabled' : '' ?>><i class="bi bi-send"></i></button>
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
  let csrfToken = document.getElementById('csrfTokenContas').value;
  const resultBox = document.getElementById('integrationResult');
  const resultList = document.getElementById('resultList');

  rows.forEach(row => {
    const obs = row.querySelector('.obs-value');
    if (obs && row.dataset.hasBoleto !== '1') obs.value = 'Depósito Banco Sicoob';
  });

  function parseDecimal(value){
    const normalized = String(value || '').replace(',', '.').replace(/[^0-9.-]/g, '');
    const number = Number.parseFloat(normalized);
    return Number.isFinite(number) ? number : 0;
  }

  function toDotMoney(value){
    return Math.max(0, value).toFixed(2);
  }

  function normalizeInput(input){
    const value = toDotMoney(parseDecimal(input.value));
    input.value = value;
    return Number.parseFloat(value);
  }

  function recalcRow(row){
    const gross = normalizeInput(row.querySelector('.gross-value'));
    const add = normalizeInput(row.querySelector('.add-value'));
    const discount = normalizeInput(row.querySelector('.discount-value'));
    const net = Math.max(0, gross + add - discount);
    row.querySelector('[data-line-total]').textContent = money.format(net);
    return { gross, add, discount, net };
  }

  function rowPayload(row){
    const values = recalcRow(row);
    return {
      id: Number(row.dataset.originId || 0),
      vencimento: row.querySelector('.due-date').value,
      obs: row.querySelector('.obs-value').value,
      valor: toDotMoney(values.gross),
      acrescimo: toDotMoney(values.add),
      desconto: toDotMoney(values.discount)
    };
  }

  function selectedPayload(){
    return rows
      .filter(row => {
        const check = row.querySelector('.row-check');
        return check && check.checked && !check.disabled;
      })
      .map(rowPayload)
      .reverse();
  }

  function refreshSummary(){
    let gross = 0, add = 0, discount = 0, net = 0, selected = 0;
    rows.forEach(row => {
      const values = recalcRow(row);
      gross += values.gross;
      add += values.add;
      discount += values.discount;
      net += values.net;
      const check = row.querySelector('.row-check');
      if (check && check.checked && !check.disabled) selected++;
    });
    document.getElementById('summaryGross').textContent = money.format(gross);
    document.getElementById('summaryAdjust').textContent = money.format(add - discount);
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
      if (motivo.includes('vencimento')) orientacao = 'Preencha o campo "Vencimento".';
      else if (motivo.includes('valor ausente') || motivo.includes('valor total')) orientacao = 'Informe um valor maior que zero e revise acrescimos e descontos.';
      else if (motivo.includes('cliente ausente')) orientacao = 'Corrija o nome do cliente no cadastro da venda.';
      else if (motivo.includes('cpf/cnpj')) orientacao = 'Corrija o CPF/CNPJ do cliente no cadastro.';
      else if (motivo.includes('data de emissao')) orientacao = 'Corrija a data de emissao da NF-e de venda.';
      else if (motivo.includes('observacao')) orientacao = 'Selecione "Boleto" ou "Depósito Banco Sicoob".';
      else if (motivo.includes('duplicidade')) orientacao = 'Confira o ID informado no Contas a Receber; este lançamento já está cadastrado.';
      else if (motivo.includes('ja enviado')) orientacao = 'Este pedido ja foi enviado; nao deve ser reenviado.';
      else if (motivo.includes('historico ignorado')) orientacao = 'O pedido foi removido da fila e precisa ser restaurado antes do envio.';
      else if (motivo.includes('nao encontrada')) orientacao = 'Confirme se a NF-e de venda está autorizada e disponível na integração.';
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
      const rawErrors = item.erros && item.erros.length ? item.erros.join(' | ') : (item.mensagem || (item.avisos && item.avisos.length ? item.avisos.join(' | ') : 'OK'));
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
    if (mode === 'sent') setTimeout(() => window.location.reload(), 1600);
  }

  async function postJson(url, payload){
    const response = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload)
    });
    const responseText = await response.text();
    let data;
    try {
      data = JSON.parse(responseText);
    } catch (parseError) {
      if (response.status === 403) {
        throw new Error('Sua sessao foi renovada. Recarregue a pagina e tente novamente.');
      }
      throw new Error(responseText || ('Falha HTTP ' + response.status));
    }
    if (!response.ok && !data.erro_critico) throw new Error('Falha HTTP ' + response.status);
    return data;
  }

  async function diagnosticar(payload){
    const data = await postJson('actions/diagnostico_receber.php', {items: payload});
    if (data.erro_critico) throw new Error(data.erro_critico);
    if (data.csrf_token) {
      csrfToken = data.csrf_token;
      document.getElementById('csrfTokenContas').value = data.csrf_token;
    }
    setResult(data.ok ? 'Diagnóstico de pré-envio' : 'Diagnóstico com erro', data.resumo || {}, data.itens || [], 'diagnostic');
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
  });

  document.getElementById('recalcRows').addEventListener('click', refreshSummary);
  document.querySelectorAll('.cancel-row').forEach(button => {
    button.addEventListener('click', async function(){
      const row = this.closest('[data-row]');
      const id = Number(row && row.dataset.originId || 0);
      if (!id) return;
      if (!confirm('Remover esta NF-e da fila de contas a receber? Isso não exclui lançamento já criado no contas a receber.')) return;
      this.disabled = true;
      try {
        const data = await postJson('actions/excluir_fila.php', {csrf_token: csrfToken, tipo: 'receber', id});
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
    const data = await postJson('actions/enviar_receber.php', {csrf_token: csrfToken, items: payload});
    if (data.erro_critico) alert(data.erro_critico);
    setResult(data.ok ? 'Envio concluído' : 'Envio concluído com bloqueios/erros', data.resumo || {}, data.itens || [], 'sent');
    return true;
  }

  diagnoseSend.addEventListener('click', async function(){
    const payload = selectedPayload();
    diagnoseSend.disabled = true;
    try { await diagnosticar(payload); } catch (err) { alert(err.message || 'Erro no diagnóstico.'); }
    refreshSummary();
  });

  document.querySelectorAll('.send-row').forEach(button => {
    button.addEventListener('click', async function(){
      const row = this.closest('[data-row]');
      if (!row) return;
      const payload = [rowPayload(row)];
      this.disabled = true;
      try {
        const sent = await enviarPayload(payload, () => 'Enviar este lançamento ao contas a receber?');
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
      const sent = await enviarPayload(payload, aptas => 'Enviar ' + aptas + ' lançamento(s) ao contas a receber? Itens bloqueados não serão enviados.');
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

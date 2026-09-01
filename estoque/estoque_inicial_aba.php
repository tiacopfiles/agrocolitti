<?php
require "../config/conexao.php";
require "../config/ciclo_helper.php";
require "../auth/proteger.php";
require "../config/layout_helper.php";
require "../config/permissions.php";

requireModule('estoque', '../index.php');

$cicloAtivo   = getCicloAtivo($conexao);
$cicloAtivoId = $cicloAtivo ? (int) $cicloAtivo['id'] : null;

$produtosResult = $conexao->query("
    SELECT id, nome
    FROM produtos
    WHERE ativo = 1 AND produto_principal_id IS NULL
    ORDER BY nome
");

// Mapa produto_id => array('estoque_id'=>..., 'quantidade'=>...)
$estoqueAtual = array();
if ($cicloAtivoId && colunaExiste($conexao, 'estoque_inicial', 'ciclo_id')) {
    $stmtE = $conexao->prepare("SELECT id, produto_id, quantidade FROM estoque_inicial WHERE ciclo_id = ?");
    $stmtE->bind_param("i", $cicloAtivoId);
    $stmtE->execute();
    $resE = $stmtE->get_result();
    while ($row = $resE->fetch_assoc()) {
        $estoqueAtual[(int)$row['produto_id']] = array('estoque_id' => (int)$row['id'], 'quantidade' => (float)$row['quantidade']);
    }
    $stmtE->close();
} else {
    $resE2 = $conexao->query("SELECT id, produto_id, quantidade FROM estoque_inicial");
    if ($resE2) while ($row = $resE2->fetch_assoc()) {
        $estoqueAtual[(int)$row['produto_id']] = array('estoque_id' => (int)$row['id'], 'quantidade' => (float)$row['quantidade']);
    }
}

// Lista completa de produtos (para o seletor "Adicionar Produto")
$todosProdutos = array();
$produtos = array();
while ($p = $produtosResult->fetch_assoc()) {
    $pid = (int)$p['id'];
    $todosProdutos[] = array('id' => $pid, 'nome' => $p['nome']);
    // Mostra todos os produtos com registro de estoque no ciclo, inclusive zerados,
    // para que apos "Zerar contagem" eles continuem na lista e a operadora so preencha.
    if (isset($estoqueAtual[$pid])) {
        $produtos[] = array(
            'id'         => $pid,
            'estoque_id' => $estoqueAtual[$pid]['estoque_id'],
            'nome'       => $p['nome'],
            // Esta tela representa exclusivamente a base inicial do ciclo.
            // Entradas, vendas e ajustes pertencem ao saldo operacional.
            'quantidade' => $estoqueAtual[$pid]['quantidade'],
        );
    }
}

// Contagens reais para o modal de confirmacao do "Zerar contagem" (somente leitura).
$ehAdmin = (($_SESSION['usuario_nivel'] ?? '') === 'admin');
$zc = array('previsoes_fornecedor' => 0, 'entradas' => 0, 'vendas' => 0, 'movimentacoes' => 0);
if ($ehAdmin && $cicloAtivoId) {
    $rPrev = $conexao->query("SELECT COUNT(*) c FROM previsao_fornecedor WHERE ciclo_id={$cicloAtivoId}");
    $zc['previsoes_fornecedor'] = $rPrev ? (int) $rPrev->fetch_assoc()['c'] : 0;
    $rEnt = $conexao->query("SELECT COUNT(*) c FROM entradas WHERE ciclo_id={$cicloAtivoId}");
    $zc['entradas'] = $rEnt ? (int) $rEnt->fetch_assoc()['c'] : 0;
    $filtroCicloVendas = colunaExiste($conexao, 'vendas', 'ciclo_id') ? " AND ciclo_id={$cicloAtivoId}" : '';
    $rVendas = $conexao->query("SELECT COUNT(*) c FROM vendas WHERE status='concluido'{$filtroCicloVendas}");
    $zc['vendas'] = $rVendas ? (int) $rVendas->fetch_assoc()['c'] : 0;
    $rMov = $conexao->query("SELECT COUNT(*) c FROM movimentacoes WHERE ciclo_id={$cicloAtivoId}");
    $zc['movimentacoes'] = $rMov ? (int) $rMov->fetch_assoc()['c'] : 0;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Estoque Inicial</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;background:#f0f2f5;color:#1a1a1a}

.ei-wrap{max-width:780px;margin:24px auto;padding:0 16px}

/* Mensagens */
.ei-msg{padding:9px 14px;border-radius:7px;font-size:13px;font-weight:600;margin-bottom:14px}
.ei-msg-ciclo{background:#e8f5e9;color:#2e7d32;border:1px solid #c8e6c9}
.ei-msg-ok{background:#e3f2fd;color:#1565c0;border:1px solid #bbdefb}

/* Card */
.ei-card{background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.09);overflow:hidden}

/* Cabeçalho do card */
.ei-card-head{padding:14px 20px;border-bottom:1px solid #e8ede8;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.ei-card-head h2{font-size:15px;font-weight:700;color:#1b5e20;display:flex;align-items:center;gap:7px}
.ei-head-right{display:flex;align-items:center;gap:12px}
.ei-count{font-size:12px;color:#888}

/* Botão Adicionar Produto */
.ei-add-toggle{display:inline-flex;align-items:center;gap:6px;background:#fff;color:#1b5e20;border:1px solid #b7d1bc;padding:7px 14px;border-radius:7px;cursor:pointer;font-size:13px;font-weight:700;transition:.15s}
.ei-add-toggle:hover{background:#f1f8f4;border-color:#2e7d32}

/* Painel de adicionar */
.ei-add-panel{display:none;padding:16px 20px;background:#f6faf6;border-bottom:1px solid #e8ede8}
.ei-add-panel.open{display:block}
.ei-add-row{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}
.ei-field{display:flex;flex-direction:column;gap:5px}
.ei-field label{font-size:11px;font-weight:700;color:#607066;text-transform:uppercase;letter-spacing:.03em}
.ei-field.grow{flex:1;min-width:200px}
.ei-select,.ei-add-qty{padding:8px 10px;border:1px solid #d0d7d0;border-radius:6px;font-size:13px;background:#fff;width:100%}
.ei-add-qty{width:120px;text-align:right}
.ei-select:focus,.ei-add-qty:focus{border-color:#2e7d32;box-shadow:0 0 0 2px rgba(46,125,50,.15);outline:none}
.ei-add-submit{display:inline-flex;align-items:center;gap:6px;background:#1b5e20;color:#fff;border:none;padding:9px 18px;border-radius:7px;cursor:pointer;font-size:13px;font-weight:700;transition:background .15s;white-space:nowrap}
.ei-add-submit:hover{background:#154a19}

/* Tabela */
.ei-table{width:100%;border-collapse:collapse}
.ei-table .col-nome{width:58%}
.ei-table .col-qty{width:30%}
.ei-table .col-acao{width:12%}

.ei-table thead tr{background:#1b5e20}
.ei-table thead th{color:#fff;font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;padding:9px 16px;text-align:left}
.ei-table thead th.col-qty{text-align:right;padding-right:20px}
.ei-table thead th.col-acao{text-align:center;padding-left:0;padding-right:0}

.ei-table tbody tr{border-bottom:1px solid #f0f0f0;transition:background .12s}
.ei-table tbody tr:last-child{border-bottom:none}
.ei-table tbody tr:hover{background:#f6faf6}

.ei-table tbody td{padding:6px 16px;font-size:13.5px;color:#222;vertical-align:middle}
.ei-table tbody td.col-qty{text-align:right;padding-right:12px}
.ei-table tbody td.col-acao{text-align:center;padding-left:0;padding-right:8px}

.ei-empty{padding:24px 20px;text-align:center;color:#888;font-size:13.5px}

/* Botão excluir por linha */
.ei-del{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border:1px solid #f0c7c7;background:#fff;color:#c62828;border-radius:6px;cursor:pointer;text-decoration:none;transition:.15s}
.ei-del:hover{background:#c62828;color:#fff;border-color:#c62828}

/* Input */
.ei-input{
  width:100px;padding:5px 8px;border:1px solid #d0d7d0;border-radius:5px;
  font-size:13px;text-align:right;background:#fafff8;
  transition:border .15s,box-shadow .15s;appearance:textfield;-moz-appearance:textfield
}
.ei-input:focus{border-color:#2e7d32;box-shadow:0 0 0 2px rgba(46,125,50,.15);outline:none}
.ei-input::-webkit-inner-spin-button,.ei-input::-webkit-outer-spin-button{-webkit-appearance:none}

/* Rodapé */
.ei-card-foot{padding:12px 20px;border-top:1px solid #e8ede8;display:flex;justify-content:flex-end}
.ei-btn{display:inline-flex;align-items:center;gap:6px;background:#2e7d32;color:#fff;border:none;padding:9px 20px;border-radius:7px;cursor:pointer;font-size:13.5px;font-weight:700;transition:background .15s}
.ei-btn:hover{background:#1b5e20}

@media(max-width:540px){
  .ei-wrap{padding:0 8px;margin:14px auto}
  .ei-table thead th,.ei-table tbody td{padding:6px 8px;font-size:12.5px}
  .ei-input{width:74px}
  .ei-add-row{flex-direction:column;align-items:stretch}
  .ei-add-qty{width:100%}
}

/* Botao Zerar contagem */
.ei-zerar-toggle{display:inline-flex;align-items:center;gap:6px;background:#fff;color:#c62828;border:1px solid #f0c7c7;padding:7px 14px;border-radius:7px;cursor:pointer;font-size:13px;font-weight:700;transition:.15s}
.ei-zerar-toggle:hover{background:#c62828;color:#fff;border-color:#c62828}
.ei-msg-erro{background:#ffebee;color:#c62828;border:1px solid #ef9a9a}
.ei-msg-zerado{background:#fff8e1;color:#795548;border:1px solid #ffe082}

/* Modal Zerar */
.ei-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1500;padding:20px;overflow-y:auto}
.ei-modal.open{display:flex;align-items:center;justify-content:center}
.ei-modal-box{background:#fff;max-width:520px;width:min(100%,520px);margin:auto;padding:24px;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.25)}
.ei-modal-box h3{margin:0 0 6px;font-size:18px;color:#c62828;display:flex;align-items:center;gap:8px}
.ei-modal-box p{font-size:13.5px;color:#444;margin:8px 0}
.ei-resumo{list-style:none;margin:12px 0;padding:12px 14px;background:#f7f7f7;border-radius:8px;font-size:13.5px}
.ei-resumo li{display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #ececec}
.ei-resumo li:last-child{border-bottom:none}
.ei-resumo .apagar{color:#c62828;font-weight:700}
.ei-resumo .manter{color:#2e7d32;font-weight:700}
.ei-warn{background:#fff8e1;border:1px solid #ffe082;color:#795548;border-radius:8px;padding:10px 12px;font-size:12.5px;margin:10px 0}
.ei-confirm-input{width:100%;padding:10px;border:2px solid #f0c7c7;border-radius:7px;font-size:15px;text-align:center;letter-spacing:.15em;font-weight:800;text-transform:uppercase;margin-top:6px}
.ei-confirm-input:focus{border-color:#c62828;outline:none}
.ei-modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:16px;flex-wrap:wrap}
.ei-btn-cancel{background:#fff;color:#444;border:1px solid #ccc;padding:9px 18px;border-radius:7px;cursor:pointer;font-size:13.5px;font-weight:700}
.ei-btn-zerar{background:#c62828;color:#fff;border:none;padding:9px 20px;border-radius:7px;cursor:pointer;font-size:13.5px;font-weight:700}
.ei-btn-zerar:disabled{background:#e0a3a3;cursor:not-allowed}
</style>
<?php renderAppLayoutStyles(); ?>
</head>
<body>
<?php renderAppHeader('..'); ?>

<div class="ei-wrap page-container app-shell">

<?php if ($cicloAtivo): ?>
<div class="ei-msg ei-msg-ciclo">Ciclo ativo: <?= htmlspecialchars(getNomeCiclo($cicloAtivo)) ?></div>
<?php endif; ?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'ok'): ?>
<div class="ei-msg ei-msg-ok"><i class="bi bi-check-circle"></i> Estoque atualizado com sucesso!</div>
<?php endif; ?>
<?php if (isset($_GET['msg']) && $_GET['msg'] === 'zerado'): ?>
<div class="ei-msg ei-msg-zerado"><i class="bi bi-arrow-counterclockwise"></i> Contagem zerada. Os registros foram arquivados no Ciclos e os produtos estão prontos para a nova contagem.</div>
<?php endif; ?>
<?php if (isset($_GET['msg']) && $_GET['msg'] === 'erro'): ?>
<div class="ei-msg ei-msg-erro"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($_GET['detalhe'] ?? 'Ocorreu um erro.') ?></div>
<?php endif; ?>

<div class="ei-card">

  <div class="ei-card-head">
    <h2><i class="bi bi-clipboard-check"></i> Contagem de Estoque</h2>
    <div class="ei-head-right">
      <span class="ei-count"><?= count($produtos) ?> produto(s) com estoque</span>
      <button type="button" class="ei-add-toggle" onclick="document.getElementById('eiAddPanel').classList.toggle('open')">
        <i class="bi bi-plus-lg"></i> Adicionar Produto
      </button>
      <?php if ($ehAdmin && $cicloAtivoId): ?>
      <button type="button" class="ei-zerar-toggle" onclick="eiAbrirModalZerar()">
        <i class="bi bi-archive"></i> Arquivar e zerar
      </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- PAINEL ADICIONAR PRODUTO -->
  <div class="ei-add-panel" id="eiAddPanel">
    <form method="POST" action="actions/salvar_estoque_inicial.php" class="ei-add-row">
      <div class="ei-field grow">
        <label>Produto</label>
        <select class="ei-select" name="produto_id" required>
          <option value="">Selecione um produto...</option>
          <?php foreach ($todosProdutos as $tp): ?>
            <option value="<?= $tp['id'] ?>"><?= htmlspecialchars($tp['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="ei-field">
        <label>Quantidade (kg)</label>
        <input class="ei-add-qty" type="number" step="0.01" min="0.01" name="quantidade" placeholder="0,00" required>
      </div>
      <button type="submit" class="ei-add-submit">
        <i class="bi bi-check-lg"></i> Adicionar
      </button>
    </form>
  </div>

  <form method="POST" action="actions/atualizar_estoque_em_massa.php">
    <table class="ei-table">
      <thead>
        <tr>
          <th class="col-nome">Produto</th>
          <th class="col-qty">Quantidade (kg)</th>
          <th class="col-acao">Ação</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($produtos)): ?>
        <tr><td colspan="3" class="ei-empty">Nenhum produto com estoque. Use <strong>Adicionar Produto</strong> para incluir.</td></tr>
      <?php endif; ?>
      <?php foreach ($produtos as $p): ?>
        <tr>
          <td class="col-nome"><?= htmlspecialchars($p['nome']) ?></td>
          <td class="col-qty">
            <input
              class="ei-input"
              type="number"
              step="0.01"
              min="0"
              name="qtd[<?= $p['id'] ?>]"
              value="<?= number_format($p['quantidade'], 2, '.', '') ?>">
          </td>
          <td class="col-acao">
            <a class="ei-del"
               href="actions/excluir_estoque_inicial.php?id=<?= $p['estoque_id'] ?>"
               title="Excluir produto da contagem"
               onclick="return confirm('Excluir <?= htmlspecialchars(addslashes($p['nome'])) ?> da contagem de estoque?')">
              <i class="bi bi-trash"></i>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div class="ei-card-foot">
      <button type="submit" class="ei-btn">
        <i class="bi bi-check-lg"></i> Atualizar Estoque
      </button>
    </div>
  </form>

</div>
</div>

<?php if ($ehAdmin && $cicloAtivoId): ?>
<!-- MODAL ZERAR CONTAGEM -->
<div class="ei-modal" id="eiModalZerar">
  <div class="ei-modal-box">
    <h3><i class="bi bi-archive"></i> Arquivar movimentações e zerar estoque</h3>
    <p>Esta ação encerra a movimentação operacional atual. Registros concluídos deixam os históricos e o dashboard, mas continuam disponíveis em Ciclos. O ciclo atual permanece aberto.</p>
    <ul class="ei-resumo">
      <li><span>Produtos na contagem — serão zerados</span><span class="manter"><?= count($produtos) ?></span></li>
      <li><span>Entradas concluídas — serão arquivadas em Ciclos</span><span class="apagar"><?= (int) $zc['entradas'] ?></span></li>
      <li><span>Vendas concluídas — serão arquivadas em Ciclos</span><span class="apagar"><?= (int) $zc['vendas'] ?></span></li>
      <li><span>Movimentações concluídas — serão arquivadas em Ciclos</span><span class="apagar"><?= (int) $zc['movimentacoes'] ?></span></li>
      <li><span>Pendências fiscais e financeiras — serão mantidas</span><span class="manter">Sim</span></li>
    </ul>
    <div class="ei-warn"><i class="bi bi-shield-check"></i> Os dados concluídos são copiados para o arquivo do ciclo antes de sair da área operacional. Previsões e processos ainda pendentes, NF-e e boletos permanecem disponíveis.</div>
    <form method="POST" action="actions/zerar_contagem.php" onsubmit="return eiConfirmarZerar()">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
      <label style="font-size:13px;font-weight:700;color:#c62828">Para confirmar, digite <strong>ZERAR</strong>:</label>
      <input type="text" class="ei-confirm-input" id="eiConfirmInput" name="confirmacao" autocomplete="off" placeholder="ZERAR" oninput="eiValidarZerar()">
      <div class="ei-modal-actions">
        <button type="button" class="ei-btn-cancel" onclick="eiFecharModalZerar()">Cancelar</button>
        <button type="submit" class="ei-btn-zerar" id="eiBtnZerar" disabled><i class="bi bi-archive"></i> Arquivar e zerar agora</button>
      </div>
    </form>
  </div>
</div>
<script>
function eiAbrirModalZerar(){ document.getElementById('eiModalZerar').classList.add('open'); }
function eiFecharModalZerar(){
  document.getElementById('eiModalZerar').classList.remove('open');
  document.getElementById('eiConfirmInput').value='';
  eiValidarZerar();
}
function eiValidarZerar(){
  var v=(document.getElementById('eiConfirmInput').value||'').trim().toUpperCase();
  document.getElementById('eiBtnZerar').disabled=(v!=='ZERAR');
}
function eiConfirmarZerar(){
  var v=(document.getElementById('eiConfirmInput').value||'').trim().toUpperCase();
  if(v!=='ZERAR'){ return false; }
  if(!confirm('Confirma o arquivamento das movimentações concluídas e a zeragem do estoque? Os registros sairão dos históricos operacionais e ficarão disponíveis em Ciclos.')){ return false; }
  var botao=document.getElementById('eiBtnZerar');
  botao.disabled=true;
  botao.innerHTML='<i class="bi bi-hourglass-split"></i> Zerando...';
  return true;
}
document.getElementById('eiModalZerar').addEventListener('click',function(e){ if(e.target===this) eiFecharModalZerar(); });
</script>
<?php endif; ?>

</body>
</html>

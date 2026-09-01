<?php
require "../config/conexao.php";
require "../auth/proteger.php";
require "../config/permissions.php";
require "../config/layout_helper.php";
require "../focus/focus_nfe_caixas.php";

requireModule('notas_caixas', '../index.php');
caixaNotasGarantirTabelas($conexao);

$tipos = caixaNotasTipos($conexao);
$clientePadrao = caixaNotasClientePadrao($conexao);
$dataHoje = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nova Nota de Caixas</title>
<style>
body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;color:#222}.container{padding:30px;max-width:1100px;margin:auto}
.card{background:#fff;padding:24px;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,.06);margin-bottom:24px}
.grid{display:grid;grid-template-columns:2fr 1fr;gap:16px}.item-grid{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:12px;align-items:end}
label{font-weight:700;font-size:14px}input,select,textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #ccc;border-radius:6px;margin-top:5px;font-family:inherit}
textarea{min-height:90px;resize:vertical}.btn{border:0;border-radius:6px;padding:10px 14px;color:#fff;background:#2e7d32;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px}.btn-secondary{background:#6b7280}
.actions{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.summary div{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:12px}
.msg-erro{background:#ffebee;color:#c62828;border:1px solid #ef9a9a;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600}
@media(max-width:760px){.grid,.item-grid,.summary{grid-template-columns:1fr}.container{padding:18px}}
</style>
<?php renderAppLayoutStyles(); ?>
<script>
function atualizarTotaisCaixas(){
  let qtd=0,peso=0,valor=0;
  document.querySelectorAll('[data-caixa-row]').forEach(row=>{
    const q=Number(row.querySelector('[data-qtd]').value||0);
    const p=Number(row.dataset.peso||0);
    const v=Number(row.querySelector('[data-valor]').value||0);
    qtd+=q; peso+=q*p; valor+=q*v;
  });
  document.getElementById('total_qtd').textContent=qtd.toLocaleString('pt-BR');
  document.getElementById('total_peso').textContent=peso.toLocaleString('pt-BR',{minimumFractionDigits:3,maximumFractionDigits:3})+' kg';
  document.getElementById('total_valor').textContent='R$ '+valor.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
}
document.addEventListener('DOMContentLoaded', atualizarTotaisCaixas);
</script>
</head>
<body>
<?php renderAppHeader('..'); ?>
<div class="container page-container app-shell">
<?php if (($_GET['msg'] ?? '') === 'erro'): ?><div class="msg-erro"><?= htmlspecialchars($_GET['detalhe'] ?? 'Erro ao processar.') ?></div><?php endif; ?>
<div class="card app-creation-card">
<h2>Nova Nota de Caixas</h2>
<form method="POST" action="actions/criar_nota_caixa.php">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
<input type="hidden" name="cliente_id" value="<?= (int) $clientePadrao['id'] ?>">
<div class="grid">
  <div><label>Cliente / Destinatário</label><input type="text" value="<?= htmlspecialchars((string) ($clientePadrao['nfe_nome_razao_social'] ?: $clientePadrao['nome'])) ?>" disabled></div>
  <div><label>Data</label><input type="date" name="data_nota" value="<?= htmlspecialchars($dataHoje) ?>" required></div>
</div>
<h3>Caixas</h3>
<?php foreach ($tipos as $tipo): ?>
<div class="item-grid" data-caixa-row data-peso="<?= htmlspecialchars((string) $tipo['peso_kg']) ?>">
  <div><label><?= htmlspecialchars((string) $tipo['nome']) ?></label><input type="text" value="NCM <?= htmlspecialchars((string) $tipo['ncm']) ?> | <?= number_format((float) $tipo['peso_kg'], 3, ',', '.') ?> kg" disabled></div>
  <div><label>Quantidade</label><input data-qtd type="number" min="0" step="1" name="itens[<?= (int) $tipo['id'] ?>][quantidade]" value="0" oninput="atualizarTotaisCaixas()"></div>
  <div><label>Valor unitário</label><input data-valor type="number" min="0" step="0.01" name="itens[<?= (int) $tipo['id'] ?>][valor_unitario]" value="<?= htmlspecialchars(number_format((float) $tipo['valor_unitario'], 2, '.', '')) ?>" oninput="atualizarTotaisCaixas()"></div>
  <div><label>Unidade</label><input type="text" value="<?= htmlspecialchars((string) $tipo['unidade']) ?>" disabled></div>
</div>
<?php endforeach; ?>
<h3>Totais</h3>
<div class="summary"><div><strong>Quantidade</strong><br><span id="total_qtd">0</span></div><div><strong>Peso bruto/líquido</strong><br><span id="total_peso">0,000 kg</span></div><div><strong>Valor</strong><br><span id="total_valor">R$ 0,00</span></div></div>
<div style="margin-top:16px;"><label>Informações complementares</label><textarea name="observacoes">Rem isent ICMS conf. artigo SPart 82,I b Anexo I Do RICMS/2000df at 6 e anexo I caderno I item 42GO anexo IX e art 6XXXiv do RCTE/GO Aliquota Conforme Art 54 Do RICMS/2000</textarea></div>
<div class="actions" style="margin-top:18px;"><a class="btn btn-secondary" href="historico.php"><i class="bi bi-clock-history"></i> Histórico</a><button class="btn" type="submit"><i class="bi bi-check-lg"></i> Criar nota</button></div>
</form>
</div>
</div>
</body>
</html>

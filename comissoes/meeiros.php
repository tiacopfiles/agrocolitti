<?php
require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../auth/proteger.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../config/layout_helper.php';
require_once __DIR__ . '/meeiros_helper_v2.php';

requirePermission(PERM_ADMIN, '../index.php');
comissoesMeeirosGarantirEstrutura($conexao);

$meeiros = comissoesMeeirosListarAtivos($conexao);
$resumos = [];
foreach ($meeiros as $meeiro) {
    $resumos[(int) $meeiro['id']] = [
        'nome' => (string) $meeiro['nome'],
        'pedidos' => 0,
        'peso' => 0.0,
        'descarte' => 0.0,
        'preco_final' => 0.0,
        'comissao' => 0.0,
    ];
}

$resultado = $conexao->query("
    SELECT cm.meeiro_id, COUNT(DISTINCT cm.previsao_colheita_id) AS pedidos,
           SUM(cm.peso_entrada_kg) AS peso,
           SUM(cm.descarte_kg) AS descarte,
           SUM(cm.preco_final) AS preco_final,
           SUM(cm.valor_comissao) AS comissao
    FROM comissoes_meeiros cm
    GROUP BY cm.meeiro_id
");
while ($resultado && ($row = $resultado->fetch_assoc())) {
    $id = (int) $row['meeiro_id'];
    if (!isset($resumos[$id])) {
        continue;
    }
    $resumos[$id]['pedidos'] = (int) $row['pedidos'];
    $resumos[$id]['peso'] = (float) $row['peso'];
    $resumos[$id]['descarte'] = (float) $row['descarte'];
    $resumos[$id]['preco_final'] = (float) $row['preco_final'];
    $resumos[$id]['comissao'] = (float) $row['comissao'];
}
if ($resultado instanceof mysqli_result) $resultado->free();

$totalPedidos = array_sum(array_column($resumos, 'pedidos'));
$totalPrecoFinal = array_sum(array_column($resumos, 'preco_final'));
$totalComissao = array_sum(array_column($resumos, 'comissao'));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Comissoes de meeiros</title>
<style>
body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;color:#1f2937}.container{max-width:1220px;margin:auto;padding:30px}.page-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:20px}.page-head h1{margin:0;color:#1b5e20;font-size:27px}.page-head p{margin:7px 0 0;color:#64748b}.summary-row{display:flex;gap:12px;flex-wrap:wrap}.summary{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;min-width:170px;box-shadow:0 4px 14px rgba(15,23,42,.06)}.summary span{display:block;color:#64748b;font-size:12px;font-weight:700;text-transform:uppercase}.summary strong{font-size:25px;color:#166534}.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 14px;border-radius:7px;background:#e8f5e9;color:#1b5e20;text-decoration:none;font-weight:700}.cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.seller-card{display:flex;align-items:center;gap:15px;background:#fff;border:1px solid #dce7df;border-radius:12px;padding:22px;color:inherit;text-decoration:none;box-shadow:0 5px 16px rgba(15,23,42,.06);transition:.18s ease}.seller-card:hover{transform:translateY(-2px);border-color:#66a76f;box-shadow:0 9px 22px rgba(22,101,52,.12)}.avatar{width:52px;height:52px;border-radius:50%;display:grid;place-items:center;background:#e8f5e9;color:#1b5e20;font-size:23px}.seller-info{flex:1}.seller-info h2{margin:0;font-size:19px;color:#173e24}.seller-info p{margin:5px 0 0;color:#64748b;font-size:13px;line-height:1.45}.arrow{color:#2e7d32;font-size:20px}.money{font-weight:800;color:#166534}@media(max-width:850px){.container{padding:18px}.page-head{display:block}.summary-row{margin-top:14px}.cards{grid-template-columns:1fr}}
</style>
<?php renderAppLayoutStyles(); ?>
</head>
<body>
<?php renderAppHeader('..'); ?>
<main class="container page-container app-shell">
  <div class="page-head">
    <div><h1>Comissoes de meeiros</h1><p>Valores gerados diretamente na confirmacao da previsao de colheita.</p></div>
    <div class="summary-row">
      <a class="btn" href="tabela_precos_meeiros.php"><i class="bi bi-table"></i> Tabela de precos</a>
      <div class="summary"><span>Pedidos</span><strong><?= $totalPedidos ?></strong></div>
      <div class="summary"><span>Valor total</span><strong>R$ <?= number_format($totalPrecoFinal, 2, ',', '.') ?></strong></div>
      <div class="summary"><span>Comissao 30%</span><strong>R$ <?= number_format($totalComissao, 2, ',', '.') ?></strong></div>
    </div>
  </div>
  <section class="cards">
    <?php foreach ($resumos as $meeiroId => $resumo): ?>
      <a class="seller-card" href="meeiro_detalhe.php?meeiro_id=<?= (int) $meeiroId ?>">
        <span class="avatar"><i class="bi bi-flower1"></i></span>
        <span class="seller-info">
          <h2><?= htmlspecialchars($resumo['nome'], ENT_QUOTES, 'UTF-8') ?></h2>
          <p><?= (int) $resumo['pedidos'] ?> pedido(s) | <?= number_format((float) $resumo['peso'], 2, ',', '.') ?> kg | descarte <?= number_format((float) $resumo['descarte'], 2, ',', '.') ?> kg</p>
          <p>Valor total: <span class="money">R$ <?= number_format((float) $resumo['preco_final'], 2, ',', '.') ?></span></p>
          <p>Comissao 30%: <span class="money">R$ <?= number_format((float) $resumo['comissao'], 2, ',', '.') ?></span></p>
        </span>
        <i class="bi bi-chevron-right arrow"></i>
      </a>
    <?php endforeach; ?>
  </section>
</main>
</body>
</html>

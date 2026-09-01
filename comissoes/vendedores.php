<?php
require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../auth/proteger.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../config/layout_helper.php';
require_once __DIR__ . '/vendedores_helper.php';

requirePermission(PERM_ADMIN, '../index.php');
comissoesVendedoresGarantirEstrutura($conexao);

$vendedores = comissoesVendedoresLista();
$contagens = array_fill_keys(array_keys($vendedores), 0);
$totais = array_fill_keys(array_keys($vendedores), 0.0);

$resultado = $conexao->query("
    SELECT cv.vendedor_nome, COUNT(*) AS total, SUM(cv.valor_comissao) AS total_comissao
    FROM comissoes_vendedores cv
    INNER JOIN contas_integracoes ci
        ON ci.id = cv.contas_integracao_id
       AND ci.tipo = 'receber'
       AND ci.status = 'enviado'
    INNER JOIN nfe_documentos nd
        ON nd.id = cv.nfe_documento_id
       AND nd.tipo_emissao = 'venda'
       AND nd.status = 'autorizada'
    GROUP BY cv.vendedor_nome
");
while ($resultado && ($row = $resultado->fetch_assoc())) {
    $nome = comissoesVendedoresNormalizarNome((string) $row['vendedor_nome']);
    if ($nome !== null) {
        $contagens[$nome] = (int) $row['total'];
        $totais[$nome] = (float) $row['total_comissao'];
    }
}
if ($resultado instanceof mysqli_result) $resultado->free();
$totalPedidos = array_sum($contagens);
$totalComissao = array_sum($totais);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Comissoes de vendedores</title>
<style>
body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;color:#1f2937}.container{max-width:1220px;margin:auto;padding:30px}.page-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:20px}.page-head h1{margin:0;color:#1b5e20;font-size:27px}.page-head p{margin:7px 0 0;color:#64748b}.summary-row{display:flex;gap:12px;flex-wrap:wrap}.summary{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;min-width:170px;box-shadow:0 4px 14px rgba(15,23,42,.06)}.summary span{display:block;color:#64748b;font-size:12px;font-weight:700;text-transform:uppercase}.summary strong{font-size:25px;color:#166534}.cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.seller-card{display:flex;align-items:center;gap:15px;background:#fff;border:1px solid #dce7df;border-radius:12px;padding:22px;color:inherit;text-decoration:none;box-shadow:0 5px 16px rgba(15,23,42,.06);transition:.18s ease}.seller-card:hover{transform:translateY(-2px);border-color:#66a76f;box-shadow:0 9px 22px rgba(22,101,52,.12)}.avatar{width:52px;height:52px;border-radius:50%;display:grid;place-items:center;background:#e8f5e9;color:#1b5e20;font-size:23px}.seller-info{flex:1}.seller-info h2{margin:0;font-size:19px;color:#173e24}.seller-info p{margin:5px 0 0;color:#64748b;font-size:13px}.arrow{color:#2e7d32;font-size:20px}.money{font-weight:800;color:#166534}@media(max-width:850px){.container{padding:18px}.page-head{display:block}.summary-row{margin-top:14px}.cards{grid-template-columns:1fr}}
</style>
<?php renderAppLayoutStyles(); ?>
</head>
<body>
<?php renderAppHeader('..'); ?>
<main class="container page-container app-shell">
  <div class="page-head">
    <div><h1>Comissoes de vendedores</h1><p>Acompanhe os pedidos enviados ao contas a receber por vendedor.</p></div>
    <div class="summary-row">
      <div class="summary"><span>Pedidos</span><strong><?= $totalPedidos ?></strong></div>
      <div class="summary"><span>Comissao</span><strong>R$ <?= number_format($totalComissao, 2, ',', '.') ?></strong></div>
    </div>
  </div>
  <section class="cards">
    <?php foreach ($vendedores as $vendedor => $percentual): ?>
      <a class="seller-card" href="vendedor_detalhe.php?vendedor=<?= rawurlencode($vendedor) ?>">
        <span class="avatar"><i class="bi bi-person-badge"></i></span>
        <span class="seller-info">
          <h2><?= htmlspecialchars($vendedor, ENT_QUOTES, 'UTF-8') ?></h2>
          <p><?= $contagens[$vendedor] ?> pedido(s) | <?= number_format((float) $percentual, 2, ',', '.') ?>% | <span class="money">R$ <?= number_format($totais[$vendedor], 2, ',', '.') ?></span></p>
        </span>
        <i class="bi bi-chevron-right arrow"></i>
      </a>
    <?php endforeach; ?>
  </section>
</main>
</body>
</html>

<?php
require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../auth/proteger.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../config/layout_helper.php';
require_once __DIR__ . '/frete_helper.php';

requirePermission(PERM_ADMIN, '../index.php');

$contagens = array_fill_keys(comissoesFreteEntrepostos(), 0);
$resultado = $conexao->query("
    SELECT cf.entreposto, COUNT(*) AS total
    FROM comissoes_frete cf
    INNER JOIN contas_integracoes ci
        ON ci.id = cf.contas_integracao_id
       AND ci.tipo = 'receber'
       AND ci.status = 'enviado'
    INNER JOIN nfe_documentos nd
        ON nd.id = cf.nfe_documento_id
       AND nd.tipo_emissao = 'venda'
       AND nd.status = 'autorizada'
    GROUP BY cf.entreposto
");
while ($resultado && ($row = $resultado->fetch_assoc())) {
    if (array_key_exists($row['entreposto'], $contagens)) {
        $contagens[$row['entreposto']] = (int) $row['total'];
    }
}
if ($resultado instanceof mysqli_result) $resultado->free();
$totalDesignacoes = array_sum($contagens);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Comissoes de frete</title>
<style>
body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;color:#1f2937}.container{max-width:1220px;margin:auto;padding:30px}.page-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:20px}.page-head h1{margin:0;color:#1b5e20;font-size:27px}.page-head p{margin:7px 0 0;color:#64748b}.summary{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;min-width:170px;box-shadow:0 4px 14px rgba(15,23,42,.06)}.summary span{display:block;color:#64748b;font-size:12px;font-weight:700;text-transform:uppercase}.summary strong{font-size:25px;color:#166534}.cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.driver-card{display:flex;align-items:center;gap:15px;background:#fff;border:1px solid #dce7df;border-radius:12px;padding:22px;color:inherit;text-decoration:none;box-shadow:0 5px 16px rgba(15,23,42,.06);transition:.18s ease}.driver-card:hover{transform:translateY(-2px);border-color:#66a76f;box-shadow:0 9px 22px rgba(22,101,52,.12)}.avatar{width:52px;height:52px;border-radius:50%;display:grid;place-items:center;background:#e8f5e9;color:#1b5e20;font-size:23px}.driver-info{flex:1}.driver-info h2{margin:0;font-size:19px;color:#173e24}.driver-info p{margin:5px 0 0;color:#64748b;font-size:13px}.arrow{color:#2e7d32;font-size:20px}@media(max-width:850px){.container{padding:18px}.page-head{display:block}.summary{margin-top:14px}.cards{grid-template-columns:1fr}}
</style>
<?php renderAppLayoutStyles(); ?>
</head>
<body>
<?php renderAppHeader('..'); ?>
<main class="container page-container app-shell">
  <div class="page-head">
    <div><h1>Comissoes de frete</h1><p>Acompanhe as vendas novas designadas para cada entreposto.</p></div>
    <div class="summary"><span>Designacoes</span><strong><?= $totalDesignacoes ?></strong></div>
  </div>
  <section class="cards">
    <?php foreach (comissoesFreteEntrepostos() as $entreposto): ?>
      <a class="driver-card" href="frete_detalhe.php?entreposto=<?= rawurlencode($entreposto) ?>">
        <span class="avatar"><i class="bi bi-truck"></i></span>
        <span class="driver-info"><h2><?= htmlspecialchars($entreposto, ENT_QUOTES, 'UTF-8') ?></h2><p><?= $contagens[$entreposto] ?> pedido(s) designado(s)</p></span>
        <i class="bi bi-chevron-right arrow"></i>
      </a>
    <?php endforeach; ?>
  </section>
</main>
</body>
</html>

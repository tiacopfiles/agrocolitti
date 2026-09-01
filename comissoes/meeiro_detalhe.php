<?php
require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../auth/proteger.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../config/layout_helper.php';
require_once __DIR__ . '/meeiros_helper_v2.php';

requirePermission(PERM_ADMIN, '../index.php');
comissoesMeeirosGarantirEstrutura($conexao);

$meeiroId = (int) ($_GET['meeiro_id'] ?? 0);
$meeiroNome = $meeiroId > 0 ? comissoesMeeirosNomePorId($conexao, $meeiroId) : '';
if ($meeiroId <= 0 || $meeiroNome === '') {
    header('Location: meeiros.php');
    exit;
}

$stmt = $conexao->prepare("
    SELECT cm.*, p.nome AS produto_nome
    FROM comissoes_meeiros cm
    JOIN produtos p ON p.id = cm.produto_id
    WHERE cm.meeiro_id = ?
    ORDER BY cm.data_confirmacao DESC, cm.id DESC
");
$stmt->bind_param('i', $meeiroId);
$stmt->execute();
$pedidos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$subtotalPeso = array_sum(array_map(static fn(array $item): float => (float) ($item['peso_entrada_kg'] ?? 0), $pedidos));
$subtotalDescarte = array_sum(array_map(static fn(array $item): float => (float) ($item['descarte_kg'] ?? 0), $pedidos));
$subtotalPreco = array_sum(array_map(static fn(array $item): float => (float) ($item['preco_final'] ?? 0), $pedidos));
$subtotalComissao = array_sum(array_map(static fn(array $item): float => (float) ($item['valor_comissao'] ?? 0), $pedidos));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Meeiro - <?= htmlspecialchars($meeiroNome) ?></title>
<style>
body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;color:#1f2937}.container{max-width:1320px;margin:auto;padding:30px}.page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:20px}.page-head h1{margin:0;color:#1b5e20;font-size:26px}.page-head p{margin:6px 0 0;color:#64748b}.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 14px;border-radius:7px;background:#e8f5e9;color:#1b5e20;text-decoration:none;font-weight:700}.card{background:#fff;border-radius:10px;padding:20px;box-shadow:0 5px 16px rgba(15,23,42,.06)}.table-wrap{overflow-x:auto}table{width:100%;min-width:980px;border-collapse:collapse}th{background:#1b5e20;color:#fff;padding:12px;text-align:left;font-size:13px}td{padding:12px;border-bottom:1px solid #e5e7eb}tr:hover td{background:#f7faf8}.os{font-weight:800;color:#166534}.money{font-weight:800;white-space:nowrap}.muted{color:#64748b}.empty{text-align:center;padding:36px;color:#64748b}tfoot td{background:#f0f7f1;border-top:2px solid #1b5e20;border-bottom:0}.subtotal-label{text-align:right;font-weight:800;color:#1b5e20;text-transform:uppercase}@media(max-width:850px){.container{padding:18px}.page-head{display:block}.btn{margin-top:14px}}
</style>
<?php renderAppLayoutStyles(); ?>
</head>
<body>
<?php renderAppHeader('..'); ?>
<main class="container page-container app-shell">
  <div class="page-head">
    <div><h1><?= htmlspecialchars($meeiroNome, ENT_QUOTES, 'UTF-8') ?></h1><p>Colheitas confirmadas vinculadas a este meeiro.</p></div>
    <a class="btn" href="meeiros.php"><i class="bi bi-arrow-left"></i> Voltar aos meeiros</a>
  </div>
  <section class="card table-wrap">
    <table>
      <thead>
        <tr>
          <th>Data</th>
          <th>Numero do pedido</th>
          <th>Qualidade</th>
          <th>Produto</th>
          <th>Peso</th>
          <th>Descarte</th>
          <th>Preco/kg</th>
          <th>Preco final</th>
          <th>Comissao 30%</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$pedidos): ?>
        <tr><td class="empty" colspan="9">Nenhuma colheita confirmada para este meeiro.</td></tr>
      <?php else: foreach ($pedidos as $item): ?>
        <tr>
          <td><?= comissoesMeeirosData($item['data_confirmacao'] ?? null) ?></td>
          <td class="os"><?= htmlspecialchars((string) $item['numero_pedido'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars(trim((string) ($item['qualidade_comercial'] ?? '')) ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string) $item['produto_nome'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= number_format((float) $item['peso_entrada_kg'], 2, ',', '.') ?> kg</td>
          <td><?= number_format((float) $item['descarte_kg'], 2, ',', '.') ?> kg</td>
          <td class="money">R$ <?= number_format((float) $item['valor_por_kg_snapshot'], 2, ',', '.') ?></td>
          <td class="money">R$ <?= number_format((float) $item['preco_final'], 2, ',', '.') ?></td>
          <td class="money">R$ <?= number_format((float) $item['valor_comissao'], 2, ',', '.') ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <td class="subtotal-label" colspan="4">Subtotal</td>
          <td><?= number_format($subtotalPeso, 2, ',', '.') ?> kg</td>
          <td><?= number_format($subtotalDescarte, 2, ',', '.') ?> kg</td>
          <td></td>
          <td class="money">R$ <?= number_format($subtotalPreco, 2, ',', '.') ?></td>
          <td class="money">R$ <?= number_format($subtotalComissao, 2, ',', '.') ?></td>
        </tr>
      </tfoot>
    </table>
  </section>
</main>
</body>
</html>

<?php
require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../auth/proteger.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../config/layout_helper.php';
require_once __DIR__ . '/vendedores_helper.php';

requirePermission(PERM_ADMIN, '../index.php');
comissoesVendedoresGarantirEstrutura($conexao);

$vendedor = comissoesVendedoresNormalizarNome((string) ($_GET['vendedor'] ?? ''));
if ($vendedor === null) {
    header('Location: vendedores.php');
    exit;
}

$stmt = $conexao->prepare("
    SELECT cv.id, cv.numero_os, cv.venda_id, cv.contabilizado_em,
           cv.percentual, cv.valor_base, cv.valor_comissao,
           n.numero_nfe, n.emitida_em, n.autorizada_em,
           (
               SELECT v.status
               FROM vendas v
               WHERE CONVERT(v.numero_os USING utf8mb4) COLLATE utf8mb4_unicode_ci = cv.numero_os COLLATE utf8mb4_unicode_ci
               ORDER BY v.id DESC
               LIMIT 1
           ) AS venda_status
    FROM comissoes_vendedores cv
    INNER JOIN contas_integracoes ci
        ON ci.id = cv.contas_integracao_id
       AND ci.tipo = 'receber'
       AND ci.status = 'enviado'
    INNER JOIN nfe_documentos n
        ON n.id = cv.nfe_documento_id
       AND n.tipo_emissao = 'venda'
       AND n.status = 'autorizada'
    WHERE cv.vendedor_nome = ?
    ORDER BY cv.contabilizado_em DESC, cv.id DESC
");
$stmt->bind_param('s', $vendedor);
$stmt->execute();
$pedidos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$subtotalBase = array_sum(array_map(static fn(array $item): float => (float) ($item['valor_base'] ?? 0), $pedidos));
$subtotalComissao = array_sum(array_map(static fn(array $item): float => (float) ($item['valor_comissao'] ?? 0), $pedidos));

function comissoesVendedoresData(?string $data): string
{
    if (!$data) return '-';
    $ts = strtotime($data);
    return $ts ? date('d/m/Y', $ts) : '-';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vendedor - <?= htmlspecialchars($vendedor) ?></title>
<style>
body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;color:#1f2937}.container{max-width:1320px;margin:auto;padding:30px}.page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:20px}.page-head h1{margin:0;color:#1b5e20;font-size:26px}.page-head p{margin:6px 0 0;color:#64748b}.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 14px;border-radius:7px;background:#e8f5e9;color:#1b5e20;text-decoration:none;font-weight:700}.card{background:#fff;border-radius:10px;padding:20px;box-shadow:0 5px 16px rgba(15,23,42,.06)}.table-wrap{overflow-x:auto}table{width:100%;min-width:980px;border-collapse:collapse}th{background:#1b5e20;color:#fff;padding:12px;text-align:left;font-size:13px}td{padding:12px;border-bottom:1px solid #e5e7eb}tr:hover td{background:#f7faf8}.os{font-weight:800;color:#166534}.money{font-weight:800;white-space:nowrap}.muted{color:#64748b}.status{display:inline-flex;border-radius:999px;padding:5px 10px;background:#f1f5f9;color:#475569;font-size:12px;font-weight:800}.status-concluido{background:#dcfce7;color:#166534}.status-pendente{background:#fef3c7;color:#92400e}.status-anexado{background:#dbeafe;color:#1e40af}.empty{text-align:center;padding:36px;color:#64748b}tfoot td{background:#f0f7f1;border-top:2px solid #1b5e20;border-bottom:0}.subtotal-label{text-align:right;font-weight:800;color:#1b5e20;text-transform:uppercase}@media(max-width:850px){.container{padding:18px}.page-head{display:block}.btn{margin-top:14px}}
</style>
<?php renderAppLayoutStyles(); ?>
</head>
<body>
<?php renderAppHeader('..'); ?>
<main class="container page-container app-shell">
  <div class="page-head">
    <div><h1><?= htmlspecialchars($vendedor, ENT_QUOTES, 'UTF-8') ?></h1><p>Pedidos contabilizados para este vendedor depois do envio ao contas a receber.</p></div>
    <a class="btn" href="vendedores.php"><i class="bi bi-arrow-left"></i> Voltar aos vendedores</a>
  </div>
  <section class="card table-wrap">
    <table>
      <thead><tr><th>Numero da nota</th><th>Data da emissao</th><th>Numero da OS</th><th>Valor base</th><th>Percentual</th><th>Comissao</th><th>Status da venda</th></tr></thead>
      <tbody>
      <?php if (!$pedidos): ?>
        <tr><td class="empty" colspan="7">Nenhum pedido contabilizado para este vendedor.</td></tr>
      <?php else: foreach ($pedidos as $item):
          $status = (string) ($item['venda_status'] ?? '');
          $dataNota = $item['emitida_em'] ?: $item['autorizada_em'];
      ?>
        <tr>
          <td><?= $item['numero_nfe'] !== null && $item['numero_nfe'] !== '' ? htmlspecialchars($item['numero_nfe']) : '<span class="muted">Aguardando emissao</span>' ?></td>
          <td><?= comissoesVendedoresData($dataNota) ?></td>
          <td class="os"><?= htmlspecialchars($item['numero_os']) ?></td>
          <td class="money">R$ <?= number_format((float) $item['valor_base'], 2, ',', '.') ?></td>
          <td><?= number_format((float) $item['percentual'], 2, ',', '.') ?>%</td>
          <td class="money">R$ <?= number_format((float) $item['valor_comissao'], 2, ',', '.') ?></td>
          <td><span class="status status-<?= htmlspecialchars($status) ?>"><?= htmlspecialchars(comissoesVendedoresStatusLabel($status)) ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <td class="subtotal-label" colspan="3">Subtotal</td>
          <td class="money">R$ <?= number_format($subtotalBase, 2, ',', '.') ?></td>
          <td></td>
          <td class="money">R$ <?= number_format($subtotalComissao, 2, ',', '.') ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </section>
</main>
</body>
</html>

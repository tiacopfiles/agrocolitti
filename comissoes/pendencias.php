<?php
require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../auth/proteger.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../config/layout_helper.php';
require_once __DIR__ . '/vendedores_helper.php';

requireModule('contas', '../index.php');
comissoesVendedoresGarantirEstrutura($conexao);

// Lista as NF-es JA enviadas ao contas a receber cuja comissao de vendedor
// ficou pendente (ambigua / json invalido / erro tecnico) e que ainda NAO
// possuem comissao registrada. Fonte: eventos gravados no proprio envio.
// Somente leitura; nao altera nada do fluxo financeiro.
$sql = "
    SELECT ci.id            AS integracao_id,
           ci.origem_id     AS nfe_documento_id,
           ci.origem_ref    AS numero_os,
           ci.ndocumento    AS ndocumento,
           ci.fornecedor_nome AS cliente,
           ci.valortotal    AS valor_base,
           ev.evento        AS evento,
           ev.detalhe       AS detalhe,
           ev.created_at    AS ocorrido_em
    FROM contas_integracoes ci
    INNER JOIN (
        SELECT integracao_id, MAX(id) AS ev_id
        FROM contas_integracao_eventos
        WHERE evento IN ('comissao_vendedor_inconsistente', 'comissao_vendedor_erro')
        GROUP BY integracao_id
    ) ult ON ult.integracao_id = ci.id
    INNER JOIN contas_integracao_eventos ev ON ev.id = ult.ev_id
    LEFT JOIN comissoes_vendedores cv ON cv.nfe_documento_id = ci.origem_id
    WHERE ci.tipo = 'receber'
      AND ci.status = 'enviado'
      AND cv.id IS NULL
    ORDER BY ev.created_at DESC
";
$pendencias = [];
if ($res = $conexao->query($sql)) {
    while ($row = $res->fetch_assoc()) $pendencias[] = $row;
    $res->free();
}
$csrf = gerarTokenCsrf();

function pendenciaMotivo(string $evento, string $detalhe): string
{
    $d = mb_strtolower($detalhe, 'UTF-8');
    if (strpos($d, 'ambiguo') !== false)      return 'Vendedor ambíguo (conflito entre linhas/snapshots da OS)';
    if (strpos($d, 'json_invalido') !== false) return 'Snapshot com JSON inválido';
    if ($evento === 'comissao_vendedor_erro') return 'Erro técnico ao registrar a comissão';
    return 'Comissão não gerada';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pendências de comissão</title>
<style>
body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;color:#1f2937}
.container{max-width:1220px;margin:auto;padding:30px}
.page-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:20px}
.page-head h1{margin:0;color:#8a4b00;font-size:27px}.page-head p{margin:7px 0 0;color:#64748b}
.summary{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;min-width:170px;box-shadow:0 4px 14px rgba(15,23,42,.06)}
.summary span{display:block;color:#64748b;font-size:12px;font-weight:700;text-transform:uppercase}.summary strong{font-size:25px;color:#b45309}
table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden}
th,td{padding:11px 13px;text-align:left;font-size:14px;border-bottom:1px solid #eef2f7}
th{background:#fafafa;color:#475569;font-size:12px;text-transform:uppercase;letter-spacing:.02em}
tr:last-child td{border-bottom:none}
.motivo{color:#b45309;font-weight:600}
.btn{border:1px solid #16a34a;background:#16a34a;color:#fff;border-radius:7px;padding:7px 13px;font-weight:600;cursor:pointer;font-size:13px}
.btn:disabled{opacity:.5;cursor:default}
.empty{background:#fff;border:1px dashed #cbd5e1;border-radius:10px;padding:40px;text-align:center;color:#64748b}
.msg{margin-top:4px;font-size:12px}
.msg.ok{color:#166534}.msg.err{color:#b91c1c}
</style>
<?php renderAppLayoutStyles(); ?>
</head>
<body>
<?php renderAppHeader('..'); ?>
<main class="container page-container app-shell">
  <div class="page-head">
    <div>
      <h1>Pendências de comissão</h1>
      <p>NF-es enviadas ao contas a receber cuja comissão do vendedor não pôde ser gerada. O financeiro já foi enviado; aqui você resolve só a comissão.</p>
    </div>
    <div class="summary"><span>Pendentes</span><strong><?= count($pendencias) ?></strong></div>
  </div>

  <?php if (!$pendencias): ?>
    <div class="empty">Nenhuma pendência de comissão. Tudo em dia.</div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>OS</th><th>NF-e</th><th>Cliente</th><th>Valor base</th><th>Motivo</th><th>Ocorrido em</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pendencias as $p): ?>
          <tr data-integracao="<?= (int) $p['integracao_id'] ?>">
            <td><?= htmlspecialchars((string) $p['numero_os'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) $p['ndocumento'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) $p['cliente'], ENT_QUOTES, 'UTF-8') ?></td>
            <td>R$ <?= number_format((float) $p['valor_base'], 2, ',', '.') ?></td>
            <td class="motivo"><?= htmlspecialchars(pendenciaMotivo((string) $p['evento'], (string) $p['detalhe']), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $p['ocorrido_em'])), ENT_QUOTES, 'UTF-8') ?></td>
            <td>
              <button class="btn btn-reprocessar" type="button">Reprocessar</button>
              <div class="msg"></div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</main>

<script>
(function(){
  const csrf = <?= json_encode($csrf) ?>;
  document.querySelectorAll('.btn-reprocessar').forEach(btn => {
    btn.addEventListener('click', async function(){
      const tr = this.closest('tr');
      const integracaoId = Number(tr.dataset.integracao || 0);
      const msg = tr.querySelector('.msg');
      if (!integracaoId) return;
      this.disabled = true;
      msg.className = 'msg';
      msg.textContent = 'Reprocessando...';
      try {
        const resp = await fetch('actions/reprocessar_comissao.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({csrf_token: csrf, integracao_id: integracaoId})
        });
        const data = await resp.json();
        if (data.ok) {
          msg.className = 'msg ok';
          msg.textContent = data.status === 'criada'
            ? ('Comissão criada para ' + (data.vendedor || 'vendedor') + '.')
            : 'Comissão já existente (nada alterado).';
          setTimeout(() => tr.remove(), 1200);
        } else {
          msg.className = 'msg err';
          msg.textContent = data.erro || 'Não foi possível reprocessar.';
          this.disabled = false;
        }
      } catch (err) {
        msg.className = 'msg err';
        msg.textContent = 'Erro de comunicação ao reprocessar.';
        this.disabled = false;
      }
    });
  });
})();
</script>
</body>
</html>

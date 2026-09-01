<?php
require __DIR__ . '/../config/conexao.php';
require __DIR__ . '/../auth/proteger.php';
require __DIR__ . '/../config/permissions.php';

requireModule('historico_vendas', '../vendas/historico_vendas.php');

function fmtCpfCnpj(string $v): string
{
    $d = preg_replace('/[^0-9]/', '', $v);
    if (strlen($d) === 11) return substr($d,0,3).'.'.substr($d,3,3).'.'.substr($d,6,3).'-'.substr($d,9,2);
    if (strlen($d) === 14) return substr($d,0,2).'.'.substr($d,2,3).'.'.substr($d,5,3).'/'.substr($d,8,4).'-'.substr($d,12,2);
    return $v;
}
function fmtCep(string $v): string
{
    $d = preg_replace('/[^0-9]/', '', $v);
    if (strlen($d) === 8) return substr($d,0,5).'-'.substr($d,5,3);
    return $v;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header('Location: ../vendas/historico_vendas.php');
    exit;
}

$stmt = $conexao->prepare('SELECT * FROM boletos WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$boleto = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$boleto) {
    http_response_code(404);
    exit('Boleto nao encontrado.');
}

$linha   = (string) ($boleto['linha_digitavel'] ?? '');
$barras  = (string) ($boleto['codigo_barras'] ?? '');
$pix     = (string) ($boleto['pix_copia_cola'] ?? '');
$pdfRel  = (string) ($boleto['pdf_path'] ?? '');
$valor   = number_format((float) ($boleto['valor'] ?? 0), 2, ',', '.');
$venc    = !empty($boleto['data_vencimento']) ? date('d/m/Y', strtotime($boleto['data_vencimento'])) : '-';
$os      = (string) ($boleto['numero_os'] ?? '-');
$pdfHref = $pdfRel !== '' ? 'pdf_boleto.php?id=' . (int) $boleto['id'] : '';
$baixar  = isset($_GET['baixar']) && $_GET['baixar'] === '1';

$situacao    = (string) ($boleto['situacao'] ?? '');
$nossoNumero = (string) ($boleto['nosso_numero'] ?? '');
// So da para cancelar um boleto registrado de fato no Sicoob (tem nosso numero).
$podeCancelar = in_array($situacao, ['registrado', 'emitido'], true) && trim($nossoNumero) !== '';
$podeExcluir = in_array($situacao, ['erro', 'rascunho', 'baixado', 'cancelado'], true) && !(trim($nossoNumero) !== '' && !in_array($situacao, ['baixado', 'cancelado'], true));
$msg     = (string) ($_GET['msg'] ?? '');
$detalhe = (string) ($_GET['detalhe'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Boleto SICOOB — OS <?= htmlspecialchars($os) ?></title>
  <link rel="stylesheet" href="../assets/css/global.css">
  <style>
    .boleto-card{max-width:760px;margin:30px auto;background:#fff;border-radius:10px;box-shadow:0 4px 14px rgba(0,0,0,.08);padding:28px;font-family:Arial,sans-serif;}
    .boleto-card h2{color:#1b5e20;margin:0 0 4px;}
    .boleto-meta{color:#555;margin-bottom:20px;}
    .campo{margin-bottom:18px;}
    .campo label{display:block;font-weight:bold;color:#333;margin-bottom:6px;}
    .campo .valor{display:flex;gap:8px;align-items:center;}
    .campo input{flex:1;padding:10px;border:1px solid #ccc;border-radius:6px;font-family:monospace;font-size:14px;background:#f9f9f9;}
    .btn-copiar{padding:10px 14px;border:none;border-radius:6px;background:#1b5e20;color:#fff;cursor:pointer;}
    .btn-pdf{display:inline-block;margin-top:10px;padding:12px 18px;background:#0d47a1;color:#fff;border-radius:6px;text-decoration:none;}
    .voltar{display:inline-block;margin-top:18px;color:#1b5e20;font-weight:bold;}
    .vazio{color:#999;font-style:italic;}
    .btn-cancelar{display:inline-block;margin-top:10px;margin-left:8px;padding:12px 18px;background:#b02a37;color:#fff;border:none;border-radius:6px;text-decoration:none;cursor:pointer;font-size:14px;}
    .aviso{padding:12px 14px;border-radius:8px;margin-bottom:16px;font-size:14px;}
    .aviso-ok{background:#d1e7dd;color:#0f5132;}
    .aviso-erro{background:#f8d7da;color:#721c24;}
    .baixado-tag{background:#f8d7da;color:#721c24;}
  </style>
</head>
<body>
  <div class="boleto-card">
    <h2><i class="bi bi-upc-scan"></i> Boleto SICOOB</h2>

    <?php if ($msg === 'cancelado'): ?>
      <div class="aviso aviso-ok"><i class="bi bi-check-circle"></i> Boleto cancelado (baixado) no Sicoob com sucesso.</div>
    <?php elseif ($msg === 'erro_cancelar'): ?>
      <div class="aviso aviso-erro"><i class="bi bi-exclamation-triangle"></i> Nao foi possivel cancelar o boleto: <?= htmlspecialchars($detalhe ?: 'erro desconhecido') ?></div>
    <?php elseif ($msg === 'erro_excluir'): ?>
      <div class="aviso aviso-erro"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($detalhe ?: 'Nao foi possivel excluir o registro.') ?></div>
    <?php endif; ?>

    <p class="boleto-meta">OS <strong><?= htmlspecialchars($os) ?></strong> &middot; Valor <strong>R$ <?= $valor ?></strong> &middot; Vencimento <strong><?= $venc ?></strong> &middot; Situacao <strong><?= htmlspecialchars((string) $boleto['situacao']) ?></strong></p>

    <div class="campo">
      <label>Linha digitavel</label>
      <div class="valor">
        <?php if ($linha !== ''): ?>
          <input id="f_linha" value="<?= htmlspecialchars($linha) ?>" readonly>
          <button class="btn-copiar" type="button" onclick="copiar('f_linha')">Copiar</button>
        <?php else: ?><span class="vazio">indisponivel</span><?php endif; ?>
      </div>
    </div>

    <div class="campo">
      <label>Codigo de barras</label>
      <div class="valor">
        <?php if ($barras !== ''): ?>
          <input id="f_barras" value="<?= htmlspecialchars($barras) ?>" readonly>
          <button class="btn-copiar" type="button" onclick="copiar('f_barras')">Copiar</button>
        <?php else: ?><span class="vazio">indisponivel</span><?php endif; ?>
      </div>
    </div>

    <div class="campo">
      <label>PIX copia e cola</label>
      <div class="valor">
        <?php if ($pix !== ''): ?>
          <input id="f_pix" value="<?= htmlspecialchars($pix) ?>" readonly>
          <button class="btn-copiar" type="button" onclick="copiar('f_pix')">Copiar</button>
        <?php else: ?><span class="vazio">indisponivel</span><?php endif; ?>
      </div>
    </div>

    <?php if ($pdfHref !== ''): ?>
      <a id="pdfLink" class="btn-pdf" href="<?= htmlspecialchars($pdfHref) ?>" download><i class="bi bi-file-earmark-pdf"></i> Baixar PDF do boleto</a>
    <?php else: ?>
      <p class="vazio">PDF nao disponivel<?= $baixar ? ' — no sandbox o Sicoob nao retorna PDF (so em producao).' : '.' ?></p>
    <?php endif; ?>

    <a class="btn-pdf" style="background:#00735e;margin-left:8px;" href="../vendas/actions/consultar_boleto_sicoob.php?id=<?= (int) $boleto['id'] ?>"><i class="bi bi-search"></i> Consultar no Sicoob (GET)</a>

    <?php if ($podeCancelar): ?>
      <form action="../vendas/actions/cancelar_boleto_sicoob.php" method="post" style="display:inline;"
            onsubmit="return confirm('Cancelar (baixar) este boleto no Sicoob? Esta acao nao pode ser desfeita.');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
        <input type="hidden" name="id" value="<?= (int) $boleto['id'] ?>">
        <button type="submit" class="btn-cancelar"><i class="bi bi-x-octagon"></i> Cancelar boleto</button>
      </form>
    <?php elseif ($situacao === 'baixado'): ?>
      <span class="badge baixado-tag" style="display:inline-block;margin-left:8px;padding:8px 14px;border-radius:12px;font-weight:700;">Boleto cancelado (baixado)</span>
    <?php endif; ?>

    <?php if ($podeExcluir): ?>
      <form action="../vendas/actions/excluir_boleto_sicoob.php" method="post" style="display:inline;"
            onsubmit="return confirm('Excluir definitivamente este registro de boleto do sistema? Esta acao nao afeta o Sicoob.');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
        <input type="hidden" name="id" value="<?= (int) $boleto['id'] ?>">
        <button type="submit" class="btn-cancelar"><i class="bi bi-trash"></i> Excluir registro</button>
      </form>
    <?php endif; ?>

    <?php if (in_array($situacao, ['erro', 'rascunho'], true) && trim((string) ($boleto['numero_os'] ?? '')) !== ''): ?>
      <a class="btn-pdf" style="background:#e65100;margin-left:8px;" href="rascunho_boleto.php?numero_os=<?= urlencode((string) $boleto['numero_os']) ?>"><i class="bi bi-arrow-clockwise"></i> Tentar novamente</a>
    <?php endif; ?>

    <div><a class="voltar" href="historico.php">&larr; Voltar ao historico de boletos</a></div>
  </div>

  <script>
    function copiar(id){
      var el = document.getElementById(id);
      el.select(); el.setSelectionRange(0, 99999);
      navigator.clipboard.writeText(el.value).then(function(){
        alert('Copiado!');
      }, function(){ document.execCommand('copy'); });
    }
    <?php if ($baixar && $pdfHref !== ''): ?>
    // Download automatico do PDF apos emitir.
    window.addEventListener('load', function(){ var l = document.getElementById('pdfLink'); if (l) { l.click(); } });
    <?php endif; ?>
  </script>
</body>
</html>

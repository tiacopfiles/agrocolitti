<?php
/**
 * Consulta um boleto no SICOOB via GET (verificacao).
 * Mostra o http_code e o JSON de retorno — util para conferir se o boleto
 * foi registrado de fato no Sicoob apos a inclusao.
 */
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/permissions.php';
require __DIR__ . '/../../boletos/sicoob_boleto_service.php';

requireModule('exportar_nfe', '../historico_vendas.php');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$erro = null;
$res = null;
$boleto = null;

try {
    if ($id <= 0) {
        throw new RuntimeException('Boleto nao informado.');
    }
    $stmt = $conexao->prepare('SELECT * FROM boletos WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $boleto = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$boleto) {
        throw new RuntimeException('Boleto nao encontrado.');
    }
    $res = sicoobBoletoConsultar($conexao, $id);
} catch (Throwable $e) {
    $erro = $e->getMessage();
}

function hc($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Consulta do Boleto SICOOB (GET)</title>
  <link rel="stylesheet" href="../../assets/css/global.css">
  <style>
    .c-wrap{max-width:820px;margin:28px auto;font-family:Arial,sans-serif;}
    .c-card{background:#fff;border-radius:10px;box-shadow:0 4px 14px rgba(0,0,0,.08);padding:26px;}
    .c-card h2{color:#00735e;margin:0 0 14px;}
    .row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px dashed #eee;font-size:14px;}
    .row .lbl{color:#777;}
    pre{background:#0f172a;color:#e2e8f0;padding:14px;border-radius:8px;overflow:auto;font-size:12px;max-height:360px;}
    .badge{display:inline-block;padding:2px 10px;border-radius:12px;font-weight:700;font-size:13px;}
    .ok{background:#d1e7dd;color:#0f5132;} .bad{background:#f8d7da;color:#721c24;}
    .voltar{display:inline-block;margin-top:16px;color:#00735e;font-weight:700;}
    .erro-box{background:#f8d7da;color:#721c24;border-radius:8px;padding:14px;}
  </style>
</head>
<body>
<div class="c-wrap"><div class="c-card">
  <h2><i class="bi bi-search"></i> Consulta do Boleto no SICOOB (GET)</h2>
<?php if ($erro !== null): ?>
  <div class="erro-box"><?= hc($erro) ?></div>
<?php else:
  $code = (int) $res['http_code'];
  $okClass = ($code >= 200 && $code < 300) ? 'ok' : 'bad';
?>
  <div class="row"><span class="lbl">Boleto (local)</span><span>#<?= (int) $boleto['id'] ?> &middot; OS <?= hc($boleto['numero_os']) ?> &middot; seu_numero <?= hc($boleto['seu_numero']) ?></span></div>
  <div class="row"><span class="lbl">Situacao local</span><span><?= hc($boleto['situacao']) ?></span></div>
  <div class="row"><span class="lbl">Nosso numero</span><span><?= hc($boleto['nosso_numero'] ?: '—') ?></span></div>
  <div class="row"><span class="lbl">HTTP da consulta</span><span class="badge <?= $okClass ?>"><?= $code ?></span></div>
  <?php if (!empty($res['error'])): ?><div class="row"><span class="lbl">Erro de conexao</span><span><?= hc($res['error']) ?></span></div><?php endif; ?>
  <p style="margin:14px 0 6px;font-weight:700;">Retorno do Sicoob:</p>
  <pre><?= hc($res['raw'] !== '' ? $res['raw'] : '(vazio)') ?></pre>
  <p style="color:#666;font-size:13px;">No <strong>sandbox</strong> o retorno e simulado. Em <strong>producao</strong>, um HTTP 200 com os dados do boleto confirma o registro.</p>
<?php endif; ?>
  <div><a class="voltar" href="../../vendas/historico_vendas.php">&larr; Voltar ao historico de vendas</a></div>
</div></div>
</body>
</html>

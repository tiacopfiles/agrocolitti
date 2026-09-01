<?php
/**
 * Rascunho/Revisao do boleto SICOOB — TODOS os campos editaveis antes de emitir.
 * O usuario revisa/ajusta e confirma. So gera o boleto ao clicar em "Confirmar e gerar".
 */
require __DIR__ . '/../config/conexao.php';
require __DIR__ . '/../auth/proteger.php';
require __DIR__ . '/../config/permissions.php';
require __DIR__ . '/../boletos/sicoob_boleto_service.php';

requireModule('exportar_nfe', '../vendas/historico_vendas.php');

$numeroOs = isset($_GET['numero_os']) && trim((string) $_GET['numero_os']) !== '' ? trim((string) $_GET['numero_os']) : null;
$vendaId  = isset($_GET['venda_id']) && $_GET['venda_id'] !== '' ? (int) $_GET['venda_id'] : null;

$erro = null; $dados = null; $config = null;
try {
    if ($numeroOs === null) { throw new RuntimeException('OS nao informada.'); }
    $dados = sicoobBoletoPrepararDados($conexao, $numeroOs, $vendaId);
    $config = $dados['config'];
} catch (Throwable $e) {
    $erro = $e->getMessage();
}

function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function num($v) { return str_replace('.', ',', (string) (float) $v); }
$ambiente = $config['ambiente'] ?? (function_exists('sicoobEnv') ? sicoobEnv('SICOOB_AMBIENTE', 'sandbox') : 'sandbox');
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Rascunho do Boleto SICOOB<?= $numeroOs ? ' — OS ' . h($numeroOs) : '' ?></title>
  <link rel="stylesheet" href="../assets/css/bootstrap-icons.min.css">
  <link rel="stylesheet" href="../assets/css/global.css">
  <style>
    .rasc-wrap{max-width:860px;margin:24px auto;font-family:Arial,sans-serif;}
    .rasc-card{background:#fff;border-radius:10px;box-shadow:0 4px 14px rgba(0,0,0,.08);padding:26px;}
    .rasc-card h2{color:#00735e;margin:0 0 2px;display:flex;align-items:center;gap:8px;}
    .rasc-sub{color:#666;margin:0 0 16px;font-size:14px;}
    .amb{display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:700;margin-left:8px;}
    .amb.sandbox{background:#fff3cd;color:#856404;} .amb.producao{background:#f8d7da;color:#721c24;}
    .grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px 22px;margin-bottom:14px;}
    .blk{border:1px solid #e8e8e8;border-radius:8px;padding:14px;}
    .blk h3{margin:0 0 10px;font-size:12px;text-transform:uppercase;color:#00735e;letter-spacing:.5px;}
    label.f{display:block;font-size:12px;color:#666;margin-bottom:8px;}
    label.f span{display:block;margin-bottom:3px;}
    .inp{width:100%;height:40px;padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:14px;line-height:1.2;box-sizing:border-box;background:#fff;}
    select.inp{appearance:auto;}
    .ro{background:#f5f5f5;color:#555;}
    .two{display:grid;grid-template-columns:1fr 1fr;gap:10px;align-items:end;}
    .msgs{margin:0;padding-left:18px;color:#444;font-size:13px;}
    .rasc-acoes{display:flex;gap:14px;align-items:center;margin-top:18px;flex-wrap:wrap;}
    .btn-emitir{display:inline-flex!important;align-items:center;gap:8px;white-space:nowrap;width:auto!important;background:#00735e!important;color:#fff!important;border:none!important;border-radius:8px!important;padding:0 26px!important;min-height:46px!important;font-size:15px!important;font-weight:700!important;cursor:pointer;}
    .btn-emitir:hover{background:#005a49!important;}
    .btn-emitir i{font-size:18px;}
    .btn-cancelar2{color:#666;text-decoration:none;font-weight:600;padding:0 6px;}
    .btn-cancelar{color:#666;text-decoration:none;font-weight:600;}
    .aviso{background:#fff3cd;color:#856404;border:1px solid #ffe69c;border-radius:8px;padding:11px 14px;font-size:13px;margin-bottom:14px;}
    .erro-box{background:#f8d7da;color:#721c24;border:1px solid #f5c2c7;border-radius:8px;padding:16px;}
    .hint{font-size:12px;color:#999;margin-top:4px;}
    .destaque{font-size:18px;font-weight:700;color:#00735e;}
    .boleto-overlay{display:none;position:fixed;inset:0;background:rgba(255,255,255,.88);z-index:9999;align-items:center;justify-content:center;flex-direction:column;gap:14px;}
    .boleto-overlay .spin{width:48px;height:48px;border:5px solid #cfe8e1;border-top-color:#00735e;border-radius:50%;animation:bolspin .8s linear infinite;}
    .boleto-overlay p{color:#00735e;font-weight:700;font-size:16px;margin:0;}
    .boleto-overlay .sub{font-weight:400;color:#666;font-size:13px;}
    @keyframes bolspin{to{transform:rotate(360deg);}}
  </style>
</head>
<body>
<div id="boletoOverlay" class="boleto-overlay"><div class="spin"></div><p>Gerando boleto no Sicoob, aguarde...</p><p class="sub">Isso pode levar alguns segundos. Nao feche nem volte a pagina.</p></div>
<div class="rasc-wrap">
<?php if ($erro !== null): ?>
  <div class="rasc-card">
    <h2><i class="bi bi-exclamation-triangle"></i> Nao foi possivel preparar o boleto</h2>
    <div class="erro-box"><?= h($erro) ?></div>
    <p style="margin-top:16px;"><a class="btn-cancelar" href="../vendas/historico_vendas.php">&larr; Voltar ao historico de vendas</a></p>
  </div>
<?php else:
    $pag = $dados['pagador'];
?>
  <div class="rasc-card">
    <h2><i class="bi bi-upc-scan"></i> Rascunho do Boleto SICOOB</h2>
    <?php if (($_GET['msg'] ?? '') === 'erro'): ?>
      <div style="background:#f8d7da;color:#721c24;padding:12px 14px;border-radius:8px;margin:10px 0;font-size:14px;">
        <strong><i class="bi bi-exclamation-triangle"></i> A emissao falhou:</strong> <?= htmlspecialchars((string) ($_GET['detalhe'] ?? 'erro desconhecido')) ?><br>
        Revise os campos abaixo e clique no botao de emitir para <strong>tentar novamente</strong>.
      </div>
    <?php endif; ?>
    <p class="rasc-sub">Todos os campos abaixo sao editaveis. Revise/ajuste e clique em <strong>Confirmar e gerar</strong>.</p>

    <form method="POST" action="../vendas/actions/gerar_boleto_sicoob.php" onsubmit="return aoEmitirBoleto();">
      <input type="hidden" name="csrf_token" value="<?= h(gerarTokenCsrf()) ?>">
      <input type="hidden" name="numero_os" value="<?= h($dados['numero_os']) ?>">
      <input type="hidden" name="venda_id" value="<?= (int) $dados['venda_id'] ?>">

      <div class="grid2">
        <div class="blk">
          <h3>Beneficiario (AGRO COLITTI LTDA)</h3>
          <div class="two">
            <label class="f"><span>Cooperativa</span><input class="inp ro" value="<?= h($config['cooperativa']) ?>" readonly></label>
            <label class="f"><span>Cod. beneficiario</span><input class="inp ro" value="<?= h($config['cooperativa']) ?>/<?= h($config['codigo_beneficiario']) ?>" readonly></label>
            <label class="f"><span>Conta cobranca</span><input class="inp" type="text" name="numero_conta" value="<?= h($config['numero_conta']) ?>"></label>
            <label class="f"><span>Nº contrato (numeroCliente)</span><input class="inp" type="text" name="numero_cliente" value="<?= h($config['numero_cliente']) ?>"></label>
          </div>
          <label class="f"><span>Modalidade</span><input class="inp ro" value="<?= h($config['codigo_modalidade']) ?> - Simples com registro" readonly></label>
        </div>

        <div class="blk">
          <h3>Pagador (cliente)</h3>
          <label class="f"><span>Nome / Razao social</span><input class="inp" type="text" name="pag_nome" value="<?= h($pag['nome']) ?>"></label>
          <div class="two">
            <label class="f"><span>CPF/CNPJ</span><input class="inp" type="text" name="pag_cpf_cnpj" value="<?= h($pag['numeroCpfCnpj']) ?>"></label>
            <label class="f"><span>CEP</span><input class="inp" type="text" name="pag_cep" value="<?= h($pag['cep']) ?>"></label>
          </div>
          <label class="f"><span>Endereco</span><input class="inp" type="text" name="pag_endereco" value="<?= h($pag['endereco']) ?>"></label>
          <div class="two">
            <label class="f"><span>Bairro</span><input class="inp" type="text" name="pag_bairro" value="<?= h($pag['bairro']) ?>"></label>
            <label class="f"><span>Cidade</span><input class="inp" type="text" name="pag_cidade" value="<?= h($pag['cidade']) ?>"></label>
          </div>
          <label class="f"><span>UF</span><input class="inp" type="text" name="pag_uf" maxlength="2" value="<?= h($pag['uf']) ?>" style="max-width:80px;"></label>
        </div>
      </div>

      <div class="grid2">
        <div class="blk">
          <h3>Documento</h3>
          <div class="two">
            <label class="f"><span>OS</span><input class="inp ro" value="<?= h($dados['numero_os']) ?>" readonly></label>
            <label class="f"><span>Especie</span>
              <select class="inp" name="codigo_especie">
                <?php foreach (['DM' => 'DM - Duplicata Mercantil', 'DS' => 'DS - Duplicata de Servico', 'NP' => 'NP - Nota Promissoria', 'RC' => 'RC - Recibo', 'OU' => 'OU - Outros'] as $sg => $lbl): ?>
                  <option value="<?= $sg ?>"<?= $sg === 'DM' ? ' selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <div class="two">
            <label class="f"><span>Nº do Documento (= NF-e)</span><input class="inp" type="text" name="seu_numero" value="<?= h($dados['seu_numero']) ?>"></label>
            <label class="f"><span>Data de emissao (da nota)</span><input class="inp" type="date" name="data_emissao" value="<?= h($dados['data_emissao']) ?>"></label>
          </div>
          <label class="f"><span>Nosso Numero (vazio = Sicoob gera)</span><input class="inp" type="text" name="nosso_numero" value="" placeholder="ex.: 2386-0, 2386-1... (gerado pelo Sicoob)"></label>
          <p class="hint">O "Nosso Numero" e a sequencia da sua carteira no Sicoob — normalmente gerado automaticamente (volta na resposta). Preencha so para forcar um numero da sua faixa.</p>
        </div>

        <div class="blk">
          <h3>Valores e vencimento</h3>
          <div class="two">
            <label class="f"><span>Valor (R$)</span><input class="inp" type="text" name="valor" id="valor_boleto" inputmode="decimal" value="<?= h(number_format($dados['valor'], 2, ',', '.')) ?>"></label>
            <label class="f"><span>Vencimento (emissao + <?= (int) $dados['dias_vencimento'] ?>d)</span><input class="inp" type="date" name="data_vencimento" value="<?= h($dados['data_vencimento']) ?>"></label>
          </div>
          <div class="two">
            <label class="f"><span>Desconto / Abatimento (R$)</span><input class="inp" type="text" name="valor_abatimento" id="valor_abatimento" inputmode="decimal" value="" placeholder="0,00" autocomplete="off"></label>
            <label class="f"><span>Desconto / Abatimento (%)</span><input class="inp" type="text" name="porcentagem_abatimento" id="porcentagem_abatimento" inputmode="decimal" value="" placeholder="0,00" autocomplete="off"></label>
          </div>
          <p class="hint">Preencha o valor ou a porcentagem; o outro campo e calculado automaticamente. O abatimento sera enviado ao campo proprio do boleto.</p>
          <div class="two">
            <label class="f"><span>Tipo de juros</span>
              <select class="inp" name="juros_tipo">
                <option value="0"<?= (int)$config['juros_tipo']===0?' selected':'' ?>>Isento</option>
                <option value="1"<?= (int)$config['juros_tipo']===1?' selected':'' ?>>Valor/dia (R$)</option>
                <option value="2"<?= (int)$config['juros_tipo']===2?' selected':'' ?>>Taxa mensal (%)</option>
              </select>
            </label>
            <label class="f"><span>Juros (taxa mensal % ≈ 0,07%/dia)</span><input class="inp" type="text" name="juros_valor" value="<?= h(num($config['juros_valor'])) ?>"></label>
          </div>
          <div class="two">
            <label class="f"><span>Tipo de multa</span>
              <select class="inp" name="multa_tipo">
                <option value="0"<?= (int)$config['multa_tipo']===0?' selected':'' ?>>Isenta</option>
                <option value="1"<?= (int)$config['multa_tipo']===1?' selected':'' ?>>Valor fixo (R$)</option>
                <option value="2"<?= (int)$config['multa_tipo']===2?' selected':'' ?>>Percentual (%)</option>
              </select>
            </label>
            <label class="f"><span>Multa (%)</span><input class="inp" type="text" name="multa_valor" value="<?= h(num($config['multa_valor'])) ?>"></label>
          </div>
        </div>
      </div>

      <div class="blk" style="margin-bottom:14px;">
        <h3>Instrucoes do boleto (editaveis)</h3>
        <?php $msgs = array_values($dados['mensagens']); for ($i = 0; $i < 5; $i++): $mv = isset($msgs[$i]) ? $msgs[$i] : ''; ?>
          <label class="f" style="margin-bottom:8px;"><input class="inp" type="text" name="mensagens[]" value="<?= h($mv) ?>" placeholder="Linha de instrucao <?= $i + 1 ?> (opcional)"></label>
        <?php endfor; ?>
        <p class="hint">Ate 5 linhas que saem impressas no boleto. Pre-preenchidas conforme juros/multa/vencimento — edite a vontade; linhas vazias sao ignoradas.</p>
      </div>

      <div class="rasc-acoes">
        <button id="btnEmitirBoleto" class="btn-emitir" type="submit" onclick="return confirm('Confirmar e gerar o boleto da OS <?= h($dados['numero_os']) ?>?');">
          <i class="bi bi-check2-circle"></i> Confirmar e gerar boleto
        </button>
        <a class="btn-cancelar2" href="../vendas/historico_vendas.php">Cancelar</a>
      </div>
    </form>
  </div>
<?php endif; ?>
</div>
<script>
function boletoNumeroPtBr(valor){
  var texto=String(valor||'').trim();
  if(texto===''){return 0;}
  if(texto.indexOf(',')!==-1){
    texto=texto.replace(/\./g,'').replace(',','.');
  }
  texto=texto.replace(/[^0-9.-]/g,'');
  var numero=Number(texto);
  return Number.isFinite(numero)?numero:NaN;
}

function boletoFormatarPtBr(valor,casas){
  return Number(valor).toLocaleString('pt-BR',{minimumFractionDigits:casas,maximumFractionDigits:casas});
}

var boletoAbatimentoOrigem='valor';

function sincronizarAbatimento(origem){
  var totalEl=document.getElementById('valor_boleto');
  var valorEl=document.getElementById('valor_abatimento');
  var percentualEl=document.getElementById('porcentagem_abatimento');
  if(!totalEl||!valorEl||!percentualEl){return;}

  var total=boletoNumeroPtBr(totalEl.value);
  if(!Number.isFinite(total)||total<=0){return;}

  if(origem==='percentual'){
    if(percentualEl.value.trim()===''){valorEl.value='';return;}
    var percentual=boletoNumeroPtBr(percentualEl.value);
    if(Number.isFinite(percentual)){
      valorEl.value=boletoFormatarPtBr(total*(percentual/100),2);
    }
    return;
  }

  if(valorEl.value.trim()===''){percentualEl.value='';return;}
  var valor=boletoNumeroPtBr(valorEl.value);
  if(Number.isFinite(valor)){
    percentualEl.value=boletoFormatarPtBr((valor/total)*100,2);
  }
}

document.addEventListener('DOMContentLoaded',function(){
  var totalEl=document.getElementById('valor_boleto');
  var valorEl=document.getElementById('valor_abatimento');
  var percentualEl=document.getElementById('porcentagem_abatimento');
  if(totalEl){totalEl.addEventListener('input',function(){
    if(boletoAbatimentoOrigem==='percentual'&&percentualEl&&percentualEl.value.trim()!==''){sincronizarAbatimento('percentual');}
    else if(valorEl&&valorEl.value.trim()!==''){sincronizarAbatimento('valor');}
  });}
  if(valorEl){
    valorEl.addEventListener('input',function(){boletoAbatimentoOrigem='valor';sincronizarAbatimento('valor');});
    valorEl.addEventListener('blur',function(){var n=boletoNumeroPtBr(this.value);if(this.value.trim()!==''&&Number.isFinite(n)){this.value=boletoFormatarPtBr(n,2);}});
  }
  if(percentualEl){
    percentualEl.addEventListener('input',function(){boletoAbatimentoOrigem='percentual';sincronizarAbatimento('percentual');});
    percentualEl.addEventListener('blur',function(){var n=boletoNumeroPtBr(this.value);if(this.value.trim()!==''&&Number.isFinite(n)){this.value=boletoFormatarPtBr(n,2);}});
  }
});

function aoEmitirBoleto(){
  var total=boletoNumeroPtBr(document.getElementById('valor_boleto').value);
  var valorEl=document.getElementById('valor_abatimento');
  var percentualEl=document.getElementById('porcentagem_abatimento');
  var valor=valorEl&&valorEl.value.trim()!==''?boletoNumeroPtBr(valorEl.value):0;
  var percentual=percentualEl&&percentualEl.value.trim()!==''?boletoNumeroPtBr(percentualEl.value):0;
  if(!Number.isFinite(valor)||valor<0){alert('Informe um valor de desconto valido.');return false;}
  if(!Number.isFinite(percentual)||percentual<0||percentual>100){alert('A porcentagem de desconto deve estar entre 0 e 100%.');return false;}
  if(!Number.isFinite(total)||total<=0){alert('O valor do boleto deve ser maior que zero.');return false;}
  if(valor>total){alert('O desconto nao pode ser maior que o valor do boleto.');return false;}
  var o=document.getElementById('boletoOverlay');
  if(o){o.style.display='flex';}
  var b=document.getElementById('btnEmitirBoleto');
  if(b){setTimeout(function(){b.disabled=true;},50);}
  return true;
}
</script>
</body>
</html>

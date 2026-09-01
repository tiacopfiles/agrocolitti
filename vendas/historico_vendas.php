<?php
require "../config/conexao.php";
require "../config/ciclo_helper.php";
require "../auth/proteger.php";
require "../config/layout_helper.php";
require __DIR__ . "/venda_helper.php";

function colunaHistoricoVendaExiste(mysqli $conexao, string $coluna): bool
{
    $coluna = $conexao->real_escape_string($coluna);
    $res = $conexao->query("SHOW COLUMNS FROM vendas LIKE '{$coluna}'");
    $ok = $res && $res->num_rows > 0;
    if ($res instanceof mysqli_result) $res->free();
    return $ok;
}

function garantirColunasHistoricoVenda(mysqli $conexao): void
{
    $cols = [
        'valor_final_original' => "ALTER TABLE vendas ADD COLUMN valor_final_original DECIMAL(12,2) NULL DEFAULT NULL",
        'valor_final_editado' => "ALTER TABLE vendas ADD COLUMN valor_final_editado DECIMAL(12,2) NULL DEFAULT NULL",
        'valor_final_editado_por' => "ALTER TABLE vendas ADD COLUMN valor_final_editado_por VARCHAR(120) NULL DEFAULT NULL",
        'valor_final_editado_em' => "ALTER TABLE vendas ADD COLUMN valor_final_editado_em DATETIME NULL DEFAULT NULL",
    ];
    foreach ($cols as $col => $sql) {
        if (!colunaHistoricoVendaExiste($conexao, $col)) $conexao->query($sql);
    }
}

garantirColunasHistoricoVenda($conexao);

$busca = trim((string) ($_GET['busca'] ?? ''));
$dataIni = trim((string) ($_GET['data_ini'] ?? ''));
$dataFim = trim((string) ($_GET['data_fim'] ?? ''));
$filtrosRetornoHistoricoVenda = array_filter([
    'busca' => $busca,
    'data_ini' => $dataIni,
    'data_fim' => $dataFim,
], static fn($valor) => $valor !== '');
$redirectHistoricoVenda = '../historico_vendas.php'
    . ($filtrosRetornoHistoricoVenda ? '?' . http_build_query($filtrosRetornoHistoricoVenda) : '');
$where = ["v.status = 'concluido'"];
$params = [];
$types = '';

if ($busca !== '') {
    $where[] = "(p.nome LIKE ? OR c.nome LIKE ? OR v.numero_os LIKE ?)";
    $like = '%' . $busca . '%';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}
if ($dataIni !== '') {
    $where[] = "v.data_venda >= ?";
    $params[] = $dataIni;
    $types .= 's';
}
if ($dataFim !== '') {
    $where[] = "v.data_venda <= ?";
    $params[] = $dataFim;
    $types .= 's';
}

$sql = "
    SELECT v.*,
           p.nome AS produto_nome, p.unidade AS produto_unidade, p.ncm AS produto_ncm, p.nfe_ncm AS produto_nfe_ncm,
           p.codigo_interno AS produto_codigo_interno, p.codigo_barras AS produto_codigo_barras, p.nfe_codigo_interno AS produto_nfe_codigo_interno,
           p.nfe_descricao AS produto_nfe_descricao, p.nfe_tipo AS produto_nfe_tipo,
           p.nfe_cst_pis AS produto_nfe_cst_pis, p.nfe_cst_cofins AS produto_nfe_cst_cofins,
           c.nome AS cliente_nome, c.documento AS cliente_documento, c.telefone AS cliente_telefone, c.endereco AS cliente_endereco,
           c.nfe_nome_razao_social, c.nfe_cpf, c.nfe_cnpj, c.nfe_ie, c.nfe_indicador_ie_destinatario,
           c.nfe_telefone, c.nfe_email, c.nfe_endereco, c.nfe_numero, c.nfe_complemento, c.nfe_bairro,
           c.nfe_cidade, c.nfe_estado, c.nfe_cep
    FROM vendas v
    LEFT JOIN produtos p ON p.id = v.produto_id
    LEFT JOIN clientes c ON c.id = v.cliente_id
    WHERE " . implode(' AND ', $where) . "
	    ORDER BY v.data_venda DESC, v.id DESC
	";
$stmt = $conexao->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$vendas = $stmt->get_result();
$vendasRows = $vendas ? $vendas->fetch_all(MYSQLI_ASSOC) : [];
	$nfeDocsVendaPorOs = [];
	$nfeDocsVendaRes = $conexao->query("
    SELECT id, ref, numero_os, status, numero_nfe, serie, tipo_emissao
    FROM nfe_documentos
    WHERE tipo_emissao = 'venda'
      AND numero_os IS NOT NULL
      AND numero_os <> ''
    ORDER BY id DESC
");
if ($nfeDocsVendaRes) {
    while ($docNfeVenda = $nfeDocsVendaRes->fetch_assoc()) {
        $numeroOsDocVenda = trim((string) ($docNfeVenda['numero_os'] ?? ''));
        if ($numeroOsDocVenda !== '') {
            $nfeDocsVendaPorOs[$numeroOsDocVenda][] = $docNfeVenda;
	        }
	    }
	}
	$boletosVendaPorOs = [];
	$boletosVendaPorNfeId = [];
	$boletosVendaRes = $conexao->query("
	    SELECT id, numero_os, venda_id, nfe_documento_id, situacao, valor
	    FROM boletos
	    WHERE situacao IN ('emitido', 'registrado', 'baixado', 'cancelado')
	    ORDER BY id DESC
	");
	if ($boletosVendaRes) {
	    while ($boletoVenda = $boletosVendaRes->fetch_assoc()) {
	        $numeroOsBoleto = trim((string) ($boletoVenda['numero_os'] ?? ''));
	        if ($numeroOsBoleto !== '') {
	            $boletosVendaPorOs[$numeroOsBoleto][] = $boletoVenda;
	        }
	        $nfeDocIdBoleto = (int) ($boletoVenda['nfe_documento_id'] ?? 0);
	        if ($nfeDocIdBoleto > 0) {
	            $boletosVendaPorNfeId[$nfeDocIdBoleto][] = $boletoVenda;
	        }
	    }
	}

	function historicoVendaNfeResumo(array $docs): array
	{
	    $maisRecente = $docs[0] ?? null;
	    $temAutorizada = false;
	    $docAutorizadaId = null;
	    foreach ($docs as $doc) {
	        if (($doc['status'] ?? '') === 'autorizada') {
	            $temAutorizada = true;
	            $docAutorizadaId = (int) ($doc['id'] ?? 0);
	            break;
	        }
	    }
	    $statusAtual = strtolower((string) ($maisRecente['status'] ?? ''));
	    $classe = $temAutorizada ? 'ok' : (in_array($statusAtual, ['processando', 'enviada'], true) ? 'pend' : (in_array($statusAtual, ['rejeitada', 'erro'], true) ? 'erro' : (in_array($statusAtual, ['cancelada'], true) ? 'cancel' : 'pend')));
	    $rotulo = $temAutorizada ? 'Autorizado' : (in_array($statusAtual, ['processando', 'enviada'], true) ? 'NF-e processando' : ($statusAtual === 'rejeitada' ? 'NF-e rejeitada' : ($statusAtual === 'cancelada' ? 'NF-e cancelada' : 'NF-e pendente')));
	    return ['tem_autorizada' => $temAutorizada, 'doc_autorizada_id' => $docAutorizadaId, 'classe' => $classe, 'rotulo' => $rotulo];
	}

	function historicoVendaBoletoResumo(array $boletosPorOs, array $boletosPorNfeId, string $os, ?int $nfeDocId): array
	{
	    $boletos = [];
	    if ($os !== '' && isset($boletosPorOs[$os])) {
	        $boletos = array_merge($boletos, $boletosPorOs[$os]);
	    }
	    if ($nfeDocId && isset($boletosPorNfeId[$nfeDocId])) {
	        $boletos = array_merge($boletos, $boletosPorNfeId[$nfeDocId]);
	    }
	    $boleto = $boletos[0] ?? null;
	    return [
	        'tem_boleto' => $boleto !== null,
	        'boleto' => $boleto,
	        'classe' => $boleto ? 'ok' : 'pend',
	        'rotulo' => $boleto ? ('Boleto ' . ($boleto['situacao'] ?? 'gerado')) : 'Boleto pendente',
	    ];
	}

	function historicoVendaBoletoStatus(array $itensOs, array $boletosPorOs, array $boletosPorNfeId, string $os, ?int $nfeDocId): array
	{
	    $resumo = historicoVendaBoletoResumo($boletosPorOs, $boletosPorNfeId, $os, $nfeDocId);
	    if ($resumo['tem_boleto']) {
	        return $resumo;
	    }
	    $formaPagamento = strtolower(trim((string) ($itensOs[0]['forma_pagamento'] ?? '')));
	    if ($formaPagamento === 'boleto') {
	        return $resumo;
	    }
	    return [
	        'tem_boleto' => false,
	        'boleto' => null,
	        'classe' => 'pend',
	        'rotulo' => 'Pendente',
	    ];
	}

	$vendasPorOs = [];
	foreach ($vendasRows as $row) {
	    $osKey = trim((string) ($row['numero_os'] ?? ''));
	    $vendasPorOs[$osKey !== '' ? $osKey : '__sem_os_' . $row['id']][] = $row;
	}
	uasort($vendasPorOs, static function (array $a, array $b) use ($nfeDocsVendaPorOs, $boletosVendaPorOs, $boletosVendaPorNfeId): int {
	    $osA = trim((string) ($a[0]['numero_os'] ?? ''));
	    $osB = trim((string) ($b[0]['numero_os'] ?? ''));
	    $nfeA = historicoVendaNfeResumo($osA !== '' ? ($nfeDocsVendaPorOs[$osA] ?? []) : []);
	    $nfeB = historicoVendaNfeResumo($osB !== '' ? ($nfeDocsVendaPorOs[$osB] ?? []) : []);
	    $bolA = historicoVendaBoletoStatus($a, $boletosVendaPorOs, $boletosVendaPorNfeId, $osA, $nfeA['doc_autorizada_id']);
	    $bolB = historicoVendaBoletoStatus($b, $boletosVendaPorOs, $boletosVendaPorNfeId, $osB, $nfeB['doc_autorizada_id']);
	    $rankA = $nfeA['tem_autorizada'] ? 1 : 0;
	    $rankB = $nfeB['tem_autorizada'] ? 1 : 0;
	    if ($rankA !== $rankB) return $rankA <=> $rankB;
	    $boletoObrigatorioA = strtolower(trim((string) ($a[0]['forma_pagamento'] ?? ''))) === 'boleto';
	    $boletoObrigatorioB = strtolower(trim((string) ($b[0]['forma_pagamento'] ?? ''))) === 'boleto';
	    $rankBolA = ($nfeA['tem_autorizada'] && $boletoObrigatorioA && !$bolA['tem_boleto']) ? 0 : 1;
	    $rankBolB = ($nfeB['tem_autorizada'] && $boletoObrigatorioB && !$bolB['tem_boleto']) ? 0 : 1;
	    if ($rankBolA !== $rankBolB) return $rankBolA <=> $rankBolB;
	    return strcmp((string) ($b[0]['data_venda'] ?? ''), (string) ($a[0]['data_venda'] ?? ''));
	});
	$podeEditarValor = in_array($_SESSION['usuario_nivel'] ?? '', ['admin', 'ti'], true);

// Conferencia temporaria das copias das 18 NF-e Covabra autorizadas em 27/08/2026.
// A fonte de cada linha e o payload autorizado; venda e estoque nao sao consultados.
$reemissoesCovabra = [];
$cstProdutoAtualPorCodigo = [];
$resCstProdutos = $conexao->query("SELECT codigo_interno, codigo_barras, nfe_codigo_interno, nfe_cst_pis, nfe_cst_cofins FROM produtos");
if ($resCstProdutos) {
    while ($produtoCst = $resCstProdutos->fetch_assoc()) {
        foreach (['nfe_codigo_interno', 'codigo_barras', 'codigo_interno'] as $campoCodigo) {
            $codigoProduto = preg_replace('/\D+/', '', (string) ($produtoCst[$campoCodigo] ?? ''));
            if ($codigoProduto !== '') {
                $cstProdutoAtualPorCodigo[$codigoProduto] = [
                    'pis' => trim((string) ($produtoCst['nfe_cst_pis'] ?? '')) ?: '06',
                    'cofins' => trim((string) ($produtoCst['nfe_cst_cofins'] ?? '')) ?: '06',
                ];
            }
        }
    }
}
$resReemissoes = $conexao->query("SELECT d.id,d.ref,d.status,d.payload_json,o.numero_nfe AS numero_original
    FROM nfe_documentos d JOIN nfe_documentos o ON o.id=d.origem_id
    WHERE d.origem_tipo='reemissao_payload' AND d.ref LIKE 'REEMISSAO3-COVABRA-NFE-%'
    ORDER BY CAST(o.numero_nfe AS UNSIGNED),d.id");
if ($resReemissoes) while ($re=$resReemissoes->fetch_assoc()) {
    $pl=json_decode((string)($re['payload_json']??''),true); if(!is_array($pl)) continue;
    $itensModal=[]; $valor=0.0;
    foreach(($pl['items']??[]) as $pi){$unidade=strtoupper((string)($pi['unidade_comercial']??'KG'));$qtd=(float)($pi['quantidade_comercial']??0);$valor+=(float)($pi['valor_bruto']??0);
        $codigoItemCst = preg_replace('/\D+/', '', (string) ($pi['codigo_produto'] ?? ''));
        $cstAtual = $cstProdutoAtualPorCodigo[$codigoItemCst] ?? null;
        $valorBrutoItem = (float) ($pi['valor_bruto'] ?? 0);
        $cstPisPayload = (string) ($pi['pis_situacao_tributaria'] ?? '06');
        $cstCofinsPayload = (string) ($pi['cofins_situacao_tributaria'] ?? '06');
        $cstPisModal = (string) ($cstAtual['pis'] ?? $cstPisPayload);
        $cstCofinsModal = (string) ($cstAtual['cofins'] ?? $cstCofinsPayload);
        $pisAlteradoPeloCadastro = $cstPisModal === '01' && $cstPisPayload !== '01';
        $cofinsAlteradoPeloCadastro = $cstCofinsModal === '01' && $cstCofinsPayload !== '01';
        $pisAliquotaModal = $pisAlteradoPeloCadastro ? 0.65 : (float) ($pi['pis_aliquota_porcentual'] ?? 0.65);
        $cofinsAliquotaModal = $cofinsAlteradoPeloCadastro ? 3.00 : (float) ($pi['cofins_aliquota_porcentual'] ?? 3.00);
        $pisValorModal = $pisAlteradoPeloCadastro ? round($valorBrutoItem * $pisAliquotaModal / 100, 2) : (float) ($pi['pis_valor'] ?? 0);
        $cofinsValorModal = $cofinsAlteradoPeloCadastro ? round($valorBrutoItem * $cofinsAliquotaModal / 100, 2) : (float) ($pi['cofins_valor'] ?? 0);
        $itensModal[]=[
        'produto_nome'=>(string)($pi['descricao']??'Produto'),'codigo_produto'=>(string)($pi['codigo_produto']??''),'codigo_produto_esperado'=>(string)($pi['codigo_produto']??''),
        'descricao'=>(string)($pi['descricao']??''),'descricao_esperada'=>(string)($pi['descricao']??''),'codigo_ncm'=>(string)($pi['codigo_ncm']??''),'cfop'=>(string)($pi['cfop']??''),
        'quantidade'=>$qtd,'valor_unitario'=>(float)($pi['valor_unitario_comercial']??0),'valor_bruto'=>(float)($pi['valor_bruto']??0),'unidade'=>$unidade,
        'icms_situacao_tributaria'=>(string)($pi['icms_situacao_tributaria']??'40'),'pis_situacao_tributaria'=>$cstPisModal,'cofins_situacao_tributaria'=>$cstCofinsModal,
        'pis_base_calculo'=>$cstPisModal==='01'?$valorBrutoItem:(float)($pi['pis_base_calculo']??0),'pis_aliquota_porcentual'=>$pisAliquotaModal,'pis_valor'=>$pisValorModal,
        'cofins_base_calculo'=>$cstCofinsModal==='01'?$valorBrutoItem:(float)($pi['cofins_base_calculo']??0),'cofins_aliquota_porcentual'=>$cofinsAliquotaModal,'cofins_valor'=>$cofinsValorModal,'codigo_beneficio_fiscal'=>(string)($pi['codigo_beneficio_fiscal']??''),
        'peso_kg'=>$unidade==='KG'?$qtd:0.001,'peso_unitario_kg'=>$unidade==='KG'?1:0.001];}
    $especie=mb_strtoupper((string)($pl['volumes'][0]['especie']??''),'UTF-8');$tipoCaixa='';
    if(str_contains($especie,'PAPEL'))$tipoCaixa='papelao';elseif(str_contains($especie,'PLASTICA')&&preg_match('/(?:MODELO\s*)?P(?:\s|$)/',$especie))$tipoCaixa='plastica_p';elseif(str_contains($especie,'PLASTICA')&&preg_match('/(?:MODELO\s*)?M(?:\s|$)/',$especie))$tipoCaixa='plastica_m';
    $re['destinatario']=(string)($pl['nome_destinatario']??'');$re['itens']=count($itensModal);$re['valor']=$valor;
    $re['modal_data']=['numero_os'=>(string)$re['numero_original'],'venda_id'=>'','os_label'=>'Cópia revisada da NF-e '.$re['numero_original'],'tipo_operacao_fiscal'=>'venda','local_destino'=>(string)($pl['local_destino']??'1'),
      'destinatario'=>['nome'=>(string)($pl['nome_destinatario']??''),'cpf'=>(string)($pl['cpf_destinatario']??''),'cnpj'=>(string)($pl['cnpj_destinatario']??''),'ie'=>(string)($pl['inscricao_estadual_destinatario']??''),'indicador_ie'=>(string)($pl['indicador_inscricao_estadual_destinatario']??''),'logradouro'=>(string)($pl['logradouro_destinatario']??''),'numero'=>(string)($pl['numero_destinatario']??''),'complemento'=>(string)($pl['complemento_destinatario']??''),'bairro'=>(string)($pl['bairro_destinatario']??''),'municipio'=>(string)($pl['municipio_destinatario']??''),'uf'=>(string)($pl['uf_destinatario']??''),'cep'=>(string)($pl['cep_destinatario']??''),'telefone'=>(string)($pl['telefone_destinatario']??''),'email'=>(string)($pl['email_destinatario']??'')],
      'items'=>$itensModal,'peso_liquido'=>(float)($pl['peso_liquido']??0),'peso_bruto'=>(float)($pl['peso_bruto']??0),'quantidade_caixas'=>(int)($pl['volumes'][0]['quantidade']??0),'tipo_caixa'=>$tipoCaixa,'informacoes_adicionais_contribuinte'=>(string)($pl['informacoes_adicionais_contribuinte']??'')];
    $reemissoesCovabra[]=$re;
}

// Listas para os modais de edição
$listaProdutos = $conexao->query("SELECT id, nome FROM produtos ORDER BY nome ASC")->fetch_all(MYSQLI_ASSOC);
$listaClientes = $conexao->query("SELECT id, nome FROM clientes ORDER BY nome ASC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Histórico de Vendas</title>
<style>
body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;color:#222}.container{padding:30px;max-width:1200px;margin:auto}
.card{background:white;padding:24px;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,.06);margin-bottom:24px}.filters{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:12px;align-items:end}
label{font-weight:700;font-size:14px}input{width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;margin-top:5px}.btn{border:0;border-radius:6px;padding:10px 14px;color:white;background:#2e7d32;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px}
textarea{width:100%;min-height:96px;padding:10px;border:1px solid #ccc;border-radius:6px;margin-top:5px;resize:vertical;font-family:inherit}
	.btn-secondary{background:#6b7280}.btn-edit{background:#1565c0}.btn-print{background:#374151}.btn-doc{background:#e65100}.btn-doc:hover{background:#bf360c}.btn-danger{background:#b91c1c}.btn-warning{background:#92400e}.btn-nfe{background:#7c3aed}.btn-nfe:hover{background:#5b21b6}
	.badge-nfe{display:inline-block;margin-left:8px;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:800;letter-spacing:.02em;vertical-align:middle;white-space:nowrap}
	.badge-nfe.ok{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7}
	.badge-nfe.pend{background:#fff8e1;color:#a86a00;border:1px solid #ffe082}
	.badge-nfe.erro{background:#ffebee;color:#b91c1c;border:1px solid #ef9a9a}
	.badge-nfe.cancel{background:#eceff1;color:#455a64;border:1px solid #b0bec5}
	.doc-status-stack{display:flex;flex-direction:column;gap:5px;align-items:center}
	.doc-status-stack .badge-nfe{margin-left:0}
.msg-sucesso{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600}.msg-erro{background:#ffebee;color:#c62828;border:1px solid #ef9a9a;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600}
.table-container{overflow-x:auto}table{width:100%;min-width:980px;border-collapse:collapse;background:white}th{background:#1b5e20;color:white;padding:12px;text-align:center}td{padding:12px;border-bottom:1px solid #eee;text-align:center}.acoes{display:flex;gap:8px;justify-content:center;flex-wrap:wrap}.alterado{color:#c62828;font-weight:800;font-size:12px;display:block}
.os-group-header td{background:#e8f5e9;font-weight:700;border-top:2px solid #2e7d32}.historico-venda-item-row td{background:#f9fbe7;font-size:13px}.historico-venda-item-row.hidden{display:none}.btn-toggle-os{background:white;color:#1b5e20;border:1px solid #2e7d32;width:32px;height:32px;padding:0;font-weight:800}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1400;padding:20px;overflow-y:auto}.modal[style*="block"]{display:flex!important;align-items:center;justify-content:center}.modal-box{background:white;max-width:420px;width:min(100%,420px);margin:auto;padding:24px;border-radius:10px}.modal-box-wide{background:white;max-width:1000px;width:min(100%,1000px);margin:auto;padding:24px;border-radius:10px;max-height:90vh;overflow-y:auto}.eit{width:100%;border-collapse:collapse;margin-top:8px;font-size:12px}.eit th{background:#2e7d32;color:white;padding:5px 7px;text-align:center;font-weight:600;white-space:nowrap}.eit td{padding:4px 6px;border-bottom:1px solid #e5e7eb;vertical-align:middle}.eit input[type=number]{width:100%;padding:3px 5px;border:1px solid #ccc;border-radius:4px;font-size:12px;text-align:right;min-width:0}.eit .pnome{text-align:left;max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.modal-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.modal-grid-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.nfe-item-card{border:1px solid #ddd;border-radius:8px;padding:12px;margin-top:10px;background:#fafafa}.nfe-item-card h4{margin:0 0 8px}.nfe-item-card.nfe-codigo-divergente,.nfe-item-card.nfe-descricao-divergente,.nfe-item-card.nfe-item-invalido{border-color:#dc2626;background:#fff1f2}.nfe-item-card.nfe-codigo-divergente .nfe-in-codigo,.nfe-item-card.nfe-descricao-divergente .nfe-in-descricao,.nfe-campo-invalido{border-color:#dc2626!important;background:#fff!important;color:#991b1b!important;font-weight:800}.nfe-codigo-alerta,.nfe-descricao-alerta{display:none;color:#b91c1c;font-size:12px;font-weight:800;margin-top:4px}.nfe-item-card.nfe-codigo-divergente .nfe-codigo-alerta,.nfe-item-card.nfe-descricao-divergente .nfe-descricao-alerta{display:block}.nfe-section-title{margin:18px 0 8px;font-size:15px;color:#1b5e20}.nfe-erros-box{display:none;background:#fff1f2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:12px 14px;margin:12px 0;font-weight:700}.nfe-erros-box ul{margin:8px 0 0;padding-left:18px;font-weight:600}.nfe-erros-box li{margin:4px 0;text-align:left}.nfe-item-head{display:flex;align-items:center;justify-content:space-between;gap:8px}.nfe-item-remove{flex:0 0 auto;border:1px solid #e0b4b4;background:#fff;color:#b91c1c;width:28px;height:28px;border-radius:6px;cursor:pointer;font-size:14px;line-height:1;font-weight:700}.nfe-item-remove:hover{background:#b91c1c;color:#fff;border-color:#b91c1c}
@media(max-width:800px){.filters{grid-template-columns:1fr}}
@media(max-width:600px){.modal{padding:72px 16px 18px;align-items:flex-start}.modal[style*="block"]{align-items:flex-start}.modal-box{width:100%!important;max-width:420px!important;margin:0 auto!important;padding:22px 18px!important}.modal-box input,.modal-box select,.modal-box textarea{width:100%!important;min-width:0}.modal-box form>div[style*="grid-template-columns"]{grid-template-columns:1fr!important}.modal-box form>div[style*="justify-content:flex-end"]{justify-content:center!important;flex-wrap:wrap}.modal-box form>div[style*="justify-content:flex-end"] .btn{min-height:42px}}
</style>
<?php renderAppLayoutStyles(); ?>
<script>
const HISTORICO_VENDAS_OS_ABERTA_KEY = 'historico_vendas_os_aberta';

function marcarHistoricoVendaOsAtiva(os) {
    os = String(os || '').trim();
    if (os !== '') sessionStorage.setItem(HISTORICO_VENDAS_OS_ABERTA_KEY, os);
}

function encontrarElementoHistoricoVenda(seletor, atributo, os) {
    return Array.from(document.querySelectorAll(seletor)).find(function (elemento) {
        return elemento.getAttribute(atributo) === os;
    }) || null;
}

function definirHistoricoVendaOsAberta(os, aberta, centralizar) {
    os = String(os || '');
    const linhas = Array.from(document.querySelectorAll('.historico-venda-item-row[data-os]')).filter(function (linha) {
        return linha.dataset.os === os;
    });
    const botao = encontrarElementoHistoricoVenda('.btn-toggle-os[data-historico-venda-os]', 'data-historico-venda-os', os);
    const cabecalho = encontrarElementoHistoricoVenda('.os-group-header[data-os]', 'data-os', os);
    if (!cabecalho || linhas.length === 0) return false;

    linhas.forEach(function (linha) { linha.classList.toggle('hidden', !aberta); });
    if (botao) botao.textContent = aberta ? '▲' : '▼';
    if (aberta) sessionStorage.setItem(HISTORICO_VENDAS_OS_ABERTA_KEY, os);
    else sessionStorage.removeItem(HISTORICO_VENDAS_OS_ABERTA_KEY);

    if (aberta && centralizar) {
        requestAnimationFrame(function () { cabecalho.scrollIntoView({block: 'center'}); });
    }
    return true;
}

/* ── Modal editar produto (histórico) ── */
function abrirModalEditarHistorico(data) {
    marcarHistoricoVendaOsAtiva(data.os);
    document.getElementById('edit_hist_id').value         = data.id;
    document.getElementById('edit_hist_produto_id').value = data.produto_id || '';
    document.getElementById('edit_hist_tipo_comercial').value = data.tipo_comercial || '';
    document.getElementById('edit_hist_tipo').value       = data.tipo || 'kg';
    document.getElementById('edit_hist_pedido').value     = Number(data.pedido || 0).toFixed(2);
    document.getElementById('edit_hist_preco').value      = Number(data.preco || 0).toFixed(2);
    document.getElementById('edit_hist_gramagem').value   = Number(data.gramagem || 0) || '';
    document.getElementById('edit_hist_kg_caixa').value   = Number(data.kg_caixa || 0) || '';
    document.getElementById('edit_hist_num_caixas_item').value = Number(data.num_caixas || 0) || '';
    document.getElementById('edit_hist_bandejas_por_caixa_item').value = Number(data.bandejas_por_caixa || 0) || '';
    atualizarCamposTipoHistorico();
    document.getElementById('modalEditarHistorico').style.display = 'block';
}
function fecharModalEditarHistorico() {
    document.getElementById('modalEditarHistorico').style.display = 'none';
}

/* -- Utilitario HTML escape -- */
function _escH(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

/* -- Modal editar OS -- */
function abrirModalEditarOs(data) {
    marcarHistoricoVendaOsAtiva(data.os);
    document.getElementById('edit_os_numero').value          = data.os;
    document.getElementById('edit_os_numero_label').textContent = ' — ' + (data.os || '');
    document.getElementById('edit_os_cliente_id').value      = data.cliente_id || '';
    document.getElementById('edit_os_previsao').value        = data.previsao || '';
    document.getElementById('edit_os_data_venda').value      = data.data_venda || '';
    document.getElementById('edit_os_vendedor').value        = data.vendedor || '';
    document.getElementById('edit_os_prazo_pagamento').value = data.prazo_pagamento || '';
    document.getElementById('edit_os_forma_pagamento').value = data.forma_pagamento || '';
    document.getElementById('edit_os_num_caixas').value      = '0';
    document.getElementById('edit_os_caixa_id').value        = '';
    var tbody = document.getElementById('edit_os_itens_tbody');
    tbody.innerHTML = '';
    (data.itens || []).forEach(function(it) {
        var tr = document.createElement('tr');
        var tipoLabel = it.tipo || 'kg';
        if (it.gramagem > 0) tipoLabel += ' ' + it.gramagem + 'g';
        else if (it.kg_caixa > 0) tipoLabel += ' ' + it.kg_caixa + 'kg/cx';
        var vfVal = (it.valor_final_editado !== null && it.valor_final_editado !== undefined)
                    ? Number(it.valor_final_editado).toFixed(2) : '';
        tr.innerHTML =
            '<td class="pnome" title="' + _escH(it.produto_nome) + '">' + _escH(it.produto_nome) +
            '<br><small style="color:#666">' + _escH(tipoLabel) + '</small></td>' +
            '<td><input type="number" step="0.01" min="0" class="eit-pedido" data-id="' + it.id + '" value="' + Number(it.pedido).toFixed(2) + '"></td>' +
            '<td><input type="number" step="0.01" min="0" class="eit-preco" data-id="' + it.id + '" value="' + Number(it.preco).toFixed(2) + '"></td>' +
            '<td><input type="number" step="0.01" min="0" class="eit-vf" data-id="' + it.id + '" value="' + vfVal + '" placeholder="Auto"></td>';
        tbody.appendChild(tr);
    });
    window._editOsAtual = data;
    document.getElementById('modalEditarOs').style.display = 'block';
}
function fecharModalEditarOs() { document.getElementById('modalEditarOs').style.display = 'none'; }

async function salvarOsHistorico() {
    var csrf  = document.querySelector('#modalEditarOs input[name=csrf_token]').value;
    var itens = [];
    document.querySelectorAll('#edit_os_itens_tbody tr').forEach(function(tr) {
        var id    = parseInt(tr.querySelector('.eit-pedido').dataset.id);
        var pedido= parseFloat(tr.querySelector('.eit-pedido').value) || 0;
        var preco = parseFloat(tr.querySelector('.eit-preco').value)  || 0;
        var vfRaw = tr.querySelector('.eit-vf').value.trim();
        var vf    = vfRaw !== '' ? parseFloat(vfRaw) : null;
        itens.push({id: id, pedido: pedido, preco: preco, valor_final_editado: vf});
    });
    var fd = new FormData();
    fd.append('csrf_token',       csrf);
    fd.append('numero_os',        document.getElementById('edit_os_numero').value);
    fd.append('cliente_id',       document.getElementById('edit_os_cliente_id').value);
    fd.append('previsao_entrega', document.getElementById('edit_os_previsao').value);
    fd.append('data_venda',       document.getElementById('edit_os_data_venda').value);
    fd.append('vendedor',         document.getElementById('edit_os_vendedor').value);
    fd.append('prazo_pagamento',  document.getElementById('edit_os_prazo_pagamento').value);
    fd.append('forma_pagamento',  document.getElementById('edit_os_forma_pagamento').value);
    fd.append('itens',            JSON.stringify(itens));
    var btn = document.getElementById('btn_salvar_os');
    btn.disabled = true; btn.textContent = 'Salvando…';
    try {
        var r = await fetch('actions/editar_os_historico.php', {method:'POST', body: fd});
        var d = await r.json();
        if (d.ok) { fecharModalEditarOs(); location.reload(); }
        else       { alert('Erro: ' + (d.erro || 'Desconhecido')); }
    } catch(e) { alert('Erro de rede.'); }
    finally { btn.disabled = false; btn.textContent = 'Salvar OS'; }
}

/* Adicionar item a OS historica */
function abrirModalAdicionarItemOs() {
    if (!window._editOsAtual) return;
    marcarHistoricoVendaOsAtiva(window._editOsAtual.os);
    document.getElementById('add_item_os_numero_display').textContent = window._editOsAtual.os || '';
    document.getElementById('add_item_os_numero').value  = window._editOsAtual.os || '';
    document.getElementById('add_item_produto_id').value = '';
    document.getElementById('add_item_tipo').value       = 'kg';
    document.getElementById('add_item_pedido').value     = '';
    document.getElementById('add_item_preco').value      = '';
    document.getElementById('add_item_gramagem').value   = '';
    document.getElementById('add_item_kg_caixa').value   = '';
    _toggleAddItemCampos();
    document.getElementById('modalAdicionarItemOs').style.display = 'block';
}
function fecharModalAdicionarItemOs() { document.getElementById('modalAdicionarItemOs').style.display = 'none'; }
function _toggleAddItemCampos() {
    var t = document.getElementById('add_item_tipo').value;
    document.getElementById('add_item_gramagem_row').style.display = t === 'bandeja' ? '' : 'none';
    document.getElementById('add_item_kg_caixa_row').style.display = t === 'caixa'   ? '' : 'none';
}
async function salvarAdicionarItemOs() {
    // Validacao frontend
    var prodSel  = document.getElementById('add_item_produto_id');
    var pedidoEl = document.getElementById('add_item_pedido');
    var tipo     = document.getElementById('add_item_tipo').value;
    if (!prodSel.value) { prodSel.focus(); alert('Selecione um produto.'); return; }
    if (!pedidoEl.value || parseFloat(pedidoEl.value) <= 0) { pedidoEl.focus(); alert('Informe a quantidade.'); return; }
    if (tipo === 'bandeja' && !(parseFloat(document.getElementById('add_item_gramagem').value) > 0)) {
        document.getElementById('add_item_gramagem').focus(); alert('Informe a gramagem por bandeja.'); return;
    }
    if (tipo === 'caixa' && !(parseFloat(document.getElementById('add_item_kg_caixa').value) > 0)) {
        document.getElementById('add_item_kg_caixa').focus(); alert('Informe o kg por caixa.'); return;
    }
    var csrf = document.querySelector('#modalAdicionarItemOs input[name=csrf_token]').value;
    var fd   = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('numero_os',  document.getElementById('add_item_os_numero').value);
    fd.append('produto_id', prodSel.value);
    fd.append('tipo',       tipo);
    fd.append('gramagem',   document.getElementById('add_item_gramagem').value || '0');
    fd.append('kg_caixa',   document.getElementById('add_item_kg_caixa').value || '0');
    fd.append('pedido',     pedidoEl.value);
    fd.append('preco',      document.getElementById('add_item_preco').value);
    var btn = document.getElementById('btn_salvar_add_item');
    btn.disabled = true; btn.textContent = 'Salvando…';
    try {
        var r = await fetch('actions/adicionar_item_os_historico.php', {method:'POST', body: fd});
        var d = await r.json();
        if (d.ok) { fecharModalAdicionarItemOs(); fecharModalEditarOs(); location.reload(); }
        else       { alert('Erro: ' + (d.erro || 'Desconhecido')); }
    } catch(e) { alert('Erro de rede.'); }
    finally { btn.disabled = false; btn.textContent = 'Salvar'; }
}
/* ── Modal cancelar OS inteira ── */
function abrirModalCancelarOs(os, cliente, valor) {
    document.getElementById('cancelar_os_numero').value = os;
    document.getElementById('cancelar_os_label').textContent  = os || '-';
    document.getElementById('cancelar_os_cliente').textContent = cliente || '-';
    document.getElementById('cancelar_os_valor').textContent   = 'R$ ' + Number(valor || 0).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('cancelar_os_motivo').value = '';
    document.getElementById('modalCancelarOs').style.display = 'block';
}
function fecharModalCancelarOs() { document.getElementById('modalCancelarOs').style.display = 'none'; }
function confirmarCancelamentoOs() {
    return confirm('Cancelar TODA a OS, devolver o estoque e registrar reversao financeira?');
}
function nfeNumero(valor, casas = 2) {
    const n = Number(String(valor || 0).replace(',', '.'));
    return Number.isFinite(n) ? n.toFixed(casas) : (0).toFixed(casas);
}
function nfeAtualizarPesos() {
    const pesoLiquido = Number(document.getElementById('nfe_peso_liquido').value || 0);
    const qtd = Number(document.getElementById('nfe_quantidade_caixas').value || 0);
    const tipo = document.getElementById('nfe_tipo_caixa').value;
    const pesos = {plastica_p: 1.350, plastica_m: 2.000, papelao: 0.750};
    // Sempre parte do peso liquido: nunca acumula caixas sobre o peso bruto atual.
    document.getElementById('nfe_peso_bruto').value = nfeNumero(pesoLiquido + (qtd * (pesos[tipo] || 0)), 3);
}
function nfeRecalcularPesoLiquidoItens() {
    let total = 0;
    document.querySelectorAll('#nfe_items_box .nfe-item-card:not(.nfe-item-excluido)').forEach(function(card) {
        const qtdEl = card.querySelector('.nfe-in-qtd');
        const qtd = Number(String(qtdEl ? qtdEl.value : 0).replace(',', '.')) || 0;
        const pesoUnitarioKg = Number(card.dataset.pesoUnitarioKg || 0);
        if (pesoUnitarioKg > 0) {
            total += qtd * pesoUnitarioKg;
        } else {
            const unidadeEl = card.querySelector('.nfe-in-unidade');
            const unidade = String(unidadeEl ? unidadeEl.value : '').trim().toUpperCase();
            if (unidade === 'KG') {
                total += qtd;
            }
        }
    });
    document.getElementById('nfe_peso_liquido').value = nfeNumero(total, 3);
    nfeAtualizarPesos();
}
function nfeRecalcItem(card) {
    const qtdEl = card.querySelector('.nfe-in-qtd');
    const vuEl  = card.querySelector('.nfe-in-vu');
    const vbEl  = card.querySelector('.nfe-in-vb');
    if (!qtdEl || !vuEl || !vbEl) return;
    const qtd = Number(String(qtdEl.value || 0).replace(',', '.')) || 0;
    const vu  = Number(String(vuEl.value || 0).replace(',', '.')) || 0;
    vbEl.value = nfeNumero(qtd * vu, 2);
    const qtdTrib = card.querySelector('.nfe-hid-qtd');
    const vuTrib  = card.querySelector('.nfe-hid-vu');
    if (qtdTrib) qtdTrib.value = nfeNumero(qtd, 4);
    if (vuTrib)  vuTrib.value  = nfeNumero(vu, 4);
    nfeAtualizarPisCofins(card, true, true);
    nfeRecalcularPesoLiquidoItens();
}
function nfeAtualizarPisCofins(card, recalcular = false, avisar = false) {
    if (!card) return;
    const base = Number(String((card.querySelector('.nfe-in-vb') || {}).value || 0).replace(',', '.')) || 0;
    let total = 0;
    [['pis', 0.65], ['cofins', 3.00]].forEach(function(cfg) {
        const prefixo = cfg[0], aliquotaPadrao = cfg[1];
        const cst = card.querySelector('.nfe-in-cst-' + prefixo);
        const baseEl = card.querySelector('.nfe-in-' + prefixo + '-base');
        const aliqEl = card.querySelector('.nfe-in-' + prefixo + '-aliquota');
        const valorEl = card.querySelector('.nfe-in-' + prefixo + '-valor');
        const tributado = cst && String(cst.value || '').trim() === '01';
        [baseEl, aliqEl, valorEl].forEach(function(el) { if (el) el.disabled = !tributado; });
        if (!tributado) {
            if (baseEl) baseEl.value = '';
            if (aliqEl) aliqEl.value = '';
            if (valorEl) valorEl.value = '';
            return;
        }
        if (baseEl) baseEl.value = nfeNumero(base, 2);
        let aliquota = Number(String(aliqEl && aliqEl.value !== '' ? aliqEl.value : aliquotaPadrao).replace(',', '.'));
        if (!Number.isFinite(aliquota) || aliquota < 0) aliquota = aliquotaPadrao;
        if (aliqEl && (recalcular || aliqEl.value === '')) aliqEl.value = nfeNumero(aliquota, 4);
        let valor = Number(String(valorEl && valorEl.value !== '' ? valorEl.value : '').replace(',', '.'));
        if (recalcular || !Number.isFinite(valor)) valor = Math.round((base * aliquota / 100 + Number.EPSILON) * 100) / 100;
        if (valorEl) valorEl.value = nfeNumero(valor, 2);
        total += valor;
    });
    const totalEl = card.querySelector('.nfe-pis-cofins-total');
    if (totalEl) totalEl.textContent = 'R$ ' + total.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    if (avisar) nfeMostrarRascunhoVendaMsg('Quantidade ou valor unitário alterado: PIS/COFINS foram recalculados. Revise os tributos antes de emitir.');
}
function nfeAlterarCstPisCofins(input) {
    nfeMarcarTributoEditado(input);
    nfeAtualizarPisCofins(input.closest('.nfe-item-card'), true, false);
}
function nfeAlterarAliquotaPisCofins(input) {
    nfeMarcarTributoEditado(input);
    nfeAtualizarPisCofins(input.closest('.nfe-item-card'), true, false);
}
function nfeAlterarValorPisCofins(input) {
    nfeMarcarTributoEditado(input);
    nfeAtualizarPisCofins(input.closest('.nfe-item-card'), false, false);
}
function nfeMarcarTributoEditado(input) {
    const card = input ? input.closest('.nfe-item-card') : null;
    if (!card) return;
    const prefixo = input.className.includes('cofins') ? 'cofins' : 'pis';
    const marcador = card.querySelector('.nfe-in-' + prefixo + '-tributo-editado');
    if (marcador) marcador.value = '1';
}
function nfeRemoverItem(btn) {
    const card = btn.closest('.nfe-item-card');
    if (!card) return;
    if (!confirm('Remover este produto da nota fiscal?')) return;
    const flag = card.querySelector('.nfe-in-excluir');
    if (flag) flag.value = '1';
    card.classList.add('nfe-item-excluido');
    card.style.display = 'none';
    nfeRecalcularPesoLiquidoItens();
}
function nfeNormalizarCodigo(valor) {
    return String(valor || '').trim();
}
function nfeNormalizarComparacao(valor) {
    return String(valor || '').trim().replace(/\s+/g, ' ').toLowerCase();
}
function nfeSomenteDigitos(valor) {
    return String(valor || '').replace(/\D/g, '');
}
function nfeValorNumerico(valor) {
    const n = Number(String(valor || 0).replace(',', '.'));
    return Number.isFinite(n) ? n : 0;
}
function nfeMarcarCampo(el, invalido) {
    if (el) el.classList.toggle('nfe-campo-invalido', !!invalido);
}
function nfeAdicionarErro(erros, mensagem, el) {
    erros.push(mensagem);
    nfeMarcarCampo(el, true);
}
function nfeLimparErrosModal() {
    const box = document.getElementById('nfe_erros_box');
    const lista = document.getElementById('nfe_erros_lista');
    if (box) box.style.display = 'none';
    if (lista) lista.innerHTML = '';
    document.querySelectorAll('#modalNfeVenda .nfe-campo-invalido').forEach(function(el) {
        el.classList.remove('nfe-campo-invalido');
    });
    document.querySelectorAll('#nfe_items_box .nfe-item-card').forEach(function(card) {
        card.classList.remove('nfe-item-invalido');
    });
}
function nfeMostrarErrosModal(erros) {
    const box = document.getElementById('nfe_erros_box');
    const lista = document.getElementById('nfe_erros_lista');
    if (!box || !lista) {
        alert(erros.join('\n'));
        return;
    }
    lista.innerHTML = '';
    erros.forEach(function(erro) {
        const li = document.createElement('li');
        li.textContent = erro;
        lista.appendChild(li);
    });
    box.style.display = 'block';
    box.scrollIntoView({behavior: 'smooth', block: 'start'});
}
function nfeValidarCodigoCard(card) {
    if (!card || card.classList.contains('nfe-item-excluido')) return true;
    const inputCodigo = card.querySelector('.nfe-in-codigo');
    const codigoEsperado = nfeNormalizarCodigo(card.dataset.codigoEsperado || '');
    const codigoAtual = nfeNormalizarCodigo(inputCodigo ? inputCodigo.value : '');
    const codigoDigitos = codigoAtual.replace(/\D/g, '');
    const codigoEsperadoDigitos = codigoEsperado.replace(/\D/g, '');
    const codigoTem13Digitos = codigoDigitos.length === 13;
    const codigoConfere = codigoEsperadoDigitos === '' || codigoDigitos === codigoEsperadoDigitos;
    const codigoOk = codigoTem13Digitos && codigoConfere;
    card.classList.toggle('nfe-codigo-divergente', !codigoOk);
    const alertaCodigo = card.querySelector('.nfe-codigo-alerta');
    if (alertaCodigo) {
        alertaCodigo.textContent = '';
        if (!codigoTem13Digitos) {
            alertaCodigo.textContent = 'Codigo interno invalido. Informe exatamente 13 digitos.';
        } else if (!codigoConfere) {
            alertaCodigo.textContent = 'Codigo divergente. Esperado: ' + codigoEsperado;
        }
    }

    const inputDescricao = card.querySelector('.nfe-in-descricao');
    const descricaoEsperada = String(card.dataset.descricaoEsperada || '').trim();
    const descricaoAtual = inputDescricao ? inputDescricao.value : '';
    const descricaoOk = descricaoEsperada === '' || nfeNormalizarComparacao(descricaoAtual) === nfeNormalizarComparacao(descricaoEsperada);
    card.classList.toggle('nfe-descricao-divergente', !descricaoOk);
    const alertaDescricao = card.querySelector('.nfe-descricao-alerta');
    if (alertaDescricao && !descricaoOk) alertaDescricao.textContent = 'Descricao divergente. Esperado: ' + descricaoEsperada;

    return codigoOk && descricaoOk;
}
function nfeValidarCodigosItens() {
    let ok = true;
    document.querySelectorAll('#nfe_items_box .nfe-item-card:not(.nfe-item-excluido)').forEach(function(card) {
        if (!nfeValidarCodigoCard(card)) ok = false;
    });
    return ok;
}
function nfePreAuditarModal() {
    nfeLimparErrosModal();
    const erros = [];
    const restantes = document.querySelectorAll('#nfe_items_box .nfe-item-card:not(.nfe-item-excluido)').length;
    if (restantes === 0) {
        erros.push('A nota precisa de pelo menos um produto. Nao e possivel emitir com todos excluidos.');
    }

    const nome = document.getElementById('nfe_dest_nome');
    const cpf = document.getElementById('nfe_dest_cpf');
    const cnpj = document.getElementById('nfe_dest_cnpj');
    const logradouro = document.getElementById('nfe_dest_logradouro');
    const numero = document.getElementById('nfe_dest_numero');
    const bairro = document.getElementById('nfe_dest_bairro');
    const municipio = document.getElementById('nfe_dest_municipio');
    const uf = document.getElementById('nfe_dest_uf');
    const cep = document.getElementById('nfe_dest_cep');
    const indicadorIe = document.getElementById('nfe_dest_indicador_ie');
    const ie = document.getElementById('nfe_dest_ie');
    const localDestino = document.getElementById('nfe_local_destino');

    if (!nome || nome.value.trim() === '') nfeAdicionarErro(erros, 'Cliente sem nome ou razao social para NF-e.', nome);
    const cpfDigitos = nfeSomenteDigitos(cpf ? cpf.value : '');
    const cnpjDigitos = nfeSomenteDigitos(cnpj ? cnpj.value : '');
    if (cpfDigitos.length !== 11 && cnpjDigitos.length !== 14) {
        nfeAdicionarErro(erros, 'Cliente sem CPF/CNPJ valido. Informe CPF com 11 digitos ou CNPJ com 14 digitos.', cnpj || cpf);
        nfeMarcarCampo(cpf, true);
    }
    if (!logradouro || logradouro.value.trim() === '') nfeAdicionarErro(erros, 'Cliente sem logradouro/endereco fiscal.', logradouro);
    if (!numero || numero.value.trim() === '') nfeAdicionarErro(erros, 'Cliente sem numero no endereco fiscal.', numero);
    if (!bairro || bairro.value.trim() === '') nfeAdicionarErro(erros, 'Cliente sem bairro no cadastro fiscal.', bairro);
    if (!municipio || municipio.value.trim() === '') nfeAdicionarErro(erros, 'Cliente sem cidade/municipio no cadastro fiscal.', municipio);
    const ufValor = uf ? uf.value.trim().toUpperCase() : '';
    if (!/^[A-Z]{2}$/.test(ufValor)) nfeAdicionarErro(erros, 'UF do cliente deve conter exatamente 2 letras.', uf);
    if (nfeSomenteDigitos(cep ? cep.value : '').length !== 8) nfeAdicionarErro(erros, 'CEP do cliente deve conter exatamente 8 digitos.', cep);
    const indicadorValor = indicadorIe ? indicadorIe.value.trim() : '';
    if (indicadorValor === '') {
        nfeAdicionarErro(erros, 'Informe o indicador de inscricao estadual do cliente.', indicadorIe);
    } else if (indicadorValor !== '9' && nfeSomenteDigitos(ie ? ie.value : '') === '') {
        nfeAdicionarErro(erros, 'Informe a inscricao estadual do cliente ou use indicador 9 quando ele for nao contribuinte.', ie);
    }
    if (localDestino && localDestino.value === '1' && ufValor !== 'SP') {
        nfeAdicionarErro(erros, 'Local de destino interno exige cliente com UF SP.', uf);
    }
    if (localDestino && localDestino.value === '2' && (ufValor === '' || ufValor === 'SP')) {
        nfeAdicionarErro(erros, 'Local de destino interestadual exige cliente fora de SP.', uf);
    }
    if (localDestino && localDestino.value === '3') {
        nfeAdicionarErro(erros, 'Operacao para exterior ainda exige dados adicionais e nao deve ser emitida por este modal.', localDestino);
    }

    if (!nfeValidarCodigosItens()) {
        erros.push('Existem produtos com codigo interno invalido, codigo divergente ou descricao divergente. Corrija os campos destacados em vermelho antes de emitir.');
    }

    document.querySelectorAll('#nfe_items_box .nfe-item-card:not(.nfe-item-excluido)').forEach(function(card, idx) {
        let itemInvalido = false;
        const titulo = card.querySelector('h4');
        const nomeProduto = (titulo ? titulo.textContent : ('Produto ' + (idx + 1))).trim();
        const ncm = card.querySelector('[name$="[codigo_ncm]"]');
        const cfop = card.querySelector('[name$="[cfop]"]');
        const unidade = card.querySelector('[name$="[unidade_comercial]"]');
        const qtd = card.querySelector('.nfe-in-qtd');
        const descricao = card.querySelector('.nfe-in-descricao');
        const cstPis = card.querySelector('.nfe-in-cst-pis');
        const cstCofins = card.querySelector('.nfe-in-cst-cofins');
        if (!descricao || descricao.value.trim() === '') {
            nfeAdicionarErro(erros, 'Produto "' + nomeProduto + '" sem descricao fiscal.', descricao);
            itemInvalido = true;
        }
        if (nfeSomenteDigitos(ncm ? ncm.value : '').length !== 8) {
            nfeAdicionarErro(erros, 'Produto "' + nomeProduto + '" possui NCM invalido. O NCM deve conter exatamente 8 numeros.', ncm);
            itemInvalido = true;
        }
        if (nfeSomenteDigitos(cfop ? cfop.value : '').length !== 4) {
            nfeAdicionarErro(erros, 'Produto "' + nomeProduto + '" possui CFOP invalido. O CFOP deve conter exatamente 4 numeros.', cfop);
            itemInvalido = true;
        }
        if (nfeSomenteDigitos(cstPis ? cstPis.value : '').length !== 2) {
            nfeAdicionarErro(erros, 'Produto "' + nomeProduto + '" possui CST PIS invalido. Informe exatamente 2 numeros.', cstPis);
            itemInvalido = true;
        }
        if (nfeSomenteDigitos(cstCofins ? cstCofins.value : '').length !== 2) {
            nfeAdicionarErro(erros, 'Produto "' + nomeProduto + '" possui CST COFINS invalido. Informe exatamente 2 numeros.', cstCofins);
            itemInvalido = true;
        }
        const unidadeValor = unidade ? unidade.value.trim().toUpperCase() : '';
        if (!['KG', 'UN', 'CX', 'BD'].includes(unidadeValor)) {
            nfeAdicionarErro(erros, 'Produto "' + nomeProduto + '" possui unidade fiscal invalida. Use KG, UN, CX ou BD quando for bandeja.', unidade);
            itemInvalido = true;
        }
        if (nfeValorNumerico(qtd ? qtd.value : 0) <= 0) {
            nfeAdicionarErro(erros, 'Produto "' + nomeProduto + '" esta com quantidade menor ou igual a zero.', qtd);
            itemInvalido = true;
        }
        if (Number(card.dataset.pesoUnitarioKg || 0) <= 0 && unidadeValor !== 'KG') {
            erros.push('Nao foi possivel calcular o peso do produto "' + nomeProduto + '". Verifique quantidade, gramagem ou peso por caixa.');
            itemInvalido = true;
        }
        card.classList.toggle('nfe-item-invalido', itemInvalido);
    });

    const pesoLiquido = document.getElementById('nfe_peso_liquido');
    const pesoBruto = document.getElementById('nfe_peso_bruto');
    const pesoLiquidoValor = nfeValorNumerico(pesoLiquido ? pesoLiquido.value : 0);
    const pesoBrutoValor = nfeValorNumerico(pesoBruto ? pesoBruto.value : 0);
    if (pesoLiquidoValor <= 0) nfeAdicionarErro(erros, 'Peso liquido da NF-e precisa ser maior que zero.', pesoLiquido);
    if (pesoBrutoValor + 0.001 < pesoLiquidoValor) nfeAdicionarErro(erros, 'Nao foi possivel emitir a NF-e porque o peso bruto esta menor que o peso liquido.', pesoBruto);

    return {ok: erros.length === 0, erros: erros};
}
function nfeConfirmarEmissao(form) {
    nfeEnviarNfeVenda(form);
    return false;
}
async function nfeEnviarNfeVenda(form) {
    const auditoria = nfePreAuditarModal();
    if (!auditoria.ok) {
        nfeMostrarErrosModal(auditoria.erros);
        return;
    }
    if (!confirm('Emitir NF-e com os dados revisados?')) {
        return;
    }
    const btn = form.querySelector('button[type="submit"]');
    const textoOriginal = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Enviando...';
    }
    try {
        const resp = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
        });
        const data = await resp.json();
        if (data.ok && data.redirect) {
            window.location.href = new URL(data.redirect, form.action).href;
            return;
        }
        nfeMostrarErrosModal(data.erros || [data.erro || 'Nao foi possivel emitir a NF-e. Revise os dados e tente novamente.']);
    } catch (e) {
        nfeMostrarErrosModal(['Nao foi possivel comunicar com o servidor para emitir a NF-e. Tente novamente e, se persistir, consulte o suporte.']);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = textoOriginal;
        }
    }
}
function nfeBindRecalcItem(card) {
    const qtdEl = card.querySelector('.nfe-in-qtd');
    const vuEl  = card.querySelector('.nfe-in-vu');
    [qtdEl, vuEl].forEach(function (el) {
        if (!el) return;
        el.addEventListener('input', function () { nfeRecalcItem(card); });
        el.addEventListener('change', function () { nfeRecalcItem(card); });
    });
}
function nfeMostrarRascunhoVendaMsg(texto, erro = false) {
    const msg = document.getElementById('nfe_rascunho_msg');
    if (!msg) return;
    msg.style.display = texto ? 'block' : 'none';
    msg.style.color = erro ? '#b91c1c' : '#166534';
    msg.textContent = texto || '';
}

function nfeAtualizarTipoOperacaoFiscalVenda() {
    const tipo = document.getElementById('nfe_tipo_operacao_fiscal');
    const localDestino = document.getElementById('nfe_local_destino');
    const isBonificacao = tipo && tipo.value === 'bonificacao';
    const cfopBonificacao = localDestino && localDestino.value === '2' ? '6910' : '5910';
    document.querySelectorAll('#nfe_items_box .nfe-item-card').forEach(function(card) {
        const cfop = card.querySelector('[name$="[cfop]"]');
        const cstIcms = card.querySelector('[name$="[icms_situacao_tributaria]"]');
        const cbenef = card.querySelector('[name$="[codigo_beneficio_fiscal]"]');
        if (!cfop) return;
        if (isBonificacao) {
            cfop.value = cfopBonificacao;
            if (cstIcms) cstIcms.value = '50';
            if (cbenef) cbenef.value = 'SP053190';
        } else if (card.dataset.cfopOriginal) {
            cfop.value = card.dataset.cfopOriginal;
            if (cstIcms && card.dataset.cstIcmsOriginal) cstIcms.value = card.dataset.cstIcmsOriginal;
            if (cbenef && card.dataset.cbenefOriginal) cbenef.value = card.dataset.cbenefOriginal;
        }
    });
}

function nfeAplicarDadosVenda(data) {
    document.getElementById('nfe_numero_os').value = data.numero_os || '';
    document.getElementById('nfe_venda_id').value = data.venda_id || '';
    document.getElementById('nfe_os_label').textContent = data.os_label || '-';
    document.getElementById('nfe_tipo_operacao_fiscal').value = data.tipo_operacao_fiscal || 'venda';
    document.getElementById('nfe_local_destino').value = data.local_destino || '1';
    const d = data.destinatario || {};
    for (const campo of ['nome','cpf','cnpj','ie','indicador_ie','logradouro','numero','complemento','bairro','municipio','uf','cep','telefone','email']) {
        const el = document.getElementById('nfe_dest_' + campo);
        if (el) el.value = d[campo] || '';
    }
    const destCpf = document.getElementById('nfe_dest_cpf');
    const destCnpj = document.getElementById('nfe_dest_cnpj');
    if (destCnpj && destCnpj.value.replace(/\D/g, '') !== '' && destCpf) {
        destCpf.value = '';
    } else if (destCpf && destCpf.value.replace(/\D/g, '') !== '' && destCnpj) {
        destCnpj.value = '';
    }
    const box = document.getElementById('nfe_items_box');
    box.innerHTML = '';
    (data.items || []).forEach((item, idx) => {
        const div = document.createElement('div');
        div.className = 'nfe-item-card';
        if (String(item.excluir || '0') === '1') {
            div.classList.add('nfe-item-excluido');
            div.style.opacity = '0.45';
            div.style.display = 'none';
        }
        const codigoEsperado = nfeNormalizarCodigo(item.codigo_produto_esperado || item.codigo_produto || '');
        const descricaoEsperada = String(item.descricao_esperada || item.descricao || '').trim();
        const produtoIdOrigem = String(item.produto_id_origem || item.produto_id || '');
        const produtoNomeOrigem = String(item.produto_nome_origem || item.produto_nome || '').trim();
        div.innerHTML = `
            <div class="nfe-item-head">
                <h4>${item.produto_nome || 'Produto'}</h4>
                <button type="button" class="nfe-item-remove" title="Remover este produto da nota" onclick="nfeRemoverItem(this)">✕</button>
            </div>
            <input type="hidden" class="nfe-in-excluir" name="items[${idx}][excluir]" value="${String(item.excluir || '0') === '1' ? '1' : '0'}">
            <input type="hidden" name="items[${idx}][produto_id_origem]" value="${produtoIdOrigem.replaceAll('"', '&quot;')}">
            <input type="hidden" name="items[${idx}][produto_nome_origem]" value="${produtoNomeOrigem.replaceAll('"', '&quot;')}">
            <input type="hidden" class="nfe-in-pis-tributo-editado" name="items[${idx}][pis_tributo_editado]" value="${String(item.pis_tributo_editado || '0') === '1' ? '1' : '0'}">
            <input type="hidden" class="nfe-in-cofins-tributo-editado" name="items[${idx}][cofins_tributo_editado]" value="${String(item.cofins_tributo_editado || '0') === '1' ? '1' : '0'}">
            <div class="modal-grid">
                <div><label>Codigo interno</label><input class="nfe-in-codigo" name="items[${idx}][codigo_produto]" value="${String(item.codigo_produto || '').replaceAll('"', '&quot;')}"><input type="hidden" name="items[${idx}][codigo_produto_esperado]" value="${String(codigoEsperado).replaceAll('"', '&quot;')}"><span class="nfe-codigo-alerta"></span></div>
                <div><label>Descricao</label><input class="nfe-in-descricao" name="items[${idx}][descricao]" value="${String(item.descricao || '').replaceAll('"', '&quot;')}"><input type="hidden" name="items[${idx}][descricao_esperada]" value="${String(descricaoEsperada).replaceAll('"', '&quot;')}"><span class="nfe-descricao-alerta"></span></div>
                <div><label>NCM</label><input name="items[${idx}][codigo_ncm]" value="${item.codigo_ncm || ''}"></div>
                <div><label>CFOP</label><input name="items[${idx}][cfop]" value="${item.cfop || '5102'}"></div>
                <div><label>Quantidade</label><input type="number" step="0.0001" class="nfe-in-qtd" oninput="nfeRecalcItem(this.closest('.nfe-item-card'))" onchange="nfeRecalcItem(this.closest('.nfe-item-card'))" name="items[${idx}][quantidade_comercial]" value="${nfeNumero(item.quantidade, 4)}"></div>
                <div><label>Valor unitario</label><input type="number" step="0.0001" class="nfe-in-vu" oninput="nfeRecalcItem(this.closest('.nfe-item-card'))" onchange="nfeRecalcItem(this.closest('.nfe-item-card'))" name="items[${idx}][valor_unitario_comercial]" value="${nfeNumero(item.valor_unitario, 4)}"></div>
                <div><label>Valor bruto</label><input type="number" step="0.01" class="nfe-in-vb" name="items[${idx}][valor_bruto]" value="${nfeNumero(item.valor_bruto, 2)}"></div>
                <div><label>Unidade</label><input class="nfe-in-unidade" name="items[${idx}][unidade_comercial]" value="${item.unidade || 'KG'}"></div>
                <div><label>CST ICMS</label><input name="items[${idx}][icms_situacao_tributaria]" value="${item.icms_situacao_tributaria || '40'}"></div>
                <div><label>CST PIS</label><input class="nfe-in-cst-pis" name="items[${idx}][pis_situacao_tributaria]" maxlength="2" inputmode="numeric" onchange="nfeAlterarCstPisCofins(this)" value="${item.pis_situacao_tributaria || '06'}"></div>
                <div><label>Base PIS</label><input class="nfe-in-pis-base" name="items[${idx}][pis_base_calculo]" readonly value="${nfeNumero(item.pis_base_calculo || item.valor_bruto || 0, 2)}"></div>
                <div><label>Alíquota PIS (%)</label><input type="number" step="0.0001" min="0" class="nfe-in-pis-aliquota" name="items[${idx}][pis_aliquota_porcentual]" oninput="nfeAlterarAliquotaPisCofins(this)" value="${nfeNumero(item.pis_aliquota_porcentual || 0.65, 4)}"></div>
                <div><label>Valor PIS</label><input type="number" step="0.01" min="0" class="nfe-in-pis-valor" name="items[${idx}][pis_valor]" oninput="nfeAlterarValorPisCofins(this)" value="${item.pis_valor !== undefined && item.pis_valor !== null ? nfeNumero(item.pis_valor, 2) : ''}"></div>
                <div><label>CST COFINS</label><input class="nfe-in-cst-cofins" name="items[${idx}][cofins_situacao_tributaria]" maxlength="2" inputmode="numeric" onchange="nfeAlterarCstPisCofins(this)" value="${item.cofins_situacao_tributaria || '06'}"></div>
                <div><label>Base COFINS</label><input class="nfe-in-cofins-base" name="items[${idx}][cofins_base_calculo]" readonly value="${nfeNumero(item.cofins_base_calculo || item.valor_bruto || 0, 2)}"></div>
                <div><label>Alíquota COFINS (%)</label><input type="number" step="0.0001" min="0" class="nfe-in-cofins-aliquota" name="items[${idx}][cofins_aliquota_porcentual]" oninput="nfeAlterarAliquotaPisCofins(this)" value="${nfeNumero(item.cofins_aliquota_porcentual || 3, 4)}"></div>
                <div><label>Valor COFINS</label><input type="number" step="0.01" min="0" class="nfe-in-cofins-valor" name="items[${idx}][cofins_valor]" oninput="nfeAlterarValorPisCofins(this)" value="${item.cofins_valor !== undefined && item.cofins_valor !== null ? nfeNumero(item.cofins_valor, 2) : ''}"></div>
                <div><label>Total PIS/COFINS</label><div class="nfe-pis-cofins-total" style="padding:10px;margin-top:5px;background:#f3f4f6;border-radius:6px;font-weight:700;">R$ 0,00</div></div>
                <div><label>cBenef</label><input name="items[${idx}][codigo_beneficio_fiscal]" value="${item.codigo_beneficio_fiscal || 'SP010360'}"></div>
                <input type="hidden" class="nfe-hid-qtd" name="items[${idx}][quantidade_tributavel]" value="${nfeNumero(item.quantidade, 4)}">
                <input type="hidden" class="nfe-hid-vu" name="items[${idx}][valor_unitario_tributavel]" value="${nfeNumero(item.valor_unitario, 4)}">
                <input type="hidden" name="items[${idx}][unidade_tributavel]" value="${item.unidade || 'KG'}">
                <input type="hidden" name="items[${idx}][icms_origem]" value="0">
            </div>`;
        div.dataset.pesoKg = Number(item.peso_kg || 0);
        div.dataset.pesoUnitarioKg = Number(item.peso_unitario_kg || 0);
        div.dataset.cfopOriginal = item.cfop || '5102';
        div.dataset.cstIcmsOriginal = item.icms_situacao_tributaria || '40';
        div.dataset.cbenefOriginal = item.codigo_beneficio_fiscal || 'SP010360';
        div.dataset.codigoEsperado = codigoEsperado;
        div.dataset.descricaoEsperada = descricaoEsperada;
        box.appendChild(div);
        const codigoInput = div.querySelector('.nfe-in-codigo');
        if (codigoInput) {
            codigoInput.addEventListener('input', function () { nfeValidarCodigoCard(div); });
            codigoInput.addEventListener('change', function () { nfeValidarCodigoCard(div); });
        }
        const descricaoInput = div.querySelector('.nfe-in-descricao');
        if (descricaoInput) {
            descricaoInput.addEventListener('input', function () { nfeValidarCodigoCard(div); });
            descricaoInput.addEventListener('change', function () { nfeValidarCodigoCard(div); });
        }
        nfeValidarCodigoCard(div);
        nfeBindRecalcItem(div);
        nfeAtualizarPisCofins(div, false, false);
    });
    nfeAtualizarTipoOperacaoFiscalVenda();
    document.getElementById('nfe_tipo_caixa').value = data.tipo_caixa || '';
    document.getElementById('nfe_tipo_caixa_original').value = data.tipo_caixa || '';
    document.getElementById('nfe_quantidade_caixas').value = String(data.quantidade_caixas || '0');
    document.getElementById('nfe_informacoes_adicionais').value = data.informacoes_adicionais_contribuinte || '';
    document.getElementById('nfe_peso_liquido').value = nfeNumero(data.peso_liquido || 0, 3);
    if (data.peso_bruto !== undefined && data.peso_bruto !== null && data.peso_bruto !== '') {
        document.getElementById('nfe_peso_bruto').value = nfeNumero(data.peso_bruto, 3);
    } else {
        nfeAtualizarPesos();
    }
}

function abrirModalNfeVenda(data) {
    const form = document.getElementById('formNfeVenda');
    if (form) form.action = 'actions/emitir_nfe_focus.php';
    const docId = document.getElementById('nfe_reemissao_documento_id');
    if (docId) docId.value = '';
    const btnRascunho = document.getElementById('btn_salvar_rascunho_nfe');
    if (btnRascunho) btnRascunho.style.display = '';
    marcarHistoricoVendaOsAtiva(data.numero_os || '');
    nfeLimparErrosModal();
    nfeMostrarRascunhoVendaMsg('');
    nfeAplicarDadosVenda(data);
    document.getElementById('modalNfeVenda').style.display = 'block';
    carregarRascunhoNfeVenda(data);
}

function abrirModalReemissaoPayload(data, documentoId) {
    nfeLimparErrosModal();
    nfeAplicarDadosVenda(data);
    const form = document.getElementById('formNfeVenda');
    if (form) form.action = 'actions/emitir_reemissao_payload.php';
    const docId = document.getElementById('nfe_reemissao_documento_id');
    if (docId) docId.value = documentoId || '';
    const btnRascunho = document.getElementById('btn_salvar_rascunho_nfe');
    if (btnRascunho) btnRascunho.style.display = 'none';
    nfeMostrarRascunhoVendaMsg('Cópia carregada diretamente do payload enviado à Focus. Confira todos os campos antes de emitir.');
    document.getElementById('modalNfeVenda').style.display = 'block';
}

async function carregarRascunhoNfeVenda(data) {
    const fd = new FormData();
    fd.append('numero_os', data.numero_os || '');
    fd.append('venda_id', data.venda_id || '');
    fd.append('ambiente', 'producao');
    fd.append('_acao', 'carregar');
    try {
        const resp = await fetch('actions/rascunho_nfe_venda.php', {
            method: 'POST',
            body: fd,
            headers: {'Accept': 'application/json'}
        });
        const retorno = await resp.json();
        if (!retorno || !retorno.ok) {
            nfeMostrarRascunhoVendaMsg('Nao foi possivel carregar rascunho: ' + ((retorno && retorno.erro) ? retorno.erro : 'erro desconhecido'), true);
            return;
        }
        nfeAplicarDadosVenda(retorno);
        if (retorno.rascunho_carregado) {
            nfeMostrarRascunhoVendaMsg('Rascunho salvo carregado.');
        }
    } catch (e) {
        nfeMostrarRascunhoVendaMsg('Falha ao carregar rascunho salvo.', true);
    }
}

async function nfeSalvarRascunhoVenda(form) {
    const fd = new FormData(form);
    fd.append('_acao', 'salvar');
    const btn = document.getElementById('btn_salvar_rascunho_nfe');
    const textoOriginal = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Salvando...';
    }
    try {
        const resp = await fetch('actions/rascunho_nfe_venda.php', {
            method: 'POST',
            body: fd,
            headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
        });
        const retorno = await resp.json();
        if (retorno && retorno.ok) {
            if (Array.isArray(retorno.items)) {
                nfeAplicarDadosVenda(retorno);
            }
            nfeMostrarRascunhoVendaMsg(retorno.mensagem || 'Rascunho salvo.');
            return;
        }
        nfeMostrarRascunhoVendaMsg((retorno && retorno.erro) ? retorno.erro : 'Nao foi possivel salvar o rascunho.', true);
    } catch (e) {
        nfeMostrarRascunhoVendaMsg('Falha ao comunicar com o servidor para salvar o rascunho.', true);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = textoOriginal;
        }
    }
}
function fecharModalNfeVenda() { document.getElementById('modalNfeVenda').style.display = 'none'; }
function abrirModalCancelarNfe(data) {
    document.getElementById('cancelar_nfe_documento_id').value = data.id || '';
    document.getElementById('cancelar_nfe_ref_label').textContent = data.ref || '-';
    document.getElementById('cancelar_nfe_numero_label').textContent = data.numero || '-';
    document.getElementById('cancelar_nfe_justificativa').value = '';
    document.getElementById('modalCancelarNfe').style.display = 'block';
}
function fecharModalCancelarNfe() { document.getElementById('modalCancelarNfe').style.display = 'none'; }
function confirmarCancelamentoNfe() {
    const texto = document.getElementById('cancelar_nfe_justificativa').value.trim();
    if (texto.length < 15 || texto.length > 255) {
        alert('A justificativa deve ter entre 15 e 255 caracteres.');
        return false;
    }
    return confirm('Cancelar esta NF-e na SEFAZ pela Focus?');
}
function abrirModalCartaCorrecaoNfe(data) {
    document.getElementById('cce_nfe_documento_id').value = data.id || '';
    document.getElementById('cce_nfe_ref_label').textContent = data.ref || '-';
    document.getElementById('cce_nfe_numero_label').textContent = data.numero || '-';
    document.getElementById('cce_nfe_correcao').value = '';
    document.getElementById('modalCartaCorrecaoNfe').style.display = 'block';
}
function fecharModalCartaCorrecaoNfe() { document.getElementById('modalCartaCorrecaoNfe').style.display = 'none'; }
function confirmarCartaCorrecaoNfe() {
    const texto = document.getElementById('cce_nfe_correcao').value.trim();
    if (texto.length < 15 || texto.length > 1000) {
        alert('A carta de correcao deve ter entre 15 e 1000 caracteres.');
        return false;
    }
    return confirm('Enviar carta de correcao desta NF-e pela Focus?');
}
function toggleHistoricoOs(osKey, tipo) {
    if (tipo !== 'historico-venda') return;
    const primeiraLinha = Array.from(document.querySelectorAll('.historico-venda-item-row[data-os]')).find(function (linha) {
        return linha.dataset.os === String(osKey);
    });
    definirHistoricoVendaOsAberta(osKey, !!primeiraLinha?.classList.contains('hidden'), false);
}
function atualizarCamposTipoHistorico() {
    const tipoEl = document.getElementById('edit_hist_tipo');
    const tipoComercialEl = document.getElementById('edit_hist_tipo_comercial');
    const pedidoEl = document.getElementById('edit_hist_pedido');
    const numCaixasEl = document.getElementById('edit_hist_num_caixas_item');
    const bandejasEl = document.getElementById('edit_hist_bandejas_por_caixa_item');
    const tipoComercial = tipoComercialEl ? tipoComercialEl.value : '';
    const ehOba = tipoComercial === 'oba_embalado';

    if (ehOba && tipoEl) {
        tipoEl.value = 'bandeja';
    }

    const tipo = tipoEl ? tipoEl.value : 'kg';
    document.getElementById('edit_hist_gramagem_bloco').style.display = tipo === 'bandeja' ? '' : 'none';
    document.getElementById('edit_hist_kg_caixa_bloco').style.display = (!ehOba && tipo === 'caixa') ? '' : 'none';
    document.getElementById('edit_hist_oba_bloco').style.display = ehOba ? '' : 'none';
    document.getElementById('edit_hist_pedido_bloco').style.display = ehOba ? 'none' : '';

    if (pedidoEl) pedidoEl.required = !ehOba;
    if (numCaixasEl) numCaixasEl.required = ehOba;
    if (bandejasEl) bandejasEl.required = ehOba;

    document.getElementById('edit_hist_pedido_label').textContent = tipo === 'kg' ? 'Quantidade em kg' : (tipo === 'caixa' ? 'Quantidade de caixas' : (tipo === 'bandeja' ? 'Quantidade de bandejas' : 'Quantidade de unidades'));
}

function alterarTipoHistorico() {
    const tipoEl = document.getElementById('edit_hist_tipo');
    const tipoComercialEl = document.getElementById('edit_hist_tipo_comercial');
    if (tipoEl?.value === 'unidade' && ['embalado', 'oba_embalado'].includes(tipoComercialEl?.value)) {
        tipoComercialEl.value = '';
    }
    atualizarCamposTipoHistorico();
}

document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('click', function (event) {
        const cabecalho = event.target.closest('.os-group-header[data-os]');
        const item = event.target.closest('.historico-venda-item-row[data-os]');
        const linha = cabecalho || item;
        if (linha && !event.target.closest('.btn-toggle-os')) marcarHistoricoVendaOsAtiva(linha.dataset.os);
    });
    const osSalva = sessionStorage.getItem(HISTORICO_VENDAS_OS_ABERTA_KEY);
    if (osSalva && !definirHistoricoVendaOsAberta(osSalva, true, true)) {
        sessionStorage.removeItem(HISTORICO_VENDAS_OS_ABERTA_KEY);
    }
    if (window.NFE_CONSULTA_REF) consultarSituacaoNfe(false);
});

let nfeConsultaTentativas = 0;
let nfeConsultaTimer = null;
async function consultarSituacaoNfe(manual) {
    const ref = window.NFE_CONSULTA_REF || '';
    if (!ref) return;
    const botao = document.getElementById('btn_atualizar_nfe');
    const texto = document.getElementById('nfe_consulta_texto');
    if (manual) nfeConsultaTentativas = 0;
    if (botao) botao.disabled = true;
    if (texto) texto.textContent = 'Consultando situacao da NF-e...';
    try {
        const resposta = await fetch('../api/focus_consultar_nfe.php?ref=' + encodeURIComponent(ref) + '&ambiente=producao', {headers:{'Accept':'application/json'}, cache:'no-store'});
        const dados = await resposta.json();
        const focus = dados && dados.response && typeof dados.response === 'object' ? dados.response : {};
        const status = String(focus.status || '').toLowerCase();
        const mensagem = String(focus.mensagem_sefaz || focus.mensagem || focus.erro || dados.erro || '');
        if (status.includes('autoriz')) {
            const emailXml = dados && dados.email_xml && typeof dados.email_xml === 'object' ? dados.email_xml : null;
            let destino = 'historico_vendas.php?msg=nfe_enviada&ref=' + encodeURIComponent(ref);
            if (emailXml) {
                destino += '&email_xml=' + encodeURIComponent(String(emailXml.status || ''))
                    + '&email_msg=' + encodeURIComponent(String(emailXml.mensagem || ''));
            }
            location.href = destino;
            return;
        }
        if (status.includes('cancel')) {
            location.href = 'historico_vendas.php?msg=nfe_cancelada&ref=' + encodeURIComponent(ref);
            return;
        }
        if (status.includes('erro') || status.includes('reje') || status.includes('deneg')) {
            location.href = 'historico_vendas.php?msg=erro&detalhe=' + encodeURIComponent(mensagem || 'NF-e rejeitada pela SEFAZ.') + '&ref=' + encodeURIComponent(ref);
            return;
        }
        nfeConsultaTentativas++;
        if (nfeConsultaTentativas < 20) {
            if (texto) texto.textContent = 'NF-e em processamento. Nova consulta em 3 segundos...';
            nfeConsultaTimer = setTimeout(function(){ consultarSituacaoNfe(false); }, 3000);
        } else {
            if (texto) texto.textContent = 'A NF-e continua em processamento. Atualize a situacao manualmente.';
            if (botao) { botao.style.display = 'inline-flex'; botao.disabled = false; }
        }
    } catch (erro) {
        nfeConsultaTentativas++;
        if (nfeConsultaTentativas < 20) nfeConsultaTimer = setTimeout(function(){ consultarSituacaoNfe(false); }, 3000);
        else if (botao) { botao.style.display = 'inline-flex'; botao.disabled = false; }
        if (texto) texto.textContent = 'Nao foi possivel atualizar agora. A emissao nao sera repetida.';
    } finally {
        if (botao && nfeConsultaTentativas < 20) botao.disabled = false;
    }
}
</script>
</head>
<body>
<?php renderAppHeader('..'); ?>
<div class="container page-container app-shell">
<?php if (($_GET['msg'] ?? '') === 'valor_atualizado'): ?><div class="msg-sucesso">Valor final atualizado com sucesso.</div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'venda_excluida'): ?><div class="msg-sucesso">Venda excluida do sistema com sucesso.</div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'venda_cancelada'): ?><div class="msg-sucesso">Venda cancelada, estoque devolvido e reversao financeira registrada com sucesso.</div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'nfe_enviada'): ?><div class="msg-sucesso">NF-e autorizada/recebida pela Focus com sucesso. Ref: <?= htmlspecialchars($_GET['ref'] ?? '-') ?></div><?php endif; ?>
<?php if (($_GET['email_xml'] ?? '') === 'enviado'): ?><div class="msg-sucesso"><?= htmlspecialchars($_GET['email_msg'] ?? 'XML enviado por e-mail com sucesso.') ?></div><?php endif; ?>
<?php if (($_GET['email_xml'] ?? '') === 'aguardando_xml'): ?><div class="msg-sucesso"><span id="nfe_consulta_texto"><?= htmlspecialchars($_GET['email_msg'] ?? 'NF-e autorizada; aguardando o XML para envio por e-mail.') ?></span></div><script>window.NFE_CONSULTA_REF=<?= json_encode((string)($_GET['ref'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;</script><?php endif; ?>
<?php if (($_GET['email_xml'] ?? '') === 'falhou'): ?><div class="msg-erro"><?= htmlspecialchars($_GET['email_msg'] ?? 'A NF-e foi emitida, mas o XML nao foi enviado por e-mail.') ?></div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'nfe_processando'): ?><div class="msg-sucesso"><span id="nfe_consulta_texto">NF-e aceita pela Focus e em processamento. Consultando situacao...</span> Ref: <?= htmlspecialchars($_GET['ref'] ?? '-') ?> <button type="button" class="btn btn-secondary" id="btn_atualizar_nfe" style="display:none;margin-left:8px" onclick="consultarSituacaoNfe(true)"><i class="bi bi-arrow-clockwise"></i> Atualizar situacao</button></div><script>window.NFE_CONSULTA_REF=<?= json_encode((string)($_GET['ref'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;</script><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'nfe_cancelada'): ?><div class="msg-sucesso">NF-e cancelada com sucesso na Focus/SEFAZ. Ref: <?= htmlspecialchars($_GET['ref'] ?? '-') ?></div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'nfe_cce_enviada'): ?><div class="msg-sucesso">Carta de correcao enviada com sucesso pela Focus/SEFAZ. Ref: <?= htmlspecialchars($_GET['ref'] ?? '-') ?></div><?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'erro'): ?><div class="msg-erro"><?= htmlspecialchars($_GET['detalhe'] ?? 'Erro ao processar.') ?></div><?php endif; ?>
<div class="card">
<h2>Histórico de Vendas</h2>
<form method="GET" class="filters">
<div><label>Busca</label><input name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="OS, produto ou cliente"></div>
<div><label>Data início</label><input type="date" name="data_ini" value="<?= htmlspecialchars($dataIni) ?>"></div>
<div><label>Data fim</label><input type="date" name="data_fim" value="<?= htmlspecialchars($dataFim) ?>"></div>
<button class="btn" type="submit"><i class="bi bi-search"></i></button>
</form>
</div>
<?php if ($reemissoesCovabra): ?>
<div class="card">
<h2>Reemissões Covabra — NF-e 10945 a 10962</h2>
<p>Cópias fiéis dos payloads efetivamente autorizados, prontas para conferência e nova emissão.</p>
<div class="table-container"><table data-no-responsive="1"><thead><tr><th>NF-e fonte</th><th>Destinatário</th><th>Itens</th><th>Valor</th><th>Status</th><th>Ação</th></tr></thead><tbody>
<?php foreach($reemissoesCovabra as $re): ?><tr><td><?= htmlspecialchars((string)$re['numero_original']) ?></td><td><?= htmlspecialchars($re['destinatario']) ?></td><td><?= (int)$re['itens'] ?></td><td>R$ <?= number_format((float)$re['valor'],2,',','.') ?></td><td><?= htmlspecialchars((string)$re['status']) ?></td><td>
<?php if(in_array((string)$re['status'],['rascunho','rejeitada','erro'],true)): ?><button class="btn btn-nfe" type="button" title="Abrir rascunho para conferir" onclick="abrirModalReemissaoPayload(<?= htmlspecialchars(json_encode($re['modal_data'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),ENT_QUOTES,'UTF-8') ?>,<?= (int)$re['id'] ?>)"><i class="bi bi-eye"></i> Conferir</button><?php else: ?><?= htmlspecialchars((string)$re['ref']) ?><?php endif; ?>
</td></tr><?php endforeach; ?></tbody></table></div>
</div>
<?php endif; ?>
<div class="card">
<div class="table-container"><table data-no-responsive="1">
<thead><tr><th style="width:44px;"></th><th>Data</th><th>OS</th><th>Cliente</th><th>Itens</th><th>Quantidade</th><th>Valor final</th><th>Documentos</th><th>Ação</th></tr></thead>
<tbody>
<?php if (count($vendasRows) === 0): ?><tr><td colspan="9">Nenhuma venda confirmada encontrada.</td></tr><?php endif; ?>
<?php foreach ($vendasPorOs as $osKeyRaw => $itensOs): ?>
<?php
$primeiraVenda = $itensOs[0];
$os = trim((string) ($primeiraVenda['numero_os'] ?? ''));
$osLabel = $os !== '' ? $os : 'VENDA-' . (int) $primeiraVenda['id'];
$osKey = htmlspecialchars($osKeyRaw, ENT_QUOTES, 'UTF-8');
$osEscape = htmlspecialchars(addslashes($osKeyRaw), ENT_QUOTES, 'UTF-8');
$docParam = $os !== '' ? 'numero_os=' . rawurlencode($os) : 'venda_id=' . (int) $primeiraVenda['id'];
$printParam = $os !== '' ? 'numero_os=' . rawurlencode($os) : 'id=' . (int) $primeiraVenda['id'];
$totalKgOs = array_sum(array_map('calcularQuantidadeFinalVenda', $itensOs));
$temUnidadeOs = false;
$temPesoOs = false;
foreach ($itensOs as $itemQuantidadeOs) {
    if (normalizarTipoVenda($itemQuantidadeOs['tipo'] ?? null) === 'unidade') {
        $temUnidadeOs = true;
    } else {
        $temPesoOs = true;
    }
}
$quantidadeOsTexto = $temUnidadeOs && $temPesoOs
    ? 'Mista'
    : number_format($totalKgOs, 2, ',', '.') . ($temUnidadeOs ? ' un.' : ' kg');
$valorFinalOs = 0.0;
$temValorEditadoOs = false;
foreach ($itensOs as $vendaItemValor) {
    $valorOriginalItem = calcularPrecoTotalVenda($vendaItemValor);
    $valorFinalOs += $vendaItemValor['valor_final_editado'] !== null ? (float) $vendaItemValor['valor_final_editado'] : $valorOriginalItem;
    $temValorEditadoOs = $temValorEditadoOs || $vendaItemValor['valor_final_editado'] !== null;
}
$ufNfeVenda = strtoupper(trim((string) ($primeiraVenda['nfe_estado'] ?? 'SP')));
$foraSpNfeVenda = $ufNfeVenda !== '' && $ufNfeVenda !== 'SP';
$itensNfeVendaOrdenados = $itensOs;
usort($itensNfeVendaOrdenados, static fn($a, $b) => (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
$itemsNfeVenda = [];
foreach ($itensNfeVendaOrdenados as $itemNfeVenda) {
    $quantidadeNfe = calcularQuantidadeFinalVenda($itemNfeVenda);
    // Valor total do item: usa o valor final editado (se houver) ou o total calculado conforme o tipo
    // (bandeja/caixa = preco x pedido; kg = preco x quantidade), mesmo criterio da lista e da venda.
    $valorTotalNfe = $itemNfeVenda['valor_final_editado'] !== null ? (float) $itemNfeVenda['valor_final_editado'] : calcularPrecoTotalVenda($itemNfeVenda);
    $tipoProdutoNfe = mb_strtolower((string) ($itemNfeVenda['produto_nfe_tipo'] ?? ''), 'UTF-8');
    $cfopNfe = str_contains($tipoProdutoNfe, 'produc') ? ($foraSpNfeVenda ? '6101' : '5101') : ($foraSpNfeVenda ? '6102' : '5102');

    // Unidade/quantidade/descricao por tipo comercial:
    //  - oba_embalado: CX, quantidade = numero de caixas, descricao "<nome> CX C/<bandejas por caixa>";
    //  - embalado: BD (bandeja), quantidade em bandejas, gramagem na descricao;
    //  - atacado/atacado_convencional: KG; demais: unidade do cadastro.
    $tipoComercialItemNfe = strtolower(trim((string) ($itemNfeVenda['tipo_comercial'] ?? '')));
    $ehObaItemNfe = ($tipoComercialItemNfe === 'oba_embalado');
    $tipoItemNfe = strtolower(trim((string) ($itemNfeVenda['tipo'] ?? '')));
    $gramagemItemNfe = (float) ($itemNfeVenda['gramagem'] ?? 0);
    // Trata como embalado tambem vendas em bandeja com gramagem mesmo sem tipo_comercial preenchido.
    $ehEmbaladoItemNfe = ($tipoComercialItemNfe === 'embalado') || (!$ehObaItemNfe && $tipoItemNfe === 'bandeja' && $gramagemItemNfe > 0);
    $nfeDescItemNfe = (string) ($itemNfeVenda['produto_nfe_descricao'] ?? '');
    $nomeItemNfe = (string) ($itemNfeVenda['produto_nome'] ?? '');
    if ($ehObaItemNfe) {
        $unidadeItemNfe = 'CX';
        $numCaixasItemNfe = (int) ($itemNfeVenda['num_caixas'] ?? 0);
        $bandejasPorCaixaItemNfe = (int) ($itemNfeVenda['bandejas_por_caixa'] ?? 0);
        $quantidadeNfe = $numCaixasItemNfe > 0 ? (float) $numCaixasItemNfe : 1.0;
        $descricaoItemNfe = obaDescricaoNfe($nfeDescItemNfe !== '' ? $nfeDescItemNfe : $nomeItemNfe, $bandejasPorCaixaItemNfe);
    } elseif ($ehEmbaladoItemNfe) {
        $unidadeItemNfe = 'BD';
        if ($gramagemItemNfe > 0) {
            // converte a quantidade de KG para numero de bandejas (gramagem em gramas), mantendo o valor total da linha.
            $quantidadeNfe = $quantidadeNfe * 1000 / $gramagemItemNfe;
            $descricaoItemNfe = descricaoNfeProdutoSelecionado($nfeDescItemNfe, $nomeItemNfe, $gramagemItemNfe);
        } else {
            $descricaoItemNfe = descricaoNfeProdutoSelecionado($nfeDescItemNfe, $nomeItemNfe, 0);
        }
    } elseif (in_array($tipoItemNfe, ['caixa', 'unidade'], true)) {
        // Venda em caixa/unidade: conserva integralmente a descricao fiscal,
        // inclusive sufixos legitimos como "CX C/10" ou pesos da embalagem.
        $unidadeItemNfe = strtoupper((string) ($itemNfeVenda['produto_unidade'] ?: 'CX'));
        $descricaoItemNfe = trim($nfeDescItemNfe !== '' ? $nfeDescItemNfe : $nomeItemNfe);
    } else {
        $unidadeItemNfe = in_array($tipoComercialItemNfe, ['atacado', 'atacado_convencional'], true)
            ? 'KG'
            : strtoupper((string) ($itemNfeVenda['produto_unidade'] ?: 'KG'));
        // Nao-embalado: remove qualquer gramagem embutida na descricao/nome.
        $descricaoItemNfe = formatarGramagemEmbalado($nfeDescItemNfe !== '' ? $nfeDescItemNfe : $nomeItemNfe, 0);
    }
    $valorUnitarioNfe = $quantidadeNfe > 0 ? $valorTotalNfe / $quantidadeNfe : 0.0;
    $pesoKgItemNfe = calcularQuantidadeFinalVenda($itemNfeVenda);
    $pesoUnitarioKgItemNfe = $quantidadeNfe > 0 ? $pesoKgItemNfe / $quantidadeNfe : 0.0;
    $codigoProdutoNfe = trim((string) (($itemNfeVenda['produto_codigo_interno'] ?? '') ?: ($itemNfeVenda['produto_nfe_codigo_interno'] ?? '') ?: ($itemNfeVenda['produto_codigo_barras'] ?? '') ?: ($itemNfeVenda['produto_id'] ?? '')));
    $itemsNfeVenda[] = [
        'produto_id' => (int) ($itemNfeVenda['produto_id'] ?? 0),
        'produto_nome' => $itemNfeVenda['produto_nome'] ?? '',
        'codigo_produto_esperado' => $codigoProdutoNfe !== '' ? $codigoProdutoNfe : (string) ($itemNfeVenda['produto_id'] ?? ''),
        'descricao_esperada' => $descricaoItemNfe,
        'codigo_produto' => $codigoProdutoNfe !== '' ? $codigoProdutoNfe : (string) ($itemNfeVenda['produto_id'] ?? ''),
        'descricao' => $descricaoItemNfe,
        'codigo_ncm' => preg_replace('/\D/', '', (string) (($itemNfeVenda['produto_ncm'] ?? '') ?: ($itemNfeVenda['produto_nfe_ncm'] ?? ''))),
        'cfop' => $cfopNfe,
        'quantidade' => $quantidadeNfe,
        'valor_unitario' => $valorUnitarioNfe,
        'valor_bruto' => $valorTotalNfe,
        'unidade' => $unidadeItemNfe,
        'pis_situacao_tributaria' => (string) ($itemNfeVenda['produto_nfe_cst_pis'] ?? '06'),
        'cofins_situacao_tributaria' => (string) ($itemNfeVenda['produto_nfe_cst_cofins'] ?? '06'),
        'peso_kg' => $pesoKgItemNfe,
        'peso_unitario_kg' => $pesoUnitarioKgItemNfe,
    ];
}
$nfeVendaData = [
    'numero_os' => $os,
    'venda_id' => (int) $primeiraVenda['id'],
    'os_label' => $osLabel,
    'peso_liquido' => $totalKgOs,
    'local_destino' => $foraSpNfeVenda ? '2' : '1',
    'destinatario' => [
        'nome' => (string) ($primeiraVenda['nfe_nome_razao_social'] ?: $primeiraVenda['cliente_nome'] ?? ''),
        'cpf' => preg_replace('/\D/', '', (string) ($primeiraVenda['nfe_cpf'] ?? '')),
        'cnpj' => preg_replace('/\D/', '', (string) ($primeiraVenda['nfe_cnpj'] ?: $primeiraVenda['cliente_documento'] ?? '')),
        'ie' => preg_replace('/\D/', '', (string) ($primeiraVenda['nfe_ie'] ?? '')),
        'indicador_ie' => (string) ($primeiraVenda['nfe_ie'] ? '1' : '9'),
        'logradouro' => (string) ($primeiraVenda['nfe_endereco'] ?: $primeiraVenda['cliente_endereco'] ?? ''),
        'numero' => (string) ($primeiraVenda['nfe_numero'] ?: 'S/N'),
        'complemento' => (string) ($primeiraVenda['nfe_complemento'] ?? ''),
        'bairro' => (string) ($primeiraVenda['nfe_bairro'] ?? ''),
        'municipio' => (string) ($primeiraVenda['nfe_cidade'] ?? ''),
        'uf' => (string) ($primeiraVenda['nfe_estado'] ?: 'SP'),
        'cep' => preg_replace('/\D/', '', (string) ($primeiraVenda['nfe_cep'] ?? '')),
        'telefone' => preg_replace('/\D/', '', (string) ($primeiraVenda['nfe_telefone'] ?: $primeiraVenda['cliente_telefone'] ?? '')),
        'email' => (string) ($primeiraVenda['nfe_email'] ?? ''),
    ],
    'items' => $itemsNfeVenda,
];
$docsNfeVendaOs = $os !== '' ? ($nfeDocsVendaPorOs[$os] ?? []) : [];
$statusNfeVendaAtual = strtolower((string) ($docsNfeVendaOs[0]['status'] ?? ''));
$classeNfeVenda = in_array($statusNfeVendaAtual, ['autorizada'], true) ? 'ok' : (in_array($statusNfeVendaAtual, ['processando','enviada'], true) ? 'pend' : (in_array($statusNfeVendaAtual, ['rejeitada'], true) ? 'erro' : (in_array($statusNfeVendaAtual, ['cancelada'], true) ? 'cancel' : 'pend')));
$rotuloNfeVenda = $statusNfeVendaAtual === 'autorizada' ? 'NF-e autorizada' : (in_array($statusNfeVendaAtual, ['processando','enviada'], true) ? 'NF-e processando' : ($statusNfeVendaAtual === 'rejeitada' ? 'NF-e rejeitada' : ($statusNfeVendaAtual === 'cancelada' ? 'NF-e cancelada' : 'Sem NF-e')));
// A OS conta como "emitida" quando tem NF-e autorizada (mesma regra do botao Cancelar).
$osTemNfeAutorizada = false;
foreach ($docsNfeVendaOs as $__docNfe) {
    if (($__docNfe['status'] ?? '') === 'autorizada') { $osTemNfeAutorizada = true; break; }
}
?>
<tr class="os-group-header" data-os="<?= $osKey ?>">
<td><button type="button" class="btn-toggle-os" data-historico-venda-os="<?= $osKey ?>" onclick="toggleHistoricoOs('<?= $osEscape ?>', 'historico-venda')">▼</button></td>
<td><?= !empty($primeiraVenda['data_venda']) ? date('d/m/Y', strtotime($primeiraVenda['data_venda'])) : '-' ?></td>
	<td><?= htmlspecialchars($osLabel) ?></td>
<td><?= htmlspecialchars($primeiraVenda['cliente_nome'] ?? '-') ?></td>
<td><?= count($itensOs) ?></td>
<td><?= htmlspecialchars($quantidadeOsTexto) ?></td>
<td>R$ <?= number_format($valorFinalOs, 2, ',', '.') ?><?php if ($temValorEditadoOs): ?><span class="alterado">alterado</span><?php endif; ?></td>
	<?php $resumoNfeVenda = historicoVendaNfeResumo($docsNfeVendaOs); $resumoBoletoVenda = historicoVendaBoletoStatus($itensOs, $boletosVendaPorOs, $boletosVendaPorNfeId, $os, $resumoNfeVenda['doc_autorizada_id']); ?>
	<td><div class="doc-status-stack"><span class="badge-nfe <?= $resumoNfeVenda['classe'] ?>"><?= htmlspecialchars($resumoNfeVenda['rotulo']) ?></span><span class="badge-nfe <?= $resumoBoletoVenda['classe'] ?>"><?= htmlspecialchars($resumoBoletoVenda['rotulo']) ?></span></div><div class="acoes" style="margin-top:8px"><a class="btn btn-doc" download title="Baixar Pedido" href="gerar_pedido_venda.php?<?= $docParam ?>"><i class="bi bi-file-earmark-text"></i></a><a class="btn btn-nfe" download title="Exportar NF-e XML" href="../api/exportar_nfe.php?<?= $docParam ?>&tipo=venda"><i class="bi bi-file-earmark-code"></i></a><?php if (!$osTemNfeAutorizada): ?><button class="btn btn-nfe" type="button" title="Revisar e emitir NF-e Focus" onclick="abrirModalNfeVenda(<?= htmlspecialchars(json_encode($nfeVendaData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>)"><i class="bi bi-receipt"></i></button><?php endif; ?><?php foreach ($docsNfeVendaOs as $docNfeVenda): ?><?php if (($docNfeVenda['status'] ?? '') === 'autorizada'): ?><?php $cancelarNfeData = ['id' => (int) $docNfeVenda['id'], 'ref' => (string) $docNfeVenda['ref'], 'numero' => trim((string) ($docNfeVenda['numero_nfe'] ?? '')) !== '' ? ('NF-e ' . $docNfeVenda['numero_nfe'] . '/' . ($docNfeVenda['serie'] ?: '1')) : (string) $docNfeVenda['ref']]; $cceNfeData = $cancelarNfeData; ?><button class="btn btn-nfe" type="button" title="Carta de correcao Venda" onclick="abrirModalCartaCorrecaoNfe(<?= htmlspecialchars(json_encode($cceNfeData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>)"><i class="bi bi-pencil-square"></i></button><button class="btn btn-danger" type="button" title="Cancelar NF-e autorizada" onclick="abrirModalCancelarNfe(<?= htmlspecialchars(json_encode($cancelarNfeData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>)"><i class="bi bi-x-octagon"></i></button><?php elseif (in_array(($docNfeVenda['status'] ?? ''), ['enviada','processando'], true)): ?><a class="btn btn-secondary" title="Atualizar situacao da NF-e" href="historico_vendas.php?msg=nfe_processando&ref=<?= rawurlencode((string)$docNfeVenda['ref']) ?>"><i class="bi bi-arrow-clockwise"></i></a><?php elseif (($docNfeVenda['status'] ?? '') === 'cancelada'): ?><button class="btn btn-secondary" type="button" title="NF-e ja cancelada"><i class="bi bi-x-octagon"></i></button><?php endif; ?><?php endforeach; ?></div></td>
<td>
<?php if ($podeEditarValor):
    $jsItensOs = [];
    foreach ($itensOs as $_jsIt) {
        $jsItensOs[] = [
            'id'                  => (int) $_jsIt['id'],
            'produto_id'          => (int) ($_jsIt['produto_id'] ?? 0),
            'produto_nome'        => (string) ($_jsIt['produto_nome'] ?? ''),
            'tipo'                => (string) ($_jsIt['tipo'] ?? 'kg'),
            'gramagem'            => (float) ($_jsIt['gramagem'] ?? 0),
            'kg_caixa'            => (float) ($_jsIt['kg_caixa'] ?? 0),
            'pedido'              => (float) ($_jsIt['pedido'] ?? 0),
            'preco'               => (float) ($_jsIt['preco'] ?? 0),
            'valor_final_editado' => $_jsIt['valor_final_editado'] !== null
                                        ? (float) $_jsIt['valor_final_editado']
                                        : null,
        ];
    }
    $jsOsData = json_encode([
        'os'              => $osKeyRaw,
        'cliente_id'      => (int)($primeiraVenda['cliente_id'] ?? 0),
        'previsao'        => (string)($primeiraVenda['previsao_entrega'] ?? $primeiraVenda['data_venda'] ?? ''),
        'data_venda'      => (string)($primeiraVenda['data_venda'] ?? ''),
        'vendedor'        => (string)($primeiraVenda['vendedor'] ?? ''),
        'prazo_pagamento' => (string)($primeiraVenda['prazo_pagamento'] ?? ''),
        'forma_pagamento' => (string)($primeiraVenda['forma_pagamento'] ?? ''),
        'caixa_id'        => (int)($primeiraVenda['caixa_id'] ?? 0),
        'num_caixas'      => (int)($primeiraVenda['num_caixas'] ?? 0),
        'cliente'         => (string)($primeiraVenda['cliente_nome'] ?? '-'),
        'valor'           => $valorFinalOs,
        'itens'           => $jsItensOs,
    ]);
    $osEscJs = htmlspecialchars(json_encode($osKeyRaw), ENT_QUOTES);
    $clienteEscJs = htmlspecialchars(json_encode((string)($primeiraVenda['cliente_nome'] ?? '-')), ENT_QUOTES);
?>
<div class="acoes">
  <button class="btn btn-edit" type="button" title="Editar OS" onclick="abrirModalEditarOs(<?= htmlspecialchars($jsOsData, ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
  <form method="POST" action="actions/duplicar_os_historico.php" style="margin:0;" onsubmit="return confirm('Copiar a OS <?= htmlspecialchars($osLabel, ENT_QUOTES) ?> para uma nova venda pendente? O sistema usara o proximo numero global de OS.');">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
    <input type="hidden" name="numero_os" value="<?= htmlspecialchars($osKeyRaw, ENT_QUOTES) ?>">
    <button class="btn btn-secondary" type="submit" title="Copiar para vendas pendentes"><i class="bi bi-copy"></i></button>
  </form>
  <button class="btn btn-warning" type="button" title="Cancelar OS" onclick="abrirModalCancelarOs(<?= $osEscJs ?>, <?= $clienteEscJs ?>, <?= htmlspecialchars((string)$valorFinalOs) ?>)"><i class="bi bi-arrow-counterclockwise"></i></button>
  <form method="POST" action="actions/excluir_venda_historico.php" style="margin:0;" onsubmit="return confirm('Excluir TODA a OS <?= htmlspecialchars($osLabel, ENT_QUOTES) ?> sem reversao financeira? Esta acao e irreversivel.');">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
    <input type="hidden" name="numero_os" value="<?= htmlspecialchars($osKeyRaw) ?>">
    <button class="btn btn-danger" type="submit" title="Excluir OS inteira"><i class="bi bi-trash"></i></button>
  </form>
</div>
<?php endif; ?>
</td>
</tr>
<?php foreach ($itensOs as $v): ?>
<?php
$valorOriginal = calcularPrecoTotalVenda($v);
$valorFinal = $v['valor_final_editado'] !== null ? (float) $v['valor_final_editado'] : $valorOriginal;
$tipoQuantidadeItem = normalizarTipoVenda($v['tipo'] ?? null);
$sufixoQuantidadeItem = $tipoQuantidadeItem === 'unidade' ? ' un.' : ' kg';
?>
<tr class="historico-venda-item-row hidden" data-os="<?= $osKey ?>">
<td></td>
<td><?= !empty($v['data_venda']) ? date('d/m/Y', strtotime($v['data_venda'])) : '-' ?></td>
<td></td>
<td><?= htmlspecialchars($v['produto_nome'] ?? '-') ?></td>
<td><?= htmlspecialchars($v['cliente_nome'] ?? '-') ?></td>
<td><?= number_format(calcularQuantidadeFinalVenda($v), 2, ',', '.') . $sufixoQuantidadeItem ?></td>
<td>R$ <?= number_format($valorFinal, 2, ',', '.') ?><?php if ($v['valor_final_editado'] !== null): ?><span class="alterado">alterado</span><?php endif; ?></td>
<td></td>
<td>
<?php if ($podeEditarValor):
    $jsData = json_encode([
        'id'         => (int)$v['id'],
        'os'         => $osKeyRaw,
        'produto_id' => (int)($v['produto_id'] ?? 0),
        'tipo_comercial' => (string)($v['tipo_comercial'] ?? ''),
        'tipo'       => $tipoQuantidadeItem,
        'pedido'     => (float)($v['pedido'] ?? 0),
        'preco'      => (float)($v['preco'] ?? 0),
        'gramagem'   => (float)($v['gramagem'] ?? 0),
        'kg_caixa'   => (float)($v['kg_caixa'] ?? 0),
        'num_caixas' => (int)($v['num_caixas'] ?? 0),
        'bandejas_por_caixa' => (int)($v['bandejas_por_caixa'] ?? 0),
    ]);
?>
<div class="acoes">
  <button class="btn btn-edit" type="button" title="Editar produto" onclick="abrirModalEditarHistorico(<?= htmlspecialchars($jsData, ENT_QUOTES) ?>)"><i class="bi bi-pencil"></i></button>
  <form method="POST" action="actions/excluir_venda_historico.php" style="margin:0;" onsubmit="return confirm('Excluir este produto da OS sem reversao financeira?');">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
    <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
    <button class="btn btn-danger" type="submit" title="Excluir produto"><i class="bi bi-trash"></i></button>
  </form>
</div>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php endforeach; ?>
</tbody>
</table></div>
</div>
</div>
<div id="modalEditarHistorico" class="modal">
  <div class="modal-box" style="max-width:400px;">
    <h3 style="margin:0 0 16px 0;">Editar produto</h3>
    <form method="POST" action="actions/editar_venda_historico.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
      <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($redirectHistoricoVenda, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="id" id="edit_hist_id">
      <div>
        <label>Produto</label>
        <select name="produto_id" id="edit_hist_produto_id" required style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;margin-top:5px;">
          <?php foreach ($listaProdutos as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="margin-top:12px;"><label>Tipo comercial</label>
        <select name="tipo_comercial" id="edit_hist_tipo_comercial" onchange="atualizarCamposTipoHistorico()" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;margin-top:5px;">
          <option value="">Manual / sem tabela</option>
          <option value="atacado">Atacado</option>
          <option value="atacado_convencional">Atacado Convencional</option>
          <option value="embalado">Embalado</option>
          <option value="oba_embalado">OBA Embalado</option>
          <option value="shopper">Shopper</option>
        </select>
      </div>
      <div style="margin-top:12px;"><label>Tipo</label>
        <select name="tipo" id="edit_hist_tipo" onchange="alterarTipoHistorico()" required style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;margin-top:5px;"><option value="kg">Kg</option><option value="bandeja">Bandeja</option><option value="caixa">Caixa</option><option value="unidade">Unidade</option></select>
      </div>
      <div id="edit_hist_pedido_bloco" style="margin-top:12px;"><label id="edit_hist_pedido_label">Quantidade</label><input type="number" step="0.01" min="0.01" name="pedido" id="edit_hist_pedido" required></div>
      <div id="edit_hist_oba_bloco" style="margin-top:12px;display:none;">
        <div>
          <label>Nº de caixas</label>
          <input type="number" step="1" min="1" name="num_caixas_item" id="edit_hist_num_caixas_item">
        </div>
        <div style="margin-top:12px;">
          <label>Bandejas por caixa</label>
          <select name="bandejas_por_caixa_item" id="edit_hist_bandejas_por_caixa_item" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;margin-top:5px;">
            <option value="">Selecione</option>
            <option value="6">6/C</option>
            <option value="15">15/C</option>
            <option value="18">18/C</option>
          </select>
        </div>
      </div>
      <div id="edit_hist_gramagem_bloco" style="margin-top:12px;"><label>Gramagem por bandeja (g)</label><input type="number" step="0.01" min="0.01" name="gramagem" id="edit_hist_gramagem"></div>
      <div id="edit_hist_kg_caixa_bloco" style="margin-top:12px;"><label>Kg por caixa</label><input type="number" step="0.01" min="0.01" name="kg_caixa" id="edit_hist_kg_caixa"></div>
      <div style="margin-top:12px;">
        <label>Preco unitario</label>
        <input type="number" step="0.01" min="0" name="preco" id="edit_hist_preco" required>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
        <button type="button" class="btn btn-secondary" onclick="fecharModalEditarHistorico()"><i class="bi bi-x-lg"></i></button>
        <button class="btn" type="submit"><i class="bi bi-check-lg"></i> Salvar</button>
      </div>
    </form>
  </div>
</div>
<!-- Modal: Editar OS inteira — todos os campos -->
<div id="modalEditarOs" class="modal">
  <div class="modal-box-wide">
    <h3 style="margin:0 0 16px 0;">Editar OS<span id="edit_os_numero_label" style="color:#2e7d32;font-weight:400;"></span></h3>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
    <input type="hidden" id="edit_os_numero">
    <input type="hidden" id="edit_os_caixa_id">
    <input type="hidden" id="edit_os_num_caixas" value="0">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
      <div>
        <label>Cliente</label>
        <select id="edit_os_cliente_id" style="width:100%;padding:6px 8px;border:1px solid #ccc;border-radius:6px;margin-top:5px;">
          <?php foreach ($listaClientes as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Vendedor</label>
        <input type="text" id="edit_os_vendedor" placeholder="Nome do vendedor" style="margin-top:5px;">
      </div>
      <div>
        <label>Data da Venda</label>
        <input type="date" id="edit_os_data_venda" style="margin-top:5px;">
      </div>
      <div>
        <label>Previsao de Entrega</label>
        <input type="date" id="edit_os_previsao" style="margin-top:5px;">
      </div>
      <div>
        <label>Forma de Pagamento</label>
        <select id="edit_os_forma_pagamento" style="width:100%;padding:6px 8px;border:1px solid #ccc;border-radius:6px;margin-top:5px;">
          <option value="">Nao informado</option>
          <option value="boleto">Boleto</option>
          <option value="deposito">Deposito</option>
          <option value="pix">Pix</option>
        </select>
      </div>
      <div>
        <label>Prazo de Pagamento</label>
        <input type="text" id="edit_os_prazo_pagamento" placeholder="Ex: 30 dias" style="margin-top:5px;">
      </div>
    </div>
    <div style="margin-top:4px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
        <strong style="font-size:13px;color:#1b5e20;">Itens da OS</strong>
        <button type="button" class="btn" style="font-size:12px;padding:5px 10px;" onclick="abrirModalAdicionarItemOs()"><i class="bi bi-plus-lg"></i> Adicionar Produto</button>
      </div>
      <table class="eit">
        <thead><tr>
          <th class="pnome" style="text-align:left;">Produto</th>
          <th style="width:70px;">Pedido</th>
          <th style="width:70px;">Preco Unit.</th>
          <th style="width:80px;">Valor Final<br><small style="font-weight:400;opacity:.8;">(vazio=auto)</small></th>
        </tr></thead>
        <tbody id="edit_os_itens_tbody"></tbody>
      </table>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
      <button type="button" class="btn btn-secondary" onclick="fecharModalEditarOs()"><i class="bi bi-x-lg"></i> Fechar</button>
      <button type="button" class="btn" id="btn_salvar_os" onclick="salvarOsHistorico()"><i class="bi bi-check-lg"></i> Salvar OS</button>
    </div>
  </div>
</div>

<!-- Modal: Cancelar OS inteira -->
<div id="modalCancelarOs" class="modal">
  <div class="modal-box">
    <h3>Cancelar OS</h3>
    <form method="POST" action="actions/cancelar_os_historico.php" onsubmit="return confirmarCancelamentoOs()">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
      <input type="hidden" name="numero_os" id="cancelar_os_numero">
      <p><strong>OS:</strong> <span id="cancelar_os_label">-</span></p>
      <p><strong>Cliente:</strong> <span id="cancelar_os_cliente">-</span></p>
      <p><strong>Reversão financeira (total):</strong> <span id="cancelar_os_valor">R$ 0,00</span></p>
      <label>Motivo do cancelamento</label>
      <textarea name="motivo_cancelamento" id="cancelar_os_motivo" required></textarea>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
        <button type="button" class="btn btn-secondary" onclick="fecharModalCancelarOs()"><i class="bi bi-x-lg"></i></button>
        <button class="btn btn-warning" type="submit"><i class="bi bi-arrow-counterclockwise"></i> Cancelar OS</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Adicionar item a OS historica -->
<div id="modalAdicionarItemOs" class="modal">
  <div class="modal-box" style="max-width:460px;">
    <h3 style="margin:0 0 4px 0;">Adicionar Produto</h3>
    <p style="margin:0 0 16px 0;color:#555;font-size:13px;">OS: <strong id="add_item_os_numero_display"></strong></p>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
    <input type="hidden" id="add_item_os_numero">
    <div>
      <label>Produto</label>
      <select id="add_item_produto_id" required style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px;margin-top:5px;">
        <option value="">Selecione...</option>
        <?php foreach ($listaProdutos as $p): ?>
        <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="margin-top:12px;">
      <label>Tipo</label>
      <select id="add_item_tipo" onchange="_toggleAddItemCampos()" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px;margin-top:5px;">
        <option value="kg">Kg</option>
        <option value="bandeja">Bandeja</option>
        <option value="caixa">Caixa</option>
        <option value="unidade">Unidade</option>
      </select>
    </div>
    <div id="add_item_gramagem_row" style="margin-top:12px;display:none;">
      <label>Gramagem por bandeja (g)</label>
      <input type="number" step="1" min="0" id="add_item_gramagem" placeholder="Ex: 500" style="margin-top:5px;">
    </div>
    <div id="add_item_kg_caixa_row" style="margin-top:12px;display:none;">
      <label>Kg por caixa</label>
      <input type="number" step="0.01" min="0" id="add_item_kg_caixa" placeholder="Ex: 20" style="margin-top:5px;">
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px;">
      <div>
        <label>Quantidade</label>
        <input type="number" step="0.01" min="0.01" id="add_item_pedido" required style="margin-top:5px;" placeholder="Ex: 10">
      </div>
      <div>
        <label>Preco Unitario (R$)</label>
        <input type="number" step="0.01" min="0" id="add_item_preco" required style="margin-top:5px;" placeholder="Ex: 5.50">
      </div>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
      <button type="button" class="btn btn-secondary" onclick="fecharModalAdicionarItemOs()"><i class="bi bi-x-lg"></i> Cancelar</button>
      <button type="button" class="btn" id="btn_salvar_add_item" onclick="salvarAdicionarItemOs()"><i class="bi bi-plus-lg"></i> Salvar</button>
    </div>
  </div>
</div>

<!-- Modal: Revisar e emitir NF-e -->
<div id="modalNfeVenda" class="modal">
  <div class="modal-box modal-box-wide" style="width:1250px; max-width:95%;">
    <h3 style="margin:0 0 6px 0;">Revisar NF-e de venda</h3>
    <p style="margin:0 0 14px;color:#555;">OS <strong id="nfe_os_label">-</strong></p>
    <form id="formNfeVenda" method="POST" action="actions/emitir_nfe_focus.php" onsubmit="return nfeConfirmarEmissao(this);">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
      <input type="hidden" name="ambiente" value="producao">
      <input type="hidden" name="numero_os" id="nfe_numero_os">
      <input type="hidden" name="venda_id" id="nfe_venda_id">
      <input type="hidden" name="documento_id" id="nfe_reemissao_documento_id">
      <input type="hidden" name="tipo_caixa_original" id="nfe_tipo_caixa_original">
      <p id="nfe_rascunho_msg" style="display:none;margin:8px 0 12px;font-size:13px;font-weight:700;"></p>
      <div id="nfe_erros_box" class="nfe-erros-box">
        <div>Encontramos alguns pontos que precisam ser corrigidos antes de emitir a NF-e:</div>
        <ul id="nfe_erros_lista"></ul>
      </div>

      <h4 class="nfe-section-title">Operacao fiscal</h4>
      <div class="modal-grid">
        <div>
          <label>Tipo de operacao fiscal</label>
          <select name="tipo_operacao_fiscal" id="nfe_tipo_operacao_fiscal" onchange="nfeAtualizarTipoOperacaoFiscalVenda()" required style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;margin-top:5px;">
            <option value="venda" selected>Venda normal</option>
            <option value="bonificacao">Bonificação</option>
          </select>
        </div>
        <div>
          <label>Local de destino</label>
          <select name="local_destino" id="nfe_local_destino" onchange="nfeAtualizarTipoOperacaoFiscalVenda()" required style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;margin-top:5px;">
            <option value="1">Dentro do estado</option>
            <option value="2">Fora do estado</option>
            <option value="3">Exterior (requer dados adicionais)</option>
          </select>
        </div>
        <div><label>Consumidor final</label><input value="Nao - revenda" readonly></div>
        <div><label>Presenca do comprador</label><input value="2 - Operacao pela internet" readonly></div>
      </div>

      <h4 class="nfe-section-title">Destinatario</h4>
      <div class="modal-grid">
        <div><label>Nome/Razao</label><input name="destinatario[nome]" id="nfe_dest_nome"></div>
        <div><label>CPF</label><input name="destinatario[cpf]" id="nfe_dest_cpf"></div>
        <div><label>CNPJ</label><input name="destinatario[cnpj]" id="nfe_dest_cnpj"></div>
        <div><label>IE</label><input name="destinatario[ie]" id="nfe_dest_ie"></div>
        <div><label>Indicador IE</label><input name="destinatario[indicador_ie]" id="nfe_dest_indicador_ie"></div>
        <div><label>Telefone</label><input name="destinatario[telefone]" id="nfe_dest_telefone"></div>
        <div><label>Email</label><input name="destinatario[email]" id="nfe_dest_email"></div>
        <div><label>CEP</label><input name="destinatario[cep]" id="nfe_dest_cep"></div>
        <div><label>UF</label><input name="destinatario[uf]" id="nfe_dest_uf" maxlength="2"></div>
        <div><label>Municipio</label><input name="destinatario[municipio]" id="nfe_dest_municipio"></div>
        <div><label>Logradouro</label><input name="destinatario[logradouro]" id="nfe_dest_logradouro"></div>
        <div><label>Numero</label><input name="destinatario[numero]" id="nfe_dest_numero"></div>
        <div><label>Bairro</label><input name="destinatario[bairro]" id="nfe_dest_bairro"></div>
        <div><label>Complemento</label><input name="destinatario[complemento]" id="nfe_dest_complemento"></div>
      </div>

      <h4 class="nfe-section-title">Itens</h4>
      <div id="nfe_items_box"></div>

      <h4 class="nfe-section-title">Pesos e caixas</h4>
      <div class="modal-grid">
        <div><label>Peso liquido (kg)</label><input type="number" step="0.001" name="peso_liquido" id="nfe_peso_liquido" oninput="nfeAtualizarPesos()"></div>
        <div><label>Tipo de caixa</label><select name="tipo_caixa" id="nfe_tipo_caixa" onchange="nfeAtualizarPesos()" style="width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;margin-top:5px;"><option value="">Sem caixa</option><option value="plastica_p">Caixa plastica P - 1,350 kg</option><option value="plastica_m">Caixa plastica M - 2 kg</option><option value="papelao">Caixa de papelao - 0,750 kg</option></select></div>
        <div><label>Quantidade de caixas</label><input type="number" min="0" step="1" name="quantidade_caixas" id="nfe_quantidade_caixas" oninput="nfeAtualizarPesos()"></div>
        <div><label>Peso bruto (kg)</label><input type="number" step="0.001" name="peso_bruto" id="nfe_peso_bruto"></div>
      </div>

      <h4 class="nfe-section-title">Informacoes complementares</h4>
      <label>Observacao para informacoes adicionais do DANFE</label>
      <textarea name="informacoes_adicionais_contribuinte" id="nfe_informacoes_adicionais" maxlength="2000" placeholder="Texto exibido no campo Informacoes Adicionais da nota" style="width:100%;min-height:70px;padding:10px;border:1px solid #ccc;border-radius:6px;margin-top:5px;"></textarea>

      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
        <button type="button" class="btn btn-secondary" onclick="fecharModalNfeVenda()"><i class="bi bi-x-lg"></i></button>
        <button type="button" class="btn btn-secondary" id="btn_salvar_rascunho_nfe" onclick="nfeSalvarRascunhoVenda(this.form)"><i class="bi bi-save"></i> Salvar rascunho</button>
        <button class="btn btn-nfe" type="submit"><i class="bi bi-receipt"></i> Emitir NF-e</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Cancelar NF-e -->
<div id="modalCancelarNfe" class="modal">
  <div class="modal-box">
    <h3>Cancelar NF-e</h3>
    <form method="POST" action="actions/cancelar_nfe_focus.php" onsubmit="return confirmarCancelamentoNfe()">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
      <input type="hidden" name="ambiente" value="producao">
      <input type="hidden" name="documento_id" id="cancelar_nfe_documento_id">
      <p><strong>Nota:</strong> <span id="cancelar_nfe_numero_label">-</span></p>
      <p><strong>Ref:</strong> <span id="cancelar_nfe_ref_label">-</span></p>
      <label>Justificativa</label>
      <textarea name="justificativa" id="cancelar_nfe_justificativa" minlength="15" maxlength="255" required></textarea>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
        <button type="button" class="btn btn-secondary" onclick="fecharModalCancelarNfe()"><i class="bi bi-x-lg"></i></button>
        <button class="btn btn-danger" type="submit"><i class="bi bi-x-octagon"></i> Cancelar NF-e</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Carta de correcao NF-e -->
<div id="modalCartaCorrecaoNfe" class="modal">
  <div class="modal-box">
    <h3>Carta de correcao</h3>
    <form method="POST" action="actions/carta_correcao_nfe_focus.php" onsubmit="return confirmarCartaCorrecaoNfe()">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(gerarTokenCsrf()) ?>">
      <input type="hidden" name="ambiente" value="producao">
      <input type="hidden" name="documento_id" id="cce_nfe_documento_id">
      <p><strong>Nota:</strong> <span id="cce_nfe_numero_label">-</span></p>
      <p><strong>Ref:</strong> <span id="cce_nfe_ref_label">-</span></p>
      <label>Texto da correcao</label>
      <textarea name="correcao" id="cce_nfe_correcao" minlength="15" maxlength="1000" required placeholder="Informe o texto que sera enviado como Carta de Correcao Eletronica"></textarea>
      <p style="font-size:12px;color:#555;margin:8px 0 0;">Nao use para alterar valores, impostos, destinatario/remetente ou data de emissao/saida.</p>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
        <button type="button" class="btn btn-secondary" onclick="fecharModalCartaCorrecaoNfe()"><i class="bi bi-x-lg"></i></button>
        <button class="btn btn-nfe" type="submit"><i class="bi bi-send"></i> Enviar carta</button>
      </div>
    </form>
  </div>
</div>
</body>
</html>

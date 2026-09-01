<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../focus/focus_nfe_operacoes.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../historico_vendas.php'); exit; }
validarTokenCsrf();
$id = (int)($_POST['documento_id'] ?? 0);

try {
    $stmt = $conexao->prepare("SELECT id,ref,status,payload_json FROM nfe_documentos WHERE id=? AND origem_tipo='reemissao_payload' LIMIT 1");
    $stmt->bind_param('i',$id); $stmt->execute(); $doc=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$doc) throw new RuntimeException('Registro de reemissao nao encontrado.');
    if (!in_array((string)$doc['status'], ['rascunho','rejeitada','erro'], true)) throw new RuntimeException('Esta reemissao ja foi enviada ou autorizada.');
    $payload=json_decode((string)$doc['payload_json'],true);
    if (!is_array($payload) || empty($payload['items'])) throw new RuntimeException('Payload original invalido.');
    // Aplica somente os campos visiveis/revisados, preservando todos os demais
    // campos fiscais do payload original (inclusive consumidor_final, PIS/COFINS,
    // transportador e campos que o modal comum normalmente recalcularia).
    if (isset($_POST['local_destino'])) $payload['local_destino'] = (int)$_POST['local_destino'];
    $destPost = is_array($_POST['destinatario'] ?? null) ? $_POST['destinatario'] : [];
    $mapDest = ['nome'=>'nome_destinatario','cpf'=>'cpf_destinatario','cnpj'=>'cnpj_destinatario','ie'=>'inscricao_estadual_destinatario','indicador_ie'=>'indicador_inscricao_estadual_destinatario','logradouro'=>'logradouro_destinatario','numero'=>'numero_destinatario','complemento'=>'complemento_destinatario','bairro'=>'bairro_destinatario','municipio'=>'municipio_destinatario','uf'=>'uf_destinatario','cep'=>'cep_destinatario','telefone'=>'telefone_destinatario','email'=>'email_destinatario'];
    foreach ($mapDest as $origem=>$destino) if (array_key_exists($origem,$destPost)) $payload[$destino]=trim((string)$destPost[$origem]);
    $postItems = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
    $novosItems=[];
    foreach (($payload['items'] ?? []) as $idx=>$item) {
        $pi=is_array($postItems[$idx]??null)?$postItems[$idx]:[];
        if (($pi['excluir']??'0')==='1') continue;
        $campos=['codigo_produto','descricao','codigo_ncm','cfop','unidade_comercial','unidade_tributavel','icms_situacao_tributaria','pis_situacao_tributaria','cofins_situacao_tributaria','codigo_beneficio_fiscal'];
        foreach($campos as $campo) if(array_key_exists($campo,$pi)) $item[$campo]=trim((string)$pi[$campo]);
        foreach(['quantidade_comercial','valor_unitario_comercial','quantidade_tributavel','valor_unitario_tributavel','valor_bruto','pis_aliquota_porcentual','pis_valor','cofins_aliquota_porcentual','cofins_valor'] as $campo) if(array_key_exists($campo,$pi)) $item[$campo]=number_format(max(0,(float)$pi[$campo]),in_array($campo,['valor_bruto','pis_valor','cofins_valor'],true)?2:4,'.','');
        $item=focusNfeAplicarPisCofinsItem($item);
        $novosItems[]=$item;
    }
    if(!$novosItems) throw new RuntimeException('A reemissao precisa manter ao menos um item.');
    $payload['items']=$novosItems;
    $payload['peso_liquido']=number_format((float)($_POST['peso_liquido']??$payload['peso_liquido']??0),3,'.','');
    $payload['peso_bruto']=number_format((float)($_POST['peso_bruto']??$payload['peso_bruto']??0),3,'.','');
    if (array_key_exists('informacoes_adicionais_contribuinte',$_POST)) $payload['informacoes_adicionais_contribuinte']=trim((string)$_POST['informacoes_adicionais_contribuinte']);
    if (!empty($payload['volumes'][0]) && is_array($payload['volumes'][0])) {
        $payload['volumes'][0]['quantidade']=max(1,(int)($_POST['quantidade_caixas']??$payload['volumes'][0]['quantidade']??1));
        $payload['volumes'][0]['peso_liquido']=$payload['peso_liquido'];
        $payload['volumes'][0]['peso_bruto']=$payload['peso_bruto'];
        $tipoCaixa = trim((string)($_POST['tipo_caixa'] ?? ''));
        $tipoCaixaOriginal = trim((string)($_POST['tipo_caixa_original'] ?? ''));
        // Mantem literalmente a especie do payload quando o usuario nao alterou o tipo.
        // Se houver alteracao consciente, sincroniza a especie com a selecao revisada.
        if ($tipoCaixa !== $tipoCaixaOriginal) {
            $especies = [
                'plastica_p' => 'Caixa plastica P',
                'plastica_m' => 'Caixa plastica M',
                'papelao' => 'Caixa de papelao',
            ];
            if (isset($especies[$tipoCaixa])) $payload['volumes'][0]['especie'] = $especies[$tipoCaixa];
        }
    }
    // Unica alteracao automatica: a data fiscal deve refletir a nova transmissao.
    $payload['data_emissao']=(new DateTimeImmutable('now',new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d\TH:i:sP');
    $config=focusNfeLoadConfig($conexao,'producao');
    $resultado=focusNfeEnviarOperacao($conexao,$config,(int)$doc['id'],(string)$doc['ref'],$payload);
    $http=(int)($resultado['http_code']??0); $resp=is_array($resultado['response']??null)?$resultado['response']:[];
    $status=strtolower((string)($resp['status']??''));
    if (($http===201||$http===202) && !str_contains($status,'erro') && !str_contains($status,'reje')) {
        $redirect='../historico_vendas.php?msg=nfe_processando&ref='.urlencode((string)$doc['ref']);
        if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))==='xmlhttprequest') { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'redirect'=>$redirect]); exit; }
        header('Location: '.$redirect); exit;
    }
    $erro=(string)($resp['mensagem_sefaz']??$resp['mensagem']??$resultado['error']??('Focus HTTP '.$http));
    throw new RuntimeException($erro);
} catch (Throwable $e) {
    if (strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))==='xmlhttprequest') { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'erros'=>[$e->getMessage()]],JSON_UNESCAPED_UNICODE); exit; }
    header('Location: ../historico_vendas.php?msg=erro&detalhe='.urlencode($e->getMessage())); exit;
}

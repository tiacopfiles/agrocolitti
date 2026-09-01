<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../auth/proteger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../historico_vendas.php');
    exit;
}
validarTokenCsrf();

$stmt = $conexao->prepare(
    "SELECT * FROM nfe_documentos
      WHERE ambiente='producao'
        AND status IN ('autorizada','cancelada')
        AND CAST(numero_nfe AS UNSIGNED) BETWEEN 10851 AND 10868
      ORDER BY CAST(numero_nfe AS UNSIGNED), id DESC"
);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$originais = [];
foreach ($rows as $row) {
    $numero = (int) ($row['numero_nfe'] ?? 0);
    if (!isset($originais[$numero])) $originais[$numero] = $row;
}

$erros = [];
for ($numero=10851; $numero<=10868; $numero++) {
    $row = $originais[$numero] ?? null;
    if (!$row) { $erros[] = "NF-e {$numero} nao encontrada/autorizada."; continue; }
    $payload = json_decode((string)($row['payload_json'] ?? ''), true);
    if (!is_array($payload) || empty($payload['items'])) { $erros[] = "NF-e {$numero} sem payload/itens validos."; continue; }
    if (stripos((string)($payload['nome_destinatario'] ?? ''), 'COVABRA') === false) $erros[] = "NF-e {$numero} nao pertence ao Covabra.";
}
if ($erros) {
    header('Location: ../historico_vendas.php?msg=erro&detalhe=' . urlencode(implode(' ', $erros)));
    exit;
}

$conexao->begin_transaction();
try {
    $criados = 0;
    foreach ($originais as $numero => $original) {
        $ref = 'REEMISSAO-COVABRA-NFE-' . $numero . '-DOC-' . (int)$original['id'];
        $numeroOs = 'REEM-' . $numero;
        $stmtInsert = $conexao->prepare(
            "INSERT INTO nfe_documentos
             (ref,tipo_emissao,origem_tipo,origem_id,venda_id,numero_os,cliente_id,fornecedor_id,
              ambiente,status,payload_json,opcoes_json,revisao_json,peso_liquido,peso_bruto,mensagem_sefaz)
             VALUES (?,'venda','reemissao_payload',?,NULL,?,?,NULL,'producao','rascunho',?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json), opcoes_json=VALUES(opcoes_json),
               revisao_json=VALUES(revisao_json), peso_liquido=VALUES(peso_liquido), peso_bruto=VALUES(peso_bruto)"
        );
        $origemId = (int)$original['id'];
        $clienteId = isset($original['cliente_id']) ? (int)$original['cliente_id'] : null;
        $payloadJson = (string)$original['payload_json'];
        $opcoesJson = (string)($original['opcoes_json'] ?? '');
        $revisaoJson = (string)($original['revisao_json'] ?? '');
        $pesoLiquido = isset($original['peso_liquido']) ? (float)$original['peso_liquido'] : null;
        $pesoBruto = isset($original['peso_bruto']) ? (float)$original['peso_bruto'] : null;
        $mensagem = 'Copia fiel do payload autorizado da NF-e ' . $numero . '. Nao movimenta venda nem estoque.';
        $stmtInsert->bind_param('sisisssdds', $ref,$origemId,$numeroOs,$clienteId,$payloadJson,$opcoesJson,$revisaoJson,$pesoLiquido,$pesoBruto,$mensagem);
        $stmtInsert->execute();
        if ($stmtInsert->affected_rows === 1) $criados++;
        $stmtInsert->close();
    }
    $conexao->commit();
    header('Location: ../historico_vendas.php?msg=reemissoes_preparadas&total=' . $criados);
} catch (Throwable $e) {
    $conexao->rollback();
    header('Location: ../historico_vendas.php?msg=erro&detalhe=' . urlencode($e->getMessage()));
}
exit;

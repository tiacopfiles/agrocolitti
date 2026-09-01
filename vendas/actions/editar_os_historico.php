<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/log_helper.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../../config/cadastro_fiscal_helper.php";
require __DIR__ . "/venda_historico_helper.php";

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'Metodo invalido.']);
    exit;
}

validarTokenCsrf();

if (!in_array($_SESSION['usuario_nivel'] ?? '', ['admin', 'ti'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Sem permissao para editar OS.']);
    exit;
}

$numeroOs       = trim((string) ($_POST['numero_os']        ?? ''));
$clienteId      = (int)         ($_POST['cliente_id']       ?? 0);
$previsao       = trim((string) ($_POST['previsao_entrega'] ?? ''));
$dataVenda      = trim((string) ($_POST['data_venda']       ?? ''));
$vendedor       = trim((string) ($_POST['vendedor']         ?? ''));
$prazoPagamento = trim((string) ($_POST['prazo_pagamento']  ?? ''));
$formaPagamento = strtolower(trim((string) ($_POST['forma_pagamento'] ?? '')));
$caixaId        = array_key_exists('caixa_id', $_POST)   ? ((int) ($_POST['caixa_id']   ?? 0) ?: null) : null;
$numCaixas      = array_key_exists('num_caixas', $_POST)  ? (int)  ($_POST['num_caixas'] ?? 0)          : 0;
$itensJson      = trim((string) ($_POST['itens']            ?? '[]'));

$itens = [];
try {
    $decoded = json_decode($itensJson, true, 8, JSON_THROW_ON_ERROR);
    if (is_array($decoded)) $itens = $decoded;
} catch (\Throwable $e) {
    $itens = [];
}

if ($numeroOs === '') {
    echo json_encode(['ok' => false, 'erro' => 'OS invalida.']);
    exit;
}
if ($clienteId <= 0) {
    echo json_encode(['ok' => false, 'erro' => 'Cliente invalido.']);
    exit;
}

try {
    cadastroFiscalExigirPessoaSelecionada($conexao, 'clientes', $clienteId, 'Cliente');
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
    exit;
}

$conexao->begin_transaction();

try {
    // Verifica se a OS existe e esta concluida
    $stmtCheck = $conexao->prepare("SELECT COUNT(*) AS total FROM vendas WHERE numero_os = ? AND status = 'concluido'");
    $stmtCheck->bind_param('s', $numeroOs);
    $stmtCheck->execute();
    $total = (int) ($stmtCheck->get_result()->fetch_assoc()['total'] ?? 0);
    $stmtCheck->close();

    if ($total === 0) {
        throw new Exception("OS '$numeroOs' nao encontrada ou nao esta concluida.");
    }

    // Monta UPDATE dinamico para campos da OS
    $sets      = ["cliente_id = ?", "caixa_id = ?", "num_caixas = ?"];
    $bindTypes = "iii";
    $bindVals  = [$clienteId, $caixaId, $numCaixas];

    if ($previsao !== '') {
        $sets[]     = "previsao_entrega = ?";
        $bindTypes .= "s";
        $bindVals[] = $previsao;
    }
    if ($dataVenda !== '') {
        $sets[]     = "data_venda = ?";
        $bindTypes .= "s";
        $bindVals[] = $dataVenda;
    }
    if ($vendedor !== '') {
        $sets[]     = "vendedor = ?";
        $bindTypes .= "s";
        $bindVals[] = $vendedor;
    }
    if ($prazoPagamento !== '') {
        $sets[]     = "prazo_pagamento = ?";
        $bindTypes .= "s";
        $bindVals[] = $prazoPagamento;
    }
    if (in_array($formaPagamento, ['boleto', 'deposito', 'pix'], true)) {
        $sets[]     = "forma_pagamento = ?";
        $bindTypes .= "s";
        $bindVals[] = $formaPagamento;
    }

    $bindVals[] = $numeroOs;
    $bindTypes .= "s";

    $stmt = $conexao->prepare(
        "UPDATE vendas SET " . implode(', ', $sets) . " WHERE numero_os = ? AND status = 'concluido'"
    );
    $stmt->bind_param($bindTypes, ...$bindVals);
    $stmt->execute();
    $stmt->close();

    // Atualiza itens e suas movimentacoes usando a mesma regra da edicao individual.
    foreach ($itens as $item) {
        $itemId = (int) ($item['id'] ?? 0);
        if ($itemId <= 0) continue;

        $atual = vendaHistoricoBuscarVenda($conexao, $itemId, true);
        vendaHistoricoAtualizarItem(
            $conexao, $itemId, (int) $atual['produto_id'], (string) ($atual['tipo'] ?? 'kg'),
            isset($item['pedido']) ? (float) $item['pedido'] : (float) $atual['pedido'],
            isset($item['preco']) ? (float) $item['preco'] : (float) $atual['preco'],
            (float) ($atual['gramagem'] ?? 0), (float) ($atual['kg_caixa'] ?? 0), $numeroOs
        );
    }

    $conexao->commit();

    registrarLog(
        $conexao,
        'os_editada_historico',
        'vendas',
        0,
        "OS '$numeroOs' editada — cliente_id $clienteId, previsao '$previsao', data_venda '$dataVenda', vendedor '$vendedor', forma '$formaPagamento'"
    );

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    $conexao->rollback();
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}

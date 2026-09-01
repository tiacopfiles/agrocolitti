<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/log_helper.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../venda_helper.php";
require __DIR__ . "/../../config/cadastro_fiscal_helper.php";
require __DIR__ . "/../../config/produto_vinculo_helper.php";
require __DIR__ . "/../../config/ciclo_helper.php";

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'Metodo invalido.']);
    exit;
}

validarTokenCsrf();

if (!in_array($_SESSION['usuario_nivel'] ?? '', ['admin', 'ti'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Sem permissao.']);
    exit;
}

$numeroOs  = trim((string) ($_POST['numero_os']  ?? ''));
$produtoId = (int)         ($_POST['produto_id'] ?? 0);
$tipo      = strtolower(trim((string) ($_POST['tipo'] ?? 'kg')));
$gramagem  = (float) str_replace(',', '.', (string) ($_POST['gramagem']  ?? '0'));
$kgCaixa   = (float) str_replace(',', '.', (string) ($_POST['kg_caixa'] ?? '0'));
$pedido    = (float) str_replace(',', '.', (string) ($_POST['pedido']    ?? '0'));
$preco     = (float) str_replace(',', '.', (string) ($_POST['preco']     ?? '0'));

if ($numeroOs === '') {
    echo json_encode(['ok' => false, 'erro' => 'OS invalida.']);
    exit;
}
if ($produtoId <= 0) {
    echo json_encode(['ok' => false, 'erro' => 'Produto invalido.']);
    exit;
}
if ($pedido <= 0) {
    echo json_encode(['ok' => false, 'erro' => 'Quantidade invalida.']);
    exit;
}
if (!in_array($tipo, ['kg', 'bandeja', 'caixa', 'unidade'], true)) {
    $tipo = 'kg';
}

// Verifica colunas opcionais ANTES de qualquer transacao
$temCicloVendas  = (bool) $conexao->query("SHOW COLUMNS FROM vendas LIKE 'ciclo_id'")->num_rows;
$temCicloMov     = (bool) $conexao->query("SHOW COLUMNS FROM movimentacoes LIKE 'ciclo_id'")->num_rows;
$temRefIdMov     = (bool) $conexao->query("SHOW COLUMNS FROM movimentacoes LIKE 'referencia_id'")->num_rows;

$conexao->begin_transaction();

try {
    // Busca dados da OS (deve existir e estar concluida)
    $cicloSel = $temCicloVendas ? ", MIN(ciclo_id) AS ciclo_id" : '';
    $stmtOs = $conexao->prepare(
        "SELECT MIN(data_venda) AS data_venda,
                MIN(cliente_id) AS cliente_id,
                MIN(previsao_entrega) AS previsao_entrega,
                MIN(vendedor) AS vendedor,
                MIN(prazo_pagamento) AS prazo_pagamento,
                MIN(forma_pagamento) AS forma_pagamento
                $cicloSel
         FROM vendas
         WHERE numero_os = ? AND status = 'concluido'"
    );
    $stmtOs->bind_param('s', $numeroOs);
    $stmtOs->execute();
    $osInfo = $stmtOs->get_result()->fetch_assoc();
    $stmtOs->close();

    if (!$osInfo || $osInfo['cliente_id'] === null) {
        throw new Exception("OS '$numeroOs' nao encontrada ou nao esta concluida.");
    }

    $dataVenda  = (string) ($osInfo['data_venda']        ?? date('Y-m-d'));
    $clienteId  = (int)    ($osInfo['cliente_id']        ?? 0);
    $previsao   = (string) ($osInfo['previsao_entrega']  ?? '');
    $vendedor   = (string) ($osInfo['vendedor']          ?? '');
    $prazo      = (string) ($osInfo['prazo_pagamento']   ?? '');
    $forma      = (string) ($osInfo['forma_pagamento']   ?? '');
    $cicloId    = !empty($osInfo['ciclo_id']) ? (int) $osInfo['ciclo_id'] : null;
    // A tabela vendas nao possui coluna ciclo_id; e esta tela so localiza OS 'concluido'
    // do ciclo corrente. Logo o ciclo correto da movimentacao e o ciclo ativo.
    // Sem este fallback a movimentacao era gravada com ciclo_id NULL (estoque do
    // ciclo divergia do estoque global). Ver diagnostico de 2026-06-19.
    if ($cicloId === null && $temCicloMov) {
        $cicloId = getCicloAtivoId($conexao);
    }
    cadastroFiscalExigirVenda($conexao, $clienteId, $produtoId);
    $produtoEstoqueId = produtoEstoqueId($conexao, $produtoId);

    // Calcula quantidade em kg
    if ($tipo === 'caixa') {
        $quantidade = $pedido * $kgCaixa;
    } elseif ($tipo === 'bandeja') {
        $quantidade = ($pedido * $gramagem) / 1000.0;
    } else {
        $quantidade = $pedido;
    }

    if ($temCicloVendas) {
        $stmtIns = $conexao->prepare("
            INSERT INTO vendas
                (data_venda, produto_id, cliente_id, tipo, gramagem, kg_caixa, pedido, preco,
                 quantidade, numero_os, previsao_entrega, vendedor, prazo_pagamento, forma_pagamento,
                 ciclo_id, status, data_confirmacao)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'concluido', NOW())
        ");
        $stmtIns->bind_param(
            'siisdddddsssssi',
            $dataVenda, $produtoId, $clienteId, $tipo,
            $gramagem, $kgCaixa, $pedido, $preco, $quantidade,
            $numeroOs, $previsao, $vendedor, $prazo, $forma, $cicloId
        );
    } else {
        $stmtIns = $conexao->prepare("
            INSERT INTO vendas
                (data_venda, produto_id, cliente_id, tipo, gramagem, kg_caixa, pedido, preco,
                 quantidade, numero_os, previsao_entrega, vendedor, prazo_pagamento, forma_pagamento,
                 status, data_confirmacao)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'concluido', NOW())
        ");
        $stmtIns->bind_param(
            'siisdddddsssss',
            $dataVenda, $produtoId, $clienteId, $tipo,
            $gramagem, $kgCaixa, $pedido, $preco, $quantidade,
            $numeroOs, $previsao, $vendedor, $prazo, $forma
        );
    }

    $stmtIns->execute();
    $newId = (int) $conexao->insert_id;
    $stmtIns->close();

    // Consistencia: venda em bandeja com gramagem e sempre embalado (regra usada pela NF-e).
    if ($tipo === 'bandeja' && $gramagem > 0) {
        $stmtTc = $conexao->prepare("UPDATE vendas SET tipo_comercial = 'embalado' WHERE id = ?");
        $stmtTc->bind_param('i', $newId);
        $stmtTc->execute();
        $stmtTc->close();
    }

    // Busca nome do produto
    $stmtProd = $conexao->prepare("SELECT nome, unidade FROM produtos WHERE id = ? LIMIT 1");
    $stmtProd->bind_param('i', $produtoId);
    $stmtProd->execute();
    $prodInfo = $stmtProd->get_result()->fetch_assoc();
    $stmtProd->close();

    // Registra movimentacao de estoque (saida) — colunas opcionais verificadas dinamicamente
    $movCols  = "produto_id, tipo, quantidade, data_movimentacao";
    $movMarks = "?, 'venda', ?, NOW()";
    $movTypes = "id";
    $movVals  = [$produtoEstoqueId, $quantidade];

    if ($temCicloMov) {
        $movCols  .= ", ciclo_id";
        $movMarks .= ", ?";
        $movTypes .= "i";
        $movVals[] = $cicloId;
    }
    if ($temRefIdMov) {
        $movCols  .= ", referencia_id";
        $movMarks .= ", ?";
        $movTypes .= "i";
        $movVals[] = $newId;
    }

    $stmtMov = $conexao->prepare("INSERT INTO movimentacoes ($movCols) VALUES ($movMarks)");
    $stmtMov->bind_param($movTypes, ...$movVals);
    $stmtMov->execute();
    $stmtMov->close();

    $conexao->commit();

    registrarLog(
        $conexao,
        'item_adicionado_os_historico',
        'vendas',
        $newId,
        "Item adicionado a OS '$numeroOs' — produto_id $produtoId, tipo $tipo, pedido $pedido, preco $preco"
    );

    echo json_encode([
        'ok'   => true,
        'item' => [
            'id'           => $newId,
            'produto_id'   => $produtoId,
            'produto_nome' => (string) ($prodInfo['nome'] ?? ''),
            'tipo'         => $tipo,
            'gramagem'     => $gramagem,
            'kg_caixa'     => $kgCaixa,
            'pedido'       => $pedido,
            'preco'        => $preco,
            'quantidade'   => $quantidade,
        ],
    ]);

} catch (Exception $e) {
    $conexao->rollback();
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}

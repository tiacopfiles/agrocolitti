<?php
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/conexao.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SESSION['usuario_nivel'] ?? '') !== 'admin') {
        echo json_encode(['ok' => false, 'erro' => 'Acesso negado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        echo json_encode(['ok' => false, 'erro' => 'Metodo invalido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $clienteOrigemId = (int) ($_POST['cliente_origem_id'] ?? 0);
    $clienteDestinoId = (int) ($_POST['cliente_destino_id'] ?? 0);
    $tabela = trim((string) ($_POST['tabela'] ?? ''));

    if ($clienteOrigemId <= 0 || $clienteDestinoId <= 0) {
        echo json_encode(['ok' => false, 'erro' => 'Clientes invalidos'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($clienteOrigemId === $clienteDestinoId) {
        echo json_encode(['ok' => true, 'sem_alteracao' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!in_array($tabela, ['embalado', 'convencional', 'atacado'], true)) {
        echo json_encode(['ok' => false, 'erro' => 'Tabela invalida'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmtCliente = $conexao->prepare("SELECT id, nome FROM clientes WHERE id = ? AND ativo = 1 LIMIT 1");
    if (!$stmtCliente) {
        throw new RuntimeException('Falha ao validar cliente: ' . $conexao->error);
    }
    $stmtCliente->bind_param('i', $clienteDestinoId);
    $stmtCliente->execute();
    $clienteDestino = $stmtCliente->get_result()->fetch_assoc();
    $stmtCliente->close();

    if (!$clienteDestino) {
        echo json_encode(['ok' => false, 'erro' => 'Cliente destino nao encontrado ou inativo'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $conexao->begin_transaction();

    $stmtOrigem = $conexao->prepare("
        SELECT percentual, tipo_aplicacao
        FROM cliente_percentual_financeiro
        WHERE cliente_id = ? AND tabela = ?
        LIMIT 1
    ");
    if (!$stmtOrigem) {
        throw new RuntimeException('Falha ao buscar percentual: ' . $conexao->error);
    }
    $stmtOrigem->bind_param('is', $clienteOrigemId, $tabela);
    $stmtOrigem->execute();
    $origem = $stmtOrigem->get_result()->fetch_assoc();
    $stmtOrigem->close();

    if (!$origem) {
        $conexao->rollback();
        echo json_encode(['ok' => false, 'erro' => 'Registro original nao encontrado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $percentual = (float) ($origem['percentual'] ?? 0);
    $tipoAplicacao = (string) ($origem['tipo_aplicacao'] ?? 'acrescimo');
    if (!in_array($tipoAplicacao, ['acrescimo', 'desconto'], true)) {
        $tipoAplicacao = 'acrescimo';
    }

    $stmtUpsert = $conexao->prepare("
        INSERT INTO cliente_percentual_financeiro (cliente_id, percentual, tipo_aplicacao, tabela)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            percentual = VALUES(percentual),
            tipo_aplicacao = VALUES(tipo_aplicacao)
    ");
    if (!$stmtUpsert) {
        throw new RuntimeException('Falha ao gravar cliente destino: ' . $conexao->error);
    }
    $stmtUpsert->bind_param('idss', $clienteDestinoId, $percentual, $tipoAplicacao, $tabela);
    $stmtUpsert->execute();
    $stmtUpsert->close();

    $stmtDelete = $conexao->prepare("
        DELETE FROM cliente_percentual_financeiro
        WHERE cliente_id = ? AND tabela = ?
        LIMIT 1
    ");
    if (!$stmtDelete) {
        throw new RuntimeException('Falha ao remover cliente origem: ' . $conexao->error);
    }
    $stmtDelete->bind_param('is', $clienteOrigemId, $tabela);
    $stmtDelete->execute();
    $stmtDelete->close();

    $conexao->commit();

    echo json_encode([
        'ok' => true,
        'cliente_id' => $clienteDestinoId,
        'cliente_nome' => (string) $clienteDestino['nome'],
        'percentual_decimal' => $percentual,
        'tipo_aplicacao' => $tipoAplicacao,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($conexao instanceof mysqli) {
        @$conexao->rollback();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

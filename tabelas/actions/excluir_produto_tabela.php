<?php
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/conexao.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SESSION['usuario_nivel'] ?? '') !== 'admin') {
        echo json_encode(['ok' => false, 'erro' => 'Acesso negado']);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        echo json_encode(['ok' => false, 'erro' => 'Metodo inválido']);
        exit;
    }

    $produtoId = (int) ($_POST['produto_id'] ?? 0);
    if ($produtoId <= 0) {
        echo json_encode(['ok' => false, 'erro' => 'Produto inválido.']);
        exit;
    }

    $stmt = $conexao->prepare('SELECT id, nome FROM produtos WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $produtoId);
    $stmt->execute();
    $produto = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$produto) {
        echo json_encode(['ok' => false, 'erro' => 'Produto nao encontrado.']);
        exit;
    }

    $referencias = [];
    $tabelasReferencia = [
        'vendas' => 'vendas',
        'entradas' => 'entradas',
        'movimentacoes' => 'movimentacoes',
        'previsao_fornecedor' => 'previsoes de fornecedor',
    ];
    foreach ($tabelasReferencia as $tabela => $rotulo) {
        $resTabela = $conexao->query("SHOW TABLES LIKE '" . $conexao->real_escape_string($tabela) . "'");
        if (!$resTabela || $resTabela->num_rows === 0) {
            continue;
        }
        $stmt = $conexao->prepare("SELECT COUNT(*) AS total FROM `$tabela` WHERE produto_id = ?");
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param('i', $produtoId);
        $stmt->execute();
        $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        if ($total > 0) {
            $referencias[] = $rotulo . ' (' . $total . ')';
        }
    }

    if ($referencias) {
        echo json_encode([
            'ok' => false,
            'erro' => 'Este produto tem historico em ' . implode(', ', $referencias) . '. Para nao quebrar o historico, ele nao pode ser apagado diretamente.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $conexao->begin_transaction();
    foreach (['preco_atacado','preco_atacado_convencional','preco_embalado','preco_oba_embalado','preco_shopper','estoque','estoque_inicial'] as $tabela) {
        $resTabela = $conexao->query("SHOW TABLES LIKE '" . $conexao->real_escape_string($tabela) . "'");
        if (!$resTabela || $resTabela->num_rows === 0) {
            continue;
        }
        $stmt = $conexao->prepare("DELETE FROM `$tabela` WHERE produto_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $produtoId);
            $stmt->execute();
            $stmt->close();
        }
    }
    $stmt = $conexao->prepare('DELETE FROM produtos WHERE id = ?');
    $stmt->bind_param('i', $produtoId);
    $stmt->execute();
    $apagados = $stmt->affected_rows;
    $stmt->close();
    $conexao->commit();

    echo json_encode(['ok' => $apagados > 0, 'apagados' => $apagados, 'nome' => (string) $produto['nome']], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($conexao instanceof mysqli) {
        @$conexao->rollback();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
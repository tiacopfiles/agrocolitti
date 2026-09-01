<?php
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/permissions.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requireModule('tabelas');

    $sql = "
        SELECT p.id, p.nome, COALESCE(NULLIF(p.unidade, ''), 'KG') AS unidade,
               COALESCE(e.quantidade, 0) AS estoque
        FROM produtos p
        LEFT JOIN estoque e ON e.produto_id = COALESCE(p.produto_principal_id, p.id)
        WHERE COALESCE(p.ativo, 1) = 1
        ORDER BY p.nome
    ";
    $res = $conexao->query($sql);
    if (!$res) {
        throw new RuntimeException('Nao foi possivel buscar produtos: ' . $conexao->error);
    }

    $produtos = [];
    while ($row = $res->fetch_assoc()) {
        $produtos[] = [
            'id' => (int) $row['id'],
            'nome' => (string) $row['nome'],
            'unidade' => (string) ($row['unidade'] ?: 'KG'),
            'estoque' => (float) ($row['estoque'] ?? 0),
        ];
    }

    echo json_encode(['ok' => true, 'produtos' => $produtos], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}


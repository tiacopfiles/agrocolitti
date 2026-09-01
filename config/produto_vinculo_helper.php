<?php

function produtoEstoqueId(mysqli $conexao, int $produtoId): int
{
    if ($produtoId <= 0) {
        return 0;
    }

    static $temProdutoPrincipal = null;
    if ($temProdutoPrincipal === null) {
        if (function_exists('colunaExiste')) {
            $temProdutoPrincipal = colunaExiste($conexao, 'produtos', 'produto_principal_id');
        } else {
            $res = $conexao->query("SHOW COLUMNS FROM produtos LIKE 'produto_principal_id'");
            $temProdutoPrincipal = $res && $res->num_rows > 0;
            if ($res instanceof mysqli_result) {
                $res->free();
            }
        }
    }

    if (!$temProdutoPrincipal) {
        return $produtoId;
    }

    $stmt = $conexao->prepare("
        SELECT COALESCE(produto_principal_id, id) AS produto_estoque_id
        FROM produtos
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $produtoId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['produto_estoque_id'] ?? $produtoId);
}

function produtoEhVinculado(array $produto): bool
{
    return (int) ($produto['produto_principal_id'] ?? 0) > 0;
}

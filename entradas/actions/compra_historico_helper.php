<?php

function compraHistoricoTabelaExiste(mysqli $conexao, string $tabela): bool
{
    $tabela = $conexao->real_escape_string($tabela);
    $res = $conexao->query("SHOW TABLES LIKE '{$tabela}'");
    $existe = $res && $res->num_rows > 0;
    if ($res instanceof mysqli_result) {
        $res->free();
    }
    return $existe;
}

function compraHistoricoColunaExiste(mysqli $conexao, string $tabela, string $coluna): bool
{
    $tabela = $conexao->real_escape_string($tabela);
    $coluna = $conexao->real_escape_string($coluna);
    $res = $conexao->query("SHOW COLUMNS FROM `{$tabela}` LIKE '{$coluna}'");
    $existe = $res && $res->num_rows > 0;
    if ($res instanceof mysqli_result) {
        $res->free();
    }
    return $existe;
}

function compraHistoricoGarantirTabelaReversoes(mysqli $conexao): void
{
    $conexao->query("
        CREATE TABLE IF NOT EXISTS financeiro_reversoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            origem VARCHAR(40) NOT NULL,
            numero_os VARCHAR(50) NOT NULL,
            contraparte VARCHAR(180) NULL,
            valor DECIMAL(12,2) NOT NULL,
            motivo TEXT NULL,
            usuario VARCHAR(120) NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_fin_rev_origem_os (origem, numero_os),
            KEY idx_fin_rev_criado_em (criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function compraHistoricoBuscarEntradasOs(mysqli $conexao, string $numeroOs, bool $lock = false): array
{
    $lockSql = $lock ? ' FOR UPDATE' : '';
    $stmt = $conexao->prepare("
        SELECT e.*
        FROM entradas e
        WHERE e.tipo = 'entrada_fornecedor'
          AND e.numero_os = ?
        ORDER BY e.id ASC
        {$lockSql}
    ");
    $stmt->bind_param('s', $numeroOs);
    $stmt->execute();
    $linhas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $linhas;
}

function compraHistoricoBuscarMovimentacaoRelacionada(mysqli $conexao, array $entrada): ?array
{
    $temCiclo = compraHistoricoColunaExiste($conexao, 'movimentacoes', 'ciclo_id')
        && array_key_exists('ciclo_id', $entrada);

    if ($temCiclo) {
        $stmt = $conexao->prepare("
            SELECT id
            FROM movimentacoes
            WHERE ciclo_id <=> ?
              AND produto_id = ?
              AND tipo = 'entrada_fornecedor'
              AND quantidade = ?
            ORDER BY ABS(TIMESTAMPDIFF(SECOND, data_movimentacao, ?)) ASC, id DESC
            LIMIT 1
        ");
        $stmt->bind_param('iids', $entrada['ciclo_id'], $entrada['produto_id'], $entrada['quantidade'], $entrada['data_entrada']);
    } else {
        $stmt = $conexao->prepare("
            SELECT id
            FROM movimentacoes
            WHERE produto_id = ?
              AND tipo = 'entrada_fornecedor'
              AND quantidade = ?
            ORDER BY ABS(TIMESTAMPDIFF(SECOND, data_movimentacao, ?)) ASC, id DESC
            LIMIT 1
        ");
        $stmt->bind_param('ids', $entrada['produto_id'], $entrada['quantidade'], $entrada['data_entrada']);
    }

    $stmt->execute();
    $movimentacao = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $movimentacao;
}

function compraHistoricoRemoverOperacionalOs(mysqli $conexao, string $numeroOs): int
{
    $entradas = compraHistoricoBuscarEntradasOs($conexao, $numeroOs, true);
    if (!$entradas) {
        throw new RuntimeException('Compra nao encontrada no historico.');
    }

    foreach ($entradas as $entrada) {
        $movimentacao = compraHistoricoBuscarMovimentacaoRelacionada($conexao, $entrada);
        if ($movimentacao) {
            $stmtMov = $conexao->prepare("DELETE FROM movimentacoes WHERE id = ?");
            $stmtMov->bind_param('i', $movimentacao['id']);
            $stmtMov->execute();
            $stmtMov->close();
        }
    }

    $stmtEntradas = $conexao->prepare("DELETE FROM entradas WHERE tipo = 'entrada_fornecedor' AND numero_os = ?");
    $stmtEntradas->bind_param('s', $numeroOs);
    $stmtEntradas->execute();
    $removidas = $stmtEntradas->affected_rows;
    $stmtEntradas->close();

    if (
        compraHistoricoTabelaExiste($conexao, 'abates')
        && compraHistoricoColunaExiste($conexao, 'abates', 'previsao_id')
        && compraHistoricoColunaExiste($conexao, 'abates', 'tipo')
    ) {
        $stmtAbates = $conexao->prepare("
            DELETE a
            FROM abates a
            INNER JOIN previsao_fornecedor pf ON pf.id = a.previsao_id
            WHERE a.tipo = 'fornecedor'
              AND pf.numero_os = ?
        ");
        $stmtAbates->bind_param('s', $numeroOs);
        $stmtAbates->execute();
        $stmtAbates->close();
    }

    $stmtPrevisao = $conexao->prepare("DELETE FROM previsao_fornecedor WHERE numero_os = ?");
    $stmtPrevisao->bind_param('s', $numeroOs);
    $stmtPrevisao->execute();
    $stmtPrevisao->close();

    if (function_exists('pedidoCompraRemoverSnapshot')) {
        pedidoCompraRemoverSnapshot($conexao, $numeroOs);
    }

    return $removidas;
}

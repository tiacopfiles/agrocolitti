<?php

function colunaTabelaPrecoFlexExiste(mysqli $conexao, string $tabela, string $coluna): bool
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

function garantirTabelasPrecoFlex(mysqli $conexao): void
{
    $conexao->query("
        CREATE TABLE IF NOT EXISTS tabelas_preco (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(150) NOT NULL,
            tipo VARCHAR(50) NOT NULL DEFAULT 'personalizada',
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!colunaTabelaPrecoFlexExiste($conexao, 'tabelas_preco', 'ativo')) {
        $conexao->query("ALTER TABLE tabelas_preco ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1");
    }

    $conexao->query("
        CREATE TABLE IF NOT EXISTS tabelas_preco_colunas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tabela_id INT NOT NULL,
            nome_coluna VARCHAR(150) NOT NULL,
            ordem INT NOT NULL DEFAULT 0,
            INDEX idx_tpc_tabela (tabela_id),
            CONSTRAINT fk_tpc_tabela FOREIGN KEY (tabela_id) REFERENCES tabelas_preco(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conexao->query("
        CREATE TABLE IF NOT EXISTS tabelas_preco_linhas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tabela_id INT NOT NULL,
            dados_json JSON NOT NULL,
            ordem INT NOT NULL DEFAULT 0,
            INDEX idx_tpl_tabela (tabela_id),
            CONSTRAINT fk_tpl_tabela FOREIGN KEY (tabela_id) REFERENCES tabelas_preco(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

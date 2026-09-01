<?php
/**
 * Database Migrations for AgroColitti Admin
 * Mantém a infraestrutura mínima do painel sem quebrar o fluxo principal.
 */

if (!isset($conexao) || !($conexao instanceof mysqli)) {
    require_once __DIR__ . '/../../bootstrap/conexao.php';
}

if (!isset($GLOBALS['_admin_migration_done'])) {
    if (isset($conexao) && $conexao instanceof mysqli) {
        $sql = "
        CREATE TABLE IF NOT EXISTS `logs_auditoria` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `usuario_id` INT DEFAULT NULL,
          `usuario_nome` VARCHAR(100) DEFAULT NULL,
          `acao` VARCHAR(100) NOT NULL,
          `tabela` VARCHAR(100) DEFAULT NULL,
          `registro_id` INT DEFAULT NULL,
          `descricao` TEXT DEFAULT NULL,
          `ip` VARCHAR(45) DEFAULT NULL,
          `criado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_usuario (usuario_id),
          INDEX idx_acao (acao),
          INDEX idx_criado_em (criado_em),
          INDEX idx_ip (ip),
          INDEX idx_acao_data (acao, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        @$conexao->query($sql);

        $colunasLogs = [
            'usuario_nivel' => "VARCHAR(50) DEFAULT NULL",
            'user_agent' => "VARCHAR(500) DEFAULT NULL",
            'sistema_origem' => "VARCHAR(120) DEFAULT NULL",
            'rota' => "VARCHAR(255) DEFAULT NULL",
            'metodo' => "VARCHAR(12) DEFAULT NULL",
            'autorizado' => "TINYINT(1) DEFAULT NULL",
        ];

        foreach ($colunasLogs as $coluna => $definicao) {
            $colunaSegura = $conexao->real_escape_string($coluna);
            $existe = @$conexao->query("SHOW COLUMNS FROM logs_auditoria LIKE '{$colunaSegura}'");
            if ($existe && $existe->num_rows === 0) {
                @$conexao->query("ALTER TABLE logs_auditoria ADD COLUMN {$coluna} {$definicao}");
            }
            if ($existe instanceof mysqli_result) {
                $existe->free();
            }
        }

        @$conexao->query(
            "ALTER TABLE usuarios MODIFY COLUMN nivel
             ENUM('admin','ti','colheita','fornecedor','operacional','operador','cliente') NOT NULL"
        );
    }
    $GLOBALS['_admin_migration_done'] = true;
}

<?php
require "../../bootstrap/conexao.php";

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
  INDEX idx_criado_em (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

if ($conexao->query($sql)) {
    echo "Tabela criada com sucesso (ou já existia).";
} else {
    echo "Erro ao criar tabela: " . $conexao->error;
}

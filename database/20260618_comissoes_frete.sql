CREATE TABLE IF NOT EXISTS `comissoes_frete` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `numero_os` VARCHAR(50) NOT NULL,
    `entreposto` VARCHAR(80) NOT NULL,
    `venda_id` INT NULL,
    `nfe_documento_id` BIGINT UNSIGNED NULL,
    `contas_integracao_id` BIGINT UNSIGNED NULL,
    `valor_liquido` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `contabilizado_em` DATETIME NULL,
    `atribuido_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_comissoes_frete_numero_os` (`numero_os`),
    KEY `idx_comissoes_frete_entreposto` (`entreposto`),
    KEY `idx_comissoes_frete_atribuido_em` (`atribuido_em`),
    KEY `idx_comissoes_frete_venda_id` (`venda_id`),
    KEY `idx_comissoes_frete_nfe_documento_id` (`nfe_documento_id`),
    KEY `idx_comissoes_frete_contas_integracao_id` (`contas_integracao_id`),
    CONSTRAINT `fk_comissoes_frete_venda`
        FOREIGN KEY (`venda_id`) REFERENCES `vendas` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_comissoes_frete_nfe_documento`
        FOREIGN KEY (`nfe_documento_id`) REFERENCES `nfe_documentos` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_comissoes_frete_contas_integracao`
        FOREIGN KEY (`contas_integracao_id`) REFERENCES `contas_integracoes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `migrations` (`nome`) VALUES ('2026_06_18_comissoes_frete');

ALTER TABLE `comissoes_frete`
    ADD COLUMN `nfe_documento_id` BIGINT UNSIGNED NULL AFTER `venda_id`,
    ADD COLUMN `contas_integracao_id` BIGINT UNSIGNED NULL AFTER `nfe_documento_id`,
    ADD COLUMN `valor_liquido` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `contas_integracao_id`,
    ADD COLUMN `contabilizado_em` DATETIME NULL AFTER `valor_liquido`,
    ADD KEY `idx_comissoes_frete_nfe_documento_id` (`nfe_documento_id`),
    ADD KEY `idx_comissoes_frete_contas_integracao_id` (`contas_integracao_id`),
    ADD CONSTRAINT `fk_comissoes_frete_nfe_documento`
        FOREIGN KEY (`nfe_documento_id`) REFERENCES `nfe_documentos` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_comissoes_frete_contas_integracao`
        FOREIGN KEY (`contas_integracao_id`) REFERENCES `contas_integracoes` (`id`) ON DELETE SET NULL;

INSERT IGNORE INTO `migrations` (`nome`) VALUES ('2026_06_18_comissoes_frete_contas_receber');

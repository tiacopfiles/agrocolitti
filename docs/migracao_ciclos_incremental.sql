-- Migração incremental de ciclos para o AgroColitti
-- Objetivo:
-- 1. Preparar o banco sem apagar histórico
-- 2. Permitir rastreamento por ciclo nas tabelas operacionais
-- 3. Habilitar snapshots para a nova aba de ciclos

START TRANSACTION;

ALTER TABLE `ciclos`
  ADD COLUMN IF NOT EXISTS `fechado_em` timestamp NULL DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `aberto_em` timestamp NOT NULL DEFAULT current_timestamp() AFTER `fechado_em`;

ALTER TABLE `vendas`
  ADD COLUMN IF NOT EXISTS `ciclo_id` int(11) DEFAULT NULL AFTER `id`;

ALTER TABLE `entradas`
  ADD COLUMN IF NOT EXISTS `ciclo_id` int(11) DEFAULT NULL AFTER `id`;

ALTER TABLE `movimentacoes`
  ADD COLUMN IF NOT EXISTS `ciclo_id` int(11) DEFAULT NULL AFTER `id`;

ALTER TABLE `previsao_fornecedor`
  ADD COLUMN IF NOT EXISTS `ciclo_id` int(11) DEFAULT NULL AFTER `id`;

ALTER TABLE `previsao_colheita`
  ADD COLUMN IF NOT EXISTS `ciclo_id` int(11) DEFAULT NULL AFTER `id`;

ALTER TABLE `abates`
  ADD COLUMN IF NOT EXISTS `ciclo_id` int(11) DEFAULT NULL AFTER `id`;

CREATE TABLE IF NOT EXISTS `ciclo_snapshot` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ciclo_id` int(11) NOT NULL,
  `total_vendas_kg` decimal(12,2) DEFAULT 0.00,
  `total_entradas_kg` decimal(12,2) DEFAULT 0.00,
  `total_abates_kg` decimal(12,2) DEFAULT 0.00,
  `total_previsoes_forn` decimal(12,2) DEFAULT 0.00,
  `total_previsoes_colh` decimal(12,2) DEFAULT 0.00,
  `qtd_vendas` int(11) DEFAULT 0,
  `qtd_entradas` int(11) DEFAULT 0,
  `qtd_abates` int(11) DEFAULT 0,
  `estoque_final_json` longtext DEFAULT NULL,
  `gerado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ciclo_snapshot_ciclo_id` (`ciclo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @ciclo_ativo_id := (
  SELECT id
  FROM ciclos
  WHERE ativo = 1
  ORDER BY id DESC
  LIMIT 1
);

UPDATE `vendas` SET `ciclo_id` = @ciclo_ativo_id WHERE `ciclo_id` IS NULL;
UPDATE `entradas` SET `ciclo_id` = @ciclo_ativo_id WHERE `ciclo_id` IS NULL;
UPDATE `movimentacoes` SET `ciclo_id` = @ciclo_ativo_id WHERE `ciclo_id` IS NULL;
UPDATE `previsao_fornecedor` SET `ciclo_id` = @ciclo_ativo_id WHERE `ciclo_id` IS NULL;
UPDATE `previsao_colheita` SET `ciclo_id` = @ciclo_ativo_id WHERE `ciclo_id` IS NULL;
UPDATE `abates` SET `ciclo_id` = @ciclo_ativo_id WHERE `ciclo_id` IS NULL;

ALTER TABLE `vendas`
  ADD INDEX IF NOT EXISTS `idx_vendas_ciclo_id` (`ciclo_id`);

ALTER TABLE `entradas`
  ADD INDEX IF NOT EXISTS `idx_entradas_ciclo_id` (`ciclo_id`);

ALTER TABLE `movimentacoes`
  ADD INDEX IF NOT EXISTS `idx_movimentacoes_ciclo_id` (`ciclo_id`);

ALTER TABLE `previsao_fornecedor`
  ADD INDEX IF NOT EXISTS `idx_pf_ciclo_id` (`ciclo_id`);

ALTER TABLE `previsao_colheita`
  ADD INDEX IF NOT EXISTS `idx_pc_ciclo_id` (`ciclo_id`);

ALTER TABLE `abates`
  ADD INDEX IF NOT EXISTS `idx_abates_ciclo_id` (`ciclo_id`);

-- Adicione as FKs apenas se o banco local já estiver consistente.
-- Exemplo:
-- ALTER TABLE `vendas` ADD CONSTRAINT `vendas_ciclo_fk` FOREIGN KEY (`ciclo_id`) REFERENCES `ciclos`(`id`);
-- ALTER TABLE `entradas` ADD CONSTRAINT `entradas_ciclo_fk` FOREIGN KEY (`ciclo_id`) REFERENCES `ciclos`(`id`);
-- ALTER TABLE `movimentacoes` ADD CONSTRAINT `movimentacoes_ciclo_fk` FOREIGN KEY (`ciclo_id`) REFERENCES `ciclos`(`id`);
-- ALTER TABLE `previsao_fornecedor` ADD CONSTRAINT `pf_ciclo_fk` FOREIGN KEY (`ciclo_id`) REFERENCES `ciclos`(`id`);
-- ALTER TABLE `previsao_colheita` ADD CONSTRAINT `pc_ciclo_fk` FOREIGN KEY (`ciclo_id`) REFERENCES `ciclos`(`id`);
-- ALTER TABLE `abates` ADD CONSTRAINT `abates_ciclo_fk` FOREIGN KEY (`ciclo_id`) REFERENCES `ciclos`(`id`);

COMMIT;


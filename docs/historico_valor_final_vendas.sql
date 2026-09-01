-- Etapa 8-10: edicao controlada do valor final no Historico de Vendas.
-- O sistema cria estas colunas automaticamente quando o historico/action e acessado.

ALTER TABLE `vendas`
  ADD COLUMN `valor_final_original` DECIMAL(12,2) NULL DEFAULT NULL,
  ADD COLUMN `valor_final_editado` DECIMAL(12,2) NULL DEFAULT NULL,
  ADD COLUMN `valor_final_editado_por` VARCHAR(120) NULL DEFAULT NULL,
  ADD COLUMN `valor_final_editado_em` DATETIME NULL DEFAULT NULL;

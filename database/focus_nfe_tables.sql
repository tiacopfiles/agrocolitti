-- Focus NFe integration tables for AgroColitti_NFe
-- Database: projeto_2
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS `focus_config` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ambiente` ENUM('homologacao','producao') NOT NULL DEFAULT 'producao',
  `base_url` VARCHAR(255) NOT NULL DEFAULT 'https://api.focusnfe.com.br',
  `token_homologacao` TEXT NULL,
  `token_producao` TEXT NULL,
  `cnpj_emitente` VARCHAR(14) NULL,
  `nome_emitente` VARCHAR(255) NULL,
  `inscricao_estadual_emitente` VARCHAR(20) NULL,
  `serie_nfe` VARCHAR(10) NOT NULL DEFAULT '1',
  `natureza_operacao_padrao` VARCHAR(120) NOT NULL DEFAULT 'Venda de mercadoria',
  `regime_tributario_emitente` TINYINT UNSIGNED NULL,
  `modalidade_frete_padrao` TINYINT UNSIGNED NOT NULL DEFAULT 9,
  `webhook_secret` VARCHAR(255) NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_focus_config_ambiente` (`ambiente`),
  KEY `idx_focus_config_ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `operacoes_fiscais` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `descricao` VARCHAR(160) NOT NULL,
  `tipo_origem_mercadoria` ENUM('producao_propria','revenda') NOT NULL,
  `destino_uf_regra` ENUM('SP','OUTRA_UF') NOT NULL,
  `cfop` CHAR(4) NOT NULL,
  `icms_origem` CHAR(1) NOT NULL DEFAULT '0',
  `icms_cst` CHAR(2) NOT NULL DEFAULT '40',
  `codigo_beneficio_fiscal` VARCHAR(20) NULL,
  `pis_cst` CHAR(2) NOT NULL DEFAULT '06',
  `cofins_cst` CHAR(2) NOT NULL DEFAULT '06',
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_operacao_fiscal_regra` (`tipo_origem_mercadoria`, `destino_uf_regra`),
  KEY `idx_operacoes_fiscais_cfop` (`cfop`),
  KEY `idx_operacoes_fiscais_ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nfe_documentos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref` VARCHAR(80) NOT NULL,
  `venda_id` INT(11) NULL,
  `numero_os` VARCHAR(50) NULL,
  `cliente_id` INT(11) NULL,
  `ambiente` ENUM('homologacao','producao') NOT NULL DEFAULT 'producao',
  `status` ENUM('rascunho','enviada','processando','autorizada','rejeitada','cancelada','erro') NOT NULL DEFAULT 'rascunho',
  `chave_nfe` VARCHAR(60) NULL,
  `numero_nfe` VARCHAR(30) NULL,
  `serie` VARCHAR(10) NULL,
  `protocolo` VARCHAR(80) NULL,
  `caminho_xml` VARCHAR(500) NULL,
  `email_xml_status` VARCHAR(20) NULL,
  `email_xml_destinatario` VARCHAR(800) NULL,
  `email_xml_tentativas` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `email_xml_enviado_em` DATETIME NULL,
  `email_xml_erro` VARCHAR(1000) NULL,
  `caminho_danfe` VARCHAR(500) NULL,
  `mensagem_sefaz` TEXT NULL,
  `payload_json` LONGTEXT NULL,
  `resposta_json` LONGTEXT NULL,
  `emitida_em` DATETIME NULL,
  `autorizada_em` DATETIME NULL,
  `cancelada_em` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_nfe_documentos_ref_ambiente` (`ref`, `ambiente`),
  KEY `idx_nfe_documentos_venda` (`venda_id`),
  KEY `idx_nfe_documentos_numero_os` (`numero_os`),
  KEY `idx_nfe_documentos_cliente` (`cliente_id`),
  KEY `idx_nfe_documentos_status` (`status`),
  KEY `idx_nfe_documentos_chave` (`chave_nfe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nfe_tentativas` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nfe_documento_id` BIGINT UNSIGNED NULL,
  `ref` VARCHAR(80) NOT NULL,
  `tipo` ENUM('emitir','consultar','cancelar','carta_correcao','webhook') NOT NULL,
  `metodo_http` VARCHAR(10) NULL,
  `endpoint` VARCHAR(500) NULL,
  `http_code` SMALLINT UNSIGNED NULL,
  `payload_resumido` LONGTEXT NULL,
  `resposta_json` LONGTEXT NULL,
  `erro` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_nfe_tentativas_documento` (`nfe_documento_id`),
  KEY `idx_nfe_tentativas_ref` (`ref`),
  KEY `idx_nfe_tentativas_tipo` (`tipo`),
  KEY `idx_nfe_tentativas_http_code` (`http_code`),
  CONSTRAINT `fk_nfe_tentativas_documento` FOREIGN KEY (`nfe_documento_id`) REFERENCES `nfe_documentos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `focus_webhooks_recebidos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `evento` VARCHAR(80) NULL,
  `ref` VARCHAR(80) NULL,
  `nfe_documento_id` BIGINT UNSIGNED NULL,
  `headers_json` LONGTEXT NULL,
  `payload_json` LONGTEXT NOT NULL,
  `processado` TINYINT(1) NOT NULL DEFAULT 0,
  `processado_em` DATETIME NULL,
  `erro` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_focus_webhooks_ref` (`ref`),
  KEY `idx_focus_webhooks_evento` (`evento`),
  KEY `idx_focus_webhooks_processado` (`processado`),
  KEY `idx_focus_webhooks_documento` (`nfe_documento_id`),
  CONSTRAINT `fk_focus_webhooks_documento` FOREIGN KEY (`nfe_documento_id`) REFERENCES `nfe_documentos` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `operacoes_fiscais`
  (`descricao`, `tipo_origem_mercadoria`, `destino_uf_regra`, `cfop`, `icms_origem`, `icms_cst`, `codigo_beneficio_fiscal`, `pis_cst`, `cofins_cst`, `ativo`)
VALUES
  ('Venda dentro do estado - producao propria', 'producao_propria', 'SP', '5101', '0', '40', 'SP010360', '06', '06', 1),
  ('Venda dentro do estado - mercadoria de revenda', 'revenda', 'SP', '5102', '0', '40', 'SP010360', '06', '06', 1),
  ('Venda fora do estado - producao propria', 'producao_propria', 'OUTRA_UF', '6101', '0', '40', 'SP010360', '06', '06', 1),
  ('Venda fora do estado - mercadoria de revenda', 'revenda', 'OUTRA_UF', '6102', '0', '40', 'SP010360', '06', '06', 1)
ON DUPLICATE KEY UPDATE
  `descricao` = VALUES(`descricao`),
  `cfop` = VALUES(`cfop`),
  `icms_origem` = VALUES(`icms_origem`),
  `icms_cst` = VALUES(`icms_cst`),
  `codigo_beneficio_fiscal` = VALUES(`codigo_beneficio_fiscal`),
  `pis_cst` = VALUES(`pis_cst`),
  `cofins_cst` = VALUES(`cofins_cst`),
  `ativo` = VALUES(`ativo`),
  `updated_at` = CURRENT_TIMESTAMP;

INSERT INTO `focus_config`
  (`ambiente`, `base_url`, `serie_nfe`, `natureza_operacao_padrao`, `modalidade_frete_padrao`, `ativo`)
VALUES
  ('producao', 'https://api.focusnfe.com.br', '1', 'Venda de mercadoria', 9, 1)
ON DUPLICATE KEY UPDATE
  `base_url` = VALUES(`base_url`),
  `serie_nfe` = VALUES(`serie_nfe`),
  `natureza_operacao_padrao` = VALUES(`natureza_operacao_padrao`),
  `modalidade_frete_padrao` = VALUES(`modalidade_frete_padrao`),
  `ativo` = VALUES(`ativo`),
  `updated_at` = CURRENT_TIMESTAMP;

-- ============================================================
-- AgroColitti — Script de Migrations
-- Execute UMA VEZ ao atualizar o sistema.
-- Não inclua ALTER TABLE em arquivos PHP de runtime.
-- ============================================================

-- ── Tabela de controle de migrations ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `migrations` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `nome`       VARCHAR(200)  NOT NULL,
    `executada_em` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── vendas: colunas extras ────────────────────────────────────────────────────
ALTER TABLE `vendas`
    ADD COLUMN IF NOT EXISTS `tipo_comercial`          ENUM('embalado','atacado','atacado_convencional') NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `prazo_escolhido`         VARCHAR(10)                    NULL     DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `preco_base`              DECIMAL(10,2)                  NULL     DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `desconto_percentual`     DECIMAL(8,4)                   NULL     DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `tipo_aplicacao_desconto` ENUM('acrescimo','desconto')   NULL     DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `preco_manual`            TINYINT(1)                     NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `prazo_pagamento`         VARCHAR(100)                   NULL     DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `forma_pagamento`         ENUM('boleto','deposito','pix') NULL    DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `previsao_entrega`        DATE                           NULL     DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `entreposto`              VARCHAR(255)                   NULL     DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `consideracoes`           TEXT                           NULL,
    ADD COLUMN IF NOT EXISTS `numero_os`               VARCHAR(50)                    NULL     DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `vendedor`                VARCHAR(100)                   NOT NULL DEFAULT '';

-- ── OBA Embalado por caixas ───────────────────────────────────────────────────
-- bandejas_por_caixa no item da venda (valor escolhido, usado na descricao "CX C/N").
ALTER TABLE `vendas`
    ADD COLUMN IF NOT EXISTS `bandejas_por_caixa` INT NULL DEFAULT NULL;
-- Opcoes de bandejas por caixa por produto OBA (ex.: "15,6"). Default "6".
ALTER TABLE `preco_oba_embalado`
    ADD COLUMN IF NOT EXISTS `bandejas_por_caixa` VARCHAR(20) NOT NULL DEFAULT '6';
-- Populacao inicial por nome (depois editavel produto a produto).
UPDATE `preco_oba_embalado` po JOIN `produtos` pr ON pr.id = po.produto_id
    SET po.bandejas_por_caixa = '15,6' WHERE UPPER(pr.nome) LIKE '%GRAPE%';
UPDATE `preco_oba_embalado` po JOIN `produtos` pr ON pr.id = po.produto_id
    SET po.bandejas_por_caixa = '18,6' WHERE UPPER(pr.nome) LIKE '%ITALIANO%';
UPDATE `preco_oba_embalado` po JOIN `produtos` pr ON pr.id = po.produto_id
    SET po.bandejas_por_caixa = '15,6' WHERE UPPER(pr.nome) LIKE '%COCKTAIL%' OR UPPER(pr.nome) LIKE '%COQUETEL%';

-- ── Índices de performance — vendas ──────────────────────────────────────────
-- Evita full-table scan nos filtros mais comuns.
CREATE INDEX IF NOT EXISTS `idx_vendas_status`      ON `vendas` (`status`);
CREATE INDEX IF NOT EXISTS `idx_vendas_ciclo_id`    ON `vendas` (`ciclo_id`);
CREATE INDEX IF NOT EXISTS `idx_vendas_produto_id`  ON `vendas` (`produto_id`);
CREATE INDEX IF NOT EXISTS `idx_vendas_cliente_id`  ON `vendas` (`cliente_id`);
CREATE INDEX IF NOT EXISTS `idx_vendas_data_venda`  ON `vendas` (`data_venda`);

-- ── Índices — entradas ────────────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS `idx_entradas_ciclo_id`   ON `entradas` (`ciclo_id`);
CREATE INDEX IF NOT EXISTS `idx_entradas_produto_id` ON `entradas` (`produto_id`);

-- ── Índices — ciclos ──────────────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS `idx_ciclos_ativo`  ON `ciclos` (`ativo`);
CREATE INDEX IF NOT EXISTS `idx_ciclos_status` ON `ciclos` (`status`);

-- ── Índices — logs_auditoria ──────────────────────────────────────────────────
CREATE INDEX IF NOT EXISTS `idx_logs_criado_em`   ON `logs_auditoria` (`criado_em`);
CREATE INDEX IF NOT EXISTS `idx_logs_acao`        ON `logs_auditoria` (`acao`);
CREATE INDEX IF NOT EXISTS `idx_logs_usuario_id`  ON `logs_auditoria` (`usuario_id`);
CREATE INDEX IF NOT EXISTS `idx_logs_ip`          ON `logs_auditoria` (`ip`);

-- ── Índices — previsoes ───────────────────────────────────────────────────────
ALTER TABLE `previsao_fornecedor`
    ADD COLUMN IF NOT EXISTS `preco` DECIMAL(10,2) NULL DEFAULT NULL AFTER `quantidade_prevista`,
    ADD COLUMN IF NOT EXISTS `forma_pagamento` VARCHAR(50) NULL DEFAULT NULL AFTER `data_prevista`;

-- O status e expandido pelo helper PHP preservando valores existentes no banco.

CREATE INDEX IF NOT EXISTS `idx_prev_forn_ciclo`   ON `previsao_fornecedor` (`ciclo_id`);
CREATE INDEX IF NOT EXISTS `idx_prev_colh_ciclo`   ON `previsao_colheita`   (`ciclo_id`);

-- ── preco_atacado ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `preco_atacado` (
    `id`            INT          NOT NULL AUTO_INCREMENT,
    `produto_id`    INT          NOT NULL,
    `categoria`     VARCHAR(100) NOT NULL DEFAULT '',
    `gramagem`      DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    `valor_mp`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `kg_caixa`      DECIMAL(10,2) NOT NULL DEFAULT 20.00,
    `acrescimo_35`  DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    `frete_kauauti` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    `frete_nivaldo` DECIMAL(10,4) NOT NULL DEFAULT 0.3000,
    `prazo_5_dias`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `prazo_30_dias` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `ativo`         TINYINT(1)   NOT NULL DEFAULT 1,
    `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_produto` (`produto_id`),
    CONSTRAINT `fk_pa_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `preco_atacado`
    ADD COLUMN IF NOT EXISTS `categoria`     VARCHAR(100)  NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS `gramagem`      DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    ADD COLUMN IF NOT EXISTS `valor_mp`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `kg_caixa`      DECIMAL(10,2) NOT NULL DEFAULT 20.00,
    ADD COLUMN IF NOT EXISTS `acrescimo_35`  DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    ADD COLUMN IF NOT EXISTS `frete_kauauti` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    ADD COLUMN IF NOT EXISTS `frete_nivaldo` DECIMAL(10,4) NOT NULL DEFAULT 0.3000,
    ADD COLUMN IF NOT EXISTS `prazo_5_dias`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `prazo_30_dias` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN IF NOT EXISTS `ativo`         TINYINT(1)    NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS `updated_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Atacado Convencional: mesma base do atacado, com percentual financeiro do cliente aplicado na venda.
CREATE TABLE IF NOT EXISTS `preco_atacado_convencional` LIKE `preco_atacado`;
ALTER TABLE `preco_atacado_convencional`
    ADD COLUMN IF NOT EXISTS `unidade_comercial` VARCHAR(50) NOT NULL DEFAULT '' AFTER `valor_mp`;

-- ── preco_embalado ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `preco_embalado` (
    `id`              INT          NOT NULL AUTO_INCREMENT,
    `produto_id`      INT          NOT NULL,
    `gramagem`        DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    `valor_mp`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `qtd_por_caixa`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `categoria`       VARCHAR(100)  NOT NULL DEFAULT '',
    `custo_mp`        DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    `frete_kauauti`   DECIMAL(10,4) NOT NULL DEFAULT 0.3250,
    `frete_nivaldo`   DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    `mao_de_obra`     DECIMAL(10,4) NOT NULL DEFAULT 1.2500,
    `preco_calculado` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `custo_p_grama`   DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
    `custo_bd`        DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    `kg_da_caixa`     DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    `ativo`           TINYINT(1)    NOT NULL DEFAULT 1,
    `updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_produto` (`produto_id`),
    CONSTRAINT `fk_pe_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── cliente_percentual_financeiro ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `cliente_percentual_financeiro` (
    `id`             INT         NOT NULL AUTO_INCREMENT,
    `cliente_id`     INT         NOT NULL,
    `percentual`     DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    `tipo_aplicacao` ENUM('acrescimo','desconto') NOT NULL DEFAULT 'acrescimo',
    `updated_at`     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cliente` (`cliente_id`),
    CONSTRAINT `fk_cpf_cliente` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Registra que a migration foi executada ────────────────────────────────────
INSERT IGNORE INTO `migrations` (`nome`) VALUES ('2024_01_vendas_colunas_extras');
INSERT IGNORE INTO `migrations` (`nome`) VALUES ('2024_02_indices_performance');
INSERT IGNORE INTO `migrations` (`nome`) VALUES ('2024_03_tabelas_precificacao');
INSERT IGNORE INTO `migrations` (`nome`) VALUES ('2026_05_vendas_entreposto');

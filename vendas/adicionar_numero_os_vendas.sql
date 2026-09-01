-- Migracao complementar da tela de vendas.
-- Execute no banco do servidor antes de usar o fluxo de OS/pedido de venda.
-- Este formato funciona em MySQL/MariaDB e nao falha quando a coluna ja existe.

SET @schema_name = DATABASE();

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE vendas ADD COLUMN numero_os VARCHAR(50) NULL DEFAULT NULL AFTER data_venda',
    'SELECT ''Coluna numero_os ja existe'' AS aviso'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'vendas' AND COLUMN_NAME = 'numero_os'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE vendas ADD COLUMN prazo_pagamento VARCHAR(100) NULL DEFAULT NULL AFTER numero_os',
    'SELECT ''Coluna prazo_pagamento ja existe'' AS aviso'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'vendas' AND COLUMN_NAME = 'prazo_pagamento'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE vendas ADD COLUMN forma_pagamento VARCHAR(50) NULL DEFAULT NULL AFTER prazo_pagamento',
    'SELECT ''Coluna forma_pagamento ja existe'' AS aviso'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'vendas' AND COLUMN_NAME = 'forma_pagamento'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE vendas ADD COLUMN previsao_entrega DATE NULL DEFAULT NULL AFTER forma_pagamento',
    'SELECT ''Coluna previsao_entrega ja existe'' AS aviso'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'vendas' AND COLUMN_NAME = 'previsao_entrega'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE vendas ADD COLUMN consideracoes TEXT NULL DEFAULT NULL AFTER previsao_entrega',
    'SELECT ''Coluna consideracoes ja existe'' AS aviso'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'vendas' AND COLUMN_NAME = 'consideracoes'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE vendas ADD COLUMN vendedor VARCHAR(150) NULL DEFAULT NULL AFTER consideracoes',
    'SELECT ''Coluna vendedor ja existe'' AS aviso'
  )
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'vendas' AND COLUMN_NAME = 'vendedor'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'CREATE INDEX idx_vendas_numero_os ON vendas (numero_os)',
    'SELECT ''Indice idx_vendas_numero_os ja existe'' AS aviso'
  )
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'vendas' AND INDEX_NAME = 'idx_vendas_numero_os'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Etapa 4: snapshots de pedidos de compra por OS.
-- Mantem os dados essenciais do documento para reimpressao mesmo se cadastros forem alterados depois.

CREATE TABLE IF NOT EXISTS `pedido_compra_documentos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `numero_os` VARCHAR(50) NOT NULL,
  `fornecedor_id` INT NULL,
  `snapshot_json` LONGTEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_pedido_compra_numero_os` (`numero_os`),
  KEY `idx_pedido_compra_fornecedor_id` (`fornecedor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

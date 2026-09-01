ALTER TABLE vendas
ADD COLUMN tipo VARCHAR(20) NULL AFTER cliente_id;

UPDATE vendas
SET tipo = CASE
    WHEN gramagem IS NOT NULL AND gramagem > 0 THEN 'bandeja'
    WHEN kg_caixa IS NOT NULL AND kg_caixa > 0 THEN 'caixa'
    ELSE 'kg'
END
WHERE tipo IS NULL;

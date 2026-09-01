<?php

function colunaPrevisaoFornecedorExiste(mysqli $conexao, string $coluna): bool
{
    $coluna = $conexao->real_escape_string($coluna);
    $resultado = $conexao->query("SHOW COLUMNS FROM `previsao_fornecedor` LIKE '{$coluna}'");
    $existe = $resultado && $resultado->num_rows > 0;

    if ($resultado instanceof mysqli_result) {
        $resultado->free();
    }

    return $existe;
}

function garantirFluxoFinanceiroPrevisaoFornecedor(mysqli $conexao): void
{
    try {
        if (!colunaPrevisaoFornecedorExiste($conexao, 'preco')) {
            $conexao->query("ALTER TABLE `previsao_fornecedor` ADD COLUMN `preco` DECIMAL(10,2) NULL DEFAULT NULL AFTER `quantidade_prevista`");
        }

        if (!colunaPrevisaoFornecedorExiste($conexao, 'quantidade_recebida')) {
            $conexao->query("ALTER TABLE `previsao_fornecedor` ADD COLUMN `quantidade_recebida` DECIMAL(10,2) NULL DEFAULT NULL AFTER `status`");
        }

        if (!colunaPrevisaoFornecedorExiste($conexao, 'motivo_abatimento')) {
            $conexao->query("ALTER TABLE `previsao_fornecedor` ADD COLUMN `motivo_abatimento` VARCHAR(255) NULL DEFAULT NULL AFTER `quantidade_recebida`");
        }

        if (!colunaPrevisaoFornecedorExiste($conexao, 'forma_pagamento')) {
            $conexao->query("ALTER TABLE `previsao_fornecedor` ADD COLUMN `forma_pagamento` VARCHAR(50) NULL DEFAULT NULL AFTER `data_prevista`");
        }

        if (!colunaPrevisaoFornecedorExiste($conexao, 'revenda_sao_paulo')) {
            $conexao->query("ALTER TABLE `previsao_fornecedor` ADD COLUMN `revenda_sao_paulo` TINYINT(1) NOT NULL DEFAULT 0 AFTER `forma_pagamento`");
        }

        if (!colunaPrevisaoFornecedorExiste($conexao, 'prazo_pagamento')) {
            $conexao->query("ALTER TABLE `previsao_fornecedor` ADD COLUMN `prazo_pagamento` VARCHAR(100) NULL DEFAULT NULL AFTER `revenda_sao_paulo`");
        }

        if (!colunaPrevisaoFornecedorExiste($conexao, 'entreposto')) {
            $conexao->query("ALTER TABLE `previsao_fornecedor` ADD COLUMN `entreposto` VARCHAR(100) NULL DEFAULT NULL AFTER `prazo_pagamento`");
        }

        $resultado = $conexao->query("SHOW COLUMNS FROM `previsao_fornecedor` LIKE 'status'");
        $status = $resultado ? $resultado->fetch_assoc() : null;
        if ($resultado instanceof mysqli_result) {
            $resultado->free();
        }

        $tipoOriginal = (string) ($status['Type'] ?? '');
        $tipo = strtolower($tipoOriginal);
        if (strpos($tipo, 'enum') !== false && strpos($tipo, "'anexado'") === false) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $tipoOriginal, $matches);
            $valores = array_map(static function (string $valor): string {
                return stripcslashes($valor);
            }, $matches[1] ?? []);

            if (!in_array('anexado', $valores, true)) {
                array_unshift($valores, 'anexado');
            }
            if (!in_array('pendente', $valores, true)) {
                $valores[] = 'pendente';
            }

            $enumSql = implode(',', array_map(static function (string $valor) use ($conexao): string {
                return "'" . $conexao->real_escape_string($valor) . "'";
            }, $valores));

            $conexao->query("ALTER TABLE `previsao_fornecedor` MODIFY `status` ENUM({$enumSql}) DEFAULT 'pendente'");
        }
    } catch (mysqli_sql_exception $e) {
        throw new RuntimeException('Falha ao atualizar a estrutura de previsão fornecedor: ' . $e->getMessage(), 0, $e);
    }
}

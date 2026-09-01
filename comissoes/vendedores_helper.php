<?php

function comissoesVendedoresLista(): array
{
    return [
        'Bruno' => 0.00,
        'Mayara' => 3.00,
        'Fabiana' => 3.00,
        'Nilza' => 3.00,
        'João' => 5.00,
    ];
}

function comissoesVendedoresNormalizarNome(string $vendedor): ?string
{
    $informado = trim($vendedor);
    $informadoSemAcento = comissoesVendedoresNormalizarComparacao($informado);
    foreach (array_keys(comissoesVendedoresLista()) as $permitido) {
        if ($informadoSemAcento === comissoesVendedoresNormalizarComparacao($permitido)) {
            return $permitido;
        }
    }
    return null;
}

function comissoesVendedoresNormalizarComparacao(string $valor): string
{
    $valor = trim($valor);
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
    if (is_string($ascii) && $ascii !== '') {
        $valor = $ascii;
    }
    return mb_strtolower($valor, 'UTF-8');
}

function comissoesVendedoresPercentual(string $vendedor): ?float
{
    $nome = comissoesVendedoresNormalizarNome($vendedor);
    if ($nome === null) return null;
    $lista = comissoesVendedoresLista();
    return (float) $lista[$nome];
}

function comissoesVendedoresGarantirEstrutura(mysqli $conexao): void
{
    $coluna = $conexao->query("SHOW COLUMNS FROM vendas LIKE 'vendedor_usuario_id'");
    $temColuna = $coluna && $coluna->num_rows > 0;
    if ($coluna instanceof mysqli_result) $coluna->free();
    if (!$temColuna) {
        $conexao->query("ALTER TABLE vendas ADD COLUMN vendedor_usuario_id INT NULL DEFAULT NULL AFTER vendedor");
    }

    $conexao->query("
        CREATE TABLE IF NOT EXISTS comissoes_vendedores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            numero_os VARCHAR(50) NOT NULL,
            vendedor_usuario_id INT NULL,
            vendedor_nome VARCHAR(120) NOT NULL,
            percentual DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            valor_base DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            valor_comissao DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            venda_id INT NULL,
            nfe_documento_id INT NOT NULL,
            contas_integracao_id INT NOT NULL,
            contabilizado_em DATETIME NOT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_comissoes_vendedores_os (numero_os),
            KEY idx_comissoes_vendedores_nome (vendedor_nome),
            KEY idx_comissoes_vendedores_nfe (nfe_documento_id),
            KEY idx_comissoes_vendedores_contas (contas_integracao_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function comissoesVendedoresResolverPorOs(mysqli $conexao, string $numeroOs): array
{
    $numeroOs = trim($numeroOs);
    $resolver = static function (array $linhas, string $origem): array {
        $pares = [];
        foreach ($linhas as $linha) {
            if (!empty($linha['_json_invalido'])) return ['status' => 'json_invalido', 'origem' => $origem];
            $nome = comissoesVendedoresNormalizarNome((string) ($linha['vendedor'] ?? ''));
            if ($nome === null) continue;
            $usuarioId = isset($linha['vendedor_usuario_id']) && (int) $linha['vendedor_usuario_id'] > 0 ? (int) $linha['vendedor_usuario_id'] : null;
            $pares[$nome . ':' . ($usuarioId ?? 'NULL')] = ['vendedor' => $nome, 'vendedor_usuario_id' => $usuarioId];
        }
        if (count($pares) === 1) return array_values($pares)[0] + ['status' => 'resolvido', 'origem' => $origem];
        return ['status' => count($pares) > 1 ? 'ambiguo' : 'ausente', 'origem' => $origem];
    };

    $stmt = $conexao->prepare('SELECT id, vendedor, vendedor_usuario_id FROM vendas WHERE numero_os = ? ORDER BY id ASC');
    $stmt->bind_param('s', $numeroOs);
    $stmt->execute();
    $linhas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $ativo = $resolver($linhas, 'vendas');
    if ($ativo['status'] !== 'ausente') {
        $ativo['venda_id'] = count($linhas) === 1 ? (int) $linhas[0]['id'] : null;
        return $ativo;
    }

    $stmt = $conexao->prepare("SELECT payload_json FROM ciclo_snapshot_registros WHERE numero_os = ? AND origem_tabela = 'vendas' ORDER BY id ASC");
    $stmt->bind_param('s', $numeroOs);
    $stmt->execute();
    $snapshots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $linhasSnapshot = [];
    foreach ($snapshots as $snapshot) {
        $payload = json_decode((string) $snapshot['payload_json'], true);
        $linhasSnapshot[] = is_array($payload) ? $payload : ['_json_invalido' => true];
    }
    return $resolver($linhasSnapshot, 'snapshot') + ['venda_id' => null];
}

function comissoesVendedoresRegistrarEnvio(
    mysqli $conexao,
    string $numeroOs,
    string $vendedor,
    ?int $vendedorUsuarioId,
    ?int $vendaId,
    int $nfeDocumentoId,
    int $contasIntegracaoId,
    float $valorBase
): bool
{
    $numeroOs = trim($numeroOs);
    $vendedorNome = comissoesVendedoresNormalizarNome($vendedor);
    if ($numeroOs === '') {
        throw new InvalidArgumentException('Numero da OS obrigatorio para registrar a comissao do vendedor.');
    }
    if ($vendedorNome === null) {
        throw new InvalidArgumentException('Vendedor invalido para registrar a comissao.');
    }
    if ($nfeDocumentoId <= 0 || $contasIntegracaoId <= 0) {
        throw new InvalidArgumentException('NF-e e integracao do contas a receber sao obrigatorias para registrar a comissao do vendedor.');
    }
    if ($valorBase < 0) {
        throw new InvalidArgumentException('Valor base invalido para registrar a comissao do vendedor.');
    }

    $percentual = comissoesVendedoresPercentual($vendedorNome) ?? 0.0;
    $valorComissao = round($valorBase * ($percentual / 100), 2);
    $vendaIdValido = $vendaId !== null && $vendaId > 0 ? $vendaId : null;
    $usuarioIdValido = $vendedorUsuarioId !== null && $vendedorUsuarioId > 0 ? $vendedorUsuarioId : null;

    $existente = $conexao->prepare('SELECT id FROM comissoes_vendedores WHERE nfe_documento_id = ? LIMIT 1');
    $existente->bind_param('i', $nfeDocumentoId);
    $existente->execute();
    $jaContabilizada = $existente->get_result()->fetch_assoc();
    $existente->close();
    if ($jaContabilizada) return false;

    $stmt = $conexao->prepare("
        INSERT INTO comissoes_vendedores
            (numero_os, vendedor_usuario_id, vendedor_nome, percentual, valor_base, valor_comissao, venda_id, nfe_documento_id, contas_integracao_id, contabilizado_em)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param(
        'sisdddiii',
        $numeroOs,
        $usuarioIdValido,
        $vendedorNome,
        $percentual,
        $valorBase,
        $valorComissao,
        $vendaIdValido,
        $nfeDocumentoId,
        $contasIntegracaoId
    );
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        if ((int) $e->getCode() !== 1062) throw $e;
        $duplicada = $conexao->prepare('SELECT id FROM comissoes_vendedores WHERE nfe_documento_id = ? LIMIT 1');
        $duplicada->bind_param('i', $nfeDocumentoId);
        $duplicada->execute();
        $existeAgora = $duplicada->get_result()->fetch_assoc();
        $duplicada->close();
        if (!$existeAgora) throw $e;
        $stmt->close();
        return false;
    }
    $stmt->close();
    return true;
}

function comissoesVendedoresStatusLabel(string $status): string
{
    $labels = [
        'anexado' => 'Em montagem',
        'pendente' => 'Pendente',
        'concluido' => 'Concluida',
        'cancelado' => 'Cancelada',
    ];
    return $labels[$status] ?? ($status !== '' ? ucfirst($status) : 'Sem status');
}

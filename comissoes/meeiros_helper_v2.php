<?php

function comissoesMeeirosNomesFixos(): array
{
    return ['Antonio Portugal', 'Antonio Carlos', 'William', 'Jose Valdeci', 'Alex'];
}

function comissoesMeeirosQualidadesPermitidas(): array
{
    return ['2a', '3a'];
}

function comissoesMeeirosProdutosComQualidade(): array
{
    return [];
}

function comissoesMeeirosProdutoPermiteQualidade(string $produtoNome): bool
{
    return trim($produtoNome) !== '';
}

function comissoesMeeirosQualidadeNormalizada(?string $qualidade): string
{
    $qualidade = strtolower(trim((string) $qualidade));
    return match ($qualidade) {
        '2', '2a', 'segunda', 'segunda classe' => '2a',
        '3', '3a', 'terceira', 'terceira classe' => '3a',
        default => '',
    };
}

function comissoesMeeirosNormalizar(string $valor): string
{
    $valor = trim($valor);
    $semAcento = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
    $semAcento = $semAcento !== false ? $semAcento : $valor;
    $semAcento = preg_replace('/\s+/', ' ', $semAcento);
    return strtolower((string) $semAcento);
}

function comissoesMeeirosColunaExiste(mysqli $conexao, string $tabela, string $coluna): bool
{
    $tabelasPermitidas = ['previsao_colheita', 'abates', 'tabela_precos_meeiros', 'comissoes_meeiros'];
    if (!in_array($tabela, $tabelasPermitidas, true) || !preg_match('/^[a-zA-Z0-9_]+$/', $coluna)) {
        return false;
    }

    $colunaSql = $conexao->real_escape_string($coluna);
    $resultado = $conexao->query("SHOW COLUMNS FROM `$tabela` LIKE '{$colunaSql}'");
    $existe = $resultado && $resultado->num_rows > 0;
    if ($resultado instanceof mysqli_result) {
        $resultado->free();
    }
    return $existe;
}

function comissoesMeeirosIndiceExiste(mysqli $conexao, string $tabela, string $indice): bool
{
    $tabelasPermitidas = ['tabela_precos_meeiros', 'comissoes_meeiros'];
    if (!in_array($tabela, $tabelasPermitidas, true) || !preg_match('/^[a-zA-Z0-9_]+$/', $indice)) {
        return false;
    }

    $indiceSql = $conexao->real_escape_string($indice);
    $resultado = $conexao->query("SHOW INDEX FROM `$tabela` WHERE Key_name = '{$indiceSql}'");
    $existe = $resultado && $resultado->num_rows > 0;
    if ($resultado instanceof mysqli_result) {
        $resultado->free();
    }
    return $existe;
}

function comissoesMeeirosIndiceColunas(mysqli $conexao, string $tabela, string $indice): array
{
    $tabelasPermitidas = ['tabela_precos_meeiros', 'comissoes_meeiros'];
    if (!in_array($tabela, $tabelasPermitidas, true) || !preg_match('/^[a-zA-Z0-9_]+$/', $indice)) {
        return [];
    }

    $indiceSql = $conexao->real_escape_string($indice);
    $resultado = $conexao->query("SHOW INDEX FROM `$tabela` WHERE Key_name = '{$indiceSql}'");
    $linhas = [];
    while ($resultado && ($row = $resultado->fetch_assoc())) {
        $linhas[] = $row;
    }
    if ($resultado instanceof mysqli_result) {
        $resultado->free();
    }

    usort($linhas, static function (array $a, array $b): int {
        return ((int) ($a['Seq_in_index'] ?? 0)) <=> ((int) ($b['Seq_in_index'] ?? 0));
    });

    $colunas = [];
    foreach ($linhas as $row) {
        $colunas[] = (string) $row['Column_name'];
    }
    return $colunas;
}

function comissoesMeeirosGarantirEstrutura(mysqli $conexao): void
{
    $conexao->query("
        CREATE TABLE IF NOT EXISTS meeiros (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(120) NOT NULL,
            documento VARCHAR(40) NULL,
            telefone VARCHAR(40) NULL,
            observacoes TEXT NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_meeiros_nome (nome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conexao->query("
        CREATE TABLE IF NOT EXISTS tabela_precos_meeiros (
            id INT AUTO_INCREMENT PRIMARY KEY,
            produto_id INT NULL,
            produto_nome VARCHAR(180) NOT NULL,
            qualidade_label VARCHAR(10) NOT NULL DEFAULT '',
            periodo_label VARCHAR(30) NOT NULL DEFAULT '',
            valor_por_kg DECIMAL(12,2) NOT NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_por INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_preco_meeiro_produto_periodo (produto_nome, qualidade_label, periodo_label),
            KEY idx_preco_meeiro_produto_id (produto_id),
            KEY idx_preco_meeiro_ativo (ativo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conexao->query("
        CREATE TABLE IF NOT EXISTS comissoes_meeiros (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeiro_id INT NOT NULL,
            previsao_colheita_id INT NOT NULL,
            produto_id INT NOT NULL,
            numero_pedido VARCHAR(80) NOT NULL,
            qualidade_comercial VARCHAR(10) NOT NULL DEFAULT '',
            data_confirmacao DATETIME NOT NULL,
            quantidade_prevista DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            descarte_kg DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            peso_entrada_kg DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            valor_por_kg_snapshot DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            preco_final DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            percentual_comissao DECIMAL(5,2) NOT NULL DEFAULT 30.00,
            valor_comissao DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(30) NOT NULL DEFAULT 'confirmada',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_comissao_meeiro_previsao_qualidade (previsao_colheita_id, qualidade_comercial),
            KEY idx_comissao_meeiro (meeiro_id),
            KEY idx_comissao_meeiro_pedido (numero_pedido),
            KEY idx_comissao_meeiro_data (data_confirmacao)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!comissoesMeeirosColunaExiste($conexao, 'previsao_colheita', 'meeiro_id')) {
        $conexao->query("ALTER TABLE previsao_colheita ADD COLUMN meeiro_id INT NULL AFTER produto_id");
    }
    if (!comissoesMeeirosColunaExiste($conexao, 'previsao_colheita', 'numero_pedido')) {
        $conexao->query("ALTER TABLE previsao_colheita ADD COLUMN numero_pedido VARCHAR(80) NULL AFTER meeiro_id");
    }
    if (!comissoesMeeirosColunaExiste($conexao, 'abates', 'numero_pedido')) {
        $conexao->query("ALTER TABLE abates ADD COLUMN numero_pedido VARCHAR(80) NULL AFTER previsao_id");
    }
    if (!comissoesMeeirosColunaExiste($conexao, 'abates', 'qualidade_comercial')) {
        $conexao->query("ALTER TABLE abates ADD COLUMN qualidade_comercial VARCHAR(10) NULL AFTER numero_pedido");
    }
    if (comissoesMeeirosColunaExiste($conexao, 'abates', 'qualidade_comercial')) {
        $conexao->query("ALTER TABLE abates MODIFY COLUMN qualidade_comercial VARCHAR(160) NULL");
    }
    if (!comissoesMeeirosColunaExiste($conexao, 'tabela_precos_meeiros', 'qualidade_label')) {
        $conexao->query("ALTER TABLE tabela_precos_meeiros ADD COLUMN qualidade_label VARCHAR(10) NOT NULL DEFAULT '' AFTER produto_nome");
    }
    if (!comissoesMeeirosColunaExiste($conexao, 'comissoes_meeiros', 'qualidade_comercial')) {
        $conexao->query("ALTER TABLE comissoes_meeiros ADD COLUMN qualidade_comercial VARCHAR(10) NOT NULL DEFAULT '' AFTER numero_pedido");
    }
    $colunasIndicePreco = comissoesMeeirosIndiceColunas($conexao, 'tabela_precos_meeiros', 'uq_preco_meeiro_produto_periodo');
    if (!$colunasIndicePreco) {
        $conexao->query("ALTER TABLE tabela_precos_meeiros ADD UNIQUE KEY uq_preco_meeiro_produto_periodo (produto_nome, qualidade_label, periodo_label)");
    } elseif ($colunasIndicePreco !== ['produto_nome', 'qualidade_label', 'periodo_label']) {
        $conexao->query("ALTER TABLE tabela_precos_meeiros DROP INDEX uq_preco_meeiro_produto_periodo");
        $conexao->query("ALTER TABLE tabela_precos_meeiros ADD UNIQUE KEY uq_preco_meeiro_produto_periodo (produto_nome, qualidade_label, periodo_label)");
    }
    if (comissoesMeeirosIndiceExiste($conexao, 'comissoes_meeiros', 'uq_comissao_meeiro_previsao')) {
        $conexao->query("ALTER TABLE comissoes_meeiros DROP INDEX uq_comissao_meeiro_previsao");
    }
    if (!comissoesMeeirosIndiceExiste($conexao, 'comissoes_meeiros', 'uq_comissao_meeiro_previsao_qualidade')) {
        $conexao->query("ALTER TABLE comissoes_meeiros ADD UNIQUE KEY uq_comissao_meeiro_previsao_qualidade (previsao_colheita_id, qualidade_comercial)");
    }

    foreach (comissoesMeeirosNomesFixos() as $nome) {
        $stmt = $conexao->prepare("
            INSERT INTO meeiros (nome, ativo)
            VALUES (?, 1)
            ON DUPLICATE KEY UPDATE ativo = 1
        ");
        $stmt->bind_param('s', $nome);
        $stmt->execute();
        $stmt->close();
    }

    comissoesMeeirosMigrarTabelaPrecosQualidade($conexao);
    comissoesMeeirosSemearTabelaPrecos($conexao);
    comissoesMeeirosVincularPrecosProdutos($conexao);
}

function comissoesMeeirosPrecosPadrao(): array
{
    return [
        ['Tomate italiano', '2a', 3.00],
        ['Tomate italiano', '3a', 4.00],
        ['Tomate salada', '2a', 3.00],
        ['Tomate salada', '3a', 4.00],
        ['Abobora espaguete', '', 10.00],
        ['Abobora Yasmin', '', 6.00],
        ['Abobrinha Italiana', '', 5.00],
        ['Beringela Japonesa', '', 5.80],
        ['Beringela Angela ( Rajada)', '', 4.50],
        ['Beringela Sharapova (Comum)', '', 4.00],
        ['Ervilha torta', '', 14.00],
        ['Grape Pera', '', 10.00],
        ['Grape San Marzano', '', 3.00],
        ['Grape amarelo', '', 10.00],
        ['Pepino Japones', '2a', 1.80],
        ['Pepino Japones', '3a', 5.50],
    ];
}

function comissoesMeeirosSemearTabelaPrecos(mysqli $conexao): void
{
    foreach (comissoesMeeirosPrecosPadrao() as [$produtoNome, $qualidade, $valor]) {
        $produtoId = comissoesMeeirosResolverProdutoId($conexao, $produtoNome);
        $periodo = '';
        $stmt = $conexao->prepare("
            INSERT INTO tabela_precos_meeiros (produto_id, produto_nome, qualidade_label, periodo_label, valor_por_kg, ativo)
            VALUES (?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE produto_id = COALESCE(produto_id, VALUES(produto_id))
        ");
        $stmt->bind_param('isssd', $produtoId, $produtoNome, $qualidade, $periodo, $valor);
        $stmt->execute();
        $stmt->close();
    }
}

function comissoesMeeirosMigrarTabelaPrecosQualidade(mysqli $conexao): void
{
    $res = $conexao->query("
        SELECT id, produto_nome
        FROM tabela_precos_meeiros
        WHERE COALESCE(qualidade_label, '') = ''
    ");
    while ($res && ($row = $res->fetch_assoc())) {
        $produtoNome = trim((string) $row['produto_nome']);
        if (!preg_match('/\b(2a|3a)\b/i', $produtoNome, $m)) {
            continue;
        }

        $qualidade = strtolower($m[1]);
        $baseNome = trim((string) preg_replace('/\s*\b(2a|3a)\b\s*$/i', '', $produtoNome));
        if ($baseNome === '') {
            continue;
        }

        $produtoId = comissoesMeeirosResolverProdutoId($conexao, $baseNome);
        $id = (int) $row['id'];
        $stmt = $conexao->prepare("
            UPDATE tabela_precos_meeiros
            SET produto_nome = ?, qualidade_label = ?, produto_id = ?
            WHERE id = ?
        ");
        $stmt->bind_param('ssii', $baseNome, $qualidade, $produtoId, $id);
        $stmt->execute();
        $stmt->close();
    }
}

function comissoesMeeirosResolverProdutoId(mysqli $conexao, string $produtoNome): ?int
{
    $normalizado = comissoesMeeirosNormalizarProduto($produtoNome);
    $res = $conexao->query("SELECT id, nome FROM produtos WHERE ativo = 1 AND produto_principal_id IS NULL");
    $candidatos = [];
    while ($row = $res->fetch_assoc()) {
        $produtoNormalizado = comissoesMeeirosNormalizarProduto((string) $row['nome']);
        if ($produtoNormalizado === $normalizado) {
            return (int) $row['id'];
        }
        $candidatos[] = [
            'id' => (int) $row['id'],
            'nome' => (string) $row['nome'],
            'normalizado' => $produtoNormalizado,
        ];
    }

    $compacto = str_replace(' ', '', $normalizado);
    foreach ($candidatos as $produto) {
        if (str_replace(' ', '', $produto['normalizado']) === $compacto) {
            return (int) $produto['id'];
        }
    }

    foreach ($candidatos as $produto) {
        $nomeProduto = $produto['normalizado'];
        if (strlen($normalizado) >= 5 && strlen($nomeProduto) >= 5) {
            if (str_contains($nomeProduto, $normalizado) || str_contains($normalizado, $nomeProduto)) {
                return (int) $produto['id'];
            }
        }
    }
    return null;
}

function comissoesMeeirosNormalizarProduto(string $valor): string
{
    $valor = comissoesMeeirosNormalizar($valor);
    $valor = str_replace(['ª', 'º'], ['a', 'o'], $valor);
    $valor = preg_replace('/\b(kg|kgs|quilo|quilos|g|gr|grs|grama|gramas)\b/', ' ', (string) $valor);
    $valor = preg_replace('/[^a-z0-9]+/', ' ', (string) $valor);
    $valor = preg_replace('/\s+/', ' ', (string) $valor);
    return trim((string) $valor);
}

function comissoesMeeirosVincularPrecosProdutos(mysqli $conexao): void
{
    $res = $conexao->query("SELECT id, produto_nome FROM tabela_precos_meeiros WHERE produto_id IS NULL OR produto_id <= 0");
    while ($res && ($row = $res->fetch_assoc())) {
        $produtoId = comissoesMeeirosResolverProdutoId($conexao, (string) $row['produto_nome']);
        if ($produtoId === null || $produtoId <= 0) {
            continue;
        }

        $id = (int) $row['id'];
        $stmt = $conexao->prepare("UPDATE tabela_precos_meeiros SET produto_id = ? WHERE id = ?");
        $stmt->bind_param('ii', $produtoId, $id);
        $stmt->execute();
        $stmt->close();
    }
}

function comissoesMeeirosListarAtivos(mysqli $conexao): array
{
    comissoesMeeirosGarantirEstrutura($conexao);
    $res = $conexao->query("SELECT id, nome FROM meeiros WHERE ativo = 1 ORDER BY nome ASC");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function comissoesMeeirosNomePorId(mysqli $conexao, int $meeiroId): string
{
    $stmt = $conexao->prepare("SELECT nome FROM meeiros WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $meeiroId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (string) ($row['nome'] ?? '');
}

function comissoesMeeirosPrecoProduto(mysqli $conexao, int $produtoId, string $produtoNome): ?float
{
    $stmt = $conexao->prepare("
        SELECT valor_por_kg
        FROM tabela_precos_meeiros
        WHERE ativo = 1 AND produto_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param('i', $produtoId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return (float) $row['valor_por_kg'];
    }

    $normalizado = comissoesMeeirosNormalizarProduto($produtoNome);
    $res = $conexao->query("SELECT produto_nome, qualidade_label, valor_por_kg FROM tabela_precos_meeiros WHERE ativo = 1 ORDER BY id DESC");
    while ($preco = $res->fetch_assoc()) {
        if (comissoesMeeirosNormalizarProduto((string) $preco['produto_nome']) === $normalizado) {
            return (float) $preco['valor_por_kg'];
        }
    }

    return null;
}

function comissoesMeeirosPrecoProdutoQualidade(mysqli $conexao, int $produtoId, string $produtoNome, string $qualidade = ''): ?float
{
    $qualidade = comissoesMeeirosQualidadeNormalizada($qualidade);
    $stmt = $conexao->prepare("
        SELECT valor_por_kg
        FROM tabela_precos_meeiros
        WHERE ativo = 1 AND produto_id = ? AND COALESCE(qualidade_label, '') = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param('is', $produtoId, $qualidade);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return (float) $row['valor_por_kg'];
    }

    return comissoesMeeirosPrecoProduto($conexao, $produtoId, $produtoNome);
}

function comissoesMeeirosNormalizarDistribuicaoQualidades(array $distribuicao, bool $exigeQualidade, float $pesoEntradaKg): array
{
    if (!$exigeQualidade) {
        return [[
            'qualidade' => '',
            'peso_kg' => $pesoEntradaKg,
        ]];
    }

    $linhas = [];
    foreach ($distribuicao as $linha) {
        if (!is_array($linha)) {
            continue;
        }

        $qualidade = comissoesMeeirosQualidadeNormalizada($linha['qualidade_comercial'] ?? $linha['qualidade'] ?? '');
        $peso = (float) str_replace(',', '.', (string) ($linha['peso_kg'] ?? $linha['peso'] ?? 0));
        if ($peso <= 0) {
            continue;
        }
        if ($qualidade === '') {
            throw new InvalidArgumentException('Informe a qualidade comercial de todas as linhas preenchidas.');
        }
        if (!in_array($qualidade, comissoesMeeirosQualidadesPermitidas(), true)) {
            throw new InvalidArgumentException('Qualidade comercial invalida. Use 2a ou 3a.');
        }
        if (!isset($linhas[$qualidade])) {
            $linhas[$qualidade] = [
                'qualidade' => $qualidade,
                'peso_kg' => 0.0,
            ];
        }
        $linhas[$qualidade]['peso_kg'] += $peso;
    }

    if (!$linhas) {
        throw new InvalidArgumentException('Informe ao menos uma qualidade comercial para este produto.');
    }

    $soma = array_sum(array_map(static fn(array $linha): float => (float) $linha['peso_kg'], $linhas));
    if (abs($soma - $pesoEntradaKg) > 0.01) {
        $somaFormatada = number_format($soma, 2, ',', '.');
        $pesoFormatado = number_format($pesoEntradaKg, 2, ',', '.');
        throw new InvalidArgumentException("A soma das qualidades ({$somaFormatada} kg) deve ser igual a quantidade real final ({$pesoFormatado} kg).");
    }

    return array_values($linhas);
}

function comissoesMeeirosRegistrarConfirmacao(
    mysqli $conexao,
    array $previsao,
    string $numeroPedido,
    string $qualidadeComercial,
    float $quantidadeOriginal,
    float $descarteKg,
    float $pesoEntradaKg
): void {
    $distribuicao = [[
        'qualidade_comercial' => $qualidadeComercial,
        'peso_kg' => $pesoEntradaKg,
    ]];
    comissoesMeeirosRegistrarConfirmacaoDistribuida(
        $conexao,
        $previsao,
        $numeroPedido,
        $distribuicao,
        $quantidadeOriginal,
        $descarteKg,
        $pesoEntradaKg
    );
}

function comissoesMeeirosRegistrarConfirmacaoDistribuida(
    mysqli $conexao,
    array $previsao,
    string $numeroPedido,
    array $distribuicaoQualidades,
    float $quantidadeOriginal,
    float $descarteKg,
    float $pesoEntradaKg
): void {
    $meeiroId = (int) ($previsao['meeiro_id'] ?? 0);
    $produtoId = (int) ($previsao['produto_id'] ?? 0);
    if ($meeiroId <= 0) {
        throw new InvalidArgumentException('Selecione um meeiro antes de confirmar a colheita.');
    }
    if ($numeroPedido === '') {
        throw new InvalidArgumentException('Numero do pedido obrigatorio para confirmar a colheita.');
    }

    $stmtProduto = $conexao->prepare("SELECT nome FROM produtos WHERE id = ? LIMIT 1");
    $stmtProduto->bind_param('i', $produtoId);
    $stmtProduto->execute();
    $produto = $stmtProduto->get_result()->fetch_assoc();
    $stmtProduto->close();
    $produtoNome = (string) ($produto['nome'] ?? '');
    $linhas = comissoesMeeirosNormalizarDistribuicaoQualidades(
        $distribuicaoQualidades,
        comissoesMeeirosProdutoPermiteQualidade($produtoNome),
        $pesoEntradaKg
    );
    $percentual = 30.00;
    $previsaoId = (int) $previsao['id'];

    $stmtDelete = $conexao->prepare("DELETE FROM comissoes_meeiros WHERE previsao_colheita_id = ?");
    $stmtDelete->bind_param('i', $previsaoId);
    $stmtDelete->execute();
    $stmtDelete->close();

    $stmt = $conexao->prepare("
        INSERT INTO comissoes_meeiros
            (meeiro_id, previsao_colheita_id, produto_id, numero_pedido, qualidade_comercial, data_confirmacao,
             quantidade_prevista, descarte_kg, peso_entrada_kg, valor_por_kg_snapshot,
             preco_final, percentual_comissao, valor_comissao, status)
        VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, 'confirmada')
        ON DUPLICATE KEY UPDATE
            meeiro_id = VALUES(meeiro_id),
            produto_id = VALUES(produto_id),
            numero_pedido = VALUES(numero_pedido),
            qualidade_comercial = VALUES(qualidade_comercial),
            quantidade_prevista = VALUES(quantidade_prevista),
            descarte_kg = VALUES(descarte_kg),
            peso_entrada_kg = VALUES(peso_entrada_kg),
            valor_por_kg_snapshot = VALUES(valor_por_kg_snapshot),
            preco_final = VALUES(preco_final),
            percentual_comissao = VALUES(percentual_comissao),
            valor_comissao = VALUES(valor_comissao),
            status = VALUES(status)
    ");
    foreach ($linhas as $linha) {
        $qualidadeComercial = (string) $linha['qualidade'];
        $pesoLinha = round((float) $linha['peso_kg'], 2);
        $descarteLinha = $pesoEntradaKg > 0 ? round($descarteKg * ($pesoLinha / $pesoEntradaKg), 2) : 0.0;
        $valorKg = comissoesMeeirosPrecoProdutoQualidade($conexao, $produtoId, $produtoNome, $qualidadeComercial);
        if ($valorKg === null || $valorKg <= 0) {
            $qualidadeMsg = $qualidadeComercial !== '' ? " ({$qualidadeComercial})" : '';
            throw new InvalidArgumentException("Produto sem preco na tabela de meeiros{$qualidadeMsg}.");
        }

        $precoFinal = round($pesoLinha * $valorKg, 2);
        $valorComissao = round($precoFinal * ($percentual / 100), 2);
        $stmt->bind_param(
            'iiissddddddd',
            $meeiroId,
            $previsaoId,
            $produtoId,
            $numeroPedido,
            $qualidadeComercial,
            $quantidadeOriginal,
            $descarteLinha,
            $pesoLinha,
            $valorKg,
            $precoFinal,
            $percentual,
            $valorComissao
        );
        $stmt->execute();
    }
    $stmt->close();
}

function comissoesMeeirosData(?string $data): string
{
    if (!$data) {
        return '-';
    }
    return date('d/m/Y', strtotime($data));
}

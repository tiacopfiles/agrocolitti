<?php

function tabelaExiste(mysqli $conexao, string $tabela): bool
{
    static $cache = [];

    $chave = 'table:' . $tabela;
    if (array_key_exists($chave, $cache)) {
        return $cache[$chave];
    }

    $stmt = $conexao->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $tabela);
    $stmt->execute();
    $resultado = $stmt->get_result();

    $cache[$chave] = $resultado && $resultado->num_rows > 0;

    $stmt->close();

    return $cache[$chave];
}

function colunaExiste(mysqli $conexao, string $tabela, string $coluna): bool
{
    static $cache = [];

    $chave = $tabela . '.' . $coluna;
    if (array_key_exists($chave, $cache)) {
        return $cache[$chave];
    }

    if (!tabelaExiste($conexao, $tabela)) {
        $cache[$chave] = false;
        return false;
    }

    $stmt = $conexao->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $tabela, $coluna);
    $stmt->execute();
    $resultado = $stmt->get_result();

    $cache[$chave] = $resultado && $resultado->num_rows > 0;

    $stmt->close();

    return $cache[$chave];
}

function getCicloAtivo(mysqli $conexao): ?array
{
    if (!tabelaExiste($conexao, 'ciclos')) {
        return null;
    }

    $sql = "
        SELECT *
        FROM ciclos
        WHERE ativo = 1
          AND status = 'aberto'
        ORDER BY ano DESC, mes DESC, id DESC
        LIMIT 1
    ";

    $resultado = $conexao->query($sql);
    if ($resultado && $resultado->num_rows > 0) {
        return $resultado->fetch_assoc();
    }

    $sqlFallback = "
        SELECT *
        FROM ciclos
        WHERE status = 'aberto'
        ORDER BY ano DESC, mes DESC, id DESC
        LIMIT 1
    ";

    $resultadoFallback = $conexao->query($sqlFallback);
    if ($resultadoFallback && $resultadoFallback->num_rows > 0) {
        return $resultadoFallback->fetch_assoc();
    }

    return null;
}

function getCicloPorAnoMes(mysqli $conexao, int $ano, int $mes): ?array
{
    if (!tabelaExiste($conexao, 'ciclos')) {
        return null;
    }

    $stmt = $conexao->prepare("
        SELECT *
        FROM ciclos
        WHERE ano = ? AND mes = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param("ii", $ano, $mes);
    $stmt->execute();
    $ciclo = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $ciclo;
}

function getCicloFechadoAnterior(mysqli $conexao, array $cicloAtual): ?array
{
    if (!tabelaExiste($conexao, 'ciclos')) {
        return null;
    }

    $anoAtual = (int) ($cicloAtual['ano'] ?? 0);
    $mesAtual = (int) ($cicloAtual['mes'] ?? 0);
    $idAtual = (int) ($cicloAtual['id'] ?? 0);

    $stmt = $conexao->prepare("
        SELECT *
        FROM ciclos
        WHERE status = 'fechado'
          AND (
                ano < ?
                OR (ano = ? AND mes < ?)
                OR (ano = ? AND mes = ? AND id < ?)
          )
        ORDER BY ano DESC, mes DESC, id DESC
        LIMIT 1
    ");
    $stmt->bind_param("iiiiii", $anoAtual, $anoAtual, $mesAtual, $anoAtual, $mesAtual, $idAtual);
    $stmt->execute();
    $ciclo = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $ciclo;
}

function getCicloAnteriorEsperado(array $cicloAtual): array
{
    $mes = (int) ($cicloAtual['mes'] ?? date('n'));
    $ano = (int) ($cicloAtual['ano'] ?? date('Y'));

    $mesAnterior = $mes - 1;
    $anoAnterior = $ano;

    if ($mesAnterior < 1) {
        $mesAnterior = 12;
        $anoAnterior--;
    }

    return ['ano' => $anoAnterior, 'mes' => $mesAnterior];
}

function getBloqueiosDesfazerFechamento(mysqli $conexao, int $cicloId): array
{
    $bloqueios = [];

    // Estas tabelas indicam que o novo ciclo ja recebeu trabalho real e nao deve ser removido automaticamente.
    $tabelas = [
        'vendas' => 'vendas',
        'entradas' => 'entradas',
        'movimentacoes' => 'movimentacoes',
        'previsao_fornecedor' => 'previsões de fornecedor',
        'previsao_colheita' => 'previsões de colheita',
        'abates' => 'abates',
    ];

    foreach ($tabelas as $tabela => $rotulo) {
        if (!colunaExiste($conexao, $tabela, 'ciclo_id')) {
            continue;
        }

        $sql = "SELECT COUNT(*) AS total FROM {$tabela} WHERE ciclo_id = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->bind_param("i", $cicloId);
        $stmt->execute();
        $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        if ($total > 0) {
            $bloqueios[] = $rotulo;
        }
    }

    return $bloqueios;
}

function getCicloAtivoId(mysqli $conexao): ?int
{
    $ciclo = getCicloAtivo($conexao);
    return $ciclo ? (int) $ciclo['id'] : null;
}

function getNomeCiclo(array $ciclo): string
{
    if (!empty($ciclo['nome'])) {
        return (string) $ciclo['nome'];
    }

    if (!empty($ciclo['mes']) && !empty($ciclo['ano'])) {
        return nomeMes((int) $ciclo['mes']) . ' ' . $ciclo['ano'];
    }

    return 'Ciclo sem nome';
}

function nomeMes(int $mes): string
{
    $meses = [
        1 => 'Janeiro',
        2 => 'Fevereiro',
        3 => 'Março',
        4 => 'Abril',
        5 => 'Maio',
        6 => 'Junho',
        7 => 'Julho',
        8 => 'Agosto',
        9 => 'Setembro',
        10 => 'Outubro',
        11 => 'Novembro',
        12 => 'Dezembro',
    ];

    return $meses[$mes] ?? 'Mês inválido';
}

function getCicloIdParaTabela(mysqli $conexao, string $tabela): ?int
{
    if (!colunaExiste($conexao, $tabela, 'ciclo_id')) {
        return null;
    }

    return getCicloAtivoId($conexao);
}

function montarClausulaCiclo(mysqli $conexao, string $tabela, string $alias, ?int $cicloId): string
{
    if (!$cicloId || !colunaExiste($conexao, $tabela, 'ciclo_id')) {
        return '';
    }

    return " AND {$alias}.ciclo_id = {$cicloId} ";
}

function calcularEstoqueFuturoProdutosDoCiclo(mysqli $conexao, ?int $cicloId, bool $filtrarPorCiclo = true): array
{
    $futuro = [];

    $agregarPrevisao = static function (string $tabela, string $alias, array $statusFuturos) use ($conexao, $cicloId, $filtrarPorCiclo, &$futuro): void {
        if (!tabelaExiste($conexao, $tabela)
            || !colunaExiste($conexao, $tabela, 'produto_id')
            || !colunaExiste($conexao, $tabela, 'quantidade_prevista')) {
            return;
        }

        $where = [];
        $params = [];
        $types = '';

        if (colunaExiste($conexao, $tabela, 'status') && $statusFuturos) {
            $statusSql = implode(',', array_fill(0, count($statusFuturos), '?'));
            $where[] = "{$alias}.status IN ({$statusSql})";
            foreach ($statusFuturos as $status) {
                $params[] = $status;
                $types .= 's';
            }
        }

        if ($filtrarPorCiclo && $cicloId && colunaExiste($conexao, $tabela, 'ciclo_id')) {
            $where[] = "{$alias}.ciclo_id = ?";
            $params[] = $cicloId;
            $types .= 'i';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = $conexao->prepare("
            SELECT {$alias}.produto_id, IFNULL(SUM({$alias}.quantidade_prevista), 0) AS total
            FROM {$tabela} {$alias}
            {$whereSql}
            GROUP BY {$alias}.produto_id
        ");

        if ($types !== '') {
            $bindParams = [];
            foreach ($params as $key => $value) {
                $bindParams[$key] = &$params[$key];
            }
            $stmt->bind_param($types, ...$bindParams);
        }

        $stmt->execute();
        $resultado = $stmt->get_result();
        while ($row = $resultado->fetch_assoc()) {
            $produtoId = (int) $row['produto_id'];
            $futuro[$produtoId] = ($futuro[$produtoId] ?? 0.0) + (float) $row['total'];
        }
        $stmt->close();
    };

    $agregarPrevisao('compras_futuras', 'cf', ['anexado', 'pendente']);
    $agregarPrevisao('previsao_fornecedor', 'pf', ['anexado', 'pendente']);
    $agregarPrevisao('colheita_futura', 'cft', ['pendente']);
    $agregarPrevisao('colheitas_futuras', 'cfts', ['pendente']);
    $agregarPrevisao('previsao_colheita', 'pc', ['pendente']);

    return $futuro;
}

function calcularEstoqueProdutosDoCiclo(mysqli $conexao, ?int $cicloId, bool $incluirVinculados = false, bool $filtrarFuturoPorCiclo = true): array
{
    $produtos = [];

    $temProdutoPrincipal = colunaExiste($conexao, 'produtos', 'produto_principal_id');
    $colunaPrincipal = $temProdutoPrincipal ? 'produto_principal_id' : 'NULL AS produto_principal_id';

    $filtroVinculados = ($temProdutoPrincipal && !$incluirVinculados) ? 'AND produto_principal_id IS NULL' : '';

    $resultadoProdutos = $conexao->query("
        SELECT id, nome, unidade, {$colunaPrincipal}
        FROM produtos
        WHERE ativo = 1 {$filtroVinculados}
        ORDER BY nome ASC
    ");
    if (!$resultadoProdutos) {
        return [];
    }

    $usaCicloEstoqueInicial = $cicloId && colunaExiste($conexao, 'estoque_inicial', 'ciclo_id');
    $estoqueFuturoPorProduto = calcularEstoqueFuturoProdutosDoCiclo($conexao, $cicloId, $filtrarFuturoPorCiclo);

    while ($produto = $resultadoProdutos->fetch_assoc()) {
        $produtoId = (int) $produto['id'];
        $produtoEstoqueId = (int) ($produto['produto_principal_id'] ?? 0);
        if ($produtoEstoqueId <= 0) {
            $produtoEstoqueId = $produtoId;
        }

        if ($usaCicloEstoqueInicial) {
            $stmtInicial = $conexao->prepare("
                SELECT IFNULL(SUM(quantidade), 0) AS quantidade
                FROM estoque_inicial
                WHERE produto_id = ? AND ciclo_id = ?
            ");
            $stmtInicial->bind_param("ii", $produtoEstoqueId, $cicloId);
        } else {
            $stmtInicial = $conexao->prepare("
                SELECT IFNULL(SUM(quantidade), 0) AS quantidade
                FROM estoque_inicial
                WHERE produto_id = ?
            ");
            $stmtInicial->bind_param("i", $produtoEstoqueId);
        }

        $stmtInicial->execute();
        $inicial = (float) ($stmtInicial->get_result()->fetch_assoc()['quantidade'] ?? 0);
        $stmtInicial->close();

        // Fonte unica do saldo operacional: o que existe nos historicos.
        // Ciclos organizam o arquivo, mas nao alteram o calculo corrente.
        $stmtEntradas = $conexao->prepare("
            SELECT IFNULL(SUM(e.quantidade), 0) AS total
            FROM entradas e
            INNER JOIN produtos pe ON pe.id = e.produto_id
            WHERE COALESCE(pe.produto_principal_id, pe.id) = ?
        ");
        $stmtEntradas->bind_param('i', $produtoEstoqueId);
        $stmtEntradas->execute();
        $entradas = (float) ($stmtEntradas->get_result()->fetch_assoc()['total'] ?? 0);
        $stmtEntradas->close();

        $stmtVendas = $conexao->prepare("
            SELECT IFNULL(SUM(v.quantidade), 0) AS total
            FROM vendas v
            INNER JOIN produtos pv ON pv.id = v.produto_id
            WHERE COALESCE(pv.produto_principal_id, pv.id) = ?
              AND v.status = 'concluido'
        ");
        $stmtVendas->bind_param('i', $produtoEstoqueId);
        $stmtVendas->execute();
        $vendas = (float) ($stmtVendas->get_result()->fetch_assoc()['total'] ?? 0);
        $stmtVendas->close();

        $atual = $inicial + $entradas - $vendas;

        $produtos[] = [
            'produto_id' => $produtoId,
            'produto_estoque_id' => $produtoEstoqueId,
            'produto' => $produto['nome'],
            'unidade' => $produto['unidade'] ?? 'kg',
            'estoque' => round($atual, 2),
            'estoque_futuro' => round((float) ($estoqueFuturoPorProduto[$produtoEstoqueId] ?? 0), 2),
        ];
    }

    return $produtos;
}

/**
 * Calcula o saldo exatamente no fim da competencia, removendo do saldo atual
 * as movimentacoes que ja pertencem ao mes seguinte.
 */
function calcularEstoqueProdutosDoCicloNoCorte(
    mysqli $conexao,
    int $cicloId,
    string $fimExclusive
): array {
    $estoque = calcularEstoqueProdutosDoCiclo($conexao, $cicloId);
    if (!$estoque || !colunaExiste($conexao, 'movimentacoes', 'ciclo_id')) {
        return $estoque;
    }

    $stmt = $conexao->prepare("
        SELECT produto_id,
               IFNULL(SUM(
                   CASE
                       WHEN tipo IN ('entrada_fornecedor', 'entrada_colheita') THEN quantidade
                       WHEN tipo = 'venda' THEN -quantidade
                       WHEN tipo = 'ajuste_estoque' THEN quantidade
                       ELSE 0
                   END
               ), 0) AS variacao
        FROM movimentacoes
        WHERE ciclo_id = ? AND data_movimentacao >= ?
        GROUP BY produto_id
    ");
    $stmt->bind_param('is', $cicloId, $fimExclusive);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $variacao = [];
    while ($row = $resultado->fetch_assoc()) {
        $variacao[(int) $row['produto_id']] = (float) $row['variacao'];
    }
    $stmt->close();

    foreach ($estoque as &$item) {
        $produtoEstoqueId = (int) ($item['produto_estoque_id'] ?? $item['produto_id']);
        $item['estoque'] = round(
            (float) $item['estoque'] - (float) ($variacao[$produtoEstoqueId] ?? 0),
            2
        );
    }
    unset($item);

    return $estoque;
}

function calcularResumoCiclo(mysqli $conexao, int $cicloId): array
{
    $usaCicloVendas = colunaExiste($conexao, 'vendas', 'ciclo_id');
    $usaCicloEntradas = colunaExiste($conexao, 'entradas', 'ciclo_id');
    $usaCicloAbates = colunaExiste($conexao, 'abates', 'ciclo_id');
    $usaCicloPF = colunaExiste($conexao, 'previsao_fornecedor', 'ciclo_id');
    $usaCicloPC = colunaExiste($conexao, 'previsao_colheita', 'ciclo_id');

    $sqlVendas = $usaCicloVendas
        ? "SELECT IFNULL(SUM(quantidade), 0) AS total, COUNT(*) AS qtd FROM vendas WHERE status = 'concluido' AND ciclo_id = ?"
        : "SELECT IFNULL(SUM(quantidade), 0) AS total, COUNT(*) AS qtd FROM vendas WHERE status = 'concluido'";
    $stmtVendas = $conexao->prepare($sqlVendas);
    if ($usaCicloVendas) {
        $stmtVendas->bind_param("i", $cicloId);
    }
    $stmtVendas->execute();
    $vendas = $stmtVendas->get_result()->fetch_assoc() ?: ['total' => 0, 'qtd' => 0];
    $stmtVendas->close();

    $sqlEntradas = $usaCicloEntradas
        ? "SELECT IFNULL(SUM(quantidade), 0) AS total, COUNT(*) AS qtd FROM entradas WHERE ciclo_id = ?"
        : "SELECT IFNULL(SUM(quantidade), 0) AS total, COUNT(*) AS qtd FROM entradas";
    $stmtEntradas = $conexao->prepare($sqlEntradas);
    if ($usaCicloEntradas) {
        $stmtEntradas->bind_param("i", $cicloId);
    }
    $stmtEntradas->execute();
    $entradas = $stmtEntradas->get_result()->fetch_assoc() ?: ['total' => 0, 'qtd' => 0];
    $stmtEntradas->close();

    $sqlAbates = $usaCicloAbates
        ? "SELECT IFNULL(SUM(quantidade_abatida), 0) AS total, COUNT(*) AS qtd FROM abates WHERE ciclo_id = ?"
        : "SELECT IFNULL(SUM(quantidade_abatida), 0) AS total, COUNT(*) AS qtd FROM abates";
    $stmtAbates = $conexao->prepare($sqlAbates);
    if ($usaCicloAbates) {
        $stmtAbates->bind_param("i", $cicloId);
    }
    $stmtAbates->execute();
    $abates = $stmtAbates->get_result()->fetch_assoc() ?: ['total' => 0, 'qtd' => 0];
    $stmtAbates->close();

    $sqlPF = $usaCicloPF
        ? "SELECT IFNULL(SUM(quantidade_prevista), 0) AS total FROM previsao_fornecedor WHERE ciclo_id = ?"
        : "SELECT IFNULL(SUM(quantidade_prevista), 0) AS total FROM previsao_fornecedor";
    $stmtPF = $conexao->prepare($sqlPF);
    if ($usaCicloPF) {
        $stmtPF->bind_param("i", $cicloId);
    }
    $stmtPF->execute();
    $pf = $stmtPF->get_result()->fetch_assoc() ?: ['total' => 0];
    $stmtPF->close();

    $sqlPC = $usaCicloPC
        ? "SELECT IFNULL(SUM(quantidade_prevista), 0) AS total FROM previsao_colheita WHERE ciclo_id = ?"
        : "SELECT IFNULL(SUM(quantidade_prevista), 0) AS total FROM previsao_colheita";
    $stmtPC = $conexao->prepare($sqlPC);
    if ($usaCicloPC) {
        $stmtPC->bind_param("i", $cicloId);
    }
    $stmtPC->execute();
    $pc = $stmtPC->get_result()->fetch_assoc() ?: ['total' => 0];
    $stmtPC->close();

    return [
        'total_vendas_kg' => (float) $vendas['total'],
        'qtd_vendas' => (int) $vendas['qtd'],
        'total_entradas_kg' => (float) $entradas['total'],
        'qtd_entradas' => (int) $entradas['qtd'],
        'total_abates_kg' => (float) $abates['total'],
        'qtd_abates' => (int) $abates['qtd'],
        'total_previsoes_forn' => (float) $pf['total'],
        'total_previsoes_colh' => (float) $pc['total'],
        'estoque_final' => calcularEstoqueProdutosDoCiclo($conexao, $cicloId),
    ];
}

function tabelaSnapshotDisponivel(mysqli $conexao): bool
{
    return tabelaExiste($conexao, 'ciclo_snapshot');
}

function salvarSnapshotCiclo(
    mysqli $conexao,
    int $cicloId,
    ?array $mapaFechamento = null,
    ?array $estoqueFinal = null
): void
{
    if (!tabelaSnapshotDisponivel($conexao)) {
        return;
    }

    $resumo = calcularResumoCiclo($conexao, $cicloId);
    if ($mapaFechamento !== null
        || (function_exists('cicloFechamentoColetarRegistros') && function_exists('cicloFechamentoTotais'))) {
        $mapaUsado = $mapaFechamento ?? cicloFechamentoColetarRegistros($conexao, $cicloId);
        $totaisFechamento = cicloFechamentoTotais($mapaUsado);
        $arquivar = $totaisFechamento['arquivar'] ?? [];
        $resumo['total_vendas_kg'] = (float) ($arquivar['venda']['quantidade'] ?? 0);
        $resumo['qtd_vendas'] = (int) ($arquivar['venda']['qtd'] ?? 0);
        $resumo['total_entradas_kg'] = (float) ($arquivar['entrada']['quantidade'] ?? 0);
        $resumo['qtd_entradas'] = (int) ($arquivar['entrada']['qtd'] ?? 0);
        $resumo['total_abates_kg'] = (float) ($arquivar['abate']['quantidade'] ?? 0);
        $resumo['qtd_abates'] = (int) ($arquivar['abate']['qtd'] ?? 0);
        $resumo['total_previsoes_forn'] = (float) ($arquivar['previsao_fornecedor']['quantidade'] ?? 0);
        $resumo['total_previsoes_colh'] = (float) ($arquivar['previsao_colheita']['quantidade'] ?? 0);
    }
    if ($estoqueFinal !== null) {
        $resumo['estoque_final'] = $estoqueFinal;
    }
    $estoqueJson = json_encode($resumo['estoque_final'], JSON_UNESCAPED_UNICODE);

    $stmt = $conexao->prepare("
        INSERT INTO ciclo_snapshot (
            ciclo_id,
            total_vendas_kg,
            total_entradas_kg,
            total_abates_kg,
            total_previsoes_forn,
            total_previsoes_colh,
            qtd_vendas,
            qtd_entradas,
            qtd_abates,
            estoque_final_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            total_vendas_kg = VALUES(total_vendas_kg),
            total_entradas_kg = VALUES(total_entradas_kg),
            total_abates_kg = VALUES(total_abates_kg),
            total_previsoes_forn = VALUES(total_previsoes_forn),
            total_previsoes_colh = VALUES(total_previsoes_colh),
            qtd_vendas = VALUES(qtd_vendas),
            qtd_entradas = VALUES(qtd_entradas),
            qtd_abates = VALUES(qtd_abates),
            estoque_final_json = VALUES(estoque_final_json)
    ");

    $stmt->bind_param(
        "idddddiiis",
        $cicloId,
        $resumo['total_vendas_kg'],
        $resumo['total_entradas_kg'],
        $resumo['total_abates_kg'],
        $resumo['total_previsoes_forn'],
        $resumo['total_previsoes_colh'],
        $resumo['qtd_vendas'],
        $resumo['qtd_entradas'],
        $resumo['qtd_abates'],
        $estoqueJson
    );

    $stmt->execute();
    $stmt->close();
}

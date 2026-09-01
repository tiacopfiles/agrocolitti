<?php

require_once __DIR__ . '/ciclo_mysqli_helper.php';

function cicloFechamentoGarantirTabelas(mysqli $conexao): void
{
    foreach (['entradas', 'movimentacoes', 'previsao_fornecedor', 'previsao_colheita', 'abates'] as $tabelaCiclo) {
        if (tabelaExiste($conexao, $tabelaCiclo) && !colunaExiste($conexao, $tabelaCiclo, 'ciclo_id')) {
            $conexao->query("ALTER TABLE {$tabelaCiclo} ADD COLUMN ciclo_id INT(11) DEFAULT NULL AFTER id");
            $conexao->query("ALTER TABLE {$tabelaCiclo} ADD INDEX idx_{$tabelaCiclo}_ciclo_id (ciclo_id)");
        }
    }

    $conexao->query("
        CREATE TABLE IF NOT EXISTS ciclo_snapshot_registros (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ciclo_id INT NOT NULL,
            tipo VARCHAR(40) NOT NULL,
            origem_tabela VARCHAR(80) NOT NULL,
            origem_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            numero_os VARCHAR(80) DEFAULT NULL,
            produto_id INT DEFAULT NULL,
            produto_nome VARCHAR(255) DEFAULT NULL,
            pessoa_id INT DEFAULT NULL,
            pessoa_nome VARCHAR(255) DEFAULT NULL,
            data_registro DATETIME DEFAULT NULL,
            quantidade DECIMAL(14,3) DEFAULT NULL,
            valor DECIMAL(14,2) DEFAULT NULL,
            status VARCHAR(80) DEFAULT NULL,
            confiabilidade VARCHAR(40) NOT NULL DEFAULT 'parcial',
            acao_fechamento ENUM('arquivar_zerar','preservar') NOT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_ciclo_snapshot_registro (ciclo_id, origem_tabela, origem_id, acao_fechamento),
            KEY idx_csr_ciclo (ciclo_id),
            KEY idx_csr_tipo (tipo),
            KEY idx_csr_os (numero_os),
            KEY idx_csr_acao (acao_fechamento)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $conexao->query("
        CREATE TABLE IF NOT EXISTS ciclo_fechamento_execucoes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ciclo_id INT NOT NULL,
            novo_ciclo_id INT DEFAULT NULL,
            status ENUM('iniciado','concluido','falhou') NOT NULL DEFAULT 'iniciado',
            dry_run_json LONGTEXT DEFAULT NULL,
            totais_json LONGTEXT DEFAULT NULL,
            erro TEXT DEFAULT NULL,
            usuario_id INT DEFAULT NULL,
            usuario_nome VARCHAR(120) DEFAULT NULL,
            iniciado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            concluido_em DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_cfe_ciclo (ciclo_id),
            KEY idx_cfe_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function cicloFechamentoValor(array $row, array $campos, $padrao = null)
{
    foreach ($campos as $campo) {
        if (array_key_exists($campo, $row) && $row[$campo] !== null && $row[$campo] !== '') {
            return $row[$campo];
        }
    }
    return $padrao;
}

function cicloFechamentoFiltro(mysqli $conexao, string $tabela, string $alias, int $cicloId): string
{
    return colunaExiste($conexao, $tabela, 'ciclo_id') ? " AND {$alias}.ciclo_id = {$cicloId}" : '';
}

/**
 * Retorna a competencia mensal como intervalo semiaberto [inicio, fim).
 * A data do clique nunca participa do calculo.
 */
function cicloFechamentoPeriodo(array $ciclo): array
{
    $ano = (int) ($ciclo['ano'] ?? 0);
    $mes = (int) ($ciclo['mes'] ?? 0);
    if ($ano < 2000 || $mes < 1 || $mes > 12) {
        throw new RuntimeException('O ciclo ativo nao possui uma competencia mensal valida.');
    }

    $inicio = DateTimeImmutable::createFromFormat(
        '!Y-n-j H:i:s',
        "{$ano}-{$mes}-1 00:00:00",
        new DateTimeZone('America/Sao_Paulo')
    );
    if (!$inicio) {
        throw new RuntimeException('Nao foi possivel calcular o periodo do ciclo.');
    }

    return [
        'inicio' => $inicio->format('Y-m-d H:i:s'),
        'fim' => $inicio->modify('first day of next month')->format('Y-m-d H:i:s'),
        'proximo_ano' => (int) $inicio->modify('first day of next month')->format('Y'),
        'proximo_mes' => (int) $inicio->modify('first day of next month')->format('n'),
    ];
}

function cicloFechamentoDataNormalizada($valor): ?string
{
    $valor = trim((string) $valor);
    if ($valor === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($valor, new DateTimeZone('America/Sao_Paulo')))
            ->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Separa os registros do ciclo ativo sem alterar o banco:
 * - arquivar: finalizados pertencentes a competencia;
 * - carregar: pendencias antigas que continuarao operacionais no proximo ciclo;
 * - transferir: registros cuja data ja pertence ao proximo mes;
 * - inconsistencias: finalizados anteriores ao inicio ou sem data confiavel.
 */
function cicloFechamentoPlanoMensal(mysqli $conexao, array $ciclo): array
{
    $periodo = cicloFechamentoPeriodo($ciclo);
    $mapaAtual = cicloFechamentoColetarRegistros($conexao, (int) $ciclo['id']);
    return cicloFechamentoClassificarMapaMensal($mapaAtual, $periodo);
}

function cicloFechamentoClassificarMapaMensal(array $mapaAtual, array $periodo): array
{
    $plano = [
        'arquivar' => [],
        'carregar' => [],
        'transferir' => [],
        'inconsistencias' => [],
        'periodo' => $periodo,
    ];

    foreach (['arquivar', 'preservar'] as $grupoOriginal) {
        foreach ($mapaAtual[$grupoOriginal] ?? [] as $registro) {
            $data = cicloFechamentoDataNormalizada($registro['data_registro'] ?? null);

            if ($data !== null && $data >= $periodo['fim']) {
                $registro['motivo_transferencia_ciclo'] = 'data_pertence_ao_proximo_mes';
                $plano['transferir'][] = $registro;
                continue;
            }

            if ($grupoOriginal === 'preservar') {
                $registro['motivo_transferencia_ciclo'] = 'pendencia_carregada';
                $plano['carregar'][] = $registro;
                continue;
            }

            if ($data !== null && $data >= $periodo['inicio'] && $data < $periodo['fim']) {
                $plano['arquivar'][] = $registro;
                continue;
            }

            $registro['motivo_inconsistencia_ciclo'] = $data === null
                ? 'registro_finalizado_sem_data'
                : 'registro_finalizado_anterior_a_competencia';
            $plano['inconsistencias'][] = $registro;
        }
    }

    return $plano;
}

function cicloFechamentoMapaSnapshot(array $plano): array
{
    return [
        'arquivar' => $plano['arquivar'] ?? [],
        'preservar' => $plano['carregar'] ?? [],
    ];
}

function cicloFechamentoIdsTransferencia(array $plano): array
{
    $ids = [];
    foreach (['carregar', 'transferir'] as $grupo) {
        foreach ($plano[$grupo] ?? [] as $registro) {
            $tabela = (string) ($registro['origem_tabela'] ?? '');
            $id = (int) ($registro['origem_id'] ?? 0);
            if ($tabela !== '' && $id > 0) {
                $ids[$tabela][$id] = $id;
            }
        }
    }

    foreach ($ids as $tabela => $tabelaIds) {
        $ids[$tabela] = array_values($tabelaIds);
    }
    return $ids;
}

function cicloFechamentoTransferirRegistros(
    mysqli $conexao,
    array $plano,
    int $cicloOrigemId,
    int $cicloDestinoId
): void {
    $permitidas = [
        'vendas',
        'entradas',
        'movimentacoes',
        'previsao_fornecedor',
        'previsao_colheita',
        'abates',
    ];

    foreach (cicloFechamentoIdsTransferencia($plano) as $tabela => $ids) {
        if (!in_array($tabela, $permitidas, true)
            || !tabelaExiste($conexao, $tabela)
            || !colunaExiste($conexao, $tabela, 'ciclo_id')) {
            continue;
        }

        $stmt = $conexao->prepare(
            "UPDATE {$tabela} SET ciclo_id = ? WHERE id = ? AND ciclo_id = ?"
        );
        foreach ($ids as $id) {
            $stmt->bind_param('iii', $cicloDestinoId, $id, $cicloOrigemId);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException(
                    "O registro {$tabela} #{$id} mudou durante o fechamento. Nada foi gravado."
                );
            }
        }
        $stmt->close();
    }
}

function cicloFechamentoBloquearRegistros(mysqli $conexao, int $cicloId): void
{
    foreach ([
        'vendas',
        'entradas',
        'movimentacoes',
        'previsao_fornecedor',
        'previsao_colheita',
        'abates',
    ] as $tabela) {
        if (!tabelaExiste($conexao, $tabela)
            || !colunaExiste($conexao, $tabela, 'ciclo_id')) {
            continue;
        }
        $stmt = $conexao->prepare(
            "SELECT id FROM {$tabela} WHERE ciclo_id = ? FOR UPDATE"
        );
        $stmt->bind_param('i', $cicloId);
        $stmt->execute();
        $resultado = $stmt->get_result();
        while ($resultado->fetch_assoc()) {
            // Consome o resultado mantendo os bloqueios ate o commit.
        }
        $stmt->close();
    }
}

function cicloFechamentoBuscar(mysqli $conexao, string $sql): array
{
    $rows = [];
    $res = $conexao->query($sql);
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    }
    return $rows;
}

function cicloFechamentoPayload(array $row): string
{
    return json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function cicloFechamentoExiste(mysqli $conexao, string $sql, string $types = '', array $params = []): bool
{
    $stmt = $conexao->prepare($sql);
    if ($types !== '') {
        $bindParams = [];
        foreach ($params as $key => $value) {
            $bindParams[$key] = &$params[$key];
        }
        $stmt->bind_param($types, ...$bindParams);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: ['total' => 0];
    $stmt->close();

    return (int) ($row['total'] ?? 0) > 0;
}

function cicloFechamentoOperacaoTemNfeAberta(mysqli $conexao, ?string $numeroOs, ?int $origemId = null, ?int $vendaId = null): bool
{
    if (!tabelaExiste($conexao, 'nfe_documentos')) {
        return false;
    }

    $where = [];
    $params = [];
    $types = '';
    $numeroOs = trim((string) $numeroOs);

    if ($numeroOs !== '' && colunaExiste($conexao, 'nfe_documentos', 'numero_os')) {
        $where[] = 'numero_os = ?';
        $params[] = $numeroOs;
        $types .= 's';
    }
    if ($origemId && colunaExiste($conexao, 'nfe_documentos', 'origem_id')) {
        $where[] = 'origem_id = ?';
        $params[] = $origemId;
        $types .= 'i';
    }
    if ($vendaId && colunaExiste($conexao, 'nfe_documentos', 'venda_id')) {
        $where[] = 'venda_id = ?';
        $params[] = $vendaId;
        $types .= 'i';
    }
    if (!$where) {
        return false;
    }

    return cicloFechamentoExiste(
        $conexao,
        'SELECT COUNT(*) AS total FROM nfe_documentos WHERE status NOT IN ("autorizada", "cancelada") AND (' . implode(' OR ', $where) . ')',
        $types,
        $params
    );
}

function cicloFechamentoOperacaoTemBoletoAberto(mysqli $conexao, ?string $numeroOs, ?int $vendaId = null): bool
{
    if (!tabelaExiste($conexao, 'boletos')) {
        return false;
    }

    $where = [];
    $params = [];
    $types = '';
    $numeroOs = trim((string) $numeroOs);

    if ($numeroOs !== '' && colunaExiste($conexao, 'boletos', 'numero_os')) {
        $where[] = 'b.numero_os = ?';
        $params[] = $numeroOs;
        $types .= 's';
    }
    if ($vendaId && colunaExiste($conexao, 'boletos', 'venda_id')) {
        $where[] = 'b.venda_id = ?';
        $params[] = $vendaId;
        $types .= 'i';
    }
    if ($numeroOs !== '' && tabelaExiste($conexao, 'nfe_documentos') && colunaExiste($conexao, 'boletos', 'nfe_documento_id')) {
        $where[] = 'EXISTS (SELECT 1 FROM nfe_documentos d WHERE d.id = b.nfe_documento_id AND d.numero_os = ?)';
        $params[] = $numeroOs;
        $types .= 's';
    }
    if (!$where) {
        return false;
    }

    return cicloFechamentoExiste(
        $conexao,
        'SELECT COUNT(*) AS total FROM boletos b WHERE b.situacao NOT IN ("baixado", "cancelado") AND (' . implode(' OR ', $where) . ')',
        $types,
        $params
    );
}

function cicloFechamentoOperacaoTemContaAberta(mysqli $conexao, ?string $numeroOs, ?int $vendaId = null): bool
{
    if (!tabelaExiste($conexao, 'contas_integracoes')) {
        return false;
    }

    $where = [];
    $params = [];
    $types = '';
    $numeroOs = trim((string) $numeroOs);

    if ($numeroOs !== '' && colunaExiste($conexao, 'contas_integracoes', 'origem_ref')) {
        $where[] = 'ci.origem_ref = ?';
        $params[] = $numeroOs;
        $types .= 's';
    }
    if ($numeroOs !== '' && tabelaExiste($conexao, 'nfe_documentos')) {
        $where[] = 'EXISTS (SELECT 1 FROM nfe_documentos d WHERE ci.origem_tabela = "nfe_documentos" AND ci.origem_id = d.id AND d.numero_os = ?)';
        $params[] = $numeroOs;
        $types .= 's';
    }
    if ($numeroOs !== '' && tabelaExiste($conexao, 'boletos')) {
        $where[] = 'EXISTS (SELECT 1 FROM boletos b WHERE ci.origem_tabela = "boletos" AND ci.origem_id = b.id AND b.numero_os = ?)';
        $params[] = $numeroOs;
        $types .= 's';
    }
    if ($vendaId && tabelaExiste($conexao, 'nfe_documentos')) {
        $where[] = 'EXISTS (SELECT 1 FROM nfe_documentos d WHERE ci.origem_tabela = "nfe_documentos" AND ci.origem_id = d.id AND d.venda_id = ?)';
        $params[] = $vendaId;
        $types .= 'i';
    }
    if ($vendaId && tabelaExiste($conexao, 'boletos')) {
        $where[] = 'EXISTS (SELECT 1 FROM boletos b WHERE ci.origem_tabela = "boletos" AND ci.origem_id = b.id AND b.venda_id = ?)';
        $params[] = $vendaId;
        $types .= 'i';
    }
    if (!$where) {
        return false;
    }

    return cicloFechamentoExiste(
        $conexao,
        'SELECT COUNT(*) AS total FROM contas_integracoes ci WHERE ci.status NOT IN ("enviado", "cancelado", "ignorado") AND (' . implode(' OR ', $where) . ')',
        $types,
        $params
    );
}

function cicloFechamentoVendaExigeBoleto(array $row): bool
{
    $formaPagamento = strtolower(trim((string) ($row['forma_pagamento'] ?? '')));

    return $formaPagamento === '' || $formaPagamento === 'boleto';
}

function cicloFechamentoVendaTemNfeAutorizada(mysqli $conexao, ?string $numeroOs, ?int $vendaId = null): bool
{
    if (!tabelaExiste($conexao, 'nfe_documentos')) {
        return false;
    }

    $where = [];
    $params = [];
    $types = '';
    $numeroOs = trim((string) $numeroOs);

    if ($numeroOs !== '' && colunaExiste($conexao, 'nfe_documentos', 'numero_os')) {
        $where[] = 'numero_os = ?';
        $params[] = $numeroOs;
        $types .= 's';
    }
    if ($vendaId && colunaExiste($conexao, 'nfe_documentos', 'venda_id')) {
        $where[] = 'venda_id = ?';
        $params[] = $vendaId;
        $types .= 'i';
    }
    if (!$where) {
        return false;
    }

    return cicloFechamentoExiste(
        $conexao,
        'SELECT COUNT(*) AS total FROM nfe_documentos WHERE tipo_emissao = "venda" AND status = "autorizada" AND (' . implode(' OR ', $where) . ')',
        $types,
        $params
    );
}

function cicloFechamentoVendaTemBoletoGerado(mysqli $conexao, ?string $numeroOs, ?int $vendaId = null): bool
{
    if (!tabelaExiste($conexao, 'boletos')) {
        return false;
    }

    $where = [];
    $params = [];
    $types = '';
    $numeroOs = trim((string) $numeroOs);

    if ($numeroOs !== '' && colunaExiste($conexao, 'boletos', 'numero_os')) {
        $where[] = 'b.numero_os = ?';
        $params[] = $numeroOs;
        $types .= 's';
    }
    if ($vendaId && colunaExiste($conexao, 'boletos', 'venda_id')) {
        $where[] = 'b.venda_id = ?';
        $params[] = $vendaId;
        $types .= 'i';
    }
    if ($numeroOs !== '' && tabelaExiste($conexao, 'nfe_documentos') && colunaExiste($conexao, 'boletos', 'nfe_documento_id')) {
        $where[] = 'EXISTS (SELECT 1 FROM nfe_documentos d WHERE d.id = b.nfe_documento_id AND d.numero_os = ?)';
        $params[] = $numeroOs;
        $types .= 's';
    }
    if (!$where) {
        return false;
    }

    return cicloFechamentoExiste(
        $conexao,
        'SELECT COUNT(*) AS total FROM boletos b WHERE b.situacao IN ("emitido", "registrado", "baixado") AND (' . implode(' OR ', $where) . ')',
        $types,
        $params
    );
}

function cicloFechamentoVendaProcessoFinalizado(mysqli $conexao, array $row): bool
{
    $numeroOs = cicloFechamentoValor($row, ['numero_os', 'os', 'ordem_servico'], null);
    $vendaId = isset($row['id']) ? (int) $row['id'] : null;

    if (!cicloFechamentoVendaTemNfeAutorizada($conexao, $numeroOs, $vendaId)) {
        return false;
    }

    if (cicloFechamentoVendaExigeBoleto($row)
        && !cicloFechamentoVendaTemBoletoGerado($conexao, $numeroOs, $vendaId)) {
        return false;
    }

    return !cicloFechamentoOperacaoTemNfeAberta($conexao, $numeroOs, $vendaId, $vendaId)
        && !cicloFechamentoOperacaoTemContaAberta($conexao, $numeroOs, $vendaId);
}

function cicloFechamentoProcessoFinalizado(mysqli $conexao, array $row, ?int $vendaId = null): bool
{
    $numeroOs = cicloFechamentoValor($row, ['numero_os', 'os', 'ordem_servico'], null);
    $origemId = isset($row['id']) ? (int) $row['id'] : null;

    return !cicloFechamentoOperacaoTemNfeAberta($conexao, $numeroOs, $origemId, $vendaId)
        && !cicloFechamentoOperacaoTemBoletoAberto($conexao, $numeroOs, $vendaId)
        && !cicloFechamentoOperacaoTemContaAberta($conexao, $numeroOs, $vendaId);
}

function cicloFechamentoRegistro(
    string $tipo,
    string $origemTabela,
    array $row,
    string $acao,
    ?string $confiabilidade = null
): array {
    $numeroOs = cicloFechamentoValor($row, ['numero_os', 'os', 'ordem_servico'], null);
    $produtoNome = cicloFechamentoValor($row, ['produto_nome', 'produto'], null);
    $pessoaNome = cicloFechamentoValor($row, ['cliente_nome', 'fornecedor_nome', 'pessoa_nome'], null);
    $data = cicloFechamentoValor($row, [
        'data_venda',
        'data_entrada',
        'data_movimentacao',
        'data_operacao',
        'data_confirmacao',
        'data_prevista',
        'criado_em',
        'created_at',
    ], null);
    $quantidade = cicloFechamentoValor($row, ['quantidade', 'quantidade_prevista', 'quantidade_recebida', 'quantidade_abatida'], null);
    $valor = cicloFechamentoValor($row, ['preco_total', 'valor', 'preco'], null);
    $status = cicloFechamentoValor($row, ['status', 'situacao'], null);

    if ($confiabilidade === null) {
        $confiabilidade = $numeroOs ? 'confiavel' : 'parcial';
    }

    return [
        'tipo' => $tipo,
        'origem_tabela' => $origemTabela,
        'origem_id' => (int) ($row['id'] ?? 0),
        'numero_os' => $numeroOs,
        'produto_id' => isset($row['produto_id']) ? (int) $row['produto_id'] : null,
        'produto_nome' => $produtoNome,
        'pessoa_id' => isset($row['cliente_id']) ? (int) $row['cliente_id'] : (isset($row['fornecedor_id']) ? (int) $row['fornecedor_id'] : null),
        'pessoa_nome' => $pessoaNome,
        'data_registro' => $data,
        'quantidade' => $quantidade !== null ? (float) $quantidade : null,
        'valor' => $valor !== null ? (float) $valor : null,
        'status' => $status,
        'confiabilidade' => $confiabilidade,
        'acao_fechamento' => $acao,
        'payload_json' => cicloFechamentoPayload($row),
    ];
}

function cicloFechamentoColetarRegistros(mysqli $conexao, int $cicloId, bool $filtrarPorCiclo = true): array
{
    $arquivar = [];
    $preservar = [];
    $filtroVendas = cicloFechamentoFiltro($conexao, 'vendas', 'v', $cicloId);
    $filtroEntradas = cicloFechamentoFiltro($conexao, 'entradas', 'e', $cicloId);
    $filtroAbates = cicloFechamentoFiltro($conexao, 'abates', 'a', $cicloId);
    $filtroPF = cicloFechamentoFiltro($conexao, 'previsao_fornecedor', 'pf', $cicloId);
    $filtroPC = cicloFechamentoFiltro($conexao, 'previsao_colheita', 'pc', $cicloId);
    $filtroMov = cicloFechamentoFiltro($conexao, 'movimentacoes', 'm', $cicloId);
    if (!$filtrarPorCiclo) {
        $filtroVendas = $filtroEntradas = $filtroAbates = '';
        $filtroPF = $filtroPC = $filtroMov = '';
    }

    foreach (cicloFechamentoBuscar($conexao, "
        SELECT v.*, p.nome AS produto_nome, c.nome AS cliente_nome
        FROM vendas v
        LEFT JOIN produtos p ON p.id = v.produto_id
        LEFT JOIN clientes c ON c.id = v.cliente_id
        WHERE v.status = 'concluido' {$filtroVendas}
    ") as $row) {
        $arquivar[] = cicloFechamentoRegistro('venda', 'vendas', $row, 'arquivar_zerar');
    }

    foreach (cicloFechamentoBuscar($conexao, "
        SELECT v.*, p.nome AS produto_nome, c.nome AS cliente_nome
        FROM vendas v
        LEFT JOIN produtos p ON p.id = v.produto_id
        LEFT JOIN clientes c ON c.id = v.cliente_id
        WHERE v.status <> 'concluido' {$filtroVendas}
    ") as $row) {
        $preservar[] = cicloFechamentoRegistro('venda', 'vendas', $row, 'preservar', 'parcial');
    }

    foreach (cicloFechamentoBuscar($conexao, "
        SELECT pf.*, p.nome AS produto_nome, f.nome AS fornecedor_nome
        FROM previsao_fornecedor pf
        LEFT JOIN produtos p ON p.id = pf.produto_id
        LEFT JOIN fornecedores f ON f.id = pf.fornecedor_id
        WHERE pf.status = 'concluido' {$filtroPF}
    ") as $row) {
        $arquivar[] = cicloFechamentoRegistro('previsao_fornecedor', 'previsao_fornecedor', $row, 'arquivar_zerar');
    }

    foreach (cicloFechamentoBuscar($conexao, "
        SELECT pf.*, p.nome AS produto_nome, f.nome AS fornecedor_nome
        FROM previsao_fornecedor pf
        LEFT JOIN produtos p ON p.id = pf.produto_id
        LEFT JOIN fornecedores f ON f.id = pf.fornecedor_id
        WHERE pf.status <> 'concluido' {$filtroPF}
    ") as $row) {
        $preservar[] = cicloFechamentoRegistro('previsao_fornecedor', 'previsao_fornecedor', $row, 'preservar', 'parcial');
    }

    foreach (cicloFechamentoBuscar($conexao, "
        SELECT pc.*, p.nome AS produto_nome
        FROM previsao_colheita pc
        LEFT JOIN produtos p ON p.id = pc.produto_id
        WHERE pc.status = 'confirmado' {$filtroPC}
    ") as $row) {
        $arquivar[] = cicloFechamentoRegistro('previsao_colheita', 'previsao_colheita', $row, 'arquivar_zerar');
    }

    foreach (cicloFechamentoBuscar($conexao, "
        SELECT pc.*, p.nome AS produto_nome
        FROM previsao_colheita pc
        LEFT JOIN produtos p ON p.id = pc.produto_id
        WHERE pc.status <> 'confirmado' {$filtroPC}
    ") as $row) {
        $preservar[] = cicloFechamentoRegistro('previsao_colheita', 'previsao_colheita', $row, 'preservar', 'parcial');
    }

    foreach (cicloFechamentoBuscar($conexao, "
        SELECT e.*, p.nome AS produto_nome, f.nome AS fornecedor_nome
        FROM entradas e
        LEFT JOIN produtos p ON p.id = e.produto_id
        LEFT JOIN fornecedores f ON f.id = e.fornecedor_id
        WHERE 1=1 {$filtroEntradas}
    ") as $row) {
        $arquivar[] = cicloFechamentoRegistro('entrada', 'entradas', $row, 'arquivar_zerar');
    }

    $dataAbate = colunaExiste($conexao, 'abates', 'criado_em')
        ? 'a.criado_em'
        : (colunaExiste($conexao, 'abates', 'created_at')
            ? 'a.created_at'
            : 'COALESCE(pf.data_confirmacao, pf.data_prevista, pc.data_prevista)');
    foreach (cicloFechamentoBuscar($conexao, "
        SELECT a.*, p.nome AS produto_nome, {$dataAbate} AS data_operacao
        FROM abates a
        LEFT JOIN previsao_fornecedor pf ON a.tipo = 'fornecedor' AND pf.id = a.previsao_id
        LEFT JOIN previsao_colheita pc ON a.tipo = 'colheita' AND pc.id = a.previsao_id
        LEFT JOIN produtos p ON p.id = COALESCE(pf.produto_id, pc.produto_id)
        WHERE 1=1 {$filtroAbates}
    ") as $row) {
        $arquivar[] = cicloFechamentoRegistro('abate', 'abates', $row, 'arquivar_zerar', 'parcial');
    }

    foreach (cicloFechamentoBuscar($conexao, "
        SELECT m.*, p.nome AS produto_nome
        FROM movimentacoes m
        LEFT JOIN produtos p ON p.id = m.produto_id
        WHERE 1=1 {$filtroMov}
    ") as $row) {
        // Movimentacoes sao fatos consumados do estoque. A competencia e
        // definida exclusivamente por data_movimentacao.
        $arquivar[] = cicloFechamentoRegistro('movimentacao', 'movimentacoes', $row, 'arquivar_zerar', 'parcial');
    }

    return ['arquivar' => $arquivar, 'preservar' => $preservar];
}

function cicloFechamentoTotais(array $mapa): array
{
    $grupos = array_values(array_intersect(
        ['arquivar', 'preservar', 'carregar', 'transferir', 'inconsistencias'],
        array_keys($mapa)
    ));
    $totais = [];
    $processos = [];
    foreach ($grupos as $grupo) {
        $totais[$grupo] = [];
        $processos[$grupo] = [];
        foreach ($mapa[$grupo] as $item) {
            $tipo = $item['tipo'];
            if (!isset($totais[$grupo][$tipo])) {
                $totais[$grupo][$tipo] = ['qtd' => 0, 'processos' => 0, 'quantidade' => 0.0, 'valor' => 0.0];
                $processos[$grupo][$tipo] = [];
            }
            $totais[$grupo][$tipo]['qtd']++;
            $totais[$grupo][$tipo]['quantidade'] += (float) ($item['quantidade'] ?? 0);
            $totais[$grupo][$tipo]['valor'] += (float) ($item['valor'] ?? 0);

            $numeroOs = trim((string) ($item['numero_os'] ?? ''));
            $origemId = (int) ($item['origem_id'] ?? 0);
            $processoKey = $numeroOs !== ''
                ? 'os:' . $numeroOs
                : 'registro:' . ($item['origem_tabela'] ?? $tipo) . ':' . $origemId;
            $processos[$grupo][$tipo][$processoKey] = true;
        }
    }
    foreach ($grupos as $grupo) {
        foreach ($totais[$grupo] as $tipo => $total) {
            $totais[$grupo][$tipo]['processos'] = count($processos[$grupo][$tipo] ?? []);
        }
    }
    return $totais;
}

function cicloFechamentoSalvarRegistros(mysqli $conexao, int $cicloId, array $registros): void
{
    $stmt = $conexao->prepare("
        INSERT INTO ciclo_snapshot_registros (
            ciclo_id, tipo, origem_tabela, origem_id, numero_os, produto_id,
            produto_nome, pessoa_id, pessoa_nome, data_registro, quantidade,
            valor, status, confiabilidade, acao_fechamento, payload_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            numero_os = VALUES(numero_os),
            produto_id = VALUES(produto_id),
            produto_nome = VALUES(produto_nome),
            pessoa_id = VALUES(pessoa_id),
            pessoa_nome = VALUES(pessoa_nome),
            data_registro = VALUES(data_registro),
            quantidade = VALUES(quantidade),
            valor = VALUES(valor),
            status = VALUES(status),
            confiabilidade = VALUES(confiabilidade),
            payload_json = VALUES(payload_json)
    ");

    foreach ($registros as $r) {
        $origemId = (int) $r['origem_id'];
        $produtoId = $r['produto_id'];
        $pessoaId = $r['pessoa_id'];
        $quantidade = $r['quantidade'];
        $valor = $r['valor'];
        $stmt->bind_param(
            'issisisissddssss',
            $cicloId,
            $r['tipo'],
            $r['origem_tabela'],
            $origemId,
            $r['numero_os'],
            $produtoId,
            $r['produto_nome'],
            $pessoaId,
            $r['pessoa_nome'],
            $r['data_registro'],
            $quantidade,
            $valor,
            $r['status'],
            $r['confiabilidade'],
            $r['acao_fechamento'],
            $r['payload_json']
        );
        $stmt->execute();
    }
    $stmt->close();
}

/**
 * Arquiva no historico do ciclo (acao 'preservar') somente NF-e e boletos ja finalizados.
 * Documento fiscal/financeiro aberto continua apenas no historico normal, junto da OS origem.
 * Deve ser chamada ANTES de fechar o ciclo.
 */
function cicloFechamentoArquivarFiscais(mysqli $conexao, int $cicloId, array $ciclo): void
{
    $periodo = cicloFechamentoPeriodo($ciclo);
    $inicio = $conexao->real_escape_string($periodo['inicio']);
    $fim = $conexao->real_escape_string($periodo['fim']);
    $registros = [];

    if (tabelaExiste($conexao, 'nfe_documentos')) {
        $filtro = "WHERE COALESCE(d.emitida_em, d.created_at) >= '{$inicio}'
                     AND COALESCE(d.emitida_em, d.created_at) < '{$fim}'";
        $sql = "
            SELECT d.id, d.numero_os, d.tipo_emissao, d.status, d.numero_nfe, d.serie, d.chave_nfe,
                   d.origem_id, d.venda_id, d.cliente_id, d.fornecedor_id,
                   COALESCE(d.emitida_em, d.created_at) AS created_at,
                   c.nome AS cliente_nome
            FROM nfe_documentos d
            LEFT JOIN clientes c ON c.id = d.cliente_id
            {$filtro}
        ";
        $res = $conexao->query($sql);
        if ($res instanceof mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $tipoEmissao = (string) ($row['tipo_emissao'] ?? '');
                $nfeFinalizada = in_array((string) ($row['status'] ?? ''), ['autorizada', 'cancelada'], true);
                $vendaFinalizada = $tipoEmissao !== 'venda'
                    || (
                        (string) ($row['status'] ?? '') === 'autorizada'
                        && (!cicloFechamentoVendaExigeBoleto($row)
                            || cicloFechamentoVendaTemBoletoGerado($conexao, $row['numero_os'] ?? null, isset($row['venda_id']) ? (int) $row['venda_id'] : null))
                    );

                if ($nfeFinalizada
                    && $vendaFinalizada
                    && cicloFechamentoProcessoFinalizado($conexao, $row, isset($row['venda_id']) ? (int) $row['venda_id'] : null)) {
                    $registros[] = cicloFechamentoRegistro('nfe', 'nfe_documentos', $row, 'preservar', 'confiavel');
                }
            }
            $res->free();
        }
    }

    if (tabelaExiste($conexao, 'boletos')) {
        $filtro = "WHERE b.created_at >= '{$inicio}' AND b.created_at < '{$fim}'";
        $sql = "
            SELECT b.id, b.numero_os, b.venda_id, b.nfe_documento_id, b.situacao, b.valor, b.created_at,
                   b.pagador_nome AS pessoa_nome
            FROM boletos b
            {$filtro}
        ";
        $res = $conexao->query($sql);
        if ($res instanceof mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                if (in_array((string) ($row['situacao'] ?? ''), ['baixado', 'cancelado'], true)
                    && !cicloFechamentoOperacaoTemContaAberta($conexao, $row['numero_os'] ?? null, isset($row['venda_id']) ? (int) $row['venda_id'] : null)) {
                    $registros[] = cicloFechamentoRegistro('boleto', 'boletos', $row, 'preservar', 'confiavel');
                }
            }
            $res->free();
        }
    }

    if ($registros) {
        cicloFechamentoSalvarRegistros($conexao, $cicloId, $registros);
    }
}

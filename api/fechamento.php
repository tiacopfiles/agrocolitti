<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../bootstrap/conexao.php';

function responderJson(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function obterBearerToken(): string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authorization = '';

    foreach ($headers as $nome => $valor) {
        if (strtolower((string) $nome) === 'authorization') {
            $authorization = trim((string) $valor);
            break;
        }
    }

    if ($authorization === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authorization = trim((string) $_SERVER['HTTP_AUTHORIZATION']);
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        return '';
    }

    return trim($matches[1]);
}

function validarToken(mysqli $conexao, string $token): bool
{
    if ($token === '') {
        return false;
    }

    $stmt = $conexao->prepare('SELECT id FROM api_tokens WHERE token = ? AND ativo = 1 LIMIT 1');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $token);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $valido = $resultado && $resultado->num_rows > 0;
    $stmt->close();

    return $valido;
}

function validarPeriodo(): array
{
    $mes = isset($_GET['mes']) ? (int) $_GET['mes'] : 0;
    $ano = isset($_GET['ano']) ? (int) $_GET['ano'] : 0;

    if ($mes < 1 || $mes > 12 || $ano < 2000 || $ano > 2100) {
        responderJson(400, [
            'sucesso' => false,
            'erro' => 'Período inválido. Informe mês entre 1 e 12 e ano válido.',
        ]);
    }

    return [$mes, $ano];
}

function tabelaExiste(mysqli $conexao, string $tabela): bool
{
    $tabela = $conexao->real_escape_string($tabela);
    $resultado = $conexao->query("SHOW TABLES LIKE '{$tabela}'");
    $existe = $resultado && $resultado->num_rows > 0;
    if ($resultado instanceof mysqli_result) {
        $resultado->free();
    }
    return $existe;
}

function buscarCompras(mysqli $conexao, int $mes, int $ano): array
{
    $sql = "
        SELECT
            MIN(pf.id) AS os_id,
            COALESCE(NULLIF(pf.numero_os, ''), CONCAT('PF-', MIN(pf.id))) AS os_numero,
            MIN(pf.data_prevista) AS data,
            f.nome AS fornecedor,
            GROUP_CONCAT(DISTINCT p.nome ORDER BY p.nome SEPARATOR ', ') AS produtos,
            SUM(COALESCE(pf.quantidade_prevista, 0) * COALESCE(pf.preco, 0)) AS valor_total
        FROM previsao_fornecedor pf
        INNER JOIN fornecedores f ON f.id = pf.fornecedor_id
        INNER JOIN produtos p ON p.id = pf.produto_id
        WHERE pf.status = 'concluido'
          AND MONTH(pf.data_prevista) = ?
          AND YEAR(pf.data_prevista) = ?
        GROUP BY COALESCE(NULLIF(pf.numero_os, ''), CONCAT('PF-', pf.id)), pf.fornecedor_id, f.nome
        ORDER BY data ASC, os_numero ASC
    ";

    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar consulta de compras: ' . $conexao->error);
    }

    $stmt->bind_param('ii', $mes, $ano);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $compras = [];

    while ($row = $resultado->fetch_assoc()) {
        $compras[] = [
            'os_id' => (int) $row['os_id'],
            'os_numero' => (string) $row['os_numero'],
            'data' => (string) $row['data'],
            'fornecedor' => (string) $row['fornecedor'],
            'produtos' => (string) ($row['produtos'] ?? ''),
            'valor_total' => round((float) $row['valor_total'], 2),
        ];
    }

    $stmt->close();

    foreach ($compras as $idx => $compra) {
        $compras[$idx]['itens'] = buscarItensCompra($conexao, (string) $compra['os_numero'], $mes, $ano);
    }

    return $compras;
}

function buscarReversoesFinanceiras(mysqli $conexao, int $mes, int $ano): array
{
    if (!tabelaExiste($conexao, 'financeiro_reversoes')) {
        return [];
    }

    $sql = "
        SELECT origem, numero_os, contraparte, valor, motivo, usuario, criado_em
        FROM financeiro_reversoes
        WHERE MONTH(criado_em) = ?
          AND YEAR(criado_em) = ?
        ORDER BY criado_em ASC, id ASC
    ";

    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar reversoes financeiras: ' . $conexao->error);
    }

    $stmt->bind_param('ii', $mes, $ano);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $reversoes = [];

    while ($row = $resultado->fetch_assoc()) {
        $reversoes[] = [
            'origem' => (string) $row['origem'],
            'os_numero' => (string) $row['numero_os'],
            'data' => (string) $row['criado_em'],
            'contraparte' => (string) ($row['contraparte'] ?? ''),
            'valor_total' => round((float) $row['valor'], 2),
            'motivo' => (string) ($row['motivo'] ?? ''),
            'usuario' => (string) ($row['usuario'] ?? ''),
        ];
    }

    $stmt->close();
    return $reversoes;
}

function buscarItensCompra(mysqli $conexao, string $osNumero, int $mes, int $ano): array
{
    $sql = "
        SELECT
            p.nome AS produto,
            f.nome AS contraparte,
            COALESCE(pf.quantidade_prevista, 0) AS quantidade,
            COALESCE(pf.preco, 0) AS valor_unitario,
            COALESCE(pf.quantidade_prevista, 0) * COALESCE(pf.preco, 0) AS valor_total
        FROM previsao_fornecedor pf
        INNER JOIN fornecedores f ON f.id = pf.fornecedor_id
        INNER JOIN produtos p ON p.id = pf.produto_id
        WHERE pf.status = 'concluido'
          AND COALESCE(NULLIF(pf.numero_os, ''), CONCAT('PF-', pf.id)) = ?
          AND MONTH(pf.data_prevista) = ?
          AND YEAR(pf.data_prevista) = ?
        ORDER BY pf.id ASC
    ";

    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar itens de compras: ' . $conexao->error);
    }

    $stmt->bind_param('sii', $osNumero, $mes, $ano);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $itens = [];

    while ($row = $resultado->fetch_assoc()) {
        $itens[] = [
            'produto' => (string) $row['produto'],
            'contraparte' => (string) $row['contraparte'],
            'quantidade' => round((float) $row['quantidade'], 2),
            'valor_unitario' => round((float) $row['valor_unitario'], 2),
            'valor_total' => round((float) $row['valor_total'], 2),
        ];
    }

    $stmt->close();
    return $itens;
}

function buscarVendas(mysqli $conexao, int $mes, int $ano): array
{
    $sql = "
        SELECT
            MIN(v.id) AS os_id,
            COALESCE(NULLIF(v.numero_os, ''), CONCAT('VENDA-', MIN(v.id))) AS os_numero,
            MIN(v.data_venda) AS data,
            c.nome AS cliente,
            GROUP_CONCAT(DISTINCT p.nome ORDER BY p.nome SEPARATOR ', ') AS produtos,
            SUM(
                CASE
                    WHEN v.preco_total IS NOT NULL AND v.preco_total > 0 THEN v.preco_total
                    WHEN v.tipo = 'bandeja' THEN COALESCE(v.pedido, 0) * COALESCE(v.preco, 0)
                    WHEN v.kg_caixa IS NOT NULL AND v.kg_caixa > 0 THEN COALESCE(v.pedido, 0) * COALESCE(v.kg_caixa, 0) * COALESCE(v.preco, 0)
                    ELSE COALESCE(v.quantidade, 0) * COALESCE(v.preco, 0)
                END
            ) AS valor_total
        FROM vendas v
        INNER JOIN clientes c ON c.id = v.cliente_id
        INNER JOIN produtos p ON p.id = v.produto_id
        WHERE v.status = 'concluido'
          AND MONTH(v.data_venda) = ?
          AND YEAR(v.data_venda) = ?
        GROUP BY COALESCE(NULLIF(v.numero_os, ''), CONCAT('VENDA-', v.id)), v.cliente_id, c.nome
        ORDER BY data ASC, os_numero ASC
    ";

    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar consulta de vendas: ' . $conexao->error);
    }

    $stmt->bind_param('ii', $mes, $ano);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $vendas = [];

    while ($row = $resultado->fetch_assoc()) {
        $vendas[] = [
            'os_id' => (int) $row['os_id'],
            'os_numero' => (string) $row['os_numero'],
            'data' => (string) $row['data'],
            'cliente' => (string) $row['cliente'],
            'produtos' => (string) ($row['produtos'] ?? ''),
            'valor_total' => round((float) $row['valor_total'], 2),
        ];
    }

    $stmt->close();

    foreach ($vendas as $idx => $venda) {
        $vendas[$idx]['itens'] = buscarItensVenda($conexao, (string) $venda['os_numero'], $mes, $ano);
    }

    return $vendas;
}

function buscarItensVenda(mysqli $conexao, string $osNumero, int $mes, int $ano): array
{
    $sql = "
        SELECT
            p.nome AS produto,
            c.nome AS contraparte,
            CASE
                WHEN v.tipo = 'bandeja' THEN COALESCE(v.pedido, 0)
                WHEN v.kg_caixa IS NOT NULL AND v.kg_caixa > 0 THEN COALESCE(v.pedido, 0) * COALESCE(v.kg_caixa, 0)
                ELSE COALESCE(v.quantidade, 0)
            END AS quantidade,
            COALESCE(v.preco, 0) AS valor_unitario,
            CASE
                WHEN v.preco_total IS NOT NULL AND v.preco_total > 0 THEN v.preco_total
                WHEN v.tipo = 'bandeja' THEN COALESCE(v.pedido, 0) * COALESCE(v.preco, 0)
                WHEN v.kg_caixa IS NOT NULL AND v.kg_caixa > 0 THEN COALESCE(v.pedido, 0) * COALESCE(v.kg_caixa, 0) * COALESCE(v.preco, 0)
                ELSE COALESCE(v.quantidade, 0) * COALESCE(v.preco, 0)
            END AS valor_total
        FROM vendas v
        INNER JOIN clientes c ON c.id = v.cliente_id
        INNER JOIN produtos p ON p.id = v.produto_id
        WHERE v.status = 'concluido'
          AND COALESCE(NULLIF(v.numero_os, ''), CONCAT('VENDA-', v.id)) = ?
          AND MONTH(v.data_venda) = ?
          AND YEAR(v.data_venda) = ?
        ORDER BY v.id ASC
    ";

    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar itens de vendas: ' . $conexao->error);
    }

    $stmt->bind_param('sii', $osNumero, $mes, $ano);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $itens = [];

    while ($row = $resultado->fetch_assoc()) {
        $itens[] = [
            'produto' => (string) $row['produto'],
            'contraparte' => (string) $row['contraparte'],
            'quantidade' => round((float) $row['quantidade'], 2),
            'valor_unitario' => round((float) $row['valor_unitario'], 2),
            'valor_total' => round((float) $row['valor_total'], 2),
        ];
    }

    $stmt->close();
    return $itens;
}

try {
    $token = obterBearerToken();
    if (!validarToken($conexao, $token)) {
        responderJson(401, [
            'sucesso' => false,
            'erro' => 'Token de API inválido ou ausente.',
        ]);
    }

    [$mes, $ano] = validarPeriodo();
    $compras = buscarCompras($conexao, $mes, $ano);
    $reversoesFinanceiras = buscarReversoesFinanceiras($conexao, $mes, $ano);
    $reversoesCompras = array_values(array_filter($reversoesFinanceiras, static function (array $reversao): bool {
        return ($reversao['origem'] ?? '') === 'compra_cancelada';
    }));
    $reversoesVendas = array_values(array_filter($reversoesFinanceiras, static function (array $reversao): bool {
        return ($reversao['origem'] ?? '') === 'venda_cancelada';
    }));
    $vendas = buscarVendas($conexao, $mes, $ano);
    $totalCompras = round(array_sum(array_column($compras, 'valor_total')), 2);
    $totalReversoes = round(array_sum(array_column($reversoesFinanceiras, 'valor_total')), 2);
    $totalReversoesCompras = round(array_sum(array_column($reversoesCompras, 'valor_total')), 2);
    $totalReversoesVendas = round(array_sum(array_column($reversoesVendas, 'valor_total')), 2);
    $totalVendas = round(array_sum(array_column($vendas, 'valor_total')), 2);

    responderJson(200, [
        'sucesso' => true,
        'mes' => str_pad((string) $mes, 2, '0', STR_PAD_LEFT),
        'ano' => (string) $ano,
        'compras' => $compras,
        'reversoes_financeiras' => $reversoesFinanceiras,
        'reversoes_compras' => $reversoesCompras,
        'reversoes_vendas' => $reversoesVendas,
        'vendas' => $vendas,
        'totais' => [
            'qtd_compras' => count($compras),
            'qtd_vendas' => count($vendas),
            'qtd_reversoes_financeiras' => count($reversoesFinanceiras),
            'total_compras' => $totalCompras,
            'total_reversoes_financeiras' => $totalReversoes,
            'total_reversoes_compras' => $totalReversoesCompras,
            'total_reversoes_vendas' => $totalReversoesVendas,
            'total_compras_liquido' => round($totalCompras + $totalReversoesCompras, 2),
            'total_vendas' => $totalVendas,
            'total_vendas_liquido' => round($totalVendas + $totalReversoesVendas, 2),
        ],
    ]);
} catch (Throwable $e) {
    error_log('Erro API fechamento Agrocolitti: ' . $e->getMessage());
    responderJson(500, [
        'sucesso' => false,
        'erro' => 'Erro interno ao consultar fechamento.',
    ]);
}

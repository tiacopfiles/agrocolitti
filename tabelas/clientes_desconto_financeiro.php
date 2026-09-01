<?php
require '../auth/proteger.php';
require '../config/conexao.php';
require '../config/calculos_preco.php';
require '../config/permissions.php';

requireModule('tabelas', '../index.php');

header('Content-Type: application/json; charset=utf-8');

$tipo = strtolower(trim((string) ($_GET['tipo'] ?? 'atacado')));
$mapaTabelas = [
    'atacado' => 'atacado',
    'atacado_convencional' => 'convencional',
    'embalado' => 'embalado',
    'oba_embalado' => 'embalado',
    'shopper' => 'atacado',
];

if (!isset($mapaTabelas[$tipo])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'Tipo de tabela inválido']);
    exit;
}

function normalizarPercentualFinanceiroModal(float $percentual): float
{
    $percentual = abs($percentual);
    return $percentual > 1 ? $percentual / 100 : $percentual;
}

function tabelaExisteModal(mysqli $conexao, string $tabela): bool
{
    $tabelaLike = $conexao->real_escape_string($tabela);
    $res = $conexao->query("SHOW TABLES LIKE '{$tabelaLike}'");
    return $res && $res->num_rows > 0;
}

function colunaExisteModal(mysqli $conexao, string $tabela, string $coluna): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabela)) {
        return false;
    }
    $colunaLike = $conexao->real_escape_string($coluna);
    $res = $conexao->query("SHOW COLUMNS FROM `{$tabela}` LIKE '{$colunaLike}'");
    return $res && $res->num_rows > 0;
}

$clientes = [];
$tabelaFinanceira = $mapaTabelas[$tipo];

if (tabelaExisteModal($conexao, 'cliente_percentual_financeiro')) {
    $stmt = $conexao->prepare("
        SELECT c.id, c.nome, cpf.percentual
        FROM cliente_percentual_financeiro cpf
        INNER JOIN clientes c ON c.id = cpf.cliente_id
        WHERE c.ativo = 1
          AND cpf.tabela = ?
          AND cpf.percentual > 0
        ORDER BY c.nome
    ");
    $stmt->bind_param('s', $tabelaFinanceira);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $percentual = normalizarPercentualFinanceiroModal((float) $row['percentual']);
        if ($percentual > 0) {
            $clientes[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'nome' => (string) $row['nome'],
                'percentual' => $percentual,
                'percentual_formatado' => number_format($percentual * 100, 2, ',', '.') . '%',
            ];
        }
    }
    $stmt->close();
}

if (colunaExisteModal($conexao, 'clientes', 'desconto_financeiro')) {
    $res = $conexao->query("
        SELECT id, nome, desconto_financeiro
        FROM clientes
        WHERE ativo = 1
          AND desconto_financeiro > 0
        ORDER BY nome
    ");
    while ($res && $row = $res->fetch_assoc()) {
        $id = (int) $row['id'];
        if (isset($clientes[$id])) {
            continue;
        }
        $percentual = normalizarPercentualFinanceiroModal((float) $row['desconto_financeiro']);
        if ($percentual > 0) {
            $clientes[$id] = [
                'id' => $id,
                'nome' => (string) $row['nome'],
                'percentual' => $percentual,
                'percentual_formatado' => number_format($percentual * 100, 2, ',', '.') . '%',
            ];
        }
    }
}

if ($tipo === 'oba_embalado') {
    $resOba = $conexao->query("
        SELECT id, nome
        FROM clientes
        WHERE ativo = 1
          AND UPPER(nome) LIKE '%GRUPO FARTURA%'
        ORDER BY id
        LIMIT 1
    ");
    $clienteOba = $resOba ? $resOba->fetch_assoc() : null;
    if ($clienteOba) {
        $clientes[(int) $clienteOba['id']] = [
            'id' => (int) $clienteOba['id'],
            'nome' => (string) $clienteOba['nome'],
            'percentual' => 0.05,
            'percentual_formatado' => '5,00%',
            'acrescimo_oba' => true,
        ];
    }
}

usort($clientes, static fn(array $a, array $b): int => strcasecmp($a['nome'], $b['nome']));

echo json_encode(['ok' => true, 'clientes' => array_values($clientes)]);

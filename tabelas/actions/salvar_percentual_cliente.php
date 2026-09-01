<?php
/**
 * Salva / atualiza o percentual financeiro de um cliente.
 * O percentual é armazenado como decimal (ex: 5% → 0.05).
 * Responde JSON: { ok: true } ou { ok: false, erro: "mensagem" }
 */
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/conexao.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SESSION['usuario_nivel'] !== 'admin') {
    echo json_encode(['ok' => false, 'erro' => 'Acesso negado']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'erro' => 'Método inválido']);
    exit;
}

$cliente_id     = (int)    ($_POST['cliente_id']     ?? 0);
$percentual_pct = (float)  ($_POST['percentual']      ?? 0); // Vem como 5 (%) → guardar 0.05
$tipo_aplicacao = trim($_POST['tipo_aplicacao'] ?? 'acrescimo');
$tabela         = trim($_POST['tabela']         ?? 'embalado');

if ($cliente_id <= 0) {
    echo json_encode(['ok' => false, 'erro' => 'Cliente inválido']);
    exit;
}

if (!in_array($tipo_aplicacao, ['acrescimo', 'desconto'], true)) {
    $tipo_aplicacao = 'acrescimo';
}

if (!in_array($tabela, ['embalado', 'atacado', 'convencional'], true)) {
    $tabela = 'embalado';
}

// Converte de porcentagem para decimal
$percentual = round($percentual_pct / 100, 6);

// Garante que a tabela existe antes de inserir
$res = $conexao->query("SHOW TABLES LIKE 'cliente_percentual_financeiro'");
if (!$res || $res->num_rows === 0) {
    echo json_encode(['ok' => false, 'erro' => 'Tabela de percentuais não encontrada. Acesse a aba Embalado primeiro.']);
    exit;
}

$stmt = $conexao->prepare("
    INSERT INTO cliente_percentual_financeiro (cliente_id, percentual, tipo_aplicacao, tabela)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        percentual     = VALUES(percentual),
        tipo_aplicacao = VALUES(tipo_aplicacao)
");
$stmt->bind_param('idss', $cliente_id, $percentual, $tipo_aplicacao, $tabela);

if ($stmt->execute()) {
    echo json_encode(['ok' => true, 'percentual_decimal' => $percentual]);
} else {
    echo json_encode(['ok' => false, 'erro' => $conexao->error]);
}
$stmt->close();

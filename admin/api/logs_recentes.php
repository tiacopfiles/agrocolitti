<?php
/**
 * API endpoint: retorna os últimos logs em JSON (usado pelo live feed do dashboard)
 */
require_once '../../bootstrap/conexao.php';
require_once '../../bootstrap/security.php';
require_once '../includes/migration.php';

start_secure_session();

if (($_SESSION['usuario_nivel'] ?? '') !== 'ti') {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$result = $conexao->query("
    SELECT id, usuario_nome, usuario_nivel, acao, tabela, descricao, ip, sistema_origem, autorizado,
           DATE_FORMAT(criado_em, '%d/%m %H:%i:%s') AS hora
    FROM logs_auditoria
    ORDER BY criado_em DESC
    LIMIT 10
");

$logs = [];
while ($row = $result->fetch_assoc()) {
    $logs[] = $row;
}

echo json_encode($logs);

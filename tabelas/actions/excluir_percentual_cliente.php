<?php
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/conexao.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SESSION['usuario_nivel'] ?? '') !== 'admin') {
        echo json_encode(['ok' => false, 'erro' => 'Acesso negado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        echo json_encode(['ok' => false, 'erro' => 'Metodo invalido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $clienteId = (int) ($_POST['cliente_id'] ?? 0);
    $tabela = trim((string) ($_POST['tabela'] ?? ''));

    if ($clienteId <= 0) {
        echo json_encode(['ok' => false, 'erro' => 'Cliente invalido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!in_array($tabela, ['embalado', 'convencional', 'atacado'], true)) {
        echo json_encode(['ok' => false, 'erro' => 'Tabela invalida'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $res = $conexao->query("SHOW TABLES LIKE 'cliente_percentual_financeiro'");
    if (!$res || $res->num_rows === 0) {
        echo json_encode(['ok' => false, 'erro' => 'Tabela de percentuais nao encontrada'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $conexao->prepare("
        DELETE FROM cliente_percentual_financeiro
        WHERE cliente_id = ? AND tabela = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar exclusao: ' . $conexao->error);
    }

    $stmt->bind_param('is', $clienteId, $tabela);
    $stmt->execute();
    $removidos = $stmt->affected_rows;
    $stmt->close();

    echo json_encode(['ok' => true, 'removidos' => $removidos], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

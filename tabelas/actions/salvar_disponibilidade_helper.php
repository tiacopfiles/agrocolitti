<?php

function salvarDisponibilidadePrecoTabela(mysqli $conexao, string $tabela, int $produtoId, int $ativo): void
{
    if ($produtoId <= 0) {
        return;
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabela)) {
        throw new RuntimeException('Tabela de preco inválida.');
    }

    $ativo = $ativo ? 1 : 0;

    $coluna = $conexao->query("SHOW COLUMNS FROM `{$tabela}` LIKE 'ativo'");
    if (!$coluna || $coluna->num_rows === 0) {
        if (!$conexao->query("ALTER TABLE `{$tabela}` ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1")) {
            throw new RuntimeException('Falha ao criar coluna de disponibilidade: ' . $conexao->error);
        }
    }

    $stmt = $conexao->prepare("UPDATE `{$tabela}` SET ativo = ? WHERE produto_id = ?");
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar disponibilidade: ' . $conexao->error);
    }
    $stmt->bind_param('ii', $ativo, $produtoId);
    $stmt->execute();
    $alteradas = $stmt->affected_rows;
    $stmt->close();

    if ($alteradas !== 0) {
        return;
    }

    $stmt = $conexao->prepare("SELECT 1 FROM `{$tabela}` WHERE produto_id = ? LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Falha ao verificar disponibilidade: ' . $conexao->error);
    }
    $stmt->bind_param('i', $produtoId);
    $stmt->execute();
    $existe = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($existe) {
        return;
    }

    $stmt = $conexao->prepare("INSERT INTO `{$tabela}` (produto_id, ativo) VALUES (?, ?)");
    if (!$stmt) {
        throw new RuntimeException('Falha ao inserir disponibilidade: ' . $conexao->error);
    }
    $stmt->bind_param('ii', $produtoId, $ativo);
    if (!$stmt->execute()) {
        $erro = $stmt->error ?: $conexao->error;
        $stmt->close();
        throw new RuntimeException('Falha ao salvar disponibilidade: ' . $erro);
    }
    $stmt->close();
}

function responderDisponibilidadeSalva(): void
{
    echo json_encode(['ok' => true, 'disponibilidade_salva' => true]);
    exit;
}

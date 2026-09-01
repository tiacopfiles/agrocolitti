<?php

function garantirTabelaAuditoriaVendas(mysqli $conexao): void
{
    $conexao->query("
        CREATE TABLE IF NOT EXISTS vendas_auditoria (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            venda_id INT NULL,
            numero_os VARCHAR(50) NULL,
            acao VARCHAR(100) NOT NULL,
            usuario_id INT NULL,
            usuario_nome VARCHAR(120) NULL,
            usuario_nivel VARCHAR(50) NULL,
            descricao TEXT NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(500) NULL,
            rota VARCHAR(255) NULL,
            metodo VARCHAR(12) NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_va_venda (venda_id),
            KEY idx_va_os (numero_os),
            KEY idx_va_acao (acao),
            KEY idx_va_usuario (usuario_id),
            KEY idx_va_criado (criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function buscarNumeroOsAuditoriaVenda(mysqli $conexao, ?int $vendaId, ?string $numeroOsInformado = null): ?string
{
    $numeroOsInformado = trim((string) $numeroOsInformado);
    if ($numeroOsInformado !== '') {
        return $numeroOsInformado;
    }

    if (!$vendaId || $vendaId <= 0) {
        return null;
    }

    $stmt = $conexao->prepare("SELECT numero_os FROM vendas WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $vendaId);
    $stmt->execute();
    $venda = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $numeroOs = trim((string) ($venda['numero_os'] ?? ''));
    return $numeroOs !== '' ? $numeroOs : null;
}

function registrarAuditoriaVenda(
    mysqli $conexao,
    string $acao,
    ?int $vendaId = null,
    ?string $numeroOs = null,
    string $descricao = ''
): void {
    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        garantirTabelaAuditoriaVendas($conexao);

        $usuarioId = isset($_SESSION['usuario_id']) ? (int) $_SESSION['usuario_id'] : null;
        $usuarioNome = isset($_SESSION['usuario_nome']) ? (string) $_SESSION['usuario_nome'] : null;
        $usuarioNivel = isset($_SESSION['usuario_nivel']) ? (string) $_SESSION['usuario_nivel'] : null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
        $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'desconhecido'), 0, 500);
        $rota = substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 255);
        $metodo = substr((string) ($_SERVER['REQUEST_METHOD'] ?? ''), 0, 12);
        $vendaIdVal = $vendaId && $vendaId > 0 ? $vendaId : null;
        $descricaoVal = $descricao !== '' ? $descricao : null;
        $numeroOsVal = buscarNumeroOsAuditoriaVenda($conexao, $vendaIdVal, $numeroOs);
        if (!$numeroOsVal && $descricaoVal && preg_match('/OS\s*[#:]?\s*[\'"]?([A-Za-z0-9._-]+)/i', $descricaoVal, $match)) {
            $numeroOsVal = $match[1];
        }

        $stmt = $conexao->prepare("
            INSERT INTO vendas_auditoria (
                venda_id, numero_os, acao, usuario_id, usuario_nome, usuario_nivel,
                descricao, ip, user_agent, rota, metodo
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            return;
        }

        $stmt->bind_param(
            'ississsssss',
            $vendaIdVal,
            $numeroOsVal,
            $acao,
            $usuarioId,
            $usuarioNome,
            $usuarioNivel,
            $descricaoVal,
            $ip,
            $userAgent,
            $rota,
            $metodo
        );
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Auditoria nao pode interromper a operacao principal.
    }
}

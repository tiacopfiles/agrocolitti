<?php
/**
 * Middleware de segurança do painel admin.
 * Inclua no topo de toda página dentro de /admin/
 * ACESSO: apenas usuários com nível "ti"
 */

require_once __DIR__ . '/../../bootstrap/security.php';
start_secure_session();

require_once __DIR__ . '/csrf.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

if (($_SESSION['usuario_nivel'] ?? '') !== 'ti') {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/migration.php';

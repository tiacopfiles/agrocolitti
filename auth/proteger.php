<?php
require_once __DIR__ . '/../bootstrap/security.php';
start_secure_session();

if (!isset($_SESSION['usuario_id'])) {
    $docRoot  = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    $authDir  = str_replace('\\', '/', __DIR__);
    $loginUrl = str_replace($docRoot, '', $authDir) . '/login.php';
    header('Location: ' . $loginUrl);
    exit;
}

// ── Proteção CSRF ─────────────────────────────────────────────────────────────

/**
 * Retorna o token CSRF da sessão, criando-o se ainda não existir.
 * Chamado em todo formulário que faz POST para um action/*.php.
 */
function gerarTokenCsrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Valida o token CSRF enviado no POST.
 * Mata a requisição com 403 se o token for inválido ou ausente.
 */
function validarTokenCsrf(): void
{
    $tokenEnviado = $_POST['csrf_token'] ?? '';
    $tokenSessao  = $_SESSION['csrf_token'] ?? '';

    if (!$tokenEnviado || !$tokenSessao || !hash_equals($tokenSessao, $tokenEnviado)) {
        http_response_code(403);
        die('Token CSRF inválido. Recarregue a página e tente novamente.');
    }
}

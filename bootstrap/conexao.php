<?php

/**
 * Conexão com o banco de dados.
 * Credenciais lidas do arquivo .env na raiz do projeto.
 * NUNCA coloque senhas diretamente neste arquivo.
 */

// ── Carrega .env ──────────────────────────────────────────────────────────────
(static function (): void {
    $envFile = dirname(__DIR__) . '/.env';
    if (!is_file($envFile)) {
        return;
    }

    $linhas = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($linhas as $linha) {
        $linha = trim($linha);
        if ($linha === '' || $linha[0] === '#') {
            continue;
        }
        $partes = explode('=', $linha, 2);
        if (count($partes) !== 2) {
            continue;
        }
        [$chave, $valor] = $partes;
        $chave = trim($chave);
        $valor = trim($valor);
        if (!array_key_exists($chave, $_ENV)) {
            $_ENV[$chave] = $valor;
            putenv("$chave=$valor");
        }
    }
})();

// ── Lê credenciais do ambiente ────────────────────────────────────────────────
$host   = $_ENV['DB_HOST']   ?? 'localhost';
$usuario = $_ENV['DB_USER']  ?? 'root';
$senha  = $_ENV['DB_PASS']   ?? '';
$banco  = $_ENV['DB_NAME']   ?? 'agrocolitti';
$porta  = isset($_ENV['DB_PORT']) && $_ENV['DB_PORT'] !== ''
    ? (int) $_ENV['DB_PORT']
    : 3307;

// ── Cria conexão ──────────────────────────────────────────────────────────────
$conexao = new mysqli($host, $usuario, $senha, $banco, $porta);

if ($conexao->connect_error) {
    http_response_code(500);
    // Em produção, NÃO exponha detalhes do erro ao usuário.
    error_log('DB connect error: ' . $conexao->connect_error);
    die('Erro interno do servidor. Por favor, tente novamente mais tarde.');
}

$conexao->set_charset('utf8mb4');

<?php

/**
 * Registra uma ação de auditoria na tabela logs_auditoria.
 * Fire-and-forget: nunca lança exceção nem causa erro fatal.
 */
function registrarLog(
    mysqli $conexao,
    string $acao,
    string $tabela = '',
    int $registroId = 0,
    string $descricao = '',
    ?string $usuarioNivel = null,
    ?bool $autorizado = null
): void {
    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $usuarioId   = isset($_SESSION['usuario_id'])   ? (int) $_SESSION['usuario_id']   : null;
        $usuarioNome = isset($_SESSION['usuario_nome'])  ? (string) $_SESSION['usuario_nome'] : null;
        $usuarioNivelVal = $usuarioNivel ?? (isset($_SESSION['usuario_nivel']) ? (string) $_SESSION['usuario_nivel'] : null);
        $ip          = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
        $tabelaVal   = $tabela !== '' ? $tabela : null;
        $registroVal = $registroId > 0 ? $registroId : null;
        $descricaoVal = $descricao !== '' ? $descricao : null;
        $userAgent = function_exists('security_user_agent')
            ? security_user_agent()
            : substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'desconhecido'), 0, 500);
        $sistemaOrigem = function_exists('security_origin_system') ? security_origin_system($userAgent) : 'desconhecido';
        $rota = substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 255);
        $metodo = substr((string) ($_SERVER['REQUEST_METHOD'] ?? ''), 0, 12);
        $autorizadoVal = $autorizado;
        if ($autorizadoVal === null) {
            $autorizadoVal = !preg_match('/falha|negad|bloquead|inval/i', $acao . ' ' . $descricao);
        }
        $autorizadoInt = $autorizadoVal ? 1 : 0;

        $temColuna = static function (string $coluna) use ($conexao): bool {
            static $cache = [];
            if (array_key_exists($coluna, $cache)) {
                return $cache[$coluna];
            }

            $colunaSegura = $conexao->real_escape_string($coluna);
            $resultado = @$conexao->query("SHOW COLUMNS FROM logs_auditoria LIKE '{$colunaSegura}'");
            $cache[$coluna] = $resultado && $resultado->num_rows > 0;
            if ($resultado instanceof mysqli_result) {
                $resultado->free();
            }
            return $cache[$coluna];
        };

        $colunas = ['usuario_id', 'usuario_nome', 'acao', 'tabela', 'registro_id', 'descricao', 'ip'];
        $placeholders = ['?', '?', '?', '?', '?', '?', '?'];
        $tipos = 'isssiss';
        $valores = [$usuarioId, $usuarioNome, $acao, $tabelaVal, $registroVal, $descricaoVal, $ip];

        $extras = [
            'usuario_nivel' => ['s', $usuarioNivelVal],
            'user_agent' => ['s', $userAgent],
            'sistema_origem' => ['s', $sistemaOrigem],
            'rota' => ['s', $rota],
            'metodo' => ['s', $metodo],
            'autorizado' => ['i', $autorizadoInt],
        ];

        foreach ($extras as $coluna => [$tipo, $valor]) {
            if ($temColuna($coluna)) {
                $colunas[] = $coluna;
                $placeholders[] = '?';
                $tipos .= $tipo;
                $valores[] = $valor;
            }
        }

        $stmt = $conexao->prepare(
            'INSERT INTO logs_auditoria (' . implode(', ', $colunas) . ') VALUES (' . implode(', ', $placeholders) . ')'
        );

        if (!$stmt) {
            return;
        }

        $stmt->bind_param($tipos, ...$valores);

        $stmt->execute();
        $stmt->close();

        if ($tabelaVal === 'vendas') {
            require_once __DIR__ . '/venda_auditoria_helper.php';
            registrarAuditoriaVenda(
                $conexao,
                $acao,
                $registroVal,
                null,
                (string) ($descricaoVal ?? '')
            );
        }
    } catch (Throwable $e) {
        // Silencia qualquer erro para não interromper o fluxo principal
    }
}

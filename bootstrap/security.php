<?php

// ── Headers de segurança HTTP ─────────────────────────────────────────────────
// Enviados uma única vez, antes de qualquer output.
if (!defined('SECURITY_HEADERS_SENT')) {
    define('SECURITY_HEADERS_SENT', true);
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // CSP permissivo para compatibilidade com inline styles/scripts existentes.
    // Aperte gradualmente conforme migrar CSS/JS para arquivos externos.
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; img-src 'self' data:; font-src 'self' data: https://cdn.jsdelivr.net https://cdnjs.cloudflare.com;");
}

if (!function_exists('start_secure_session')) {
    function start_secure_session(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        );

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();

        $now            = time();
        $lastRegenerate = (int) ($_SESSION['_last_regenerate'] ?? 0);

        if ($lastRegenerate === 0 || ($now - $lastRegenerate) >= 300) {
            session_regenerate_id(true);
            $_SESSION['_last_regenerate'] = $now;
        }
    }
}

// ── Exclusão segura de arquivos dentro de um diretório esperado ───────────────
if (!function_exists('security_delete_files_in_dir')) {
    function security_delete_files_in_dir(string $pasta): int
    {
        $deleted = 0;
        $baseReal = realpath($pasta);
        if ($baseReal === false) {
            return 0;
        }

        $arquivos = glob($baseReal . DIRECTORY_SEPARATOR . '*');
        if (!$arquivos) {
            return 0;
        }

        foreach ($arquivos as $arquivo) {
            $fileReal = realpath($arquivo);
            if ($fileReal === false) {
                continue;
            }
            // Garante que o arquivo está DENTRO do diretório esperado (evita path traversal)
            if (str_starts_with($fileReal, $baseReal . DIRECTORY_SEPARATOR) && is_file($fileReal)) {
                unlink($fileReal);
                $deleted++;
            }
        }

        return $deleted;
    }
}

if (!function_exists('security_client_ip')) {
    function security_client_ip(): string
    {
        return trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'desconhecido'));
    }
}

if (!function_exists('security_user_agent')) {
    function security_user_agent(): string
    {
        return security_trimmed_string($_SERVER['HTTP_USER_AGENT'] ?? 'desconhecido', 500);
    }
}

if (!function_exists('security_origin_system')) {
    function security_origin_system(?string $userAgent = null): string
    {
        $ua = $userAgent ?? security_user_agent();
        $checks = [
            'Windows 11/10' => 'Windows NT 10.0',
            'Windows 8.1' => 'Windows NT 6.3',
            'Windows 8' => 'Windows NT 6.2',
            'Windows 7' => 'Windows NT 6.1',
            'macOS' => 'Mac OS X',
            'iOS' => 'iPhone',
            'Android' => 'Android',
            'Linux' => 'Linux',
        ];

        foreach ($checks as $label => $needle) {
            if (stripos($ua, $needle) !== false) {
                return $label;
            }
        }

        return 'desconhecido';
    }
}

if (!function_exists('security_trimmed_string')) {
    function security_trimmed_string(mixed $value, int $maxLength = 255): string
    {
        $value = trim((string) $value);

        if ($maxLength > 0 && mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }

        return $value;
    }
}

if (!function_exists('security_int')) {
    function security_int(mixed $value, int $default = 0, ?int $min = null, ?int $max = null): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false) {
            return $default;
        }

        if ($min !== null && $number < $min) {
            return $default;
        }

        if ($max !== null && $number > $max) {
            return $default;
        }

        return (int) $number;
    }
}

if (!function_exists('security_date_ymd')) {
    function security_date_ymd(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $date = DateTime::createFromFormat('Y-m-d', $value);
        $errors = DateTime::getLastErrors();

        if (!$date || ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $value : null;
    }
}

if (!function_exists('login_is_rate_limited')) {
    function login_is_rate_limited(mysqli $conexao, string $ip, string $nome, int $maxTentativas = 5): bool
    {
        $sql = "
            SELECT COUNT(*) AS total
            FROM logs_auditoria
            WHERE acao = 'login_falha'
              AND criado_em >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
              AND (ip = ? OR descricao LIKE ?)
        ";

        $likeNome = '%' . $nome . '%';
        $stmt = @$conexao->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ss', $ip, $likeNome);
        $stmt->execute();
        $result = $stmt->get_result();
        $total = (int) ($result->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        return $total >= $maxTentativas;
    }
}

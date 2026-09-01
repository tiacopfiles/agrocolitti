<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

function nfeEmailConfig(): array
{
    $passwordFile = trim((string) ($_ENV['SMTP_PASSWORD_FILE'] ?? getenv('SMTP_PASSWORD_FILE') ?: ''));
    $password = (string) ($_ENV['SMTP_PASSWORD'] ?? getenv('SMTP_PASSWORD') ?: '');
    if ($password === '' && $passwordFile !== '' && is_file($passwordFile)) {
        $password = preg_replace('/\s+/', '', (string) file_get_contents($passwordFile));
    }
    return [
        'host' => trim((string) ($_ENV['SMTP_HOST'] ?? getenv('SMTP_HOST') ?: 'smtplw.com.br')),
        'port' => (int) ($_ENV['SMTP_PORT'] ?? getenv('SMTP_PORT') ?: 465),
        'secure' => strtolower(trim((string) ($_ENV['SMTP_SECURE'] ?? getenv('SMTP_SECURE') ?: 'smtps'))),
        'username' => trim((string) ($_ENV['SMTP_USERNAME'] ?? getenv('SMTP_USERNAME') ?: '')),
        'password' => $password,
        'from_email' => trim((string) ($_ENV['SMTP_FROM_EMAIL'] ?? getenv('SMTP_FROM_EMAIL') ?: '')),
        'from_name' => trim((string) ($_ENV['SMTP_FROM_NAME'] ?? getenv('SMTP_FROM_NAME') ?: 'AgroColitti')),
    ];
}

function nfeEmailPareceXml(string $conteudo): bool
{
    $inicio = ltrim($conteudo, "\xEF\xBB\xBF \t\r\n");
    return str_starts_with($inicio, '<?xml') || str_starts_with($inicio, '<NFe')
        || str_starts_with($inicio, '<proc') || str_starts_with($inicio, '<nfeProc');
}

function nfeEmailBaixarXml(array $documento, array $focusConfig): string
{
    $caminho = trim((string) ($documento['caminho_xml'] ?? ''));
    if ($caminho === '') {
        throw new RuntimeException('O XML autorizado ainda nao foi disponibilizado pela Focus.');
    }
    $url = preg_match('#^https?://#i', $caminho)
        ? $caminho
        : rtrim((string) ($focusConfig['base_url'] ?? ''), '/') . '/' . ltrim($caminho, '/');
    $token = trim((string) ($focusConfig['token_ativo'] ?? ''));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING => '',
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $token . ':',
    ]);
    $conteudo = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    curl_close($ch);
    if (!is_string($conteudo) || $conteudo === '' || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('Nao foi possivel obter o XML autorizado da Focus.' . ($erro !== '' ? ' ' . $erro : ''));
    }
    if (!nfeEmailPareceXml($conteudo)) {
        throw new RuntimeException('O arquivo retornado pela Focus nao possui formato XML valido.');
    }
    return $conteudo;
}

function nfeEmailAtualizarStatus(mysqli $conexao, int $documentoId, string $status, ?string $destinatario = null, ?string $erro = null): void
{
    $enviadoEm = $status === 'enviado' ? date('Y-m-d H:i:s') : null;
    $stmt = $conexao->prepare(
        "UPDATE nfe_documentos
         SET email_xml_status=?, email_xml_destinatario=NULLIF(?, ''), email_xml_erro=NULLIF(?, ''),
             email_xml_enviado_em=IF(?='enviado', ?, email_xml_enviado_em), updated_at=CURRENT_TIMESTAMP
         WHERE id=?"
    );
    $destinatario = (string) $destinatario;
    $erro = (string) $erro;
    $stmt->bind_param('sssssi', $status, $destinatario, $erro, $status, $enviadoEm, $documentoId);
    $stmt->execute();
    $stmt->close();
}

function nfeEmailExecutarEnvioXml(mysqli $conexao, int $documentoId, array $focusConfig, bool $forcar = false): array
{
    if ($documentoId <= 0) {
        return ['status' => 'falhou', 'mensagem' => 'NF-e emitida, mas o envio do XML nao pôde ser iniciado.'];
    }
    $stmt = $conexao->prepare(
        "SELECT d.id, d.status, d.tipo_emissao, d.numero_nfe, d.serie, d.caminho_xml,
                d.email_xml_status, d.email_xml_erro, d.email_xml_tentativas,
                c.email_nfe, c.email_nfe_2, c.email_nfe_3
         FROM nfe_documentos d
         LEFT JOIN clientes c ON c.id=d.cliente_id
         WHERE d.id=? LIMIT 1"
    );
    $stmt->bind_param('i', $documentoId);
    $stmt->execute();
    $documento = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    if (!$documento || ($documento['tipo_emissao'] ?? '') !== 'venda' || ($documento['status'] ?? '') !== 'autorizada') {
        return ['status' => 'aguardando', 'mensagem' => 'O XML sera enviado quando a NF-e for autorizada.'];
    }
    $statusAtual = (string) ($documento['email_xml_status'] ?? '');
    if ($statusAtual === 'enviado' && !$forcar) {
        return ['status' => 'enviado', 'mensagem' => 'XML enviado por e-mail com sucesso.'];
    }
    $falhaXmlTemporaria = $statusAtual === 'falhou'
        && str_contains(strtolower((string) ($documento['email_xml_erro'] ?? '')), 'xml autorizado ainda nao foi disponibilizado')
        && (int) ($documento['email_xml_tentativas'] ?? 0) < 3;
    if (!$forcar && $statusAtual !== '' && $statusAtual !== 'aguardando_xml' && !$falhaXmlTemporaria) {
        return ['status' => $statusAtual, 'mensagem' => $statusAtual === 'sem_email'
            ? 'NF-e emitida normalmente; cliente sem e-mail cadastrado para o XML.'
            : 'NF-e emitida, mas o XML nao foi enviado por e-mail.'];
    }
    $emails = [];
    foreach (['email_nfe', 'email_nfe_2', 'email_nfe_3'] as $campoEmail) {
        $email = strtolower(trim((string) ($documento[$campoEmail] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $emails[$email] = $email;
    }
    $emails = array_values($emails);
    if (!$emails) {
        nfeEmailAtualizarStatus($conexao, $documentoId, 'sem_email');
        return ['status' => 'sem_email', 'mensagem' => 'NF-e emitida normalmente; cliente sem e-mail cadastrado para o XML.'];
    }

    if (trim((string) ($documento['caminho_xml'] ?? '')) === '') {
        nfeEmailAtualizarStatus($conexao, $documentoId, 'aguardando_xml', implode(', ', $emails));
        return ['status' => 'aguardando_xml', 'mensagem' => 'NF-e autorizada; aguardando a Focus disponibilizar o XML para envio por e-mail.'];
    }

    $sqlClaim = $forcar
        ? "UPDATE nfe_documentos SET email_xml_status='processando', email_xml_erro=NULL, email_xml_tentativas=email_xml_tentativas+1 WHERE id=? AND COALESCE(email_xml_status, '')<>'processando'"
        : "UPDATE nfe_documentos SET email_xml_status='processando', email_xml_erro=NULL, email_xml_tentativas=email_xml_tentativas+1 WHERE id=? AND (email_xml_status IS NULL OR email_xml_status='aguardando_xml' OR (email_xml_status='falhou' AND email_xml_tentativas<3 AND email_xml_erro LIKE '%XML autorizado ainda nao foi disponibilizado%'))";
    $stmt = $conexao->prepare($sqlClaim);
    $stmt->bind_param('i', $documentoId);
    $stmt->execute();
    $assumiuEnvio = $stmt->affected_rows === 1;
    $stmt->close();
    if (!$assumiuEnvio) {
        return ['status' => 'processando', 'mensagem' => 'NF-e emitida; o envio do XML por e-mail ja esta em processamento.'];
    }

    try {
        $config = nfeEmailConfig();
        if ($config['username'] === '' || $config['password'] === '' || $config['from_email'] === '') {
            throw new RuntimeException('Configuracao SMTP ainda nao concluida.');
        }
        $xml = nfeEmailBaixarXml($documento, $focusConfig);
        $numero = trim((string) ($documento['numero_nfe'] ?? '')) ?: (string) $documentoId;
        $serie = trim((string) ($documento['serie'] ?? ''));
        $rotulo = $serie !== '' ? $numero . '/' . $serie : $numero;
        $nomeArquivo = 'NFe_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $rotulo) . '.xml';
        $corpo = 'Olá, segue o arquivo XML da NFe ' . $rotulo . '.';

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->Port = $config['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['username'];
        $mail->Password = $config['password'];
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Timeout = 12;
        $mail->Timelimit = 12;
        if ($config['secure'] === 'starttls' || $config['secure'] === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($config['secure'] === 'smtps' || $config['secure'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }
        $mail->setFrom($config['from_email'], $config['from_name']);
        foreach ($emails as $email) $mail->addAddress($email);
        $mail->Subject = 'XML da NFe ' . $rotulo;
        $mail->Body = $corpo;
        $mail->AltBody = $corpo;
        $mail->addStringAttachment($xml, $nomeArquivo, PHPMailer::ENCODING_BASE64, 'application/xml');
        $mail->send();

        $destinatarios = implode(', ', $emails);
        nfeEmailAtualizarStatus($conexao, $documentoId, 'enviado', $destinatarios);
        return ['status' => 'enviado', 'mensagem' => 'XML enviado por e-mail com sucesso para ' . $destinatarios . '.'];
    } catch (Throwable $e) {
        nfeEmailAtualizarStatus($conexao, $documentoId, 'falhou', implode(', ', $emails), mb_substr($e->getMessage(), 0, 1000));
        error_log('Falha no e-mail XML da NF-e #' . $documentoId . ': ' . $e->getMessage());
        return ['status' => 'falhou', 'mensagem' => 'NF-e emitida normalmente, mas nao foi possivel enviar o XML por e-mail.'];
    }
}

function nfeEmailTentarEnviarXml(mysqli $conexao, int $documentoId, array $focusConfig, bool $forcar = false): array
{
    try {
        return nfeEmailExecutarEnvioXml($conexao, $documentoId, $focusConfig, $forcar);
    } catch (Throwable $e) {
        error_log('Falha isolada no fluxo de e-mail XML da NF-e #' . $documentoId . ': ' . $e->getMessage());
        return [
            'status' => 'falhou',
            'mensagem' => 'NF-e emitida normalmente, mas nao foi possivel processar o envio do XML por e-mail.',
        ];
    }
}

<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/permissions.php';
require __DIR__ . '/../../focus/focus_nfe_operacoes.php';
require __DIR__ . '/../../config/nfe_email_helper.php';

requireModule('exportar_nfe', '../historico_vendas.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../historico_vendas.php');
    exit;
}

validarTokenCsrf();

$isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
    || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

function responderNfeVenda(bool $ok, string $redirect = '', array $erros = [], ?array $emailXml = null): void
{
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => $ok,
            'redirect' => $redirect,
            'erros' => $erros,
            'erro' => $erros[0] ?? '',
            'email_xml' => $emailXml,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($ok) {
        header('Location: ' . $redirect);
    } else {
        header('Location: ../historico_vendas.php?msg=erro&detalhe=' . urlencode($erros[0] ?? 'Erro ao emitir NF-e.'));
    }
    exit;
}

$vendaId = isset($_POST['venda_id']) && $_POST['venda_id'] !== '' ? (int) $_POST['venda_id'] : null;
$numeroOs = isset($_POST['numero_os']) && trim((string) $_POST['numero_os']) !== '' ? trim((string) $_POST['numero_os']) : null;
$ambiente = $_POST['ambiente'] ?? 'producao';

try {
    $opcoes = focusNfeOpcoesVendaFromPost($_POST);
    $resultado = focusNfeEmitirVendaRevisada($conexao, $vendaId, $numeroOs, $opcoes, $ambiente);
    $httpCode = (int) ($resultado['http_code'] ?? 0);
    $ref = (string) ($resultado['ref'] ?? '');

    $statusFocus = is_array($resultado['response']) ? strtolower((string) ($resultado['response']['status'] ?? '')) : '';
    $mensagemSefaz = is_array($resultado['response']) ? (string) ($resultado['response']['mensagem_sefaz'] ?? '') : '';

    if ($httpCode === 201 && !str_contains($statusFocus, 'erro') && !str_contains($statusFocus, 'reje')) {
        $emailXml = null;
        if (str_contains($statusFocus, 'autoriz')) {
            $emailXml = nfeEmailTentarEnviarXml($conexao, (int) ($resultado['documento_id'] ?? 0), focusNfeLoadConfig($conexao, $ambiente));
        }
        $redirect = '../historico_vendas.php?msg=nfe_enviada&ref=' . urlencode($ref);
        if ($emailXml) {
            $redirect .= '&email_xml=' . urlencode((string) $emailXml['status']) . '&email_msg=' . urlencode((string) $emailXml['mensagem']);
        }
        responderNfeVenda(true, $redirect, [], $emailXml);
    }

    if ($httpCode === 202 && !str_contains($statusFocus, 'erro') && !str_contains($statusFocus, 'reje')) {
        responderNfeVenda(true, '../historico_vendas.php?msg=nfe_processando&ref=' . urlencode($ref));
    }

    if ($httpCode === 401) {
        $mensagem = 'Focus NFe retornou HTTP 401: token de producao recusado. Confira se o token informado e o token individual de emissao do emitente no ambiente de producao.';
    } elseif ($httpCode === 403) {
        $mensagem = 'Focus NFe retornou HTTP 403: token sem permissao para este emitente/ambiente.';
    } else {
        $mensagem = 'Focus NFe retornou HTTP ' . $httpCode . '.';
    }
    if (is_array($resultado['response'])) {
        $mensagemFocus = $resultado['response']['mensagem'] ?? $resultado['response']['erro'] ?? $resultado['response']['message'] ?? '';
        if ($mensagemSefaz !== '') {
            $mensagemFocus = $mensagemSefaz;
        }
        if (is_string($mensagemFocus) && $mensagemFocus !== '') {
            $mensagem .= ' ' . $mensagemFocus;
        }
    }
    if (!empty($resultado['error'])) {
        $mensagem .= ' ' . $resultado['error'];
    }

    responderNfeVenda(false, '', [$mensagem]);
} catch (Throwable $e) {
    responderNfeVenda(false, '', [$e->getMessage()]);
}

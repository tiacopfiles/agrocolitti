<?php
require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../auth/proteger.php';
require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../../contas/lib/contas_receber_integration.php'; // assert de banco + RecordEventoSeguro
require_once __DIR__ . '/../vendedores_helper.php';

requireModule('contas', '../../index.php');
header('Content-Type: application/json; charset=UTF-8');

// CSRF pelo corpo JSON (mesmo padrao do enviar_receber.php)
$rawBody = file_get_contents('php://input');
$jsonBody = json_decode((string) $rawBody, true);
if (is_array($jsonBody) && isset($jsonBody['csrf_token'])) {
    $_POST['csrf_token'] = (string) $jsonBody['csrf_token'];
}
validarTokenCsrf();

$respond = static function (array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    // Mesma trava do fluxo: so opera contra o banco AgroColitti de producao.
    contasReceberAssertAgroAllowed($conexao);
    comissoesVendedoresGarantirEstrutura($conexao);

    $integracaoId = (int) ($jsonBody['integracao_id'] ?? 0);
    if ($integracaoId <= 0) {
        $respond(['ok' => false, 'erro' => 'Integração inválida.'], 400);
    }

    // Carrega a integracao financeira ja enviada (fonte do valor base real enviado).
    $stmt = $conexao->prepare("
        SELECT origem_id, origem_ref, valortotal, status
        FROM contas_integracoes
        WHERE id = ? AND tipo = 'receber'
        LIMIT 1
    ");
    $stmt->bind_param('i', $integracaoId);
    $stmt->execute();
    $ci = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$ci) {
        $respond(['ok' => false, 'erro' => 'Integração não encontrada.'], 404);
    }
    if ((string) $ci['status'] !== 'enviado') {
        $respond(['ok' => false, 'erro' => 'A integração não está enviada; o reprocesso da comissão não se aplica.'], 409);
    }

    $nfeDocumentoId = (int) $ci['origem_id'];
    $numeroOs       = trim((string) $ci['origem_ref']);
    $valorBase      = (float) $ci['valortotal'];

    // Re-resolve o vendedor pela MESMA regra do envio (consolidacao por OS).
    $resolucao = comissoesVendedoresResolverPorOs($conexao, $numeroOs);

    if (($resolucao['status'] ?? '') !== 'resolvido') {
        contasReceberRecordEventoSeguro(
            $conexao, $integracaoId, null, 'comissao_vendedor_reprocesso',
            'Reprocesso nao resolveu: ' . (string) ($resolucao['status'] ?? 'desconhecido'),
            ['numero_os' => $numeroOs, 'nfe_documento_id' => $nfeDocumentoId, 'resolucao_vendedor' => $resolucao]
        );
        $respond([
            'ok'     => false,
            'status' => (string) ($resolucao['status'] ?? 'ausente'),
            'erro'   => 'Ainda não foi possível resolver o vendedor (' . (string) ($resolucao['status'] ?? '') . '). Corrija o cadastro da venda e reprocesse.',
        ]);
    }

    // Idempotente: o helper nao recria se ja existir comissao para a NF-e.
    $criada = comissoesVendedoresRegistrarEnvio(
        $conexao,
        $numeroOs,
        (string) $resolucao['vendedor'],
        $resolucao['vendedor_usuario_id'] ?? null,
        $resolucao['venda_id'] ?? null,
        $nfeDocumentoId,
        $integracaoId,
        $valorBase
    );

    contasReceberRecordEventoSeguro(
        $conexao, $integracaoId, null, 'comissao_vendedor_reprocesso',
        $criada ? 'Comissao criada via reprocesso.' : 'Comissao ja existia (idempotente).',
        ['numero_os' => $numeroOs, 'nfe_documento_id' => $nfeDocumentoId, 'vendedor' => (string) $resolucao['vendedor']]
    );

    $respond([
        'ok'       => true,
        'status'   => $criada ? 'criada' : 'ja_contabilizada',
        'vendedor' => (string) $resolucao['vendedor'],
    ]);
} catch (Throwable $e) {
    $respond(['ok' => false, 'erro' => 'Falha no reprocesso: ' . $e->getMessage()], 500);
}

<?php
/**
 * Cancela (baixa) um boleto SICOOB ja registrado.
 * Operacao destrutiva -> exige POST + CSRF. Chamado pelo botao em ver_boleto.php.
 *
 * ESCOPO: depende de "boletos_baixa" estar liberado no app e em SICOOB_SCOPES.
 * Sem ele, o Sicoob responde 401/403 e a mensagem de erro e exibida ao usuario.
 */
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/permissions.php';
require __DIR__ . '/../../boletos/sicoob_boleto_service.php';

// Mesma permissao da emissao/geracao: quem gera o boleto pode cancelar.
requireModule('exportar_nfe', '../historico_vendas.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../historico_vendas.php');
    exit;
}

validarTokenCsrf();

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

try {
    if ($id <= 0) {
        throw new RuntimeException('Boleto nao informado.');
    }

    $resultado = sicoobBoletoBaixar($conexao, $id);

    if (!empty($resultado['sucesso'])) {
        header('Location: ../../boletos/ver_boleto.php?id=' . $id . '&msg=cancelado');
        exit;
    }

    $detalhe = $resultado['mensagem'] !== '' ? $resultado['mensagem'] : ('HTTP ' . $resultado['http_code']);
    header('Location: ../../boletos/ver_boleto.php?id=' . $id . '&msg=erro_cancelar&detalhe=' . urlencode($detalhe));
    exit;

} catch (Throwable $e) {
    header('Location: ../../boletos/ver_boleto.php?id=' . $id . '&msg=erro_cancelar&detalhe=' . urlencode($e->getMessage()));
    exit;
}

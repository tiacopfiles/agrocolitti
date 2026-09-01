<?php
require_once __DIR__ . "/../../config/conexao.php";
require_once __DIR__ . "/../../config/ciclo_helper.php";
require_once __DIR__ . "/../../support/ciclo_fechamento_helper.php";
require_once __DIR__ . "/../../support/estoque_zeragem_helper.php";
require_once __DIR__ . "/../../auth/proteger.php";
require_once __DIR__ . "/../../config/permissions.php";
require_once __DIR__ . "/../../config/log_helper.php";

$redirect = '../estoque_inicial_aba.php';

requireModule('estoque', $redirect);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: {$redirect}");
    exit;
}

// Apenas administradores podem zerar a contagem (acao destrutiva).
if (($_SESSION['usuario_nivel'] ?? '') !== 'admin') {
    header("Location: {$redirect}?msg=erro&detalhe=" . urlencode('Apenas administradores podem zerar a contagem.'));
    exit;
}

validarTokenCsrf();

// Confirmacao explicita: o usuario precisa digitar a palavra ZERAR no modal.
if (($_POST['confirmacao'] ?? '') !== 'ZERAR') {
    header("Location: {$redirect}?msg=erro&detalhe=" . urlencode('Confirmacao invalida. Digite ZERAR para confirmar.'));
    exit;
}

$cicloId = getCicloAtivoId($conexao);
if (!$cicloId) {
    header("Location: {$redirect}?msg=erro&detalhe=" . urlencode('Nenhum ciclo ativo.'));
    exit;
}

// DDL de garantia deve ocorrer antes da transacao, pois MySQL executa commit
// implicito em CREATE/ALTER TABLE.
cicloFechamentoGarantirTabelas($conexao);

$conexao->begin_transaction();

try {
    $resultado = zerarContagemEstoqueDoCiclo($conexao, $cicloId);

    registrarLog(
        $conexao,
        'estoque_zerado',
        'estoque',
        $cicloId,
        "Fechamento operacional no ciclo {$cicloId}: {$resultado['arquivados']} registro(s) arquivado(s), {$resultado['preservados']} pendencia(s) preservada(s), {$resultado['estoques_iniciais_zerados']} base(s) inicial(is) zerada(s) e {$resultado['ajustes_criados']} ajuste(s) auditavel(is)."
    );

    $conexao->commit();
    header("Location: {$redirect}?msg=zerado");
    exit;

} catch (Throwable $e) {
    $conexao->rollback();
    header("Location: {$redirect}?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}

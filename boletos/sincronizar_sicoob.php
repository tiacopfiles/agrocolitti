<?php
require '../config/conexao.php';
require '../auth/proteger.php';
require '../config/permissions.php';
requireModule('historico_nfe', '../index.php');

require_once __DIR__ . '/sicoob_boleto_service.php';

try {
    $resultado   = sicoobBoletoSincronizarTodos($conexao);
    $atualizados = (int) $resultado['atualizados'];
    $verificados = (int) $resultado['verificados'];
    $detalhe     = $verificados . ' boleto(s) verificado(s), ' . $atualizados . ' atualizado(s).';
    header('Location: historico.php?msg=sincronizado&detalhe=' . urlencode($detalhe));
} catch (Throwable $e) {
    header('Location: historico.php?msg=erro_sync&detalhe=' . urlencode('Erro na sincronizacao: ' . $e->getMessage()));
}
exit;
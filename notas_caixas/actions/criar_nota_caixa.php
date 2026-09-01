<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/permissions.php';
require __DIR__ . '/../../focus/focus_nfe_caixas.php';

requireModule('notas_caixas', '../nova.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../nova.php');
    exit;
}

validarTokenCsrf();

try {
    $notaId = caixaNotasCriar(
        $conexao,
        (int) ($_POST['cliente_id'] ?? 0),
        (string) ($_POST['data_nota'] ?? ''),
        is_array($_POST['itens'] ?? null) ? $_POST['itens'] : [],
        (string) ($_POST['observacoes'] ?? '')
    );
    header('Location: ../historico.php?msg=criada&id=' . $notaId);
    exit;
} catch (Throwable $e) {
    header('Location: ../nova.php?msg=erro&detalhe=' . urlencode($e->getMessage()));
    exit;
}

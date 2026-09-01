<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/log_helper.php';
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../venda_helper.php';
require __DIR__ . '/../../config/ciclo_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../historico_vendas.php');
    exit;
}
validarTokenCsrf();
if (!in_array($_SESSION['usuario_nivel'] ?? '', ['admin', 'ti'], true)) {
    header('Location: ../historico_vendas.php?msg=erro&detalhe=' . urlencode('Sem permissao para copiar OS.'));
    exit;
}

$numeroOsOrigem = trim((string) ($_POST['numero_os'] ?? ''));
if ($numeroOsOrigem === '') {
    header('Location: ../historico_vendas.php?msg=erro&detalhe=' . urlencode('OS invalida.'));
    exit;
}

$conexao->begin_transaction();
try {
    $stmt = $conexao->prepare("SELECT id FROM vendas WHERE numero_os=? AND status='concluido' ORDER BY id FOR UPDATE");
    $stmt->bind_param('s', $numeroOsOrigem);
    $stmt->execute();
    $total = $stmt->get_result()->num_rows;
    $stmt->close();
    if ($total === 0) throw new RuntimeException('OS concluida nao encontrada para copiar.');

    $novoNumeroOs = proximoNumeroOsVenda($conexao);
    $cicloAtivo = getCicloIdParaTabela($conexao, 'vendas');
    $res = $conexao->query('SHOW COLUMNS FROM vendas');
    $colunas = [];
    while ($col = $res->fetch_assoc()) $colunas[] = (string) $col['Field'];

    $resetarNulo = [
        'data_confirmacao', 'foto', 'foto_comprovante', 'valor_final_original', 'valor_final_editado',
        'valor_final_editado_por', 'valor_final_editado_em', 'usuario_montagem_id',
        'usuario_montagem_nome', 'caixa_movimentacao_id', 'financeiro_id'
    ];
    $insertCols = [];
    $selectExpr = [];
    foreach ($colunas as $coluna) {
        if ($coluna === 'id') continue;
        $insertCols[] = '`' . $coluna . '`';
        if ($coluna === 'numero_os') $selectExpr[] = "'" . $conexao->real_escape_string($novoNumeroOs) . "'";
        elseif ($coluna === 'status') $selectExpr[] = "'pendente'";
        elseif ($coluna === 'ciclo_id') $selectExpr[] = $cicloAtivo === null ? 'NULL' : (string) ((int) $cicloAtivo);
        elseif (in_array($coluna, $resetarNulo, true)) $selectExpr[] = 'NULL';
        else $selectExpr[] = '`' . $coluna . '`';
    }
    $sql = 'INSERT INTO vendas (' . implode(',', $insertCols) . ') SELECT ' . implode(',', $selectExpr) . " FROM vendas WHERE numero_os=? AND status='concluido' ORDER BY id";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param('s', $numeroOsOrigem);
    $stmt->execute();
    if ($stmt->affected_rows !== $total) throw new RuntimeException('Nem todos os itens da OS foram copiados.');
    $primeiroId = (int) $conexao->insert_id;
    $stmt->close();
    $conexao->commit();

    registrarLog($conexao, 'os_duplicada_para_pendente', 'vendas', $primeiroId, "OS '$numeroOsOrigem' copiada como OS '$novoNumeroOs' com $total item(ns), sem movimentacao de estoque ou documentos fiscais.");
    header('Location: ../vendas.php?msg=os_duplicada&numero_os=' . urlencode($novoNumeroOs));
} catch (Throwable $e) {
    $conexao->rollback();
    header('Location: ../historico_vendas.php?msg=erro&detalhe=' . urlencode($e->getMessage()));
}
exit;

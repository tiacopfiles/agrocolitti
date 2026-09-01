<?php
require_once __DIR__ . "/../../config/conexao.php";
require_once __DIR__ . "/../../config/ciclo_helper.php";
require_once __DIR__ . "/../../auth/proteger.php";
require_once __DIR__ . "/../../config/permissions.php";

$redirectBase = '../index.php';

requireModule('ciclos', $redirectBase);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: {$redirectBase}");
    exit;
}

$cicloAtual = getCicloAtivo($conexao);

if (!$cicloAtual) {
    header("Location: {$redirectBase}?msg=erro&detalhe=" . urlencode("Nenhum ciclo ativo encontrado para desfazer."));
    exit;
}

$cicloAnterior = getCicloFechadoAnterior($conexao, $cicloAtual);

if (!$cicloAnterior) {
    header("Location: {$redirectBase}?msg=erro&detalhe=" . urlencode("Nenhum ciclo fechado anterior encontrado."));
    exit;
}

$esperado = getCicloAnteriorEsperado($cicloAtual);
if ((int) $cicloAnterior['ano'] !== $esperado['ano'] || (int) $cicloAnterior['mes'] !== $esperado['mes']) {
    header("Location: {$redirectBase}?msg=erro&detalhe=" . urlencode("Só é possível desfazer o fechamento do ciclo imediatamente anterior."));
    exit;
}

$stmtExecucao = $conexao->prepare("
    SELECT id, dry_run_json
    FROM ciclo_fechamento_execucoes
    WHERE ciclo_id = ? AND novo_ciclo_id = ? AND status = 'concluido'
    ORDER BY id DESC
    LIMIT 1
");
$cicloAnteriorIdConsulta = (int) $cicloAnterior['id'];
$cicloAtualIdConsulta = (int) $cicloAtual['id'];
$stmtExecucao->bind_param('ii', $cicloAnteriorIdConsulta, $cicloAtualIdConsulta);
$stmtExecucao->execute();
$execucaoFechamento = $stmtExecucao->get_result()->fetch_assoc();
$stmtExecucao->close();
$auditoriaFechamento = $execucaoFechamento
    ? json_decode((string) ($execucaoFechamento['dry_run_json'] ?? ''), true)
    : null;
$transferencias = is_array($auditoriaFechamento)
    ? ($auditoriaFechamento['transferencias'] ?? null)
    : null;
$novoCicloAnterior = is_array($auditoriaFechamento)
    ? ($auditoriaFechamento['novo_ciclo_anterior'] ?? null)
    : null;

if (!is_array($transferencias)) {
    header("Location: {$redirectBase}?msg=erro&detalhe=" . urlencode(
        "Este fechamento não possui o mapa de reversão seguro e não pode ser desfeito automaticamente."
    ));
    exit;
}

$tabelasReversiveis = [
    'vendas',
    'entradas',
    'movimentacoes',
    'previsao_fornecedor',
    'previsao_colheita',
    'abates',
];

// Bloqueia se o ciclo novo recebeu qualquer registro depois do fechamento.
foreach ($tabelasReversiveis as $tabela) {
    if (!colunaExiste($conexao, $tabela, 'ciclo_id')) {
        continue;
    }
    $idsEsperados = array_map('intval', $transferencias[$tabela] ?? []);
    $res = $conexao->query(
        "SELECT id FROM {$tabela} WHERE ciclo_id = {$cicloAtualIdConsulta}"
    );
    $idsAtuais = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $idsAtuais[] = (int) $row['id'];
    }
    sort($idsEsperados);
    sort($idsAtuais);
    if ($idsAtuais !== $idsEsperados) {
        header("Location: {$redirectBase}?msg=erro&detalhe=" . urlencode(
            "O ciclo atual já recebeu dados novos em {$tabela}; o desfazer foi bloqueado sem alterar nada."
        ));
        exit;
    }
}

$conexao->begin_transaction();

try {
    $cicloAtualId = (int) $cicloAtual['id'];
    $cicloAnteriorId = (int) $cicloAnterior['id'];
    $execucaoId = (int) $execucaoFechamento['id'];

    $stmtLock = $conexao->prepare("SELECT id FROM ciclos WHERE id IN (?, ?) FOR UPDATE");
    $stmtLock->bind_param('ii', $cicloAtualId, $cicloAnteriorId);
    $stmtLock->execute();
    $stmtLock->get_result();
    $stmtLock->close();

    foreach ($tabelasReversiveis as $tabela) {
        if (!colunaExiste($conexao, $tabela, 'ciclo_id')) {
            continue;
        }
        $ids = array_map('intval', $transferencias[$tabela] ?? []);
        if (!$ids) {
            continue;
        }
        $stmtVoltar = $conexao->prepare(
            "UPDATE {$tabela} SET ciclo_id = ? WHERE id = ? AND ciclo_id = ?"
        );
        foreach ($ids as $id) {
            $stmtVoltar->bind_param('iii', $cicloAnteriorId, $id, $cicloAtualId);
            $stmtVoltar->execute();
            if ($stmtVoltar->affected_rows !== 1) {
                $stmtVoltar->close();
                throw new RuntimeException("Falha ao reverter {$tabela} #{$id}.");
            }
        }
        $stmtVoltar->close();
    }

    // Remove o estoque inicial carregado para o ciclo novo, pois ele foi criado no fechamento anterior.
    if (colunaExiste($conexao, 'estoque_inicial', 'ciclo_id')) {
        $stmtEstoque = $conexao->prepare("DELETE FROM estoque_inicial WHERE ciclo_id = ?");
        $stmtEstoque->bind_param("i", $cicloAtualId);
        $stmtEstoque->execute();
        $stmtEstoque->close();
    }

    // Preserva snapshots e a auditoria. O ciclo aberto nao soma snapshots e
    // um novo fechamento atualiza os mesmos registros de forma idempotente.
    $motivoDesfeito = 'Fechamento desfeito com reversao integral das associacoes.';
    $stmtMarcarExecucao = $conexao->prepare("
        UPDATE ciclo_fechamento_execucoes
        SET status = 'falhou', erro = ?
        WHERE id = ?
    ");
    $stmtMarcarExecucao->bind_param('si', $motivoDesfeito, $execucaoId);
    $stmtMarcarExecucao->execute();
    $stmtMarcarExecucao->close();

    if (is_array($novoCicloAnterior)) {
        $nomeAnterior = (string) ($novoCicloAnterior['nome'] ?? '');
        $ativoAnterior = (int) ($novoCicloAnterior['ativo'] ?? 0);
        $statusAnterior = (string) ($novoCicloAnterior['status'] ?? 'fechado');
        if (colunaExiste($conexao, 'ciclos', 'aberto_em')
            && colunaExiste($conexao, 'ciclos', 'fechado_em')) {
            $abertoAnterior = $novoCicloAnterior['aberto_em'] ?? null;
            $fechadoAnterior = $novoCicloAnterior['fechado_em'] ?? null;
            $stmtRestaurarAtual = $conexao->prepare("
                UPDATE ciclos
                SET nome = ?, ativo = ?, status = ?, aberto_em = ?, fechado_em = ?
                WHERE id = ?
            ");
            $stmtRestaurarAtual->bind_param(
                'sisssi',
                $nomeAnterior,
                $ativoAnterior,
                $statusAnterior,
                $abertoAnterior,
                $fechadoAnterior,
                $cicloAtualId
            );
        } else {
            $stmtRestaurarAtual = $conexao->prepare("
                UPDATE ciclos SET nome = ?, ativo = ?, status = ? WHERE id = ?
            ");
            $stmtRestaurarAtual->bind_param(
                'sisi',
                $nomeAnterior,
                $ativoAnterior,
                $statusAnterior,
                $cicloAtualId
            );
        }
        $stmtRestaurarAtual->execute();
        $stmtRestaurarAtual->close();
    } else {
        // O ciclo foi criado pelo fechamento e continua vazio; pode ser removido.
        $stmtDeleteAtual = $conexao->prepare("DELETE FROM ciclos WHERE id = ?");
        $stmtDeleteAtual->bind_param("i", $cicloAtualId);
        $stmtDeleteAtual->execute();
        $stmtDeleteAtual->close();
    }

    // Reabre o ciclo anterior como ativo novamente.
    if (colunaExiste($conexao, 'ciclos', 'fechado_em')) {
        $stmtReabrir = $conexao->prepare("
            UPDATE ciclos
            SET ativo = 1, status = 'aberto', fechado_em = NULL
            WHERE id = ?
        ");
    } else {
        $stmtReabrir = $conexao->prepare("
            UPDATE ciclos
            SET ativo = 1, status = 'aberto'
            WHERE id = ?
        ");
    }
    $stmtReabrir->bind_param("i", $cicloAnteriorId);
    $stmtReabrir->execute();
    $stmtReabrir->close();

    $conexao->commit();

    header("Location: {$redirectBase}?msg=desfeito");
    exit;
} catch (Throwable $e) {
    $conexao->rollback();
    header("Location: {$redirectBase}?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}

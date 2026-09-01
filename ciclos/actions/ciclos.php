<?php
require_once __DIR__ . "/../../config/conexao.php";
require_once __DIR__ . "/../../config/ciclo_helper.php";
require_once __DIR__ . "/../../support/ciclo_fechamento_helper.php";
require_once __DIR__ . "/../../auth/proteger.php";
require_once __DIR__ . "/../../config/permissions.php";

$redirectBase = '../index.php';

requireModule('ciclos', $redirectBase);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: {$redirectBase}");
    exit;
}

if (($_POST['confirmar_fechamento'] ?? '') !== '1' || ($_POST['dry_run_conferido'] ?? '') !== '1') {
    header("Location: ../novo_ciclo.php");
    exit;
}

$cicloAtual = getCicloAtivo($conexao);

if (!$cicloAtual) {
    header("Location: {$redirectBase}?msg=erro&detalhe=" . urlencode("Nenhum ciclo ativo encontrado."));
    exit;
}

cicloFechamentoGarantirTabelas($conexao);

$conexao->begin_transaction();

try {
    $cicloAtualId = (int) $cicloAtual['id'];

    // Serializa o fechamento e confirma que o ciclo nao mudou desde a previa.
    $stmtLock = $conexao->prepare("
        SELECT *
        FROM ciclos
        WHERE id = ? AND ativo = 1 AND status = 'aberto'
        FOR UPDATE
    ");
    $stmtLock->bind_param('i', $cicloAtualId);
    $stmtLock->execute();
    $cicloTravado = $stmtLock->get_result()->fetch_assoc();
    $stmtLock->close();
    if (!$cicloTravado) {
        throw new RuntimeException('O ciclo ativo mudou durante o fechamento. Refaça a prévia.');
    }
    $cicloAtual = $cicloTravado;
    cicloFechamentoBloquearRegistros($conexao, $cicloAtualId);

    $periodo = cicloFechamentoPeriodo($cicloAtual);
    $agora = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    if ($agora->format('Y-m-d H:i:s') < $periodo['fim']) {
        throw new RuntimeException('O ciclo só pode ser fechado depois do término do mês de competência.');
    }

    $planoFechamento = cicloFechamentoPlanoMensal($conexao, $cicloAtual);
    if (!empty($planoFechamento['inconsistencias'])) {
        throw new RuntimeException(
            'Existem registros finalizados sem data válida ou anteriores à competência. '
            . 'O fechamento foi bloqueado sem alterar dados.'
        );
    }
    $mapaSnapshot = cicloFechamentoMapaSnapshot($planoFechamento);
    $totaisFechamento = cicloFechamentoTotais($planoFechamento);
    $estoqueNoCorte = calcularEstoqueProdutosDoCicloNoCorte(
        $conexao,
        $cicloAtualId,
        $periodo['fim']
    );

    $stmtExecucaoAnterior = $conexao->prepare("
        SELECT id
        FROM ciclo_fechamento_execucoes
        WHERE ciclo_id = ? AND status = 'concluido'
        LIMIT 1
    ");
    $stmtExecucaoAnterior->bind_param("i", $cicloAtualId);
    $stmtExecucaoAnterior->execute();
    $execucaoAnterior = $stmtExecucaoAnterior->get_result()->fetch_assoc();
    $stmtExecucaoAnterior->close();

    if ($execucaoAnterior) {
        throw new RuntimeException('Este ciclo já possui fechamento concluído. A operação foi bloqueada para evitar duplicidade.');
    }

    $usuarioId = isset($_SESSION['usuario_id']) ? (int) $_SESSION['usuario_id'] : null;
    $usuarioNome = $_SESSION['usuario_nome'] ?? ($_SESSION['nome'] ?? null);
    $proximoMes = (int) $periodo['proximo_mes'];
    $proximoAno = (int) $periodo['proximo_ano'];
    $aberturaNovoCiclo = $periodo['fim'];
    $novoNome = nomeMes($proximoMes) . " " . $proximoAno;
    $cicloExistente = getCicloPorAnoMes($conexao, $proximoAno, $proximoMes);
    if ($cicloExistente) {
        $bloqueiosCicloExistente = getBloqueiosDesfazerFechamento(
            $conexao,
            (int) $cicloExistente['id']
        );
        if (colunaExiste($conexao, 'estoque_inicial', 'ciclo_id')) {
            $stmtEstoqueExistente = $conexao->prepare(
                'SELECT COUNT(*) AS total FROM estoque_inicial WHERE ciclo_id = ?'
            );
            $cicloExistenteId = (int) $cicloExistente['id'];
            $stmtEstoqueExistente->bind_param('i', $cicloExistenteId);
            $stmtEstoqueExistente->execute();
            $temEstoqueExistente = (int) (
                $stmtEstoqueExistente->get_result()->fetch_assoc()['total'] ?? 0
            ) > 0;
            $stmtEstoqueExistente->close();
            if ($temEstoqueExistente) {
                $bloqueiosCicloExistente[] = 'estoque inicial';
            }
        }
        if ($bloqueiosCicloExistente) {
            throw new RuntimeException(
                'O próximo ciclo já existe e contém dados. O fechamento foi bloqueado para evitar duplicidade.'
            );
        }
    }
    $dryRunJson = json_encode([
        'periodo' => $periodo,
        'totais' => $totaisFechamento,
        'transferencias' => cicloFechamentoIdsTransferencia($planoFechamento),
        'novo_ciclo_anterior' => $cicloExistente ?: null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmtExecucao = $conexao->prepare("
        INSERT INTO ciclo_fechamento_execucoes (ciclo_id, status, dry_run_json, usuario_id, usuario_nome)
        VALUES (?, 'iniciado', ?, ?, ?)
    ");
    $stmtExecucao->bind_param("isis", $cicloAtualId, $dryRunJson, $usuarioId, $usuarioNome);
    $stmtExecucao->execute();
    $execucaoId = (int) $conexao->insert_id;
    $stmtExecucao->close();

    salvarSnapshotCiclo($conexao, $cicloAtualId, $mapaSnapshot, $estoqueNoCorte);
    cicloFechamentoSalvarRegistros($conexao, $cicloAtualId, $planoFechamento['arquivar']);
    cicloFechamentoSalvarRegistros($conexao, $cicloAtualId, $planoFechamento['carregar']);
    // Arquiva (sem apagar) as NF-e e boletos do periodo do ciclo, para o historico/dashboard
    // "zerarem" no proximo mes mantendo o registro fiscal preservado.
    cicloFechamentoArquivarFiscais($conexao, $cicloAtualId, $cicloAtual);

    if (colunaExiste($conexao, 'ciclos', 'fechado_em')) {
        $stmtFechar = $conexao->prepare("
            UPDATE ciclos
            SET ativo = 0, status = 'fechado', fechado_em = NOW()
            WHERE id = ?
        ");
    } else {
        $stmtFechar = $conexao->prepare("
            UPDATE ciclos
            SET ativo = 0, status = 'fechado'
            WHERE id = ?
        ");
    }
    $stmtFechar->bind_param("i", $cicloAtualId);
    $stmtFechar->execute();
    $stmtFechar->close();

    // Reaproveita o proximo ciclo se ele ja existir para evitar duplicacoes.
    if ($cicloExistente) {
        $novoCicloId = (int) $cicloExistente['id'];

        if (colunaExiste($conexao, 'ciclos', 'aberto_em') && colunaExiste($conexao, 'ciclos', 'fechado_em')) {
            $stmtNovo = $conexao->prepare("
                UPDATE ciclos
                SET nome = ?, ativo = 1, status = 'aberto', aberto_em = ?, fechado_em = NULL
                WHERE id = ?
            ");
            $stmtNovo->bind_param("ssi", $novoNome, $aberturaNovoCiclo, $novoCicloId);
        } elseif (colunaExiste($conexao, 'ciclos', 'aberto_em')) {
            $stmtNovo = $conexao->prepare("
                UPDATE ciclos
                SET nome = ?, ativo = 1, status = 'aberto', aberto_em = ?
                WHERE id = ?
            ");
            $stmtNovo->bind_param("ssi", $novoNome, $aberturaNovoCiclo, $novoCicloId);
        } else {
            $stmtNovo = $conexao->prepare("
                UPDATE ciclos
                SET nome = ?, ativo = 1, status = 'aberto'
                WHERE id = ?
            ");
            $stmtNovo->bind_param("si", $novoNome, $novoCicloId);
        }
    } else {
        if (colunaExiste($conexao, 'ciclos', 'aberto_em')) {
            $stmtNovo = $conexao->prepare("
                INSERT INTO ciclos (nome, ano, mes, ativo, status, aberto_em)
                VALUES (?, ?, ?, 1, 'aberto', ?)
            ");
        } else {
            $stmtNovo = $conexao->prepare("
                INSERT INTO ciclos (nome, ano, mes, ativo, status)
                VALUES (?, ?, ?, 1, 'aberto')
            ");
        }

        if (colunaExiste($conexao, 'ciclos', 'aberto_em')) {
            $stmtNovo->bind_param("siis", $novoNome, $proximoAno, $proximoMes, $aberturaNovoCiclo);
        } else {
            $stmtNovo->bind_param("sii", $novoNome, $proximoAno, $proximoMes);
        }
    }

    $stmtNovo->execute();
    if (!$cicloExistente) {
        $novoCicloId = (int) $conexao->insert_id;
    }
    $stmtNovo->close();

    // Garante que apenas um ciclo permaneca aberto/ativo no sistema.
    if (colunaExiste($conexao, 'ciclos', 'fechado_em')) {
        $stmtSanear = $conexao->prepare("
            UPDATE ciclos
            SET ativo = CASE WHEN id = ? THEN 1 ELSE 0 END,
                status = CASE WHEN id = ? THEN 'aberto' ELSE 'fechado' END,
                fechado_em = CASE
                    WHEN id = ? THEN NULL
                    WHEN status = 'aberto' OR ativo = 1 THEN NOW()
                    ELSE fechado_em
                END
            WHERE id <> ? OR id = ?
        ");
        $stmtSanear->bind_param("iiiii", $novoCicloId, $novoCicloId, $novoCicloId, $novoCicloId, $novoCicloId);
    } else {
        $stmtSanear = $conexao->prepare("
            UPDATE ciclos
            SET ativo = CASE WHEN id = ? THEN 1 ELSE 0 END,
                status = CASE WHEN id = ? THEN 'aberto' ELSE 'fechado' END
            WHERE id <> ? OR id = ?
        ");
        $stmtSanear->bind_param("iiii", $novoCicloId, $novoCicloId, $novoCicloId, $novoCicloId);
    }
    $stmtSanear->execute();
    $stmtSanear->close();

    if (colunaExiste($conexao, 'estoque_inicial', 'ciclo_id')) {
        $stmtEstoque = $conexao->prepare("
            INSERT INTO estoque_inicial (ciclo_id, produto_id, quantidade)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE quantidade = VALUES(quantidade)
        ");

        foreach ($estoqueNoCorte as $item) {
            $produtoId = (int) $item['produto_id'];
            $quantidade = (float) $item['estoque'];
            $stmtEstoque->bind_param("iid", $novoCicloId, $produtoId, $quantidade);
            $stmtEstoque->execute();
        }

        $stmtEstoque->close();
    }

    // Pendencias antigas e operacoes ja realizadas no novo mes passam para o
    // ciclo novo. Nada e apagado das tabelas operacionais.
    cicloFechamentoTransferirRegistros(
        $conexao,
        $planoFechamento,
        $cicloAtualId,
        $novoCicloId
    );

    $totaisJson = json_encode($totaisFechamento, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmtExecucaoOk = $conexao->prepare("
        UPDATE ciclo_fechamento_execucoes
        SET novo_ciclo_id = ?, status = 'concluido', totais_json = ?, concluido_em = NOW()
        WHERE id = ?
    ");
    $stmtExecucaoOk->bind_param("isi", $novoCicloId, $totaisJson, $execucaoId);
    $stmtExecucaoOk->execute();
    $stmtExecucaoOk->close();

    $conexao->commit();

    header("Location: {$redirectBase}?msg=fechado");
    exit;
} catch (Throwable $e) {
    $conexao->rollback();
    header("Location: {$redirectBase}?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}

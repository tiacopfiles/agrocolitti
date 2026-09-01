<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/ciclo_helper.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../../comissoes/meeiros_helper_v2.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../previsoes/../previsao_colheita.php");
    exit;
}

$id                  = intval($_POST['id'] ?? 0);
$numeroPedido        = trim((string) ($_POST['numero_pedido'] ?? ''));
$qualidadeComercial  = trim((string) ($_POST['qualidade_comercial'] ?? ''));
$qualidadesPost      = is_array($_POST['qualidades'] ?? null) ? $_POST['qualidades'] : [];
$quantidade_recebida = (float) str_replace(',', '.', (string) ($_POST['quantidade_recebida'] ?? 0));
$quantidade_abatida  = max(0.0, (float) str_replace(',', '.', (string) ($_POST['quantidade_abatida'] ?? 0)));
$motivo              = trim($_POST['motivo_abatimento'] ?? '');

if ($id <= 0) {
    header("Location: ../previsoes/../previsao_colheita.php?msg=erro&detalhe=ID+inválido");
    exit;
}

if ($numeroPedido === '') {
    header("Location: ../previsoes/../previsao_colheita.php?msg=erro&detalhe=Numero+do+pedido+obrigatorio");
    exit;
}

comissoesMeeirosGarantirEstrutura($conexao);

function montarResumoQualidadesColheita(array $qualidades): string
{
    $partes = [];
    foreach ($qualidades as $linha) {
        if (!is_array($linha)) {
            continue;
        }
        $qualidade = comissoesMeeirosQualidadeNormalizada($linha['qualidade_comercial'] ?? '');
        $peso = (float) str_replace(',', '.', (string) ($linha['peso_kg'] ?? 0));
        if ($qualidade === '' || $peso <= 0) {
            continue;
        }
        $partes[] = $qualidade . ': ' . number_format($peso, 2, '.', '') . ' kg';
    }
    return implode('; ', $partes);
}

$conexao->begin_transaction();

try {
    // Buscar e travar previsão
    $stmt = $conexao->prepare("SELECT * FROM previsao_colheita WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $previsao = $stmt->get_result()->fetch_assoc();

    if (!$previsao) throw new Exception("Previsão não encontrada.");

    if (($previsao['status'] ?? '') !== 'pendente') {
        throw new Exception("Esta previsao ja foi processada (status: {$previsao['status']}).");
    }

    $produto_id = $previsao['produto_id'];
    $cicloBaseId = isset($previsao['ciclo_id']) ? (int) $previsao['ciclo_id'] : null;
    $cicloEntradaId = colunaExiste($conexao, 'entradas', 'ciclo_id') ? ($cicloBaseId ?: getCicloAtivoId($conexao)) : null;
    $cicloMovId = colunaExiste($conexao, 'movimentacoes', 'ciclo_id') ? ($cicloBaseId ?: getCicloAtivoId($conexao)) : null;
    $cicloAbateId = colunaExiste($conexao, 'abates', 'ciclo_id') ? ($cicloBaseId ?: getCicloAtivoId($conexao)) : null;
    $quantidadeOriginal = (float) $previsao['quantidade_prevista'];
    $quantidade = max(0.0, ($quantidade_recebida > 0 ? $quantidade_recebida : $quantidadeOriginal) - $quantidade_abatida);

    $motivoFinal = ($quantidade_abatida > 0) ? $motivo : null;
    if ($quantidade_abatida > 0 && $motivoFinal === '') {
        throw new Exception("Informe o motivo do abatimento.");
    }
    if ($quantidade <= 0) {
        throw new Exception("Quantidade real invalida.");
    }
    $qualidadesResumo = montarResumoQualidadesColheita($qualidadesPost);
    if ($qualidadesResumo === '') {
        $qualidadesResumo = comissoesMeeirosQualidadeNormalizada($qualidadeComercial);
    }

    // Atualizar status
    $stmtUpdate = $conexao->prepare("UPDATE previsao_colheita SET status = 'confirmado', numero_pedido = ? WHERE id = ?");
    $stmtUpdate->bind_param("si", $numeroPedido, $id);
    $stmtUpdate->execute();

    // Registrar no histórico de entradas
    if ($cicloEntradaId !== null) {
        $stmtEntrada = $conexao->prepare("
            INSERT INTO entradas (ciclo_id, produto_id, quantidade, tipo, data_entrada, motivo_abatimento)
            VALUES (?, ?, ?, 'colheita', NOW(), ?)
        ");
        $stmtEntrada->bind_param("iids", $cicloEntradaId, $produto_id, $quantidade, $motivoFinal);
    } else {
        $stmtEntrada = $conexao->prepare("
            INSERT INTO entradas (produto_id, quantidade, tipo, data_entrada, motivo_abatimento)
            VALUES (?, ?, 'colheita', NOW(), ?)
        ");
        $stmtEntrada->bind_param("ids", $produto_id, $quantidade, $motivoFinal);
    }
    $stmtEntrada->execute();

    // Registrar movimentação (controle de estoque)
    if ($cicloMovId !== null) {
        $stmtMov = $conexao->prepare("
            INSERT INTO movimentacoes (ciclo_id, produto_id, tipo, quantidade, data_movimentacao)
            VALUES (?, ?, 'entrada_colheita', ?, NOW())
        ");
        $stmtMov->bind_param("iid", $cicloMovId, $produto_id, $quantidade);
    } else {
        $stmtMov = $conexao->prepare("
            INSERT INTO movimentacoes (produto_id, tipo, quantidade, data_movimentacao)
            VALUES (?, 'entrada_colheita', ?, NOW())
        ");
        $stmtMov->bind_param("id", $produto_id, $quantidade);
    }
    $stmtMov->execute();

    if ($quantidade_abatida > 0) {
        if ($cicloAbateId !== null) {
            $stmtAbate = $conexao->prepare("
                INSERT INTO abates (ciclo_id, tipo, previsao_id, numero_pedido, qualidade_comercial, quantidade_original, quantidade_abatida, quantidade_final, motivo)
                VALUES (?, 'colheita', ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtAbate->bind_param("iissddds", $cicloAbateId, $id, $numeroPedido, $qualidadesResumo, $quantidadeOriginal, $quantidade_abatida, $quantidade, $motivoFinal);
        } else {
            $stmtAbate = $conexao->prepare("
                INSERT INTO abates (tipo, previsao_id, numero_pedido, qualidade_comercial, quantidade_original, quantidade_abatida, quantidade_final, motivo)
                VALUES ('colheita', ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtAbate->bind_param("issddds", $id, $numeroPedido, $qualidadesResumo, $quantidadeOriginal, $quantidade_abatida, $quantidade, $motivoFinal);
        }
        $stmtAbate->execute();
    }

    comissoesMeeirosRegistrarConfirmacaoDistribuida(
        $conexao,
        $previsao,
        $numeroPedido,
        $qualidadesPost ?: [[
            'qualidade_comercial' => $qualidadeComercial,
            'peso_kg' => $quantidade,
        ]],
        $quantidadeOriginal,
        $quantidade_abatida,
        $quantidade
    );

    $conexao->commit();

    header("Location: ../previsoes/../previsao_colheita.php?msg=entrada_registrada");
    exit;
} catch (Exception $e) {
    $conexao->rollback();
    $msg = urlencode($e->getMessage());
    header("Location: ../previsoes/../previsao_colheita.php?msg=erro&detalhe=$msg");
    exit;
}



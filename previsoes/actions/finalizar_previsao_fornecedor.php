<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/ciclo_helper.php";
require __DIR__ . "/../../config/previsao_fornecedor_schema.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../pedido_compra_helper.php";
require __DIR__ . "/../../config/cadastro_fiscal_helper.php";

garantirFluxoFinanceiroPrevisaoFornecedor($conexao);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../previsoes/../previsao_fornecedor.php");
    exit;
}

$id                  = intval($_POST['id'] ?? 0);
$quantidade_recebida = (float) str_replace(',', '.', (string) ($_POST['quantidade_recebida'] ?? 0));
$quantidade_abatida  = max(0.0, (float) str_replace(',', '.', (string) ($_POST['quantidade_abatida'] ?? 0)));
$motivo              = trim($_POST['motivo_abatimento'] ?? '');

if ($quantidade_abatida > 0 && $motivo === '') {
    header("Location: ../previsoes/../previsao_fornecedor.php?msg=erro&detalhe=" . urlencode("Informe o motivo do abatimento."));
    exit;
}

if ($id <= 0) {
    header("Location: ../previsoes/../previsao_fornecedor.php?msg=erro&detalhe=ID+inválido");
    exit;
}

// Buscar previsao somente depois que a OS foi enviada para pendentes.
$stmt = $conexao->prepare("SELECT * FROM previsao_fornecedor WHERE id = ? AND status = 'pendente'");
$stmt->bind_param("i", $id);
$stmt->execute();
$previsao = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$previsao) {
    header("Location: ../previsoes/../previsao_fornecedor.php?msg=erro&detalhe=Previsao+pendente+nao+encontrada");
    exit;
}

// pegar numero_os
$numero_os = $previsao['numero_os'];

$produto_id    = $previsao['produto_id'];
$fornecedor_id = $previsao['fornecedor_id'];
try {
    cadastroFiscalExigirCompra($conexao, (int) $fornecedor_id, (int) $produto_id);
} catch (Throwable $e) {
    header("Location: ../previsao_fornecedor.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}
$cicloBaseId   = isset($previsao['ciclo_id']) ? (int) $previsao['ciclo_id'] : null;
$cicloEntradaId = colunaExiste($conexao, 'entradas', 'ciclo_id') ? ($cicloBaseId ?: getCicloAtivoId($conexao)) : null;
$cicloMovId = colunaExiste($conexao, 'movimentacoes', 'ciclo_id') ? ($cicloBaseId ?: getCicloAtivoId($conexao)) : null;
$cicloAbateId = colunaExiste($conexao, 'abates', 'ciclo_id') ? ($cicloBaseId ?: getCicloAtivoId($conexao)) : null;
$revendaSaoPaulo = !empty($previsao['revenda_sao_paulo']);

$quantidadeOriginal = (float) $previsao['quantidade_prevista'];
$quantidade = max(0.0, ($quantidade_recebida > 0 ? $quantidade_recebida : $quantidadeOriginal) - $quantidade_abatida);
if ($quantidade <= 0) {
    header("Location: ../previsoes/../previsao_fornecedor.php?msg=erro&detalhe=" . urlencode("Quantidade recebida invalida. Para produto que nao chegou, use a opcao de excluir."));
    exit;
}

$motivoFinal = ($quantidade_abatida > 0) ? $motivo : null;

pedidoCompraTabelaSnapshotGarantida($conexao);
$conexao->begin_transaction();

try {
    // Atualizar previsão
    $stmt = $conexao->prepare("
        UPDATE previsao_fornecedor
        SET status = 'concluido', motivo_abatimento = ?, quantidade_recebida = ?, data_confirmacao = NOW()
        WHERE id = ? AND status = 'pendente'
    ");
    $stmt->bind_param("sdi", $motivoFinal, $quantidade, $id);
    $stmt->execute();
    if ($stmt->affected_rows <= 0) {
        $stmt->close();
        throw new Exception("Previsao pendente nao encontrada para finalizar");
    }
    $stmt->close();

    //agora salva numero_os
    if ($cicloEntradaId !== null) {
        $stmt = $conexao->prepare("
            INSERT INTO entradas 
            (ciclo_id, produto_id, fornecedor_id, quantidade, tipo, data_entrada, motivo_abatimento, numero_os)
            VALUES (?, ?, ?, ?, 'entrada_fornecedor', NOW(), ?, ?)
        ");
        $stmt->bind_param("iiidss", $cicloEntradaId, $produto_id, $fornecedor_id, $quantidade, $motivoFinal, $numero_os);
    } else {
        $stmt = $conexao->prepare("
            INSERT INTO entradas 
            (produto_id, fornecedor_id, quantidade, tipo, data_entrada, motivo_abatimento, numero_os)
            VALUES (?, ?, ?, 'entrada_fornecedor', NOW(), ?, ?)
        ");
        $stmt->bind_param("iidss", $produto_id, $fornecedor_id, $quantidade, $motivoFinal, $numero_os);
    }
    $stmt->execute();

    if (!$revendaSaoPaulo) {
        // Registrar movimentacao no estoque apenas quando a compra entra na base.
        if ($cicloMovId !== null) {
            $stmt = $conexao->prepare("
                INSERT INTO movimentacoes (ciclo_id, produto_id, tipo, quantidade, data_movimentacao)
                VALUES (?, ?, 'entrada_fornecedor', ?, NOW())
            ");
            $stmt->bind_param("iid", $cicloMovId, $produto_id, $quantidade);
        } else {
            $stmt = $conexao->prepare("
                INSERT INTO movimentacoes (produto_id, tipo, quantidade, data_movimentacao)
                VALUES (?, 'entrada_fornecedor', ?, NOW())
            ");
            $stmt->bind_param("id", $produto_id, $quantidade);
        }
        $stmt->execute();
    }

    if ($quantidade_abatida > 0) {
        if ($cicloAbateId !== null) {
            $stmt = $conexao->prepare("
                INSERT INTO abates (ciclo_id, tipo, previsao_id, quantidade_original, quantidade_abatida, quantidade_final, motivo)
                VALUES (?, 'fornecedor', ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iiddds", $cicloAbateId, $id, $quantidadeOriginal, $quantidade_abatida, $quantidade, $motivoFinal);
        } else {
            $stmt = $conexao->prepare("
                INSERT INTO abates (tipo, previsao_id, quantidade_original, quantidade_abatida, quantidade_final, motivo)
                VALUES ('fornecedor', ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iddds", $id, $quantidadeOriginal, $quantidade_abatida, $quantidade, $motivoFinal);
        }
        $stmt->execute();
    }

    $responsavel = $_SESSION['usuario_nome'] ?? $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '';
    pedidoCompraAtualizarSnapshotSePossivel($conexao, (string) $numero_os, (string) $responsavel);

    $conexao->commit();

    header("Location: ../previsao_fornecedor.php?msg=entrada_registrada");
    exit;
} catch (Exception $e) {
    $conexao->rollback();
    $msg = urlencode($e->getMessage());
    header("Location: ../previsoes/../previsao_fornecedor.php?msg=erro&detalhe=$msg");
    exit;
}

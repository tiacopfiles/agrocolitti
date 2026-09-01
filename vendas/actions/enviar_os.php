<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/ciclo_helper.php";
require __DIR__ . "/../../config/log_helper.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../../config/cadastro_fiscal_helper.php";

function garantirColunasMontagemVendaEnvio(mysqli $conexao): void
{
    $colunas = [
        'usuario_montagem_id' => "ALTER TABLE vendas ADD COLUMN usuario_montagem_id INT NULL DEFAULT NULL",
        'usuario_montagem_nome' => "ALTER TABLE vendas ADD COLUMN usuario_montagem_nome VARCHAR(120) NULL DEFAULT NULL AFTER usuario_montagem_id",
    ];

    foreach ($colunas as $coluna => $sql) {
        $resultado = $conexao->query("SHOW COLUMNS FROM vendas LIKE '{$coluna}'");
        $existe = $resultado && $resultado->num_rows > 0;
        if ($resultado instanceof mysqli_result) {
            $resultado->free();
        }
        if (!$existe) {
            $conexao->query($sql);
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../vendas.php");
    exit;
}

validarTokenCsrf();
garantirColunasMontagemVendaEnvio($conexao);
$usuarioMontagemId = (int) ($_SESSION['usuario_id'] ?? 0);
$entrepostoPresente = array_key_exists('entreposto', $_POST);
$entreposto = trim((string) ($_POST['entreposto'] ?? ''));
$prazoPagamentoPresente = array_key_exists('prazo_pagamento', $_POST);
$prazoPagamento = trim((string) ($_POST['prazo_pagamento'] ?? ''));
$formaPagamentoPresente = array_key_exists('forma_pagamento', $_POST);
$formaPagamento = strtolower(trim((string) ($_POST['forma_pagamento'] ?? '')));
$consideracoesPresente = array_key_exists('consideracoes', $_POST);
$consideracoes = trim((string) ($_POST['consideracoes'] ?? ''));

$prazosPermitidos = ['5 dias', '7 dias', '15 dias', '21 dias', '30 dias', '35 dias', '45 dias'];
if ($prazoPagamentoPresente && !in_array($prazoPagamento, $prazosPermitidos, true)) {
    header("Location: ../vendas.php?msg=erro&detalhe=" . urlencode("Prazo de pagamento invalido."));
    exit;
}
if ($formaPagamentoPresente && !in_array($formaPagamento, ['boleto', 'deposito', 'pix'], true)) {
    header("Location: ../vendas.php?msg=erro&detalhe=" . urlencode("Forma de pagamento invalida."));
    exit;
}

$entradaOs = $_POST["numero_os"] ?? [];
$listaOs = is_array($entradaOs)
    ? array_values(array_filter(array_map(static fn($os) => trim((string) $os), $entradaOs), static fn($os) => $os !== ''))
    : [trim((string) $entradaOs)];
$listaOs = array_values(array_unique($listaOs));

if (empty($listaOs)) {
    header("Location: ../vendas.php?msg=erro&detalhe=" . urlencode("Numero de OS invalido."));
    exit;
}

$conexao->begin_transaction();

try {
    $total = 0;
    $enviadas = [];

    foreach ($listaOs as $numero_os) {
        cadastroFiscalExigirOsVenda($conexao, $numero_os);
        $stmtCount = $conexao->prepare("
            SELECT COUNT(*) AS total FROM vendas
            WHERE numero_os = ? AND status = 'anexado'
              AND (usuario_montagem_id = ? OR usuario_montagem_id IS NULL)
        ");
        $stmtCount->bind_param("si", $numero_os, $usuarioMontagemId);
        $stmtCount->execute();
        $totalOs = (int) $stmtCount->get_result()->fetch_assoc()['total'];
        $stmtCount->close();

        if ($totalOs <= 0) {
            continue;
        }

        $camposUpdate = ["status = 'pendente'"];
        $tiposUpdate = '';
        $valoresUpdate = [];
        if ($entrepostoPresente) {
            $camposUpdate[] = 'entreposto = ?';
            $tiposUpdate .= 's';
            $valoresUpdate[] = $entreposto;
        }
        if ($prazoPagamentoPresente) {
            $camposUpdate[] = 'prazo_pagamento = ?';
            $tiposUpdate .= 's';
            $valoresUpdate[] = $prazoPagamento;
        }
        if ($formaPagamentoPresente) {
            $camposUpdate[] = 'forma_pagamento = ?';
            $tiposUpdate .= 's';
            $valoresUpdate[] = $formaPagamento;
        }
        if ($consideracoesPresente) {
            $camposUpdate[] = 'consideracoes = ?';
            $tiposUpdate .= 's';
            $valoresUpdate[] = $consideracoes;
        }
        $tiposUpdate .= 'si';
        $valoresUpdate[] = $numero_os;
        $valoresUpdate[] = $usuarioMontagemId;

        $stmtUpdate = $conexao->prepare("
            UPDATE vendas SET " . implode(', ', $camposUpdate) . "
            WHERE numero_os = ? AND status = 'anexado'
              AND (usuario_montagem_id = ? OR usuario_montagem_id IS NULL)
        ");
        $stmtUpdate->bind_param($tiposUpdate, ...$valoresUpdate);
        $stmtUpdate->execute();
        $stmtUpdate->close();

        $total += $totalOs;
        $enviadas[] = $numero_os;
    }

    if ($total <= 0) {
        throw new Exception("Nao ha vendas anexadas para as OS selecionadas.");
    }

    $conexao->commit();

    registrarLog(
        $conexao,
        'os_enviada',
        'vendas',
        0,
        "OS enviada(s) para pendentes: " . implode(', ', $enviadas) . " - $total item(s)"
    );

    header("Location: ../vendas.php?msg=enviado_lote&total={$total}");
    exit;
} catch (Exception $e) {
    $conexao->rollback();
    header("Location: ../vendas.php?msg=erro&detalhe=" . urlencode($e->getMessage()));
    exit;
}

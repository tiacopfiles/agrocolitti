<?php
ob_start();

require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/previsao_fornecedor_schema.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../pedido_compra_helper.php";
require_once __DIR__ . "/../../vendor/autoload.php";

$numeroOs = trim((string) ($_GET['numero_os'] ?? ''));
$responsavel = $_SESSION['usuario_nome'] ?? $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '';

garantirFluxoFinanceiroPrevisaoFornecedor($conexao);

$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'agrocolitti_preview_' . bin2hex(random_bytes(8));
if (!mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
    http_response_code(500);
    exit('Nao foi possivel criar a pasta temporaria do preview.');
}

try {
    $pedido = pedidoCompraObterSnapshot($conexao, $numeroOs, (string) $responsavel);
    if (empty($pedido['responsavel']) && $responsavel !== '') {
        $pedido['responsavel'] = (string) $responsavel;
    }

    $baseNome = 'Pedido_Venda_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $numeroOs);
    $docxPath = $tmpDir . DIRECTORY_SEPARATOR . $baseNome . '.docx';

    pedidoCompraGerarDocxModeloVenda($pedido, $docxPath);
    $pdfPath = pedidoCompraConverterDocxParaPdf($docxPath, $tmpDir);
    $pdf = file_get_contents($pdfPath);
    if ($pdf === false) {
        throw new RuntimeException('Nao foi possivel ler o PDF gerado para preview.');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $baseNome . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $e->getMessage();
} finally {
    foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $arquivo) {
        if (is_file($arquivo)) {
            @unlink($arquivo);
        }
    }
    @rmdir($tmpDir);
}

exit;
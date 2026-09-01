<?php
ob_start();
require __DIR__ . '/../config/conexao.php';
require __DIR__ . '/../auth/proteger.php';
require __DIR__ . '/../config/permissions.php';

requireModule('historico_vendas', '../vendas/historico_vendas.php');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit('Boleto invalido.');
}

$stmt = $conexao->prepare('SELECT id, seu_numero, pdf_path FROM boletos WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$boleto = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$boleto) {
    http_response_code(404);
    exit('Boleto nao encontrado.');
}

$pdfRel = ltrim(str_replace('\\', '/', (string) ($boleto['pdf_path'] ?? '')), '/');
if ($pdfRel === '' || str_contains($pdfRel, '..')) {
    http_response_code(404);
    exit('PDF do boleto nao disponivel.');
}

$baseAtual = dirname(__DIR__);
$candidatos = [
    $baseAtual . '/' . $pdfRel,
];

// Fallback para PDFs gerados antes da migracao final da API para o agrocolitti.
$baseAntigo = dirname($baseAtual) . '/agrocolitti_boleto';
$candidatos[] = $baseAntigo . '/' . $pdfRel;

$arquivo = '';
foreach ($candidatos as $cand) {
    if (is_file($cand) && is_readable($cand)) {
        $arquivo = $cand;
        break;
    }
}

if ($arquivo === '') {
    http_response_code(404);
    exit('Arquivo PDF do boleto nao encontrado no servidor.');
}

$bin = file_get_contents($arquivo);
if ($bin === false || $bin === '') {
    http_response_code(404);
    exit('Arquivo PDF do boleto vazio ou ilegivel.');
}

$pdfPos = strpos($bin, '%PDF');
if ($pdfPos === false) {
    http_response_code(500);
    exit('Arquivo encontrado, mas o conteudo nao e um PDF valido.');
}
if ($pdfPos > 0) {
    $bin = substr($bin, $pdfPos);
}

$eofPos = strrpos($bin, '%%EOF');
if ($eofPos !== false) {
    $bin = substr($bin, 0, $eofPos + 5);
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

$nome = basename($arquivo);
header('Content-Type: application/pdf');
header('X-Content-Type-Options: nosniff');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . strlen($bin));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $nome) . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
echo $bin;
exit;
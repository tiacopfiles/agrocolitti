<?php
/**
 * Exclui o REGISTRO LOCAL de um boleto que NAO esta ativo no Sicoob
 * (situacao erro/rascunho ou ja baixado/cancelado).
 * Trava de seguranca: NUNCA exclui um boleto registrado/emitido ativo
 * (esse so sai via "Cancelar boleto" -> baixa no Sicoob).
 */
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/permissions.php';

requireModule('exportar_nfe', '../historico_vendas.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../historico_vendas.php');
    exit;
}

validarTokenCsrf();

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    header('Location: ../historico_vendas.php?msg=erro&detalhe=' . urlencode('Boleto nao informado.'));
    exit;
}

$stmt = $conexao->prepare('SELECT situacao, nosso_numero, pdf_path FROM boletos WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$b = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$b) {
    header('Location: ../historico_vendas.php?msg=erro&detalhe=' . urlencode('Boleto nao encontrado.'));
    exit;
}

$situacao = (string) ($b['situacao'] ?? '');
$temNossoNumero = trim((string) ($b['nosso_numero'] ?? '')) !== '';

// Bloqueia exclusao local de boleto ativo no Sicoob.
$bloqueado = in_array($situacao, ['registrado', 'emitido'], true)
          || ($temNossoNumero && !in_array($situacao, ['baixado', 'cancelado'], true));

if ($bloqueado) {
    header('Location: ../../boletos/ver_boleto.php?id=' . $id . '&msg=erro_excluir&detalhe='
        . urlencode('Boleto ativo no Sicoob (situacao: ' . $situacao . '). Use "Cancelar boleto" para baixa-lo antes de excluir.'));
    exit;
}

// Remove o PDF local, se houver.
$pdf = (string) ($b['pdf_path'] ?? '');
if ($pdf !== '') {
    $abs = __DIR__ . '/../../' . ltrim($pdf, '/');
    if (is_file($abs)) {
        @unlink($abs);
    }
}

// Remove tentativas e o proprio boleto.
$d1 = $conexao->prepare('DELETE FROM boleto_tentativas WHERE boleto_id = ?');
$d1->bind_param('i', $id);
$d1->execute();
$d1->close();

$d2 = $conexao->prepare('DELETE FROM boletos WHERE id = ?');
$d2->bind_param('i', $id);
$d2->execute();
$d2->close();

header('Location: ../historico_vendas.php?msg=boleto_excluido');
exit;
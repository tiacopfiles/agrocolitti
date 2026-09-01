<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/log_helper.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../pedido_compra_helper.php";

$redirectTo = trim((string) ($_POST['redirect_to'] ?? '../../entradas/historico_compras.php'));
if (!preg_match('#^\.\./\.\./entradas/historico_compras\.php(?:\?[^\r\n#]*)?$#', $redirectTo)) {
    $redirectTo = '../../entradas/historico_compras.php';
}
function redirecionarEdicaoValorCompra(string $redirectTo, array $params = []): void
{
    $separador = str_contains($redirectTo, '?') ? '&' : '?';
    header('Location: ' . $redirectTo . ($params ? $separador . http_build_query($params) : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionarEdicaoValorCompra('../../entradas/historico_compras.php');
}

validarTokenCsrf();

if (!in_array($_SESSION['usuario_nivel'] ?? '', ['admin', 'ti'], true)) {
    redirecionarEdicaoValorCompra($redirectTo, ['msg' => 'erro', 'detalhe' => 'Sem permissao para editar valor.']);
}

$numeroOs = trim((string) ($_POST['numero_os'] ?? ''));
$outrasDespesas = pedidoCompraNormalizarMoedaBrasileira($_POST['outras_despesas'] ?? '');

if ($numeroOs === '' || $outrasDespesas === null) {
    redirecionarEdicaoValorCompra($redirectTo, ['msg' => 'erro', 'detalhe' => 'Dados invalidos.']);
}

$responsavel = $_SESSION['usuario_nome'] ?? $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '';
$conexao->begin_transaction();
try {
    $snapshot = pedidoCompraObterSnapshot($conexao, $numeroOs, (string) $responsavel);
    $valorAnterior = (float) ($snapshot['valor_total'] ?? 0);
    $valorItens = (float) ($snapshot['valor_itens'] ?? $snapshot['valor_total_original'] ?? $valorAnterior);
    $snapshot['valor_itens'] = round($valorItens, 2);
    $snapshot = pedidoCompraAplicarOutrasDespesas($snapshot, $outrasDespesas);
    $snapshot['valor_total_original'] = $snapshot['valor_itens'];
    $snapshot['valor_total_editado'] = false;
    $snapshot['outras_despesas_editado_por'] = (string) $responsavel;
    $snapshot['outras_despesas_editado_em'] = date('Y-m-d H:i:s');
    pedidoCompraSalvarSnapshot($conexao, $snapshot);
    $conexao->commit();
} catch (Throwable $e) {
    $conexao->rollback();
    redirecionarEdicaoValorCompra($redirectTo, ['msg' => 'erro', 'detalhe' => 'Nao foi possivel salvar outras despesas.']);
}

registrarLog($conexao, 'outras_despesas_compra_editadas', 'pedido_compra_documentos', 0, "OS {$numeroOs}: outras despesas R$ {$outrasDespesas}; total de R$ {$valorAnterior} para R$ {$snapshot['valor_total']}");

redirecionarEdicaoValorCompra($redirectTo, ['msg' => 'valor_atualizado']);

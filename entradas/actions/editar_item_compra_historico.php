<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/log_helper.php';
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/cadastro_fiscal_helper.php';
require __DIR__ . '/../actions/compra_historico_helper.php';
require __DIR__ . '/../../previsoes/pedido_compra_helper.php';

$redirectTo = trim((string) ($_POST['redirect_to'] ?? '../historico_compras.php'));
if (!preg_match('#^\.\./historico_compras\.php(?:\?[^\r\n#]*)?$#', $redirectTo)) $redirectTo = '../historico_compras.php';
function voltarCompraEditada(string $destino, array $params): void { header('Location: ' . $destino . (str_contains($destino, '?') ? '&' : '?') . http_build_query($params)); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') voltarCompraEditada('../historico_compras.php', []);
validarTokenCsrf();
if (!in_array($_SESSION['usuario_nivel'] ?? '', ['admin', 'ti'], true)) voltarCompraEditada($redirectTo, ['msg'=>'erro','detalhe'=>'Sem permissao.']);
$id = (int) ($_POST['id'] ?? 0);
$produtoId = (int) ($_POST['produto_id'] ?? 0);
$quantidade = (float) str_replace(',', '.', (string) ($_POST['quantidade'] ?? 0));
if ($id <= 0 || $produtoId <= 0 || $quantidade <= 0) voltarCompraEditada($redirectTo, ['msg'=>'erro','detalhe'=>'Dados invalidos.']);

try { cadastroFiscalExigirProdutoSelecionado($conexao, $produtoId); }
catch (Throwable $e) { voltarCompraEditada($redirectTo, ['msg'=>'erro','detalhe'=>$e->getMessage()]); }

$conexao->begin_transaction();
try {
    $stmt = $conexao->prepare("SELECT * FROM entradas WHERE id=? AND tipo='entrada_fornecedor' FOR UPDATE");
    $stmt->bind_param('i', $id); $stmt->execute(); $entrada = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$entrada) throw new RuntimeException('Item da compra nao encontrado.');
    $mov = compraHistoricoBuscarMovimentacaoRelacionada($conexao, $entrada);
    $stmt = $conexao->prepare("SELECT * FROM previsao_fornecedor WHERE numero_os=? AND produto_id=? AND fornecedor_id <=> ? AND status='concluido' ORDER BY ABS(COALESCE(NULLIF(quantidade_recebida,0), quantidade_prevista)-?) ASC, id DESC LIMIT 1 FOR UPDATE");
    $stmt->bind_param('siid', $entrada['numero_os'], $entrada['produto_id'], $entrada['fornecedor_id'], $entrada['quantidade']);
    $stmt->execute(); $previsao = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$previsao) throw new RuntimeException('Previsao concluida relacionada a compra nao encontrada.');
    if (!$mov && empty($previsao['revenda_sao_paulo'])) throw new RuntimeException('Movimentacao de estoque da compra nao encontrada. Nenhuma alteracao foi salva.');

    $stmt = $conexao->prepare('UPDATE entradas SET produto_id=?, quantidade=? WHERE id=?');
    $stmt->bind_param('idi', $produtoId, $quantidade, $id); $stmt->execute(); $stmt->close();
    $stmt = $conexao->prepare('UPDATE previsao_fornecedor SET produto_id=?, quantidade_recebida=? WHERE id=?');
    $stmt->bind_param('idi', $produtoId, $quantidade, $previsao['id']); $stmt->execute(); $stmt->close();
    if ($mov) {
        $stmt = $conexao->prepare('UPDATE movimentacoes SET produto_id=?, quantidade=? WHERE id=?');
        $stmt->bind_param('idi', $produtoId, $quantidade, $mov['id']); $stmt->execute(); $stmt->close();
    }
    pedidoCompraAtualizarSnapshotSePossivel($conexao, (string) $entrada['numero_os'], (string) ($_SESSION['usuario_nome'] ?? ''));
    $conexao->commit();
    registrarLog($conexao, 'item_compra_editado_historico', 'entradas', $id, "Item da OS '{$entrada['numero_os']}' editado: produto_id $produtoId, quantidade_recebida $quantidade");
    voltarCompraEditada($redirectTo, ['msg'=>'item_atualizado']);
} catch (Throwable $e) {
    $conexao->rollback();
    voltarCompraEditada($redirectTo, ['msg'=>'erro','detalhe'=>$e->getMessage()]);
}

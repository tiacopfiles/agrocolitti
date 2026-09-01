<?php
/**
 * api_preco.php
 * Endpoint AJAX — retorna preços sugeridos em JSON para a tela de Vendas.
 *
 * GET ?tipo=atacado&produto_id=X
 *   → { prazo_5_dias, prazo_30_dias, kg_caixa, disponivel }
 *
 * GET ?tipo=embalado&produto_id=X&cliente_id=Y
 *   → { preco_calculado, gramagem_kg, percentual, tipo_aplicacao, preco_com_percentual, disponivel }
 */
session_start();
require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/ciclo_helper.php';
require_once __DIR__ . '/../config/calculos_preco.php';
require_once __DIR__ . '/../vendas/venda_helper.php';

header('Content-Type: application/json; charset=utf-8');

// Autenticação mínima
if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}

$tipo       = trim($_GET['tipo'] ?? '');
$produto_id = (int) ($_GET['produto_id'] ?? 0);
$cliente_id = (int) ($_GET['cliente_id'] ?? 0);
$prazo      = trim($_GET['prazo'] ?? '');

if ($produto_id <= 0) {
    echo json_encode(['erro' => 'produto_id inválido']);
    exit;
}

// ── ATACADO ───────────────────────────────────────────────────────────────────
if ($tipo === 'atacado') {
    $prazo_resolucao = in_array($prazo, ['5_dias', '30_dias'], true) ?$prazo : '5_dias';

    try {
        $preco = resolverPrecoVendaAtacado($conexao, $produto_id, $prazo_resolucao, $cliente_id);

        echo json_encode([
            'prazo_5_dias'   => $preco['preco_5_dias'],
            'prazo_30_dias'  => $preco['preco_30_dias'],
            'custo_5_dias'   => $preco['custo_5_dias'],
            'custo_30_dias'  => $preco['custo_30_dias'],
            'percentual'     => $preco['percentual'],
            'tipo_aplicacao' => $preco['tipo_aplicacao'],
            'preco_venda'    => $preco['preco_venda'],
            'prazo_escolhido'=> $preco['prazo_escolhido'],
            'kg_caixa'       => $preco['kg_caixa'],
            'disponivel'     => $preco['disponivel'],
        ]);
        exit;
    } catch (Exception $e) {
        $mensagem = $e->getMessage();
        $indisponivel = stripos($mensagem, 'indisponível') !== false;
        echo json_encode([
            'erro'         => $mensagem,
            'indisponivel' => $indisponivel,
        ]);
        exit;
    }
}

if ($tipo === 'shopper') {
    $prazo_resolucao = in_array($prazo, ['5_dias', '30_dias'], true) ? $prazo : '5_dias';
    try {
        $preco = resolverPrecoVendaShopper($conexao, $produto_id, $prazo_resolucao);
        echo json_encode([
            'custo_5_dias' => $preco['custo_5_dias'],
            'custo_30_dias' => $preco['custo_30_dias'],
            'prazo_5_dias' => $preco['preco_5_dias'],
            'prazo_30_dias' => $preco['preco_30_dias'],
            'preco_venda' => $preco['preco_venda'],
            'prazo_escolhido' => $preco['prazo_escolhido'],
            'kg_caixa' => $preco['kg_caixa'],
            'disponivel' => $preco['disponivel'],
        ]);
        exit;
    } catch (Exception $e) {
        $mensagem = $e->getMessage();
        echo json_encode(['erro' => $mensagem, 'indisponivel' => stripos($mensagem, 'indispon') !== false]);
        exit;
    }
}

// ── EMBALADO ──────────────────────────────────────────────────────────────────
if ($tipo === 'atacado_convencional') {
    $prazo_resolucao = in_array($prazo, ['5_dias', '30_dias'], true) ?$prazo : '5_dias';

    if ($cliente_id <= 0) {
        echo json_encode(['erro' => 'cliente_id inválido']);
        exit;
    }

    try {
        $preco = resolverPrecoVendaAtacadoConvencional($conexao, $produto_id, $cliente_id, $prazo_resolucao);

        echo json_encode([
            'custo_5_dias'    => $preco['custo_5_dias'],
            'custo_30_dias'   => $preco['custo_30_dias'],
            'prazo_5_dias'    => $preco['preco_5_dias'],
            'prazo_30_dias'   => $preco['preco_30_dias'],
            'preco_venda'     => $preco['preco_venda'],
            'prazo_escolhido' => $preco['prazo_escolhido'],
            'kg_caixa'        => $preco['kg_caixa'],
            'percentual'      => $preco['percentual'],
            'tipo_aplicacao'  => $preco['tipo_aplicacao'],
            'disponivel'      => $preco['disponivel'],
        ]);
        exit;
    } catch (Exception $e) {
        $mensagem = $e->getMessage();
        $indisponivel = stripos($mensagem, 'indisponivel') !== false || stripos($mensagem, 'indispon') !== false;
        echo json_encode([
            'erro'         => $mensagem,
            'indisponivel' => $indisponivel,
        ]);
        exit;
    }
}
if ($tipo === 'embalado') {

    if ($cliente_id <= 0) {
        echo json_encode(['erro' => 'cliente_id inválido']);
        exit;
    }

    try {
        $preco = resolverPrecoVendaEmbalado($conexao, $produto_id, $cliente_id);

        echo json_encode([
            'preco_calculado'      => $preco['preco_base'],
            'gramagem_kg'          => $preco['gramagem_kg'],
            'percentual'           => $preco['percentual'],
            'tipo_aplicacao'       => $preco['tipo_aplicacao'],
            'preco_com_percentual' => $preco['preco_ajustado'],
            'preco_venda'          => $preco['preco_venda'],
            'ignora_frete'         => $preco['ignora_frete'],
            'disponivel'           => $preco['disponivel'],
        ]);
        exit;
    } catch (Exception $e) {
        $mensagem = $e->getMessage();
        $indisponivel = stripos($mensagem, 'indisponível') !== false;
        echo json_encode([
            'erro'         => $mensagem,
            'indisponivel' => $indisponivel,
        ]);
        exit;
    }
}

if ($tipo === 'oba_embalado') {
    try {
        $preco = resolverPrecoVendaObaEmbalado($conexao, $produto_id);

        // Opcoes de bandejas por caixa: usa a coluna da tabela OBA; se vazia, deriva do nome.
        garantirColunasObaCaixa($conexao);
        $stmtBpc = $conexao->prepare("
            SELECT po.bandejas_por_caixa AS opcoes, pr.nome AS nome
            FROM preco_oba_embalado po
            LEFT JOIN produtos pr ON pr.id = po.produto_id
            WHERE po.produto_id = ? LIMIT 1
        ");
        $stmtBpc->bind_param('i', $produto_id);
        $stmtBpc->execute();
        $rowBpc = $stmtBpc->get_result()->fetch_assoc();
        $stmtBpc->close();
        $opcoesBandejas = trim((string) ($rowBpc['opcoes'] ?? ''));
        if ($opcoesBandejas === '') {
            $opcoesBandejas = obaBandejasPorCaixaPadrao((string) ($rowBpc['nome'] ?? ''));
        }

        echo json_encode([
            'preco_calculado' => $preco['preco_base'],
            'gramagem_kg' => $preco['gramagem_kg'],
            'percentual' => $preco['percentual'],
            'tipo_aplicacao' => $preco['tipo_aplicacao'],
            'preco_com_percentual' => $preco['preco_ajustado'],
            'preco_venda' => $preco['preco_venda'],
            'ignora_frete' => $preco['ignora_frete'],
            'disponivel' => $preco['disponivel'],
            'bandejas_por_caixa' => $opcoesBandejas,
            'opcoes_bandejas' => obaOpcoesBandejas($opcoesBandejas),
        ]);
        exit;
    } catch (Exception $e) {
        $mensagem = $e->getMessage();
        echo json_encode(['erro' => $mensagem, 'indisponivel' => stripos($mensagem, 'indispon') !== false]);
        exit;
    }
}

echo json_encode(['erro' => 'Tipo inválido']);

<?php
require "../../config/conexao.php";
require "../../auth/proteger.php";
require "../../config/permissions.php";
require "../lib/contas_receber_integration.php";

requireModule('contas', '../../index.php');

try {
    contasReceberAssertAgroAllowed($conexao);
    $destino = contasReceberConnectDestino();

    $items = contasReceberRequestItems();
    $ids = [];
    foreach ($items as $item) $ids[] = (int) ($item['id'] ?? $item['origem_id'] ?? 0);
    $ids = array_values(array_unique(array_filter($ids)));

    if (!$ids) {
        $res = $conexao->query("
            SELECT d.id
            FROM nfe_documentos d
            LEFT JOIN contas_integracoes ci
                   ON ci.tipo = 'receber'
                  AND ci.origem_tabela = 'nfe_documentos'
                  AND ci.origem_id = d.id
            WHERE d.tipo_emissao = 'venda'
              AND d.status = 'autorizada'
              AND (ci.id IS NULL OR ci.status IN ('pendente','preparado','erro'))
            ORDER BY COALESCE(d.emitida_em, d.autorizada_em, d.created_at) DESC, d.id DESC
            LIMIT 200
        ");
        while ($row = $res->fetch_assoc()) $ids[] = (int) $row['id'];
    }

    $docs = contasReceberLoadNfeDocs($conexao, $ids);
    $resultados = [];
    $resumo = [
        'selecionadas' => count($ids),
        'encontradas' => count($docs),
        'aptas' => 0,
        'bloqueadas' => 0,
        'clientes_existentes' => 0,
        'clientes_a_criar' => 0,
        'duplicidade_controle' => 0,
        'duplicidade_legado' => 0,
        'sem_vencimento' => 0,
        'valor_total_zero' => 0,
        'valor_total_negativo' => 0,
    ];

    foreach ($ids as $id) {
        if (!isset($docs[$id])) {
            $resumo['bloqueadas']++;
            $resultados[] = ['origem_id' => $id, 'status' => 'bloqueado', 'erros' => ['NF-e autorizada nao encontrada.']];
            continue;
        }

        $input = contasReceberFindInputItem($items, $id);
        $item = contasReceberBuildItem($conexao, $docs[$id], $input, $destino);
        $resultados[] = $item;

        if ($item['status'] === 'apto') $resumo['aptas']++; else $resumo['bloqueadas']++;
        if (!empty($docs[$id]['integracao_id'])) $resumo['duplicidade_controle']++;
        if (!empty($item['snapshot']['legacy_duplicate'])) $resumo['duplicidade_legado']++;
        if (!empty($item['snapshot']['cliente']['cliente_destino'])) $resumo['clientes_existentes']++;
        if (in_array('Cliente nao encontrado no contas a receber; sera cadastrado antes do lancamento.', $item['avisos'] ?? [], true)) $resumo['clientes_a_criar']++;
        if (in_array('Vencimento obrigatorio ou invalido.', $item['erros'] ?? [], true)) $resumo['sem_vencimento']++;
        $total = (float) ($item['snapshot']['calculo']['valor_total'] ?? 0);
        if ($total === 0.0) $resumo['valor_total_zero']++;
        if ($total < 0) $resumo['valor_total_negativo']++;
    }

    // A tela pode permanecer aberta durante uma renovacao de login/sessao.
    // Devolve o token vigente para que o POST financeiro seguinte nao use
    // o token antigo que ficou renderizado no HTML.
    contasReceberJsonResponse([
        'ok' => true,
        'csrf_token' => gerarTokenCsrf(),
        'resumo' => $resumo,
        'itens' => $resultados,
    ]);
} catch (Throwable $e) {
    contasReceberJsonResponse(['ok' => false, 'erro_critico' => $e->getMessage()], 500);
}

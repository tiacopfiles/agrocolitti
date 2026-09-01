<?php
require "../../config/conexao.php";
require "../../auth/proteger.php";
require "../../config/permissions.php";
require "../lib/contas_pagar_integration.php";

requireModule('contas', '../../index.php');

try {
    contasAssertAgroAllowed($conexao);
    $destino = contasConnectDestino();

    $items = contasRequestItems();
    $ids = [];
    foreach ($items as $item) $ids[] = (int) ($item['id'] ?? $item['origem_id'] ?? 0);
    $ids = array_values(array_unique(array_filter($ids)));

    if (!$ids) {
        $res = $conexao->query("
            SELECT d.id
            FROM nfe_documentos d
            LEFT JOIN contas_integracoes ci
                   ON ci.tipo = 'pagar'
                  AND ci.origem_tabela = 'nfe_documentos'
                  AND ci.origem_id = d.id
            WHERE d.tipo_emissao IN ('compra','saida_abate_sem_entrada')
              AND d.status = 'autorizada'
              AND (ci.id IS NULL OR ci.status IN ('pendente','preparado','erro'))
            ORDER BY COALESCE(d.emitida_em, d.created_at) DESC, d.id DESC
            LIMIT 200
        ");
        while ($row = $res->fetch_assoc()) $ids[] = (int) $row['id'];
    }

    $docs = contasLoadNfeDocs($conexao, $ids);
    $resultados = [];
    $resumo = [
        'selecionadas' => count($ids),
        'encontradas' => count($docs),
        'aptas' => 0,
        'bloqueadas' => 0,
        'com_devolucao_manual' => 0,
        'duplicidade_controle' => 0,
        'duplicidade_legado' => 0,
        'sem_emissao' => 0,
        'sem_vencimento' => 0,
        'valor_liquido_zero' => 0,
        'valor_liquido_negativo' => 0,
    ];

    foreach ($ids as $id) {
        if (!isset($docs[$id])) {
            $resumo['bloqueadas']++;
            $resultados[] = ['origem_id' => $id, 'status' => 'bloqueado', 'erros' => ['NF-e nao encontrada.']];
            continue;
        }
        $input = contasFindInputItem($items, $id);
        $item = contasBuildItem($conexao, $docs[$id], $input, $destino);
        $docFornecedor = (string) ($item['snapshot']['fornecedor']['cnpj_cpf'] ?? $item['cnpj'] ?? '');
        $docFornecedorDigits = contasNormalizeDoc($docFornecedor);
        if (strlen($docFornecedorDigits) === 14) {
            $fornecedorDestino = contasFindFornecedorDestino($destino, $docFornecedor, $item['fornecedor']);
            if ($fornecedorDestino) {
                $nomeContas = trim((string) ($fornecedorDestino['nomefantasia'] ?? ''));
                if ($nomeContas === '') $nomeContas = trim((string) ($fornecedorDestino['razaosocial'] ?? ''));
                $item = contasApplyFornecedorDestino($item, [
                    'aplicado' => true,
                    'acao' => 'existente',
                    'fornecedor' => $fornecedorDestino,
                    'nome_contas' => $nomeContas !== '' ? $nomeContas : $item['fornecedor'],
                ]);
            } else {
                $item['avisos'][] = 'Fornecedor sera cadastrado no contas no momento do envio.';
                $item['snapshot']['fornecedor']['cadastro_contas'] = [
                    'aplicado' => false,
                    'acao' => 'sera_criado_no_envio',
                    'cnpj' => contasFormatCnpj($docFornecedorDigits),
                    'nomefantasia' => $item['fornecedor'],
                    'razaosocial' => $item['fornecedor'],
                ];
            }
        }
        $resultados[] = $item;

        if ($item['status'] === 'apto') $resumo['aptas']++; else $resumo['bloqueadas']++;
        if ((float) ($item['snapshot']['calculo']['devolucao_final'] ?? 0) > 0) $resumo['com_devolucao_manual']++;
        if (!empty($docs[$id]['integracao_id'])) $resumo['duplicidade_controle']++;
        if (!empty($item['snapshot']['legacy_duplicate'])) $resumo['duplicidade_legado']++;
        if (in_array('Data de emissao do fornecedor obrigatoria ou invalida.', $item['erros'] ?? [], true)) $resumo['sem_emissao']++;
        if (in_array('Vencimento obrigatorio ou invalido.', $item['erros'] ?? [], true)) $resumo['sem_vencimento']++;
        $liquido = (float) ($item['snapshot']['calculo']['valor_liquido'] ?? 0);
        if ($liquido === 0.0) $resumo['valor_liquido_zero']++;
        if ($liquido < 0) $resumo['valor_liquido_negativo']++;
    }

    contasJsonResponse(['ok' => true, 'resumo' => $resumo, 'itens' => $resultados]);
} catch (Throwable $e) {
    contasJsonResponse(['ok' => false, 'erro_critico' => $e->getMessage()], 500);
}

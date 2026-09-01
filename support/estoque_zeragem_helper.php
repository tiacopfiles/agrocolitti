<?php

/**
 * Remove do operacional os registros ja copiados para o arquivo do ciclo.
 *
 * @return array<string,int>
 */
function estoqueZeragemRemoverArquivados(mysqli $conexao, array $registros): array
{
    $permitidas = [
        'movimentacoes', 'abates', 'entradas',
        'previsao_fornecedor', 'previsao_colheita', 'vendas',
    ];
    $idsPorTabela = [];

    foreach ($registros as $registro) {
        $tabela = (string) ($registro['origem_tabela'] ?? '');
        $id = (int) ($registro['origem_id'] ?? 0);
        if ($id > 0 && in_array($tabela, $permitidas, true)) {
            $idsPorTabela[$tabela][$id] = $id;
        }
    }

    // Dependencias operacionais primeiro. Documentos fiscais e financeiros
    // permanecem em suas tabelas e continuam consultaveis por OS.
    $ordem = [
        'movimentacoes', 'abates', 'entradas',
        'previsao_fornecedor', 'previsao_colheita', 'vendas',
    ];
    $removidos = [];

    foreach ($ordem as $tabela) {
        $ids = array_values($idsPorTabela[$tabela] ?? []);
        $removidos[$tabela] = 0;
        foreach (array_chunk($ids, 500) as $lote) {
            $lista = implode(',', array_map('intval', $lote));
            if ($lista === '') {
                continue;
            }
            $conexao->query("DELETE FROM {$tabela} WHERE id IN ({$lista})");
            $removidos[$tabela] += $conexao->affected_rows;
        }
    }

    return $removidos;
}

/**
 * Arquiva a movimentacao concluida e reinicia a area operacional do ciclo.
 *
 * Registros concluidos com cadeia fiscal/financeira encerrada sao copiados
 * para ciclo_snapshot_registros e removidos das tabelas operacionais. As
 * pendencias permanecem intactas. Por fim, a base e o saldo remanescente do
 * estoque sao zerados de forma auditavel.
 *
 * Esta funcao deve ser chamada dentro de uma transacao.
 *
 * @return array{estoques_iniciais_zerados:int,ajustes_criados:int,arquivados:int,preservados:int,removidos:array<string,int>}
 */
function zerarContagemEstoqueDoCiclo(mysqli $conexao, int $cicloId): array
{
    $stmtCiclo = $conexao->prepare("
        SELECT id FROM ciclos
        WHERE id = ? AND ativo = 1 AND status = 'aberto'
        FOR UPDATE
    ");
    $stmtCiclo->bind_param('i', $cicloId);
    $stmtCiclo->execute();
    $cicloExiste = (bool) $stmtCiclo->get_result()->fetch_assoc();
    $stmtCiclo->close();
    if (!$cicloExiste) {
        throw new RuntimeException('O ciclo ativo mudou. Atualize a pagina e tente novamente.');
    }

    cicloFechamentoBloquearRegistros($conexao, $cicloId);
    $mapa = cicloFechamentoColetarRegistros($conexao, $cicloId, false);

    // Snapshot antes da remocao. Como tudo ocorre na mesma transacao, qualquer
    // falha restaura tanto o arquivo quanto os registros de origem.
    cicloFechamentoSalvarRegistros($conexao, $cicloId, $mapa['arquivar']);
    $removidos = estoqueZeragemRemoverArquivados($conexao, $mapa['arquivar']);

    $stmtInicial = $conexao->prepare("
        UPDATE estoque_inicial SET quantidade = 0
        WHERE ciclo_id = ? AND quantidade <> 0
    ");
    $stmtInicial->bind_param('i', $cicloId);
    $stmtInicial->execute();
    $estoquesIniciaisZerados = $stmtInicial->affected_rows;
    $stmtInicial->close();

    // O saldo deriva apenas da base, das entradas e das vendas concluidas.
    // Como a base foi zerada e os concluidos foram removidos, nenhum ajuste
    // artificial em movimentacoes e necessario.
    $ajustes = 0;

    if (tabelaExiste($conexao, 'estoque')) {
        $conexao->query('UPDATE estoque SET quantidade = 0 WHERE quantidade <> 0');
    }

    return [
        'estoques_iniciais_zerados' => $estoquesIniciaisZerados,
        'ajustes_criados' => $ajustes,
        'arquivados' => count($mapa['arquivar']),
        'preservados' => count($mapa['preservar']),
        'removidos' => $removidos,
    ];
}

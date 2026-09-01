<?php

require_once __DIR__ . '/../../config/produto_vinculo_helper.php';
require_once __DIR__ . '/../venda_helper.php';

function vendaHistoricoTabelaExiste(mysqli $conexao, string $tabela): bool
{
    $tabela = $conexao->real_escape_string($tabela);
    $res = $conexao->query("SHOW TABLES LIKE '{$tabela}'");
    $existe = $res && $res->num_rows > 0;
    if ($res instanceof mysqli_result) {
        $res->free();
    }
    return $existe;
}

function vendaHistoricoColunaExiste(mysqli $conexao, string $tabela, string $coluna): bool
{
    $tabela = $conexao->real_escape_string($tabela);
    $coluna = $conexao->real_escape_string($coluna);
    $res = $conexao->query("SHOW COLUMNS FROM `{$tabela}` LIKE '{$coluna}'");
    $existe = $res && $res->num_rows > 0;
    if ($res instanceof mysqli_result) {
        $res->free();
    }
    return $existe;
}

function vendaHistoricoGarantirTabelaReversoes(mysqli $conexao): void
{
    $conexao->query("
        CREATE TABLE IF NOT EXISTS financeiro_reversoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            origem VARCHAR(40) NOT NULL,
            numero_os VARCHAR(50) NOT NULL,
            contraparte VARCHAR(180) NULL,
            valor DECIMAL(12,2) NOT NULL,
            motivo TEXT NULL,
            usuario VARCHAR(120) NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_fin_rev_origem_os (origem, numero_os),
            KEY idx_fin_rev_criado_em (criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function vendaHistoricoBuscarVenda(mysqli $conexao, int $id, bool $lock = false): array
{
    $lockSql = $lock ? ' FOR UPDATE' : '';
    $stmt = $conexao->prepare("
        SELECT v.*, c.nome AS cliente_nome
        FROM vendas v
        LEFT JOIN clientes c ON c.id = v.cliente_id
        WHERE v.id = ?
          AND v.status = 'concluido'
        LIMIT 1
        {$lockSql}
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $venda = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    if (!$venda) {
        throw new RuntimeException('Venda concluida nao encontrada.');
    }

    return $venda;
}

function vendaHistoricoValorFinal(array $venda): float
{
    if (array_key_exists('valor_final_editado', $venda) && $venda['valor_final_editado'] !== null && $venda['valor_final_editado'] !== '') {
        return (float) $venda['valor_final_editado'];
    }

    $pedido = (float) ($venda['pedido'] ?? 0);
    $preco = (float) ($venda['preco'] ?? 0);
    $frete = max(0.0, (float) ($venda['frete'] ?? 0));

    return ($pedido * $preco) + $frete;
}

function vendaHistoricoBuscarMovimentacaoRelacionada(mysqli $conexao, array $venda): ?array
{
    if (vendaHistoricoColunaExiste($conexao, 'movimentacoes', 'referencia_id')) {
        $stmtRef = $conexao->prepare("SELECT id FROM movimentacoes WHERE referencia_id=? AND tipo='venda' ORDER BY id DESC LIMIT 1");
        $stmtRef->bind_param('i', $venda['id']);
        $stmtRef->execute();
        $porReferencia = $stmtRef->get_result()->fetch_assoc() ?: null;
        $stmtRef->close();
        if ($porReferencia) return $porReferencia;
    }
    $temCiclo = vendaHistoricoColunaExiste($conexao, 'movimentacoes', 'ciclo_id')
        && array_key_exists('ciclo_id', $venda);

    $produtoEstoqueId = produtoEstoqueId($conexao, (int) $venda['produto_id']);

    if ($temCiclo) {
        $stmt = $conexao->prepare("
            SELECT id
            FROM movimentacoes
            WHERE ciclo_id <=> ?
              AND produto_id = ?
              AND tipo = 'venda'
              AND quantidade = ?
            ORDER BY ABS(TIMESTAMPDIFF(SECOND, data_movimentacao, ?)) ASC, id DESC
            LIMIT 1
        ");
        $dataRef = $venda['data_venda'] ?? date('Y-m-d');
        $stmt->bind_param('iids', $venda['ciclo_id'], $produtoEstoqueId, $venda['quantidade'], $dataRef);
    } else {
        $stmt = $conexao->prepare("
            SELECT id
            FROM movimentacoes
            WHERE produto_id = ?
              AND tipo = 'venda'
              AND quantidade = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param('id', $produtoEstoqueId, $venda['quantidade']);
    }

    $stmt->execute();
    $movimentacao = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $movimentacao;
}

function vendaHistoricoRemoverOperacional(mysqli $conexao, array $venda): void
{
    $movimentacao = vendaHistoricoBuscarMovimentacaoRelacionada($conexao, $venda);
    if ($movimentacao) {
        $stmtMov = $conexao->prepare("DELETE FROM movimentacoes WHERE id = ?");
        $stmtMov->bind_param('i', $movimentacao['id']);
        $stmtMov->execute();
        $stmtMov->close();
    }

    $stmtVenda = $conexao->prepare("DELETE FROM vendas WHERE id = ?");
    $stmtVenda->bind_param('i', $venda['id']);
    $stmtVenda->execute();
    $stmtVenda->close();
}

function vendaHistoricoBuscarVendasOs(mysqli $conexao, string $numeroOs, bool $lock = false): array
{
    $lockSql = $lock ? ' FOR UPDATE' : '';
    $stmt = $conexao->prepare("
        SELECT v.*, c.nome AS cliente_nome
        FROM vendas v
        LEFT JOIN clientes c ON c.id = v.cliente_id
        WHERE v.numero_os = ?
          AND v.status = 'concluido'
        ORDER BY v.id ASC
        {$lockSql}
    ");
    $stmt->bind_param('s', $numeroOs);
    $stmt->execute();
    $vendas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$vendas) {
        throw new RuntimeException('OS concluida nao encontrada no historico.');
    }

    return $vendas;
}

function vendaHistoricoRemoverOperacionalOs(mysqli $conexao, string $numeroOs): int
{
    $vendas = vendaHistoricoBuscarVendasOs($conexao, $numeroOs, true);
    foreach ($vendas as $venda) {
        vendaHistoricoRemoverOperacional($conexao, $venda);
    }

    return count($vendas);
}

function vendaHistoricoAtualizarItem(mysqli $conexao, int $id, int $produtoId, string $tipo, float $pedido, float $preco, float $gramagem, float $kgCaixa, ?string $numeroOs = null, ?string $tipoComercial = '__preservar__', ?int $numCaixasItem = null, ?int $bandejasPorCaixaItem = null): array
{
    $tipo = normalizarTipoVenda($tipo);
    if ($id <= 0 || $produtoId <= 0 || $preco < 0) throw new RuntimeException('Dados do item invalidos.');

    $venda = vendaHistoricoBuscarVenda($conexao, $id, true);
    if ($tipoComercial === '__preservar__') {
        $tipoComercial = trim((string) ($venda['tipo_comercial'] ?? ''));
    }
    $tipoComercial = in_array($tipoComercial, ['embalado', 'oba_embalado', 'atacado', 'atacado_convencional', 'shopper'], true)
        ? $tipoComercial
        : null;
    // Protege tambem chamadas internas: unidade e tabela de embalados sao
    // conceitos incompatíveis. A escolha explicita por unidade vira manual.
    if ($tipo === 'unidade' && in_array($tipoComercial, ['embalado', 'oba_embalado'], true)) {
        $tipoComercial = null;
    }
    $numCaixasSalvar = 0;
    $bandejasPorCaixaSalvar = 0;
    if ($tipoComercial === 'oba_embalado') {
        $tipo = 'bandeja';
        $numCaixasSalvar = max(0, (int) ($numCaixasItem ?? ($venda['num_caixas'] ?? 0)));
        $bandejasPorCaixaSalvar = max(0, (int) ($bandejasPorCaixaItem ?? ($venda['bandejas_por_caixa'] ?? 0)));
        if ($numCaixasSalvar <= 0) throw new RuntimeException('Informe o numero de caixas deste produto para a Tabela OBA.');
        if ($bandejasPorCaixaSalvar <= 0) throw new RuntimeException('Selecione a quantidade de bandejas por caixa para a Tabela OBA.');
        $pedido = (float) ($numCaixasSalvar * $bandejasPorCaixaSalvar);
    } elseif ($tipoComercial === 'embalado') {
        $tipo = 'bandeja';
    } elseif (in_array($tipoComercial, ['atacado', 'atacado_convencional', 'shopper'], true)) {
        $tipo = in_array($tipo, ['caixa', 'unidade'], true) ? $tipo : 'kg';
    }
    if ($pedido <= 0) throw new RuntimeException('Quantidade invalida.');
    if ($tipo === 'bandeja' && $gramagem <= 0) throw new RuntimeException('Informe a gramagem da bandeja.');
    if ($tipo === 'caixa' && $kgCaixa <= 0) throw new RuntimeException('Informe o peso em kg da caixa.');
    if ($tipo !== 'bandeja') $gramagem = 0.0;
    if ($tipo !== 'caixa') $kgCaixa = 0.0;
    if ($numeroOs !== null && (string) $venda['numero_os'] !== $numeroOs) throw new RuntimeException('Item nao pertence a OS informada.');
    $movimentacao = vendaHistoricoBuscarMovimentacaoRelacionada($conexao, $venda);
    if (!$movimentacao) throw new RuntimeException('Movimentacao de estoque da venda nao encontrada. Nenhuma alteracao foi salva.');

    $quantidade = calcularQuantidadeVendaEmKg($pedido, $tipo, $tipo === 'bandeja' ? $gramagem : $kgCaixa);
    $precoTotal = ($tipo === 'kg' ? $quantidade : $pedido) * $preco;
    $stmt = $conexao->prepare("UPDATE vendas SET produto_id=?, tipo=?, tipo_comercial=?, gramagem=?, kg_caixa=?, pedido=?, quantidade=?, preco=?, preco_total=?, num_caixas=?, bandejas_por_caixa=?, valor_final_editado=NULL, valor_final_editado_por=NULL, valor_final_editado_em=NULL WHERE id=? AND status='concluido'");
    $stmt->bind_param('issddddddiii', $produtoId, $tipo, $tipoComercial, $gramagem, $kgCaixa, $pedido, $quantidade, $preco, $precoTotal, $numCaixasSalvar, $bandejasPorCaixaSalvar, $id);
    $stmt->execute();
    $stmt->close();

    $produtoEstoqueId = produtoEstoqueId($conexao, $produtoId);
    $stmt = $conexao->prepare("UPDATE movimentacoes SET produto_id=?, quantidade=? WHERE id=?");
    $stmt->bind_param('idi', $produtoEstoqueId, $quantidade, $movimentacao['id']);
    $stmt->execute();
    $stmt->close();
    return compact('quantidade', 'tipo', 'tipoComercial', 'pedido', 'preco', 'gramagem', 'kgCaixa', 'numCaixasSalvar', 'bandejasPorCaixaSalvar');
}

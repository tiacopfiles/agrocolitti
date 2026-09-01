<?php

function proximoNumeroOsVenda(mysqli $conexao): string
{
    $conexao->query("CREATE TABLE IF NOT EXISTS os_numeracao (tipo VARCHAR(30) NOT NULL PRIMARY KEY, proximo_numero INT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $resultado = $conexao->query("SELECT COALESCE(MAX(CAST(numero_os AS UNSIGNED)), 0) AS ultima_os FROM vendas WHERE numero_os REGEXP '^[0-9]+$'");
    $ultimaOs = (int) (($resultado ? $resultado->fetch_assoc() : [])['ultima_os'] ?? 0);
    $proximoInicial = $ultimaOs + 1;
    $stmt = $conexao->prepare("INSERT INTO os_numeracao (tipo, proximo_numero) VALUES ('venda', ?) ON DUPLICATE KEY UPDATE proximo_numero = GREATEST(proximo_numero, VALUES(proximo_numero))");
    $stmt->bind_param('i', $proximoInicial);
    $stmt->execute();
    $stmt->close();
    $conexao->query("UPDATE os_numeracao SET proximo_numero = LAST_INSERT_ID(proximo_numero), proximo_numero = proximo_numero + 1 WHERE tipo = 'venda'");
    $resultado = $conexao->query("SELECT LAST_INSERT_ID() AS numero_os");
    return (string) ((int) (($resultado ? $resultado->fetch_assoc() : [])['numero_os'] ?? $proximoInicial));
}

/**
 * Monta a descricao de um produto embalado para a NF-e: mantem o texto base
 * (descricao fiscal ou nome), remove qualquer gramagem/peso ja existente no
 * final e anexa a gramagem (em gramas) da tabela embalado. Ex.: "BETERRABA ORGANICA 500g".
 */
function formatarGramagemEmbalado(string $descricaoBase, float $gramagem): string
{
    $base = trim($descricaoBase);
    // remove peso no final: "500g", "600GR", "200 G", "1,5 kg", "500 gramas" etc.
    $base = preg_replace('/\s*\d+(?:[.,]\d+)?\s*(?:kg|g|gr|grs|gramas?)\.?\s*$/iu', '', $base);
    $base = trim($base);
    if ($gramagem > 0) {
        return ($base !== '' ? $base . ' ' : '') . ((int) round($gramagem)) . 'g';
    }
    return $base;
}

function extrairGramagemTextoProduto(string $descricao): float
{
    if (!preg_match('/\b(\d+(?:[.,]\d+)?)\s*(kg|g)\b/iu', $descricao, $m)) {
        return 0.0;
    }

    $valor = (float) str_replace(',', '.', $m[1]);
    $unidade = mb_strtolower($m[2], 'UTF-8');
    return $unidade === 'kg' ? $valor * 1000 : $valor;
}

function descricaoNfeProdutoSelecionado(string $descricaoFiscal, string $nomeProduto, float $gramagemVenda): string
{
    $descricaoFiscal = trim($descricaoFiscal);
    $nomeProduto = trim($nomeProduto);
    $baseSelecionada = $descricaoFiscal !== '' ? $descricaoFiscal : $nomeProduto;

    if (extrairGramagemTextoProduto($baseSelecionada) > 0) {
        return $baseSelecionada;
    }

    if (extrairGramagemTextoProduto($nomeProduto) > 0) {
        return $nomeProduto;
    }

    return formatarGramagemEmbalado($baseSelecionada, $gramagemVenda);
}

function normalizarTipoVenda(?string $tipo, ?string $unidade = null, ?float $gramagem = null, ?float $kgCaixa = null): string
{
    $tipoNormalizado = strtolower(trim((string) $tipo));
    if (in_array($tipoNormalizado, ['kg', 'bandeja', 'caixa', 'unidade'], true)) {
        return $tipoNormalizado;
    }

    $unidadeNormalizada = strtolower(trim((string) $unidade));
    if (in_array($unidadeNormalizada, ['kg', 'bandeja', 'caixa', 'unidade'], true)) {
        return $unidadeNormalizada;
    }

    if ((float) $gramagem > 0) {
        return 'bandeja';
    }

    if ((float) $kgCaixa > 0) {
        return 'caixa';
    }

    return 'kg';
}

function calcularQuantidadeVendaEmKg(float $pedido, string $tipo, ?float $pesoUnitario = null): float
{
    if ($tipo === 'caixa') {
        return $pedido * (float) $pesoUnitario;
    }

    if ($tipo === 'bandeja') {
        return ($pedido * (float) $pesoUnitario) / 1000;
    }

    return $pedido;
}

function calcularQuantidadeFinalVenda(array $venda): float
{
    $pedido = isset($venda['pedido']) ? (float) $venda['pedido'] : 0.0;
    $tipo = normalizarTipoVenda(
        $venda['tipo'] ?? null,
        $venda['produto_unidade'] ?? null,
        isset($venda['gramagem']) ? (float) $venda['gramagem'] : null,
        isset($venda['kg_caixa']) ? (float) $venda['kg_caixa'] : null
    );

    if ($tipo === 'caixa') {
        return calcularQuantidadeVendaEmKg($pedido, $tipo, isset($venda['kg_caixa']) ? (float) $venda['kg_caixa'] : 0.0);
    }

    if ($tipo === 'bandeja') {
        return calcularQuantidadeVendaEmKg($pedido, $tipo, isset($venda['gramagem']) ? (float) $venda['gramagem'] : 0.0);
    }

    return calcularQuantidadeVendaEmKg($pedido, $tipo);
}

function calcularPrecoTotalVenda(array $venda): float
{
    $pedido = isset($venda['pedido']) ? (float) $venda['pedido'] : 0.0;
    $precoUnitario = isset($venda['preco']) ? (float) $venda['preco'] : 0.0;
    $tipo = normalizarTipoVenda(
        $venda['tipo'] ?? null,
        $venda['produto_unidade'] ?? null,
        isset($venda['gramagem']) ? (float) $venda['gramagem'] : null,
        isset($venda['kg_caixa']) ? (float) $venda['kg_caixa'] : null
    );

    $subtotal = $tipo === 'kg'
        ? $precoUnitario * calcularQuantidadeFinalVenda($venda)
        : $precoUnitario * $pedido;

    $frete = isset($venda['frete']) && $venda['frete'] !== '' ? (float) $venda['frete'] : 0.0;
    return $subtotal + max(0.0, $frete);
}

/**
 * Garante as colunas usadas pela venda OBA por caixas:
 *  - vendas.bandejas_por_caixa (valor escolhido no item, p/ descricao da NF-e)
 *  - preco_oba_embalado.bandejas_por_caixa (opcoes do dropdown, ex.: "15,6")
 * Na primeira criacao da coluna na tabela OBA, popula os tomates conhecidos por nome.
 */
function garantirColunasObaCaixa(mysqli $conexao): void
{
    static $feito = false;
    if ($feito) {
        return;
    }
    $feito = true;

    $rv = $conexao->query("SHOW COLUMNS FROM vendas LIKE 'bandejas_por_caixa'");
    if ($rv && $rv->num_rows === 0) {
        $conexao->query("ALTER TABLE vendas ADD COLUMN bandejas_por_caixa INT NULL DEFAULT NULL");
    }
    if ($rv instanceof mysqli_result) {
        $rv->free();
    }

    $rt = $conexao->query("SHOW TABLES LIKE 'preco_oba_embalado'");
    $temTabela = $rt && $rt->num_rows > 0;
    if ($rt instanceof mysqli_result) {
        $rt->free();
    }
    if ($temTabela) {
        $rc = $conexao->query("SHOW COLUMNS FROM preco_oba_embalado LIKE 'bandejas_por_caixa'");
        if ($rc && $rc->num_rows === 0) {
            $conexao->query("ALTER TABLE preco_oba_embalado ADD COLUMN bandejas_por_caixa VARCHAR(20) NOT NULL DEFAULT '6'");
            // Populacao inicial por nome (depois editavel produto a produto).
            $conexao->query("UPDATE preco_oba_embalado po JOIN produtos pr ON pr.id = po.produto_id SET po.bandejas_por_caixa = '15,6' WHERE UPPER(pr.nome) LIKE '%GRAPE%'");
            $conexao->query("UPDATE preco_oba_embalado po JOIN produtos pr ON pr.id = po.produto_id SET po.bandejas_por_caixa = '18,6' WHERE UPPER(pr.nome) LIKE '%ITALIANO%'");
            $conexao->query("UPDATE preco_oba_embalado po JOIN produtos pr ON pr.id = po.produto_id SET po.bandejas_por_caixa = '15,6' WHERE UPPER(pr.nome) LIKE '%COCKTAIL%' OR UPPER(pr.nome) LIKE '%COQUETEL%'");
        }
        if ($rc instanceof mysqli_result) {
            $rc->free();
        }
    }
}

/** Opcoes padrao de bandejas por caixa, derivadas do nome do produto (fallback). */
function obaBandejasPorCaixaPadrao(string $nomeProduto): string
{
    $nome = mb_strtoupper(trim($nomeProduto), 'UTF-8');
    if (strpos($nome, 'GRAPE') !== false || strpos($nome, 'COCKTAIL') !== false || strpos($nome, 'COQUETEL') !== false) {
        return '15,6';
    }
    if (strpos($nome, 'ITALIANO') !== false) {
        return '18,6';
    }
    return '6';
}

/** Normaliza a string de opcoes ("15, 6") para um array de inteiros [15, 6]. */
function obaOpcoesBandejas(string $opcoes): array
{
    $itens = array_filter(array_map('intval', array_map('trim', explode(',', $opcoes))), static fn($n) => $n > 0);
    $itens = array_values(array_unique($itens));
    return $itens ?: [6];
}

/** Monta a descricao fiscal do item OBA: "<nome base> CX C/<bandejas por caixa>". */
function obaDescricaoNfe(string $descricaoBase, int $bandejasPorCaixa): string
{
    $base = trim($descricaoBase);
    // remove qualquer "CX C/N" ja existente no final para nao duplicar
    $base = preg_replace('/\s*CX\s*C\/\s*\d+\s*$/iu', '', $base);
    $base = trim($base);
    $n = $bandejasPorCaixa > 0 ? $bandejasPorCaixa : 6;
    return ($base !== '' ? $base . ' ' : '') . 'CX C/' . $n;
}

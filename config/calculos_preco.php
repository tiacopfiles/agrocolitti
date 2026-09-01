<?php
/**
 * calculos_preco.php
 * ------------------------------------------------------------------
 * FONTE ÚNICA DE VERDADE para fórmulas de precificação.
 * Altere APENAS aqui. JS em cada página espelha as mesmas fórmulas.
 * ------------------------------------------------------------------
 */

// ── Carregamento de configurações ─────────────────────────────────────────────

/**
 * Retorna o array de configurações de precificação.
 * Busca do banco se a tabela existir; usa defaults caso contrário.
 */
function getConfigPrecificacao(mysqli $conexao): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $defaults = [
        // Frete Kauavuti
        'frete_total_kauavuti'         => 6.5,
        'divisor_kauavuti_embalado'    => 20.0,   // frete_kauavuti_embalado = 6.5 / 20

        // Frete Nivaldo
        'frete_nivaldo_atacado_padrao' => 0.30,   // default inicial por produto atacado
        'frete_nivaldo_base_embalado'  => 0.30,   // frete_nivaldo_embalado = base * gramagem

        // Embalado
        'custo_fixo_embalado'          => 1.25,   // coluna "L" / mão de obra

        // Atacado — percentuais
        'percentual_acrescimo_atacado' => 35.0,   // 35 %
        'percentual_prazo_5'           => 5.0,    //  5 %
        'percentual_prazo_30'          => 5.0,    //  5 %
    ];

    $res = $conexao->query("SHOW TABLES LIKE 'configuracoes_precificacao'");
    if ($res && $res->num_rows > 0) {
        $rows = $conexao->query("SELECT chave, valor FROM configuracoes_precificacao");
        if ($rows) {
            while ($r = $rows->fetch_assoc()) {
                $defaults[$r['chave']] = (float) $r['valor'];
            }
        }
    }

    $cache = $defaults;
    return $cache;
}

function arredondarDecimal(float $valor, int $casas = 4): float
{
    return round($valor, $casas);
}

function arredondarInteiro(float $valor): int
{
    return (int) round($valor, 0);
}

function clienteEmbaladoSemFrete(mysqli $conexao, int $cliente_id): bool
{
    static $cache = [];

    if ($cliente_id <= 0) {
        return false;
    }

    if (array_key_exists($cliente_id, $cache)) {
        return $cache[$cliente_id];
    }

    $stmt = $conexao->prepare("
        SELECT nome
        FROM clientes
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $cliente_id);
    $stmt->execute();
    $cliente = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $nome = strtoupper(trim((string) ($cliente['nome'] ?? '')));
    $cache[$cliente_id] = ($nome === 'OBA - GRUPO FARTURA');

    return $cache[$cliente_id];
}

// ── ATACADO ───────────────────────────────────────────────────────────────────

/**
 * Calcula preços de ATACADO replicando exatamente a planilha.
 *
 * Fórmulas (planilha):
 *   acrescimo_35   = valor_mp × 35% + valor_mp         (= valor_mp × 1,35)
 *   frete_kauavuti = frete_total_kauavuti ÷ kg_da_caixa
 *   prazo_5_dias   = acrescimo_35 + frete_kauavuti + (frete_nivaldo × 5%)
 *                  + frete_kauavuti + frete_nivaldo
 *                  = acrescimo_35 + (2 × frete_kauavuti) + (1,05 × frete_nivaldo)
 *   prazo_30_dias  = prazo_5_dias × 1,05
 *
 * Nota: prazo_30 usa o valor bruto de prazo_5 antes do arredondamento final,
 * evitando perda de precisão intermediária.
 *
 * @param float $valor_mp       Valor da matéria-prima por kg (vem de produtos.valor_materia_prima)
 * @param float $kg_da_caixa    KG por caixa (editável por produto em preco_atacado)
 * @param float $frete_nivaldo  Frete Nivaldo (editável por produto, default da config)
 * @param array $cfg            Config de precificacao (getConfigPrecificacao)
 */
function calcAtacado(float $valor_mp, float $kg_da_caixa, float $frete_nivaldo, array $cfg): array
{
    $pct_acresc = $cfg['percentual_acrescimo_atacado'] / 100;   // 0.35
    $pct_5      = $cfg['percentual_prazo_5']           / 100;   // 0.05
    $pct_30     = $cfg['percentual_prazo_30']          / 100;   // 0.05

    // acrescimo_35 = valor_mp * 35/100 + valor_mp
    $acrescimo_35   = ($valor_mp * $pct_acresc) + $valor_mp;

    // frete_kauavuti = frete_total / kg_caixa
    $frete_kauavuti = ($kg_da_caixa > 0) ?($cfg['frete_total_kauavuti'] / $kg_da_caixa) : 0.0;

    // Fórmula exata da planilha de referência: =E7+I7+J7*5/100+I7+J7
    // prazo_5_dias = acrescimo_35 + frete_k + (frete_n × 0.05) + frete_k + frete_n
    //             = acrescimo_35 + 2×frete_kauavuti + frete_nivaldo×1.05
    $prazo5_bruto  = $acrescimo_35
                   + $frete_kauavuti
                   + ($frete_nivaldo * $pct_5)
                   + $frete_kauavuti
                   + $frete_nivaldo;

    // prazo_30_dias = prazo_5_dias × 1.05
    $prazo30_bruto = $prazo5_bruto * (1 + $pct_30);

    return [
        'acrescimo_35'   => round($acrescimo_35,   4),
        'frete_kauavuti' => round($frete_kauavuti, 4),
        'frete_nivaldo'  => round($frete_nivaldo,  4),
        'prazo_5_dias'   => round($prazo5_bruto,   2),
        'prazo_30_dias'  => round($prazo30_bruto,  2),
    ];
}

function calcAtacadoComFreteKauauti(float $valor_mp, float $frete_kauauti, float $frete_nivaldo, array $cfg): array
{
    $pct_acresc = $cfg['percentual_acrescimo_atacado'] / 100;
    $pct_5      = $cfg['percentual_prazo_5'] / 100;
    $pct_30     = $cfg['percentual_prazo_30'] / 100;

    $acrescimo_35 = ($valor_mp * $pct_acresc) + $valor_mp;
    $prazo5_bruto = $acrescimo_35
        + $frete_kauauti
        + ($frete_nivaldo * $pct_5)
        + $frete_kauauti
        + $frete_nivaldo;

    return [
        'acrescimo_35'   => round($acrescimo_35, 4),
        'frete_kauavuti' => round($frete_kauauti, 4),
        'frete_nivaldo'  => round($frete_nivaldo, 4),
        'prazo_5_dias'   => round($prazo5_bruto, 2),
        'prazo_30_dias'  => round($prazo5_bruto * (1 + $pct_30), 2),
    ];
}

/**
 * Resolve o preço de venda do fluxo Atacado.
 *
 * Regra oficial:
 * - preço depende do produto + prazo escolhido
 * - o percentual financeiro configurado para o cliente é aplicado ao preço
 *
 * @throws Exception
 */
function resolverPrecoVendaAtacado(mysqli $conexao, int $produto_id, string $prazo_escolhido, int $cliente_id = 0): array
{
    if ($produto_id <= 0) {
        throw new Exception('Produto inválido.');
    }

    if (!in_array($prazo_escolhido, ['5_dias', '30_dias'], true)) {
        throw new Exception('Selecione um prazo válido para o atacado.');
    }

    if (!tabelaExiste($conexao, 'preco_atacado')) {
        throw new Exception('Tabela de preços atacado não encontrada. Configure em Tabelas → Atacado.');
    }

    $stmt = $conexao->prepare("
        SELECT pa.valor_mp, pa.kg_caixa, pa.frete_kauauti, pa.frete_nivaldo, pa.ativo
        FROM preco_atacado pa
        INNER JOIN produtos p ON p.id = pa.produto_id
        WHERE pa.produto_id = ?
          AND COALESCE(p.escopo_produto, 'normal') IN ('normal', 'ambos')
        LIMIT 1
    ");
    $stmt->bind_param('i', $produto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new Exception('Preço não configurado para este produto.');
    }

    if (!(int) ($row['ativo'] ?? 0)) {
        throw new Exception('Produto indisponível para venda atacado.');
    }

    $valor_mp       = (float) ($row['valor_mp'] ?? 0);
    $kg_caixa       = (float) ($row['kg_caixa'] ?? 0);
    $cfg            = getConfigPrecificacao($conexao);
    $frete_nivaldo  = (float) ($row['frete_nivaldo'] ?? $cfg['frete_nivaldo_atacado_padrao']);
    $calc           = calcAtacado($valor_mp, $kg_caixa, $frete_nivaldo, $cfg);
    $preco_5_dias   = arredondarMoeda((float) $calc['prazo_5_dias']);
    $preco_30_dias  = arredondarMoeda((float) $calc['prazo_30_dias']);
    [$percentual, $tipoAplicacao] = obterPercentualFinanceiroCliente($conexao, $cliente_id, 'atacado');
    $custo_5_dias = $preco_5_dias;
    $custo_30_dias = $preco_30_dias;
    $preco_5_dias = arredondarMoeda(aplicarPercentualFinanceiro($custo_5_dias, $percentual, $tipoAplicacao));
    $preco_30_dias = arredondarMoeda(aplicarPercentualFinanceiro($custo_30_dias, $percentual, $tipoAplicacao));
    $preco_venda = $prazo_escolhido === '30_dias' ? $preco_30_dias : $preco_5_dias;

    return [
        'kg_caixa'      => $kg_caixa,
        'frete_nivaldo' => arredondarDecimal($frete_nivaldo, 4),
        'acrescimo_35'  => arredondarDecimal((float) $calc['acrescimo_35'], 4),
        'frete_kauavuti'=> arredondarDecimal((float) $calc['frete_kauavuti'], 4),
        'preco_5_dias'  => $preco_5_dias,
        'preco_30_dias' => $preco_30_dias,
        'custo_5_dias'  => $custo_5_dias,
        'custo_30_dias' => $custo_30_dias,
        'percentual'    => $percentual,
        'tipo_aplicacao'=> $tipoAplicacao,
        'preco_venda'   => $preco_venda,
        'prazo_escolhido' => $prazo_escolhido,
        'disponivel'    => true,
    ];
}

// ── EMBALADO ──────────────────────────────────────────────────────────────────

/**
 * Calcula preços de EMBALADO replicando exatamente a planilha.
 *
 * Fórmulas (planilha — colunas B..N):
 *   B  gramagem                 (editável)
 *   C  valor_materia_prima      (vem de produtos)
 *   D  valor_materia_x_gramagem = C × B
 *   E  custo_p_grama            = C / 1000 × 10
 *   F  categoria                (vem de produtos)
 *   G  custo_bd                 = E × B × 100   (≡ D por álgebra, mantido por fidelidade)
 *   H  qtd_por_caixa            (editável)
 *   I  kg_da_caixa              = H × B
 *   J  frete_kauavuti           = 6.5 / 20      (de config)
 *   K  frete_nivaldo            = 0.30 × B      (de config)
 *   L  custo_fixo               = mao_de_obra   (de config)
 *   M  disponibilidade          (editável)
 *   N  preco_produto_base       = D + J + K + L
 *   O  preco_produto_cliente    = N × (1 + percentual_cliente)
 *
 * @param float $gramagem      Gramagem em kg (ex: 0.5 = 500 g)
 * @param float $valor_mp      Valor MP/kg (de produtos.valor_materia_prima)
 * @param float $qtd_por_caixa Quantidade de unidades por caixa
 * @param array $cfg           Config de precificacao
 * @param bool  $ignorar_frete Quando true, zera fretes no cálculo
 */
function obterPercentualFinanceiroCliente(mysqli $conexao, int $cliente_id, string $tabela = 'embalado'): array
{
    $percentual = 0.0;
    $tipoAplicacao = 'acrescimo';

    if ($cliente_id <= 0 || !tabelaExiste($conexao, 'cliente_percentual_financeiro')) {
        return [$percentual, $tipoAplicacao];
    }

    $stmt = $conexao->prepare("
        SELECT percentual, tipo_aplicacao
        FROM cliente_percentual_financeiro
        WHERE cliente_id = ? AND tabela = ?
        LIMIT 1
    ");
    $stmt->bind_param('is', $cliente_id, $tabela);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $percentual = (float) ($row['percentual'] ?? 0);
        $tipoAplicacao = (string) ($row['tipo_aplicacao'] ?? 'acrescimo');
    }

    return [$percentual, $tipoAplicacao];
}

function resolverPrecoVendaAtacadoConvencional(mysqli $conexao, int $produto_id, int $cliente_id, string $prazo_escolhido): array
{
    if ($produto_id <= 0) {
        throw new Exception('Produto invalido.');
    }

    if ($cliente_id <= 0) {
        throw new Exception('Cliente invalido.');
    }

    if (!in_array($prazo_escolhido, ['5_dias', '30_dias'], true)) {
        throw new Exception('Selecione um prazo valido para o atacado convencional.');
    }

    if (!tabelaExiste($conexao, 'preco_atacado_convencional')) {
        throw new Exception('Tabela de precos atacado convencional nao encontrada. Configure em Tabelas -> Atacado Convencional.');
    }

    $stmt = $conexao->prepare("
        SELECT pac.valor_mp, pac.kg_caixa, pac.frete_nivaldo, pac.ativo
        FROM preco_atacado_convencional pac
        INNER JOIN produtos p ON p.id = pac.produto_id
        WHERE pac.produto_id = ?
          AND COALESCE(p.escopo_produto, 'normal') IN ('normal', 'ambos')
        LIMIT 1
    ");
    $stmt->bind_param('i', $produto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new Exception('Preco nao configurado para este produto no atacado convencional.');
    }

    if (!(int) ($row['ativo'] ?? 0)) {
        throw new Exception('Produto indisponivel para venda atacado convencional.');
    }

    $valor_mp      = (float) ($row['valor_mp'] ?? 0);
    $kg_caixa      = (float) ($row['kg_caixa'] ?? 0);
    $cfg           = getConfigPrecificacao($conexao);
    $frete_kauauti = (float) ($row['frete_kauauti'] ?? 0);
    $frete_nivaldo = (float) ($row['frete_nivaldo'] ?? $cfg['frete_nivaldo_atacado_padrao']);
    $calc          = calcAtacadoComFreteKauauti($valor_mp, $frete_kauauti, $frete_nivaldo, $cfg);
    $custo5        = arredondarMoeda((float) $calc['prazo_5_dias']);
    $custo30       = arredondarMoeda($custo5 * (1 + ($cfg['percentual_prazo_30'] / 100)));
    [$percentual, $tipoAplicacao] = obterPercentualFinanceiroCliente($conexao, $cliente_id, 'convencional');

    $preco5 = arredondarMoeda(aplicarPercentualFinanceiro($custo5, $percentual, $tipoAplicacao));
    $preco30 = arredondarMoeda(aplicarPercentualFinanceiro($custo30, $percentual, $tipoAplicacao));
    $precoVenda = $prazo_escolhido === '30_dias' ?$preco30 : $preco5;

    return [
        'kg_caixa'        => $kg_caixa,
        'frete_nivaldo'   => arredondarDecimal($frete_nivaldo, 4),
        'acrescimo_35'    => arredondarDecimal((float) $calc['acrescimo_35'], 4),
        'frete_kauavuti'  => arredondarDecimal((float) $calc['frete_kauavuti'], 4),
        'custo_5_dias'    => $custo5,
        'custo_30_dias'   => $custo30,
        'preco_5_dias'    => $preco5,
        'preco_30_dias'   => $preco30,
        'preco_venda'     => $precoVenda,
        'prazo_escolhido' => $prazo_escolhido,
        'percentual'      => $percentual,
        'tipo_aplicacao'  => $tipoAplicacao,
        'disponivel'      => true,
    ];
}
function calcEmbalado(float $gramagem, float $valor_mp, float $qtd_por_caixa, array $cfg, bool $ignorar_frete = false): array
{
    // D: valor_materia_x_gramagem = valor_mp * gramagem
    $valor_materia_x_gramagem = $valor_mp * $gramagem;

    // E: custo_p_grama = valor_mp / 1000 * 10
    $custo_p_grama = ($valor_mp / 1000) * 10;

    // G: custo_bd = custo_p_grama * gramagem * 100  (≡ D por álgebra, mantido por fidelidade)
    $custo_bd = $custo_p_grama * $gramagem * 100;

    // I: kg_da_caixa = qtd_por_caixa * gramagem
    $kg_da_caixa = $qtd_por_caixa * $gramagem;

    // J: frete_kauavuti = frete_total / divisor  (parametrizado)
    $frete_kauavuti = $ignorar_frete
        ?0.0
        : (
            ($cfg['divisor_kauavuti_embalado'] > 0)
                ?($cfg['frete_total_kauavuti'] / $cfg['divisor_kauavuti_embalado'])
                : 0.0
        );

    // K: frete_nivaldo = base * gramagem  (base parametrizado)
    $frete_nivaldo = $ignorar_frete ?0.0 : ($cfg['frete_nivaldo_base_embalado'] * $gramagem);

    // L: custo_fixo_embalado  (parametrizado)
    $custo_fixo = $cfg['custo_fixo_embalado'];

    // N: preco_produto_base = 2*D + J + K + L (formula Excel: 2x custo de materia-prima)
    $preco_base = 2 * $valor_materia_x_gramagem + $frete_kauavuti + $frete_nivaldo + $custo_fixo;

    return [
        'valor_materia_x_gramagem' => arredondarDecimal($valor_materia_x_gramagem, 4),
        'custo_p_grama'            => arredondarDecimal($custo_p_grama, 4),
        'custo_bd'                 => arredondarDecimal($custo_bd, 4),
        'kg_da_caixa'              => arredondarInteiro($kg_da_caixa),
        'frete_kauavuti'           => arredondarDecimal($frete_kauavuti, 4),
        'frete_nivaldo'            => arredondarDecimal($frete_nivaldo, 4),
        'custo_fixo_embalado'      => arredondarDecimal($custo_fixo, 4),
        'preco_produto_base'       => arredondarMoeda($preco_base),
    ];
}

// ── PERCENTUAL FINANCEIRO POR CLIENTE ─────────────────────────────────────────

/**
 * Aplica percentual financeiro do cliente sobre o preço base.
 *
 * Para inverter entre acréscimo e desconto, altere APENAS este função.
 *   'acrescimo' → preco_final = preco_base × (1 + percentual)
 *   'desconto'  → preco_final = preco_base × (1 − percentual)
 *
 * @param float  $preco_base   Preço base do produto
 * @param float  $percentual   Decimal — ex: 0,05 = 5 %
 * @param string $tipo   'acrescimo' (padrão) | 'desconto'
 */
function aplicarPercentualFinanceiro(float $preco_base, float $percentual, string $tipo = 'acrescimo'): float
{
    if ($tipo === 'desconto') {
        return round($preco_base * (1.0 - $percentual), 2);
    }
    return round($preco_base * (1.0 + $percentual), 2);
}

/**
 * Padroniza arredondamento monetário em uma única função.
 */
function arredondarMoeda(float $valor): float
{
    return round($valor, 2);
}

/**
 * Resolve o preço de venda do fluxo Embalado com compatibilidade para schemas antigos.
 *
 * Regra oficial:
 * - sem percentual do cliente: usa preço base
 * - com percentual do cliente: usa preço final ajustado
 *
 * @throws Exception
 */
function resolverPrecoVendaEmbalado(mysqli $conexao, int $produto_id, int $cliente_id): array
{
    if ($produto_id <= 0) {
        throw new Exception('Produto inválido.');
    }

    if ($cliente_id <= 0) {
        throw new Exception('Cliente inválido.');
    }

    if (!tabelaExiste($conexao, 'preco_embalado')) {
        throw new Exception('Tabela de preços embalado não encontrada. Configure em Tabelas → Embalado.');
    }

    $campos = ['pe.gramagem AS gramagem', 'pe.valor_mp AS valor_mp', 'pe.ativo AS ativo'];
    $temQtdPorCaixa = colunaExiste($conexao, 'preco_embalado', 'qtd_por_caixa');
    if ($temQtdPorCaixa) {
        $campos[] = 'pe.qtd_por_caixa AS qtd_por_caixa';
    }

    $sql = "
        SELECT " . implode(', ', $campos) . "
        FROM preco_embalado pe
        INNER JOIN produtos p ON p.id = pe.produto_id
        WHERE pe.produto_id = ?
          AND COALESCE(p.escopo_produto, 'normal') IN ('normal', 'ambos')
        LIMIT 1
    ";

    $stmt = $conexao->prepare($sql);
    $stmt->bind_param('i', $produto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || (float) ($row['gramagem'] ?? 0) <= 0) {
        throw new Exception('Produto sem configuração de preço embalado. Cadastre em Tabelas → Embalado.');
    }

    if (!(int) ($row['ativo'] ?? 0)) {
        throw new Exception('Este produto está marcado como indisponível na Tabela Embalado.');
    }

    $gramagem     = (float) $row['gramagem'];
    $valor_mp     = (float) $row['valor_mp'];
    $qtd_por_caixa = $temQtdPorCaixa ?(float) ($row['qtd_por_caixa'] ?? 0) : 0.0;

    $cfg          = getConfigPrecificacao($conexao);
    $ignorarFrete = clienteEmbaladoSemFrete($conexao, $cliente_id);
    $calc         = calcEmbalado($gramagem, $valor_mp, $qtd_por_caixa, $cfg, $ignorarFrete);
    $preco_base = arredondarMoeda((float) $calc['preco_produto_base']);

    $percentual     = 0.0;
    $tipo_aplicacao = 'acrescimo';

    if (tabelaExiste($conexao, 'cliente_percentual_financeiro')) {
        $stmtCliente = $conexao->prepare("
            SELECT percentual, tipo_aplicacao
            FROM cliente_percentual_financeiro
            WHERE cliente_id = ? AND tabela = 'embalado'
            LIMIT 1
        ");
        $stmtCliente->bind_param('i', $cliente_id);
        $stmtCliente->execute();
        $rowCliente = $stmtCliente->get_result()->fetch_assoc();
        $stmtCliente->close();

        if ($rowCliente) {
            $percentual     = (float) ($rowCliente['percentual'] ?? 0);
            $tipo_aplicacao = (string) ($rowCliente['tipo_aplicacao'] ?? 'acrescimo');
        }
    }

    $preco_ajustado = arredondarMoeda(
        aplicarPercentualFinanceiro($preco_base, $percentual, $tipo_aplicacao)
    );

    return [
        'gramagem_kg'          => $gramagem,
        'qtd_por_caixa'        => $qtd_por_caixa,
        'percentual'           => $percentual,
        'tipo_aplicacao'       => $tipo_aplicacao,
        'preco_base'           => $preco_base,
        'preco_ajustado'       => $preco_ajustado,
        'preco_venda'          => $percentual > 0 ?$preco_ajustado : $preco_base,
        'tem_percentual'       => $percentual > 0,
        'ignora_frete'         => $ignorarFrete,
        'disponivel'           => true,
    ];
}

function resolverPrecoVendaObaEmbalado(mysqli $conexao, int $produto_id): array
{
    if ($produto_id <= 0) {
        throw new Exception('Produto invalido.');
    }

    if (!tabelaExiste($conexao, 'preco_oba_embalado')) {
        throw new Exception('Tabela de precos OBA nao encontrada. Configure em Tabelas -> OBA Embalado.');
    }

    $stmt = $conexao->prepare("
        SELECT po.gramagem, po.valor_mp, po.qtd_por_caixa, po.frete_kauauti, po.frete_nivaldo, po.ativo
        FROM preco_oba_embalado po
        INNER JOIN produtos p ON p.id = po.produto_id
        WHERE po.produto_id = ?
          AND COALESCE(p.escopo_produto, 'normal') IN ('oba', 'ambos')
          AND (p.escopo_produto = 'oba' OR p.produto_principal_id IS NULL)
        LIMIT 1
    ");
    $stmt->bind_param('i', $produto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || (float) ($row['gramagem'] ?? 0) <= 0) {
        throw new Exception('Produto sem configuracao de preco na Tabela OBA Embalado.');
    }
    if (!(int) ($row['ativo'] ?? 0)) {
        throw new Exception('Produto indisponivel na Tabela OBA Embalado.');
    }

    $gramagem = (float) $row['gramagem'];
    $valorMp = (float) $row['valor_mp'];
    $qtdPorCaixa = (float) ($row['qtd_por_caixa'] ?? 0);
    $freteKauauti = (float) ($row['frete_kauauti'] ?? 0);
    $freteNivaldo = (float) ($row['frete_nivaldo'] ?? 0);
    $cfg = getConfigPrecificacao($conexao);
    $valorMateria = $valorMp * $gramagem;
    $precoBase = arredondarMoeda((2 * $valorMateria) + $freteKauauti + $freteNivaldo + $cfg['custo_fixo_embalado']);
    $precoOba = arredondarMoeda($precoBase * 1.05);

    return [
        'gramagem_kg' => $gramagem,
        'qtd_por_caixa' => $qtdPorCaixa,
        'percentual' => 0.05,
        'tipo_aplicacao' => 'acrescimo',
        'preco_base' => $precoBase,
        'preco_ajustado' => $precoOba,
        'preco_venda' => $precoOba,
        'tem_percentual' => true,
        'ignora_frete' => ($freteKauauti == 0.0 && $freteNivaldo == 0.0),
        'disponivel' => true,
    ];
}

function resolverPrecoVendaShopper(mysqli $conexao, int $produto_id, string $prazo_escolhido): array
{
    if ($produto_id <= 0) {
        throw new Exception('Produto invalido.');
    }
    if (!in_array($prazo_escolhido, ['5_dias', '30_dias'], true)) {
        throw new Exception('Selecione um prazo valido para a Tabela Shopper.');
    }
    if (!tabelaExiste($conexao, 'preco_shopper')) {
        throw new Exception('Tabela Shopper nao encontrada. Configure em Tabelas -> Shopper.');
    }

    $stmt = $conexao->prepare("
        SELECT ps.kg_caixa, ps.custo_5_dias, ps.prazo_5_dias, ps.custo_30_dias, ps.prazo_30_dias, ps.ativo
        FROM preco_shopper ps
        INNER JOIN produtos p ON p.id = ps.produto_id
        WHERE ps.produto_id = ?
          AND COALESCE(p.escopo_produto, 'normal') IN ('normal', 'ambos')
        LIMIT 1
    ");
    $stmt->bind_param('i', $produto_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new Exception('Preco nao configurado para este produto na Tabela Shopper.');
    }
    if (!(int) ($row['ativo'] ?? 0)) {
        throw new Exception('Produto indisponivel na Tabela Shopper.');
    }

    $preco5 = arredondarMoeda((float) $row['prazo_5_dias']);
    $preco30 = arredondarMoeda((float) $row['prazo_30_dias']);

    return [
        'kg_caixa' => (float) $row['kg_caixa'],
        'custo_5_dias' => arredondarMoeda((float) $row['custo_5_dias']),
        'custo_30_dias' => arredondarMoeda((float) $row['custo_30_dias']),
        'preco_5_dias' => $preco5,
        'preco_30_dias' => $preco30,
        'preco_venda' => $prazo_escolhido === '30_dias' ? $preco30 : $preco5,
        'prazo_escolhido' => $prazo_escolhido,
        'disponivel' => true,
    ];
}

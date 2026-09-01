<?php
/**
 * Salva / atualiza os dados de precificação embalado de um produto.
 * Responde JSON: { ok: true } ou { ok: false, erro: "mensagem" }
 */
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/ciclo_helper.php';
require __DIR__ . '/../../config/calculos_preco.php';
require __DIR__ . '/salvar_disponibilidade_helper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SESSION['usuario_nivel'] !== 'admin') {
    echo json_encode(['ok' => false, 'erro' => 'Acesso negado']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'erro' => 'Método inválido']);
    exit;
}

$produto_id    = (int)    ($_POST['produto_id']    ?? 0);
$gramagem      = (float)  ($_POST['gramagem']      ?? 0);
$valor_mp      = (float)  ($_POST['valor_mp']      ?? 0);
$qtd_por_caixa = (float)  ($_POST['qtd_por_caixa'] ?? 0);
$categoria     = trim(    $_POST['categoria']      ?? '');
$frete_kauauti_post = $_POST['frete_kauauti'] ?? null;
$frete_nivaldo_post = $_POST['frete_nivaldo'] ?? null;
$ativo         = (int)    ($_POST['ativo']          ?? 1);

if ($produto_id <= 0 || $gramagem <= 0) {
    if ($produto_id > 0) {
        salvarDisponibilidadePrecoTabela($conexao, 'preco_embalado', $produto_id, $ativo);
        responderDisponibilidadeSalva();
    }
    echo json_encode(['ok' => false, 'erro' => 'Dados inválidos (produto ou gramagem ausente)']);
    exit;
}

// Migração incremental: garante que as colunas existam
$novasColunas = [
    'qtd_por_caixa' => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
    'categoria'     => "VARCHAR(100) NOT NULL DEFAULT ''",
    'custo_p_grama' => "DECIMAL(12,6) NOT NULL DEFAULT 0.000000",
    'custo_bd'      => "DECIMAL(10,4) NOT NULL DEFAULT 0.0000",
    'kg_da_caixa'   => "DECIMAL(10,4) NOT NULL DEFAULT 0.0000",
];
foreach ($novasColunas as $col => $def) {
    if (!colunaExiste($conexao, 'preco_embalado', $col)) {
        $conexao->query("ALTER TABLE preco_embalado ADD COLUMN $col $def");
    }
}

// Recalcula todos os campos derivados (4 argumentos obrigatórios)
$cfg = getConfigPrecificacao($conexao);
$c   = calcEmbalado($gramagem, $valor_mp, $qtd_por_caixa, $cfg);

if ($frete_kauauti_post !== null && $frete_kauauti_post !== '') {
    $c['frete_kauavuti'] = max(0, (float) $frete_kauauti_post);
}
if ($frete_nivaldo_post !== null && $frete_nivaldo_post !== '') {
    $c['frete_nivaldo'] = max(0, (float) $frete_nivaldo_post);
}
$c['preco_produto_base'] = round(
    $c['valor_materia_x_gramagem'] + $c['frete_kauavuti'] + $c['frete_nivaldo'] + $c['custo_fixo_embalado'],
    2
);

$stmt = $conexao->prepare("
    INSERT INTO preco_embalado
        (produto_id, gramagem, valor_mp, qtd_por_caixa, categoria,
         custo_mp, frete_kauauti, frete_nivaldo, mao_de_obra, preco_calculado,
         custo_p_grama, custo_bd, kg_da_caixa, ativo)
    VALUES (?, ?, ?, ?, ?,  ?, ?, ?, ?, ?,  ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        gramagem        = VALUES(gramagem),
        valor_mp        = VALUES(valor_mp),
        qtd_por_caixa   = VALUES(qtd_por_caixa),
        categoria       = VALUES(categoria),
        custo_mp        = VALUES(custo_mp),
        frete_kauauti   = VALUES(frete_kauauti),
        frete_nivaldo   = VALUES(frete_nivaldo),
        mao_de_obra     = VALUES(mao_de_obra),
        preco_calculado = VALUES(preco_calculado),
        custo_p_grama   = VALUES(custo_p_grama),
        custo_bd        = VALUES(custo_bd),
        kg_da_caixa     = VALUES(kg_da_caixa),
        ativo           = VALUES(ativo)
");

// Mapeia chaves do array calcEmbalado → colunas do banco
$custo_mp        = $c['valor_materia_x_gramagem'];
$frete_kauauti   = $c['frete_kauavuti'];          // calcEmbalado retorna 'frete_kauavuti' (com v)
$frete_nivaldo   = $c['frete_nivaldo'];
$mao_de_obra     = $c['custo_fixo_embalado'];
$preco_calculado = $c['preco_produto_base'];
$custo_p_grama   = $c['custo_p_grama'];
$custo_bd        = $c['custo_bd'];
$kg_da_caixa     = $c['kg_da_caixa'];

$stmt->bind_param(
    'idddsddddddddi',
    $produto_id,
    $gramagem,
    $valor_mp,
    $qtd_por_caixa,
    $categoria,
    $custo_mp,
    $frete_kauauti,
    $frete_nivaldo,
    $mao_de_obra,
    $preco_calculado,
    $custo_p_grama,
    $custo_bd,
    $kg_da_caixa,
    $ativo
);

if ($stmt->execute()) {
    echo json_encode(['ok' => true, 'calc' => $c]);
} else {
    echo json_encode(['ok' => false, 'erro' => $conexao->error]);
}
$stmt->close();

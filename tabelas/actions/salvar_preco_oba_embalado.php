<?php
/**
 * Salva / atualiza os dados de precificacao OBA Embalado de um produto.
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
    echo json_encode(['ok' => false, 'erro' => 'Metodo inválido']);
    exit;
}

$produto_id    = (int)   ($_POST['produto_id']    ?? 0);
$gramagem      = (float) ($_POST['gramagem']      ?? 0);
$valor_mp      = (float) ($_POST['valor_mp']      ?? 0);
$qtd_por_caixa = (float) ($_POST['qtd_por_caixa'] ?? 0);
$categoria     = trim((string) ($_POST['categoria'] ?? ''));
$frete_kauauti_post = $_POST['frete_kauauti'] ?? null;
$frete_nivaldo_post = $_POST['frete_nivaldo'] ?? null;
$ativo         = (int)   ($_POST['ativo'] ?? 1);

if ($produto_id <= 0 || $gramagem <= 0) {
    if ($produto_id > 0) {
        salvarDisponibilidadePrecoTabela($conexao, 'preco_oba_embalado', $produto_id, $ativo);
        responderDisponibilidadeSalva();
    }
    echo json_encode(['ok' => false, 'erro' => 'Dados invalidos (produto ou gramagem ausente)']);
    exit;
}

$conexao->query("
    CREATE TABLE IF NOT EXISTS preco_oba_embalado (
        id INT NOT NULL AUTO_INCREMENT,
        produto_id INT NOT NULL,
        gramagem DECIMAL(10,3) NOT NULL DEFAULT 0.000,
        valor_mp DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        qtd_por_caixa DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        categoria VARCHAR(100) NOT NULL DEFAULT '',
        custo_mp DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        frete_kauauti DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        frete_nivaldo DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        mao_de_obra DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        preco_calculado DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        custo_p_grama DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
        custo_bd DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        kg_da_caixa DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_preco_oba_produto (produto_id),
        CONSTRAINT fk_preco_oba_produto FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$cfg = getConfigPrecificacao($conexao);
$c = calcEmbalado($gramagem, $valor_mp, $qtd_por_caixa, $cfg);

if ($frete_kauauti_post !== null && $frete_kauauti_post !== '') {
    $c['frete_kauavuti'] = max(0, (float) $frete_kauauti_post);
}
if ($frete_nivaldo_post !== null && $frete_nivaldo_post !== '') {
    $c['frete_nivaldo'] = max(0, (float) $frete_nivaldo_post);
}

$c['preco_produto_base'] = round(
    (2 * $c['valor_materia_x_gramagem']) + $c['frete_kauavuti'] + $c['frete_nivaldo'] + $c['custo_fixo_embalado'],
    2
);
$c['preco_oba'] = round($c['preco_produto_base'] * 1.05, 2);

$stmt = $conexao->prepare("
    INSERT INTO preco_oba_embalado
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

$custo_mp        = $c['valor_materia_x_gramagem'];
$frete_kauauti   = $c['frete_kauavuti'];
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
    echo json_encode(['ok' => false, 'erro' => $stmt->error ?: $conexao->error]);
}

$stmt->close();

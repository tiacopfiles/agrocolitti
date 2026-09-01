<?php
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/salvar_disponibilidade_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['usuario_nivel'] ?? '') !== 'admin') {
    echo json_encode(['ok' => false, 'erro' => 'Acesso negado']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['ok' => false, 'erro' => 'Metodo inválido']);
    exit;
}

$produtoId = (int) ($_POST['produto_id'] ?? 0);
$categoria = trim((string) ($_POST['categoria'] ?? ''));
$gramagem = (float) ($_POST['gramagem'] ?? 0);
$valorMp = (float) ($_POST['valor_mp'] ?? 0);
$unidade = trim((string) ($_POST['unidade'] ?? ''));
$kgCaixa = (float) ($_POST['kg_caixa'] ?? 0);
$freteKauauti = max(0, (float) ($_POST['frete_kauauti'] ?? 0));
$freteNivaldo = max(0, (float) ($_POST['frete_nivaldo'] ?? 0));
$ativo = (int) ($_POST['ativo'] ?? 1);

if ($produtoId <= 0 || $kgCaixa <= 0) {
    if ($produtoId > 0) {
        salvarDisponibilidadePrecoTabela($conexao, 'preco_shopper', $produtoId, $ativo);
        responderDisponibilidadeSalva();
    }
    echo json_encode(['ok' => false, 'erro' => 'Dados invalidos (produto ou kg da caixa ausente)']);
    exit;
}

$conexao->query("
    CREATE TABLE IF NOT EXISTS preco_shopper (
        id INT NOT NULL AUTO_INCREMENT,
        produto_id INT NOT NULL,
        categoria VARCHAR(100) NOT NULL DEFAULT '',
        gramagem DECIMAL(10,3) NOT NULL DEFAULT 0.000,
        valor_mp DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        unidade VARCHAR(60) NOT NULL DEFAULT '',
        kg_caixa DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        acrescimo_35 DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
        frete_kauauti DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
        frete_nivaldo DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
        custo_5_dias DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
        prazo_5_dias DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
        custo_30_dias DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
        prazo_30_dias DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_preco_shopper_produto (produto_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$acrescimo35 = $valorMp * 1.35;
$custo5 = $acrescimo35 + $freteKauauti + ($freteNivaldo * 0.05) + $freteKauauti + $freteNivaldo;
$prazo5 = $custo5 * 1.08;
$custo30 = $custo5 * 1.05;
$prazo30 = $custo30 * 1.08;

$stmt = $conexao->prepare("
    INSERT INTO preco_shopper
        (produto_id, categoria, gramagem, valor_mp, unidade, kg_caixa,
         acrescimo_35, frete_kauauti, frete_nivaldo, custo_5_dias,
         prazo_5_dias, custo_30_dias, prazo_30_dias, ativo)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        categoria = VALUES(categoria),
        gramagem = VALUES(gramagem),
        valor_mp = VALUES(valor_mp),
        unidade = VALUES(unidade),
        kg_caixa = VALUES(kg_caixa),
        acrescimo_35 = VALUES(acrescimo_35),
        frete_kauauti = VALUES(frete_kauauti),
        frete_nivaldo = VALUES(frete_nivaldo),
        custo_5_dias = VALUES(custo_5_dias),
        prazo_5_dias = VALUES(prazo_5_dias),
        custo_30_dias = VALUES(custo_30_dias),
        prazo_30_dias = VALUES(prazo_30_dias),
        ativo = VALUES(ativo)
");
$stmt->bind_param(
    'isddsddddddddi',
    $produtoId, $categoria, $gramagem, $valorMp, $unidade, $kgCaixa,
    $acrescimo35, $freteKauauti, $freteNivaldo, $custo5,
    $prazo5, $custo30, $prazo30, $ativo
);

if ($stmt->execute()) {
    echo json_encode(['ok' => true, 'calc' => [
        'acrescimo_35' => $acrescimo35,
        'custo_5_dias' => $custo5,
        'prazo_5_dias' => $prazo5,
        'custo_30_dias' => $custo30,
        'prazo_30_dias' => $prazo30,
    ]]);
} else {
    echo json_encode(['ok' => false, 'erro' => $stmt->error ?: $conexao->error]);
}
$stmt->close();

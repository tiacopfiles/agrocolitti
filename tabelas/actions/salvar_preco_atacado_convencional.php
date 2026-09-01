<?php
/**
 * Salva / atualiza os dados de precificação de atacado de um produto.
 * Responde JSON: { ok: true } ou { ok: false, erro: "mensagem" }
 */
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/ciclo_helper.php';
require __DIR__ . '/../../config/calculos_preco.php';
require __DIR__ . '/salvar_disponibilidade_helper.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SESSION['usuario_nivel'] ?? '') !== 'admin') {
        echo json_encode(['ok' => false, 'erro' => 'Acesso negado']);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        echo json_encode(['ok' => false, 'erro' => 'Metodo inválido']);
        exit;
    }

    $produto_id = (int)   ($_POST['produto_id'] ?? 0);
    $categoria  = trim((string) ($_POST['categoria'] ?? ''));
    $gramagem   = (float) ($_POST['gramagem'] ?? 0);
    $valor_mp   = (float) ($_POST['valor_mp']   ?? 0);
    $unidade_comercial = trim((string) ($_POST['unidade_comercial'] ?? ''));
    $kg_caixa   = (float) ($_POST['kg_caixa']   ?? 0);
    $frete_kauauti_post = $_POST['frete_kauauti'] ?? null;
    $frete_nivaldo_post = $_POST['frete_nivaldo'] ?? null;
    $ativo      = (int)   ($_POST['ativo']       ?? 1);

    if ($produto_id <= 0 || $kg_caixa <= 0) {
        if ($produto_id > 0) {
            salvarDisponibilidadePrecoTabela($conexao, 'preco_atacado_convencional', $produto_id, $ativo);
            responderDisponibilidadeSalva();
        }
        echo json_encode(['ok' => false, 'erro' => 'Dados inválidos (produto ou kg_caixa ausente)']);
        exit;
    }

    $novasColunas = [
        'nome_tabela' => "VARCHAR(150) NOT NULL DEFAULT ''",
        'categoria' => "VARCHAR(100) NOT NULL DEFAULT ''",
        'gramagem'  => "DECIMAL(10,3) NOT NULL DEFAULT 0.000",
        'valor_mp'  => "DECIMAL(10,2) NOT NULL DEFAULT 0.00",
        'unidade_comercial' => "VARCHAR(50) NOT NULL DEFAULT ''",
        'kg_caixa'  => "DECIMAL(10,2) NOT NULL DEFAULT 20.00",
        'ativo'     => "TINYINT(1) NOT NULL DEFAULT 1",
    ];
    foreach ($novasColunas as $col => $def) {
        if (!colunaExiste($conexao, 'preco_atacado_convencional', $col)) {
            $conexao->query("ALTER TABLE preco_atacado_convencional ADD COLUMN $col $def");
        }
    }

    // Recalcula todos os campos derivados (4 argumentos obrigatórios)
    $cfg           = getConfigPrecificacao($conexao);
    $frete_nivaldo = ($frete_nivaldo_post !== null && $frete_nivaldo_post !== '')
        ? max(0, (float) $frete_nivaldo_post)
        : $cfg['frete_nivaldo_atacado_padrao'];
    $c             = calcAtacado($valor_mp, $kg_caixa, $frete_nivaldo, $cfg);

    // calcAtacado retorna 'frete_kauavuti' (com v); a coluna no banco chama 'frete_kauauti' (sem v)
    $frete_kauauti_valor = ($frete_kauauti_post !== null && $frete_kauauti_post !== '')
        ? max(0, (float) $frete_kauauti_post)
        : $c['frete_kauavuti'];
    $c['frete_kauavuti'] = $frete_kauauti_valor;
    $prazo5 = $c['acrescimo_35'] + $frete_kauauti_valor + ($frete_nivaldo * 5 / 100) + $frete_kauauti_valor + $frete_nivaldo;
    $c['prazo_5_dias'] = round($prazo5, 2);
    $c['prazo_30_dias'] = round($prazo5 * 1.05, 2);

    $stmt = $conexao->prepare("
        INSERT INTO preco_atacado_convencional
            (produto_id, categoria, gramagem, valor_mp, unidade_comercial, kg_caixa, acrescimo_35, frete_kauauti, frete_nivaldo, prazo_5_dias, prazo_30_dias, ativo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            categoria     = VALUES(categoria),
            gramagem      = VALUES(gramagem),
            valor_mp      = VALUES(valor_mp),
            unidade_comercial = VALUES(unidade_comercial),
            kg_caixa      = VALUES(kg_caixa),
            acrescimo_35  = VALUES(acrescimo_35),
            frete_kauauti = VALUES(frete_kauauti),
            frete_nivaldo = VALUES(frete_nivaldo),
            prazo_5_dias  = VALUES(prazo_5_dias),
            prazo_30_dias = VALUES(prazo_30_dias),
            ativo         = VALUES(ativo)
    ");
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar salvamento do atacado: ' . $conexao->error);
    }
    $stmt->bind_param(
        'isddsddddddi',
        $produto_id,
        $categoria,
        $gramagem,
        $valor_mp,
        $unidade_comercial,
        $kg_caixa,
        $c['acrescimo_35'],
        $frete_kauauti_valor,
        $frete_nivaldo,
        $c['prazo_5_dias'],
        $c['prazo_30_dias'],
        $ativo
    );

    if ($stmt->execute()) {
        echo json_encode(['ok' => true, 'calc' => $c]);
    } else {
        echo json_encode(['ok' => false, 'erro' => $conexao->error]);
    }
    $stmt->close();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}

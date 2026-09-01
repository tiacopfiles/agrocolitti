<?php
require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../auth/proteger.php';
require_once __DIR__ . '/../../config/permissions.php';
require_once __DIR__ . '/../meeiros_helper_v2.php';

requirePermission(PERM_ADMIN, '../../index.php');
comissoesMeeirosGarantirEstrutura($conexao);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../tabela_precos_meeiros.php');
    exit;
}

$linhas = $_POST['linhas'] ?? [];
if (!is_array($linhas)) {
    header('Location: ../tabela_precos_meeiros.php?msg=erro');
    exit;
}

$conexao->begin_transaction();
try {
    foreach ($linhas as $linha) {
        if (!is_array($linha)) {
            continue;
        }

        $id = (int) ($linha['id'] ?? 0);
        $produtoId = (int) ($linha['produto_id'] ?? 0);
        $produtoNome = trim((string) ($linha['produto_nome'] ?? ''));
        $qualidade = comissoesMeeirosQualidadeNormalizada((string) ($linha['qualidade_label'] ?? ''));
        $valor = (float) str_replace(',', '.', (string) ($linha['valor_por_kg'] ?? 0));
        $ativo = isset($linha['ativo']) ? 1 : 0;
        $periodo = '';

        if ($produtoNome === '' && $valor <= 0 && $produtoId <= 0) {
            continue;
        }
        if ($produtoNome === '' || $valor <= 0) {
            throw new RuntimeException('Produto e preco positivo sao obrigatorios.');
        }
        if ($produtoId <= 0) {
            $produtoId = comissoesMeeirosResolverProdutoId($conexao, $produtoNome) ?? 0;
        } else {
            $stmtProdutoPrincipal = $conexao->prepare("
                SELECT id
                FROM produtos
                WHERE id = ?
                  AND ativo = 1
                  AND produto_principal_id IS NULL
                LIMIT 1
            ");
            $stmtProdutoPrincipal->bind_param('i', $produtoId);
            $stmtProdutoPrincipal->execute();
            $produtoPrincipalValido = $stmtProdutoPrincipal->get_result()->fetch_assoc();
            $stmtProdutoPrincipal->close();
            if (!$produtoPrincipalValido) {
                throw new RuntimeException('O produto vinculado precisa ser um produto principal.');
            }
        }
        $produtoIdDb = $produtoId > 0 ? $produtoId : null;
        if ($id > 0) {
            $stmtDuplicado = $conexao->prepare("
                SELECT id
                FROM tabela_precos_meeiros
                WHERE produto_nome = ?
                  AND qualidade_label = ?
                  AND periodo_label = ?
                  AND id <> ?
                LIMIT 1
            ");
            $stmtDuplicado->bind_param('sssi', $produtoNome, $qualidade, $periodo, $id);
            $stmtDuplicado->execute();
            $duplicado = $stmtDuplicado->get_result()->fetch_assoc();
            $stmtDuplicado->close();

            if ($duplicado) {
                $idDestino = (int) $duplicado['id'];
                $stmt = $conexao->prepare("
                    UPDATE tabela_precos_meeiros
                    SET produto_id = ?, produto_nome = ?, qualidade_label = ?, periodo_label = ?, valor_por_kg = ?, ativo = ?
                    WHERE id = ?
                ");
                $stmt->bind_param('isssdii', $produtoIdDb, $produtoNome, $qualidade, $periodo, $valor, $ativo, $idDestino);
                $stmt->execute();
                $stmt->close();

                $stmtDeleteDuplicado = $conexao->prepare("DELETE FROM tabela_precos_meeiros WHERE id = ?");
                $stmtDeleteDuplicado->bind_param('i', $id);
                $stmtDeleteDuplicado->execute();
                $stmtDeleteDuplicado->close();
                continue;
            }

            $stmt = $conexao->prepare("
                UPDATE tabela_precos_meeiros
                SET produto_id = ?, produto_nome = ?, qualidade_label = ?, periodo_label = ?, valor_por_kg = ?, ativo = ?
                WHERE id = ?
            ");
            $stmt->bind_param('isssdii', $produtoIdDb, $produtoNome, $qualidade, $periodo, $valor, $ativo, $id);
        } else {
            $stmt = $conexao->prepare("
                INSERT INTO tabela_precos_meeiros (produto_id, produto_nome, qualidade_label, periodo_label, valor_por_kg, ativo)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE produto_id = VALUES(produto_id), valor_por_kg = VALUES(valor_por_kg), ativo = VALUES(ativo), qualidade_label = VALUES(qualidade_label)
            ");
            $stmt->bind_param('isssdi', $produtoIdDb, $produtoNome, $qualidade, $periodo, $valor, $ativo);
        }
        $stmt->execute();
        $stmt->close();
    }

    $conexao->commit();
    header('Location: ../tabela_precos_meeiros.php?msg=salvo');
    exit;
} catch (Throwable $e) {
    $conexao->rollback();
    error_log('Erro ao salvar tabela de precos de meeiros: ' . $e->getMessage());
    header('Location: ../tabela_precos_meeiros.php?msg=erro');
    exit;
}

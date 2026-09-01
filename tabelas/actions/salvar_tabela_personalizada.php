<?php
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../config/permissions.php';
require __DIR__ . '/../../config/tabelas_preco_flex_schema.php';

requirePermission(PERM_ADMIN, '../tabelas_personalizadas.php');
garantirTabelasPrecoFlex($conexao);

$id = (int) ($_POST['id'] ?? 0);
$nome = trim((string) ($_POST['nome'] ?? ''));
$colunas = array_values(array_filter(array_map('trim', $_POST['colunas'] ?? []), static fn($v) => $v !== ''));

if ($nome === '' || !$colunas) {
    header('Location: ../tabelas_personalizadas.php?msg=erro');
    exit;
}

$conexao->begin_transaction();
try {
    if ($id > 0) {
        $stmt = $conexao->prepare("UPDATE tabelas_preco SET nome = ? WHERE id = ?");
        $stmt->bind_param('si', $nome, $id);
        $stmt->execute();
        $stmt->close();
    } else {
        $tipo = 'personalizada';
        $stmt = $conexao->prepare("INSERT INTO tabelas_preco (nome, tipo, ativo) VALUES (?, ?, 1)");
        $stmt->bind_param('ss', $nome, $tipo);
        $stmt->execute();
        $id = $conexao->insert_id;
        $stmt->close();
    }

    $stmt = $conexao->prepare("DELETE FROM tabelas_preco_colunas WHERE tabela_id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conexao->prepare("INSERT INTO tabelas_preco_colunas (tabela_id, nome_coluna, ordem) VALUES (?, ?, ?)");
    foreach ($colunas as $ordem => $coluna) {
        $stmt->bind_param('isi', $id, $coluna, $ordem);
        $stmt->execute();
    }
    $stmt->close();

    $excluir = array_map('intval', $_POST['excluir_linhas'] ?? []);
    if ($excluir) {
        $stmt = $conexao->prepare("DELETE FROM tabelas_preco_linhas WHERE id = ? AND tabela_id = ?");
        foreach ($excluir as $linhaId) {
            $stmt->bind_param('ii', $linhaId, $id);
            $stmt->execute();
        }
        $stmt->close();
    }

    $stmt = $conexao->prepare("UPDATE tabelas_preco_linhas SET dados_json = ?, ordem = ? WHERE id = ? AND tabela_id = ?");
    foreach (($_POST['linhas_existentes'] ?? []) as $linhaId => $dados) {
        if (in_array((int) $linhaId, $excluir, true)) {
            continue;
        }
        $json = json_encode(array_values($dados), JSON_UNESCAPED_UNICODE);
        $ordem = (int) $linhaId;
        $linhaId = (int) $linhaId;
        $stmt->bind_param('siii', $json, $ordem, $linhaId, $id);
        $stmt->execute();
    }
    $stmt->close();

    $stmt = $conexao->prepare("INSERT INTO tabelas_preco_linhas (tabela_id, dados_json, ordem) VALUES (?, ?, ?)");
    $ordemNova = 0;
    foreach (($_POST['linhas_novas'] ?? []) as $dados) {
        $valores = array_values($dados);
        if (count(array_filter($valores, static fn($v) => trim((string) $v) !== '')) === 0) {
            continue;
        }
        $ordemInt = $ordemNova++;
        $json = json_encode($valores, JSON_UNESCAPED_UNICODE);
        $stmt->bind_param('isi', $id, $json, $ordemInt);
        $stmt->execute();
    }
    $stmt->close();

    $conexao->commit();
    header('Location: ../tabelas_personalizadas.php?id=' . $id . '&msg=salvo');
    exit;
} catch (Throwable $e) {
    $conexao->rollback();
    header('Location: ../tabelas_personalizadas.php?msg=erro');
    exit;
}

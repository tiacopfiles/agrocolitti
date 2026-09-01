<?php
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/conexao.php';

header('Content-Type: application/json; charset=utf-8');

function colunaExisteAdicionarProdutoTabela(mysqli $conexao, string $tabela, string $coluna): bool
{
    $stmt = $conexao->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $tabela, $coluna);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $existe = $resultado && $resultado->num_rows > 0;
    $stmt->close();
    return $existe;
}

function garantirProdutoNaTabela(mysqli $conexao, int $produtoId, string $tipoTabela): void
{
    $tabelas = [
        'atacado' => 'preco_atacado',
        'atacado_convencional' => 'preco_atacado_convencional',
        'embalado' => 'preco_embalado',
        'oba_embalado' => 'preco_oba_embalado',
        'shopper' => 'preco_shopper',
    ];

    if ($produtoId <= 0 || !isset($tabelas[$tipoTabela])) {
        return;
    }

    $tabelaPreco = $tabelas[$tipoTabela];
    $temAtivo = colunaExisteAdicionarProdutoTabela($conexao, $tabelaPreco, 'ativo');

    $stmt = $conexao->prepare("SELECT id FROM `$tabelaPreco` WHERE produto_id = ? LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Falha ao verificar produto na tabela: ' . $conexao->error);
    }
    $stmt->bind_param('i', $produtoId);
    $stmt->execute();
    $registro = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($registro) {
        if ($temAtivo) {
            $stmt = $conexao->prepare("UPDATE `$tabelaPreco` SET ativo = 1 WHERE produto_id = ?");
            if (!$stmt) {
                throw new RuntimeException('Falha ao reativar produto na tabela: ' . $conexao->error);
            }
            $stmt->bind_param('i', $produtoId);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }

    if ($temAtivo) {
        $stmt = $conexao->prepare("INSERT INTO `$tabelaPreco` (produto_id, ativo) VALUES (?, 1)");
    } else {
        $stmt = $conexao->prepare("INSERT INTO `$tabelaPreco` (produto_id) VALUES (?)");
    }
    if (!$stmt) {
        throw new RuntimeException('Falha ao incluir produto na tabela: ' . $conexao->error);
    }
    $stmt->bind_param('i', $produtoId);
    $stmt->execute();
    $stmt->close();
}

try {
    if (($_SESSION['usuario_nivel'] ?? '') !== 'admin') {
        echo json_encode(['ok' => false, 'erro' => 'Acesso negado']);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        echo json_encode(['ok' => false, 'erro' => 'Metodo inválido']);
        exit;
    }

    $produtoIdSelecionado = (int) ($_POST['produto_id'] ?? 0);
    $tabelaOrigem = trim((string) ($_POST['tabela_origem'] ?? ''));
    $nome = trim((string) ($_POST['nome'] ?? ''));
    $unidade = strtoupper(trim((string) ($_POST['unidade'] ?? 'KG')));
    $unidadesPermitidas = ['KG', 'UN', 'CX', 'BDJ', 'PCT'];

    if ($produtoIdSelecionado > 0) {
        $stmt = $conexao->prepare('SELECT id, nome, unidade, ativo, produto_principal_id, escopo_produto FROM produtos WHERE id = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Falha ao preparar busca do produto: ' . $conexao->error);
        }
        $stmt->bind_param('i', $produtoIdSelecionado);
        $stmt->execute();
        $produto = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$produto) {
            echo json_encode(['ok' => false, 'erro' => 'Produto selecionado nao encontrado.']);
            exit;
        }
        $escopoProduto = (string) ($produto['escopo_produto'] ?? 'normal');
        $produtoPrincipalId = (int) ($produto['produto_principal_id'] ?? 0);
        if ($tabelaOrigem === 'oba_embalado') {
            if (!in_array($escopoProduto, ['oba', 'ambos'], true) || ($escopoProduto === 'ambos' && $produtoPrincipalId > 0)) {
                echo json_encode(['ok' => false, 'erro' => 'Selecione um produto cadastrado como OBA ou Ambos.']);
                exit;
            }
        } elseif (!in_array($escopoProduto, ['normal', 'ambos'], true)) {
            echo json_encode(['ok' => false, 'erro' => 'Produto exclusivo OBA nao pode ser adicionado nesta tabela.']);
            exit;
        }
        if ((int) ($produto['ativo'] ?? 1) !== 1) {
            $stmt = $conexao->prepare('UPDATE produtos SET ativo = 1 WHERE id = ?');
            $stmt->bind_param('i', $produtoIdSelecionado);
            $stmt->execute();
            $stmt->close();
        }
        garantirProdutoNaTabela($conexao, $produtoIdSelecionado, $tabelaOrigem);
        echo json_encode([
            'ok' => true,
            'id' => $produtoIdSelecionado,
            'existente' => true,
            'nome' => (string) $produto['nome'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($tabelaOrigem === 'oba_embalado') {
        echo json_encode(['ok' => false, 'erro' => 'Cadastre novos produtos OBA em Editar > Produtos OBA para informar o produto principal vinculado.']);
        exit;
    }

    $tamanhoNome = function_exists('mb_strlen') ? mb_strlen($nome, 'UTF-8') : strlen($nome);
    if ($nome === '' || $tamanhoNome < 2) {
        echo json_encode(['ok' => false, 'erro' => 'Informe o nome do produto ou selecione um produto existente.']);
        exit;
    }

    if (!in_array($unidade, $unidadesPermitidas, true)) {
        echo json_encode(['ok' => false, 'erro' => 'Unidade comercial inválida.']);
        exit;
    }

    $colunasProdutos = [];
    $resColunas = $conexao->query('SHOW COLUMNS FROM produtos');
    if (!$resColunas) {
        throw new RuntimeException('Nao foi possivel ler a estrutura de produtos.');
    }
    while ($coluna = $resColunas->fetch_assoc()) {
        $colunasProdutos[$coluna['Field']] = $coluna;
    }

    $stmt = $conexao->prepare('SELECT id, ativo FROM produtos WHERE LOWER(TRIM(nome)) = LOWER(TRIM(?)) LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar busca do produto: ' . $conexao->error);
    }
    $stmt->bind_param('s', $nome);
    $stmt->execute();
    $existente = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existente) {
        $produtoId = (int) $existente['id'];
        if (array_key_exists('ativo', $colunasProdutos) && (int) ($existente['ativo'] ?? 1) !== 1) {
            $stmt = $conexao->prepare('UPDATE produtos SET ativo = 1, unidade = ? WHERE id = ?');
            if (!$stmt) {
                throw new RuntimeException('Falha ao preparar reativacao do produto: ' . $conexao->error);
            }
            $stmt->bind_param('si', $unidade, $produtoId);
            $stmt->execute();
            $stmt->close();
        }
        garantirProdutoNaTabela($conexao, $produtoId, $tabelaOrigem);
        echo json_encode(['ok' => true, 'id' => $produtoId, 'existente' => true]);
        exit;
    }

    $dados = [
        'nome' => $nome,
        'unidade' => $unidade,
        'ativo' => 1,
        'ncm' => '',
        'nfe_ncm' => '',
        'nfe_descricao' => $nome,
        'nfe_tipo' => 'Mercadoria para Revenda',
        'nfe_unidade' => $unidade,
        'nfe_origem' => '0',
    ];

    $campos = [];
    $placeholders = [];
    $tipos = '';
    $valores = [];

    foreach ($dados as $campo => $valor) {
        if (!array_key_exists($campo, $colunasProdutos)) {
            continue;
        }
        $campos[] = "`$campo`";
        $placeholders[] = '?';
        $tipos .= is_int($valor) ? 'i' : 's';
        $valores[] = $valor;
    }

    if (!in_array('`nome`', $campos, true) || !in_array('`unidade`', $campos, true)) {
        throw new RuntimeException('A tabela de produtos nao possui os campos obrigatorios.');
    }

    $sql = 'INSERT INTO produtos (' . implode(', ', $campos) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $conexao->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar cadastro do produto: ' . $conexao->error);
    }
    $stmt->bind_param($tipos, ...$valores);
    $stmt->execute();
    $produtoId = (int) $stmt->insert_id;
    $stmt->close();

    garantirProdutoNaTabela($conexao, $produtoId, $tabelaOrigem);

    echo json_encode(['ok' => true, 'id' => $produtoId, 'existente' => false]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}

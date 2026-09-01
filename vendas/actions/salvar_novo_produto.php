<?php
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../auth/proteger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array('erro' => 'Metodo invalido'));
    exit;
}

$tokenEnviado = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
$tokenSessao  = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
if (!$tokenEnviado || !$tokenSessao || !hash_equals($tokenSessao, $tokenEnviado)) {
    echo json_encode(array('erro' => 'Token invalido. Recarregue a pagina.'));
    exit;
}

$nome       = trim(isset($_POST['nome'])       ? $_POST['nome']       : '');
$unidade    = trim(isset($_POST['unidade'])    ? $_POST['unidade']    : 'kg');
$precoVenda = (float)(isset($_POST['preco_venda']) ? $_POST['preco_venda'] : 0);
$categoria  = trim(isset($_POST['categoria'])  ? $_POST['categoria']  : '');

if ($nome === '') {
    echo json_encode(array('erro' => 'Nome e obrigatorio.'));
    exit;
}

$unidadesValidas = array('kg', 'un', 'cx', 'bandeja', 'fardo');
if (!in_array($unidade, $unidadesValidas, true)) {
    $unidade = 'kg';
}

$stmt = $conexao->prepare(
    "INSERT INTO produtos
     (nome, unidade, preco_venda, categoria, ativo,
      nfe_codigo_barras, nfe_tipo_produto, nfe_descricao,
      nfe_preco_custo, nfe_preco_venda_varejo, nfe_preco_venda_atacado,
      nfe_quantidade_minima_atacado, nfe_unidade, nfe_ativo,
      nfe_categoria_produto, nfe_movimenta_estoque, nfe_estoque_minimo,
      nfe_quantidade_estoque, nfe_modelo, nfe_codigo_interno,
      nfe_tipo, nfe_ncm, nfe_origem, nfe_nome_loja_virtual,
      nfe_preco_de, nfe_preco_por, nfe_altura_cm,
      nfe_largura_cm, nfe_profundidade_cm, nfe_peso_kg)
     VALUES (?, ?, ?, ?, 1,
      '', '', '', '', '', '', '', '', '', '', '', '',
      '', '', '', '', '', '', '', '', '', '', '', '')"
);
$stmt->bind_param('ssds', $nome, $unidade, $precoVenda, $categoria);

if (!$stmt->execute()) {
    echo json_encode(array('erro' => 'Erro ao salvar: ' . $stmt->error));
    exit;
}

$novoId = (int) $conexao->insert_id;

echo json_encode(array(
    'sucesso' => true,
    'produto' => array(
        'id'      => $novoId,
        'nome'    => $nome,
        'unidade' => $unidade,
        'estoque' => 0.0
    )
));

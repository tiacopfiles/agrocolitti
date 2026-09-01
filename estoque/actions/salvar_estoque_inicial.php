<?php
require_once __DIR__ . "/../../config/conexao.php";
require_once __DIR__ . "/../../config/ciclo_helper.php";

/* ================================
   VERIFICAR SE VEIO POST
================================ */

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    die("Acesso inválido.");
}

/* ================================
   BUSCAR CICLO ATIVO
================================ */

$ciclo_id = getCicloAtivoId($conexao);

if(!$ciclo_id){
    die("Nenhum ciclo ativo encontrado.");
}

/* ================================
   VALIDAR DADOS DO FORMULÁRIO
================================ */

if(!isset($_POST['produto_id']) || !isset($_POST['quantidade'])){
    die("Dados incompletos.");
}

$produto_id = (int) $_POST['produto_id'];
$quantidade = (float) $_POST['quantidade'];

if($produto_id <= 0){
    die("Produto inválido.");
}

if($quantidade <= 0){
    die("Quantidade deve ser maior que zero.");
}

/* ================================
   VERIFICAR SE PRODUTO EXISTE
================================ */

$stmtProduto = $conexao->prepare("
SELECT id 
FROM produtos 
WHERE id = ? AND ativo = 1 AND produto_principal_id IS NULL
");

$stmtProduto->bind_param("i", $produto_id);
$stmtProduto->execute();

$resultProduto = $stmtProduto->get_result();

if($resultProduto->num_rows === 0){
    die("Produto não encontrado ou inativo.");
}

$stmtProduto->close();

/* ================================
   VERIFICAR SE JÁ EXISTE ESTOQUE
================================ */

$conexao->begin_transaction();

try {
$check = $conexao->prepare("
SELECT id 
FROM estoque_inicial
WHERE ciclo_id = ? 
AND produto_id = ?
");

$check->bind_param("ii", $ciclo_id, $produto_id);
$check->execute();

$result = $check->get_result();

if($result->num_rows > 0){

    /* ================================
       ATUALIZAR ESTOQUE
    ================================= */

    $stmt = $conexao->prepare("
    UPDATE estoque_inicial
    SET quantidade = ?
    WHERE ciclo_id = ? 
    AND produto_id = ?
    ");

    $stmt->bind_param("dii", $quantidade, $ciclo_id, $produto_id);
    $stmt->execute();

    $stmt->close();

}else{

    /* ================================
       INSERIR NOVO ESTOQUE
    ================================= */

    $stmt = $conexao->prepare("
    INSERT INTO estoque_inicial (ciclo_id, produto_id, quantidade)
    VALUES (?, ?, ?)
    ");

    $stmt->bind_param("iid", $ciclo_id, $produto_id, $quantidade);
    $stmt->execute();

    $stmt->close();
}

$check->close();

$conexao->commit();
} catch (Throwable $e) {
    $conexao->rollback();
    die("Não foi possível salvar o estoque inicial: " . $e->getMessage());
}

/* ================================
   REDIRECIONAR
================================ */

header("Location: ../estoque_inicial_aba.php");
exit();

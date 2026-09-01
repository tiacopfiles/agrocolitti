<?php
require_once(__DIR__ . "/../config/conexao.php");
require_once(__DIR__ . "/../config/ciclo_helper.php");
require_once(__DIR__ . "/../auth/proteger.php");

// Usa o helper central para sempre respeitar a mesma regra de ciclo ativo.
$ciclo = getCicloAtivo($conexao);

if (!$ciclo) {
    echo "Nenhum ciclo aberto.";
    exit;
}

$ciclo_id = (int) $ciclo['id'];

// Aqui você pode continuar seu código normalmente usando $conexao
?>

<?php
require __DIR__ . "/../../config/conexao.php";
require_once __DIR__ . "/../../config/ciclo_helper.php";
require __DIR__ . "/../../auth/proteger.php";

header("Content-Type: application/vnd.ms-excel; charset=Windows-1252");
header("Content-Disposition: attachment; filename=estoque_hortalicas.xls");

echo "Produto\tEstoque (kg)\n";

foreach (calcularEstoqueProdutosDoCiclo($conexao, getCicloAtivoId($conexao)) as $produto) {
    echo utf8_decode($produto['produto']) . "\t" . $produto['estoque'] . "\n";
}

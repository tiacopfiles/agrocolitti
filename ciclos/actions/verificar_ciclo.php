<?php
require_once __DIR__ . "/../../config/conexao.php";
require_once __DIR__ . "/../../config/ciclo_helper.php";

header('Content-Type: application/json; charset=utf-8');

$ciclo = getCicloAtivo($conexao);

echo json_encode([
    'sucesso' => $ciclo !== null,
    'ciclo' => $ciclo,
], JSON_UNESCAPED_UNICODE);


<?php
require_once __DIR__ . '/../bootstrap/conexao.php';
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../config/calculos_preco.php';
$cfg = getConfigPrecificacao($conexao);
foreach ($cfg as $k => $v) echo "$k = $v
";
@unlink(__FILE__);

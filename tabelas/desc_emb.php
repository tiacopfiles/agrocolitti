<?php
require "../config/conexao.php";
header("Content-Type: text/html; charset=utf-8");
$r = $conexao->query("DESCRIBE preco_embalado");
while ($row = $r->fetch_assoc()) echo $row['Field'] . ' | ' . $row['Type'] . "<br>";
?>

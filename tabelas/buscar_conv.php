<?php
require "../config/conexao.php";
header("Content-Type: text/html; charset=utf-8");

$nomes = [
    'ABT - BOA TERRA',
    'CARREFOUR CD',
    'CARREFOUR LOJA',
    'CONVENCIONAL',
    'DISTRIBUIDORA DE FRUTAS NK',
    'EMPORIO SÃO PAULO  - MHM / MM / HM',
    'FRUTARIA',
    'HORTISABOR',
    'JAB - BENAFRUTTI',
    'KAWAKAMI',
    'KORIN',
    'LUZITA',
    'MAMBO',
    'MERCANTIL - PATIO GOURMET',
    'OBA - GRUPO FARTURA',
    'PAGUE MENOS',
    'RH SUPERMERCADO - DONINE',
    'ROYAL CENTER e ROYAL PORÃO (KETEK)',
    'SACOLÃO',
    'SANTA LUZIA',
    'SHOPPER',
    'VAREJÃO VILA MARIANA - IMIGRANTES',
    'VEIO DA TERRA',
];

foreach ($nomes as $nome) {
    $n = $conexao->real_escape_string($nome);
    $r = $conexao->query("SELECT id, nome FROM clientes WHERE nome LIKE '%$n%' OR nome LIKE '%" . strtok($n, ' ') . "%' ORDER BY nome LIMIT 5");
    echo "<b>Buscando: $nome</b><br>";
    if ($r && $r->num_rows > 0) {
        while ($row = $r->fetch_assoc()) {
            echo " &nbsp; [{$row['id']}] {$row['nome']}<br>";
        }
    } else {
        echo " &nbsp; <span style='color:red'>NÃO ENCONTRADO</span><br>";
    }
    echo "<br>";
}
?>

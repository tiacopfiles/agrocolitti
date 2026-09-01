<?php
require __DIR__ . '/../../config/conexao.php';


$queries = [

    'estoque_inicial' => "
        SELECT
            e.ciclo_id,
            p.nome AS produto,
            e.quantidade
        FROM estoque_inicial e
        LEFT JOIN produtos p ON p.id = e.produto_id
    ",

    'produtos' => "SELECT * FROM produtos",

    'vendas' => "
        SELECT
            v.id,
            c.nome AS cliente,
            p.nome AS produto,
            v.gramagem,
            v.kg_caixa,
            v.pedido,
            v.quantidade,
            DATE_FORMAT(v.data_venda, '%d/%m/%Y') AS data_venda,
            v.status,
            v.foto_comprovante
        FROM vendas v
        LEFT JOIN produtos p ON p.id = v.produto_id
        LEFT JOIN clientes c ON c.id = v.cliente_id
    ",

    'previsao_colheita' => "
        SELECT
            p.nome AS produto,
            pc.quantidade_prevista,
            DATE_FORMAT(pc.data_prevista, '%d/%m/%Y') AS data_prevista,
            pc.observacao,
            pc.status
        FROM previsao_colheita pc
        LEFT JOIN produtos p ON p.id = pc.produto_id
    ",

    'previsao_fornecedor' => "SELECT * FROM previsao_fornecedor",

    'abates' => "SELECT * FROM abates",
];

$nome_arquivo = 'relatorios_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nome_arquivo . '"');
header('Cache-Control: no-cache');

ob_end_clean();

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

foreach ($queries as $tabela => $sql) {

    $resultado = $conexao->query($sql);
    if (!$resultado) continue;

    $linhas = [];
    while ($row = $resultado->fetch_assoc()) $linhas[] = $row;
    $resultado->free();

    if (empty($linhas)) continue;

    fputcsv($out, ["" . strtoupper($tabela) . ""], ';');

    $colunas = array_map(fn($col) => strtoupper(str_replace('_', ' ', $col)), array_keys($linhas[0]));
    fputcsv($out, $colunas, ';');

    foreach ($linhas as $linha) {
        fputcsv($out, $linha, ';');
    }

    fputcsv($out, [], ';');
}

fclose($out);
exit;
<?php
ob_start();

require '../auth/proteger.php';
require '../config/conexao.php';
require '../config/calculos_preco.php';
require '../config/permissions.php';
require '../config/dompdf_loader.php';

requireModule('tabelas', '../index.php');
carregarDompdfSeNecessario();

use Dompdf\Dompdf;
use Dompdf\Options;

$tipo = strtolower(trim((string) ($_GET['tipo'] ?? 'atacado')));
$tabelasPdf = [
    'atacado' => [
        'titulo' => 'TABELA ATACADO',
        'financeiro_tabela' => 'atacado',
        'headers' => ['PRODUTO', 'CAT', 'UNIDADE', 'KG DA CAIXA', 'DISPONIBILIDADE', 'PRAZO 5 DIAS', 'PRAZO 30 DIAS'],
    ],
    'atacado_convencional' => [
        'titulo' => 'TABELA ATACADO CONVENCIONAL',
        'financeiro_tabela' => 'convencional',
        'headers' => ['PRODUTO', 'CAT', 'UNIDADE', 'KG DA CAIXA', 'DISPONIBILIDADE', 'PRAZO 5 DIAS', 'PRAZO 30 DIAS'],
    ],
    'embalado' => [
        'titulo' => 'PREÇOS EMBALADOS',
        'financeiro_tabela' => 'embalado',
        'headers' => ['PRODUTO', 'CAT', 'UNIDADE', 'DISPONIBILIDADE', 'PREÇO DO PRODUTO'],
    ],
    'oba_embalado' => [
        'titulo' => 'OBA',
        'financeiro_tabela' => 'embalado',
        'headers' => ['PRODUTO', 'CAT', 'UNIDADE', 'DISPONIBILIDADE', 'PREÇO DO PRODUTO'],
    ],
    'shopper' => [
        'titulo' => 'SHOPPER',
        'financeiro_tabela' => 'atacado',
        'headers' => ['PRODUTO', 'CAT', 'UNIDADE', 'KG DA CAIXA', 'DISPONIBILIDADE', 'PRAZO 5 DIAS', 'PRAZO 30 DIAS'],
    ],
];

if (!isset($tabelasPdf[$tipo])) {
    http_response_code(400);
    exit('Tipo de tabela inválido.');
}

$cfg = getConfigPrecificacao($conexao);
$clienteId = (int) ($_GET['cliente_id'] ?? 0);
$acrescimoOba = $tipo === 'oba_embalado' && (int) ($_GET['acrescimo_oba'] ?? 0) === 1;

function hPdf(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function normalizarPercentualFinanceiroPdf(float $percentual): float
{
    $percentual = abs($percentual);
    return $percentual > 1 ? $percentual / 100 : $percentual;
}

function tabelaExistePdf(mysqli $conexao, string $tabela): bool
{
    $tabelaLike = $conexao->real_escape_string($tabela);
    $res = $conexao->query("SHOW TABLES LIKE '{$tabelaLike}'");
    return $res && $res->num_rows > 0;
}

function colunaExistePdf(mysqli $conexao, string $tabela, string $coluna): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabela)) {
        return false;
    }
    $colunaLike = $conexao->real_escape_string($coluna);
    $res = $conexao->query("SHOW COLUMNS FROM `{$tabela}` LIKE '{$colunaLike}'");
    return $res && $res->num_rows > 0;
}

function obterAjusteFinanceiroPdf(mysqli $conexao, int $clienteId, string $tabela): array
{
    if ($clienteId <= 0) {
        return ['percentual' => 0.0, 'cliente_nome' => ''];
    }

    $stmtCliente = $conexao->prepare("SELECT id, nome FROM clientes WHERE id = ? AND ativo = 1 LIMIT 1");
    $stmtCliente->bind_param('i', $clienteId);
    $stmtCliente->execute();
    $clienteAtivo = $stmtCliente->get_result()->fetch_assoc();
    $stmtCliente->close();
    if (!$clienteAtivo) {
        return ['percentual' => 0.0, 'cliente_nome' => ''];
    }
    $clienteNome = (string) ($clienteAtivo['nome'] ?? '');

    if (tabelaExistePdf($conexao, 'cliente_percentual_financeiro')) {
        $stmt = $conexao->prepare("
            SELECT percentual
            FROM cliente_percentual_financeiro
            WHERE cliente_id = ? AND tabela = ? AND percentual > 0
            LIMIT 1
        ");
        $stmt->bind_param('is', $clienteId, $tabela);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return [
                'percentual' => normalizarPercentualFinanceiroPdf((float) $row['percentual']),
                'cliente_nome' => $clienteNome,
            ];
        }
    }

    if (colunaExistePdf($conexao, 'clientes', 'desconto_financeiro')) {
        $stmt = $conexao->prepare("
            SELECT desconto_financeiro
            FROM clientes
            WHERE id = ? AND ativo = 1 AND desconto_financeiro > 0
            LIMIT 1
        ");
        $stmt->bind_param('i', $clienteId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return [
                'percentual' => normalizarPercentualFinanceiroPdf((float) $row['desconto_financeiro']),
                'cliente_nome' => $clienteNome,
            ];
        }
    }

    return ['percentual' => 0.0, 'cliente_nome' => $clienteNome];
}

function aplicarPercentualFinanceiroPdf(float $valor, float $percentual): float
{
    return round($valor * (1 + $percentual), 2);
}

function moedaPdf(float $value): string
{
    return 'R$ ' . number_format($value, 2, ',', '.');
}

function formatarNomeProdutoEmbaladoPdf(string $nome, float $gramagem): string
{
    $nome = trim($nome);
    if ($gramagem <= 0) {
        return $nome;
    }
    if (preg_match('/(^|\s)\d+(?:[.,]\d+)?\s*(?:kg|g|gr|grs|gramas?)\.?(?=\s|$)/iu', $nome) === 1) {
        return $nome;
    }
    if ($gramagem < 1) {
        $gramas = (int) round($gramagem * 1000);
        return $nome . ' ' . $gramas . 'g';
    }
    $gramagemTexto = number_format($gramagem, 3, ',', '.');
    $gramagemTexto = rtrim(rtrim($gramagemTexto, '0'), ',');
    return $nome . ' ' . $gramagemTexto . ' KG';
}

function nomeProdutoAtacadoSemGramagemPdf(string $nome): string
{
    $nome = trim($nome);
    $nome = preg_replace('/(^|\s)\d+(?:[.,]\d+)?\s*(?:kg|g|gr|grs|gramas?)\.?(?=\s|$)/iu', ' ', $nome);
    return trim(preg_replace('/\s+/', ' ', $nome));
}

function logoDataUriPdf(string $path): string
{
    if (!is_file($path)) {
        return '';
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        default => '',
    };
    if ($mime === '') {
        return '';
    }
    return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
}

function logoCellPdf(array $logo, int $total): string
{
    $uri = logoDataUriPdf((string) ($logo['path'] ?? ''));
    $cellStyle = 'width:' . number_format(100 / max(1, $total), 4, '.', '') . '%;';
    $imgStyle = 'max-width:' . (int) ($logo['maxWidth'] ?? 180) . 'px;'
        . 'max-height:' . (int) ($logo['maxHeight'] ?? 60) . 'px;'
        . 'width:auto;height:auto;';
    $content = $uri !== '' ? '<img src="' . hPdf($uri) . '" style="' . $imgStyle . '">' : '';
    return '<td style="' . $cellStyle . '">' . $content . '</td>';
}

$rows = [];
$ajusteFinanceiro = obterAjusteFinanceiroPdf($conexao, $clienteId, $tabelasPdf[$tipo]['financeiro_tabela']);
$percentualFinanceiro = (float) $ajusteFinanceiro['percentual'];
$clienteNomeExportacao = trim((string) $ajusteFinanceiro['cliente_nome']);
if ($acrescimoOba) {
    $percentualFinanceiro = 0.05;
}

if ($tipo === 'atacado' || $tipo === 'atacado_convencional') {
    $tabelaPreco = $tipo === 'atacado_convencional' ? 'preco_atacado_convencional' : 'preco_atacado';
    $campoFreteKauauti = $tipo === 'atacado_convencional' ? ', pa.frete_kauauti' : '';
    $campoUnidadeComercial = colunaExistePdf($conexao, $tabelaPreco, 'unidade_comercial')
        ? 'pa.unidade_comercial'
        : "'' AS unidade_comercial";
    $resultado = $conexao->query("
        SELECT p.id, p.nome, p.unidade,
               pa.categoria, pa.valor_mp, {$campoUnidadeComercial}, pa.kg_caixa, pa.frete_nivaldo{$campoFreteKauauti},
               CASE WHEN pa.valor_mp > 0 THEN COALESCE(pa.ativo, 1) ELSE 0 END AS disponivel
        FROM produtos p
        LEFT JOIN {$tabelaPreco} pa ON pa.produto_id = p.id
        WHERE p.ativo = 1
          AND p.produto_principal_id IS NULL
          AND pa.valor_mp > 0
          AND COALESCE(pa.ativo, 1) = 1
        ORDER BY p.nome
    ");

    while ($p = $resultado->fetch_assoc()) {
        $valorMp = (float) ($p['valor_mp'] ?? 0);
        $kgCaixa = (float) ($p['kg_caixa'] ?? 20);
        $freteNivaldo = (float) ($p['frete_nivaldo'] ?? $cfg['frete_nivaldo_atacado_padrao']);
        if ($tipo === 'atacado_convencional') {
            $freteKauauti = (float) ($p['frete_kauauti'] ?? 0);
            $calc = ($valorMp > 0 || $freteKauauti > 0) ? calcAtacadoComFreteKauauti($valorMp, $freteKauauti, $freteNivaldo, $cfg) : null;
        } else {
            $calc = ($valorMp > 0 || $kgCaixa > 0) ? calcAtacado($valorMp, $kgCaixa, $freteNivaldo, $cfg) : null;
        }
        $prazo5 = $calc ? aplicarPercentualFinanceiroPdf((float) $calc['prazo_5_dias'], $percentualFinanceiro) : 0.0;
        $prazo30 = $calc ? aplicarPercentualFinanceiroPdf((float) $calc['prazo_30_dias'], $percentualFinanceiro) : 0.0;
        if ($prazo5 <= 0 && $prazo30 <= 0) {
            continue;
        }
        $rows[] = [
            $tipo === 'atacado' ? nomeProdutoAtacadoSemGramagemPdf((string) $p['nome']) : (string) $p['nome'],
            (string) ($p['categoria'] ?? ''),
            (string) (($p['unidade_comercial'] ?? '') !== '' ? $p['unidade_comercial'] : ($p['unidade'] ?? 'kg')),
            (string) (float) ($p['kg_caixa'] ?? 0),
            ((int) ($p['disponivel'] ?? 1)) ? 'Disponível' : 'Indisponível',
            $calc ? moedaPdf($prazo5) : '-',
            $calc ? moedaPdf($prazo30) : '-',
        ];
    }
} elseif ($tipo === 'embalado') {
    $resultado = $conexao->query("
        SELECT p.id, p.nome,
               pe.gramagem, pe.valor_mp, pe.qtd_por_caixa, pe.categoria,
               CASE WHEN pe.gramagem > 0 AND pe.valor_mp > 0 THEN COALESCE(pe.ativo, 1) ELSE 0 END AS disponivel
        FROM produtos p
        LEFT JOIN preco_embalado pe ON pe.produto_id = p.id
        WHERE p.ativo = 1
          AND (p.produto_principal_id IS NULL OR pe.produto_id IS NOT NULL)
          AND pe.gramagem > 0
          AND pe.valor_mp > 0
          AND COALESCE(pe.ativo, 1) = 1
        ORDER BY p.nome
    ");

    while ($p = $resultado->fetch_assoc()) {
        $gramagem = (float) ($p['gramagem'] ?? 0);
        $valorMp = (float) ($p['valor_mp'] ?? 0);
        $qtdCaixa = (float) ($p['qtd_por_caixa'] ?? 0);
        $calc = ($gramagem > 0 && $valorMp > 0) ? calcEmbalado($gramagem, $valorMp, $qtdCaixa, $cfg) : null;
        $precoProduto = $calc ? (float) $calc['preco_produto_base'] : 0.0;
        $precoProduto = $precoProduto > 0 ? aplicarPercentualFinanceiroPdf($precoProduto, $percentualFinanceiro) : 0.0;
        if ($precoProduto <= 0) {
            continue;
        }
        $rows[] = [
            formatarNomeProdutoEmbaladoPdf((string) $p['nome'], $gramagem),
            (string) ($p['categoria'] ?? ''),
            $qtdCaixa > 0 ? number_format($qtdCaixa, 0, ',', '.') : '-',
            ((int) ($p['disponivel'] ?? 1)) ? 'Disponível' : 'Indisponível',
            $precoProduto > 0 ? moedaPdf($precoProduto) : '-',
        ];
    }
} elseif ($tipo === 'oba_embalado') {
    $resultado = $conexao->query("
        SELECT p.id, p.nome,
               po.gramagem, po.valor_mp, po.qtd_por_caixa, po.categoria, po.frete_kauauti, po.frete_nivaldo,
               CASE WHEN po.gramagem > 0 AND po.valor_mp > 0 THEN COALESCE(po.ativo, 1) ELSE 0 END AS disponivel
        FROM produtos p
        LEFT JOIN preco_oba_embalado po ON po.produto_id = p.id
        WHERE p.ativo = 1
          AND COALESCE(p.escopo_produto, 'normal') IN ('oba', 'ambos')
          AND (p.escopo_produto = 'oba' OR p.produto_principal_id IS NULL)
          AND po.gramagem > 0
          AND po.valor_mp > 0
          AND COALESCE(po.ativo, 1) = 1
        ORDER BY p.nome
    ");

    while ($p = $resultado->fetch_assoc()) {
        $gramagem = (float) ($p['gramagem'] ?? 0);
        $valorMp = (float) ($p['valor_mp'] ?? 0);
        $qtdCaixa = (float) ($p['qtd_por_caixa'] ?? 0);
        $calc = ($gramagem > 0 && $valorMp > 0) ? calcEmbalado($gramagem, $valorMp, $qtdCaixa, $cfg) : null;
        if ($calc) {
            $calc['preco_produto_base'] = round((2 * $calc['valor_materia_x_gramagem']) + (float) ($p['frete_kauauti'] ?? 0) + (float) ($p['frete_nivaldo'] ?? 0) + $calc['custo_fixo_embalado'], 2);
        }
        $precoProduto = $calc ? (float) $calc['preco_produto_base'] : 0.0;
        $precoProduto = $precoProduto > 0 ? aplicarPercentualFinanceiroPdf($precoProduto, $percentualFinanceiro) : 0.0;
        if ($precoProduto <= 0) {
            continue;
        }
        $rows[] = [
            formatarNomeProdutoEmbaladoPdf((string) $p['nome'], $gramagem),
            (string) ($p['categoria'] ?? ''),
            $qtdCaixa > 0 ? number_format($qtdCaixa, 0, ',', '.') : '-',
            ((int) ($p['disponivel'] ?? 1)) ? 'Disponível' : 'Indisponível',
            $precoProduto > 0 ? moedaPdf($precoProduto) : '-',
        ];
    }
} else {
    if (colunaExistePdf($conexao, 'preco_shopper', 'unidade_comercial')) {
        $campoUnidadeComercial = 'ps.unidade_comercial';
    } elseif (colunaExistePdf($conexao, 'preco_shopper', 'unidade')) {
        $campoUnidadeComercial = 'ps.unidade AS unidade_comercial';
    } else {
        $campoUnidadeComercial = "'' AS unidade_comercial";
    }
    $resultado = $conexao->query("
        SELECT p.id, p.nome, p.unidade,
               ps.categoria, ps.gramagem, {$campoUnidadeComercial}, ps.kg_caixa, ps.prazo_5_dias, ps.prazo_30_dias,
               CASE WHEN ps.prazo_5_dias > 0 OR ps.prazo_30_dias > 0 THEN COALESCE(ps.ativo, 1) ELSE 0 END AS disponivel
        FROM produtos p
        LEFT JOIN preco_shopper ps ON ps.produto_id = p.id
        WHERE p.ativo = 1
          AND (p.produto_principal_id IS NULL OR ps.produto_id IS NOT NULL)
          AND (ps.prazo_5_dias > 0 OR ps.prazo_30_dias > 0)
          AND COALESCE(ps.ativo, 1) = 1
        ORDER BY p.nome
    ");

    while ($p = $resultado->fetch_assoc()) {
        $gramagem = (float) ($p['gramagem'] ?? 0);
        $prazo5 = (float) ($p['prazo_5_dias'] ?? 0);
        $prazo30 = (float) ($p['prazo_30_dias'] ?? 0);
        $prazo5 = $prazo5 > 0 ? aplicarPercentualFinanceiroPdf($prazo5, $percentualFinanceiro) : 0.0;
        $prazo30 = $prazo30 > 0 ? aplicarPercentualFinanceiroPdf($prazo30, $percentualFinanceiro) : 0.0;
        if ($prazo5 <= 0 && $prazo30 <= 0) {
            continue;
        }
        $rows[] = [
            formatarNomeProdutoEmbaladoPdf((string) $p['nome'], $gramagem),
            (string) ($p['categoria'] ?? ''),
            (string) (($p['unidade_comercial'] ?? '') !== '' ? $p['unidade_comercial'] : ($p['unidade'] ?? 'kg')),
            (string) (float) ($p['kg_caixa'] ?? 0),
            ((int) ($p['disponivel'] ?? 1)) ? 'Disponível' : 'Indisponível',
            $prazo5 > 0 ? moedaPdf($prazo5) : '-',
            $prazo30 > 0 ? moedaPdf($prazo30) : '-',
        ];
    }
}

$headersFinal = $tabelasPdf[$tipo]['headers'];
$colIdxRemover = array_search('DISPONIBILIDADE', $headersFinal, true);
if ($colIdxRemover !== false) {
    array_splice($headersFinal, $colIdxRemover, 1);
    foreach ($rows as $rIdx => $rowVals) {
        array_splice($rows[$rIdx], $colIdxRemover, 1);
    }
}

$title = 'TABELA DE DISPONIBILIDADE - ' . ($clienteNomeExportacao !== '' && $percentualFinanceiro > 0 ? $clienteNomeExportacao . ' - ' : '') . date('d/m/Y');
$subtitle = in_array($tipo, ['embalado', 'oba_embalado'], true) ? 'UNIDADE: quantidade por caixa' : '';
$logosOrganico = [
    ['path' => __DIR__ . '/../assets/img/disponibilidade/pdf_organico_colitti.jpg', 'maxWidth' => 126, 'maxHeight' => 66],
    ['path' => __DIR__ . '/../assets/img/disponibilidade/pdf_organico_portfolio.jpg', 'maxWidth' => 112, 'maxHeight' => 66],
    ['path' => __DIR__ . '/../assets/img/disponibilidade/pdf_organico_brasil.jpg', 'maxWidth' => 170, 'maxHeight' => 66],
];
$logosPorTipo = [
    'atacado' => $logosOrganico,
    'shopper' => $logosOrganico,
    'embalado' => [
        ['path' => __DIR__ . '/../assets/img/disponibilidade/pdf_embalado_colitti.jpg', 'maxWidth' => 134, 'maxHeight' => 66],
        ['path' => __DIR__ . '/../assets/img/disponibilidade/pdf_organico_portfolio.jpg', 'maxWidth' => 112, 'maxHeight' => 66],
        ['path' => __DIR__ . '/../assets/img/disponibilidade/pdf_organico_brasil.jpg', 'maxWidth' => 170, 'maxHeight' => 66],
    ],
    'oba_embalado' => [
        ['path' => __DIR__ . '/../assets/img/disponibilidade/pdf_organico_portfolio.jpg', 'maxWidth' => 112, 'maxHeight' => 66],
        ['path' => __DIR__ . '/../assets/img/disponibilidade/pdf_embalado_colitti.jpg', 'maxWidth' => 134, 'maxHeight' => 66],
        ['path' => __DIR__ . '/../assets/img/disponibilidade/pdf_organico_brasil.jpg', 'maxWidth' => 170, 'maxHeight' => 66],
    ],
    'atacado_convencional' => [
        ['path' => __DIR__ . '/../assets/img/agrocolittilogo.png', 'maxWidth' => 220, 'maxHeight' => 82],
    ],
];
$logos = $logosPorTipo[$tipo] ?? $logosOrganico;

$logosHtml = '';
foreach ($logos as $logo) {
    $logosHtml .= logoCellPdf($logo, count($logos));
}

$headHtml = '';
foreach ($headersFinal as $header) {
    $headHtml .= '<th>' . hPdf($header) . '</th>';
}

$rowsHtml = '';
foreach ($rows as $row) {
    $rowsHtml .= '<tr>';
    foreach ($row as $cell) {
        $rowsHtml .= '<td>' . hPdf((string) $cell) . '</td>';
    }
    $rowsHtml .= '</tr>';
}

$html = '<!doctype html><html><head><meta charset="UTF-8"><style>
@page { margin: 18px 16px; }
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color: #111; }
.logos { width: 100%; border-collapse: collapse; margin-bottom: 8px; table-layout: fixed; }
.logos td { text-align: center; vertical-align: middle; height: 70px; border: 0; padding: 0 8px; }
.logos.convencional td { height: 86px; }
h1 { font-size: 13px; text-align: center; margin: 6px 0 4px; }
.subtitle { text-align: center; font-size: 9px; margin-bottom: 8px; }
table.dados { width: 100%; border-collapse: collapse; }
.dados th { background: #d9ead3; border: 1px solid #333; padding: 5px 4px; text-align: center; font-weight: 700; }
.dados td { border: 1px solid #333; padding: 4px; vertical-align: top; text-align: center; }
.dados td:first-child { text-align: left; }
</style></head><body>'
    . '<table class="logos ' . ($tipo === 'atacado_convencional' ? 'convencional' : '') . '"><tr>' . $logosHtml . '</tr></table>'
    . '<h1>' . hPdf($title) . '</h1>'
    . ($subtitle !== '' ? '<div class="subtitle">' . hPdf($subtitle) . '</div>' : '')
    . '<table class="dados"><thead><tr>' . $headHtml . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table>'
    . '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'tabela_disponibilidade_' . $tipo . '_' . date('Ymd') . '.pdf';
while (ob_get_level() > 0) {
    ob_end_clean();
}
$dompdf->stream($filename, ['Attachment' => true]);
exit;

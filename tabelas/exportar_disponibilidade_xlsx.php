<?php
ob_start();

require '../auth/proteger.php';
require '../config/conexao.php';
require '../config/calculos_preco.php';
require '../config/permissions.php';

requireModule('tabelas', '../index.php');

$tipo = strtolower(trim((string) ($_GET['tipo'] ?? 'atacado')));
$tabelasExcel = [
    'atacado' => [
        'sheet' => 'TABELA ATACADO',
        'financeiro_tabela' => 'atacado',
        'headers' => ['PRODUTO', 'CAT', 'UNIDADE', 'KG DA CAIXA', 'DISPONIBILIDADE', 'PRAZO 5 DIAS', 'PRAZO 30 DIAS'],
    ],
    'atacado_convencional' => [
        'sheet' => 'TABELA ATACADO CONVENCIONAL',
        'financeiro_tabela' => 'convencional',
        'headers' => ['PRODUTO', 'CAT', 'UNIDADE', 'KG DA CAIXA', 'DISPONIBILIDADE', 'PRAZO 5 DIAS', 'PRAZO 30 DIAS'],
    ],
    'embalado' => [
        'sheet' => 'PREÇOS EMBALADOS',
        'financeiro_tabela' => 'embalado',
        'headers' => ['PRODUTO', 'CAT', 'UNIDADE', 'DISPONIBILIDADE', 'PREÇO DO PRODUTO'],
    ],
    'oba_embalado' => [
        'sheet' => 'Oba',
        'financeiro_tabela' => 'embalado',
        'headers' => ['PRODUTO', 'CAT', 'UNIDADE', 'DISPONIBILIDADE', 'PREÇO DO PRODUTO'],
    ],
    'shopper' => [
        'sheet' => 'SHOPPER',
        'financeiro_tabela' => 'atacado',
        'headers' => ['PRODUTO', 'CAT', 'UNIDADE', 'KG DA CAIXA', 'DISPONIBILIDADE', 'PRAZO 5 DIAS', 'PRAZO 30 DIAS'],
    ],
];

if (!isset($tabelasExcel[$tipo])) {
    http_response_code(400);
    exit('Tipo de tabela inválido.');
}

$cfg = getConfigPrecificacao($conexao);
$clienteId = (int) ($_GET['cliente_id'] ?? 0);
$acrescimoOba = $tipo === 'oba_embalado' && (int) ($_GET['acrescimo_oba'] ?? 0) === 1;

function normalizarPercentualFinanceiroExportacao(float $percentual): float
{
    $percentual = abs($percentual);
    return $percentual > 1 ? $percentual / 100 : $percentual;
}

function tabelaExisteExportacao(mysqli $conexao, string $tabela): bool
{
    $tabelaLike = $conexao->real_escape_string($tabela);
    $res = $conexao->query("SHOW TABLES LIKE '{$tabelaLike}'");
    return $res && $res->num_rows > 0;
}

function colunaExisteExportacao(mysqli $conexao, string $tabela, string $coluna): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabela)) {
        return false;
    }
    $colunaLike = $conexao->real_escape_string($coluna);
    $res = $conexao->query("SHOW COLUMNS FROM `{$tabela}` LIKE '{$colunaLike}'");
    return $res && $res->num_rows > 0;
}

function obterAjusteFinanceiroExportacao(mysqli $conexao, int $clienteId, string $tabela): array
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

    if (tabelaExisteExportacao($conexao, 'cliente_percentual_financeiro')) {
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
                'percentual' => normalizarPercentualFinanceiroExportacao((float) $row['percentual']),
                'cliente_nome' => $clienteNome,
            ];
        }
    }

    if (colunaExisteExportacao($conexao, 'clientes', 'desconto_financeiro')) {
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
                'percentual' => normalizarPercentualFinanceiroExportacao((float) $row['desconto_financeiro']),
                'cliente_nome' => $clienteNome,
            ];
        }
    }

    return ['percentual' => 0.0, 'cliente_nome' => $clienteNome];
}

function aplicarPercentualFinanceiroExportacao(float $valor, float $percentual): float
{
    return round($valor * (1 + $percentual), 2);
}

function xlsxText(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function xlsxMoney(float $value): string
{
    return 'R$ ' . number_format($value, 2, ',', '.');
}

function formatarNomeProdutoEmbaladoExportacao(string $nome, float $gramagem): string
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

function nomeProdutoAtacadoSemGramagemExportacao(string $nome): string
{
    $nome = trim($nome);
    $nome = preg_replace('/(^|\s)\d+(?:[.,]\d+)?\s*(?:kg|g|gr|grs|gramas?)\.?(?=\s|$)/iu', ' ', $nome);
    return trim(preg_replace('/\s+/', ' ', $nome));
}

function xlsxCell(string $ref, string $value, int $style = 0): string
{
    $styleAttr = $style > 0 ? ' s="' . $style . '"' : '';
    return '<c r="' . $ref . '" t="inlineStr"' . $styleAttr . '><is><t>' . xlsxText($value) . '</t></is></c>';
}

function xlsxRow(int $rowNumber, array $values, int $style = 0): string
{
    $letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];
    $cells = '';
    foreach ($values as $idx => $value) {
        $cells .= xlsxCell($letters[$idx] . $rowNumber, (string) $value, $style);
    }
    return '<row r="' . $rowNumber . '">' . $cells . '</row>';
}

function xlsxImageMime(string $path): ?array
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
        'png' => ['ext' => 'png', 'contentType' => 'image/png'],
        'jpg', 'jpeg' => ['ext' => 'jpeg', 'contentType' => 'image/jpeg'],
        default => null,
    };
}

function xlsxDrawingAnchor(int $col, int $row, string $relId, string $name, int $cx = 1320000, int $cy = 500000, int $colOff = 60000): string
{
    return '<xdr:oneCellAnchor>'
        . '<xdr:from><xdr:col>' . $col . '</xdr:col><xdr:colOff>' . $colOff . '</xdr:colOff><xdr:row>' . $row . '</xdr:row><xdr:rowOff>25000</xdr:rowOff></xdr:from>'
        . '<xdr:ext cx="' . $cx . '" cy="' . $cy . '"/>'
        . '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="' . ($col + 2) . '" name="' . xlsxText($name) . '"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
        . '<xdr:blipFill><a:blip r:embed="' . $relId . '"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
        . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic>'
        . '<xdr:clientData/></xdr:oneCellAnchor>';
}

function buildDisponibilidadeXlsx(string $title, ?string $subtitle, array $rows, array $headers, string $sheetName, ?array $logoCandidates = null, ?array $columnWidths = null): string
{
    $logoCandidates ??= [
        ['path' => __DIR__ . '/../assets/img/disponibilidade/organico_colitti.png', 'name' => 'Colitti Organicos', 'col' => 0],
        ['path' => __DIR__ . '/../assets/img/disponibilidade/organico_portfolio.png', 'name' => 'Portfolio Organicos Colitti', 'col' => 2],
        ['path' => __DIR__ . '/../assets/img/disponibilidade/organico_brasil.png', 'name' => 'Produto Organico Brasil', 'col' => 4],
    ];
    $logos = [];
    foreach ($logoCandidates as $candidate) {
        $mime = is_file($candidate['path']) ? xlsxImageMime($candidate['path']) : null;
        if ($mime) {
            $candidate += $mime;
            $logos[] = $candidate;
        }
    }

    $logoRowHeight = 46;
    foreach ($logos as $logo) {
        $logoRowHeight = max($logoRowHeight, (int) ($logo['rowHeight'] ?? 46));
    }

    $sheetRows = [];
    $sheetRows[] = '<row r="1" ht="' . $logoRowHeight . '" customHeight="1"></row>';
    $sheetRows[] = xlsxRow(2, [$logos ? '' : 'ORGÂNICOS COLITTI', '', '', '', $logos ? '' : 'PRODUTO ORGÂNICO BRASIL', ''], 3);
    $sheetRows[] = xlsxRow(3, [$title, '', '', '', '', ''], 1);
    $nextRow = 4;

    if ($subtitle) {
        $sheetRows[] = xlsxRow($nextRow, [$subtitle, '', '', '', '', ''], 4);
        $nextRow++;
    }

    $sheetRows[] = xlsxRow($nextRow, $headers, 2);
    $nextRow++;

    foreach ($rows as $row) {
        $sheetRows[] = xlsxRow($nextRow, $row, 5);
        $nextRow++;
    }

    $mergeSubtitle = $subtitle ? '<mergeCell ref="A4:F4"/>' : '';
    $mergeCount = $subtitle ? 5 : 4;
    $drawingNode = $logos ? '<drawing r:id="rId1"/>' : '';
    $columnWidths ??= [34, 14, 14, 16, 16, 16];
    $colsXml = '<cols>';
    foreach (array_values($columnWidths) as $idx => $width) {
        $col = $idx + 1;
        $colsXml .= '<col min="' . $col . '" max="' . $col . '" width="' . (float) $width . '" customWidth="1"/>';
    }
    $colsXml .= '</cols>';
    $worksheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . $colsXml
        . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
        . '<mergeCells count="' . $mergeCount . '"><mergeCell ref="A1:B1"/><mergeCell ref="C1:D1"/><mergeCell ref="E1:F1"/><mergeCell ref="A3:F3"/>' . $mergeSubtitle . '</mergeCells>'
        . '<pageMargins left="0.25" right="0.25" top="0.4" bottom="0.4" header="0.2" footer="0.2"/>'
        . $drawingNode
        . '</worksheet>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><sz val="10"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFC6E0B4"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FF333333"/></left><right style="thin"><color rgb="FF333333"/></right><top style="thin"><color rgb="FF333333"/></top><bottom style="thin"><color rgb="FF333333"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="6">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Não foi possível gerar o XLSX.');
    }

    $drawingContentType = $logos ? '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '';
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Default Extension="jpeg" ContentType="image/jpeg"/><Default Extension="jpg" ContentType="image/jpeg"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $drawingContentType . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . xlsxText($sheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $worksheet);
    if ($logos) {
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>');
        $anchors = [];
        $rels = [];
        foreach ($logos as $idx => $logo) {
            $relId = 'rId' . ($idx + 1);
            $mediaName = 'logo' . ($idx + 1) . '.' . $logo['ext'];
            $anchors[] = xlsxDrawingAnchor(
                (int) $logo['col'],
                0,
                $relId,
                $logo['name'],
                (int) ($logo['cx'] ?? 1320000),
                (int) ($logo['cy'] ?? 500000),
                (int) ($logo['colOff'] ?? 60000)
            );
            $rels[] = '<Relationship Id="' . $relId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/' . $mediaName . '"/>';
            $zip->addFile($logo['path'], 'xl/media/' . $mediaName);
        }
        $zip->addFromString('xl/drawings/drawing1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . implode('', $anchors) . '</xdr:wsDr>');
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . implode('', $rels) . '</Relationships>');
    }
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>' . xlsxText($title) . '</dc:title></cp:coreProperties>');
    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>AgroColitti</Application></Properties>');
    $zip->close();

    return $tmp;
}

$rows = [];
$ajusteFinanceiro = obterAjusteFinanceiroExportacao($conexao, $clienteId, $tabelasExcel[$tipo]['financeiro_tabela']);
$percentualFinanceiro = (float) $ajusteFinanceiro['percentual'];
$clienteNomeExportacao = trim((string) $ajusteFinanceiro['cliente_nome']);
if ($acrescimoOba) {
    $percentualFinanceiro = 0.05;
}

if ($tipo === 'atacado' || $tipo === 'atacado_convencional') {
    $tabelaPreco = $tipo === 'atacado_convencional' ? 'preco_atacado_convencional' : 'preco_atacado';
    $campoFreteKauauti = $tipo === 'atacado_convencional' ? ', pa.frete_kauauti' : '';
    $campoUnidadeComercial = colunaExisteExportacao($conexao, $tabelaPreco, 'unidade_comercial')
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
        $prazo5 = $calc ? aplicarPercentualFinanceiroExportacao((float) $calc['prazo_5_dias'], $percentualFinanceiro) : 0.0;
        $prazo30 = $calc ? aplicarPercentualFinanceiroExportacao((float) $calc['prazo_30_dias'], $percentualFinanceiro) : 0.0;
        if ($prazo5 <= 0 && $prazo30 <= 0) {
            continue;
        }
        $rows[] = [
            $tipo === 'atacado' ? nomeProdutoAtacadoSemGramagemExportacao((string) $p['nome']) : (string) $p['nome'],
            (string) ($p['categoria'] ?? ''),
            (string) (($p['unidade_comercial'] ?? '') !== '' ? $p['unidade_comercial'] : ($p['unidade'] ?? 'kg')),
            (float) ($p['kg_caixa'] ?? 0),
            ((int) ($p['disponivel'] ?? 1)) ? 'Disponível' : 'Indisponível',
            $calc ? xlsxMoney($prazo5) : '-',
            $calc ? xlsxMoney($prazo30) : '-',
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
        $precoProduto = $precoProduto > 0 ? aplicarPercentualFinanceiroExportacao($precoProduto, $percentualFinanceiro) : 0.0;
        if ($precoProduto <= 0) {
            continue;
        }
        $rows[] = [
            formatarNomeProdutoEmbaladoExportacao((string) $p['nome'], $gramagem),
            (string) ($p['categoria'] ?? ''),
            $qtdCaixa > 0 ? number_format($qtdCaixa, 0, ',', '.') : '-',
            ((int) ($p['disponivel'] ?? 1)) ? 'Disponível' : 'Indisponível',
            $precoProduto > 0 ? xlsxMoney($precoProduto) : '-',
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
        $precoProduto = $precoProduto > 0 ? aplicarPercentualFinanceiroExportacao($precoProduto, $percentualFinanceiro) : 0.0;
        if ($precoProduto <= 0) {
            continue;
        }
        $rows[] = [
            formatarNomeProdutoEmbaladoExportacao((string) $p['nome'], $gramagem),
            (string) ($p['categoria'] ?? ''),
            $qtdCaixa > 0 ? number_format($qtdCaixa, 0, ',', '.') : '-',
            ((int) ($p['disponivel'] ?? 1)) ? 'Disponível' : 'Indisponível',
            $precoProduto > 0 ? xlsxMoney($precoProduto) : '-',
        ];
    }
} else {
    if (colunaExisteExportacao($conexao, 'preco_shopper', 'unidade_comercial')) {
        $campoUnidadeComercial = 'ps.unidade_comercial';
    } elseif (colunaExisteExportacao($conexao, 'preco_shopper', 'unidade')) {
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
        $prazo5 = $prazo5 > 0 ? aplicarPercentualFinanceiroExportacao($prazo5, $percentualFinanceiro) : 0.0;
        $prazo30 = $prazo30 > 0 ? aplicarPercentualFinanceiroExportacao($prazo30, $percentualFinanceiro) : 0.0;
        if ($prazo5 <= 0 && $prazo30 <= 0) {
            continue;
        }
        $rows[] = [
            formatarNomeProdutoEmbaladoExportacao((string) $p['nome'], $gramagem),
            (string) ($p['categoria'] ?? ''),
            (string) (($p['unidade_comercial'] ?? '') !== '' ? $p['unidade_comercial'] : ($p['unidade'] ?? 'kg')),
            (float) ($p['kg_caixa'] ?? 0),
            ((int) ($p['disponivel'] ?? 1)) ? 'Disponível' : 'Indisponível',
            $prazo5 > 0 ? xlsxMoney($prazo5) : '-',
            $prazo30 > 0 ? xlsxMoney($prazo30) : '-',
        ];
    }
}

// Remocao da coluna DISPONIBILIDADE (todas as tabelas)
$headersFinal = $tabelasExcel[$tipo]['headers'];
$colIdxRemover = array_search('DISPONIBILIDADE', $headersFinal);
if ($colIdxRemover !== false) {
    array_splice($headersFinal, $colIdxRemover, 1);
    foreach ($rows as $rIdx => $rowVals) {
        array_splice($rows[$rIdx], $colIdxRemover, 1);
    }
}

$title = 'TABELA DE DISPONIBILIDADE - ' . ($clienteNomeExportacao !== '' && $percentualFinanceiro > 0 ? $clienteNomeExportacao . ' - ' : '') . date('d/m/Y');
$subtitle = in_array($tipo, ['embalado', 'oba_embalado'], true) ? 'UNIDADE: quantidade por caixa' : null;
$logosOrganico = [
    ['path' => __DIR__ . '/../assets/img/disponibilidade/organico_colitti.png', 'name' => 'Colitti Organicos', 'col' => 0, 'cx' => 1025000, 'cy' => 750000, 'colOff' => 900000, 'rowHeight' => 60],
    ['path' => __DIR__ . '/../assets/img/disponibilidade/organico_portfolio.png', 'name' => 'Portfolio Organicos Colitti', 'col' => 2, 'cx' => 866000, 'cy' => 750000, 'colOff' => 760000, 'rowHeight' => 60],
    ['path' => __DIR__ . '/../assets/img/disponibilidade/organico_brasil.png', 'name' => 'Produto Organico Brasil', 'col' => 4, 'cx' => 1500000, 'cy' => 750000, 'colOff' => 260000, 'rowHeight' => 60],
];
$logosPorTipo = [
    'atacado' => $logosOrganico,
    'shopper' => $logosOrganico,
    'embalado' => [
        ['path' => __DIR__ . '/../assets/img/disponibilidade/embalado_colitti.png', 'name' => 'Colitti Organicos', 'col' => 0, 'cx' => 1135000, 'cy' => 750000, 'colOff' => 840000, 'rowHeight' => 60],
        ['path' => __DIR__ . '/../assets/img/disponibilidade/organico_portfolio.png', 'name' => 'Portfolio Organicos Colitti', 'col' => 2, 'cx' => 866000, 'cy' => 750000, 'colOff' => 760000, 'rowHeight' => 60],
        ['path' => __DIR__ . '/../assets/img/disponibilidade/organico_brasil.png', 'name' => 'Produto Organico Brasil', 'col' => 4, 'cx' => 1500000, 'cy' => 750000, 'colOff' => 260000, 'rowHeight' => 60],
    ],
    'oba_embalado' => [
        ['path' => __DIR__ . '/../assets/img/disponibilidade/organico_portfolio.png', 'name' => 'Portfolio Organicos Colitti', 'col' => 0, 'cx' => 866000, 'cy' => 750000, 'colOff' => 1415000, 'rowHeight' => 60],
        ['path' => __DIR__ . '/../assets/img/disponibilidade/embalado_colitti.png', 'name' => 'Colitti Organicos', 'col' => 2, 'cx' => 1135000, 'cy' => 750000, 'colOff' => 1014000, 'rowHeight' => 60],
        ['path' => __DIR__ . '/../assets/img/disponibilidade/organico_brasil.png', 'name' => 'Produto Organico Brasil', 'col' => 4, 'cx' => 1500000, 'cy' => 750000, 'colOff' => 364000, 'rowHeight' => 60],
    ],
    'atacado_convencional' => [
        ['path' => __DIR__ . '/../assets/img/agrocolittilogo.png', 'name' => 'Grupo AgroColitti', 'col' => 2, 'cx' => 1700000, 'cy' => 820000, 'colOff' => 650000, 'rowHeight' => 66],
    ],
];
$largurasColunasPorTipo = [
    'atacado' => [34, 14, 14, 14, 16, 16],
    'atacado_convencional' => [48, 18, 18, 18, 22, 22],
    'embalado' => [38, 16, 18, 28, 16, 16],
    'oba_embalado' => [38, 16, 18, 28, 16, 16],
];
$tmpFile = buildDisponibilidadeXlsx(
    $title,
    $subtitle,
    $rows,
    $headersFinal,
    $tabelasExcel[$tipo]['sheet'],
    $logosPorTipo[$tipo] ?? $logosOrganico,
    $largurasColunasPorTipo[$tipo] ?? null
);

$filename = 'tabela_disponibilidade_' . $tipo . '_' . date('Ymd') . '.xlsx';
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: max-age=0');
readfile($tmpFile);
unlink($tmpFile);
exit;






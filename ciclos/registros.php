<?php
require_once __DIR__ . "/../config/conexao.php";
require_once __DIR__ . "/../config/ciclo_helper.php";
require_once __DIR__ . "/../auth/proteger.php";
require_once __DIR__ . "/../config/permissions.php";
require_once __DIR__ . "/../config/layout_helper.php";

requireModule('ciclos', '../index.php');

$tipoRegistro = $tipoRegistro ?? ($_GET['tipo'] ?? 'vendas');
$configuracoes = [
    'vendas' => [
        'titulo' => 'Vendas do ciclo',
        'voltar_prefixo' => '..',
        'alias' => 'v',
        'tabela' => 'vendas',
        'data_coluna' => 'v.data_venda',
        'status_coluna' => 'v.status',
        'tipo_coluna' => null,
        'tipo_opcoes' => [],
        'os_coluna' => 'v.numero_os',
        'from' => "vendas v
            LEFT JOIN produtos p ON p.id = v.produto_id
            LEFT JOIN clientes c ON c.id = v.cliente_id",
        'select' => "v.id, v.produto_id, v.numero_os, v.data_venda AS data_registro, p.nome AS produto_nome,
            c.nome AS pessoa_nome, v.tipo, v.quantidade, v.preco, v.status",
        'headers' => ['OS', 'Data', 'Produto', 'Cliente', 'Tipo', 'Quantidade', 'Preco', 'Status'],
    ],
    'entradas' => [
        'titulo' => 'Entradas do ciclo',
        'voltar_prefixo' => '..',
        'alias' => 'e',
        'tabela' => 'entradas',
        'data_coluna' => 'e.data_entrada',
        'status_coluna' => null,
        'tipo_coluna' => 'e.tipo',
        'tipo_opcoes' => [
            'colheita' => 'Colheita',
            'entrada_fornecedor' => 'Entrada fornecedor',
        ],
        'os_coluna' => 'e.numero_os',
        'from' => "entradas e
            LEFT JOIN produtos p ON p.id = e.produto_id
            LEFT JOIN fornecedores f ON f.id = e.fornecedor_id",
        'select' => "e.id, e.numero_os, e.data_entrada AS data_registro, p.nome AS produto_nome,
            f.nome AS pessoa_nome, e.tipo, e.quantidade, NULL AS preco, NULL AS status",
        'headers' => ['OS', 'Data', 'Produto', 'Fornecedor', 'Tipo', 'Quantidade'],
    ],
    'colheitas' => [
        'titulo' => 'Colheitas do ciclo',
        'voltar_prefixo' => '..',
        'alias' => 'pc',
        'tabela' => 'previsao_colheita',
        'data_coluna' => 'pc.data_prevista',
        'status_coluna' => 'pc.status',
        'tipo_coluna' => null,
        'tipo_opcoes' => [],
        'os_coluna' => "'Colheita'",
        'from' => "previsao_colheita pc
            LEFT JOIN produtos p ON p.id = pc.produto_id",
        'select' => "pc.id, NULL AS numero_os, pc.data_prevista AS data_registro, p.nome AS produto_nome,
            pc.meieiro AS pessoa_nome, 'colheita' AS tipo, pc.quantidade_prevista AS quantidade,
            NULL AS preco, pc.status",
        'headers' => ['OS', 'Data', 'Produto', 'Meieiro', 'Tipo', 'Quantidade'],
    ],
    'abates' => [
        'titulo' => 'Abates do ciclo',
        'voltar_prefixo' => '..',
        'alias' => 'a',
        'tabela' => 'abates',
        'data_coluna' => 'a.criado_em',
        'status_coluna' => null,
        'tipo_coluna' => 'a.tipo',
        'tipo_opcoes' => [
            'fornecedor' => 'Fornecedor',
            'colheita' => 'Colheita',
        ],
        'os_coluna' => 'COALESCE({OS_FORNECEDOR}, {OS_COLHEITA})',
        'from' => "abates a
            LEFT JOIN previsao_fornecedor pf ON a.tipo = 'fornecedor' AND pf.id = a.previsao_id
            LEFT JOIN previsao_colheita pc ON a.tipo = 'colheita' AND pc.id = a.previsao_id
            LEFT JOIN produtos p ON p.id = COALESCE(pf.produto_id, pc.produto_id)",
        'select' => "a.id, COALESCE({OS_FORNECEDOR}, {OS_COLHEITA}) AS numero_os,
            a.criado_em AS data_registro, p.nome AS produto_nome, a.tipo AS pessoa_nome,
            a.tipo, a.quantidade_abatida AS quantidade, NULL AS preco, NULL AS status,
            a.quantidade_original, a.quantidade_final, a.motivo",
        'headers' => ['OS', 'Data', 'Produto', 'Origem', 'Qtd. original', 'Qtd. abatida', 'Qtd. final', 'Motivo'],
    ],
];

if (!isset($configuracoes[$tipoRegistro])) {
    header('Location: detalhe.php?msg=tipo_invalido');
    exit;
}

$cfg = $configuracoes[$tipoRegistro];
$cicloId = (int) ($_GET['ciclo_id'] ?? $_GET['id'] ?? 0);
if ($cicloId <= 0) {
    header('Location: index.php?msg=erro&detalhe=Ciclo+invalido');
    exit;
}

$stmtCiclo = $conexao->prepare("SELECT * FROM ciclos WHERE id = ? LIMIT 1");
$stmtCiclo->bind_param('i', $cicloId);
$stmtCiclo->execute();
$ciclo = $stmtCiclo->get_result()->fetch_assoc();
$stmtCiclo->close();

if (!$ciclo) {
    header('Location: index.php?msg=erro&detalhe=Ciclo+nao+encontrado');
    exit;
}

$produtosFiltroVendas = [];
if ($tipoRegistro === 'vendas') {
    $resProdutosFiltro = $conexao->query("\n        SELECT p.id, p.nome, pp.nome AS produto_principal_nome\n        FROM produtos p\n        LEFT JOIN produtos pp ON pp.id = p.produto_principal_id\n        ORDER BY COALESCE(pp.nome, p.nome), p.produto_principal_id IS NOT NULL, p.nome\n    ");
    if ($resProdutosFiltro) {
        while ($produtoFiltro = $resProdutosFiltro->fetch_assoc()) {
            $produtosFiltroVendas[] = $produtoFiltro;
        }
    }
}

$osFornecedor = colunaExiste($conexao, 'previsao_fornecedor', 'numero_os') ? 'pf.numero_os' : 'NULL';
$osColheita = colunaExiste($conexao, 'previsao_colheita', 'numero_os') ? 'pc.numero_os' : 'NULL';
foreach (['from', 'select', 'os_coluna'] as $campoSql) {
    $cfg[$campoSql] = str_replace(
        ['{OS_FORNECEDOR}', '{OS_COLHEITA}'],
        [$osFornecedor, $osColheita],
        $cfg[$campoSql]
    );
}

$buscaOs = trim((string) ($_GET['os'] ?? ''));
$dataInicio = trim((string) ($_GET['data_inicio'] ?? ''));
$dataFim = trim((string) ($_GET['data_fim'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$origem = trim((string) ($_GET['origem'] ?? ''));
$produtoId = $tipoRegistro === 'vendas' ? max(0, (int) ($_GET['produto_id'] ?? 0)) : 0;
$visao = $tipoRegistro === 'vendas' ? trim((string) ($_GET['visao'] ?? 'registros')) : 'registros';
$visaoTotaisProdutos = $tipoRegistro === 'vendas' && $visao === 'totais_produtos';
if ($visaoTotaisProdutos && $status === '') {
    // "Vendido/saida" representa somente operacoes efetivamente concluidas.
    $status = 'concluido';
}
$pagina = max(1, (int) ($_GET['page'] ?? 1));
$porPagina = 50;
$offset = ($pagina - 1) * $porPagina;

$where = ['1 = 1'];
$params = [];
$types = '';
$clausulaCiclo = montarClausulaCiclo($conexao, $cfg['tabela'], $cfg['alias'], $cicloId);
if ($clausulaCiclo !== '') {
    $where[] = preg_replace('/^\s*AND\s+/i', '', trim($clausulaCiclo));
} else {
    // Tabelas legadas, especialmente vendas, ainda nao possuem ciclo_id.
    // Nelas a competencia mensal e delimitada pela data do registro.
    $inicioCiclo = sprintf('%04d-%02d-01 00:00:00', (int) $ciclo['ano'], (int) $ciclo['mes']);
    $fimCiclo = (new DateTimeImmutable($inicioCiclo))
        ->modify('first day of next month')
        ->format('Y-m-d H:i:s');
    $where[] = $cfg['data_coluna'] . ' >= ?';
    $params[] = $inicioCiclo;
    $types .= 's';
    $where[] = $cfg['data_coluna'] . ' < ?';
    $params[] = $fimCiclo;
    $types .= 's';
}
if ($produtoId > 0) {
    $where[] = 'v.produto_id = ?';
    $params[] = $produtoId;
    $types .= 'i';
}
if ($buscaOs !== '') {
    $where[] = $cfg['os_coluna'] . ' LIKE ?';
    $params[] = '%' . $buscaOs . '%';
    $types .= 's';
}
if ($dataInicio !== '') {
    $where[] = $cfg['data_coluna'] . ' >= ?';
    $params[] = $dataInicio . ' 00:00:00';
    $types .= 's';
}
if ($dataFim !== '') {
    $where[] = $cfg['data_coluna'] . ' <= ?';
    $params[] = $dataFim . ' 23:59:59';
    $types .= 's';
}
if ($status !== '' && $cfg['status_coluna']) {
    $where[] = $cfg['status_coluna'] . ' = ?';
    $params[] = $status;
    $types .= 's';
}
if ($origem !== '' && $cfg['tipo_coluna'] && isset($cfg['tipo_opcoes'][$origem])) {
    $where[] = $cfg['tipo_coluna'] . ' = ?';
    $params[] = $origem;
    $types .= 's';
}

$whereSql = implode(' AND ', $where);
$sqlBase = "FROM {$cfg['from']} WHERE {$whereSql}";

$stmtTotal = $conexao->prepare("SELECT COUNT(*) AS total {$sqlBase}");
if ($types !== '') {
    $stmtTotal->bind_param($types, ...$params);
}
$stmtTotal->execute();
$totalRegistros = (int) ($stmtTotal->get_result()->fetch_assoc()['total'] ?? 0);
$stmtTotal->close();

// LAYOUT NOVO (UX/UI): ordenação do mais novo para o mais velho (data desc).
$sqlLista = "SELECT {$cfg['select']} {$sqlBase} ORDER BY {$cfg['data_coluna']} DESC, {$cfg['alias']}.id DESC";
$stmtLista = $conexao->prepare($sqlLista);
if ($types !== '') {
    $stmtLista->bind_param($types, ...$params);
}
$stmtLista->execute();
$registros = $stmtLista->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtLista->close();

// Ciclo fechado usa o snapshot imutavel como fonte autoritativa.
if (($ciclo['status'] ?? '') === 'fechado') {
    $registros = [];
}

// Registros removidos pelo "Zerar contagem" continuam no mesmo ciclo, no arquivo.
// Para a auditoria não parecer vazia, eles são exibidos junto do operacional.
$tiposArquivo = [
    'vendas' => 'venda',
    'entradas' => 'entrada',
    'colheitas' => 'previsao_colheita',
    'abates' => 'abate',
];
$tipoArquivo = $tiposArquivo[$tipoRegistro] ?? null;
if ($tipoArquivo !== null) {
    $whereArquivo = ["ciclo_id = ?", "tipo = ?", "acao_fechamento = 'arquivar_zerar'"];
    $paramsArquivo = [$cicloId, $tipoArquivo];
    $typesArquivo = 'is';
    if ($produtoId > 0) {
        $whereArquivo[] = 'produto_id = ?';
        $paramsArquivo[] = $produtoId;
        $typesArquivo .= 'i';
    }
    if ($buscaOs !== '') {
        $whereArquivo[] = 'numero_os LIKE ?';
        $paramsArquivo[] = '%' . $buscaOs . '%';
        $typesArquivo .= 's';
    }
    if ($dataInicio !== '') {
        $whereArquivo[] = 'data_registro >= ?';
        $paramsArquivo[] = $dataInicio . ' 00:00:00';
        $typesArquivo .= 's';
    }
    if ($dataFim !== '') {
        $whereArquivo[] = 'data_registro <= ?';
        $paramsArquivo[] = $dataFim . ' 23:59:59';
        $typesArquivo .= 's';
    }
    if ($status !== '' && $cfg['status_coluna']) {
        $whereArquivo[] = 'status = ?';
        $paramsArquivo[] = $status;
        $typesArquivo .= 's';
    }
    if ($origem !== '' && $cfg['tipo_coluna'] && isset($cfg['tipo_opcoes'][$origem])) {
        $whereArquivo[] = "JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.tipo')) = ?";
        $paramsArquivo[] = $origem;
        $typesArquivo .= 's';
    }
    if (($ciclo['status'] ?? '') !== 'fechado') {
        // No ciclo ativo, o operacional prevalece. O snapshot entra apenas
        // como recuperacao de um registro que nao existe mais na origem.
        $whereArquivo[] = "NOT EXISTS (
            SELECT 1 FROM {$cfg['tabela']} origem_operacional
            WHERE origem_operacional.id = ciclo_snapshot_registros.origem_id
        )";
    }

    $sqlArquivo = "
        SELECT origem_id AS id, produto_id, numero_os, data_registro, produto_nome, pessoa_nome,
               COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.tipo')), tipo) AS tipo,
               quantidade, valor AS preco, status
        FROM ciclo_snapshot_registros
        WHERE " . implode(' AND ', $whereArquivo);
    $stmtArquivo = $conexao->prepare($sqlArquivo);
    $stmtArquivo->bind_param($typesArquivo, ...$paramsArquivo);
    $stmtArquivo->execute();
    $registrosArquivo = $stmtArquivo->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtArquivo->close();
    $registros = array_merge($registros, $registrosArquivo);
}

usort($registros, static function (array $a, array $b): int {
    $dataA = (string) ($a['data_registro'] ?? '');
    $dataB = (string) ($b['data_registro'] ?? '');
    if ($dataA !== $dataB) {
        return strcmp($dataB, $dataA);
    }
    return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
});

$mapaProdutosFiltro = [];
foreach ($produtosFiltroVendas as $produtoFiltro) {
    $mapaProdutosFiltro[(int) $produtoFiltro['id']] = $produtoFiltro;
}
$totaisPorProduto = [];
$totalGeralProdutos = 0.0;
if ($visaoTotaisProdutos) {
    foreach ($registros as $registro) {
        $id = (int) ($registro['produto_id'] ?? 0);
        $nome = trim((string) ($registro['produto_nome'] ?? ''));
        $chave = $id > 0 ? 'id:' . $id : 'nome:' . mb_strtolower($nome ?: 'produto sem identificacao');
        if (!isset($totaisPorProduto[$chave])) {
            $cadastro = $id > 0 ? ($mapaProdutosFiltro[$id] ?? null) : null;
            $totaisPorProduto[$chave] = [
                'produto_id' => $id,
                'produto' => $nome !== '' ? $nome : 'Produto sem identificação',
                'produto_principal' => (string) ($cadastro['produto_principal_nome'] ?? ''),
                'quantidade' => 0.0,
            ];
        }
        $quantidade = (float) ($registro['quantidade'] ?? 0);
        $totaisPorProduto[$chave]['quantidade'] += $quantidade;
        $totalGeralProdutos += $quantidade;
    }
    $totaisPorProduto = array_values($totaisPorProduto);
    usort($totaisPorProduto, static fn(array $a, array $b): int =>
        strnatcasecmp((string) $a['produto'], (string) $b['produto'])
    );
}
$totalSaidasProduto = null;
if ($tipoRegistro === 'vendas' && $produtoId > 0) {
    $totalSaidasProduto = array_sum(array_map(
        static fn(array $registro): float => (float) ($registro['quantidade'] ?? 0),
        $registros
    ));
}
$totalRegistros = count($registros);
$registros = array_slice($registros, $offset, $porPagina);

$totalPaginas = max(1, (int) ceil($totalRegistros / $porPagina));
$queryBase = [
    'ciclo_id' => $cicloId,
    'os' => $buscaOs,
    'data_inicio' => $dataInicio,
    'data_fim' => $dataFim,
    'status' => $status,
    'origem' => $origem,
    'produto_id' => $produtoId > 0 ? $produtoId : '',
    'visao' => $visaoTotaisProdutos ? 'totais_produtos' : '',
];

function cicloUrlPagina(array $queryBase, int $pagina): string
{
    $queryBase['page'] = $pagina;
    return '?' . http_build_query(array_filter($queryBase, static fn($v) => $v !== '' && $v !== null));
}

// === LAYOUT NOVO (UX/UI): preparação de apresentação ==========================
// Apenas camada visual. Sem query/filtro novo — deriva do que já foi calculado.

// Link "voltar ao ciclo" robusto: '../detalhe.php' quando incluído por uma
// subpasta (vendas/entradas/abates), 'detalhe.php' quando acessado direto.
$detalheHref = isset($cicloHeaderPrefix) ? '../detalhe.php' : 'detalhe.php';

// Chips de filtros ativos (cada um remove só o seu parâmetro).
$mkChipUrl = static function (array $base, string $remover): string {
    unset($base[$remover]);
    $base = array_filter($base, static fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($base);
};
$chipsAtivos = [];
if ($buscaOs !== '') {
    $chipsAtivos[] = ['label' => 'OS: ' . $buscaOs, 'url' => $mkChipUrl($queryBase, 'os')];
}
if ($dataInicio !== '') {
    $chipsAtivos[] = ['label' => 'De ' . $dataInicio, 'url' => $mkChipUrl($queryBase, 'data_inicio')];
}
if ($dataFim !== '') {
    $chipsAtivos[] = ['label' => 'Até ' . $dataFim, 'url' => $mkChipUrl($queryBase, 'data_fim')];
}
if ($status !== '') {
    $chipsAtivos[] = ['label' => 'Status: ' . ucfirst($status), 'url' => $mkChipUrl($queryBase, 'status')];
}
if ($origem !== '') {
    $chipsAtivos[] = ['label' => 'Origem: ' . ($cfg['tipo_opcoes'][$origem] ?? $origem), 'url' => $mkChipUrl($queryBase, 'origem')];
}
if ($produtoId > 0) {
    $nomeProdutoSelecionado = 'Produto #' . $produtoId;
    foreach ($produtosFiltroVendas as $produtoFiltro) {
        if ((int) $produtoFiltro['id'] === $produtoId) {
            $nomeProdutoSelecionado = (string) $produtoFiltro['nome'];
            break;
        }
    }
    $chipsAtivos[] = ['label' => 'Produto: ' . $nomeProdutoSelecionado, 'url' => $mkChipUrl($queryBase, 'produto_id')];
}

// Soma da página atual (apenas dos registros já carregados em $registros).
$somaQtdPagina = 0.0;
$somaValorPagina = 0.0;
foreach ($registros as $linhaSoma) {
    $somaQtdPagina += (float) ($linhaSoma['quantidade'] ?? 0);
    $somaValorPagina += (float) ($linhaSoma['preco'] ?? 0);
}

// Rótulo e cor do tipo de registro exibido.
$tipoMeta = [
    'vendas'   => ['rotulo' => 'Vendas',   'cor' => '#2e7d32', 'icon' => 'cart-check'],
    'entradas' => ['rotulo' => 'Entradas', 'cor' => '#a05a2c', 'icon' => 'box-arrow-in-down'],
    'colheitas' => ['rotulo' => 'Colheitas', 'cor' => '#3f9357', 'icon' => 'basket'],
    'abates'   => ['rotulo' => 'Abates',   'cor' => '#a93b2e', 'icon' => 'exclamation-triangle'],
][$tipoRegistro] ?? ['rotulo' => ucfirst($tipoRegistro), 'cor' => '#2e7d32', 'icon' => 'list-check'];
// === FIM da preparação ========================================================
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($cfg['titulo']) ?></title>
    <style>
        /* ===== LAYOUT NOVO (UX/UI) — escopo .cic-reg; paleta ORIGINAL do sistema ===== */
        .cic-reg{
            --g50:#f1f8f4;--g100:#dcefe1;--g200:#a5d6a7;--g300:#8cc79e;
            --g600:#2e7d32;--g700:#1f6d23;--g800:#1b5e20;
            --ink-1:#222;--ink-2:#3a3f3a;--ink-3:#5F5E5A;--ink-4:#9ba29a;
            --line-1:#e2e5df;--line-2:#eef0ec;--surf:#fff;--surf-2:#fbfcfb;
            --sh-1:0 1px 2px rgba(20,24,20,.05),0 1px 1px rgba(20,24,20,.03);
            --sh-2:0 2px 8px rgba(20,24,20,.07);
            --r:12px;--ease:cubic-bezier(.2,0,0,1);
            max-width:1320px;margin:0 auto;color:var(--ink-1);font-family:'Segoe UI',Arial,sans-serif;font-size:14px;
        }
        .cic-reg *{box-sizing:border-box;}
        .cic-reg h1,.cic-reg h3,.cic-reg p{margin:0;}

        .cd-back{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--ink-3);cursor:pointer;text-decoration:none;margin-bottom:14px;}
        .cd-back:hover{color:var(--g700);}

        .cd-head{margin-bottom:18px;}
        .cd-eyebrow{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-3);}
        .cd-head h1{font-size:28px;font-weight:700;letter-spacing:-.01em;margin-top:6px;}
        .cd-sub{display:flex;align-items:center;gap:10px;margin-top:6px;color:var(--ink-3);font-size:14px;flex-wrap:wrap;}
        .cd-typechip{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;background:var(--g50);border:1px solid var(--g200);color:var(--g800);}

        .cd-card{background:var(--surf);border:1px solid var(--line-1);border-radius:var(--r);box-shadow:var(--sh-1);overflow:hidden;margin-bottom:18px;}

        /* toolbar de filtros */
        .cd-filters{display:grid;grid-template-columns:minmax(180px,1.4fr) repeat(4,minmax(150px,1fr)) auto auto;gap:12px;align-items:end;padding:18px 20px;}
        .cd-field{display:flex;flex-direction:column;gap:6px;}
        .cd-field label{font-size:11px;color:var(--ink-3);font-weight:700;letter-spacing:.04em;text-transform:uppercase;}
        .cd-field input,.cd-field select{border:1px solid var(--line-1);border-radius:8px;padding:9px 11px;font-size:14px;min-height:38px;background:var(--surf);color:var(--ink-1);}
        .cd-field input:focus,.cd-field select:focus{outline:none;border-color:var(--g300);box-shadow:0 0 0 3px rgba(46,125,50,.14);}
        .cd-btn{border:1px solid var(--line-1);border-radius:8px;padding:0 16px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:38px;background:var(--surf);color:var(--ink-1);transition:background .15s var(--ease);}
        .cd-btn:hover{background:#f4f6f4;}
        .cd-btn-primary{background:var(--g800);border-color:var(--g800);color:#fff;}
        .cd-btn-primary:hover{background:var(--g700);}

        /* chips de filtros ativos */
        .cd-chips{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:12px 20px;border-top:1px solid var(--line-2);background:var(--surf-2);}
        .cd-chips .lbl{font-size:12px;color:var(--ink-3);}
        .cd-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 6px 4px 12px;border-radius:999px;font-size:12px;font-weight:600;background:var(--g50);border:1px solid var(--g200);color:var(--g800);text-decoration:none;}
        .cd-chip .x{width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:var(--g700);font-size:10px;}
        .cd-chip:hover .x{background:var(--g100);}
        .cd-total-produto{padding:12px 20px;border-top:1px solid var(--line-2);background:var(--g50);font-size:13px;color:var(--ink-2);}
        .cd-total-produto strong{color:var(--g800);font-variant-numeric:tabular-nums;}
        .cd-resumo-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:16px 20px;border-bottom:1px solid var(--line-1);}
        .cd-resumo-head h2{font-size:16px;margin:0;color:var(--ink-1);}
        .cd-resumo-head p{font-size:12px;color:var(--ink-3);margin-top:3px;}
        .cd-resumo-total{font-size:13px;color:var(--ink-2);}
        .cd-resumo-total strong{font-size:18px;color:var(--g800);font-variant-numeric:tabular-nums;}
        .cd-vinculo{font-size:12px;color:var(--ink-3);}

        /* tabela */
        .cd-tablewrap{overflow-x:auto;max-height:560px;overflow-y:auto;}
        .cic-reg table{width:100%;min-width:860px;border-collapse:collapse;font-size:13px;}
        .cic-reg thead th{position:sticky;top:0;z-index:1;background:#f6f7f5;color:var(--ink-2);text-align:left;font-weight:600;font-size:11px;letter-spacing:.06em;text-transform:uppercase;padding:11px 16px;border-bottom:1px solid var(--line-1);}
        .cic-reg tbody td{padding:11px 16px;border-bottom:1px solid var(--line-2);text-align:left;}
        .cic-reg tbody tr:hover td{background:var(--g50);}
        .os-cell{font-weight:700;color:var(--g800);font-variant-numeric:tabular-nums;}
        .num{font-variant-numeric:tabular-nums;}
        .empty{color:var(--ink-3);font-style:italic;text-align:center;padding:28px;}
        .st-pill{display:inline-flex;align-items:center;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:600;border:1px solid var(--line-1);background:var(--line-2);color:var(--ink-2);text-transform:capitalize;}
        .st-ok{background:#e8f5e9;border-color:#a5d6a7;color:#2e7d32;}
        .st-warn{background:#fff8e1;border-color:#ffe082;color:#b45309;}

        /* rodapé: soma da página + paginação */
        .cd-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 20px;}
        .cd-foot .info{font-size:13px;color:var(--ink-3);}
        .cd-foot .totais{font-size:13px;color:var(--ink-2);font-weight:600;}
        .cd-foot .totais b{color:var(--g800);font-variant-numeric:tabular-nums;}
        .cd-pag{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
        .cd-pag .pg{font-size:12px;color:var(--ink-3);margin-right:6px;}
        .cd-btn[aria-disabled="true"]{opacity:.45;pointer-events:none;}

        @media (max-width:900px){.cd-filters{grid-template-columns:1fr;}}
    </style>
    <?php renderAppLayoutStyles(); ?>
</head>
<body>
    <?php renderAppHeader($cicloHeaderPrefix ?? '..'); ?>
    <div class="container page-container app-shell">
    <!-- LAYOUT NOVO (UX/UI): wrapper de escopo dos registros -->
    <div class="cic-reg">

        <a class="cd-back" href="<?= htmlspecialchars($detalheHref) ?>?id=<?= (int) $cicloId ?>"><i class="bi bi-arrow-left"></i>Voltar ao ciclo</a>

        <!-- LAYOUT NOVO (UX/UI): cabeçalho -->
        <div class="cd-head">
            <span class="cd-eyebrow">Auditoria</span>
            <h1><?= htmlspecialchars($cfg['titulo']) ?></h1>
            <div class="cd-sub">
                <span><?= htmlspecialchars(getNomeCiclo($ciclo)) ?></span>
                <span class="cd-typechip"><i class="bi bi-<?= $tipoMeta['icon'] ?>" style="color:<?= $tipoMeta['cor'] ?>;"></i><?= htmlspecialchars($tipoMeta['rotulo']) ?></span>
            </div>
        </div>

        <!-- LAYOUT NOVO (UX/UI): toolbar de filtros (mesmos campos/nomes) + chips -->
        <div class="cd-card">
            <form class="cd-filters" method="GET">
                <input type="hidden" name="ciclo_id" value="<?= (int) $cicloId ?>">
                <?php if ($tipoRegistro === 'vendas'): ?>
                    <div class="cd-field">
                        <label>Visualização</label>
                        <select name="visao">
                            <option value="registros" <?= !$visaoTotaisProdutos ? 'selected' : '' ?>>Registros detalhados</option>
                            <option value="totais_produtos" <?= $visaoTotaisProdutos ? 'selected' : '' ?>>Totais por produto</option>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="cd-field">
                    <label>Buscar por OS</label>
                    <input type="text" name="os" value="<?= htmlspecialchars($buscaOs) ?>" placeholder="Número da OS">
                </div>
                <?php if ($tipoRegistro === 'vendas'): ?>
                    <div class="cd-field">
                        <label>Produto</label>
                        <select name="produto_id">
                            <option value="">Todos os produtos</option>
                            <?php foreach ($produtosFiltroVendas as $produtoFiltro): ?>
                                <?php $rotuloProduto = (string) $produtoFiltro['nome']; ?>
                                <?php if (!empty($produtoFiltro['produto_principal_nome'])) $rotuloProduto .= ' (vinculado a ' . $produtoFiltro['produto_principal_nome'] . ')'; ?>
                                <option value="<?= (int) $produtoFiltro['id'] ?>" <?= $produtoId === (int) $produtoFiltro['id'] ? 'selected' : '' ?>><?= htmlspecialchars($rotuloProduto) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="cd-field">
                    <label>Data inicial</label>
                    <input type="date" name="data_inicio" value="<?= htmlspecialchars($dataInicio) ?>">
                </div>
                <div class="cd-field">
                    <label>Data final</label>
                    <input type="date" name="data_fim" value="<?= htmlspecialchars($dataFim) ?>">
                </div>
                <?php if ($cfg['status_coluna']): ?>
                    <div class="cd-field">
                        <label>Status</label>
                        <select name="status">
                            <option value="">Todos</option>
                            <?php foreach (['anexado', 'pendente', 'concluido'] as $opcao): ?>
                                <option value="<?= $opcao ?>" <?= $status === $opcao ? 'selected' : '' ?>><?= ucfirst($opcao) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <div class="cd-field">
                        <label>Status</label>
                        <select disabled><option>Indisponível</option></select>
                    </div>
                <?php endif; ?>
                <?php if (!empty($cfg['tipo_opcoes'])): ?>
                    <div class="cd-field">
                        <label>Origem</label>
                        <select name="origem">
                            <option value="">Todas</option>
                            <?php foreach ($cfg['tipo_opcoes'] as $valor => $rotulo): ?>
                                <option value="<?= htmlspecialchars($valor) ?>" <?= $origem === $valor ? 'selected' : '' ?>><?= htmlspecialchars($rotulo) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <div class="cd-field">
                        <label>Origem</label>
                        <select disabled><option>Indisponível</option></select>
                    </div>
                <?php endif; ?>
                <button class="cd-btn cd-btn-primary" type="submit"><i class="bi bi-funnel"></i>Filtrar</button>
                <a class="cd-btn" href="?ciclo_id=<?= (int) $cicloId ?>"><i class="bi bi-x-circle"></i>Limpar</a>
            </form>

            <?php if (!empty($chipsAtivos)): ?>
                <div class="cd-chips">
                    <span class="lbl">Filtros ativos:</span>
                    <?php foreach ($chipsAtivos as $chip): ?>
                        <a class="cd-chip" href="<?= htmlspecialchars($chip['url']) ?>"><?= htmlspecialchars($chip['label']) ?><span class="x"><i class="bi bi-x-lg"></i></span></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($totalSaidasProduto !== null && !$visaoTotaisProdutos): ?>
                <div class="cd-total-produto">Total de saídas de <strong><?= htmlspecialchars($nomeProdutoSelecionado) ?></strong> no período filtrado: <strong><?= number_format($totalSaidasProduto, 2, ',', '.') ?> kg</strong></div>
            <?php endif; ?>
        </div>

        <?php if ($visaoTotaisProdutos): ?>
        <div class="cd-card">
            <div class="cd-resumo-head">
                <div>
                    <h2>Totais vendidos por produto</h2>
                    <p>Status e período conforme os filtros, sem duplicar registros preservados no snapshot.</p>
                </div>
                <div class="cd-resumo-total">Total geral: <strong><?= number_format($totalGeralProdutos, 2, ',', '.') ?> kg</strong></div>
            </div>
            <div class="cd-tablewrap">
                <table data-no-responsive="1">
                    <thead>
                        <tr>
                            <th>Produto</th>
                            <th>Vínculo</th>
                            <th>Total vendido</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($totaisPorProduto): ?>
                            <?php foreach ($totaisPorProduto as $totalProduto): ?>
                                <tr>
                                    <td><?= htmlspecialchars($totalProduto['produto']) ?></td>
                                    <td class="cd-vinculo"><?= $totalProduto['produto_principal'] !== '' ? 'Vinculado a ' . htmlspecialchars($totalProduto['produto_principal']) : 'Produto principal' ?></td>
                                    <td class="num"><strong><?= number_format((float) $totalProduto['quantidade'], 2, ',', '.') ?> kg</strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="empty">Nenhuma venda concluída encontrada com estes filtros.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="cd-foot" style="border-top:1px solid var(--line-2);">
                <span class="info"><?= count($totaisPorProduto) ?> produto(s) encontrado(s)</span>
                <span class="totais">Total do período: <b><?= number_format($totalGeralProdutos, 2, ',', '.') ?> kg</b></span>
            </div>
        </div>
        <?php else: ?>
        <!-- LAYOUT NOVO (UX/UI): tabela com header fixo (mesmos dados/colunas) -->
        <div class="cd-card">
            <div class="cd-tablewrap">
                <table data-no-responsive="1">
                    <thead>
                        <tr>
                            <?php foreach ($cfg['headers'] as $header): ?>
                                <th><?= htmlspecialchars($header) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($registros)): ?>
                            <?php foreach ($registros as $linha): ?>
                                <?php
                                    $numeroOsExibicao = trim((string) ($linha['numero_os'] ?? ''));
                                    if ($numeroOsExibicao === '') {
                                        if (($linha['tipo'] ?? '') === 'colheita') {
                                            $numeroOsExibicao = 'Colheita';
                                        } elseif (($linha['tipo'] ?? '') === 'entrada_fornecedor') {
                                            $numeroOsExibicao = 'Entrada fornecedor sem OS';
                                        } else {
                                            $numeroOsExibicao = 'Sem OS';
                                        }
                                    }
                                ?>
                                <tr>
                                    <td class="os-cell"><?= htmlspecialchars($numeroOsExibicao) ?></td>
                                    <td><?= !empty($linha['data_registro']) ? date('d/m/Y H:i', strtotime($linha['data_registro'])) : '-' ?></td>
                                    <td><?= htmlspecialchars($linha['produto_nome'] ?? '-') ?></td>
                                    <?php if ($tipoRegistro === 'abates'): ?>
                                        <td><?= htmlspecialchars($linha['tipo'] ?? '-') ?></td>
                                        <td class="num"><?= number_format((float) ($linha['quantidade_original'] ?? 0), 2, ',', '.') ?></td>
                                        <td class="num"><?= number_format((float) ($linha['quantidade'] ?? 0), 2, ',', '.') ?></td>
                                        <td class="num"><?= number_format((float) ($linha['quantidade_final'] ?? 0), 2, ',', '.') ?></td>
                                        <td><?= htmlspecialchars($linha['motivo'] ?? '-') ?></td>
                                    <?php else: ?>
                                        <td><?= htmlspecialchars($linha['pessoa_nome'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($linha['tipo'] ?? '-') ?></td>
                                        <td class="num"><?= number_format((float) ($linha['quantidade'] ?? 0), 2, ',', '.') ?> kg</td>
                                        <?php if ($tipoRegistro === 'vendas'): ?>
                                            <td class="num">R$ <?= number_format((float) ($linha['preco'] ?? 0), 2, ',', '.') ?></td>
                                            <?php
                                                $stat = strtolower(trim((string) ($linha['status'] ?? '')));
                                                $stClass = in_array($stat, ['concluido', 'anexado'], true) ? 'st-ok' : ($stat === 'pendente' ? 'st-warn' : '');
                                            ?>
                                            <td><span class="st-pill <?= $stClass ?>"><?= htmlspecialchars($linha['status'] ?? '-') ?></span></td>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="<?= count($cfg['headers']) ?>" class="empty">Nenhum registro encontrado com estes filtros.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- LAYOUT NOVO (UX/UI): rodapé com soma da página + paginação -->
            <div class="cd-foot" style="border-top:1px solid var(--line-2);">
                <span class="info"><?= $totalRegistros ?> registro(s) encontrado(s)</span>
                <span class="totais">
                    Soma desta página: <b><?= number_format($somaQtdPagina, 2, ',', '.') ?> kg</b><?php if ($tipoRegistro === 'vendas'): ?> · <b>R$ <?= number_format($somaValorPagina, 2, ',', '.') ?></b><?php endif; ?>
                </span>
                <div class="cd-pag">
                    <span class="pg">Página <?= $pagina ?> de <?= $totalPaginas ?></span>
                    <a class="cd-btn" href="<?= $pagina > 1 ? cicloUrlPagina($queryBase, $pagina - 1) : '#' ?>" <?= $pagina > 1 ? '' : 'aria-disabled="true"' ?>>← Anterior</a>
                    <a class="cd-btn cd-btn-primary" href="<?= $pagina < $totalPaginas ? cicloUrlPagina($queryBase, $pagina + 1) : '#' ?>" <?= $pagina < $totalPaginas ? '' : 'aria-disabled="true"' ?>>Próxima →</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /.cic-reg -->
    </div>
</body>
</html>

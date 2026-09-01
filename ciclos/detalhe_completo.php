<?php
// --- SEÇÃO: autenticação e conexão PDO ---
require_once __DIR__ . '/../auth/proteger.php';

if (file_exists(__DIR__ . '/../includes/db.php')) {
    require_once __DIR__ . '/../includes/db.php';
} else {
    require_once __DIR__ . '/../config/conexao.php';
    $dsn = sprintf(
        'mysql:host=%s;%sdbname=%s;charset=utf8mb4',
        $host,
        isset($porta) && $porta !== '' ? 'port=' . (int) $porta . ';' : '',
        $banco
    );
    $pdo = new PDO(
        $dsn,
        $usuario,
        $senha,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

require_once __DIR__ . '/../includes/ciclo_helper.php';
require_once __DIR__ . '/../config/layout_helper.php';

if (!in_array($_SESSION['usuario_nivel'] ?? '', ['admin', 'operacional'], true)) {
    die('Acesso negado.');
}

// --- SEÇÃO: parâmetros e paginação ---
$cicloId = isset($_GET['ciclo_id']) ? intval($_GET['ciclo_id']) : 0;
$tipo = $_GET['tipo'] ?? '';
$tipo = in_array($tipo, ['vendas', 'entradas'], true) ? $tipo : 'vendas';
$pagina = max(1, intval($_GET['page'] ?? 1));
$porPagina = 20;
$offset = ($pagina - 1) * $porPagina;

if ($cicloId <= 0) {
    die('Ciclo inválido.');
}

// --- SEÇÃO: busca do ciclo ---
$stmtCiclo = $pdo->prepare("SELECT id, nome, ano, mes FROM ciclos WHERE id = :id LIMIT 1");
$stmtCiclo->execute([':id' => $cicloId]);
$ciclo = $stmtCiclo->fetch();

if (!$ciclo) {
    die('Ciclo não encontrado.');
}

// --- SEÇÃO: total e consulta paginada ---
$totalRegistros = 0;
$registros = [];

if ($tipo === 'vendas') {
    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM vendas WHERE ciclo_id = :ciclo_id");
    $stmtTotal->execute([':ciclo_id' => $cicloId]);
    $totalRegistros = (int) $stmtTotal->fetchColumn();

    $stmtLista = $pdo->prepare(
        "SELECT
            v.data_venda,
            p.nome AS produto_nome,
            c.nome AS cliente_nome,
            v.quantidade,
            v.tipo,
            v.preco,
            v.preco_manual,
            v.status
        FROM vendas v
        LEFT JOIN produtos p ON p.id = v.produto_id
        LEFT JOIN clientes c ON c.id = v.cliente_id
        WHERE v.ciclo_id = :ciclo_id
        ORDER BY v.data_venda DESC, v.id DESC
        LIMIT :limite OFFSET :offset"
    );
} else {
    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM entradas WHERE ciclo_id = :ciclo_id");
    $stmtTotal->execute([':ciclo_id' => $cicloId]);
    $totalRegistros = (int) $stmtTotal->fetchColumn();

    $stmtLista = $pdo->prepare(
        "SELECT
            e.data_entrada,
            p.nome AS produto_nome,
            f.nome AS fornecedor_nome,
            e.quantidade,
            e.tipo
        FROM entradas e
        LEFT JOIN produtos p ON p.id = e.produto_id
        LEFT JOIN fornecedores f ON f.id = e.fornecedor_id
        WHERE e.ciclo_id = :ciclo_id
        ORDER BY e.data_entrada DESC, e.id DESC
        LIMIT :limite OFFSET :offset"
    );
}

$stmtLista->bindValue(':ciclo_id', $cicloId, PDO::PARAM_INT);
$stmtLista->bindValue(':limite', $porPagina, PDO::PARAM_INT);
$stmtLista->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmtLista->execute();
$registros = $stmtLista->fetchAll();

$totalPaginas = max(1, (int) ceil($totalRegistros / $porPagina));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $tipo === 'vendas' ? 'Vendas do ciclo' : 'Entradas do ciclo' ?></title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f4f6f9; color: #1f2937; }
        .page { max-width: 1180px; margin: 0 auto; padding: 28px 20px 40px; }
        .breadcrumb { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; color: #5F5E5A; font-size: 14px; }
        .breadcrumb a { color: #1D9E75; text-decoration: none; }
        .box { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; padding: 18px 20px; box-shadow: 0 6px 18px rgba(0, 0, 0, 0.04); }
        .header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 20px; }
        .header h1 { margin: 0; color: #1a3a2e; }
        .header p { margin: 6px 0 0; color: #5F5E5A; }
        .btn { border: 1px solid #ccc; background: #fff; border-radius: 6px; padding: 8px 14px; font-size: 13px; cursor: pointer; color: #1f2937; text-decoration: none; display: inline-flex; align-items: center; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px 12px; border-bottom: 1px solid #eee; text-align: left; font-size: 14px; }
        th { background: #f9f9f9; color: #5F5E5A; }
        .table-wrap { overflow-x: auto; }
        .pagination { margin-top: 18px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .pagination-links { display: flex; gap: 8px; flex-wrap: wrap; }
        .empty { color: #5F5E5A; font-style: italic; }
    </style>
    <?php renderAppLayoutStyles(); ?>
</head>
<body>
    <?php renderAppHeader('..'); ?>
    <div class="page page-container app-shell">
        <div class="breadcrumb">
            <a href="index.php">Ciclos</a>
            <span>→</span>
            <span><?= htmlspecialchars($ciclo['nome'] ?: (nomeMesCurto($ciclo['mes']) . ' ' . $ciclo['ano'])) ?></span>
            <span>→</span>
            <span><?= $tipo === 'vendas' ? 'Vendas' : 'Entradas' ?></span>
        </div>

        <div class="box section-card">
            <div class="header">
                <div>
                    <h1><?= $tipo === 'vendas' ? 'Vendas do ciclo' : 'Entradas do ciclo' ?></h1>
                    <p><?= htmlspecialchars($ciclo['nome'] ?: (nomeMesCurto($ciclo['mes']) . ' ' . $ciclo['ano'])) ?></p>
                </div>
                <a class="btn" href="detalhe.php?id=<?= (int) $ciclo['id'] ?>">Voltar para detalhes</a>
            </div>

            <div class="table-wrap">
                <table data-no-responsive="1">
                    <thead>
                        <tr>
                            <?php if ($tipo === 'vendas'): ?>
                                <th>Data</th><th>Produto</th><th>Cliente</th><th>Quantidade</th><th>Tipo</th><th>Preço</th><th>Status</th>
                            <?php else: ?>
                                <th>Data</th><th>Produto</th><th>Fornecedor</th><th>Quantidade</th><th>Tipo</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($registros)): ?>
                            <?php foreach ($registros as $registro): ?>
                                <tr>
                                    <?php if ($tipo === 'vendas'): ?>
                                        <?php $pmComp = !empty($registro['preco_manual']) && (int) $registro['preco_manual'] === 1; ?>
                                        <td><?= !empty($registro['data_venda']) ? date('d/m/Y', strtotime($registro['data_venda'])) : '-' ?></td>
                                        <td><?= htmlspecialchars($registro['produto_nome'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($registro['cliente_nome'] ?? '-') ?></td>
                                        <td><?= formatKg((float) ($registro['quantidade'] ?? 0)) ?></td>
                                        <td><?= htmlspecialchars($registro['tipo'] ?? '-') ?></td>
                                        <td<?= $pmComp ? ' style="color:#c62828;font-weight:700;"' : '' ?>>
                                            R$ <?= number_format((float) ($registro['preco'] ?? 0), 2, ',', '.') ?>
                                            <?php if ($pmComp): ?><span title="Preço inserido manualmente" style="font-size:11px;margin-left:2px;">✎</span><?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($registro['status'] ?? '-') ?></td>
                                    <?php else: ?>
                                        <td><?= !empty($registro['data_entrada']) ? date('d/m/Y H:i', strtotime($registro['data_entrada'])) : '-' ?></td>
                                        <td><?= htmlspecialchars($registro['produto_nome'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($registro['fornecedor_nome'] ?? '-') ?></td>
                                        <td><?= formatKg((float) ($registro['quantidade'] ?? 0)) ?></td>
                                        <td><?= htmlspecialchars($registro['tipo'] ?? '-') ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="<?= $tipo === 'vendas' ? 7 : 5 ?>" class="empty">Nenhum registro encontrado para este ciclo.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination">
                <div>Total de registros: <?= $totalRegistros ?></div>
                <div class="pagination-links">
                    <?php if ($pagina > 1): ?>
                        <a class="btn" href="?ciclo_id=<?= $cicloId ?>&tipo=<?= urlencode($tipo) ?>&page=<?= $pagina - 1 ?>">← Anterior</a>
                    <?php endif; ?>
                    <span>Página <?= $pagina ?> de <?= $totalPaginas ?></span>
                    <?php if ($pagina < $totalPaginas): ?>
                        <a class="btn" href="?ciclo_id=<?= $cicloId ?>&tipo=<?= urlencode($tipo) ?>&page=<?= $pagina + 1 ?>">Próxima →</a>
                        <a class="btn" href="?ciclo_id=<?= $cicloId ?>&tipo=<?= urlencode($tipo) ?>&page=<?= $totalPaginas ?>">Última</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

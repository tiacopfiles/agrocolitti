<?php
require_once '../bootstrap/conexao.php';
require_once 'includes/admin_guard.php';
require_once 'includes/admin_layout.php';

// Métricas
$total_prod   = (int)$conexao->query("SELECT COUNT(*) AS c FROM produtos")->fetch_assoc()['c'];
$ativos       = (int)$conexao->query("SELECT COUNT(*) AS c FROM produtos WHERE ativo=1")->fetch_assoc()['c'];
$inativos     = $total_prod - $ativos;

// Lista com total vendido por produto
$produtosQ = $conexao->query("
    SELECT p.id, p.nome, p.unidade, p.ativo,
           COALESCE(SUM(v.pedido),0) AS total_vendido,
           COUNT(v.id) AS num_vendas
    FROM produtos p
    LEFT JOIN vendas v ON p.id = v.produto_id
    GROUP BY p.id, p.nome, p.unidade, p.ativo
    ORDER BY total_vendido DESC
");

adminHead('Produtos');
?>
<body>
<?php adminSidebar('produtos'); ?>
<?php adminOpenMain('Produtos', 'Visão geral do cadastro de produtos'); ?>

<!-- Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:20px;">
    <div class="stat-card" style="--c:var(--cyan)">
        <div class="stat-ico cyan"><i class="fa-solid fa-boxes-stacked"></i></div>
        <div class="stat-body"><div class="stat-val"><?= $total_prod ?></div><div class="stat-lbl">Total de Produtos</div></div>
    </div>
    <div class="stat-card" style="--c:var(--green)">
        <div class="stat-ico green"><i class="fa-solid fa-circle-check"></i></div>
        <div class="stat-body"><div class="stat-val"><?= $ativos ?></div><div class="stat-lbl">Ativos</div></div>
    </div>
    <div class="stat-card" style="--c:var(--red)">
        <div class="stat-ico red"><i class="fa-solid fa-circle-xmark"></i></div>
        <div class="stat-body"><div class="stat-val"><?= $inativos ?></div><div class="stat-lbl">Inativos</div></div>
    </div>
</div>

<!-- Tabela -->
<div class="panel">
    <div class="panel-hdr">
        <div class="panel-title"><i class="fa-solid fa-boxes-stacked"></i> Catálogo de Produtos</div>
        <a href="../../produtos/produtos.php" class="btn btn-c" style="font-size:11px;">
            <i class="fa-solid fa-pen-to-square"></i> Editar no sistema
        </a>
    </div>
    <div class="panel-body" style="padding:0;">
        <div class="tbl-wrap">
            <table class="adm-tbl">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Unidade</th>
                        <th>Status</th>
                        <th>Vendas (pedidos)</th>
                        <th>Qtd. Transações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($p = $produtosQ->fetch_assoc()): ?>
                    <tr>
                        <td style="color:var(--txt3);font-size:11px;">#<?= $p['id'] ?></td>
                        <td style="font-weight:600;"><?= htmlspecialchars($p['nome']) ?></td>
                        <td><span class="badge b-gr"><?= htmlspecialchars($p['unidade'] ?? 'kg') ?></span></td>
                        <td>
                            <?= $p['ativo']
                                ? '<span class="badge b-g">Ativo</span>'
                                : '<span class="badge b-r">Inativo</span>' ?>
                        </td>
                        <td style="font-variant-numeric:tabular-nums;">
                            <?= number_format((float)$p['total_vendido'], 2, ',', '.') ?>
                        </td>
                        <td>
                            <span class="badge b-c"><?= (int)$p['num_vendas'] ?></span>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php adminFooter(); ?>

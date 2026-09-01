<?php
require '../auth/proteger.php';
require '../config/conexao.php';
require '../config/layout_helper.php';
require '../config/permissions.php';
require '../config/tabelas_preco_flex_schema.php';

requireModule('tabelas', '../index.php');
garantirTabelasPrecoFlex($conexao);

$tabelas = [];
$resTabelas = $conexao->query("SELECT id, nome, tipo, ativo, criado_em FROM tabelas_preco WHERE tipo = 'personalizada' ORDER BY ativo DESC, nome ASC, id ASC");
if ($resTabelas) {
    $tabelas = $resTabelas->fetch_all(MYSQLI_ASSOC);
}

$mensagens = [
    'salvo' => 'Tabela salva com sucesso.',
    'excluido' => 'Tabela removida da listagem com sucesso.',
    'erro' => 'Nao foi possivel concluir a operacao.',
];
$msg = (string) ($_GET['msg'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Editar Tabelas</title>
<style>
* { box-sizing:border-box; }
body { margin:0; font-family:'Segoe UI',Arial,sans-serif; background:#f4f6f9; color:#333; }
.container { padding:30px; max-width:1200px; margin:auto; }
.card { background:#fff; padding:25px; border-radius:12px; box-shadow:0 4px 12px rgba(0,0,0,.06); margin-bottom:30px; }
.card h2 { margin-top:0; }
.msg { background:#e8f5e9; color:#2e7d32; border:1px solid #a5d6a7; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-weight:600; }
.msg.erro { background:#ffebee; color:#c62828; border-color:#ef9a9a; }
.table-responsive { width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; }
table { width:100%; min-width:820px; border-collapse:collapse; background:#fff; border-radius:12px; overflow:hidden; }
table th { background:#1b5e20; color:#fff; padding:16px 20px; font-size:14px; text-align:center; white-space:nowrap; }
table td { padding:16px 20px; text-align:center; border-bottom:1px solid #eee; font-size:14px; white-space:nowrap; }
table tr:hover { background:#f9f9f9; }
.badge-ativo { background:#27ae60; color:#fff; padding:5px 10px; border-radius:20px; font-size:12px; }
.badge-bloqueado { background:#7f8c8d; color:#fff; padding:5px 10px; border-radius:20px; font-size:12px; }
.acoes { display:flex; gap:8px; justify-content:center; align-items:center; flex-wrap:nowrap; }
button { padding:8px 14px; border:none; border-radius:6px; font-weight:600; cursor:pointer; }
.acoes button, .acoes a { width:34px; height:34px; display:inline-flex; align-items:center; justify-content:center; padding:0; font-size:15px; line-height:1; border-radius:6px; text-decoration:none; }
.btn-edit { background:#1976d2; color:#fff; }
.btn-danger { background:#e74c3c; color:#fff; }
.btn-danger:disabled { background:#aaa; cursor:not-allowed; }
@media (max-width:768px) { .container { padding:20px; } table { min-width:820px; } table th, table td { padding:16px 20px; font-size:14px; } }
</style>
<?php renderAppLayoutStyles(); ?>
<script>
function confirmarExcluirTabela(id) {
    const executar = function () {
        document.getElementById('excluirTabelaId').value = id;
        document.getElementById('formExcluirTabela').submit();
    };
    if (window.appConfirm) {
        window.appConfirm('Tem certeza que deseja excluir esta tabela? Essa acao nao podera ser desfeita.', executar, { danger: true });
        return;
    }
    if (confirm('Tem certeza que deseja excluir esta tabela? Essa acao nao podera ser desfeita.')) {
        executar();
    }
}
</script>
</head>
<body>
<?php renderAppHeader('..'); ?>
<div class="container">
    <?php if (isset($mensagens[$msg])): ?>
        <div class="msg <?= $msg === 'erro' ? 'erro' : '' ?>"><?= htmlspecialchars($mensagens[$msg]) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>Tabelas disponiveis</h2>
        <div class="table-responsive">
            <table>
                <tr>
                    <th>ID</th>
                    <th>Nome</th>
                    <th>Tipo</th>
                    <th>Status</th>
                    <th>Criada em</th>
                    <th>Ações</th>
                </tr>
                <?php if (!$tabelas): ?>
                    <tr><td colspan="6">Nenhuma tabela cadastrada.</td></tr>
                <?php endif; ?>
                <?php foreach ($tabelas as $tabela): ?>
                    <tr>
                        <td><?= (int) $tabela['id'] ?></td>
                        <td><?= htmlspecialchars($tabela['nome']) ?></td>
                        <td><?= htmlspecialchars($tabela['tipo']) ?></td>
                        <td>
                            <?php if ((int) $tabela['ativo'] === 1): ?>
                                <span class="badge-ativo">Ativa</span>
                            <?php else: ?>
                                <span class="badge-bloqueado">Inativa</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string) ($tabela['criado_em'] ?? '')) ?></td>
                        <td>
                            <div class="acoes">
                                <?php if ((int) $tabela['ativo'] === 1): ?>
                                    <a class="btn-edit" title="Editar" href="criar_tabela_personalizada.php?id=<?= (int) $tabela['id'] ?>">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                <?php endif; ?>
                                <button type="button" class="btn-danger" title="Excluir" <?= (int) $tabela['ativo'] === 1 ? '' : 'disabled' ?> onclick="confirmarExcluirTabela(<?= (int) $tabela['id'] ?>)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <form id="formExcluirTabela" method="POST" action="actions/excluir_tabela_personalizada.php">
        <input type="hidden" name="id" id="excluirTabelaId">
    </form>
</div>
</body>
</html>

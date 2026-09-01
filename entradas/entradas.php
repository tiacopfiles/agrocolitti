<?php
require "../config/conexao.php";
require "../config/ciclo_helper.php";
require "../auth/proteger.php";
require "../config/layout_helper.php";

$cicloAtivo = getCicloAtivo($conexao);
$cicloAtivoId = $cicloAtivo ? (int) $cicloAtivo['id'] : null;

$stmtProdutos = $conexao->prepare("SELECT id, nome FROM produtos WHERE ativo = 1 ORDER BY nome ASC");
$stmtProdutos->execute();
$produtos = $stmtProdutos->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtProdutos->close();

$stmtFornecedores = $conexao->prepare("SELECT id, nome FROM fornecedores WHERE ativo = 1 ORDER BY nome ASC");
$stmtFornecedores->execute();
$fornecedores = $stmtFornecedores->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtFornecedores->close();

$stmt = $conexao->prepare("
    SELECT 
        e.*, 
        p.nome AS produto, 
        f.nome AS fornecedor
    FROM entradas e
    JOIN produtos p ON e.produto_id = p.id
    LEFT JOIN fornecedores f ON e.fornecedor_id = f.id
    WHERE 1 = 1
    ORDER BY e.data_entrada DESC
");
$stmt->execute();
$result = $stmt->get_result();



?>




<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Histórico de Entradas</title>

<style>
*{ box-sizing:border-box; }
body{ margin:0; font-family:'Segoe UI', Arial, sans-serif; background:#f4f6f9; color:#222; }
.header{ background:#1b5e20; color:white; padding:15px 20px; border-bottom:3px solid #2e7d32; }
.header-top{ display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
.header h1{ margin:0; font-size:20px; font-weight:600; }
.voltar{ background:white; color:#1b5e20; padding:6px 14px; border-radius:6px; text-decoration:none; font-weight:600; font-size:14px; }
.voltar:hover{ background:#e8f5e9; }
.user-info{ margin-top:5px; font-size:14px; }
.user-info a{ color:white; text-decoration:none; margin-left:10px; font-weight:600; }
.container{ padding:30px; max-width:1200px; margin:auto; }
.card{ background:white; padding:25px; border-radius:10px; box-shadow:0 4px 12px rgba(0,0,0,0.06); margin-bottom:30px; }
.table-responsive{ width:100%; overflow-x:auto; }
table{ width:100%; min-width:750px; border-collapse:collapse; background:white; border-radius:10px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.06); }
table th{ background:#1b5e20; color:white; padding:12px; text-align:center; font-weight:600; font-size:14px; }
table td{ padding:12px; text-align:center; border-bottom:1px solid #eee; font-size:14px; }
table tr:hover{ background:#f1f8f4; }
.badge{ padding:5px 12px; border-radius:20px; font-size:12px; font-weight:600; color:white; }
.badge-fornecedor{ background:#2e7d32; }
.badge-colheita{ background:#1b5e20; }
.abatimento{ color:#c62828; font-weight:600; }
.sem-dado{ color:#999; }
.vazio{ text-align:center; padding:25px; color:#666; font-style:italic; }
.msg-sucesso{ background:#e8f5e9; color:#2e7d32; border:1px solid #a5d6a7; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-weight:600; }
.msg-erro{ background:#ffebee; color:#c62828; border:1px solid #ef9a9a; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-weight:600; }
.btn-action{ border:none; width:38px; height:38px; padding:0; border-radius:6px; cursor:pointer; font-weight:600; color:white; display:inline-flex; align-items:center; justify-content:center; text-decoration:none; }
.btn-edit{ background:#1565c0; }
.btn-edit:hover{ background:#0d47a1; }
.btn-delete{ background:#c62828; }
.btn-delete:hover{ background:#a61c1c; }
.btn-success{ background:#2e7d32; }
.btn-success:hover{ background:#1b5e20; }
.btn-secondary{ background:#6b7280; }
.btn-secondary:hover{ background:#4b5563; }
.btn-imprimir{ background:#e65100; color:white; padding:6px 12px; border-radius:6px; border:none; text-decoration:none; font-weight:600; font-size:13px; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; }
.btn-imprimir:hover{ background:#bf360c; }
.acoes{ display:flex; gap:8px; justify-content:center; flex-wrap:wrap; }
.modal{ display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); padding:20px; z-index:999; }
.modal-content{ background:white; width:520px; max-width:100%; margin:auto; margin-top:6vh; padding:25px; border-radius:12px; }
.form-grid{ display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px; }
.form-grid-full{ grid-column:1 / -1; }
label{ font-weight:600; font-size:14px; display:block; }
input, select, textarea{ width:100%; padding:10px; margin-top:5px; border-radius:6px; border:1px solid #ccc; font-size:14px; }
textarea{ min-height:90px; resize:vertical; }
@media (max-width:768px){ .form-grid{ grid-template-columns:1fr; } }
</style>
<?php renderAppLayoutStyles(); ?>
<script>
function abrirModalEdicaoEntrada(item) {
    document.getElementById("entrada_id").value = item.id || "";
    document.getElementById("entrada_produto_id").value = item.produto_id || "";
    document.getElementById("entrada_tipo").value = item.tipo || "entrada_fornecedor";
    document.getElementById("entrada_quantidade").value = item.quantidade || "";
    document.getElementById("entrada_data").value = item.data_entrada_formatada || "";
    document.getElementById("entrada_numero_os").value = item.numero_os || "";
    document.getElementById("entrada_fornecedor_id").value = item.fornecedor_id || "";
    document.getElementById("entrada_motivo").value = item.motivo_abatimento || "";

    atualizarCamposEntrada();
    document.getElementById("modalEditarEntrada").style.display = "block";
}

function fecharModalEdicaoEntrada() {
    document.getElementById("modalEditarEntrada").style.display = "none";
}

function atualizarCamposEntrada() {
    const tipo = document.getElementById("entrada_tipo").value;
    const blocoFornecedor = document.getElementById("blocoFornecedorEntrada");
    const blocoOs = document.getElementById("blocoOsEntrada");

    const mostrarFornecedor = tipo === "entrada_fornecedor";
    blocoFornecedor.style.display = mostrarFornecedor ? "block" : "none";
    blocoOs.style.display = mostrarFornecedor ? "block" : "none";

    if (!mostrarFornecedor) {
        document.getElementById("entrada_fornecedor_id").value = "";
        document.getElementById("entrada_numero_os").value = "";
    }
}
</script>
</head>

<body>

<?php renderAppHeader('..'); ?>

<div class="container page-container app-shell">

<?php if ($cicloAtivo): ?>
    <div class="msg-sucesso">Ciclo ativo: <?= htmlspecialchars(getNomeCiclo($cicloAtivo)) ?></div>
<?php endif; ?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'entrada_registrada'): ?>
    <div class="msg-sucesso">✅ Entrada registrada com sucesso!</div>
<?php endif; ?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'atualizado'): ?>
    <div class="msg-sucesso">✅ Entrada atualizada com sucesso!</div>
<?php endif; ?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'excluido'): ?>
    <div class="msg-sucesso">✅ Entrada excluída com sucesso!</div>
<?php endif; ?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'erro'): ?>
    <div class="msg-erro">❌ <?= htmlspecialchars($_GET['detalhe'] ?? 'Erro ao processar a entrada.'); ?></div>
<?php endif; ?>

<div class="card section-card">
<h2>Histórico de Entradas</h2>

<div class="table-responsive">
<table>
<tr>
    <th>Produto</th>
    <th>Fornecedor</th>
    <th>Nº OS</th>
    <th>Quantidade</th>
    <th>Tipo</th>
    <th>Data Entrada</th>
    <th>Abatimento</th>
    <th>Ação</th>
</tr>

<?php if ($result->num_rows > 0): ?>
    <?php while ($linha = $result->fetch_assoc()): ?>
        <?php
        $itemJson = htmlspecialchars(json_encode([
            'id' => (int) $linha['id'],
            'produto_id' => (int) $linha['produto_id'],
            'fornecedor_id' => isset($linha['fornecedor_id']) ? (int) $linha['fornecedor_id'] : '',
            'numero_os' => (string) ($linha['numero_os'] ?? ''),
            'quantidade' => (float) $linha['quantidade'],
            'tipo' => (string) $linha['tipo'],
            'data_entrada_formatada' => !empty($linha['data_entrada']) ? date('Y-m-d\TH:i', strtotime($linha['data_entrada'])) : '',
            'motivo_abatimento' => (string) ($linha['motivo_abatimento'] ?? ''),
        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        ?>
        <tr>

            <td><?= htmlspecialchars($linha['produto']) ?></td>

            <td>
                <?= !empty($linha['fornecedor']) 
                    ? htmlspecialchars($linha['fornecedor']) 
                    : '<span class="sem-dado">-</span>' ?>
            </td>

            <td>
                <?= !empty($linha['numero_os']) 
                    ? htmlspecialchars($linha['numero_os']) 
                    : '<span class="sem-dado">-</span>' ?>
            </td>

            <td><?= number_format($linha['quantidade'], 2, ',', '.') ?> kg</td>

            <td>
                <?php if ($linha['tipo'] === 'entrada_fornecedor'): ?>
                    <span class="badge badge-fornecedor">Fornecedor</span>
                <?php elseif ($linha['tipo'] === 'colheita' || $linha['tipo'] === 'entrada_colheita'): ?>
                    <span class="badge badge-colheita">Colheita</span>
                <?php else: ?>
                    <span class="sem-dado"><?= htmlspecialchars($linha['tipo']) ?></span>
                <?php endif; ?>
            </td>

            <td><?= date('d/m/Y H:i', strtotime($linha['data_entrada'])) ?></td>

            <td>
                <?php if (!empty($linha['motivo_abatimento'])): ?>
                    <span class="abatimento">⚠ <?= htmlspecialchars($linha['motivo_abatimento']) ?></span>
                <?php else: ?>
                    <span class="sem-dado">-</span>
                <?php endif; ?>
            </td>

            <td>
                <div class="acoes">
                    <?php if ($linha['tipo'] === 'entrada_fornecedor' && !empty($linha['numero_os'])): ?>
                        <a class="btn-action btn-imprimir" download title="Gerar Pedido Word" href="../previsoes/gerar_pedido_compra.php?numero_os=<?= rawurlencode((string) $linha['numero_os']) ?>"><i class="bi bi-file-earmark-text"></i></a>
                    <?php endif; ?>

                    <!-- Abre o modal preenchendo os dados atuais da entrada. -->
                    <button type="button" class="btn-action btn-edit" title="Editar" onclick='abrirModalEdicaoEntrada(<?= $itemJson ?>)'>
                        <i class="bi bi-pencil"></i>
                    </button>

                    <!-- Exclui a entrada e sua movimentacao correspondente. -->
                    <form method="POST" action="actions/excluir_entrada.php" onsubmit="return confirm('Deseja excluir esta entrada?');" style="margin:0;">
                        <input type="hidden" name="id" value="<?= (int) $linha['id'] ?>">
                        <button type="submit" class="btn-action btn-delete" title="Excluir"><i class="bi bi-trash"></i></button>
                    </form>
                </div>
            </td>

        </tr>
    <?php endwhile; ?>
<?php else: ?>
    <tr><td colspan="8" class="vazio">Nenhuma entrada registrada.</td></tr>
<?php endif; ?>

</table>
</div>
</div>
</div>

<div id="modalEditarEntrada" class="modal">
    <div class="modal-content">
        <h3>Editar Entrada</h3>

        <!-- O modal reaproveita os dados da linha para facilitar a manutencao do CRUD. -->
        <form method="POST" action="actions/editar_entrada.php" class="form-grid">
            <input type="hidden" name="id" id="entrada_id">

            <div>
                <label>Produto</label>
                <select name="produto_id" id="entrada_produto_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($produtos as $produto): ?>
                        <option value="<?= (int) $produto['id'] ?>"><?= htmlspecialchars($produto['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>Tipo</label>
                <select name="tipo" id="entrada_tipo" onchange="atualizarCamposEntrada()" required>
                    <option value="entrada_fornecedor">Fornecedor</option>
                    <option value="colheita">Colheita</option>
                </select>
            </div>

            <div id="blocoFornecedorEntrada">
                <label>Fornecedor</label>
                <select name="fornecedor_id" id="entrada_fornecedor_id">
                    <option value="">Selecione</option>
                    <?php foreach ($fornecedores as $fornecedor): ?>
                        <option value="<?= (int) $fornecedor['id'] ?>"><?= htmlspecialchars($fornecedor['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="blocoOsEntrada">
                <label>Nº OS</label>
                <input type="text" name="numero_os" id="entrada_numero_os">
            </div>

            <div>
                <label>Quantidade</label>
                <input type="number" step="0.01" name="quantidade" id="entrada_quantidade" required>
            </div>

            <div>
                <label>Data da Entrada</label>
                <input type="datetime-local" name="data_entrada" id="entrada_data" required>
            </div>

            <div class="form-grid-full">
                <label>Motivo do Abatimento</label>
                <textarea name="motivo_abatimento" id="entrada_motivo"></textarea>
            </div>

            <div class="form-grid-full" style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="submit" class="btn-action btn-success" title="Salvar"><i class="bi bi-check-lg"></i></button>
                <button type="button" class="btn-action btn-secondary" title="Cancelar" onclick="fecharModalEdicaoEntrada()"><i class="bi bi-x-lg"></i></button>
            </div>
        </form>
    </div>
</div>

</body>
</html>

<?php
require "../config/conexao.php";
require "../config/ciclo_helper.php";
require "../auth/proteger.php";
require "../config/layout_helper.php";
require "../comissoes/meeiros_helper_v2.php";

if (!in_array($_SESSION['usuario_nivel'], ['admin', 'colheita', 'operacional'])) {
    die("Sem permissao.");
}

function colunaMeieiroExiste(mysqli $conexao): bool
{
    static $existe = null;

    if ($existe !== null) {
        return $existe;
    }

    $resultado = $conexao->query("SHOW COLUMNS FROM previsao_colheita LIKE 'meieiro'");
    $existe = $resultado && $resultado->num_rows > 0;

    if ($resultado instanceof mysqli_result) {
        $resultado->free();
    }

    return $existe;
}

comissoesMeeirosGarantirEstrutura($conexao);
$temColunaMeieiro = colunaMeieiroExiste($conexao);
$meeiros = comissoesMeeirosListarAtivos($conexao);
$cicloAtivo = getCicloAtivo($conexao);
$cicloAtivoId = $cicloAtivo ? (int) $cicloAtivo['id'] : null;

$produtos = $conexao->prepare("
    SELECT DISTINCT p.id, p.nome
    FROM tabela_precos_meeiros tpm
    JOIN produtos p ON p.id = tpm.produto_id
    WHERE tpm.ativo = 1
      AND tpm.produto_id IS NOT NULL
      AND p.ativo = 1
    ORDER BY p.nome ASC
");
$produtos->execute();
$produtos = $produtos->get_result()->fetch_all(MYSQLI_ASSOC);

function produtoNomeAgranelColheita(string $nome): string
{
    $nome = trim($nome);
    $unidades = '(?:kg|kgs|quilo|quilos|g|gr|grs|grama|gramas)';
    $limpo = preg_replace('/^\s*\d+(?:[\.,]\d+)?\s*' . $unidades . '\b\s*[-_\/]*\s*/iu', '', $nome);
    $limpo = preg_replace('/\s*[-_\/]*\s*\d+(?:[\.,]\d+)?\s*' . $unidades . '\b\s*$/iu', '', (string) $limpo);
    $limpo = trim((string) $limpo);
    return $limpo !== '' ? $limpo : $nome;
}

$stmtLista = $conexao->prepare("
    SELECT pc.*, p.nome AS produto, m.nome AS meeiro_nome
    FROM previsao_colheita pc
    JOIN produtos p ON pc.produto_id = p.id
    LEFT JOIN meeiros m ON m.id = pc.meeiro_id
    WHERE pc.status = 'pendente'
    ORDER BY pc.data_prevista ASC
");
$stmtLista->execute();
$lista = $stmtLista->get_result();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Previsao de Colheita</title>
<style>
*{ box-sizing:border-box; }
body{ font-family:'Segoe UI', Arial, sans-serif; background:#f4f6f9; margin:0; color:#333; }
.header{ background:#1b5e20; color:white; padding:15px 20px; }
.header-top{ display:flex; justify-content:space-between; align-items:flex-start; }
.header h1{ margin:0; font-size:20px; font-weight:600; }
.user-info{ font-size:14px; margin-top:4px; }
.user-info a{ color:white; text-decoration:none; margin-left:6px; font-weight:bold; }
.voltar{ background:white; color:#1b5e20; padding:6px 14px; border-radius:6px; text-decoration:none; font-weight:bold; font-size:14px; }
.voltar:hover{ background:#e8f5e9; }
.container{ padding:30px; max-width:1200px; margin:auto; }
.card{ background:white; padding:25px; border-radius:12px; box-shadow:0 4px 12px rgba(0,0,0,0.06); margin-bottom:30px; }
.card h2{ margin-top:0; }
.form-title{ margin:0 0 20px 0; color:#111; grid-column:1 / -1; }
.form-grid{ display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:20px; }
label{ font-weight:600; font-size:14px; }
input, select, textarea{ width:100%; padding:10px; margin-top:5px; border-radius:6px; border:1px solid #ccc; font-size:14px; }
input:focus, select:focus, textarea:focus{ border-color:#1b5e20; outline:none; }
button{ padding:10px 16px; border-radius:6px; border:none; font-weight:600; cursor:pointer; }
.btn-primary{ background:#2e7d32; color:white; }
.btn-primary:hover{ background:#1b5e20; }
.btn-secondary{ background:#7f8c8d; color:white; }
table{ width:100%; border-collapse:collapse; background:white; border-radius:12px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.06); }
table th{ background:#1b5e20; color:white; padding:12px; font-size:14px; }
table td{ padding:12px; text-align:center; border-bottom:1px solid #eee; font-size:14px; }
table tr:hover{ background:#f9f9f9; }
.badge-pendente{ background:#f39c12; color:white; padding:5px 10px; border-radius:20px; font-size:12px; }
.modal-bg{ display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); padding:20px; z-index:999; }
.modal-box{ background:white; width:450px; max-width:100%; margin:auto; margin-top:10vh; padding:30px; border-radius:12px; }
.msg-sucesso{ background:#e8f5e9; color:#2e7d32; border:1px solid #a5d6a7; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-weight:600; }
.msg-erro{ background:#ffebee; color:#c62828; border:1px solid #ef9a9a; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-weight:600; }
.acoes{ display:flex; gap:8px; justify-content:center; flex-wrap:wrap; align-items:center; }
.acoes a,.acoes button,.acoes form button{width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;font-size:15px;padding:0;flex-shrink:0;}
.btn-danger{ background:#c62828; color:white; }
.btn-danger:hover{ background:#a61c1c; }
.btn-edit{ background:#1565c0; color:white; }
.btn-edit:hover{ background:#0d47a1; }
@media (max-width:768px){
    .container{ padding:20px; }
    .form-grid{ grid-template-columns:1fr; }
    button{ width:100%; margin-top:8px; }
    table{ display:block; overflow-x:auto; white-space:nowrap; }
}
</style>
<?php renderAppLayoutStyles(); ?>
<script>
function abrirModal(id, quantidade){
    document.getElementById("modalColheita").style.display = "block";
    document.getElementById("modal_id").value = id;
    document.getElementById("modal_quantidade").innerText = quantidade;
    document.getElementById("numero_pedido").value = "";
    document.getElementById("qualidade_2a_peso").value = "";
    document.getElementById("qualidade_3a_peso").value = "";
    document.getElementById("quantidade_abatida").value = "";
    document.getElementById("quantidade_recebida").value = quantidade;
    document.getElementById("motivo_abatimento").value = "";
    atualizarCamposColheita();
}
function fecharModal(){
    document.getElementById("modalColheita").style.display = "none";
}
function atualizarCamposColheita(){
    var abatida = parseFloat(document.getElementById("quantidade_abatida").value || "0") || 0;
    document.getElementById("motivo_abatimento").required = abatida > 0;
}
function atualizarDistribuicaoQualidade(){
    var box = document.getElementById("qualidadeBox");
    if (!box || box.style.display === 'none') return true;
    var recebida = parseFloat(document.getElementById("quantidade_recebida").value || "0") || 0;
    var abatida = parseFloat(document.getElementById("quantidade_abatida").value || "0") || 0;
    var finalKg = Math.max(0, recebida - abatida);
    var peso2a = parseFloat(document.getElementById("qualidade_2a_peso").value || "0") || 0;
    var peso3a = parseFloat(document.getElementById("qualidade_3a_peso").value || "0") || 0;
    var soma = peso2a + peso3a;
    document.getElementById("qualidade_total").innerText = soma.toFixed(2).replace('.', ',') + " kg";
    document.getElementById("qualidade_final").innerText = finalKg.toFixed(2).replace('.', ',') + " kg";
    return Math.abs(soma - finalKg) <= 0.01 && finalKg > 0;
}
function validarConfirmacaoColheita(){
    if (!atualizarDistribuicaoQualidade()) {
        alert("A soma dos pesos por qualidade deve ser igual a quantidade real final.");
        return false;
    }
    return true;
}
function abrirModalComItem(item){
    abrirModal(item.id || 0, item.quantidade_prevista || 0);
    var qualidadeBox = document.getElementById("qualidadeBox");
    if (qualidadeBox) {
        qualidadeBox.style.display = 'block';
        atualizarDistribuicaoQualidade();
    }
}
function abrirModalEdicao(item){
    document.getElementById("edit_id").value = item.id || "";
    document.getElementById("edit_produto_id").value = item.produto_id || "";
    document.getElementById("edit_meeiro_id").value = item.meeiro_id || "";
    document.getElementById("edit_quantidade_prevista").value = item.quantidade_prevista || "";
    document.getElementById("edit_data_prevista").value = item.data_prevista || "";
    document.getElementById("edit_observacao").value = item.observacao || "";
    document.getElementById("modalEdicao").style.display = "block";
}
function fecharModalEdicao(){
    document.getElementById("modalEdicao").style.display = "none";
}
</script>
</head>
<body>

<?php renderAppHeader('..'); ?>

<div class="container page-container app-shell">
    <?php if ($cicloAtivo): ?>
        <div class="msg-sucesso">Ciclo ativo: <?= htmlspecialchars(getNomeCiclo($cicloAtivo)) ?></div>
    <?php endif; ?>

    <?php if (isset($_GET['msg'])): ?>
        <?php if ($_GET['msg'] === 'salvo'): ?>
            <div class="msg-sucesso">✅ Previsao registrada com sucesso!</div>
        <?php elseif ($_GET['msg'] === 'atualizado'): ?>
            <div class="msg-sucesso">✅ Previsão atualizada com sucesso!</div>
        <?php elseif ($_GET['msg'] === 'excluido'): ?>
            <div class="msg-sucesso">✅ Previsão excluída com sucesso!</div>
        <?php elseif ($_GET['msg'] === 'entrada_registrada'): ?>
            <div class="msg-sucesso">✅ Colheita confirmada e entrada registrada com sucesso!</div>
        <?php elseif ($_GET['msg'] === 'erro'): ?>
            <div class="msg-erro"><?= htmlspecialchars($_GET['detalhe'] ?? 'Erro ao processar.'); ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="card form-card">
        <form method="POST" action="actions/salvar_previsao_colheita.php" class="form-grid">
            <h2 class="form-title">Nova Previsao</h2>
            <h3 class="form-section-title">Dados principais da previs&atilde;o</h3>
            <div>
                <label>Produto</label>
                <select name="produto_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($produtos as $produto): ?>
                        <option value="<?= $produto['id'] ?>"><?= htmlspecialchars(produtoNomeAgranelColheita((string) $produto['nome'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Quantidade Prevista (kg)</label>
                <input type="number" step="0.01" name="quantidade_prevista" required>
            </div>
            <div>
                <label>Data Prevista</label>
                <input type="date" name="data_prevista" required>
            </div>
            <div>
                <label>Meeiro</label>
                <select name="meeiro_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($meeiros as $meeiro): ?>
                        <option value="<?= (int) $meeiro['id'] ?>"><?= htmlspecialchars((string) $meeiro['nome'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Observacao</label>
                <input type="text" name="observacao">
            </div>
            <div style="display:flex; align-items:flex-end;">
                <button type="submit" class="btn-primary btn-primary-app" title="Salvar"><i class="bi bi-check2-circle"></i></button>
            </div>
        </form>
    </div>

    <div class="card section-card">
                <h2>Previsoes Pendentes</h2>
        <div class="table-container">
        <table data-no-responsive="1">
            <tr>
                <th>Produto</th>
                <th>Quantidade</th>
                <th>Data</th>
                <th>Meieiro</th>
                <th>Observacao</th>
                <th>Status</th>
                <th>Acao</th>
            </tr>
            <?php while ($item = $lista->fetch_assoc()): ?>
                <?php
                $itemJson = htmlspecialchars(json_encode([
                    'id' => (int) $item['id'],
                    'produto_id' => (int) $item['produto_id'],
                    'meeiro_id' => (int) ($item['meeiro_id'] ?? 0),
                    'quantidade_prevista' => (float) $item['quantidade_prevista'],
                    'data_prevista' => (string) $item['data_prevista'],
                    'produto' => (string) $item['produto'],
                    'meieiro' => (string) ($item['meieiro'] ?? ''),
                    'observacao' => (string) ($item['observacao'] ?? ''),
                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                ?>
                <tr>
                    <td><?= htmlspecialchars($item['produto']) ?></td>
                    <td><?= number_format($item['quantidade_prevista'], 2, ',', '.') ?> kg</td>
                    <td><?= date('d/m/Y', strtotime($item['data_prevista'])) ?></td>
                    <td><?= htmlspecialchars(($item['meeiro_nome'] ?? '') ?: (($temColunaMeieiro ? ($item['meieiro'] ?? '') : '') ?: '-')) ?></td>
                    <td><?= htmlspecialchars($item['observacao'] ?: '-') ?></td>
                    <td><span class="badge-pendente">Pendente</span></td>
                    <td>
                        <div class="acoes">
                            <button type="button" class="btn-primary" title="Confirmar Colheita"
                                onclick='abrirModalComItem(<?= $itemJson ?>)'>
                                <i class="bi bi-check-lg"></i>
                            </button>

                            <!-- Modal de edicao para manter o CRUD da previsao na mesma tela. -->
                            <button type="button" class="btn-edit" title="Editar" onclick='abrirModalEdicao(<?= $itemJson ?>)'>
                                <i class="bi bi-pencil"></i>
                            </button>

                            <!-- Exclui apenas previsoes pendentes. -->
                            <form method="POST" action="actions/excluir_previsao_colheita.php" style="margin:0;" onsubmit="return confirm('Deseja excluir esta previsão?');">
                                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                <button type="submit" class="btn-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endwhile; ?>
        </table>
        </div>
    </div>
</div>

<div id="modalEdicao" class="modal-bg">
    <div class="modal-box">
        <h3>Editar Previsão de Colheita</h3>

        <!-- Formulario de edicao mantido separado do formulario de criacao para leitura mais clara. -->
        <form method="POST" action="actions/editar_previsao_colheita.php" class="form-grid">
            <input type="hidden" name="id" id="edit_id">

            <div>
                <label>Produto</label>
                <select name="produto_id" id="edit_produto_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($produtos as $produto): ?>
                        <option value="<?= (int) $produto['id'] ?>"><?= htmlspecialchars(produtoNomeAgranelColheita((string) $produto['nome'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label>Quantidade Prevista (kg)</label>
                <input type="number" step="0.01" name="quantidade_prevista" id="edit_quantidade_prevista" required>
            </div>

            <div>
                <label>Data Prevista</label>
                <input type="date" name="data_prevista" id="edit_data_prevista" required>
            </div>

            <div>
                <label>Meeiro</label>
                <select name="meeiro_id" id="edit_meeiro_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($meeiros as $meeiro): ?>
                        <option value="<?= (int) $meeiro['id'] ?>"><?= htmlspecialchars((string) $meeiro['nome'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="grid-column:1 / -1;">
                <label>Observacao</label>
                <textarea name="observacao" id="edit_observacao"></textarea>
            </div>

            <div style="grid-column:1 / -1; display:flex; gap:10px; justify-content:flex-end;">
                <button type="submit" class="btn-primary" title="Salvar"><i class="bi bi-check-lg"></i></button>
                <button type="button" class="btn-secondary" title="Cancelar" onclick="fecharModalEdicao()"><i class="bi bi-x-lg"></i></button>
            </div>
        </form>
    </div>
</div>

<div id="modalColheita" class="modal-bg">
    <div class="modal-box">
        <h3>Confirmar Colheita</h3>
        <form method="POST" action="actions/confirmar_colheita.php" onsubmit="return validarConfirmacaoColheita()">
            <input type="hidden" name="id" id="modal_id">
            <p>Quantidade prevista: <strong><span id="modal_quantidade"></span> kg</strong></p>
            <div style="margin-top:15px;">
                <label>Numero do pedido</label>
                <input type="text" name="numero_pedido" id="numero_pedido" required>
                <div id="qualidadeBox" style="display:none;">
                    <label>Distribuicao por qualidade comercial</label>
                    <p class="muted" style="margin:6px 0 10px;">Informe quanto do peso final recebido pertence a cada qualidade. O estoque continua movimentando o produto principal pelo total.</p>
                    <input type="hidden" name="qualidades[0][qualidade_comercial]" value="2a">
                    <label>Peso 2a (kg)</label>
                    <input type="number" step="0.01" min="0" name="qualidades[0][peso_kg]" id="qualidade_2a_peso" oninput="atualizarDistribuicaoQualidade()">
                    <input type="hidden" name="qualidades[1][qualidade_comercial]" value="3a">
                    <label>Peso 3a (kg)</label>
                    <input type="number" step="0.01" min="0" name="qualidades[1][peso_kg]" id="qualidade_3a_peso" oninput="atualizarDistribuicaoQualidade()">
                    <p class="muted" style="margin:8px 0 0;">Soma das qualidades: <strong id="qualidade_total">0,00 kg</strong> | Quantidade final: <strong id="qualidade_final">0,00 kg</strong></p>
                </div>
                <label>Abate / descarte (kg)</label>
                <input type="number" step="0.01" min="0" name="quantidade_abatida" id="quantidade_abatida" oninput="atualizarCamposColheita(); atualizarDistribuicaoQualidade();">
                <label>Detalhar abatimento</label>
                <textarea name="motivo_abatimento" id="motivo_abatimento"></textarea>
                <label>Quantidade real (kg)</label>
                <input type="number" step="0.01" min="0.01" name="quantidade_recebida" id="quantidade_recebida" required oninput="atualizarDistribuicaoQualidade()">
            </div>
            <br>
            <button type="submit" class="btn-primary" title="Confirmar"><i class="bi bi-check-lg"></i></button>
            <button type="button" class="btn-secondary" title="Cancelar" onclick="fecharModal()"><i class="bi bi-x-lg"></i></button>
        </form>
    </div>
</div>

</body>
</html>

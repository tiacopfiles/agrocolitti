<?php
require "../auth/proteger.php";
require "../config/conexao.php";
require "../config/ciclo_helper.php";
require "../config/layout_helper.php";
require "../config/log_helper.php";
require_once "../config/permissions.php";

requirePermission(PERM_ADMIN, '../index.php');

/* ===============================
   CRIAR USUARIO
=============================== */
if(isset($_POST['criar'])){
    $nome = trim($_POST['nome']);
    $senha = password_hash($_POST['senha'], PASSWORD_DEFAULT);
    $nivel = $_POST['nivel'];

    if($nome != "" && $_POST['senha'] != ""){
        $stmt = $conexao->prepare("INSERT INTO usuarios (nome, senha, nivel, ativo) VALUES (?,?,?,1)");
        $stmt->bind_param("sss", $nome, $senha, $nivel);
        $stmt->execute();
        $novoUserId = $conexao->insert_id;
        $stmt->close();
        registrarLog($conexao, 'usuario_criado', 'usuarios', $novoUserId, "Usuario '$nome' criado com nivel '$nivel'");
    }

    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

/* ===============================
   EDITAR USUARIO
=============================== */
if(isset($_POST['editar'])){
    $id    = intval($_POST['id']);
    $nivel = $_POST['nivel'];
    $ativo = intval($_POST['ativo']);

    $stmt = $conexao->prepare("UPDATE usuarios SET nivel=?, ativo=? WHERE id=?");
    $stmt->bind_param("sii", $nivel, $ativo, $id);
    $stmt->execute();
    $stmt->close();

    if(!empty($_POST['nova_senha'])){
        $novaSenha = password_hash($_POST['nova_senha'], PASSWORD_DEFAULT);
        $stmt2 = $conexao->prepare("UPDATE usuarios SET senha=? WHERE id=?");
        $stmt2->bind_param("si", $novaSenha, $id);
        $stmt2->execute();
        $stmt2->close();
    }

    registrarLog($conexao, 'usuario_editado', 'usuarios', $id, "Usuario #$id editado - nivel '$nivel', ativo '$ativo'");

    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

/* ===============================
   SALVAR MODULOS
=============================== */
if(isset($_POST['salvar_modulos'])){
    $id = intval($_POST['id']);

    if($id > 0 && $id !== (int)$_SESSION['usuario_id']){
        $modulos = [];
        if(isset($_POST['modulos']) && is_array($_POST['modulos'])){
            foreach($_POST['modulos'] as $m){
                if(array_key_exists($m, MODULOS_DISPONIVEIS)){
                    $modulos[] = $m;
                }
            }
        }

        if(empty($modulos)){
            $stmt = $conexao->prepare("UPDATE usuarios SET modulos_permitidos=NULL WHERE id=?");
            $stmt->bind_param("i", $id);
        } else {
            $json = json_encode($modulos);
            $stmt = $conexao->prepare("UPDATE usuarios SET modulos_permitidos=? WHERE id=?");
            $stmt->bind_param("si", $json, $id);
        }

        $stmt->execute();
        $stmt->close();

        registrarLog($conexao, 'modulos_editados', 'usuarios', $id, "Modulos do usuario #$id atualizados: " . implode(',', $modulos));
    }

    header("Location: ".$_SERVER['PHP_SELF']."?ok=modulos");
    exit;
}

/* ===============================
   EXCLUIR
=============================== */
if(isset($_GET['excluir'])){
    $idExcluir = intval($_GET['excluir']);

    if($idExcluir != $_SESSION['usuario_id']){
        $stmt = $conexao->prepare("DELETE FROM usuarios WHERE id=?");
        $stmt->bind_param("i", $idExcluir);
        $stmt->execute();
        $stmt->close();
        registrarLog($conexao, 'usuario_excluido', 'usuarios', $idExcluir, "Usuario #$idExcluir excluido");
    }

    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

$usuarios = $conexao->query("SELECT id,nome,nivel,ativo,modulos_permitidos FROM usuarios ORDER BY nome ASC");
$cicloAtivo = getCicloAtivo($conexao);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gerenciar Usuarios - AgroColitti</title>
<style>
.perm-container{padding:24px 30px;max-width:1200px;margin:auto}
.perm-card{background:white;padding:24px;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,0.06);margin-bottom:24px}
.perm-card h2{margin:0 0 16px;font-size:18px;color:#111}
.msg-ok{background:#e8f5e9;color:#1b5e20;border:1px solid #a5d6a7;padding:10px 16px;border-radius:8px;margin-bottom:16px;font-weight:600}
.msg-ciclo{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;padding:10px 16px;border-radius:8px;margin-bottom:16px;font-weight:600}
.form-novo{display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end}
.form-novo .campo{flex:1;min-width:180px}
.form-novo label{display:block;font-size:13px;font-weight:600;margin-bottom:4px;color:#333}
.form-novo input,.form-novo select{width:100%;padding:8px 10px;border:1px solid #ccc;border-radius:8px;font-size:14px}
.btn-primary{background:#1b5e20;color:white;border:none;padding:9px 18px;border-radius:8px;font-weight:600;cursor:pointer}
.btn-primary:hover{background:#145218}
.btn-save{background:#2e7d32;color:white;border:none;padding:6px 12px;border-radius:6px;font-weight:600;cursor:pointer;font-size:13px}
.btn-save:hover{background:#1b5e20}
.btn-modulos{background:#1565c0;color:white;border:none;padding:6px 10px;border-radius:6px;font-weight:600;cursor:pointer;font-size:13px}
.btn-modulos:hover{background:#0d47a1}
.btn-danger{background:#c62828;color:white;border:none;padding:6px 10px;border-radius:6px;font-weight:600;cursor:pointer;font-size:13px}
.btn-danger:hover{background:#9f1e1e}
.btn-fechar{background:#607d8b;color:white;border:none;padding:6px 12px;border-radius:6px;font-weight:600;cursor:pointer;font-size:13px}
.tbl-wrap{width:100%;overflow-x:auto}
table{width:100%;min-width:900px;border-collapse:collapse}
table th{background:#1b5e20;color:white;padding:11px 14px;text-align:left;font-size:13px;font-weight:600;white-space:nowrap}
table td{padding:9px 14px;font-size:13px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
table tbody tr:hover td{background:#f9fbe7}
table select,table input[type=password]{padding:6px 8px;border:1px solid #ccc;border-radius:6px;font-size:13px;width:100%}
.acoes{display:flex;gap:6px;align-items:center}
.badge{display:inline-block;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:700}
.badge-ativo{background:#e8f5e9;color:#2e7d32}
.badge-inativo{background:#fce4e4;color:#c62828}
.tag-fallback{background:#fff3e0;color:#e65100;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;white-space:nowrap}
.tag-custom{background:#e3f2fd;color:#1565c0;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;white-space:nowrap}
/* Linha expandida de modulos */
.modulos-row{display:none}
.modulos-row.open{display:table-row}
.modulos-panel{background:#f0f4ff;border:1px solid #90caf9;border-radius:8px;padding:16px;margin:0}
.modulos-title{font-weight:700;font-size:14px;margin-bottom:4px}
.modulos-hint{font-size:12px;color:#555;margin-bottom:12px}
.modulos-grid{display:flex;flex-wrap:wrap;gap:8px 20px;margin-bottom:14px}
.modulos-grid label{display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;white-space:nowrap}
.modulos-grid input[type=checkbox]{width:15px;height:15px;accent-color:#1565c0;cursor:pointer}
.modulos-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.modulos-status{font-size:12px;color:#555}
@media(max-width:800px){
    .perm-container{padding:16px}
    .form-novo{flex-direction:column}
    .form-novo .campo{width:100%}
}
</style>
<?php renderAppLayoutStyles(); ?>
</head>
<body>

<?php renderAppHeader('..'); ?>

<div class="perm-container">

<?php if(isset($_GET['ok']) && $_GET['ok'] === 'modulos'): ?>
    <div class="msg-ok">&#10003; Permissoes de modulos atualizadas com sucesso!</div>
<?php endif; ?>

<?php if($cicloAtivo): ?>
    <div class="msg-ciclo">&#128337; Ciclo ativo: <?= htmlspecialchars(getNomeCiclo($cicloAtivo)) ?></div>
<?php endif; ?>

    <!-- Novo usuario -->
    <div class="perm-card">
        <h2>Novo Usuario</h2>
        <form method="POST" class="form-novo">
            <div class="campo">
                <label>Nome</label>
                <input type="text" name="nome" placeholder="Nome de usuario" required maxlength="100">
            </div>
            <div class="campo">
                <label>Senha</label>
                <input type="password" name="senha" placeholder="Senha inicial" required>
            </div>
            <div class="campo" style="max-width:180px">
                <label>Nivel</label>
                <select name="nivel">
                    <option value="operador">Operador</option>
                    <option value="operacional">Operacional</option>
                    <option value="colheita">Colheita</option>
                    <option value="fornecedor">Fornecedor</option>
                    <option value="cliente">Cliente</option>
                    <option value="admin">Admin</option>
                    <option value="ti">TI</option>
                </select>
            </div>
            <div>
                <button type="submit" name="criar" class="btn-primary">+ Criar usuario</button>
            </div>
        </form>
    </div>

    <!-- Lista de usuarios -->
    <div class="perm-card">
        <h2>Usuarios</h2>
        <div class="tbl-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Nivel</th>
                        <th>Status</th>
                        <th>Nova Senha</th>
                        <th>Modulos</th>
                        <th>Acoes</th>
                    </tr>
                </thead>
                <tbody>
                <?php while($u = $usuarios->fetch_assoc()):
                    $modulosAtivos = null;
                    if(!empty($u['modulos_permitidos'])){
                        $dec = json_decode($u['modulos_permitidos'], true);
                        if(is_array($dec)) $modulosAtivos = $dec;
                    }
                    $isMe = ((int)$u['id'] === (int)$_SESSION['usuario_id']);
                ?>
                <tr id="row-u-<?= $u['id'] ?>">
                    <form method="POST">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">

                    <td><?= $u['id'] ?></td>
                    <td><strong><?= htmlspecialchars($u['nome']) ?></strong></td>

                    <td>
                        <?php if($isMe): ?>
                            <span><?= htmlspecialchars($u['nivel']) ?></span>
                            <input type="hidden" name="nivel" value="<?= htmlspecialchars($u['nivel']) ?>">
                        <?php else: ?>
                        <select name="nivel" style="min-width:110px">
                            <option value="admin"       <?= $u['nivel']==='admin'       ?'selected':'' ?>>Admin</option>
                            <option value="ti"          <?= $u['nivel']==='ti'          ?'selected':'' ?>>TI</option>
                            <option value="operacional" <?= $u['nivel']==='operacional' ?'selected':'' ?>>Operacional</option>
                            <option value="operador"    <?= $u['nivel']==='operador'    ?'selected':'' ?>>Operador</option>
                            <option value="colheita"    <?= $u['nivel']==='colheita'    ?'selected':'' ?>>Colheita</option>
                            <option value="fornecedor"  <?= $u['nivel']==='fornecedor'  ?'selected':'' ?>>Fornecedor</option>
                            <option value="cliente"     <?= $u['nivel']==='cliente'     ?'selected':'' ?>>Cliente</option>
                        </select>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if($isMe): ?>
                            <span class="badge badge-ativo">Ativo</span>
                            <input type="hidden" name="ativo" value="1">
                        <?php else: ?>
                        <select name="ativo">
                            <option value="1" <?= $u['ativo']==1?'selected':'' ?>>Ativo</option>
                            <option value="0" <?= $u['ativo']==0?'selected':'' ?>>Inativo</option>
                        </select>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if($isMe): ?>
                            <small style="color:#999">-</small>
                        <?php else: ?>
                        <input type="password" name="nova_senha" placeholder="Deixe vazio para manter">
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php if($modulosAtivos !== null): ?>
                            <span class="tag-custom" title="Modulos personalizados definidos">
                                <?= count($modulosAtivos) ?> modulo<?= count($modulosAtivos)!==1?'s':'' ?>
                            </span>
                        <?php else: ?>
                            <span class="tag-fallback" title="Usando permissoes automaticas por nivel">Padrao</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <div class="acoes">
                            <button type="submit" name="editar" class="btn-save" title="Salvar nivel/status/senha">
                                <i class="bi bi-check-lg"></i>
                            </button>
                            <?php if(!$isMe): ?>
                            <button type="button" class="btn-modulos" title="Permissoes de modulos"
                                onclick="toggleModulos(<?= $u['id'] ?>)">
                                <i class="bi bi-shield-lock"></i>
                            </button>
                            <a href="?excluir=<?= $u['id'] ?>"
                               onclick="return confirm('Excluir usuario <?= htmlspecialchars(addslashes($u['nome'])) ?>?')">
                                <button type="button" class="btn-danger" title="Excluir usuario">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </a>
                            <?php else: ?>
                                <small style="color:#999">(voce)</small>
                            <?php endif; ?>
                        </div>
                    </td>
                    </form>
                </tr>

                <?php if(!$isMe): ?>
                <tr class="modulos-row" id="modulos-row-<?= $u['id'] ?>">
                    <td colspan="7" style="padding:12px 14px">
                        <form method="POST">
                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <div class="modulos-panel">
                            <div class="modulos-title">Permissoes de modulos: <?= htmlspecialchars($u['nome']) ?></div>
                            <div class="modulos-hint">
                                Marque os modulos que este usuario pode acessar.
                                Deixe todos desmarcados para usar o padrao do nivel (<?= $u['nivel'] ?>).
                            </div>
                            <div class="modulos-grid">
                            <?php foreach(MODULOS_DISPONIVEIS as $chave => $label):
                                $checked = ($modulosAtivos !== null && in_array($chave, $modulosAtivos, true)) ? 'checked' : '';
                            ?>
                                <label>
                                    <input type="checkbox" name="modulos[]" value="<?= $chave ?>" <?= $checked ?>>
                                    <?= htmlspecialchars($label) ?>
                                </label>
                            <?php endforeach; ?>
                            </div>
                            <div class="modulos-actions">
                                <button type="submit" name="salvar_modulos" class="btn-save">
                                    <i class="bi bi-save"></i> Salvar modulos
                                </button>
                                <button type="button" class="btn-fechar" onclick="toggleModulos(<?= $u['id'] ?>)">
                                    Fechar
                                </button>
                                <span class="modulos-status">
                                    <?php if($modulosAtivos !== null): ?>
                                        Modulos personalizados ativos (<?= count($modulosAtivos) ?> selecionados).
                                    <?php else: ?>
                                        Usando permissao automatica por nivel.
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        </form>
                    </td>
                </tr>
                <?php endif; ?>

                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
function toggleModulos(userId) {
    var row = document.getElementById('modulos-row-' + userId);
    if (!row) return;
    // Fechar outras abertas
    document.querySelectorAll('.modulos-row.open').forEach(function(r){
        if (r !== row) r.classList.remove('open');
    });
    row.classList.toggle('open');
    if (row.classList.contains('open')) {
        setTimeout(function(){ row.scrollIntoView({behavior:'smooth', block:'nearest'}); }, 50);
    }
}
</script>

</body>
</html>

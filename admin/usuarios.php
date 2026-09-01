<?php
require_once '../bootstrap/conexao.php';
require_once '../config/permissions.php';
require_once '../bootstrap/security.php';
require_once 'includes/admin_guard.php';
require_once 'includes/admin_layout.php';
require_once '../config/log_helper.php';
require_once 'includes/csrf.php';

$msg = '';
$niveis = ['admin','ti','operador','colheita','fornecedor','operacional','cliente'];
start_secure_session();

// ── CRIAR ─────────────────────────────────────────────────────────────────────
if (isset($_POST['salvar_modulos'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403); exit('Token CSRF invalido.');
    }
    $uid = security_int($_POST['id'] ?? 0, 0, 1);
    if ($uid > 0 && $uid !== (int)$_SESSION['usuario_id']) {
        $modulosPost = $_POST['modulos'] ?? [];
        $validos = array_keys(MODULOS_DISPONIVEIS);
        $modulosFiltrados = array_values(array_intersect($modulosPost, $validos));
        $json = empty($modulosFiltrados) ? null : json_encode($modulosFiltrados);
        $st = $conexao->prepare("UPDATE usuarios SET modulos_permitidos = ? WHERE id = ?");
        $st->bind_param('si', $json, $uid);
        $st->execute();
        $st->close();
        registrarLog($conexao, 'permissoes_editadas', 'usuarios', $uid, "Modulos atualizados para usuario #$uid");
    }
    header("Location: usuarios.php?msg=ok_perm"); exit;
}

if (isset($_POST['criar'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Token CSRF inválido.');
    }

    $nome  = security_trimmed_string($_POST['nome'] ?? '', 100);
    $senha = $_POST['senha'] ?? '';
    $nivel = in_array($_POST['nivel']??'', $niveis,true) ? $_POST['nivel'] : 'operacional';

    if ($nome !== '' && $senha !== '') {
        $hash = password_hash($senha, PASSWORD_DEFAULT);
        $st = $conexao->prepare("INSERT INTO usuarios (nome, senha, nivel, ativo) VALUES (?,?,?,1)");
        $st->bind_param('sss', $nome, $hash, $nivel);
        $st->execute();
        $nid = $conexao->insert_id;
        $st->close();
        registrarLog($conexao,'usuario_criado','usuarios',$nid,"Usuário '$nome' (nível $nivel) criado pelo painel admin");
        $msg = 'ok_criar';
    }
    header("Location: usuarios.php?msg=$msg"); exit;
}

// ── EDITAR ────────────────────────────────────────────────────────────────────
if (isset($_POST['editar'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Token CSRF inválido.');
    }

    $id    = security_int($_POST['id'] ?? 0, 0, 1);
    $nivel = in_array($_POST['nivel']??'', $niveis,true) ? $_POST['nivel'] : 'operacional';
    $ativo = security_int($_POST['ativo'] ?? 1, 1, 0, 1);

    if ($id > 0) {
        $st = $conexao->prepare("UPDATE usuarios SET nivel=?, ativo=? WHERE id=?");
        $st->bind_param('sii', $nivel, $ativo, $id);
        $st->execute();
        $st->close();

        if (!empty($_POST['nova_senha'])) {
            $hash = password_hash((string) $_POST['nova_senha'], PASSWORD_DEFAULT);
            $st2 = $conexao->prepare("UPDATE usuarios SET senha=? WHERE id=?");
            $st2->bind_param('si', $hash, $id);
            $st2->execute();
            $st2->close();
        }

        registrarLog($conexao,'usuario_editado','usuarios',$id,"Usuário #$id editado - nível $nivel, ativo $ativo");
    }

    header("Location: usuarios.php?msg=ok_edit"); exit;
}

// ── EXCLUIR ───────────────────────────────────────────────────────────────────
if (isset($_POST['excluir'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Token CSRF inválido.');
    }

    $xid = security_int($_POST['id'] ?? 0, 0, 1);
    if ($xid > 0 && $xid !== (int)$_SESSION['usuario_id']) {
        $stDel = $conexao->prepare("DELETE FROM usuarios WHERE id = ?");
        $stDel->bind_param('i', $xid);
        $stDel->execute();
        $stDel->close();

        registrarLog($conexao,'usuario_excluido','usuarios',$xid,"Usuário #$xid excluído pelo painel admin");
    }
    header("Location: usuarios.php?msg=ok_del"); exit;
}

$usuarios = $conexao->query("SELECT * FROM usuarios ORDER BY nome ASC");
$msgs = ['ok_criar'=>'Usuário criado com sucesso.','ok_edit'=>'Usuário atualizado.','ok_del'=>'Usuário excluído.','ok_perm'=>'Permissoes atualizadas com sucesso.'];
$msgTxt = $msgs[$_GET['msg'] ?? ''] ?? '';

function nivelBadge(string $n): string {
    $map = ['admin'=>'b-r','ti'=>'b-c','operador'=>'b-p','colheita'=>'b-g','fornecedor'=>'b-y','operacional'=>'b-p','cliente'=>'b-gr'];
    return '<span class="badge '.($map[$n]??'b-gr').'">'.htmlspecialchars($n).'</span>';
}

adminHead('Usuários');
?>
<body>
<?php adminSidebar('usuarios'); ?>
<?php adminOpenMain('Gerenciar Usuários', 'Criar, editar e remover usuários do sistema'); ?>

<?php if ($msgTxt): ?>
<div style="background:rgba(52,211,153,.1);border:1px solid rgba(52,211,153,.25);color:var(--green);padding:10px 16px;border-radius:8px;margin-bottom:18px;font-size:13px;">
    <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($msgTxt) ?>
</div>
<?php endif; ?>

<!-- Criar usuário -->
<div class="panel" style="margin-bottom:20px;">
    <div class="panel-hdr">
        <div class="panel-title"><i class="fa-solid fa-user-plus"></i> Novo Usuário</div>
    </div>
    <div class="panel-body">
        <form method="POST">
            <?= csrf_field() ?>
            <div class="form-row">
                <div class="form-grp">
                    <label class="form-lbl">Nome</label>
                    <input type="text" name="nome" class="form-inp" required placeholder="Nome de usuário">
                </div>
                <div class="form-grp">
                    <label class="form-lbl">Senha</label>
                    <input type="password" name="senha" class="form-inp" required placeholder="••••••••">
                </div>
                <div class="form-grp" style="max-width:200px;">
                    <label class="form-lbl">Nível</label>
                    <select name="nivel" class="form-sel">
                        <?php foreach ($niveis as $n): ?>
                        <option value="<?= $n ?>"><?= ucfirst($n) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="align-self:flex-end;">
                    <button type="submit" name="criar" class="btn btn-g">
                        <i class="fa-solid fa-plus"></i> Criar
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Lista -->
<div class="panel">
    <div class="panel-hdr">
        <div class="panel-title"><i class="fa-solid fa-users"></i> Usuários cadastrados</div>
        <span class="panel-pill"><?= $usuarios->num_rows ?> usuários</span>
    </div>
    <div class="panel-body" style="padding:0;">
        <div class="tbl-wrap">
            <table class="adm-tbl">
                <thead>
                    <tr>
                        <th>ID</th><th>Nome</th><th>Nível atual</th><th>Status</th>
                        <th>Cadastrado em</th><th>Alterar nível</th><th>Nova senha</th><th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($u = $usuarios->fetch_assoc()): ?>
                    <tr>
                        <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <td style="color:var(--txt3);font-size:11px;">#<?= $u['id'] ?></td>
                        <td style="font-weight:600;"><?= htmlspecialchars($u['nome']) ?></td>
                        <td><?= nivelBadge($u['nivel']) ?></td>
                        <td>
                            <?php if ($u['ativo']): ?>
                                <span class="badge b-g">Ativo</span>
                            <?php else: ?>
                                <span class="badge b-gr">Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:11px;color:var(--txt3);">
                            <?= date('d/m/Y', strtotime($u['criado_em'])) ?>
                        </td>
                        <td>
                            <?php if ($u['id'] != $_SESSION['usuario_id']): ?>
                            <div style="display:flex;gap:6px;align-items:center;">
                                <select name="nivel" class="form-sel" style="width:130px;padding:5px 8px;font-size:12px;">
                                    <?php foreach ($niveis as $n): ?>
                                    <option value="<?= $n ?>" <?= $u['nivel']===$n?'selected':'' ?>><?= ucfirst($n) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="ativo" class="form-sel" style="width:90px;padding:5px 8px;font-size:12px;">
                                    <option value="1" <?= $u['ativo']==1?'selected':'' ?>>Ativo</option>
                                    <option value="0" <?= $u['ativo']==0?'selected':'' ?>>Inativo</option>
                                </select>
                            </div>
                            <?php else: echo '<span style="color:var(--txt3);font-size:12px;">Própria conta</span>'; endif; ?>
                        </td>
                        <td>
                            <?php if ($u['id'] != $_SESSION['usuario_id']): ?>
                            <input type="password" name="nova_senha" class="form-inp" style="width:130px;padding:5px 8px;font-size:12px;" placeholder="••••••">
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u['id'] != $_SESSION['usuario_id']): ?>
                            <div style="display:flex;gap:6px;">
                                <button type="submit" name="editar" class="btn btn-c" style="padding:5px 10px;font-size:11px;">
                                    <i class="fa-solid fa-floppy-disk"></i> Salvar
                                </button>
                                <button type="submit" name="excluir" value="1"
                                   onclick="return confirm('Excluir <?= htmlspecialchars(addslashes($u['nome'])) ?>?')"
                                   class="btn btn-r" style="padding:5px 10px;font-size:11px;">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                            <?php else: echo '<span style="color:var(--txt3)">—</span>'; endif; ?>
                        </td>
                        </form>
                    </tr>
                    <tr>
                      <td colspan="8" style="padding:0;border-top:none;border-bottom:1px solid #e5e7eb;">
                        <button type="button" onclick="var r=document.getElementById('modulos-'+<?= $u['id'] ?>);r.style.display=r.style.display==='none'?'table-row':'none';" style="width:100%;padding:4px 16px;font-size:11px;background:#f1f5f9;border:none;cursor:pointer;color:#374151;text-align:left;">
                          <i class="fa-solid fa-key"></i> Modulos de <?= htmlspecialchars($u['nome']) ?> &darr;
                        </button>
                      </td>
                    </tr>
                    <?php
                    $modulosU2 = isset($u['modulos_permitidos']) && $u['modulos_permitidos'] ? json_decode($u['modulos_permitidos'], true) : null;
                    $nivelU2 = $u['nivel'];
                    ?>
                    <tr id="modulos-<?= $u['id'] ?>" style="display:none;">
                    <td colspan="8" style="background:#f8fafc;padding:12px 16px;border-top:1px solid #e5e7eb;">
                      <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:8px;">
                          <strong style="font-size:12px;color:#374151;margin-right:4px;">
                            <i class="fa-solid fa-key"></i> Modulos de <?= htmlspecialchars($u['nome']) ?>:
                          </strong>
                          <?php foreach (MODULOS_DISPONIVEIS as $mk => $ml):
                              $chk = ($nivelU2 === 'ti') ? true : ($modulosU2 !== null ? in_array($mk, $modulosU2) : in_array($nivelU2, MODULO_NIVEL_FALLBACK[$mk] ?? []));
                              $dis = ($nivelU2 === 'ti' || $u['id'] == $_SESSION['usuario_id']) ? 'disabled' : '';
                          ?>
                          <label style="display:flex;align-items:center;gap:4px;font-size:12px;background:white;padding:4px 8px;border-radius:6px;border:1px solid #e5e7eb;cursor:pointer;">
                            <input type="checkbox" name="modulos[]" value="<?= $mk ?>" <?= $chk ? 'checked' : '' ?> <?= $dis ?>>
                            <?= htmlspecialchars($ml) ?>
                          </label>
                          <?php endforeach; ?>
                        </div>
                        <?php if ($u['id'] != $_SESSION['usuario_id'] && $nivelU2 !== 'ti'): ?>
                        <button type="submit" name="salvar_modulos" class="btn btn-c" style="padding:4px 12px;font-size:12px;">
                          <i class="fa-solid fa-floppy-disk"></i> Salvar permissoes
                        </button>
                        <?php else: ?>
                        <span style="font-size:11px;color:#6b7280;">
                          <?= $nivelU2 === 'ti' ? 'TI tem acesso total.' : 'Propria conta.' ?>
                        </span>
                        <?php endif; ?>
                      </form>
                    </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php adminFooter(); ?>

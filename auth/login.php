<?php
require "../config/conexao.php";
require_once "../config/log_helper.php";
require_once "../bootstrap/security.php";
require_once "../admin/includes/csrf.php";

start_secure_session();

if (isset($_POST['login'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $erro = "Requisicao invalida. Tente novamente.";
    } else {
        $nome = security_trimmed_string($_POST['nome'] ?? '', 100);
        $senhaInformada = (string) ($_POST['senha'] ?? '');
        $ip = security_client_ip();

        if ($nome === '' || $senhaInformada === '') {
            $erro = "Informe usuario e senha.";
        } elseif (login_is_rate_limited($conexao, $ip, $nome)) {
            $erro = "Muitas tentativas invalidas. Aguarde 15 minutos e tente novamente.";
        } else {
            $stmt = $conexao->prepare("SELECT * FROM usuarios WHERE nome = ? LIMIT 1");
            $stmt->bind_param("s", $nome);
            $stmt->execute();
            $result = $stmt->get_result();

            $senhaCorreta = false;
            $usuario = null;

            if ($result->num_rows === 1) {
                $usuario = $result->fetch_assoc();

                if (password_verify($senhaInformada, $usuario['senha'])) {
                    $senhaCorreta = true;
                } elseif ($usuario['senha'] === md5($senhaInformada)) {
                    $senhaCorreta = true;
                    $novoHash = password_hash($senhaInformada, PASSWORD_DEFAULT);
                    $stmtMig = $conexao->prepare("UPDATE usuarios SET senha = ? WHERE id = ?");
                    $stmtMig->bind_param("si", $novoHash, $usuario['id']);
                    $stmtMig->execute();
                    $stmtMig->close();
                }
            }

            if ($senhaCorreta && $usuario) {
                session_regenerate_id(true);

                $_SESSION['usuario_id'] = $usuario['id'];
                $_SESSION['usuario_nome'] = $usuario['nome'];
                $_SESSION['usuario_nivel'] = $usuario['nivel'];
                $_SESSION['modulos_permitidos'] = isset($usuario['modulos_permitidos']) && $usuario['modulos_permitidos'] !== null ? json_decode($usuario['modulos_permitidos'], true) : null;

                unset($_SESSION['csrf_token']);
                generate_csrf_token();

                registrarLog(
                    $conexao,
                    'login_sucesso',
                    'usuarios',
                    (int) $usuario['id'],
                    "Login bem-sucedido - usuario '{$usuario['nome']}' (nivel: {$usuario['nivel']})",
                    (string) $usuario['nivel'],
                    true
                );

                if ($usuario['nivel'] === 'ti') {
                    header("Location: ../admin/dashboard.php");
                } else {
                    header("Location: ../index.php");
                }
                exit;
            } else {
                $erro = "Usuario ou senha invalidos!";
                registrarLog(
                    $conexao,
                    'login_falha',
                    'usuarios',
                    0,
                    "Tentativa de login falhou - usuario informado: '{$nome}'",
                    isset($usuario['nivel']) ? (string) $usuario['nivel'] : null,
                    false
                );
            }

            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="../assets/img/favicon.ico?v=20260601b" sizes="any">
<link rel="icon" type="image/svg+xml" href="../assets/img/favicon.svg?v=20260601b">
<link rel="icon" type="image/png" sizes="32x32" href="../assets/img/favicon-32.png?v=20260601b">
<link rel="icon" type="image/png" sizes="16x16" href="../assets/img/favicon-16.png?v=20260601b">
<link rel="apple-touch-icon" sizes="180x180" href="../assets/img/apple-touch-icon.png?v=20260601b">
<title>Login - Sistema AgroColitti</title>
<style>
:root{
    --verde:#166534;
    --verde-escuro:#14532d;
    --verde-suave:#e8f5ec;
    --laranja:#f59e32;
    --vinho:#9f2f37;
    --terra:#b46838;
    --fundo:#eef3f1;
    --texto:#111827;
    --muted:#64748b;
    --borda:#dfe7e2;
    --sombra:0 22px 70px rgba(15,23,42,.18);
}
*{box-sizing:border-box}
html,body{min-height:100%}
body{
    margin:0;
    min-height:100vh;
    display:grid;
    place-items:center;
    padding:24px;
    overflow-x:hidden;
    font-family:"Inter","Segoe UI",Arial,sans-serif;
    color:var(--texto);
    background:
        radial-gradient(circle at 24% 18%, rgba(245,158,50,.13), transparent 28%),
        radial-gradient(circle at 82% 74%, rgba(22,101,52,.14), transparent 30%),
        linear-gradient(135deg,#166534 0%,#2f8f46 48%,#70b869 100%);
}
.login-shell{
    width:100%;
    max-width:430px;
}
.login-brand-panel{
    display:none;
}
.login-panel{
    display:block;
}
.login-container{
    width:100%;
    padding:34px 38px 30px;
    text-align:left;
    background:rgba(255,255,255,.96);
    border:1px solid rgba(223,231,226,.95);
    border-radius:18px;
    box-shadow:0 18px 48px rgba(15,23,42,.22);
}
.login-logo{
    width:172px;
    margin:0 auto 22px;
}
.login-logo img{
    display:block;
    width:100%;
    height:auto;
}
.login-container h2{
    margin:0;
    color:var(--texto);
    font-size:23px;
    line-height:1.15;
    letter-spacing:0;
    text-align:center;
}
.login-subtitle{
    margin:8px 0 24px;
    color:var(--muted);
    font-size:13px;
    line-height:1.5;
    text-align:center;
}
.input-group{margin-bottom:16px}
.input-group label{
    display:block;
    margin-bottom:7px;
    color:#243247;
    font-weight:800;
    font-size:13px;
}
.input-control{position:relative}
.input-control input{
    width:100%;
    min-height:44px;
    padding:11px 13px 11px 42px;
    border-radius:12px;
    border:1px solid var(--borda);
    background:#fff;
    color:var(--texto);
    font-size:15px;
    transition:border-color .16s ease, box-shadow .16s ease;
}
.input-control input:focus{
    border-color:var(--verde);
    box-shadow:0 0 0 4px rgba(22,101,52,.12);
    outline:none;
}
.input-control::before{
    position:absolute;
    left:14px;
    top:50%;
    transform:translateY(-50%);
    color:#64748b;
    font-size:16px;
    z-index:1;
}
.input-control-user::before{content:"@"}
.input-control-pass::before{content:"●";font-size:12px}
button{
    width:100%;
    min-height:46px;
    padding:12px 16px;
    background:var(--verde);
    color:white;
    border:none;
    border-radius:12px;
    font-size:15px;
    font-weight:850;
    cursor:pointer;
    transition:background .16s ease, transform .16s ease, box-shadow .16s ease;
    box-shadow:0 10px 22px rgba(22,101,52,.22);
}
button:hover{background:var(--verde-escuro);transform:translateY(-1px)}
button:focus-visible{outline:3px solid rgba(245,158,50,.35);outline-offset:3px}
.erro{
    margin:0 0 18px;
    padding:12px 14px;
    background:#fff1f2;
    color:#9f1239;
    border:1px solid #fecdd3;
    border-radius:12px;
    font-size:14px;
    font-weight:700;
}
.footer{
    margin-top:20px;
    padding-top:18px;
    border-top:1px solid var(--borda);
    color:var(--muted);
    font-size:12px;
    text-align:center;
}
.footer strong{color:var(--verde)}
@media (max-width:900px){
    body{padding:18px}
}
@media (max-width:520px){
    body{padding:18px}
    .login-container{padding:28px 24px 26px;border-radius:16px}
    .login-logo{width:150px}
}
</style>
</head>
<body>
<main class="login-shell">
    <section class="login-panel">
        <div class="login-container">
            <div class="login-logo">
                <img src="../assets/img/agrocolittilogo.png" alt="Grupo AgroColitti">
            </div>
            <h2>Entrar no sistema</h2>
            <p class="login-subtitle">Acesse sua area de trabalho AgroColitti.</p>

            <?php if (isset($erro)): ?>
                <div class="erro"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

                <div class="input-group">
                    <label for="nome">Usuario</label>
                    <div class="input-control input-control-user">
                        <input type="text" id="nome" name="nome" maxlength="100" autocomplete="username" required autofocus>
                    </div>
                </div>

                <div class="input-group">
                    <label for="senha">Senha</label>
                    <div class="input-control input-control-pass">
                        <input type="password" id="senha" name="senha" autocomplete="current-password" required>
                    </div>
                </div>

                <button type="submit" name="login">Entrar</button>
            </form>

            <div class="footer">
                <strong>AgroColitti</strong> - Controle de Estoque Agricola
            </div>
        </div>
    </section>
</main>
</body>
</html>

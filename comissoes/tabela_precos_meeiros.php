<?php
require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../auth/proteger.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../config/layout_helper.php';
require_once __DIR__ . '/meeiros_helper_v2.php';

requirePermission(PERM_ADMIN, '../index.php');
comissoesMeeirosGarantirEstrutura($conexao);

$produtosStmt = $conexao->prepare("SELECT id, nome FROM produtos WHERE ativo = 1 AND produto_principal_id IS NULL ORDER BY nome ASC");
$produtosStmt->execute();
$produtos = $produtosStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$produtosStmt->close();

$precos = $conexao->query("
    SELECT tpm.*, p.nome AS produto_cadastro
    FROM tabela_precos_meeiros tpm
    LEFT JOIN produtos p ON p.id = tpm.produto_id
    ORDER BY COALESCE(p.nome, tpm.produto_nome), tpm.id
")->fetch_all(MYSQLI_ASSOC);

function qualidadeMeeiroOpcoes(array $produto, string $qualidadeAtual = ''): string
{
    $qualidadeAtual = strtolower(trim($qualidadeAtual));
    $html = '<select name="linhas[__IDX__][qualidade_label]">';
    $html .= '<option value=""' . ($qualidadeAtual === '' ? ' selected' : '') . '>Padrao</option>';
    $html .= '<option value="2a"' . ($qualidadeAtual === '2a' ? ' selected' : '') . '>2a</option>';
    $html .= '<option value="3a"' . ($qualidadeAtual === '3a' ? ' selected' : '') . '>3a</option>';
    $html .= '</select>';
    return $html;
}

function precoMeeiroMensagem(string $msg): ?array
{
    return match ($msg) {
        'salvo' => ['ok', 'Tabela de precos salva.'],
        'apagado' => ['ok', 'Preco apagado.'],
        'erro' => ['erro', 'Nao foi possivel concluir a operacao.'],
        default => null,
    };
}

$mensagem = precoMeeiroMensagem((string) ($_GET['msg'] ?? ''));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tabela de precos dos meeiros</title>
<style>
body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;color:#1f2937}.container{max-width:1320px;margin:auto;padding:30px}.page-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:20px}.page-head h1{margin:0;color:#1b5e20;font-size:26px}.page-head p{margin:6px 0 0;color:#64748b}.actions-top{display:flex;gap:10px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 14px;border:0;border-radius:7px;background:#e8f5e9;color:#1b5e20;text-decoration:none;font-weight:700;cursor:pointer}.btn-primary{background:#1b5e20;color:#fff}.btn-danger{background:#fee2e2;color:#991b1b}.card{background:#fff;border-radius:10px;padding:18px;box-shadow:0 5px 16px rgba(15,23,42,.06)}.table-wrap{overflow:auto;border:1px solid #d9e2dc;border-radius:8px}table{width:100%;min-width:980px;border-collapse:collapse}th{position:sticky;top:0;background:#1b5e20;color:#fff;padding:10px;text-align:left;font-size:13px;z-index:1}td{padding:0;border:1px solid #e5e7eb;background:#fff}tr:hover td{background:#f8fbf8}input,select{width:100%;height:40px;border:0;background:transparent;padding:8px 10px;font-size:14px;color:#111827}input:focus,select:focus{outline:2px solid #66a76f;outline-offset:-2px;background:#fff}.cell-check{text-align:center}.cell-check input{width:18px;height:18px}.cell-actions{display:flex;justify-content:center;align-items:center;height:40px}.empty{text-align:center;padding:28px;color:#64748b}.msg{padding:12px 14px;border-radius:8px;margin-bottom:16px;font-weight:700}.msg-ok{background:#dcfce7;color:#166534}.msg-erro{background:#fee2e2;color:#991b1b}.hint{margin-top:12px;color:#64748b;font-size:13px}@media(max-width:850px){.container{padding:18px}.page-head{display:block}.actions-top{margin-top:14px}}
</style>
<?php renderAppLayoutStyles(); ?>
</head>
<body>
<?php renderAppHeader('..'); ?>
<main class="container page-container app-shell">
  <div class="page-head">
    <div>
      <h1>Tabela de precos dos meeiros</h1>
      <p>Fonte de preco usada no calculo: peso que entrou x preco/kg.</p>
    </div>
    <div class="actions-top">
      <a class="btn" href="meeiros.php"><i class="bi bi-arrow-left"></i> Voltar</a>
      <button class="btn btn-primary" form="formTabelaPrecosMeeiros" type="submit"><i class="bi bi-check-lg"></i> Salvar</button>
    </div>
  </div>

  <?php if ($mensagem): ?>
    <div class="msg msg-<?= $mensagem[0] ?>"><?= htmlspecialchars($mensagem[1], ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <section class="card">
    <form id="formTabelaPrecosMeeiros" method="POST" action="actions/salvar_tabela_precos_meeiros.php">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th style="width:34%;">Produto cadastrado</th>
              <th style="width:34%;">Nome na tabela</th>
              <th style="width:8%;">Qualidade</th>
              <th style="width:16%;">Preco/kg</th>
              <th style="width:8%;">Ativo</th>
              <th style="width:8%;">Apagar</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($precos as $i => $preco): ?>
              <tr>
                <td>
                  <input type="hidden" name="linhas[<?= $i ?>][id]" value="<?= (int) $preco['id'] ?>">
                  <select name="linhas[<?= $i ?>][produto_id]">
                    <option value="">Sem vinculo</option>
                    <?php foreach ($produtos as $produto): ?>
                      <option value="<?= (int) $produto['id'] ?>" <?= (int) ($preco['produto_id'] ?? 0) === (int) $produto['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $produto['nome'], ENT_QUOTES, 'UTF-8') ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </td>
                <td><input type="text" name="linhas[<?= $i ?>][produto_nome]" value="<?= htmlspecialchars((string) $preco['produto_nome'], ENT_QUOTES, 'UTF-8') ?>" required></td>
                <td>
                  <select name="linhas[<?= $i ?>][qualidade_label]">
                    <option value="" <?= trim((string) ($preco['qualidade_label'] ?? '')) === '' ? 'selected' : '' ?>>Padrao</option>
                    <option value="2a" <?= strtolower(trim((string) ($preco['qualidade_label'] ?? ''))) === '2a' ? 'selected' : '' ?>>2a</option>
                    <option value="3a" <?= strtolower(trim((string) ($preco['qualidade_label'] ?? ''))) === '3a' ? 'selected' : '' ?>>3a</option>
                  </select>
                </td>
                <td><input type="number" name="linhas[<?= $i ?>][valor_por_kg]" value="<?= number_format((float) $preco['valor_por_kg'], 2, '.', '') ?>" step="0.01" min="0.01" required></td>
                <td class="cell-check"><input type="checkbox" name="linhas[<?= $i ?>][ativo]" value="1" <?= (int) $preco['ativo'] === 1 ? 'checked' : '' ?>></td>
                <td>
                  <div class="cell-actions">
                    <button class="btn btn-danger" type="submit" form="apagarPreco<?= (int) $preco['id'] ?>" title="Apagar"><i class="bi bi-trash"></i></button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php $novoIndex = count($precos); ?>
            <tr>
              <td>
                <input type="hidden" name="linhas[<?= $novoIndex ?>][id]" value="0">
                <select name="linhas[<?= $novoIndex ?>][produto_id]">
                  <option value="">Sem vinculo</option>
                  <?php foreach ($produtos as $produto): ?>
                    <option value="<?= (int) $produto['id'] ?>"><?= htmlspecialchars((string) $produto['nome'], ENT_QUOTES, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="text" name="linhas[<?= $novoIndex ?>][produto_nome]" placeholder="Novo produto"></td>
              <td>
                <select name="linhas[<?= $novoIndex ?>][qualidade_label]">
                  <option value="">Padrao</option>
                  <option value="2a">2a</option>
                  <option value="3a">3a</option>
                </select>
              </td>
              <td><input type="number" name="linhas[<?= $novoIndex ?>][valor_por_kg]" step="0.01" min="0.01" placeholder="0.00"></td>
              <td class="cell-check"><input type="checkbox" name="linhas[<?= $novoIndex ?>][ativo]" value="1" checked></td>
              <td></td>
            </tr>
          </tbody>
        </table>
      </div>
    </form>
    <?php foreach ($precos as $preco): ?>
      <form id="apagarPreco<?= (int) $preco['id'] ?>" method="POST" action="actions/apagar_tabela_precos_meeiros.php" onsubmit="return confirm('Apagar este preco da tabela?');">
        <input type="hidden" name="id" value="<?= (int) $preco['id'] ?>">
      </form>
    <?php endforeach; ?>
    <p class="hint">As comissoes ja confirmadas mantem o preco salvo no snapshot. Alterar esta tabela afeta apenas novas confirmacoes.</p>
  </section>
</main>
</body>
</html>

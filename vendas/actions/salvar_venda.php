<?php
require __DIR__ . "/../../config/conexao.php";
require __DIR__ . "/../../config/ciclo_helper.php";
require __DIR__ . "/../../config/calculos_preco.php";
require __DIR__ . "/../../config/log_helper.php";
require __DIR__ . "/../../auth/proteger.php";
require __DIR__ . "/../../config/cadastro_fiscal_helper.php";
require __DIR__ . "/../venda_helper.php";
require __DIR__ . "/../../comissoes/vendedores_helper.php";

function colunaTipoExiste(mysqli $conexao): bool
{
    static $existe = null;

    if ($existe !== null) {
        return $existe;
    }

    $resultado = $conexao->query("SHOW COLUMNS FROM vendas LIKE 'tipo'");
    $existe = $resultado && $resultado->num_rows > 0;

    if ($resultado instanceof mysqli_result) {
        $resultado->free();
    }

    return $existe;
}

function colunaPrecoExiste(mysqli $conexao): bool
{
    static $existe = null;

    if ($existe !== null) {
        return $existe;
    }

    $resultado = $conexao->query("SHOW COLUMNS FROM vendas LIKE 'preco'");
    $existe = $resultado && $resultado->num_rows > 0;

    if ($resultado instanceof mysqli_result) {
        $resultado->free();
    }

    return $existe;
}

function colunaTipoComercialExiste(mysqli $conexao): bool
{
    static $existe = null;

    if ($existe !== null) {
        return $existe;
    }

    $resultado = $conexao->query("SHOW COLUMNS FROM vendas LIKE 'tipo_comercial'");
    $existe = $resultado && $resultado->num_rows > 0;

    if ($resultado instanceof mysqli_result) {
        $resultado->free();
    }

    return $existe;
}

function garantirColunaFreteVenda(mysqli $conexao): void
{
    $resultado = $conexao->query("SHOW COLUMNS FROM vendas LIKE 'frete'");
    $existe = $resultado && $resultado->num_rows > 0;

    if ($resultado instanceof mysqli_result) {
        $resultado->free();
    }

    if (!$existe) {
        $conexao->query("ALTER TABLE vendas ADD COLUMN frete DECIMAL(10,2) NOT NULL DEFAULT 0.00");
    }
}

function montarQueryRetorno(array $params): string
{
    $filtrados = [];
    foreach ($params as $chave => $valor) {
        if ($valor === null || $valor === '') {
            continue;
        }
        $filtrados[$chave] = $valor;
    }

    return http_build_query($filtrados);
}

function normalizarNomeArquivoUpload(string $valor): string
{
    $valor = trim($valor);
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
    if (is_string($ascii) && $ascii !== '') {
        $valor = $ascii;
    }
    $valor = preg_replace('/[^A-Za-z0-9]+/', '_', $valor) ?? 'arquivo';
    $valor = trim($valor, '_');
    return $valor !== '' ? strtolower($valor) : 'arquivo';
}

function gerarNomeArquivoUploadUnico(string $pasta, string $nomeBase, string $extensao): string
{
    $nomeBase = normalizarNomeArquivoUpload($nomeBase);
    $nomeArquivo = $nomeBase . '.' . $extensao;
    $contador = 2;

    while (is_file($pasta . $nomeArquivo)) {
        $nomeArquivo = $nomeBase . '_' . $contador . '.' . $extensao;
        $contador++;
    }

    return $nomeArquivo;
}

function buscarNomeClienteUpload(mysqli $conexao, int $clienteId): string
{
    $stmt = $conexao->prepare("SELECT nome FROM clientes WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $clienteId);
    $stmt->execute();
    $cliente = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (string) ($cliente['nome'] ?? 'cliente');
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../vendas.php");
    exit;
}

function garantirColunasMontagemVenda(mysqli $conexao): void
{
    $colunas = [
        'usuario_montagem_id' => "ALTER TABLE vendas ADD COLUMN usuario_montagem_id INT NULL DEFAULT NULL AFTER foto",
        'usuario_montagem_nome' => "ALTER TABLE vendas ADD COLUMN usuario_montagem_nome VARCHAR(120) NULL DEFAULT NULL AFTER usuario_montagem_id",
    ];
    foreach ($colunas as $coluna => $sql) {
        $resultado = $conexao->query("SHOW COLUMNS FROM vendas LIKE '" . $conexao->real_escape_string($coluna) . "'");
        $existe = $resultado && $resultado->num_rows > 0;
        if ($resultado instanceof mysqli_result) $resultado->free();
        if (!$existe) $conexao->query($sql);
    }
}

function usuarioTemOsVendaEmMontagem(mysqli $conexao, string $numeroOs, int $usuarioId): bool
{
    if ($numeroOs === '') return false;
    $stmt = $conexao->prepare("
        SELECT id FROM vendas
        WHERE numero_os = ? AND status = 'anexado' AND usuario_montagem_id = ?
        LIMIT 1
    ");
    $stmt->bind_param('si', $numeroOs, $usuarioId);
    $stmt->execute();
    $tem = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $tem;
}

function osEstaPendente(mysqli $conexao, string $numeroOs): bool
{
    if ($numeroOs === '') return false;
    $stmt = $conexao->prepare("SELECT id FROM vendas WHERE numero_os = ? AND status = 'pendente' LIMIT 1");
    $stmt->bind_param('s', $numeroOs);
    $stmt->execute();
    $existe = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $existe;
}

function osEmMontagemDeOutroUsuario(mysqli $conexao, string $numeroOs, int $usuarioId): bool
{
    if ($numeroOs === '') return false;
    $stmt = $conexao->prepare("
        SELECT id FROM vendas
        WHERE numero_os = ? AND status = 'anexado'
          AND usuario_montagem_id IS NOT NULL
          AND usuario_montagem_id <> ?
        LIMIT 1
    " );
    $stmt->bind_param('si', $numeroOs, $usuarioId);
    $stmt->execute();
    $existe = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $existe;
}

validarTokenCsrf();
garantirColunaFreteVenda($conexao);
garantirColunasMontagemVenda($conexao);
comissoesVendedoresGarantirEstrutura($conexao);
garantirColunasObaCaixa($conexao);

// Migration incremental: adiciona coluna foto se não existir
$_checkFoto = $conexao->query("SHOW COLUMNS FROM vendas LIKE 'foto'");
if ($_checkFoto && $_checkFoto->num_rows === 0) {
    $conexao->query("ALTER TABLE vendas ADD COLUMN foto VARCHAR(255) DEFAULT NULL");
}
if ($_checkFoto instanceof mysqli_result) {
    $_checkFoto->free();
}

// Colunas extras de vendas são criadas via database/migrations.sql.
// ALTER TABLE foi removido do runtime.

$cliente_id = intval($_POST['cliente_id'] ?? 0);
$ciclo_id   = getCicloIdParaTabela($conexao, 'vendas');

$conexao->begin_transaction();

try {
    $data_venda      = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
    $previsao_entrega_input = trim((string) ($_POST['previsao_entrega'] ?? ($_POST['data_venda'] ?? '')));
    $produto_id      = intval($_POST['produto_id']);
    $cliente_id      = intval($_POST['cliente_id']);
    $tipo            = strtolower(trim($_POST['tipo']           ?? ''));
    $tipo_com_raw    = strtolower(trim($_POST['tipo_comercial'] ?? ''));
    $tipo_comercial  = in_array($tipo_com_raw, ['embalado', 'oba_embalado', 'atacado', 'atacado_convencional', 'shopper'], true) ? $tipo_com_raw : null;
    if (in_array($tipo_comercial, ['embalado', 'oba_embalado'], true)) {
        $tipo = 'bandeja';
    } elseif (in_array($tipo_comercial, ['atacado', 'atacado_convencional', 'shopper'], true)) {
        $tipo = 'kg';
    }
    $prazo_raw       = trim($_POST['prazo_escolhido'] ?? '');
    $prazo_escolhido = in_array($prazo_raw, ['5_dias', '30_dias'], true) ? $prazo_raw : null;
    $numero_os           = trim($_POST['numero_os'] ?? '');
    $prazo_pagamento_raw = trim($_POST['prazo_pagamento'] ?? '');
    $prazo_pagamento     = in_array($prazo_pagamento_raw, ['5 dias', '7 dias', '15 dias', '21 dias', '30 dias', '35 dias', '45 dias'], true) ? $prazo_pagamento_raw : '';
    $forma_raw           = strtolower(trim($_POST['forma_pagamento'] ?? ''));
    $forma_pagamento     = in_array($forma_raw, ['boleto', 'deposito', 'pix'], true) ? $forma_raw : null;
    $previsao_dt         = DateTimeImmutable::createFromFormat('Y-m-d', $previsao_entrega_input);
    $previsao_entrega_db = ($previsao_dt && $previsao_dt->format('Y-m-d') === $previsao_entrega_input) ? $previsao_entrega_input : null;
    $entreposto           = trim((string) ($_POST['entreposto'] ?? ''));
    $consideracoes       = trim($_POST['consideracoes'] ?? '');
    $vendedor            = comissoesVendedoresNormalizarNome((string) ($_POST['vendedor'] ?? '')) ?? '';
    $vendedor_usuario_id = null;
    if ($vendedor === '') {
        throw new Exception("Selecione um vendedor válido.");
    }
    // A acao do botao e a fonte primaria; evita que o envio vire adicao silenciosa
    // caso o JavaScript que mantem o campo oculto seja alterado futuramente.
    $acao_final          = strtolower(trim((string) ($_POST['acao_botao'] ?? $_POST['acao_final'] ?? 'adicionar_item')));
    if (!in_array($acao_final, ['adicionar_item', 'enviar_os', 'salvar_rascunho'], true)) {
        $acao_final = 'adicionar_item';
    }
    $adicionar_pendente = ($_POST['adicionar_pendente'] ?? '0') === '1';
    if ($adicionar_pendente) {
        $acao_final = 'adicionar_item';
    }
    if ($acao_final === 'enviar_os' && $prazo_pagamento === '') {
        throw new Exception("Informe o prazo de pagamento para enviar a OS.");
    }
    if ($acao_final === 'enviar_os' && $forma_pagamento === null) {
        throw new Exception("Informe a forma de pagamento para enviar a OS.");
    }
    $pedido               = floatval($_POST['pedido']);
    $num_caixas_item      = max(0, (int) ($_POST['num_caixas_item'] ?? 0));
    $bandejas_por_caixa_item = max(0, (int) ($_POST['bandejas_por_caixa_item'] ?? 0));
    // OBA por caixas: a quantidade (pedido = total de bandejas) e derivada no backend
    // como bandejas por caixa x numero de caixas (fonte da verdade).
    if ($tipo_comercial === 'oba_embalado') {
        if ($num_caixas_item <= 0) {
            throw new Exception("Informe o numero de caixas deste produto para a Tabela OBA.");
        }
        if ($bandejas_por_caixa_item <= 0) {
            throw new Exception("Selecione a quantidade de bandejas por caixa para a Tabela OBA.");
        }
        $pedido = (float) ($bandejas_por_caixa_item * $num_caixas_item);
    }
    $frete                = max(0.0, (float) str_replace(',', '.', (string) ($_POST['frete'] ?? 0)));
    $preco                = isset($_POST['preco']) ? (float) $_POST['preco'] : -1;
    $preco_post_original  = $preco >= 0 ? arredondarMoeda($preco) : -1.0; // captura antes de qualquer sobrescrita
    $preco_editado_flag   = ($_POST['preco_editado_manualmente'] ?? '0') === '1';
    $peso_unitario        = isset($_POST['peso_unitario']) ? floatval($_POST['peso_unitario']) : 0;
    $kg_caixa        = null;
    $gramagem        = null;

    // Campos de snapshot (preenchidos pelo JS, validados/sobrescritos pelo backend para embalado)
    $preco_base_salvar         = null;
    $desconto_pct_salvar       = null;
    $tipo_aplicacao_salvar     = null;

    if ($produto_id <= 0 || $cliente_id <= 0 || $pedido <= 0) {
        throw new Exception("Dados inválidos: produto, cliente e quantidade são obrigatórios.");
    }
    cadastroFiscalExigirVenda($conexao, $cliente_id, $produto_id);

    $usuarioMontagemId = (int) ($_SESSION['usuario_id'] ?? 0);
    $usuarioMontagemNome = (string) ($_SESSION['usuario_nome'] ?? $_SESSION['nome'] ?? $_SESSION['usuario'] ?? '');

    $salvarComoPendente = $adicionar_pendente && osEstaPendente($conexao, $numero_os);

    if (!$salvarComoPendente && (
        osEstaPendente($conexao, $numero_os)
        || osEmMontagemDeOutroUsuario($conexao, $numero_os, $usuarioMontagemId)
        || !usuarioTemOsVendaEmMontagem($conexao, $numero_os, $usuarioMontagemId)
    )) {
        $numero_os = proximoNumeroOsVenda($conexao);
    }
    $statusInicial = $salvarComoPendente ? 'pendente' : 'anexado';

    // FUTURA REGRA: tornar foto obrigatoria na venda.
    // Para ativar, descomente este bloco.
    // if (empty($_FILES['foto']['name'])) {
    //     throw new Exception('A foto da venda e obrigatoria.');
    // }

    $retornoForm = [
        'cliente_id'     => $cliente_id,
        'produto_id'     => $produto_id,
        'tipo_comercial' => $tipo_comercial,
        'previsao_entrega' => $previsao_entrega_db ?? '',
        'entreposto'      => $entreposto,
        'tipo'           => $tipo,
        'prazo_escolhido'=> $prazo_escolhido,
        'vendedor'       => $vendedor,
        'numero_os'      => $numero_os,
        'forma_pagamento'=> $forma_pagamento,
        'prazo_pagamento'=> $prazo_pagamento,
        'consideracoes'  => $consideracoes,
    ];

    // ── Para embalado: backend recalcula e valida tudo ────────────────────────
    if ($tipo_comercial === 'embalado' || $tipo_comercial === 'oba_embalado') {
        $precoResolvido = $tipo_comercial === 'oba_embalado'
            ? resolverPrecoVendaObaEmbalado($conexao, $produto_id)
            : resolverPrecoVendaEmbalado($conexao, $produto_id, $cliente_id);

        // Usa o preço recalculado (backend como fonte da verdade)
        $preco = $precoResolvido['preco_venda'];

        // Preenche snapshot com valores reais do banco
        $preco_base_salvar     = $precoResolvido['preco_base'];
        $desconto_pct_salvar   = $precoResolvido['percentual'];
        $tipo_aplicacao_salvar = $precoResolvido['tipo_aplicacao'];

        // Peso unitário para bandeja: gramagem em gramas (se não vier do form, usa da tabela)
        if ($peso_unitario <= 0) {
            $peso_unitario = $precoResolvido['gramagem_kg'] * 1000; // converte kg → gramas
        }
    } elseif (in_array($tipo_comercial, ['atacado', 'atacado_convencional', 'shopper'], true)) {
        if ($prazo_escolhido !== null) {
            if ($tipo_comercial === 'atacado_convencional') {
                $precoResolvido = resolverPrecoVendaAtacadoConvencional($conexao, $produto_id, $cliente_id, $prazo_escolhido);
            } elseif ($tipo_comercial === 'shopper') {
                $precoResolvido = resolverPrecoVendaShopper($conexao, $produto_id, $prazo_escolhido);
            } else {
                $precoResolvido = resolverPrecoVendaAtacado($conexao, $produto_id, $prazo_escolhido, $cliente_id);
            }

            $preco = $precoResolvido['preco_venda'];
            $preco_base_salvar     = in_array($tipo_comercial, ['atacado', 'atacado_convencional'], true)
                ? ($prazo_escolhido === '30_dias' ? $precoResolvido['custo_30_dias'] : $precoResolvido['custo_5_dias'])
                : ($tipo_comercial === 'shopper'
                    ? ($prazo_escolhido === '30_dias' ? $precoResolvido['custo_30_dias'] : $precoResolvido['custo_5_dias'])
                    : $preco);
            $desconto_pct_salvar   = in_array($tipo_comercial, ['atacado', 'atacado_convencional'], true) ? $precoResolvido['percentual'] : null;
            $tipo_aplicacao_salvar = in_array($tipo_comercial, ['atacado', 'atacado_convencional'], true) ? $precoResolvido['tipo_aplicacao'] : null;

            if ($peso_unitario <= 0) {
                $peso_unitario = $precoResolvido['kg_caixa'];
            }
        }
    }

    // ── Detecta se o preço foi inserido manualmente ───────────────────────────
    // CASO 1: sem tabela (tipo_comercial vazio) → sempre manual
    // CASO 2: embalado / atacado, mas usuário alterou o valor sugerido
    $preco_manual = 0;
    if (
        $tipo_comercial === null
        || ($preco_editado_flag && $preco_post_original >= 0)
        || (in_array($tipo_comercial, ['atacado', 'atacado_convencional', 'shopper'], true) && $prazo_escolhido === null && $preco_post_original >= 0)
    ) {
        $preco_manual = 1;
        if ($preco_post_original >= 0) {
            $preco = $preco_post_original;
        }
    } elseif (isset($precoResolvido) && in_array($tipo_comercial, ['embalado', 'oba_embalado', 'atacado', 'atacado_convencional', 'shopper'], true)) {
        $precoCalculado = arredondarMoeda($precoResolvido['preco_venda']);
        $precoDiferente = $preco_post_original >= 0 && abs($preco_post_original - $precoCalculado) >= 0.005;
        if ($preco_editado_flag || $precoDiferente) {
            $preco_manual = 1;
            // Usa o preço que o usuário digitou em vez do calculado
            if ($preco_post_original >= 0) {
                $preco = $preco_post_original;
            }
        }
    }

    if ($preco < 0) {
        throw new Exception("Dados inválidos: preço é obrigatório.");
    }

    $preco = arredondarMoeda($preco);

    if (!colunaPrecoExiste($conexao)) {
        throw new Exception("A coluna preco não existe na tabela vendas.");
    }

    if (!in_array($tipo, ['bandeja', 'caixa', 'kg', 'unidade'], true)) {
        throw new Exception("Selecione um tipo válido.");
    }

    // Calcula quantidade e monta variáveis de peso
    if ($tipo === 'caixa') {
        if ($peso_unitario <= 0) throw new Exception("Informe o kg por caixa.");
        $kg_caixa         = $peso_unitario;
        $quantidade_total = $pedido * $kg_caixa;
    } elseif ($tipo === 'bandeja') {
        if ($peso_unitario <= 0) throw new Exception("Informe a gramagem por bandeja.");
        $gramagem         = $peso_unitario;
        $quantidade_total = ($pedido * $gramagem) / 1000;
    } elseif ($tipo === 'unidade') {
        $quantidade_total = $pedido;
    } else {
        $quantidade_total = $pedido;
    }

    // Consistencia: venda em bandeja com gramagem e sempre embalado (regra usada pela NF-e).
    if ($tipo_comercial === null && $tipo === 'bandeja' && (float) $gramagem > 0) {
        $tipo_comercial = 'embalado';
    }

    // INSERT principal
    if (colunaTipoExiste($conexao)) {
        if ($ciclo_id !== null) {
            $stmt = $conexao->prepare("
                INSERT INTO vendas (ciclo_id, data_venda, produto_id, cliente_id, tipo, gramagem, kg_caixa, pedido, preco, quantidade, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isiisddddds", $ciclo_id, $data_venda, $produto_id, $cliente_id, $tipo, $gramagem, $kg_caixa, $pedido, $preco, $quantidade_total, $statusInicial);
        } else {
            $stmt = $conexao->prepare("
                INSERT INTO vendas (data_venda, produto_id, cliente_id, tipo, gramagem, kg_caixa, pedido, preco, quantidade, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("siisddddds", $data_venda, $produto_id, $cliente_id, $tipo, $gramagem, $kg_caixa, $pedido, $preco, $quantidade_total, $statusInicial);
        }
    } else {
        if ($ciclo_id !== null) {
            $stmt = $conexao->prepare("
                INSERT INTO vendas (ciclo_id, data_venda, produto_id, cliente_id, gramagem, kg_caixa, pedido, preco, quantidade, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isiiddddds", $ciclo_id, $data_venda, $produto_id, $cliente_id, $gramagem, $kg_caixa, $pedido, $preco, $quantidade_total, $statusInicial);
        } else {
            $stmt = $conexao->prepare("
                INSERT INTO vendas (data_venda, produto_id, cliente_id, gramagem, kg_caixa, pedido, preco, quantidade, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("siiddddds", $data_venda, $produto_id, $cliente_id, $gramagem, $kg_caixa, $pedido, $preco, $quantidade_total, $statusInicial);
        }
    }
    $stmt->execute();
    $novoId = $conexao->insert_id;
    $stmt->close();

    $stmtFrete = $conexao->prepare("UPDATE vendas SET frete = ? WHERE id = ?");
    $stmtFrete->bind_param('di', $frete, $novoId);
    $stmtFrete->execute();
    $stmtFrete->close();

    $stmtUsuario = $conexao->prepare("
        UPDATE vendas
        SET usuario_montagem_id = ?, usuario_montagem_nome = ?
        WHERE id = ?
    ");
    $stmtUsuario->bind_param('isi', $usuarioMontagemId, $usuarioMontagemNome, $novoId);
    $stmtUsuario->execute();
    $stmtUsuario->close();

    if ($tipo_comercial === 'oba_embalado') {
        $stmtCaixasItem = $conexao->prepare("UPDATE vendas SET num_caixas = ?, bandejas_por_caixa = ? WHERE id = ?");
        $stmtCaixasItem->bind_param('iii', $num_caixas_item, $bandejas_por_caixa_item, $novoId);
        $stmtCaixasItem->execute();
        $stmtCaixasItem->close();
    }

    // UPDATE: snapshot de precificação + flag de preço manual + campos do pedido
    $stmtExtra = $conexao->prepare("
        UPDATE vendas
        SET tipo_comercial          = ?,
            prazo_escolhido         = ?,
            preco_base              = ?,
            desconto_percentual     = ?,
            tipo_aplicacao_desconto = ?,
            preco_manual            = ?,
            prazo_pagamento         = ?,
            forma_pagamento         = ?,
            previsao_entrega        = ?,
            entreposto              = ?,
            consideracoes           = ?,
            numero_os               = ?,
            vendedor                = ?,
            vendedor_usuario_id     = ?
        WHERE id = ?
    ");
    $stmtExtra->bind_param('ssddsisssssssii',
        $tipo_comercial,
        $prazo_escolhido,
        $preco_base_salvar,
        $desconto_pct_salvar,
        $tipo_aplicacao_salvar,
        $preco_manual,
        $prazo_pagamento,
        $forma_pagamento,
        $previsao_entrega_db,
        $entreposto,
        $consideracoes,
        $numero_os,
        $vendedor,
        $vendedor_usuario_id,
        $novoId
    );
    $stmtExtra->execute();
    $stmtExtra->close();

    $totalEnviado = 0;
    if ($acao_final === 'enviar_os') {
        if ($numero_os === '') {
            throw new Exception("Informe o numero da OS para enviar a venda.");
        }
        cadastroFiscalExigirOsVenda($conexao, $numero_os);

        $stmtCount = $conexao->prepare("
            SELECT COUNT(*) AS total
            FROM vendas
            WHERE numero_os = ? AND status = 'anexado'
              AND (usuario_montagem_id = ? OR usuario_montagem_id IS NULL)
        ");
        $stmtCount->bind_param('si', $numero_os, $usuarioMontagemId);
        $stmtCount->execute();
        $totalEnviado = (int) ($stmtCount->get_result()->fetch_assoc()['total'] ?? 0);
        $stmtCount->close();

        if ($totalEnviado <= 0) {
            throw new Exception("Nao ha vendas anexadas para a OS '$numero_os'.");
        }

        $stmtEnviar = $conexao->prepare("
            UPDATE vendas
            SET status = 'pendente'
            WHERE numero_os = ? AND status = 'anexado'
              AND (usuario_montagem_id = ? OR usuario_montagem_id IS NULL)
        ");
        $stmtEnviar->bind_param('si', $numero_os, $usuarioMontagemId);
        $stmtEnviar->execute();
        $stmtEnviar->close();

    }

    $conexao->commit();

    // Upload de foto (fora da transação, após commit)
    if (!empty($_FILES['foto']['tmp_name']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $uploadDir = dirname(__DIR__, 2) . '/storage/uploads/vendas/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $clienteNome = buscarNomeClienteUpload($conexao, $cliente_id);
            $fotoName = gerarNomeArquivoUploadUnico($uploadDir, $clienteNome . '_' . date('Ymd'), $ext);
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $uploadDir . $fotoName)) {
                $stmtFoto = $conexao->prepare("UPDATE vendas SET foto = ? WHERE id = ?");
                $stmtFoto->bind_param('si', $fotoName, $novoId);
                $stmtFoto->execute();
                $stmtFoto->close();
            }
        }
    }

    registrarLog(
        $conexao,
        'venda_criada',
        'vendas',
        $novoId,
        "Venda criada para cliente_id $cliente_id, produto_id $produto_id, quantidade $quantidade_total, preço R$ $preco"
    );

    if ($acao_final === 'enviar_os') {
        $retornoEnvio = $retornoForm;
        unset($retornoEnvio['numero_os']);
        $qs = montarQueryRetorno(array_merge(['msg' => 'enviado_lote', 'total' => $totalEnviado], $retornoEnvio));
        header("Location: ../vendas.php?" . $qs);
        exit;
    }

    if ($acao_final === 'salvar_rascunho') {
        $qs = montarQueryRetorno(array_merge(['msg' => 'rascunho_salvo', 'etapa' => 'finalizar'], $retornoForm));
        header("Location: ../vendas.php?" . $qs);
        exit;
    }

    if ($salvarComoPendente) {
        $qs = montarQueryRetorno([
            'msg' => 'produto_adicionado',
            'numero_os' => $numero_os,
        ]);
        header("Location: ../vendas.php?" . $qs);
        exit;
    }

    $retornoProximoItem = $retornoForm;
    unset(
        $retornoProximoItem['produto_id'],
        $retornoProximoItem['tipo']
    );
    $qs = montarQueryRetorno(array_merge(['msg' => 'salvo', 'etapa' => 'produto'], $retornoProximoItem));
    header("Location: ../vendas.php?" . $qs);
    exit;
} catch (Exception $e) {
    $conexao->rollback();
    $qs = montarQueryRetorno(array_merge([
        'msg' => 'erro',
        'detalhe' => $e->getMessage(),
    ], $retornoForm ?? ['cliente_id' => $cliente_id]));
    header("Location: ../vendas.php?" . $qs);
    exit;
}

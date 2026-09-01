<?php

require "../config/conexao.php";

require "../config/ciclo_helper.php";

require "../config/previsao_fornecedor_schema.php";

require "../auth/proteger.php";

require "../config/layout_helper.php";
require "../config/cadastro_fiscal_helper.php";



if (!in_array($_SESSION['usuario_nivel'], ['admin', 'fornecedor', 'operacional'])) {

    die("Sem permissão.");

}



garantirFluxoFinanceiroPrevisaoFornecedor($conexao);
function garantirColunasMontagemCompraTela(mysqli $conexao): void
{
    $colunas = [
        'usuario_montagem_id' => "ALTER TABLE previsao_fornecedor ADD COLUMN usuario_montagem_id INT NULL DEFAULT NULL AFTER foto",
        'usuario_montagem_nome' => "ALTER TABLE previsao_fornecedor ADD COLUMN usuario_montagem_nome VARCHAR(120) NULL DEFAULT NULL AFTER usuario_montagem_id",
    ];
    foreach ($colunas as $coluna => $sql) {
        $resultado = $conexao->query("SHOW COLUMNS FROM previsao_fornecedor LIKE '" . $conexao->real_escape_string($coluna) . "'");
        $existe = $resultado && $resultado->num_rows > 0;
        if ($resultado instanceof mysqli_result) $resultado->free();
        if (!$existe) $conexao->query($sql);
    }
}
garantirColunasMontagemCompraTela($conexao);
$usuarioMontagemId = (int) ($_SESSION['usuario_id'] ?? 0);
$filtroMontagemCompraUsuario = "AND (pf.usuario_montagem_id = {$usuarioMontagemId} OR pf.usuario_montagem_id IS NULL)";
$temPrecoFornecedor = colunaPrevisaoFornecedorExiste($conexao, 'preco');
$precoSelect = $temPrecoFornecedor ? 'pf.preco' : 'NULL AS preco';

$precoResumoSelect = $temPrecoFornecedor

    ? 'SUM(COALESCE(pf.preco, 0) * pf.quantidade_prevista) AS total_valor'

    : '0 AS total_valor';



$stmtProdutos = $conexao->prepare("SELECT * FROM produtos WHERE ativo = 1 AND produto_principal_id IS NULL ORDER BY nome ASC");

$stmtProdutos->execute();

$produtos = $stmtProdutos->get_result()->fetch_all(MYSQLI_ASSOC);

function produtoCodigoAntigoCompra(array $produto): bool
{
    foreach (['codigo_interno', 'nfe_codigo_interno', 'codigo_barras'] as $campoCodigo) {
        $codigo = preg_replace('/\D+/', '', (string) ($produto[$campoCodigo] ?? ''));
        if ($codigo !== '') {
            return strlen($codigo) < 13;
        }
    }
    return false;
}

function produtoNomeAgranelCompra(string $nome): string
{
    $nome = trim($nome);
    $unidades = '(?:kg|kgs|quilo|quilos|g|gr|grs|grama|gramas)';
    $limpo = preg_replace('/^\s*\d+(?:[\.,]\d+)?\s*' . $unidades . '\b\s*[-_\/]*\s*/iu', '', $nome);
    $limpo = preg_replace('/\s*[-_\/]*\s*\d+(?:[\.,]\d+)?\s*' . $unidades . '\b\s*$/iu', '', (string) $limpo);
    $limpo = trim((string) $limpo);
    return $limpo !== '' ? $limpo : $nome;
}



$cicloAtivo = getCicloAtivo($conexao);

$cicloAtivoId = $cicloAtivo ? (int) $cicloAtivo['id'] : null;

$filtroCicloPF = montarClausulaCiclo($conexao, 'previsao_fornecedor', 'pf', $cicloAtivoId);



$stmtFornecedores = $conexao->prepare("SELECT * FROM fornecedores WHERE ativo = 1 ORDER BY nome ASC");

$stmtFornecedores->execute();

$fornecedores = $stmtFornecedores->get_result()->fetch_all(MYSQLI_ASSOC);



$produtoSelecionado = isset($_GET['produto_id']) ? (int) $_GET['produto_id'] : 0;
$fornecedorSelecionado = isset($_GET['fornecedor_id']) ? (int) $_GET['fornecedor_id'] : 0;
$numeroOsSelecionado = trim((string) ($_GET['numero_os'] ?? ''));
$dataPrevistaSelecionada = trim((string) ($_GET['data_prevista'] ?? ''));
$formaPagamentoSelecionada = strtolower(trim((string) ($_GET['forma_pagamento'] ?? '')));
if (!in_array($formaPagamentoSelecionada, ['', 'boleto', 'deposito', 'pix'], true)) {
    $formaPagamentoSelecionada = '';
}
$prazoPagamentoSelecionado = trim((string) ($_GET['prazo_pagamento'] ?? ''));
$entrepostoSelecionado = trim((string) ($_GET['entreposto'] ?? ''));
$adicionarProdutoPendente = (($_GET['adicionar_pendente'] ?? '') === '1');
$revendaSaoPauloSelecionada = (($_GET['revenda_sao_paulo'] ?? '') === '1');
$etapaInicialCompra = trim((string) ($_GET['etapa'] ?? ''));
if (!in_array($etapaInicialCompra, ['fornecedor', 'produto', 'finalizar'], true)) {
    $etapaInicialCompra = 'fornecedor';
}
$redirectEdicaoCompra = trim((string) ($_GET['redirect_to'] ?? ''));
if ($redirectEdicaoCompra === '' || strpos($redirectEdicaoCompra, '://') !== false || substr($redirectEdicaoCompra, 0, 2) === '//') {
    $redirectEdicaoCompra = '../previsao_fornecedor.php';
}

$stmtAnexadas = $conexao->prepare("
    SELECT pf.id, pf.produto_id, pf.fornecedor_id, pf.numero_os, pf.quantidade_prevista, {$precoSelect}, pf.data_prevista, pf.forma_pagamento, pf.revenda_sao_paulo, pf.foto,

           p.nome AS produto, f.nome AS fornecedor

    FROM previsao_fornecedor pf

    JOIN produtos p    ON pf.produto_id    = p.id

    JOIN fornecedores f ON pf.fornecedor_id = f.id
    WHERE pf.status = 'anexado'
    {$filtroCicloPF}
    {$filtroMontagemCompraUsuario}
    ORDER BY pf.numero_os ASC, f.nome ASC, pf.id DESC
");
$stmtAnexadas->execute();
$previsoesAnexadas = $stmtAnexadas->get_result();
$previsoesAnexadasRows = $previsoesAnexadas ? $previsoesAnexadas->fetch_all(MYSQLI_ASSOC) : [];
$previsoesAnexadasPorOs = [];
foreach ($previsoesAnexadasRows as $row) {
    $osKey = trim((string) ($row['numero_os'] ?? ''));
    $previsoesAnexadasPorOs[$osKey !== '' ? $osKey : '__sem_os_' . $row['id']][] = $row;
}
$compraItensExistentes = $numeroOsSelecionado !== '' ? count($previsoesAnexadasPorOs[$numeroOsSelecionado] ?? []) : 0;


$resumoAnexadas = $conexao->query("

    SELECT pf.numero_os, MAX(pf.fornecedor_id) AS fornecedor_id, MAX(pf.data_prevista) AS data_prevista,
           MAX(pf.forma_pagamento) AS forma_pagamento, MAX(pf.revenda_sao_paulo) AS revenda_sao_paulo,
           MAX(f.nome) AS fornecedor, COUNT(*) AS total_itens,
           SUM(pf.quantidade_prevista) AS total_kg,

           {$precoResumoSelect}

    FROM previsao_fornecedor pf

    JOIN fornecedores f ON pf.fornecedor_id = f.id
    WHERE pf.status = 'anexado'
    {$filtroCicloPF}
    {$filtroMontagemCompraUsuario}
    GROUP BY pf.numero_os
    ORDER BY pf.numero_os ASC

");



$stmtLista = $conexao->prepare("
    SELECT pf.id, pf.produto_id, pf.fornecedor_id, pf.numero_os, pf.quantidade_prevista, {$precoSelect}, pf.data_prevista, pf.forma_pagamento, pf.revenda_sao_paulo, pf.foto,
           p.nome AS produto, f.nome AS fornecedor
    FROM previsao_fornecedor pf
    JOIN produtos p    ON pf.produto_id    = p.id

    JOIN fornecedores f ON pf.fornecedor_id = f.id

    WHERE pf.status = 'pendente'

    {$filtroCicloPF}

    ORDER BY pf.data_prevista ASC

");

$stmtLista->execute();
$lista = $stmtLista->get_result();
$listaRows = $lista ? $lista->fetch_all(MYSQLI_ASSOC) : [];
$listaPorOs = [];
foreach ($listaRows as $row) {
    $osKey = trim((string) ($row['numero_os'] ?? ''));
    $listaPorOs[$osKey !== '' ? $osKey : '__sem_os_' . $row['id']][] = $row;
}

$previsaoAbrirEdicao = null;
$editarPrevisaoId = (int) ($_GET['editar_previsao_id'] ?? 0);
if ($editarPrevisaoId > 0) {
    $stmtEditarPrevisao = $conexao->prepare("
        SELECT id, produto_id, fornecedor_id, numero_os, quantidade_prevista, {$precoSelect}, data_prevista, forma_pagamento, revenda_sao_paulo, status
        FROM previsao_fornecedor pf
        WHERE id = ? AND status IN ('anexado', 'pendente', 'concluido')
        LIMIT 1
    ");
    $stmtEditarPrevisao->bind_param('i', $editarPrevisaoId);
    $stmtEditarPrevisao->execute();
    $previsaoAbrirEdicao = $stmtEditarPrevisao->get_result()->fetch_assoc() ?: null;
    $stmtEditarPrevisao->close();
}

$autoDownloadPedidoCompraUrl = '';
if (!empty($_GET['download_numero_os'])) {

    $autoDownloadPedidoCompraUrl = 'gerar_pedido_compra.php?numero_os=' . rawurlencode((string) $_GET['download_numero_os']);

}



?>

<!DOCTYPE html>

<html>



<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Previsão de Fornecedor</title>

    <style>

        * {

            box-sizing: border-box;

        }



        body {

            margin: 0;

            font-family: 'Segoe UI', Arial, sans-serif;

            background: #f4f6f9;

            color: #222;

        }



        .header {

            background: #1b5e20;

            color: white;

            padding: 15px 20px;

            position: relative;

        }



        .header-top {

            display: flex;

            justify-content: space-between;

            align-items: flex-start;

        }



        .header h1 {

            margin: 0;

            font-size: 20px;

            font-weight: 600;

        }



        .user-info {

            font-size: 14px;

            margin-top: 4px;

        }



        .user-info a {

            color: white;

            text-decoration: none;

            margin-left: 6px;

            font-weight: bold;

        }



        .btn-voltar {

            position: absolute;

            top: 15px;

            right: 20px;

            background: white;

            color: #1b5e20;

            padding: 6px 14px;

            border-radius: 6px;

            text-decoration: none;

            font-weight: bold;

            font-size: 14px;

        }



        .btn-voltar:hover {

            background: #e8f5e9;

        }



        .container {

            padding: 30px;

            max-width: 1200px;

            margin: auto;

        }



        .card {

            background: white;

            padding: 25px;

            border-radius: 10px;

            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);

            margin-bottom: 30px;

        }



        h2 {

            margin-top: 0;

            color: black;

        }



        .form-title {

            margin: 0 0 20px 0;

            color: #111;

            grid-column: 1 / -1;

        }



        .form-grid {

            display: grid;

            grid-template-columns: repeat(2, 1fr);

            gap: 20px;

        }



        .form-card .form-grid {

            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));

            align-items: start;

            position: relative;

            min-height: 270px;

            padding-bottom: 64px;

        }



        .modal-box .form-grid {

            grid-template-columns: repeat(2, 1fr);

        }



        label {

            font-weight: bold;

            font-size: 14px;

        }



        input,

        select,

        textarea {

            width: 100%;

            padding: 10px;

            border-radius: 6px;

            border: 1px solid #ccc;

            margin-top: 5px;

            font-size: 14px;

        }



        button {

            padding: 10px 16px;

            border-radius: 6px;

            border: none;

            font-weight: 600;

            cursor: pointer;

        }



        .btn-primary {

            background: #2e7d32;

            color: white;

        }



        .btn-primary:hover {

            background: #1b5e20;

        }



        .btn-secondary {

            background: #7f8c8d;

            color: white;

        }



        .btn-secondary:hover {

            background: #6c7a7a;

        }

        .btn-emitir {
            background: #e89020;
            color: white;
        }

        .btn-emitir:hover {
            background: #c97a10;
        }



        .table-container {

            overflow-x: auto;

        }



        table {

            width: 100%;

            border-collapse: collapse;

            min-width: 700px;

            background: white;

            border-radius: 10px;

            overflow: hidden;

            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);

        }



        table th {

            background: #1b5e20;

            color: white;

            padding: 12px;

            font-size: 14px;

            font-weight: 600;

        }



        table td {

            padding: 12px;

            border-bottom: 1px solid #eee;

            text-align: center;

            font-size: 14px;

        }



        table tr:hover {
            background: #f1f8f4;
        }

        .live-item-preview {
            display: none;
            border: 1px solid #a5d6a7;
            background: #f4fbf5;
        }

        .live-item-preview.active {
            display: block;
        }

        .live-item-preview .preview-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #1b5e20;
            font-size: 13px;
            font-weight: 700;
            margin: 0 0 12px;
        }

        .live-item-preview table td {
            background: #fff;
        }

        .live-item-preview .preview-note {
            color: #4b6350;
            font-size: 13px;
            margin: 10px 0 0;
        }

        .live-montagem-preview {
            display: none;
            margin-top: 14px;
        }

        .live-montagem-preview.active {
            display: block;
        }

        .live-montagem-row {
            background: #fffde7 !important;
        }

        .live-montagem-row .preview-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #fff8d1;
            color: #806100;
            border-radius: 999px;
            padding: 4px 9px;
            font-size: 12px;
            font-weight: 700;
        }

        .modal-bg {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            padding: 20px;

            z-index: 999;

        }



        .modal-box {
            background: white;
            width: 450px;
            max-width: 100%;
            margin: auto;

            margin-top: 10vh;

            padding: 25px;

            border-radius: 12px;

            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .modal-box.modal-os-recebimento {
            width: min(960px, 96vw);
            margin-top: 4vh;
            max-height: 90vh;
            overflow: auto;
        }

        .recebimento-os-item {
            border: 1px solid #e5ece6;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 12px;
            background: #fbfdfb;
            transition: border-color .15s ease, background .15s ease, opacity .15s ease;
        }

        .recebimento-os-item:last-child {
            margin-bottom: 0;
        }

        .recebimento-os-item.modo-abate {
            border-color: #f0c987;
            background: #fffaf2;
        }

        .recebimento-os-item.modo-excluir {
            border-color: #e0a3a3;
            background: #fdf3f3;
            opacity: .8;
        }

        .recebimento-os-cab {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 12px;
        }

        .recebimento-os-produto strong {
            display: block;
            font-size: 15px;
            color: #1b5e20;
            line-height: 1.25;
        }

        .recebimento-os-produto span,
        .recebimento-os-ajuda {
            display: block;
            color: #66746a;
            font-size: 12px;
        }

        .recebimento-os-produto span {
            margin-top: 2px;
        }

        .recebimento-os-excluir {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-weight: 600;
            font-size: 13px;
            color: #9a3a3a;
            background: #fff;
            border: 1px solid #e6d2d2;
            border-radius: 8px;
            padding: 7px 11px;
            margin: 0;
            cursor: pointer;
            white-space: nowrap;
        }

        .recebimento-os-excluir input {
            width: auto;
            margin: 0;
        }

        .recebimento-os-campos {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            align-items: start;
        }

        .recebimento-os-campos label {
            font-size: 13px;
        }

        .recebimento-os-ajuda {
            margin-top: 4px;
        }

        .recebimento-os-motivo {
            display: none;
            margin-top: 12px;
        }

        .recebimento-os-item.modo-abate .recebimento-os-motivo {
            display: block;
        }

        @media (max-width:600px) {
            .recebimento-os-campos {
                grid-template-columns: 1fr;
            }

            .recebimento-os-cab {
                flex-direction: column;
            }
        }

        .modal-box.preview-pedido {
            width: min(1280px, 98vw);
            height: min(94vh, 940px);

            display: grid;

            grid-template-columns: minmax(0, 1fr) 96px;

            grid-template-rows: auto 1fr;

            gap: 14px;

            margin-top: 2vh;

        }



        .preview-pedido h3 {

            grid-column: 1 / -1;

        }



        .preview-pedido iframe {

            width: 100%;

            height: 100%;

            min-height: 0;

            border: 1px solid #d9e2d9;

            border-radius: 8px;

            background: #fff;

        }



        .preview-pedido .preview-actions {

            display: flex;

            flex-direction: column;

            justify-content: flex-end;

            align-items: stretch;

            gap: 12px;

        }



        .preview-pedido .preview-actions button {

            width: 100%;

            min-height: 52px;

            display: inline-flex;

            align-items: center;

            justify-content: center;

            font-size: 20px;

        }



        @media (max-width: 760px) {

            .modal-box.preview-pedido {

                grid-template-columns: 1fr;

                grid-template-rows: auto minmax(0, 1fr) auto;

            }



            .preview-pedido .preview-actions {

                flex-direction: row;

                justify-content: space-between;

            }



            .preview-pedido .preview-actions button {

                width: 52px;

            }

        }



        .wizard-progress {

            display: flex;

            align-items: center;

            gap: 0;

            margin: 2px 0 22px;

            overflow-x: auto;

            padding-bottom: 2px;

        }



        .wizard-step-indicator {

            position: relative;

            flex: 1 1 0;

            min-width: 126px;

            border: 1px solid #cfd8cf;

            border-radius: 6px;

            padding: 10px 26px 10px 12px;

            background: #f7faf7;

            color: #496149;

            font-size: 13px;

            font-weight: 700;

            text-align: center;

            white-space: nowrap;

        }



        .wizard-step-indicator + .wizard-step-indicator {

            margin-left: 18px;

        }



        .wizard-step-indicator:not(:last-child)::after {

            content: "";

            position: absolute;

            top: 50%;

            right: -18px;

            width: 18px;

            height: 2px;

            background: #b8c8b8;

            transform: translateY(-50%);

        }



        .wizard-step-indicator:not(:last-child)::before {

            content: "";

            position: absolute;

            top: 50%;

            right: -20px;

            border-top: 5px solid transparent;

            border-bottom: 5px solid transparent;

            border-left: 7px solid #b8c8b8;

            transform: translateY(-50%);

        }



        .wizard-step-indicator.active {

            background: #1b5e20;

            border-color: #1b5e20;

            color: #fff;

        }



        /* grid auto-fill uniforme */
        .form-card .form-grid .wizard-hidden{display:none!important}

        /* step1 — campos normais herdam auto-fill, sem grid-column explícito */
        .compra-field-revenda{grid-column:1/-1!important;display:flex!important;align-items:center!important;padding-top:2px!important;border:none!important;background:none!important}

        /* step2 */
        .compra-field-produto{grid-column:1/-1!important}

        /* step3 — protegidos */
        .compra-field-os{grid-column:1/-1!important}
        .compra-field-forma{grid-column:1/-1!important}
        .form-attachments{grid-column:1/-1!important}

        .wizard-hidden {
            visibility: hidden !important;
            height: 0 !important;
            min-height: 0 !important;
            padding: 0 !important;
            margin: 0 !important;
            overflow: hidden !important;
            pointer-events: none !important;
        }



        .wizard-nav {

            position: absolute;

            left: 0;

            right: 0;

            bottom: 0;

            display: flex;

            justify-content: flex-end;

            align-items: center;

            gap: 10px;

            grid-column: 1/-1;

            margin-top: 0;

            padding-top: 12px;

            border-top: 1px solid #edf2ed;

        }



        .wizard-actions-final {

            position: absolute;

            right: 0;

            bottom: 0;

            display: flex;

            flex-direction: row;

            justify-content: flex-end;

            gap: 10px;

            flex-wrap: nowrap;

            grid-column: 1/-1;

            width: max-content;

            min-width: max-content;

            padding-top: 12px;

            z-index: 2;

        }



        .wizard-actions-final button {

            flex: 0 0 38px;

            width: 38px !important;

            height: 38px !important;

            min-width: 38px;

            padding: 0 !important;

            display: inline-flex;

            align-items: center;

            justify-content: center;

        }



        .form-card .form-section-title {

            display: none;

        }



        .msg-sucesso {

            background: #e8f5e9;

            color: #2e7d32;

            border: 1px solid #a5d6a7;

            padding: 12px 16px;

            border-radius: 8px;

            margin-bottom: 20px;

            font-weight: 600;

        }



        .msg-erro {

            background: #ffebee;

            color: #c62828;

            border: 1px solid #ef9a9a;

            padding: 12px 16px;

            border-radius: 8px;

            margin-bottom: 20px;

            font-weight: 600;

        }



        .acoes {
            display: flex;
            gap: 8px;
            justify-content: center;
            flex-wrap: wrap;
            align-items: center;
        }

        .os-group-header td {
            background: #f8fbf8;
            font-weight: 700;
        }

        .compra-revenda-sp td {
            background: rgba(46, 125, 50, 0.10) !important;
        }

        .revenda-sp-option {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 13px;
            color: #374151;
            font-weight: 500;
        }

        .revenda-sp-option input {
            width: 16px;
            height: 16px;
            margin: 0;
            accent-color: #2e7d32;
            cursor: pointer;
            flex-shrink: 0;
        }

        .badge-revenda-sp {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 4px;
            padding: 4px 7px;
            border-radius: 999px;
            background: #dff3e3;
            color: #1b5e20;
            font-size: 11px;
            font-weight: 800;
            white-space: nowrap;
        }

        .os-item-row.hidden,
        .compra-montagem-item-row.hidden,
        .compra-pendente-item-row.hidden {
            display: none;
        }

        .btn-toggle-os {
            width: 32px;
            height: 32px;
            padding: 0;
            border-radius: 6px;
            background: #e8f5e9;
            color: #1b5e20;
            font-weight: 800;
        }

        .draft-resume-banner {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: #ecfdf3;
            border: 1px solid #86efac;
            color: #14532d;
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-weight: 700;
            flex-wrap: wrap;
        }

        .draft-resume-banner a {
            background: #128a40;
            color: #fff;
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 7px;
        }


        .acoes a,

        .acoes button,

        .acoes form button {

            width: 34px;

            height: 34px;

            display: inline-flex;

            align-items: center;

            justify-content: center;

            font-size: 15px;

            padding: 0;

            flex-shrink: 0;

        }



        .btn-danger {

            background: #c62828;

            color: white;

        }



        .btn-danger:hover {

            background: #a61c1c;

        }



        .btn-edit {

            background: #1565c0;

            color: white;

        }



        .btn-edit:hover {

            background: #0d47a1;

        }



        .btn-imprimir {

            background: #e65100;

            color: white;

            padding: 6px 12px;

            border-radius: 6px;

            border: none;

            text-decoration: none;

            font-weight: 600;

            font-size: 13px;

            display: inline-block;

            cursor: pointer;

        }



        .btn-imprimir:hover {

            background: #bf360c;

        }



        @media (max-width:900px) {

            .container {

                padding: 20px;

            }



            .form-grid {

                grid-template-columns: 1fr;

            }



            button {

                width: 100%;

                margin-top: 5px;

            }

        }

    </style>

    <?php renderAppLayoutStyles(); ?>

    <script>

        const autoDownloadPedidoCompraUrl = <?= json_encode($autoDownloadPedidoCompraUrl) ?>;
        const etapaInicialCompra = <?= json_encode($etapaInicialCompra) ?>;
        const compraItensExistentes = <?= json_encode($compraItensExistentes) ?>;
        const previsaoAbrirEdicao = <?= json_encode($previsaoAbrirEdicao, JSON_UNESCAPED_UNICODE) ?>;
        const redirectEdicaoCompra = <?= json_encode($redirectEdicaoCompra) ?>;
        let formPreviewPedidoCompra = null;
        let compraWizardStep = 0;
        const compraWizardSteps = [

            { label: 'Fornecedor', selectors: ['[name="fornecedor_id"]', '[name="revenda_sao_paulo"]', '#fornecedor_info_cnpj', '#fornecedor_info_telefone', '#fornecedor_info_endereco'] },

            { label: 'Produto', selectors: ['[name="produto_id"]', '[name="quantidade_prevista"]', '[name="preco"]', '[name="data_prevista"]'] },

            { label: 'Finalizar', selectors: ['[name="numero_os"]', '[name="forma_pagamento"]', '[name="prazo_pagamento"]', '[name="entreposto"]', '[name="foto"]', '#compraAcoesFinais'] }

        ];



        function iniciarDownloadPedidoCompra(url) {

            if (!url) return;

            const iframe = document.createElement('iframe');

            iframe.style.display = 'none';

            iframe.src = url;

            document.body.appendChild(iframe);

            setTimeout(() => iframe.remove(), 60000);



            const cleanUrl = new URL(window.location.href);

            cleanUrl.searchParams.delete('download_numero_os');

            window.history.replaceState({}, '', cleanUrl.toString());

        }



        function confirmarRevendaSaoPaulo(campo) {
            if (!campo.checked) return;
            const confirmou = confirm('Essa compra sera marcada como Revenda Sao Paulo e nao movimentara o estoque ao confirmar. Deseja continuar?');
            if (!confirmou) {
                campo.checked = false;
            }
        }

        function abrirModal(id, quantidade, numeroOs, revendaSaoPaulo) {

            document.getElementById("modalAbatimento").style.display = "block";
            document.getElementById("modal_id").value = id;
            document.getElementById("modal_id_excluir").value = id;
            document.getElementById("modal_numero_os").value = numeroOs || "";
            document.getElementById("modal_quantidade").innerText = quantidade;
            document.getElementById("quantidade_abatida").value = "";
            document.getElementById("quantidade_recebida").value = quantidade;
            document.getElementById("motivo_abatimento").value = "";
            const ajudaRecebida = document.getElementById("modal_recebida_ajuda");
            if (ajudaRecebida) {
                ajudaRecebida.textContent = revendaSaoPaulo ? "Final recebido para historico/NFe. Nao movimenta estoque." : "Final que entrou no estoque.";
            }
            mostrarCampo();
        }



        function fecharModal() {

            document.getElementById("modalAbatimento").style.display = "none";

        }



        function mostrarCampo() {
            var abatida = parseFloat(document.getElementById("quantidade_abatida").value || "0") || 0;
            document.getElementById("motivo_abatimento").required = abatida > 0;
        }

        function abrirModalRecebimentoOs(numeroOs, itens, revendaSaoPaulo) {
            const modal = document.getElementById('modalRecebimentoOs');
            const form = document.getElementById('formRecebimentoOs');
            const lista = document.getElementById('recebimentoOsItens');
            document.getElementById('recebimentoOsNumero').textContent = numeroOs;
            document.getElementById('recebimentoOsNumeroInput').value = numeroOs;
            lista.innerHTML = '';

            itens.forEach(function (item) {
                const id = String(item.id);
                const qtd = Number(item.quantidade_prevista || 0);
                const linha = document.createElement('div');
                linha.className = 'recebimento-os-item';
                linha.dataset.itemId = id;
                linha.dataset.quantidadePrevista = String(qtd);
                linha.innerHTML =
                    '<div class="recebimento-os-cab">' +
                    '<div class="recebimento-os-produto">' +
                    '<strong>' + escapeHtml(item.produto || '-') + '</strong>' +
                    '<span>Pedido: ' + qtd.toFixed(2).replace('.', ',') + ' kg</span>' +
                    '</div>' +
                    '<label class="recebimento-os-excluir" title="Marque se o produto não chegou">' +
                    '<input type="checkbox" name="recebimento[' + id + '][excluir]" value="1"> Produto não chegou' +
                    '</label>' +
                    '</div>' +
                    '<div class="recebimento-os-campos">' +
                    '<div>' +
                    '<label>Quantidade real (kg)</label>' +
                    '<input type="number" step="0.01" min="0" name="recebimento[' + id + '][quantidade_recebida]" value="' + qtd.toFixed(2) + '" required>' +
                    '<small class="recebimento-os-ajuda">' + (revendaSaoPaulo ? 'Final recebido para historico/NFe. Nao movimenta estoque.' : 'Final que entrou.') + '</small>' +
                    '</div>' +
                    '<div>' +
                    '<label>Abate / descarte (kg)</label>' +
                    '<input type="number" step="0.01" min="0" name="recebimento[' + id + '][quantidade_abatida]" value="0">' +
                    '<small class="recebimento-os-ajuda">Produto ruim/devolvido.</small>' +
                    '</div>' +
                    '</div>' +
                    '<div class="recebimento-os-motivo">' +
                    '<label>Motivo do abate</label>' +
                    '<textarea name="recebimento[' + id + '][motivo]" rows="2"></textarea>' +
                    '</div>';
                lista.appendChild(linha);
                linha.querySelectorAll('input').forEach(function (input) {
                    input.addEventListener('input', function () {
                        atualizarLinhaRecebimentoOs(linha);
                    });
                    input.addEventListener('change', function () {
                        atualizarLinhaRecebimentoOs(linha);
                    });
                });
                atualizarLinhaRecebimentoOs(linha);
            });

            modal.style.display = 'block';
            form.onsubmit = function () {
                return confirm('Confirmar a OS ' + numeroOs + ' inteira com as quantidades informadas por produto?');
            };
        }

        function fecharModalRecebimentoOs() {
            document.getElementById('modalRecebimentoOs').style.display = 'none';
        }

        function atualizarLinhaRecebimentoOs(linha) {
            if (!linha.classList || !linha.classList.contains('recebimento-os-item')) {
                linha = linha.closest('.recebimento-os-item');
            }
            const abateInput = linha.querySelector('[name$="[quantidade_abatida]"]');
            const recebidoInput = linha.querySelector('[name$="[quantidade_recebida]"]');
            const excluirInput = linha.querySelector('[name$="[excluir]"]');
            const motivo = linha.querySelector('textarea');
            const abatida = parseFloat(abateInput.value || '0') || 0;
            const excluir = !!(excluirInput && excluirInput.checked);
            linha.classList.toggle('modo-abate', abatida > 0 && !excluir);
            linha.classList.toggle('modo-excluir', excluir);
            motivo.required = abatida > 0 && !excluir;
            abateInput.disabled = excluir;
            recebidoInput.disabled = excluir;
            if (excluir) {
                motivo.required = false;
            }
        }

        function abrirModalEdicao(item) {
            // Guarda a OS deste item para reabrir o dropdown apos o reload (pos-save).
            if (item && item.numero_os) {
                marcarCompraOsAberta(item.status === 'pendente' ? 'pendente' : 'montagem', item.numero_os);
            }
            document.getElementById("edit_id").value = item.id || "";
            document.getElementById("edit_foto").value = "";
            document.getElementById("edit_produto_id").value = item.produto_id || "";
            document.getElementById("edit_fornecedor_id").value = item.fornecedor_id || "";
            document.getElementById("edit_numero_os").value = item.numero_os || "";
            document.getElementById("edit_quantidade_prevista").value = item.quantidade_prevista || "";
            document.getElementById("edit_preco").value = item.preco || "";
            document.getElementById("edit_data_prevista").value = item.data_prevista || "";
            document.getElementById("edit_forma_pagamento").value = item.forma_pagamento || "";
            document.getElementById("edit_revenda_sao_paulo").value = item.revenda_sao_paulo ? "1" : "0";
            const botaoAdicionar = document.getElementById("edit_adicionar_produto_os");
            if (botaoAdicionar) {
                botaoAdicionar.style.display = item.status === 'pendente' ? 'inline-flex' : 'none';
            }
            document.getElementById("edit_redirect_to").value = redirectEdicaoCompra || "../previsao_fornecedor.php";
            atualizarFornecedorInfo('edit_fornecedor_id', 'edit_fornecedor_info');
            document.getElementById("modalEdicao").style.display = "block";
        }

        function fecharModalEdicao() {

            document.getElementById("modalEdicao").style.display = "none";
        }

        function adicionarProdutoNaOsEditada() {
            const numeroOs = document.getElementById("edit_numero_os").value.trim();
            const fornecedorId = document.getElementById("edit_fornecedor_id").value;
            const dataPrevista = document.getElementById("edit_data_prevista").value;
            const formaPagamento = document.getElementById("edit_forma_pagamento").value;
            const revendaSaoPaulo = document.getElementById("edit_revenda_sao_paulo").value;
            if (!numeroOs || !fornecedorId) {
                alert('Informe a OS e o fornecedor antes de adicionar outro produto.');
                return;
            }

            const params = new URLSearchParams();
            params.set('numero_os', numeroOs);
            params.set('fornecedor_id', fornecedorId);
            if (dataPrevista) params.set('data_prevista', dataPrevista);
            if (formaPagamento) params.set('forma_pagamento', formaPagamento);
            if (revendaSaoPaulo === '1') params.set('revenda_sao_paulo', '1');
            params.set('etapa', 'produto');
            params.set('adicionar_pendente', '1');
            window.location.href = 'previsao_fornecedor.php?' + params.toString();
        }


        function atualizarFornecedorInfo(selectId, prefixo) {

            const select = document.getElementById(selectId);

            if (!select) {

                return;

            }



            const option = select.options[select.selectedIndex];

            document.getElementById(prefixo + "_cnpj").value = option ? (option.getAttribute("data-cnpj") || "") : "";

            document.getElementById(prefixo + "_telefone").value = option ? (option.getAttribute("data-telefone") || "") : "";

            document.getElementById(prefixo + "_endereco").value = option ? (option.getAttribute("data-endereco") || "") : "";

        }



        function abrirModalEnviarOs() {

            document.getElementById('modalEnviarOs').style.display = 'block';

            document.getElementById('inputNumeroOs').focus();

        }



        function fecharModalEnviarOs() {

            document.getElementById('modalEnviarOs').style.display = 'none';

        }



        function confirmarEnvioOs() {

            const numeroOs = document.getElementById('inputNumeroOs').value.trim();

            if (!numeroOs) {

                alert('Informe o número da OS.');

                return;

            }

            document.getElementById('hiddenNumeroOs').value = numeroOs;

            document.getElementById('formEnviarOs').submit();

        }

        function abrirPreviewConfirmacaoCompra(form) {

            const numeroOs = document.getElementById('modal_numero_os').value.trim();

            if (!numeroOs) {

                return confirm('Confirmar recebimento desta compra?');

            }



            formPreviewPedidoCompra = form;

            document.getElementById('previewPedidoCompraTitulo').textContent = 'Conferir OS ' + numeroOs;

            document.getElementById('previewPedidoCompraFrame').src = 'actions/pedido_compra_pdf.php?numero_os=' + encodeURIComponent(numeroOs);

            document.getElementById('modalPreviewPedidoCompra').style.display = 'block';

            return false;

        }



        function fecharPreviewPedidoCompra() {

            document.getElementById('modalPreviewPedidoCompra').style.display = 'none';

            document.getElementById('previewPedidoCompraFrame').src = 'about:blank';

            formPreviewPedidoCompra = null;

        }



        function confirmarPreviewPedidoCompra() {

            if (!formPreviewPedidoCompra) return;

            formPreviewPedidoCompra.submit();

        }



        function grupoWizardCompra(selector) {

            const el = document.querySelector(selector);

            return el ? el.closest('.form-grid > div, .form-attachments') : null;

        }



        function camposEtapaCompra(index) {

            return compraWizardSteps[index].selectors

                .map(grupoWizardCompra)

                .filter((el, pos, arr) => el && arr.indexOf(el) === pos);

        }



        function validarEtapaCompra() {

            for (const group of camposEtapaCompra(compraWizardStep)) {

                for (const field of group.querySelectorAll('input, select, textarea')) {

                    if (!field.checkValidity()) {

                        field.reportValidity();

                        return false;

                    }

                }

            }

            return true;

        }



        function renderWizardCompra() {

            const progress = document.getElementById('wizardCompraProgress');

            progress.innerHTML = compraWizardSteps.map((step, index) =>

                '<div class="wizard-step-indicator ' + (index === compraWizardStep ? 'active' : '') + '">' + (index + 1) + '. ' + step.label + '</div>'

            ).join('');



            const allGroups = new Set(compraWizardSteps.flatMap((step) => step.selectors.map(grupoWizardCompra).filter(Boolean)));

            allGroups.forEach((group) => group.classList.add('wizard-hidden'));

            camposEtapaCompra(compraWizardStep).forEach((group) => group.classList.remove('wizard-hidden'));



            document.getElementById('wizardCompraPrev').style.visibility = compraWizardStep === 0 ? 'hidden' : 'visible';

            document.getElementById('wizardCompraNext').classList.toggle('wizard-hidden', compraWizardStep === compraWizardSteps.length - 1);

            document.getElementById('compraAcoesFinais').classList.toggle('wizard-hidden', compraWizardStep !== compraWizardSteps.length - 1);

        }



        function inicializarWizardCompra() {
            const etapaIndex = { fornecedor: 0, produto: 1, finalizar: 2 };
            compraWizardStep = etapaIndex[etapaInicialCompra] || 0;
            document.getElementById('wizardCompraPrev').addEventListener('click', function () {
                compraWizardStep = Math.max(0, compraWizardStep - 1);
                renderWizardCompra();
            });

            document.getElementById('wizardCompraNext').addEventListener('click', function () {

                if (!validarEtapaCompra()) return;

                compraWizardStep = Math.min(compraWizardSteps.length - 1, compraWizardStep + 1);

                renderWizardCompra();

            });

            renderWizardCompra();
        }

        function etapaAtualCompraSlug() {
            return ['fornecedor', 'produto', 'finalizar'][compraWizardStep] || 'fornecedor';
        }

        function valorCampoCompra(nome) {
            const campo = document.querySelector('form[action="actions/salvar_previsao_fornecedor.php"] [name="' + nome + '"]');
            return campo ? campo.value : '';
        }

        function escapeHtml(valor) {
            return String(valor ?? '').replace(/[&<>"']/g, function (char) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
            });
        }

        function textoOpcaoSelecionada(select) {
            if (!select || select.selectedIndex < 0) return '';
            return (select.options[select.selectedIndex]?.textContent || '').trim();
        }

        function fmtPrecoCompra(valor) {
            return 'R$ ' + Number(valor || 0).toFixed(2).replace('.', ',');
        }

        function atualizarPreviewCompraAoVivo() {
            const preview = document.getElementById('previewCompraAoVivo');
            const tbody = document.getElementById('previewCompraAoVivoBody');
            if (!preview || !tbody) return;

            const produtoSelect = document.getElementById('compra_produto_id');
            const fornecedorSelect = document.getElementById('fornecedor_id');
            const numeroOs = valorCampoCompra('numero_os').trim();
            const quantidade = parseFloat(valorCampoCompra('quantidade_prevista')) || 0;
            const preco = parseFloat(valorCampoCompra('preco')) || 0;
            const dataPrevista = valorCampoCompra('data_prevista');
            const revendaSaoPaulo = !!document.querySelector('[name="revenda_sao_paulo"]')?.checked;

            if (!produtoSelect?.value || !fornecedorSelect?.value || !numeroOs || quantidade <= 0 || preco <= 0 || !dataPrevista) {
                preview.classList.remove('active');
                tbody.innerHTML = '';
                atualizarMontagemAoVivoCompra(null);
                return;
            }

            const total = quantidade * preco;
            const dataPartes = dataPrevista.split('-');
            const dataFormatada = dataPartes.length === 3 ? dataPartes[2] + '/' + dataPartes[1] + '/' + dataPartes[0] : dataPrevista;

            tbody.innerHTML =
                '<tr>' +
                '<td>' + escapeHtml(numeroOs) + '</td>' +
                '<td>' + escapeHtml(textoOpcaoSelecionada(fornecedorSelect)) + '</td>' +
                '<td>' + escapeHtml(textoOpcaoSelecionada(produtoSelect)) + '</td>' +
                '<td>' + quantidade.toFixed(2).replace('.', ',') + ' kg</td>' +
                '<td>' + fmtPrecoCompra(preco) + '/kg</td>' +
                '<td>' + fmtPrecoCompra(total) + '</td>' +
                '<td>' + escapeHtml(dataFormatada) + '</td>' +
                '<td><span class="preview-status">Preenchendo agora</span></td>' +
                '</tr>';
            preview.classList.add('active');
            atualizarMontagemAoVivoCompra({
                numeroOs: numeroOs,
                fornecedor: textoOpcaoSelecionada(fornecedorSelect),
                produto: textoOpcaoSelecionada(produtoSelect),
                quantidade: quantidade,
                preco: preco,
                total: total,
                data: dataFormatada,
                revendaSaoPaulo: revendaSaoPaulo
            });
        }

        function atualizarMontagemAoVivoCompra(item) {
            const bloco = document.getElementById('compraMontagemAoVivo');
            const body = document.getElementById('compraMontagemAoVivoBody');
            document.querySelectorAll('.live-montagem-inline-compra').forEach((row) => row.remove());
            document.querySelectorAll('[data-live-original-compra]').forEach((cell) => {
                cell.textContent = cell.getAttribute('data-live-original-compra');
                cell.removeAttribute('data-live-original-compra');
            });
            if (bloco) bloco.classList.remove('active');
            if (body) body.innerHTML = '';

            if (!item) {
                const vazio = document.getElementById('compraMontagemVazia');
                if (vazio) vazio.style.display = '';
                return;
            }

            const osKey = item.numeroOs || '__sem_os';
            const rowsDaOs = Array.from(document.querySelectorAll('.compra-montagem-item-row')).filter((row) => row.dataset.os === osKey);
            const botaoOs = Array.from(document.querySelectorAll('.btn-toggle-os[data-compra-os]')).find((btn) => btn.dataset.compraOs === 'montagem:' + osKey);
            const htmlLinha =
                '<tr class="compra-montagem-item-row live-montagem-row">' +
                '<td></td>' +
                '<td></td>' +
                '<td>' + escapeHtml(item.produto) + '</td>' +
                '<td>' + item.quantidade.toFixed(2).replace('.', ',') + ' kg</td>' +
                '<td>' + fmtPrecoCompra(item.preco) + '</td>' +
                '<td colspan="2">Total: ' + fmtPrecoCompra(item.total) + ' | Data: ' + escapeHtml(item.data) + '</td>' +
                '<td><span class="preview-status">Preenchendo agora</span></td>' +
                '</tr>';

            if (rowsDaOs.length > 0) {
                rowsDaOs.forEach((row) => row.classList.remove('hidden'));
                if (botaoOs) botaoOs.textContent = '▲';
                const header = rowsDaOs[0].previousElementSibling;
                const itemCell = header?.classList.contains('os-group-header') ? header.children[3] : null;
                if (itemCell && !itemCell.hasAttribute('data-live-original-compra')) {
                    itemCell.setAttribute('data-live-original-compra', itemCell.textContent.trim());
                    itemCell.textContent = itemCell.textContent.trim() + ' + 1 em preenchimento';
                }
                rowsDaOs[rowsDaOs.length - 1].insertAdjacentHTML('afterend', htmlLinha);
                const novaLinha = rowsDaOs[rowsDaOs.length - 1].nextElementSibling;
                if (novaLinha) {
                    novaLinha.classList.add('live-montagem-inline-compra');
                    novaLinha.dataset.os = osKey;
                }
                return;
            }

            if (bloco && body) {
                body.innerHTML =
                    '<tr class="os-group-header live-montagem-row live-montagem-inline-compra">' +
                    '<td style="text-align:center;"><button type="button" class="btn-toggle-os">▲</button></td>' +
                    '<td>' + escapeHtml(item.numeroOs) + '</td>' +
                    '<td>' + escapeHtml(item.fornecedor) + '</td>' +
                    '<td>1 item em preenchimento</td>' +
                    '<td>' + item.quantidade.toFixed(2).replace('.', ',') + ' kg</td>' +
                    '<td>' + fmtPrecoCompra(item.total) + '</td>' +
                    '<td><span class="preview-status">Ainda nao salvo</span></td>' +
                    '<td><span class="preview-status">' + (item.revendaSaoPaulo ? 'Revenda SP' : 'Preenchendo agora') + '</span></td>' +
                    '</tr>' + htmlLinha;
                bloco.classList.add('active');
                const vazio = document.getElementById('compraMontagemVazia');
                if (vazio) vazio.style.display = 'none';
            }
        }

        function salvarRascunhoCompraLocal() {
            const numeroOs = valorCampoCompra('numero_os').trim();
            if (!numeroOs) return;
            const dados = {
                numero_os: numeroOs,
                usuario_id: String(window.AGROCOLITTI_CURRENT_USER_ID || ''),
                tipo_fluxo: 'compra',
                fornecedor_id: valorCampoCompra('fornecedor_id'),
                data_prevista: valorCampoCompra('data_prevista'),
                forma_pagamento: valorCampoCompra('forma_pagamento'),
                revenda_sao_paulo: document.querySelector('[name="revenda_sao_paulo"]')?.checked ? '1' : '0',
                etapa: etapaAtualCompraSlug(),
                atualizado_em: new Date().toISOString()
            };
            dados.url = montarUrlRascunhoCompra(dados);
            localStorage.setItem('agrocolitti_compra_draft', JSON.stringify(dados));
            mostrarBannerRascunhoCompra();
        }

        function montarUrlRascunhoCompra(dados) {
            const params = new URLSearchParams();
            params.set('numero_os', dados.numero_os || '');
            if (dados.fornecedor_id) params.set('fornecedor_id', dados.fornecedor_id);
            if (dados.data_prevista) params.set('data_prevista', dados.data_prevista);
            if (dados.forma_pagamento) params.set('forma_pagamento', dados.forma_pagamento);
            if (dados.revenda_sao_paulo === '1') params.set('revenda_sao_paulo', '1');
            params.set('etapa', dados.etapa || 'produto');
            return 'previsao_fornecedor.php?' + params.toString();
        }

        function mostrarBannerRascunhoCompra() {
            const banner = document.getElementById('compraDraftBanner');
            if (!banner) return;
            let dados = null;
            try { dados = JSON.parse(localStorage.getItem('agrocolitti_compra_draft') || 'null'); } catch (e) {}
            const usuarioAtual = String(window.AGROCOLITTI_CURRENT_USER_ID || '');
            const usuarioRascunho = String(dados?.usuario_id || '');
            if (!dados || !dados.numero_os || !usuarioRascunho || usuarioRascunho !== usuarioAtual) {
                banner.style.display = 'none';
                return;
            }
            banner.querySelector('strong').textContent = 'Continuar editando OS ' + dados.numero_os;
            banner.querySelector('a').href = montarUrlRascunhoCompra(dados);
            banner.style.display = 'flex';
        }

        function cancelarFluxoNovaPrevisao() {
            if (!confirm('Cancelar este fluxo e começar uma nova previsão em outra OS? Itens já anexados não serão excluídos.')) {
                return;
            }
            localStorage.removeItem('agrocolitti_compra_draft');
            window.location.href = 'previsao_fornecedor.php';
        }

        function prepararEmitirCompra(botao) {
            const form = botao.form || document.querySelector('form[action="actions/salvar_previsao_fornecedor.php"]');
            if (!form) return false;

            const produto = form.querySelector('[name="produto_id"]');
            const temNovoItem = produto && produto.value !== '';

            document.getElementById('acao_final_compra').value = 'emitir_os';
            localStorage.removeItem('agrocolitti_compra_draft');
            form.noValidate = true;

            if (!temNovoItem && compraItensExistentes > 0) {
                form.action = 'actions/emitir_os_fornecedor.php';
            } else {
                form.action = 'actions/salvar_previsao_fornecedor.php';
            }

            form.submit();
            return false;
        }

        function inicializarRascunhoCompra() {
            const form = document.querySelector('form[action="actions/salvar_previsao_fornecedor.php"]');
            if (!form) return;
            form.addEventListener('change', salvarRascunhoCompraLocal);
            form.addEventListener('input', salvarRascunhoCompraLocal);
            form.addEventListener('change', atualizarPreviewCompraAoVivo);
            form.addEventListener('input', atualizarPreviewCompraAoVivo);
            form.addEventListener('submit', function () {
                const acao = document.getElementById('acao_final_compra').value;
                if (acao === 'enviar_os') {
                    localStorage.removeItem('agrocolitti_compra_draft');
                    return;
                }
                salvarRascunhoCompraLocal();
            });
            mostrarBannerRascunhoCompra();
            atualizarPreviewCompraAoVivo();
        }

        // ── Persistencia da OS aberta (montagem / pendentes) ────────────────────
        // Mantem o dropdown da OS aberto e rola ate ele depois de salvar uma
        // edicao (a pagina recarrega), igual ao Historico de Vendas.
        const COMPRA_OS_ABERTA_KEY = 'agrocolitti_compra_os_aberta';

        function marcarCompraOsAberta(grupo, osKey) {
            if (osKey === undefined || osKey === null || osKey === '') return;
            try { sessionStorage.setItem(COMPRA_OS_ABERTA_KEY, grupo + '|' + osKey); } catch (e) {}
        }

        function limparCompraOsAberta() {
            try { sessionStorage.removeItem(COMPRA_OS_ABERTA_KEY); } catch (e) {}
        }

        function reabrirCompraOsSalva() {
            let salvo = null;
            try { salvo = sessionStorage.getItem(COMPRA_OS_ABERTA_KEY); } catch (e) {}
            if (!salvo) return;
            const sep   = salvo.indexOf('|');
            const grupo = sep >= 0 ? salvo.slice(0, sep) : 'pendente';
            const osKey = sep >= 0 ? salvo.slice(sep + 1) : salvo;
            const rowClass = grupo === 'pendente' ? '.compra-pendente-item-row' : '.compra-montagem-item-row';
            const rows = Array.from(document.querySelectorAll(rowClass + '[data-os]'))
                .filter((r) => r.dataset.os === osKey);
            if (rows.length === 0) { limparCompraOsAberta(); return; }
            rows.forEach((r) => r.classList.remove('hidden'));
            const btn = Array.from(document.querySelectorAll('.btn-toggle-os[data-compra-os]'))
                .find((b) => b.getAttribute('data-compra-os') === grupo + ':' + osKey);
            if (btn) btn.textContent = '▲';
            const header = btn ? btn.closest('.os-group-header') : rows[0];
            if (header) requestAnimationFrame(() => header.scrollIntoView({block: 'center'}));
        }

        function toggleCompraOsItens(osKey, grupo) {
            const rowClass = grupo === 'pendente' ? '.compra-pendente-item-row' : '.compra-montagem-item-row';
            const rows = Array.from(document.querySelectorAll(rowClass + '[data-os="' + osKey + '"]'));
            const btn = document.querySelector('.btn-toggle-os[data-compra-os="' + grupo + ':' + osKey + '"]');
            const hidden = rows[0]?.classList.contains('hidden');
            rows.forEach((row) => row.classList.toggle('hidden', !hidden));
            if (btn) btn.textContent = hidden ? '▲' : '▼';
            if (hidden) marcarCompraOsAberta(grupo, osKey); else limparCompraOsAberta();
        }


        document.addEventListener('DOMContentLoaded', function () {
            reabrirCompraOsSalva();
            if (previsaoAbrirEdicao) {
                abrirModalEdicao(previsaoAbrirEdicao);
            }
            iniciarDownloadPedidoCompra(autoDownloadPedidoCompraUrl);
            inicializarWizardCompra();
            inicializarRascunhoCompra();
        });
    </script>

</head>



<body>



    <?php renderAppHeader('..'); ?>



        <div class="container">

        <?php if ($cicloAtivo): ?>

            <div class="msg-sucesso">Ciclo ativo: <?= htmlspecialchars(getNomeCiclo($cicloAtivo)) ?></div>

        <?php endif; ?>

        <?php if (isset($_GET['msg'])): ?>
            <?php if ($_GET['msg'] === 'salvo'): ?>

                <div class="msg-sucesso">✅ Previsão anexada com sucesso!</div>

            <?php elseif ($_GET['msg'] === 'rascunho_salvo'): ?>

                <div class="msg-sucesso">✅ Rascunho de compra salvo com sucesso!</div>

            <?php elseif ($_GET['msg'] === 'foto_salva'): ?>

                <div class="msg-sucesso">✅ Foto atualizada com sucesso!</div>

            <?php elseif ($_GET['msg'] === 'atualizado'): ?>
                <div class="msg-sucesso">✅ Previsão atualizada com sucesso!</div>
            <?php elseif ($_GET['msg'] === 'produto_adicionado'): ?>
                <div class="msg-sucesso">✅ Produto adicionado na OS pendente e pedido Word atualizado.</div>
            <?php elseif ($_GET['msg'] === 'excluido'): ?>
                <div class="msg-sucesso">✅ Previsão excluída com sucesso!</div>
            <?php elseif ($_GET['msg'] === 'entrada_registrada'): ?>

                <div class="msg-sucesso">✅ Chegada confirmada e entrada registrada com sucesso!</div>

            <?php elseif ($_GET['msg'] === 'enviado_lote'): ?>

                <div class="msg-sucesso">✅ OS enviada para pendentes com <?= (int) ($_GET['total'] ?? 0) ?> item(ns).</div>

            <?php elseif ($_GET['msg'] === 'emitido'): ?>

                <div class="msg-sucesso">✅ Compra enviada direto para o histórico com <?= (int) ($_GET['total'] ?? 0) ?> item(ns), sem movimentar estoque.</div>

            <?php elseif ($_GET['msg'] === 'erro'): ?>

                <div class="msg-erro">Erro: <?= htmlspecialchars($_GET['detalhe'] ?? 'Erro ao processar.'); ?></div>
            <?php endif; ?>
        <?php endif; ?>
        <div id="compraDraftBanner" class="draft-resume-banner">
            <strong>Continuar editando OS</strong>
            <a href="#">Continuar fluxo</a>
        </div>

        <div class="card form-card">
            <h2 class="form-title"><?= $adicionarProdutoPendente && $numeroOsSelecionado !== '' ? 'Adicionar Produto na OS ' . htmlspecialchars($numeroOsSelecionado) : 'Nova Previs&atilde;o' ?></h2>
            <div class="msg-erro">Fornecedor e produto precisam ter as informacoes fiscais completas para concluir a compra.</div>
            <div class="wizard-progress" id="wizardCompraProgress"></div>
            <form method="POST" action="actions/salvar_previsao_fornecedor.php" enctype="multipart/form-data" class="form-grid">
                <input type="hidden" name="acao_final" id="acao_final_compra" value="adicionar_item">
                <input type="hidden" name="adicionar_pendente" value="<?= $adicionarProdutoPendente ? '1' : '0' ?>">
                <h3 class="form-section-title">Dados principais da previs&atilde;o</h3>
                <div class="compra-field-fornecedor">

                    <label>Fornecedor</label>

                    <select name="fornecedor_id" id="fornecedor_id" onchange="atualizarFornecedorInfo('fornecedor_id', 'fornecedor_info')" required>

                        <option value="">Selecione</option>
                        <?php foreach ($fornecedores as $fornecedor): ?>
                            <?php $fiscalOk = cadastroFiscalPessoaCompleta($fornecedor); ?>

                            <option value="<?= $fornecedor['id'] ?>" <?= $fornecedorSelecionado === (int) $fornecedor['id'] ? 'selected' : '' ?> <?= $fiscalOk ? '' : 'disabled' ?>

                                data-cnpj="<?= htmlspecialchars($fornecedor['cnpj'] ?? '', ENT_QUOTES, 'UTF-8') ?>"

                                data-telefone="<?= htmlspecialchars($fornecedor['telefone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"

                                data-endereco="<?= htmlspecialchars($fornecedor['endereco'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

                                <?= htmlspecialchars($fornecedor['nome']) ?><?= $fiscalOk ? '' : ' - fiscal incompleto' ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="compra-field-cnpj">

                    <label>CNPJ</label>

                    <input type="text" id="fornecedor_info_cnpj" readonly>

                </div>

                <h3 class="form-section-title">Dados do fornecedor</h3>

                <div class="compra-field-telefone">

                    <label>Telefone</label>

                    <input type="text" id="fornecedor_info_telefone" readonly>

                </div>

                <div class="compra-field-endereco">

                    <label>Endereço</label>

                    <input type="text" id="fornecedor_info_endereco" readonly>

                </div>

                <div class="compra-field-revenda">
                    <label class="revenda-sp-option">
                        <input type="checkbox" name="revenda_sao_paulo" value="1" <?= $revendaSaoPauloSelecionada ? 'checked' : '' ?> onchange="confirmarRevendaSaoPaulo(this)">
                        <span>Revenda São Paulo</span>
                    </label>
                </div>

                <div class="compra-field-produto">

                    <label>Produto</label>

                    <select name="produto_id" id="compra_produto_id" required>

                        <option value="">Selecione</option>
                        <?php foreach ($produtos as $produto): ?>
                            <?php $fiscalOk = cadastroFiscalProdutoCompleto($produto); $codigoAntigo = produtoCodigoAntigoCompra($produto); ?>

                            <option value="<?= $produto['id'] ?>" <?= $produtoSelecionado === (int) $produto['id'] ? 'selected' : '' ?> <?= $fiscalOk ? '' : 'disabled' ?>><?= htmlspecialchars(produtoNomeAgranelCompra((string) $produto['nome'])) ?><?= $fiscalOk ? '' : ' - fiscal incompleto' ?><?= $codigoAntigo ? ' - código antigo' : '' ?></option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="compra-field-os">
                    <label>Número OS</label>
                    <input type="text" name="numero_os" value="<?= htmlspecialchars($numeroOsSelecionado, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="compra-field-qtd">
                    <label>Quantidade Prevista (kg)</label>
                    <input type="number" step="0.01" name="quantidade_prevista" id="compra_quantidade_prevista" required>
                </div>
                <div class="compra-field-preco">
                    <label>Preço (R$/kg)</label>
                    <input type="number" step="0.01" min="0" name="preco" id="compra_preco" required>
                </div>
                <div class="compra-field-data">
                    <label>Data Prevista</label>
                    <input type="date" name="data_prevista" value="<?= htmlspecialchars($dataPrevistaSelecionada, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="compra-field-forma">
                    <label>Forma de Pagamento</label>
                    <select name="forma_pagamento">
                        <option value="" <?= $formaPagamentoSelecionada === '' ? 'selected' : '' ?>>Selecione</option>
                        <option value="boleto" <?= $formaPagamentoSelecionada === 'boleto' ? 'selected' : '' ?>>Boleto</option>
                        <option value="deposito" <?= $formaPagamentoSelecionada === 'deposito' ? 'selected' : '' ?>>Depósito</option>
                        <option value="pix" <?= $formaPagamentoSelecionada === 'pix' ? 'selected' : '' ?>>PIX</option>
                    </select>
                </div>
                <div class="compra-field-prazo">
                    <label>Prazo de Pagamento</label>
                    <input type="text" name="prazo_pagamento" placeholder="Ex.: 30 dias, à vista, 7/14/21..."
                        value="<?= htmlspecialchars($prazoPagamentoSelecionado, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="compra-field-entreposto">
                    <label>Entreposto</label>
                    <input type="text" name="entreposto" placeholder="Ex.: Agrocolitti"
                        value="<?= htmlspecialchars($entrepostoSelecionado, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-attachments">

                    <div class="attachments-title">Anexos</div>

                    <label class="upload-dropzone">

                        <input type="file" name="foto" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp,image/heic,image/avif">

                        <span class="upload-icon"><i class="bi bi-camera"></i></span>

                        <strong>Foto da Previs&atilde;o (opcional)</strong>

                        <small>Clique para enviar ou arraste o arquivo aqui</small>

                    </label>

                </div>

                <div class="wizard-nav" id="wizardCompraNav">

                    <button type="button" class="btn-secondary" id="wizardCompraPrev" title="Voltar"><i class="bi bi-arrow-left"></i></button>

                    <button type="button" class="btn-primary" id="wizardCompraNext" title="Proxima etapa"><i class="bi bi-arrow-right"></i></button>

                </div>

                <div class="wizard-actions-final" id="compraAcoesFinais">
                    <button type="button" class="btn-secondary" title="Cancelar fluxo"
                        onclick="cancelarFluxoNovaPrevisao()">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                    <button type="submit" class="btn-secondary" title="Adicionar outro produto"
                        onclick="document.getElementById('acao_final_compra').value='adicionar_item'">
                        <i class="bi bi-plus-lg"></i>
                    </button>

                    <button type="submit" class="btn-edit" title="Salvar rascunho" style="background-color: #0288d1;"

                        onclick="document.getElementById('acao_final_compra').value='salvar_rascunho'">

                        <i class="bi bi-save"></i>

                    </button>

                    <button type="submit" class="btn-primary" title="Enviar compra"

                        onclick="document.getElementById('acao_final_compra').value='enviar_os'">

                        <i class="bi bi-send"></i>

                    </button>

                    <button type="button" class="btn-emitir" title="Emitir - vai direto para o historico de compras sem movimentar estoque"
                        onclick="return prepararEmitirCompra(this)">
                        <i class="bi bi-file-earmark-check"></i>
                    </button>

                </div>

            </form>
        </div>

        <div class="card section-card live-item-preview" id="previewCompraAoVivo">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:12px;flex-wrap:wrap;">
                <h2 style="margin:0;">Item em preenchimento</h2>
                <span class="preview-status"><i class="bi bi-pencil-square"></i> Atualizando ao vivo</span>
            </div>
            <div class="table-container">
                <table data-no-responsive="1">
                    <thead>
                        <tr>
                            <th>OS</th>
                            <th>Fornecedor</th>
                            <th>Produto</th>
                            <th>Quantidade</th>
                            <th>Preco</th>
                            <th>Total</th>
                            <th>Data</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="previewCompraAoVivoBody"></tbody>
                </table>
            </div>
            <p class="preview-note">Este item ja entra na OS ao clicar em +, salvar rascunho ou enviar compra.</p>
        </div>

        <div id="modalEnviarOs" class="modal-bg">
            <div class="modal-box">
                <h3>Enviar OS para Pendentes</h3>
                <p style="margin:0 0 12px 0;font-size:14px;color:#555;">Digite o número da OS a ser enviada:</p>

                <input type="text" id="inputNumeroOs" placeholder="Ex.: 001" onkeydown="if(event.key==='Enter') confirmarEnvioOs()">

                <form id="formEnviarOs" action="actions/enviar_os_fornecedor.php" method="POST">

                    <input type="hidden" name="numero_os" id="hiddenNumeroOs">

                </form>

                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">

                    <button type="button" class="btn-secondary" title="Cancelar" onclick="fecharModalEnviarOs()"><i class="bi bi-x-lg"></i></button>

                    <button type="button" class="btn-primary" title="Enviar" onclick="confirmarEnvioOs()"><i class="bi bi-send"></i></button>

                </div>

            </div>

        </div>



        <div class="card section-card">

            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;gap:12px;flex-wrap:wrap;">

                <h2 style="margin:0;">Lista de Montagem</h2>

            </div>



            <?php if ($resumoAnexadas && $resumoAnexadas->num_rows > 0): ?>

                <div class="table-container" style="margin-bottom:20px;">

                    <table data-no-responsive="1">

                        <tr>
                            <th style="width:44px;"></th>
                            <th>OS</th>
                            <th>Fornecedor</th>
                            <th>Itens Anexados</th>
                            <th>Total (kg)</th>

                            <th>Total (R$)</th>

                            <th>Pedido</th>

                            <th>Ações</th>

                        </tr>

                        <?php while ($resumo = $resumoAnexadas->fetch_assoc()): ?>

                            <?php 
                            $numeroOsResumo = (string) ($resumo['numero_os'] ?? ''); 
                            $osKeyResumo = $numeroOsResumo !== '' ? $numeroOsResumo : '__sem_os';
                            $osEscapeResumo = htmlspecialchars(addslashes($osKeyResumo), ENT_QUOTES, 'UTF-8');
                            $itensResumo = $previsoesAnexadasPorOs[$osKeyResumo] ?? [];
                            $editOsUrl = "previsao_fornecedor.php?" . http_build_query([
                                'numero_os'     => $numeroOsResumo,
                                'fornecedor_id' => $resumo['fornecedor_id'],
                                'data_prevista' => $resumo['data_prevista'],
                                'etapa'         => 'produto'
                            ]);
                            ?>
                            <tr class="os-group-header">
                                <td>
                                    <?php if (!empty($itensResumo)): ?>
                                        <button type="button" class="btn-toggle-os" data-compra-os="montagem:<?= htmlspecialchars($osKeyResumo, ENT_QUOTES) ?>"
                                            onclick="toggleCompraOsItens('<?= $osEscapeResumo ?>', 'montagem')">▼</button>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($resumo['numero_os'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($resumo['fornecedor'] ?? '-') ?></td>
                                <td><?= (int) $resumo['total_itens'] ?></td>
                                <td><?= number_format((float) $resumo['total_kg'], 2, ',', '.') ?></td>

                                <td>R$ <?= number_format((float) $resumo['total_valor'], 2, ',', '.') ?></td>

                                <td>

                                    <?php if ($numeroOsResumo !== ''): ?>

                                        <div class="acoes">

                                            <a class="btn-imprimir" download title="Gerar Pedido Word" href="gerar_pedido_compra.php?numero_os=<?= rawurlencode($numeroOsResumo) ?>"><i class="bi bi-file-earmark-text"></i></a>

                                        </div>

                                    <?php endif; ?>

                                </td>

                                <td>
                                    <div class="acoes">
                                        <a href="<?= $editOsUrl ?>" class="btn-edit" title="Continuar esta OS"><i class="bi bi-pencil-square"></i></a>
                                        <?php if ($numeroOsResumo !== ''): ?>
                                            <form method="POST" action="actions/excluir_os_fornecedor.php" style="margin:0;" onsubmit="return confirm('Deseja excluir a OS <?= htmlspecialchars($numeroOsResumo, ENT_QUOTES) ?> inteira?');">
                                                <input type="hidden" name="numero_os" value="<?= htmlspecialchars($numeroOsResumo, ENT_QUOTES) ?>">
                                                <input type="hidden" name="status" value="anexado">
                                                <button type="submit" class="btn-danger" title="Excluir OS inteira"><i class="bi bi-trash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php foreach ($itensResumo as $itemResumo): ?>
                                <?php
                                $precoItemResumo = isset($itemResumo['preco']) ? (float) $itemResumo['preco'] : 0;
                                $totalItemResumo = $precoItemResumo * (float) $itemResumo['quantidade_prevista'];
                                $itemResumoJson = htmlspecialchars(json_encode([
                                    'id' => (int) $itemResumo['id'],
                                    'produto_id' => (int) $itemResumo['produto_id'],
                                    'fornecedor_id' => (int) $itemResumo['fornecedor_id'],
                                    'numero_os' => (string) ($itemResumo['numero_os'] ?? ''),
                                    'quantidade_prevista' => (float) $itemResumo['quantidade_prevista'],
                                    'preco' => $precoItemResumo > 0 ? $precoItemResumo : '',
                                    'data_prevista' => (string) $itemResumo['data_prevista'],
                                    'status' => 'anexado',
                                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr class="compra-montagem-item-row hidden" data-os="<?= htmlspecialchars($osKeyResumo, ENT_QUOTES) ?>">
                                    <td></td>
                                    <td></td>
                                    <td><?= htmlspecialchars($itemResumo['produto']) ?></td>
                                    <td><?= number_format($itemResumo['quantidade_prevista'], 2, ',', '.') ?> kg</td>
                                    <td>R$ <?= number_format($precoItemResumo, 2, ',', '.') ?></td>
                                    <td colspan="2">Total: R$ <?= number_format($totalItemResumo, 2, ',', '.') ?> | Data: <?= date('d/m/Y', strtotime($itemResumo['data_prevista'])) ?></td>
                                    <td>
                                        <div class="acoes">
                                            <button type="button" class="btn-edit" title="Editar" onclick='abrirModalEdicao(<?= $itemResumoJson ?>)'>
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" action="actions/excluir_previsao_fornecedor.php" style="margin:0;" onsubmit="return confirm('Deseja excluir esta previsão?');">
                                                <input type="hidden" name="id" value="<?= (int) $itemResumo['id'] ?>">
                                                <button type="submit" class="btn-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endwhile; ?>
                    </table>

                </div>

            <?php else: ?>
                <div class="msg-sucesso" id="compraMontagemVazia">Nenhuma previsão anexada no momento.</div>
            <?php endif; ?>

            <div class="table-container live-montagem-preview" id="compraMontagemAoVivo">
                <table data-no-responsive="1">
                    <thead>
                        <tr>
                            <th style="width:44px;"></th>
                            <th>OS</th>
                            <th>Fornecedor</th>
                            <th>Itens Anexados</th>
                            <th>Total (kg)</th>
                            <th>Total (R$)</th>
                            <th>Pedido</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody id="compraMontagemAoVivoBody"></tbody>
                </table>
            </div>

            <?php if (false && $previsoesAnexadas && $previsoesAnexadas->num_rows > 0): ?>
                <div class="table-container">

                    <table data-no-responsive="1">

                        <tr>

                            <th>OS</th>

                            <th>Produto</th>

                            <th>Fornecedor</th>

                            <th>Quantidade</th>

                            <th>Preço</th>

                            <th>Total</th>

                            <th>Data</th>

                            <th>Ação</th>

                        </tr>

                        <?php while ($item = $previsoesAnexadas->fetch_assoc()): ?>

                            <?php

                            $precoItem = isset($item['preco']) ? (float) $item['preco'] : 0;

                            $totalItem = $precoItem * (float) $item['quantidade_prevista'];

                            $itemJson = htmlspecialchars(json_encode([

                                'id' => (int) $item['id'],

                                'produto_id' => (int) $item['produto_id'],

                                'fornecedor_id' => (int) $item['fornecedor_id'],

                                'numero_os' => (string) ($item['numero_os'] ?? ''),

                                'quantidade_prevista' => (float) $item['quantidade_prevista'],

                                'preco' => $precoItem > 0 ? $precoItem : '',

                                'data_prevista' => (string) $item['data_prevista'],

                            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

                            ?>

                            <tr>

                                <td><?= htmlspecialchars($item['numero_os'] ?? '—') ?></td>

                                <td><?= htmlspecialchars($item['produto']) ?></td>

                                <td><?= htmlspecialchars($item['fornecedor']) ?></td>

                                <td><?= number_format($item['quantidade_prevista'], 2, ',', '.') ?> kg</td>

                                <td>R$ <?= number_format($precoItem, 2, ',', '.') ?></td>

                                <td>R$ <?= number_format($totalItem, 2, ',', '.') ?></td>

                                <td><?= date('d/m/Y', strtotime($item['data_prevista'])) ?></td>

                                <td>

                                    <div class="acoes">

                                        <button type="button" class="btn-edit" title="Editar" onclick='abrirModalEdicao(<?= $itemJson ?>)'>

                                            <i class="bi bi-pencil"></i>

                                        </button>

                                        <form method="POST" action="actions/excluir_previsao_fornecedor.php" style="margin:0;" onsubmit="return confirm('Deseja excluir esta previsão?');">

                                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">

                                            <button type="submit" class="btn-danger" title="Excluir"><i class="bi bi-trash"></i></button>

                                        </form>

                                    </div>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    </table>

                </div>

            <?php endif; ?>

        </div>



        <div class="card section-card">
            <h2>Previsões Pendentes</h2>
            <?php if (empty($listaPorOs)): ?>
                <div class="msg-sucesso">Nenhuma previsão pendente no momento.</div>
            <?php else: ?>
            <div class="table-container">
                <table data-no-responsive="1">
                    <tr>
                        <th style="width:44px;"></th>
                        <th>OS</th>
                        <th>Fornecedor</th>
                        <th>Itens</th>
                        <th>Total (kg)</th>
                        <th>Total</th>
                        <th>Ação</th>
                    </tr>
                    <?php foreach ($listaPorOs as $osKey => $osItens): ?>
                        <?php
                        $primeiroItem = $osItens[0];
                        $osNum = (string) ($primeiroItem['numero_os'] ?? '');
                        $osLabel = $osNum !== '' ? $osNum : '— sem OS —';
                        $osEscape = htmlspecialchars(addslashes($osKey), ENT_QUOTES, 'UTF-8');
                        $totalKgOs = array_sum(array_map(static fn($row) => (float) $row['quantidade_prevista'], $osItens));
                        $totalValorOs = array_sum(array_map(static fn($row) => ((float) ($row['preco'] ?? 0)) * (float) $row['quantidade_prevista'], $osItens));
                        $osRevendaSaoPaulo = !empty($primeiroItem['revenda_sao_paulo']);
                        $osUrlParam = $osNum !== '' ? rawurlencode($osNum) : '';
                        $adicionarProdutoUrl = $osNum !== '' ? "previsao_fornecedor.php?" . http_build_query([
                            'numero_os' => $osNum,
                            'fornecedor_id' => (int) ($primeiroItem['fornecedor_id'] ?? 0),
                            'data_prevista' => (string) ($primeiroItem['data_prevista'] ?? ''),
                            'forma_pagamento' => (string) ($primeiroItem['forma_pagamento'] ?? ''),
                            'revenda_sao_paulo' => $osRevendaSaoPaulo ? '1' : '0',
                            'etapa' => 'produto',
                            'adicionar_pendente' => '1',
                            'redirect_to' => 'previsao_fornecedor.php',
                        ]) : '';
                        $itensRecebimentoJson = htmlspecialchars(json_encode(array_map(static fn($row) => [
                            'id' => (int) $row['id'],
                            'produto' => (string) ($row['produto'] ?? '-'),
                            'quantidade_prevista' => (float) $row['quantidade_prevista'],
                        ], $osItens), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                        $numeroOsRecebimentoJson = htmlspecialchars(json_encode($osNum, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr class="os-group-header <?= $osRevendaSaoPaulo ? 'compra-revenda-sp' : '' ?>">
                            <td>
                                <button type="button" class="btn-toggle-os" data-compra-os="pendente:<?= htmlspecialchars($osKey, ENT_QUOTES) ?>"
                                    onclick="toggleCompraOsItens('<?= $osEscape ?>', 'pendente')">▼</button>
                            </td>
                            <td>
                                <?= htmlspecialchars($osLabel) ?>
                                <?php if ($osRevendaSaoPaulo): ?>
                                    <span class="badge-revenda-sp">Revenda São Paulo</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($primeiroItem['fornecedor'] ?? '-') ?></td>
                            <td><?= count($osItens) ?></td>
                            <td><?= number_format($totalKgOs, 2, ',', '.') ?> kg</td>
                            <td>R$ <?= number_format($totalValorOs, 2, ',', '.') ?></td>
                            <td>
                                <div class="acoes">
                                    <?php if ($osUrlParam !== ''): ?>
                                        <a class="btn-imprimir" download title="Gerar Pedido Word" href="gerar_pedido_compra.php?numero_os=<?= $osUrlParam ?>"><i class="bi bi-file-earmark-text"></i></a>
                                        <a class="btn-edit" title="Adicionar produto" href="<?= htmlspecialchars($adicionarProdutoUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-plus-lg"></i></a>
                                        <button type="button" class="btn-primary" title="Confirmar OS inteira"
                                            onclick='abrirModalRecebimentoOs(<?= $numeroOsRecebimentoJson ?>, <?= $itensRecebimentoJson ?>, <?= $osRevendaSaoPaulo ? 'true' : 'false' ?>)'>
                                            <i class="bi bi-check2-all"></i>
                                        </button>
                                        <form method="POST" action="actions/excluir_os_fornecedor.php" style="margin:0;" onsubmit="return confirm('Deseja excluir a OS <?= htmlspecialchars($osNum, ENT_QUOTES) ?> inteira?');">
                                            <input type="hidden" name="numero_os" value="<?= htmlspecialchars($osNum, ENT_QUOTES) ?>">
                                            <input type="hidden" name="status" value="pendente">
                                            <button type="submit" class="btn-danger" title="Excluir OS inteira"><i class="bi bi-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php foreach ($osItens as $item): ?>
                            <?php
                            $itemJson = htmlspecialchars(json_encode([
                                'id' => (int) $item['id'],
                                'produto_id' => (int) $item['produto_id'],
                                'fornecedor_id' => (int) $item['fornecedor_id'],
                                'numero_os' => (string) ($item['numero_os'] ?? ''),
                                'quantidade_prevista' => (float) $item['quantidade_prevista'],
                                'preco' => isset($item['preco']) ? (float) $item['preco'] : '',
                                'data_prevista' => (string) $item['data_prevista'],
                                'forma_pagamento' => (string) ($item['forma_pagamento'] ?? ''),
                                'revenda_sao_paulo' => !empty($item['revenda_sao_paulo']) ? 1 : 0,
                                'status' => 'pendente',
                            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                            $precoItem = isset($item['preco']) ? (float) $item['preco'] : 0;
                            $totalItem = $precoItem * (float) $item['quantidade_prevista'];
                            $fotoNome = !empty($item['foto']) ? basename((string) $item['foto']) : null;
                            $fotoPath = $fotoNome ? dirname(__DIR__) . "/storage/uploads/previsoes/" . $fotoNome : null;
                            if (!$fotoPath || !is_file($fotoPath)) {
                                $arquivosLegado = glob(dirname(__DIR__) . "/storage/uploads/previsoes/previsao_" . $item['id'] . "_*");
                                $fotoPath = $arquivosLegado[0] ?? null;
                                $fotoNome = $fotoPath ? basename($fotoPath) : null;
                            }
                            ?>
                            <tr class="compra-pendente-item-row hidden <?= !empty($item['revenda_sao_paulo']) ? 'compra-revenda-sp' : '' ?>" data-os="<?= htmlspecialchars($osKey, ENT_QUOTES) ?>">
                                <td></td>
                                <td><?= htmlspecialchars($item['produto']) ?></td>
                                <td><?= htmlspecialchars($item['fornecedor']) ?></td>
                                <td><?= number_format($item['quantidade_prevista'], 2, ',', '.') ?> kg</td>
                                <td>R$ <?= number_format($precoItem, 2, ',', '.') ?></td>
                                <td>R$ <?= number_format($totalItem, 2, ',', '.') ?><br><?= date('d/m/Y', strtotime($item['data_prevista'])) ?></td>
                                <td>
                                    <div class="acoes">
                                        <?php if ($osUrlParam === ''): ?>
                                            <button type="button" class="btn-primary" title="Confirmar Recebimento"
                                                onclick="abrirModal(<?= $item['id'] ?>, <?= $item['quantidade_prevista'] ?>, '<?= htmlspecialchars((string) $item['numero_os'], ENT_QUOTES) ?>', <?= !empty($item['revenda_sao_paulo']) ? 'true' : 'false' ?>)">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn-edit" title="Editar" onclick='abrirModalEdicao(<?= $itemJson ?>)'>
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php if ($fotoNome): ?>
                                            <a href="../storage/uploads/previsoes/<?= rawurlencode($fotoNome) ?>" target="_blank" class="btn-secondary" title="Ver Foto"><i class="bi bi-image"></i></a>
                                        <?php endif; ?>
                                        <form method="POST" action="actions/excluir_previsao_fornecedor.php" style="margin:0;" onsubmit="return confirm('Deseja excluir esta previsão?');">
                                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                            <button type="submit" class="btn-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>



    <div id="modalEdicao" class="modal-bg">

        <div class="modal-box">

            <h3>Editar Previsão de Fornecedor</h3>



            <!-- Formulario de edicao separado do cadastro principal para deixar o fluxo mais legivel. -->

            <form method="POST" action="actions/editar_previsao_fornecedor.php" class="form-grid" enctype="multipart/form-data">
                <input type="hidden" name="id" id="edit_id">
                <input type="hidden" name="redirect_to" id="edit_redirect_to" value="../previsao_fornecedor.php">
                <input type="hidden" id="edit_forma_pagamento">
                <input type="hidden" id="edit_revenda_sao_paulo">


                <div>

                    <label>Produto</label>

                    <select name="produto_id" id="edit_produto_id" required>

                        <?php foreach ($produtos as $produto): ?>
                            <?php $fiscalOk = cadastroFiscalProdutoCompleto($produto); $codigoAntigo = produtoCodigoAntigoCompra($produto); ?>

                            <option value="<?= (int) $produto['id'] ?>" <?= $fiscalOk ? '' : 'disabled' ?>><?= htmlspecialchars(produtoNomeAgranelCompra((string) $produto['nome'])) ?><?= $fiscalOk ? '' : ' - fiscal incompleto' ?><?= $codigoAntigo ? ' - código antigo' : '' ?></option>

                        <?php endforeach; ?>

                    </select>

                </div>



                <div>

                    <label>Fornecedor</label>

                    <select name="fornecedor_id" id="edit_fornecedor_id" onchange="atualizarFornecedorInfo('edit_fornecedor_id', 'edit_fornecedor_info')" required>

                        <?php foreach ($fornecedores as $fornecedor): ?>
                            <?php $fiscalOk = cadastroFiscalPessoaCompleta($fornecedor); ?>

                            <option value="<?= (int) $fornecedor['id'] ?>" <?= $fiscalOk ? '' : 'disabled' ?>

                                data-cnpj="<?= htmlspecialchars($fornecedor['cnpj'] ?? '', ENT_QUOTES, 'UTF-8') ?>"

                                data-telefone="<?= htmlspecialchars($fornecedor['telefone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"

                                data-endereco="<?= htmlspecialchars($fornecedor['endereco'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

                                <?= htmlspecialchars($fornecedor['nome']) ?><?= $fiscalOk ? '' : ' - fiscal incompleto' ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div>

                    <label>CNPJ</label>

                    <input type="text" id="edit_fornecedor_info_cnpj" readonly>

                </div>

                <div>

                    <label>Telefone</label>

                    <input type="text" id="edit_fornecedor_info_telefone" readonly>

                </div>

                <div>

                    <label>Endereço</label>

                    <input type="text" id="edit_fornecedor_info_endereco" readonly>

                </div>



                <div>

                    <label>Número OS</label>

                    <input type="text" name="numero_os" id="edit_numero_os" required>

                </div>



                <div>

                    <label>Quantidade Prevista (kg)</label>

                    <input type="number" step="0.01" name="quantidade_prevista" id="edit_quantidade_prevista" required>

                </div>



                <div>

                    <label>Preço (R$/kg)</label>

                    <input type="number" step="0.01" min="0" name="preco" id="edit_preco" required>

                </div>



                <div>
                    <label>Data Prevista</label>
                    <input type="date" name="data_prevista" id="edit_data_prevista" required>
                </div>

                <div>
                    <label>Anexar foto</label>
                    <input type="file" name="foto" id="edit_foto" accept="image/jpeg,image/jpg,image/png,image/webp,image/heic,image/avif">
                    <small>Opcional. Ao enviar uma nova foto, ela substitui a foto atual.</small>
                </div>

                <div style="display:flex; align-items:flex-end; gap:10px;">
                    <button type="button" class="btn-edit" id="edit_adicionar_produto_os" title="Adicionar produto nesta OS" onclick="adicionarProdutoNaOsEditada()"><i class="bi bi-plus-lg"></i></button>
                    <button type="submit" class="btn-primary" title="Salvar"><i class="bi bi-check-lg"></i></button>
                    <button type="button" class="btn-secondary" title="Cancelar" onclick="fecharModalEdicao()"><i class="bi bi-x-lg"></i></button>
                </div>
            </form>

        </div>

    </div>



    <div id="modalAbatimento" class="modal-bg">
        <div class="modal-box">
            <h3>Confirmar Recebimento</h3>
            <form method="POST" action="actions/finalizar_previsao_fornecedor.php">

                <input type="hidden" name="id" id="modal_id">

                <input type="hidden" id="modal_numero_os">

                <p>Quantidade prevista: <strong><span id="modal_quantidade"></span> kg</strong></p>

                <div id="campoQuantidade">
                    <label>Abate / descarte</label>
                    <input type="number" step="0.01" min="0" name="quantidade_abatida" id="quantidade_abatida" oninput="mostrarCampo()">
                    <small style="display:block;margin-top:5px;color:#666;">Produto ruim/devolvido.</small>
                    <br><br>
                    <div id="campoMotivoAbatimento">
                        <label>Motivo</label>
                        <textarea name="motivo_abatimento" id="motivo_abatimento"></textarea>
                    </div>
                    <br><br>
                    <label>Quantidade real recebida</label>
                    <input type="number" step="0.01" min="0" name="quantidade_recebida" id="quantidade_recebida">
                    <small id="modal_recebida_ajuda" style="display:block;margin-top:5px;color:#666;">Final que entrou no estoque.</small>
                </div>
                <br>
                <button class="btn-primary" title="Confirmar"><i class="bi bi-check-lg"></i></button>
                <button type="button" class="btn-secondary" title="Cancelar" onclick="fecharModal()"><i class="bi bi-x-lg"></i></button>
            </form>
            <form method="POST" action="actions/excluir_previsao_fornecedor.php" style="margin-top:10px;" onsubmit="return confirm('Excluir este produto porque não chegou?');">
                <input type="hidden" name="id" id="modal_id_excluir">
                <button class="btn-secondary" type="submit" title="Produto não chegou / excluir"><i class="bi bi-trash"></i></button>
            </form>
        </div>
    </div>
    <div id="modalRecebimentoOs" class="modal-bg">
        <div class="modal-box modal-os-recebimento">
            <h3>Confirmar OS <span id="recebimentoOsNumero"></span></h3>
            <form method="POST" action="actions/finalizar_os_fornecedor.php" id="formRecebimentoOs">
                <input type="hidden" name="numero_os" id="recebimentoOsNumeroInput">
                <div id="recebimentoOsItens"></div>
                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;">
                    <button type="button" class="btn-secondary" title="Cancelar" onclick="fecharModalRecebimentoOs()"><i class="bi bi-x-lg"></i></button>
                    <button type="submit" class="btn-primary" title="Confirmar OS inteira"><i class="bi bi-check2-all"></i></button>
                </div>
            </form>
        </div>
    </div>
    <div id="modalPreviewPedidoCompra" class="modal-bg">
        <div class="modal-box preview-pedido">
            <h3 id="previewPedidoCompraTitulo" style="margin:0;">Conferir Pedido</h3>

            <iframe id="previewPedidoCompraFrame" src="about:blank" title="Preview do pedido de venda"></iframe>

            <div class="preview-actions">

                <button type="button" class="btn-secondary" title="Voltar" onclick="fecharPreviewPedidoCompra()"><i class="bi bi-x-lg"></i></button>

                <button type="button" class="btn-primary" title="Confirmar" onclick="confirmarPreviewPedidoCompra()"><i class="bi bi-check-lg"></i></button>

            </div>

        </div>

    </div>

    <script>

        atualizarFornecedorInfo('fornecedor_id', 'fornecedor_info');

        atualizarFornecedorInfo('edit_fornecedor_id', 'edit_fornecedor_info');

    </script>

</body>



</html>


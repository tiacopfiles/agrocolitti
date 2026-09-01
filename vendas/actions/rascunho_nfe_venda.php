<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/permissions.php';
require __DIR__ . '/../../focus/focus_nfe_operacoes.php';

header('Content-Type: application/json; charset=utf-8');

if (!userCanAccess('exportar_nfe')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Sem permissao para emitir NF-e.']);
    exit;
}

function nfeVendaCampo(array $payload, string $campo, string $default = ''): string
{
    return isset($payload[$campo]) ? (string) $payload[$campo] : $default;
}

function nfeVendaNormalizarItem(string $valor): string
{
    return focusNfeNormalizarComparacaoItem($valor);
}

function nfeVendaItemIdentidade(array $item): array
{
    return [
        'produto_id_origem' => (string) ($item['produto_id_origem'] ?? $item['produto_id'] ?? ''),
        'produto_nome_origem' => (string) ($item['produto_nome_origem'] ?? $item['produto_nome'] ?? $item['descricao'] ?? ''),
        'codigo_produto_esperado' => (string) ($item['codigo_produto_esperado'] ?? $item['codigo_produto'] ?? ''),
        'descricao_esperada' => (string) ($item['descricao_esperada'] ?? $item['descricao'] ?? ''),
    ];
}

function nfeVendaRevisaoCorrespondeItem(array $item, array $revisao): bool
{
    $identidade = nfeVendaItemIdentidade($item);
    $produtoAtual = trim($identidade['produto_id_origem']);
    $produtoRevisao = trim((string) ($revisao['produto_id_origem'] ?? $revisao['produto_id'] ?? ''));
    if ($produtoAtual !== '' && $produtoRevisao !== '') {
        return $produtoAtual === $produtoRevisao;
    }

    $codigoAtual = focusNfeSomenteDigitos($identidade['codigo_produto_esperado']);
    $codigoRevisao = focusNfeSomenteDigitos((string) ($revisao['codigo_produto_esperado'] ?? $revisao['codigo_produto'] ?? ''));
    if ($codigoAtual !== '' && $codigoRevisao !== '') {
        return $codigoAtual === $codigoRevisao;
    }

    $descricaoAtual = nfeVendaNormalizarItem($identidade['descricao_esperada']);
    $descricaoRevisao = nfeVendaNormalizarItem((string) ($revisao['descricao_esperada'] ?? $revisao['descricao'] ?? ''));
    return $descricaoAtual !== '' && $descricaoRevisao !== '' && $descricaoAtual === $descricaoRevisao;
}

function nfeVendaSanearRevisaoItem(array $item, ?array $revisao, bool $exigirCorrespondencia = true): ?array
{
    if (!is_array($revisao)) {
        return null;
    }
    if ($exigirCorrespondencia && !nfeVendaRevisaoCorrespondeItem($item, $revisao)) {
        return null;
    }

    $identidade = nfeVendaItemIdentidade($item);
    $codigoEsperado = trim($identidade['codigo_produto_esperado']);
    $descricaoEsperada = trim($identidade['descricao_esperada']);

    $revisao['produto_id_origem'] = $identidade['produto_id_origem'];
    $revisao['produto_nome_origem'] = $identidade['produto_nome_origem'];
    $revisao['codigo_produto_esperado'] = $codigoEsperado;
    $revisao['descricao_esperada'] = $descricaoEsperada;

    $codigoPostado = trim((string) ($revisao['codigo_produto'] ?? ''));
    if ($codigoEsperado !== '' && (
        $codigoPostado === ''
        || focusNfeSomenteDigitos($codigoPostado) !== focusNfeSomenteDigitos($codigoEsperado)
    )) {
        $revisao['codigo_produto'] = $codigoEsperado;
    }

    $descricaoPostada = trim((string) ($revisao['descricao'] ?? ''));
    if ($descricaoEsperada !== '' && (
        $descricaoPostada === ''
        || nfeVendaNormalizarItem($descricaoPostada) !== nfeVendaNormalizarItem($descricaoEsperada)
    )) {
        $revisao['descricao'] = $descricaoEsperada;
    }

    if (array_key_exists('codigo_beneficio_fiscal', $revisao)) {
        $revisao['codigo_beneficio_fiscal'] = focusNfeCodigoBeneficioFiscalValido($revisao['codigo_beneficio_fiscal']) ?? 'SP010360';
    }

    // Rascunhos antigos apenas repetiam o CST vigente quando foram salvos. Sem a
    // marca de edicao manual, o cadastro atual do produto deve prevalecer ao reabrir.
    foreach (['pis', 'cofins'] as $tributo) {
        $campoEditado = $tributo . '_tributo_editado';
        $foiEditado = (string) ($revisao[$campoEditado] ?? '0') === '1';
        $revisao[$campoEditado] = $foiEditado ? '1' : '0';
        if (!$foiEditado) {
            unset(
                $revisao[$tributo . '_situacao_tributaria'],
                $revisao[$tributo . '_base_calculo'],
                $revisao[$tributo . '_aliquota_porcentual'],
                $revisao[$tributo . '_valor']
            );
        }
    }

    return $revisao;
}

function nfeVendaSanearOpcoesRascunho(array $payload, array $opcoes, bool $exigirCorrespondencia = true): array
{
    $revisao = is_array($opcoes['revisao'] ?? null) ? $opcoes['revisao'] : [];
    $itemsRevisao = is_array($revisao['items'] ?? null) ? $revisao['items'] : [];
    $itemsSaneados = [];

    foreach (array_values($payload['items'] ?? []) as $idx => $item) {
        if (!is_array($item)) {
            continue;
        }
        $saneado = nfeVendaSanearRevisaoItem(
            $item,
            is_array($itemsRevisao[$idx] ?? null) ? $itemsRevisao[$idx] : null,
            $exigirCorrespondencia
        );
        $itemsSaneados[$idx] = $saneado ?? nfeVendaItemIdentidade($item);
    }

    $revisao['items'] = $itemsSaneados;
    $opcoes['revisao'] = $revisao;
    return $opcoes;
}

function nfeVendaItemModal(array $item, ?array $revisao = null): array
{
    $revisao = nfeVendaSanearRevisaoItem($item, $revisao, true) ?? [];
    $identidade = nfeVendaItemIdentidade($item);

    $valor = static function (string $campoItem, string $campoRevisao, mixed $default = '') use ($item, $revisao): mixed {
        return array_key_exists($campoRevisao, $revisao) ? $revisao[$campoRevisao] : ($item[$campoItem] ?? $default);
    };

    return [
        'produto_id_origem' => $identidade['produto_id_origem'],
        'produto_nome_origem' => $identidade['produto_nome_origem'],
        'produto_nome' => $identidade['produto_nome_origem'] !== '' ? $identidade['produto_nome_origem'] : 'Produto',
        'codigo_produto_esperado' => $identidade['codigo_produto_esperado'],
        'descricao_esperada' => $identidade['descricao_esperada'],
        'codigo_produto' => (string) $valor('codigo_produto', 'codigo_produto'),
        'descricao' => (string) $valor('descricao', 'descricao'),
        'codigo_ncm' => (string) $valor('codigo_ncm', 'codigo_ncm'),
        'cfop' => (string) $valor('cfop', 'cfop', '5102'),
        'quantidade' => (float) $valor('quantidade_comercial', 'quantidade_comercial', 0),
        'valor_unitario' => (float) $valor('valor_unitario_comercial', 'valor_unitario_comercial', 0),
        'valor_bruto' => (float) $valor('valor_bruto', 'valor_bruto', 0),
        'unidade' => (string) $valor('unidade_comercial', 'unidade_comercial', 'KG'),
        'icms_situacao_tributaria' => (string) $valor('icms_situacao_tributaria', 'icms_situacao_tributaria', '40'),
        'pis_situacao_tributaria' => (string) $valor('pis_situacao_tributaria', 'pis_situacao_tributaria', '06'),
        'cofins_situacao_tributaria' => (string) $valor('cofins_situacao_tributaria', 'cofins_situacao_tributaria', '06'),
        'pis_base_calculo' => (float) $valor('pis_base_calculo', 'pis_base_calculo', 0),
        'pis_aliquota_porcentual' => (float) $valor('pis_aliquota_porcentual', 'pis_aliquota_porcentual', 0.65),
        'pis_valor' => (float) $valor('pis_valor', 'pis_valor', 0),
        'cofins_base_calculo' => (float) $valor('cofins_base_calculo', 'cofins_base_calculo', 0),
        'cofins_aliquota_porcentual' => (float) $valor('cofins_aliquota_porcentual', 'cofins_aliquota_porcentual', 3.00),
        'cofins_valor' => (float) $valor('cofins_valor', 'cofins_valor', 0),
        'pis_tributo_editado' => (string) ($revisao['pis_tributo_editado'] ?? '0'),
        'cofins_tributo_editado' => (string) ($revisao['cofins_tributo_editado'] ?? '0'),
        'codigo_beneficio_fiscal' => (string) $valor('codigo_beneficio_fiscal', 'codigo_beneficio_fiscal', 'SP010360'),
        'peso_kg' => (float) ($item['peso_kg'] ?? 0),
        'peso_unitario_kg' => (float) ($item['peso_unitario_kg'] ?? 0),
        'excluir' => (string) ($revisao['excluir'] ?? '0'),
    ];
}

function nfeVendaBuscarRascunho(mysqli $conexao, string $ambiente, int $vendaId, string $numeroOs): ?array
{
    $stmt = $conexao->prepare("
        SELECT id, ref, opcoes_json, revisao_json, updated_at
        FROM nfe_documentos
        WHERE ambiente = ?
          AND tipo_emissao = 'venda'
          AND status = 'rascunho'
          AND ref LIKE '%-rascunho'
          AND ((? <> '' AND numero_os = ?) OR (? > 0 AND venda_id = ?))
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->bind_param('sssii', $ambiente, $numeroOs, $numeroOs, $vendaId, $vendaId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function nfeVendaOpcoesRascunho(?array $rascunho): array
{
    if (!$rascunho) {
        return [];
    }
    $opcoes = json_decode((string) ($rascunho['opcoes_json'] ?? ''), true);
    if (!is_array($opcoes)) {
        $opcoes = [];
    }
    if (!isset($opcoes['revisao']) || !is_array($opcoes['revisao'])) {
        $revisao = json_decode((string) ($rascunho['revisao_json'] ?? ''), true);
        if (is_array($revisao)) {
            $opcoes['revisao'] = $revisao;
        }
    }
    return $opcoes;
}

function nfeVendaRespostaRascunho(array $payload, array $opcoes, array $rows, ?array $rascunho = null): array
{
    $opcoes = nfeVendaSanearOpcoesRascunho($payload, $opcoes, true);
    $revisao = is_array($opcoes['revisao'] ?? null) ? $opcoes['revisao'] : [];
    $destRevisao = is_array($revisao['destinatario'] ?? null) ? $revisao['destinatario'] : [];
    $itemsRevisao = is_array($revisao['items'] ?? null) ? $revisao['items'] : [];

    $destinatario = [
        'nome' => nfeVendaCampo($payload, 'nome_destinatario'),
        'cpf' => nfeVendaCampo($payload, 'cpf_destinatario'),
        'cnpj' => nfeVendaCampo($payload, 'cnpj_destinatario'),
        'ie' => nfeVendaCampo($payload, 'inscricao_estadual_destinatario'),
        'indicador_ie' => nfeVendaCampo($payload, 'indicador_inscricao_estadual_destinatario'),
        'logradouro' => nfeVendaCampo($payload, 'logradouro_destinatario'),
        'numero' => nfeVendaCampo($payload, 'numero_destinatario'),
        'complemento' => nfeVendaCampo($payload, 'complemento_destinatario'),
        'bairro' => nfeVendaCampo($payload, 'bairro_destinatario'),
        'municipio' => nfeVendaCampo($payload, 'municipio_destinatario'),
        'uf' => nfeVendaCampo($payload, 'uf_destinatario'),
        'cep' => nfeVendaCampo($payload, 'cep_destinatario'),
        'telefone' => nfeVendaCampo($payload, 'telefone_destinatario'),
        'email' => nfeVendaCampo($payload, 'email_destinatario'),
    ];
    foreach ($destinatario as $campo => $valor) {
        if (array_key_exists($campo, $destRevisao)) {
            $destinatario[$campo] = (string) $destRevisao[$campo];
        }
    }

    $items = [];
    foreach (array_values($payload['items'] ?? []) as $idx => $item) {
        if (!is_array($item)) {
            continue;
        }
        $items[] = nfeVendaItemModal($item, is_array($itemsRevisao[$idx] ?? null) ? $itemsRevisao[$idx] : null);
    }
    $pesoLiquidoCalculado = focusNfeCalcularPesoLiquidoItens(array_map(static function (array $item): array {
        return [
            'quantidade_comercial' => $item['quantidade'] ?? 0,
            'peso_unitario_kg' => $item['peso_unitario_kg'] ?? 0,
            'unidade_comercial' => $item['unidade'] ?? 'KG',
        ];
    }, $items));
    $pesoCaixas = (float) ($opcoes['peso_caixas'] ?? 0);
    $pesoLiquido = isset($revisao['peso_liquido']) && $revisao['peso_liquido'] !== ''
        ? max(0.0, focusNfeFloat($revisao['peso_liquido']))
        : $pesoLiquidoCalculado;
    $pesoBruto = isset($revisao['peso_bruto']) && $revisao['peso_bruto'] !== ''
        ? max(0.0, focusNfeFloat($revisao['peso_bruto']))
        : $pesoLiquido + $pesoCaixas;

    $primeira = $rows[0] ?? [];
    return [
        'ok' => true,
        'rascunho_carregado' => $rascunho !== null,
        'rascunho_ref' => (string) ($rascunho['ref'] ?? ''),
        'rascunho_updated_at' => (string) ($rascunho['updated_at'] ?? ''),
        'numero_os' => (string) ($primeira['numero_os'] ?? ''),
        'venda_id' => (int) ($primeira['id'] ?? 0),
        'os_label' => (string) (($primeira['numero_os'] ?? '') ?: ('Venda #' . (int) ($primeira['id'] ?? 0))),
        'tipo_operacao_fiscal' => in_array(($opcoes['tipo_operacao_fiscal'] ?? 'venda'), ['venda', 'bonificacao'], true) ? (string) ($opcoes['tipo_operacao_fiscal'] ?? 'venda') : 'venda',
        'local_destino' => (int) ($opcoes['local_destino'] ?? $payload['local_destino'] ?? 1),
        'destinatario' => $destinatario,
        'items' => $items,
        'peso_liquido' => $pesoLiquido,
        'peso_bruto' => $pesoBruto,
        'tipo_caixa' => (string) ($opcoes['tipo_caixa'] ?? ''),
        'quantidade_caixas' => (int) ($opcoes['quantidade_caixas'] ?? 0),
        'informacoes_adicionais_contribuinte' => (string) ($opcoes['informacoes_adicionais_contribuinte'] ?? $payload['informacoes_adicionais_contribuinte'] ?? ''),
    ];
}

try {
    $acao = strtolower(trim((string) ($_POST['_acao'] ?? $_GET['_acao'] ?? 'carregar')));
    $vendaId = isset($_POST['venda_id']) && $_POST['venda_id'] !== '' ? (int) $_POST['venda_id'] : (isset($_GET['venda_id']) ? (int) $_GET['venda_id'] : null);
    $numeroOs = trim((string) ($_POST['numero_os'] ?? $_GET['numero_os'] ?? ''));
    $ambiente = (string) ($_POST['ambiente'] ?? $_GET['ambiente'] ?? 'producao');

    $config = focusNfeLoadConfig($conexao, $ambiente);
    [$payload, $rows] = focusNfeMontarPayloadVenda($conexao, $config, $vendaId, $numeroOs !== '' ? $numeroOs : null);
    $primeira = $rows[0] ?? [];
    $vendaIdReal = (int) ($primeira['id'] ?? 0);
    $numeroOsReal = trim((string) ($primeira['numero_os'] ?? $numeroOs));

    if ($acao === 'salvar') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new RuntimeException('Metodo invalido para salvar rascunho.');
        }
        validarTokenCsrf();
        $opcoes = focusNfeOpcoesVendaFromPost($_POST);
        $opcoes = nfeVendaSanearOpcoesRascunho($payload, $opcoes, false);
        $payloadSaneado = focusNfeAplicarRevisaoPayloadVenda($payload, $opcoes);
        $ref = focusNfeCriarRef($vendaIdReal, $numeroOsReal) . '-rascunho';
        $documentoId = focusNfeDocumentoOperacao($conexao, $ref, [
            'tipo_emissao' => 'venda',
            'origem_tipo' => 'venda',
            'origem_id' => $vendaIdReal,
            'venda_id' => $vendaIdReal,
            'numero_os' => $numeroOsReal,
            'cliente_id' => (int) ($primeira['cliente_id'] ?? 0),
        ], $config, $payloadSaneado, $opcoes);

        $rascunhoSalvo = nfeVendaBuscarRascunho($conexao, (string) $config['ambiente'], $vendaIdReal, $numeroOsReal);
        if (!$rascunhoSalvo || (int) ($rascunhoSalvo['id'] ?? 0) !== $documentoId) {
            throw new RuntimeException('O rascunho foi gravado, mas nao foi possivel confirma-lo no banco de dados.');
        }
        $resposta = nfeVendaRespostaRascunho($payloadSaneado, nfeVendaOpcoesRascunho($rascunhoSalvo), $rows, $rascunhoSalvo);
        $resposta += [
            'documento_id' => $documentoId,
            'ref' => $ref,
            'mensagem' => 'Rascunho da NF-e salvo.',
        ];
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $rascunho = nfeVendaBuscarRascunho($conexao, (string) $config['ambiente'], $vendaIdReal, $numeroOsReal);
    $opcoes = nfeVendaOpcoesRascunho($rascunho);

    echo json_encode(nfeVendaRespostaRascunho($payload, $opcoes, $rows, $rascunho), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

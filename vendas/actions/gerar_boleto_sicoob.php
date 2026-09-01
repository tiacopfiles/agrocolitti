<?php
require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/permissions.php';
require __DIR__ . '/../../boletos/sicoob_boleto_service.php';

// Mesma permissao da emissao de NF-e: quem emite a nota gera o boleto.
requireModule('exportar_nfe', '../historico_vendas.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../historico_vendas.php');
    exit;
}

validarTokenCsrf();

$numeroOs = isset($_POST['numero_os']) && trim((string) $_POST['numero_os']) !== '' ? trim((string) $_POST['numero_os']) : null;
$vendaId  = isset($_POST['venda_id']) && $_POST['venda_id'] !== '' ? (int) $_POST['venda_id'] : null;
$vencimentoPost = isset($_POST['data_vencimento']) && trim((string) $_POST['data_vencimento']) !== '' ? trim((string) $_POST['data_vencimento']) : null;

if ($numeroOs === null) {
    header('Location: ../historico_vendas.php?msg=erro&detalhe=' . urlencode('OS nao informada.'));
    exit;
}

function boletoValorDecimalPost($valor): ?float
{
    $raw = trim((string) $valor);
    if ($raw === '') { return null; }
    if (strpos($raw, ',') !== false) {
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
    }
    if (!is_numeric($raw)) { return null; }
    return round((float) $raw, 2);
}

try {
    // Prepara os dados (mesma logica do rascunho): NF-e, pagador, valor, vencimento, juros/multa, mensagens.
    $dados = sicoobBoletoPrepararDados($conexao, $numeroOs, $vendaId, $vencimentoPost);

    // Impede boleto duplicado para a mesma OS.
    if (!empty($dados['ja_existe'])) {
        header('Location: ../historico_vendas.php?msg=boleto_existente&os=' . urlencode($numeroOs));
        exit;
    }

    // Overrides vindos da tela de rascunho (sem mexer no .env).
    $override = [];
    if (isset($_POST['numero_cliente']) && trim((string) $_POST['numero_cliente']) !== '') {
        $override['numero_cliente'] = preg_replace('/\D/', '', (string) $_POST['numero_cliente']);
    }
    if (isset($_POST['numero_conta']) && trim((string) $_POST['numero_conta']) !== '') {
        $override['numero_conta'] = trim((string) $_POST['numero_conta']);
    }
    if (isset($_POST['juros_tipo']) && $_POST['juros_tipo'] !== '') {
        $override['juros_tipo'] = (int) $_POST['juros_tipo'];
    }
    if (isset($_POST['juros_valor']) && trim((string) $_POST['juros_valor']) !== '') {
        $override['juros_valor'] = (float) str_replace(',', '.', (string) $_POST['juros_valor']);
    }
    if (isset($_POST['multa_tipo']) && $_POST['multa_tipo'] !== '') {
        $override['multa_tipo'] = (int) $_POST['multa_tipo'];
    }
    if (isset($_POST['multa_valor']) && trim((string) $_POST['multa_valor']) !== '') {
        $override['multa_valor'] = (float) str_replace(',', '.', (string) $_POST['multa_valor']);
    }
    if (isset($_POST['nosso_numero']) && trim((string) $_POST['nosso_numero']) !== '') {
        $override['nosso_numero'] = preg_replace('/\D/', '', (string) $_POST['nosso_numero']);
    }
    if (isset($_POST['codigo_especie']) && trim((string) $_POST['codigo_especie']) !== '') {
        $override['codigo_especie'] = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $_POST['codigo_especie']), 0, 2));
    }

    // Campos do documento/pagador editados no rascunho (sobrescrevem os calculados).
    if (isset($_POST['valor']) && trim((string) $_POST['valor']) !== '') {
        $valorPost = boletoValorDecimalPost($_POST['valor']);
        if ($valorPost === null) {
            throw new RuntimeException('Valor do boleto invalido.');
        }
        if ($valorPost > 0) { $dados['valor'] = round($valorPost, 2); }
    }
    if ((float) ($dados['valor'] ?? 0) <= 0) {
        throw new RuntimeException('Valor do boleto deve ser maior que zero.');
    }

    $valorAbatimentoRaw = trim((string) ($_POST['valor_abatimento'] ?? ''));
    $porcentagemAbatimentoRaw = trim((string) ($_POST['porcentagem_abatimento'] ?? ''));
    $valorAbatimento = boletoValorDecimalPost($valorAbatimentoRaw);
    $porcentagemAbatimento = boletoValorDecimalPost($porcentagemAbatimentoRaw);
    if ($valorAbatimentoRaw !== '' && $valorAbatimento === null) {
        throw new RuntimeException('Valor abatimento invalido.');
    }
    if ($porcentagemAbatimentoRaw !== '' && $porcentagemAbatimento === null) {
        throw new RuntimeException('Porcentagem abatimento invalida.');
    }
    if ($valorAbatimento !== null && $valorAbatimento < 0) {
        throw new RuntimeException('Valor abatimento nao pode ser menor que zero.');
    }
    if ($porcentagemAbatimento !== null && $porcentagemAbatimento < 0) {
        throw new RuntimeException('Porcentagem abatimento nao pode ser menor que zero.');
    }
    if ($porcentagemAbatimento !== null && $porcentagemAbatimento > 100) {
        throw new RuntimeException('Porcentagem abatimento nao pode ser maior que 100%.');
    }
    if (($valorAbatimento === null || $valorAbatimento <= 0) && $porcentagemAbatimento !== null && $porcentagemAbatimento > 0) {
        $valorAbatimento = round(((float) $dados['valor']) * ($porcentagemAbatimento / 100), 2);
    }
    if ($valorAbatimento !== null && $valorAbatimento > (float) $dados['valor']) {
        throw new RuntimeException('Valor abatimento nao pode ser maior que o valor do boleto.');
    }
    $dados['valor_abatimento'] = ($valorAbatimento !== null && $valorAbatimento > 0)
        ? round($valorAbatimento, 2)
        : 0.0;
    if (isset($_POST['data_emissao']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_POST['data_emissao'])) {
        $dados['data_emissao'] = (string) $_POST['data_emissao'];
    }
    if (isset($_POST['seu_numero']) && trim((string) $_POST['seu_numero']) !== '') {
        $dados['seu_numero'] = trim((string) $_POST['seu_numero']);
    }
    // Pagador editavel
    $camposPag = ['pag_nome' => 'nome', 'pag_cpf_cnpj' => 'numeroCpfCnpj', 'pag_endereco' => 'endereco', 'pag_bairro' => 'bairro', 'pag_cidade' => 'cidade', 'pag_uf' => 'uf', 'pag_cep' => 'cep'];
    foreach ($camposPag as $campoPost => $chave) {
        if (isset($_POST[$campoPost])) {
            $val = trim((string) $_POST[$campoPost]);
            if ($chave === 'numeroCpfCnpj') { $val = sicoobFormatarCpfCnpj($val); }
            elseif ($chave === 'cep') { $val = preg_replace('/[^0-9]/', '', $val); }
            if ($chave === 'uf') { $val = strtoupper(substr($val, 0, 2)); }
            $dados['pagador'][$chave] = $val;
        }
    }

    // Mensagens de instrucao: usa as editadas no rascunho se vierem; senao recalcula
    // com os valores finais (config + overrides) e o vencimento p/ a data das instrucoes.
    $configFinal = array_merge($dados['config'], $override);
    $mensagens = [];
    if (isset($_POST['mensagens']) && is_array($_POST['mensagens'])) {
        foreach ($_POST['mensagens'] as $linha) {
            $linha = trim((string) $linha);
            if ($linha !== '') { $mensagens[] = $linha; }
        }
    }
    if (empty($mensagens)) {
        $mensagens = sicoobBoletoMontarMensagens($configFinal, (string) $dados['numero_nfe'], (string) $dados['data_vencimento']);
    }
    // O abatimento possui campo proprio no payload do Sicoob. Quando informado,
    // remove somente as linhas automaticas de desconto para nao duplicar nem
    // contradizer o valor impresso em "(-) Desconto / Abatimento".
    if ($dados['valor_abatimento'] > 0) {
        $mensagens = array_values(array_filter($mensagens, static function ($linha) {
            $linha = trim((string) $linha);
            return !preg_match('/^(?:n[aã]o\s+conceder\s+desconto|desconto\s+de\s+r\$)/iu', $linha);
        }));
    }

    // Inclui o boleto via SICOOB.
    $resultado = sicoobBoletoIncluir($conexao, [
        'numero_os'        => $dados['numero_os'],
        'venda_id'         => $dados['venda_id'],
        'nfe_documento_id' => $dados['nfe_documento_id'],
        'seu_numero'       => $dados['seu_numero'],
        'valor'            => $dados['valor'],
        'valor_abatimento' => $dados['valor_abatimento'] ?? 0,
        'data_emissao'     => $dados['data_emissao'],
        'data_vencimento'  => $dados['data_vencimento'],
        'pagador'          => $dados['pagador'],
        'mensagens'        => $mensagens,
    ], null, $override);

    if (!empty($resultado['sucesso'])) {
        // baixar=1 -> a tela de resultado dispara o download do PDF automaticamente.
        header('Location: ../../boletos/ver_boleto.php?id=' . (int) $resultado['boleto_id'] . '&baixar=1');
        exit;
    }

    $detalhe = sicoobBoletoMensagemErro([
        'json'      => $resultado['response'],
        'error'     => $resultado['error'],
        'http_code' => $resultado['http_code'],
    ]);
    header('Location: ../../boletos/rascunho_boleto.php?numero_os=' . urlencode($numeroOs) . '&msg=erro&detalhe=' . urlencode('Boleto: ' . $detalhe));
    exit;

} catch (Throwable $e) {
    header('Location: ../../boletos/rascunho_boleto.php?numero_os=' . urlencode((string) $numeroOs) . '&msg=erro&detalhe=' . urlencode($e->getMessage()));
    exit;
}

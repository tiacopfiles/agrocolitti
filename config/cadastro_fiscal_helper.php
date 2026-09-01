<?php

function cadastroFiscalDigitos($valor): string
{
    return preg_replace('/\D+/', '', (string) $valor) ?? '';
}

function cadastroFiscalTexto(array $dados, string $campo): string
{
    return trim((string) ($dados[$campo] ?? ''));
}

function cadastroFiscalIndicadorNormalizado(?string $valor): string
{
    $texto = strtolower(trim((string) $valor));
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) ?: $texto;
    if (str_contains($ascii, 'isento')) {
        return 'Contribuinte Isento';
    }
    if (str_contains($ascii, 'contribuinte') && !str_contains($ascii, 'nao')) {
        return 'Contribuinte do ICMS';
    }
    return 'Nao Contribuinte';
}

function cadastroFiscalPessoa(array $post): array
{
    $nome = cadastroFiscalTexto($post, 'nome');
    $telefone = cadastroFiscalTexto($post, 'telefone');
    $razaoSocial = cadastroFiscalTexto($post, 'nfe_nome_razao_social');
    $tipoDocumento = cadastroFiscalTexto($post, 'tipo_documento');
    $documento = cadastroFiscalDigitos($post['documento_fiscal'] ?? '');
    $indicadorIe = cadastroFiscalTexto($post, 'nfe_indicador_ie_destinatario');
    $ie = cadastroFiscalTexto($post, 'nfe_ie');
    $logradouro = cadastroFiscalTexto($post, 'nfe_endereco');
    $numero = cadastroFiscalTexto($post, 'nfe_numero');
    $complemento = cadastroFiscalTexto($post, 'nfe_complemento');
    $bairro = cadastroFiscalTexto($post, 'nfe_bairro');
    $cidade = cadastroFiscalTexto($post, 'nfe_cidade');
    $estado = strtoupper(cadastroFiscalTexto($post, 'nfe_estado'));
    $cep = cadastroFiscalDigitos($post['nfe_cep'] ?? '');

    if ($nome === '' || $razaoSocial === '') {
        throw new InvalidArgumentException('Informe nome e razao social.');
    }
    if ($tipoDocumento === 'cpf' && strlen($documento) !== 11) {
        throw new InvalidArgumentException('Informe um CPF valido com 11 digitos.');
    }
    if ($tipoDocumento === 'cnpj' && strlen($documento) !== 14) {
        throw new InvalidArgumentException('Informe um CNPJ valido com 14 digitos.');
    }
    if (!in_array($tipoDocumento, ['cpf', 'cnpj'], true)) {
        throw new InvalidArgumentException('Selecione CPF ou CNPJ.');
    }
    $indicadores = ['Contribuinte do ICMS', 'Contribuinte Isento', 'Nao Contribuinte'];
    if (!in_array($indicadorIe, $indicadores, true)) {
        throw new InvalidArgumentException('Selecione o indicador de inscricao estadual.');
    }
    if ($indicadorIe === 'Contribuinte do ICMS' && cadastroFiscalDigitos($ie) === '') {
        throw new InvalidArgumentException('A inscricao estadual e obrigatoria para contribuinte do ICMS.');
    }
    if ($logradouro === '' || $numero === '' || $bairro === '' || $cidade === '' || strlen($estado) !== 2 || strlen($cep) !== 8) {
        throw new InvalidArgumentException('Preencha logradouro, numero, bairro, cidade, UF e CEP fiscal.');
    }

    $cpf = $tipoDocumento === 'cpf' ? $documento : '';
    $cnpj = $tipoDocumento === 'cnpj' ? $documento : '';
    $enderecoLegado = implode(', ', array_filter([$logradouro, $numero, $complemento, $bairro, $cidade, $estado, $cep], static fn($valor) => $valor !== ''));

    return [
        'nome' => $nome,
        'documento' => $documento,
        'telefone' => $telefone,
        'endereco' => $enderecoLegado,
        'nfe_nome_razao_social' => $razaoSocial,
        'nfe_cpf' => $cpf,
        'nfe_cnpj' => $cnpj,
        'nfe_ie' => $ie,
        'nfe_indicador_ie_destinatario' => $indicadorIe,
        'nfe_telefone' => $telefone,
        'nfe_endereco' => $logradouro,
        'nfe_numero' => $numero,
        'nfe_complemento' => $complemento,
        'nfe_bairro' => $bairro,
        'nfe_cidade' => $cidade,
        'nfe_estado' => $estado,
        'nfe_cep' => $cep,
    ];
}

function cadastroFiscalProduto(array $post): array
{
    $nome = cadastroFiscalTexto($post, 'nome');
    $codigo = cadastroFiscalTexto($post, 'codigo_interno');
    if ($codigo === '') {
        $codigo = cadastroFiscalTexto($post, 'nfe_codigo_interno');
    }
    $unidade = strtoupper(cadastroFiscalTexto($post, 'unidade'));
    $descricao = cadastroFiscalTexto($post, 'nfe_descricao');
    $ncm = cadastroFiscalDigitos($post['nfe_ncm'] ?? '');
    $tipo = cadastroFiscalTexto($post, 'nfe_tipo');
    $cstPis = cadastroFiscalDigitos($post['nfe_cst_pis'] ?? '06');
    $cstCofins = cadastroFiscalDigitos($post['nfe_cst_cofins'] ?? '06');

    if ($nome === '' || $descricao === '') {
        throw new InvalidArgumentException('Informe nome e descricao fiscal do produto.');
    }
    if ($codigo === '') {
        throw new InvalidArgumentException('Informe o codigo do produto.');
    }
    if (!in_array($unidade, ['KG', 'UN', 'CX'], true)) {
        throw new InvalidArgumentException('Selecione a unidade comercial.');
    }
    if (strlen($ncm) !== 8) {
        throw new InvalidArgumentException('Informe um NCM valido com 8 digitos.');
    }
    if (!in_array($tipo, ['Mercadoria para Revenda', 'Producao propria'], true)) {
        throw new InvalidArgumentException('Selecione a classificacao fiscal do produto.');
    }
    if (strlen($cstPis) !== 2 || strlen($cstCofins) !== 2) {
        throw new InvalidArgumentException('Informe CST PIS e CST COFINS com 2 digitos.');
    }

    return [
        'nome' => $nome,
        'codigo_interno' => $codigo,
        'nfe_codigo_interno' => $codigo,
        'unidade' => $unidade,
        'ncm' => $ncm,
        'nfe_ncm' => $ncm,
        'nfe_descricao' => $descricao,
        'nfe_tipo' => $tipo,
        'nfe_unidade' => $unidade,
        'nfe_origem' => 'NACIONAL',
        'nfe_cst_pis' => $cstPis,
        'nfe_cst_cofins' => $cstCofins,
    ];
}

function cadastroFiscalPessoaCompleta(array $dados): bool
{
    $doc = cadastroFiscalDigitos(($dados['nfe_cpf'] ?? '') ?: ($dados['nfe_cnpj'] ?? ''));
    $indicadorInformado = trim((string) ($dados['nfe_indicador_ie_destinatario'] ?? '')) !== '';
    $indicador = cadastroFiscalIndicadorNormalizado($dados['nfe_indicador_ie_destinatario'] ?? '');
    $ieOk = $indicador !== 'Contribuinte do ICMS' || cadastroFiscalDigitos($dados['nfe_ie'] ?? '') !== '';

    return in_array(strlen($doc), [11, 14], true)
        && trim((string) ($dados['nfe_nome_razao_social'] ?? '')) !== ''
        && $indicadorInformado
        && $ieOk
        && trim((string) ($dados['nfe_endereco'] ?? '')) !== ''
        && trim((string) ($dados['nfe_numero'] ?? '')) !== ''
        && trim((string) ($dados['nfe_bairro'] ?? '')) !== ''
        && trim((string) ($dados['nfe_cidade'] ?? '')) !== ''
        && strlen(trim((string) ($dados['nfe_estado'] ?? ''))) === 2
        && strlen(cadastroFiscalDigitos($dados['nfe_cep'] ?? '')) === 8;
}

function cadastroFiscalProdutoCompleto(array $produto): bool
{
    $ncm = cadastroFiscalDigitos(($produto['ncm'] ?? '') ?: ($produto['nfe_ncm'] ?? ''));
    $tipo = trim((string) ($produto['nfe_tipo'] ?? ''));
    $unidade = strtoupper(trim((string) (($produto['unidade'] ?? '') ?: ($produto['nfe_unidade'] ?? ''))));
    $codigo = trim((string) (($produto['codigo_interno'] ?? '') ?: ($produto['nfe_codigo_interno'] ?? '')));

    return strlen($ncm) === 8
        && $codigo !== ''
        && trim((string) ($produto['nfe_descricao'] ?? '')) !== ''
        && in_array($tipo, ['Mercadoria para Revenda', 'Producao propria'], true)
        && in_array($unidade, ['KG', 'UN', 'CX'], true);
}

function cadastroFiscalExigirPessoaSelecionada(mysqli $conexao, string $tabela, int $id, string $rotulo): void
{
    if (!in_array($tabela, ['clientes', 'fornecedores'], true) || $id <= 0) {
        throw new RuntimeException($rotulo . ' invalido.');
    }

    $stmt = $conexao->prepare("SELECT * FROM {$tabela} WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $pessoa = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$pessoa) {
        throw new RuntimeException($rotulo . ' nao encontrado.');
    }
    if (!cadastroFiscalPessoaCompleta($pessoa)) {
        throw new RuntimeException($rotulo . ' sem informacoes fiscais completas para emitir NF-e. Atualize o cadastro antes de continuar.');
    }
}

function cadastroFiscalExigirProdutoSelecionado(mysqli $conexao, int $id): void
{
    if ($id <= 0) {
        throw new RuntimeException('Produto invalido.');
    }

    $stmt = $conexao->prepare('SELECT * FROM produtos WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $produto = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$produto) {
        throw new RuntimeException('Produto nao encontrado.');
    }
    if (!cadastroFiscalProdutoCompleto($produto)) {
        throw new RuntimeException('Produto sem informacoes fiscais completas para emitir NF-e. Atualize o cadastro antes de continuar.');
    }
}

function cadastroFiscalExigirVenda(mysqli $conexao, int $clienteId, int $produtoId): void
{
    cadastroFiscalExigirPessoaSelecionada($conexao, 'clientes', $clienteId, 'Cliente');
    cadastroFiscalExigirProdutoSelecionado($conexao, $produtoId);
}

function cadastroFiscalExigirCompra(mysqli $conexao, int $fornecedorId, int $produtoId): void
{
    cadastroFiscalExigirPessoaSelecionada($conexao, 'fornecedores', $fornecedorId, 'Fornecedor');
    cadastroFiscalExigirProdutoSelecionado($conexao, $produtoId);
}

function cadastroFiscalExigirOsVenda(mysqli $conexao, string $numeroOs, string $status = 'anexado'): void
{
    $stmt = $conexao->prepare('SELECT DISTINCT cliente_id, produto_id FROM vendas WHERE numero_os = ? AND status = ?');
    $stmt->bind_param('ss', $numeroOs, $status);
    $stmt->execute();
    $itens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($itens as $item) {
        cadastroFiscalExigirVenda($conexao, (int) $item['cliente_id'], (int) $item['produto_id']);
    }
}

function cadastroFiscalExigirOsCompra(mysqli $conexao, string $numeroOs, string $status): void
{
    $stmt = $conexao->prepare('SELECT DISTINCT fornecedor_id, produto_id FROM previsao_fornecedor WHERE numero_os = ? AND status = ?');
    $stmt->bind_param('ss', $numeroOs, $status);
    $stmt->execute();
    $itens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($itens as $item) {
        cadastroFiscalExigirCompra($conexao, (int) $item['fornecedor_id'], (int) $item['produto_id']);
    }
}

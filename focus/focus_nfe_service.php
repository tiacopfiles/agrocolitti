<?php

require_once __DIR__ . '/../vendas/venda_helper.php';

function focusNfeSomenteDigitos(?string $valor): string
{
    return preg_replace('/\D/', '', (string) $valor);
}

function focusNfeCodigoBeneficioFiscalValido($valor): ?string
{
    $codigo = strtoupper(trim((string) $valor));
    if ($codigo === '' || in_array($codigo, ['SEM CBENEF', 'SEM C BENEF', 'SEM BENEFICIO', 'SEM BENEFÍCIO', 'NAO', 'NÃO', 'N/A', '-'], true)) {
        return null;
    }
    return $codigo;
}

function focusNfeCbenefAutomaticoSp(array $payload, array $item): ?string
{
    // SP010360 passa a ser o cBenef padrao para entradas e saidas.
    // Codigos especificos validos, como bonificacao (SP053190), sao preservados.
    return 'SP010360';
}

function focusNfeSanitizarCodigoBeneficioFiscal(array $payload): array
{
    if (!isset($payload['items']) || !is_array($payload['items'])) {
        return $payload;
    }
    foreach ($payload['items'] as &$item) {
        if (!is_array($item)) {
            continue;
        }
        if (!array_key_exists('codigo_beneficio_fiscal', $item)) {
            $codigo = focusNfeCbenefAutomaticoSp($payload, $item);
            if ($codigo !== null) {
                $item['codigo_beneficio_fiscal'] = $codigo;
            }
            continue;
        }
        $codigo = focusNfeCodigoBeneficioFiscalValido($item['codigo_beneficio_fiscal']);
        if ($codigo === null) {
            $codigo = focusNfeCbenefAutomaticoSp($payload, $item);
            if ($codigo === null) {
                unset($item['codigo_beneficio_fiscal']);
            } else {
                $item['codigo_beneficio_fiscal'] = $codigo;
            }
        } else {
            $item['codigo_beneficio_fiscal'] = $codigo;
        }
    }
    unset($item);
    return $payload;
}

function focusNfeNcmProduto(array $row): string
{
    $ncmPrincipal = focusNfeSomenteDigitos((string) ($row['produto_ncm'] ?? ''));
    $ncmFiscal = focusNfeSomenteDigitos((string) ($row['produto_nfe_ncm'] ?? ''));
    if (strlen($ncmPrincipal) === 8) {
        return $ncmPrincipal;
    }
    if (strlen($ncmFiscal) === 8) {
        return $ncmFiscal;
    }
    return $ncmPrincipal !== '' ? $ncmPrincipal : $ncmFiscal;
}

function focusNfeValidarVinculoProduto(array $row, string $contexto): void
{
    if (trim((string) ($row['produto_nome'] ?? '')) !== '') {
        return;
    }
    $produtoId = (int) ($row['produto_id'] ?? 0);
    $origemId = (int) ($row['id'] ?? 0);
    $numeroOs = trim((string) ($row['numero_os'] ?? ''));
    throw new RuntimeException(
        'Vinculo de produto invalido na ' . $contexto
        . ($numeroOs !== '' ? ' OS ' . $numeroOs : '')
        . ': o registro ' . $origemId
        . ' aponta para produto_id ' . $produtoId
        . ', que nao existe mais no cadastro. Vincule o item a um produto ativo antes de emitir a NF-e.'
    );
}

function focusNfeNormalizarTexto(string $valor): string
{
    $valor = mb_strtolower(trim($valor), 'UTF-8');
            $mapa = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i',
            'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ò' => 'o', 'ó' => 'o',
            'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ù' => 'u', 'ú' => 'u',
            'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n'
        ];
    return preg_replace('/\s+/', ' ', strtr($valor, $mapa));
}

function focusNfeLoadConfig(mysqli $conexao, ?string $ambiente = null): array
{
    $ambiente = $ambiente ?: 'producao';
    $stmt = $conexao->prepare('SELECT * FROM focus_config WHERE ambiente = ? AND ativo = 1 LIMIT 1');
    $stmt->bind_param('s', $ambiente);
    $stmt->execute();
    $config = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$config) {
        throw new RuntimeException('Configuracao da Focus NFe nao encontrada para o ambiente ' . $ambiente . '.');
    }

    $tokenCampo = $ambiente === 'producao' ? 'token_producao' : 'token_homologacao';
    $config['token_ativo'] = trim((string) ($config[$tokenCampo] ?? ''));
    if ($config['token_ativo'] === '') {
        throw new RuntimeException('Token da Focus NFe nao configurado para o ambiente ' . $ambiente . '.');
    }

    return $config;
}

function focusNfeParseEnderecoLegado(string $txt): array
{
    $cep = '';
    $uf = '';
    $cidade = '';
    $bairro = '';
    $logradouro = '';
    $numero = 'S/N';

    if (preg_match('/\b(\d{5})[.\-]?(\d{3})\b/', $txt, $m)) {
        $cep = $m[1] . $m[2];
    }
    if (preg_match('/[-,]\s*([A-Z]{2})\s*[,\s]*(CEP|$)/i', $txt, $m)) {
        $uf = strtoupper($m[1]);
    }
    if ($uf && preg_match('/([^,]+)-' . preg_quote($uf, '/') . '/i', $txt, $m)) {
        $cidade = trim($m[1]);
    }

    $partes = array_map('trim', explode(',', $txt));
    if (isset($partes[0])) {
        $logradouro = $partes[0];
    }
    if (isset($partes[1]) && preg_match('/^\d+/', $partes[1], $m)) {
        $numero = $m[0];
        $bairro = $partes[2] ?? '';
    } elseif (isset($partes[1])) {
        $bairro = $partes[1];
    }

    return [
        'logradouro' => mb_substr($logradouro, 0, 60),
        'numero' => mb_substr($numero, 0, 10),
        'bairro' => mb_substr($bairro, 0, 60),
        'municipio' => mb_substr($cidade, 0, 60),
        'uf' => $uf ?: 'SP',
        'cep' => $cep,
    ];
}

function focusNfeIndicadorIe(?string $valor, ?string $ie): int
{
    $texto = focusNfeNormalizarTexto((string) $valor);
    if (str_contains($texto, 'isento')) {
        return 2;
    }
    if (str_contains($texto, 'contribuinte') && !str_contains($texto, 'nao')) {
        return 1;
    }
    return focusNfeSomenteDigitos($ie) !== '' ? 1 : 9;
}

function focusNfeSincronizarTransportadorDestinatario(array $payload): array
{
    foreach ([
        'cpf_transportador',
        'cnpj_transportador',
        'nome_transportador',
        'inscricao_estadual_transportador',
        'endereco_transportador',
        'municipio_transportador',
        'uf_transportador',
    ] as $campoTransportador) {
        unset($payload[$campoTransportador]);
    }

    $nome = trim((string) ($payload['nome_destinatario'] ?? ''));
    if ($nome !== '') {
        $payload['nome_transportador'] = mb_substr($nome, 0, 60, 'UTF-8');
    }

    $cpf = focusNfeSomenteDigitos($payload['cpf_destinatario'] ?? '');
    $cnpj = focusNfeSomenteDigitos($payload['cnpj_destinatario'] ?? '');
    if (strlen($cpf) === 11) {
        $payload['cpf_transportador'] = $cpf;
    } elseif (strlen($cnpj) === 14) {
        $payload['cnpj_transportador'] = $cnpj;
        $ie = focusNfeSomenteDigitos($payload['inscricao_estadual_destinatario'] ?? '');
        if ($ie !== '') {
            $payload['inscricao_estadual_transportador'] = $ie;
        }
    }

    $partesEndereco = array_filter([
        trim((string) ($payload['logradouro_destinatario'] ?? '')),
        trim((string) ($payload['numero_destinatario'] ?? '')),
        trim((string) ($payload['complemento_destinatario'] ?? '')),
        trim((string) ($payload['bairro_destinatario'] ?? '')),
    ], static fn($valor) => $valor !== '');
    $endereco = trim(implode(', ', $partesEndereco));
    if ($endereco !== '') {
        $payload['endereco_transportador'] = mb_substr($endereco, 0, 60, 'UTF-8');
    }

    $municipio = trim((string) ($payload['municipio_destinatario'] ?? ''));
    if ($municipio !== '') {
        $payload['municipio_transportador'] = mb_substr($municipio, 0, 60, 'UTF-8');
    }

    $uf = strtoupper(trim((string) ($payload['uf_destinatario'] ?? '')));
    if (strlen($uf) === 2) {
        $payload['uf_transportador'] = $uf;
    }

    return $payload;
}

function focusNfeUnidade(string $valor): string
{
    $texto = focusNfeNormalizarTexto($valor);
    if ($texto === '' || str_contains($texto, 'quilo') || $texto === 'kg') {
        return 'KG';
    }
    if (str_contains($texto, 'unidade') || $texto === 'un') {
        return 'UN';
    }
    if (str_contains($texto, 'caixa') || $texto === 'cx') {
        return 'CX';
    }
    return mb_substr(strtoupper(trim($valor)), 0, 6) ?: 'KG';
}

function focusNfeTipoOrigemMercadoria(array $produto): string
{
    $tipo = focusNfeNormalizarTexto((string) ($produto['produto_nfe_tipo'] ?? ''));
    if (str_contains($tipo, 'revenda') || str_contains($tipo, 'mercadoria')) {
        return 'revenda';
    }
    if (str_contains($tipo, 'producao') || str_contains($tipo, 'propria') || str_contains($tipo, 'fabricacao')) {
        return 'producao_propria';
    }

    throw new RuntimeException('Produto sem classificacao fiscal de origem: ' . ($produto['produto_nome'] ?? ''));
}

function focusNfeOperacaoFiscal(mysqli $conexao, string $tipoOrigem, string $ufDestino): array
{
    $regraUf = strtoupper($ufDestino) === 'SP' ? 'SP' : 'OUTRA_UF';
    $stmt = $conexao->prepare(
        'SELECT * FROM operacoes_fiscais WHERE tipo_origem_mercadoria = ? AND destino_uf_regra = ? AND ativo = 1 LIMIT 1'
    );
    $stmt->bind_param('ss', $tipoOrigem, $regraUf);
    $stmt->execute();
    $regra = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$regra) {
        throw new RuntimeException('Regra fiscal nao encontrada para ' . $tipoOrigem . ' / ' . $regraUf . '.');
    }

    return $regra;
}

function focusNfeBuscarVendas(mysqli $conexao, ?int $vendaId, ?string $numeroOs): array
{
    $where = ["v.status = 'concluido'"];
    $params = [];
    $types = '';

    if ($numeroOs !== null && trim($numeroOs) !== '') {
        $where[] = 'v.numero_os = ?';
        $params[] = trim($numeroOs);
        $types .= 's';
    } elseif ($vendaId !== null && $vendaId > 0) {
        $where[] = 'v.id = ?';
        $params[] = $vendaId;
        $types .= 'i';
    } else {
        throw new RuntimeException('Informe venda_id ou numero_os.');
    }

    $sql = "SELECT v.*,
                   p.nome AS produto_nome,
                   p.codigo_barras AS produto_codigo_barras,
                   p.codigo_interno AS produto_codigo_interno,
                   p.nfe_codigo_interno AS produto_nfe_codigo_interno,
                   p.unidade AS produto_unidade,
                   p.ncm AS produto_ncm,
                   p.nfe_ncm AS produto_nfe_ncm,
                   p.nfe_descricao AS produto_nfe_descricao,
                   p.nfe_tipo AS produto_nfe_tipo,
                   p.nfe_origem AS produto_nfe_origem,
                   p.nfe_cst_pis AS produto_nfe_cst_pis,
                   p.nfe_cst_cofins AS produto_nfe_cst_cofins,
                   c.nome AS cliente_nome,
                   c.documento AS cliente_documento,
                   c.telefone AS cliente_telefone,
                   c.endereco AS cliente_endereco,
                   c.nfe_nome_razao_social,
                   c.nfe_cpf,
                   c.nfe_cnpj,
                   c.nfe_ie,
                   c.nfe_indicador_ie_destinatario,
                   c.nfe_telefone,
                   c.nfe_email,
                   c.nfe_endereco,
                   c.nfe_numero,
                   c.nfe_complemento,
                   c.nfe_bairro,
                   c.nfe_cidade,
                   c.nfe_estado,
                   c.nfe_cep,
                   po.qtd_por_caixa AS oba_qtd_por_caixa,
                   po.bandejas_por_caixa AS oba_bandejas_por_caixa
            FROM vendas v
            LEFT JOIN produtos p ON p.id = v.produto_id
            LEFT JOIN clientes c ON c.id = v.cliente_id
            LEFT JOIN preco_oba_embalado po ON po.produto_id = v.produto_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY v.id ASC";

    $stmt = $conexao->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$rows) {
        throw new RuntimeException('Venda concluida nao encontrada.');
    }

    return $rows;
}

function focusNfeMontarPayloadVenda(mysqli $conexao, array $config, ?int $vendaId, ?string $numeroOs): array
{
    $rows = focusNfeBuscarVendas($conexao, $vendaId, $numeroOs);
    $primeira = $rows[0];
    $enderecoLegado = focusNfeParseEnderecoLegado((string) ($primeira['cliente_endereco'] ?? ''));

    $cpf = focusNfeSomenteDigitos($primeira['nfe_cpf'] ?: $primeira['cliente_documento'] ?? '');
    $cnpj = focusNfeSomenteDigitos($primeira['nfe_cnpj'] ?: $primeira['cliente_documento'] ?? '');
    if (strlen($cpf) !== 11) {
        $cpf = '';
    }
    if (strlen($cnpj) !== 14) {
        $cnpj = '';
    }
    if ($cpf === '' && $cnpj === '') {
        throw new RuntimeException('Cliente sem CPF/CNPJ valido para NF-e.');
    }

    $ufDestino = strtoupper(trim((string) ($primeira['nfe_estado'] ?: $enderecoLegado['uf'])));
    $cepDestino = focusNfeSomenteDigitos($primeira['nfe_cep'] ?: $enderecoLegado['cep']);
    $municipioDestino = trim((string) ($primeira['nfe_cidade'] ?: $enderecoLegado['municipio']));
    $nomeClienteNfe = (string) ($primeira['nfe_nome_razao_social'] ?: $primeira['cliente_nome'] ?: 'NAO INFORMADO');
    $logradouroClienteNfe = (string) ($primeira['nfe_endereco'] ?: $enderecoLegado['logradouro'] ?: 'NAO INFORMADO');
    $numeroClienteNfe = (string) ($primeira['nfe_numero'] ?: $enderecoLegado['numero'] ?: 'S/N');

    $payload = [
        'natureza_operacao' => (string) $config['natureza_operacao_padrao'],
        'data_emissao' => date('c'),
        'tipo_documento' => 1,
        'finalidade_emissao' => 1,
        'local_destino' => $ufDestino === 'SP' ? 1 : 2,
        'consumidor_final' => 0,
        'presenca_comprador' => 2,
        'cnpj_emitente' => focusNfeSomenteDigitos($config['cnpj_emitente'] ?? ''),
        'nome_emitente' => (string) ($config['nome_emitente'] ?? ''),
        'inscricao_estadual_emitente' => focusNfeSomenteDigitos($config['inscricao_estadual_emitente'] ?? ''),
        'regime_tributario_emitente' => $config['regime_tributario_emitente'] !== null ? (int) $config['regime_tributario_emitente'] : null,
        'nome_destinatario' => $nomeClienteNfe,
        'logradouro_destinatario' => $logradouroClienteNfe,
        'numero_destinatario' => $numeroClienteNfe,
        'complemento_destinatario' => (string) ($primeira['nfe_complemento'] ?? ''),
        'bairro_destinatario' => (string) ($primeira['nfe_bairro'] ?: $enderecoLegado['bairro'] ?: 'NAO INFORMADO'),
        'municipio_destinatario' => $municipioDestino,
        'uf_destinatario' => $ufDestino,
        'cep_destinatario' => $cepDestino,
        'telefone_destinatario' => focusNfeSomenteDigitos($primeira['nfe_telefone'] ?: $primeira['cliente_telefone'] ?? ''),
        'email_destinatario' => (string) ($primeira['nfe_email'] ?? ''),
        'inscricao_estadual_destinatario' => focusNfeSomenteDigitos($primeira['nfe_ie'] ?? ''),
        'indicador_inscricao_estadual_destinatario' => focusNfeIndicadorIe($primeira['nfe_indicador_ie_destinatario'] ?? '', $primeira['nfe_ie'] ?? ''),
        'modalidade_frete' => 1,
        'items' => [],
    ];

    if ($cpf !== '') {
        $payload['cpf_destinatario'] = $cpf;
    } else {
        $payload['cnpj_destinatario'] = $cnpj;
    }

    $payload = focusNfeSincronizarTransportadorDestinatario($payload);

    $erros = [];
    foreach (['cnpj_emitente', 'nome_emitente', 'inscricao_estadual_emitente', 'municipio_destinatario', 'uf_destinatario', 'cep_destinatario'] as $campo) {
        if (empty($payload[$campo])) {
            $erros[] = $campo;
        }
    }
    if ($payload['regime_tributario_emitente'] === null) {
        $erros[] = 'regime_tributario_emitente';
    }
    if ($erros) {
        throw new RuntimeException('Configuracao/dados incompletos para NF-e: ' . implode(', ', $erros) . '.');
    }

    $numeroItem = 1;
    foreach ($rows as $row) {
        focusNfeValidarVinculoProduto($row, 'venda');
        $ncm = focusNfeNcmProduto($row);
        if (strlen($ncm) !== 8) {
            throw new RuntimeException(
                'Produto "' . (string) $row['produto_nome']
                . '" (produto_id ' . (int) $row['produto_id']
                . ') sem NCM valido com 8 digitos.'
            );
        }

        $tipoOrigem = focusNfeTipoOrigemMercadoria($row);
        $operacao = focusNfeOperacaoFiscal($conexao, $tipoOrigem, $ufDestino);
        $pesoKgOriginal = max(0.0, calcularQuantidadeFinalVenda($row));
        $quantidade = $pesoKgOriginal;
        // Valor total: usa o valor final editado ou o total conforme o tipo (bandeja/caixa = preco x pedido; kg = preco x quantidade).
        $valorTotal = $row['valor_final_editado'] !== null
            ? (float) $row['valor_final_editado']
            : calcularPrecoTotalVenda($row);
        $tipoComercialRow = strtolower(trim((string) ($row['tipo_comercial'] ?? '')));
        $gramagemRow = (float) ($row['gramagem'] ?? 0);
        $tipoRow = strtolower(trim((string) ($row['tipo'] ?? '')));
        $ehOba = ($tipoComercialRow === 'oba_embalado');
        // Trata como embalado tambem vendas em bandeja com gramagem mesmo sem tipo_comercial preenchido.
        $ehEmbalado = ($tipoComercialRow === 'embalado') || (!$ehOba && $tipoRow === 'bandeja' && $gramagemRow > 0);
        $nfeDescRow = (string) ($row['produto_nfe_descricao'] ?: '');
        $nomeProduto = (string) ($row['produto_nome'] ?: 'PRODUTO');
        if ($ehOba) {
            // OBA: unidade CX, quantidade = numero de caixas, descricao "<nome> CX C/<bandejas por caixa>".
            $unidade = 'CX';
            $numCaixasRow = (int) ($row['num_caixas'] ?? 0);
            $bandejasPorCaixaRow = (int) ($row['bandejas_por_caixa'] ?? 0);
            $pedidoBandejas = (float) ($row['pedido'] ?? 0);
            $opcoesOba = obaOpcoesBandejas((string) ($row['oba_bandejas_por_caixa'] ?? ''));
            if ($numCaixasRow <= 0 && $bandejasPorCaixaRow <= 0 && $pedidoBandejas > 0) {
                foreach ($opcoesOba as $opcaoBandejas) {
                    $calculado = $pedidoBandejas / $opcaoBandejas;
                    if ($calculado > 0 && abs($calculado - round($calculado)) < 0.0001) {
                        $bandejasPorCaixaRow = (int) $opcaoBandejas;
                        $numCaixasRow = (int) round($calculado);
                        break;
                    }
                }
            }
            if ($bandejasPorCaixaRow <= 0) {
                $bandejasPorCaixaRow = (int) ($opcoesOba[0] ?? 0);
            }
            if ($bandejasPorCaixaRow <= 0) {
                $bandejasPorCaixaRow = (int) round((float) ($row['oba_qtd_por_caixa'] ?? 0));
            }
            if ($numCaixasRow <= 0 && $bandejasPorCaixaRow > 0 && $pedidoBandejas > 0) {
                $numCaixasCalculado = $pedidoBandejas / $bandejasPorCaixaRow;
                if ($numCaixasCalculado >= 1 && abs($numCaixasCalculado - round($numCaixasCalculado)) < 0.0001) {
                    $numCaixasRow = (int) round($numCaixasCalculado);
                } elseif (abs($pedidoBandejas - round($pedidoBandejas)) < 0.0001) {
                    // Compatibilidade com vendas OBA antigas em que pedido foi salvo como numero de caixas.
                    $numCaixasRow = (int) round($pedidoBandejas);
                }
            }
            if ($numCaixasRow <= 0) {
                throw new RuntimeException('OBA embalado sem numero de caixas (item ' . $nomeProduto . '): preencha o numero de caixas antes de emitir a NF-e.');
            }
            $quantidade = (float) $numCaixasRow;
            $descricaoNfe = obaDescricaoNfe($nfeDescRow !== '' ? $nfeDescRow : $nomeProduto, $bandejasPorCaixaRow);
        } elseif ($ehEmbalado) {
            $unidade = 'BD';
            if ($gramagemRow > 0) {
                // converte a quantidade de KG para numero de bandejas (gramagem em gramas), mantendo o valor total da linha.
                $quantidade = $quantidade * 1000 / $gramagemRow;
                $descricaoNfe = descricaoNfeProdutoSelecionado($nfeDescRow, $nomeProduto, $gramagemRow);
            } else {
                $descricaoNfe = descricaoNfeProdutoSelecionado($nfeDescRow, $nomeProduto, 0);
            }
        } elseif (in_array($tipoRow, ['caixa', 'unidade'], true)) {
            // Venda em caixa/unidade: conserva integralmente a descricao fiscal,
            // inclusive sufixos legitimos como "CX C/10" ou pesos da embalagem.
            $unidade = focusNfeUnidade((string) ($row['produto_unidade'] ?? 'CX'));
            $descricaoNfe = trim($nfeDescRow !== '' ? $nfeDescRow : $nomeProduto);
        } elseif (in_array($tipoComercialRow, ['atacado', 'atacado_convencional'], true)) {
            $unidade = 'KG';
            $descricaoNfe = formatarGramagemEmbalado($nfeDescRow !== '' ? $nfeDescRow : $nomeProduto, 0);
        } else {
            $unidade = focusNfeUnidade((string) ($row['produto_unidade'] ?? 'KG'));
            $descricaoNfe = formatarGramagemEmbalado($nfeDescRow !== '' ? $nfeDescRow : $nomeProduto, 0);
        }
        $valorUnitario = $quantidade > 0 ? $valorTotal / $quantidade : 0.0;
        $pesoUnitarioKg = $quantidade > 0 ? $pesoKgOriginal / $quantidade : 0.0;
        $codigoBarras = trim((string) ($row['produto_codigo_interno'] ?: $row['produto_nfe_codigo_interno'] ?: $row['produto_codigo_barras']));
        $codigoProduto = $codigoBarras !== '' ? $codigoBarras : (string) $row['produto_id'];
        $cstPis = trim((string) ($row['produto_nfe_cst_pis'] ?? '')) ?: (string) $operacao['pis_cst'];
        $cstCofins = trim((string) ($row['produto_nfe_cst_cofins'] ?? '')) ?: (string) $operacao['cofins_cst'];

        $payload['items'][] = [
            'numero_item' => (string) $numeroItem++,
            'produto_id_origem' => (string) ($row['produto_id'] ?? ''),
            'produto_nome_origem' => (string) ($row['produto_nome'] ?? ''),
            'peso_kg_original' => number_format($pesoKgOriginal, 4, '.', ''),
            'peso_unitario_kg' => number_format($pesoUnitarioKg, 6, '.', ''),
            'codigo_produto_esperado' => $codigoProduto !== '' ? $codigoProduto : (string) $row['produto_id'],
            'descricao_esperada' => $descricaoNfe,
            'codigo_produto' => $codigoProduto !== '' ? $codigoProduto : (string) $row['produto_id'],
            'codigo_barras_comercial' => $codigoBarras,
            'codigo_barras_tributavel' => $codigoBarras,
            'descricao' => $descricaoNfe,
            'cfop' => (string) $operacao['cfop'],
            'unidade_comercial' => $unidade,
            'quantidade_comercial' => number_format($quantidade, 4, '.', ''),
            'valor_unitario_comercial' => number_format($valorUnitario, 4, '.', ''),
            'unidade_tributavel' => $unidade,
            'quantidade_tributavel' => number_format($quantidade, 4, '.', ''),
            'valor_unitario_tributavel' => number_format($valorUnitario, 4, '.', ''),
            'codigo_ncm' => $ncm,
            'valor_bruto' => number_format($valorTotal, 2, '.', ''),
            'icms_situacao_tributaria' => (string) $operacao['icms_cst'],
            'icms_origem' => (string) $operacao['icms_origem'],
            'pis_situacao_tributaria' => $cstPis,
            'cofins_situacao_tributaria' => $cstCofins,
        ];
        $itemIdx = count($payload['items']) - 1;
        $basePisCofins = round($valorTotal, 2);
        if ($cstPis === '01') {
            $payload['items'][$itemIdx]['pis_base_calculo'] = number_format($basePisCofins, 2, '.', '');
            $payload['items'][$itemIdx]['pis_aliquota_porcentual'] = '0.6500';
            $payload['items'][$itemIdx]['pis_valor'] = number_format(round($basePisCofins * 0.0065, 2), 2, '.', '');
        }
        if ($cstCofins === '01') {
            $payload['items'][$itemIdx]['cofins_base_calculo'] = number_format($basePisCofins, 2, '.', '');
            $payload['items'][$itemIdx]['cofins_aliquota_porcentual'] = '3.0000';
            $payload['items'][$itemIdx]['cofins_valor'] = number_format(round($basePisCofins * 0.03, 2), 2, '.', '');
        }
        $codigoBeneficio = focusNfeCodigoBeneficioFiscalValido($operacao['codigo_beneficio_fiscal'] ?? '') ?? 'SP010360';
        $payload['items'][count($payload['items']) - 1]['codigo_beneficio_fiscal'] = $codigoBeneficio;
    }

    return [$payload, $rows];
}

function focusNfeCriarRef(?int $vendaId, ?string $numeroOs): string
{
    if ($numeroOs !== null && trim($numeroOs) !== '') {
        return 'VENDA-OS-' . preg_replace('/[^A-Za-z0-9_-]/', '-', trim($numeroOs));
    }
    return 'VENDA-' . (int) $vendaId;
}

function focusNfeDocumento(mysqli $conexao, string $ref, array $rows, array $config, array $payload): int
{
    $payload = focusNfeSanitizarCodigoBeneficioFiscal($payload);
    $primeira = $rows[0];
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $conexao->prepare(
        "INSERT INTO nfe_documentos
         (ref, venda_id, numero_os, cliente_id, ambiente, status, payload_json)
         VALUES (?, ?, ?, ?, ?, 'rascunho', ?)
         ON DUPLICATE KEY UPDATE
           venda_id = VALUES(venda_id),
           numero_os = VALUES(numero_os),
           cliente_id = VALUES(cliente_id),
           payload_json = VALUES(payload_json),
           updated_at = CURRENT_TIMESTAMP"
    );
    $vendaId = (int) ($primeira['id'] ?? 0);
    $numeroOs = trim((string) ($primeira['numero_os'] ?? ''));
    $clienteId = (int) ($primeira['cliente_id'] ?? 0);
    $ambiente = (string) $config['ambiente'];
    $stmt->bind_param('sisiss', $ref, $vendaId, $numeroOs, $clienteId, $ambiente, $payloadJson);
    $stmt->execute();
    $stmt->close();

    $stmt = $conexao->prepare('SELECT id FROM nfe_documentos WHERE ref = ? AND ambiente = ? LIMIT 1');
    $stmt->bind_param('ss', $ref, $ambiente);
    $stmt->execute();
    $id = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    $stmt->close();

    return $id;
}

function focusNfeRegistrarTentativa(mysqli $conexao, ?int $documentoId, string $ref, string $tipo, string $metodo, string $endpoint, ?int $httpCode, ?array $payload, ?string $resposta, ?string $erro): void
{
    $payloadJson = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $stmt = $conexao->prepare(
        'INSERT INTO nfe_tentativas
         (nfe_documento_id, ref, tipo, metodo_http, endpoint, http_code, payload_resumido, resposta_json, erro)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issssisss', $documentoId, $ref, $tipo, $metodo, $endpoint, $httpCode, $payloadJson, $resposta, $erro);
    $stmt->execute();
    $stmt->close();
}

function focusNfeHttp(string $baseUrl, string $token, string $metodo, string $path, ?array $payload = null): array
{
    $url = rtrim($baseUrl, '/') . $path;
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $token . ':',
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CUSTOMREQUEST => $metodo,
    ];
    if ($payload !== null) {
        $payload = focusNfeSanitizarCodigoBeneficioFiscal($payload);
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'raw' => $body === false ? '' : (string) $body,
        'json' => $body !== false ? json_decode((string) $body, true) : null,
        'error' => $body === false ? $error : '',
        'url' => $url,
    ];
}

function focusNfeStatusLocal(int $httpCode, ?array $json): string
{
    $statusFocus = focusNfeNormalizarTexto((string) ($json['status'] ?? ''));
    if (str_contains($statusFocus, 'erro') || str_contains($statusFocus, 'reje')) {
        return 'rejeitada';
    }
    if (str_contains($statusFocus, 'autoriz')) {
        return 'autorizada';
    }
    if ($httpCode === 202 || str_contains($statusFocus, 'process')) {
        return 'processando';
    }
    if (str_contains($statusFocus, 'cancel')) {
        return 'cancelada';
    }
    if ($httpCode >= 400) {
        return 'rejeitada';
    }
    return 'enviada';
}

function focusNfeAtualizarDocumentoResposta(mysqli $conexao, int $documentoId, int $httpCode, ?array $json, string $raw): void
{
    $status = focusNfeStatusLocal($httpCode, $json);
    $chave = (string) ($json['chave_nfe'] ?? $json['chave'] ?? '');
    $numero = (string) ($json['numero'] ?? $json['numero_nfe'] ?? '');
    $serie = (string) ($json['serie'] ?? '');
    $protocolo = (string) ($json['protocolo'] ?? $json['protocolo_autorizacao'] ?? '');
    $xml = (string) ($json['caminho_xml_nota_fiscal'] ?? $json['url_xml'] ?? '');
    $danfe = (string) ($json['caminho_danfe'] ?? $json['url_danfe'] ?? '');
    $mensagem = (string) ($json['mensagem_sefaz'] ?? $json['mensagem'] ?? $json['erro'] ?? '');
    $agora = date('Y-m-d H:i:s');
    $autorizadaEm = $status === 'autorizada' ? $agora : null;
    $respostaJson = $raw;

    $stmt = $conexao->prepare(
        'UPDATE nfe_documentos
         SET status = ?, chave_nfe = NULLIF(?, \'\'), numero_nfe = NULLIF(?, \'\'), serie = NULLIF(?, \'\'),
             protocolo = NULLIF(?, \'\'), caminho_xml = NULLIF(?, \'\'), caminho_danfe = NULLIF(?, \'\'),
             mensagem_sefaz = NULLIF(?, \'\'), resposta_json = ?, emitida_em = COALESCE(emitida_em, ?),
             autorizada_em = COALESCE(autorizada_em, ?), updated_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    );
    $stmt->bind_param('sssssssssssi', $status, $chave, $numero, $serie, $protocolo, $xml, $danfe, $mensagem, $respostaJson, $agora, $autorizadaEm, $documentoId);
    $stmt->execute();
    $stmt->close();
}

function focusNfeGarantirColunasCancelamento(mysqli $conexao): void
{
    $colunas = [
        'caminho_xml_cancelamento' => "ALTER TABLE nfe_documentos ADD COLUMN caminho_xml_cancelamento VARCHAR(500) NULL DEFAULT NULL AFTER caminho_xml",
        'protocolo_cancelamento' => "ALTER TABLE nfe_documentos ADD COLUMN protocolo_cancelamento VARCHAR(80) NULL DEFAULT NULL AFTER protocolo",
        'justificativa_cancelamento' => "ALTER TABLE nfe_documentos ADD COLUMN justificativa_cancelamento VARCHAR(255) NULL DEFAULT NULL AFTER mensagem_sefaz",
        'cancelada_em' => "ALTER TABLE nfe_documentos ADD COLUMN cancelada_em DATETIME NULL DEFAULT NULL AFTER autorizada_em",
    ];
    foreach ($colunas as $coluna => $sql) {
        $res = $conexao->query("SHOW COLUMNS FROM nfe_documentos LIKE '" . $conexao->real_escape_string($coluna) . "'");
        $existe = $res && $res->num_rows > 0;
        if ($res instanceof mysqli_result) $res->free();
        if (!$existe) {
            $conexao->query($sql);
        }
    }
}

function focusNfeAtualizarDocumentoCancelamento(mysqli $conexao, int $documentoId, int $httpCode, ?array $json, string $raw, string $justificativa): void
{
    focusNfeGarantirColunasCancelamento($conexao);

    $statusFocus = focusNfeNormalizarTexto((string) ($json['status'] ?? ''));
    $status = str_contains($statusFocus, 'cancel') ? 'cancelada' : 'erro_cancelamento';
    if ($httpCode >= 400 && $status !== 'cancelada') {
        $status = 'erro_cancelamento';
    }

    $mensagem = (string) ($json['mensagem_sefaz'] ?? $json['mensagem'] ?? $json['erro'] ?? '');
    if ($mensagem === '' && is_array($json['erros'] ?? null)) {
        $mensagem = implode('; ', array_map(static fn($erro) => is_array($erro) ? (string) ($erro['mensagem'] ?? json_encode($erro, JSON_UNESCAPED_UNICODE)) : (string) $erro, $json['erros']));
    }
    $xmlCancelamento = (string) ($json['caminho_xml_cancelamento'] ?? $json['caminho_xml'] ?? $json['url_xml_cancelamento'] ?? '');
    $protocoloCancelamento = (string) ($json['numero_protocolo'] ?? $json['protocolo'] ?? $json['protocolo_cancelamento'] ?? '');
    $canceladaEm = $status === 'cancelada' ? date('Y-m-d H:i:s') : null;

    $stmt = $conexao->prepare(
        'UPDATE nfe_documentos
         SET status = ?, mensagem_sefaz = NULLIF(?, \'\'), resposta_json = ?, caminho_xml_cancelamento = NULLIF(?, \'\'),
             protocolo_cancelamento = NULLIF(?, \'\'), justificativa_cancelamento = ?, cancelada_em = COALESCE(cancelada_em, ?),
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    );
    $stmt->bind_param('sssssssi', $status, $mensagem, $raw, $xmlCancelamento, $protocoloCancelamento, $justificativa, $canceladaEm, $documentoId);
    $stmt->execute();
    $stmt->close();
}

function focusNfeCancelarDocumento(mysqli $conexao, int $documentoId, string $justificativa, string $ambiente = 'producao'): array
{
    $justificativa = trim($justificativa);
    $tamanho = mb_strlen($justificativa);
    if ($tamanho < 15 || $tamanho > 255) {
        throw new RuntimeException('A justificativa do cancelamento deve ter entre 15 e 255 caracteres.');
    }

    $config = focusNfeLoadConfig($conexao, $ambiente);
    $stmt = $conexao->prepare("
        SELECT id, ref, status, ambiente
        FROM nfe_documentos
        WHERE id = ? AND ambiente = ?
        LIMIT 1
    ");
    $stmt->bind_param('is', $documentoId, $ambiente);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doc) {
        throw new RuntimeException('NF-e nao encontrada para cancelamento.');
    }
    if ((string) ($doc['status'] ?? '') !== 'autorizada') {
        throw new RuntimeException('Apenas NF-e autorizada pode ser cancelada.');
    }

    $ref = (string) $doc['ref'];
    $path = '/v2/nfe/' . rawurlencode($ref);
    $payload = ['justificativa' => $justificativa];
    $res = focusNfeHttp((string) $config['base_url'], (string) $config['token_ativo'], 'DELETE', $path, $payload);
    focusNfeRegistrarTentativa($conexao, $documentoId, $ref, 'cancelar', 'DELETE', $path, $res['http_code'], $payload, $res['raw'], $res['error']);
    focusNfeAtualizarDocumentoCancelamento($conexao, $documentoId, $res['http_code'], is_array($res['json']) ? $res['json'] : null, $res['raw'], $justificativa);

    return [
        'documento_id' => $documentoId,
        'ref' => $ref,
        'http_code' => $res['http_code'],
        'response' => $res['json'],
        'raw' => $res['raw'],
        'error' => $res['error'],
    ];
}

function focusNfeGarantirColunasCartaCorrecao(mysqli $conexao): void
{
    focusNfeGarantirColunasCancelamento($conexao);

    $colunas = [
        'caminho_xml_carta_correcao' => "ALTER TABLE nfe_documentos ADD COLUMN caminho_xml_carta_correcao VARCHAR(500) NULL DEFAULT NULL AFTER caminho_xml_cancelamento",
        'protocolo_carta_correcao' => "ALTER TABLE nfe_documentos ADD COLUMN protocolo_carta_correcao VARCHAR(80) NULL DEFAULT NULL AFTER protocolo_cancelamento",
        'ultima_carta_correcao' => "ALTER TABLE nfe_documentos ADD COLUMN ultima_carta_correcao TEXT NULL DEFAULT NULL AFTER justificativa_cancelamento",
        'carta_correcao_em' => "ALTER TABLE nfe_documentos ADD COLUMN carta_correcao_em DATETIME NULL DEFAULT NULL AFTER cancelada_em",
    ];
    foreach ($colunas as $coluna => $sql) {
        $res = $conexao->query("SHOW COLUMNS FROM nfe_documentos LIKE '" . $conexao->real_escape_string($coluna) . "'");
        $existe = $res && $res->num_rows > 0;
        if ($res instanceof mysqli_result) $res->free();
        if (!$existe) {
            $conexao->query($sql);
        }
    }
}

function focusNfeAtualizarDocumentoCartaCorrecao(mysqli $conexao, int $documentoId, int $httpCode, ?array $json, string $raw, string $correcao): void
{
    focusNfeGarantirColunasCartaCorrecao($conexao);

    $mensagem = (string) ($json['mensagem_sefaz'] ?? $json['mensagem'] ?? $json['erro'] ?? '');
    if ($mensagem === '' && is_array($json['erros'] ?? null)) {
        $mensagem = implode('; ', array_map(static fn($erro) => is_array($erro) ? (string) ($erro['mensagem'] ?? json_encode($erro, JSON_UNESCAPED_UNICODE)) : (string) $erro, $json['erros']));
    }

    $xmlCarta = (string) ($json['caminho_xml_carta_correcao'] ?? $json['caminho_xml'] ?? $json['url_xml_carta_correcao'] ?? '');
    $protocoloCarta = (string) ($json['numero_protocolo'] ?? $json['protocolo'] ?? $json['protocolo_carta_correcao'] ?? '');
    $cartaEm = $httpCode < 400 ? date('Y-m-d H:i:s') : null;

    $stmt = $conexao->prepare(
        'UPDATE nfe_documentos
         SET mensagem_sefaz = NULLIF(?, \'\'),
             caminho_xml_carta_correcao = NULLIF(?, \'\'), protocolo_carta_correcao = NULLIF(?, \'\'),
             ultima_carta_correcao = ?, carta_correcao_em = COALESCE(carta_correcao_em, ?),
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    );
    $stmt->bind_param('sssssi', $mensagem, $xmlCarta, $protocoloCarta, $correcao, $cartaEm, $documentoId);
    $stmt->execute();
    $stmt->close();
}

function focusNfeEmitirCartaCorrecaoDocumento(mysqli $conexao, int $documentoId, string $correcao, string $ambiente = 'producao'): array
{
    $correcao = trim($correcao);
    $tamanho = mb_strlen($correcao);
    if ($tamanho < 15 || $tamanho > 1000) {
        throw new RuntimeException('A carta de correcao deve ter entre 15 e 1000 caracteres.');
    }

    $config = focusNfeLoadConfig($conexao, $ambiente);
    $stmt = $conexao->prepare("
        SELECT id, ref, status, ambiente
        FROM nfe_documentos
        WHERE id = ? AND ambiente = ?
        LIMIT 1
    ");
    $stmt->bind_param('is', $documentoId, $ambiente);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doc) {
        throw new RuntimeException('NF-e nao encontrada para carta de correcao.');
    }
    if ((string) ($doc['status'] ?? '') !== 'autorizada') {
        throw new RuntimeException('Apenas NF-e autorizada pode receber carta de correcao.');
    }

    $ref = (string) $doc['ref'];
    $path = '/v2/nfe/' . rawurlencode($ref) . '/carta_correcao';
    $payload = ['correcao' => $correcao];
    $res = focusNfeHttp((string) $config['base_url'], (string) $config['token_ativo'], 'POST', $path, $payload);
    focusNfeRegistrarTentativa($conexao, $documentoId, $ref, 'carta_correcao', 'POST', $path, $res['http_code'], $payload, $res['raw'], $res['error']);
    focusNfeAtualizarDocumentoCartaCorrecao($conexao, $documentoId, $res['http_code'], is_array($res['json']) ? $res['json'] : null, $res['raw'], $correcao);

    return [
        'documento_id' => $documentoId,
        'ref' => $ref,
        'http_code' => $res['http_code'],
        'response' => $res['json'],
        'raw' => $res['raw'],
        'error' => $res['error'],
    ];
}

function focusNfeEmitirVenda(mysqli $conexao, ?int $vendaId, ?string $numeroOs, string $ambiente = 'producao'): array
{
    $config = focusNfeLoadConfig($conexao, $ambiente);
    [$payload, $rows] = focusNfeMontarPayloadVenda($conexao, $config, $vendaId, $numeroOs);
    $ref = focusNfeCriarRef($vendaId, $numeroOs);
    $documentoId = focusNfeDocumento($conexao, $ref, $rows, $config, $payload);
    $path = '/v2/nfe?ref=' . rawurlencode($ref);
    $res = focusNfeHttp((string) $config['base_url'], (string) $config['token_ativo'], 'POST', $path, $payload);
    focusNfeRegistrarTentativa($conexao, $documentoId, $ref, 'emitir', 'POST', $path, $res['http_code'], $payload, $res['raw'], $res['error']);
    focusNfeAtualizarDocumentoResposta($conexao, $documentoId, $res['http_code'], is_array($res['json']) ? $res['json'] : null, $res['raw']);

    return [
        'documento_id' => $documentoId,
        'ref' => $ref,
        'http_code' => $res['http_code'],
        'response' => $res['json'],
        'raw' => $res['raw'],
        'error' => $res['error'],
    ];
}

function focusNfeConsultarRef(mysqli $conexao, string $ref, string $ambiente = 'producao'): array
{
    $config = focusNfeLoadConfig($conexao, $ambiente);
    $documentoId = null;
    $stmt = $conexao->prepare('SELECT id FROM nfe_documentos WHERE ref = ? AND ambiente = ? LIMIT 1');
    $stmt->bind_param('ss', $ref, $ambiente);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $documentoId = (int) $row['id'];
    }

    $path = '/v2/nfe/' . rawurlencode($ref) . '?completa=1';
    $res = focusNfeHttp((string) $config['base_url'], (string) $config['token_ativo'], 'GET', $path);
    focusNfeRegistrarTentativa($conexao, $documentoId, $ref, 'consultar', 'GET', $path, $res['http_code'], null, $res['raw'], $res['error']);
    if ($documentoId !== null) {
        focusNfeAtualizarDocumentoResposta($conexao, $documentoId, $res['http_code'], is_array($res['json']) ? $res['json'] : null, $res['raw']);
    }

    return [
        'documento_id' => $documentoId,
        'ref' => $ref,
        'http_code' => $res['http_code'],
        'response' => $res['json'],
        'raw' => $res['raw'],
        'error' => $res['error'],
    ];
}

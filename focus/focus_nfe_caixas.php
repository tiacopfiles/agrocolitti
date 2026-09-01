<?php

require_once __DIR__ . '/focus_nfe_operacoes.php';
require_once __DIR__ . '/../config/cadastro_fiscal_helper.php';

function caixaNotasGarantirTabelas(mysqli $conexao): void
{
    $conexao->query("
        CREATE TABLE IF NOT EXISTS nfe_caixa_tipos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(50) NOT NULL UNIQUE,
            nome VARCHAR(120) NOT NULL,
            ncm VARCHAR(20) NOT NULL DEFAULT '39231090',
            unidade VARCHAR(10) NOT NULL DEFAULT 'UN',
            peso_kg DECIMAL(12,3) NOT NULL DEFAULT 0,
            valor_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conexao->query("
        CREATE TABLE IF NOT EXISTS nfe_caixa_notas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            numero_os VARCHAR(80) NOT NULL UNIQUE,
            cliente_id INT NOT NULL,
            data_nota DATE NOT NULL,
            observacoes TEXT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'rascunho',
            quantidade_total INT NOT NULL DEFAULT 0,
            peso_liquido DECIMAL(12,3) NOT NULL DEFAULT 0,
            peso_bruto DECIMAL(12,3) NOT NULL DEFAULT 0,
            valor_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_nfe_caixa_cliente_id (cliente_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conexao->query("
        CREATE TABLE IF NOT EXISTS nfe_caixa_itens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nota_id INT NOT NULL,
            tipo_id INT NOT NULL,
            quantidade INT NOT NULL DEFAULT 0,
            valor_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
            peso_unitario_kg DECIMAL(12,3) NOT NULL DEFAULT 0,
            valor_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            peso_total_kg DECIMAL(12,3) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_nfe_caixa_itens_nota_id (nota_id),
            CONSTRAINT fk_nfe_caixa_itens_nota FOREIGN KEY (nota_id) REFERENCES nfe_caixa_notas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $defaults = [
        ['plastica_m', 'CAIXA PLASTICA MODELO M', '39231090', 'UN', 2.000, 18.33],
        ['plastica_p', 'CAIXA PLASTICA MODELO P', '39231090', 'UN', 1.350, 21.25],
        ['papelao', 'CAIXA DE PAPELAO', '48191000', 'UN', 0.750, 0.01],
    ];
    $stmt = $conexao->prepare("
        INSERT INTO nfe_caixa_tipos (slug, nome, ncm, unidade, peso_kg, valor_unitario)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE nome = VALUES(nome), ncm = VALUES(ncm), unidade = VALUES(unidade)
    ");
    foreach ($defaults as $d) {
        $stmt->bind_param('ssssdd', $d[0], $d[1], $d[2], $d[3], $d[4], $d[5]);
        $stmt->execute();
    }
    $stmt->close();
}

function caixaNotasTipos(mysqli $conexao): array
{
    caixaNotasGarantirTabelas($conexao);
    $res = $conexao->query("SELECT * FROM nfe_caixa_tipos WHERE ativo = 1 ORDER BY id ASC");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function caixaNotasClientePadrao(mysqli $conexao): array
{
    $res = $conexao->query("
        SELECT id, nome, nfe_nome_razao_social
        FROM clientes
        WHERE ativo = 1
          AND (
            nome LIKE '%FARTURA%'
            OR nfe_nome_razao_social LIKE '%FARTURA%'
            OR nome LIKE '%HORTIFRUT%'
            OR nfe_nome_razao_social LIKE '%HORTIFRUT%'
            OR nome LIKE '%OBA%'
            OR nfe_nome_razao_social LIKE '%OBA%'
          )
        ORDER BY
          CASE
            WHEN nfe_nome_razao_social LIKE '%GRUPO FARTURA%' OR nome LIKE '%GRUPO FARTURA%' THEN 0
            WHEN nome LIKE '%OBA%' OR nfe_nome_razao_social LIKE '%OBA%' THEN 1
            ELSE 2
          END,
          id ASC
        LIMIT 1
    ");
    $cliente = $res ? $res->fetch_assoc() : null;
    if (!$cliente) {
        throw new RuntimeException('Cliente padrao OBA/Grupo Fartura nao encontrado.');
    }
    return $cliente;
}

function caixaNotasCriar(mysqli $conexao, int $clienteId, string $dataNota, array $itensPost, string $observacoes = ''): int
{
    caixaNotasGarantirTabelas($conexao);
    if ($clienteId <= 0) {
        $clientePadrao = caixaNotasClientePadrao($conexao);
        $clienteId = (int) $clientePadrao['id'];
    }
    if ($clienteId <= 0) {
        throw new RuntimeException('Selecione o cliente da nota de caixas.');
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $dataNota);
    if (!$dt || $dt->format('Y-m-d') !== $dataNota) {
        throw new RuntimeException('Informe uma data valida.');
    }

    $tipos = [];
    foreach (caixaNotasTipos($conexao) as $tipo) {
        $tipos[(int) $tipo['id']] = $tipo;
    }

    $itens = [];
    $quantidadeTotal = 0;
    $pesoTotal = 0.0;
    $valorTotal = 0.0;
    foreach ($itensPost as $tipoId => $dados) {
        $tipoId = (int) $tipoId;
        if (!isset($tipos[$tipoId])) {
            continue;
        }
        $quantidade = max(0, (int) ($dados['quantidade'] ?? 0));
        if ($quantidade <= 0) {
            continue;
        }
        $valorUnitario = max(0.0, focusNfeFloat($dados['valor_unitario'] ?? $tipos[$tipoId]['valor_unitario']));
        $pesoUnitario = max(0.0, (float) $tipos[$tipoId]['peso_kg']);
        $valorItem = $quantidade * $valorUnitario;
        $pesoItem = $quantidade * $pesoUnitario;
        $itens[] = [$tipoId, $quantidade, $valorUnitario, $pesoUnitario, $valorItem, $pesoItem];
        $quantidadeTotal += $quantidade;
        $valorTotal += $valorItem;
        $pesoTotal += $pesoItem;
    }
    if (!$itens) {
        throw new RuntimeException('Informe pelo menos uma quantidade de caixa.');
    }

    $numeroOs = 'CAIXA-OS-' . (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Ymd-His');
    $stmt = $conexao->prepare("
        INSERT INTO nfe_caixa_notas (numero_os, cliente_id, data_nota, observacoes, quantidade_total, peso_liquido, peso_bruto, valor_total)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('sissiddd', $numeroOs, $clienteId, $dataNota, $observacoes, $quantidadeTotal, $pesoTotal, $pesoTotal, $valorTotal);
    $stmt->execute();
    $notaId = $stmt->insert_id;
    $stmt->close();

    $stmtItem = $conexao->prepare("
        INSERT INTO nfe_caixa_itens (nota_id, tipo_id, quantidade, valor_unitario, peso_unitario_kg, valor_total, peso_total_kg)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($itens as $item) {
        [$tipoId, $quantidade, $valorUnitario, $pesoUnitario, $valorItem, $pesoItem] = $item;
        $stmtItem->bind_param('iiidddd', $notaId, $tipoId, $quantidade, $valorUnitario, $pesoUnitario, $valorItem, $pesoItem);
        $stmtItem->execute();
    }
    $stmtItem->close();

    return $notaId;
}

function caixaNotasBuscar(mysqli $conexao, int $notaId): array
{
    caixaNotasGarantirTabelas($conexao);
    $stmt = $conexao->prepare("
        SELECT n.*, c.nome AS cliente_nome, c.documento AS cliente_documento, c.telefone AS cliente_telefone, c.endereco AS cliente_endereco,
               c.nfe_nome_razao_social, c.nfe_cpf, c.nfe_cnpj, c.nfe_ie, c.nfe_indicador_ie_destinatario,
               c.nfe_telefone, c.nfe_email, c.nfe_endereco, c.nfe_numero, c.nfe_complemento, c.nfe_bairro,
               c.nfe_cidade, c.nfe_estado, c.nfe_cep
        FROM nfe_caixa_notas n
        LEFT JOIN clientes c ON c.id = n.cliente_id
        WHERE n.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $notaId);
    $stmt->execute();
    $nota = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$nota) {
        throw new RuntimeException('Nota de caixas nao encontrada.');
    }

    $stmt = $conexao->prepare("
        SELECT i.*, t.slug, t.nome, t.ncm, t.unidade
        FROM nfe_caixa_itens i
        INNER JOIN nfe_caixa_tipos t ON t.id = i.tipo_id
        WHERE i.nota_id = ?
        ORDER BY i.id ASC
    ");
    $stmt->bind_param('i', $notaId);
    $stmt->execute();
    $itens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return [$nota, $itens];
}

function focusNfeMontarPayloadCaixa(mysqli $conexao, array $config, int $notaId): array
{
    [$nota, $itens] = caixaNotasBuscar($conexao, $notaId);
    $cpf = focusNfeSomenteDigitos((string) ($nota['nfe_cpf'] ?? ''));
    $cnpj = focusNfeSomenteDigitos((string) (($nota['nfe_cnpj'] ?? '') ?: ($nota['cliente_documento'] ?? '')));
    if (strlen($cpf) !== 11) $cpf = '';
    if (strlen($cnpj) !== 14) $cnpj = '';
    if ($cpf === '' && $cnpj === '') {
        throw new RuntimeException('Cliente sem CPF/CNPJ valido para NF-e.');
    }

    $uf = strtoupper((string) ($nota['nfe_estado'] ?: 'SP'));
    $cfop = $uf === 'SP' ? '5909' : '6909';
    $payload = [
        'natureza_operacao' => 'Retorno de bem recebido em comodato',
        'data_emissao' => date('c'),
        'tipo_documento' => 1,
        'finalidade_emissao' => 1,
        'local_destino' => $uf === 'SP' ? 1 : 2,
        'cnpj_emitente' => focusNfeSomenteDigitos($config['cnpj_emitente'] ?? ''),
        'nome_emitente' => (string) ($config['nome_emitente'] ?? ''),
        'inscricao_estadual_emitente' => focusNfeSomenteDigitos($config['inscricao_estadual_emitente'] ?? ''),
        'regime_tributario_emitente' => $config['regime_tributario_emitente'] !== null ? (int) $config['regime_tributario_emitente'] : null,
        'nome_destinatario' => (string) ($nota['nfe_nome_razao_social'] ?: $nota['cliente_nome'] ?: 'NAO INFORMADO'),
        'logradouro_destinatario' => (string) ($nota['nfe_endereco'] ?: $nota['cliente_endereco'] ?: 'NAO INFORMADO'),
        'numero_destinatario' => (string) ($nota['nfe_numero'] ?: 'S/N'),
        'complemento_destinatario' => (string) ($nota['nfe_complemento'] ?? ''),
        'bairro_destinatario' => (string) ($nota['nfe_bairro'] ?: 'NAO INFORMADO'),
        'municipio_destinatario' => (string) ($nota['nfe_cidade'] ?: 'NAO INFORMADO'),
        'uf_destinatario' => $uf,
        'cep_destinatario' => focusNfeSomenteDigitos((string) ($nota['nfe_cep'] ?? '')),
        'telefone_destinatario' => focusNfeSomenteDigitos((string) ($nota['nfe_telefone'] ?: $nota['cliente_telefone'] ?? '')),
        'email_destinatario' => (string) ($nota['nfe_email'] ?? ''),
        'inscricao_estadual_destinatario' => focusNfeSomenteDigitos((string) ($nota['nfe_ie'] ?? '')),
        'indicador_inscricao_estadual_destinatario' => focusNfeIndicadorIe($nota['nfe_indicador_ie_destinatario'] ?? '', $nota['nfe_ie'] ?? ''),
        'modalidade_frete' => 0,
        'items' => [],
    ];
    if ($cpf !== '') $payload['cpf_destinatario'] = $cpf; else $payload['cnpj_destinatario'] = $cnpj;

    $numeroItem = 1;
    foreach ($itens as $item) {
        $quantidade = (int) $item['quantidade'];
        $valorUnitario = (float) $item['valor_unitario'];
        $payload['items'][] = [
            'numero_item' => (string) $numeroItem++,
            'codigo_produto' => str_pad((string) $item['tipo_id'], 13, '0', STR_PAD_LEFT),
            'descricao' => (string) $item['nome'],
            'cfop' => $cfop,
            'unidade_comercial' => (string) ($item['unidade'] ?: 'UN'),
            'quantidade_comercial' => number_format($quantidade, 4, '.', ''),
            'valor_unitario_comercial' => number_format($valorUnitario, 4, '.', ''),
            'unidade_tributavel' => (string) ($item['unidade'] ?: 'UN'),
            'quantidade_tributavel' => number_format($quantidade, 4, '.', ''),
            'valor_unitario_tributavel' => number_format($valorUnitario, 4, '.', ''),
            'codigo_ncm' => focusNfeSomenteDigitos((string) $item['ncm']),
            'valor_bruto' => number_format((float) $item['valor_total'], 2, '.', ''),
            'icms_situacao_tributaria' => '41',
            'icms_origem' => '0',
            'codigo_beneficio_fiscal' => 'SP070100',
            'pis_situacao_tributaria' => '06',
            'cofins_situacao_tributaria' => '06',
        ];
    }
    $payload = focusNfeAplicarVolumes($payload, (float) $nota['peso_liquido'], (float) $nota['peso_bruto'], (int) $nota['quantidade_total'], 'CAIXA PLASTICA');
    $payload['volumes'][0]['marca'] = 'CAIXA OBA';
    if (trim((string) ($nota['observacoes'] ?? '')) !== '') {
        $payload['informacoes_adicionais_contribuinte'] = (string) $nota['observacoes'];
    }
    return [$payload, $nota, $itens];
}

function focusNfeOpcoesCaixaFromPost(array $post): array
{
    return [
        'informacoes_adicionais_contribuinte' => trim((string) ($post['informacoes_adicionais_contribuinte'] ?? '')),
        'revisao' => [
            'destinatario' => is_array($post['destinatario'] ?? null) ? $post['destinatario'] : [],
            'items' => is_array($post['items'] ?? null) ? $post['items'] : [],
        ],
    ];
}

function focusNfeAplicarRevisaoPayloadCaixa(array $payload, array $opcoes): array
{
    $informacoesAdicionais = trim((string) ($opcoes['informacoes_adicionais_contribuinte'] ?? ''));
    if ($informacoesAdicionais !== '') {
        $payload['informacoes_adicionais_contribuinte'] = $informacoesAdicionais;
    }

    $revisao = $opcoes['revisao'] ?? [];
    $dest = is_array($revisao['destinatario'] ?? null) ? $revisao['destinatario'] : [];
    $map = [
        'nome' => 'nome_destinatario',
        'cpf' => 'cpf_destinatario',
        'cnpj' => 'cnpj_destinatario',
        'ie' => 'inscricao_estadual_destinatario',
        'indicador_ie' => 'indicador_inscricao_estadual_destinatario',
        'logradouro' => 'logradouro_destinatario',
        'numero' => 'numero_destinatario',
        'complemento' => 'complemento_destinatario',
        'bairro' => 'bairro_destinatario',
        'municipio' => 'municipio_destinatario',
        'uf' => 'uf_destinatario',
        'cep' => 'cep_destinatario',
        'telefone' => 'telefone_destinatario',
        'email' => 'email_destinatario',
    ];
    foreach ($map as $origem => $campoPayload) {
        if (array_key_exists($origem, $dest)) {
            $valor = trim((string) $dest[$origem]);
            if (in_array($origem, ['cpf', 'cnpj', 'ie', 'cep', 'telefone'], true)) {
                $valor = focusNfeSomenteDigitos($valor);
            }
            if ($origem === 'indicador_ie') {
                $payload[$campoPayload] = (int) $valor;
            } elseif ($valor !== '' || in_array($origem, ['complemento', 'email', 'telefone', 'ie'], true)) {
                $payload[$campoPayload] = $valor;
            }
        }
    }
    if (!empty($payload['cpf_destinatario']) && strlen((string) $payload['cpf_destinatario']) === 11) {
        unset($payload['cnpj_destinatario']);
    } elseif (!empty($payload['cnpj_destinatario'])) {
        unset($payload['cpf_destinatario']);
    }

    $items = is_array($revisao['items'] ?? null) ? $revisao['items'] : [];
    $idxExcluir = [];
    foreach ($payload['items'] as $idx => &$item) {
        $posted = $items[$idx] ?? null;
        if (!is_array($posted)) {
            continue;
        }
        if (!empty($posted['excluir']) && (string) $posted['excluir'] === '1') {
            $idxExcluir[] = $idx;
            continue;
        }
        foreach (['descricao', 'cfop', 'codigo_ncm', 'unidade_comercial', 'unidade_tributavel', 'icms_situacao_tributaria', 'icms_origem', 'pis_situacao_tributaria', 'cofins_situacao_tributaria', 'codigo_beneficio_fiscal'] as $campo) {
            if (array_key_exists($campo, $posted)) {
                $valor = trim((string) $posted[$campo]);
                if ($campo === 'codigo_ncm') {
                    $valor = focusNfeSomenteDigitos($valor);
                }
                if ($campo === 'codigo_beneficio_fiscal') {
                    $codigoBeneficio = focusNfeCodigoBeneficioFiscalValido($valor);
                    if ($codigoBeneficio === null) {
                        unset($item[$campo]);
                    } else {
                        $item[$campo] = $codigoBeneficio;
                    }
                } elseif ($valor !== '') {
                    $item[$campo] = $valor;
                }
            }
        }
        foreach (['quantidade_comercial', 'valor_unitario_comercial', 'quantidade_tributavel', 'valor_unitario_tributavel', 'valor_bruto'] as $campo) {
            if (array_key_exists($campo, $posted)) {
                $item[$campo] = number_format(max(0.0, focusNfeFloat($posted[$campo])), $campo === 'valor_bruto' ? 2 : 4, '.', '');
            }
        }
        $qtdComercial = max(0.0, focusNfeFloat($item['quantidade_comercial'] ?? 0));
        $vuComercial = max(0.0, focusNfeFloat($item['valor_unitario_comercial'] ?? 0));
        $item['quantidade_comercial'] = number_format($qtdComercial, 4, '.', '');
        $item['valor_unitario_comercial'] = number_format($vuComercial, 4, '.', '');
        $item['quantidade_tributavel'] = $item['quantidade_comercial'];
        $item['valor_unitario_tributavel'] = $item['valor_unitario_comercial'];
        $item['valor_bruto'] = number_format(round($qtdComercial * $vuComercial, 2), 2, '.', '');
    }
    unset($item);

    if ($idxExcluir) {
        foreach ($idxExcluir as $i) {
            unset($payload['items'][$i]);
        }
        $payload['items'] = array_values($payload['items']);
        $numeroItem = 1;
        foreach ($payload['items'] as &$itemRenum) {
            $itemRenum['numero_item'] = (string) $numeroItem++;
        }
        unset($itemRenum);
    }
    if (empty($payload['items'])) {
        throw new RuntimeException('Selecione ao menos um produto para emitir a NF-e.');
    }
    foreach ($payload['items'] as &$itemCaixa) {
        if (is_array($itemCaixa)) {
            $itemCaixa['icms_situacao_tributaria'] = '41';
            $itemCaixa['codigo_beneficio_fiscal'] = 'SP070100';
        }
    }
    unset($itemCaixa);

    return $payload;
}

function focusNfeEmitirNotaCaixaRevisada(mysqli $conexao, int $notaId, array $opcoes, string $ambiente = 'producao'): array
{
    $config = focusNfeLoadConfig($conexao, $ambiente);
    [$payload, $nota] = focusNfeMontarPayloadCaixa($conexao, $config, $notaId);
    cadastroFiscalExigirPessoaSelecionada($conexao, 'clientes', (int) $nota['cliente_id'], 'Cliente');
    $payload = focusNfeAplicarRevisaoPayloadCaixa($payload, $opcoes);
    $ref = 'CAIXA-OS-' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $nota['numero_os']);
    $documentoId = focusNfeDocumentoOperacao($conexao, $ref, [
        'tipo_emissao' => 'caixa_retorno',
        'origem_tipo' => 'nota_caixa',
        'origem_id' => $notaId,
        'numero_os' => (string) $nota['numero_os'],
        'cliente_id' => (int) $nota['cliente_id'],
    ], $config, $payload, $opcoes);

    $resultado = focusNfeEnviarOperacao($conexao, $config, $documentoId, $ref, $payload);
    $stmt = $conexao->prepare("SELECT status FROM nfe_documentos WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $documentoId);
    $stmt->execute();
    $status = (string) ($stmt->get_result()->fetch_assoc()['status'] ?? 'enviada');
    $stmt->close();
    $stmt = $conexao->prepare("UPDATE nfe_caixa_notas SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('si', $status, $notaId);
    $stmt->execute();
    $stmt->close();
    return $resultado;
}

function focusNfeEmitirNotaCaixa(mysqli $conexao, int $notaId, string $ambiente = 'producao'): array
{
    $config = focusNfeLoadConfig($conexao, $ambiente);
    [$payload, $nota] = focusNfeMontarPayloadCaixa($conexao, $config, $notaId);
    $ref = 'CAIXA-OS-' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $nota['numero_os']);
    $documentoId = focusNfeDocumentoOperacao($conexao, $ref, [
        'tipo_emissao' => 'caixa_retorno',
        'origem_tipo' => 'nota_caixa',
        'origem_id' => $notaId,
        'numero_os' => (string) $nota['numero_os'],
        'cliente_id' => (int) $nota['cliente_id'],
    ], $config, $payload, ['revisao' => []]);

    $resultado = focusNfeEnviarOperacao($conexao, $config, $documentoId, $ref, $payload);
    $stmt = $conexao->prepare("SELECT status FROM nfe_documentos WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $documentoId);
    $stmt->execute();
    $status = (string) ($stmt->get_result()->fetch_assoc()['status'] ?? 'enviada');
    $stmt->close();
    $stmt = $conexao->prepare("UPDATE nfe_caixa_notas SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('si', $status, $notaId);
    $stmt->execute();
    $stmt->close();
    return $resultado;
}

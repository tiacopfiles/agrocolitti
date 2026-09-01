<?php

const CONTAS_RECEBER_DESTINO_HOST = '192.168.0.220';
const CONTAS_RECEBER_DESTINO_PORT = 3306;
const CONTAS_RECEBER_DESTINO_USER = 'root';
const CONTAS_RECEBER_DESTINO_PASS = '';
const CONTAS_RECEBER_DESTINO_DB = 'contasareceber';
const CONTAS_RECEBER_ENVIO_HABILITADO = true;

const CONTAS_RECEBER_FIXO_TIPO = 'Nota Fiscal';
const CONTAS_RECEBER_FIXO_CATEGORIA = 'Hortifruti';
const CONTAS_RECEBER_FIXO_CONTA = 'Agro Colitti';
const CONTAS_RECEBER_FIXO_CENTROCUSTO = 'Receita';

require_once __DIR__ . '/../../vendas/venda_helper.php';

function contasReceberJsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function contasReceberConnectDestino(): mysqli
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli(CONTAS_RECEBER_DESTINO_HOST, CONTAS_RECEBER_DESTINO_USER, CONTAS_RECEBER_DESTINO_PASS, CONTAS_RECEBER_DESTINO_DB, CONTAS_RECEBER_DESTINO_PORT);
    $db->set_charset('utf8');

    $res = $db->query('SELECT DATABASE() AS db');
    $row = $res->fetch_assoc();
    $activeDb = (string) ($row['db'] ?? '');
    if ($activeDb !== CONTAS_RECEBER_DESTINO_DB) {
        throw new RuntimeException('Trava critica: banco destino ativo nao e ' . CONTAS_RECEBER_DESTINO_DB . '. Ativo: ' . $activeDb);
    }

    return $db;
}

function contasReceberActiveAgroDb(mysqli $conexao): string
{
    $res = $conexao->query('SELECT DATABASE() AS db');
    $row = $res ? $res->fetch_assoc() : [];
    return (string) ($row['db'] ?? '');
}

function contasReceberAssertAgroAllowed(mysqli $conexao): void
{
    $activeDb = contasReceberActiveAgroDb($conexao);
    if ($activeDb !== 'agrocolitti') {
        throw new RuntimeException('Trava critica: banco AgroColitti ativo nao permitido. Ativo: ' . $activeDb);
    }
}

function contasReceberAssertEnvioHabilitado(): void
{
    if (!CONTAS_RECEBER_ENVIO_HABILITADO) {
        throw new RuntimeException('Envio bloqueado: sistema antigo ainda esta em preparacao e validacao.');
    }
}

function contasReceberNormalizeDoc(?string $value): string
{
    return preg_replace('/\D+/', '', (string) $value);
}

function contasReceberFormatDoc(string $documento): string
{
    $doc = contasReceberNormalizeDoc($documento);
    if (strlen($doc) === 11) {
        return substr($doc, 0, 3) . '.' . substr($doc, 3, 3) . '.' . substr($doc, 6, 3) . '-' . substr($doc, 9, 2);
    }
    if (strlen($doc) === 14) {
        return substr($doc, 0, 2) . '.' . substr($doc, 2, 3) . '.' . substr($doc, 5, 3) . '/' . substr($doc, 8, 4) . '-' . substr($doc, 12, 2);
    }
    return $documento;
}

function contasReceberNormalizeName(?string $value): string
{
    return preg_replace('/\s+/', ' ', mb_strtoupper(trim((string) $value), 'UTF-8'));
}

function contasReceberMoney(float $value): string
{
    return number_format($value, 2, '.', '');
}

function contasReceberParseMoney($value): float
{
    return round((float) str_replace(',', '.', (string) $value), 2);
}

function contasReceberParsePayload(?string $json): array
{
    $payload = $json ? json_decode($json, true) : null;
    return is_array($payload) ? $payload : [];
}

function contasReceberValidDate(?string $date): bool
{
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    return checkdate($m, $d, $y);
}

function contasReceberCompetencia(?string $date): string
{
    if (!$date) return '';
    $ts = strtotime($date);
    return $ts ? date('m/Y', $ts) : '';
}

function contasReceberObsDestino(string $obs): string
{
    $obs = trim($obs);
    if (in_array($obs, ['Boleto', 'VIA BOLETO'], true)) return 'Boleto';
    if (in_array($obs, ['Depósito Banco Sicoob', 'Depósito Sicoob', 'VIA DEPOSITO', 'VIA DEPÓSITO'], true)) return 'Depósito Banco Sicoob';
    return $obs;
}

function contasReceberRequestBody(): array
{
    $raw = file_get_contents('php://input');
    $input = json_decode((string) $raw, true);
    return is_array($input) ? $input : $_POST;
}

function contasReceberRequestItems(): array
{
    $input = contasReceberRequestBody();
    $items = $input['items'] ?? [];
    return is_array($items) ? $items : [];
}

function contasReceberFindInputItem(array $items, int $id): array
{
    foreach ($items as $item) {
        if ((int) ($item['id'] ?? $item['origem_id'] ?? 0) === $id) return is_array($item) ? $item : [];
    }
    return [];
}

function contasReceberLoadBoletos(mysqli $conexao, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "
        SELECT b.id, b.numero_os, b.venda_id, b.nfe_documento_id, b.situacao AS boleto_situacao,
               b.seu_numero, b.nosso_numero, b.valor AS boleto_valor, b.data_emissao AS boleto_data_emissao,
               b.data_vencimento AS boleto_data_vencimento, b.pagador_nome, b.pagador_cpf_cnpj,
               b.payload_envio AS boleto_payload_envio, b.payload_retorno AS boleto_payload_retorno,
               COALESCE(d.id, d2.id) AS nfe_id,
               COALESCE(d.tipo_emissao, d2.tipo_emissao) AS nfe_tipo_emissao,
               COALESCE(d.status, d2.status) AS nfe_status,
               COALESCE(d.numero_nfe, d2.numero_nfe) AS numero_nfe,
               COALESCE(d.serie, d2.serie) AS serie,
               COALESCE(d.chave_nfe, d2.chave_nfe) AS chave_nfe,
               COALESCE(d.payload_json, d2.payload_json) AS nfe_payload_json,
               COALESCE(d.emitida_em, d2.emitida_em) AS nfe_emitida_em,
               COALESCE(d.created_at, d2.created_at) AS nfe_created_at,
               ci.id AS integracao_id, ci.status AS integracao_status, ci.destino_id AS integracao_destino_id,
               ci.ndocumento AS integracao_ndocumento, ci.erro_mensagem AS integracao_erro
        FROM boletos b
        LEFT JOIN nfe_documentos d ON d.id = b.nfe_documento_id
        LEFT JOIN nfe_documentos d2 ON d.id IS NULL AND d2.id = (
            SELECT dx.id
            FROM nfe_documentos dx
            WHERE dx.tipo_emissao = 'venda'
              AND dx.status = 'autorizada'
              AND dx.numero_os = b.numero_os
            ORDER BY COALESCE(dx.emitida_em, dx.created_at) DESC, dx.id DESC
            LIMIT 1
        )
        LEFT JOIN contas_integracoes ci
               ON ci.tipo = 'receber'
              AND ci.origem_tabela = 'boletos'
              AND ci.origem_id = b.id
        WHERE b.id IN ($placeholders)
    ";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $byId = [];
    foreach ($rows as $row) $byId[(int) $row['id']] = $row;
    return $byId;
}

function contasReceberLoadNfeDocs(mysqli $conexao, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "
        SELECT d.id, d.id AS nfe_id, d.ref, d.numero_os, d.venda_id, d.cliente_id,
               d.tipo_emissao, d.tipo_emissao AS nfe_tipo_emissao, d.status, d.status AS nfe_status,
               d.numero_nfe, d.serie, d.chave_nfe, d.payload_json AS nfe_payload_json, d.emitida_em, d.emitida_em AS nfe_emitida_em, d.autorizada_em, d.created_at,
               b.id AS boleto_id, b.situacao AS boleto_situacao, b.valor AS boleto_valor,
               b.data_emissao AS boleto_data_emissao, b.data_vencimento AS boleto_data_vencimento,
               b.pagador_nome, b.pagador_cpf_cnpj, b.seu_numero, b.nosso_numero,
               ci.id AS integracao_id, ci.status AS integracao_status, ci.destino_id AS integracao_destino_id,
               ci.ndocumento AS integracao_ndocumento, ci.erro_mensagem AS integracao_erro
        FROM nfe_documentos d
        LEFT JOIN boletos b ON b.id = (
            SELECT bx.id
            FROM boletos bx
            WHERE bx.nfe_documento_id = d.id
               OR (bx.nfe_documento_id IS NULL AND bx.numero_os = d.numero_os)
            ORDER BY COALESCE(bx.data_emissao, bx.created_at) DESC, bx.id DESC
            LIMIT 1
        )
        LEFT JOIN contas_integracoes ci
               ON ci.tipo = 'receber'
              AND (
                    (ci.origem_tabela = 'nfe_documentos' AND ci.origem_id = d.id)
                    OR (b.id IS NOT NULL AND ci.origem_tabela = 'boletos' AND ci.origem_id = b.id)
                  )
        WHERE d.id IN ($placeholders)
    ";
    $stmt = $conexao->prepare($sql);
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $byId = [];
    foreach ($rows as $row) $byId[(int) $row['id']] = $row;
    return $byId;
}

function contasReceberClienteNome(array $boleto, array $payload): string
{
    $nome = trim((string) ($boleto['pagador_nome'] ?? ''));
    if ($nome === '') $nome = trim((string) ($payload['nome_destinatario'] ?? $payload['razao_social_destinatario'] ?? ''));
    return $nome;
}

function contasReceberClienteDocumento(array $boleto, array $payload): string
{
    $doc = trim((string) ($boleto['pagador_cpf_cnpj'] ?? ''));
    if ($doc !== '') return $doc;
    foreach (['cnpj_destinatario', 'cpf_destinatario', 'destinatario_cnpj', 'destinatario_cpf'] as $key) {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value !== '') return $value;
    }
    return '';
}

function contasReceberNfeNumero(array $boleto, array $payload): string
{
    $numero = trim((string) ($boleto['numero_nfe'] ?? ''));
    if ($numero === '') $numero = trim((string) ($payload['numero'] ?? ''));
    if ($numero === '') $numero = trim((string) ($boleto['seu_numero'] ?? ''));
    return $numero;
}

function contasReceberNfeValor(array $payload): float
{
    foreach (['valor_total', 'valor_produtos', 'total_nota', 'total'] as $key) {
        if (isset($payload[$key]) && (float) $payload[$key] > 0) return round((float) $payload[$key], 2);
    }

    $total = 0.0;
    foreach (($payload['items'] ?? $payload['itens'] ?? []) as $item) {
        if (!is_array($item)) continue;
        foreach (['valor_bruto', 'valor_total', 'valor_produtos'] as $key) {
            if (isset($item[$key]) && (float) $item[$key] > 0) {
                $total += (float) $item[$key];
                continue 2;
            }
        }
        $qtd = (float) ($item['quantidade_comercial'] ?? $item['quantidade'] ?? 0);
        $unitario = (float) ($item['valor_unitario_comercial'] ?? $item['valor_unitario'] ?? 0);
        if ($qtd > 0 && $unitario > 0) $total += $qtd * $unitario;
    }
    return round($total, 2);
}

function contasReceberValorVendaAgro(mysqli $conexao, ?string $numeroOs, ?int $vendaId = null): float
{
    $numeroOs = trim((string) $numeroOs);
    $params = [];
    $types = '';
    if ($numeroOs !== '') {
        $where = 'numero_os = ?';
        $params[] = $numeroOs;
        $types .= 's';
    } elseif ($vendaId && $vendaId > 0) {
        $where = 'id = ?';
        $params[] = $vendaId;
        $types .= 'i';
    } else {
        return 0.0;
    }

    $stmt = $conexao->prepare("SELECT * FROM vendas WHERE status = 'concluido' AND $where ORDER BY id ASC");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $total = 0.0;
    foreach ($rows as $row) {
        $total += array_key_exists('valor_final_editado', $row) && $row['valor_final_editado'] !== null
            ? (float) $row['valor_final_editado']
            : calcularPrecoTotalVenda($row);
    }
    return round($total, 2);
}

function contasReceberBuildItem(mysqli $conexao, array $boleto, array $input, ?mysqli $destino = null): array
{
    $erros = [];
    $avisos = [];
    $payload = contasReceberParsePayload($boleto['nfe_payload_json'] ?? null);
    $nome = contasReceberClienteNome($boleto, $payload);
    $docOriginal = contasReceberClienteDocumento($boleto, $payload);
    $docNormalizado = contasReceberNormalizeDoc($docOriginal);
    $docFormatado = contasReceberFormatDoc($docOriginal);
    $numeroNfe = contasReceberNfeNumero($boleto, $payload);
    $dataEmissaoRaw = (string) (($boleto['nfe_emitida_em'] ?? '') ?: ($boleto['autorizada_em'] ?? '') ?: ($boleto['boleto_data_emissao'] ?? '') ?: ($boleto['nfe_created_at'] ?? '') ?: '');
    $dataEmissao = $dataEmissaoRaw ? date('Y-m-d', strtotime($dataEmissaoRaw)) : '';
    $vencimento = trim((string) ($input['vencimento'] ?? ''));
    $obsPadrao = !empty($boleto['boleto_id']) ? 'Boleto' : 'Depósito Banco Sicoob';
    $obs = trim((string) ($input['obs'] ?? $obsPadrao));
    $valorNfe = contasReceberNfeValor($payload);
    if ($valorNfe <= 0) {
        $valorNfe = contasReceberValorVendaAgro($conexao, $boleto['numero_os'] ?? '', isset($boleto['venda_id']) ? (int) $boleto['venda_id'] : null);
    }
    $valor = array_key_exists('valor', $input) ? contasReceberParseMoney($input['valor']) : $valorNfe;
    $acrescimo = array_key_exists('acrescimo', $input) ? contasReceberParseMoney($input['acrescimo']) : 0.0;
    $desconto = array_key_exists('desconto', $input) ? contasReceberParseMoney($input['desconto']) : 0.0;
    $valorTotal = round($valor + $acrescimo - $desconto, 2);

    if ((string) ($boleto['nfe_tipo_emissao'] ?? '') !== 'venda') $erros[] = 'Documento vinculado nao e NF-e de venda.';
    if ((string) ($boleto['nfe_status'] ?? '') !== 'autorizada') $erros[] = 'NF-e de venda nao esta autorizada.';
    if ($numeroNfe === '') $erros[] = 'Numero da NF-e emitida no site ausente.';
    if ($nome === '') $erros[] = 'Cliente ausente.';
    if (!in_array(strlen($docNormalizado), [11, 14], true)) $erros[] = 'CPF/CNPJ do cliente ausente ou invalido.';
    if (!contasReceberValidDate($dataEmissao)) $erros[] = 'Data de emissao da NF-e invalida.';
    if (!contasReceberValidDate($vencimento)) $erros[] = 'Vencimento obrigatorio ou invalido.';
    if (!in_array(contasReceberObsDestino($obs), ['Boleto', 'Depósito Banco Sicoob'], true)) $erros[] = 'Observacao deve ser Boleto ou Depósito Banco Sicoob.';
    if ($valor <= 0) $erros[] = 'Valor ausente ou zerado.';
    if ($acrescimo < 0 || $desconto < 0) $erros[] = 'Acrescimo e desconto nao podem ser negativos.';
    if ($valorTotal < 0) $erros[] = 'Valor total negativo.';
    if ($valorTotal == 0.0) $avisos[] = 'Valor total zerado.';

    if (!empty($boleto['integracao_id']) && ($boleto['integracao_status'] ?? '') === 'enviado') {
        $erros[] = 'Boleto ja enviado ao contas a receber. ID destino: ' . (int) ($boleto['integracao_destino_id'] ?? 0);
    }
    if (!empty($boleto['integracao_id']) && in_array((string) ($boleto['integracao_status'] ?? ''), ['ignorado', 'cancelado'], true)) {
        $erros[] = 'Boleto marcado como historico ignorado na integracao.';
    }

    $lancamento = [
        'ndocumento' => $numeroNfe,
        'tipo' => CONTAS_RECEBER_FIXO_TIPO,
        'nomefantasia' => $nome,
        'vencimento' => $vencimento,
        'dataemissao' => $dataEmissao,
        'obs' => contasReceberObsDestino($obs),
        'valor' => contasReceberMoney($valor),
        'datapgto' => null,
        'categoria' => CONTAS_RECEBER_FIXO_CATEGORIA,
        'desconto' => contasReceberMoney($desconto),
        'valortotal' => contasReceberMoney($valorTotal),
        'parcela' => null,
        'nparcela' => null,
        'conta' => CONTAS_RECEBER_FIXO_CONTA,
        'situacao' => 'aberto',
        'acrescimo' => contasReceberMoney($acrescimo),
        'competencia' => contasReceberCompetencia($dataEmissao),
        'centrocusto' => CONTAS_RECEBER_FIXO_CENTROCUSTO,
        'cnpj' => $docFormatado,
    ];

    $clienteDestino = null;
    $legacyDuplicate = null;
    if ($destino && !$erros) {
        $clienteDestino = contasReceberFindClienteDestino($destino, $docNormalizado, $nome);
        if ($clienteDestino) {
            $lancamento['nomefantasia'] = (string) ($clienteDestino['nome'] ?: $nome);
        } else {
            $avisos[] = 'Cliente nao encontrado no contas a receber; sera cadastrado antes do lancamento.';
        }
        $legacyDuplicate = contasReceberLegacyDuplicate($destino, $lancamento);
        if ($legacyDuplicate) {
            $erros[] = 'Possivel duplicidade no contas a receber. ID existente: ' . (int) $legacyDuplicate['id'];
        }
    }

    $snapshot = [
        'origem' => [
            'tabela' => 'nfe_documentos',
            'id' => (int) $boleto['id'],
            'numero_os' => (string) ($boleto['numero_os'] ?? ''),
            'nfe_id' => (int) ($boleto['nfe_id'] ?? 0),
            'numero_nfe' => $numeroNfe,
            'serie' => (string) ($boleto['serie'] ?? ''),
            'chave_nfe' => (string) ($boleto['chave_nfe'] ?? ''),
            'boleto_id' => (int) ($boleto['boleto_id'] ?? 0),
            'boleto_situacao' => (string) ($boleto['boleto_situacao'] ?? ''),
        ],
        'cliente' => [
            'nome_agro' => $nome,
            'nome_destino' => $lancamento['nomefantasia'],
            'cnpj_cpf' => $docFormatado,
            'cnpj_cpf_normalizado' => $docNormalizado,
            'cliente_destino' => $clienteDestino,
        ],
        'calculo' => [
            'valor' => contasReceberMoney($valor),
            'acrescimo' => contasReceberMoney($acrescimo),
            'desconto' => contasReceberMoney($desconto),
            'valor_total' => contasReceberMoney($valorTotal),
        ],
        'lancamento' => $lancamento,
        'legacy_duplicate' => $legacyDuplicate,
    ];

    return [
        'origem_id' => (int) $boleto['id'],
        'origem_ref' => (string) ($boleto['numero_os'] ?: $boleto['ref'] ?: $boleto['id']),
        'ndocumento' => $numeroNfe,
        'cliente' => $lancamento['nomefantasia'],
        'cnpj' => $docFormatado,
        'status' => $erros ? 'bloqueado' : 'apto',
        'erros' => $erros,
        'avisos' => $avisos,
        'lancamento' => $lancamento,
        'snapshot' => $snapshot,
    ];
}

function contasReceberTableColumns(mysqli $destino, string $table): array
{
    $stmt = $destino->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $columns = [];
    foreach ($rows as $row) $columns[(string) $row['COLUMN_NAME']] = true;
    return $columns;
}

function contasReceberClienteTable(mysqli $destino): string
{
    $res = $destino->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('cliente','clientes','fornecedor')");
    $found = [];
    while ($row = $res->fetch_assoc()) $found[] = (string) $row['TABLE_NAME'];
    foreach (['cliente', 'clientes', 'fornecedor'] as $candidate) {
        if (in_array($candidate, $found, true)) return $candidate;
    }
    throw new RuntimeException('Tabela de clientes nao encontrada no contas a receber.');
}

function contasReceberFindClienteDestino(mysqli $destino, string $docNormalizado, string $razaoSocial): ?array
{
    $table = contasReceberClienteTable($destino);
    $columns = contasReceberTableColumns($destino, $table);
    if (empty($columns['cnpj']) || empty($columns['razaosocial'])) return null;
    $razaoSocial = contasReceberNormalizeName($razaoSocial);

    $cnpjExpr = "REPLACE(REPLACE(REPLACE(REPLACE(cnpj, '.', ''), '/', ''), '-', ''), ' ', '')";
    $docLen = strlen($docNormalizado);
    if ($docLen === 11 || $docLen === 14) {
        // CPF/CNPJ valido e unico: casar apenas pelo documento, ignorando variacoes de razao social
        // (pontuacao, sufixo "S.A.", CPF anexado ao nome do pagador, etc.) que geravam duplicidade.
        $sql = "SELECT * FROM `$table` WHERE $cnpjExpr = ? ORDER BY id DESC LIMIT 1";
        $stmt = $destino->prepare($sql);
        $stmt->bind_param('s', $docNormalizado);
    } else {
        // Sem documento valido: cair para casamento por documento + razao social exata (legado).
        if ($razaoSocial === '') return null;
        $sql = "SELECT * FROM `$table` WHERE $cnpjExpr = ? AND UPPER(TRIM(razaosocial)) = ? ORDER BY id DESC LIMIT 1";
        $stmt = $destino->prepare($sql);
        $stmt->bind_param('ss', $docNormalizado, $razaoSocial);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;

    return [
        'id' => (int) ($row['id'] ?? 0),
        'tabela' => $table,
        'cnpj' => (string) ($row['cnpj'] ?? ''),
        'nome' => (string) (($row['nomefantasia'] ?? '') ?: ($row['razaosocial'] ?? '') ?: ($row['nome'] ?? '')),
    ];
}

function contasReceberEnsureClienteDestino(mysqli $destino, string $docNormalizado, string $nome): array
{
    $existente = contasReceberFindClienteDestino($destino, $docNormalizado, $nome);
    if ($existente) return $existente + ['criado' => false];

    $table = contasReceberClienteTable($destino);
    $columns = contasReceberTableColumns($destino, $table);
    $docFormatado = contasReceberFormatDoc($docNormalizado);
    $nomeSeguro = trim($nome) !== '' ? trim($nome) : $docFormatado;

    $values = [
        'cnpj' => $docFormatado,
        'nomefantasia' => $nomeSeguro,
        'razaosocial' => $nomeSeguro,
        'endereco' => '',
        'cidade' => '',
        'estado' => '',
        'bairro' => '',
        'cep' => '',
        'telefone1' => '',
        'telefone2' => '',
    ];
    if (isset($columns['nome']) && !isset($columns['nomefantasia'])) $values['nome'] = $nomeSeguro;

    $insertColumns = [];
    $params = [];
    foreach ($values as $column => $value) {
        if (isset($columns[$column])) {
            $insertColumns[] = "`$column`";
            $params[] = $value;
        }
    }
    if (!$insertColumns) {
        throw new RuntimeException('Tabela de clientes sem colunas compativeis para cadastro minimo.');
    }

    $placeholders = implode(',', array_fill(0, count($insertColumns), '?'));
    $sql = "INSERT INTO `$table` (" . implode(',', $insertColumns) . ") VALUES ($placeholders)";
    $stmt = $destino->prepare($sql);
    contasReceberBindParams($stmt, str_repeat('s', count($params)), $params);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return [
        'id' => $id,
        'tabela' => $table,
        'cnpj' => $docFormatado,
        'nome' => $nomeSeguro,
        'criado' => true,
    ];
}

function contasReceberBindParams(mysqli_stmt $stmt, string $types, array &$params): void
{
    $refs = [];
    $refs[] = $types;
    foreach ($params as $key => $value) $refs[] = &$params[$key];
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function contasReceberLegacyDuplicate(mysqli $destino, array $lancamento): ?array
{
    $sql = "
        SELECT id, ndocumento, cnpj, conta, valor, dataemissao
        FROM lancamentos
        WHERE ndocumento = ?
          AND REPLACE(REPLACE(REPLACE(REPLACE(cnpj, '.', ''), '/', ''), '-', ''), ' ', '') = ?
          AND conta = ?
        ORDER BY id DESC
        LIMIT 1
    ";
    $cnpj = contasReceberNormalizeDoc($lancamento['cnpj'] ?? '');
    $stmt = $destino->prepare($sql);
    $stmt->bind_param('sss', $lancamento['ndocumento'], $cnpj, $lancamento['conta']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function contasReceberRecordEvento(mysqli $conexao, ?int $integracaoId, ?int $loteId, string $evento, string $detalhe, array $payload = []): void
{
    $payloadJson = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
    $stmt = $conexao->prepare("INSERT INTO contas_integracao_eventos (integracao_id, lote_id, evento, detalhe, payload_json, usuario_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('iisssi', $integracaoId, $loteId, $evento, $detalhe, $payloadJson, $usuarioId);
    $stmt->execute();
    $stmt->close();
}

function contasReceberRecordEventoSeguro(mysqli $conexao, ?int $integracaoId, ?int $loteId, string $evento, string $detalhe, array $payload = []): bool
{
    try {
        contasReceberRecordEvento($conexao, $integracaoId, $loteId, $evento, $detalhe, $payload);
        return true;
    } catch (Throwable $e) {
        error_log('Contas a receber: falha ao registrar evento apos processamento financeiro. Evento=' . $evento
            . ' integracao=' . (string) $integracaoId . ' lote=' . (string) $loteId
            . ' erro=' . $e->getMessage());
        return false;
    }
}

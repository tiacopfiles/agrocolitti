<?php
ob_start();

require __DIR__ . '/../../config/conexao.php';
require __DIR__ . '/../../auth/proteger.php';
require __DIR__ . '/../../config/permissions.php';

requireModule('historico_nfe', '../historico.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../historico.php');
    exit;
}

validarTokenCsrf();
@set_time_limit(0);

function nfeZipErro(string $mensagem): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Location: ../historico.php?msg=erro&detalhe=' . urlencode($mensagem));
    exit;
}

function nfeZipLimparNomeArquivo(string $nome): string
{
    $nome = preg_replace('/[^A-Za-z0-9._-]+/', '_', $nome);
    $nome = trim((string) $nome, '._-');
    return $nome !== '' ? $nome : 'nfe';
}

function nfeZipConfigPorAmbiente(mysqli $conexao): array
{
    $configs = [];
    $res = $conexao->query("SELECT ambiente, base_url, token_homologacao, token_producao FROM focus_config");
    if (!$res) {
        return $configs;
    }
    while ($row = $res->fetch_assoc()) {
        $ambiente = (string) ($row['ambiente'] ?? '');
        if ($ambiente === '') {
            continue;
        }
        $tokenCampo = $ambiente === 'producao' ? 'token_producao' : 'token_homologacao';
        $configs[$ambiente] = [
            'base_url' => (string) ($row['base_url'] ?? ''),
            'token' => trim((string) ($row[$tokenCampo] ?? '')),
        ];
    }
    return $configs;
}

function nfeZipUrlArquivo(string $baseUrl, string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
}

function nfeZipBaixarArquivo(string $url, string $token): ?string
{
    if ($url === '') {
        return null;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => '',
            CURLOPT_TIMEOUT => 45,
        ];
        if ($token !== '') {
            $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $opts[CURLOPT_USERPWD] = $token . ':';
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($body) && $body !== '' && $httpCode >= 200 && $httpCode < 300) {
            return $body;
        }
        return null;
    }

    $context = null;
    if ($token !== '') {
        $context = stream_context_create([
            'http' => [
                'timeout' => 45,
                'header' => 'Authorization: Basic ' . base64_encode($token . ':'),
            ],
        ]);
    }
    $body = @file_get_contents($url, false, $context);
    return is_string($body) && $body !== '' ? $body : null;
}

function nfeZipPareceXml(string $conteudo): bool
{
    $inicio = ltrim($conteudo, "\xEF\xBB\xBF \t\r\n");
    return str_starts_with($inicio, '<?xml') || str_starts_with($inicio, '<NFe') || str_starts_with($inicio, '<proc') || str_starts_with($inicio, '<nfeProc') || str_starts_with($inicio, '<ret');
}

function nfeZipAdicionarXml(ZipArchive $zip, array $doc, array $configs, string $campo, string $sufixo, array &$falhas, array &$nomesUsados): int
{
    $path = trim((string) ($doc[$campo] ?? ''));
    if ($path === '') {
        return 0;
    }

    $ambiente = (string) ($doc['ambiente'] ?? 'producao');
    $config = $configs[$ambiente] ?? ['base_url' => '', 'token' => ''];
    $url = nfeZipUrlArquivo((string) $config['base_url'], $path);
    $conteudo = nfeZipBaixarArquivo($url, (string) $config['token']);
    if ($conteudo === null) {
        $falhas[] = sprintf('ID %s - %s - %s', (string) ($doc['id'] ?? '-'), (string) ($doc['ref'] ?? '-'), $url);
        return 0;
    }
    if (!nfeZipPareceXml($conteudo)) {
        $falhas[] = sprintf('ID %s - %s - conteudo nao parece XML - %s', (string) ($doc['id'] ?? '-'), (string) ($doc['ref'] ?? '-'), $url);
        return 0;
    }

    $tipo = nfeZipLimparNomeArquivo((string) ($doc['tipo_emissao'] ?? 'nfe'));
    $status = nfeZipLimparNomeArquivo((string) ($doc['status'] ?? ''));
    $numero = trim((string) ($doc['numero_nfe'] ?? ''));
    $numeroNome = $numero !== '' ? 'nfe_' . $numero : 'nfe_sem_numero_id_' . (string) ($doc['id'] ?? '0');
    $serie = trim((string) ($doc['serie'] ?? ''));
    $serieNome = $serie !== '' ? '_serie_' . $serie : '';
    $ref = nfeZipLimparNomeArquivo((string) ($doc['ref'] ?? ''));
    $nome = nfeZipLimparNomeArquivo($numeroNome . $serieNome . '_' . $tipo . '_' . $status . '_' . $ref . $sufixo) . '.xml';

    $base = $nome;
    $contador = 2;
    while (isset($nomesUsados[$nome])) {
        $nome = preg_replace('/\.xml$/', '_' . $contador . '.xml', $base);
        $contador++;
    }
    $nomesUsados[$nome] = true;
    $zip->addFromString($nome, $conteudo);
    return 1;
}

if (!class_exists('ZipArchive')) {
    nfeZipErro('Compactacao ZIP nao esta habilitada neste servidor.');
}

$tipo = (string) ($_POST['tipo'] ?? 'todos');
$status = (string) ($_POST['status'] ?? 'todos');
$dataIni = trim((string) ($_POST['data_ini'] ?? ''));
$dataFim = trim((string) ($_POST['data_fim'] ?? ''));
$tiposPermitidos = ['todos', 'venda', 'compra'];
$statusesPermitidos = ['todos', 'autorizada', 'cancelada'];
if (!in_array($tipo, $tiposPermitidos, true) || !in_array($status, $statusesPermitidos, true)) {
    nfeZipErro('Filtro invalido para baixar XMLs.');
}
if (($dataIni !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataIni)) || ($dataFim !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim))) {
    nfeZipErro('Filtro de data invalido.');
}
if ($dataIni !== '' && $dataFim !== '' && $dataIni > $dataFim) {
    nfeZipErro('A data inicial nao pode ser maior que a data final.');
}

$where = ["COALESCE(caminho_xml, '') <> ''"];
$params = [];
$types = '';
if ($tipo !== 'todos') {
    $where[] = 'tipo_emissao = ?';
    $params[] = $tipo;
    $types .= 's';
} else {
    $where[] = "tipo_emissao IN ('venda', 'compra')";
}
if ($status !== 'todos') {
    $where[] = 'status = ?';
    $params[] = $status;
    $types .= 's';
} else {
    $where[] = "status IN ('autorizada', 'cancelada')";
}
if ($dataIni !== '') {
    $where[] = 'DATE(COALESCE(emitida_em, created_at)) >= ?';
    $params[] = $dataIni;
    $types .= 's';
}
if ($dataFim !== '') {
    $where[] = 'DATE(COALESCE(emitida_em, created_at)) <= ?';
    $params[] = $dataFim;
    $types .= 's';
}

$sql = "SELECT id, ref, tipo_emissao, ambiente, status, numero_nfe, serie, caminho_xml, caminho_xml_cancelamento
        FROM nfe_documentos
        WHERE " . implode(' AND ', $where) . "
        ORDER BY COALESCE(emitida_em, created_at) DESC, id DESC";
$stmt = $conexao->prepare($sql);
if (!$stmt) {
    nfeZipErro('Falha ao preparar consulta de XMLs.');
}
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$docs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$docs) {
    nfeZipErro('Nenhum XML encontrado para os filtros selecionados.');
}

$configs = nfeZipConfigPorAmbiente($conexao);
$tmp = tempnam(sys_get_temp_dir(), 'nfe_xmls_');
if ($tmp === false) {
    nfeZipErro('Nao foi possivel criar arquivo temporario do ZIP.');
}

$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    @unlink($tmp);
    nfeZipErro('Nao foi possivel iniciar o arquivo ZIP.');
}

$falhas = [];
$nomesUsados = [];
$adicionados = 0;
foreach ($docs as $doc) {
    $adicionados += nfeZipAdicionarXml($zip, $doc, $configs, 'caminho_xml', '', $falhas, $nomesUsados);
    if (($doc['status'] ?? '') === 'cancelada') {
        $adicionados += nfeZipAdicionarXml($zip, $doc, $configs, 'caminho_xml_cancelamento', '_cancelamento', $falhas, $nomesUsados);
    }
}

if ($falhas) {
    $zip->addFromString('xmls_nao_baixados.txt', implode(PHP_EOL, $falhas) . PHP_EOL);
}
$zip->close();

if ($adicionados === 0) {
    @unlink($tmp);
    nfeZipErro('Nenhum XML pode ser baixado da Focus para os filtros selecionados.');
}

$periodoNome = ($dataIni !== '' || $dataFim !== '') ? '_' . nfeZipLimparNomeArquivo(($dataIni ?: 'inicio') . '_a_' . ($dataFim ?: 'fim')) : '';
$nomeZip = 'nfe_xmls_' . nfeZipLimparNomeArquivo($tipo) . '_' . nfeZipLimparNomeArquivo($status) . $periodoNome . '_' . date('Ymd_His') . '.zip';
$conteudoZip = file_get_contents($tmp);
@unlink($tmp);
if (!is_string($conteudoZip) || $conteudoZip === '') {
    nfeZipErro('Nao foi possivel ler o ZIP gerado.');
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $nomeZip . '"');
header('Content-Length: ' . strlen($conteudoZip));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo $conteudoZip;
exit;

<?php
// --- SEÇÃO: helper principal de ciclos ---
function getCicloAtivo($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM ciclos WHERE status = 'aberto' AND ativo = 1 LIMIT 1");
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// --- SEÇÃO: helper para verificar se coluna existe em ambiente PDO ---
function colunaExistePdo(PDO $pdo, string $tabela, string $coluna): bool {
    static $cache = [];

    $chave = $tabela . '.' . $coluna;
    if (array_key_exists($chave, $cache)) {
        return $cache[$chave];
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$tabela, $coluna]);

    $cache[$chave] = (bool) $stmt->fetchColumn();
    return $cache[$chave];
}

// --- SEÇÃO: helper do id do ciclo ativo ---
function getCicloAtivoId($pdo) {
    $ciclo = getCicloAtivo($pdo);
    return $ciclo ? $ciclo['id'] : null;
}

// --- SEÇÃO: helper de formatação de kg ---
function formatKg($valor) {
    return number_format((float) $valor, 2, ',', '.') . ' kg';
}

// --- SEÇÃO: helper de nome curto do mês ---
function nomeMesCurto($mes) {
    $meses = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
    return $meses[intval($mes) - 1] ?? '';
}

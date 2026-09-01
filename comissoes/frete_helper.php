<?php

function comissoesFreteEntrepostos(): array
{
    return ['Rodrigo', 'Nivaldo', 'Dezao', 'Botan', 'Danylo', 'Pato Roco', 'CD'];
}

function comissoesFreteNormalizarEntreposto(string $entreposto): ?string
{
    $informado = trim($entreposto);
    foreach (comissoesFreteEntrepostos() as $permitido) {
        if (mb_strtolower($informado, 'UTF-8') === mb_strtolower($permitido, 'UTF-8')) {
            return $permitido;
        }
    }
    return null;
}

function comissoesFreteRegistrarEnvio(
    mysqli $conexao,
    string $numeroOs,
    string $entreposto,
    ?int $vendaId,
    int $nfeDocumentoId,
    int $contasIntegracaoId,
    float $valorLiquido
): void
{
    $numeroOs = trim($numeroOs);
    $entrepostoNormalizado = comissoesFreteNormalizarEntreposto($entreposto);
    if ($numeroOs === '') {
        throw new InvalidArgumentException('Numero da OS obrigatorio para registrar a comissao de frete.');
    }
    if ($entrepostoNormalizado === null) {
        throw new InvalidArgumentException('Entreposto invalido para registrar a comissao de frete.');
    }
    if ($nfeDocumentoId <= 0 || $contasIntegracaoId <= 0) {
        throw new InvalidArgumentException('NF-e e integracao do contas a receber sao obrigatorias para registrar a comissao de frete.');
    }
    if ($valorLiquido < 0) {
        throw new InvalidArgumentException('Valor liquido invalido para registrar a comissao de frete.');
    }

    $vendaIdValido = $vendaId !== null && $vendaId > 0 ? $vendaId : null;
    $stmt = $conexao->prepare("
        INSERT INTO comissoes_frete
            (numero_os, entreposto, venda_id, nfe_documento_id, contas_integracao_id, valor_liquido, contabilizado_em)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            entreposto = VALUES(entreposto),
            venda_id = COALESCE(VALUES(venda_id), venda_id),
            nfe_documento_id = VALUES(nfe_documento_id),
            contas_integracao_id = VALUES(contas_integracao_id),
            valor_liquido = VALUES(valor_liquido),
            contabilizado_em = NOW(),
            atualizado_em = CURRENT_TIMESTAMP
    ");
    $stmt->bind_param(
        'ssiiid',
        $numeroOs,
        $entrepostoNormalizado,
        $vendaIdValido,
        $nfeDocumentoId,
        $contasIntegracaoId,
        $valorLiquido
    );
    $stmt->execute();
    $stmt->close();
}

function comissoesFreteStatusLabel(string $status): string
{
    $labels = [
        'anexado' => 'Em montagem',
        'pendente' => 'Pendente',
        'concluido' => 'Concluida',
        'cancelado' => 'Cancelada',
    ];
    return $labels[$status] ?? ($status !== '' ? ucfirst($status) : 'Sem status');
}

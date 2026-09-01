<?php
validarTokenCsrf();
try {
    $dados = cadastroFiscalPessoa($_POST);
    $emailsNfe = [];
    foreach (['email_nfe', 'email_nfe_2', 'email_nfe_3'] as $campoEmail) {
        $email = strtolower(trim((string) ($_POST[$campoEmail] ?? '')));
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255)) {
            throw new InvalidArgumentException('Informe enderecos de e-mail validos para o envio do XML da NF-e.');
        }
        $emailsNfe[] = $email;
    }
    $stmt = $conexao->prepare("
        INSERT INTO clientes
        (nome, documento, telefone, endereco, email_nfe, email_nfe_2, email_nfe_3, ativo,
         nfe_nome_razao_social, nfe_cpf, nfe_cnpj, nfe_ie,
         nfe_indicador_ie_destinatario, nfe_telefone, nfe_endereco,
         nfe_numero, nfe_complemento, nfe_bairro, nfe_cidade, nfe_estado, nfe_cep)
        VALUES (?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        str_repeat('s', 20),
        $dados['nome'], $dados['documento'], $dados['telefone'], $dados['endereco'], $emailsNfe[0], $emailsNfe[1], $emailsNfe[2],
        $dados['nfe_nome_razao_social'], $dados['nfe_cpf'], $dados['nfe_cnpj'], $dados['nfe_ie'],
        $dados['nfe_indicador_ie_destinatario'], $dados['nfe_telefone'], $dados['nfe_endereco'],
        $dados['nfe_numero'], $dados['nfe_complemento'], $dados['nfe_bairro'],
        $dados['nfe_cidade'], $dados['nfe_estado'], $dados['nfe_cep']
    );
    $stmt->execute();
    $stmt->close();
    header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=salvo');
    exit;
} catch (Throwable $e) {
    $erroCadastro = $e->getMessage();
}

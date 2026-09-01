<section class="wizard-panel field-grid full" data-wizard-panel="2">
    <div class="fiscal-note">Campos necessarios para gerar NF-e pela Focus.</div>
    <label class="full">Razao social / nome fiscal
        <input type="text" name="nfe_nome_razao_social" required>
    </label>
    <label>Tipo de documento
        <select name="tipo_documento" required>
            <option value="cnpj">CNPJ</option>
            <option value="cpf">CPF</option>
        </select>
    </label>
    <label>CPF/CNPJ
        <input type="text" name="documento_fiscal" required minlength="11" maxlength="18">
    </label>
    <label>Indicador de IE
        <select name="nfe_indicador_ie_destinatario" required>
            <option value="Contribuinte do ICMS">Contribuinte do ICMS</option>
            <option value="Contribuinte Isento">Contribuinte isento</option>
            <option value="Nao Contribuinte">Nao contribuinte</option>
        </select>
    </label>
    <label class="ie-wrap">Inscricao estadual
        <input type="text" name="nfe_ie">
    </label>
    <label class="full">Logradouro
        <input type="text" name="nfe_endereco" required>
    </label>
    <label>Numero
        <input type="text" name="nfe_numero" required>
    </label>
    <label>Complemento (opcional)
        <input type="text" name="nfe_complemento">
    </label>
    <label>Bairro
        <input type="text" name="nfe_bairro" required>
    </label>
    <label>Cidade
        <input type="text" name="nfe_cidade" required>
    </label>
    <label>UF
        <input type="text" name="nfe_estado" required minlength="2" maxlength="2" placeholder="SP">
    </label>
    <label>CEP
        <input type="text" name="nfe_cep" required minlength="8" maxlength="9">
    </label>
    <div class="wizard-actions">
        <button type="button" class="btn-secondary" data-back>Voltar</button>
        <button type="button" class="btn-primary" data-next>Revisar</button>
    </div>
</section>

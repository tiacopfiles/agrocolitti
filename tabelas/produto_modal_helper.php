<?php

function renderBotaoAdicionarProdutoTabela(): void
{
    ?>
    <button type="button" class="add-produto-btn" onclick="abrirModalAdicionarProdutoTabela()" title="Adicionar produto">
        <i class="bi bi-plus-lg"></i><span>Produto</span>
    </button>
    <?php
}

function renderModalAdicionarProdutoTabela(string $tipoTabela): void
{
    ?>
    <style>
    .add-produto-btn {
        background:#2e7d32;
        color:#fff;
        border:0;
        min-height:40px;
        padding:0 12px;
        border-radius:6px;
        cursor:pointer;
        font-size:13px;
        font-weight:700;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:6px;
        white-space:nowrap;
    }
    .add-produto-btn:hover { background:#1b5e20; color:#fff; }
    .produto-modal-overlay {
        position:fixed;
        inset:0;
        background:rgba(0,0,0,.45);
        display:none;
        align-items:center;
        justify-content:center;
        padding:18px;
        z-index:9999;
    }
    .produto-modal-overlay.is-open { display:flex; }
    .produto-modal-card {
        width:min(460px, 100%);
        background:#fff;
        border-radius:10px;
        padding:22px;
        box-shadow:0 12px 30px rgba(0,0,0,.18);
    }
    .produto-modal-header {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:14px;
        margin-bottom:16px;
    }
    .produto-modal-header h3 {
        margin:0;
        color:#1b5e20;
        font-size:20px;
        line-height:1.2;
    }
    .produto-modal-header p {
        margin:6px 0 0;
        color:#555;
        font-size:13px;
        line-height:1.4;
    }
    .produto-modal-close {
        width:34px;
        height:34px;
        border:0;
        border-radius:6px;
        background:#f1f3f4;
        cursor:pointer;
        color:#333;
    }
    .produto-modal-form {
        display:grid;
        gap:12px;
    }
    .produto-modal-form label {
        display:grid;
        gap:6px;
        font-size:13px;
        font-weight:700;
        color:#333;
    }
    .produto-modal-form input,
    .produto-modal-form select {
        width:100% !important;
        min-height:40px;
        border:1px solid #ccd7cc;
        border-radius:6px;
        padding:8px 10px;
        font-size:14px;
        background:#fff;
    }
    .produto-modal-actions {
        display:flex;
        justify-content:flex-end;
        gap:10px;
        margin-top:8px;
    }
    .produto-modal-actions button {
        border:0;
        border-radius:6px;
        padding:10px 14px;
        cursor:pointer;
        font-weight:700;
    }
    .produto-modal-cancelar { background:#e9ecef; color:#222; }
    .produto-modal-salvar { background:#2e7d32; color:#fff; }
    .produto-modal-salvar:disabled { background:#aaa; cursor:not-allowed; }
    .produto-modal-divider { display:flex; align-items:center; gap:10px; color:#64748b; font-size:12px; font-weight:700; text-transform:uppercase; }
    .produto-modal-divider::before, .produto-modal-divider::after { content:''; height:1px; background:#d7ded7; flex:1; }
    .btn-excluir-produto-tabela { background:#b91c1c; color:#fff; border:none; width:34px; height:34px; padding:0; border-radius:6px; cursor:pointer; font-size:15px; display:inline-flex; align-items:center; justify-content:center; margin-left:6px; }
    .btn-excluir-produto-tabela:hover { background:#7f1d1d; }
    .produto-modal-status {
        min-height:18px;
        font-size:12px;
        color:#c62828;
    }
    .produto-modal-status.ok { color:#2e7d32; }
    @media (max-width:760px) {
        .add-produto-btn { width:100%; }
        .produto-modal-actions { flex-direction:column; }
        .produto-modal-actions button { width:100%; }
    }
    </style>
    <div class="produto-modal-overlay" id="modalAdicionarProdutoTabela" aria-hidden="true">
        <div class="produto-modal-card" role="dialog" aria-modal="true" aria-labelledby="produtoModalTitulo">
            <div class="produto-modal-header">
                <div>
                    <h3 id="produtoModalTitulo">Adicionar produto</h3>
                    <p>O produto sera criado ativo e aparecera nas tabelas para preencher preco, categoria e disponibilidade.</p>
                </div>
                <button type="button" class="produto-modal-close" onclick="fecharModalAdicionarProdutoTabela()" title="Fechar">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <form class="produto-modal-form" id="formAdicionarProdutoTabela">
                <input type="hidden" name="tabela_origem" value="<?= htmlspecialchars($tipoTabela, ENT_QUOTES, 'UTF-8') ?>">
                <label>
                    Produto existente no estoque/cadastro
                    <select name="produto_id" id="produtoExistenteTabela">
                        <option value="">Carregando produtos...</option>
                    </select>
                </label>
                <div class="produto-modal-divider">ou cadastrar novo</div>
                <label>
                    Nome do produto
                    <input type="text" name="nome" id="novoProdutoNome" maxlength="150" autocomplete="off">
                </label>
                <label>
                    Unidade comercial
                    <select name="unidade" id="novoProdutoUnidade" required>
                        <option value="KG">Quilograma (KG)</option>
                        <option value="UN">Unidade (UN)</option>
                        <option value="CX">Caixa (CX)</option>
                        <option value="BDJ">Bandeja (BDJ)</option>
                        <option value="PCT">Pacote (PCT)</option>
                    </select>
                </label>
                <div class="produto-modal-status" id="novoProdutoStatus"></div>
                <div class="produto-modal-actions">
                    <button type="button" class="produto-modal-cancelar" onclick="fecharModalAdicionarProdutoTabela()">Cancelar</button>
                    <button type="submit" class="produto-modal-salvar" id="novoProdutoSalvar">Adicionar</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    function abrirModalAdicionarProdutoTabela() {
        const modal = document.getElementById('modalAdicionarProdutoTabela');
        const status = document.getElementById('novoProdutoStatus');
        const form = document.getElementById('formAdicionarProdutoTabela');
        if (!modal || !form) return;
        form.reset();
        if (status) {
            status.textContent = '';
            status.className = 'produto-modal-status';
        }
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        carregarProdutosExistentesTabela();
        setTimeout(() => document.getElementById('produtoExistenteTabela')?.focus(), 50);
    }
    function fecharModalAdicionarProdutoTabela() {
        const modal = document.getElementById('modalAdicionarProdutoTabela');
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') fecharModalAdicionarProdutoTabela();
    });
    document.getElementById('modalAdicionarProdutoTabela')?.addEventListener('click', function (event) {
        if (event.target === this) fecharModalAdicionarProdutoTabela();
    });
    async function carregarProdutosExistentesTabela() {
        const select = document.getElementById('produtoExistenteTabela');
        if (!select || select.dataset.carregado === '1') return;
        try {
            const tipoTabela = document.querySelector('#formAdicionarProdutoTabela input[name="tabela_origem"]')?.value || '';
            const resposta = await fetch('actions/buscar_produtos_tabela.php?tabela=' + encodeURIComponent(tipoTabela), { credentials: 'same-origin' });
            const dados = await resposta.json();
            if (!dados.ok) throw new Error(dados.erro || 'Falha ao buscar produtos.');
            select.innerHTML = '<option value="">Cadastrar novo produto</option>';
            (dados.produtos || []).forEach((produto) => {
                const option = document.createElement('option');
                option.value = String(produto.id || '');
                const estoque = Number(produto.estoque || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                option.textContent = `${produto.nome} - Estoque: ${estoque} ${produto.unidade || 'KG'}`;
                select.appendChild(option);
            });
            select.dataset.carregado = '1';
        } catch (erro) {
            select.innerHTML = '<option value="">Nao foi possivel carregar produtos</option>';
        }
    }
    document.getElementById('produtoExistenteTabela')?.addEventListener('change', function () {
        const nome = document.getElementById('novoProdutoNome');
        if (nome && this.value) nome.value = '';
    });
    document.getElementById('novoProdutoNome')?.addEventListener('input', function () {
        const select = document.getElementById('produtoExistenteTabela');
        if (select && this.value.trim() !== '') select.value = '';
    });
    async function excluirProdutoTabela(produtoId, nomeProduto) {
        if (!produtoId) return;
        const msg = `Excluir definitivamente o produto ${nomeProduto || produtoId}? Esta acao remove o cadastro e as linhas de preco quando nao houver historico.`;
        const executar = async () => {
            const fd = new FormData();
            fd.append('produto_id', produtoId);
            const resposta = await fetch('actions/excluir_produto_tabela.php', { method: 'POST', body: fd });
            const dados = await resposta.json();
            if (!dados.ok) throw new Error(dados.erro || 'Nao foi possivel excluir o produto.');
            window.location.reload();
        };
        try {
            if (window.appConfirm) {
                window.appConfirm(msg, () => executar().catch((erro) => alert(erro.message || erro)), { danger: true });
            } else if (confirm(msg)) {
                await executar();
            }
        } catch (erro) {
            alert(erro.message || 'Erro ao excluir produto.');
        }
    }
    document.getElementById('formAdicionarProdutoTabela')?.addEventListener('submit', async function (event) {
        event.preventDefault();
        const form = event.currentTarget;
        const status = document.getElementById('novoProdutoStatus');
        const btn = document.getElementById('novoProdutoSalvar');
        if (status) {
            status.textContent = 'Salvando...';
            status.className = 'produto-modal-status';
        }
        if (btn) btn.disabled = true;
        try {
            const resposta = await fetch('actions/adicionar_produto_tabela.php', {
                method: 'POST',
                body: new FormData(form)
            });
            const dados = await resposta.json();
            if (!dados.ok) {
                throw new Error(dados.erro || 'Nao foi possivel adicionar o produto.');
            }
            if (status) {
                status.textContent = dados.existente ? 'Produto selecionado. Recarregando tabela...' : 'Produto adicionado. Recarregando tabela...';
                status.className = 'produto-modal-status ok';
            }
            setTimeout(() => window.location.reload(), 650);
        } catch (erro) {
            if (status) {
                status.textContent = erro.message || 'Erro ao adicionar produto.';
                status.className = 'produto-modal-status';
            }
            if (btn) btn.disabled = false;
        }
    });
    </script>
    <?php
}

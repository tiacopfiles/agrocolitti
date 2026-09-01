(function () {
    const endpointClientes = 'clientes_desconto_financeiro.php';

    function criarModal() {
        let modal = document.getElementById('modalExportacaoFinanceira');
        if (modal) return modal;

        modal = document.createElement('div');
        modal.id = 'modalExportacaoFinanceira';
        modal.className = 'export-fin-modal';
        modal.innerHTML = `
            <div class="export-fin-dialog" role="dialog" aria-modal="true" aria-labelledby="exportFinTitulo">
                <h3 id="exportFinTitulo">Aplicar desconto/acréscimo financeiro?</h3>
                <p>Escolha um cliente com percentual financeiro ou mantenha os preços originais.</p>
                <label for="exportFinCliente">Cliente</label>
                <select id="exportFinCliente">
                    <option value="">Nenhum cliente / manter preços originais</option>
                </select>
                <div class="export-fin-status" id="exportFinStatus"></div>
                <div class="export-fin-actions">
                    <button type="button" class="export-fin-cancel">Cancelar</button>
                    <button type="button" class="export-fin-confirm">Gerar Excel</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        return modal;
    }

    function garantirEstilos() {
        if (document.getElementById('exportacaoFinanceiraStyles')) return;
        const style = document.createElement('style');
        style.id = 'exportacaoFinanceiraStyles';
        style.textContent = `
            .export-fin-modal{position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.42);padding:16px}
            .export-fin-modal.is-open{display:flex}
            .export-fin-dialog{width:min(460px,100%);background:#fff;border-radius:8px;box-shadow:0 18px 48px rgba(0,0,0,.22);padding:20px;color:#1b1b1b}
            .export-fin-dialog h3{margin:0 0 8px;font-size:18px}
            .export-fin-dialog p{margin:0 0 16px;color:#555;font-size:13px;line-height:1.4}
            .export-fin-dialog label{display:block;margin:0 0 6px;font-weight:700;font-size:13px}
            .export-fin-dialog select{width:100%;min-height:40px;border:1px solid #ccc;border-radius:6px;padding:8px;background:#fff}
            .export-fin-status{min-height:18px;margin-top:10px;font-size:12px;color:#666}
            .export-fin-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:18px}
            .export-fin-actions button{border:0;border-radius:6px;padding:9px 14px;cursor:pointer;font-weight:700}
            .export-fin-cancel{background:#e0e0e0;color:#222}
            .export-fin-confirm{background:#1b5e20;color:#fff}
        `;
        document.head.appendChild(style);
    }

    function parseMoeda(texto) {
        const limpo = (texto || '').replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.');
        const valor = parseFloat(limpo);
        return Number.isFinite(valor) ? valor : null;
    }

    function formatarMoeda(valor) {
        return 'R$ ' + Number(valor || 0).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function imprimirComPercentual(percentual) {
        if (typeof window.atualizarValoresParaImpressao === 'function') {
            window.atualizarValoresParaImpressao();
        }
        const celulas = Array.from(document.querySelectorAll('.print-commercial td.num'));
        const originais = celulas.map((celula) => celula.textContent);
        if (percentual > 0) {
            celulas.forEach((celula) => {
                const valor = parseMoeda(celula.textContent);
                if (valor !== null) {
                    celula.textContent = formatarMoeda(Math.round(valor * (1 + percentual) * 100) / 100);
                }
            });
        }
        window.print();
        setTimeout(() => {
            celulas.forEach((celula, index) => {
                celula.textContent = originais[index];
            });
        }, 500);
    }

    async function abrirModal(tipo, urlBase, acao) {
        garantirEstilos();
        const modal = criarModal();
        const select = modal.querySelector('#exportFinCliente');
        const status = modal.querySelector('#exportFinStatus');
        const cancelar = modal.querySelector('.export-fin-cancel');
        const confirmar = modal.querySelector('.export-fin-confirm');

        confirmar.textContent = acao === 'print' ? 'Imprimir' : 'Gerar Excel';
        select.innerHTML = '<option value="">Nenhum cliente / manter preços originais</option>';
        status.textContent = 'Carregando clientes...';
        modal.classList.add('is-open');

        try {
            const resposta = await fetch(`${endpointClientes}?tipo=${encodeURIComponent(tipo)}`, { credentials: 'same-origin' });
            const texto = await resposta.text();
            let dados;
            try {
                dados = JSON.parse(texto);
            } catch (erroJson) {
                throw new Error('Não foi possível carregar os clientes. Atualize a página e tente novamente.');
            }
            if (!dados.ok) throw new Error(dados.erro || 'Falha ao buscar clientes.');
            (dados.clientes || []).forEach((cliente) => {
                const option = document.createElement('option');
                option.value = cliente.id;
                option.dataset.percentual = cliente.percentual || 0;
                option.dataset.acrescimoOba = cliente.acrescimo_oba ? '1' : '0';
                option.textContent = `${cliente.nome} (${cliente.percentual_formatado})`;
                select.appendChild(option);
            });
            status.textContent = dados.clientes && dados.clientes.length
                ? 'O percentual será aplicado apenas no Excel gerado.'
                : 'Nenhum cliente com percentual financeiro maior que zero foi encontrado.';
        } catch (erro) {
            status.textContent = erro.message || 'Não foi possível carregar os clientes.';
        }

        function fechar() {
            modal.classList.remove('is-open');
            cancelar.removeEventListener('click', fechar);
            confirmar.removeEventListener('click', gerar);
        }

        function gerar() {
            if (acao === 'print') {
                const percentual = parseFloat(select.options[select.selectedIndex]?.dataset.percentual || '0') || 0;
                fechar();
                imprimirComPercentual(percentual);
                return;
            }
            const destino = new URL(urlBase, window.location.href);
            if (select.value) destino.searchParams.set('cliente_id', select.value);
            if (select.options[select.selectedIndex]?.dataset.acrescimoOba === '1') {
                destino.searchParams.set('acrescimo_oba', '1');
            }
            fechar();
            window.location.href = destino.toString();
        }

        cancelar.addEventListener('click', fechar);
        confirmar.addEventListener('click', gerar);
    }

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-export-financeiro]');
        if (trigger) {
            event.preventDefault();
            abrirModal(trigger.dataset.tipoTabela || 'atacado', trigger.getAttribute('href'), 'excel');
            return;
        }
        const printTrigger = event.target.closest('[data-print-financeiro]');
        if (!printTrigger) return;
        event.preventDefault();
        abrirModal(printTrigger.dataset.tipoTabela || 'atacado', null, 'print');
    });
})();

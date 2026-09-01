(function () {
    function valor(form, nome) {
        var campo = form.querySelector('[name="' + nome + '"]');
        if (!campo) return '';
        if (campo.tagName === 'SELECT') {
            return campo.options[campo.selectedIndex] ? campo.options[campo.selectedIndex].text : '';
        }
        return campo.value.trim();
    }

    function atualizarIe(form) {
        var indicador = form.querySelector('[name="nfe_indicador_ie_destinatario"]');
        var ie = form.querySelector('[name="nfe_ie"]');
        var wrap = ie ? ie.closest('.ie-wrap') : null;
        if (!indicador || !ie || !wrap) return;
        var contribuinte = indicador.value === 'Contribuinte do ICMS';
        wrap.classList.toggle('is-hidden', !contribuinte);
        ie.required = contribuinte;
        if (!contribuinte) ie.value = '';
    }

    function montarRevisao(form) {
        form.querySelectorAll('[data-review-field]').forEach(function (saida) {
            var nomes = saida.getAttribute('data-review-field').split('|');
            var textos = nomes.map(function (nome) { return valor(form, nome); }).filter(Boolean);
            saida.textContent = textos.join(' - ') || '-';
        });
    }

    function iniciar(form) {
        var etapa = 1;
        var panels = form.querySelectorAll('[data-wizard-panel]');
        var steps = form.querySelectorAll('[data-wizard-step]');

        function exibir(numero) {
            etapa = numero;
            panels.forEach(function (painel) {
                painel.classList.toggle('is-active', Number(painel.dataset.wizardPanel) === etapa);
            });
            steps.forEach(function (passo) {
                passo.classList.toggle('is-active', Number(passo.dataset.wizardStep) === etapa);
            });
            if (etapa === 3) montarRevisao(form);
        }

        function painelValido() {
            var painel = form.querySelector('[data-wizard-panel="' + etapa + '"]');
            if (!painel) return false;
            var campos = painel.querySelectorAll('input, select, textarea');
            for (var i = 0; i < campos.length; i++) {
                if (!campos[i].checkValidity()) {
                    campos[i].reportValidity();
                    return false;
                }
            }
            return true;
        }

        form.querySelectorAll('[data-next]').forEach(function (botao) {
            botao.addEventListener('click', function () {
                atualizarIe(form);
                if (painelValido()) exibir(Math.min(3, etapa + 1));
            });
        });
        form.querySelectorAll('[data-back]').forEach(function (botao) {
            botao.addEventListener('click', function () { exibir(Math.max(1, etapa - 1)); });
        });
        var indicador = form.querySelector('[name="nfe_indicador_ie_destinatario"]');
        if (indicador) indicador.addEventListener('change', function () { atualizarIe(form); });
        form.addEventListener('submit', function (evento) {
            atualizarIe(form);
            if (!form.checkValidity()) {
                evento.preventDefault();
                for (var i = 1; i <= 3; i++) {
                    var invalido = form.querySelector('[data-wizard-panel="' + i + '"] :invalid');
                    if (invalido) {
                        exibir(i);
                        invalido.reportValidity();
                        break;
                    }
                }
            }
        });
        form.wizardExibir = exibir;
        atualizarIe(form);
        exibir(1);
    }

    document.querySelectorAll('[data-fiscal-wizard]').forEach(iniciar);
    window.cadastroFiscalMontarRevisao = montarRevisao;
})();

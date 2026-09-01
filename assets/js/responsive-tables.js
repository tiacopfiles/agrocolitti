(function () {
    'use strict';

    var primaryWords = [
        'produto', 'item', 'nome', 'cliente', 'fornecedor',
        'preco', 'preço', 'valor', 'total', 'status',
        'estoque', 'disponivel', 'disponível', 'acao', 'ação', 'acoes', 'ações'
    ];

    function normalize(text) {
        return String(text || '').trim().toLowerCase();
    }

    function isMobile() {
        return window.matchMedia && window.matchMedia('(max-width: 768px)').matches;
    }

    function headerLabels(table) {
        var headers = Array.from(table.querySelectorAll('thead th'));
        if (!headers.length) {
            var firstRow = table.querySelector('tr');
            headers = firstRow ? Array.from(firstRow.children).filter(function (cell) {
                return cell.tagName && cell.tagName.toLowerCase() === 'th';
            }) : [];
            if (headers.length && firstRow) {
                firstRow.classList.add('app-table-header-row');
            }
        }
        return headers.map(function (header) {
            return header.textContent.trim();
        });
    }

    function isPrimary(label, index, total) {
        var clean = normalize(label);
        if (index === 0 || index === total - 1) return true;
        return primaryWords.some(function (word) {
            return clean.indexOf(word) !== -1;
        });
    }

    function markStatus(row) {
        var text = normalize(row.textContent);
        row.classList.toggle('app-status-danger', text.indexOf('indispon') !== -1 || text.indexOf('erro') !== -1 || text.indexOf('cancel') !== -1);
        row.classList.toggle('app-status-ok', text.indexOf('dispon') !== -1 || text.indexOf('confirm') !== -1 || text.indexOf('ativo') !== -1);
    }

    function addToggleCell(row) {
        if (row.querySelector('.app-mobile-toggle-cell')) return;
        var hiddenCount = Array.from(row.children).filter(function (cell) {
            return cell.tagName && cell.tagName.toLowerCase() === 'td' && !cell.classList.contains('app-mobile-primary');
        }).length;
        if (!hiddenCount) return;

        var cell = document.createElement('td');
        cell.className = 'app-mobile-toggle-cell app-mobile-primary';
        cell.setAttribute('data-label', '');

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'app-row-details-btn';
        button.textContent = 'Ver detalhes';
        button.addEventListener('click', function () {
            var collapsed = row.classList.toggle('app-card-collapsed');
            button.textContent = collapsed ? 'Ver detalhes' : 'Ocultar detalhes';
        });

        cell.appendChild(button);
        row.appendChild(cell);
    }

    function isGroupedChildRow(row) {
        return row.matches('.os-item-row, .montagem-os-item-row, .compra-montagem-item-row, .compra-pendente-item-row, .historico-item-row, .historico-venda-item-row');
    }

    function enhanceTable(table) {
        if (table.dataset.responsiveEnhanced === '1' || table.dataset.noResponsive === '1') return;
        var isStockOverview = table.id === 'tabelaProdutos';
        var labels = headerLabels(table);
        if (labels.length < 2) return;

        table.classList.add('app-responsive-table');
        table.dataset.responsiveEnhanced = '1';
        table.style.setProperty('min-width', '0', 'important');
        table.style.setProperty('width', '100%', 'important');
        table.style.setProperty('max-width', '100%', 'important');

        Array.from(table.querySelectorAll('tr')).forEach(function (row) {
            var cells = Array.from(row.children).filter(function (cell) {
                return cell.tagName && cell.tagName.toLowerCase() === 'td';
            });
            if (!cells.length) return;

            cells.forEach(function (cell, index) {
                var label = cell.getAttribute('data-label') || labels[index] || '';
                cell.setAttribute('data-label', label);
                if (isPrimary(label, index, cells.length)) {
                    cell.classList.add('app-mobile-primary');
                }
            });

            markStatus(row);
            if (isMobile()) {
                if (isGroupedChildRow(row)) {
                    row.classList.add('app-mobile-child-row');
                } else if (isStockOverview) {
                    row.classList.remove('app-card-collapsed');
                } else {
                    row.classList.add('app-card-collapsed');
                    addToggleCell(row);
                }
            }
        });
    }

    function enhanceAllTables() {
        Array.from(document.querySelectorAll('table')).forEach(enhanceTable);
    }

    function setupScrollHints() {
        if (!isMobile()) return;

        Array.from(document.querySelectorAll('.table-responsive, .table-container, .table-wrap')).forEach(function (wrapper) {
            var scrollTable = wrapper.querySelector(':scope > table[data-no-responsive="1"]');
            if (!scrollTable || wrapper.dataset.scrollHintReady === '1') return;

            wrapper.dataset.scrollHintReady = '1';
            wrapper.classList.add('app-scroll-hint-ready');

            var syncHint = function () {
                var maxScroll = Math.max(0, wrapper.scrollWidth - wrapper.clientWidth);
                var progress = maxScroll ? wrapper.scrollLeft / maxScroll : 0;
                var offset = Math.round(progress * 74);

                wrapper.style.setProperty('--app-scroll-hint-offset', offset + 'px');
                wrapper.classList.toggle('app-scroll-hint-end', progress > 0.96);
                wrapper.classList.toggle('app-scroll-hint-start', progress < 0.04);
            };

            wrapper.addEventListener('scroll', function () {
                var maxScroll = Math.max(0, wrapper.scrollWidth - wrapper.clientWidth);
                if (maxScroll > 0 && wrapper.scrollLeft > 2) {
                    wrapper.classList.add('app-scroll-hint-touched');
                }
                window.requestAnimationFrame(syncHint);
            }, { passive: true });

            syncHint();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        enhanceAllTables();
        setupScrollHints();
    });
})();

(function () {
    'use strict';

    const AGROCOLITTI_DRAFT_REMINDER_MINUTES = 5;
    const REMINDER_INTERVAL_MS = 30000;
    const REMINDER_DELAY_MS = AGROCOLITTI_DRAFT_REMINDER_MINUTES * 60 * 1000;

    const drafts = [
        {
            key: 'agrocolitti_compra_draft',
            type: 'compra',
            label: 'compra',
            path: '/previsoes/previsao_fornecedor.php'
        },
        {
            key: 'agrocolitti_venda_draft',
            type: 'venda',
            label: 'venda',
            path: '/vendas/vendas.php'
        }
    ];

    function currentUserId() {
        return String(window.AGROCOLITTI_CURRENT_USER_ID || '');
    }

    function parseDraft(key) {
        try {
            return JSON.parse(localStorage.getItem(key) || 'null');
        } catch (error) {
            return null;
        }
    }

    function saveDraft(key, draft) {
        localStorage.setItem(key, JSON.stringify(draft));
    }

    function sameUser(draft) {
        return draft && draft.usuario_id && String(draft.usuario_id) === currentUserId();
    }

    function elapsedEnough(draft) {
        const updatedAt = Date.parse(draft.atualizado_em || '');
        return updatedAt && Date.now() - updatedAt >= REMINDER_DELAY_MS;
    }

    function alreadyReminded(draft) {
        return Boolean(draft.lembrete_enviado_em);
    }

    function fallbackUrl(config, draft) {
        const params = new URLSearchParams();
        if (draft.numero_os) params.set('numero_os', draft.numero_os);
        if (draft.etapa) params.set('etapa', draft.etapa);
        return appRoot() + config.path + (params.toString() ? '?' + params.toString() : '');
    }

    function appRoot() {
        return String(window.AGROCOLITTI_APP_ROOT || '').replace(/\/$/, '');
    }

    function continueUrl(config, draft) {
        const savedUrl = String(draft.url || '');
        if (savedUrl.indexOf('http://') === 0 || savedUrl.indexOf('https://') === 0 || savedUrl.indexOf('/') === 0) {
            return savedUrl;
        }
        return fallbackUrl(config, draft);
    }

    function isOnDraftPage(config) {
        const currentPath = window.location.pathname.replace(/\/$/, '');
        return currentPath === (appRoot() + config.path).replace(/\/$/, '');
    }

    function removeToast() {
        const previous = document.querySelector('.draft-reminder-toast');
        if (previous) previous.remove();
    }

    function createToast(config, draft) {
        removeToast();

        const toast = document.createElement('div');
        toast.className = 'draft-reminder-toast';
        toast.setAttribute('role', 'status');
        toast.innerHTML = [
            '<div class="draft-reminder-toast__text">',
            '<strong></strong>',
            '<span></span>',
            '</div>',
            '<div class="draft-reminder-toast__actions">',
            '<a class="draft-reminder-toast__primary" href="#">Continuar</a>',
            '<button type="button" class="draft-reminder-toast__secondary">Depois</button>',
            '</div>'
        ].join('');

        toast.querySelector('strong').textContent = 'Finalize sua ' + config.label;
        toast.querySelector('span').textContent = 'Voce iniciou a OS ' + (draft.numero_os || '') + ' e ainda nao concluiu.';
        toast.querySelector('a').href = continueUrl(config, draft);
        toast.querySelector('button').addEventListener('click', removeToast);
        document.body.appendChild(toast);
    }

    function injectStyles() {
        if (document.getElementById('draft-reminder-styles')) return;
        const style = document.createElement('style');
        style.id = 'draft-reminder-styles';
        style.textContent = [
            '.draft-reminder-toast{position:fixed;right:22px;bottom:22px;z-index:5000;width:min(380px,calc(100vw - 28px));background:#fff;border:1px solid #d7e6d8;border-left:5px solid #2e7d32;box-shadow:0 18px 45px rgba(20,40,20,.18);border-radius:8px;padding:14px;display:flex;gap:14px;align-items:center;justify-content:space-between;font-family:Arial,sans-serif;color:#1f2a21}',
            '.draft-reminder-toast__text{display:flex;flex-direction:column;gap:4px;min-width:0}',
            '.draft-reminder-toast__text strong{font-size:15px;color:#1b5e20}',
            '.draft-reminder-toast__text span{font-size:13px;line-height:1.35}',
            '.draft-reminder-toast__actions{display:flex;gap:8px;align-items:center;flex-shrink:0}',
            '.draft-reminder-toast__primary,.draft-reminder-toast__secondary{border-radius:7px;padding:8px 10px;font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap;cursor:pointer}',
            '.draft-reminder-toast__primary{background:#1b5e20;color:#fff;border:1px solid #1b5e20}',
            '.draft-reminder-toast__secondary{background:#fff;color:#1b5e20;border:1px solid #b8d6ba}',
            '@media(max-width:640px){.draft-reminder-toast{left:14px;right:14px;bottom:14px;align-items:flex-start;flex-direction:column}.draft-reminder-toast__actions{width:100%}.draft-reminder-toast__primary,.draft-reminder-toast__secondary{flex:1;text-align:center}}'
        ].join('');
        document.head.appendChild(style);
    }

    function checkDrafts() {
        if (!currentUserId()) return;

        drafts.some(function (config) {
            const draft = parseDraft(config.key);
            if (!draft || !draft.numero_os || isOnDraftPage(config) || !sameUser(draft) || !elapsedEnough(draft) || alreadyReminded(draft)) {
                return false;
            }

            draft.lembrete_enviado_em = new Date().toISOString();
            saveDraft(config.key, draft);
            injectStyles();
            createToast(config, draft);
            return true;
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        checkDrafts();
        setInterval(checkDrafts, REMINDER_INTERVAL_MS);
    });
})();

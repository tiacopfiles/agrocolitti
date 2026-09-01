<?php
if (!function_exists('userCanAccess')) {
    require_once __DIR__ . '/../config/permissions.php';
}
function appBaseLink(string $basePath, string $path): string
{
    return rtrim($basePath, '/\\') . '/' . ltrim($path, '/\\');
}

function renderAppLayoutStyles(): void
{
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $parts = array_values(array_filter(explode('/', $scriptName), 'strlen'));
    $appRoot = isset($parts[0]) ?'/' . rawurlencode($parts[0]) : '';
    $tabelasSomenteVisualizacao = strpos($scriptName, '/tabelas/') !== false
        && function_exists('userCanEditTabelas')
        && !userCanEditTabelas();

    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">';
    echo '<link rel="stylesheet" href="' . htmlspecialchars($appRoot . '/assets/css/global.css?v=20260522d', ENT_QUOTES, 'UTF-8') . '">';
    echo '<link rel="icon" href="' . htmlspecialchars($appRoot . '/assets/img/favicon.ico?v=20260601b', ENT_QUOTES, 'UTF-8') . '" sizes="any">';
    echo '<link rel="icon" type="image/svg+xml" href="' . htmlspecialchars($appRoot . '/assets/img/favicon.svg?v=20260601b', ENT_QUOTES, 'UTF-8') . '">';
    echo '<link rel="icon" type="image/png" sizes="32x32" href="' . htmlspecialchars($appRoot . '/assets/img/favicon-32.png?v=20260601b', ENT_QUOTES, 'UTF-8') . '">';
    echo '<link rel="icon" type="image/png" sizes="16x16" href="' . htmlspecialchars($appRoot . '/assets/img/favicon-16.png?v=20260601b', ENT_QUOTES, 'UTF-8') . '">';
    echo '<link rel="apple-touch-icon" sizes="180x180" href="' . htmlspecialchars($appRoot . '/assets/img/apple-touch-icon.png?v=20260601b', ENT_QUOTES, 'UTF-8') . '">';
    echo <<<'CSS'
<style>
html,body{
    max-width:100%!important;
    overflow-x:hidden!important;
}
*,*::before,*::after{box-sizing:border-box}
img,svg,canvas,video{max-width:100%;height:auto}
html,body{
    touch-action:manipulation!important;
    -webkit-text-size-adjust:100%!important;
}
a,button,input,select,textarea,label,.btn,.btn-primary,.btn-secondary,.btn-danger,.btn-edit,.btn-confirmar,.app-btn-lite,.btn-toggle-os{
    touch-action:manipulation!important;
}
input,select,textarea{
    font-size:max(16px,1em)!important;
}
.container,.page-container,.app-shell,.page{
    max-width:calc(100vw - 264px)!important;
    overflow-x:hidden!important;
}
.table-responsive,.table-container,.table-wrap{
    max-width:100%!important;
    overflow-x:auto!important;
    -webkit-overflow-scrolling:touch!important;
}
@media (max-width:768px){
    body,
    .content-area,
    .page,
    .page-container,
    .app-shell,
    .container,
    .card,
    .box,
    .section-card,
    .panel,
    .dashboard-card{
        background:#fff!important;
    }
    body::before{
        content:none!important;
        display:none!important;
        background:none!important;
    }
    .table-responsive,.table-container,.table-wrap{
        width:100%!important;
        max-width:100%!important;
        margin-left:auto!important;
        margin-right:auto!important;
        padding-left:0!important;
        padding-right:0!important;
        background:#fff!important;
        overflow-x:auto!important;
        overflow-y:visible!important;
        -webkit-overflow-scrolling:touch!important;
    }
    .table-responsive > table[data-no-responsive="1"],
    .table-container > table[data-no-responsive="1"],
    .table-wrap > table[data-no-responsive="1"]{
        width:max-content!important;
        max-width:none!important;
        min-width:max(720px,100%)!important;
    }
    table.app-responsive-table{
        min-width:0!important;
        width:100%!important;
        max-width:100%!important;
        margin-left:auto!important;
        margin-right:auto!important;
        display:block!important;
        background:transparent!important;
        border:0!important;
        box-shadow:none!important;
        border-collapse:separate!important;
        border-spacing:0!important;
        table-layout:fixed!important;
    }
    table.app-responsive-table thead{display:none!important}
    table.app-responsive-table tr.app-table-header-row{display:none!important}
    table.app-responsive-table tbody,
    table.app-responsive-table tr,
    table.app-responsive-table td{
        display:block!important;
    }
    table.app-responsive-table tbody{
        width:100%!important;
        max-width:100%!important;
        background:#fff!important;
    }
    table.app-responsive-table tr{
        box-sizing:border-box!important;
        width:calc(100% - 8px)!important;
        max-width:calc(100% - 8px)!important;
        margin:0 auto 14px!important;
        padding:14px!important;
        border:1px solid #dfe8e1!important;
        border-left:1px solid #dfe8e1!important;
        border-radius:10px!important;
        background:#fff!important;
        box-shadow:0 4px 12px rgba(15,23,42,.06)!important;
        overflow:hidden!important;
    }
    table.app-responsive-table tr.hidden{
        display:none!important;
    }
    table.app-responsive-table tr.app-mobile-child-row{
        margin:0 auto 14px!important;
        width:calc(100% - 8px)!important;
        max-width:calc(100% - 8px)!important;
        border-left-width:1px!important;
        border-left-color:#dfe8e1!important;
        box-shadow:0 4px 12px rgba(15,23,42,.05)!important;
        background:#fff!important;
    }
    table.app-responsive-table tr.app-status-ok{border-color:#dfe8e1!important}
    table.app-responsive-table tr.app-status-danger{border-color:#f0c7c7!important}
    table.app-responsive-table tr.app-card-collapsed td:not(.app-mobile-primary):not(.app-mobile-toggle-cell){display:none!important}
    table.app-responsive-table tr.app-mobile-child-row.app-card-collapsed td:not(.app-mobile-primary){display:block!important}
    table.app-responsive-table td{
        box-sizing:border-box!important;
        max-width:100%!important;
        width:100%!important;
        position:relative!important;
        left:auto!important;
        padding:9px 0!important;
        border:0!important;
        border-bottom:1px solid #edf2ee!important;
        background:transparent!important;
        text-align:left!important;
        white-space:normal!important;
        overflow-wrap:anywhere!important;
        word-break:break-word!important;
    }
    table.app-responsive-table td:last-child{border-bottom:0!important}
    table.app-responsive-table td::before{
        content:attr(data-label);
        display:block!important;
        margin-bottom:4px!important;
        color:#607066!important;
        font-size:11px!important;
        font-weight:800!important;
        letter-spacing:.02em!important;
        text-transform:uppercase!important;
    }
    table.app-responsive-table td.app-mobile-primary{
        font-size:15px!important;
        font-weight:700!important;
        color:#172018!important;
    }
    table.app-responsive-table td.app-mobile-primary::before{color:#1b5e20!important}
    table.app-responsive-table .acoes{
        justify-content:flex-start!important;
        flex-wrap:wrap!important;
        max-width:100%!important;
    }
    table.app-responsive-table input,
    table.app-responsive-table select,
    table.app-responsive-table textarea{
        max-width:100%!important;
        box-sizing:border-box!important;
    }
    table.app-responsive-table td.app-mobile-toggle-cell::before{
        display:none!important;
        content:''!important;
    }
    table.app-responsive-table td.app-mobile-toggle-cell{
        display:block!important;
        width:auto!important;
        max-width:100%!important;
        min-width:0!important;
        margin:0!important;
        padding:12px 0 0!important;
        border-bottom:0!important;
    }
    table.app-responsive-table td.app-mobile-toggle-cell > .app-row-details-btn{
        display:flex!important;
        align-items:center!important;
        justify-content:center!important;
        box-sizing:border-box!important;
        width:100%!important;
        max-width:100%!important;
        min-width:0!important;
        min-height:38px!important;
        height:auto!important;
        margin:8px 0 0!important;
        padding:9px 12px!important;
        border:1px solid #b7d1bc!important;
        border-radius:8px!important;
        background:#fff!important;
        color:#1b5e20!important;
        font-weight:800!important;
        cursor:pointer!important;
        line-height:1.2!important;
        white-space:normal!important;
        text-align:center!important;
    }
    .btn-toggle-os{
        min-width:42px!important;
        width:42px!important;
        min-height:42px!important;
        height:42px!important;
        display:inline-flex!important;
        align-items:center!important;
        justify-content:center!important;
        line-height:1!important;
    }
}
/* Header */
.app-header{background:#1b5e20;color:#fff;padding:15px 20px;border-bottom:3px solid #2e7d32}
.app-header-top{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}
.app-brand h1{margin:0;font-size:20px;font-weight:600}
.app-user{margin-top:5px;font-size:14px}
.app-user a{color:#fff;text-decoration:none;margin-left:8px;font-weight:600}
.app-page-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.app-btn-lite{background:#fff;color:#1b5e20;padding:7px 14px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;border:none;cursor:pointer}
.app-btn-lite:hover{background:#e8f5e9}

/* Navbar */
.app-navbar{
    background:#18531c;
    padding:6px 20px;
    display:flex;
    gap:2px;
    flex-wrap:wrap;
    align-items:center;
    position:relative;
    z-index:600;
}

/* Flat nav links */
.app-navbar > a{
    color:#fff;
    text-decoration:none;
    font-weight:600;
    font-size:14px;
    padding:8px 13px;
    border-radius:7px;
    white-space:nowrap;
    transition:background .15s;
}
.app-navbar > a:hover,
.app-navbar > a.active{background:rgba(255,255,255,.18)}

/* Dropdown wrapper */
.app-dropdown{position:relative}

.app-nav-btn{
    background:none;
    border:none;
    cursor:pointer;
    color:#fff;
    font-family:inherit;
    font-weight:600;
    font-size:14px;
    padding:8px 13px;
    border-radius:7px;
    display:inline-flex;
    align-items:center;
    gap:6px;
    white-space:nowrap;
    transition:background .15s;
    line-height:1;
}
.app-nav-btn:hover,
.app-nav-btn.active{background:rgba(255,255,255,.18)}

.app-caret{
    font-size:10px;
    opacity:.75;
    display:inline-block;
    transition:transform .2s ease;
}
.app-dropdown.open > .app-nav-btn .app-caret{transform:rotate(180deg)}

/* Dropdown panel */
.app-dropdown-menu{
    display:none;
    position:absolute;
    top:calc(100% + 7px);
    left:0;
    background:#fff;
    border-radius:11px;
    box-shadow:0 10px 32px rgba(0,0,0,.14),0 2px 8px rgba(0,0,0,.08);
    border:1px solid #e5e7eb;
    min-width:215px;
    z-index:1000;
    padding:6px;
}
.app-dropdown.open > .app-dropdown-menu{
    display:block;
    animation:appDropIn .17s ease forwards;
}
@keyframes appDropIn{
    from{opacity:0;transform:translateY(-6px)}
    to  {opacity:1;transform:translateY(0)}
}

.app-dropdown-menu a{
    display:block;
    padding:9px 13px;
    color:#1f2937;
    text-decoration:none;
    border-radius:7px;
    font-size:14px;
    font-weight:500;
    transition:background .12s,color .12s;
    white-space:nowrap;
}
.app-dropdown-menu a:hover{background:#e8f5e9;color:#1b5e20}
.app-dropdown-menu a.active{background:#e8f5e9;color:#1b5e20;font-weight:700}

/* Print */
@media print{
    .app-header,.app-navbar,.no-print{display:none!important}
    body{background:#fff!important}
}
@media print{
    .app-header,.app-navbar,.no-print,form,button,.btn,.btn-primary,.btn-secondary,
    .btn-danger,.btn-edit,.modal-bg,.modal-box,.link-row,.card form,.form-grid,
    .app-page-actions{display:none!important}
    body{background:#fff!important;color:#000!important}
    .container,.page{max-width:none!important;width:100%!important;padding:0!important;margin:0!important}
    .card,.box,.section-card{box-shadow:none!important;border:0!important;margin:0 0 16px!important;padding:0!important;background:#fff!important}
    .table-container,.table-wrap{overflow:visible!important}
    table{width:100%!important;font-size:12px!important}
    th,td{padding:6px 8px!important;color:#000!important}
    table th:last-child,table td:last-child{display:none!important}
    input,select,textarea{display:none!important}
}

/* Mobile */
@media (max-width:768px){
    .app-header-top{align-items:flex-start}
    .app-page-actions{width:100%}
    .app-btn-lite{width:100%;text-align:center;justify-content:center}

    .app-navbar{
        flex-direction:column;
        align-items:stretch;
        gap:2px;
        padding:8px 14px 10px;
    }
    .app-navbar > a{display:block}
    .app-dropdown{width:100%}
    .app-nav-btn{width:100%;justify-content:space-between;box-sizing:border-box}

    /* On mobile the panel is inline, not floating */
    .app-dropdown-menu{
        position:static;
        box-shadow:none;
        border:none;
        border-radius:0;
        background:rgba(0,0,0,.18);
        padding:4px 0 4px 10px;
        margin-top:2px;
        animation:none;
    }
    .app-dropdown.open > .app-dropdown-menu{display:block}
    .app-dropdown-menu a{color:#fff;font-size:13px;padding:8px 10px;border-radius:5px}
    .app-dropdown-menu a:hover,
    .app-dropdown-menu a.active{background:rgba(255,255,255,.18);color:#fff}
}
</style>
CSS;
    echo <<<'CSS'
<style>
.app-header{
    background:linear-gradient(135deg,#14532d 0%,#166534 58%,#1f7a3b 100%)!important;
    color:#fff!important;
    padding:18px 32px!important;
    border-bottom:1px solid rgba(255,255,255,.16)!important;
    box-shadow:0 10px 28px rgba(15,23,42,.14)!important;
}
.app-header-top{max-width:1440px;margin:0 auto;align-items:center!important}
.app-brand h1{font-size:21px!important;letter-spacing:0!important;font-weight:750!important}
.app-user{color:rgba(255,255,255,.84)!important;font-size:13px!important}
.app-user a{color:#fff!important;text-decoration:none!important;border-bottom:1px solid rgba(255,255,255,.35)!important}
.app-page-actions{gap:8px!important}
.app-btn-lite{
    display:inline-flex!important;
    align-items:center!important;
    gap:8px!important;
    background:rgba(255,255,255,.12)!important;
    color:#fff!important;
    border:1px solid rgba(255,255,255,.22)!important;
    border-radius:9px!important;
    padding:8px 13px!important;
    min-height:38px!important;
    box-shadow:none!important;
}
.app-btn-lite:hover{background:rgba(255,255,255,.2)!important;color:#fff!important}
.app-navbar{
    background:#0f3d25!important;
    padding:9px 32px!important;
    gap:4px!important;
    box-shadow:0 12px 24px rgba(15,23,42,.08)!important;
}
.app-navbar::before{
    content:"";
    width:min(100%,1440px);
    height:0;
    order:-2;
}
.app-navbar > a,.app-nav-btn{
    display:inline-flex!important;
    align-items:center!important;
    gap:8px!important;
    min-height:38px!important;
    padding:8px 12px!important;
    border-radius:9px!important;
    color:rgba(255,255,255,.9)!important;
    font-size:14px!important;
    font-weight:650!important;
    letter-spacing:0!important;
}
.app-navbar > a:hover,.app-navbar > a.active,.app-nav-btn:hover,.app-nav-btn.active{
    background:rgba(255,255,255,.13)!important;
    color:#fff!important;
}
.app-dropdown-menu{
    border-radius:10px!important;
    border:1px solid var(--color-border)!important;
    box-shadow:var(--shadow-soft)!important;
    padding:8px!important;
}
.app-dropdown-menu a{
    display:flex!important;
    align-items:center!important;
    gap:9px!important;
    padding:10px 12px!important;
    border-radius:8px!important;
    color:var(--color-text)!important;
}
.app-dropdown-menu a:hover,.app-dropdown-menu a.active{
    background:var(--color-primary-soft)!important;
    color:var(--color-primary-dark)!important;
}
@media (max-width:768px){
    .app-header{padding:16px!important}
    .app-navbar{padding:10px 16px!important}
}
</style>
CSS;
    echo <<<'CSS'
<style>
body{
    padding-left:296px!important;
    padding-top:92px!important;
    background:#f7f8fa!important;
}
.app-header{
    position:fixed!important;
    top:0!important;
    left:296px!important;
    right:0!important;
    height:92px!important;
    z-index:900!important;
    background:#fff!important;
    color:#1f2937!important;
    padding:0 24px!important;
    border-bottom:1px solid #e5e7eb!important;
    box-shadow:none!important;
}
.app-header-top{
    height:92px!important;
    max-width:none!important;
    margin:0!important;
    display:flex!important;
    align-items:center!important;
    justify-content:space-between!important;
}
.app-header .app-brand h1{display:none!important}
.app-header .app-user{
    margin:0!important;
    color:#334155!important;
    font-size:13px!important;
    font-weight:500!important;
}
.app-header .app-user::before{content:"Usuário: ";font-weight:700;color:#0f172a}
.app-header .app-user a{
    color:#166534!important;
    border:0!important;
    margin-left:10px!important;
    font-weight:800!important;
}
.app-page-actions{
    display:flex!important;
    align-items:center!important;
    justify-content:flex-end!important;
    gap:14px!important;
    flex-wrap:nowrap!important;
    min-width:0!important;
}
.app-btn-lite{
    background:#fff!important;
    color:#166534!important;
    border:1px solid #dfe7e2!important;
    border-radius:8px!important;
    min-height:38px!important;
    padding:8px 12px!important;
    font-weight:800!important;
}
.app-btn-lite:hover{background:#ecfdf3!important;color:#14532d!important}
.app-navbar{
    position:fixed!important;
    inset:0 auto 0 0!important;
    width:296px!important;
    height:100vh!important;
    z-index:950!important;
    background:#fff!important;
    border-right:1px solid #e5e7eb!important;
    box-shadow:none!important;
    padding:0 16px 24px!important;
    display:flex!important;
    flex-direction:column!important;
    align-items:stretch!important;
    gap:6px!important;
    overflow-y:auto!important;
    flex-wrap:nowrap!important;
}
.app-navbar::before{display:none!important}
.app-sidebar-brand{
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
    height:92px!important;
    margin:0 -16px 10px!important;
    padding:10px 20px!important;
    border-bottom:1px solid #e5e7eb!important;
    background:linear-gradient(180deg,#ffffff 0%,#fbfdf8 100%)!important;
}
.app-sidebar-brand img{
    display:block!important;
    width:100%!important;
    max-width:150px!important;
    max-height:62px!important;
    object-fit:contain!important;
}
.app-navbar > a,.app-nav-btn{
    width:100%!important;
    justify-content:flex-start!important;
    min-height:36px!important;
    padding:9px 12px!important;
    border-radius:9px!important;
    color:#253247!important;
    background:transparent!important;
    font-size:14px!important;
    font-weight:700!important;
    text-align:left!important;
    box-shadow:none!important;
}
.app-nav-btn{justify-content:space-between!important}
.app-navbar > a i,.app-nav-btn i{
    width:20px!important;
    color:#475569!important;
    font-size:17px!important;
}
.app-navbar > a:hover,.app-nav-btn:hover{
    background:#f1f5f9!important;
    color:#0f172a!important;
}
.app-navbar > a.active,.app-nav-btn.active{
    background:#ecfdf3!important;
    color:#008f46!important;
}
.app-navbar > a.active i,.app-nav-btn.active i{
    color:#009f4d!important;
}
.app-dropdown{width:100%!important;position:relative!important}
.app-dropdown-menu{
    position:static!important;
    min-width:0!important;
    width:100%!important;
    margin:2px 0 6px!important;
    padding:2px 0 2px 30px!important;
    background:transparent!important;
    border:0!important;
    border-radius:0!important;
    box-shadow:none!important;
    animation:none!important;
}
.app-dropdown-menu a{
    color:#475569!important;
    min-height:34px!important;
    padding:8px 10px!important;
    border-radius:8px!important;
    font-size:13px!important;
    font-weight:650!important;
}
.app-dropdown-menu a:hover,.app-dropdown-menu a.active{
    background:#ecfdf3!important;
    color:#008f46!important;
}
.app-caret{margin-left:auto!important;color:rgba(255,255,255,.45)!important;font-size:13px!important}
.container,.page-container,.app-shell{
    width:100%!important;
    max-width:none!important;
    padding:28px 32px 44px!important;
}
.app-header .app-user::before{
    content:"\F4D7"!important;
    font-family:"bootstrap-icons"!important;
    font-weight:400!important;
    color:#166534!important;
    margin-right:7px!important;
    font-size:16px!important;
    vertical-align:-2px!important;
}
.app-header{
    left:264px!important;
    height:78px!important;
    padding:0 32px!important;
    background:#fff!important;
}
body{
    padding-left:264px!important;
    padding-top:78px!important;
    background:#f7f7f5!important;
}
.app-header-top{
    height:78px!important;
}
.app-navbar{
    width:264px!important;
    background:#1f1c19!important;
    border-right:0!important;
    padding:0 16px 24px!important;
}
.app-sidebar-brand{
    height:78px!important;
    margin:0 -16px 18px!important;
    padding:10px 24px!important;
    justify-content:flex-start!important;
    background:#1f1c19!important;
    border-bottom:1px solid rgba(255,255,255,.08)!important;
}
.app-sidebar-brand img{
    max-width:116px!important;
    max-height:54px!important;
    filter:none!important;
}
.app-navbar > a,.app-nav-btn{
    color:#f5f2ed!important;
    min-height:48px!important;
    padding:12px 16px!important;
    border-radius:8px!important;
    font-size:15px!important;
    font-weight:750!important;
}
.app-navbar > a i,.app-nav-btn i{
    color:#f5f2ed!important;
    font-size:18px!important;
}
.app-navbar > a:hover,.app-nav-btn:hover{
    background:rgba(255,255,255,.08)!important;
    color:#fff!important;
}
.app-navbar > a.active,.app-nav-btn.active{
    background:#128a40!important;
    color:#fff!important;
}
.app-navbar > a.active i,.app-nav-btn.active i{
    color:#fff!important;
}
.app-dropdown-menu{
    padding-left:32px!important;
}
.app-dropdown-menu a{
    color:#d7d2ca!important;
}
.app-dropdown-menu a:hover,.app-dropdown-menu a.active{
    background:rgba(18,138,64,.18)!important;
    color:#fff!important;
}
.app-search{
    width:min(448px,45vw)!important;
    position:relative!important;
}
.app-search i{
    position:absolute!important;
    left:15px!important;
    top:50%!important;
    transform:translateY(-50%)!important;
    color:#9ca3af!important;
    font-size:18px!important;
}
.app-search input{
    width:100%!important;
    min-height:36px!important;
    border:0!important;
    border-radius:8px!important;
    background:#f2f2f1!important;
    padding:8px 14px 8px 44px!important;
    color:#334155!important;
    font-size:14px!important;
}
.app-search-results{
    display:none!important;
    position:absolute!important;
    top:calc(100% + 8px)!important;
    left:0!important;
    right:0!important;
    z-index:1200!important;
    background:#fff!important;
    border:1px solid #e5e7eb!important;
    border-radius:10px!important;
    box-shadow:0 16px 34px rgba(15,23,42,.16)!important;
    padding:6px!important;
    max-height:360px!important;
    overflow:auto!important;
}
.app-search-results.open{display:block!important}
.app-search-result{
    display:grid!important;
    grid-template-columns:28px 1fr!important;
    gap:10px!important;
    align-items:center!important;
    width:100%!important;
    border:0!important;
    background:#fff!important;
    color:#1f2937!important;
    text-decoration:none!important;
    padding:10px!important;
    border-radius:8px!important;
    cursor:pointer!important;
    text-align:left!important;
    font:inherit!important;
}
.app-search-result:hover,.app-search-result.active{background:#ecfdf3!important;color:#14532d!important}
.app-search-result i{
    position:static!important;
    transform:none!important;
    color:#128a40!important;
    font-size:18px!important;
}
.app-search-result strong{display:block!important;font-size:14px!important;line-height:1.2!important}
.app-search-result span{display:block!important;margin-top:3px!important;color:#64748b!important;font-size:12px!important;line-height:1.35!important}
.app-search-empty{padding:12px!important;color:#64748b!important;font-size:13px!important}
.app-page-actions > a.app-btn-lite{
    display:none!important;
}
.app-btn-lite{
    border:0!important;
    background:#2e7d32!important;
    color:#fff!important;
    width:38px!important;
    height:38px!important;
    min-width:38px!important;
    min-height:38px!important;
    padding:0!important;
    border-radius:8px!important;
    font-size:0!important;
    gap:0!important;
    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;
    flex:0 0 auto!important;
}
.app-btn-lite:hover{background:#1b5e20!important;color:#fff!important}
.app-btn-lite i{font-size:19px!important;line-height:1!important}
.app-user-menu{
    position:relative!important;
    margin:0!important;
    flex:0 0 auto!important;
}
.app-user-menu summary{
    list-style:none!important;
}
.app-user-menu summary::-webkit-details-marker{
    display:none!important;
}
.app-user{
    display:grid!important;
    grid-template-columns:auto 1fr!important;
    column-gap:10px!important;
    align-items:center!important;
    color:#1f2937!important;
    min-width:128px!important;
    padding-right:18px!important;
    cursor:pointer!important;
    position:relative!important;
}
.app-user::after{
    content:"\F282"!important;
    font-family:"bootstrap-icons"!important;
    position:absolute!important;
    right:0!important;
    top:50%!important;
    transform:translateY(-50%)!important;
    color:#64748b!important;
    font-size:12px!important;
}
.app-user::before{
    grid-row:1 / span 2!important;
    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;
    width:38px!important;
    height:38px!important;
    border-radius:999px!important;
    background:#128a40!important;
    color:#fff!important;
    margin:0!important;
}
.app-user-name{
    font-weight:850!important;
    line-height:1.1!important;
}
.app-user-role{
    color:#64748b!important;
    font-size:12px!important;
    text-transform:capitalize!important;
}
.app-user a{
    display:none!important;
}
.app-user-dropdown{
    position:absolute!important;
    right:0!important;
    top:calc(100% + 10px)!important;
    min-width:132px!important;
    background:#fff!important;
    border:1px solid #e5e7eb!important;
    border-radius:10px!important;
    box-shadow:0 16px 34px rgba(15,23,42,.16)!important;
    padding:6px!important;
    z-index:1300!important;
}
.app-user-dropdown a{
    display:flex!important;
    align-items:center!important;
    gap:8px!important;
    color:#1f2937!important;
    text-decoration:none!important;
    padding:9px 10px!important;
    border-radius:8px!important;
    font-size:13px!important;
    font-weight:700!important;
}
.app-user-dropdown a:hover{
    background:#ffebee!important;
    color:#c62828!important;
}
.app-mobile-toggle,.app-sidebar-overlay,.app-toast-region,.app-dialog-overlay{display:none}
@media (min-width:769px){
    .app-mobile-toggle{display:none!important}
}
.app-toast-region{
    position:fixed!important;
    top:94px!important;
    right:24px!important;
    z-index:2200!important;
    width:min(380px,calc(100vw - 32px))!important;
    gap:10px!important;
    flex-direction:column!important;
    pointer-events:none!important;
}
.app-toast-region.open{display:flex!important}
.app-toast{
    display:grid!important;
    grid-template-columns:28px 1fr auto!important;
    align-items:start!important;
    gap:10px!important;
    background:#fff!important;
    color:#1f2937!important;
    border:1px solid #dfe7e2!important;
    border-left:4px solid #128a40!important;
    border-radius:10px!important;
    box-shadow:0 18px 44px rgba(15,23,42,.18)!important;
    padding:13px 12px!important;
    pointer-events:auto!important;
}
.app-toast.error{border-left-color:#c62828!important}
.app-toast i{color:#128a40!important;font-size:18px!important;margin-top:1px!important}
.app-toast.error i{color:#c62828!important}
.app-toast strong{display:block!important;font-size:14px!important;line-height:1.2!important;margin-bottom:3px!important}
.app-toast span{display:block!important;font-size:13px!important;line-height:1.35!important;color:#475569!important}
.app-toast button{
    width:28px!important;
    height:28px!important;
    border:0!important;
    border-radius:7px!important;
    background:#f1f5f9!important;
    color:#334155!important;
    cursor:pointer!important;
}
.app-dialog-overlay{
    position:fixed!important;
    inset:0!important;
    z-index:2400!important;
    align-items:center!important;
    justify-content:center!important;
    padding:18px!important;
    background:rgba(15,23,42,.48)!important;
    backdrop-filter:blur(3px)!important;
}
.app-dialog-overlay.open{display:flex!important}
.app-dialog{
    width:min(430px,100%)!important;
    background:#fff!important;
    color:#1f2937!important;
    border-radius:12px!important;
    box-shadow:0 24px 70px rgba(15,23,42,.28)!important;
    padding:20px!important;
}
.app-dialog-head{display:flex!important;gap:12px!important;align-items:flex-start!important;margin-bottom:10px!important}
.app-dialog-icon{
    width:38px!important;
    height:38px!important;
    border-radius:10px!important;
    background:#ecfdf3!important;
    color:#128a40!important;
    display:inline-flex!important;
    align-items:center!important;
    justify-content:center!important;
    flex:0 0 auto!important;
    font-size:20px!important;
}
.app-dialog.danger .app-dialog-icon{background:#ffebee!important;color:#c62828!important}
.app-dialog h3{margin:0!important;font-size:18px!important;line-height:1.25!important;color:#0f172a!important}
.app-dialog p{margin:5px 0 0!important;font-size:14px!important;line-height:1.45!important;color:#475569!important}
.app-dialog-actions{display:flex!important;justify-content:flex-end!important;gap:10px!important;margin-top:18px!important}
.app-dialog-btn{
    min-height:38px!important;
    padding:8px 14px!important;
    border-radius:8px!important;
    border:1px solid #dfe7e2!important;
    cursor:pointer!important;
    font-weight:800!important;
}
.app-dialog-cancel{background:#fff!important;color:#334155!important}
.app-dialog-confirm{background:#128a40!important;color:#fff!important;border-color:#128a40!important}
.app-dialog.danger .app-dialog-confirm{background:#c62828!important;border-color:#c62828!important}
@media (max-width:900px){
    html,body{
        width:100%!important;
        max-width:100%!important;
        overflow-x:hidden!important;
    }
    body{padding-left:0!important;padding-top:56px!important}
    .app-header{
        position:fixed!important;
        top:0!important;
        left:0!important;
        right:0!important;
        width:100%!important;
        height:56px!important;
        min-height:56px!important;
        padding:0 14px!important;
        z-index:1200!important;
        background:#fff!important;
        border-bottom:1px solid #e5e7eb!important;
        box-shadow:0 4px 14px rgba(15,23,42,.08)!important;
    }
    .app-header-top{
        width:100%!important;
        max-width:none!important;
        height:56px!important;
        gap:6px!important;
        align-items:center!important;
        justify-content:space-between!important;
        flex-wrap:nowrap!important;
        margin:0!important;
    }
    .app-brand{
        flex:1 1 auto!important;
        min-width:0!important;
        display:flex!important;
        align-items:center!important;
        overflow:hidden!important;
    }
    .app-brand::after{
        content:"AgroColitti Gestao";
        color:#1f2937!important;
        font-weight:850!important;
        font-size:15px!important;
        line-height:1!important;
        white-space:nowrap!important;
        overflow:hidden!important;
        text-overflow:ellipsis!important;
    }
    .app-search,.app-search-results{display:none!important}
    /* Posiciona user+sair absolutamente dentro do header fixo */
    .app-page-actions{
        position:absolute!important;
        right:14px!important;
        top:0!important;
        height:56px!important;
        display:flex!important;
        align-items:center!important;
        gap:6px!important;
        width:auto!important;
        flex-wrap:nowrap!important;
        z-index:10!important;
    }
    .app-page-actions>a.app-btn-lite,.app-page-actions>button.app-btn-lite{display:none!important}
    .app-brand{padding-right:160px!important}
    .app-user-menu{display:flex!important;flex-direction:row!important;align-items:center!important;gap:6px!important}
    .app-user{display:flex!important;flex-direction:column!important;align-items:flex-end!important;min-width:0!important;padding-right:0!important;cursor:default!important;grid-template-columns:none!important}
    .app-user::before,.app-user::after{display:none!important}
    .app-user-name{font-size:12px!important;font-weight:700!important;color:#111827!important;line-height:1.1!important;white-space:nowrap!important}
    .app-user-role{font-size:10px!important;color:#6b7280!important;line-height:1.1!important;white-space:nowrap!important}
    .app-user-dropdown{position:static!important;top:auto!important;right:auto!important;background:none!important;border:none!important;box-shadow:none!important;padding:0!important;min-width:0!important}
    .app-user-dropdown a{display:flex!important;align-items:center!important;gap:4px!important;background:#fee2e2!important;color:#b91c1c!important;border-radius:8px!important;padding:5px 9px!important;font-size:12px!important;font-weight:700!important;white-space:nowrap!important;text-decoration:none!important}
    .app-navbar{
        position:fixed!important;
        top:0!important;
        bottom:0!important;
        left:0!important;
        width:min(292px,86vw)!important;
        height:100vh!important;
        max-height:100vh!important;
        padding:0 16px 24px!important;
        border-right:0!important;
        border-bottom:0!important;
        z-index:1250!important;
        transform:translateX(-102%)!important;
        transition:transform .22s ease!important;
        box-shadow:18px 0 42px rgba(0,0,0,.28)!important;
    }
    body.app-nav-open .app-navbar{transform:translateX(0)!important}
    .app-sidebar-overlay{
        position:fixed!important;
        inset:0!important;
        z-index:1230!important;
        background:rgba(15,23,42,.44)!important;
    }
    body.app-nav-open .app-sidebar-overlay{display:block!important}
    /* Hide desktop sidebar toggle on mobile - hamburger is the only control */
    #appSidebarTopToggle,.app-sidebar-top-toggle{display:none!important}
    .app-sidebar-toggle{display:none!important}
    .app-mobile-toggle{
        display:inline-flex!important;
        align-items:center!important;
        justify-content:center!important;
        width:38px!important;
        height:38px!important;
        border:0!important;
        border-radius:8px!important;
        background:#128a40!important;
        color:#fff!important;
        font-size:20px!important;
        cursor:pointer!important;
        flex:0 0 auto!important;
        position:relative!important;
        z-index:50!important;
    }
    .app-brand{pointer-events:none!important}
    .app-sidebar-brand{margin:0 -16px 8px!important}
    .app-sidebar-brand img{max-width:138px!important;max-height:56px!important}
    .container,.page-container,.app-shell,.page{
        width:100%!important;
        max-width:100%!important;
        padding:16px!important;
        margin-left:0!important;
        margin-right:0!important;
        overflow-x:hidden!important;
    }
    .table-responsive,.table-container,.table-wrap{
        overflow-x:auto!important;
        overflow-y:visible!important;
        -webkit-overflow-scrolling:touch!important;
    }
    .table-responsive:has(> table[data-no-responsive="1"])::before,
    .table-container:has(> table[data-no-responsive="1"])::before,
    .table-wrap:has(> table[data-no-responsive="1"])::before{
        content:"";
        position:absolute;
        top:0;
        left:0;
        display:block;
        width:112px;
        height:5px;
        border-radius:999px;
        background:linear-gradient(90deg,rgba(20,99,42,.24),rgba(47,154,72,.12));
    }
    .table-responsive:has(> table[data-no-responsive="1"])::after,
    .table-container:has(> table[data-no-responsive="1"])::after,
    .table-wrap:has(> table[data-no-responsive="1"])::after{
        content:"";
        position:absolute;
        top:0;
        left:var(--app-scroll-hint-offset,0px);
        display:block;
        width:38px;
        height:5px;
        border-radius:999px;
        background:linear-gradient(90deg,#14632a,#2f9a48);
        box-shadow:0 0 10px rgba(47,154,72,.38);
        animation:appScrollHintNudge 1.6s ease-in-out infinite;
        pointer-events:none;
    }
    .table-responsive:has(> table[data-no-responsive="1"]),
    .table-container:has(> table[data-no-responsive="1"]),
    .table-wrap:has(> table[data-no-responsive="1"]){
        position:relative!important;
        padding-top:13px!important;
        background:
            linear-gradient(90deg,rgba(255,255,255,0),rgba(255,255,255,.92) 72%,#fff) right top/34px 100% no-repeat,
            #fff!important;
    }
    .table-responsive.app-scroll-hint-touched::after,
    .table-container.app-scroll-hint-touched::after,
    .table-wrap.app-scroll-hint-touched::after{
        animation:none;
    }
    @keyframes appScrollHintNudge{
        0%,100%{transform:translateX(0)}
        45%{transform:translateX(34px)}
    }
    @media (prefers-reduced-motion:reduce){
        .table-responsive:has(> table[data-no-responsive="1"])::after,
        .table-container:has(> table[data-no-responsive="1"])::after,
        .table-wrap:has(> table[data-no-responsive="1"])::after{
            animation:none!important;
        }
    }
    .table-responsive > table[data-no-responsive="1"],
    .table-container > table[data-no-responsive="1"],
    .table-wrap > table[data-no-responsive="1"]{
        width:max-content!important;
        max-width:none!important;
        min-width:max(720px,100%)!important;
    }
    .card,.box,.section-card,.panel,.dashboard-card{
        max-width:100%!important;
        overflow-x:hidden!important;
    }
    table{max-width:100%!important}
    .app-toast-region{top:68px!important;right:12px!important;left:12px!important;width:auto!important}
    .app-dialog-overlay,
    .modal-bg,
    .modal-backdrop{
        position:fixed!important;
        inset:0!important;
        width:100vw!important;
        height:100dvh!important;
        max-height:100dvh!important;
        overflow-y:auto!important;
        overscroll-behavior:contain!important;
        align-items:center!important;
        justify-content:center!important;
        padding:16px!important;
        z-index:5000!important;
    }
    .app-dialog,
    .modal-box,
    .modal-content{
        position:relative!important;
        top:auto!important;
        left:auto!important;
        right:auto!important;
        bottom:auto!important;
        transform:none!important;
        margin:auto!important;
        max-height:calc(100dvh - 32px)!important;
        overflow-y:auto!important;
        -webkit-overflow-scrolling:touch!important;
    }
    .app-dialog{padding:18px!important}
}
</style>
CSS;

    echo <<<'SBCSS'
<style>
/* ===== Sidebar 3-state ===== */
:root{--sb-w:clamp(220px,18vw,264px)}
body.sidebar-collapsed{--sb-w:64px}
body.sidebar-hidden{--sb-w:0px}

body{
    padding-left:var(--sb-w)!important;
    transition:padding-left 200ms ease!important;
}
.app-header{
    left:var(--sb-w)!important;
    transition:left 200ms ease!important;
}
.app-navbar{
    width:var(--sb-w)!important;
    transition:width 200ms ease!important;
    overflow-x:hidden!important;
    overflow-y:auto!important;
    scrollbar-width:thin;
    scrollbar-color:rgba(255,255,255,.26) transparent;
}
.app-navbar::-webkit-scrollbar{width:6px}
.app-navbar::-webkit-scrollbar-track{background:transparent}
.app-navbar::-webkit-scrollbar-thumb{background:rgba(255,255,255,.24);border-radius:6px}
.app-navbar::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.38)}
body.sidebar-resizing,
body.sidebar-resizing .app-header,
body.sidebar-resizing .app-navbar{
    transition:none!important;
    cursor:col-resize!important;
    user-select:none!important;
}
.app-sidebar-resizer{
    display:block!important;
    position:fixed!important;
    top:0!important;
    bottom:auto!important;
    left:calc(var(--sb-w) - 8px)!important;
    width:16px!important;
    height:100vh!important;
    height:100dvh!important;
    min-height:100vh!important;
    border:0!important;
    padding:0!important;
    margin:0!important;
    background:transparent!important;
    cursor:col-resize!important;
    z-index:1260!important;
    touch-action:none!important;
    pointer-events:auto!important;
}
.app-sidebar-resizer::after{
    content:"";
    position:absolute;
    top:0;
    bottom:0;
    left:7px;
    height:auto;
    transform:none;
    width:2px;
    background:rgba(255,255,255,.08);
    transition:background 150ms ease;
}
.app-sidebar-resizer:hover::after,
.app-sidebar-resizer:focus-visible::after,
body.sidebar-resizing .app-sidebar-resizer::after{
    background:#20a352;
}
.app-sidebar-resizer:focus-visible{outline:none}
body.sidebar-collapsed .app-sidebar-resizer,
body.sidebar-hidden .app-sidebar-resizer{display:none!important}

/* Monitores baixos: diminui a altura consumida sem retirar opcoes do alcance. */
@media (min-width:901px) and (max-height:760px){
    .app-navbar{gap:3px!important;padding-bottom:10px!important}
    .app-sidebar-brand{height:58px!important;margin-bottom:8px!important;padding:6px 20px!important}
    .app-sidebar-brand img{max-height:42px!important}
    .app-navbar > a,.app-nav-btn{min-height:38px!important;padding:8px 14px!important;font-size:14px!important}
    .app-dropdown-menu{padding-left:26px!important;margin-bottom:2px!important}
    .app-dropdown-menu a{min-height:30px!important;padding:6px 8px!important;font-size:12px!important}
    .app-sidebar-top-toggle{margin-top:6px!important;margin-bottom:2px!important}
}
@media (min-width:901px) and (max-height:620px){
    .app-sidebar-brand{height:48px!important;margin-bottom:4px!important}
    .app-sidebar-brand img{max-height:35px!important}
    .app-navbar > a,.app-nav-btn{min-height:34px!important;padding:6px 12px!important}
    .app-dropdown-menu a{min-height:27px!important;padding:5px 8px!important}
}

/* Dropdown buttons: flex-start now that caret is gone */
.app-navbar .app-nav-btn{justify-content:space-between!important;gap:10px!important}

/* Collapsed: hide labels, center items */
body.sidebar-collapsed .sb-label{display:none!important}
body.sidebar-collapsed .app-sidebar-brand{display:none!important}
body.sidebar-collapsed .app-navbar a,
body.sidebar-collapsed .app-navbar .app-nav-btn{
    justify-content:center!important;
    padding-left:0!important;
    padding-right:0!important;
}
body.sidebar-collapsed .app-navbar .app-caret{display:none!important}
body.sidebar-collapsed .app-dropdown-menu{display:none!important}
/* Flyout lateral quando sidebar recolhida */
body.sidebar-collapsed .app-dropdown.flyout-open>.app-dropdown-menu{display:block!important;position:fixed!important;left:calc(var(--sb-w) + 6px)!important;top:0;width:220px!important;background:linear-gradient(160deg,#14532d,#1f7a3b)!important;border:1px solid rgba(255,255,255,.2)!important;border-radius:10px!important;box-shadow:6px 4px 24px rgba(0,0,0,.55)!important;z-index:9999!important;padding:4px 0!important}
body.sidebar-collapsed .app-dropdown.flyout-open>.app-dropdown-menu a{color:rgba(255,255,255,.9)!important;font-size:13px!important;padding:10px 14px!important;border-radius:0!important;border-bottom:1px solid rgba(255,255,255,.07)!important}
body.sidebar-collapsed .app-dropdown.flyout-open>.app-dropdown-menu a:last-child{border-bottom:none!important}
body.sidebar-collapsed .app-dropdown.flyout-open>.app-dropdown-menu a:hover,body.sidebar-collapsed .app-dropdown.flyout-open>.app-dropdown-menu a.active{background:rgba(255,255,255,.18)!important;color:#fff!important}

/* Hidden */
body.sidebar-hidden .app-navbar{
    transform:translateX(-100%)!important;
    pointer-events:none!important;
}
body.sidebar-hidden .app-sidebar-restore{
    display:flex!important;
}

/* Toggle button (inside sidebar, bottom) */
.app-sidebar-toggle{
    display:flex;
    align-items:center;
    gap:8px;
    width:100%;
    padding:14px 18px;
    background:rgba(255,255,255,.06);
    border:none;
    border-top:1px solid rgba(255,255,255,.08);
    color:rgba(255,255,255,.55);
    font-size:.82rem;
    cursor:pointer;
    transition:background 150ms;
    margin-top:auto;
}
.app-sidebar-toggle:hover{background:rgba(255,255,255,.12);color:#fff}
.app-sidebar-toggle i{font-size:1.1rem;flex-shrink:0}
body.sidebar-collapsed .app-sidebar-toggle .sb-label{display:none!important}
body.sidebar-collapsed .app-sidebar-toggle i{transform:rotate(180deg)}

/* Restore button (floating, shown only when sidebar-hidden) */
.app-sidebar-restore{
    display:none;
    position:fixed;
    top:50%;
    left:0;
    transform:translateY(-50%);
    z-index:1100;
    align-items:center;
    justify-content:center;
    width:28px;
    height:48px;
    background:#1f1c19;
    border:1px solid rgba(255,255,255,.15);
    border-left:none;
    border-radius:0 8px 8px 0;
    color:rgba(255,255,255,.7);
    cursor:pointer;
    transition:background 150ms,color 150ms;
}
.app-sidebar-restore:hover{background:#2d2a26;color:#fff}

/* Top toggle (compact icon at top of sidebar) */
.app-sidebar-top-toggle{
    display:flex;
    align-items:center;
    justify-content:center;
    align-self:flex-end;
    width:28px;
    height:28px;
    margin:10px 8px 0;
    background:transparent;
    border:1px solid rgba(255,255,255,.20);
    border-radius:6px;
    color:#f5f2ed;
    font-size:.95rem;
    cursor:pointer;
    transition:background 150ms,color 150ms,border-color 150ms;
    padding:0;
    flex-shrink:0;
}
.app-sidebar-top-toggle:hover{background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.4)}
body.sidebar-collapsed .app-sidebar-top-toggle{align-self:center;margin-left:auto;margin-right:auto;}
body.sidebar-collapsed .app-sidebar-top-toggle i{transform:scaleX(-1)}
body.sidebar-hidden .app-sidebar-top-toggle{display:none!important}
/* Mobile */
@media(max-width:900px){
    :root{--sb-w:0px}
    body{--sb-w:0px!important}
    body.app-nav-open .app-navbar{
        width:min(292px,86vw)!important;
        transform:translateX(0)!important;
        pointer-events:auto!important;
    }
    body.app-nav-open .app-sidebar-overlay{display:block!important}
    body.app-nav-open.sidebar-hidden .app-navbar,
    body.app-nav-open.sidebar-collapsed .app-navbar{
        width:min(292px,86vw)!important;
        transform:translateX(0)!important;
        pointer-events:auto!important;
    }
    body.sidebar-collapsed .sb-label{display:inline!important}
    body.sidebar-collapsed .app-sidebar-brand{display:block!important}
    body.sidebar-collapsed .app-navbar a,
    body.sidebar-collapsed .app-navbar .app-nav-btn{
        justify-content:flex-start!important;
        padding-left:14px!important;
        padding-right:14px!important;
    }
    body.sidebar-collapsed .app-dropdown-menu{display:none}
    body.sidebar-collapsed .app-dropdown.open > .app-dropdown-menu{display:block!important}
    .app-sidebar-restore{display:none!important}
    body.sidebar-hidden .app-sidebar-restore{display:none!important}
    /* Desktop-only toggle: hidden on mobile (LAST RULE - overwrites base display:flex) */
    #appSidebarTopToggle,.app-sidebar-top-toggle,.app-sidebar-toggle,.app-sidebar-resizer{display:none!important}
}
</style>
SBCSS;


    echo '<style>
/* M1 — Navbar overlap fix 561px–720px
   .app-page-actions uses position:absolute with a 160px padding-right hack on brand.
   At 561-720px the user-name + sair button exceed that reserve and overlap.
   Fix: switch to normal flex item for the entire above-560px mobile range. */
@media (min-width:561px) and (max-width:900px){
    .app-page-actions{
        position:static!important;
        height:auto!important;
        width:auto!important;
        flex-shrink:0!important;
        top:auto!important;
        right:auto!important;
    }
    .app-brand{
        padding-right:0!important;
    }
}
</style>';
    $usuarioId = (string)($_SESSION['usuario_id'] ?? '');
    echo '<script>window.AGROCOLITTI_CURRENT_USER_ID=' . json_encode($usuarioId) . ';window.AGROCOLITTI_APP_ROOT=' . json_encode($appRoot) . ';</script>';
    if ($tabelasSomenteVisualizacao) {
        echo <<<'HTML'
<style>
.btn-salvar,
.add-produto-btn,
.btn-add-cliente-sec,
.btn-remover-col,
.produto-modal-salvar,
table .btn-edit,
table .btn-danger {
    display:none!important;
}
table input:disabled,
table select:disabled,
table textarea:disabled {
    background:#f7f7f7!important;
    color:#333!important;
    border-color:#ddd!important;
    cursor:default!important;
    opacity:1!important;
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('option[value="criar_tabela_personalizada.php"], option[value="tabelas_personalizadas.php"]').forEach(function (option) {
        option.remove();
    });
    document.querySelectorAll('table input, table select, table textarea').forEach(function (field) {
        field.disabled = true;
        field.setAttribute('aria-readonly', 'true');
    });
    document.querySelectorAll('.btn-salvar, .add-produto-btn, .btn-add-cliente-sec, .btn-remover-col, table .btn-edit, table .btn-danger').forEach(function (button) {
        button.remove();
    });
});
</script>
HTML;
    }
    echo '<script defer src="' . htmlspecialchars($appRoot . '/assets/js/draft-reminder.js?v=20260512d', ENT_QUOTES, 'UTF-8') . '"></script>';
    echo '<script defer src="' . htmlspecialchars($appRoot . '/assets/js/mobile-touch.js?v=20260512b', ENT_QUOTES, 'UTF-8') . '"></script>';
    echo '<script defer src="' . htmlspecialchars($appRoot . '/assets/js/responsive-tables.js?v=20260521d', ENT_QUOTES, 'UTF-8') . '"></script>';
}

function renderAppHeader(string $basePath = '.'): void
{
    $nivel = $_SESSION['usuario_nivel'] ?? '';
    $nome  = $_SESSION['usuario_nome'] ?? 'Usuário';

    // Active-page detection: compare URL suffix against path
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $isActive = static function (string $path) use ($scriptName): bool {
        $suffix = '/' . ltrim(str_replace('\\', '/', $path), '/');
        return substr($scriptName, -strlen($suffix)) === $suffix;
    };
    $isActiveAny = static function (array $paths) use ($isActive): bool {
        foreach ($paths as $p) { if ($isActive($p)) return true; }
        return false;
    };

    $searchItems = [
        ['title' => 'Dashboard', 'description' => 'Resumo da empresa, cards, estoque baixo e estoque por produto', 'url' => appBaseLink($basePath, 'index.php'), 'icon' => 'bi-speedometer2', 'terms' => 'home inicio dashboard resumo painel estoque baixo alerta total'],
        ['title' => 'Estoque', 'description' => 'Estoque inicial e controle de produtos em estoque', 'url' => appBaseLink($basePath, 'estoque/estoque_inicial_aba.php'), 'icon' => 'bi-box-seam', 'terms' => 'estoque produto produtos quantidade kg saldo inicial editar estoque'],
        ['title' => 'Previsao Fornecedor', 'description' => 'Compras previstas, OS anexada, preço e chegada de fornecedor', 'url' => appBaseLink($basePath, 'previsoes/previsao_fornecedor.php'), 'icon' => 'bi-truck', 'terms' => 'previsao previsoes fornecedor compra compras os ordem servico anexada pendente preco chegada entrada financeiro'],
        ['title' => 'Previsao Colheita', 'description' => 'Previsao da colheita local da empresa', 'url' => appBaseLink($basePath, 'previsoes/previsao_colheita.php'), 'icon' => 'bi-calendar-check', 'terms' => 'previsao previsoes colheita local meia meieiro campo plantio pendente confirmar'],
        ['title' => 'Vendas', 'description' => 'Venda, pedido, OS anexada, preço e confirmação', 'url' => appBaseLink($basePath, 'vendas/vendas.php'), 'icon' => 'bi-cart-check', 'terms' => 'venda vendas vender pedido pedidos cliente os anexada pendente preco pagamento boleto deposito caixa bandeja kg'],
        ['title' => 'Notas de Caixas', 'description' => 'Criar e emitir NF-e de retorno de caixas em comodato', 'url' => appBaseLink($basePath, 'notas_caixas/nova.php'), 'icon' => 'bi-boxes', 'terms' => 'nota notas caixas caixa comodato retorno oba nfe'],
        ['title' => 'Historico de Boletos', 'description' => 'Boletos SICOOB gerados, situacao e segunda via', 'url' => appBaseLink($basePath, 'boletos/historico.php'), 'icon' => 'bi-upc-scan', 'terms' => 'boleto boletos sicoob cobranca segunda via historico situacao'],
        ['title' => 'Historico de NF-e', 'description' => 'Painel fiscal com todas as notas emitidas pela Focus', 'url' => appBaseLink($basePath, 'nfe/historico.php'), 'icon' => 'bi-receipt-cutoff', 'terms' => 'nfe nota fiscal historico focus danfe xml sefaz chave'],
        ['title' => 'Tabela Atacado', 'description' => 'Preços por produto para venda no atacado', 'url' => appBaseLink($basePath, 'tabelas/tabela_atacado.php'), 'icon' => 'bi-table', 'terms' => 'tabela tabelas atacado preco precos caixa kg desconto prazo'],
        ['title' => 'Tabela Atacado Convencional', 'description' => 'Precos de atacado convencional com percentual financeiro por cliente', 'url' => appBaseLink($basePath, 'tabelas/tabela_atacado_convencional.php'), 'icon' => 'bi-table', 'terms' => 'tabela tabelas atacado convencional preco precos caixa kg desconto prazo cliente'],
        ['title' => 'Tabela Embalado', 'description' => 'Preços de produtos embalados e bandejas', 'url' => appBaseLink($basePath, 'tabelas/tabela_embalado.php'), 'icon' => 'bi-table', 'terms' => 'tabela tabelas embalado bandeja preco precos percentual'],
        ['title' => 'OBA Embalado', 'description' => 'Tabela fixa OBA para produtos embalados sem frete', 'url' => appBaseLink($basePath, 'tabelas/tabela_oba_embalado.php'), 'icon' => 'bi-table', 'terms' => 'oba embalado tabela preco precos sem frete cliente'],
        ['title' => 'Tabela Shopper', 'description' => 'Tabela fixa Shopper para atacado organico', 'url' => appBaseLink($basePath, 'tabelas/tabela_shopper.php'), 'icon' => 'bi-table', 'terms' => 'shopper tabela preco precos atacado organico cliente'],
        ['title' => 'Produtos', 'description' => 'Cadastrar, editar, ativar ou desativar produtos', 'url' => appBaseLink($basePath, 'produtos/produtos.php'), 'icon' => 'bi-basket', 'terms' => 'produto produtos cadastrar cadastro editar desativar reativar unidade'],
        ['title' => 'Produtos OBA', 'description' => 'Cadastrar produtos exclusivos da tabela OBA vinculados ao produto principal', 'url' => appBaseLink($basePath, 'produtos/produtos_oba.php'), 'icon' => 'bi-basket2', 'terms' => 'produto produtos oba cadastrar cadastro vinculado tabela embalado'],
        ['title' => 'Fornecedores', 'description' => 'Cadastro de fornecedores, CNPJ, telefone, endereço e certificado', 'url' => appBaseLink($basePath, 'fornecedores/fornecedores.php'), 'icon' => 'bi-truck', 'terms' => 'fornecedor fornecedores cadastro cadastrar editar cnpj telefone endereco certificado'],
        ['title' => 'Clientes', 'description' => 'Cadastro de clientes, documento, telefone e endereço', 'url' => appBaseLink($basePath, 'clientes/clientes.php'), 'icon' => 'bi-people', 'terms' => 'cliente clientes cadastro cadastrar editar cpf cnpj documento telefone endereco'],
        ['title' => 'Usuários', 'description' => 'Cadastro e permissao dos usuários do sistema', 'url' => appBaseLink($basePath, 'usuarios/usuarios.php'), 'icon' => 'bi-people-fill', 'terms' => 'usuario usuarios permissao permissoes acesso senha login admin editar'],
        ['title' => 'Editar Tabelas', 'description' => 'Gerenciar tabelas de preco criadas', 'url' => appBaseLink($basePath, 'tabelas/tabelas_personalizadas.php'), 'icon' => 'bi-table', 'terms' => 'tabela tabelas editar excluir preco precos personalizada personalizadas'],
        ['title' => 'Histórico de Entradas', 'description' => 'Entradas e movimentações registradas no estoque', 'url' => appBaseLink($basePath, 'entradas/entradas.php'), 'icon' => 'bi-clock-history', 'terms' => 'historico entradas movimentacoes movimento entrada fornecedor colheita'],
        ['title' => 'Contas a pagar', 'description' => 'Integracao das NF-e de compra com o contas a pagar', 'url' => appBaseLink($basePath, 'contas/pagar.php'), 'icon' => 'bi-cash-coin', 'terms' => 'contas pagar financeiro compra compras nfe nota fiscal fornecedor funrural desconto vencimento integracao'],
        ['title' => 'Contas a receber', 'description' => 'Integracao futura de boletos e vendas com o contas a receber', 'url' => appBaseLink($basePath, 'contas/receber.php'), 'icon' => 'bi-receipt', 'terms' => 'contas receber financeiro venda vendas boleto boletos cliente integracao'],
        ['title' => 'Comissoes Frete', 'description' => 'Pedidos designados para cada entreposto', 'url' => appBaseLink($basePath, 'comissoes/frete.php'), 'icon' => 'bi-truck-front', 'terms' => 'comissoes frete entreposto caminhoneiro entrega pedidos os'],
        ['title' => 'Comissoes Meeiros', 'description' => 'Colheitas confirmadas por meeiro', 'url' => appBaseLink($basePath, 'comissoes/meeiros.php'), 'icon' => 'bi-flower1', 'terms' => 'comissoes meeiros meieiros colheita previsao abate descarte pedido'],
        ['title' => 'Tabela Precos Meeiros', 'description' => 'Editar precos por kg usados na comissao de meeiros', 'url' => appBaseLink($basePath, 'comissoes/tabela_precos_meeiros.php'), 'icon' => 'bi-table', 'terms' => 'tabela precos meeiros meieiros kg editar excel comissao'],
        ['title' => 'Comissoes Vendedores', 'description' => 'Pedidos e valores de comissão por vendedor', 'url' => appBaseLink($basePath, 'comissoes/vendedores.php'), 'icon' => 'bi-person-badge', 'terms' => 'comissoes vendedores vendedor vendas pedidos percentual'],
    ];
    $searchItems = array_values(array_filter($searchItems, static function (array $item) use ($nivel): bool {
        $title = $item['title'];
        if ($title === 'Estoque') return userCanAccess('estoque');
        if ($title === 'Previsao Fornecedor') return userCanAccess('previsao_fornecedor');
        if ($title === 'Previsao Colheita') return userCanAccess('previsao_colheita');
        if ($title === 'Vendas') return userCanAccess('vendas');
        if ($title === 'Notas de Caixas') return userCanAccess('notas_caixas');
        if ($title === 'Historico de Boletos') return userCanAccess('historico_nfe');
        if ($title === 'Historico de NF-e') return userCanAccess('historico_nfe');
        if ($title === 'Tabela Atacado' || $title === 'Tabela Atacado Convencional' || $title === 'Tabela Embalado' || $title === 'OBA Embalado' || $title === 'Tabela Shopper') return userCanAccess('tabelas');
        if ($title === 'Produtos' || $title === 'Produtos OBA') return userCanAccess('produtos');
        if ($title === 'Fornecedores') return userCanAccess('fornecedores');
        if ($title === 'Clientes') return userCanAccess('clientes');
        if ($title === 'Usuários') return userCanAccess('usuarios');
        if ($title === 'Editar Tabelas') return userCanAccess('tabelas') && userCanEditTabelas();
        if ($title === 'Historico de Entradas') return userCanAccess('entradas');
        if ($title === 'Contas a pagar' || $title === 'Contas a receber') return userCanAccess('contas');
        if ($title === 'Comissoes Frete' || $title === 'Comissoes Meeiros' || $title === 'Tabela Precos Meeiros' || $title === 'Comissoes Vendedores') return userHasPermission(PERM_ADMIN);
        return true;
    }));
    $searchJson = htmlspecialchars(json_encode($searchItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
    $msgParam = (string) ($_GET['msg'] ?? '');
    $detalheParam = (string) ($_GET['detalhe'] ?? '');
    $totalParam = isset($_GET['total']) ?(int) $_GET['total'] : 0;
    $toastType = 'success';
    $toastTitle = '';
    $toastText = '';
    if ($msgParam !== '') {
        $toastMessages = [
            'salvo' => ['success', 'Registro salvo', 'As informações foram registradas com sucesso.'],
            'atualizado' => ['success', 'Registro atualizado', 'As alterações foram aplicadas com sucesso.'],
            'excluido' => ['success', 'Registro removido', 'O item foi removido da listagem com sucesso.'],
            'confirmado' => ['success', 'Ação confirmada', 'A operação foi concluída com sucesso.'],
            'fechado' => ['success', 'Ciclo encerrado', 'O ciclo foi encerrado e o próximo ciclo foi aberto.'],
            'desfeito' => ['success', 'Fechamento desfeito', 'O ciclo anterior foi reaberto com sucesso.'],
            'entrada_registrada' => ['success', 'Entrada registrada', 'A entrada foi registrada no estoque com sucesso.'],
            'enviado_lote' => ['success', 'Envio concluído', $totalParam > 0 ?$totalParam . ' item(ns) enviados com sucesso.' : 'Os itens foram enviados com sucesso.'],
            'foto' => ['success', 'Foto enviada', 'A foto foi salva com sucesso.'],
            'foto_salva' => ['success', 'Foto atualizada', 'A foto foi salva com sucesso.'],
            'erro' => ['error', 'Nao foi possível concluir', $detalheParam !== '' ?$detalheParam : 'Revise os dados informados e tente novamente.'],
        ];
        if (isset($toastMessages[$msgParam])) {
            [$toastType, $toastTitle, $toastText] = $toastMessages[$msgParam];
        }
    }
    $toastData = htmlspecialchars(json_encode([
        'type' => $toastType,
        'title' => $toastTitle,
        'text' => $toastText,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');

    echo <<<'CSS'
<style>
@media (max-width:768px){
    table.app-responsive-table:not([data-no-responsive="1"]){
        min-width:0!important;
        width:100%!important;
        max-width:100%!important;
    }
    .table-responsive > table[data-no-responsive="1"],
    .table-container > table[data-no-responsive="1"],
    .table-wrap > table[data-no-responsive="1"]{
        width:max-content!important;
        max-width:none!important;
        min-width:max(720px,100%)!important;
    }
    .table-responsive,.table-container,.table-wrap{
        overflow-x:auto!important;
        overflow-y:visible!important;
        -webkit-overflow-scrolling:touch!important;
    }
    .btn-toggle-os{
        min-width:42px!important;
        width:42px!important;
        min-height:42px!important;
        height:42px!important;
        display:inline-flex!important;
        align-items:center!important;
        justify-content:center!important;
        line-height:1!important;
    }
    .wizard-progress{
        display:grid!important;
        grid-template-columns:repeat(3,minmax(0,1fr))!important;
        gap:6px!important;
        overflow:visible!important;
        margin:0 0 18px!important;
        padding:0!important;
    }
    .wizard-step-indicator{
        min-width:0!important;
        width:100%!important;
        margin:0!important;
        padding:8px 6px!important;
        border-radius:7px!important;
        font-size:11px!important;
        line-height:1.15!important;
        white-space:normal!important;
        overflow-wrap:anywhere!important;
    }
    .wizard-step-indicator + .wizard-step-indicator{
        margin-left:0!important;
    }
    .wizard-step-indicator::before,
    .wizard-step-indicator::after{
        content:none!important;
        display:none!important;
    }
    .wizard-nav{
        display:flex!important;
        align-items:center!important;
        gap:8px!important;
        width:auto!important;
    }
    .wizard-nav button,
    .wizard-actions-final button{
        flex:0 0 42px!important;
        width:42px!important;
        min-width:42px!important;
        height:42px!important;
        min-height:42px!important;
        padding:0!important;
        display:inline-flex!important;
        align-items:center!important;
        justify-content:center!important;
        line-height:1!important;
    }
    .wizard-actions-final{
        display:flex!important;
        align-items:center!important;
        justify-content:flex-start!important;
        gap:8px!important;
        flex-wrap:nowrap!important;
    }
    /* Fix: esconde botoes de Finalizar quando wizard-hidden */
    #vendaAcoesFinais.wizard-hidden,
    .wizard-actions-final.wizard-hidden{
        display:none!important;
    }
    #preco_sugestao,
    #resumoVenda{
        width:100%!important;
        max-width:100%!important;
        min-width:0!important;
        display:grid!important;
        grid-template-columns:minmax(0,1fr)!important;
        gap:8px!important;
        overflow:visible!important;
    }
    #preco_sugestao *,
    #resumoVenda *{
        max-width:100%!important;
        white-space:normal!important;
        overflow-wrap:anywhere!important;
        word-break:normal!important;
    }
    #preco_sugestao .btn-preco-sugestao{
        width:100%!important;
        min-height:42px!important;
        height:auto!important;
        padding:10px 12px!important;
        text-align:left!important;
        justify-content:flex-start!important;
        line-height:1.25!important;
    }
    #tabelaProdutos .est-bar-cell,
    #tabelaProdutos td.app-mobile-toggle-cell{
        display:none!important;
    }
    .acoes{
        align-items:center!important;
    }
    table.app-responsive-table td.acoes,
    table.app-responsive-table td.acoes-cell{
        display:flex!important;
        align-items:center!important;
        justify-content:flex-start!important;
        gap:8px!important;
        flex-wrap:wrap!important;
        flex-direction:row!important;
    }
    table.app-responsive-table td.acoes::before,
    table.app-responsive-table td.acoes-cell::before{
        flex:0 0 100%!important;
        width:100%!important;
        margin-bottom:2px!important;
    }
    .acoes > a,
    .acoes > button,
    .acoes form > button,
    .acoes-cell > a,
    .acoes-cell > button{
        align-self:center!important;
        margin:0!important;
    }
    .acoes form{
        display:inline-flex!important;
        align-items:center!important;
        justify-content:center!important;
        margin:0!important;
        padding:0!important;
    }
    .acoes form button{
        margin:0!important;
    }
}
</style>
CSS;

    // Header
    echo '<div class="app-header no-print">';
    echo   '<div class="app-header-top">';
    echo     '<button type="button" class="app-mobile-toggle" aria-label="Abrir menu" aria-expanded="false"><i class="bi bi-list"></i></button>';
    echo     '<div class="app-brand">';
    echo       '<div class="app-search" role="search" data-app-search-items="' . $searchJson . '">';
    echo         '<i class="bi bi-search"></i>';
    echo         '<input type="text" id="appGlobalSearch" aria-label="Busca" autocomplete="off" placeholder="Buscar funcionalidade, produto, OS...">';
    echo         '<div class="app-search-results" id="appGlobalSearchResults"></div>';
    echo       '</div>';
    echo     '</div>';
    echo     '<div class="app-page-actions">';
    echo       '<a class="app-btn-lite" href="' . htmlspecialchars(appBaseLink($basePath, 'index.php')) . '">Início</a>';
    echo       '<button type="button" class="app-btn-lite" onclick="window.print()" title="Imprimir" aria-label="Imprimir"><i class="bi bi-printer"></i> Imprimir</button>';
    echo       '<div class="app-user-menu">';
    echo         '<div class="app-user"><span class="app-user-name">' . htmlspecialchars($nome) . '</span><span class="app-user-role">' . htmlspecialchars($nivel) . '</span></div>';
    echo         '<div class="app-user-dropdown"><a href="' . htmlspecialchars(appBaseLink($basePath, 'auth/logout.php')) . '"><i class="bi bi-box-arrow-right"></i> Sair</a></div>';
    echo       '</div>';
    echo     '</div>';
    echo   '</div>';
    echo '</div>';

    // Navbar
    echo '<div class="app-sidebar-overlay no-print" aria-hidden="true"></div>';
    echo '<nav class="app-navbar no-print">';
    echo '<button id="appSidebarTopToggle" class="app-sidebar-top-toggle" title="Recolher/expandir sidebar"><i class="bi bi-layout-sidebar-reverse"></i></button>';
    echo '<div class="app-sidebar-brand"><img src="' . htmlspecialchars(appBaseLink($basePath, 'assets/img/agrocolittilogo.png'), ENT_QUOTES, 'UTF-8') . '" alt="Grupo AgroColitti"></div>';

    // Dashboard
    $cls = $isActive('index.php') ?' class="active"' : '';
    echo '<a href="' . htmlspecialchars(appBaseLink($basePath, 'index.php')) . '"' . $cls . '><i class="bi bi-speedometer2"></i><span class="sb-label"> Dashboard</span></a>';

    // Estoque
    if (userCanAccess('estoque')) {
        $cls = $isActive('estoque/estoque_inicial_aba.php') ?' class="active"' : '';
        echo '<a href="' . htmlspecialchars(appBaseLink($basePath, 'estoque/estoque_inicial_aba.php')) . '"' . $cls . '><i class="bi bi-box-seam"></i><span class="sb-label"> Estoque</span></a>';
    }

    // Previsoes
    $prevItems = [];
    if (userCanAccess('previsao_fornecedor'))
        $prevItems[] = ['<i class="bi bi-truck"></i> Previsao Fornecedor', 'previsoes/previsao_fornecedor.php'];
    if (userCanAccess('previsao_colheita'))
        $prevItems[] = ['<i class="bi bi-calendar-check"></i> Previsao Colheita', 'previsoes/previsao_colheita.php'];

    if (!empty($prevItems)) {
        $btnCls = $isActiveAny(array_column($prevItems, 1)) ?' active' : '';
        echo '<div class="app-dropdown">';
        echo   '<button type="button" class="app-nav-btn' . $btnCls . '" onclick="appToggleDropdown(this)">';
        echo     '<i class="bi bi-calendar2-week"></i><span class="sb-label"> Previsoes</span><i class="bi bi-chevron-down app-caret"></i>';
        echo   '</button>';
        echo   '<div class="app-dropdown-menu">';
        foreach ($prevItems as [$label, $path]) {
            $cls = $isActive($path) ?' class="active"' : '';
            echo '<a href="' . htmlspecialchars(appBaseLink($basePath, $path)) . '"' . $cls . '>' . $label . '</a>';
        }
        echo   '</div>';
        echo '</div>';
    }

    // Vendas
    if (userCanAccess('vendas')) {
        $cls = $isActive('vendas/vendas.php') ?' class="active"' : '';
        echo '<a href="' . htmlspecialchars(appBaseLink($basePath, 'vendas/vendas.php')) . '"' . $cls . '><i class="bi bi-cart-check"></i><span class="sb-label"> Vendas</span></a>';
    }

    // Notas de Caixas
    if (userCanAccess('notas_caixas')) {
        $cxItems = [
            ['<i class="bi bi-plus-square"></i> Nova Nota de Caixas', 'notas_caixas/nova.php'],
        ];
        $cxPaths = ['notas_caixas/nova.php'];
        $btnCls = $isActiveAny($cxPaths) ?' active' : '';
        echo '<div class="app-dropdown">';
        echo   '<button type="button" class="app-nav-btn' . $btnCls . '" onclick="appToggleDropdown(this)">';
        echo     '<i class="bi bi-boxes"></i><span class="sb-label"> Notas de Caixas</span><i class="bi bi-chevron-down app-caret"></i>';
        echo   '</button>';
        echo   '<div class="app-dropdown-menu">';
        foreach ($cxItems as [$label, $path]) {
            $cls = $isActive($path) ?' class="active"' : '';
            echo '<a href="' . htmlspecialchars(appBaseLink($basePath, $path)) . '"' . $cls . '>' . $label . '</a>';
        }
        echo   '</div>';
        echo '</div>';
    }

    // Tabelas
    if (userCanAccess('tabelas')) {
        $tabItems = [
            ['<i class="bi bi-grid-3x3-gap"></i> Tabela Atacado',  'tabelas/tabela_atacado.php'],
            ['<i class="bi bi-grid-3x3-gap"></i> Tabela Atacado Convencional',  'tabelas/tabela_atacado_convencional.php'],
            ['<i class="bi bi-box2-heart"></i> Tabela Embalado', 'tabelas/tabela_embalado.php'],
            ['<i class="bi bi-box2-heart"></i> OBA Embalado', 'tabelas/tabela_oba_embalado.php'],
            ['<i class="bi bi-table"></i> Tabela Shopper', 'tabelas/tabela_shopper.php'],
        ];
        $tabPaths = ['tabelas/tabela_atacado.php', 'tabelas/tabela_atacado_convencional.php', 'tabelas/tabela_embalado.php', 'tabelas/tabela_oba_embalado.php', 'tabelas/tabela_shopper.php'];
        if (userCanEditTabelas()) {
            $tabItems[] = ['<i class="bi bi-plus-square"></i> Criar nova tabela', 'tabelas/criar_tabela_personalizada.php'];
            $tabPaths[] = 'tabelas/criar_tabela_personalizada.php';
        }
        $btnCls = $isActiveAny($tabPaths) ?' active' : '';
        echo '<div class="app-dropdown">';
        echo   '<button type="button" class="app-nav-btn' . $btnCls . '" onclick="appToggleDropdown(this)">';
        echo     '<i class="bi bi-table"></i><span class="sb-label"> Tabelas</span><i class="bi bi-chevron-down app-caret"></i>';
        echo   '</button>';
        echo   '<div class="app-dropdown-menu">';
        foreach ($tabItems as [$label, $path]) {
            $cls = $isActive($path) ?' class="active"' : '';
            echo '<a href="' . htmlspecialchars(appBaseLink($basePath, $path)) . '"' . $cls . '>' . $label . '</a>';
        }
        echo   '</div>';
        echo '</div>';
    }

    // Ciclos
    if (userCanAccess('ciclos')) {
        $cls = $isActive('ciclos/index.php') ?' class="active"' : '';
        echo '<a href="' . htmlspecialchars(appBaseLink($basePath, 'ciclos/index.php')) . '"' . $cls . '><i class="bi bi-arrow-repeat"></i><span class="sb-label"> Ciclos</span></a>';
    }

    // Editar
    if (userCanAccess('produtos') || userCanAccess('fornecedores') || userCanAccess('clientes') || userCanAccess('usuarios') || (userCanAccess('tabelas') && userCanEditTabelas())) {
        $editItems = [
            ['<i class="bi bi-basket"></i> Produtos',     'produtos/produtos.php'],
            ['<i class="bi bi-basket2"></i> Produtos OBA', 'produtos/produtos_oba.php'],
            ['<i class="bi bi-truck"></i> Fornecedores', 'fornecedores/fornecedores.php'],
            ['<i class="bi bi-people"></i> Clientes',     'clientes/clientes.php'],
            ['<i class="bi bi-people-fill"></i> Usuários', 'usuarios/usuarios.php'],
        ];
        $editPaths = ['produtos/produtos.php', 'produtos/produtos_oba.php', 'fornecedores/fornecedores.php', 'clientes/clientes.php', 'usuarios/usuarios.php'];
        if (userCanAccess('tabelas') && userCanEditTabelas()) {
            $editItems[] = ['<i class="bi bi-table"></i> Editar Tabelas', 'tabelas/tabelas_personalizadas.php'];
            $editPaths[] = 'tabelas/tabelas_personalizadas.php';
        }
        $btnCls = $isActiveAny($editPaths) ?' active' : '';
        echo '<div class="app-dropdown">';
        echo   '<button type="button" class="app-nav-btn' . $btnCls . '" onclick="appToggleDropdown(this)">';
        echo     '<i class="bi bi-pencil-square"></i><span class="sb-label"> Editar</span><i class="bi bi-chevron-down app-caret"></i>';
        echo   '</button>';
        echo   '<div class="app-dropdown-menu">';
        foreach ($editItems as [$label, $path]) {
            $cls = $isActive($path) ?' class="active"' : '';
            echo '<a href="' . htmlspecialchars(appBaseLink($basePath, $path)) . '"' . $cls . '>' . $label . '</a>';
        }
        echo   '</div>';
        echo '</div>';
    }

    // Historico
    if (userCanAccess('historico_vendas') || userCanAccess('historico_compras') || userCanAccess('entradas') || userCanAccess('notas_caixas') || userCanAccess('historico_nfe')) {
        $histItems = [
            ['<i class="bi bi-receipt-cutoff"></i> Historico de NF-e', 'nfe/historico.php'],
            ['<i class="bi bi-upc-scan"></i> Historico de Boletos', 'boletos/historico.php'],
            ['<i class="bi bi-cart-check"></i> Historico de Vendas', 'vendas/historico_vendas.php'],
            ['<i class="bi bi-truck"></i> Historico de Compras', 'entradas/historico_compras.php'],
            ['<i class="bi bi-boxes"></i> Historico de Caixas', 'notas_caixas/historico.php'],
            ['<i class="bi bi-reception-4"></i> Histórico Geral', 'entradas/entradas.php'],
        ];
        $histPaths = ['nfe/historico.php', 'boletos/historico.php', 'vendas/historico_vendas.php', 'entradas/historico_compras.php', 'notas_caixas/historico.php', 'entradas/entradas.php'];
        $btnCls = $isActiveAny($histPaths) ?' active' : '';
        echo '<div class="app-dropdown">';
        echo   '<button type="button" class="app-nav-btn' . $btnCls . '" onclick="appToggleDropdown(this)">';
        echo     '<i class="bi bi-clock-history"></i><span class="sb-label"> Histórico</span><i class="bi bi-chevron-down app-caret"></i>';
        echo   '</button>';
        echo   '<div class="app-dropdown-menu">';
        foreach ($histItems as [$label, $path]) {
            $cls = $isActive($path) ?' class="active"' : '';
            echo '<a href="' . htmlspecialchars(appBaseLink($basePath, $path)) . '"' . $cls . '>' . $label . '</a>';
        }
        echo   '</div>';
        echo '</div>';
    }

    // Comissoes
    if (userHasPermission(PERM_ADMIN)) {
        $comissoesItems = [
            ['<i class="bi bi-truck-front"></i> Comissoes Frete', 'comissoes/frete.php'],
            ['<i class="bi bi-flower1"></i> Comissoes Meeiros', 'comissoes/meeiros.php'],
            ['<i class="bi bi-table"></i> Tabela Precos Meeiros', 'comissoes/tabela_precos_meeiros.php'],
            ['<i class="bi bi-person-badge"></i> Comissoes Vendedores', 'comissoes/vendedores.php'],
            ['<i class="bi bi-exclamation-triangle"></i> Pendencias de Comissao', 'comissoes/pendencias.php'],
        ];
        $comissoesPaths = ['comissoes/frete.php', 'comissoes/frete_detalhe.php', 'comissoes/meeiros.php', 'comissoes/meeiro_detalhe.php', 'comissoes/tabela_precos_meeiros.php', 'comissoes/vendedores.php', 'comissoes/vendedor_detalhe.php', 'comissoes/pendencias.php'];
        $btnCls = $isActiveAny($comissoesPaths) ? ' active' : '';
        echo '<div class="app-dropdown">';
        echo   '<button type="button" class="app-nav-btn' . $btnCls . '" onclick="appToggleDropdown(this)">';
        echo     '<i class="bi bi-cash-stack"></i><span class="sb-label"> Comissoes</span><i class="bi bi-chevron-down app-caret"></i>';
        echo   '</button>';
        echo   '<div class="app-dropdown-menu">';
        foreach ($comissoesItems as [$label, $path]) {
            $cls = $isActive($path) ? ' class="active"' : '';
            echo '<a href="' . htmlspecialchars(appBaseLink($basePath, $path)) . '"' . $cls . '>' . $label . '</a>';
        }
        echo   '</div>';
        echo '</div>';
    }

    // Contas
    if (userCanAccess('contas')) {
        $contasItems = [
            ['<i class="bi bi-cash-coin"></i> Contas a pagar', 'contas/pagar.php'],
            ['<i class="bi bi-receipt"></i> Contas a receber', 'contas/receber.php'],
        ];
        $contasPaths = ['contas/pagar.php', 'contas/receber.php'];
        $btnCls = $isActiveAny($contasPaths) ?' active' : '';
        echo '<div class="app-dropdown">';
        echo   '<button type="button" class="app-nav-btn' . $btnCls . '" onclick="appToggleDropdown(this)">';
        echo     '<i class="bi bi-wallet2"></i><span class="sb-label"> Contas</span><i class="bi bi-chevron-down app-caret"></i>';
        echo   '</button>';
        echo   '<div class="app-dropdown-menu">';
        foreach ($contasItems as [$label, $path]) {
            $cls = $isActive($path) ?' class="active"' : '';
            echo '<a href="' . htmlspecialchars(appBaseLink($basePath, $path)) . '"' . $cls . '>' . $label . '</a>';
        }
        echo   '</div>';
        echo '</div>';
    }

    // Painel Admin - apenas ti
    if ($nivel === 'ti') {
        $cls = $isActiveAny(['admin/dashboard.php','admin/logs.php','admin/usuarios.php','admin/produtos.php','admin/vendas.php']) ?' class="active"' : '';
        echo '<a href="' . htmlspecialchars(appBaseLink($basePath, 'admin/dashboard.php')) . '"' . $cls . '><i class="bi bi-shield-lock"></i><span class="sb-label"> Admin</span></a>';
    }

    echo '</nav>';
    echo '<button type="button" class="app-sidebar-resizer no-print" id="appSidebarResizer" aria-label="Redimensionar sidebar" title="Arraste para redimensionar; duplo clique restaura o ajuste automatico"></button>';
    // ── Sidebar collapsed tooltip ─────────────────────────────────────────────
    echo '<style>
#sbColTip{
    display:none;
    position:fixed;
    z-index:9999;
    background:#2a2522;
    color:#f5f2ed;
    padding:6px 12px;
    border-radius:7px;
    font-size:13px;
    font-weight:650;
    white-space:nowrap;
    pointer-events:none;
    box-shadow:0 4px 18px rgba(0,0,0,.42);
    border:1px solid rgba(255,255,255,.1);
    line-height:1.3;
}
#sbColTip.visible{display:block}
@media(max-width:900px){#sbColTip{display:none!important}}
</style>';
    echo '<div id="sbColTip"></div>';
    echo '<script>
(function(){
    "use strict";
    var tip=document.getElementById("sbColTip");
    if(!tip)return;
    var COLL="sidebar-collapsed";
    var nav=document.querySelector(".app-navbar");
    var items=document.querySelectorAll(
        ".app-navbar>a,.app-navbar>.app-dropdown>.app-nav-btn"
    );
    items.forEach(function(el){
        var lb=el.querySelector(".sb-label");
        if(!lb)return;
        var label=lb.textContent.trim();
        el.addEventListener("mouseenter",function(){
            if(!document.body.classList.contains(COLL))return;
            var nr=nav?nav.getBoundingClientRect():{right:72};
            var r=el.getBoundingClientRect();
            tip.textContent=label;
            tip.style.visibility="hidden";
            tip.style.display="block";
            var th=tip.offsetHeight;
            var top=Math.max(8,Math.min(window.innerHeight-th-8,r.top+(r.height-th)/2));
            tip.style.top=top+"px";
            tip.style.left=(nr.right+10)+"px";
            tip.style.visibility="";
            tip.style.display="";
            tip.classList.add("visible");
        });
        el.addEventListener("mouseleave",function(){tip.classList.remove("visible");});
    });
    new MutationObserver(function(){
        if(!document.body.classList.contains(COLL))tip.classList.remove("visible");
    }).observe(document.body,{attributes:true,attributeFilter:["class"]});
})();
</script>';

    echo '<div class="app-toast-region no-print" id="appToastRegion" aria-live="polite" aria-atomic="true" data-app-toast="' . $toastData . '"></div>';
    echo '<div class="app-dialog-overlay no-print" id="appDialogOverlay" role="dialog" aria-modal="true" aria-labelledby="appDialogTitle">';
    echo   '<div class="app-dialog" id="appDialogBox">';
    echo     '<div class="app-dialog-head">';
    echo       '<div class="app-dialog-icon"><i class="bi bi-question-lg"></i></div>';
    echo       '<div><h3 id="appDialogTitle">Confirmar ação</h3><p id="appDialogMessage">Deseja continuar?</p></div>';
    echo     '</div>';
    echo     '<div class="app-dialog-actions">';
    echo       '<button type="button" class="app-dialog-btn app-dialog-cancel" id="appDialogCancel">Cancelar</button>';
    echo       '<button type="button" class="app-dialog-btn app-dialog-confirm" id="appDialogConfirm">Confirmar</button>';
    echo     '</div>';
    echo   '</div>';
    echo '</div>';

    // Dropdown JS
    echo '<script>
(function () {
    "use strict";
    function closeAll(except) {
        document.querySelectorAll(".app-dropdown.open,.app-dropdown.flyout-open").forEach(function (d) {
            if (d !== except) d.classList.remove("open", "flyout-open");
        });
    }
    window.appToggleDropdown = function (btn) {
        var dropdown = btn.closest(".app-dropdown");
        if (document.body.classList.contains("sidebar-collapsed")) {
            var wasFlyout = dropdown.classList.contains("flyout-open");
            closeAll(null);
            if (!wasFlyout) {
                dropdown.classList.add("flyout-open");
                var menu = dropdown.querySelector(".app-dropdown-menu");
                var rect = btn.getBoundingClientRect();
                menu.style.top = rect.top + "px";
            }
        } else {
            var wasOpen = dropdown.classList.contains("open");
            closeAll(null);
            if (!wasOpen) dropdown.classList.add("open");
        }
    };
    document.querySelectorAll(".app-dropdown > .app-nav-btn.active").forEach(function (btn) {
        var dropdown = btn.closest(".app-dropdown");
        if (dropdown) dropdown.classList.add("open");
    });
    document.addEventListener("click", function (e) {
        if (!e.target.closest(".app-dropdown")) closeAll(null);
    });
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") closeAll(null);
    });
})();

(function () {
    "use strict";
    var LS_KEY = "agro_sb";
    var WIDTH_KEY = "agro_sb_width";
    var DEFAULT_WIDTH = 264;
    var MIN_WIDTH = 210;
    var MAX_WIDTH = 420;
    var resizeHandle = document.getElementById("appSidebarResizer");

    function normalizarLargura(value) {
        var width = parseInt(value, 10);
        var viewportMax = Math.max(MIN_WIDTH, Math.floor(window.innerWidth * 0.34));
        var maxWidth = Math.min(MAX_WIDTH, viewportMax);
        if (!Number.isFinite(width)) width = DEFAULT_WIDTH;
        return Math.max(MIN_WIDTH, Math.min(maxWidth, width));
    }

    function applyWidth(value, save) {
        var width = normalizarLargura(value);
        document.documentElement.style.setProperty("--sb-w", width + "px");
        if (save) localStorage.setItem(WIDTH_KEY, String(width));
        return width;
    }

    function applyAutoWidthUnlessCustomized() {
        var savedWidth = localStorage.getItem(WIDTH_KEY);
        if (savedWidth === null || savedWidth === "") {
            document.documentElement.style.removeProperty("--sb-w");
            return;
        }
        applyWidth(savedWidth, false);
    }

    function getSaved() {
        return localStorage.getItem(LS_KEY) || "expanded";
    }

    function applyState(state) {
        document.body.classList.remove("sidebar-collapsed", "sidebar-hidden");
        if (state === "collapsed") document.body.classList.add("sidebar-collapsed");
        if (state === "hidden")    document.body.classList.add("sidebar-hidden");
        localStorage.setItem(LS_KEY, state);
        var btn = document.getElementById("appSidebarToggle");
        if (!btn) return;
        var lbl = btn.querySelector(".sb-label");
        if (state === "collapsed") {
            if (lbl) lbl.textContent = "Expandir";
        } else {
            if (lbl) lbl.textContent = "Recolher";
        }
    }

    // Restore saved state on load (desktop only - breakpoint matches CSS 900px)
    if (window.innerWidth >= 900) {
        applyAutoWidthUnlessCustomized();
        applyState(getSaved());
    } else {
        // Mobile: force-hide desktop toggle buttons in case CSS is not yet applied
        var dtBtn = document.getElementById("appSidebarTopToggle");
        if (dtBtn) dtBtn.style.display = "none";
    }

    // Top toggle btn (top of sidebar): expanded <-> collapsed
    var topToggleBtn = document.getElementById("appSidebarTopToggle");
    if (topToggleBtn) {
        topToggleBtn.addEventListener("click", function () {
            if (window.innerWidth < 900) return;
            var cur = getSaved();
            if (cur === "hidden") {
                applyState("expanded");
            } else if (cur === "expanded") {
                applyState("collapsed");
            } else {
                applyState("expanded");
            }
        });
    }

    if (resizeHandle) {
        var resizing = false;

        function stopResize() {
            if (!resizing) return;
            resizing = false;
            document.body.classList.remove("sidebar-resizing");
        }

        resizeHandle.addEventListener("pointerdown", function (event) {
            if (window.innerWidth < 900) return;
            applyState("expanded");
            resizing = true;
            document.body.classList.add("sidebar-resizing");
            resizeHandle.setPointerCapture(event.pointerId);
            applyWidth(event.clientX, true);
            event.preventDefault();
        });
        resizeHandle.addEventListener("pointermove", function (event) {
            if (!resizing) return;
            applyWidth(event.clientX, true);
        });
        resizeHandle.addEventListener("pointerup", stopResize);
        resizeHandle.addEventListener("pointercancel", stopResize);
        resizeHandle.addEventListener("dblclick", function () {
            if (window.innerWidth < 900) return;
            localStorage.removeItem(WIDTH_KEY);
            applyAutoWidthUnlessCustomized();
        });
        resizeHandle.addEventListener("keydown", function (event) {
            if (window.innerWidth < 900) return;
            var delta = event.shiftKey ? 20 : 8;
            var current = normalizarLargura(localStorage.getItem(WIDTH_KEY));
            if (event.key === "ArrowLeft") {
                applyState("expanded");
                applyWidth(current - delta, true);
                event.preventDefault();
            }
            if (event.key === "ArrowRight") {
                applyState("expanded");
                applyWidth(current + delta, true);
                event.preventDefault();
            }
        });
    }

    // Ctrl+B: toggle hidden (desktop only)
    document.addEventListener("keydown", function (e) {
        if (e.ctrlKey && (e.key === "b" || e.key === "B")) {
            if (window.innerWidth < 900) return;
            var active = document.activeElement;
            var tag = active ? active.tagName : "";
            if (tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT") return;
            e.preventDefault();
            var cur = getSaved();
            applyState(cur === "hidden" ? "expanded" : "hidden");
        }
    });

    // Resize: reset sidebar state when crossing the 900px breakpoint
    var _sbLastMobile = window.innerWidth < 900;
    var _sbResizeTimer = null;
    window.addEventListener("resize", function () {
        clearTimeout(_sbResizeTimer);
        _sbResizeTimer = setTimeout(function () {
            var isMobile = window.innerWidth < 900;
            if (!isMobile) applyAutoWidthUnlessCustomized();
            if (isMobile === _sbLastMobile) return;
            _sbLastMobile = isMobile;
            if (isMobile) {
                // Entering mobile: strip desktop state, close mobile nav
                document.body.classList.remove("sidebar-collapsed", "sidebar-hidden");
                document.body.classList.remove("app-nav-open");
                var mTog = document.querySelector(".app-mobile-toggle");
                if (mTog) mTog.setAttribute("aria-expanded", "false");
            } else {
                // Entering desktop: close mobile nav, restore desktop state
                document.body.classList.remove("app-nav-open");
                var mTog2 = document.querySelector(".app-mobile-toggle");
                if (mTog2) mTog2.setAttribute("aria-expanded", "false");
                applyAutoWidthUnlessCustomized();
                applyState(getSaved());
            }
        }, 150);
    });
})();

(function () {
    "use strict";
    var wrap = document.querySelector(".app-search[data-app-search-items]");
    if (!wrap) return;

    var input = wrap.querySelector("#appGlobalSearch");
    var results = wrap.querySelector("#appGlobalSearchResults");
    var items = [];
    try {
        items = JSON.parse(wrap.getAttribute("data-app-search-items") || "[]");
    } catch (e) {
        items = [];
    }

    function normalize(value) {
        return String(value || "")
            .normalize("NFD")
            .replace(/[\u0300-\u036f]/g, "")
            .toLowerCase()
            .trim();
    }

    function scoreItem(item, query) {
        var haystack = normalize([item.title, item.description, item.terms].join(" "));
        var title = normalize(item.title);
        var words = query.split(/\s+/).filter(Boolean);
        if (!words.length) return 0;
        var score = 0;
        words.forEach(function (word) {
            if (title.indexOf(word) !== -1) score += 8;
            if (haystack.indexOf(word) !== -1) score += 3;
        });
        if (title.indexOf(query) !== -1) score += 12;
        if (haystack.indexOf(query) !== -1) score += 5;
        return score;
    }

    function syncHomeStockSearch(term) {
        var stockSearch = document.getElementById("pesquisa");
        if (!stockSearch || stockSearch === input) return;
        stockSearch.value = term;
        if (typeof window.pesquisarProduto === "function") {
            window.pesquisarProduto();
        }
    }

    function getStockMatches(query) {
        if (!query) return [];
        return Array.prototype.slice.call(document.querySelectorAll("#tabelaProdutos tbody tr"))
            .map(function (row) {
                var nameCell = row.querySelector(".est-nome");
                var name = nameCell ? nameCell.textContent.trim() : row.textContent.trim();
                return { name: name, row: row };
            })
            .filter(function (entry) {
                return normalize(entry.name).indexOf(query) !== -1;
            })
            .slice(0, 4);
    }

    function goToStock() {
        var target = document.getElementById("destino") || document.getElementById("tabelaProdutos");
        if (target) target.scrollIntoView({ behavior: "smooth", block: "start" });
        results.classList.remove("open");
    }

    function render(term) {
        var query = normalize(term);
        syncHomeStockSearch(term);

        if (!query) {
            results.classList.remove("open");
            results.innerHTML = "";
            return;
        }

        var stockMatches = getStockMatches(query);
        var matches = items
            .map(function (item) {
                return { item: item, score: scoreItem(item, query) };
            })
            .filter(function (entry) { return entry.score > 0; })
            .sort(function (a, b) { return b.score - a.score; })
            .slice(0, 7);

        if (!matches.length && !stockMatches.length) {
            results.innerHTML = "<div class=\"app-search-empty\">Nenhuma funcionalidade encontrada.</div>";
            results.classList.add("open");
            return;
        }

        var productHtml = stockMatches.map(function (entry, index) {
            return "<button type=\"button\" class=\"app-search-result" + (index === 0 ? " active" : "") + "\" data-stock-result=\"1\">"
                + "<i class=\"bi bi-box-seam\"></i>"
                + "<span><strong>" + entry.name + "</strong><span>Produto encontrado no estoque da home</span></span>"
                + "</button>";
        }).join("");
        var itemHtml = matches.map(function (entry, index) {
            var item = entry.item;
            return "<a class=\"app-search-result" + (!stockMatches.length && index === 0 ? " active" : "") + "\" href=\"" + item.url + "\">"
                + "<i class=\"bi " + item.icon + "\"></i>"
                + "<span><strong>" + item.title + "</strong><span>" + item.description + "</span></span>"
                + "</a>";
        }).join("");
        results.innerHTML = productHtml + itemHtml;
        results.classList.add("open");
    }

    input.addEventListener("input", function () { render(input.value); });
    input.addEventListener("focus", function () { render(input.value); });
    input.addEventListener("keydown", function (event) {
        if (event.key !== "Enter") return;
        var first = results.querySelector(".app-search-result");
        if (first) {
            event.preventDefault();
            if (first.getAttribute("data-stock-result") === "1") {
                goToStock();
                return;
            }
            window.location.href = first.getAttribute("href");
        }
    });
    results.addEventListener("click", function (event) {
        if (event.target.closest("[data-stock-result]")) goToStock();
    });

    document.addEventListener("click", function (event) {
        if (!event.target.closest(".app-search")) results.classList.remove("open");
    });
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") results.classList.remove("open");
    });
})();
(function () {
    "use strict";
    var scrollKey = "app-scroll:" + location.pathname;
    var navToggle = document.querySelector(".app-mobile-toggle");
    var overlay = document.querySelector(".app-sidebar-overlay");
    var toastRegion = document.getElementById("appToastRegion");
    var dialogOverlay = document.getElementById("appDialogOverlay");
    var dialogBox = document.getElementById("appDialogBox");
    var dialogTitle = document.getElementById("appDialogTitle");
    var dialogMessage = document.getElementById("appDialogMessage");
    var dialogCancel = document.getElementById("appDialogCancel");
    var dialogConfirm = document.getElementById("appDialogConfirm");
    var dialogCallback = null;

    function setNav(open) {
        document.body.classList.toggle("app-nav-open", open);
        if (navToggle) navToggle.setAttribute("aria-expanded", open ? "true" : "false");
    }
    if (navToggle) navToggle.addEventListener("click", function () {
        setNav(!document.body.classList.contains("app-nav-open"));
    });
    if (overlay) overlay.addEventListener("click", function () { setNav(false); });
    document.querySelectorAll(".app-navbar a").forEach(function (link) {
        link.addEventListener("click", function () { setNav(false); });
    });

    function saveScroll() {
        try { sessionStorage.setItem(scrollKey, String(window.scrollY || 0)); } catch (e) {}
    }
    document.addEventListener("submit", function () { saveScroll(); }, true);
    document.addEventListener("click", function (event) {
        var link = event.target.closest("a[href]");
        if (link && link.getAttribute("href").charAt(0) !== "#" && !link.target) saveScroll();
    }, true);
    window.addEventListener("load", function () {
        var hasMessage = new URLSearchParams(location.search).has("msg");
        var stored = null;
        try { stored = sessionStorage.getItem(scrollKey); } catch (e) {}
        if (hasMessage && stored !== null) {
            window.scrollTo(0, Math.max(0, parseInt(stored, 10) || 0));
            try { sessionStorage.removeItem(scrollKey); } catch (e) {}
        }
    });

    function showToast(type, title, text) {
        if (!toastRegion || !title) return;
        var toast = document.createElement("div");
        toast.className = "app-toast " + (type === "error" ? "error" : "success");
        toast.innerHTML = "<i class=\"bi " + (type === "error" ? "bi-exclamation-triangle" : "bi-check-circle") + "\"></i>"
            + "<div><strong></strong><span></span></div><button type=\"button\" aria-label=\"Fechar\"><i class=\"bi bi-x\"></i></button>";
        toast.querySelector("strong").textContent = title;
        toast.querySelector("span").textContent = text || "";
        toast.querySelector("button").addEventListener("click", function () { toast.remove(); });
        toastRegion.appendChild(toast);
        toastRegion.classList.add("open");
        setTimeout(function () {
            toast.remove();
            if (!toastRegion.children.length) toastRegion.classList.remove("open");
        }, type === "error" ? 7000 : 4600);
    }
    if (toastRegion) {
        try {
            var data = JSON.parse(toastRegion.getAttribute("data-app-toast") || "{}");
            if (data.title) {
                document.querySelectorAll(".msg-sucesso, .msg-erro, .msg:not(.msg-ciclo)").forEach(function (node) {
                    if ((node.textContent || "").toLowerCase().indexOf("ciclo ativo") === -1) node.style.display = "none";
                });
                showToast(data.type, data.title, data.text);
            }
        } catch (e) {}
    }

    function closeDialog(run) {
        if (dialogOverlay) dialogOverlay.classList.remove("open");
        var cb = dialogCallback;
        dialogCallback = null;
        if (run && typeof cb === "function") cb();
    }
    window.appConfirm = function (message, onConfirm, options) {
        options = options || {};
        if (!dialogOverlay) {
            if (window.confirm(message)) onConfirm();
            return;
        }
        dialogBox.classList.toggle("danger", !!options.danger);
        if (dialogCancel) dialogCancel.style.display = "";
        dialogTitle.textContent = options.title || "Confirmar ação";
        dialogMessage.textContent = message || "Deseja continuar?";
        dialogConfirm.textContent = options.confirmText || "Confirmar";
        dialogCancel.textContent = options.cancelText || "Cancelar";
        dialogCallback = onConfirm;
        dialogOverlay.classList.add("open");
        dialogConfirm.focus();
    };
    window.appAlert = function (message, options) {
        options = options || {};
        window.appConfirm(message, function () {}, {
            title: options.title || "Atenção",
            confirmText: "Entendi",
            cancelText: "Fechar"
        });
        if (dialogCancel) dialogCancel.style.display = "none";
    };
    if (dialogCancel) dialogCancel.addEventListener("click", function () {
        dialogCancel.style.display = "";
        closeDialog(false);
    });
    if (dialogConfirm) dialogConfirm.addEventListener("click", function () {
        dialogCancel.style.display = "";
        closeDialog(true);
    });
    if (dialogOverlay) dialogOverlay.addEventListener("click", function (event) {
        if (event.target === dialogOverlay) closeDialog(false);
    });
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && dialogOverlay && dialogOverlay.classList.contains("open")) closeDialog(false);
    });
    window.alert = function (message) { window.appAlert(String(message || "")); };

    document.addEventListener("submit", function (event) {
        var form = event.target;
        if (!form || form.dataset.appConfirmed === "1") return;
        var attr = form.getAttribute("onsubmit") || "";
        var match = attr.match(/confirm\\(([\"\\\'])(.*?)\\1\\)/);
        if (!match) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        window.appConfirm(match[2], function () {
            form.dataset.appConfirmed = "1";
            form.removeAttribute("onsubmit");
            saveScroll();
            form.submit();
        }, { danger: /excluir|zerar|desfazer|remover|sobrescreve/i.test(match[2]) });
    }, true);
    document.addEventListener("click", function (event) {
        var link = event.target.closest("a[onclick]");
        if (!link) return;
        var attr = link.getAttribute("onclick") || "";
        var match = attr.match(/confirm\\(([\"\\\'])(.*?)\\1\\)/);
        if (!match) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        window.appConfirm(match[2], function () {
            saveScroll();
            window.location.href = link.href;
        }, { danger: /excluir|desativar|remover/i.test(match[2]) });
    }, true);
})();
</script>';
}



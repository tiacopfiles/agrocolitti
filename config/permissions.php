<?php
if (defined('_PERMISSIONS_LOADED')) return;
define('_PERMISSIONS_LOADED', true);

if (!defined('PERM_ADMIN')) {
    define('PERM_ADMIN',       ['admin', 'ti']);
    define('PERM_OPERACIONAL', ['admin', 'ti', 'operacional']);
    define('PERM_OPERADOR',    ['admin', 'ti', 'operacional', 'operador']);
    define('PERM_FORNECEDOR',  ['admin', 'ti', 'operacional', 'fornecedor']);
    define('PERM_COLHEITA',    ['admin', 'ti', 'operacional', 'colheita']);
    define('PERM_QUALQUER',    ['admin', 'ti', 'operacional', 'operador', 'fornecedor', 'colheita', 'cliente']);
}

if (!defined('MODULOS_DISPONIVEIS')) {
    define('MODULOS_DISPONIVEIS', [
        'dashboard'           => 'Dashboard',
        'clientes'            => 'Clientes',
        'fornecedores'        => 'Fornecedores',
        'produtos'            => 'Produtos',
        'tabelas'             => 'Tabelas de Preco',
        'entradas'            => 'Entradas',
        'historico_compras'   => 'Historico de Compras',
        'estoque'             => 'Estoque',
        'previsao_colheita'   => 'Previsao Colheita',
        'previsao_fornecedor' => 'Previsao Fornecedor',
        'vendas'              => 'Vendas',
        'historico_vendas'    => 'Historico de Vendas',
        'ciclos'              => 'Ciclos',
        'exportar_nfe'        => 'Exportar NF-e',
        'historico_nfe'       => 'Historico de NF-e',
        'contas'              => 'Contas',
        'notas_caixas'        => 'Notas de Caixas',
        'usuarios'            => 'Gerenciar Usuarios',
    ]);
    define('MODULO_NIVEL_FALLBACK', [
        'dashboard'           => ['admin','ti','operacional','operador','cliente','colheita','fornecedor'],
        'clientes'            => ['admin','ti'],
        'fornecedores'        => ['admin','ti'],
        'produtos'            => ['admin','ti'],
        'tabelas'             => ['admin','ti'],
        'entradas'            => ['admin','ti','operacional','operador'],
        'historico_compras'   => ['admin','ti','operacional','operador'],
        'estoque'             => ['admin','ti','operacional','operador'],
        'previsao_colheita'   => ['admin','ti','operacional','colheita'],
        'previsao_fornecedor' => ['admin','ti','operacional','fornecedor'],
        'vendas'              => ['admin','ti','operacional','operador','cliente'],
        'historico_vendas'    => ['admin','ti','operacional','operador','cliente'],
        'ciclos'              => ['admin','ti','operacional'],
        'exportar_nfe'        => ['admin','ti'],
        'historico_nfe'       => ['admin','ti','operacional','operador'],
        'contas'              => ['admin','ti','operacional','operador'],
        'notas_caixas'        => ['admin','ti','operacional','operador'],
        'usuarios'            => ['admin','ti'],
    ]);
}

if (!function_exists('userHasPermission')) {
    function userHasPermission(string|array $niveisPermitidos): bool
    {
        $nivel = $_SESSION['usuario_nivel'] ?? '';
        if (is_string($niveisPermitidos)) { $niveisPermitidos = [$niveisPermitidos]; }
        return in_array($nivel, $niveisPermitidos, true);
    }
}

if (!function_exists('requirePermission')) {
    function requirePermission(string|array $niveisPermitidos, string $redirectUrl = ''): void
    {
        if (userHasPermission($niveisPermitidos)) { return; }
        http_response_code(403);
        if ($redirectUrl !== '') { header('Location: ' . $redirectUrl); exit; }
        $nivel = htmlspecialchars($_SESSION['usuario_nivel'] ?? 'desconhecido', ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html lang=pt-br><head><meta charset=UTF-8><title>Acesso Negado</title>'
            . '<style>body{font-family:Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f4f4f4;}'
            . '.box{background:white;padding:40px;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,.1);text-align:center;max-width:400px;}'
            . 'h2{color:#c62828;}a{color:#1b5e20;font-weight:bold;}</style></head><body>'
            . '<div class="box"><h2>Acesso Negado</h2>'
            . "<p>Seu nivel <strong>$nivel</strong> nao tem permissao.</p>"
            . '<p><a href="javascript:history.back()">Voltar</a></p></div></body></html>';
        exit;
    }
}

if (!function_exists('userCanAccess')) {
    function userCanAccess(string $modulo): bool
    {
        $nivel   = $_SESSION['usuario_nivel'] ?? '';
        $modulos = $_SESSION['modulos_permitidos'] ?? null;
        if ($nivel === 'ti') return true;
        if ($modulos === null) {
            $fallback = MODULO_NIVEL_FALLBACK[$modulo] ?? ['admin','ti'];
            return in_array($nivel, $fallback, true);
        }
        return in_array($modulo, (array)$modulos, true);
    }
}

if (!function_exists('requireModule')) {
    function requireModule(string $modulo, string $redirectUrl = ''): void
    {
        if (userCanAccess($modulo)) return;
        http_response_code(403);
        if ($redirectUrl !== '') { header('Location: ' . $redirectUrl); exit; }
        echo '<!DOCTYPE html><html lang=pt-br><head><meta charset=UTF-8><title>Acesso Negado</title>'
            . '<style>body{font-family:Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f4f4f4;}'
            . '.box{background:white;padding:40px;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,.1);text-align:center;max-width:400px;}'
            . 'h2{color:#c62828;}a{color:#1b5e20;font-weight:bold;}</style></head><body>'
            . '<div class="box"><h2>Acesso Negado</h2><p>Sem permissao para este modulo.</p>'
            . '<p><a href="javascript:history.back()">Voltar</a></p></div></body></html>';
        exit;
    }
}

if (!function_exists('userCanEditTabelas')) {
    function userCanEditTabelas(): bool
    {
        return ($_SESSION['usuario_nivel'] ?? '') !== 'operacional';
    }
}

if (!function_exists('requireTabelasEditPermission')) {
    function requireTabelasEditPermission(string $redirectUrl = ''): void
    {
        if (userCanEditTabelas()) return;
        http_response_code(403);
        if ($redirectUrl !== '') { header('Location: ' . $redirectUrl); exit; }
        echo '<!DOCTYPE html><html lang=pt-br><head><meta charset=UTF-8><title>Acesso Negado</title>'
            . '<style>body{font-family:Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f4f4f4;}'
            . '.box{background:white;padding:40px;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,.1);text-align:center;max-width:420px;}'
            . 'h2{color:#c62828;}a{color:#1b5e20;font-weight:bold;}</style></head><body>'
            . '<div class="box"><h2>Acesso Negado</h2><p>Seu usuario pode apenas visualizar as tabelas.</p>'
            . '<p><a href="javascript:history.back()">Voltar</a></p></div></body></html>';
        exit;
    }
}

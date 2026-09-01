<?php

function carregarDompdfSeNecessario(): void
{
    $vendor = dirname(__DIR__) . '/vendor';
    $composerAutoload = $vendor . '/autoload.php';

    if (is_file($composerAutoload)) {
        require_once $composerAutoload;
    }

    if (class_exists(\Dompdf\Options::class) && class_exists(\Dompdf\Dompdf::class)) {
        return;
    }

    $prefixos = [
        'Dompdf\\' => $vendor . '/dompdf/dompdf/src/',
        'FontLib\\' => $vendor . '/dompdf/php-font-lib/src/FontLib/',
        'Svg\\' => $vendor . '/dompdf/php-svg-lib/src/Svg/',
        'Sabberworm\\CSS\\' => $vendor . '/sabberworm/php-css-parser/src/',
        'Masterminds\\' => $vendor . '/masterminds/html5/src/',
    ];

    spl_autoload_register(static function (string $classe) use ($prefixos): void {
        if ($classe === 'Dompdf\\Cpdf') {
            $arquivo = dirname(__DIR__) . '/vendor/dompdf/dompdf/lib/Cpdf.php';
            if (is_file($arquivo)) {
                require_once $arquivo;
            }
            return;
        }

        foreach ($prefixos as $prefixo => $baseDir) {
            if (strncmp($classe, $prefixo, strlen($prefixo)) !== 0) {
                continue;
            }

            $relativo = substr($classe, strlen($prefixo));
            $arquivo = $baseDir . str_replace('\\', '/', $relativo) . '.php';
            if (is_file($arquivo)) {
                require_once $arquivo;
            }
            return;
        }
    }, true, true);

    spl_autoload_register(static function (string $classe) use ($vendor): void {
        $prefixo = 'Safe\\Exceptions\\';
        if (strncmp($classe, $prefixo, strlen($prefixo)) !== 0) {
            return;
        }

        $nome = substr($classe, strlen($prefixo));
        $arquivos = [
            $vendor . '/thecodingmachine/safe/lib/Exceptions/' . $nome . '.php',
            $vendor . '/thecodingmachine/safe/generated/Exceptions/' . $nome . '.php',
        ];

        foreach ($arquivos as $arquivo) {
            if (is_file($arquivo)) {
                require_once $arquivo;
                return;
            }
        }
    }, true, true);

    $composerFiles = $vendor . '/composer/autoload_files.php';
    if (is_file($composerFiles)) {
        $arquivos = require $composerFiles;
        foreach ($arquivos as $arquivo) {
            if (is_file($arquivo)) {
                require_once $arquivo;
            }
        }
    }

}

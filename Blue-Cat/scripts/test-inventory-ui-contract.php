<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$javascript = (string)file_get_contents($root . '/assets/js/inventario.js');

$assertions = [
    'el frontend preserva cantidades decimales' => str_contains($javascript, 'Number.parseFloat(n)'),
    'el ingreso rápido declara precisión de 0.001' => str_contains($javascript, 'id="qs-cant"') && str_contains($javascript, 'step="0.001"'),
    'las transferencias declaran precisión de 0.001' => substr_count($javascript, 'class="trf-cant"') >= 2 && substr_count($javascript, 'class="trf-cant" min="0.001" step="0.001"') >= 2,
    'los ajustes declaran precisión de 0.001' => str_contains($javascript, 'id="aj-cantidad" min="0" step="0.001"'),
    'los lotes preservan cantidades decimales' => str_contains($javascript, 'id="lote-cantidad" min="0.001" step="0.001"'),
    'no se ofrece conteo por categoría sin filtro implementado' => !str_contains($javascript, 'value="CATEGORIA"'),
];

foreach ($assertions as $message => $condition) {
    if (!$condition) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
    echo "PASS {$message}\n";
}

echo "Contrato decimal de inventario válido.\n";

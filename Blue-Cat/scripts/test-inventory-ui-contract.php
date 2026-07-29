<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$javascript = (string)file_get_contents($root . '/assets/js/inventario.js');
$api = (string)file_get_contents($root . '/assets/api/inventario.php');

$assertions = [
    'el frontend preserva cantidades decimales' => str_contains($javascript, 'Number.parseFloat(n)'),
    'el ingreso rápido declara precisión de 0.001' => str_contains($javascript, 'id="qs-cant"') && str_contains($javascript, 'step="0.001"'),
    'el ingreso rápido exige bodega explícita' => str_contains($javascript, 'id="qs-bodega"') && str_contains($javascript, "id_bodega: _stockBodega"),
    'el ingreso rápido no mezcla costo con existencias' => !str_contains($javascript, 'id="qs-costo"'),
    'el alta declara bodega para stock inicial' => str_contains($javascript, 'id="f-bodega"') && str_contains($javascript, 'Seleccione la bodega activa del stock inicial'),
    'costo y precio tienen un flujo respaldado separado' => str_contains($javascript, 'showPriceCostForm') && str_contains($javascript, 'documento_referencia') && str_contains($javascript, "api('producto_actualizar_precios'"),
    'el impuesto inicial proviene de la configuración del tenant' => str_contains($javascript, "num(p.iva_porcentaje)") && !str_contains($javascript, 'id="pc-impuesto" min="0" max="100" step="0.01" value="19"'),
    'el backend recalcula neto e impuesto' => str_contains($api, "case 'producto_actualizar_precios':") && str_contains($api, '$costoNeto = round($costoBruto / $factor, 2);') && str_contains($api, "'stock_alterado'=>false"),
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

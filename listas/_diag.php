<?php
/**
 * Diagnóstico temporal para investigar por qué el parseo del PDF de listas
 * se cuelga en producción (Clever Cloud) mientras que localmente, con PHP
 * real, el mismo archivo/código corre en ~250ms. Borrar una vez resuelto.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

set_time_limit(0);
@ob_implicit_flush(1);

echo "<pre style=\"font-family:monospace; white-space:pre-wrap;\">\n";

echo "=== Entorno ===\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "SAPI: " . php_sapi_name() . "\n";
echo "memory_limit: " . ini_get('memory_limit') . "\n";
echo "max_execution_time: " . ini_get('max_execution_time') . "\n";
echo "opcache.enable: " . (ini_get('opcache.enable') ?: '(no disponible)') . "\n";
echo "xdebug cargado: " . (extension_loaded('xdebug') ? 'SI - mode=' . ini_get('xdebug.mode') : 'no') . "\n";
echo "zlib cargado: " . (extension_loaded('zlib') ? 'si' : 'NO') . "\n";
echo "extensiones: " . implode(', ', get_loaded_extensions()) . "\n\n";
flush();

echo "=== Test 1: loop CPU puro (sin dependencias) ===\n";
$t0 = microtime(true);
$acc = 0;
for ($i = 0; $i < 5_000_000; $i++) { $acc += $i % 7; }
printf("5M iteraciones simples: %.1fms (acc=%d)\n\n", (microtime(true) - $t0) * 1000, $acc);
flush();

echo "=== Test 2: indexado de string char-by-char (como el tokenizer) ===\n";
$s = str_repeat("Hola Mundo 123 (test) /Name Tj\n", 30000); // ~930KB, similar orden de magnitud
$n = strlen($s);
$t0 = microtime(true);
$count = 0;
for ($i = 0; $i < $n; $i++) {
    $c = $s[$i];
    if ($c === ' ') $count++;
}
printf("recorrido char-by-char de %d bytes: %.1fms\n\n", $n, (microtime(true) - $t0) * 1000);
flush();

echo "=== Test 3: parser real contra un PDF subido en este mismo request ===\n";
require_once __DIR__ . '/_parser_proveedor.php';
$archivoTmp = $_FILES['pdf']['tmp_name'] ?? null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $archivoTmp && is_uploaded_file($archivoTmp)) {
    $raw = file_get_contents($archivoTmp);
    echo "bytes del PDF: " . strlen($raw) . "\n";
    flush();
    $t0 = microtime(true);
    $productos = parsearPDFCatalogoProveedor($raw, function(string $etapa, array $datos) use (&$t0) {
        $ahora = microtime(true);
        $extra = [];
        foreach ($datos as $k => $v) $extra[] = "{$k}={$v}";
        printf("  [%.1fms] %s%s\n", ($ahora - $t0) * 1000, $etapa, $extra ? ' (' . implode(', ', $extra) . ')' : '');
        flush();
        $t0 = $ahora;
    });
    echo "productos encontrados: " . count($productos) . "\n";
} else {
    echo "(no se subio ningun PDF en este request -- no se persiste nada en git,\n";
    echo " es un simple formulario que sube el archivo directo a este script)\n";
}

echo "\n=== FIN ===\n";
echo "</pre>";
?>
<hr>
<form method="POST" enctype="multipart/form-data" style="font-family:sans-serif; padding:12px;">
    <input type="file" name="pdf" accept=".pdf">
    <button type="submit">Probar parser con este PDF</button>
</form>

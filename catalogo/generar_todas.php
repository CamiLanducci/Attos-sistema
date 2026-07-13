<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

set_time_limit(0);
ini_set('memory_limit', '-1');

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    $pageTitle = 'Error — mPDF no instalado';
    require_once __DIR__ . '/../config/layout.php';
    echo '<div class="alert alert-warning" style="max-width:560px;">
        <strong>mPDF no está instalado.</strong><br>
        Corré <code>composer install</code> en <code>c:\xampp\htdocs\Attos\</code> y volvé a intentarlo.
        <br><br><a href="' . BASE_PATH . '/catalogo/" class="btn btn-secondary btn-sm">← Volver</a>
    </div>';
    require_once __DIR__ . '/../config/layout_end.php';
    exit;
}

require_once $autoload;
require_once __DIR__ . '/_pdf_helper.php';

ini_set('pcre.backtrack_limit', 50000000);
ini_set('memory_limit', '-1');

$db = getDB();

$modo         = in_array($_POST['modo'] ?? '', ['caja','unidad','ambos']) ? $_POST['modo'] : 'ambos';
$marcasFiltro = array_values(array_filter(array_map('trim', $_POST['marcas'] ?? [])));

$listas = $db->query("SELECT * FROM listas ORDER BY margen ASC")->fetchAll();
if (empty($listas)) redirect(BASE_PATH . '/catalogo/');

try {
    $db->query("SELECT mostrar_precio FROM productos LIMIT 0");
    $tieneMostrarPrecio = true;
} catch (PDOException $e) {
    $tieneMostrarPrecio = false;
}

$fontConfig = setupFontsConfig();

$meses  = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$mes    = $meses[intval(date('n')) - 1];
$sufijos = [10 => 'Mayorista', 15 => 'Flia', 20 => 'ClientesFieles', 25 => ''];

$tmpFile = tempnam(sys_get_temp_dir(), 'attos_zip_');
$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die('No se pudo crear el archivo ZIP.');
}

foreach ($listas as $lista) {
    $pdf    = buildCatalogoPDF($db, $lista, 'completo', 0, $modo, $marcasFiltro, $tieneMostrarPrecio, $fontConfig);
    $sufijo = $sufijos[(int)$lista['margen']] ?? $lista['codigo'];
    $nombre = 'Attos-Lista-' . $mes . ($sufijo !== '' ? '-' . $sufijo : '') . '.pdf';
    $zip->addFromString($nombre, $pdf);
}

$zip->close();

$zipNombre = 'Attos-Listas-' . $mes . '-' . date('Y') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipNombre . '"');
header('Content-Length: ' . filesize($tmpFile));
readfile($tmpFile);
unlink($tmpFile);

<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

// ── Verificar mPDF instalado ───────────────────────────────────────────────────
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    $pageTitle = 'Error — mPDF no instalado';
    require_once __DIR__ . '/../config/layout.php';
    echo '<div class="alert alert-warning" style="max-width:560px;">
        <strong>mPDF no está instalado.</strong><br>
        Corré <code>composer install</code> en <code>c:\xampp\htdocs\Attos\</code> y volvé a intentarlo.
        <br><br><a href="/attos/catalogo/" class="btn btn-secondary btn-sm">← Volver</a>
    </div>';
    require_once __DIR__ . '/../config/layout_end.php';
    exit;
}

require_once $autoload;

ini_set('pcre.backtrack_limit', 50000000);
ini_set('memory_limit', '-1');

use Mpdf\Mpdf;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

// ── Parámetros ─────────────────────────────────────────────────────────────────
$db       = getDB();
$lista_id = (int)($_POST['lista_id'] ?? $_GET['lista_id'] ?? 0);
if (!$lista_id) redirect('/attos/catalogo/');

$lista = $db->prepare("SELECT * FROM listas WHERE id=?");
$lista->execute([$lista_id]);
$lista = $lista->fetch();
if (!$lista) redirect('/attos/catalogo/');

$tipo         = in_array($_POST['tipo'] ?? '', ['completo','filtrado']) ? $_POST['tipo'] : 'completo';
$precio_min   = $tipo === 'filtrado' ? max(0, (float)($_POST['precio_min'] ?? 20000)) : 0;
$modo         = in_array($_POST['modo'] ?? '', ['caja','unidad','ambos']) ? $_POST['modo'] : 'ambos';
$marcasFiltro = array_values(array_filter(array_map('trim', $_POST['marcas'] ?? [])));
$margen       = (float)$lista['margen'];
$esFiltrado   = $tipo === 'filtrado';

// ── Detectar columna mostrar_precio ────────────────────────────────────────────
try {
    $db->query("SELECT mostrar_precio FROM productos LIMIT 0");
    $tieneMostrarPrecio = true;
} catch (PDOException $e) {
    $tieneMostrarPrecio = false;
}

// ── Query de productos ─────────────────────────────────────────────────────────
$selectMp = $tieneMostrarPrecio ? 'p.mostrar_precio' : '1 AS mostrar_precio';
$sql = "
    SELECT p.id, p.nombre, p.marca, p.categoria, p.codigo, p.unidades_por_caja,
           p.precio_por_pack, p.contenido, lp.costo, {$selectMp}
    FROM productos p
    JOIN lista_precios lp ON lp.producto_id = p.id AND lp.lista_id = ?
    WHERE p.activo = 1
";
$params = [$lista_id];

if (!empty($marcasFiltro)) {
    $ph     = implode(',', array_fill(0, count($marcasFiltro), '?'));
    $sql   .= " AND p.marca IN ({$ph})";
    $params = array_merge($params, $marcasFiltro);
}
$sql .= " ORDER BY p.marca COLLATE utf8mb4_unicode_ci ASC, p.nombre COLLATE utf8mb4_unicode_ci ASC";

$stmtProds = $db->prepare($sql);
$stmtProds->execute($params);
$rawProds  = $stmtProds->fetchAll();

// ── Cálculo de precios ─────────────────────────────────────────────────────────
$productos = [];
foreach ($rawProds as $p) {
    $upc       = max(1, (int)$p['unidades_por_caja']);
    $esGaseosa = esGaseosaOEnergizante($p['marca'] ?? '');
    if ($esGaseosa) {
        if ($p['precio_por_pack']) {
            $p['precio_caja'] = (float)$p['costo'] * (1 + $margen / 100);
            $p['precio_unit'] = $p['precio_caja'] / $upc;
        } else {
            $p['precio_unit'] = (float)$p['costo'] * (1 + $margen / 100);
            $p['precio_caja'] = $p['precio_unit'] * $upc;
        }
    } else {
        if ($p['precio_por_pack'] || esCerveza($p['marca'] ?? '')) {
            $p['precio_caja'] = (float)$p['costo'];
            $p['precio_unit'] = $p['precio_caja'] / $upc;
        } else {
            $p['precio_unit'] = (float)$p['costo'];
            $p['precio_caja'] = $p['precio_unit'] * $upc;
        }
    }

    if ($esFiltrado) {
        $cat = strtolower(trim($p['categoria'] ?? ''));
        if ($p['precio_unit'] <= $precio_min && $cat !== 'aceite') continue;
    }

    $productos[] = $p;
}

// ── Agrupar por marca ──────────────────────────────────────────────────────────
$byMarca = [];
foreach ($productos as $p) {
    $byMarca[trim($p['marca'] ?: 'Sin marca')][] = $p;
}
uksort($byMarca, fn($a, $b) => mb_strtolower($a, 'UTF-8') <=> mb_strtolower($b, 'UTF-8'));

// Unificar todas las variantes de espumantes en una sola sección "Espumantes",
// excepto las que contengan "importad" (ej: "espumantes importados (pedi por unidad)")
$espumantesPool = [];
foreach ($byMarca as $marcaKey => $prods) {
    $lower = mb_strtolower($marcaKey, 'UTF-8');
    if (strpos($lower, 'espumante') !== false && strpos($lower, 'importad') === false) {
        $espumantesPool = array_merge($espumantesPool, $prods);
        unset($byMarca[$marcaKey]);
    }
}
if (!empty($espumantesPool)) {
    $byMarca['Espumantes'] = $espumantesPool;
    uksort($byMarca, fn($a, $b) => mb_strtolower($a, 'UTF-8') <=> mb_strtolower($b, 'UTF-8'));
}

// ── Clasificar marcas en secciones del catálogo ────────────────────────────
function clasificarMarca(string $marca): string {
    $m = mb_strtolower(trim($marca), 'UTF-8');
    if (strpos($m, 'aceite') !== false) return 'Aceites';
    if (strpos($m, 'espumante') !== false) return 'Espumantes';
    if (strpos($m, 'cerveza') !== false) return 'Cervezas';
    if (strpos($m, 'whisky') !== false || strpos($m, 'whiskey') !== false) return 'Whiskeys';
    if (strpos($m, 'aperitiv') !== false) return 'Aperitivos';
    if (strpos($m, 'vodka') !== false  || strpos($m, 'tequila') !== false
        || strpos($m, 'fernet') !== false || strpos($m, 'licor') !== false
        || strpos($m, 'brandy') !== false || strpos($m, 'brandys') !== false
        || strpos($m, 'oporto') !== false
        || strpos($m, 'cóctel') !== false || strpos($m, 'coctel') !== false
        || strpos($m, 'petaca') !== false || strpos($m, 'miniatura') !== false
        || strpos($m, 'vinos generosos') !== false
        || strpos($m, 'gin (') !== false  || $m === 'gin'
        || strpos($m, 'ron (') !== false  || $m === 'ron') return 'Destilados y Más';
    if (strpos($m, 'energizante') !== false || strpos($m, 'gaseosa') !== false
        || strpos($m, 'jugo') !== false   || strpos($m, 'sidra') !== false) return 'Bebidas';
    return 'Bodegas';
}

// Nombre limpio para el índice (sin "( pedi por unidad )", con title case)
function getTocName(string $marca, string $catNombre): string {
    $ml = mb_strtolower(trim($marca), 'UTF-8');
    if ($ml === 'espumantes') return 'Espumantes Nacionales';
    if (strpos($ml, 'espumante') !== false && strpos($ml, 'importad') !== false) return 'Espumantes Importados';
    $nombre = preg_replace('/\s*\(\s*pedi por unidad\s*\)/i', '', $marca);
    $nombre = trim($nombre);
    if ($catNombre === 'Whiskeys') {
        $nombre = preg_replace('/^whiskys?\s+/i', '', $nombre);
    }
    return mb_convert_case($nombre, MB_CASE_TITLE, 'UTF-8');
}

$categoriaOrder = ['Bodegas', 'Espumantes', 'Cervezas', 'Whiskeys', 'Aperitivos', 'Destilados y Más', 'Aceites', 'Bebidas'];
$byCategoria = [];
foreach ($byMarca as $marca => $prods) {
    $cat = clasificarMarca($marca);
    $byCategoria[$cat][$marca] = $prods;
}
uksort($byCategoria, function($a, $b) use ($categoriaOrder) {
    $ia = array_search($a, $categoriaOrder) ?? 999;
    $ib = array_search($b, $categoriaOrder) ?? 999;
    return $ia <=> $ib;
});
$byCategoria = array_filter($byCategoria);

// ── Fechas ─────────────────────────────────────────────────────────────────────
// fechaPublica: solo mes+año para documentos que ve el cliente (sin día exacto)
$meses        = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$fechaPublica = 'Precios - ' . $meses[intval(date('n')) - 1] . ' ' . date('Y');
$fechaPortada = $fechaPublica;
$fechaHeader  = $fechaPublica;
$listaLabel   = $esFiltrado ? 'Selección Premium' : '';

// ── Logo ───────────────────────────────────────────────────────────────────────
$logoSrc = '';
foreach ([
    __DIR__ . '/../assets/img/logo.png',
    __DIR__ . '/assets/logo.png',
] as $logoPath) {
    if (file_exists($logoPath)) {
        $logoSrc = realpath($logoPath);
        break;
    }
}

// ── Fuentes ────────────────────────────────────────────────────────────────────
$fontDir = __DIR__ . '/assets/fonts/';
$defaultCfg  = (new ConfigVariables())->getDefaults();
$fontDirs    = $defaultCfg['fontDir'];
$defaultFCfg = (new FontVariables())->getDefaults();
$fontData    = $defaultFCfg['fontdata'];

$hasInter     = is_dir($fontDir) && file_exists($fontDir . 'Inter-Regular.ttf');
$hasPlayfair  = is_dir($fontDir) && file_exists($fontDir . 'PlayfairDisplay-Regular.ttf');

if ($hasInter || $hasPlayfair) {
    $fontDirs[] = $fontDir;
}
if ($hasInter) {
    $fontData['inter'] = [
        'R' => 'Inter-Regular.ttf',
        'B' => 'Inter-Bold.ttf',
        'I' => file_exists($fontDir . 'Inter-Italic.ttf') ? 'Inter-Italic.ttf' : 'Inter-Regular.ttf',
    ];
}
if ($hasPlayfair) {
    $fontData['playfair'] = [
        'R' => 'PlayfairDisplay-Regular.ttf',
        'B' => file_exists($fontDir . 'PlayfairDisplay-Bold.ttf') ? 'PlayfairDisplay-Bold.ttf' : 'PlayfairDisplay-Regular.ttf',
    ];
}

$bodyFont    = $hasInter    ? 'inter'    : 'dejavusans';
$headingFont = $hasPlayfair ? 'playfair' : 'dejavuserif';

// ── mPDF ───────────────────────────────────────────────────────────────────────
$mpdf = new Mpdf([
    'format'        => 'A4',
    'margin_left'   => 20,
    'margin_right'  => 20,
    'margin_top'    => 28,
    'margin_bottom' => 22,
    'margin_header' => 8,
    'margin_footer' => 6,
    'default_font'  => $bodyFont,
    'fontDir'       => $fontDirs,
    'fontdata'      => $fontData,
    'basepath'      => realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR,
]);

$mpdf->SetTitle('Catálogo ATTOS — ' . $lista['codigo']);
$mpdf->SetAuthor('ATTOS Distribuidora');
$mpdf->SetCreator('ATTOS');

// ── HTML ───────────────────────────────────────────────────────────────────────
ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
* { margin:0; padding:0; box-sizing:border-box; }

body {
    font-family: <?= $bodyFont ?>, dejavusans, sans-serif;
    font-size: 10pt;
    color: #1A1A1A;
    line-height: 1.5;
}

/* ── Portada ─────────────────────────────────── */
.cover-bar-top {
    width: 100%;
    background-color: #631636;
    height: 14mm;
}
.cover-body {
    text-align: center;
    padding-top: 38mm;
    padding-bottom: 16mm;
}
.cover-logo-text {
    font-family: <?= $headingFont ?>, dejavuserif, serif;
    font-size: 48pt;
    font-weight: 700;
    color: #631636;
    letter-spacing: 8px;
    line-height: 1;
}
.cover-distributor {
    font-size: 8pt;
    letter-spacing: 4px;
    color: #aaa;
    text-transform: uppercase;
    margin-top: 3mm;
}
.cover-rule {
    width: 36mm;
    border: none;
    border-top: 0.3mm solid #ddd;
    margin: 10mm auto;
}
.cover-title {
    font-family: <?= $headingFont ?>, dejavuserif, serif;
    font-size: 22pt;
    color: #1A1A1A;
    margin-bottom: 4mm;
}
.cover-sublabel {
    font-family: <?= $headingFont ?>, dejavuserif, serif;
    font-size: 13pt;
    color: #631636;
    font-style: italic;
    margin-bottom: 10mm;
}
.cover-date {
    font-size: 10pt;
    color: #888;
}
.cover-bar-bottom {
    width: 100%;
    background-color: #631636;
    height: 8mm;
    margin-top: 20mm;
}
.cover-foot {
    text-align: center;
    font-size: 7.5pt;
    color: #aaa;
    letter-spacing: 2px;
    text-transform: uppercase;
    margin-top: 4mm;
}

/* ── Secciones de marca ──────────────────────── */
.brand-heading {
    font-family: <?= $headingFont ?>, dejavuserif, serif;
    font-size: 15pt;
    font-weight: 700;
    color: #631636;
    border-bottom: 0.4mm solid #631636;
    padding-bottom: 2mm;
    margin-top: 10mm;
    margin-bottom: 4mm;
    page-break-after: avoid;
}

/* ── Tabla de productos ──────────────────────── */
table.prods {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 4mm;
}
table.prods thead td {
    background-color: #631636;
    color: #FAF6EF;
    padding: 3mm 2.5mm;
    font-size: 8pt;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
table.prods tbody td {
    padding: 2.5mm 2.5mm;
    border-bottom: 0.15mm solid #EAE4DC;
    font-size: 9.5pt;
    vertical-align: middle;
}
.td-alt { background-color: #FAF6EF; }
.col-cod  { color: #888; font-size: 8.5pt; width: 20mm; }
.col-pack { text-align: center; width: 14mm; color: #888; font-size: 9pt; }
.col-precio-caja { text-align: right; width: 34mm; font-weight: 700; font-size: 10.5pt; color: #1A1A1A; }
.col-precio-unit { text-align: right; width: 32mm; font-size: 9pt; color: #555; }
.precio-consulta { color: #631636; font-style: italic; font-size: 9pt; }

/* ── Contratapa ──────────────────────────────── */
.backcover {
    text-align: center;
    padding-top: 60mm;
}
.backcover-title {
    font-family: <?= $headingFont ?>, dejavuserif, serif;
    font-size: 24pt;
    font-weight: 700;
    color: #631636;
    margin-bottom: 6mm;
}
.backcover-sub {
    font-size: 11pt;
    color: #888;
    line-height: 2;
    margin-bottom: 10mm;
}
.backcover-disclaimer {
    font-size: 8pt;
    color: #aaa;
    line-height: 1.8;
}

/* ── Encabezado de categoría ─────────────── */
.cat-heading {
    font-family: <?= $headingFont ?>, dejavuserif, serif;
    font-size: 13pt;
    font-weight: 700;
    color: #FAF6EF;
    background-color: #631636;
    padding: 3mm 5mm;
    margin-top: 14mm;
    margin-bottom: 0;
    letter-spacing: 2px;
    text-transform: uppercase;
    page-break-after: avoid;
}
.brand-heading {
    margin-top: 6mm;
}

/* ── Índice ──────────────────────────────── */
.index-heading {
    font-family: <?= $headingFont ?>, dejavuserif, serif;
    font-size: 20pt;
    font-weight: 700;
    color: #631636;
    border-bottom: 0.4mm solid #631636;
    padding-bottom: 3mm;
    margin-bottom: 10mm;
}
.idx-cat {
    font-family: <?= $headingFont ?>, dejavuserif, serif;
    font-size: 11pt;
    font-weight: 700;
    color: #631636;
    border-bottom: 0.2mm solid #631636;
    padding-bottom: 1mm;
    margin-top: 7mm;
    margin-bottom: 2mm;
    page-break-after: avoid;
}
.idx-name {
    font-size: 9.5pt;
    color: #1A1A1A;
    padding: 0.6mm 3mm;
    line-height: 1.55;
}
</style>
</head>
<body>

<!-- ── Header mPDF (páginas 2+) ──────────────────────────────────────────── -->
<htmlpageheader name="hdr">
<table style="width:100%; border-bottom:0.4mm solid #631636; padding-bottom:1.5mm;" cellpadding="0">
    <tr>
        <td style="font-family:<?= $headingFont ?>,dejavuserif,serif; font-size:10pt; font-weight:700; color:#631636; letter-spacing:2px;">ATTOS</td>
        <td style="text-align:right; font-size:8pt; color:#888; font-family:<?= $bodyFont ?>,dejavusans,sans-serif;">Catálogo de Productos · <?= $fechaHeader ?></td>
    </tr>
</table>
</htmlpageheader>

<!-- ── Footer mPDF (páginas 2+) ──────────────────────────────────────────── -->
<htmlpagefooter name="ftr">
<table style="width:100%; border-top:0.3mm solid #E0D8CE; padding-top:1.5mm;" cellpadding="0">
    <tr>
        <td style="font-size:8pt; color:#888; font-family:<?= $bodyFont ?>,dejavusans,sans-serif;">ATTOS Distribuidora · La Plata, Buenos Aires</td>
        <td style="text-align:right; font-size:8pt; color:#888; font-family:<?= $bodyFont ?>,dejavusans,sans-serif;">Página {PAGENO} de {nbpg}</td>
    </tr>
</table>
</htmlpagefooter>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- PORTADA (sin header/footer — aún no activados)                           -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<table width="100%" cellpadding="0" cellspacing="0">
    <tr><td class="cover-bar-top"></td></tr>
</table>

<div class="cover-body">
    <div class="cover-logo-text">ATTOS</div>
    <div class="cover-distributor">Distribuidora de Bebidas · La Plata, Buenos Aires</div>

    <table width="36mm" cellpadding="0" cellspacing="0" style="margin:10mm auto; border-top:0.3mm solid #ddd;"><tr><td></td></tr></table>

    <div class="cover-title">Nuestro Catálogo</div>
    <?php if ($listaLabel): ?><div class="cover-sublabel"><?= e($listaLabel) ?></div><?php endif; ?>
    <div class="cover-date"><?= $fechaPortada ?></div>
</div>

<table width="100%" cellpadding="0" cellspacing="0">
    <tr><td class="cover-bar-bottom"></td></tr>
</table>
<div class="cover-foot">ATTOS Distribuidora · La Plata, Buenos Aires</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- CONTENIDO — activar header/footer desde página 2                         -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<pagebreak />
<sethtmlpageheader name="hdr" page="ALL" value="on" show-this-page="1" />
<sethtmlpagefooter name="ftr" page="ALL" value="on" show-this-page="1" />
<?php
// ── Split: escribir la portada y luego insertar índice vía PHP API ─────────
$html = ob_get_clean();
$mpdf->WriteHTML($html);

$mpdf->TOCpagebreakByArray([
    'paging'               => true,
    'links'                => true,
    'toc-preHTML'          => '<div class="index-heading">Índice</div>',
    'toc-odd-header-name'  => 'hdr',
    'toc-even-header-name' => 'hdr',
    'toc-odd-footer-name'  => 'ftr',
    'toc-even-footer-name' => 'ftr',
    'toc-odd-header-value'  => 1,
    'toc-even-header-value' => 1,
    'toc-odd-footer-value'  => 1,
    'toc-even-footer-value' => 1,
]);

ob_start();
?>
<?php if (empty($byCategoria)): ?>
<p style="text-align:center; color:#888; font-style:italic; padding:40mm 0;">
    No hay productos que cumplan los criterios del catálogo.
</p>
<?php else: ?>

<?php
$catsTocDetallado = ['Bodegas', 'Espumantes', 'Whiskeys'];
foreach ($byCategoria as $catNombre => $marcasEnCat):
  $catLower   = mb_strtolower($catNombre, 'UTF-8');
  $catDisplay = mb_strtoupper(mb_substr($catLower, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($catLower, 1, null, 'UTF-8');
  $tocDetallado = in_array($catNombre, $catsTocDetallado);
?>
<tocentry content="<?= e($catNombre) ?>" level="0" />
<div class="cat-heading"><?= e($catDisplay) ?></div>

<?php foreach ($marcasEnCat as $marca => $prods):
  usort($prods, function($a, $b) { return $a['precio_unit'] <=> $b['precio_unit']; });
  $colsPrecio       = $modo === 'ambos' ? 2 : 1;
  $texto_formateado = mb_convert_case($marca, MB_CASE_TITLE, 'UTF-8');
  $mismaNombreQueCategoria = mb_strtolower($texto_formateado, 'UTF-8') === mb_strtolower($catNombre, 'UTF-8');
  $tocName = getTocName($marca, $catNombre);
?>
<?php if ($tocDetallado && mb_strtolower(trim($marca), 'UTF-8') !== 'zzz'): ?>
<tocentry content="<?= e($tocName) ?>" level="1" />
<?php endif; ?>
<?php if (!$mismaNombreQueCategoria): ?>
<div class="brand-heading"><?= e($texto_formateado) ?></div>
<?php endif; ?>
<table class="prods" cellpadding="0" cellspacing="0">
    <thead>
        <tr>
            <td class="col-cod" style="background-color:#631636; color:#FAF6EF; padding:3mm 2.5mm; font-size:8pt; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Código</td>
            <td style="background-color:#631636; color:#FAF6EF; padding:3mm 2.5mm; font-size:8pt; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Producto</td>
            <td class="col-pack" style="background-color:#631636; color:#FAF6EF; padding:3mm 2.5mm; font-size:8pt; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; text-align:center;">Pack</td>
            <?php if ($modo !== 'unidad'): ?>
            <td class="col-precio-caja" style="background-color:#631636; color:#FAF6EF; padding:3mm 2.5mm; font-size:8pt; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; text-align:right;">Precio caja</td>
            <?php endif; ?>
            <?php if ($modo !== 'caja'): ?>
            <td class="col-precio-unit" style="background-color:#631636; color:#FAF6EF; padding:3mm 2.5mm; font-size:8pt; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; text-align:right;">Precio unit.</td>
            <?php endif; ?>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($prods as $i => $p):
        $alt = $i % 2 === 1 ? ' class="td-alt"' : '';
    ?>
        <tr>
            <td<?= $alt ?> class="col-cod" style="<?= $i % 2 === 1 ? 'background-color:#FAF6EF;' : '' ?> padding:2.5mm 2.5mm; border-bottom:0.15mm solid #EAE4DC; color:#888; font-size:8.5pt;"><?= e($p['codigo'] ?: '—') ?></td>
            <td<?= $alt ?> style="<?= $i % 2 === 1 ? 'background-color:#FAF6EF;' : '' ?> padding:2.5mm 2.5mm; border-bottom:0.15mm solid #EAE4DC; font-size:9.5pt; font-weight:500;"><?= e($p['nombre']) ?><?php if (!empty($p['contenido'])): ?><br><span style="font-size:8pt; color:#aaa; font-weight:400;"><?= e($p['contenido']) ?></span><?php endif; ?></td>
            <td<?= $alt ?> style="<?= $i % 2 === 1 ? 'background-color:#FAF6EF;' : '' ?> padding:2.5mm 2.5mm; border-bottom:0.15mm solid #EAE4DC; text-align:center; font-size:9pt; color:#888;"><?= (int)$p['unidades_por_caja'] ?></td>
            <?php if ((int)$p['mostrar_precio']): ?>
                <?php if ($modo !== 'unidad'): ?>
                <td<?= $alt ?> style="<?= $i % 2 === 1 ? 'background-color:#FAF6EF;' : '' ?> padding:2.5mm 2.5mm; border-bottom:0.15mm solid #EAE4DC; text-align:right; font-size:10.5pt; font-weight:700; color:#1A1A1A;"><?= precio($p['precio_caja']) ?></td>
                <?php endif; ?>
                <?php if ($modo !== 'caja'): ?>
                <td<?= $alt ?> style="<?= $i % 2 === 1 ? 'background-color:#FAF6EF;' : '' ?> padding:2.5mm 2.5mm; border-bottom:0.15mm solid #EAE4DC; text-align:right; font-size:9pt; color:#555;"><?= precio($p['precio_unit']) ?></td>
                <?php endif; ?>
            <?php else: ?>
                <td<?= $alt ?> colspan="<?= $colsPrecio ?>" style="<?= $i % 2 === 1 ? 'background-color:#FAF6EF;' : '' ?> padding:2.5mm 2.5mm; border-bottom:0.15mm solid #EAE4DC; text-align:center;">
                    <span class="precio-consulta">Consultar</span>
                </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endforeach; // end brands ?>
<?php endforeach; // end categories ?>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- CONTRATAPA                                                                 -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<pagebreak />
<div class="backcover">
    <div class="backcover-title">ATTOS Distribuidora</div>
    <table width="30mm" cellpadding="0" cellspacing="0" style="margin:0 auto 6mm; border-top:0.4mm solid #631636;"><tr><td></td></tr></table>
    <div class="backcover-sub">La Plata, Buenos Aires</div>
    <table width="40mm" cellpadding="0" cellspacing="0" style="margin:0 auto 8mm; border-top:0.3mm solid #E0D8CE;"><tr><td></td></tr></table>
    <div class="backcover-disclaimer">
        Los precios son en pesos argentinos e incluyen IVA.<br>
        Los precios están sujetos a cambios sin previo aviso.<br><br>
        Para consultar precios de productos marcados como "Consultar",<br>
        contactanos directamente.
    </div>
</div>

</body>
</html>
<?php
$html = ob_get_clean();
$mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);
$meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$mes   = $meses[intval(date('n')) - 1];
$sufijos = [10 => 'Mayorista', 15 => 'Flia', 20 => 'ClientesFieles', 25 => ''];
$sufijo  = $sufijos[(int)$lista['margen']] ?? '';
$nombre  = 'Attos-Lista-' . $mes . ($sufijo !== '' ? '-' . $sufijo : '') . '.pdf';
$mpdf->Output($nombre, 'I');

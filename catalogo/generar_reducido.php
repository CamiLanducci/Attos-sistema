<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

set_time_limit(0);
ini_set('memory_limit', '-1');

// Verificar mPDF
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    redirect(BASE_PATH . '/catalogo/reducido.php?error=nompdf');
}
require_once $autoload;

use Mpdf\Mpdf;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

// ── Validar inputs ──────────────────────────────────────────────────────────
$lista_id   = (int)($_POST['lista_id'] ?? 0);
$batchToken = trim($_POST['batch_token'] ?? '');

if (!$lista_id || !$batchToken) redirect(BASE_PATH . '/catalogo/reducido.php');

if (!preg_match('/^[a-f0-9]{32}$/', $batchToken) || $batchToken !== ($_SESSION['catalogo_reducido_token'] ?? '')) {
    redirect(BASE_PATH . '/catalogo/reducido.php?error=token');
}

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM listas WHERE id = ?");
$stmt->execute([$lista_id]);
$lista = $stmt->fetch();
if (!$lista) redirect(BASE_PATH . '/catalogo/reducido.php');

$margen = (float)$lista['margen'];

// ── Leer imágenes del directorio temporal ───────────────────────────────────
$tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $batchToken;
if (!is_dir($tmpDir)) redirect(BASE_PATH . '/catalogo/reducido.php?error=noimgs');

$imageFiles = glob($tmpDir . DIRECTORY_SEPARATOR . '*.{jpg,jpeg,png,JPG,JPEG,PNG}', GLOB_BRACE) ?: [];
if (empty($imageFiles)) redirect(BASE_PATH . '/catalogo/reducido.php?error=noimgs');

// Ordenar por nombre de archivo (preserva el orden lógico por código)
usort($imageFiles, fn($a, $b) => strnatcasecmp(basename($a), basename($b)));

// ── Procesar cada imagen: extraer código → consultar BD ─────────────────────
$productos = [];

foreach ($imageFiles as $imgPath) {
    $filename = basename($imgPath);
    if (!preg_match('/^(\d+)_/i', $filename, $m)) continue;

    $codigo = $m[1];

    $stmt = $db->prepare("
        SELECT p.id, p.nombre, p.codigo, p.marca, p.categoria,
               p.unidades_por_caja, p.precio_por_pack, p.contenido,
               lp.costo
        FROM productos p
        JOIN lista_precios lp ON lp.producto_id = p.id AND lp.lista_id = ?
        WHERE p.codigo = ? AND p.activo = 1
        LIMIT 1
    ");
    $stmt->execute([$lista_id, $codigo]);
    $p = $stmt->fetch();

    if (!$p) continue;

    // Calcular precios (misma lógica que generar.php)
    $upc       = max(1, (int)$p['unidades_por_caja']);
    $esGaseosa = esGaseosaOEnergizante($p['marca'] ?? '');

    if ($esGaseosa) {
        if ($p['precio_por_pack']) {
            $precioCaja = (float)$p['costo'] * (1 + $margen / 100);
            $precioUnit = $precioCaja / $upc;
        } else {
            $precioUnit = (float)$p['costo'] * (1 + $margen / 100);
            $precioCaja = $precioUnit * $upc;
        }
    } else {
        if ($p['precio_por_pack'] || esCerveza($p['marca'] ?? '')) {
            $precioCaja = (float)$p['costo'];
            $precioUnit = $precioCaja / $upc;
        } else {
            $precioUnit = (float)$p['costo'];
            $precioCaja = $precioUnit * $upc;
        }
    }

    $productos[] = [
        'nombre'      => $p['nombre'],
        'contenido'   => $p['contenido'] ?? '',
        'upc'         => $upc,
        'precio_unit' => $precioUnit,
        'precio_caja' => $precioCaja,
        'img_path'    => realpath($imgPath),
    ];
}

if (empty($productos)) {
    foreach ($imageFiles as $f) @unlink($f);
    @rmdir($tmpDir);
    redirect(BASE_PATH . '/catalogo/reducido.php?error=noproductos');
}

// ── Fecha pública (solo mes y año) ──────────────────────────────────────────
$meses       = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$mesLabel    = $meses[intval(date('n')) - 1];
$anioLabel   = date('Y');
$fechaPublica = 'Catálogo — ' . $mesLabel . ' ' . $anioLabel;

// ── Fuentes ─────────────────────────────────────────────────────────────────
$fontDir     = __DIR__ . '/assets/fonts/';
$defaultCfg  = (new ConfigVariables())->getDefaults();
$fontDirs    = $defaultCfg['fontDir'];
$defaultFCfg = (new FontVariables())->getDefaults();
$fontData    = $defaultFCfg['fontdata'];

$hasInter    = is_dir($fontDir) && file_exists($fontDir . 'Inter-Regular.ttf');
$hasPlayfair = is_dir($fontDir) && file_exists($fontDir . 'PlayfairDisplay-Regular.ttf');

if ($hasInter || $hasPlayfair) $fontDirs[] = $fontDir;
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

// ── mPDF ────────────────────────────────────────────────────────────────────
$mpdf = new Mpdf([
    'format'        => 'A4',
    'margin_left'   => 14,
    'margin_right'  => 14,
    'margin_top'    => 26,
    'margin_bottom' => 20,
    'margin_header' => 6,
    'margin_footer' => 5,
    'default_font'  => $bodyFont,
    'fontDir'       => $fontDirs,
    'fontdata'      => $fontData,
    'basepath'      => realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR,
]);

$mpdf->SetTitle('Catálogo ATTOS — ' . $lista['codigo']);
$mpdf->SetAuthor('ATTOS Distribuidora');
$mpdf->SetCreator('ATTOS');

// ── HTML ─────────────────────────────────────────────────────────────────────
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
    font-size: 9pt;
    color: #1A1A1A;
}

/* ── Portada ───────────────── */
.cover-body {
    text-align: center;
    padding-top: 44mm;
    padding-bottom: 22mm;
}
.cover-logo {
    font-family: <?= $headingFont ?>, dejavuserif, serif;
    font-size: 46pt;
    font-weight: 700;
    color: #631636;
    letter-spacing: 8px;
    line-height: 1;
}
.cover-sub {
    font-size: 7.5pt;
    letter-spacing: 4px;
    color: #bbb;
    text-transform: uppercase;
    margin-top: 3mm;
}
.cover-rule {
    width: 32mm;
    margin: 8mm auto;
    border: none;
    border-top: 0.3mm solid #DDD;
}
.cover-title {
    font-family: <?= $headingFont ?>, dejavuserif, serif;
    font-size: 19pt;
    color: #1A1A1A;
    margin-bottom: 3mm;
}
.cover-date { font-size: 9.5pt; color: #888; }
.cover-foot {
    text-align: center;
    font-size: 7pt;
    color: #bbb;
    letter-spacing: 2px;
    text-transform: uppercase;
    margin-top: 4mm;
}

/* ── Grilla de productos ──── */
table.grid {
    width: 100%;
    border-collapse: collapse;
}
td.g-cell {
    width: 33.33%;
    vertical-align: top;
    text-align: center;
    padding: 1.8mm;
}
.card {
    background: #FAF6EF;
    border: 0.25mm solid #DDD6CC;
    padding: 3mm 2.5mm 2.5mm;
}
.card-img {
    display: block;
    max-width: 50mm;
    max-height: 32mm;
    margin: 0 auto 2mm;
}
.card-name {
    font-family: <?= $headingFont ?>, dejavuserif, serif;
    font-size: 8pt;
    font-weight: 700;
    color: #1A1A1A;
    line-height: 1.3;
    margin-bottom: 1mm;
}
.card-contenido {
    font-size: 7pt;
    color: #bbb;
    line-height: 1.2;
    margin-bottom: 1.5mm;
}
.card-rule { border: none; border-top: 0.2mm solid #DDD6CC; margin: 1.5mm 0; }
.card-precio-unit {
    font-size: 10.5pt;
    font-weight: 700;
    color: #631636;
    line-height: 1.2;
}
.card-precio-caja {
    font-size: 7.5pt;
    color: #777;
    margin-top: 0.8mm;
}
</style>
</head>
<body>

<!-- ── Header (páginas de contenido) ────────────────────────────────────── -->
<htmlpageheader name="hdr">
<table style="width:100%; border-bottom:0.3mm solid #631636; padding-bottom:1.5mm;" cellpadding="0">
    <tr>
        <td style="font-family:<?= $headingFont ?>,dejavuserif,serif; font-size:9pt; font-weight:700; color:#631636; letter-spacing:2px;">ATTOS</td>
        <td style="text-align:right; font-size:7.5pt; color:#999; font-family:<?= $bodyFont ?>,dejavusans,sans-serif;"><?= e($fechaPublica) ?></td>
    </tr>
</table>
</htmlpageheader>

<!-- ── Footer (páginas de contenido) ────────────────────────────────────── -->
<htmlpagefooter name="ftr">
<table style="width:100%; border-top:0.2mm solid #E0D8CE; padding-top:1.5mm;" cellpadding="0">
    <tr>
        <td style="font-size:7.5pt; color:#888; font-weight:600; font-family:<?= $bodyFont ?>,dejavusans,sans-serif;"><?= e($fechaPublica) ?></td>
        <td style="text-align:right; font-size:7pt; color:#aaa; font-style:italic; font-family:<?= $bodyFont ?>,dejavusans,sans-serif;">Los precios están sujetos a cambios sin previo aviso.</td>
    </tr>
</table>
</htmlpagefooter>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- PORTADA (sin header/footer)                                           -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<table width="100%" cellpadding="0" cellspacing="0">
    <tr><td style="background:#631636; height:12mm;"></td></tr>
</table>

<div class="cover-body">
    <div class="cover-logo">ATTOS</div>
    <div class="cover-sub">Distribuidora de Bebidas · La Plata, Buenos Aires</div>
    <table style="width:32mm; margin:8mm auto; border-top:0.3mm solid #DDD;" cellpadding="0" cellspacing="0"><tr><td></td></tr></table>
    <div class="cover-title">Catálogo Selección</div>
    <div class="cover-date"><?= e($fechaPublica) ?></div>
</div>

<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:18mm;">
    <tr><td style="background:#631636; height:7mm;"></td></tr>
</table>
<div class="cover-foot">ATTOS Distribuidora · La Plata, Buenos Aires</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- GRILLA DE PRODUCTOS — header/footer activos desde página 2            -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<pagebreak />
<sethtmlpageheader name="hdr" page="ALL" value="on" show-this-page="1" />
<sethtmlpagefooter name="ftr" page="ALL" value="on" show-this-page="1" />

<?php
$cols    = 3;
$rowsPer = 4;          // 3 × 4 = 12 productos por página
$perPage = $cols * $rowsPer;
$total   = count($productos);
$pages   = (int)ceil($total / $perPage);

for ($pg = 0; $pg < $pages; $pg++):
    if ($pg > 0) echo '<pagebreak />';
    $slice = array_slice($productos, $pg * $perPage, $perPage);
    // Rellenar la última página si no llega a completar la grilla
    while (count($slice) % $cols !== 0) $slice[] = null;
    $rows = array_chunk($slice, $cols);
?>
<table class="grid" cellpadding="0" cellspacing="0">
    <?php foreach ($rows as $row): ?>
    <tr>
        <?php foreach ($row as $p): ?>
        <td class="g-cell">
            <?php if ($p !== null): ?>
            <div class="card">
                <img class="card-img" src="<?= e($p['img_path']) ?>">
                <div class="card-name"><?= e($p['nombre']) ?></div>
                <?php if (!empty(trim($p['contenido']))): ?>
                <div class="card-contenido"><?= e($p['contenido']) ?></div>
                <?php endif; ?>
                <hr class="card-rule">
                <div class="card-precio-unit"><?= precio($p['precio_unit']) ?></div>
                <div class="card-precio-caja">Caja ×<?= $p['upc'] ?>: <?= precio($p['precio_caja']) ?></div>
            </div>
            <?php endif; ?>
        </td>
        <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
</table>
<?php endfor; ?>

</body>
</html>
<?php
$html = ob_get_clean();

$mpdf->WriteHTML($html);

// Limpiar imágenes temporales (ya embebidas en el PDF)
foreach ($imageFiles as $f) @unlink($f);
@rmdir($tmpDir);

// Regenerar token para que el próximo catálogo empiece limpio
$_SESSION['catalogo_reducido_token'] = bin2hex(random_bytes(16));

$slug   = strtolower(preg_replace('/[^a-z0-9]/i', '-', $lista['codigo']));
$nombre = 'catalogo-seleccion-' . $slug . '-' . date('Y-m') . '.pdf';
$mpdf->Output($nombre, 'I');

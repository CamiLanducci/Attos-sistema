<?php
require_once __DIR__ . '/../config/db.php';

$db = getDB();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) redirect('/attos/pedidos_galpon/');

$stmt = $db->prepare("
    SELECT pg.*, pv.nombre AS proveedor_nombre
    FROM pedidos_galpon pg
    JOIN proveedores pv ON pv.id = pg.proveedor_id
    WHERE pg.id = ?
");
$stmt->execute([$id]);
$pedido = $stmt->fetch();
if (!$pedido) redirect('/attos/pedidos_galpon/');

$items = $db->prepare("SELECT * FROM pedidos_galpon_items WHERE pedido_id=? ORDER BY id ASC");
$items->execute([$id]);
$items = $items->fetchAll();

$provLabel  = $pedido['proveedor_nombre'];
$fechaLabel = date('d/m/Y', strtotime($pedido['fecha_pedido']));

// ── Dimensiones ───────────────────────────────────────────────────────────────
$W          = 860;
$MARGIN     = 40;
$TW         = $W - 2 * $MARGIN;
$HDR_BAND_H = 65;
$HDR_INFO_H = 55;
$HDR_H      = $HDR_BAND_H + $HDR_INFO_H;
$TBLHDR_H   = 38;
$ROW_H      = 40;
$FOOTER_H   = 58;
$totalCajas    = 0;
$totalUnidades = 0;
foreach ($items as $it) {
    $totalCajas    += (int)$it['cajas'];
    $totalUnidades += (int)$it['unidades'];
}

$H          = $HDR_H + $TBLHDR_H + count($items) * $ROW_H + $ROW_H + 2 + $FOOTER_H;

$img = imagecreatetruecolor($W, $H);

// ── Colores ───────────────────────────────────────────────────────────────────
$cBurgundy = imagecolorallocate($img, 99, 22, 54);
$cCream    = imagecolorallocate($img, 240, 235, 228);
$cAltRow   = imagecolorallocate($img, 247, 243, 239);
$cWhite    = imagecolorallocate($img, 255, 255, 255);
$cDark     = imagecolorallocate($img, 45, 45, 45);
$cGray     = imagecolorallocate($img, 110, 100, 95);
$cBorder   = imagecolorallocate($img, 210, 202, 194);

imagefilledrectangle($img, 0, 0, $W - 1, $H - 1, $cCream);

// ── Fuente TTF ────────────────────────────────────────────────────────────────
$fontTTF = null;
foreach (['C:\\Windows\\Fonts\\arial.ttf', 'C:\\Windows\\Fonts\\Arial.ttf', 'C:\\Windows\\Fonts\\DejaVuSans.ttf'] as $f) {
    if (file_exists($f)) { $fontTTF = $f; break; }
}

function imgText($img, $text, $x, $y, $color, $size, $font, $align = 'left', $areaW = 0) {
    $text = (string)$text;
    if ($font) {
        $bbox = imagettfbbox($size, 0, $font, $text);
        $tw   = abs($bbox[2] - $bbox[0]);
        if ($align === 'center' && $areaW > 0) $x += ($areaW - $tw) / 2;
        elseif ($align === 'right' && $areaW > 0) $x += $areaW - $tw;
        imagettftext($img, $size, 0, (int)$x, (int)$y, $color, $font, $text);
    } else {
        $iso = mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
        $fw  = imagefontwidth(5);
        $tw  = strlen($iso) * $fw;
        if ($align === 'center' && $areaW > 0) $x += ($areaW - $tw) / 2;
        elseif ($align === 'right' && $areaW > 0) $x += $areaW - $tw;
        imagestring($img, 5, (int)$x, (int)$y - 13, $iso, $color);
    }
}

function imgTrunc($text, $maxW, $font, $size) {
    if (!$font) {
        $maxC = (int)($maxW / imagefontwidth(5));
        return mb_strlen($text) > $maxC ? mb_substr($text, 0, $maxC - 1) . '…' : $text;
    }
    $bbox = imagettfbbox($size, 0, $font, $text);
    if (abs($bbox[2] - $bbox[0]) <= $maxW) return $text;
    while (mb_strlen($text) > 1) {
        $text = mb_substr($text, 0, mb_strlen($text) - 1);
        $bbox = imagettfbbox($size, 0, $font, $text . '…');
        if (abs($bbox[2] - $bbox[0]) <= $maxW) return $text . '…';
    }
    return $text;
}

function fmtPeso($n) {
    if ($n === null) return '—';
    $parts = number_format((float)$n, 2, ',', '.');
    return '$' . $parts;
}

// ── Header ────────────────────────────────────────────────────────────────────
imagefilledrectangle($img, 0, 0, $W - 1, $HDR_BAND_H - 1, $cBurgundy);
$titleY = (int)($HDR_BAND_H / 2 + ($fontTTF ? 8 : 5));
imgText($img, strtoupper($provLabel), $MARGIN, $titleY, $cWhite, 20, $fontTTF, 'center', $TW);

$infoY = (int)($HDR_BAND_H + $HDR_INFO_H / 2 + ($fontTTF ? 6 : 4));
imgText($img, 'Pedido #' . $id, $MARGIN, $infoY, $cBurgundy, 14, $fontTTF);
imgText($img, $fechaLabel,      $MARGIN, $infoY, $cGray,     13, $fontTTF, 'right', $TW);

imageline($img, $MARGIN, $HDR_H, $W - $MARGIN, $HDR_H, $cBorder);

// ── Columnas: CÓDIGO 12% | PRODUCTO 58% | CAJAS 15% | UNIDADES 15%
$colCod  = (int)($TW * 0.12);
$colCaj  = (int)($TW * 0.15);
$colUnid = (int)($TW * 0.15);
$colProd = $TW - $colCod - $colCaj - $colUnid;

imagefilledrectangle($img, $MARGIN, $HDR_H, $W - $MARGIN - 1, $HDR_H + $TBLHDR_H - 1, $cBurgundy);
$thY   = (int)($HDR_H + $TBLHDR_H / 2 + ($fontTTF ? 5 : 4));
$xCod  = $MARGIN + 4;
$xProd = $MARGIN + $colCod + 4;
$xCaj  = $MARGIN + $colCod + $colProd;
$xUnid = $xCaj + $colCaj;

imgText($img, 'CÓDIGO',   $xCod,  $thY, $cWhite, 12, $fontTTF);
imgText($img, 'PRODUCTO', $xProd, $thY, $cWhite, 12, $fontTTF);
imgText($img, 'CAJAS',    $xCaj,  $thY, $cWhite, 12, $fontTTF, 'center', $colCaj);
imgText($img, 'UNIDADES', $xUnid, $thY, $cWhite, 12, $fontTTF, 'center', $colUnid);

// ── Filas ─────────────────────────────────────────────────────────────────────
$rowTop   = $HDR_H + $TBLHDR_H;
$totalCant = 0;
$totalSub  = 0.0;

foreach ($items as $i => $item) {
    $bg = ($i % 2 === 0) ? $cWhite : $cAltRow;
    imagefilledrectangle($img, $MARGIN, $rowTop, $W - $MARGIN - 1, $rowTop + $ROW_H - 1, $bg);
    $ry = (int)($rowTop + $ROW_H / 2 + ($fontTTF ? 5 : 4));

    $cod  = imgTrunc($item['codigo'] ?? '', $colCod  - 8, $fontTTF, 13);
    $nom  = imgTrunc($item['nombre'],       $colProd - 8, $fontTTF, 13);
    $cajas = (int)$item['cajas'];
    $unid  = (int)$item['unidades'];
    $totalCant += $cajas + $unid;

    imgText($img, $cod,  $xCod,  $ry, $cDark, 13, $fontTTF);
    imgText($img, $nom,  $xProd, $ry, $cDark, 13, $fontTTF);
    imgText($img, $cajas > 0 ? (string)$cajas : '—', $xCaj,  $ry, $cDark, 13, $fontTTF, 'center', $colCaj);
    imgText($img, $unid  > 0 ? (string)$unid  : '—', $xUnid, $ry, $cDark, 13, $fontTTF, 'center', $colUnid);

    imageline($img, $MARGIN, $rowTop + $ROW_H - 1, $W - $MARGIN - 1, $rowTop + $ROW_H - 1, $cBorder);
    $rowTop += $ROW_H;
}

// ── Fila TOTAL ────────────────────────────────────────────────────────────────
imageline($img, $MARGIN, $rowTop, $W - $MARGIN - 1, $rowTop, $cBurgundy);
imagefilledrectangle($img, $MARGIN, $rowTop, $W - $MARGIN - 1, $rowTop + $ROW_H - 1, $cCream);
$ty = (int)($rowTop + $ROW_H / 2 + ($fontTTF ? 5 : 4));

$totalLabel = 'TOTAL';
$bbox = $fontTTF ? imagettfbbox(14, 0, $fontTTF, $totalLabel) : null;
$labelW = $bbox ? abs($bbox[2] - $bbox[0]) : strlen($totalLabel) * imagefontwidth(5);
$labelX = $xCaj - $labelW - 12;
imgText($img, $totalLabel, (int)$labelX, $ty, $cBurgundy, 14, $fontTTF);
imgText($img, $totalCajas    > 0 ? (string)$totalCajas    : '—', $xCaj,  $ty, $cBurgundy, 14, $fontTTF, 'center', $colCaj);
imgText($img, $totalUnidades > 0 ? (string)$totalUnidades : '—', $xUnid, $ty, $cBurgundy, 14, $fontTTF, 'center', $colUnid);
$rowTop += $ROW_H;

// ── Separador footer ──────────────────────────────────────────────────────────
imagefilledrectangle($img, $MARGIN, $rowTop, $W - $MARGIN - 1, $rowTop + 2, $cBurgundy);
$rowTop += 3;

// ── Footer ────────────────────────────────────────────────────────────────────
$footerText = 'Productos: ' . count($items) . '  ·  Unidades: ' . $totalCant . '  ·  Pedido #' . $id;
$fy = (int)($rowTop + $FOOTER_H / 2 + ($fontTTF ? 5 : 4));
imgText($img, $footerText, $MARGIN, $fy, $cGray, 12, $fontTTF, 'center', $TW);

// ── Output ────────────────────────────────────────────────────────────────────
$filename = 'pedido_' . $id . '_' . date('Ymd') . '.png';
header('Content-Type: image/png');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: no-store');
imagepng($img);
imagedestroy($img);

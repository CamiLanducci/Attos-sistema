<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

set_time_limit(120);
ini_set('memory_limit', '512M');

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    $pageTitle = 'Error — mPDF no instalado';
    require_once __DIR__ . '/../config/layout.php';
    echo '<div class="alert alert-warning" style="max-width:560px;">
        <strong>mPDF no está instalado.</strong><br>
        Corré <code>composer install</code> en <code>c:\xampp\htdocs\Attos\</code> y volvé a intentarlo.
        <br><br><a href="' . BASE_PATH . '/listas/" class="btn btn-secondary btn-sm">← Volver</a>
    </div>';
    require_once __DIR__ . '/../config/layout_end.php';
    exit;
}
require_once $autoload;

use Mpdf\Mpdf;

$listaId = (int)($_GET['lista_id'] ?? 0);
$origen  = ($_GET['origen'] ?? 'preview') === 'historial' ? 'historial' : 'preview';
$modo    = ($_GET['modo'] ?? 'todos') === 'aumentos' ? 'aumentos' : 'todos';

if (!$listaId) {
    redirect(BASE_PATH . '/listas/');
}

$logFecha = null;

if ($origen === 'historial') {
    // PDF pedido desde el dashboard de Listas: usa el snapshot guardado en la
    // última importación aplicada, no depende de la sesión de importación.
    $db = getDB();
    $stmt = $db->prepare("
        SELECT l.*, log.changes_json, log.new_prods_json, log.fecha AS log_fecha
        FROM listas l
        JOIN lista_import_log log ON log.lista_id = l.id
        WHERE l.id = ?
    ");
    $stmt->execute([$listaId]);
    $row = $stmt->fetch();

    if (!$row) {
        redirect(BASE_PATH . '/listas/');
    }

    $lista    = $row;
    $logFecha = $row['log_fecha'];
    $changes  = json_decode($row['changes_json'], true) ?: [];
    $newProds = json_decode($row['new_prods_json'], true) ?: [];
} else {
    $previewData = $_SESSION['import_preview']    ?? null;
    $previewTs   = $_SESSION['import_preview_ts'] ?? 0;

    if (!$previewData || (time() - $previewTs) > 1800 || empty($previewData[$listaId])) {
        redirect(BASE_PATH . '/listas/importar.php?step=confirm&error=expired');
    }

    $data = $previewData[$listaId];
    if ($data['error'] ?? null) {
        redirect(BASE_PATH . '/listas/importar.php?step=preview');
    }

    $lista    = $data['lista'];
    $changes  = $data['changes'];
    $newProds = $data['new_prods'];
}

// "Solo aumentos" = únicamente los precios que efectivamente se aplicaron
// (las bajas se avisan pero nunca se cargan — ver importar.php).
if ($modo === 'aumentos') {
    $changes = array_values(array_filter($changes, fn($c) => $c['pct'] >= 0));
}

if (empty($changes) && empty($newProds)) {
    redirect($origen === 'historial' ? BASE_PATH . '/listas/?msg=sin_cambios_pdf' : BASE_PATH . '/listas/importar.php?step=preview');
}

$mpdf = new Mpdf([
    'format'        => 'A4',
    'margin_left'   => 16,
    'margin_right'  => 16,
    'margin_top'    => 20,
    'margin_bottom' => 16,
    'margin_header' => 8,
    'margin_footer' => 6,
    'default_font'  => 'dejavusans',
]);

$tituloModo = $modo === 'aumentos' ? 'Aumentos aplicados' : 'Aumentos y bajas';

$mpdf->SetTitle($tituloModo . ' — ' . $lista['codigo']);
$mpdf->SetAuthor('ATTOS Distribuidora');

$fecha = date('d/m/Y H:i');

ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: dejavusans, sans-serif; font-size:10pt; color:#1A1A1A; }
h1 { font-size:16pt; color:#631636; margin-bottom:2mm; }
.subtitle { font-size:9pt; color:#888; margin-bottom:6mm; }
h2 { font-size:12pt; color:#631636; margin-top:6mm; margin-bottom:2mm; border-bottom:0.3mm solid #631636; padding-bottom:1mm; }
table { width:100%; border-collapse:collapse; margin-bottom:3mm; }
thead td { background:#631636; color:#FAF6EF; padding:2.2mm 2.5mm; font-size:8pt; font-weight:700; text-transform:uppercase; }
tbody td { padding:2mm 2.5mm; border-bottom:0.15mm solid #EAE4DC; font-size:9pt; }
.alt { background:#FAF6EF; }
.right { text-align:right; }
.center { text-align:center; }
.sube { color:#c0392b; font-weight:700; }
.baja { color:#27ae60; font-weight:700; }
.tag-no-aplicado { font-size:7.5pt; color:#888; font-style:italic; }
.nota { font-size:8pt; color:#888; margin-bottom:4mm; }
</style>
</head>
<body>

<h1>ATTOS — <?= e($tituloModo) ?></h1>
<div class="subtitle">
    Lista <strong><?= e($lista['codigo']) ?></strong> (<?= $lista['margen'] ?>% margen)
    <?php if ($logFecha): ?>
        &middot; importado el <?= date('d/m/Y H:i', strtotime($logFecha)) ?>
    <?php endif; ?>
    &middot; PDF generado el <?= $fecha ?>
</div>

<?php if (!empty($changes)): ?>
<h2><?= $modo === 'aumentos' ? 'Aumentos aplicados' : 'Precios modificados' ?> (<?= count($changes) ?>)</h2>
<table cellpadding="0" cellspacing="0">
    <thead>
        <tr>
            <td>Código</td>
            <td>Producto</td>
            <td>Marca</td>
            <td class="right">Precio anterior</td>
            <td class="right">Precio nuevo</td>
            <td class="right">Variación</td>
            <?php if ($modo !== 'aumentos'): ?><td class="center">Aplicado</td><?php endif; ?>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($changes as $i => $c):
        $aplicado = $c['pct'] >= 0;
        $pctFmt   = ($aplicado ? '+' : '') . number_format($c['pct'], 1) . '%';
        $alt      = $i % 2 === 1 ? ' class="alt"' : '';
    ?>
        <tr>
            <td<?= $alt ?>><?= e($c['codigo']) ?></td>
            <td<?= $alt ?>><?= e($c['nombre']) ?></td>
            <td<?= $alt ?>><?= e($c['marca']) ?></td>
            <td<?= $alt ?> class="right"><?= precio($c['viejo']) ?></td>
            <td<?= $alt ?> class="right"><?= precio($c['nuevo']) ?></td>
            <td<?= $alt ?> class="right <?= $aplicado ? 'sube' : 'baja' ?>"><?= $pctFmt ?></td>
            <?php if ($modo !== 'aumentos'): ?>
            <td<?= $alt ?> class="center">
                <?php if ($aplicado): ?>Sí<?php else: ?><span class="tag-no-aplicado">No (baja)</span><?php endif; ?>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if ($modo !== 'aumentos'): ?>
<p class="nota">
    Las bajas de precio se informan a modo de aviso — el sistema no reduce automáticamente el precio
    cargado al importar; se conserva el precio anterior hasta que se actualice manualmente.
</p>
<?php endif; ?>
<?php endif; ?>

<?php if (!empty($newProds)): ?>
<h2>Productos nuevos (<?= count($newProds) ?>)</h2>
<table cellpadding="0" cellspacing="0">
    <thead>
        <tr>
            <td>Código</td>
            <td>Producto</td>
            <td>Marca</td>
            <td class="center">Pack</td>
            <td class="right">Precio</td>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($newProds as $i => $np): $alt = $i % 2 === 1 ? ' class="alt"' : ''; ?>
        <tr>
            <td<?= $alt ?>><?= e($np['codigo']) ?></td>
            <td<?= $alt ?>><?= e($np['nombre']) ?></td>
            <td<?= $alt ?>><?= e($np['marca']) ?></td>
            <td<?= $alt ?> class="center"><?= (int)$np['pack'] ?></td>
            <td<?= $alt ?> class="right"><?= precio($np['precio']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

</body>
</html>
<?php
$html = ob_get_clean();
$mpdf->WriteHTML($html);

$sufijoModo    = $modo === 'aumentos' ? 'Aumentos' : 'AumentosYBajas';
$nombreArchivo = 'Attos-' . $sufijoModo . '-' . preg_replace('/[^A-Za-z0-9_-]/', '', $lista['codigo']) . '-' . date('Ymd') . '.pdf';
$pdfBin = $mpdf->Output('', 'S');

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
header('Content-Length: ' . strlen($pdfBin));
echo $pdfBin;

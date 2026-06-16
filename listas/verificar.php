<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$pageTitle     = 'Verificar importación';
$topbarActions = '<a href="' . BASE_PATH . '/listas/" class="btn btn-secondary">← Volver</a>';
require_once __DIR__ . '/../config/layout.php';

$db = getDB();

$listas   = $db->query("SELECT * FROM listas ORDER BY margen ASC")->fetchAll();
$listaIds = array_column($listas, 'id');

// Conteo de productos por lista
$conteos = [];
if ($listaIds) {
    $placeholders = implode(',', array_fill(0, count($listaIds), '?'));
    $rows = $db->prepare("
        SELECT lista_id, COUNT(*) AS total
        FROM lista_precios lp
        JOIN productos p ON p.id = lp.producto_id AND p.activo = 1
        WHERE lista_id IN ($placeholders)
        GROUP BY lista_id
    ");
    $rows->execute($listaIds);
    foreach ($rows->fetchAll() as $r) {
        $conteos[$r['lista_id']] = $r['total'];
    }
}

// Productos presentes en al menos una lista pero ausentes en otras
$ausentes = $db->query("
    SELECT p.codigo, p.nombre, p.marca,
           GROUP_CONCAT(l.codigo ORDER BY l.margen ASC SEPARATOR ', ') AS en_listas,
           COUNT(DISTINCT lp.lista_id) AS n_listas
    FROM productos p
    JOIN lista_precios lp ON lp.producto_id = p.id
    JOIN listas l         ON l.id = lp.lista_id
    WHERE p.activo = 1
    GROUP BY p.id
    HAVING n_listas < " . count($listas) . "
    ORDER BY n_listas ASC, p.marca, p.nombre
    LIMIT 100
")->fetchAll();

// Muestra de precios por lista para los primeros 10 productos de cada lista
$muestras = [];
foreach ($listas as $lista) {
    $rows = $db->prepare("
        SELECT p.codigo, p.nombre, lp.costo, lp.costo_caja, p.unidades_por_caja
        FROM lista_precios lp
        JOIN productos p ON p.id = lp.producto_id AND p.activo = 1
        WHERE lp.lista_id = ?
        ORDER BY p.marca, p.nombre
        LIMIT 8
    ");
    $rows->execute([$lista['id']]);
    $muestras[$lista['id']] = $rows->fetchAll();
}
?>

<!-- Resumen por lista -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><span class="card-title">Productos por lista</span></div>
    <div class="card-body">
        <table style="width:100%; font-size:13px; border-collapse:collapse;">
            <thead>
                <tr style="border-bottom:2px solid var(--border);">
                    <th style="padding:6px 8px; text-align:left;">Lista</th>
                    <th style="padding:6px 8px; text-align:center;">Margen</th>
                    <th style="padding:6px 8px; text-align:center;">Productos</th>
                    <th style="padding:6px 8px; text-align:left;">URL</th>
                    <th style="padding:6px 8px; text-align:center;">Última import.</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($listas as $l): ?>
            <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:6px 8px;"><strong><?= e($l['codigo']) ?></strong></td>
                <td style="padding:6px 8px; text-align:center;"><?= $l['margen'] ?>%</td>
                <td style="padding:6px 8px; text-align:center; font-weight:600;">
                    <?= $conteos[$l['id']] ?? 0 ?>
                </td>
                <td style="padding:6px 8px; font-size:11px; color:var(--text-soft); max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                    <?php if ($l['url_actualizacion']): ?>
                        <span title="<?= e($l['url_actualizacion']) ?>"><?= e(substr($l['url_actualizacion'], 0, 55)) ?>…</span>
                    <?php else: ?>
                        <span style="color:#aaa;">Sin URL</span>
                    <?php endif; ?>
                </td>
                <td style="padding:6px 8px; text-align:center; font-size:11px;">
                    <?php if ($l['ultima_actualizacion']): ?>
                        <span style="color:var(--success);"><?= date('d/m/Y H:i', strtotime($l['ultima_actualizacion'])) ?></span>
                    <?php else: ?>
                        <span style="color:#aaa;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Productos ausentes en alguna lista -->
<?php if ($listas): ?>
<div class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <span class="card-title">Productos incompletos (ausentes en alguna lista)</span>
        <span class="text-muted" style="font-size:12px; margin-left:8px;"><?= count($ausentes) ?> encontrados</span>
    </div>
    <?php if ($ausentes): ?>
    <div class="card-body" style="padding:0;">
        <table style="width:100%; font-size:12px; border-collapse:collapse;">
            <thead>
                <tr style="border-bottom:2px solid var(--border); background:var(--bg-soft);">
                    <th style="padding:6px 8px; text-align:left;">Código</th>
                    <th style="padding:6px 8px; text-align:left;">Producto</th>
                    <th style="padding:6px 8px; text-align:left;">Marca</th>
                    <th style="padding:6px 8px; text-align:left;">Presente en</th>
                    <th style="padding:6px 8px; text-align:center;">Listas</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ausentes as $a): ?>
            <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:5px 8px; font-family:monospace;"><?= e($a['codigo']) ?></td>
                <td style="padding:5px 8px;"><?= e($a['nombre']) ?></td>
                <td style="padding:5px 8px; color:var(--text-soft);"><?= e($a['marca']) ?></td>
                <td style="padding:5px 8px; color:var(--text-soft);"><?= e($a['en_listas']) ?></td>
                <td style="padding:5px 8px; text-align:center;">
                    <span style="background:#fef3cd; color:#856404; padding:2px 6px; border-radius:3px; font-size:11px;">
                        <?= $a['n_listas'] ?> / <?= count($listas) ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body" style="color:var(--success); font-size:13px;">Todos los productos están en todas las listas.</div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Muestra de precios por lista -->
<div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(420px,1fr)); gap:16px;">
<?php foreach ($listas as $lista): ?>
<div class="card">
    <div class="card-header">
        <span class="card-title"><?= e($lista['codigo']) ?> — <?= $lista['margen'] ?>%</span>
        <span class="text-muted" style="font-size:11px; margin-left:6px;">muestra de 8</span>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($muestras[$lista['id']])): ?>
            <div style="padding:12px; font-size:12px; color:#aaa;">Sin productos importados.</div>
        <?php else: ?>
        <table style="width:100%; font-size:12px; border-collapse:collapse;">
            <thead>
                <tr style="border-bottom:1px solid var(--border); background:var(--bg-soft);">
                    <th style="padding:5px 8px; text-align:left;">Código</th>
                    <th style="padding:5px 8px; text-align:left;">Producto</th>
                    <th style="padding:5px 8px; text-align:right;">$/u</th>
                    <th style="padding:5px 8px; text-align:right;">$/caja</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($muestras[$lista['id']] as $p): ?>
            <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:4px 8px; font-family:monospace;"><?= e($p['codigo']) ?></td>
                <td style="padding:4px 8px; max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= e($p['nombre']) ?>"><?= e($p['nombre']) ?></td>
                <td style="padding:4px 8px; text-align:right;"><?= precio($p['costo']) ?></td>
                <td style="padding:4px 8px; text-align:right; color:var(--text-soft);"><?= precio($p['costo_caja']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
$pageTitle = 'Reportes';

$db = getDB();

// Filtros
$mes        = $_GET['mes']        ?? date('Y-m');
$lista_id   = isset($_GET['lista_id'])   ? (int)$_GET['lista_id']   : 0;
$cliente_id = isset($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : 0;

[$year, $month] = explode('-', $mes . '-01');
$year  = (int)$year;
$month = (int)$month;

$listas   = $db->query("SELECT * FROM listas ORDER BY margen DESC")->fetchAll();
$clientes = $db->query("SELECT id, nombre FROM clientes WHERE activo=1 ORDER BY nombre ASC")->fetchAll();

// Parámetros base para filtros
$fechaDesde = sprintf('%04d-%02d-01', $year, $month);
$fechaHasta = (new DateTime($fechaDesde))->modify('+1 month')->format('Y-m-d');

$params     = [':fecha_desde' => $fechaDesde, ':fecha_hasta' => $fechaHasta];
$whereExtra = '';
if ($lista_id) {
    $whereExtra .= ' AND c.lista_id = :lista_id';
    $params[':lista_id'] = $lista_id;
}
if ($cliente_id) {
    $whereExtra .= ' AND c.cliente_id = :cliente_id';
    $params[':cliente_id'] = $cliente_id;
}

// Totales del período
$totales = $db->prepare("
    SELECT
        COALESCE(SUM(c.total), 0) AS ingresos,
        COALESCE(SUM(ci_sum.costo_total), 0) AS costos
    FROM comprobantes c
    LEFT JOIN (
        SELECT comprobante_id, SUM(subtotal / (1 + margen_aplicado / 100.0)) AS costo_total
        FROM comprobante_items
        GROUP BY comprobante_id
    ) ci_sum ON ci_sum.comprobante_id = c.id
    WHERE c.fecha >= :fecha_desde AND c.fecha < :fecha_hasta $whereExtra
");
$totales->execute($params);
$tot = $totales->fetch();
$ingresos = (float)$tot['ingresos'];
$costos   = (float)$tot['costos'];
$ganancia = $ingresos - $costos;

// Comprobantes del período
$comps = $db->prepare("
    SELECT c.id, c.numero, c.fecha, c.total, c.estado,
           cl.nombre AS cliente,
           l.codigo AS lista_codigo, l.margen,
           COALESCE(SUM(ci.subtotal / (1 + ci.margen_aplicado / 100.0)), 0) AS costo_total,
           c.total - COALESCE(SUM(ci.subtotal / (1 + ci.margen_aplicado / 100.0)), 0) AS ganancia
    FROM comprobantes c
    JOIN clientes cl ON cl.id = c.cliente_id
    JOIN listas l ON l.id = c.lista_id
    LEFT JOIN comprobante_items ci ON ci.comprobante_id = c.id
    WHERE c.fecha >= :fecha_desde AND c.fecha < :fecha_hasta $whereExtra
    GROUP BY c.id
    ORDER BY c.fecha DESC, c.id DESC
");
$comps->execute($params);
$comps = $comps->fetchAll();

// Ventas por lista en el período
$porLista = $db->prepare("
    SELECT l.codigo, l.margen,
           COUNT(c.id) AS qty,
           COALESCE(SUM(c.total), 0) AS total,
           COALESCE(SUM(ci_sum.costo_total), 0) AS costos
    FROM listas l
    LEFT JOIN comprobantes c
        ON c.lista_id = l.id
       AND c.fecha >= :fecha_desde
       AND c.fecha < :fecha_hasta
    LEFT JOIN (
        SELECT comprobante_id, SUM(subtotal / (1 + margen_aplicado / 100.0)) AS costo_total
        FROM comprobante_items
        GROUP BY comprobante_id
    ) ci_sum ON ci_sum.comprobante_id = c.id
    GROUP BY l.id
    ORDER BY l.margen DESC
");
$porLista->execute([
    ':fecha_desde' => $fechaDesde,
    ':fecha_hasta' => $fechaHasta
]);
$porLista = $porLista->fetchAll();

// Meses disponibles para el selector
$meses = $db->query("
    SELECT DISTINCT DATE_FORMAT(fecha, '%Y-%m') AS ym, DATE_FORMAT(fecha, '%M %Y') AS label
    FROM comprobantes
    ORDER BY ym DESC
    LIMIT 24
")->fetchAll();

// ── Datos para tablas de ranking ─────────────────────────────────────────────

// Ganancia por cliente en el período seleccionado
$graficoClientes = $db->prepare("
    SELECT
        cl.nombre,
        COALESCE(SUM(c.total), 0) AS ingresos,
        COALESCE(SUM(ci_sub.costo_total), 0) AS costos,
        COALESCE(SUM(c.total), 0) - COALESCE(SUM(ci_sub.costo_total), 0) AS ganancia
    FROM comprobantes c
    JOIN clientes cl ON cl.id = c.cliente_id
    LEFT JOIN (
        SELECT comprobante_id, SUM(subtotal / (1 + margen_aplicado / 100.0)) AS costo_total
        FROM comprobante_items
        GROUP BY comprobante_id
    ) ci_sub ON ci_sub.comprobante_id = c.id
    WHERE c.fecha >= :fecha_desde AND c.fecha < :fecha_hasta $whereExtra
    GROUP BY cl.id, cl.nombre
    ORDER BY ganancia DESC
    LIMIT 10
");
$graficoClientes->execute($params);
$graficoClientes = $graficoClientes->fetchAll();

// Ganancia por producto en el período seleccionado
$graficoProductos = $db->prepare("
    SELECT
        ci.nombre_producto AS nombre,
        COALESCE(SUM(ci.subtotal), 0) AS ingresos,
        COALESCE(SUM(ci.subtotal / (1 + ci.margen_aplicado / 100.0)), 0) AS costos,
        COALESCE(SUM(ci.subtotal), 0) - COALESCE(SUM(ci.subtotal / (1 + ci.margen_aplicado / 100.0)), 0) AS ganancia
    FROM comprobante_items ci
    JOIN comprobantes c ON c.id = ci.comprobante_id
    WHERE c.fecha >= :fecha_desde AND c.fecha < :fecha_hasta $whereExtra
    GROUP BY ci.nombre_producto
    ORDER BY ganancia DESC
    LIMIT 12
");
$graficoProductos->execute($params);
$graficoProductos = $graficoProductos->fetchAll();

require_once __DIR__ . '/../config/layout.php';
?>

<!-- Filtros -->
<div class="card" style="margin-bottom:16px;">
    <div class="card-body" style="padding:14px 20px;">
        <form method="GET" class="d-flex gap-2 align-center" style="flex-wrap:wrap;">
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:11px;">Período</label>
                <select name="mes" class="form-control" onchange="this.form.submit()">
                    <?php if (empty($meses)): ?>
                        <option value="<?= date('Y-m') ?>"><?= date('F Y') ?></option>
                    <?php else: ?>
                        <?php foreach ($meses as $mm): ?>
                            <option value="<?= $mm['ym'] ?>" <?= $mm['ym'] === $mes ? 'selected' : '' ?>><?= $mm['label'] ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:11px;">Lista</label>
                <select name="lista_id" class="form-control" onchange="this.form.submit()">
                    <option value="">Todas las listas</option>
                    <?php foreach ($listas as $l): ?>
                        <option value="<?= $l['id'] ?>" <?= $lista_id === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['codigo']) ?> — <?= $l['margen'] ?>%</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:11px;">Cliente</label>
                <select name="cliente_id" class="form-control" onchange="this.form.submit()">
                    <option value="">Todos los clientes</option>
                    <?php foreach ($clientes as $cl): ?>
                        <option value="<?= $cl['id'] ?>" <?= $cliente_id === (int)$cl['id'] ? 'selected' : '' ?>><?= e($cl['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($lista_id || $cliente_id): ?>
            <div class="form-group" style="margin:0; align-self:flex-end;">
                <a href="<?= BASE_PATH ?>/reportes/?mes=<?= e($mes) ?>" class="btn btn-secondary btn-sm">✕ Limpiar</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Totales -->
<div class="reporte-grid" style="margin-bottom:24px;">
    <div class="reporte-total">
        <div class="label">Ingresos</div>
        <div class="value"><?= precio($ingresos) ?></div>
    </div>
    <div class="reporte-total" style="background:var(--text);">
        <div class="label">Costos</div>
        <div class="value"><?= precio($costos) ?></div>
    </div>
    <div class="reporte-total" style="background:var(--success);">
        <div class="label">Ganancia</div>
        <div class="value"><?= precio($ganancia) ?></div>
        <?php if ($ingresos > 0): ?>
            <div style="font-size:12px; opacity:.8; margin-top:4px;"><?= number_format($ganancia / $ingresos * 100, 1) ?>% del ingreso</div>
        <?php endif; ?>
    </div>
</div>

<!-- Por cliente y por producto -->
<div class="d-flex gap-2" style="margin-bottom:24px; align-items:flex-start; flex-wrap:wrap;">

    <!-- Ganancia por cliente -->
    <div class="card" style="flex:1; min-width:280px;">
        <div class="card-header">
            <span class="card-title">Por cliente</span>
            <span class="text-muted" style="font-size:12px;">Top 10</span>
        </div>
        <?php if (empty($graficoClientes)): ?>
            <div class="card-body"><p class="text-muted" style="font-size:13px;">Sin datos en este período.</p></div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th class="text-right">Ingresos</th>
                        <th class="text-right">Ganancia</th>
                        <th class="text-right" style="width:50px;">%</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($graficoClientes as $row):
                    $pct = $row['ingresos'] > 0 ? $row['ganancia'] / $row['ingresos'] * 100 : 0;
                ?>
                <tr>
                    <td><?= e($row['nombre']) ?></td>
                    <td class="text-right text-muted"><?= precio((float)$row['ingresos']) ?></td>
                    <td class="text-right fw-bold text-bordo"><?= precio((float)$row['ganancia']) ?></td>
                    <td class="text-right text-muted" style="font-size:11px;"><?= number_format($pct, 1) ?>%</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Ganancia por producto -->
    <div class="card" style="flex:1; min-width:280px;">
        <div class="card-header">
            <span class="card-title">Por producto</span>
            <span class="text-muted" style="font-size:12px;">Top 12</span>
        </div>
        <?php if (empty($graficoProductos)): ?>
            <div class="card-body"><p class="text-muted" style="font-size:13px;">Sin datos en este período.</p></div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th class="text-right">Ingresos</th>
                        <th class="text-right">Ganancia</th>
                        <th class="text-right" style="width:50px;">%</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($graficoProductos as $row):
                    $pct = $row['ingresos'] > 0 ? $row['ganancia'] / $row['ingresos'] * 100 : 0;
                ?>
                <tr>
                    <td style="font-size:12px;"><?= e($row['nombre']) ?></td>
                    <td class="text-right text-muted"><?= precio((float)$row['ingresos']) ?></td>
                    <td class="text-right fw-bold text-bordo"><?= precio((float)$row['ganancia']) ?></td>
                    <td class="text-right text-muted" style="font-size:11px;"><?= number_format($pct, 1) ?>%</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<div class="d-flex gap-2" style="align-items:flex-start;">

    <!-- Tabla de comprobantes -->
    <div class="card" style="flex:2;">
        <div class="card-header">
            <span class="card-title">Comprobantes del período</span>
            <span class="text-muted" style="font-size:12px;"><?= count($comps) ?> comprobante<?= count($comps) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Fecha</th>
                        <th>Cliente</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Costo</th>
                        <th class="text-right">Ganancia</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($comps)): ?>
                    <tr><td colspan="8" class="text-center text-muted" style="padding:24px;">Sin comprobantes en este período.</td></tr>
                <?php else: ?>
                    <?php
                    $badgeMap = ['emitido'=>'badge-bordo','cobrado'=>'badge-success','borrador'=>'badge-warning'];
                    foreach ($comps as $c):
                        $cls = $badgeMap[$c['estado']] ?? 'badge-gray';
                    ?>
                    <tr>
                        <td><strong>#<?= $c['numero'] ?></strong></td>
                        <td><?= date('d/m', strtotime($c['fecha'])) ?></td>
                        <td><?= e($c['cliente']) ?></td>
                        <td class="text-right fw-bold"><?= precio((float)$c['total']) ?></td>
                        <td class="text-right text-muted"><?= precio((float)$c['costo_total']) ?></td>
                        <td class="text-right fw-bold text-bordo"><?= precio((float)$c['ganancia']) ?></td>
                        <td><span class="badge <?= $cls ?>"><?= $c['estado'] ?></span></td>
                        <td><a href="<?= BASE_PATH ?>/comprobantes/ver.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-secondary">Ver</a></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Por lista -->
    <div class="card" style="flex:1; min-width:220px;">
        <div class="card-header"><span class="card-title">Por lista</span></div>
        <div class="card-body">
            <?php foreach ($porLista as $pl): ?>
            <div style="margin-bottom:18px;">
                <div class="d-flex justify-between align-center mb-1">
                    <span><span class="badge badge-bordo"><?= e($pl['codigo']) ?></span> <strong><?= $pl['margen'] ?>%</strong></span>
                    <span class="text-muted" style="font-size:12px;"><?= $pl['qty'] ?> comp.</span>
                </div>
                <div class="fw-bold text-bordo"><?= precio((float)$pl['total']) ?></div>
                <div class="text-muted" style="font-size:12px;">Ganancia: <?= precio((float)$pl['total'] - (float)$pl['costos']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

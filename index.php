<?php
require_once __DIR__ . '/config/db.php';
$pageTitle = 'Dashboard';

$db = getDB();

/* ============================================================
   PERÍODO SELECCIONADO
   ============================================================
   ?periodo=actual    → este mes (default)
   ?periodo=anterior  → mes anterior
   Reload completo (no AJAX): el form hace GET y la página vuelve a calcular todo.
   ============================================================ */
$periodo = ($_GET['periodo'] ?? 'actual') === 'anterior' ? 'anterior' : 'actual';

if ($periodo === 'actual') {
    $desde = date('Y-m-01');
    $hasta = date('Y-m-t');
    $periodoLabel = 'Este mes (' . date('m/Y') . ')';
} else {
    $desde = date('Y-m-01', strtotime('first day of last month'));
    $hasta = date('Y-m-t',  strtotime('first day of last month'));
    $periodoLabel = 'Mes anterior (' . date('m/Y', strtotime('first day of last month')) . ')';
}

/* ============================================================
   KPIs DEL PERÍODO
   ============================================================ */
$totalClientes  = $db->query("SELECT COUNT(*) FROM clientes  WHERE activo=1")->fetchColumn();
$totalProductos = $db->query("SELECT COUNT(*) FROM productos WHERE activo=1")->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM comprobantes WHERE fecha BETWEEN ? AND ?");
$stmt->execute([$desde, $hasta]);
$compPeriodo = $stmt->fetchColumn();

$compEmitidos = $db->query("SELECT COUNT(*) FROM comprobantes WHERE estado='emitido'")->fetchColumn();

/* ============================================================
   GRÁFICO 1 — VENTAS DIARIAS (línea)
   ============================================================ */
$stmt = $db->prepare("
    SELECT DATE(fecha) AS dia, COALESCE(SUM(total),0) AS total
    FROM comprobantes
    WHERE fecha BETWEEN ? AND ?
    GROUP BY DATE(fecha)
    ORDER BY dia
");
$stmt->execute([$desde, $hasta]);
$ventasPorDiaRaw = $stmt->fetchAll();

// Rellenar días sin ventas con 0 para que el eje X no quede saltado
$ventasMap = [];
foreach ($ventasPorDiaRaw as $r) {
    $ventasMap[$r['dia']] = (float)$r['total'];
}

$labelsDias = [];
$datosDias  = [];
$cursor = strtotime($desde);
$fin    = strtotime($hasta);
while ($cursor <= $fin) {
    $d = date('Y-m-d', $cursor);
    $labelsDias[] = date('d/m', $cursor);
    $datosDias[]  = $ventasMap[$d] ?? 0;
    $cursor = strtotime('+1 day', $cursor);
}

/* ============================================================
   GRÁFICO 2 — TOP 10 PRODUCTOS (barras horizontales)
   ============================================================ */
$stmt = $db->prepare("
    SELECT ci.nombre_producto AS nombre,
           SUM(ci.cantidad_unidades) AS unidades,
           SUM(ci.subtotal)          AS facturado
    FROM comprobante_items ci
    JOIN comprobantes c ON c.id = ci.comprobante_id
    WHERE c.fecha BETWEEN ? AND ?
    GROUP BY ci.producto_id, ci.nombre_producto
    ORDER BY facturado DESC
    LIMIT 10
");
$stmt->execute([$desde, $hasta]);
$topProductos = $stmt->fetchAll();

$labelsProductos = array_map(fn($p) => $p['nombre'], $topProductos);
$datosProductos  = array_map(fn($p) => (float)$p['facturado'], $topProductos);

/* ============================================================
   GRÁFICO 3 — TOP 10 CLIENTES (barras horizontales)
   ============================================================ */
$stmt = $db->prepare("
    SELECT cl.nombre,
           COUNT(c.id)             AS comprobantes,
           COALESCE(SUM(c.total),0) AS facturado
    FROM comprobantes c
    JOIN clientes cl ON cl.id = c.cliente_id
    WHERE c.fecha BETWEEN ? AND ?
    GROUP BY cl.id, cl.nombre
    ORDER BY facturado DESC
    LIMIT 10
");
$stmt->execute([$desde, $hasta]);
$topClientes = $stmt->fetchAll();

$labelsClientes = array_map(fn($c) => $c['nombre'], $topClientes);
$datosClientes  = array_map(fn($c) => (float)$c['facturado'], $topClientes);

/* ============================================================
   GRÁFICO 4 — VENTAS POR LISTA / MARGEN (dona)
   ============================================================ */
$stmt = $db->prepare("
    SELECT l.codigo, l.margen,
           COUNT(c.id)              AS qty,
           COALESCE(SUM(c.total),0) AS total
    FROM listas l
    LEFT JOIN comprobantes c
           ON c.lista_id = l.id
          AND c.fecha BETWEEN ? AND ?
    GROUP BY l.id, l.codigo, l.margen
    HAVING total > 0
    ORDER BY l.margen DESC
");
$stmt->execute([$desde, $hasta]);
$ventasPorMargen = $stmt->fetchAll();

$labelsListas = array_map(
    fn($v) => $v['codigo'] . ' (' . rtrim(rtrim(number_format((float)$v['margen'], 2, '.', ''), '0'), '.') . '%)',
    $ventasPorMargen
);
$datosListas = array_map(fn($v) => (float)$v['total'], $ventasPorMargen);

require_once __DIR__ . '/config/layout.php';
?>

<!-- Selector de período: form GET → reload completo -->
<form method="GET" class="card" style="margin-bottom:16px;">
    <div class="card-body d-flex gap-2 align-center" style="flex-wrap:wrap;">
        <strong>Período:</strong>
        <select name="periodo" onchange="this.form.submit()" class="form-control" style="max-width:240px;">
            <option value="actual"   <?= $periodo === 'actual'   ? 'selected' : '' ?>>Este mes</option>
            <option value="anterior" <?= $periodo === 'anterior' ? 'selected' : '' ?>>Mes anterior</option>
        </select>
        <span class="text-muted" style="font-size:13px;">Mostrando: <strong><?= e($periodoLabel) ?></strong></span>
        <noscript><button type="submit" class="btn btn-sm btn-bordo">Aplicar</button></noscript>
    </div>
</form>

<!-- KPIs -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Clientes activos</div>
        <div class="stat-value"><?= $totalClientes ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Productos activos</div>
        <div class="stat-value"><?= $totalProductos ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Comprobantes del período</div>
        <div class="stat-value"><?= $compPeriodo ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Emitidos pendientes de cobro</div>
        <div class="stat-value"><?= $compEmitidos ?></div>
    </div>
</div>

<!-- Fila 1 de gráficos -->
<div class="d-flex gap-2" style="align-items:flex-start; flex-wrap:wrap; margin-top:8px;">

    <div class="card" style="flex:2; min-width:380px;">
        <div class="card-header"><span class="card-title">Ventas diarias</span></div>
        <div class="card-body" style="position:relative; height:320px;">
            <canvas id="chartVentasDiarias"></canvas>
        </div>
    </div>

    <div class="card" style="flex:1; min-width:320px;">
        <div class="card-header"><span class="card-title">Ventas por lista / margen</span></div>
        <div class="card-body" style="position:relative; height:320px;">
            <canvas id="chartListas"></canvas>
        </div>
    </div>

</div>

<!-- Fila 2 de gráficos -->
<div class="d-flex gap-2" style="align-items:flex-start; flex-wrap:wrap; margin-top:16px;">

    <div class="card" style="flex:1; min-width:360px;">
        <div class="card-header"><span class="card-title">Top productos (facturado)</span></div>
        <div class="card-body" style="position:relative; height:380px;">
            <canvas id="chartProductos"></canvas>
        </div>
    </div>

    <div class="card" style="flex:1; min-width:360px;">
        <div class="card-header"><span class="card-title">Top clientes (facturado)</span></div>
        <div class="card-body" style="position:relative; height:380px;">
            <canvas id="chartClientes"></canvas>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    // Datos inyectados desde PHP
    const labelsDias     = <?= json_encode($labelsDias,     JSON_UNESCAPED_UNICODE) ?>;
    const datosDias      = <?= json_encode($datosDias,      JSON_UNESCAPED_UNICODE) ?>;
    const labelsProductos= <?= json_encode($labelsProductos,JSON_UNESCAPED_UNICODE) ?>;
    const datosProductos = <?= json_encode($datosProductos, JSON_UNESCAPED_UNICODE) ?>;
    const labelsClientes = <?= json_encode($labelsClientes, JSON_UNESCAPED_UNICODE) ?>;
    const datosClientes  = <?= json_encode($datosClientes,  JSON_UNESCAPED_UNICODE) ?>;
    const labelsListas   = <?= json_encode($labelsListas,   JSON_UNESCAPED_UNICODE) ?>;
    const datosListas    = <?= json_encode($datosListas,    JSON_UNESCAPED_UNICODE) ?>;

    const BORDO      = '#631636';
    const BORDO_SOFT = 'rgba(99, 22, 54, 0.15)';
    const PALETA     = ['#631636','#8b2a55','#b34577','#d46d9b','#a87d99','#7a4763','#c19a8a','#5d3a4a','#e0a8c0','#3d1a2a'];

    const fmtPeso = (v) => '$' + Number(v).toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    // Defaults globales
    Chart.defaults.font.family = 'Arial, sans-serif';
    Chart.defaults.font.size = 12;
    Chart.defaults.color = '#444';

    // 1) Ventas diarias (línea)
    new Chart(document.getElementById('chartVentasDiarias'), {
        type: 'line',
        data: {
            labels: labelsDias,
            datasets: [{
                label: 'Ventas',
                data: datosDias,
                borderColor: BORDO,
                backgroundColor: BORDO_SOFT,
                fill: true,
                tension: 0.3,
                pointBackgroundColor: BORDO,
                pointRadius: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (ctx) => fmtPeso(ctx.parsed.y) } }
            },
            scales: {
                y: { ticks: { callback: (v) => fmtPeso(v) } }
            }
        }
    });

    // 2) Top productos (barras horizontales)
    new Chart(document.getElementById('chartProductos'), {
        type: 'bar',
        data: {
            labels: labelsProductos,
            datasets: [{
                label: 'Facturado',
                data: datosProductos,
                backgroundColor: BORDO,
                borderRadius: 4
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (ctx) => fmtPeso(ctx.parsed.x) } }
            },
            scales: {
                x: { ticks: { callback: (v) => fmtPeso(v) } }
            }
        }
    });

    // 3) Top clientes (barras horizontales)
    new Chart(document.getElementById('chartClientes'), {
        type: 'bar',
        data: {
            labels: labelsClientes,
            datasets: [{
                label: 'Facturado',
                data: datosClientes,
                backgroundColor: '#8b2a55',
                borderRadius: 4
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (ctx) => fmtPeso(ctx.parsed.x) } }
            },
            scales: {
                x: { ticks: { callback: (v) => fmtPeso(v) } }
            }
        }
    });

    // 4) Ventas por lista / margen (dona)
    new Chart(document.getElementById('chartListas'), {
        type: 'doughnut',
        data: {
            labels: labelsListas,
            datasets: [{
                data: datosListas,
                backgroundColor: PALETA,
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ctx.label + ': ' + fmtPeso(ctx.parsed)
                    }
                }
            }
        }
    });
})();
</script>

<?php require_once __DIR__ . '/config/layout_end.php'; ?>

<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$db = getDB();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) redirect(BASE_PATH . '/comprobantes/');

$comp = $db->prepare("
    SELECT c.*, cl.nombre AS cliente_nombre, cl.telefono AS cliente_tel, cl.ciudad AS cliente_ciudad, cl.direccion AS cliente_dir,
           l.codigo AS lista_codigo, l.margen AS lista_margen
    FROM comprobantes c
    JOIN clientes cl ON cl.id = c.cliente_id
    JOIN listas   l  ON l.id  = c.lista_id
    WHERE c.id = ?
");
$comp->execute([$id]);
$comp = $comp->fetch();
if (!$comp) redirect(BASE_PATH . '/comprobantes/');

$items = $db->prepare("
    SELECT ci.*, p.marca
    FROM comprobante_items ci
    LEFT JOIN productos p ON p.id = ci.producto_id
    WHERE ci.comprobante_id = ?
    ORDER BY ci.id ASC
");
$items->execute([$id]);
$items = $items->fetchAll();

$totalCajas    = 0;
$totalUnidades = 0;
foreach ($items as $it) {
    $totalCajas    += (int)$it['cantidad_cajas'];
    $totalUnidades += (int)$it['cantidad_unidades'];
}

$pageTitle = 'Comprobante #' . $comp['numero']
           . (isset($comp['numero_cliente']) ? ' — Pedido N.° ' . $comp['numero_cliente'] . ' del cliente' : '');
$extraHead = '<style>
@media print {
    body * { display: none !important; }
    body::after {
        display: block !important;
        content: "Para imprimir este comprobante, usá el botón Imprimir o PDF de la barra superior.";
        font-family: sans-serif;
        font-size: 16px;
        text-align: center;
        padding: 40px;
        color: #631636;
    }
}
</style>';
$topbarActions = '
    <a href="' . BASE_PATH . '/comprobantes/" class="btn btn-secondary">← Volver</a>
    ' . ($comp['estado'] === 'borrador' ? '<a href="' . BASE_PATH . '/comprobantes/crear.php?id=' . $id . '" class="btn btn-outline">Editar</a>' : '') . '
    <a href="' . BASE_PATH . '/comprobantes/imprimir.php?id=' . $id . '" class="btn btn-outline" target="_blank">🖨 Imprimir</a>
    <a href="' . BASE_PATH . '/comprobantes/imprimir.php?id=' . $id . '&pdf=1" class="btn btn-outline" target="_blank">📄 PDF</a>
';
$msg = $_GET['msg'] ?? '';
require_once __DIR__ . '/../config/layout.php';

$badgeMap = ['emitido'=>'badge-bordo','cobrado'=>'badge-success','borrador'=>'badge-warning'];
$cls = $badgeMap[$comp['estado']] ?? 'badge-gray';
?>

<?php if ($msg === 'created'): ?><div class="alert alert-success" data-autodismiss>Comprobante creado correctamente.</div><?php endif; ?>
<?php if ($msg === 'updated'): ?><div class="alert alert-success" data-autodismiss>Comprobante actualizado correctamente.</div><?php endif; ?>
<?php if ($msg === 'not_borrador'): ?><div class="alert alert-warning" data-autodismiss>Solo se pueden editar comprobantes en borrador.</div><?php endif; ?>

<div class="d-flex gap-2" style="align-items:flex-start;">

    <div style="flex:2;">
        <!-- Encabezado -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Comprobante #<?= $comp['numero'] ?></span>
                <?php if (isset($comp['numero_cliente'])): ?>
                <span class="text-muted" style="font-size:12px; font-weight:400;">— Pedido N.° <?= $comp['numero_cliente'] ?> del cliente</span>
                <?php endif; ?>
                <span class="badge <?= $cls ?>" style="font-size:13px;"><?= $comp['estado'] ?></span>
            </div>
            <div class="card-body">
                <div class="form-row">
                    <div>
                        <div class="text-muted" style="font-size:11px; margin-bottom:4px;">CLIENTE</div>
                        <strong><?= e($comp['cliente_nombre']) ?></strong><br>
                        <?php if ($comp['cliente_ciudad']): ?><span class="text-muted"><?= e($comp['cliente_ciudad']) ?></span><br><?php endif; ?>
                        <?php if ($comp['cliente_dir']): ?><span class="text-muted"><?= e($comp['cliente_dir']) ?></span><br><?php endif; ?>
                        <?php if ($comp['cliente_tel']): ?><span class="text-muted"><?= e($comp['cliente_tel']) ?></span><?php endif; ?>
                    </div>
                    <div>
                        <div class="text-muted" style="font-size:11px; margin-bottom:4px;">DETALLES</div>
                        <div>Fecha: <strong><?= date('d/m/Y', strtotime($comp['fecha'])) ?></strong></div>
                        <div>Lista: <span class="badge badge-bordo"><?= e($comp['lista_codigo']) ?> — <?= $comp['lista_margen'] ?>%</span></div>
                        <?php if ($comp['notas']): ?><div class="text-muted" style="margin-top:6px;"><?= e($comp['notas']) ?></div><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Items -->
        <div class="card" style="margin-top:16px;">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Marca</th>
                            <th style="text-align:right;">Costo/ud</th>
                            <th style="text-align:right;">Margen</th>
                            <th style="text-align:right;">Precio/ud</th>
                            <th style="text-align:right;">UPC</th>
                            <th style="text-align:right;">Cajas</th>
                            <th style="text-align:right;">Unidades</th>
                            <th style="text-align:right;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $it): ?>
                    <tr>
                        <td><strong><?= e($it['nombre_producto']) ?></strong></td>
                        <td><?= e($it['marca'] ?? '—') ?></td>
                        <td class="text-right"><?= precio((float)$it['costo_unitario']) ?></td>
                        <td class="text-right"><?= $it['margen_aplicado'] ?>%</td>
                        <td class="text-right fw-bold"><?= precio((float)$it['precio_unitario']) ?></td>
                        <td class="text-right"><?= (int)$it['unidades_por_caja'] ?></td>
                        <td class="text-right"><?= (int)$it['cantidad_cajas'] ?></td>
                        <td class="text-right"><?= (int)$it['cantidad_unidades'] > 0 ? (int)$it['cantidad_unidades'] : '—' ?></td>
                        <td class="text-right fw-bold text-bordo"><?= precio((float)$it['subtotal']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Resumen y acciones -->
    <div style="flex:1; min-width:220px;">
        <div class="card">
            <div class="card-header"><span class="card-title">Resumen</span></div>
            <div class="card-body">
                <div class="d-flex justify-between mb-1">
                    <span class="text-muted">Entrega</span>
                    <span><?= ($comp['tipo_entrega'] ?? 'envio') === 'retira' ? '🏪 Retira en local' : '🚚 Envío a domicilio' ?></span>
                </div>
                <hr style="border:none;border-top:1px solid var(--border);margin:8px 0;">
                <div class="d-flex justify-between mb-1">
                    <span style="color:var(--bordo); font-size:13px;">Total cajas</span>
                    <span class="fw-bold text-bordo"><?= $totalCajas ?></span>
                </div>
                <div class="d-flex justify-between mb-1">
                    <span style="color:var(--bordo); font-size:13px;">Total unidades sueltas</span>
                    <span class="fw-bold text-bordo"><?= $totalUnidades ?></span>
                </div>
                <hr style="border:none;border-top:1px solid var(--border);margin:8px 0;">
                <div class="d-flex justify-between mb-1">
                    <span class="text-muted">Subtotal</span>
                    <span class="fw-bold"><?= precio((float)$comp['subtotal']) ?></span>
                </div>
                <?php if (($comp['tipo_entrega'] ?? 'envio') !== 'retira'): ?>
                <div class="d-flex justify-between mb-1">
                    <span class="text-muted">Envío</span>
                    <span><?= (float)$comp['envio'] > 0 ? precio((float)$comp['envio']) : 'Gratis' ?></span>
                </div>
                <?php endif; ?>
                <?php if ((float)($comp['descuento'] ?? 0) > 0): ?>
                <div class="d-flex justify-between mb-1">
                    <span class="text-muted">Bonificación</span>
                    <span class="fw-bold text-bordo">−<?= precio((float)$comp['descuento']) ?></span>
                </div>
                <?php endif; ?>
                <hr style="border:none;border-top:1px solid var(--border);margin:12px 0;">
                <div class="d-flex justify-between">
                    <span class="fw-bold">TOTAL</span>
                    <span class="fw-bold text-bordo" style="font-size:20px;"><?= precio((float)$comp['total']) ?></span>
                </div>
            </div>
        </div>

        <div class="card" style="margin-top:16px;">
            <div class="card-header"><span class="card-title">Cambiar estado</span></div>
            <div class="card-body">
                <form method="POST" action="<?= BASE_PATH ?>/comprobantes/actions.php">
                    <input type="hidden" name="action" value="estado">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <select name="estado" class="form-control" style="margin-bottom:10px;"
                            onchange="document.getElementById('medio-pago-wrap').style.display=this.value==='cobrado'?'block':'none'">
                        <option value="borrador" <?= $comp['estado']==='borrador' ? 'selected':'' ?>>Borrador</option>
                        <option value="emitido"  <?= $comp['estado']==='emitido'  ? 'selected':'' ?>>Emitido</option>
                        <option value="cobrado"  <?= $comp['estado']==='cobrado'  ? 'selected':'' ?>>Cobrado</option>
                    </select>
                    <div id="medio-pago-wrap" style="display:<?= $comp['estado']==='cobrado'?'block':'none' ?>; margin-bottom:10px;">
                        <label style="font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); display:block; margin-bottom:4px;">Medio de cobro</label>
                        <select name="medio_pago" class="form-control">
                            <option value="efectivo"      <?= ($comp['medio_pago']??'')==='efectivo'      ? 'selected':'' ?>>💵 Efectivo</option>
                            <option value="transferencia" <?= ($comp['medio_pago']??'')==='transferencia' ? 'selected':'' ?>>💳 Transferencia</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Actualizar</button>
                </form>
            </div>
        </div>

        <div style="margin-top:12px;">
            <a href="<?= BASE_PATH ?>/comprobantes/actions.php?action=delete&id=<?= $id ?>"
               class="btn btn-danger w-100"
               data-confirm="¿Eliminar este comprobante? Esta acción no se puede deshacer.">Eliminar comprobante</a>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

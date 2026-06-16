<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$db = getDB();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) redirect(BASE_PATH . '/pedidos_galpon/');

$stmt = $db->prepare("
    SELECT pg.*, pv.nombre AS proveedor_nombre
    FROM pedidos_galpon pg
    JOIN proveedores pv ON pv.id = pg.proveedor_id
    WHERE pg.id = ?
");
$stmt->execute([$id]);
$pedido = $stmt->fetch();
if (!$pedido) redirect(BASE_PATH . '/pedidos_galpon/');

$items = $db->prepare("SELECT * FROM pedidos_galpon_items WHERE pedido_id=? ORDER BY id ASC");
$items->execute([$id]);
$items = $items->fetchAll();

$totalCajas    = 0;
$totalUnidades = 0;
foreach ($items as $it) {
    $totalCajas    += (int)$it['cajas'];
    $totalUnidades += (int)$it['unidades'];
}

$esBorrador  = $pedido['estado_pedido'] === 'borrador';
$esEnviado   = $pedido['estado_pedido'] === 'enviado';
$esRecibido  = $pedido['estado_pedido'] === 'recibido';

$estadoPedBadge = ['borrador' => 'badge-gray', 'enviado' => 'badge-warning', 'recibido' => 'badge-success'];

$msg = $_GET['msg'] ?? '';

$pageTitle     = 'Pedido #' . $id;
$topbarActions = '
    <a href="' . BASE_PATH . '/pedidos_galpon/" class="btn btn-secondary">← Volver</a>'
    . ($esBorrador ? ' <a href="' . BASE_PATH . '/pedidos_galpon/crear.php?id=' . $id . '" class="btn btn-outline">Editar</a>' : '')
    . ' <a href="' . BASE_PATH . '/pedidos_galpon/imagen.php?id=' . $id . '" class="btn btn-outline" target="_blank">🖼 Imagen</a>';

require_once __DIR__ . '/../config/layout.php';
?>

<?php if ($msg === 'created'):  ?><div class="alert alert-success"  data-autodismiss>Pedido creado.</div><?php endif; ?>
<?php if ($msg === 'updated'):  ?><div class="alert alert-success"  data-autodismiss>Pedido actualizado.</div><?php endif; ?>
<?php if ($msg === 'enviado'):  ?><div class="alert alert-success"  data-autodismiss>Pedido marcado como enviado.</div><?php endif; ?>
<?php if ($msg === 'recibido'): ?><div class="alert alert-success"  data-autodismiss>Recepción registrada.</div><?php endif; ?>
<?php if ($msg === 'no_editable'): ?><div class="alert alert-warning" data-autodismiss>Este pedido no se puede editar en el estado actual.</div><?php endif; ?>
<?php if ($msg === 'error'):    ?><div class="alert alert-warning"  data-autodismiss>Ocurrió un error. Intentá de nuevo.</div><?php endif; ?>

<div class="d-flex gap-2" style="align-items:flex-start;">

    <!-- Panel izquierdo: info + items -->
    <div style="flex:2;">
        <!-- Cabecera -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Pedido #<?= $id ?></span>
                <span class="badge <?= $estadoPedBadge[$pedido['estado_pedido']] ?>"><?= $pedido['estado_pedido'] ?></span>
            </div>
            <div class="card-body">
                <div class="form-row" style="flex-wrap:wrap; gap:16px;">
                    <div>
                        <div class="text-muted" style="font-size:11px; margin-bottom:4px;">PROVEEDOR</div>
                        <strong><?= e($pedido['proveedor_nombre']) ?></strong>
                    </div>
                    <div>
                        <div class="text-muted" style="font-size:11px; margin-bottom:4px;">FECHA PEDIDO</div>
                        <strong><?= date('d/m/Y', strtotime($pedido['fecha_pedido'])) ?></strong>
                    </div>
                    <?php if ($pedido['fecha_recepcion']): ?>
                    <div>
                        <div class="text-muted" style="font-size:11px; margin-bottom:4px;">RECIBIDO EL</div>
                        <strong><?= date('d/m/Y', strtotime($pedido['fecha_recepcion'])) ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if ($pedido['total'] !== null): ?>
                    <div>
                        <div class="text-muted" style="font-size:11px; margin-bottom:4px;">TOTAL</div>
                        <strong class="text-bordo" style="font-size:16px;"><?= precio((float)$pedido['total']) ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($pedido['observaciones']): ?>
                <div class="text-muted" style="margin-top:10px; font-size:13px;"><?= e($pedido['observaciones']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Items -->
        <div class="card" style="margin-top:16px;">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width:100px;">Código</th>
                            <th>Nombre</th>
                            <th style="text-align:center; width:75px;">Cajas</th>
                            <th style="text-align:center; width:80px;">Unidades</th>
                            <?php if ($esRecibido || $esEnviado): ?>
                            <th style="text-align:center; width:90px;">Cant. recibida</th>
                            <?php endif; ?>
                            <th style="text-align:right; width:120px;">Costo unit.</th>
                            <th style="text-align:right; width:120px;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($items)): ?>
                        <tr><td colspan="6" class="text-center text-muted" style="padding:20px;">Sin productos.</td></tr>
                    <?php else: ?>
                        <?php foreach ($items as $it):
                            $cantRec = $it['cantidad_recibida'];
                            $difiere = $esRecibido && $cantRec !== null && (int)$cantRec !== (int)$it['cajas'];
                        ?>
                        <tr>
                            <td class="text-muted" style="font-family:monospace; font-size:11px;"><?= e($it['codigo'] ?: '—') ?></td>
                            <td><strong><?= e($it['nombre']) ?></strong></td>
                            <td style="text-align:center;"><?= (int)$it['cajas'] ?: '—' ?></td>
                            <td style="text-align:center;"><?= (int)$it['unidades'] ?: '—' ?></td>
                            <?php if ($esRecibido || $esEnviado): ?>
                            <td style="text-align:center;">
                                <?php if ($cantRec !== null): ?>
                                    <span class="<?= $difiere ? 'text-bordo fw-bold' : '' ?>">
                                        <?= (int)$cantRec ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td style="text-align:right;" class="text-muted">
                                <?= $it['costo_unitario'] !== null ? precio((float)$it['costo_unitario']) : '—' ?>
                            </td>
                            <td style="text-align:right;" class="fw-bold">
                                <?= $it['subtotal'] !== null ? precio((float)$it['subtotal']) : '—' ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fila-total">
                            <td colspan="2" style="text-align:right; font-weight:700; padding:8px;">TOTAL</td>
                            <td style="text-align:center; font-weight:700; padding:8px;"><?= $totalCajas ?: '—' ?></td>
                            <td style="text-align:center; font-weight:700; padding:8px;"><?= $totalUnidades ?: '—' ?></td>
                            <?php if ($esRecibido || $esEnviado): ?><td></td><?php endif; ?>
                            <td></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Panel derecho: acciones -->
    <div style="flex:1; min-width:220px;">

        <?php if ($esBorrador): ?>
        <!-- BORRADOR: enviar o eliminar -->
        <div class="card">
            <div class="card-header"><span class="card-title">Acciones</span></div>
            <div class="card-body">
                <form method="POST" action="<?= BASE_PATH ?>/pedidos_galpon/actions.php"
                      onsubmit="return confirm('¿Marcar como enviado al proveedor? Ya no podrás editarlo.')">
                    <input type="hidden" name="action" value="enviar">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button type="submit" class="btn btn-primary w-100">✈ Marcar como enviado</button>
                </form>
                <div style="margin-top:8px;">
                    <a href="<?= BASE_PATH ?>/pedidos_galpon/actions.php?action=delete&id=<?= $id ?>"
                       class="btn btn-danger w-100"
                       data-confirm="¿Eliminar este pedido?">Eliminar pedido</a>
                </div>
            </div>
        </div>

        <?php elseif ($esEnviado): ?>
        <!-- ENVIADO: registrar recepción -->
        <div class="card">
            <div class="card-header"><span class="card-title">Registrar recepción</span></div>
            <div class="card-body">
                <form method="POST" action="<?= BASE_PATH ?>/pedidos_galpon/actions.php"
                      onsubmit="return confirm('¿Confirmar recepción? El pedido quedará bloqueado.')">
                    <input type="hidden" name="action" value="recibir">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <div class="form-group" style="margin-bottom:10px;">
                        <label class="form-label">Fecha de recepción</label>
                        <input type="date" name="fecha_recepcion" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group" style="margin-bottom:10px;">
                        <label class="form-label">Total final (si difiere)</label>
                        <input type="number" name="total_ajustado" class="form-control"
                               step="0.01" min="0"
                               placeholder="<?= $pedido['total'] !== null ? number_format((float)$pedido['total'], 2, '.', '') : '' ?>"
                               value="">
                        <div class="text-muted" style="font-size:11px; margin-top:3px;">Dejá vacío para conservar el total actual.</div>
                    </div>
                    <div class="text-muted" style="font-size:12px; margin-bottom:8px;">
                        Cantidades recibidas por item — dejá en blanco = igual a lo pedido:
                    </div>
                    <?php foreach ($items as $it): ?>
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px; font-size:13px;">
                        <span style="flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= e($it['nombre']) ?>">
                            <?= e(mb_strimwidth($it['nombre'], 0, 28, '…')) ?>
                        </span>
                        <input type="number" name="cantidad_recibida[<?= $it['id'] ?>]"
                               class="form-control" min="0"
                               placeholder="<?= (int)$it['cajas'] ?>"
                               style="width:70px; text-align:center;">
                    </div>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-primary w-100" style="margin-top:8px;">Confirmar recepción</button>
                </form>
            </div>
        </div>

        <?php elseif ($esRecibido): ?>
        <!-- RECIBIDO -->
        <div class="card">
            <div class="card-body text-muted" style="font-size:13px;">
                Pedido <strong>recibido</strong>.<br><br>
                La deuda con el proveedor se registra en
                <a href="<?= BASE_PATH ?>/cuentas/" class="text-bordo">Cuentas</a>.
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

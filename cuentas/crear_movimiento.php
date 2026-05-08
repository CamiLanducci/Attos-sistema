<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
$pageTitle     = 'Movimiento manual';
$topbarActions = '<a href="/attos/cuentas/" class="btn btn-secondary">← Volver</a>';

$db = getDB();

$pedidos      = $db->query("
    SELECT pg.id, pg.fecha_pedido, pg.estado_pedido, pv.nombre AS proveedor_nombre
    FROM pedidos_galpon pg
    JOIN proveedores pv ON pv.id = pg.proveedor_id
    ORDER BY pg.fecha_pedido DESC LIMIT 200
")->fetchAll();
$comprobantes = $db->query("
    SELECT c.id, c.numero, cl.nombre AS cliente
    FROM comprobantes c JOIN clientes cl ON cl.id=c.cliente_id
    ORDER BY c.numero DESC LIMIT 200
")->fetchAll();

$msg = $_GET['msg'] ?? '';
require_once __DIR__ . '/../config/layout.php';
?>

<?php if ($msg === 'error'): ?><div class="alert alert-warning" data-autodismiss>Error al guardar el movimiento.</div><?php endif; ?>

<div class="card" style="max-width:560px;">
    <div class="card-header">
        <span class="card-title">Movimiento manual</span>
    </div>
    <div class="card-body">
        <div class="text-muted" style="font-size:12px; margin-bottom:16px;">
            Usá esta pantalla para saldos iniciales, ajustes o casos especiales. El movimiento queda sin par automático.
        </div>
        <form method="POST" action="/attos/cuentas/actions.php">
            <input type="hidden" name="action" value="crear_movimiento">

            <div class="form-row">
                <div class="form-group" style="flex:1;">
                    <label class="form-label">Cuenta *</label>
                    <select name="cuenta" class="form-control" required>
                        <option value="">— Seleccionar —</option>
                        <option value="area_520">Area 520</option>
                        <option value="alfre">Cuenta Alfre</option>
                        <option value="patrimonio">Patrimonio</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Tipo *</label>
                    <select name="tipo" class="form-control" required>
                        <option value="cargo">Cargo</option>
                        <option value="pago">Pago</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex:1;">
                    <label class="form-label">Monto *</label>
                    <input type="number" name="monto" class="form-control"
                           step="0.01" min="0.01" placeholder="0,00" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Fecha</label>
                    <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Descripción</label>
                <input type="text" name="descripcion" class="form-control" maxlength="500"
                       placeholder="Saldo inicial, ajuste, etc.">
            </div>

            <div class="form-group">
                <label class="form-label">Pedido vinculado <span class="text-muted">(opcional)</span></label>
                <select name="pedido_galpon_id" class="form-control">
                    <option value="">— Ninguno —</option>
                    <?php foreach ($pedidos as $p): ?>
                    <option value="<?= $p['id'] ?>">
                        #<?= $p['id'] ?> · <?= date('d/m/Y', strtotime($p['fecha_pedido'])) ?> · <?= e($p['proveedor_nombre']) ?> · <?= $p['estado_pedido'] ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Comprobante vinculado <span class="text-muted">(opcional)</span></label>
                <select name="comprobante_id" class="form-control">
                    <option value="">— Ninguno —</option>
                    <?php foreach ($comprobantes as $c): ?>
                    <option value="<?= $c['id'] ?>">
                        #<?= $c['numero'] ?> · <?= e($c['cliente']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary w-100">Guardar movimiento</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

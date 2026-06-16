<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$db = getDB();

$filtroProveedor  = isset($_GET['proveedor_id']) ? (int)$_GET['proveedor_id'] : 0;
$filtroEstadoPed  = $_GET['estado_pedido'] ?? '';
$msg              = $_GET['msg']           ?? '';

$where  = [];
$params = [];
if ($filtroProveedor) {
    $where[] = 'p.proveedor_id = ?'; $params[] = $filtroProveedor;
}
if ($filtroEstadoPed && in_array($filtroEstadoPed, ['borrador','enviado','recibido'])) {
    $where[] = 'p.estado_pedido = ?'; $params[] = $filtroEstadoPed;
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT p.id, p.fecha_pedido, p.fecha_recepcion, p.estado_pedido,
           p.total, p.created_at,
           pv.nombre AS proveedor_nombre,
           COUNT(i.id) AS cant_items
    FROM pedidos_galpon p
    JOIN proveedores pv ON pv.id = p.proveedor_id
    LEFT JOIN pedidos_galpon_items i ON i.pedido_id = p.id
    $whereSQL
    GROUP BY p.id
    ORDER BY p.created_at DESC
");
$stmt->execute($params);
$pedidos = $stmt->fetchAll();

$proveedores = $db->query("SELECT id, nombre FROM proveedores WHERE activo=1 ORDER BY nombre")->fetchAll();

$estadoPedBadge = [
    'borrador' => 'badge-gray',
    'enviado'  => 'badge-warning',
    'recibido' => 'badge-success',
];

$pageTitle     = 'Pedidos galpón';
$topbarActions = '<a href="' . BASE_PATH . '/pedidos_galpon/crear.php" class="btn btn-primary">+ Nuevo pedido</a>
                  <a href="' . BASE_PATH . '/pedidos_galpon/proveedores.php" class="btn btn-outline" style="margin-left:6px;">Proveedores</a>';
require_once __DIR__ . '/../config/layout.php';
?>

<?php if ($msg === 'created'): ?><div class="alert alert-success" data-autodismiss>Pedido creado.</div><?php endif; ?>
<?php if ($msg === 'updated'): ?><div class="alert alert-success" data-autodismiss>Pedido actualizado.</div><?php endif; ?>
<?php if ($msg === 'deleted'): ?><div class="alert alert-success" data-autodismiss>Pedido eliminado.</div><?php endif; ?>

<div class="card">
    <div class="filters" style="flex-wrap:wrap; gap:8px;">
        <form method="GET" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <select name="proveedor_id" class="form-control" style="width:180px;" onchange="this.form.submit()">
                <option value="">Todos los proveedores</option>
                <?php foreach ($proveedores as $pv): ?>
                <option value="<?= $pv['id'] ?>" <?= $filtroProveedor === (int)$pv['id'] ? 'selected' : '' ?>>
                    <?= e($pv['nombre']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="estado_pedido" class="form-control" style="width:140px;" onchange="this.form.submit()">
                <option value="">Todos los estados</option>
                <option value="borrador" <?= $filtroEstadoPed==='borrador' ? 'selected':'' ?>>Borrador</option>
                <option value="enviado"  <?= $filtroEstadoPed==='enviado'  ? 'selected':'' ?>>Enviado</option>
                <option value="recibido" <?= $filtroEstadoPed==='recibido' ? 'selected':'' ?>>Recibido</option>
            </select>
            <?php if ($filtroProveedor || $filtroEstadoPed): ?>
                <a href="<?= BASE_PATH ?>/pedidos_galpon/" class="btn btn-secondary btn-sm">✕ Limpiar</a>
            <?php endif; ?>
        </form>
        <span class="text-muted" style="font-size:12px; margin-left:auto;"><?= count($pedidos) ?> pedido<?= count($pedidos)!==1?'s':'' ?></span>
    </div>

    <?php if (empty($pedidos)): ?>
    <div class="empty-state">
        <div class="empty-icon">📦</div>
        <p>No hay pedidos todavía. <a href="<?= BASE_PATH ?>/pedidos_galpon/crear.php" class="text-bordo">Crear el primero</a>.</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Fecha pedido</th>
                    <th>Proveedor</th>
                    <th style="text-align:center;">Items</th>
                    <th>Estado pedido</th>
                    <th style="text-align:right;">Total</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pedidos as $p): ?>
            <tr>
                <td><strong>#<?= $p['id'] ?></strong></td>
                <td><?= date('d/m/Y', strtotime($p['fecha_pedido'])) ?></td>
                <td><?= e($p['proveedor_nombre']) ?></td>
                <td style="text-align:center;" class="text-muted"><?= (int)$p['cant_items'] ?></td>
                <td><span class="badge <?= $estadoPedBadge[$p['estado_pedido']] ?? 'badge-gray' ?>"><?= $p['estado_pedido'] ?></span></td>
                <td style="text-align:right;" class="fw-bold">
                    <?= $p['total'] !== null ? precio((float)$p['total']) : '—' ?>
                </td>
                <td class="text-right">
                    <a href="<?= BASE_PATH ?>/pedidos_galpon/ver.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-secondary">Ver</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

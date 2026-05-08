<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$db  = getDB();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion   = $_POST['accion'] ?? '';
    $cuentaOk = in_array($_POST['cuenta'] ?? '', ['area_520','alfre','']) ? (($_POST['cuenta'] ?? '') ?: null) : null;

    if ($accion === 'crear') {
        $nombre   = trim($_POST['nombre']   ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $contacto = trim($_POST['contacto'] ?? '');
        if ($nombre) {
            $db->prepare("INSERT INTO proveedores (nombre, telefono, contacto, cuenta) VALUES (?,?,?,?)")
               ->execute([$nombre, $telefono ?: null, $contacto ?: null, $cuentaOk]);
            $msg = 'created';
        }
    } elseif ($accion === 'editar') {
        $id       = (int)$_POST['id'];
        $nombre   = trim($_POST['nombre']   ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $contacto = trim($_POST['contacto'] ?? '');
        if ($id && $nombre) {
            $db->prepare("UPDATE proveedores SET nombre=?, telefono=?, contacto=?, cuenta=? WHERE id=?")
               ->execute([$nombre, $telefono ?: null, $contacto ?: null, $cuentaOk, $id]);
            $msg = 'updated';
        }
    } elseif ($accion === 'desactivar') {
        $id = (int)$_POST['id'];
        if ($id) {
            $db->prepare("UPDATE proveedores SET activo=0 WHERE id=?")->execute([$id]);
            $msg = 'deleted';
        }
    }
    redirect('/attos/pedidos_galpon/proveedores.php?msg=' . $msg);
}

$msg    = $_GET['msg'] ?? '';
$editId = isset($_GET['editar']) ? (int)$_GET['editar'] : 0;
$editProv = null;
if ($editId) {
    $st = $db->prepare("SELECT * FROM proveedores WHERE id=? AND activo=1");
    $st->execute([$editId]);
    $editProv = $st->fetch();
}

$proveedores = $db->query("SELECT * FROM proveedores WHERE activo=1 ORDER BY nombre")->fetchAll();
$cuentaLabel = ['area_520' => 'Area 520', 'alfre' => 'Cuenta Alfre'];

$pageTitle     = 'Proveedores';
$topbarActions = '<a href="/attos/pedidos_galpon/" class="btn btn-secondary">← Pedidos galpón</a>';
require_once __DIR__ . '/../config/layout.php';
?>

<?php if ($msg === 'created'): ?><div class="alert alert-success" data-autodismiss>Proveedor creado.</div><?php endif; ?>
<?php if ($msg === 'updated'): ?><div class="alert alert-success" data-autodismiss>Proveedor actualizado.</div><?php endif; ?>
<?php if ($msg === 'deleted'): ?><div class="alert alert-success" data-autodismiss>Proveedor desactivado.</div><?php endif; ?>

<div class="d-flex gap-2" style="align-items:flex-start;">
    <div style="flex:2;">
        <div class="card">
            <div class="table-wrap">
                <?php if (empty($proveedores)): ?>
                <div class="empty-state"><p>No hay proveedores. Agregá uno a la derecha.</p></div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Cuenta contable</th>
                            <th>Teléfono</th>
                            <th>Contacto</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($proveedores as $p): ?>
                    <tr>
                        <td><strong><?= e($p['nombre']) ?></strong></td>
                        <td>
                            <?php if ($p['cuenta']): ?>
                                <span class="badge badge-bordo"><?= $cuentaLabel[$p['cuenta']] ?></span>
                            <?php else: ?>
                                <span class="text-muted" style="font-size:12px;">Sin asignar</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted"><?= e($p['telefono'] ?? '—') ?></td>
                        <td class="text-muted"><?= e($p['contacto'] ?? '—') ?></td>
                        <td class="text-right" style="white-space:nowrap;">
                            <a href="?editar=<?= $p['id'] ?>" class="btn btn-sm btn-outline">Editar</a>
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('¿Desactivar este proveedor?')">
                                <input type="hidden" name="accion" value="desactivar">
                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Quitar</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div style="flex:1; min-width:260px;">
        <div class="card">
            <div class="card-header">
                <span class="card-title"><?= $editProv ? 'Editar proveedor' : 'Nuevo proveedor' ?></span>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="accion" value="<?= $editProv ? 'editar' : 'crear' ?>">
                    <?php if ($editProv): ?>
                    <input type="hidden" name="id" value="<?= $editProv['id'] ?>">
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label">Nombre *</label>
                        <input type="text" name="nombre" class="form-control" required
                               value="<?= e($editProv['nombre'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Cuenta contable</label>
                        <select name="cuenta" class="form-control">
                            <option value="">— Sin asignar —</option>
                            <option value="area_520" <?= ($editProv['cuenta'] ?? '') === 'area_520' ? 'selected' : '' ?>>Area 520</option>
                            <option value="alfre"    <?= ($editProv['cuenta'] ?? '') === 'alfre'    ? 'selected' : '' ?>>Cuenta Alfre</option>
                        </select>
                        <div class="text-muted" style="font-size:11px; margin-top:4px;">
                            Al recibir un pedido se registra deuda automáticamente en esta cuenta.
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Teléfono</label>
                        <input type="text" name="telefono" class="form-control"
                               value="<?= e($editProv['telefono'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contacto</label>
                        <input type="text" name="contacto" class="form-control"
                               value="<?= e($editProv['contacto'] ?? '') ?>">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <?= $editProv ? 'Guardar cambios' : 'Agregar proveedor' ?>
                    </button>
                    <?php if ($editProv): ?>
                    <a href="/attos/pedidos_galpon/proveedores.php" class="btn btn-secondary w-100" style="margin-top:8px;">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

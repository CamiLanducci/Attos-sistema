<?php
require_once __DIR__ . '/../config/db.php';
$pageTitle = 'Clientes';
$topbarActions = '<a href="/attos/clientes/form.php" class="btn btn-primary">+ Nuevo cliente</a>';

$db = getDB();
$listas = $db->query("SELECT * FROM listas ORDER BY margen DESC")->fetchAll();

$search = trim($_GET['q'] ?? '');
$params = [];
$where  = 'WHERE c.activo = 1';
if ($search !== '') {
    $where   .= ' AND (c.nombre LIKE :q OR c.ciudad LIKE :q OR c.telefono LIKE :q)';
    $params[':q'] = "%$search%";
}

$clientes = $db->prepare("
    SELECT c.*, l.codigo AS lista_codigo, l.margen
    FROM clientes c
    LEFT JOIN listas l ON l.id = c.lista_id
    $where
    ORDER BY c.nombre ASC
");
$clientes->execute($params);
$clientes = $clientes->fetchAll();

$msg = $_GET['msg'] ?? '';
require_once __DIR__ . '/../config/layout.php';
?>

<?php if ($msg === 'created'): ?><div class="alert alert-success" data-autodismiss>Cliente creado correctamente.</div><?php endif; ?>
<?php if ($msg === 'updated'): ?><div class="alert alert-success" data-autodismiss>Cliente actualizado.</div><?php endif; ?>
<?php if ($msg === 'deleted'): ?><div class="alert alert-success" data-autodismiss>Cliente eliminado.</div><?php endif; ?>

<div class="card">
    <div class="filters">
        <input type="text" id="buscar" class="form-control" placeholder="Buscar por nombre, ciudad, teléfono..." value="<?= e($search) ?>">
        <span class="text-muted" style="font-size:12px;"><?= count($clientes) ?> resultado<?= count($clientes) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="table-wrap">
        <table id="tabla-clientes">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Teléfono</th>
                    <th>Ciudad</th>
                    <th>Lista asignada</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($clientes)): ?>
                <tr><td colspan="5"><div class="empty-state"><div class="empty-icon">👤</div><p>No hay clientes aún.</p></div></td></tr>
            <?php else: ?>
                <?php foreach ($clientes as $cl): ?>
                <tr>
                    <td><strong><?= e($cl['nombre']) ?></strong></td>
                    <td><?= e($cl['telefono'] ?? '—') ?></td>
                    <td><?= e($cl['ciudad'] ?? '—') ?></td>
                    <td>
                        <?php if ($cl['lista_codigo']): ?>
                            <span class="badge badge-bordo"><?= e($cl['lista_codigo']) ?> — <?= $cl['margen'] ?>%</span>
                        <?php else: ?>
                            <span class="text-muted">Sin asignar</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right">
                        <a href="/attos/clientes/form.php?id=<?= $cl['id'] ?>" class="btn btn-sm btn-secondary">Editar</a>
                        <a href="/attos/clientes/actions.php?action=delete&id=<?= $cl['id'] ?>"
                           class="btn btn-sm btn-danger"
                           data-confirm="¿Eliminar a <?= e($cl['nombre']) ?>?">Eliminar</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => filtrarTabla('buscar', 'tabla-clientes'));
</script>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

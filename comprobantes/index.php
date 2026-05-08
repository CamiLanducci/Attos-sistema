<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
$pageTitle     = 'Comprobantes';
$topbarActions = '<a href="/attos/comprobantes/crear.php" class="btn btn-primary">+ Nuevo comprobante</a>';

$db = getDB();

$search = trim($_GET['q'] ?? '');
$estado = $_GET['estado'] ?? '';
$params = [];
$where  = 'WHERE 1=1';

if ($search !== '') {
    $where .= ' AND (c.numero LIKE :q OR cl.nombre LIKE :q)';
    $params[':q'] = "%$search%";
}
if ($estado !== '') {
    $where .= ' AND c.estado = :estado';
    $params[':estado'] = $estado;
}

$comprobantes = $db->prepare("
    SELECT c.id, c.numero, c.fecha, c.total, c.estado,
           cl.nombre AS cliente,
           l.codigo AS lista, l.margen
    FROM comprobantes c
    JOIN clientes cl ON cl.id = c.cliente_id
    JOIN listas   l  ON l.id  = c.lista_id
    $where
    ORDER BY c.created_at DESC
");
$comprobantes->execute($params);
$comprobantes = $comprobantes->fetchAll();

$msg = $_GET['msg'] ?? '';
require_once __DIR__ . '/../config/layout.php';
?>

<?php if ($msg === 'created'): ?><div class="alert alert-success" data-autodismiss>Comprobante creado.</div><?php endif; ?>
<?php if ($msg === 'updated'): ?><div class="alert alert-success" data-autodismiss>Comprobante actualizado.</div><?php endif; ?>
<?php if ($msg === 'deleted'): ?><div class="alert alert-success" data-autodismiss>Comprobante eliminado.</div><?php endif; ?>

<div class="card">
    <div class="filters">
        <input type="text" id="buscar" class="form-control" placeholder="Buscar por número o cliente..." value="<?= e($search) ?>">
        <select id="filtro-estado" class="form-control" style="width:160px;">
            <option value="">Todos los estados</option>
            <option value="borrador"  <?= $estado === 'borrador'  ? 'selected' : '' ?>>Borrador</option>
            <option value="emitido"   <?= $estado === 'emitido'   ? 'selected' : '' ?>>Emitido</option>
            <option value="cobrado"   <?= $estado === 'cobrado'   ? 'selected' : '' ?>>Cobrado</option>
        </select>
        <span class="text-muted" style="font-size:12px;"><?= count($comprobantes) ?> resultado<?= count($comprobantes) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="table-wrap">
        <table id="tabla-comp">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Fecha</th>
                    <th>Cliente</th>
                    <th>Lista</th>
                    <th>Total</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($comprobantes)): ?>
                <tr><td colspan="7"><div class="empty-state"><div class="empty-icon">🧾</div><p>No hay comprobantes aún.</p></div></td></tr>
            <?php else: ?>
                <?php
                $badgeMap = ['emitido'=>'badge-bordo','cobrado'=>'badge-success','borrador'=>'badge-warning'];
                foreach ($comprobantes as $c):
                    $cls = $badgeMap[$c['estado']] ?? 'badge-gray';
                ?>
                <tr>
                    <td><strong>#<?= $c['numero'] ?></strong></td>
                    <td><?= date('d/m/Y', strtotime($c['fecha'])) ?></td>
                    <td><?= e($c['cliente']) ?></td>
                    <td><span class="badge badge-bordo"><?= e($c['lista']) ?></span></td>
                    <td class="fw-bold"><?= precio((float)$c['total']) ?></td>
                    <td><span class="badge <?= $cls ?>"><?= $c['estado'] ?></span></td>
                    <td class="text-right">
                        <a href="/attos/comprobantes/ver.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-secondary">Ver</a>
                        <?php if ($c['estado'] === 'borrador'): ?>
                        <a href="/attos/comprobantes/crear.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline">Editar</a>
                        <?php endif; ?>
                        <a href="/attos/comprobantes/imprimir.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline" target="_blank">Imprimir</a>
                        <a href="/attos/comprobantes/actions.php?action=delete&id=<?= $c['id'] ?>"
                           class="btn btn-sm btn-danger"
                           data-confirm="¿Eliminar comprobante #<?= $c['numero'] ?>?">Eliminar</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    filtrarTabla('buscar', 'tabla-comp');
    document.getElementById('filtro-estado').addEventListener('change', function() {
        const url = new URL(window.location);
        if (this.value) url.searchParams.set('estado', this.value);
        else url.searchParams.delete('estado');
        window.location = url;
    });
});
</script>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

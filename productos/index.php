<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
$pageTitle     = 'Productos';
$topbarActions = '<a href="' . BASE_PATH . '/productos/form.php" class="btn btn-secondary btn-sm">+ Agregar manual</a>';

$db     = getDB();
$listas = $db->query("SELECT * FROM listas ORDER BY margen DESC")->fetchAll();

$lista_id = isset($_GET['lista_id']) ? (int)$_GET['lista_id'] : ($listas[0]['id'] ?? 0);
$busqueda = trim($_GET['q'] ?? '');

$lista = null;
foreach ($listas as $l) {
    if ((int)$l['id'] === $lista_id) { $lista = $l; break; }
}
if (!$lista && !empty($listas)) { $lista = $listas[0]; $lista_id = (int)$lista['id']; }

$params = [$lista_id];
$whereExtra = '';
if ($busqueda !== '') {
    $whereExtra = " AND (p.nombre LIKE ? OR p.marca LIKE ? OR p.codigo LIKE ?)";
    $like = '%' . $busqueda . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$stmt = $db->prepare("
    SELECT p.id, p.codigo, p.marca, p.nombre, p.contenido, p.unidades_por_caja,
           p.precio_por_pack, COALESCE(p.categoria,'') AS categoria,
           lp.costo
    FROM productos p
    JOIN lista_precios lp ON lp.producto_id = p.id AND lp.lista_id = ?
    WHERE p.activo = 1 $whereExtra
    ORDER BY p.marca COLLATE utf8mb4_unicode_ci ASC, p.nombre COLLATE utf8mb4_unicode_ci ASC
");
$stmt->execute($params);
$productos = $stmt->fetchAll();

$stmtTotal = $db->prepare("SELECT COUNT(*) FROM productos p JOIN lista_precios lp ON lp.producto_id=p.id AND lp.lista_id=? WHERE p.activo=1");
$stmtTotal->execute([$lista_id]);
$totalCount = (int)$stmtTotal->fetchColumn();

$msg = $_GET['msg'] ?? '';
require_once __DIR__ . '/../config/layout.php';
?>

<?php if ($msg === 'created'): ?><div class="alert alert-success" data-autodismiss>Producto creado.</div><?php endif; ?>
<?php if ($msg === 'updated'): ?><div class="alert alert-success" data-autodismiss>Producto actualizado.</div><?php endif; ?>
<?php if ($msg === 'deleted'): ?><div class="alert alert-success" data-autodismiss>Producto desactivado.</div><?php endif; ?>

<div class="card">
    <div class="filters" style="flex-wrap:wrap; gap:8px;">
        <form method="GET" style="display:flex; gap:8px; flex:1; min-width:240px;">
            <input type="hidden" name="lista_id" value="<?= $lista_id ?>">
            <input type="text" name="q" class="form-control" placeholder="Buscar por nombre, marca o código…"
                   value="<?= e($busqueda) ?>" style="flex:1;">
            <button type="submit" class="btn btn-primary btn-sm">Buscar</button>
            <?php if ($busqueda): ?><a href="?lista_id=<?= $lista_id ?>" class="btn btn-secondary btn-sm">✕</a><?php endif; ?>
        </form>

        <div style="display:flex; gap:6px; align-items:center;">
            <?php foreach ($listas as $l): ?>
                <a href="?lista_id=<?= $l['id'] ?><?= $busqueda ? '&q='.urlencode($busqueda) : '' ?>"
                   class="btn btn-sm <?= (int)$l['id'] === $lista_id ? 'btn-primary' : 'btn-secondary' ?>">
                    <?= e($l['codigo']) ?> <span style="opacity:.7;"><?= $l['margen'] ?>%</span>
                </a>
            <?php endforeach; ?>
        </div>

        <span class="text-muted" style="font-size:12px; margin-left:auto; white-space:nowrap;">
            <?= count($productos) ?> de <?= $totalCount ?> productos
        </span>
    </div>

    <?php if (empty($productos)): ?>
    <div class="empty-state">
        <div class="empty-icon">📦</div>
        <p>
            <?= $busqueda ? 'Sin resultados para "' . e($busqueda) . '".' : 'No hay productos en esta lista todavía.' ?>
            <?php if (!$busqueda): ?>
                <br><a href="<?= BASE_PATH ?>/listas/" class="text-bordo">Importar desde URL de lista</a>
            <?php endif; ?>
        </p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:72px;">Código</th>
                    <th style="width:140px;">Marca</th>
                    <th>Producto</th>
                    <th style="text-align:center; width:52px;">UxC</th>
                    <th style="text-align:right; width:110px;">Costo unit.</th>
                    <th style="text-align:right; width:110px;">Precio (<?= $lista['margen'] ?>%)</th>
                    <th style="text-align:right; width:120px;">Precio caja</th>
                    <th style="width:110px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($productos as $p):
                $pr = calcularPreciosProducto(
                    (float)$p['costo'], (float)$lista['margen'],
                    (int)$p['unidades_por_caja'], (int)$p['precio_por_pack'],
                    $p['categoria'], $p['marca'] ?? ''
                );
                $precioUnit = $pr['precio_unit'];
                $precioCaja = $pr['precio_caja'];
                $costoUnit  = $pr['costo_unit'];
            ?>
            <tr>
                <td class="text-muted" style="font-family:monospace; font-size:11px;"><?= e($p['codigo'] ?: '—') ?></td>
                <td><?= e($p['marca'] ?: '—') ?></td>
                <td>
                    <strong><?= e($p['nombre']) ?></strong>
                    <?php if ($p['precio_por_pack']): ?>
                        <span style="font-size:10px; background:#f4ede3; color:#631636; border-radius:3px; padding:1px 5px; margin-left:4px; font-weight:600;">pack</span>
                    <?php endif; ?>
                    <?php if ($p['contenido']): ?>
                        <span class="text-muted" style="font-size:11px; display:block;"><?= e($p['contenido']) ?></span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;" class="text-muted"><?= (int)$p['unidades_por_caja'] ?></td>
                <td style="text-align:right;" class="text-muted"><?= precio($costoUnit) ?></td>
                <td style="text-align:right;" class="fw-bold"><?= precio($precioUnit) ?></td>
                <td style="text-align:right;" class="fw-bold text-bordo"><?= precio($precioCaja) ?></td>
                <td style="text-align:right;">
                    <div style="display:flex; gap:4px; justify-content:flex-end;">
                        <a href="<?= BASE_PATH ?>/productos/form.php?id=<?= $p['id'] ?>"
                           class="btn btn-sm btn-secondary" style="padding:2px 8px;">Editar</a>
                        <a href="<?= BASE_PATH ?>/productos/actions.php?action=delete&id=<?= $p['id'] ?>"
                           class="btn btn-sm btn-danger" style="padding:2px 8px;"
                           data-confirm="¿Eliminar «<?= e($p['nombre']) ?>»? Esta acción no se puede deshacer.">✕</a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

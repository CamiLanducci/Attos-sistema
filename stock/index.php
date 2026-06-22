<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

if (($_SESSION['rol'] ?? 'admin') !== 'admin') redirect(BASE_PATH . '/index.php');

$db = getDB();

$productos = $db->query("
    SELECT id, codigo, nombre, marca, unidades_por_caja, categoria,
           stock_cajas, stock_unidades, costo_compra
    FROM productos
    WHERE activo = 1
    ORDER BY marca ASC, nombre ASC
")->fetchAll();

// Totales
$totalValuacion = 0.0;
foreach ($productos as $p) {
    $uds = (int)$p['stock_cajas'] * max(1,(int)$p['unidades_por_caja']) + (int)$p['stock_unidades'];
    $totalValuacion += $uds * (float)($p['costo_compra'] ?? 0);
}

$msg       = $_GET['msg'] ?? '';
$pageTitle = 'Stock';
$topbarActions = '<a href="' . BASE_PATH . '/caja/" class="btn btn-secondary">← Caja</a>';
require_once __DIR__ . '/../config/layout.php';
?>

<?php if ($msg === 'ok'): ?><div class="alert alert-success" data-autodismiss>Stock actualizado.</div><?php endif; ?>

<div class="card" style="margin-bottom:16px; padding:12px 20px; display:flex; align-items:center; gap:24px;">
    <div>
        <div style="font-size:11px; text-transform:uppercase; letter-spacing:1px; color:var(--text-muted);">Valuación total del stock</div>
        <div style="font-size:28px; font-weight:900; color:var(--bordo);"><?= precio($totalValuacion) ?></div>
    </div>
    <div style="font-size:12px; color:var(--text-muted); max-width:380px;">
        Calculado como (cajas × uds/caja + unidades sueltas) × costo de compra. Los productos sin costo de compra no suman al total.
    </div>
</div>

<form method="POST" action="<?= BASE_PATH ?>/stock/actions.php">
<input type="hidden" name="action" value="update_stock">

<div class="card">
    <div class="card-header" style="display:flex; align-items:center; gap:12px;">
        <span class="card-title">Productos</span>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-left:auto;">Guardar stock</button>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Producto</th>
                    <th style="text-align:center; width:90px;">Ud/caja</th>
                    <th style="text-align:center; width:110px;">Cajas</th>
                    <th style="text-align:center; width:110px;">Unidades sueltas</th>
                    <th style="text-align:right; width:140px;">Costo compra</th>
                    <th style="text-align:right; width:130px;">Valor stock</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($productos as $p): ?>
            <?php
                $udc = max(1,(int)$p['unidades_por_caja']);
                $uds = (int)$p['stock_cajas'] * $udc + (int)$p['stock_unidades'];
                $val = $uds * (float)($p['costo_compra'] ?? 0);
                $tieneStock = (int)$p['stock_cajas'] > 0 || (int)$p['stock_unidades'] > 0;
            ?>
            <tr style="<?= $tieneStock ? 'background:#f9fff9;' : '' ?>">
                <td>
                    <input type="hidden" name="productos[<?= $p['id'] ?>][id]" value="<?= $p['id'] ?>">
                    <div style="font-weight:600; font-size:13px;"><?= e($p['nombre']) ?></div>
                    <?php if ($p['marca']): ?><div class="text-muted" style="font-size:11px;"><?= e($p['marca']) ?></div><?php endif; ?>
                    <?php if ($p['codigo']): ?><div class="text-muted" style="font-size:10px; font-family:monospace;"><?= e($p['codigo']) ?></div><?php endif; ?>
                </td>
                <td style="text-align:center; color:var(--text-muted); font-size:13px;"><?= $udc ?></td>
                <td style="text-align:center;">
                    <input type="number" name="productos[<?= $p['id'] ?>][stock_cajas]"
                           class="form-control" style="width:80px; margin:0 auto; text-align:center; padding:4px 6px;"
                           value="<?= (int)$p['stock_cajas'] ?>" min="0">
                </td>
                <td style="text-align:center;">
                    <input type="number" name="productos[<?= $p['id'] ?>][stock_unidades]"
                           class="form-control" style="width:80px; margin:0 auto; text-align:center; padding:4px 6px;"
                           value="<?= (int)$p['stock_unidades'] ?>" min="0">
                </td>
                <td style="text-align:right;">
                    <input type="text" name="productos[<?= $p['id'] ?>][costo_compra]"
                           class="form-control" style="width:110px; margin-left:auto; text-align:right; padding:4px 8px;"
                           value="<?= $p['costo_compra'] !== null ? number_format((float)$p['costo_compra'], 2, ',', '.') : '' ?>"
                           placeholder="—">
                </td>
                <td style="text-align:right; font-weight:700; color:<?= $val > 0 ? '#27ae60' : 'var(--text-muted)' ?>; font-size:13px;">
                    <?= $val > 0 ? precio($val) : '—' ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div style="margin-top:12px; text-align:right;">
    <button type="submit" class="btn btn-primary">Guardar stock</button>
</div>
</form>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

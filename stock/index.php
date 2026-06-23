<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

if (($_SESSION['rol'] ?? 'admin') !== 'admin') redirect(BASE_PATH . '/index.php');

$db = getDB();

$productos = $db->query("
    SELECT p.id, p.codigo, p.nombre, p.marca, p.unidades_por_caja, p.categoria,
           p.stock_cajas, p.stock_unidades, p.costo_compra,
           ROUND(
               (SELECT lp.costo / (1 + l.margen / 100)
                FROM lista_precios lp
                JOIN listas l ON l.id = lp.lista_id
                WHERE lp.producto_id = p.id
                ORDER BY l.margen ASC
                LIMIT 1),
           2) AS costo_calc
    FROM productos p
    WHERE p.activo = 1
    ORDER BY p.marca ASC, p.nombre ASC
")->fetchAll();

// Totales — usa costo_compra guardado; si es NULL, usa el calculado desde lista_precios
$totalValuacion = 0.0;
foreach ($productos as $p) {
    $costo = $p['costo_compra'] ?? $p['costo_calc'];
    $uds   = (int)$p['stock_cajas'] * max(1,(int)$p['unidades_por_caja']) + (int)$p['stock_unidades'];
    $totalValuacion += $uds * (float)($costo ?? 0);
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
        (cajas × uds/caja + uds sueltas) × costo de compra. Los costos en <em>itálica</em> son calculados desde la lista; guardá para fijarlos.
    </div>
</div>

<form method="POST" action="<?= BASE_PATH ?>/stock/actions.php">
<input type="hidden" name="action" value="update_stock">

<div class="card">
    <div class="card-header" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
        <span class="card-title">Productos</span>
        <input type="text" id="stock-search" placeholder="Buscar por nombre…"
               class="form-control" style="max-width:260px; margin-left:0;"
               oninput="filtrarStock(this.value)">
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
            <tbody id="stock-tbody">
            <?php foreach ($productos as $p): ?>
            <?php
                $udc        = max(1,(int)$p['unidades_por_caja']);
                $uds        = (int)$p['stock_cajas'] * $udc + (int)$p['stock_unidades'];
                $esGuardado = $p['costo_compra'] !== null;
                $costo      = $esGuardado ? (float)$p['costo_compra'] : (float)($p['costo_calc'] ?? 0);
                $val        = $uds * $costo;
                $tieneStock = (int)$p['stock_cajas'] > 0 || (int)$p['stock_unidades'] > 0;
            ?>
            <tr style="<?= $tieneStock ? 'background:#f9fff9;' : '' ?>" data-nombre="<?= e($p['nombre'] . ' ' . $p['marca']) ?>">
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
                           class="form-control" style="width:110px; margin-left:auto; text-align:right; padding:4px 8px;
                                  <?= !$esGuardado && $costo > 0 ? 'color:#888; font-style:italic;' : '' ?>"
                           value="<?= $costo > 0 ? number_format($costo, 2, ',', '.') : '' ?>"
                           placeholder="—">
                    <?php if (!$esGuardado && $costo > 0): ?>
                    <div style="font-size:10px; color:#aaa; text-align:right; margin-top:2px;">calculado</div>
                    <?php endif; ?>
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

<script>
function filtrarStock(q) {
    q = q.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    document.querySelectorAll('#stock-tbody tr').forEach(function(tr) {
        var texto = (tr.dataset.nombre || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        tr.style.display = texto.includes(q) ? '' : 'none';
    });
}
</script>
<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

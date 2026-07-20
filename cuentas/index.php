<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

if (($_SESSION['rol'] ?? 'admin') !== 'admin') redirect(BASE_PATH . '/index.php');

$pageTitle     = 'Cuentas';
$topbarActions = '
    <a href="' . BASE_PATH . '/cuentas/pago.php"            class="btn btn-primary">+ Registrar pago</a>
    <a href="' . BASE_PATH . '/cuentas/crear_movimiento.php" class="btn btn-secondary">+ Movimiento manual</a>
';

$db = getDB();

// ── Saldos de movimientos_cuenta ──────────────────────────────────────────────
$saldos = ['area_520' => 0.0, 'alfre' => 0.0, 'patrimonio' => 0.0];
foreach ($db->query("SELECT cuenta, tipo, SUM(monto) AS total FROM movimientos_cuenta GROUP BY cuenta, tipo")->fetchAll() as $r) {
    if ($r['tipo'] === 'cargo') $saldos[$r['cuenta']] = ($saldos[$r['cuenta']] ?? 0) + (float)$r['total'];
    else                        $saldos[$r['cuenta']] = ($saldos[$r['cuenta']] ?? 0) - (float)$r['total'];
}

// ── Por cobrar (comprobantes emitidos) ────────────────────────────────────────
$porCobrar = $db->query("
    SELECT cl.nombre, SUM(c.total) AS total
    FROM comprobantes c
    JOIN clientes cl ON cl.id = c.cliente_id
    WHERE c.estado = 'emitido'
    GROUP BY c.cliente_id, cl.nombre
    ORDER BY total DESC
")->fetchAll();
$porCobrarTotal = array_sum(array_column($porCobrar, 'total'));

// ── Totales Activos / Pasivos ─────────────────────────────────────────────────
$totalActivos = max(0, $saldos['patrimonio']) + $porCobrarTotal;
$totalPasivos = max(0, $saldos['area_520'])   + max(0, $saldos['alfre']);
$patrimonioNeto = $totalActivos - $totalPasivos;

// ── Filtros movimientos ───────────────────────────────────────────────────────
$filtroCuenta = $_GET['cuenta'] ?? '';
$filtroTipo   = $_GET['tipo']   ?? '';
$filtroDesde  = $_GET['desde']  ?? '';
$filtroHasta  = $_GET['hasta']  ?? '';

$where  = [];
$params = [];
if ($filtroCuenta && in_array($filtroCuenta, ['area_520','alfre','patrimonio'])) {
    $where[] = 'm.cuenta = ?'; $params[] = $filtroCuenta;
}
if ($filtroTipo && in_array($filtroTipo, ['cargo','pago'])) {
    $where[] = 'm.tipo = ?'; $params[] = $filtroTipo;
}
if ($filtroDesde) { $where[] = 'm.fecha >= ?'; $params[] = $filtroDesde; }
if ($filtroHasta) { $where[] = 'm.fecha <= ?'; $params[] = $filtroHasta; }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$movimientos = $db->prepare("
    SELECT m.*,
           c.numero  AS comp_numero,
           pg.id     AS pedido_id
    FROM movimientos_cuenta m
    LEFT JOIN comprobantes   c  ON c.id  = m.comprobante_id
    LEFT JOIN pedidos_galpon pg ON pg.id = m.pedido_galpon_id
    $whereSQL
    ORDER BY m.fecha DESC, m.id DESC
    LIMIT 200
");
$movimientos->execute($params);
$movimientos = $movimientos->fetchAll();

$msg = $_GET['msg'] ?? '';
require_once __DIR__ . '/../config/layout.php';

$cuentaLabel = ['area_520' => 'Area 520', 'alfre' => 'Cuenta Alfre', 'patrimonio' => 'Patrimonio'];
$cuentaBadge = ['area_520' => 'badge-warning', 'alfre' => 'badge-warning', 'patrimonio' => 'badge-bordo'];
$tipoBadge   = ['cargo' => 'badge-bordo', 'pago' => 'badge-success'];
?>

<?php if ($msg === 'pago_ok'):         ?><div class="alert alert-success" data-autodismiss>Pago registrado. Dos movimientos creados.</div><?php endif; ?>
<?php if ($msg === 'creado'):          ?><div class="alert alert-success" data-autodismiss>Movimiento registrado.</div><?php endif; ?>
<?php if ($msg === 'deleted'):         ?><div class="alert alert-success" data-autodismiss>Movimiento eliminado.</div><?php endif; ?>
<?php if ($msg === 'no_delete_comp'):  ?><div class="alert alert-warning" data-autodismiss>No se puede eliminar: está vinculado a un comprobante.</div><?php endif; ?>
<?php if ($msg === 'no_delete_ped'):   ?><div class="alert alert-warning" data-autodismiss>No se puede eliminar: el pedido ya está facturado.</div><?php endif; ?>
<?php if ($msg === 'error'):           ?><div class="alert alert-warning" data-autodismiss>Error al procesar la operación.</div><?php endif; ?>

<!-- ═══ BALANCE ACTIVOS / PASIVOS ══════════════════════════════════════════ -->
<div class="d-flex gap-2" style="align-items:flex-start; margin-bottom:16px; flex-wrap:wrap;">

    <!-- ACTIVOS -->
    <div class="card" style="flex:1; min-width:280px; border-top:3px solid #27ae60;">
        <div class="card-header" style="background:#f0faf4;">
            <span class="card-title" style="color:#27ae60;">ACTIVOS</span>
        </div>
        <div class="card-body" style="padding:0;">

            <!-- Dinero disponible -->
            <div style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-bottom:1px solid var(--border);">
                <div>
                    <div style="font-size:12px; color:#888; margin-bottom:2px;">💰 Dinero disponible</div>
                    <div style="font-size:11px; color:#aaa;">Patrimonio en caja/banco</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:20px; font-weight:700; color:<?= $saldos['patrimonio'] >= 0 ? '#27ae60' : '#c0392b' ?>;">
                        <?= precio(abs($saldos['patrimonio'])) ?>
                    </div>
                    <a href="?cuenta=patrimonio" style="font-size:11px; color:#888;">Ver movimientos</a>
                </div>
            </div>

            <!-- Por cobrar -->
            <div style="padding:14px 20px; border-bottom:1px solid var(--border);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:<?= $porCobrar ? '10px' : '0' ?>;">
                    <div>
                        <div style="font-size:12px; color:#888; margin-bottom:2px;">📋 Por cobrar</div>
                        <div style="font-size:11px; color:#aaa;">Comprobantes emitidos sin cobrar</div>
                    </div>
                    <div style="font-size:20px; font-weight:700; color:<?= $porCobrarTotal > 0 ? '#27ae60' : '#888' ?>;">
                        <?= precio($porCobrarTotal) ?>
                    </div>
                </div>
                <?php if ($porCobrar): ?>
                <div style="padding-left:8px; border-left:2px solid #e8f5e9;">
                    <?php foreach ($porCobrar as $pc): ?>
                    <div style="display:flex; justify-content:space-between; font-size:12px; padding:3px 0; color:#555;">
                        <span><?= e($pc['nombre']) ?></span>
                        <span style="font-weight:600;"><?= precio((float)$pc['total']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Total Activos -->
            <div style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; background:#f0faf4;">
                <span style="font-weight:700; font-size:13px; color:#27ae60;">TOTAL ACTIVOS</span>
                <span style="font-size:22px; font-weight:800; color:#27ae60;"><?= precio($totalActivos) ?></span>
            </div>
        </div>
    </div>

    <!-- PASIVOS -->
    <div class="card" style="flex:1; min-width:280px; border-top:3px solid #631636;">
        <div class="card-header" style="background:#fdf0f4;">
            <span class="card-title" style="color:#631636;">PASIVOS</span>
        </div>
        <div class="card-body" style="padding:0;">

            <!-- Area 520 -->
            <div style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-bottom:1px solid var(--border);">
                <div>
                    <div style="font-size:12px; color:#888; margin-bottom:2px;">🏭 Area 520</div>
                    <div style="display:flex; gap:8px; margin-top:4px;">
                        <a href="?cuenta=area_520" class="btn btn-sm btn-secondary" style="font-size:11px; padding:2px 8px;">Ver movimientos</a>
                        <a href="<?= BASE_PATH ?>/cuentas/pago.php?cuenta=area_520" class="btn btn-sm btn-outline" style="font-size:11px; padding:2px 8px;">Registrar pago</a>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:20px; font-weight:700; color:<?= $saldos['area_520'] > 0 ? '#c0392b' : '#27ae60' ?>;">
                        <?= precio(abs($saldos['area_520'])) ?>
                    </div>
                    <div style="font-size:11px; color:<?= $saldos['area_520'] > 0 ? '#c0392b' : '#27ae60' ?>;">
                        <?= $saldos['area_520'] > 0 ? 'Deuda' : ($saldos['area_520'] < 0 ? 'A favor' : 'Sin deuda') ?>
                    </div>
                </div>
            </div>

            <!-- Cuenta Alfre -->
            <div style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-bottom:1px solid var(--border);">
                <div>
                    <div style="font-size:12px; color:#888; margin-bottom:2px;">👤 Cuenta Alfre</div>
                    <div style="display:flex; gap:8px; margin-top:4px;">
                        <a href="?cuenta=alfre" class="btn btn-sm btn-secondary" style="font-size:11px; padding:2px 8px;">Ver movimientos</a>
                        <a href="<?= BASE_PATH ?>/cuentas/pago.php?cuenta=alfre" class="btn btn-sm btn-outline" style="font-size:11px; padding:2px 8px;">Registrar pago</a>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:20px; font-weight:700; color:<?= $saldos['alfre'] > 0 ? '#c0392b' : '#27ae60' ?>;">
                        <?= precio(abs($saldos['alfre'])) ?>
                    </div>
                    <div style="font-size:11px; color:<?= $saldos['alfre'] > 0 ? '#c0392b' : '#27ae60' ?>;">
                        <?= $saldos['alfre'] > 0 ? 'Deuda' : ($saldos['alfre'] < 0 ? 'A favor' : 'Sin deuda') ?>
                    </div>
                </div>
            </div>

            <!-- Total Pasivos -->
            <div style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; background:#fdf0f4;">
                <span style="font-weight:700; font-size:13px; color:#631636;">TOTAL PASIVOS</span>
                <span style="font-size:22px; font-weight:800; color:#631636;"><?= precio($totalPasivos) ?></span>
            </div>
        </div>
    </div>
</div>

<!-- PATRIMONIO NETO -->
<div class="card" style="margin-bottom:20px; border-top:3px solid <?= $patrimonioNeto >= 0 ? '#27ae60' : '#c0392b' ?>;">
    <div class="card-body" style="display:flex; justify-content:space-between; align-items:center; padding:16px 24px;">
        <div>
            <div style="font-weight:700; font-size:14px;">PATRIMONIO NETO</div>
            <div style="font-size:12px; color:#888; margin-top:2px;">Activos − Pasivos</div>
        </div>
        <div style="font-size:28px; font-weight:800; color:<?= $patrimonioNeto >= 0 ? '#27ae60' : '#c0392b' ?>;">
            <?= $patrimonioNeto >= 0 ? '' : '−' ?><?= precio(abs($patrimonioNeto)) ?>
        </div>
    </div>
</div>

<!-- ═══ MOVIMIENTOS ════════════════════════════════════════════════════════ -->
<div class="card">
    <div class="card-header">
        <span class="card-title">Movimientos</span>
    </div>
    <div class="filters" style="flex-wrap:wrap; gap:8px;">
        <form method="GET" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <select name="cuenta" class="form-control" style="width:150px;" onchange="this.form.submit()">
                <option value="">Todas las cuentas</option>
                <?php foreach ($cuentaLabel as $k => $v): ?>
                <option value="<?= $k ?>" <?= $filtroCuenta===$k ? 'selected':'' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
            <select name="tipo" class="form-control" style="width:120px;" onchange="this.form.submit()">
                <option value="">Cargo y pago</option>
                <option value="cargo" <?= $filtroTipo==='cargo' ? 'selected':'' ?>>Cargo</option>
                <option value="pago"  <?= $filtroTipo==='pago'  ? 'selected':'' ?>>Pago</option>
            </select>
            <input type="date" name="desde" class="form-control" style="width:140px;"
                   value="<?= e($filtroDesde) ?>" onchange="this.form.submit()">
            <input type="date" name="hasta" class="form-control" style="width:140px;"
                   value="<?= e($filtroHasta) ?>" onchange="this.form.submit()">
            <?php if ($filtroCuenta || $filtroTipo || $filtroDesde || $filtroHasta): ?>
                <a href="<?= BASE_PATH ?>/cuentas/" class="btn btn-secondary btn-sm">✕ Limpiar</a>
            <?php endif; ?>
        </form>
        <span class="text-muted" style="font-size:12px; margin-left:auto;"><?= count($movimientos) ?> movimiento<?= count($movimientos)!==1?'s':'' ?></span>
    </div>

    <?php if (empty($movimientos)): ?>
    <div class="empty-state">
        <div class="empty-icon">💰</div>
        <p>No hay movimientos<?= ($filtroCuenta||$filtroTipo||$filtroDesde||$filtroHasta) ? ' con esos filtros' : ' todavía' ?>.</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Cuenta</th>
                    <th>Tipo</th>
                    <th style="text-align:right;">Monto</th>
                    <th>Descripción</th>
                    <th>Vínculo</th>
                    <th style="width:50px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($movimientos as $m): ?>
            <tr>
                <td class="text-muted"><?= date('d/m/Y', strtotime($m['fecha'])) ?></td>
                <td><span class="badge <?= $cuentaBadge[$m['cuenta']] ?? 'badge-gray' ?>"><?= e($cuentaLabel[$m['cuenta']] ?? $m['cuenta']) ?></span></td>
                <td><span class="badge <?= $tipoBadge[$m['tipo']] ?? 'badge-gray' ?>"><?= $m['tipo'] ?></span></td>
                <td style="text-align:right;" class="fw-bold"><?= precio((float)$m['monto']) ?></td>
                <td class="text-muted" style="font-size:13px; max-width:240px;"><?= e($m['descripcion'] ?? '—') ?></td>
                <td style="font-size:12px;">
                    <?php if ($m['comp_numero']): ?>
                        <a href="<?= BASE_PATH ?>/comprobantes/ver.php?id=<?= $m['comprobante_id'] ?>" class="text-bordo">Comp. #<?= $m['comp_numero'] ?></a>
                    <?php elseif ($m['pedido_id']): ?>
                        <a href="<?= BASE_PATH ?>/pedidos_galpon/ver.php?id=<?= $m['pedido_id'] ?>" class="text-bordo">Pedido #<?= $m['pedido_id'] ?></a>
                    <?php elseif ($m['movimiento_par_id']): ?>
                        <span class="text-muted">Par #<?= $m['movimiento_par_id'] ?></span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td class="text-right">
                    <a href="<?= BASE_PATH ?>/cuentas/actions.php?action=delete_movimiento&id=<?= $m['id'] ?>"
                       class="btn btn-sm btn-danger" style="padding:2px 8px;"
                       data-confirm="¿Eliminar este movimiento?<?= $m['movimiento_par_id'] ? ' También se eliminará el movimiento par.' : '' ?>">×</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

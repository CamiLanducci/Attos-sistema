<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

if (($_SESSION['rol'] ?? 'admin') !== 'admin') redirect(BASE_PATH . '/index.php');

$db = getDB();

// ── Saldo inicial ──────────────────────────────────────────────
$si = ['efectivo' => 0, 'transferencia' => 0, 'dolares' => 0, 'dolares_precio' => 1000];
try {
    $row = $db->query("SELECT * FROM caja_saldo_inicial WHERE id=1")->fetch();
    if ($row) $si = $row;
} catch (Exception $e) {}

// ── Movimientos de caja ────────────────────────────────────────
$bal = ['efectivo' => 0, 'transferencia' => 0, 'dolares' => 0];
try {
    $row = $db->query("
        SELECT
            COALESCE(SUM(CASE WHEN medio_pago='efectivo'     AND tipo='ingreso' THEN monto
                              WHEN medio_pago='efectivo'     AND tipo='egreso'  THEN -monto ELSE 0 END), 0) AS efectivo,
            COALESCE(SUM(CASE WHEN medio_pago='transferencia' AND tipo='ingreso' THEN monto
                              WHEN medio_pago='transferencia' AND tipo='egreso'  THEN -monto ELSE 0 END), 0) AS transferencia,
            COALESCE(SUM(CASE WHEN concepto='compra_dolares' THEN monto_dolares ELSE 0 END), 0) AS dolares
        FROM caja_movimientos
    ")->fetch();
    if ($row) $bal = $row;
} catch (Exception $e) {}

$totalEfectivo      = (float)$si['efectivo']     + (float)$bal['efectivo'];
$totalTransferencia = (float)$si['transferencia'] + (float)$bal['transferencia'];
$totalDolares       = (float)$si['dolares']       + (float)$bal['dolares'];
$precioUsd          = (float)$si['dolares_precio'];
$valorUsd           = $totalDolares * $precioUsd;

// ── Caja por usuario ────────────────────────────────────────────
// Cada usuario tiene su propio saldo inicial (efectivo/transferencia, sin
// dólares — esos siguen siendo sólo de la caja general) y sus movimientos se
// agrupan por usuario_id. Los movimientos de antes de que existiera este
// sistema (usuario_id NULL) quedan en un balde "histórico", junto con el
// saldo inicial global de siempre — no se migran retroactivamente.
$miId       = (int)($_SESSION['usuario_id'] ?? 0);
$usuarios   = [];
$saldoIniUsuarios = []; // usuario_id => ['efectivo'=>, 'transferencia'=>]
$movXUsuario      = []; // usuario_id|null => ['efectivo'=>, 'transferencia'=>]
try {
    $usuarios = $db->query("SELECT id, nombre_real FROM usuarios WHERE activo = 1 ORDER BY nombre_real")->fetchAll();
} catch (Exception $e) {}
try {
    foreach ($db->query("SELECT * FROM caja_saldo_inicial_usuario")->fetchAll() as $r) {
        $saldoIniUsuarios[(int)$r['usuario_id']] = $r;
    }
} catch (Exception $e) {}
try {
    $rows = $db->query("
        SELECT usuario_id,
            COALESCE(SUM(CASE WHEN medio_pago='efectivo'      AND tipo='ingreso' THEN monto
                              WHEN medio_pago='efectivo'      AND tipo='egreso'  THEN -monto ELSE 0 END), 0) AS efectivo,
            COALESCE(SUM(CASE WHEN medio_pago='transferencia' AND tipo='ingreso' THEN monto
                              WHEN medio_pago='transferencia' AND tipo='egreso'  THEN -monto ELSE 0 END), 0) AS transferencia
        FROM caja_movimientos
        GROUP BY usuario_id
    ")->fetchAll();
    foreach ($rows as $r) {
        $key = $r['usuario_id'] !== null ? (int)$r['usuario_id'] : 'historico';
        $movXUsuario[$key] = $r;
    }
} catch (Exception $e) {}

// Desglose completo (para la pestaña "Caja general"): un renglón por usuario
// activo + el balde histórico, aunque no tengan movimientos.
$desgloseUsuarios = [];
foreach ($usuarios as $u) {
    $uid = (int)$u['id'];
    $ini = $saldoIniUsuarios[$uid] ?? ['efectivo' => 0, 'transferencia' => 0];
    $mov = $movXUsuario[$uid]      ?? ['efectivo' => 0, 'transferencia' => 0];
    $desgloseUsuarios[] = [
        'nombre'        => $u['nombre_real'],
        'efectivo'      => (float)$ini['efectivo']      + (float)$mov['efectivo'],
        'transferencia' => (float)$ini['transferencia'] + (float)$mov['transferencia'],
    ];
}
$movHistorico = $movXUsuario['historico'] ?? ['efectivo' => 0, 'transferencia' => 0];
$desgloseUsuarios[] = [
    'nombre'        => 'Histórico / sin asignar',
    'efectivo'      => (float)$si['efectivo']      + (float)$movHistorico['efectivo'],
    'transferencia' => (float)$si['transferencia'] + (float)$movHistorico['transferencia'],
];

// "Mi caja": saldo del usuario logueado.
$miIni          = $saldoIniUsuarios[$miId] ?? ['efectivo' => 0, 'transferencia' => 0];
$miMov          = $movXUsuario[$miId]      ?? ['efectivo' => 0, 'transferencia' => 0];
$miEfectivo      = (float)$miIni['efectivo']      + (float)$miMov['efectivo'];
$miTransferencia = (float)$miIni['transferencia'] + (float)$miMov['transferencia'];

// Últimos arqueos del usuario logueado.
$misArqueos = [];
try {
    $stmt = $db->prepare("SELECT * FROM caja_arqueos WHERE usuario_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$miId]);
    $misArqueos = $stmt->fetchAll();
} catch (Exception $e) {}

// Último precio de compra/venta de dólares (referencia)
$ultimoDolar = null;
try {
    $ultimoDolar = $db->query("
        SELECT precio_dolar_compra, precio_dolar_venta
        FROM caja_movimientos WHERE concepto='compra_dolares'
        ORDER BY created_at DESC LIMIT 1
    ")->fetch();
} catch (Exception $e) {}

// ── Valuación de stock ─────────────────────────────────────────
$stockValuacion = 0.0;
$stockItems     = 0;
try {
    $sv = $db->query("
        SELECT
            COUNT(*) AS items,
            SUM((stock_cajas * unidades_por_caja + stock_unidades) * COALESCE(costo_compra, 0)) AS valor
        FROM productos
        WHERE activo = 1 AND (stock_cajas > 0 OR stock_unidades > 0)
    ")->fetch();
    if ($sv) {
        $stockValuacion = (float)$sv['valor'];
        $stockItems     = (int)$sv['items'];
    }
} catch (Exception $e) {}

// ── Total patrimonio ───────────────────────────────────────────
$grandTotal = $totalEfectivo + $totalTransferencia + $valorUsd + $stockValuacion;

// ── Deuda a proveedores ────────────────────────────────────────
$deudas = [];
$totalDeudaProveedores = 0.0;
try {
    $deudas = $db->query("
        SELECT cuenta,
               SUM(CASE WHEN tipo='cargo' THEN monto ELSE -monto END) AS saldo
        FROM movimientos_cuenta
        WHERE cuenta IN ('area_520','alfre')
        GROUP BY cuenta
        HAVING saldo > 0
    ")->fetchAll();
    $totalDeudaProveedores = array_sum(array_column($deudas, 'saldo'));
} catch (Exception $e) {}

// ── Pendientes de cobro ────────────────────────────────────────
$emitidos = $db->query("
    SELECT c.id, c.numero, c.fecha, c.total, cl.nombre AS cliente_nombre
    FROM comprobantes c
    JOIN clientes cl ON cl.id = c.cliente_id
    WHERE c.estado = 'emitido'
    ORDER BY c.fecha ASC, c.id ASC
")->fetchAll();
$totalPendiente = array_sum(array_column($emitidos, 'total'));

// ── Últimos movimientos (caja general: todos los usuarios) ─────
$movimientos = [];
try {
    $movimientos = $db->query("
        SELECT cm.*, u.nombre_real AS usuario_nombre, c.numero AS comp_numero
        FROM caja_movimientos cm
        LEFT JOIN usuarios     u ON u.id  = cm.usuario_id
        LEFT JOIN comprobantes c ON c.id  = cm.comprobante_id
        ORDER BY cm.created_at DESC
        LIMIT 40
    ")->fetchAll();
} catch (Exception $e) {}

// ── Mis movimientos (mi caja: sólo los que cargué yo) ───────────
$misMovimientos = [];
try {
    $stmtMM = $db->prepare("
        SELECT cm.*, c.numero AS comp_numero
        FROM caja_movimientos cm
        LEFT JOIN comprobantes c ON c.id = cm.comprobante_id
        WHERE cm.usuario_id = ?
        ORDER BY cm.created_at DESC
        LIMIT 40
    ");
    $stmtMM->execute([$miId]);
    $misMovimientos = $stmtMM->fetchAll();
} catch (Exception $e) {}

// Caja general separada en ingresos / egresos (mismo set de $movimientos).
$movimientosIngresos = array_values(array_filter($movimientos, fn($m) => $m['tipo'] === 'ingreso'));
$movimientosEgresos  = array_values(array_filter($movimientos, fn($m) => $m['tipo'] === 'egreso'));

$msg       = $_GET['msg'] ?? '';
$pageTitle = 'Caja';
require_once __DIR__ . '/../config/layout.php';

$conceptoLabel = [
    'venta'          => '🛒 Venta',
    'pago_proveedor' => '🏭 Proveedor',
    'compra_dolares' => '💲 Dólares',
    'sueldo'         => '👤 Sueldo',
    'gasto'          => '📝 Gasto',
    'ajuste'         => '⚖️ Ajuste',
    'otro'           => '• Otro',
];
$cuentaLabel = ['area_520' => 'Area 520', 'alfre' => 'Cuenta Alfre'];
?>

<?php if ($msg === 'ok'): ?><div class="alert alert-success" data-autodismiss>Movimiento registrado.</div><?php endif; ?>
<?php if ($msg === 'deleted'): ?><div class="alert alert-success" data-autodismiss>Movimiento eliminado.</div><?php endif; ?>
<?php if ($msg === 'saldo_ok'): ?><div class="alert alert-success" data-autodismiss>Saldo inicial actualizado.</div><?php endif; ?>
<?php if ($msg === 'mi_saldo_ok'): ?><div class="alert alert-success" data-autodismiss>Tu saldo inicial fue actualizado.</div><?php endif; ?>
<?php if ($msg === 'arqueo_ok'): ?><div class="alert alert-success" data-autodismiss>Arqueo registrado.</div><?php endif; ?>
<?php if ($msg === 'ajuste_ok'): ?><div class="alert alert-success" data-autodismiss>Ajuste aplicado — el saldo calculado ya incluye la diferencia.</div><?php endif; ?>

<!-- ── Pestañas: mi caja / caja general ──────────────────────────── -->
<div style="display:flex; gap:8px; margin-bottom:16px;">
    <button onclick="showCajaTab('mi-caja')" id="btn-tab-mi-caja" class="btn btn-primary btn-sm">👤 Mi caja</button>
    <button onclick="showCajaTab('general')" id="btn-tab-general" class="btn btn-outline btn-sm">🏢 Caja general</button>
</div>

<!-- ── Mi caja ──────────────────────────────────────────────────── -->
<div id="caja-tab-mi-caja">

    <div style="display:grid; grid-template-columns:repeat(2,1fr); gap:12px; margin-bottom:16px; max-width:560px;">
        <div class="card" style="text-align:center; padding:18px 10px;">
            <div style="font-size:10px; text-transform:uppercase; letter-spacing:1px; color:var(--text-muted); margin-bottom:6px;">💵 Mi efectivo</div>
            <div style="font-size:24px; font-weight:800; color:<?= $miEfectivo >= 0 ? '#27ae60' : '#c0392b' ?>;"><?= precio($miEfectivo) ?></div>
            <div style="font-size:10px; color:var(--text-muted); margin-top:4px;">
                Inicial: <?= precio((float)$miIni['efectivo']) ?> + movimientos: <?= precio((float)$miMov['efectivo']) ?>
            </div>
        </div>
        <div class="card" style="text-align:center; padding:18px 10px;">
            <div style="font-size:10px; text-transform:uppercase; letter-spacing:1px; color:var(--text-muted); margin-bottom:6px;">💳 Mi transferencia</div>
            <div style="font-size:24px; font-weight:800; color:<?= $miTransferencia >= 0 ? '#2980b9' : '#c0392b' ?>;"><?= precio($miTransferencia) ?></div>
            <div style="font-size:10px; color:var(--text-muted); margin-top:4px;">
                Inicial: <?= precio((float)$miIni['transferencia']) ?> + movimientos: <?= precio((float)$miMov['transferencia']) ?>
            </div>
        </div>
    </div>

    <!-- ── Registrar movimiento + mis movimientos ──────────────────── -->
    <div style="display:grid; grid-template-columns:340px 1fr; gap:16px; align-items:flex-start; margin-bottom:16px;">

        <div class="card">
            <div class="card-header"><span class="card-title">Registrar movimiento</span></div>
            <div class="card-body">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:16px;">
                    <button onclick="showTab('proveedor')" class="btn btn-outline btn-sm">🏭 Pago proveedor</button>
                    <button onclick="showTab('dolares')"   class="btn btn-outline btn-sm">💲 Comprar dólares</button>
                    <button onclick="showTab('sueldo')"    class="btn btn-outline btn-sm">👤 Pagar sueldo</button>
                    <button onclick="showTab('gasto')"     class="btn btn-outline btn-sm">📝 Registrar gasto</button>
                </div>

                <div id="tab-proveedor" class="caja-tab" style="display:none;">
                    <form method="POST" action="<?= BASE_PATH ?>/caja/actions.php">
                        <input type="hidden" name="action" value="pago_proveedor">
                        <div class="form-group">
                            <label class="form-label">Proveedor / descripción</label>
                            <input type="text" name="descripcion" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Monto ($)</label>
                            <input type="number" name="monto" class="form-control" min="0.01" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Medio de pago</label>
                            <select name="medio_pago" class="form-control">
                                <option value="efectivo">💵 Efectivo</option>
                                <option value="transferencia">💳 Transferencia</option>
                            </select>
                        </div>
                        <button class="btn btn-primary w-100">Registrar egreso</button>
                    </form>
                </div>

                <div id="tab-dolares" class="caja-tab" style="display:none;">
                    <form method="POST" action="<?= BASE_PATH ?>/caja/actions.php">
                        <input type="hidden" name="action" value="compra_dolares">
                        <div class="form-group">
                            <label class="form-label">Cantidad de dólares (USD)</label>
                            <input type="number" name="monto_dolares" id="usd-cant" class="form-control" min="0.01" step="0.01" oninput="calcPesos()" required>
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                            <div class="form-group">
                                <label class="form-label">Precio compra ($)</label>
                                <input type="number" name="precio_compra" id="usd-compra" class="form-control" min="1" step="0.01" oninput="calcPesos()" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Precio venta ($)</label>
                                <input type="number" name="precio_venta" id="usd-venta" class="form-control" min="1" step="0.01" placeholder="Referencia">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Total en pesos</label>
                            <div id="usd-total" style="font-size:20px; font-weight:800; color:var(--bordo); padding:6px 0;">$0,00</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Medio de pago</label>
                            <select name="medio_pago" class="form-control">
                                <option value="efectivo">💵 Efectivo</option>
                                <option value="transferencia">💳 Transferencia</option>
                            </select>
                        </div>
                        <button class="btn btn-primary w-100">Registrar compra de dólares</button>
                    </form>
                </div>

                <div id="tab-sueldo" class="caja-tab" style="display:none;">
                    <form method="POST" action="<?= BASE_PATH ?>/caja/actions.php">
                        <input type="hidden" name="action" value="sueldo">
                        <div class="form-group">
                            <label class="form-label">Empleado</label>
                            <input type="text" name="descripcion" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Monto ($)</label>
                            <input type="number" name="monto" class="form-control" min="0.01" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Medio de pago</label>
                            <select name="medio_pago" class="form-control">
                                <option value="efectivo">💵 Efectivo</option>
                                <option value="transferencia">💳 Transferencia</option>
                            </select>
                        </div>
                        <button class="btn btn-primary w-100">Registrar pago de sueldo</button>
                    </form>
                </div>

                <div id="tab-gasto" class="caja-tab" style="display:none;">
                    <form method="POST" action="<?= BASE_PATH ?>/caja/actions.php">
                        <input type="hidden" name="action" value="gasto">
                        <div class="form-group">
                            <label class="form-label">Descripción del gasto</label>
                            <input type="text" name="descripcion" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Monto ($)</label>
                            <input type="number" name="monto" class="form-control" min="0.01" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Medio de pago</label>
                            <select name="medio_pago" class="form-control">
                                <option value="efectivo">💵 Efectivo</option>
                                <option value="transferencia">💳 Transferencia</option>
                            </select>
                        </div>
                        <button class="btn btn-primary w-100">Registrar gasto</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><span class="card-title">Mis movimientos</span></div>
            <div class="table-wrap">
                <?php if (empty($misMovimientos)): ?>
                <div class="empty-state"><div class="empty-icon">💵</div><p>Todavía no cargaste movimientos.</p></div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width:90px;">Fecha</th>
                            <th>Concepto</th>
                            <th style="width:110px;">Medio</th>
                            <th style="text-align:right; width:130px;">Monto</th>
                            <th style="width:28px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($misMovimientos as $m): ?>
                    <tr>
                        <td class="text-muted" style="font-size:12px; white-space:nowrap;"><?= date('d/m/Y', strtotime($m['created_at'])) ?></td>
                        <td>
                            <?= $conceptoLabel[$m['concepto']] ?? $m['concepto'] ?>
                            <?php if ($m['descripcion']): ?><br><span class="text-muted" style="font-size:11px;"><?= e($m['descripcion']) ?></span><?php endif; ?>
                            <?php if ($m['comp_numero']): ?><a href="<?= BASE_PATH ?>/comprobantes/ver.php?id=<?= $m['comprobante_id'] ?>" class="text-bordo" style="font-size:11px;"> → Comp #<?= $m['comp_numero'] ?></a><?php endif; ?>
                            <?php if ($m['monto_dolares']): ?><br><span class="text-muted" style="font-size:11px;">USD <?= number_format((float)$m['monto_dolares'], 2, ',', '.') ?></span><?php endif; ?>
                        </td>
                        <td style="font-size:12px;"><?= $m['medio_pago'] === 'efectivo' ? '💵 Efectivo' : '💳 Transf.' ?></td>
                        <td style="text-align:right; font-weight:700; color:<?= $m['tipo']==='ingreso' ? '#27ae60' : '#c0392b' ?>;">
                            <?= $m['tipo']==='ingreso' ? '+' : '−' ?><?= precio((float)$m['monto']) ?>
                        </td>
                        <td>
                            <?php if ($m['concepto'] !== 'venta'): ?>
                            <a href="<?= BASE_PATH ?>/caja/actions.php?action=delete&id=<?= $m['id'] ?>"
                               class="btn btn-sm btn-danger" style="padding:2px 6px; font-size:11px;"
                               data-confirm="¿Eliminar este movimiento?">✕</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <div style="display:flex; gap:8px; margin-bottom:16px;">
        <button onclick="toggleArqueoForm()" class="btn btn-outline btn-sm">🔎 Hacer arqueo</button>
        <button onclick="toggleMiSaldoForm()" class="btn btn-outline btn-sm">⚙️ Fijar mi saldo actual</button>
    </div>

    <div id="arqueo-form" style="display:none; margin-bottom:16px;">
        <div class="card" style="max-width:560px;">
            <div class="card-header"><span class="card-title">Arqueo — conteo físico</span></div>
            <div class="card-body">
                <p style="font-size:12px; color:var(--text-soft); margin-bottom:12px;">
                    Ingresá lo que contaste realmente. El sistema lo compara contra el saldo calculado
                    (<?= precio($miEfectivo) ?> efectivo, <?= precio($miTransferencia) ?> transferencia) y guarda la diferencia.
                </p>
                <form method="POST" action="<?= BASE_PATH ?>/caja/actions.php">
                    <input type="hidden" name="action" value="arqueo">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">💵 Efectivo contado ($)</label>
                            <input type="text" name="efectivo_contado" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">💳 Transferencia contada ($)</label>
                            <input type="text" name="transferencia_contado" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notas (opcional)</label>
                        <input type="text" name="notas" class="form-control">
                    </div>
                    <button type="submit" class="btn btn-primary">Guardar arqueo</button>
                    <button type="button" onclick="toggleArqueoForm()" class="btn btn-secondary">Cancelar</button>
                </form>
            </div>
        </div>
    </div>

    <div id="mi-saldo-form" style="display:none; margin-bottom:16px;">
        <div class="card" style="max-width:560px;">
            <div class="card-header"><span class="card-title">Fijar mi saldo actual</span></div>
            <div class="card-body">
                <p style="font-size:12px; color:var(--text-soft); margin-bottom:12px;">
                    Poné cuánto tenés <strong>ahora mismo</strong> — el sistema ajusta el punto de partida
                    para que tu total te quede exactamente en ese monto (ya tiene en cuenta los movimientos
                    que ya cargaste).
                </p>
                <form method="POST" action="<?= BASE_PATH ?>/caja/actions.php">
                    <input type="hidden" name="action" value="mi_saldo_inicial">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">💵 Efectivo que tenés ahora ($)</label>
                            <input type="text" name="efectivo" class="form-control"
                                   value="<?= number_format($miEfectivo, 2, ',', '.') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">💳 Transferencia que tenés ahora ($)</label>
                            <input type="text" name="transferencia" class="form-control"
                                   value="<?= number_format($miTransferencia, 2, ',', '.') ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                    <button type="button" onclick="toggleMiSaldoForm()" class="btn btn-secondary">Cancelar</button>
                </form>
            </div>
        </div>
    </div>

    <?php if (!empty($misArqueos)): ?>
    <div class="card" style="max-width:680px;">
        <div class="card-header"><span class="card-title">Mis últimos arqueos</span></div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:90px;">Fecha</th>
                        <th style="text-align:right;">Efectivo (dif.)</th>
                        <th style="text-align:right;">Transf. (dif.)</th>
                        <th>Notas</th>
                        <th style="width:110px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($misArqueos as $a):
                    $hayDif   = abs((float)$a['diferencia_efectivo']) >= 0.01 || abs((float)$a['diferencia_transferencia']) >= 0.01;
                    $ajustado = (bool)($a['ajustado'] ?? false);
                ?>
                <tr>
                    <td class="text-muted" style="font-size:12px; white-space:nowrap;"><?= date('d/m/Y', strtotime($a['created_at'])) ?></td>
                    <td style="text-align:right; font-size:12px;">
                        <?= precio((float)$a['efectivo_contado']) ?>
                        <span style="color:<?= abs((float)$a['diferencia_efectivo']) < 0.01 ? 'var(--text-muted)' : '#c0392b' ?>;">
                            (<?= (float)$a['diferencia_efectivo'] >= 0 ? '+' : '' ?><?= precio((float)$a['diferencia_efectivo']) ?>)
                        </span>
                    </td>
                    <td style="text-align:right; font-size:12px;">
                        <?= precio((float)$a['transferencia_contado']) ?>
                        <span style="color:<?= abs((float)$a['diferencia_transferencia']) < 0.01 ? 'var(--text-muted)' : '#c0392b' ?>;">
                            (<?= (float)$a['diferencia_transferencia'] >= 0 ? '+' : '' ?><?= precio((float)$a['diferencia_transferencia']) ?>)
                        </span>
                    </td>
                    <td style="font-size:12px; color:var(--text-soft);"><?= e($a['notas'] ?? '') ?></td>
                    <td style="text-align:right;">
                        <?php if (!$hayDif): ?>
                        <span class="text-muted" style="font-size:11px;">—</span>
                        <?php elseif ($ajustado): ?>
                        <span class="badge badge-success" style="font-size:10px;">Ajustado ✓</span>
                        <?php else: ?>
                        <form method="POST" action="<?= BASE_PATH ?>/caja/actions.php" style="display:inline;">
                            <input type="hidden" name="action" value="aplicar_ajuste_arqueo">
                            <input type="hidden" name="arqueo_id" value="<?= (int)$a['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline" style="font-size:11px; padding:3px 8px;"
                                    data-confirm="¿Aplicar esta diferencia como ajuste? Se va a crear un movimiento para que el saldo calculado quede igual al contado.">
                                Aplicar ajuste
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ── Caja general ─────────────────────────────────────────────── -->
<div id="caja-tab-general" style="display:none;">

<!-- ── Tarjetas de balance ──────────────────────────────────────── -->
<div style="display:grid; grid-template-columns:repeat(5,1fr); gap:12px; margin-bottom:20px;">

    <div class="card" style="text-align:center; padding:18px 10px;">
        <div style="font-size:10px; text-transform:uppercase; letter-spacing:1px; color:var(--text-muted); margin-bottom:6px;">💵 Efectivo</div>
        <div style="font-size:24px; font-weight:800; color:<?= $totalEfectivo >= 0 ? '#27ae60' : '#c0392b' ?>;"><?= precio($totalEfectivo) ?></div>
        <?php if ((float)$si['efectivo'] != 0): ?>
        <div style="font-size:10px; color:var(--text-muted); margin-top:4px;">Inicial: <?= precio((float)$si['efectivo']) ?></div>
        <?php endif; ?>
    </div>

    <div class="card" style="text-align:center; padding:18px 10px;">
        <div style="font-size:10px; text-transform:uppercase; letter-spacing:1px; color:var(--text-muted); margin-bottom:6px;">💳 Transferencia</div>
        <div style="font-size:24px; font-weight:800; color:<?= $totalTransferencia >= 0 ? '#2980b9' : '#c0392b' ?>;"><?= precio($totalTransferencia) ?></div>
        <?php if ((float)$si['transferencia'] != 0): ?>
        <div style="font-size:10px; color:var(--text-muted); margin-top:4px;">Inicial: <?= precio((float)$si['transferencia']) ?></div>
        <?php endif; ?>
    </div>

    <div class="card" style="text-align:center; padding:18px 10px;">
        <div style="font-size:10px; text-transform:uppercase; letter-spacing:1px; color:var(--text-muted); margin-bottom:6px;">💲 Dólares</div>
        <div style="font-size:24px; font-weight:800; color:#b8860b;">USD <?= number_format($totalDolares, 2, ',', '.') ?></div>
        <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">
            ≈ <?= precio($valorUsd) ?> · $<?= number_format($precioUsd, 0, ',', '.') ?>/USD
        </div>
        <?php if ($ultimoDolar): ?>
        <div style="font-size:10px; color:var(--text-muted);">
            Últ. compra: $<?= number_format((float)$ultimoDolar['precio_dolar_compra'], 0, ',', '.') ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="card" style="text-align:center; padding:18px 10px;">
        <div style="font-size:10px; text-transform:uppercase; letter-spacing:1px; color:var(--text-muted); margin-bottom:6px;">📦 Stock</div>
        <div style="font-size:24px; font-weight:800; color:#5d6b8a;"><?= precio($stockValuacion) ?></div>
        <?php if ($stockItems > 0): ?>
        <div style="font-size:10px; color:var(--text-muted); margin-top:4px;"><?= $stockItems ?> producto<?= $stockItems !== 1 ? 's' : '' ?> con stock</div>
        <?php else: ?>
        <div style="font-size:10px; color:var(--text-muted); margin-top:4px;"><a href="<?= BASE_PATH ?>/stock/">Cargar stock</a></div>
        <?php endif; ?>
    </div>

    <div class="card" style="text-align:center; padding:18px 10px; background:var(--bordo); border-color:var(--bordo);">
        <div style="font-size:10px; text-transform:uppercase; letter-spacing:1px; color:rgba(255,255,255,.7); margin-bottom:6px;">📊 Patrimonio total</div>
        <div style="font-size:24px; font-weight:900; color:#fff;"><?= precio($grandTotal) ?></div>
        <div style="font-size:10px; color:rgba(255,255,255,.65); margin-top:5px; line-height:1.7;">
            Ef + Transf + USD + Stock
        </div>
    </div>

</div>

<!-- ── Botón saldo inicial ──────────────────────────────────────── -->
<div style="margin-bottom:16px;">
    <button onclick="toggleSaldoForm()" class="btn btn-outline btn-sm">⚙️ Configurar saldo inicial y precio USD</button>
    <div id="saldo-form" style="display:none; margin-top:12px;">
        <div class="card" style="max-width:680px;">
            <div class="card-header"><span class="card-title">Saldo inicial (antes del primer registro en el sistema)</span></div>
            <div class="card-body">
                <form method="POST" action="<?= BASE_PATH ?>/caja/actions.php">
                    <input type="hidden" name="action" value="saldo_inicial">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">💵 Efectivo inicial ($)</label>
                            <input type="text" name="efectivo" class="form-control"
                                   value="<?= number_format((float)$si['efectivo'], 2, ',', '.') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">💳 Transferencia inicial ($)</label>
                            <input type="text" name="transferencia" class="form-control"
                                   value="<?= number_format((float)$si['transferencia'], 2, ',', '.') ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">💲 Dólares iniciales (USD)</label>
                            <input type="text" name="dolares" class="form-control"
                                   value="<?= number_format((float)$si['dolares'], 4, ',', '.') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Precio de referencia USD ($/USD)</label>
                            <input type="text" name="dolares_precio" class="form-control"
                                   value="<?= number_format((float)$si['dolares_precio'], 2, ',', '.') ?>">
                            <small class="text-muted" style="font-size:11px;">Usado para convertir los dólares a pesos en el total.</small>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                    <button type="button" onclick="toggleSaldoForm()" class="btn btn-secondary">Cancelar</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ── Desglose por usuario ────────────────────────────────────── -->
<div class="card" style="max-width:680px; margin-bottom:20px;">
    <div class="card-header"><span class="card-title">Desglose por usuario</span></div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Usuario</th>
                    <th style="text-align:right;">Efectivo</th>
                    <th style="text-align:right;">Transferencia</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($desgloseUsuarios as $du): ?>
            <tr>
                <td><?= e($du['nombre']) ?></td>
                <td style="text-align:right;"><?= precio($du['efectivo']) ?></td>
                <td style="text-align:right;"><?= precio($du['transferencia']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
// Misma tabla se repite para ingresos y egresos — helper local para no duplicar el markup.
$renderTablaMovs = function(array $movs) use ($conceptoLabel) {
    if (empty($movs)) {
        echo '<div class="empty-state"><div class="empty-icon">💵</div><p>Sin movimientos.</p></div>';
        return;
    }
    ?>
    <table>
        <thead>
            <tr>
                <th style="width:90px;">Fecha</th>
                <th>Concepto</th>
                <th style="width:110px;">Usuario</th>
                <th style="width:110px;">Medio</th>
                <th style="text-align:right; width:130px;">Monto</th>
                <th style="width:28px;"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($movs as $m): ?>
        <tr>
            <td class="text-muted" style="font-size:12px; white-space:nowrap;"><?= date('d/m/Y', strtotime($m['created_at'])) ?></td>
            <td>
                <?= $conceptoLabel[$m['concepto']] ?? $m['concepto'] ?>
                <?php if ($m['descripcion']): ?><br><span class="text-muted" style="font-size:11px;"><?= e($m['descripcion']) ?></span><?php endif; ?>
                <?php if ($m['comp_numero']): ?><a href="<?= BASE_PATH ?>/comprobantes/ver.php?id=<?= $m['comprobante_id'] ?>" class="text-bordo" style="font-size:11px;"> → Comp #<?= $m['comp_numero'] ?></a><?php endif; ?>
                <?php if ($m['monto_dolares']): ?><br><span class="text-muted" style="font-size:11px;">USD <?= number_format((float)$m['monto_dolares'], 2, ',', '.') ?></span><?php endif; ?>
            </td>
            <td class="text-muted" style="font-size:12px;"><?= e($m['usuario_nombre'] ?? '—') ?></td>
            <td style="font-size:12px;"><?= $m['medio_pago'] === 'efectivo' ? '💵 Efectivo' : '💳 Transf.' ?></td>
            <td style="text-align:right; font-weight:700; color:<?= $m['tipo']==='ingreso' ? '#27ae60' : '#c0392b' ?>;">
                <?= $m['tipo']==='ingreso' ? '+' : '−' ?><?= precio((float)$m['monto']) ?>
            </td>
            <td>
                <?php if ($m['concepto'] !== 'venta'): ?>
                <a href="<?= BASE_PATH ?>/caja/actions.php?action=delete&id=<?= $m['id'] ?>"
                   class="btn btn-sm btn-danger" style="padding:2px 6px; font-size:11px;"
                   data-confirm="¿Eliminar este movimiento?">✕</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
};
?>

<!-- ── Ingresos / Egresos (separados) ──────────────────────────── -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px; align-items:start;">
    <div class="card">
        <div class="card-header" style="background:#f0faf4;">
            <span class="card-title" style="color:#27ae60;">↑ Ingresos</span>
            <span class="text-muted" style="font-size:11px; margin-left:8px;">(últimos <?= count($movimientosIngresos) ?>)</span>
        </div>
        <div class="table-wrap"><?php $renderTablaMovs($movimientosIngresos); ?></div>
    </div>
    <div class="card">
        <div class="card-header" style="background:#fdf0f0;">
            <span class="card-title" style="color:#c0392b;">↓ Egresos</span>
            <span class="text-muted" style="font-size:11px; margin-left:8px;">(últimos <?= count($movimientosEgresos) ?>)</span>
        </div>
        <div class="table-wrap"><?php $renderTablaMovs($movimientosEgresos); ?></div>
    </div>
</div>

<!-- ── Todos los movimientos ────────────────────────────────────── -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header"><span class="card-title">Todos los movimientos</span></div>
    <div class="table-wrap"><?php $renderTablaMovs($movimientos); ?></div>
</div>

</div>

<!-- ── Pendientes de cobro ──────────────────────────────────────── -->
<?php if (!empty($emitidos)): ?>
<div class="card" style="margin-top:20px;">
    <div class="card-header" style="display:flex; align-items:center; gap:12px;">
        <span class="card-title">⚠️ Pendientes de cobro</span>
        <span class="badge badge-danger"><?= count($emitidos) ?> comp.</span>
        <span style="margin-left:auto; font-weight:700; color:var(--bordo); font-size:15px;">
            Total: <?= precio($totalPendiente) ?>
        </span>
    </div>
    <div class="card-body" style="padding:16px;">
        <div style="display:flex; flex-wrap:wrap; gap:12px;">
        <?php foreach ($emitidos as $e): ?>
            <a href="<?= BASE_PATH ?>/comprobantes/ver.php?id=<?= $e['id'] ?>" style="text-decoration:none; flex:0 0 auto; min-width:190px; max-width:250px;">
                <div style="border:2px solid var(--bordo); border-radius:8px; padding:12px 14px; background:#fff8f8;">
                    <div style="font-size:16px; font-weight:800; color:#1a1a1a; margin-bottom:2px;"><?= e($e['cliente_nombre']) ?></div>
                    <div style="font-size:11px; color:var(--text-muted); margin-bottom:6px;">Comp. #<?= $e['numero'] ?> · <?= date('d/m/Y', strtotime($e['fecha'])) ?></div>
                    <div style="font-size:22px; font-weight:900; color:var(--bordo);"><?= precio((float)$e['total']) ?></div>
                </div>
            </a>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Deuda a proveedores ──────────────────────────────────────── -->
<?php if (!empty($deudas)): ?>
<div class="card" style="margin-top:16px; border-color:#c0392b;">
    <div class="card-header" style="display:flex; align-items:center; gap:12px; background:#fdf0f0;">
        <span class="card-title" style="color:#c0392b;">🏭 Deuda a proveedores</span>
        <span style="margin-left:auto; font-weight:700; color:#c0392b; font-size:15px;">
            Total: <?= precio($totalDeudaProveedores) ?>
        </span>
    </div>
    <div class="card-body" style="padding:14px 16px; display:flex; gap:24px; flex-wrap:wrap;">
        <?php foreach ($deudas as $d): ?>
        <div>
            <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:#888; margin-bottom:4px;">
                <?= $cuentaLabel[$d['cuenta']] ?? $d['cuenta'] ?>
            </div>
            <div style="font-size:22px; font-weight:800; color:#c0392b;"><?= precio((float)$d['saldo']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script>
function showTab(name) {
    document.querySelectorAll('.caja-tab').forEach(el => el.style.display = 'none');
    const el = document.getElementById('tab-' + name);
    if (el) el.style.display = 'block';
}
function toggleSaldoForm() {
    const f = document.getElementById('saldo-form');
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
}
function toggleArqueoForm() {
    const f = document.getElementById('arqueo-form');
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
}
function toggleMiSaldoForm() {
    const f = document.getElementById('mi-saldo-form');
    f.style.display = f.style.display === 'none' ? 'block' : 'none';
}
function showCajaTab(name) {
    document.getElementById('caja-tab-mi-caja').style.display = name === 'mi-caja' ? 'block' : 'none';
    document.getElementById('caja-tab-general').style.display = name === 'general' ? 'block' : 'none';
    document.getElementById('btn-tab-mi-caja').className = 'btn btn-sm ' + (name === 'mi-caja' ? 'btn-primary' : 'btn-outline');
    document.getElementById('btn-tab-general').className = 'btn btn-sm ' + (name === 'general' ? 'btn-primary' : 'btn-outline');
}
function calcPesos() {
    const cant   = parseFloat(document.getElementById('usd-cant').value)   || 0;
    const compra = parseFloat(document.getElementById('usd-compra').value) || 0;
    const total  = cant * compra;
    document.getElementById('usd-total').textContent =
        '$' + total.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}
</script>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

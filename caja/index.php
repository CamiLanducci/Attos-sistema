<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

if (($_SESSION['rol'] ?? 'admin') !== 'admin') redirect(BASE_PATH . '/index.php');

$db = getDB();

// ── Balances ──────────────────────────────────────────────────
$bal = $db->query("
    SELECT
        COALESCE(SUM(CASE WHEN medio_pago='efectivo' AND tipo='ingreso' THEN monto
                          WHEN medio_pago='efectivo' AND tipo='egreso'  THEN -monto ELSE 0 END), 0) AS efectivo,
        COALESCE(SUM(CASE WHEN medio_pago='transferencia' AND tipo='ingreso' THEN monto
                          WHEN medio_pago='transferencia' AND tipo='egreso'  THEN -monto ELSE 0 END), 0) AS transferencia,
        COALESCE(SUM(CASE WHEN concepto='compra_dolares' THEN monto_dolares ELSE 0 END), 0) AS dolares
    FROM caja_movimientos
")->fetch();

$balEfectivo      = (float)$bal['efectivo'];
$balTransferencia = (float)$bal['transferencia'];
$balTotal         = $balEfectivo + $balTransferencia;
$balDolares       = (float)$bal['dolares'];

$ultimoDolar = $db->query("
    SELECT precio_dolar_compra, precio_dolar_venta
    FROM caja_movimientos WHERE concepto='compra_dolares'
    ORDER BY created_at DESC LIMIT 1
")->fetch();

// ── Deudas pendientes (emitidos) ─────────────────────────────
$emitidos = $db->query("
    SELECT c.id, c.numero, c.fecha, c.total,
           cl.nombre AS cliente_nombre
    FROM comprobantes c
    JOIN clientes cl ON cl.id = c.cliente_id
    WHERE c.estado = 'emitido'
    ORDER BY c.fecha ASC, c.id ASC
")->fetchAll();

$totalDeuda = array_sum(array_column($emitidos, 'total'));

// ── Últimos movimientos ───────────────────────────────────────
$movimientos = $db->query("
    SELECT cm.*, u.nombre_real AS usuario_nombre,
           c.numero AS comp_numero
    FROM caja_movimientos cm
    LEFT JOIN usuarios    u ON u.id  = cm.usuario_id
    LEFT JOIN comprobantes c ON c.id = cm.comprobante_id
    ORDER BY cm.created_at DESC
    LIMIT 40
")->fetchAll();

$msg       = $_GET['msg'] ?? '';
$pageTitle = 'Caja de Plata';
require_once __DIR__ . '/../config/layout.php';

$conceptoLabel = [
    'venta'           => '🛒 Venta',
    'pago_proveedor'  => '🏭 Proveedor',
    'compra_dolares'  => '💲 Dólares',
    'sueldo'          => '👤 Sueldo',
    'gasto'           => '📝 Gasto',
    'otro'            => '• Otro',
];
?>

<?php if ($msg === 'ok'): ?><div class="alert alert-success" data-autodismiss>Movimiento registrado.</div><?php endif; ?>
<?php if ($msg === 'deleted'): ?><div class="alert alert-success" data-autodismiss>Movimiento eliminado.</div><?php endif; ?>

<!-- ── Balances ──────────────────────────────────────────────── -->
<div style="display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:20px;">

    <div class="card" style="text-align:center; padding:20px 12px;">
        <div style="font-size:10px; text-transform:uppercase; letter-spacing:1px; color:var(--text-muted); margin-bottom:6px;">💵 Efectivo</div>
        <div style="font-size:26px; font-weight:800; color:<?= $balEfectivo >= 0 ? '#27ae60' : '#c0392b' ?>;"><?= precio($balEfectivo) ?></div>
    </div>

    <div class="card" style="text-align:center; padding:20px 12px;">
        <div style="font-size:10px; text-transform:uppercase; letter-spacing:1px; color:var(--text-muted); margin-bottom:6px;">💳 Transferencia</div>
        <div style="font-size:26px; font-weight:800; color:<?= $balTransferencia >= 0 ? '#2980b9' : '#c0392b' ?>;"><?= precio($balTransferencia) ?></div>
    </div>

    <div class="card" style="text-align:center; padding:20px 12px;">
        <div style="font-size:10px; text-transform:uppercase; letter-spacing:1px; color:var(--text-muted); margin-bottom:6px;">📊 Total en pesos</div>
        <div style="font-size:26px; font-weight:800; color:var(--bordo);"><?= precio($balTotal) ?></div>
    </div>

    <div class="card" style="text-align:center; padding:20px 12px;">
        <div style="font-size:10px; text-transform:uppercase; letter-spacing:1px; color:var(--text-muted); margin-bottom:6px;">💲 Dólares</div>
        <div style="font-size:26px; font-weight:800; color:#b8860b;">
            USD <?= number_format($balDolares, 2, ',', '.') ?>
        </div>
        <?php if ($ultimoDolar): ?>
        <div style="font-size:10px; color:var(--text-muted); margin-top:4px; line-height:1.6;">
            Compra: <?= precio((float)$ultimoDolar['precio_dolar_compra']) ?>
            <?php if ($ultimoDolar['precio_dolar_venta']): ?>
            · Venta: <?= precio((float)$ultimoDolar['precio_dolar_venta']) ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- ── Cuerpo principal: formularios + movimientos ───────────── -->
<div style="display:grid; grid-template-columns:340px 1fr; gap:16px; align-items:flex-start;">

    <!-- Columna izquierda: registrar movimiento -->
    <div>
        <div class="card">
            <div class="card-header"><span class="card-title">Registrar movimiento</span></div>
            <div class="card-body">

                <!-- Botones de acción -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:16px;">
                    <button onclick="showTab('proveedor')" class="btn btn-outline btn-sm">🏭 Pago proveedor</button>
                    <button onclick="showTab('dolares')"   class="btn btn-outline btn-sm">💲 Comprar dólares</button>
                    <button onclick="showTab('sueldo')"    class="btn btn-outline btn-sm">👤 Pagar sueldo</button>
                    <button onclick="showTab('gasto')"     class="btn btn-outline btn-sm">📝 Registrar gasto</button>
                </div>

                <!-- ─ Pago proveedor ─ -->
                <div id="tab-proveedor" class="caja-tab" style="display:none;">
                    <form method="POST" action="<?= BASE_PATH ?>/caja/actions.php">
                        <input type="hidden" name="action" value="pago_proveedor">
                        <div class="form-group">
                            <label class="form-label">Proveedor / descripción</label>
                            <input type="text" name="descripcion" class="form-control" placeholder="Ej: Pago a Distribuidora X" required>
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

                <!-- ─ Compra de dólares ─ -->
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

                <!-- ─ Sueldo ─ -->
                <div id="tab-sueldo" class="caja-tab" style="display:none;">
                    <form method="POST" action="<?= BASE_PATH ?>/caja/actions.php">
                        <input type="hidden" name="action" value="sueldo">
                        <div class="form-group">
                            <label class="form-label">Empleado</label>
                            <input type="text" name="descripcion" class="form-control" placeholder="Ej: Sueldo Bauti — Junio" required>
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

                <!-- ─ Gasto ─ -->
                <div id="tab-gasto" class="caja-tab" style="display:none;">
                    <form method="POST" action="<?= BASE_PATH ?>/caja/actions.php">
                        <input type="hidden" name="action" value="gasto">
                        <div class="form-group">
                            <label class="form-label">Descripción del gasto</label>
                            <input type="text" name="descripcion" class="form-control" placeholder="Ej: Combustible, alquiler, etc." required>
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
    </div>

    <!-- Columna derecha: últimos movimientos -->
    <div class="card">
        <div class="card-header"><span class="card-title">Últimos movimientos</span></div>
        <div class="table-wrap">
            <?php if (empty($movimientos)): ?>
            <div class="empty-state"><div class="empty-icon">💵</div><p>Sin movimientos aún.</p></div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th style="width:90px;">Fecha</th>
                        <th>Concepto</th>
                        <th style="width:120px;">Medio</th>
                        <th style="text-align:right; width:130px;">Monto</th>
                        <th style="width:28px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($movimientos as $m): ?>
                <tr>
                    <td class="text-muted" style="font-size:12px; white-space:nowrap;">
                        <?= date('d/m/Y', strtotime($m['created_at'])) ?>
                    </td>
                    <td>
                        <span><?= $conceptoLabel[$m['concepto']] ?? $m['concepto'] ?></span>
                        <?php if ($m['descripcion']): ?>
                        <br><span class="text-muted" style="font-size:11px;"><?= e($m['descripcion']) ?></span>
                        <?php endif; ?>
                        <?php if ($m['comp_numero']): ?>
                        <a href="<?= BASE_PATH ?>/comprobantes/ver.php?id=<?= $m['comprobante_id'] ?>"
                           class="text-bordo" style="font-size:11px;"> → Comp #<?= $m['comp_numero'] ?></a>
                        <?php endif; ?>
                        <?php if ($m['monto_dolares']): ?>
                        <br><span class="text-muted" style="font-size:11px;">USD <?= number_format((float)$m['monto_dolares'], 2, ',', '.') ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;">
                        <?= $m['medio_pago'] === 'efectivo' ? '💵 Efectivo' : '💳 Transf.' ?>
                    </td>
                    <td style="text-align:right; font-weight:700;
                                color:<?= $m['tipo']==='ingreso' ? '#27ae60' : '#c0392b' ?>;">
                        <?= $m['tipo']==='ingreso' ? '+' : '−' ?><?= precio((float)$m['monto']) ?>
                    </td>
                    <td>
                        <?php if ($m['concepto'] !== 'venta'): ?>
                        <a href="<?= BASE_PATH ?>/caja/actions.php?action=delete&id=<?= $m['id'] ?>"
                           class="btn btn-sm btn-danger"
                           style="padding:2px 6px; font-size:11px;"
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

<!-- ── Deudas pendientes ──────────────────────────────────────── -->
<?php if (!empty($emitidos)): ?>
<div class="card" style="margin-top:20px;">
    <div class="card-header" style="display:flex; align-items:center; gap:12px;">
        <span class="card-title">⚠️ Pendientes de cobro</span>
        <span class="badge badge-danger"><?= count($emitidos) ?> comprobante<?= count($emitidos)!==1?'s':'' ?></span>
        <span style="margin-left:auto; font-weight:700; color:var(--bordo); font-size:15px;">
            Total deuda: <?= precio($totalDeuda) ?>
        </span>
    </div>
    <div class="card-body" style="padding:16px;">
        <div style="display:flex; flex-wrap:wrap; gap:12px;">
        <?php foreach ($emitidos as $e): ?>
            <a href="<?= BASE_PATH ?>/comprobantes/ver.php?id=<?= $e['id'] ?>"
               style="text-decoration:none; flex:0 0 auto; min-width:200px; max-width:260px;">
                <div style="border:2px solid var(--bordo); border-radius:8px; padding:14px 16px;
                             background:#fff8f8; transition:box-shadow .15s;"
                     onmouseover="this.style.boxShadow='0 4px 16px rgba(99,22,54,.18)'"
                     onmouseout="this.style.boxShadow=''">
                    <div style="font-size:17px; font-weight:800; color:#1a1a1a; margin-bottom:4px; line-height:1.2;">
                        <?= e($e['cliente_nombre']) ?>
                    </div>
                    <div style="font-size:11px; color:var(--text-muted); margin-bottom:8px;">
                        Comp. #<?= $e['numero'] ?> · <?= date('d/m/Y', strtotime($e['fecha'])) ?>
                    </div>
                    <div style="font-size:24px; font-weight:900; color:var(--bordo);">
                        <?= precio((float)$e['total']) ?>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function showTab(name) {
    document.querySelectorAll('.caja-tab').forEach(function(el) { el.style.display = 'none'; });
    var el = document.getElementById('tab-' + name);
    if (el) el.style.display = 'block';
}
function calcPesos() {
    var cant   = parseFloat(document.getElementById('usd-cant').value)   || 0;
    var compra = parseFloat(document.getElementById('usd-compra').value) || 0;
    var total  = cant * compra;
    document.getElementById('usd-total').textContent =
        '$' + total.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}
</script>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

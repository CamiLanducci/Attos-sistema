<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
$pageTitle     = 'Registrar pago a galpón / Alfre';
$topbarActions = '<a href="/attos/cuentas/" class="btn btn-secondary">← Volver</a>';

$msg          = $_GET['msg'] ?? '';
$cuentaPresel = in_array($_GET['cuenta'] ?? '', ['area_520','alfre']) ? $_GET['cuenta'] : '';
require_once __DIR__ . '/../config/layout.php';
?>

<?php if ($msg === 'error'): ?><div class="alert alert-warning" data-autodismiss>Error al registrar el pago. Verificá los datos.</div><?php endif; ?>

<div class="card" style="max-width:500px;">
    <div class="card-header"><span class="card-title">Registrar pago</span></div>
    <div class="card-body">
        <form method="POST" action="/attos/cuentas/actions.php">
            <input type="hidden" name="action" value="pago">

            <div class="form-group">
                <label class="form-label">Cuenta destino *</label>
                <select name="cuenta" class="form-control" required id="sel-cuenta" onchange="actualizarDesc()">
                    <option value="">— Seleccionar —</option>
                    <option value="area_520" <?= $cuentaPresel === 'area_520' ? 'selected' : '' ?>>Area 520</option>
                    <option value="alfre"    <?= $cuentaPresel === 'alfre'    ? 'selected' : '' ?>>Cuenta Alfre</option>
                </select>
                <div class="text-muted" style="font-size:12px; margin-top:4px;">
                    Genera dos movimientos: pago en la cuenta seleccionada y pago en Patrimonio.
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex:1;">
                    <label class="form-label">Monto *</label>
                    <input type="number" name="monto" class="form-control"
                           step="0.01" min="0.01" placeholder="0,00" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Fecha</label>
                    <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Descripción</label>
                <input type="text" name="descripcion" id="desc-input" class="form-control"
                       placeholder="Pago a …" maxlength="500">
            </div>

            <button type="submit" class="btn btn-primary w-100"
                    onclick="return confirm('¿Confirmar el pago? Se crearán dos movimientos en una sola transacción.')">
                Confirmar pago
            </button>
        </form>
    </div>
</div>

<script>
function actualizarDesc() {
    const cuenta = document.getElementById('sel-cuenta').value;
    const label = {'area_520': 'Area 520', 'alfre': 'Cuenta Alfre'};
    const input = document.getElementById('desc-input');
    if (cuenta && !input.value) {
        input.value = 'Pago a ' + (label[cuenta] || cuenta);
    }
}
<?php if ($cuentaPresel): ?>
document.addEventListener('DOMContentLoaded', actualizarDesc);
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

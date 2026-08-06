<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
$pageTitle = 'Listas / Márgenes';

// Esquema fijo: sólo existen (para este flujo) las listas de 10/15/20/25%.
// La del 20% es la "base" — tiene la URL del proveedor. Las otras 3 se
// calculan siempre a partir de ella: 20−10=10%, 20−5=15%, 20+5=25%.
const MARGENES_FIJOS = [10, 15, 20, 25];
const MARGEN_BASE    = 20;

$db    = getDB();
$filas = $db->query("
    SELECT l.*, log.fecha AS import_log_fecha
    FROM listas l
    LEFT JOIN lista_import_log log ON log.lista_id = l.id
    WHERE l.margen IN (" . implode(',', MARGENES_FIJOS) . ")
    ORDER BY l.margen ASC
")->fetchAll();

$porMargen  = [];
$duplicados = [];
foreach ($filas as $f) {
    $m = (int)round((float)$f['margen']);
    if (isset($porMargen[$m])) {
        $duplicados[$m][] = $f;
    } else {
        $porMargen[$m] = $f;
    }
}

$base = $porMargen[MARGEN_BASE] ?? null;

// Mantener el vínculo "deriva de" de las listas 10/15/25 apuntando siempre a
// la base — en este esquema fijo no lo elige el usuario, se fuerza acá.
// Es una escritura idempotente y liviana (a lo sumo 3 UPDATE) así que es
// seguro correrla en cada carga de la página.
if ($base) {
    foreach (MARGENES_FIJOS as $m) {
        if ($m === MARGEN_BASE || !isset($porMargen[$m])) continue;
        $l = $porMargen[$m];
        if ((int)($l['deriva_de_lista_id'] ?? 0) !== (int)$base['id'] || !empty($l['url_actualizacion'])) {
            $db->prepare("UPDATE listas SET deriva_de_lista_id=?, url_actualizacion=NULL WHERE id=?")
               ->execute([$base['id'], $l['id']]);
            $porMargen[$m]['deriva_de_lista_id'] = $base['id'];
            $porMargen[$m]['url_actualizacion']  = null;
        }
    }
}

$faltantes      = array_values(array_diff(MARGENES_FIJOS, array_keys($porMargen)));
$puedeImportar  = $base && !empty($base['url_actualizacion']);

$msg = $_GET['msg'] ?? '';

require_once __DIR__ . '/../config/layout.php';
?>

<?php if ($msg === 'updated'):        ?><div class="alert alert-success" data-autodismiss>Lista actualizada.</div><?php endif; ?>
<?php if ($msg === 'duplicate'):      ?><div class="alert alert-danger"  data-autodismiss>Ya existe una lista con ese código.</div><?php endif; ?>
<?php if ($msg === 'config_missing'): ?><div class="alert alert-warning" data-autodismiss>Configurá la URL de la lista base (20%) antes de importar.</div><?php endif; ?>
<?php if ($msg === 'sin_cambios_pdf'): ?><div class="alert alert-warning" data-autodismiss>Esa lista no tiene datos para ese PDF (no hubo cambios de ese tipo en la última importación).</div><?php endif; ?>

<?php if (!empty($duplicados)): ?>
<div class="alert alert-warning" style="margin-bottom:16px;">
    <strong>Atención:</strong> hay más de una lista con el mismo margen entre las fijas (10/15/20/25%) —
    <?php foreach ($duplicados as $m => $dups): ?>
        <?= $m ?>%: <?= implode(', ', array_map(fn($d) => e($d['codigo']), $dups)) ?>.
    <?php endforeach; ?>
    Sólo se usa la primera encontrada de cada margen; revisá esto directamente en la base si hace falta.
</div>
<?php endif; ?>

<!-- Botón de importación global -->
<div style="display:flex; align-items:center; gap:12px; margin-bottom:20px; flex-wrap:wrap;">
    <a href="<?= BASE_PATH ?>/listas/importar.php"
       class="btn btn-primary <?= !$puedeImportar ? 'disabled' : '' ?>"
       <?= !$puedeImportar ? 'onclick="return false;" title=\'Configurá la URL de la lista base (20%)\'' : '' ?>>
        ↓ Importar precios desde proveedor
    </a>
    <span class="text-muted" style="font-size:12px;">
        <?= $puedeImportar ? 'Listo para importar — se actualizan las 4 listas (10/15/20/25%)' : 'Falta configurar la URL de la lista base' ?>
    </span>
    <a href="<?= BASE_PATH ?>/listas/verificar.php" class="btn btn-secondary btn-sm">Ver estado importación</a>
</div>

<?php if (!$base): ?>
<!-- No existe todavía la lista base (20%) -->
<div class="card" style="max-width:480px; margin-bottom:24px;">
    <div class="card-header"><span class="card-title">Falta crear la lista base (20%)</span></div>
    <div class="card-body">
        <p style="font-size:13px; color:var(--text-soft); margin-bottom:14px;">
            Todo el sistema de precios parte de una única lista con margen 20%. Creala primero
            (después vas a cargarle la URL del proveedor acá mismo).
        </p>
        <form method="POST" action="<?= BASE_PATH ?>/listas/actions.php">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="margen" value="<?= MARGEN_BASE ?>">
            <div class="form-group">
                <label class="form-label">Código</label>
                <input type="text" name="codigo" class="form-control" placeholder="ej: l20" required>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Crear lista del 20%</button>
            </div>
        </form>
    </div>
</div>
<?php else: ?>

<!-- Lista base — único lugar donde se carga la URL del proveedor -->
<div class="card" style="max-width:640px; margin-bottom:24px;">
    <div class="card-header" style="display:flex; align-items:center; justify-content:space-between;">
        <span class="card-title">Lista base <span class="text-muted" style="font-weight:400;">— 20%</span></span>
        <span class="badge badge-bordo" style="font-size:14px;"><?= e($base['codigo']) ?></span>
    </div>
    <div class="card-body">
        <p style="font-size:12.5px; color:var(--text-soft); margin-bottom:14px;">
            De esta lista salen las otras 3 automáticamente: 20−10=<strong>10%</strong>,
            20−5=<strong>15%</strong>, 20+5=<strong>25%</strong>.
        </p>

        <?php if ($base['ultima_actualizacion']): ?>
        <div class="lista-stat" style="margin-bottom:12px;">
            <span class="lista-stat-label">Última actualización del sistema</span>
            <span style="font-size:12px; color:var(--success); font-weight:600;">
                <?= date('d/m/Y', strtotime($base['ultima_actualizacion'])) ?> a las <?= date('H:i', strtotime($base['ultima_actualizacion'])) ?>
            </span>
        </div>
        <?php else: ?>
        <div style="font-size:12px; color:var(--text-soft); margin-bottom:12px;">Sin actualizaciones aún</div>
        <?php endif; ?>

        <?php if ($base['import_log_fecha']): ?>
        <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px;">
            <a href="<?= BASE_PATH ?>/listas/exportar_cambios_pdf.php?lista_id=<?= $base['id'] ?>&origen=historial&modo=aumentos"
               target="_blank" class="btn btn-secondary btn-sm" style="font-size:11px;">📈 PDF aumentos</a>
            <a href="<?= BASE_PATH ?>/listas/exportar_cambios_pdf.php?lista_id=<?= $base['id'] ?>&origen=historial&modo=todos"
               target="_blank" class="btn btn-secondary btn-sm" style="font-size:11px;">📊 PDF aumentos y bajas</a>
        </div>
        <?php endif; ?>

        <form method="POST" action="<?= BASE_PATH ?>/listas/actions.php">
            <input type="hidden" name="id" value="<?= $base['id'] ?>">
            <input type="hidden" name="codigo" value="<?= e($base['codigo']) ?>">
            <div class="form-group">
                <label class="form-label">URL del proveedor</label>
                <input type="url" name="url_actualizacion" class="form-control"
                       value="<?= e($base['url_actualizacion'] ?? '') ?>"
                       placeholder="https://…">
            </div>
            <div class="form-actions">
                <button type="submit" name="action" value="update" class="btn btn-primary btn-sm">Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- Listas derivadas — sólo informativas, no hay nada para configurar -->
<div class="lista-cards-grid">
<?php foreach (MARGENES_FIJOS as $m):
    if ($m === MARGEN_BASE) continue;
    $l    = $porMargen[$m] ?? null;
    $diff = $m - MARGEN_BASE; // -10, -5 o +5
?>

<?php if (!$l): ?>
<div class="lista-card">
    <div class="lista-card-header">
        <span class="badge badge-gray" style="font-size:15px;">— sin crear —</span>
        <span class="lista-margen"><?= $m ?>%</span>
    </div>
    <div class="lista-card-body">
        <p style="font-size:12px; color:var(--text-soft); margin-bottom:12px;">
            Falta crear la lista del <?= $m ?>%. Se va a calcular sola (base <?= $diff >= 0 ? '+' : '' ?><?= $diff ?>%)
            en cuanto exista.
        </p>
        <form method="POST" action="<?= BASE_PATH ?>/listas/actions.php">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="margen" value="<?= $m ?>">
            <div class="form-group">
                <input type="text" name="codigo" class="form-control" placeholder="ej: l<?= $m ?>" required>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-secondary btn-sm">Crear lista del <?= $m ?>%</button>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
<div class="lista-card">
    <div class="lista-card-header">
        <span class="badge badge-bordo" style="font-size:15px;"><?= e($l['codigo']) ?></span>
        <span class="lista-margen"><?= $m ?>%</span>
    </div>
    <div class="lista-card-body">
        <div style="font-size:11px; color:var(--text-soft); margin-bottom:10px;">
            Se calcula sola: base <?= $diff >= 0 ? '+' : '' ?><?= $diff ?>% sobre el precio de la lista del 20%.
        </div>

        <?php if ($l['ultima_actualizacion']): ?>
        <div class="lista-stat" style="margin-bottom:12px;">
            <span class="lista-stat-label">Última actualización del sistema</span>
            <span style="font-size:12px; color:var(--success); font-weight:600;">
                <?= date('d/m/Y', strtotime($l['ultima_actualizacion'])) ?> a las <?= date('H:i', strtotime($l['ultima_actualizacion'])) ?>
            </span>
        </div>
        <?php else: ?>
        <div style="font-size:12px; color:var(--text-soft); margin-bottom:12px;">Sin actualizaciones aún</div>
        <?php endif; ?>

        <?php if ($l['import_log_fecha']): ?>
        <div style="display:flex; gap:6px; flex-wrap:wrap;">
            <a href="<?= BASE_PATH ?>/listas/exportar_cambios_pdf.php?lista_id=<?= $l['id'] ?>&origen=historial&modo=aumentos"
               target="_blank" class="btn btn-secondary btn-sm" style="font-size:11px;">📈 PDF aumentos</a>
            <a href="<?= BASE_PATH ?>/listas/exportar_cambios_pdf.php?lista_id=<?= $l['id'] ?>&origen=historial&modo=todos"
               target="_blank" class="btn btn-secondary btn-sm" style="font-size:11px;">📊 PDF aumentos y bajas</a>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php endforeach; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

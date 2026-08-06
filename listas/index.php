<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
$pageTitle = 'Listas / Márgenes';

$db     = getDB();
$listas = $db->query("
    SELECT l.*, log.fecha AS import_log_fecha
    FROM listas l
    LEFT JOIN lista_import_log log ON log.lista_id = l.id
    ORDER BY l.margen DESC
")->fetchAll();

$msg = $_GET['msg'] ?? '';
$listasConUrl      = array_filter($listas, fn($l) => !empty($l['url_actualizacion']));
$listasDerivadas   = array_filter($listas, fn($l) => empty($l['url_actualizacion']) && !empty($l['deriva_de_lista_id']));
$listasImportables = $listasConUrl + $listasDerivadas;
$listasPorId       = array_column($listas, null, 'id');

require_once __DIR__ . '/../config/layout.php';
?>

<?php if ($msg === 'updated'):        ?><div class="alert alert-success" data-autodismiss>Lista actualizada.</div><?php endif; ?>
<?php if ($msg === 'duplicate'):      ?><div class="alert alert-danger"  data-autodismiss>Ya existe una lista con ese código.</div><?php endif; ?>
<?php if ($msg === 'config_missing'): ?><div class="alert alert-warning" data-autodismiss>Configurá las URLs de las listas antes de importar.</div><?php endif; ?>
<?php if ($msg === 'sin_cambios_pdf'): ?><div class="alert alert-warning" data-autodismiss>Esa lista no tiene datos para ese PDF (no hubo cambios de ese tipo en la última importación).</div><?php endif; ?>

<!-- Botón de importación global -->
<div style="display:flex; align-items:center; gap:12px; margin-bottom:20px; flex-wrap:wrap;">
    <a href="<?= BASE_PATH ?>/listas/importar.php"
       class="btn btn-primary <?= empty($listasImportables) ? 'disabled' : '' ?>"
       <?= empty($listasImportables) ? 'onclick="return false;" title=\'Configurá al menos una URL en las listas\'' : '' ?>>
        ↓ Importar precios desde proveedor
    </a>
    <span class="text-muted" style="font-size:12px;">
        <?= count($listasImportables) ?> de <?= count($listas) ?> listas configuradas
        (<?= count($listasConUrl) ?> con URL, <?= count($listasDerivadas) ?> derivadas)
    </span>
    <a href="<?= BASE_PATH ?>/listas/verificar.php" class="btn btn-secondary btn-sm">Ver estado importación</a>
</div>

<div class="lista-cards-grid">
<?php foreach ($listas as $l): ?>
<div class="lista-card">
    <div class="lista-card-header">
        <span class="badge badge-bordo" style="font-size:15px;"><?= e($l['codigo']) ?></span>
        <span class="lista-margen"><?= $l['margen'] ?>%</span>
    </div>
    <div class="lista-card-body">

        <?php if (!empty($l['deriva_de_lista_id']) && isset($listasPorId[$l['deriva_de_lista_id']])):
            $base = $listasPorId[$l['deriva_de_lista_id']];
            $diff = $l['margen'] - $base['margen'];
        ?>
        <div style="font-size:11px; color:var(--text-soft); margin-bottom:10px;">
            Deriva de <strong><?= e($base['codigo']) ?></strong>
            (<?= $diff >= 0 ? '+' : '' ?><?= number_format($diff, 2) ?>% sobre su precio)
        </div>
        <?php endif; ?>

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
        <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px;">
            <a href="<?= BASE_PATH ?>/listas/exportar_cambios_pdf.php?lista_id=<?= $l['id'] ?>&origen=historial&modo=aumentos"
               target="_blank" class="btn btn-secondary btn-sm" style="font-size:11px;">
                📈 PDF aumentos
            </a>
            <a href="<?= BASE_PATH ?>/listas/exportar_cambios_pdf.php?lista_id=<?= $l['id'] ?>&origen=historial&modo=todos"
               target="_blank" class="btn btn-secondary btn-sm" style="font-size:11px;">
                📊 PDF aumentos y bajas
            </a>
        </div>
        <?php endif; ?>

        <form method="POST" action="<?= BASE_PATH ?>/listas/actions.php">
            <input type="hidden" name="id" value="<?= $l['id'] ?>">
            <div class="form-group">
                <label class="form-label">Código</label>
                <input type="text" name="codigo" class="form-control" value="<?= e($l['codigo']) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Margen</label>
                <input type="text" class="form-control" value="<?= $l['margen'] ?>%"
                       readonly style="background:var(--bg-soft); color:var(--text-soft); cursor:not-allowed;">
            </div>
            <div class="form-group">
                <label class="form-label">URL del proveedor</label>
                <input type="url" name="url_actualizacion" class="form-control"
                       value="<?= e($l['url_actualizacion'] ?? '') ?>"
                       placeholder="https://…"
                       style="font-size:11px;">
            </div>
            <div class="form-group">
                <label class="form-label">O deriva precios de otra lista</label>
                <select name="deriva_de_lista_id" class="form-control" style="font-size:12px;">
                    <option value="">— Ninguna (usa la URL de arriba) —</option>
                    <?php foreach ($listas as $otra): if ($otra['id'] == $l['id']) continue; ?>
                    <option value="<?= $otra['id'] ?>" <?= (int)($l['deriva_de_lista_id'] ?? 0) === (int)$otra['id'] ? 'selected' : '' ?>>
                        <?= e($otra['codigo']) ?> (<?= $otra['margen'] ?>%)
                    </option>
                    <?php endforeach; ?>
                </select>
                <span class="text-muted" style="font-size:11px;">
                    El precio se calcula como el de esa lista ± la diferencia de margen.
                </span>
            </div>
            <div class="form-actions">
                <button type="submit" name="action" value="update" class="btn btn-secondary btn-sm">
                    Guardar
                </button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>
</div>

<div class="card" style="max-width:420px; margin-top:24px;">
    <div class="card-header"><span class="card-title">Nueva lista</span></div>
    <div class="card-body">
        <form method="POST" action="<?= BASE_PATH ?>/listas/actions.php">
            <input type="hidden" name="action" value="create">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Código</label>
                    <input type="text" name="codigo" class="form-control" placeholder="ej: l80" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Margen (%)</label>
                    <input type="number" name="margen" class="form-control" min="0" max="100"
                           step="0.01" placeholder="30" required>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Crear lista</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
$pageTitle = 'Listas / Márgenes';

$db     = getDB();
$listas = $db->query("SELECT * FROM listas ORDER BY margen DESC")->fetchAll();

$msg = $_GET['msg'] ?? '';
$listasConUrl = array_filter($listas, fn($l) => !empty($l['url_actualizacion']));

require_once __DIR__ . '/../config/layout.php';
?>

<?php if ($msg === 'updated'):        ?><div class="alert alert-success" data-autodismiss>Lista actualizada.</div><?php endif; ?>
<?php if ($msg === 'duplicate'):      ?><div class="alert alert-danger"  data-autodismiss>Ya existe una lista con ese código.</div><?php endif; ?>
<?php if ($msg === 'config_missing'): ?><div class="alert alert-warning" data-autodismiss>Configurá las URLs de las listas antes de importar.</div><?php endif; ?>

<!-- Botón de importación global -->
<div style="display:flex; align-items:center; gap:12px; margin-bottom:20px; flex-wrap:wrap;">
    <a href="<?= BASE_PATH ?>/listas/importar.php"
       class="btn btn-primary <?= empty($listasConUrl) ? 'disabled' : '' ?>"
       <?= empty($listasConUrl) ? 'onclick="return false;" title=\'Configurá al menos una URL en las listas\'' : '' ?>>
        ↓ Importar precios desde proveedor
    </a>
    <span class="text-muted" style="font-size:12px;">
        <?= count($listasConUrl) ?> de <?= count($listas) ?> listas con URL configurada
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

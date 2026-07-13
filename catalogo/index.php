<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
$pageTitle = 'Generar Catálogo';

$db     = getDB();
$listas = $db->query("SELECT * FROM listas ORDER BY margen DESC")->fetchAll();
$marcas = $db->query("
    SELECT DISTINCT p.marca
    FROM productos p
    JOIN lista_precios lp ON lp.producto_id = p.id
    WHERE p.activo = 1 AND p.marca IS NOT NULL AND p.marca != ''
    ORDER BY p.marca COLLATE utf8mb4_unicode_ci ASC
")->fetchAll(PDO::FETCH_COLUMN);

$mPdfInstalado = file_exists(__DIR__ . '/../vendor/autoload.php');

require_once __DIR__ . '/../config/layout.php';
?>

<?php if (!$mPdfInstalado): ?>
<div class="alert alert-warning" style="max-width:620px;">
    <strong>mPDF no está instalado.</strong> Para generar PDFs profesionales instalá mPDF:<br>
    <ol style="margin:10px 0 0 20px; font-size:13px; line-height:2;">
        <li>Descargá <strong>Composer</strong> desde <code>https://getcomposer.org/download/</code> e instalalo.</li>
        <li>Abrí <strong>cmd</strong> y navegá a <code>c:\xampp\htdocs\Attos\</code></li>
        <li>Ejecutá: <code>composer install</code></li>
        <li>Recargá esta página.</li>
    </ol>
    También necesitás correr <code>db/update_v13.sql</code> para el campo <code>mostrar_precio</code>.
</div>
<?php endif; ?>

<div class="card" style="max-width:560px;">
    <div class="card-header"><span class="card-title">Generar catálogo PDF</span></div>
    <div class="card-body">
        <form method="POST" action="<?= BASE_PATH ?>/catalogo/generar.php" target="_blank" id="form-catalogo">

            <div class="form-group">
                <label class="form-label">Lista de precios</label>
                <select name="lista_id" class="form-control" required onchange="onListaChange(this)">
                    <option value="">— Seleccionar —</option>
                    <option value="todas">★ Todas las listas (ZIP con los 4 catálogos)</option>
                    <?php foreach ($listas as $l): ?>
                        <option value="<?= $l['id'] ?>"><?= e($l['codigo']) ?> — <?= $l['margen'] ?>%</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Mostrar precios como</label>
                <select name="modo" class="form-control">
                    <option value="ambos">Precio caja + unidad</option>
                    <option value="caja">Solo precio por caja</option>
                    <option value="unidad">Solo precio por unidad</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Tipo de catálogo</label>
                <div style="display:flex; gap:20px; margin-top:4px;">
                    <label style="display:flex; align-items:center; gap:7px; cursor:pointer; font-size:13px;">
                        <input type="radio" name="tipo" value="completo" checked onchange="toggleFiltro()">
                        Completo
                    </label>
                    <label style="display:flex; align-items:center; gap:7px; cursor:pointer; font-size:13px;">
                        <input type="radio" name="tipo" value="filtrado" onchange="toggleFiltro()">
                        Filtrado / Premium
                    </label>
                </div>
            </div>

            <div class="form-group" id="row-precio-min" style="display:none;">
                <label class="form-label">Precio unitario mínimo</label>
                <input type="number" name="precio_min" value="20000" class="form-control" min="0" step="1000">
                <small class="text-muted" style="font-size:11px; margin-top:4px; display:block;">
                    También incluye todos los productos de categoría "Aceite" sin importar el precio.
                </small>
            </div>

            <div class="form-group">
                <label class="form-label">
                    Marcas a incluir
                    <span class="text-muted" style="font-size:11px; font-weight:400;">(ninguna seleccionada = todas)</span>
                </label>
                <input type="text" id="buscar-marca" class="form-control" placeholder="Buscar bodega…" oninput="filtrarMarcas()" style="margin-bottom:6px;">
                <div id="lista-marcas" style="max-height:180px; overflow-y:auto; border:1px solid var(--border); border-radius:4px; padding:8px 12px; font-size:13px;">
                    <?php foreach ($marcas as $m): ?>
                    <label style="display:flex; align-items:center; gap:8px; padding:3px 0; cursor:pointer;">
                        <input type="checkbox" name="marcas[]" value="<?= e($m) ?>">
                        <?= e($m) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:6px; display:flex; gap:10px;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleMarcas(true)">Todas</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="toggleMarcas(false)">Ninguna</button>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" <?= !$mPdfInstalado ? 'disabled title="Instalá mPDF primero"' : '' ?>>
                    Generar PDF →
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Catálogo Reducido con Fotos ─────────────────────────────────────── -->
<div class="card" style="max-width:560px; margin-top:18px;">
    <div class="card-header"><span class="card-title">Catálogo Reducido con Fotos</span></div>
    <div class="card-body">
        <p style="font-size:13px; color:#666; margin-bottom:16px; line-height:1.6;">
            Generá un catálogo visual premium para un lote de 120–130 productos seleccionados.
            Subís las fotos (<code>CODIGO_nombre.jpg</code>) y el sistema vincula automáticamente
            cada imagen con su precio en la base de datos.
        </p>
        <a href="<?= BASE_PATH ?>/catalogo/reducido.php" class="btn btn-primary">Ir al Catálogo Reducido →</a>
    </div>
</div>

<script>
function onListaChange(sel) {
    const form = document.getElementById('form-catalogo');
    if (sel.value === 'todas') {
        form.action = '<?= BASE_PATH ?>/catalogo/generar_todas.php';
    } else {
        form.action = '<?= BASE_PATH ?>/catalogo/generar.php';
    }
}
function toggleFiltro() {
    const filtrado = document.querySelector('input[name="tipo"][value="filtrado"]').checked;
    document.getElementById('row-precio-min').style.display = filtrado ? '' : 'none';
}
function toggleMarcas(check) {
    document.querySelectorAll('#lista-marcas input[name="marcas[]"]').forEach(cb => cb.checked = check);
}
function filtrarMarcas() {
    const q = document.getElementById('buscar-marca').value.toLowerCase();
    document.querySelectorAll('#lista-marcas label').forEach(label => {
        label.style.display = label.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}
</script>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$db     = getDB();
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$edit   = $id > 0;
$listas = $db->query("SELECT * FROM listas ORDER BY margen DESC")->fetchAll();

if ($edit) {
    $stmt = $db->prepare("SELECT * FROM productos WHERE id = ?");
    $stmt->execute([$id]);
    $producto = $stmt->fetch();
    if (!$producto) { redirect(BASE_PATH . '/productos/'); }

    $preciosStmt = $db->prepare("SELECT lista_id, costo FROM lista_precios WHERE producto_id = ?");
    $preciosStmt->execute([$id]);
    $precios = [];
    foreach ($preciosStmt->fetchAll() as $row) {
        $precios[(int)$row['lista_id']] = (float)$row['costo'];
    }
    $costoBase = !empty($precios) ? reset($precios) : 0.0;
} else {
    $producto  = ['codigo' => '', 'nombre' => '', 'marca' => '', 'unidades_por_caja' => 6, 'precio_por_pack' => 0, 'contenido' => '', 'descripcion' => ''];
    $precios   = [];
    $costoBase = 0.0;
}

$marcas = $db->query("SELECT DISTINCT marca FROM productos WHERE activo=1 AND marca IS NOT NULL AND marca != '' ORDER BY marca ASC")->fetchAll(PDO::FETCH_COLUMN);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codigo            = trim($_POST['codigo']          ?? '');
    $nombre            = trim($_POST['nombre']          ?? '');
    $marca             = trim($_POST['marca_custom'] !== '' ? $_POST['marca_custom'] : ($_POST['marca'] ?? ''));
    $unidades_por_caja = max(1, (int)($_POST['unidades_por_caja'] ?? 1));
    $precio_por_pack   = ($_POST['precio_por_pack'] ?? '0') === '1' ? 1 : 0;
    $contenido         = trim($_POST['contenido']       ?? '');
    $descripcion       = trim($_POST['descripcion']     ?? '');
    $costoBase         = (float)str_replace(',', '.', trim($_POST['costo_base'] ?? '0'));

    if ($nombre === '') $errors[] = 'El nombre es obligatorio.';
    if ($unidades_por_caja < 1) $errors[] = 'Las unidades por caja deben ser al menos 1.';

    if (empty($errors)) {
        if ($edit) {
            $stmt = $db->prepare("UPDATE productos SET codigo=?, nombre=?, marca=?, unidades_por_caja=?, precio_por_pack=?, contenido=?, descripcion=? WHERE id=?");
            $stmt->execute([$codigo, $nombre, $marca, $unidades_por_caja, $precio_por_pack, $contenido, $descripcion, $id]);
        } else {
            $stmt = $db->prepare("INSERT INTO productos (codigo, nombre, marca, unidades_por_caja, precio_por_pack, contenido, descripcion) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$codigo, $nombre, $marca, $unidades_por_caja, $precio_por_pack, $contenido, $descripcion]);
            $id = (int)$db->lastInsertId();
        }

        $stmtLP = $db->prepare("
            INSERT INTO lista_precios (lista_id, producto_id, costo, costo_caja)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE costo=VALUES(costo), costo_caja=VALUES(costo_caja)
        ");
        if ($costoBase > 0) {
            foreach ($listas as $l) {
                $lid = (int)$l['id'];
                $stmtLP->execute([$lid, $id, $costoBase, $costoBase * $unidades_por_caja]);
            }
        }

        redirect(BASE_PATH . '/productos/?msg=' . ($edit ? 'updated' : 'created'));
    }

    $producto = compact('codigo', 'nombre', 'marca', 'unidades_por_caja', 'precio_por_pack', 'contenido', 'descripcion');
}

$pageTitle     = $edit ? 'Editar producto' : 'Nuevo producto';
$topbarActions = '<a href="' . BASE_PATH . '/productos/" class="btn btn-secondary">← Volver</a>';
require_once __DIR__ . '/../config/layout.php';
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="card" style="max-width:680px;">
    <div class="card-header">
        <span class="card-title"><?= $edit ? 'Editar producto' : 'Nuevo producto' ?></span>
    </div>
    <div class="card-body">
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Código</label>
                    <input type="text" name="codigo" class="form-control" value="<?= e($producto['codigo'] ?? '') ?>">
                </div>
                <div class="form-group" style="flex:2;">
                    <label class="form-label">Nombre *</label>
                    <input type="text" name="nombre" class="form-control" value="<?= e($producto['nombre']) ?>" required autofocus>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Marca</label>
                <select name="marca" class="form-control" id="select-marca" onchange="toggleMarcaCustom(this.value)">
                    <option value="">— Nueva marca (escribir abajo) —</option>
                    <?php foreach ($marcas as $m): ?>
                        <option value="<?= e($m) ?>" <?= ($producto['marca'] ?? '') === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="marca_custom" id="marca-custom" class="form-control" style="margin-top:6px;"
                       placeholder="Escribir nueva marca..."
                       value="<?= in_array($producto['marca'] ?? '', $marcas) ? '' : e($producto['marca'] ?? '') ?>">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Unidades por caja *</label>
                    <input type="number" name="unidades_por_caja" class="form-control" min="1" value="<?= (int)($producto['unidades_por_caja'] ?? 6) ?>" required>
                </div>
                <div class="form-group" style="flex:2;">
                    <label class="form-label">Contenido (ej: 750ml, 1L x 12)</label>
                    <input type="text" name="contenido" class="form-control" value="<?= e($producto['contenido'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Tipo de costo cargado</label>
                <div style="display:flex; gap:20px; margin-top:4px;">
                    <label style="display:flex; align-items:center; gap:7px; cursor:pointer; font-size:13px;">
                        <input type="radio" name="precio_por_pack" value="0"
                               <?= !($producto['precio_por_pack'] ?? 0) ? 'checked' : '' ?>>
                        Por unidad (Fernet, vino, aceite…)
                    </label>
                    <label style="display:flex; align-items:center; gap:7px; cursor:pointer; font-size:13px;">
                        <input type="radio" name="precio_por_pack" value="1"
                               <?= ($producto['precio_por_pack'] ?? 0) ? 'checked' : '' ?>>
                        Por pack completo (cerveza, gaseosa, energizante)
                    </label>
                </div>
                <small class="text-muted" style="font-size:11px; margin-top:4px; display:block;">
                    Determina cómo se calcula el precio de caja y unitario en comprobantes y catálogo.
                </small>
            </div>

            <!-- Costo base -->
            <div class="form-group" style="margin-top:8px;">
                <label class="form-label">Costo base</label>
                <input type="text" name="costo_base" id="costo_base" class="form-control"
                       style="max-width:200px;"
                       placeholder="0,00"
                       value="<?= $costoBase > 0 ? number_format($costoBase, 2, ',', '.') : '' ?>"
                       oninput="actualizarPrecios()">
                <small class="text-muted" style="font-size:11px; margin-top:4px; display:block;">
                    El precio de venta se calcula aplicando el margen de cada lista.
                </small>
                <table style="margin-top:10px; width:100%; border-collapse:collapse;">
                    <thead>
                        <tr style="font-size:11px; color:#888; text-transform:uppercase; letter-spacing:0.4px;">
                            <th style="text-align:left; padding:4px 8px; font-weight:600;">Lista</th>
                            <th style="text-align:right; padding:4px 8px; font-weight:600;">Margen</th>
                            <th style="text-align:right; padding:4px 8px; font-weight:600;">Precio venta</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($listas as $l): ?>
                        <tr style="border-top:1px solid #EEE;">
                            <td style="padding:5px 8px; font-size:13px;"><?= e($l['codigo']) ?></td>
                            <td style="padding:5px 8px; font-size:13px; text-align:right; color:#888;"><?= $l['margen'] ?>%</td>
                            <td style="padding:5px 8px; font-size:13px; text-align:right; font-weight:600; color:#631636;" id="prev-<?= $l['id'] ?>">—</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="form-group">
                <label class="form-label">Descripción / notas</label>
                <textarea name="descripcion" class="form-control" rows="2"><?= e($producto['descripcion'] ?? '') ?></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Guardar cambios' : 'Crear producto' ?></button>
                <a href="<?= BASE_PATH ?>/productos/" class="btn btn-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<script>
function toggleMarcaCustom(val) {
    document.getElementById('marca-custom').style.display = val === '' ? '' : 'none';
}
const listasData = <?= json_encode(array_map(fn($l) => ['id' => (int)$l['id'], 'margen' => (float)$l['margen']], $listas)) ?>;
function actualizarPrecios() {
    const raw   = document.getElementById('costo_base').value.replace(',', '.');
    const costo = parseFloat(raw) || 0;
    listasData.forEach(l => {
        const precio = costo > 0 ? costo * (1 + l.margen / 100) : 0;
        const el = document.getElementById('prev-' + l.id);
        if (el) el.textContent = precio > 0
            ? '$' + precio.toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2})
            : '—';
    });
}
document.addEventListener('DOMContentLoaded', () => {
    toggleMarcaCustom(document.getElementById('select-marca').value);
    actualizarPrecios();
});
</script>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

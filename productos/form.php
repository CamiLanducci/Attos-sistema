<?php
require_once __DIR__ . '/../config/db.php';

$db     = getDB();
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$edit   = $id > 0;
$listas = $db->query("SELECT * FROM listas ORDER BY margen DESC")->fetchAll();

if ($edit) {
    $stmt = $db->prepare("SELECT * FROM productos WHERE id = ?");
    $stmt->execute([$id]);
    $producto = $stmt->fetch();
    if (!$producto) { redirect('/attos/productos/'); }

    $preciosStmt = $db->prepare("SELECT lista_id, costo FROM lista_precios WHERE producto_id = ?");
    $preciosStmt->execute([$id]);
    $precios = [];
    foreach ($preciosStmt->fetchAll() as $row) {
        $precios[(int)$row['lista_id']] = (float)$row['costo'];
    }
} else {
    $producto = ['codigo' => '', 'nombre' => '', 'marca' => '', 'unidades_por_caja' => 6, 'precio_por_pack' => 0, 'contenido' => '', 'descripcion' => ''];
    $precios  = [];
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
    $costosPOST        = $_POST['costo'] ?? [];

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
        foreach ($listas as $l) {
            $lid   = (int)$l['id'];
            $raw   = str_replace(',', '.', trim($costosPOST[$lid] ?? '0'));
            $costo = is_numeric($raw) ? (float)$raw : 0.0;
            if ($costo > 0) {
                $stmtLP->execute([$lid, $id, $costo, $costo * $unidades_por_caja]);
            }
        }

        redirect('/attos/productos/?msg=' . ($edit ? 'updated' : 'created'));
    }

    $producto = compact('codigo', 'nombre', 'marca', 'unidades_por_caja', 'precio_por_pack', 'contenido', 'descripcion');
    $precios  = [];
    foreach ($listas as $l) {
        $lid = (int)$l['id'];
        $raw = str_replace(',', '.', trim($costosPOST[$lid] ?? ''));
        if ($raw !== '') $precios[$lid] = (float)$raw;
    }
}

$pageTitle     = $edit ? 'Editar producto' : 'Nuevo producto';
$topbarActions = '<a href="/attos/productos/" class="btn btn-secondary">← Volver</a>';
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

            <!-- Precios por lista -->
            <div class="form-group" style="margin-top:8px;">
                <label class="form-label">Costo por lista</label>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                <?php foreach ($listas as $l): ?>
                    <div style="display:flex; align-items:center; gap:8px; background:#f9f5f0; border-radius:6px; padding:8px 10px;">
                        <span class="badge badge-bordo" style="min-width:36px; text-align:center;"><?= e($l['codigo']) ?></span>
                        <span class="text-muted" style="font-size:11px; white-space:nowrap;"><?= $l['margen'] ?>% mrg</span>
                        <input type="text" name="costo[<?= $l['id'] ?>]" class="form-control"
                               style="flex:1; text-align:right;"
                               placeholder="0,00"
                               value="<?= isset($precios[(int)$l['id']]) ? number_format($precios[(int)$l['id']], 2, ',', '.') : '' ?>">
                    </div>
                <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Descripción / notas</label>
                <textarea name="descripcion" class="form-control" rows="2"><?= e($producto['descripcion'] ?? '') ?></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Guardar cambios' : 'Crear producto' ?></button>
                <a href="/attos/productos/" class="btn btn-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<script>
function toggleMarcaCustom(val) {
    document.getElementById('marca-custom').style.display = val === '' ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', () => {
    toggleMarcaCustom(document.getElementById('select-marca').value);
});
</script>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

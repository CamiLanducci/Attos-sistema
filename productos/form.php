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

    // Back-calcular el costo de compra desde el precio de venta almacenado.
    // Para gaseosa+pack el costo almacenado ya es el costo de compra (sin margen).
    $costoBase = 0.0;
    if (!empty($precios)) {
        $esGaseosaPackEdit = $producto['precio_por_pack'] && esGaseosaOEnergizante($producto['categoria'] ?? '');
        $firstListId = (int)array_key_first($precios);
        $firstCosto  = $precios[$firstListId];
        $firstMargen = 0.0;
        foreach ($listas as $l) {
            if ((int)$l['id'] === $firstListId) { $firstMargen = (float)$l['margen']; break; }
        }
        $costoBase = ($esGaseosaPackEdit || $firstMargen <= 0)
            ? $firstCosto
            : round($firstCosto / (1 + $firstMargen / 100), 4);
    }
} else {
    $producto  = ['codigo' => '', 'nombre' => '', 'marca' => '', 'unidades_por_caja' => 6, 'precio_por_pack' => 0, 'contenido' => '', 'descripcion' => '', 'categoria' => ''];
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
    $categorias_validas = ['', 'Cerveza', 'Gaseosa y Energizante', 'Vino y Espirituosas', 'Otro'];
    $categoria         = in_array($_POST['categoria'] ?? '', $categorias_validas) ? ($_POST['categoria'] ?? '') : '';
    $costoBase         = (float)str_replace(',', '.', trim($_POST['costo_base'] ?? '0'));

    if ($nombre === '') $errors[] = 'El nombre es obligatorio.';
    if ($unidades_por_caja < 1) $errors[] = 'Las unidades por caja deben ser al menos 1.';

    if (empty($errors)) {
        if ($edit) {
            $stmt = $db->prepare("UPDATE productos SET codigo=?, nombre=?, marca=?, unidades_por_caja=?, precio_por_pack=?, contenido=?, descripcion=?, categoria=?, costo_compra=? WHERE id=?");
            $stmt->execute([$codigo, $nombre, $marca, $unidades_por_caja, $precio_por_pack, $contenido, $descripcion, $categoria ?: null, $costoBase > 0 ? $costoBase : null, $id]);
        } else {
            $stmt = $db->prepare("INSERT INTO productos (codigo, nombre, marca, unidades_por_caja, precio_por_pack, contenido, descripcion, categoria, costo_compra) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$codigo, $nombre, $marca, $unidades_por_caja, $precio_por_pack, $contenido, $descripcion, $categoria ?: null, $costoBase > 0 ? $costoBase : null]);
            $id = (int)$db->lastInsertId();
        }

        $stmtLP = $db->prepare("
            INSERT INTO lista_precios (lista_id, producto_id, costo, costo_caja)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE costo=VALUES(costo), costo_caja=VALUES(costo_caja)
        ");
        if ($costoBase > 0) {
            // gaseosa+pack: el costo almacenado es el costo de compra del bulto;
            // calcularPreciosProducto aplica el margen sobre él.
            // Todos los demás: se almacena el precio de venta ya calculado por lista,
            // porque calcularPreciosProducto devuelve el costo almacenado tal cual.
            $esGaseosaPack = $precio_por_pack && esGaseosaOEnergizante($categoria);
            foreach ($listas as $l) {
                $lid    = (int)$l['id'];
                $margen = (float)$l['margen'];
                $costoLista = $esGaseosaPack
                    ? $costoBase
                    : $costoBase * (1 + $margen / 100);
                $stmtLP->execute([$lid, $id, $costoLista, $costoLista * $unidades_por_caja]);
            }
        }

        redirect(BASE_PATH . '/productos/?msg=' . ($edit ? 'updated' : 'created'));
    }

    $producto = compact('codigo', 'nombre', 'marca', 'unidades_por_caja', 'precio_por_pack', 'contenido', 'descripcion', 'categoria');
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
                    <input type="number" name="unidades_por_caja" class="form-control" min="1" value="<?= (int)($producto['unidades_por_caja'] ?? 6) ?>" required oninput="actualizarPrecios()">
                </div>
                <div class="form-group" style="flex:2;">
                    <label class="form-label">Contenido (ej: 750ml, 1L x 12)</label>
                    <input type="text" name="contenido" class="form-control" value="<?= e($producto['contenido'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Categoría</label>
                <select name="categoria" id="sel-categoria" class="form-control" style="max-width:280px;" onchange="actualizarPrecios()">
                    <option value=""         <?= ($producto['categoria'] ?? '') === ''                      ? 'selected' : '' ?>>— Sin categoría —</option>
                    <option value="Cerveza"  <?= ($producto['categoria'] ?? '') === 'Cerveza'               ? 'selected' : '' ?>>🍺 Cerveza</option>
                    <option value="Gaseosa y Energizante" <?= ($producto['categoria'] ?? '') === 'Gaseosa y Energizante' ? 'selected' : '' ?>>🥤 Gaseosa / Energizante</option>
                    <option value="Vino y Espirituosas"   <?= ($producto['categoria'] ?? '') === 'Vino y Espirituosas'   ? 'selected' : '' ?>>🍷 Vino / Espirituosas</option>
                    <option value="Otro"     <?= ($producto['categoria'] ?? '') === 'Otro'                  ? 'selected' : '' ?>>• Otro</option>
                </select>
                <small class="text-muted" style="font-size:11px; margin-top:4px; display:block;">
                    Usada para calcular precios: las cervezas dividen el costo del bulto para obtener el precio unitario.
                </small>
            </div>
            <div class="form-group">
                <label class="form-label">Costo ingresado</label>
                <div style="display:flex; gap:20px; margin-top:4px;">
                    <label style="display:flex; align-items:center; gap:7px; cursor:pointer; font-size:13px;">
                        <input type="radio" name="precio_por_pack" value="0" id="radio-unit"
                               <?= !($producto['precio_por_pack'] ?? 0) ? 'checked' : '' ?>
                               onchange="actualizarPrecios()">
                        Por unidad (Fernet, vino, aceite…)
                    </label>
                    <label style="display:flex; align-items:center; gap:7px; cursor:pointer; font-size:13px;">
                        <input type="radio" name="precio_por_pack" value="1" id="radio-pack"
                               <?= ($producto['precio_por_pack'] ?? 0) ? 'checked' : '' ?>
                               onchange="actualizarPrecios()">
                        Por bulto/pack completo (cerveza, gaseosa…)
                    </label>
                </div>
                <small class="text-muted" style="font-size:11px; margin-top:4px; display:block;">
                    Indica si el costo base ingresado es el precio de una unidad o de un pack/bulto entero.
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
                            <th style="text-align:right; padding:4px 8px; font-weight:600;">Precios</th>
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
const UPC_BASE   = <?= (int)($producto['unidades_por_caja'] ?? 6) ?>;

const MARCAS_CERVEZA_JS = [
    'cerveza',   // cualquier marca que ya tenga "cerveza" en el nombre
    'corona', 'andes', 'grolsch', 'mazbier',
    'warsteiner', 'palermo', 'budweiser', 'amstel',
    'kunstmann', 'patagonia', 'andina', 'porter',
];

function actualizarPrecios() {
    const raw      = document.getElementById('costo_base').value.replace(',', '.');
    const costo    = parseFloat(raw) || 0;
    const cat      = document.getElementById('sel-categoria').value;
    const esPack   = document.querySelector('input[name="precio_por_pack"]:checked')?.value === '1';
    const upc      = parseInt(document.querySelector('input[name="unidades_por_caja"]')?.value) || UPC_BASE;
    const marcaVal = (document.getElementById('select-marca')?.value || document.getElementById('marca-custom')?.value || '').toLowerCase();
    const esCerv   = cat.toLowerCase().includes('cerveza') || MARCAS_CERVEZA_JS.some(m => marcaVal.includes(m));
    const esGas    = cat.toLowerCase().includes('gaseosa') || cat.toLowerCase().includes('energi');

    listasData.forEach(l => {
        const el = document.getElementById('prev-' + l.id);
        if (!el) return;
        if (!costo) { el.textContent = '—'; return; }

        // El costo ingresado es siempre el costo de compra.
        // Para gaseosa+pack calcularPreciosProducto aplica margen sobre el costo almacenado.
        // Para todos los demás se pre-aplica el margen antes de guardar,
        // por eso el preview ya lo muestra con margen.
        const factor = 1 + l.margen / 100;
        let precioUnit, precioCaja;
        if (esCerv || esPack) {
            precioCaja = costo * factor;
            precioUnit = precioCaja / upc;
        } else {
            precioUnit = costo * factor;
            precioCaja = precioUnit * upc;
        }

        el.innerHTML =
            '<span style="color:#888;">ud: </span>' + fmt(precioUnit) +
            ' &nbsp; <span style="color:#631636; font-weight:700;">bulto: ' + fmt(precioCaja) + '</span>';
    });
}
function fmt(n) {
    return '$' + n.toLocaleString('es-AR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
document.addEventListener('DOMContentLoaded', () => {
    toggleMarcaCustom(document.getElementById('select-marca').value);
    actualizarPrecios();
});
</script>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

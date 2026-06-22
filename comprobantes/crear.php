<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$db = getDB();

// ── Modo edición ─────────────────────────────────────────────────────────────
$editId   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editMode = $editId > 0;
$editComp = null;
$editItemsJson = [];

if ($editMode) {
    $stmtComp = $db->prepare("SELECT * FROM comprobantes WHERE id = ?");
    $stmtComp->execute([$editId]);
    $editComp = $stmtComp->fetch();

    if (!$editComp) redirect(BASE_PATH . '/comprobantes/');
    if ($editComp['estado'] !== 'borrador') {
        redirect(BASE_PATH . '/comprobantes/ver.php?id=' . $editId . '&msg=not_borrador');
    }

    $stmtItems = $db->prepare("
        SELECT producto_id, cantidad_cajas, cantidad_unidades,
               descuento_tipo, descuento_valor, nombre_producto
        FROM comprobante_items WHERE comprobante_id = ? ORDER BY id ASC
    ");
    $stmtItems->execute([$editId]);
    foreach ($stmtItems->fetchAll() as $it) {
        $editItemsJson[] = [
            'producto_id'       => (int)$it['producto_id'],
            'cantidad_cajas'    => (int)$it['cantidad_cajas'],
            'cantidad_unidades' => (int)$it['cantidad_unidades'],
            'descuento_tipo'    => $it['descuento_tipo'],
            'descuento_valor'   => (float)$it['descuento_valor'],
            'nombre_producto'   => $it['nombre_producto'],
        ];
    }
}

$pageTitle     = $editMode ? 'Editar comprobante #' . $editComp['numero'] : 'Nuevo comprobante';
$topbarActions = $editMode
    ? '<a href="' . BASE_PATH . '/comprobantes/ver.php?id=' . $editId . '" class="btn btn-secondary">← Volver</a>'
    : '<a href="' . BASE_PATH . '/comprobantes/" class="btn btn-secondary">← Volver</a>';

// ── Datos del form ────────────────────────────────────────────────────────────
$lastNum = $db->query("SELECT MAX(numero) FROM comprobantes")->fetchColumn();
$nextNum = ($lastNum ?? 0) + 1;

$clientes = $db->query("SELECT id, nombre, lista_id FROM clientes WHERE activo=1 ORDER BY nombre ASC")->fetchAll();
$listas   = $db->query("SELECT * FROM listas ORDER BY margen DESC")->fetchAll();

$margenPorLista = [];
foreach ($listas as $l) {
    $margenPorLista[(int)$l['id']] = (float)$l['margen'];
}

$todosProductos = $db->query("
    SELECT p.id, p.nombre, p.marca, p.codigo, p.unidades_por_caja, p.precio_por_pack,
           p.contenido, COALESCE(p.categoria,'') AS categoria,
           lp.costo, lp.lista_id
    FROM productos p
    JOIN lista_precios lp ON lp.producto_id = p.id
    WHERE p.activo = 1
    ORDER BY p.marca ASC, p.nombre ASC
")->fetchAll();

$productosPorLista = [];
foreach ($todosProductos as $p) {
    $listaId = (int)$p['lista_id'];
    $precios = calcularPreciosProducto(
        (float)$p['costo'],
        $margenPorLista[$listaId] ?? 0.0,
        (int)$p['unidades_por_caja'],
        (int)($p['precio_por_pack'] ?? 0),
        $p['categoria'],
        $p['marca'] ?? ''
    );
    $productosPorLista[$listaId][] = [
        'id'          => (int)$p['id'],
        'nombre'      => $p['nombre'],
        'marca'       => $p['marca'] ?? '',
        'codigo'      => $p['codigo'] ?? '',
        'upc'         => max(1, (int)$p['unidades_por_caja']),
        'contenido'   => $p['contenido'] ?? '',
        'precio_unit' => round($precios['precio_unit'], 2),
        'precio_caja' => round($precios['precio_caja'], 2),
    ];
}

require_once __DIR__ . '/../config/layout.php';
?>

<form method="POST" action="<?= BASE_PATH ?>/comprobantes/actions.php" id="form-comp" onsubmit="return validarForm()">
<input type="hidden" name="action" value="<?= $editMode ? 'update' : 'create' ?>">
<?php if ($editMode): ?>
<input type="hidden" name="id" value="<?= $editId ?>">
<?php endif; ?>

<div class="d-flex gap-2" style="align-items:flex-start;">

    <!-- Panel izquierdo -->
    <div style="flex:2;">
        <div class="card">
            <div class="card-header"><span class="card-title">Datos del comprobante</span></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Número</label>
                        <input type="number" name="numero" class="form-control"
                               value="<?= $editMode ? (int)$editComp['numero'] : $nextNum ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fecha</label>
                        <input type="date" name="fecha" class="form-control"
                               value="<?= $editMode ? e($editComp['fecha']) : date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Estado</label>
                        <select name="estado" id="sel-estado" class="form-control">
                            <option value="borrador" <?= $editMode ? 'selected' : '' ?>>Borrador</option>
                            <option value="emitido"  <?= !$editMode ? 'selected' : '' ?>>Emitido</option>
                            <option value="cobrado">Cobrado</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" style="flex:2;">
                        <label class="form-label">Cliente *</label>
                        <div class="prod-wrap">
                            <input type="text" id="input-cliente" class="form-control"
                                   placeholder="Buscar cliente por nombre…"
                                   autocomplete="off"
                                   oninput="buscarCliente(this.value)"
                                   onkeydown="navClienteDropdown(event)"
                                   onblur="cerrarClienteDropdown()">
                            <div id="dropdown-cliente" class="prod-dropdown" style="display:none;"></div>
                            <input type="hidden" name="cliente_id" id="sel-cliente"
                                   value="<?= $editMode ? (int)$editComp['cliente_id'] : '' ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Lista de precios *</label>
                        <select name="lista_id" id="sel-lista" class="form-control" required onchange="actualizarPrecios()">
                            <option value="">— Seleccionar lista —</option>
                            <?php foreach ($listas as $l): ?>
                                <option value="<?= $l['id'] ?>"
                                    <?= ($editMode && (int)$l['id'] === (int)$editComp['lista_id']) ? 'selected' : '' ?>>
                                    <?= e($l['codigo']) ?> — <?= $l['margen'] ?>%
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Notas / observaciones</label>
                    <textarea name="notas" class="form-control" rows="2"><?= $editMode ? e($editComp['notas'] ?? '') : '' ?></textarea>
                </div>
            </div>
        </div>

        <!-- Items -->
        <div class="card" style="margin-top:16px;">
            <div class="card-header">
                <span class="card-title">Productos</span>
                <button type="button" class="btn btn-sm btn-outline" onclick="agregarItem()">+ Agregar producto</button>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="table-wrap" style="overflow: visible;">
                    <table id="tabla-items">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th style="width:95px;">Cajas</th>
                                <th style="width:95px;">Unid.</th>
                                <th style="width:110px;">Precio/ud</th>
                                <th style="width:110px;">Precio/caja</th>
                                <th style="width:110px;">Subtotal</th>
                                <th style="width:160px;">Bonificación</th>
                                <th style="width:38px;"></th>
                            </tr>
                        </thead>
                        <tbody id="items-body">
                            <tr id="row-empty">
                                <td colspan="8" class="text-center text-muted" style="padding:20px;">
                                    Seleccioná una lista y luego agregá productos.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Panel derecho -->
    <div style="flex:1; min-width:220px;">
        <div class="card">
            <div class="card-header"><span class="card-title">Resumen</span></div>
            <div class="card-body">

                <div class="form-group" style="margin-bottom:14px;">
                    <label class="form-label">Entrega</label>
                    <div style="display:flex; gap:8px;">
                        <label style="flex:1; display:flex; align-items:center; gap:6px; padding:7px 10px;
                                       border:1px solid var(--border); border-radius:var(--radius); cursor:pointer;
                                       font-size:13px;" id="lbl-envio">
                            <input type="radio" name="tipo_entrega" value="envio"
                                   <?= (!$editMode || ($editComp['tipo_entrega'] ?? 'envio') === 'envio') ? 'checked' : '' ?>
                                   onchange="toggleEntrega('envio')"> 🚚 Envío
                        </label>
                        <label style="flex:1; display:flex; align-items:center; gap:6px; padding:7px 10px;
                                       border:1px solid var(--border); border-radius:var(--radius); cursor:pointer;
                                       font-size:13px;" id="lbl-retira">
                            <input type="radio" name="tipo_entrega" value="retira"
                                   <?= ($editMode && ($editComp['tipo_entrega'] ?? '') === 'retira') ? 'checked' : '' ?>
                                   onchange="toggleEntrega('retira')"> 🏪 Retira
                        </label>
                    </div>
                </div>

                <div class="d-flex justify-between mb-1">
                    <span style="color:var(--bordo); font-size:13px;">Total bultos</span>
                    <span id="display-total-cajas" class="fw-bold text-bordo">0</span>
                </div>
                <div class="d-flex justify-between mb-1">
                    <span style="color:var(--bordo); font-size:13px;">Total unidades sueltas</span>
                    <span id="display-total-unidades" class="fw-bold text-bordo">0</span>
                </div>
                <hr style="border:none;border-top:1px solid var(--border);margin:8px 0;">
                <div class="d-flex justify-between mb-1">
                    <span class="text-muted">Subtotal</span>
                    <span id="display-subtotal" class="fw-bold">$0,00</span>
                </div>
                <div class="d-flex justify-between mb-1" id="row-envio">
                    <label class="text-muted" for="envio">Costo envío</label>
                    <input type="number" name="envio" id="envio" class="form-control"
                           style="width:100px; text-align:right;"
                           value="<?= $editMode ? (float)$editComp['envio'] : 0 ?>"
                           step="0.01" min="0" oninput="recalcularTotal()">
                </div>
                <div class="d-flex justify-between mb-1" id="row-descuento" style="display:none;">
                    <span class="text-muted">Bonificación</span>
                    <span id="display-descuento" class="fw-bold text-bordo">−$0,00</span>
                </div>
                <hr style="border:none;border-top:1px solid var(--border);margin:12px 0;">
                <div class="d-flex justify-between">
                    <span class="fw-bold">TOTAL</span>
                    <span id="display-total" class="fw-bold text-bordo" style="font-size:18px;">$0,00</span>
                </div>
                <input type="hidden" name="subtotal"  id="hidden-subtotal"  value="0">
                <input type="hidden" name="descuento" id="hidden-descuento" value="0">
                <input type="hidden" name="total"     id="hidden-total"     value="0">
                <div style="margin-top:20px;">
                    <button type="submit" class="btn btn-primary w-100">
                        <?= $editMode ? 'Guardar cambios' : 'Guardar comprobante' ?>
                    </button>
                    <?php if ($editMode): ?>
                    <button type="button" class="btn btn-secondary w-100" onclick="emitirYGuardar()"
                            style="margin-top:8px;">
                        Guardar y emitir
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>
</form>

<style>
.prod-wrap { position: relative; }
.prod-dropdown {
    position: absolute; top: 100%; left: 0; right: 0; z-index: 999;
    background: #fff; border: 1px solid #ddd0c4; border-top: none;
    border-radius: 0 0 6px 6px; max-height: 260px; overflow-y: auto;
    box-shadow: 0 4px 14px rgba(0,0,0,.13);
}
.prod-option {
    padding: 8px 12px; cursor: pointer; font-size: 13px;
    border-bottom: 1px solid #f0ece6; display: flex; gap: 8px; align-items: baseline;
}
.prod-option:last-child { border-bottom: none; }
.prod-option:hover, .prod-option.dd-active { background: #f4ede3; }
.opt-cod  { font-size: 10px; color: #999; font-family: monospace; min-width: 50px; flex-shrink: 0; }
.opt-nom  { font-weight: 600; flex: 1; }
.opt-cont { font-size: 11px; color: #bbb; flex-shrink: 0; }
.row-unavailable { background: #fff8f0; }
</style>

<script>
const PRODUCTOS_POR_LISTA = <?= json_encode($productosPorLista) ?>;
const EDIT_MODE  = <?= $editMode ? 'true' : 'false' ?>;
const EDIT_ITEMS = <?= json_encode($editItemsJson) ?>;
const CLIENTES   = <?= json_encode(array_map(fn($c) => [
    'id'       => (int)$c['id'],
    'nombre'   => $c['nombre'],
    'lista_id' => (int)($c['lista_id'] ?? 0),
], $clientes)) ?>;

let PRODUCTOS = [];
let PROD_MAP  = {};

function cargarProductosDeLista(listaId) {
    PRODUCTOS = PRODUCTOS_POR_LISTA[listaId] || [];
    PROD_MAP  = {};
    PRODUCTOS.forEach(p => { PROD_MAP[p.id] = p; });
}

let itemCount = 0;
const ddCache = {};

/* ── Template de fila ──────────────────────────────────────────────────────── */
function buildRowHTML(idx) {
    return `
        <td>
            <div class="prod-wrap">
                <input type="text" class="form-control prod-search" placeholder="Código o nombre…"
                       oninput="buscarProducto(${idx}, this.value)"
                       onkeydown="navDropdown(event, ${idx})"
                       onblur="cerrarDropdown(${idx})"
                       autocomplete="off" style="min-width:180px;">
                <div class="prod-dropdown" id="dropdown-${idx}" style="display:none;"></div>
                <input type="hidden" name="items[${idx}][producto_id]" class="prod-id" value="">
                <div class="upc-display" style="font-size:11px; color:#999; margin-top:2px; display:none;"></div>
            </div>
        </td>
        <td><input type="number" name="items[${idx}][cantidad_cajas]" class="form-control cant-cajas"
                   min="0" value="1" oninput="calcularFila(${idx})" style="width:100%; padding:5px 6px;"></td>
        <td><input type="number" name="items[${idx}][cantidad_unidades]" class="form-control cant-unidades"
                   min="0" value="0" oninput="calcularFila(${idx})" style="width:100%; padding:5px 6px;"></td>
        <td><span class="precio-unit price-display">—</span></td>
        <td><span class="precio-caja price-display">—</span></td>
        <td>
            <span class="subtotal-item price-display">—</span>
            <input type="hidden" name="items[${idx}][subtotal]"        class="hidden-subtotal"    value="0">
            <input type="hidden" name="items[${idx}][precio_unitario]" class="hidden-precio-unit" value="0">
        </td>
        <td>
            <div style="display:flex; gap:4px; align-items:center;">
                <select class="form-control desc-tipo" name="items[${idx}][descuento_tipo]"
                        onchange="toggleDescuento(${idx})" style="width:70px; font-size:12px; padding:4px 6px;">
                    <option value="ninguno">—</option>
                    <option value="porcentaje">%</option>
                    <option value="fijo">$</option>
                </select>
                <input type="number" class="form-control desc-valor" name="items[${idx}][descuento_valor]"
                       min="0" step="0.01" value="0"
                       oninput="calcularFila(${idx})"
                       style="width:70px; display:none; font-size:12px;">
            </div>
            <div class="desc-display text-bordo" style="font-size:12px; margin-top:2px; display:none;"></div>
            <input type="hidden" name="items[${idx}][descuento_monto]" class="hidden-desc-monto" value="0">
        </td>
        <td><button type="button" class="btn btn-sm btn-danger"
                    onclick="eliminarItem(${idx})" style="padding:2px 8px;">×</button></td>
    `;
}

/* ── Agregar fila ──────────────────────────────────────────────────────────── */
function agregarItem() {
    const listaId = parseInt(document.getElementById('sel-lista').value) || 0;
    if (!listaId) { alert('Seleccioná primero la lista de precios.'); return; }
    cargarProductosDeLista(listaId);
    document.getElementById('row-empty')?.remove();

    const idx = itemCount++;
    const tr  = document.createElement('tr');
    tr.id = `item-row-${idx}`;
    tr.dataset.idx = idx;
    tr.innerHTML = buildRowHTML(idx);
    document.getElementById('items-body').appendChild(tr);
    tr.querySelector('.prod-search').focus();
}

/* ── Pre-cargar item existente (modo edición) ──────────────────────────────── */
function precargarItem(item) {
    document.getElementById('row-empty')?.remove();
    const idx = itemCount++;
    const tr  = document.createElement('tr');
    tr.id = `item-row-${idx}`;
    tr.dataset.idx = idx;

    const p = PROD_MAP[item.producto_id];

    if (!p) {
        tr.dataset.unavailable = '1';
        tr.className = 'row-unavailable';
        tr.innerHTML = `
            <td colspan="6" style="font-size:12px; padding:10px 12px;">
                <span style="color:var(--bordo);">⚠</span>
                <em>${escHtml(item.nombre_producto)}</em>
                <span class="text-muted"> — no disponible en lista actual</span>
            </td>
            <td></td>
            <td><button type="button" class="btn btn-sm btn-danger"
                        onclick="eliminarItem(${idx})" style="padding:2px 8px;">×</button></td>
        `;
        document.getElementById('items-body').appendChild(tr);
        return;
    }

    tr.innerHTML = buildRowHTML(idx);
    document.getElementById('items-body').appendChild(tr);

    tr.querySelector('.prod-search').value =
        (p.codigo ? p.codigo + ' — ' : '') + p.nombre + (p.contenido ? ' (' + p.contenido + ')' : '');
    tr.querySelector('.prod-id').value = item.producto_id;
    const upcEl = tr.querySelector('.upc-display');
    if (upcEl) { upcEl.textContent = p.upc + ' ud/caja'; upcEl.style.display = ''; }
    tr.querySelector('.cant-cajas').value   = item.cantidad_cajas;
    tr.querySelector('.cant-unidades').value = item.cantidad_unidades;

    if (item.descuento_tipo && item.descuento_tipo !== 'ninguno') {
        tr.querySelector('.desc-tipo').value = item.descuento_tipo;
        const dv = tr.querySelector('.desc-valor');
        dv.value = item.descuento_valor;
        dv.style.display = '';
    }

    calcularFila(idx);
}

/* ── Búsqueda de clientes ──────────────────────────────────────────────────── */
let _clDdCache = [];

function buscarCliente(query) {
    const dd = document.getElementById('dropdown-cliente');
    document.getElementById('sel-cliente').value = '';
    query = query.trim().toLowerCase();
    if (!query) { dd.style.display = 'none'; return; }
    const matches = CLIENTES.filter(c => c.nombre.toLowerCase().includes(query)).slice(0, 12);
    _clDdCache = matches;
    if (!matches.length) { dd.style.display = 'none'; return; }
    dd.innerHTML = matches.map((c, i) => `
        <div class="prod-option" data-i="${i}" onmousedown="seleccionarCliente(${c.id})">
            <span class="opt-nom">${escHtml(c.nombre)}</span>
        </div>`).join('');
    dd.style.display = 'block';
}

function seleccionarCliente(clienteId) {
    const c = CLIENTES.find(c => c.id === clienteId);
    if (!c) return;
    document.getElementById('input-cliente').value = c.nombre;
    document.getElementById('sel-cliente').value   = clienteId;
    document.getElementById('dropdown-cliente').style.display = 'none';
    const selLista = document.getElementById('sel-lista');
    if (c.lista_id && !selLista.value) {
        selLista.value = c.lista_id;
        actualizarPrecios();
    }
}

function navClienteDropdown(e) {
    const dd   = document.getElementById('dropdown-cliente');
    if (dd.style.display === 'none') return;
    const opts = dd.querySelectorAll('.prod-option');
    const cur  = dd.querySelector('.prod-option.dd-active');
    let   ci   = cur ? parseInt(cur.dataset.i) : -1;
    if (e.key === 'ArrowDown')       { e.preventDefault(); ci = Math.min(ci + 1, opts.length - 1); }
    else if (e.key === 'ArrowUp')    { e.preventDefault(); ci = Math.max(ci - 1, 0); }
    else if (e.key === 'Enter' && cur) {
        e.preventDefault();
        if (_clDdCache[ci]) seleccionarCliente(_clDdCache[ci].id);
        return;
    } else if (e.key === 'Escape') { dd.style.display = 'none'; return; }
    else return;
    opts.forEach(o => o.classList.remove('dd-active'));
    if (opts[ci]) opts[ci].classList.add('dd-active');
}

function cerrarClienteDropdown() {
    setTimeout(() => {
        const dd = document.getElementById('dropdown-cliente');
        if (dd) dd.style.display = 'none';
    }, 160);
}

/* ── Búsqueda typeahead ────────────────────────────────────────────────────── */
function buscarProducto(idx, query) {
    const dd = document.getElementById(`dropdown-${idx}`);
    query = query.trim().toLowerCase();
    if (!query) {
        const tr = document.getElementById(`item-row-${idx}`);
        if (tr) tr.querySelector('.prod-id').value = '';
        dd.style.display = 'none';
        return;
    }
    const matches = PRODUCTOS.filter(p =>
        p.nombre.toLowerCase().includes(query) ||
        (p.codigo && p.codigo.toLowerCase().includes(query))
    ).slice(0, 10);
    ddCache[idx] = matches;
    if (!matches.length) { dd.style.display = 'none'; return; }
    dd.innerHTML = matches.map((p, i) => `
        <div class="prod-option" data-i="${i}"
             onmousedown="seleccionarProducto(${idx}, ${p.id})">
            ${p.codigo ? `<span class="opt-cod">${escHtml(p.codigo)}</span>` : ''}
            <span class="opt-nom">${escHtml(p.nombre)}</span>
            ${p.contenido ? `<span class="opt-cont">${escHtml(p.contenido)}</span>` : ''}
        </div>`).join('');
    dd.style.display = 'block';
}

function navDropdown(e, idx) {
    const dd   = document.getElementById(`dropdown-${idx}`);
    if (dd.style.display === 'none') return;
    const opts = dd.querySelectorAll('.prod-option');
    const cur  = dd.querySelector('.prod-option.dd-active');
    let   ci   = cur ? parseInt(cur.dataset.i) : -1;
    if (e.key === 'ArrowDown')  { e.preventDefault(); ci = Math.min(ci + 1, opts.length - 1); }
    else if (e.key === 'ArrowUp')   { e.preventDefault(); ci = Math.max(ci - 1, 0); }
    else if (e.key === 'Enter' && cur) {
        e.preventDefault();
        const m = ddCache[idx] || [];
        if (m[ci]) seleccionarProducto(idx, m[ci].id);
        return;
    } else if (e.key === 'Escape') { dd.style.display = 'none'; return; }
    else return;
    opts.forEach(o => o.classList.remove('dd-active'));
    if (opts[ci]) opts[ci].classList.add('dd-active');
}

function cerrarDropdown(idx) {
    setTimeout(() => {
        const dd = document.getElementById(`dropdown-${idx}`);
        if (dd) dd.style.display = 'none';
    }, 160);
}

function seleccionarProducto(idx, prodId) {
    const p  = PROD_MAP[prodId];
    if (!p) return;
    const tr = document.getElementById(`item-row-${idx}`);
    tr.querySelector('.prod-search').value =
        (p.codigo ? p.codigo + ' — ' : '') + p.nombre + (p.contenido ? ' (' + p.contenido + ')' : '');
    tr.querySelector('.prod-id').value = prodId;
    const upcEl = tr.querySelector('.upc-display');
    if (upcEl) { upcEl.textContent = p.upc + ' ud/caja'; upcEl.style.display = ''; }
    document.getElementById(`dropdown-${idx}`).style.display = 'none';
    calcularFila(idx);
    const cajasInput = tr.querySelector('.cant-cajas');
    cajasInput.focus();
    cajasInput.select();
}

/* ── Cálculo ───────────────────────────────────────────────────────────────── */
function calcularFila(idx) {
    const tr = document.getElementById(`item-row-${idx}`);
    if (!tr || tr.dataset.unavailable) return;
    const prodId = parseInt(tr.querySelector('.prod-id').value) || 0;
    if (!prodId) return;
    const p = PROD_MAP[prodId];

    if (!p) {
        // Producto no disponible en lista actual — limpiar precios
        tr.querySelector('.precio-unit').textContent   = '—';
        tr.querySelector('.precio-caja').textContent   = '—';
        tr.querySelector('.subtotal-item').textContent = '—';
        tr.querySelector('.hidden-subtotal').value    = '0';
        tr.querySelector('.hidden-precio-unit').value = '0';
        tr.querySelector('.hidden-desc-monto').value  = '0';
        recalcularTotal();
        return;
    }

    const cajas    = parseInt(tr.querySelector('.cant-cajas').value)    || 0;
    const unidades = parseInt(tr.querySelector('.cant-unidades').value) || 0;

    const precioUnit    = p.precio_unit;
    const precioCaja    = p.precio_caja;
    const subtotalBruto = precioCaja * cajas + precioUnit * unidades;

    const dTipo  = tr.querySelector('.desc-tipo').value;
    const dValor = parseFloat(tr.querySelector('.desc-valor').value) || 0;
    let descMonto = 0;
    if (dTipo === 'porcentaje' && dValor > 0)
        descMonto = subtotalBruto * Math.min(dValor, 100) / 100;
    else if (dTipo === 'fijo' && dValor > 0)
        descMonto = Math.min(dValor, subtotalBruto);

    tr.querySelector('.precio-unit').textContent   = formatPeso(precioUnit);
    tr.querySelector('.precio-caja').textContent   = formatPeso(precioCaja);
    tr.querySelector('.subtotal-item').textContent = formatPeso(subtotalBruto);

    const descEl = tr.querySelector('.desc-display');
    if (descMonto > 0) {
        descEl.textContent   = '−' + formatPeso(descMonto);
        descEl.style.display = '';
    } else {
        descEl.style.display = 'none';
    }

    tr.querySelector('.hidden-subtotal').value    = subtotalBruto.toFixed(2);
    tr.querySelector('.hidden-precio-unit').value = precioUnit.toFixed(2);
    tr.querySelector('.hidden-desc-monto').value  = descMonto.toFixed(2);

    recalcularTotal();
}

function toggleDescuento(idx) {
    const tr    = document.getElementById(`item-row-${idx}`);
    const tipo  = tr.querySelector('.desc-tipo').value;
    const input = tr.querySelector('.desc-valor');
    if (tipo === 'ninguno') { input.style.display = 'none'; input.value = 0; }
    else { input.style.display = ''; input.focus(); }
    calcularFila(idx);
}

function actualizarPrecios() {
    const listaId = parseInt(document.getElementById('sel-lista').value) || 0;
    cargarProductosDeLista(listaId);
    document.querySelectorAll('#items-body tr[id^="item-row-"]').forEach(tr => {
        if (!tr.dataset.unavailable) calcularFila(parseInt(tr.dataset.idx));
    });
}

function recalcularTotal() {
    let sub  = 0;
    let desc = 0;
    let totalCajas    = 0;
    let totalUnidades = 0;
    document.querySelectorAll('.hidden-subtotal').forEach(inp => sub  += parseFloat(inp.value) || 0);
    document.querySelectorAll('.hidden-desc-monto').forEach(inp => desc += parseFloat(inp.value) || 0);
    document.querySelectorAll('#items-body tr[id^="item-row-"]:not([data-unavailable]) .cant-cajas').forEach(inp => totalCajas    += parseInt(inp.value) || 0);
    document.querySelectorAll('#items-body tr[id^="item-row-"]:not([data-unavailable]) .cant-unidades').forEach(inp => totalUnidades += parseInt(inp.value) || 0);
    const envio = parseFloat(document.getElementById('envio').value) || 0;
    const total = sub + envio - desc;

    document.getElementById('display-subtotal').textContent      = formatPeso(sub);
    document.getElementById('display-total').textContent         = formatPeso(total);
    document.getElementById('display-total-cajas').textContent   = totalCajas;
    document.getElementById('display-total-unidades').textContent = totalUnidades;
    document.getElementById('hidden-subtotal').value  = sub.toFixed(2);
    document.getElementById('hidden-descuento').value = desc.toFixed(2);
    document.getElementById('hidden-total').value     = total.toFixed(2);

    const rowDesc     = document.getElementById('row-descuento');
    const displayDesc = document.getElementById('display-descuento');
    if (desc > 0) {
        displayDesc.textContent = '−' + formatPeso(desc);
        rowDesc.style.display   = '';
    } else {
        rowDesc.style.display = 'none';
    }
}

function eliminarItem(idx) {
    document.getElementById(`item-row-${idx}`)?.remove();
    recalcularTotal();
    if (!document.querySelector('#items-body tr[id^="item-row-"]')) {
        const tr = document.createElement('tr');
        tr.id = 'row-empty';
        tr.innerHTML = '<td colspan="8" class="text-center text-muted" style="padding:20px;">Seleccioná una lista y luego agregá productos.</td>';
        document.getElementById('items-body').appendChild(tr);
    }
}

function validarForm() {
    if (!document.getElementById('sel-cliente').value) {
        alert('Seleccioná un cliente.'); return false;
    }
    const rows = document.querySelectorAll('#items-body tr[id^="item-row-"]');
    let validRows = 0;
    for (const tr of rows) {
        if (tr.dataset.unavailable) continue;
        validRows++;
        if (!tr.querySelector('.prod-id').value) {
            alert('Hay una fila sin producto seleccionado. Buscá y elegí el producto del listado.'); return false;
        }
        const cajas = parseInt(tr.querySelector('.cant-cajas').value) || 0;
        const uds   = parseInt(tr.querySelector('.cant-unidades').value) || 0;
        if (cajas <= 0 && uds <= 0) {
            alert('Cada producto debe tener al menos 1 caja o 1 unidad.'); return false;
        }
    }
    if (!validRows) { alert('Agregá al menos un producto.'); return false; }
    return true;
}

function emitirYGuardar() {
    document.getElementById('sel-estado').value = 'emitido';
    if (validarForm()) document.getElementById('form-comp').submit();
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function toggleEntrega(tipo) {
    const rowEnvio   = document.getElementById('row-envio');
    const inputEnvio = document.getElementById('envio');
    const lblEnvio   = document.getElementById('lbl-envio');
    const lblRetira  = document.getElementById('lbl-retira');
    if (tipo === 'retira') {
        rowEnvio.style.display = 'none';
        inputEnvio.value = 0;
        lblRetira.style.borderColor = 'var(--bordo)';
        lblRetira.style.background  = '#f4e0e8';
        lblEnvio.style.borderColor  = 'var(--border)';
        lblEnvio.style.background   = '';
    } else {
        rowEnvio.style.display = '';
        lblEnvio.style.borderColor  = 'var(--bordo)';
        lblEnvio.style.background   = '#f4e0e8';
        lblRetira.style.borderColor = 'var(--border)';
        lblRetira.style.background  = '';
    }
    recalcularTotal();
}

document.addEventListener('DOMContentLoaded', () => {
    const tipoEntrega = document.querySelector('input[name="tipo_entrega"]:checked')?.value || 'envio';
    toggleEntrega(tipoEntrega);

    if (EDIT_MODE) {
        const editClienteId = <?= $editMode ? (int)$editComp['cliente_id'] : 0 ?>;
        if (editClienteId) {
            const c = CLIENTES.find(c => c.id === editClienteId);
            if (c) document.getElementById('input-cliente').value = c.nombre;
        }
        if (EDIT_ITEMS.length > 0) {
            const listaId = parseInt(document.getElementById('sel-lista').value) || 0;
            if (listaId) {
                cargarProductosDeLista(listaId);
                EDIT_ITEMS.forEach(item => precargarItem(item));
            }
        }
    }
});
</script>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

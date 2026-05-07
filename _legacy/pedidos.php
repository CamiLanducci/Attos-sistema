<?php
$conexion = new mysqli("localhost", "root", "", "attos");

if ($conexion->connect_error) {
    die("Error de conexión");
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $cliente  = $_POST["cliente"];
    $lista_id = (int)$_POST["lista_id"];
    $total    = (float)$_POST["total_general"];

    $stmt = $conexion->prepare("INSERT INTO pedidos (cliente, lista_id, total) VALUES (?, ?, ?)");
    $stmt->bind_param("sid", $cliente, $lista_id, $total);
    $stmt->execute();
    $stmt->close();

    $pedido_id = $conexion->insert_id;

    if (isset($_POST["productos"])) {
        foreach ($_POST["productos"] as $id => $cantidad) {
            $cantidad = (int)$cantidad;

            if ($cantidad > 0) {
                $id = (int)$id;

                $p = $conexion->query("SELECT * FROM productos WHERE id = $id")->fetch_assoc();

                if ($p) {
                    $precio = (float)$p["costo"];
                    $total_item = $precio * $cantidad;

                    $conexion->query("
                        INSERT INTO pedido_items 
                        (pedido_id, producto_id, cantidad, precio_unitario, total)
                        VALUES ($pedido_id, $id, $cantidad, $precio, $total_item)
                    ");
                }
            }
        }
    }

    echo "<h2>Pedido guardado</h2>";
    echo "<a href='pedidos.php'>Nuevo pedido</a>";
    exit;
}

$clientes = $conexion->query("SELECT id, nombre, lista_id FROM clientes");

$lista_id = isset($_GET['lista_id']) ? (int)$_GET['lista_id'] : 0;

$productos_js = [];

if ($lista_id > 0) {
    $productos = $conexion->query("
        SELECT * FROM productos 
        WHERE lista_id = $lista_id AND activo = 1
        ORDER BY nombre
        LIMIT 500
    ");

    while ($p = $productos->fetch_assoc()) {
        $productos_js[] = $p;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Pedidos</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        table { width:100%; border-collapse: collapse; }
        th, td { border:1px solid #ccc; padding:6px; }
        th { background:#333; color:white; }
        input[type="number"] { width:70px; }
        input[type="text"] { padding: 6px; }
        .resaltado { background: #d4edda; }
    </style>
</head>
<body>

<h2>Nuevo Pedido</h2>

<form method="POST">

Cliente:
<select name="cliente" id="clienteSelect" onchange="cargarLista()" required>
    <option value="">Seleccionar</option>
    <?php while ($c = $clientes->fetch_assoc()): ?>
        <option value="<?= htmlspecialchars($c['nombre']) ?>" data-lista="<?= (int)$c['lista_id'] ?>">
            <?= htmlspecialchars($c['nombre']) ?>
        </option>
    <?php endwhile; ?>
</select>

<input type="hidden" name="lista_id" id="lista_id" value="<?= $lista_id ?>">

<br><br>

<?php if ($lista_id > 0): ?>

<h3>Cargar por código</h3>
<input type="text" id="codigoInput" placeholder="Ingresar código y Enter" onkeypress="agregarCodigo(event)">

<br><br>

<table id="tabla">
    <tr>
        <th>Código</th>
        <th>Producto</th>
        <th>Cajas</th>
        <th>Unid.</th>
        <th>Precio</th>
        <th>Cantidad</th>
        <th>Total</th>
    </tr>
</table>

<h3>Total: $<span id="total">0.00</span></h3>
<input type="hidden" name="total_general" id="totalInput" value="0">

<button type="submit">Guardar Pedido</button>

<?php endif; ?>

</form>

<script>
let productos = <?= json_encode($productos_js, JSON_UNESCAPED_UNICODE) ?>;

function cargarLista() {
    let select = document.getElementById("clienteSelect");
    let listaId = select.options[select.selectedIndex].dataset.lista || "";
    document.getElementById("lista_id").value = listaId;

    if (listaId) {
        window.location.href = "pedidos.php?lista_id=" + listaId;
    }
}

function calc(input) {
    let row = input.closest('tr');
    let precio = parseFloat(row.children[4].innerText.replace('$','')) || 0;
    let cant = parseInt(input.value) || 0;

    let total = precio * cant;
    row.querySelector('.total').innerText = '$' + total.toFixed(2);

    recalcular();
}

function recalcular() {
    let totales = document.querySelectorAll('.total');
    let total = 0;

    totales.forEach(t => {
        total += parseFloat(t.innerText.replace('$','')) || 0;
    });

    document.getElementById('total').innerText = total.toFixed(2);
    document.getElementById('totalInput').value = total.toFixed(2);
}

function agregarCodigo(e) {
    if (e.key !== "Enter") return;
    e.preventDefault();

    let codigoInput = document.getElementById("codigoInput");
    let codigo = codigoInput.value.trim();

    if (!codigo) return;

    let producto = productos.find(p => String(p.codigo) === codigo);

    if (!producto) {
        alert("Producto no encontrado");
        codigoInput.value = "";
        return;
    }

    let tabla = document.getElementById("tabla");
    let filas = tabla.querySelectorAll("tr");

    let filaExistente = null;

    filas.forEach((fila, index) => {
        if (index === 0) return;
        let codigoFila = fila.children[0].innerText.trim();
        if (codigoFila === codigo) {
            filaExistente = fila;
        }
    });

    if (filaExistente) {
        let inputCantidad = filaExistente.querySelector("input[type='number']");
        let cantidadActual = parseInt(inputCantidad.value) || 0;
        inputCantidad.value = cantidadActual + 1;

        filaExistente.classList.add("resaltado");
        setTimeout(() => filaExistente.classList.remove("resaltado"), 500);

        calc(inputCantidad);
        codigoInput.value = "";
        return;
    }

    let fila = tabla.insertRow();

    fila.innerHTML = `
        <td>${producto.codigo}</td>
        <td>${producto.nombre}</td>
        <td>${producto.unidades_por_caja}</td>
        <td>${producto.unidades_por_caja}</td>
        <td>$${parseFloat(producto.costo).toFixed(2)}</td>
        <td>
            <input type="number" name="productos[${producto.id}]" value="1" min="0" onchange="calc(this)">
        </td>
        <td class="total">$${parseFloat(producto.costo).toFixed(2)}</td>
    `;

    recalcular();
    codigoInput.value = "";
}
</script>

</body>
</html>
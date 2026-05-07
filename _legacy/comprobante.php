<?php
$conexion = new mysqli("localhost", "root", "", "attos");

$id = (int)$_GET['id'];

$pedido = $conexion->query("
    SELECT p.*, l.codigo AS lista_codigo
    FROM pedidos p
    LEFT JOIN listas l ON l.id = p.lista_id
    WHERE p.id = $id
")->fetch_assoc();

$items = $conexion->query("
    SELECT pi.*, pr.nombre
    FROM pedido_items pi
    LEFT JOIN productos pr ON pr.id = pi.producto_id
    WHERE pi.pedido_id = $id
");

// BUSCAR CLIENTE
$stmt = $conexion->prepare("SELECT * FROM clientes WHERE nombre = ?");
$stmt->bind_param("s", $pedido['cliente']);
$stmt->execute();
$cliente = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ARMAR MENSAJE
$mensaje = "Hola ".$pedido['cliente']." 👋\nTe paso tu pedido:\n\n";

while ($i = $items->fetch_assoc()) {
    $mensaje .= "- ".$i['nombre']." x".$i['cantidad']."\n";
}

$mensaje .= "\nTotal: $".$pedido['total'];

$mensaje_url = urlencode($mensaje);

// limpiar teléfono
$telefono = preg_replace('/[^0-9]/', '', $cliente['telefono']);
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Comprobante</title>

<style>
body {
    font-family: Arial;
    padding: 30px;
}

.contenedor {
    max-width: 700px;
    margin: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th, td {
    border-bottom: 1px solid #ccc;
    padding: 8px;
}

.total {
    text-align: right;
    font-size: 20px;
    margin-top: 20px;
}

.btn {
    margin-top: 15px;
    padding: 10px;
    border: none;
    cursor: pointer;
}

.print {
    background: black;
    color: white;
}

.wa {
    background: #25D366;
    color: white;
}
</style>

</head>
<body>

<div class="contenedor">

<h1>ATTOS</h1>

<p><strong>Cliente:</strong> <?= $pedido['cliente'] ?></p>
<p><strong>Fecha:</strong> <?= $pedido['fecha'] ?></p>

<table>
<tr>
<th>Producto</th>
<th>Cant</th>
<th>Total</th>
</tr>

<?php
$items = $conexion->query("
    SELECT pi.*, pr.nombre
    FROM pedido_items pi
    LEFT JOIN productos pr ON pr.id = pi.producto_id
    WHERE pi.pedido_id = $id
");

while ($i = $items->fetch_assoc()):
?>
<tr>
<td><?= $i['nombre'] ?></td>
<td><?= $i['cantidad'] ?></td>
<td>$<?= number_format($i['total'], 2) ?></td>
</tr>
<?php endwhile; ?>

</table>

<div class="total">
TOTAL: $<?= number_format($pedido['total'], 2) ?>
</div>

<br>

<button class="btn print" onclick="window.print()">Imprimir</button>

<br>

<a href="https://wa.me/<?= $telefono ?>?text=<?= $mensaje_url ?>" target="_blank">
    <button class="btn wa">Enviar por WhatsApp</button>
</a>

</div>

</body>
</html>
<?php
$conexion = new mysqli("localhost", "root", "", "attos");

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$pedido = $conexion->query("
    SELECT p.*, l.codigo AS lista_codigo
    FROM pedidos p
    LEFT JOIN listas l ON l.id = p.lista_id
    WHERE p.id = $id
")->fetch_assoc();

if (!$pedido) {
    die("Pedido no encontrado");
}

$items = $conexion->query("
    SELECT pi.*, pr.nombre, pr.codigo
    FROM pedido_items pi
    LEFT JOIN productos pr ON pr.id = pi.producto_id
    WHERE pi.pedido_id = $id
    ORDER BY pi.id ASC
");
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Pedido #<?= $pedido['id'] ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            background: #f4f4f4;
            margin: 0;
        }
        .caja {
            background: white;
            padding: 20px;
            border: 1px solid #ccc;
        }
        a.boton {
            display: inline-block;
            margin-bottom: 20px;
            background: #333;
            color: white;
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 4px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            margin-top: 20px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 10px;
        }
        th {
            background: #333;
            color: white;
        }
    </style>
</head>
<body>

<a class="boton" href="pedidos.php">Volver</a>

<div class="caja">
    <h1>Pedido #<?= $pedido['id'] ?></h1>
    <p><strong>Cliente:</strong> <?= htmlspecialchars($pedido['cliente']) ?></p>
    <p><strong>Fecha:</strong> <?= $pedido['fecha'] ?></p>
    <p><strong>Lista:</strong> <?= htmlspecialchars($pedido['lista_codigo']) ?></p>
    <p><strong>Total:</strong> $<?= number_format($pedido['total'], 2) ?></p>
</div>

<table>
    <tr>
        <th>Código</th>
        <th>Producto</th>
        <th>Cantidad</th>
        <th>Precio unitario</th>
        <th>Total</th>
    </tr>

    <?php while ($item = $items->fetch_assoc()): ?>
        <tr>
            <td><?= htmlspecialchars($item['codigo']) ?></td>
            <td><?= htmlspecialchars($item['nombre']) ?></td>
            <td><?= $item['cantidad'] ?></td>
            <td>$<?= number_format($item['precio_unitario'], 2) ?></td>
            <td>$<?= number_format($item['total'], 2) ?></td>
        </tr>
    <?php endwhile; ?>
</table>

</body>
</html>
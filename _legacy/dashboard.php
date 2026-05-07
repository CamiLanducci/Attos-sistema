<?php
$conexion = new mysqli("localhost", "root", "", "attos");

// FECHAS
$hoy = date("Y-m-d");
$mes = date("Y-m-d", strtotime("-30 days"));

// =====================
// VENTAS Y GANANCIA
// =====================
$ventas = $conexion->query("
    SELECT SUM(total) as total FROM pedidos
")->fetch_assoc()['total'] ?? 0;

$ganancia = $conexion->query("
    SELECT SUM(p.total * (l.margen / 100)) as ganancia
    FROM pedidos p
    JOIN listas l ON l.id = p.lista_id
")->fetch_assoc()['ganancia'] ?? 0;

// =====================
// CLIENTES INACTIVOS
// =====================
$clientes_inactivos = $conexion->query("
    SELECT c.nombre, c.telefono
    FROM clientes c
    LEFT JOIN pedidos p 
        ON p.cliente = c.nombre 
        AND p.fecha >= '$mes'
    WHERE p.id IS NULL
");

// =====================
// CLIENTES QUE CRECEN
// =====================
$semana = date("Y-m-d", strtotime("-7 days"));

$clientes_crecen = $conexion->query("
    SELECT 
        p.cliente,
        SUM(CASE WHEN p.fecha >= '$semana' THEN p.total ELSE 0 END) as semana,
        SUM(CASE WHEN p.fecha < '$semana' AND p.fecha >= '$mes' THEN p.total ELSE 0 END) as anterior
    FROM pedidos p
    GROUP BY p.cliente
    HAVING semana > anterior AND semana > 0
");

// =====================
// PRODUCTOS SIN VENTA
// =====================
$productos_muertos = $conexion->query("
    SELECT pr.nombre
    FROM productos pr
    LEFT JOIN pedido_items pi 
        ON pi.producto_id = pr.id
    WHERE pi.id IS NULL
    LIMIT 10
");
?>

<!DOCTYPE html>
<html>
<head>
<title>Dashboard Attos</title>

<style>
body {
    font-family: Arial;
    padding: 20px;
    background: #f4f4f4;
}

.box {
    background: white;
    padding: 20px;
    margin-bottom: 20px;
}

h1 {
    margin-bottom: 30px;
}

.green {
    color: green;
}

.red {
    color: red;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th, td {
    padding: 8px;
    border: 1px solid #ccc;
}

th {
    background: #333;
    color: white;
}

.btn {
    background: #25D366;
    color: white;
    padding: 6px;
    border: none;
    cursor: pointer;
}
</style>
</head>
<body>

<h1>📊 Dashboard Attos</h1>

<div class="box">
    <h2>Resumen</h2>
    <p>Ventas Totales: $<?= number_format($ventas, 2) ?></p>
    <p class="green">Ganancia Total: $<?= number_format($ganancia, 2) ?></p>
</div>

<div class="box">
<h2 class="red">Clientes inactivos (30 días)</h2>

<table>
<tr>
<th>Cliente</th>
<th>Acción</th>
</tr>

<?php while($c = $clientes_inactivos->fetch_assoc()): 

$mensaje = "Hola ".$c['nombre']." 👋\nHace un tiempo no realizamos pedidos.\n\nSi necesitás reposición o querés que te pase la lista actualizada, avisame 🙌";

$telefono = preg_replace('/[^0-9]/', '', $c['telefono']);
$url = urlencode($mensaje);
?>

<tr>
<td><?= $c['nombre'] ?></td>
<td>
<a href="https://wa.me/<?= $telefono ?>?text=<?= $url ?>" target="_blank">
<button class="btn">Contactar</button>
</a>
</td>
</tr>

<?php endwhile; ?>
</table>
</div>

<div class="box">
<h2 class="green">Clientes que están creciendo</h2>

<table>
<tr>
<th>Cliente</th>
<th>Semana</th>
<th>Anterior</th>
</tr>

<?php while($c = $clientes_crecen->fetch_assoc()): ?>
<tr>
<td><?= $c['cliente'] ?></td>
<td>$<?= number_format($c['semana'], 2) ?></td>
<td>$<?= number_format($c['anterior'], 2) ?></td>
</tr>
<?php endwhile; ?>

</table>
</div>

<div class="box">
<h2 class="red">Productos sin ventas</h2>

<table>
<tr><th>Producto</th></tr>

<?php while($p = $productos_muertos->fetch_assoc()): ?>
<tr><td><?= $p['nombre'] ?></td></tr>
<?php endwhile; ?>

</table>
</div>

</body>
</html>
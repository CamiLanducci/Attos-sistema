<?php
$conexion = new mysqli("localhost", "root", "", "attos");

$lista_id = isset($_GET['lista_id']) ? (int)$_GET['lista_id'] : 1;

// BUSCAR LISTAS
$listas = $conexion->query("SELECT * FROM listas");

// BUSCAR PRODUCTOS
$productos = $conexion->query("
    SELECT * FROM productos 
    WHERE lista_id = $lista_id AND activo = 1
    ORDER BY nombre
");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Productos</title>
    <style>
        body {
            font-family: Arial;
            padding: 20px;
            background: #f4f4f4;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th, td {
            padding: 8px;
            border: 1px solid #ccc;
        }

        th {
            background: #333;
            color: white;
        }

        .selector {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>

<h2>Productos</h2>

<div class="selector">
    <form method="GET">
        <select name="lista_id" onchange="this.form.submit()">
            <?php while ($l = $listas->fetch_assoc()): ?>
                <option value="<?= $l['id'] ?>" <?= $l['id'] == $lista_id ? 'selected' : '' ?>>
                    <?= $l['codigo'] ?> (<?= $l['margen'] ?>%)
                </option>
            <?php endwhile; ?>
        </select>
    </form>
</div>

<table>
<tr>
    <th>Código</th>
    <th>Nombre</th>
    <th>Marca</th>
    <th>Pack</th>
    <th>Precio Unidad</th>
    <th>Precio Caja</th>
</tr>

<?php while ($p = $productos->fetch_assoc()): ?>
<tr>
    <td><?= $p['codigo'] ?></td>
    <td><?= $p['nombre'] ?></td>
    <td><?= $p['marca'] ?></td>
    <td><?= $p['unidades_por_caja'] ?></td>
    <td>$<?= number_format($p['costo'], 2) ?></td>
    <td>$<?= number_format($p['costo_caja'], 2) ?></td>
</tr>
<?php endwhile; ?>

</table>

</body>
</html>
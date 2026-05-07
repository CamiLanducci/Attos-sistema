<?php
$conexion = new mysqli("localhost", "root", "", "attos");

$listas = $conexion->query("SELECT * FROM listas");

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $nombre   = $_POST["nombre"];
    $telefono = $_POST["telefono"];
    $direccion = $_POST["direccion"];
    $lista_id = (int)$_POST["lista_id"];

    $stmt = $conexion->prepare("INSERT INTO clientes (nombre, telefono, direccion, lista_id) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("sssi", $nombre, $telefono, $direccion, $lista_id);
    $stmt->execute();
    $stmt->close();
}

$clientes = $conexion->query("
    SELECT c.*, l.codigo as lista_codigo 
    FROM clientes c
    LEFT JOIN listas l ON l.id = c.lista_id
    ORDER BY c.id DESC
");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Clientes</title>
</head>
<body>

<h2>Clientes</h2>

<form method="POST">
    Nombre: <input name="nombre" required>
    Tel: <input name="telefono">
    Dirección: <input name="direccion">

    Lista:
    <select name="lista_id" required>
        <?php while ($l = $listas->fetch_assoc()): ?>
            <option value="<?= $l['id'] ?>">
                <?= $l['codigo'] ?> (<?= $l['margen'] ?>%)
            </option>
        <?php endwhile; ?>
    </select>

    <button>Guardar</button>
</form>

<hr>

<table border="1">
<tr>
<th>ID</th>
<th>Nombre</th>
<th>Tel</th>
<th>Dirección</th>
<th>Lista</th>
</tr>

<?php while ($c = $clientes->fetch_assoc()): ?>
<tr>
<td><?= $c['id'] ?></td>
<td><?= $c['nombre'] ?></td>
<td><?= $c['telefono'] ?></td>
<td><?= $c['direccion'] ?></td>
<td><?= $c['lista_codigo'] ?></td>
</tr>
<?php endwhile; ?>

</table>

</body>
</html>
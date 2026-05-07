<?php
$conexion = new mysqli("localhost", "root", "", "attos");

if ($conexion->connect_error) {
    die("Error de conexión: " . $conexion->connect_error);
}

$mensaje = "";

// GUARDAR URL
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar_url"])) {
    $id  = (int)$_POST["id"];
    $url = trim($_POST["url"]);

    $stmt = $conexion->prepare("UPDATE listas SET url_actualizacion = ? WHERE id = ?");
    $stmt->bind_param("si", $url, $id);
    if ($stmt->execute()) {
        $mensaje = "URL guardada correctamente.";
    } else {
        $mensaje = "Error al guardar la URL: " . $stmt->error;
    }
    $stmt->close();
}

// TRAER LISTAS
$result = $conexion->query("SELECT * FROM listas ORDER BY id");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Administrar Listas - Attos</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f3f3f3;
            padding: 20px;
            margin: 0;
        }

        h1 {
            margin-bottom: 20px;
        }

        .top-actions {
            margin-bottom: 20px;
        }

        .btn-todas {
            background: #1f7a1f;
            color: white;
            text-decoration: none;
            padding: 10px 16px;
            display: inline-block;
            border-radius: 4px;
            font-weight: bold;
        }

        .mensaje {
            background: #e8f5e9;
            color: #1b5e20;
            padding: 10px;
            border: 1px solid #c8e6c9;
            margin-bottom: 15px;
            border-radius: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th, td {
            border: 1px solid #d0d0d0;
            padding: 10px;
            vertical-align: middle;
        }

        th {
            background: #333;
            color: white;
            text-align: center;
        }

        input[type="text"] {
            width: 100%;
            padding: 7px;
            box-sizing: border-box;
        }

        .btn-guardar {
            background: #f0f0f0;
            border: 1px solid #999;
            padding: 7px 12px;
            cursor: pointer;
        }

        .btn-importar {
            background: #28a745;
            color: white;
            text-decoration: none;
            padding: 7px 12px;
            display: inline-block;
            border-radius: 3px;
        }

        .ultima {
            font-size: 12px;
            color: #555;
            margin-top: 5px;
        }
    </style>
</head>
<body>

<h1>Administrar Listas</h1>

<div class="top-actions">
    <a class="btn-todas" href="importar_lista.php" target="_blank">Importar todas las listas</a>
</div>

<?php if ($mensaje !== ""): ?>
    <div class="mensaje"><?= htmlspecialchars($mensaje) ?></div>
<?php endif; ?>

<table>
    <tr>
        <th>ID</th>
        <th>Código</th>
        <th>Margen</th>
        <th>URL</th>
        <th>Guardar</th>
        <th>Importar</th>
    </tr>

    <?php while ($row = $result->fetch_assoc()): ?>
        <tr>
            <form method="POST">
                <td><?= $row['id'] ?></td>
                <td><?= htmlspecialchars($row['codigo']) ?></td>
                <td><?= number_format((float)$row['margen'], 2) ?>%</td>

                <td>
                    <input type="text" name="url" value="<?= htmlspecialchars($row['url_actualizacion'] ?? '') ?>">
                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                    <input type="hidden" name="guardar_url" value="1">
                    <div class="ultima">
                        Última actualización:
                        <?= $row['ultima_actualizacion'] ? htmlspecialchars($row['ultima_actualizacion']) : 'Nunca' ?>
                    </div>
                </td>

                <td style="text-align:center;">
                    <button class="btn-guardar" type="submit">Guardar</button>
                </td>

                <td style="text-align:center;">
                    <a class="btn-importar" href="importar_lista.php?lista_id=<?= $row['id'] ?>" target="_blank">
                        Importar
                    </a>
                </td>
            </form>
        </tr>
    <?php endwhile; ?>
</table>

</body>
</html>
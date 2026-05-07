<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);

$conexion = new mysqli("localhost", "root", "", "attos");

if ($conexion->connect_error) {
    die("Error de conexión: " . $conexion->connect_error);
}

$conexion->set_charset("utf8");

$soloListaId = isset($_GET['lista_id']) ? (int)$_GET['lista_id'] : null;

/*
|--------------------------------------------------------------------------
| FUNCIONES AUXILIARES
|--------------------------------------------------------------------------
*/

function getInnerHTML(DOMNode $node): string
{
    $html = '';
    foreach ($node->childNodes as $child) {
        $html .= $node->ownerDocument->saveHTML($child);
    }
    return $html;
}

function limpiarTexto(string $texto): string
{
    $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $texto = strip_tags($texto);
    $texto = preg_replace('/\s+/u', ' ', $texto);
    return trim($texto);
}

function contieneDatosProducto(string $texto): bool
{
    return (
        stripos($texto, 'Pack:') !== false &&
        (
            stripos($texto, 'Código:') !== false ||
            stripos($texto, 'CÃ³digo:') !== false
        ) &&
        stripos($texto, 'Precio:') !== false
    );
}

function esCabeceraMarca(string $texto): bool
{
    if ($texto === '') {
        return false;
    }

    if (contieneDatosProducto($texto)) {
        return false;
    }

    $prohibidos = [
        'Buscar artículo',
        'Lista de Precios',
        'Los Precios son x Unidad',
        'Precio',
        'Pack',
        'Código',
        'CÃ³digo'
    ];

    foreach ($prohibidos as $p) {
        if (stripos($texto, $p) !== false) {
            return false;
        }
    }

    return true;
}

function extraerProductoDesdeHTML(string $innerHtml): ?array
{
    $html = html_entity_decode($innerHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = str_replace(["\r", "\n"], ' ', $html);

    if (
        stripos($html, 'Pack:') === false ||
        (stripos($html, 'Código:') === false && stripos($html, 'CÃ³digo:') === false) ||
        stripos($html, 'Precio:') === false
    ) {
        return null;
    }

    $nombre = preg_replace('/<br\s*\/?>.*$/i', '', $html);
    $nombre = limpiarTexto($nombre);

    preg_match('/Pack:\s*(\d+)/iu', $html, $packMatch);
    preg_match('/C(?:Ã³|ó)digo:\s*([0-9]+)/iu', $html, $codigoMatch);
    preg_match('/Precio:\s*<b>\s*([\d\.,]+)\s*<\/b>/iu', $html, $precioMatch);

    if (empty($precioMatch)) {
        preg_match('/Precio:\s*([\d\.,]+)/iu', $html, $precioMatch);
    }

    if (empty($nombre) || empty($packMatch) || empty($codigoMatch) || empty($precioMatch)) {
        return null;
    }

    $pack = (int)$packMatch[1];
    $codigo = trim($codigoMatch[1]);

    $precioOriginal = trim($precioMatch[1]);

    if (substr_count($precioOriginal, '.') === 1 && substr_count($precioOriginal, ',') === 0) {
        $precioTexto = $precioOriginal;
    } else {
        $precioTexto = str_replace('.', '', $precioOriginal);
        $precioTexto = str_replace(',', '.', $precioTexto);
    }

    $precioUnidad = (float)$precioTexto;

    if ($pack <= 0 || $precioUnidad <= 0 || $codigo === '') {
        return null;
    }

    return [
        'nombre' => $nombre,
        'codigo' => $codigo,
        'pack' => $pack,
        'precio_unidad' => $precioUnidad,
        'precio_caja' => $precioUnidad * $pack,
    ];
}

/*
|--------------------------------------------------------------------------
| BUSCAR LISTAS
|--------------------------------------------------------------------------
*/

$sqlListas = "
    SELECT id, codigo, margen, url_actualizacion
    FROM listas
    WHERE url_actualizacion IS NOT NULL
      AND url_actualizacion <> ''
";

if ($soloListaId) {
    $sqlListas .= " AND id = $soloListaId";
}

$sqlListas .= " ORDER BY id";

$resultListas = $conexion->query($sqlListas);

if (!$resultListas || $resultListas->num_rows === 0) {
    die("No se encontraron listas para importar.");
}

/*
|--------------------------------------------------------------------------
| PREPARAR STATEMENTS
|--------------------------------------------------------------------------
*/

// Insertar producto si no existe; ON DUPLICATE KEY en codigo no actualiza nada
// (preserva datos editados manualmente desde productos/form.php).
// REQUIERE: UNIQUE KEY en productos.codigo — ver nota al pie.
$stmtProducto = $conexion->prepare("
    INSERT INTO productos (codigo, nombre, marca, unidades_por_caja, activo)
    VALUES (?, ?, ?, ?, 1)
    ON DUPLICATE KEY UPDATE id = id
");

if (!$stmtProducto) {
    die("Error preparando stmtProducto: " . $conexion->error);
}

// Upsert precio por lista
$stmtLP = $conexion->prepare("
    INSERT INTO lista_precios (lista_id, producto_id, costo, costo_caja)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE costo = VALUES(costo), costo_caja = VALUES(costo_caja)
");

if (!$stmtLP) {
    die("Error preparando stmtLP: " . $conexion->error);
}

// Limpiar precios de la lista antes de reimportar
$delStmt = $conexion->prepare("DELETE FROM lista_precios WHERE lista_id = ?");

if (!$delStmt) {
    die("Error preparando delStmt: " . $conexion->error);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Importar listas - Attos</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f7f7f7;
            padding: 20px;
            margin: 0;
        }
        .bloque {
            background: white;
            border: 1px solid #ddd;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 5px;
        }
        .ok {
            color: #1b5e20;
            font-weight: bold;
        }
        .error {
            color: #b71c1c;
            font-weight: bold;
        }
        .titulo {
            margin-top: 0;
        }
        .back {
            display: inline-block;
            margin-bottom: 20px;
            text-decoration: none;
            background: #333;
            color: white;
            padding: 8px 12px;
            border-radius: 4px;
        }
    </style>
</head>
<body>

<a class="back" href="listas.php">Volver a listas</a>

<h1>Importación de listas</h1>

<?php
while ($lista = $resultListas->fetch_assoc()) {
    $listaId = (int)$lista['id'];
    $codigoLista = $lista['codigo'];
    $margen = $lista['margen'];
    $url = $lista['url_actualizacion'];

    echo "<div class='bloque'>";
    echo "<h2 class='titulo'>Lista {$codigoLista} ({$margen}%)</h2>";
    echo "<p><strong>URL:</strong> " . htmlspecialchars($url) . "</p>";

    $html = @file_get_contents($url);

    if ($html === false) {
        echo "<p class='error'>No se pudo descargar la página.</p>";
        echo "</div>";
        continue;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);

    $nodos = $xpath->query("//td[contains(@class,'dxgv')]");

    if (!$nodos || $nodos->length === 0) {
        echo "<p class='error'>No se encontraron nodos de productos.</p>";
        echo "</div>";
        continue;
    }

    // Borrar precios viejos de esta lista; los productos del catálogo no se tocan
    $delStmt->bind_param("i", $listaId);
    $delStmt->execute();

    $marcaActual = 'SIN MARCA';
    $importados = 0;
    $errores = 0;

    foreach ($nodos as $nodo) {
        $textoPlano = limpiarTexto($nodo->textContent);
        $innerHtml = getInnerHTML($nodo);

        if ($textoPlano === '') {
            continue;
        }

        if (esCabeceraMarca($textoPlano)) {
            $marcaActual = $textoPlano;
            continue;
        }

        if (!contieneDatosProducto($textoPlano) && !contieneDatosProducto($innerHtml)) {
            continue;
        }

        $producto = extraerProductoDesdeHTML($innerHtml);

        if (!$producto) {
            $errores++;
            continue;
        }

        $nombre = $producto['nombre'];
        $codigo = $producto['codigo'];
        $pack = $producto['pack'];
        $precioUnidad = $producto['precio_unidad'];
        $precioCaja = $producto['precio_caja'];
        $marca = $marcaActual ?: 'SIN MARCA';

        // 1. Insertar producto si no existe (no pisa datos editados manualmente)
        $stmtProducto->bind_param("sssi", $codigo, $nombre, $marca, $pack);
        $stmtProducto->execute();

        // 2. Obtener producto_id
        $productoId = $conexion->insert_id;
        if ($productoId === 0) {
            $sel = $conexion->prepare("SELECT id FROM productos WHERE codigo = ?");
            $sel->bind_param("s", $codigo);
            $sel->execute();
            $productoId = (int)$sel->get_result()->fetch_assoc()['id'];
            $sel->close();
        }

        if ($productoId === 0) {
            $errores++;
            continue;
        }

        // 3. Upsert precio en lista_precios
        $stmtLP->bind_param("iidd", $listaId, $productoId, $precioUnidad, $precioCaja);

        if ($stmtLP->execute()) {
            $importados++;
        } else {
            $errores++;
        }
    }

    $conexion->query("
        UPDATE listas
        SET ultima_actualizacion = NOW()
        WHERE id = {$listaId}
    ");

    echo "<p><strong>Nodos encontrados:</strong> {$nodos->length}</p>";
    echo "<p><strong>Productos importados/actualizados:</strong> {$importados}</p>";
    echo "<p><strong>Errores omitidos:</strong> {$errores}</p>";
    echo "<p class='ok'>Lista {$codigoLista} finalizada.</p>";
    echo "</div>";
}

$stmtProducto->close();
$stmtLP->close();
$delStmt->close();
$conexion->close();
?>

<div class="bloque">
    <h2 class="titulo">Importación completa</h2>
    <p class="ok">Proceso terminado correctamente.</p>
</div>

</body>
</html>

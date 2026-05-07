<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'attos');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function precio(float $val): string {
    return '$' . number_format($val, 2, ',', '.');
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function esGaseosaOEnergizante(string $cat): bool {
    $cat = strtolower(trim($cat));
    return strpos($cat, 'gaseosa') !== false
        || strpos($cat, 'energi')  !== false
        || strpos($cat, 'soda')    !== false;
}

function getPrecioProductoLista(int $productoId, int $listaId): ?array {
    $stmt = getDB()->prepare("
        SELECT lp.costo, lp.costo_caja, l.margen
        FROM lista_precios lp
        JOIN listas l ON l.id = lp.lista_id
        WHERE lp.producto_id = ? AND lp.lista_id = ?
        LIMIT 1
    ");
    $stmt->execute([$productoId, $listaId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function listarProductosConPrecio(int $listaId): array {
    $stmt = getDB()->prepare("
        SELECT p.id, p.codigo, p.nombre, p.marca, p.unidades_por_caja,
               lp.costo, lp.costo_caja, l.margen
        FROM lista_precios lp
        JOIN productos p ON p.id = lp.producto_id
        JOIN listas l    ON l.id = lp.lista_id
        WHERE lp.lista_id = ? AND p.activo = 1
        ORDER BY p.marca, p.nombre
    ");
    $stmt->execute([$listaId]);
    return $stmt->fetchAll();
}

function calcularPreciosProducto(float $costo, float $margen, int $upc, int $precioPorPack, string $categoria): array {
    $upc = max(1, $upc);
    $esGaseosa = esGaseosaOEnergizante($categoria);
    if ($esGaseosa) {
        if ($precioPorPack) {
            $precioCaja = $costo * (1 + $margen / 100);
            $precioUnit = $precioCaja / $upc;
            $costoUnit  = $costo / $upc;
        } else {
            $precioUnit = $costo * (1 + $margen / 100);
            $precioCaja = $precioUnit * $upc;
            $costoUnit  = $costo;
        }
    } else {
        if ($precioPorPack) {
            $precioCaja = $costo;
            $precioUnit = $costo / $upc;
            $costoUnit  = $precioUnit / (1 + $margen / 100);
        } else {
            $precioUnit = $costo;
            $precioCaja = $costo * $upc;
            $costoUnit  = $costo / (1 + $margen / 100);
        }
    }
    return [
        'precio_unit' => $precioUnit,
        'precio_caja' => $precioCaja,
        'costo_unit'  => $costoUnit,
    ];
}

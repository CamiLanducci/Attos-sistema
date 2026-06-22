<?php
/**
 * ATTOS — Database Configuration
 * 
 * Soporta múltiples entornos (desarrollo, staging, producción)
 * mediante variables de entorno (.env.local / .env.production)
 */

// ─── CARGA DE VARIABLES DE ENTORNO ──────────────────────────
function loadEnvironment(): void {
    // Detectar ruta del proyecto
    $rootPath = dirname(__DIR__);
    
    // Prioridad: .env.local > .env.production > valores por defecto
    $envFile = null;
    if (file_exists($rootPath . '/.env.local')) {
        $envFile = $rootPath . '/.env.local';
    } elseif (file_exists($rootPath . '/.env')) {
        $envFile = $rootPath . '/.env';
    }
    
    // Cargar archivo .env si existe
    if ($envFile && !getenv('_ENV_LOADED')) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if ($line[0] !== '#' && strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim(trim($value), '\'"');
                if (!getenv($key)) {
                    putenv("$key=$value");
                }
            }
        }
        putenv('_ENV_LOADED=1');
    }
}

// Cargar variables de entorno
loadEnvironment();

// ─── DEFINICIONES DE CONFIGURACIÓN ──────────────────────────
const APP_ENV = 'APP_ENV';
const DB_HOST_ENV = 'DB_HOST';
const DB_PORT_ENV = 'DB_PORT';
const DB_NAME_ENV = 'DB_NAME';
const DB_USER_ENV = 'DB_USER';
const DB_PASS_ENV = 'DB_PASS';
const DB_CHARSET_ENV = 'DB_CHARSET';
const BASE_URL_ENV = 'BASE_URL';

// Valores por defecto — Clever Cloud (MYSQL_ADDON_*) tiene prioridad sobre vars genéricas (DB_*)
define('DB_HOST', getenv('MYSQL_ADDON_HOST') ?: getenv(DB_HOST_ENV) ?: 'localhost');
define('DB_PORT', getenv('MYSQL_ADDON_PORT') ?: getenv(DB_PORT_ENV) ?: '3306');
define('DB_NAME', getenv('MYSQL_ADDON_DB')   ?: getenv(DB_NAME_ENV) ?: 'attos');
define('DB_USER', getenv('MYSQL_ADDON_USER') ?: getenv(DB_USER_ENV) ?: 'root');
define('DB_PASS', getenv('MYSQL_ADDON_PASSWORD') ?: getenv(DB_PASS_ENV) ?: '');
define('DB_CHARSET', getenv(DB_CHARSET_ENV) ?: 'utf8mb4');
define('BASE_URL', getenv(BASE_URL_ENV) ?: 'http://localhost/Attos');
define('APP_ENVIRONMENT', getenv(APP_ENV) ?: 'development');
// BASE_PATH: vacío en producción (raíz del dominio), '/attos' en XAMPP local.
// En .env.local: BASE_PATH=/attos
define('BASE_PATH', getenv('BASE_PATH') ?: '');

// ─── VALIDACIÓN DE CONFIGURACIÓN ────────────────────────────
if (APP_ENVIRONMENT === 'production') {
    if (empty(DB_PASS)) {
        throw new RuntimeException(
            'ERROR: En producción, DB_PASS debe estar configurada en .env'
        );
    }
}

/**
 * Obtiene la conexión PDO singleton a la base de datos
 * 
 * @return PDO
 * @throws PDOException si la conexión falla
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 10,
            ]);
        } catch (PDOException $e) {
            if (APP_ENVIRONMENT === 'development') {
                throw new PDOException('Error de conexión: ' . $e->getMessage());
            } else {
                // En producción, no revelar detalles de error
                throw new PDOException('Error de conexión a la base de datos');
            }
        }
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

// Marcas de cerveza cuyo nombre no contiene la palabra "cerveza"
const MARCAS_CERVEZA_EXTRA = [
    'corona', 'andes', 'grolsch', 'mazbier',
    'warsteiner', 'palermo', 'budweiser', 'amstel',
    'kunstmann', 'patagonia', 'andina', 'porter',
];

function esCerveza(string $cat, string $marca = ''): bool {
    // Detecta por categoría o por palabra "cerveza" en la marca (igual que el catálogo)
    $catL   = strtolower(trim($cat));
    $marcaL = strtolower(trim($marca));
    if (strpos($catL,   'cerveza') !== false) return true;
    if (strpos($marcaL, 'cerveza') !== false) return true;
    // Fallback: marcas de cerveza sin "cerveza" en el nombre
    foreach (MARCAS_CERVEZA_EXTRA as $m) {
        if (strpos($marcaL, $m) !== false) return true;
    }
    return false;
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

function calcularPreciosProducto(float $costo, float $margen, int $upc, int $precioPorPack, string $categoria, string $marca = ''): array {
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
    } elseif (esCerveza($categoria, $marca)) {
        // Cerveza: costo almacenado = precio de la caja; unidad = caja ÷ upc
        $precioCaja = $costo;
        $precioUnit = $costo / $upc;
        $costoUnit  = $precioUnit / (1 + $margen / 100);
    } else {
        $precioUnit = $costo;
        $precioCaja = $costo * $upc;
        $costoUnit  = $costo / (1 + $margen / 100);
    }
    return [
        'precio_unit' => $precioUnit,
        'precio_caja' => $precioCaja,
        'costo_unit'  => $costoUnit,
    ];
}

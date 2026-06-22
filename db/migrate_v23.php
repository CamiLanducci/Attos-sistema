<?php
/**
 * Migración v23 — ejecutar UNA VEZ y luego borrar este archivo.
 * Acceder vía: https://tu-dominio/db/migrate_v23.php?token=attos_migrate_v23
 */

define('EXPECTED_TOKEN', 'attos_migrate_v23');

if (($_GET['token'] ?? '') !== EXPECTED_TOKEN) {
    http_response_code(403);
    die('Acceso denegado.');
}

require_once __DIR__ . '/../config/db.php';

$db = getDB();

$steps = [
    'Agregar mixto al ENUM de medio_pago' =>
        "ALTER TABLE comprobantes
         MODIFY COLUMN medio_pago ENUM('efectivo','transferencia','mixto') NULL",

    'Agregar columna monto_efectivo' =>
        "ALTER TABLE comprobantes
         ADD COLUMN IF NOT EXISTS monto_efectivo DECIMAL(12,2) NULL AFTER medio_pago",

    'Agregar columna monto_transferencia' =>
        "ALTER TABLE comprobantes
         ADD COLUMN IF NOT EXISTS monto_transferencia DECIMAL(12,2) NULL AFTER monto_efectivo",

    'Crear tabla caja_movimientos' =>
        "CREATE TABLE IF NOT EXISTS caja_movimientos (
            id                  INT AUTO_INCREMENT PRIMARY KEY,
            tipo                ENUM('ingreso','egreso') NOT NULL,
            concepto            ENUM('venta','pago_proveedor','compra_dolares','sueldo','gasto','otro') NOT NULL,
            medio_pago          ENUM('efectivo','transferencia') NOT NULL,
            monto               DECIMAL(12,2) NOT NULL,
            monto_dolares       DECIMAL(10,4) NULL,
            precio_dolar_compra DECIMAL(10,2) NULL,
            precio_dolar_venta  DECIMAL(10,2) NULL,
            comprobante_id      INT NULL,
            descripcion         VARCHAR(500) NULL,
            usuario_id          INT NULL,
            created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tipo       (tipo),
            INDEX idx_concepto   (concepto),
            INDEX idx_medio_pago (medio_pago),
            INDEX idx_comp       (comprobante_id),
            INDEX idx_created    (created_at)
        )",

    'Crear tabla movimientos_cuenta' =>
        "CREATE TABLE IF NOT EXISTS movimientos_cuenta (
            id                 INT AUTO_INCREMENT PRIMARY KEY,
            fecha              DATE NOT NULL,
            cuenta             ENUM('area_520','alfre','patrimonio') NOT NULL,
            tipo               ENUM('cargo','pago') NOT NULL,
            monto              DECIMAL(12,2) NOT NULL,
            descripcion        VARCHAR(500) NULL,
            pedido_galpon_id   INT NULL,
            comprobante_id     INT NULL,
            movimiento_par_id  INT NULL,
            creado_por         INT NULL,
            created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
];

echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'>
<title>Migración v23</title>
<style>
  body { font-family: monospace; max-width: 700px; margin: 40px auto; padding: 0 20px; }
  .ok  { color: #2d7a4f; }
  .err { color: #c0392b; }
  pre  { background: #f5f5f5; padding: 10px; border-radius: 4px; white-space: pre-wrap; word-break: break-all; }
</style></head><body>";

echo "<h2>Migración ATTOS v23</h2>";
echo "<p>Host DB: <strong>" . DB_HOST . "</strong> / Base: <strong>" . DB_NAME . "</strong></p><hr>";

$allOk = true;

foreach ($steps as $label => $sql) {
    echo "<p><strong>$label…</strong> ";
    try {
        $db->exec($sql);
        echo "<span class='ok'>✓ OK</span></p>";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        // "Duplicate column" o "already exists" no son errores reales
        if (stripos($msg, 'Duplicate column') !== false ||
            stripos($msg, 'already exists') !== false) {
            echo "<span class='ok'>✓ Ya existía</span></p>";
        } else {
            echo "<span class='err'>✗ Error</span></p><pre>" . htmlspecialchars($msg) . "</pre>";
            $allOk = false;
        }
    }
}

echo "<hr>";
if ($allOk) {
    echo "<p class='ok'><strong>✓ Migración completada.</strong> Borrá este archivo del servidor.</p>";
} else {
    echo "<p class='err'><strong>✗ Hubo errores. Revisá los mensajes arriba.</strong></p>";
}

echo "</body></html>";

<?php
/**
 * Migración v28 — ejecutar UNA VEZ y luego borrar este archivo.
 * Acceder vía: https://tu-dominio/db/migrate_v28.php?token=attos_migrate_v28
 */

define('EXPECTED_TOKEN', 'attos_migrate_v28');

if (($_GET['token'] ?? '') !== EXPECTED_TOKEN) {
    http_response_code(403);
    die('Acceso denegado.');
}

require_once __DIR__ . '/../config/db.php';

$db = getDB();

$steps = [
    'Crear tabla caja_saldo_inicial_usuario' =>
        "CREATE TABLE IF NOT EXISTS caja_saldo_inicial_usuario (
            usuario_id     INT           PRIMARY KEY,
            efectivo       DECIMAL(12,2) NOT NULL DEFAULT 0,
            transferencia  DECIMAL(12,2) NOT NULL DEFAULT 0,
            updated_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
        )",

    'Crear tabla caja_arqueos' =>
        "CREATE TABLE IF NOT EXISTS caja_arqueos (
            id                     INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id             INT           NOT NULL,
            efectivo_calculado     DECIMAL(12,2) NOT NULL,
            efectivo_contado       DECIMAL(12,2) NOT NULL,
            diferencia_efectivo    DECIMAL(12,2) NOT NULL,
            transferencia_calculado DECIMAL(12,2) NOT NULL,
            transferencia_contado  DECIMAL(12,2) NOT NULL,
            diferencia_transferencia DECIMAL(12,2) NOT NULL,
            notas                  VARCHAR(500)  NULL,
            ajustado               TINYINT(1)    NOT NULL DEFAULT 0,
            ajustado_at            TIMESTAMP     NULL DEFAULT NULL,
            created_at             TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
            INDEX idx_usuario_created (usuario_id, created_at)
        )",

    'Agregar concepto ajuste a caja_movimientos' =>
        "ALTER TABLE caja_movimientos
         MODIFY COLUMN concepto ENUM('venta','pago_proveedor','compra_dolares','sueldo','gasto','ajuste','otro') NOT NULL",
];

echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'>
<title>Migración v28</title>
<style>
  body { font-family: monospace; max-width: 700px; margin: 40px auto; padding: 0 20px; }
  .ok  { color: #2d7a4f; }
  .err { color: #c0392b; }
  pre  { background: #f5f5f5; padding: 10px; border-radius: 4px; white-space: pre-wrap; word-break: break-all; }
</style></head><body>";

echo "<h2>Migración ATTOS v28 — Caja por usuario</h2>";
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

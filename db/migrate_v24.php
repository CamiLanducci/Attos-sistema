<?php
/**
 * Migración v24 — ejecutar UNA VEZ y luego borrar este archivo.
 * Acceder vía: /db/migrate_v24.php?token=attos_migrate_v24
 */
if (($_GET['token'] ?? '') !== 'attos_migrate_v24') { http_response_code(403); die('Acceso denegado.'); }
require_once __DIR__ . '/../config/db.php';
$db = getDB();

$steps = [
    'Crear tabla caja_saldo_inicial' =>
        "CREATE TABLE IF NOT EXISTS caja_saldo_inicial (
            id             TINYINT       PRIMARY KEY DEFAULT 1,
            efectivo       DECIMAL(12,2) NOT NULL DEFAULT 0,
            transferencia  DECIMAL(12,2) NOT NULL DEFAULT 0,
            dolares        DECIMAL(10,4) NOT NULL DEFAULT 0,
            dolares_precio DECIMAL(10,2) NOT NULL DEFAULT 1000,
            updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",
    'Insertar fila base saldo_inicial' =>
        "INSERT IGNORE INTO caja_saldo_inicial (id) VALUES (1)",
    'Agregar stock_cajas a productos' =>
        "ALTER TABLE productos ADD COLUMN stock_cajas    INT           NOT NULL DEFAULT 0 AFTER descripcion",
    'Agregar stock_unidades a productos' =>
        "ALTER TABLE productos ADD COLUMN stock_unidades INT           NOT NULL DEFAULT 0 AFTER stock_cajas",
    'Agregar costo_compra a productos' =>
        "ALTER TABLE productos ADD COLUMN costo_compra   DECIMAL(12,2) NULL               AFTER stock_unidades",
];

echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><title>Migración v24</title>
<style>body{font-family:monospace;max-width:700px;margin:40px auto;padding:0 20px}
.ok{color:#2d7a4f}.err{color:#c0392b}pre{background:#f5f5f5;padding:10px;border-radius:4px;white-space:pre-wrap}</style></head><body>";
echo "<h2>Migración ATTOS v24</h2><hr>";
$allOk = true;
foreach ($steps as $label => $sql) {
    echo "<p><strong>$label…</strong> ";
    try {
        $db->exec($sql);
        echo "<span class='ok'>✓ OK</span></p>";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate column') !== false || stripos($msg, 'already exists') !== false) {
            echo "<span class='ok'>✓ Ya existía</span></p>";
        } else {
            echo "<span class='err'>✗ Error</span></p><pre>" . htmlspecialchars($msg) . "</pre>";
            $allOk = false;
        }
    }
}
echo "<hr>";
echo $allOk
    ? "<p class='ok'><strong>✓ Migración completada. Borrá este archivo.</strong></p>"
    : "<p class='err'><strong>✗ Hubo errores.</strong></p>";
echo "</body></html>";

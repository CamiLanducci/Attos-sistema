-- ============================================================
--  ATTOS - Limpieza de productos basura (código LIKE '%Precio')
--  Diagnóstico previo confirmó: 1362 registros, 0 referencias.
--  Ejecutar en phpMyAdmin contra la base `attos`.
-- ============================================================

USE attos;

START TRANSACTION;

-- Verificación de seguridad: abortar si hay alguna referencia inesperada
SELECT
    (SELECT COUNT(DISTINCT p.id)
     FROM productos p
     JOIN comprobante_items ci ON ci.producto_id = p.id
     WHERE p.activo = 0 AND p.codigo LIKE '%Precio') AS en_comprobantes,
    (SELECT COUNT(DISTINCT p.id)
     FROM productos p
     JOIN lista_precios lp ON lp.producto_id = p.id
     WHERE p.activo = 0 AND p.codigo LIKE '%Precio') AS en_lista_precios;

-- Si ambas columnas son 0, continuar con el DELETE:
DELETE FROM productos
WHERE activo = 0
  AND codigo LIKE '%Precio';

-- Confirmar cuántos se borraron
SELECT ROW_COUNT() AS eliminados;

COMMIT;

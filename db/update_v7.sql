-- ============================================================
--  ATTOS - Actualización v7
--  Distinguir productos manuales (gaseosas/energizantes) de los
--  que vienen del proveedor por URL.
--  IDEMPOTENTE: se puede correr más de una vez sin romper.
-- ============================================================

USE attos;

-- 1) Agregar columna `origen` a productos
ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS origen ENUM('url','manual')
        NOT NULL DEFAULT 'url' AFTER costo_neto;

-- 2) Marcar como 'manual' los productos de la categoría
-- 'Gaseosas y Energizantes'
UPDATE productos
SET origen = 'manual'
WHERE activo = 1
  AND categoria = 'Gaseosas y Energizantes';

-- 3) Recalcular costo_neto para los productos manuales:
-- usar MIN(lp.costo) directamente, sin dividir por margen.
-- (Los manuales tienen el mismo costo en todas las listas, así
-- que MIN, MAX o AVG dan lo mismo; usamos MIN por convención.)
UPDATE productos p
JOIN (
    SELECT producto_id, MIN(costo) AS costo_real
    FROM lista_precios
    WHERE costo > 0
    GROUP BY producto_id
) x ON x.producto_id = p.id
SET p.costo_neto = x.costo_real
WHERE p.activo = 1
  AND p.origen = 'manual';

-- 4) Verificación: mostrar los productos manuales con su nuevo costo_neto
SELECT id, codigo, nombre, categoria, origen, costo_neto
FROM productos
WHERE origen = 'manual'
ORDER BY id;

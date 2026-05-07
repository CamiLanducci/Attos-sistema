-- ============================================================
--  ATTOS - Actualización v6 (REVISADA)
--  Migración a costo_neto basado en lista_precios
--  IDEMPOTENTE: se puede correr más de una vez sin romper.
-- ============================================================

USE attos;

-- 1) Tabla config (idempotente)
CREATE TABLE IF NOT EXISTS config (
    clave VARCHAR(50) PRIMARY KEY,
    valor TEXT NULL
);

INSERT IGNORE INTO config (clave, valor) VALUES
    ('url_proveedor',       NULL),
    ('lista_referencia_id', NULL);

-- 2) Columna costo_neto en productos
ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS costo_neto DECIMAL(12,2) NULL AFTER contenido;

-- 3) Migrar costo_neto desde lista_precios.
-- Para cada producto, tomamos el promedio de (costo / (1 + margen/100))
-- de todas las filas de lista_precios donde aparece. El promedio absorbe
-- los redondeos de centavos entre listas.
UPDATE productos p
JOIN (
    SELECT lp.producto_id,
           AVG(lp.costo / (1 + l.margen / 100)) AS neto
    FROM lista_precios lp
    JOIN listas l ON l.id = lp.lista_id
    WHERE lp.costo > 0
    GROUP BY lp.producto_id
) x ON x.producto_id = p.id
SET p.costo_neto = ROUND(x.neto, 2)
WHERE p.activo = 1
  AND p.costo_neto IS NULL;

-- 4) Diagnóstico: productos cuyo costo_neto difiere mucho entre listas.
-- Si desvio_pct > 5, hay precios mal cargados en alguna lista.
SELECT
    p.id,
    p.codigo,
    p.nombre,
    COUNT(lp.id)                                                 AS listas_con_precio,
    ROUND(MIN(lp.costo / (1 + l.margen / 100)), 2)              AS neto_min,
    ROUND(MAX(lp.costo / (1 + l.margen / 100)), 2)              AS neto_max,
    ROUND(p.costo_neto, 2)                                       AS neto_promedio,
    ROUND(
      (MAX(lp.costo / (1 + l.margen / 100)) -
       MIN(lp.costo / (1 + l.margen / 100))) /
       NULLIF(MIN(lp.costo / (1 + l.margen / 100)), 0) * 100, 2
    )                                                            AS desvio_pct
FROM productos p
JOIN lista_precios lp ON lp.producto_id = p.id
JOIN listas l         ON l.id = lp.lista_id
WHERE p.activo = 1
  AND lp.costo > 0
GROUP BY p.id, p.codigo, p.nombre, p.costo_neto
HAVING desvio_pct > 1
ORDER BY desvio_pct DESC
LIMIT 30;

-- 5) NOTA: ni listas.url_actualizacion ni la tabla lista_precios se
-- eliminan en esta migración. Quedan para limpiar en update_v7.sql
-- una vez que el código nuevo esté en producción.

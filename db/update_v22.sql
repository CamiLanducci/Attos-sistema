-- ATTOS — v22: Marcar cervezas existentes como "precio por pack"
-- Ejecutar en phpMyAdmin → pestaña SQL, o en la CLI: source db/update_v22.sql
--
-- ANTES de ejecutar, corré esto para ver las marcas disponibles en tu base:
--   SELECT DISTINCT TRIM(marca) AS marca, COUNT(*) AS cant
--   FROM productos WHERE activo = 1
--   GROUP BY TRIM(marca) ORDER BY marca;
--
-- Agregá o quitá marcas en el IN (...) según lo que corresponda.

USE attos;

UPDATE productos
SET precio_por_pack = 1,
    categoria       = 'Cerveza'
WHERE activo = 1
  AND precio_por_pack = 0
  AND LOWER(TRIM(COALESCE(marca, ''))) IN (
    'corona',
    'quilmes',
    'brahma',
    'heineken',
    'stella artois',
    'isenbeck',
    'warsteiner',
    'schneider',
    'palermo',
    'imperial',
    'budweiser',
    'amstel',
    'kunstmann',
    'patagonia',
    'andina'
    -- agregá más marcas acá según tu catálogo
  );

-- Verificación: muestra todos los productos marcados como cerveza
SELECT id, codigo, nombre, marca, unidades_por_caja, precio_por_pack, categoria
FROM productos
WHERE categoria = 'Cerveza'
ORDER BY marca, nombre;

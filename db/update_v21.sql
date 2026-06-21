-- ATTOS — v21: Categoría de productos para cálculo correcto de precios
-- Ejecutar en phpMyAdmin o MySQL CLI: source db/update_v21.sql

USE attos;

-- ─── Si precio_por_pack=1 y la categoria ya tenía 'cerveza' en el import ─────
UPDATE productos
SET categoria = 'Cerveza'
WHERE (LOWER(categoria) LIKE '%cerveza%')
  AND (categoria != 'Cerveza' OR categoria IS NULL);

-- ─── Marcar precio_por_pack=1 para todos los productos con categoria Cerveza ──
-- (solo si aún tienen el valor incorrecto 0)
UPDATE productos
SET precio_por_pack = 1
WHERE LOWER(COALESCE(categoria,'')) LIKE '%cerveza%'
  AND precio_por_pack = 0;

-- ─── Consulta diagnóstico: muestra productos con precio_por_pack=1 ────────────
-- SELECT id, codigo, nombre, marca, categoria, precio_por_pack, unidades_por_caja
-- FROM productos WHERE precio_por_pack = 1 ORDER BY categoria, nombre;

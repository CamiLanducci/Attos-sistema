-- ============================================================
--  ATTOS — update_v13.sql
--  Agrega mostrar_precio en productos.
--  Marcar Bressia y línea Monteagrello/Conjuro sin precio público.
-- ============================================================

ALTER TABLE productos
  ADD COLUMN mostrar_precio TINYINT(1) NOT NULL DEFAULT 1
  COMMENT 'Si 0, el catálogo PDF muestra "Consultar" en lugar del precio';

UPDATE productos SET mostrar_precio = 0
WHERE codigo IN ('1944','1101','1946','1168','1945','29','25','23','22','24','27','26','1855');

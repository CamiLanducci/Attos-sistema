-- update_v9.sql
-- Reemplaza columna `cantidad` por `cajas` y `unidades` en pedidos_galpon_items

ALTER TABLE pedidos_galpon_items
  ADD COLUMN cajas    INT NOT NULL DEFAULT 0 AFTER nombre,
  ADD COLUMN unidades INT NOT NULL DEFAULT 0 AFTER cajas;

-- Migrar datos existentes: lo que era "cantidad" pasa a "cajas"
UPDATE pedidos_galpon_items SET cajas = cantidad WHERE cantidad > 0;

ALTER TABLE pedidos_galpon_items DROP COLUMN cantidad;

-- update_v11.sql
-- Reemplaza cantidad_pedida por cajas + unidades en pedidos_galpon_items

ALTER TABLE pedidos_galpon_items
  CHANGE COLUMN cantidad_pedida cajas    INT NOT NULL DEFAULT 0,
  ADD   COLUMN  unidades        INT NOT NULL DEFAULT 0 AFTER cajas;

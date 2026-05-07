-- ATTOS — Actualización v3
-- Ejecutar en phpMyAdmin si ya tenés la BD instalada

ALTER TABLE comprobante_items
    ADD COLUMN cantidad_unidades INT NOT NULL DEFAULT 0 AFTER cantidad_cajas;

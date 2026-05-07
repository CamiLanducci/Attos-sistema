-- ATTOS — Actualización v4
-- Ejecutar en phpMyAdmin o MySQL CLI

ALTER TABLE productos
    ADD COLUMN precio_por_pack TINYINT(1) NOT NULL DEFAULT 0
    AFTER unidades_por_caja;

-- ATTOS — Actualización v5
-- Ejecutar en phpMyAdmin o MySQL CLI

ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS categoria VARCHAR(100) DEFAULT NULL AFTER marca;

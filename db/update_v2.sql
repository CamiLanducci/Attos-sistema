-- ============================================================
--  ATTOS — Actualización v2
--  Ejecutar en phpMyAdmin si ya tenés la BD instalada
-- ============================================================

-- Agregar URL y fecha de última actualización a listas
-- (Si ya existe la columna "link", la renombramos)
ALTER TABLE listas
    CHANGE COLUMN link url_actualizacion VARCHAR(700) DEFAULT NULL;

-- Si NO tenías la columna "link", usá esta línea en cambio:
-- ALTER TABLE listas ADD COLUMN url_actualizacion VARCHAR(700) DEFAULT NULL AFTER margen;

ALTER TABLE listas
    ADD COLUMN IF NOT EXISTS ultima_actualizacion DATETIME DEFAULT NULL AFTER url_actualizacion;

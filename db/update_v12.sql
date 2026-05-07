-- ============================================================
--  ATTOS — update_v12.sql
--  Agrega url_actualizacion por lista (importación directa,
--  sin cálculo de margen).
-- ============================================================

ALTER TABLE listas
  ADD COLUMN url_actualizacion VARCHAR(500) NULL AFTER codigo;

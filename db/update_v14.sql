-- ============================================================
--  ATTOS — update_v14.sql
--  Agrega columna `cuenta` en proveedores para mapear
--  cada proveedor a su cuenta contable (area_520 / alfre).
-- ============================================================

ALTER TABLE proveedores
  ADD COLUMN cuenta ENUM('area_520','alfre') NULL
  COMMENT 'Cuenta contable asociada para movimientos automáticos';

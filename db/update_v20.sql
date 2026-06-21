-- ATTOS — v20: Cobro mixto (efectivo + transferencia)
-- Ejecutar en phpMyAdmin o MySQL CLI: source db/update_v20.sql

USE attos;

-- ─── Extiende medio_pago para soportar cobro mixto ───────────
ALTER TABLE comprobantes
    MODIFY COLUMN medio_pago ENUM('efectivo','transferencia','mixto') NULL;

-- ─── Montos individuales para cobro mixto ────────────────────
ALTER TABLE comprobantes
    ADD COLUMN IF NOT EXISTS monto_efectivo      DECIMAL(12,2) NULL AFTER medio_pago,
    ADD COLUMN IF NOT EXISTS monto_transferencia DECIMAL(12,2) NULL AFTER monto_efectivo;

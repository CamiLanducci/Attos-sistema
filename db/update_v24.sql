-- ATTOS v24: Saldo inicial de caja + stock en productos
-- Seguro de ejecutar múltiples veces.

-- Saldo inicial de caja (fila única, id=1 siempre)
CREATE TABLE IF NOT EXISTS caja_saldo_inicial (
    id             TINYINT      PRIMARY KEY DEFAULT 1,
    efectivo       DECIMAL(12,2) NOT NULL DEFAULT 0,
    transferencia  DECIMAL(12,2) NOT NULL DEFAULT 0,
    dolares        DECIMAL(10,4) NOT NULL DEFAULT 0,
    dolares_precio DECIMAL(10,2) NOT NULL DEFAULT 1000,
    updated_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
INSERT IGNORE INTO caja_saldo_inicial (id) VALUES (1);

-- Stock y costo de compra en productos
ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS stock_cajas    INT           NOT NULL DEFAULT 0 AFTER descripcion,
    ADD COLUMN IF NOT EXISTS stock_unidades INT           NOT NULL DEFAULT 0 AFTER stock_cajas,
    ADD COLUMN IF NOT EXISTS costo_compra   DECIMAL(12,2) NULL               AFTER stock_unidades;

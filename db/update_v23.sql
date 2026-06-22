-- ATTOS — v23: Garantizar estructuras para cobro mixto y caja
-- Seguro de ejecutar múltiples veces (IF NOT EXISTS / IF EXISTS).
-- Ejecutar en phpMyAdmin o MySQL CLI: source db/update_v23.sql

USE attos;

-- ─── medio_pago en comprobantes: agregar 'mixto' si falta ────
ALTER TABLE comprobantes
    MODIFY COLUMN medio_pago ENUM('efectivo','transferencia','mixto') NULL;

-- ─── Columnas de cobro mixto en comprobantes ─────────────────
ALTER TABLE comprobantes
    ADD COLUMN IF NOT EXISTS monto_efectivo      DECIMAL(12,2) NULL AFTER medio_pago,
    ADD COLUMN IF NOT EXISTS monto_transferencia DECIMAL(12,2) NULL AFTER monto_efectivo;

-- ─── Tabla caja_movimientos (por si no se corrió update_v18) ─
CREATE TABLE IF NOT EXISTS caja_movimientos (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    tipo                ENUM('ingreso','egreso') NOT NULL,
    concepto            ENUM('venta','pago_proveedor','compra_dolares','sueldo','gasto','otro') NOT NULL,
    medio_pago          ENUM('efectivo','transferencia') NOT NULL,
    monto               DECIMAL(12,2) NOT NULL,
    monto_dolares       DECIMAL(10,4) NULL,
    precio_dolar_compra DECIMAL(10,2) NULL,
    precio_dolar_venta  DECIMAL(10,2) NULL,
    comprobante_id      INT           NULL,
    descripcion         VARCHAR(500)  NULL,
    usuario_id          INT           NULL,
    created_at          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tipo       (tipo),
    INDEX idx_concepto   (concepto),
    INDEX idx_medio_pago (medio_pago),
    INDEX idx_comp       (comprobante_id),
    INDEX idx_created    (created_at)
);

-- ─── Tabla movimientos_cuenta (por si no se corrió update_v8) ─
CREATE TABLE IF NOT EXISTS movimientos_cuenta (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    fecha              DATE            NOT NULL,
    cuenta             ENUM('area_520','alfre','patrimonio') NOT NULL,
    tipo               ENUM('cargo','pago') NOT NULL,
    monto              DECIMAL(12,2)   NOT NULL,
    descripcion        VARCHAR(500)    NULL,
    pedido_galpon_id   INT             NULL,
    comprobante_id     INT             NULL,
    movimiento_par_id  INT             NULL,
    creado_por         INT             NULL,
    created_at         TIMESTAMP       DEFAULT CURRENT_TIMESTAMP
);

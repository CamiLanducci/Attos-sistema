-- ATTOS — v18: Caja de Plata
-- Ejecutar en phpMyAdmin o MySQL CLI: source db/update_v18.sql

USE attos;

-- ─── Medio de pago en comprobantes (se registra al cobrar) ───
ALTER TABLE comprobantes
    ADD COLUMN IF NOT EXISTS medio_pago ENUM('efectivo','transferencia') NULL AFTER estado;

-- ─── Caja de Plata: tabla de movimientos ─────────────────────
CREATE TABLE IF NOT EXISTS caja_movimientos (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    tipo                ENUM('ingreso','egreso') NOT NULL,
    concepto            ENUM('venta','pago_proveedor','compra_dolares','sueldo','gasto','otro') NOT NULL,
    medio_pago          ENUM('efectivo','transferencia') NOT NULL,
    monto               DECIMAL(12,2) NOT NULL COMMENT 'Siempre en pesos, siempre positivo',
    monto_dolares       DECIMAL(10,4) NULL      COMMENT 'Solo para concepto=compra_dolares',
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

-- ─── Asignar rol=usuario a Bauti (ajustar si el usuario se llama diferente) ─
-- UPDATE usuarios SET rol = 'usuario' WHERE LOWER(usuario) LIKE '%bauti%' OR LOWER(nombre_real) LIKE '%bauti%';

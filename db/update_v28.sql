-- ATTOS — update_v28.sql
-- Caja por usuario: saldo inicial y arqueo (conteo físico vs. calculado) por usuario.
-- Los movimientos existentes (caja_movimientos) ya tienen usuario_id desde v15/v18,
-- así que no hace falta migrar datos: los que tengan usuario_id NULL quedan agrupados
-- como "histórico / sin asignar" junto con el saldo inicial global (caja_saldo_inicial,
-- que no se toca). Seguro de ejecutar múltiples veces.

USE attos;

-- ─── Saldo inicial por usuario (efectivo y transferencia, no dólares) ────────
CREATE TABLE IF NOT EXISTS caja_saldo_inicial_usuario (
    usuario_id     INT           PRIMARY KEY,
    efectivo       DECIMAL(12,2) NOT NULL DEFAULT 0,
    transferencia  DECIMAL(12,2) NOT NULL DEFAULT 0,
    updated_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

-- ─── Arqueos: conteo físico registrado por un usuario, contra su saldo calculado ─
-- ajustado/ajustado_at: si la diferencia se convirtió en un movimiento de ajuste
-- (ver concepto 'ajuste' más abajo) que corrige el saldo calculado hacia adelante
-- — pensado para casos como intereses bancarios que el sistema no registra solo.
CREATE TABLE IF NOT EXISTS caja_arqueos (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id             INT           NOT NULL,
    efectivo_calculado     DECIMAL(12,2) NOT NULL,
    efectivo_contado       DECIMAL(12,2) NOT NULL,
    diferencia_efectivo    DECIMAL(12,2) NOT NULL,
    transferencia_calculado DECIMAL(12,2) NOT NULL,
    transferencia_contado  DECIMAL(12,2) NOT NULL,
    diferencia_transferencia DECIMAL(12,2) NOT NULL,
    notas                  VARCHAR(500)  NULL,
    ajustado               TINYINT(1)    NOT NULL DEFAULT 0,
    ajustado_at            TIMESTAMP     NULL DEFAULT NULL,
    created_at             TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_usuario_created (usuario_id, created_at)
);

-- ─── Concepto 'ajuste' en caja_movimientos: la diferencia de un arqueo,
-- convertida en un movimiento real para que el saldo calculado quede en línea
-- con lo contado (ej. intereses u otros descuadres bancarios) ────────────────
ALTER TABLE caja_movimientos
    MODIFY COLUMN concepto ENUM('venta','pago_proveedor','compra_dolares','sueldo','gasto','ajuste','otro') NOT NULL;

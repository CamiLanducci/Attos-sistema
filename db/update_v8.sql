-- ============================================================
--  ATTOS — update_v8.sql
--  Nuevas tablas: pedidos_galpon, pedidos_galpon_items, movimientos_cuenta
--  Requiere MariaDB 10.2+ (CHECK enforced, ON DELETE SET NULL en self-ref FK).
--  Correr desde phpMyAdmin: USE attos; source db/update_v8.sql
-- ============================================================

USE attos;

CREATE TABLE IF NOT EXISTS pedidos_galpon (
    id              INT             AUTO_INCREMENT PRIMARY KEY,
    fecha           DATE            NOT NULL,
    destinatario    ENUM('area_520','alfre') NOT NULL,
    estado          ENUM('pendiente','facturado') NOT NULL DEFAULT 'pendiente',
    total_facturado DECIMAL(12,2)   NULL,
    notas           TEXT            NULL,
    created_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pedidos_galpon_items (
    id          INT             AUTO_INCREMENT PRIMARY KEY,
    pedido_id   INT             NOT NULL,
    producto_id INT             NOT NULL,
    codigo      VARCHAR(50)     NULL,
    nombre      VARCHAR(200)    NOT NULL,
    cantidad    INT             NOT NULL,
    FOREIGN KEY (pedido_id)   REFERENCES pedidos_galpon(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- movimiento_par_id: self-referencing FK con ON DELETE SET NULL.
-- Al eliminar un movimiento, el par pierde la referencia automáticamente.
CREATE TABLE IF NOT EXISTS movimientos_cuenta (
    id                 INT             AUTO_INCREMENT PRIMARY KEY,
    fecha              DATE            NOT NULL,
    cuenta             ENUM('area_520','alfre','patrimonio') NOT NULL,
    tipo               ENUM('cargo','pago') NOT NULL,
    monto              DECIMAL(12,2)   NOT NULL CHECK (monto > 0),
    descripcion        VARCHAR(500)    NULL,
    pedido_galpon_id   INT             NULL,
    comprobante_id     INT             NULL,
    movimiento_par_id  INT             NULL,
    created_at         TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pedido_galpon_id)  REFERENCES pedidos_galpon(id) ON DELETE SET NULL,
    FOREIGN KEY (comprobante_id)    REFERENCES comprobantes(id)   ON DELETE SET NULL,
    FOREIGN KEY (movimiento_par_id) REFERENCES movimientos_cuenta(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

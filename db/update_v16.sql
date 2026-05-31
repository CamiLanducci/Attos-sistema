

-- ATTOS — Actualización v16: Pedidos Galpón
-- Ejecutar en phpMyAdmin o MySQL CLI

CREATE TABLE IF NOT EXISTS pedidos_galpon (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    numero     INT          NOT NULL,
    fecha      DATE         NOT NULL,
    estado     ENUM('pendiente','preparado','despachado') NOT NULL DEFAULT 'pendiente',
    notas      TEXT         DEFAULT NULL,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pedidos_galpon_items (
    id                INT           AUTO_INCREMENT PRIMARY KEY,
    pedido_id         INT           NOT NULL,
    comprobante_id    INT           DEFAULT NULL,
    producto_id       INT           DEFAULT NULL,
    nombre_producto   VARCHAR(200)  NOT NULL,
    codigo_producto   VARCHAR(50)   DEFAULT NULL,
    cantidad_cajas    INT           NOT NULL DEFAULT 0,
    cantidad_unidades INT           NOT NULL DEFAULT 0,
    costo_unitario    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (pedido_id)      REFERENCES pedidos_galpon(id) ON DELETE CASCADE,
    FOREIGN KEY (comprobante_id) REFERENCES comprobantes(id)   ON DELETE SET NULL,
    FOREIGN KEY (producto_id)    REFERENCES productos(id)       ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS pedidos_galpon_comprobantes (
    pedido_id      INT NOT NULL,
    comprobante_id INT NOT NULL,
    PRIMARY KEY (pedido_id, comprobante_id),
    FOREIGN KEY (pedido_id)      REFERENCES pedidos_galpon(id) ON DELETE CASCADE,
    FOREIGN KEY (comprobante_id) REFERENCES comprobantes(id)   ON DELETE CASCADE
);

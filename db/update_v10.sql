-- ============================================================
--  ATTOS — update_v10.sql
--  Rediseño módulo pedidos_galpon:
--    - Nueva tabla proveedores
--    - pedidos_galpon: proveedor_id, estados borrador/enviado/recibido,
--      estado_pago, total editable manual, observaciones
--    - pedidos_galpon_items: cantidad_pedida, cantidad_recibida, costo_unitario, subtotal
--
--  NOTA: update_v9.sql puede omitirse — sus tablas están vacías y este
--  script las descarta y recrea completamente.
--
--  Aplicar desde phpMyAdmin: seleccionar base "attos", pestaña Import,
--  subir este archivo.
-- ============================================================

-- 1. Tabla de proveedores
CREATE TABLE IF NOT EXISTS proveedores (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    nombre     VARCHAR(200) NOT NULL,
    telefono   VARCHAR(50)  NULL,
    contacto   VARCHAR(200) NULL,
    activo     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Quitar FK de movimientos_cuenta → pedidos_galpon para poder DROP la tabla
ALTER TABLE movimientos_cuenta DROP FOREIGN KEY movimientos_cuenta_ibfk_1;

-- 3. Borrar tablas viejas (vacías)
DROP TABLE IF EXISTS pedidos_galpon_items;
DROP TABLE IF EXISTS pedidos_galpon;

-- 4. Crear pedidos_galpon con nuevo modelo
CREATE TABLE pedidos_galpon (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    proveedor_id     INT           NOT NULL,
    fecha_pedido     DATE          NOT NULL,
    fecha_recepcion  DATE          NULL,
    estado_pedido    ENUM('borrador','enviado','recibido') NOT NULL DEFAULT 'borrador',
    estado_pago      ENUM('pendiente','pagado')            NOT NULL DEFAULT 'pendiente',
    fecha_pago       DATE          NULL,
    total            DECIMAL(12,2) NULL,
    observaciones    TEXT          NULL,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (proveedor_id) REFERENCES proveedores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Crear pedidos_galpon_items con nuevo modelo
CREATE TABLE pedidos_galpon_items (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    pedido_id         INT           NOT NULL,
    producto_id       INT           NOT NULL,
    codigo            VARCHAR(50)   NULL,
    nombre            VARCHAR(200)  NOT NULL,
    cantidad_pedida   INT           NOT NULL DEFAULT 0,
    cantidad_recibida INT           NULL,
    costo_unitario    DECIMAL(12,2) NULL,
    subtotal          DECIMAL(12,2) NULL,
    FOREIGN KEY (pedido_id)   REFERENCES pedidos_galpon(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Restaurar FK en movimientos_cuenta
ALTER TABLE movimientos_cuenta
    ADD CONSTRAINT fk_movcuenta_pedidogalpon
    FOREIGN KEY (pedido_galpon_id) REFERENCES pedidos_galpon(id) ON DELETE SET NULL;

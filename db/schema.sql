-- ============================================================
--  ATTOS — Schema de base de datos
-- ============================================================

CREATE DATABASE IF NOT EXISTS attos
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE attos;

-- ─── LISTAS DE PRECIOS ───────────────────────────────────────
CREATE TABLE listas (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    codigo               VARCHAR(10)  NOT NULL UNIQUE,
    margen               DECIMAL(5,2) NOT NULL,
    url_actualizacion    VARCHAR(700) DEFAULT NULL,
    ultima_actualizacion DATETIME     DEFAULT NULL,
    updated_at           TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO listas (codigo, margen) VALUES
    ('l62', 25.00),
    ('l56', 20.00),
    ('l59', 15.00),
    ('l70', 10.00);

-- ─── CLIENTES ────────────────────────────────────────────────
CREATE TABLE clientes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    nombre     VARCHAR(150) NOT NULL,
    telefono   VARCHAR(50)  DEFAULT NULL,
    direccion  VARCHAR(200) DEFAULT NULL,
    ciudad     VARCHAR(100) DEFAULT NULL,
    email      VARCHAR(150) DEFAULT NULL,
    notas      TEXT         DEFAULT NULL,
    lista_id   INT          DEFAULT NULL,
    activo     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lista_id) REFERENCES listas(id) ON UPDATE CASCADE ON DELETE SET NULL
);

-- ─── PRODUCTOS ───────────────────────────────────────────────
CREATE TABLE productos (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    codigo            VARCHAR(50)   DEFAULT NULL,
    marca             VARCHAR(100)  NOT NULL DEFAULT '',
    nombre            VARCHAR(200)  NOT NULL,
    contenido         VARCHAR(100)  DEFAULT NULL,
    unidades_por_caja INT           NOT NULL DEFAULT 6,
    costo             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    descripcion       TEXT          DEFAULT NULL,
    activo            TINYINT(1)    NOT NULL DEFAULT 1,
    updated_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ─── PRECIOS POR LISTA (junction table) ──────────────────────
CREATE TABLE lista_precios (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    lista_id    INT           NOT NULL,
    producto_id INT           NOT NULL,
    costo       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    costo_caja  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    updated_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lista_prod (lista_id, producto_id),
    FOREIGN KEY (lista_id)    REFERENCES listas(id)    ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
);

-- ─── COMPROBANTES ────────────────────────────────────────────
CREATE TABLE comprobantes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    numero     INT           NOT NULL UNIQUE,
    cliente_id INT           NOT NULL,
    lista_id   INT           NOT NULL,
    fecha      DATE          NOT NULL,
    subtotal   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    envio      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    descuento  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    estado     ENUM('borrador','emitido','cobrado') NOT NULL DEFAULT 'emitido',
    notas      TEXT          DEFAULT NULL,
    created_at TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    FOREIGN KEY (lista_id)   REFERENCES listas(id)
);

-- ─── ITEMS DE COMPROBANTES (foto histórica) ──────────────────
CREATE TABLE comprobante_items (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    comprobante_id    INT           NOT NULL,
    producto_id       INT           DEFAULT NULL,
    nombre_producto   VARCHAR(200)  NOT NULL,
    unidades_por_caja INT           NOT NULL,
    costo_unitario    DECIMAL(12,2) NOT NULL,
    margen_aplicado   DECIMAL(5,2)  NOT NULL,
    precio_unitario   DECIMAL(12,2) NOT NULL,
    cantidad_cajas    INT           NOT NULL,
    cantidad_unidades INT           NOT NULL DEFAULT 0,
    subtotal          DECIMAL(12,2) NOT NULL,
    descuento_tipo    ENUM('ninguno','porcentaje','fijo') NOT NULL DEFAULT 'ninguno',
    descuento_valor   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    descuento_monto   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (comprobante_id) REFERENCES comprobantes(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id)    REFERENCES productos(id) ON DELETE SET NULL
);

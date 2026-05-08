-- ============================================================
--  ATTOS v15 — Sistema de Usuarios y Autenticación
--  Migración: Tabla de usuarios + trazabilidad creado_por
--
--  INSTRUCCIONES:
--  1. Ejecutar este script en MySQL:
--       source /path/to/attos/db/update_v15_usuarios.sql
--  2. Luego ejecutar en el navegador para crear los usuarios:
--       http://localhost/Attos/db/seed_usuarios.php
--  3. Eliminar seed_usuarios.php del servidor de producción.
-- ============================================================

USE attos;

-- ─── TABLA USUARIOS ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS usuarios (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    nombre_real   VARCHAR(150) NOT NULL,
    usuario       VARCHAR(50)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol           ENUM('admin', 'usuario') NOT NULL DEFAULT 'admin',
    activo        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login    DATETIME     DEFAULT NULL,
    INDEX idx_usuario (usuario),
    INDEX idx_activo  (activo)
);

-- ─── TABLA DE SESIONES (auditoría de logins) ────────────────
CREATE TABLE IF NOT EXISTS sesiones (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT          NOT NULL,
    ip_address  VARCHAR(45),
    user_agent  VARCHAR(500),
    login_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    logout_at   DATETIME     DEFAULT NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_usuario_id (usuario_id),
    INDEX idx_login_at   (login_at)
);

-- ─── TRAZABILIDAD EN COMPROBANTES ───────────────────────────
ALTER TABLE comprobantes
    ADD COLUMN IF NOT EXISTS creado_por    INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS modificado_por INT DEFAULT NULL;

ALTER TABLE comprobantes
    ADD CONSTRAINT fk_comp_creado_por    FOREIGN KEY (creado_por)    REFERENCES usuarios(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_comp_modificado_por FOREIGN KEY (modificado_por) REFERENCES usuarios(id) ON DELETE SET NULL;

-- ─── TRAZABILIDAD EN PEDIDOS_GALPON ─────────────────────────
ALTER TABLE pedidos_galpon
    ADD COLUMN IF NOT EXISTS creado_por    INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS modificado_por INT DEFAULT NULL;

ALTER TABLE pedidos_galpon
    ADD CONSTRAINT fk_ped_creado_por    FOREIGN KEY (creado_por)    REFERENCES usuarios(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_ped_modificado_por FOREIGN KEY (modificado_por) REFERENCES usuarios(id) ON DELETE SET NULL;

-- ─── TRAZABILIDAD EN MOVIMIENTOS_CUENTA ─────────────────────
ALTER TABLE movimientos_cuenta
    ADD COLUMN IF NOT EXISTS creado_por INT DEFAULT NULL;

ALTER TABLE movimientos_cuenta
    ADD CONSTRAINT fk_mov_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL;

-- ─── CAMPOS LOGÍSTICOS EN CLIENTES (reparto futuro) ─────────
ALTER TABLE clientes
    ADD COLUMN IF NOT EXISTS coordenadas_lat DECIMAL(10, 8) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS coordenadas_lng DECIMAL(11, 8) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS zona_reparto    VARCHAR(50)    DEFAULT NULL;

-- ─── TIPO DE ENTREGA EN COMPROBANTES (faltaba migración) ───
ALTER TABLE comprobantes
    ADD COLUMN IF NOT EXISTS tipo_entrega ENUM('envio','retira') NOT NULL DEFAULT 'envio' AFTER envio;

-- ─── CAMPOS LOGÍSTICOS EN COMPROBANTES (reparto futuro) ─────
ALTER TABLE comprobantes
    ADD COLUMN IF NOT EXISTS estado_entrega  ENUM('pendiente','en_transito','entregado','cancelado') DEFAULT 'pendiente',
    ADD COLUMN IF NOT EXISTS fecha_entrega   DATETIME     DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS distancia_km    DECIMAL(8,2) DEFAULT NULL;

-- ─── ÍNDICES DE PERFORMANCE ──────────────────────────────────
CREATE INDEX IF NOT EXISTS idx_comprobantes_creado_por ON comprobantes(creado_por);
CREATE INDEX IF NOT EXISTS idx_comprobantes_fecha       ON comprobantes(fecha);
CREATE INDEX IF NOT EXISTS idx_pedidos_creado_por       ON pedidos_galpon(creado_por);
CREATE INDEX IF NOT EXISTS idx_movimientos_creado_por   ON movimientos_cuenta(creado_por);

-- ─── NOTA SOBRE MySQL 5.7 ────────────────────────────────────
-- Si tu servidor usa MySQL 5.7 (no soporta ADD COLUMN IF NOT EXISTS),
-- reemplazá "ADD COLUMN IF NOT EXISTS" por "ADD COLUMN" y ejecutá
-- solo las líneas para las tablas que aún no tienen esas columnas.

COMMIT;

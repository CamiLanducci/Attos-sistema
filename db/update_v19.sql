-- ATTOS — v19: Rol 'empleado'

USE attos;

ALTER TABLE usuarios
    MODIFY COLUMN rol ENUM('admin','empleado','usuario') NOT NULL DEFAULT 'admin';

UPDATE usuarios SET rol = 'empleado' WHERE usuario = 'Bauti';

-- ATTOS v25: Historial de la última importación aplicada por lista
-- Permite generar los PDF de cambios (aumentos / aumentos y bajas) desde el
-- dashboard de Listas en cualquier momento, sin depender de la sesión de
-- importación (que expira a los 30 min).
-- Seguro de ejecutar múltiples veces.

CREATE TABLE IF NOT EXISTS lista_import_log (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    lista_id       INT      NOT NULL,
    fecha          DATETIME NOT NULL,
    changes_json   LONGTEXT NOT NULL,
    new_prods_json LONGTEXT NOT NULL,
    UNIQUE KEY uq_lista_import_log (lista_id),
    FOREIGN KEY (lista_id) REFERENCES listas(id) ON DELETE CASCADE
);

-- ATTOS — update_v27.sql
-- Permite que una lista derive sus precios de otra lista (el proveedor sólo publica el listado del 20%
-- y las demás listas se calculan a partir de ese, ajustando el precio según
-- la diferencia de margen entre listas).

ALTER TABLE listas
    ADD COLUMN deriva_de_lista_id INT DEFAULT NULL AFTER url_actualizacion,
    ADD CONSTRAINT fk_listas_deriva_de FOREIGN KEY (deriva_de_lista_id) REFERENCES listas(id) ON DELETE SET NULL;

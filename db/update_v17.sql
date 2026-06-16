-- ATTOS — Actualización v17: número de comprobante por cliente
-- Requiere MariaDB 10.2+ o MySQL 8.0+ (XAMPP moderno cumple ambas)
-- Ejecutar en phpMyAdmin o MySQL CLI

ALTER TABLE comprobantes
    ADD COLUMN IF NOT EXISTS numero_cliente INT NULL AFTER numero;

-- Backfill: asignar número secuencial por cliente, en orden del número global
UPDATE comprobantes c
JOIN (
    SELECT id,
           ROW_NUMBER() OVER (PARTITION BY cliente_id ORDER BY numero) AS nc
    FROM comprobantes
) x ON c.id = x.id
SET c.numero_cliente = x.nc;

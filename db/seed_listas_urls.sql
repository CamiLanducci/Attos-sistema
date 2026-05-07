-- ============================================================
--  ATTOS — seed_listas_urls.sql
--  Completar las URLs reales del proveedor antes de correr.
--  Correr DESPUÉS de update_v12.sql.
-- ============================================================

-- Lista L70 (10%)
UPDATE listas SET url_actualizacion = 'URL_LISTA_10' WHERE id = 4;

-- Lista L59 (15%)
UPDATE listas SET url_actualizacion = 'URL_LISTA_15' WHERE id = 3;

-- Lista L56 (20%)
UPDATE listas SET url_actualizacion = 'URL_LISTA_20' WHERE id = 2;

-- Lista L62 (25%)
UPDATE listas SET url_actualizacion = 'URL_LISTA_25' WHERE id = 1;

-- Lista L65 (30%)
UPDATE listas SET url_actualizacion = 'URL_LISTA_30' WHERE id = 8;

-- Lista L74 (7%) — dejar sin URL si no viene del proveedor
-- UPDATE listas SET url_actualizacion = 'URL_LISTA_7' WHERE id = 7;

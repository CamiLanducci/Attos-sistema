-- ATTOS v26: evitar productos duplicados por código
-- Contexto: se detectaron 59 productos duplicados (mismo código, distinto id)
-- creados por error durante una importación de la lista l70. Cada duplicado
-- terminó con su propia fila en lista_precios, y como la importación siempre
-- actualiza el producto "canónico" (el de menor id) pero la comparación de
-- precios podía leer la fila del duplicado, los mismos aumentos se aplicaban
-- una y otra vez sin que el sistema se diera cuenta. Los 59 duplicados ya se
-- eliminaron a mano (verificado: ninguno tenía stock ni uso en comprobantes
-- o pedidos de galpón). Este ALTER evita que vuelva a pasar.
-- Seguro de ejecutar una sola vez (fallará si ya existe el índice, o si
-- vuelven a aparecer códigos duplicados — en ese caso hay que limpiarlos antes).

ALTER TABLE productos
    ADD UNIQUE KEY uq_productos_codigo (codigo);

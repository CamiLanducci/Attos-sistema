<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$db     = getDB();
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

if ($action === 'create') {
    $numero     = (int)($_POST['numero']     ?? 0);
    $fecha      = $_POST['fecha']            ?? date('Y-m-d');
    $cliente_id = (int)($_POST['cliente_id'] ?? 0);
    $lista_id   = (int)($_POST['lista_id']   ?? 0);
    $estado     = $_POST['estado']           ?? 'emitido';
    $notas      = trim($_POST['notas']       ?? '');
    $envio        = max(0.0, (float)str_replace(',', '.', $_POST['envio'] ?? '0'));
    $tipo_entrega = in_array($_POST['tipo_entrega'] ?? '', ['envio','retira']) ? $_POST['tipo_entrega'] : 'envio';
    $items        = $_POST['items'] ?? [];

    if (!$cliente_id || !$lista_id || empty($items)) {
        redirect(BASE_PATH . '/comprobantes/crear.php');
    }

    // Obtener margen de la lista desde la DB — no del cliente
    $listaRow = $db->prepare("SELECT margen FROM listas WHERE id=?");
    $listaRow->execute([$lista_id]);
    $margen = (float)($listaRow->fetchColumn() ?? 0);
    if (!$margen && $margen !== 0.0) redirect(BASE_PATH . '/comprobantes/crear.php');

    // Número secuencial por cliente
    $stmtNc = $db->prepare("SELECT COALESCE(MAX(numero_cliente), 0) + 1 FROM comprobantes WHERE cliente_id = ?");
    $stmtNc->execute([$cliente_id]);
    $numeroCliente = (int)$stmtNc->fetchColumn();

    $db->beginTransaction();
    try {
        // Insertar encabezado con totales en 0; se actualizan al final con valores calculados en servidor
        $stmt = $db->prepare("INSERT INTO comprobantes (numero, numero_cliente, fecha, cliente_id, lista_id, estado, notas, envio, tipo_entrega, subtotal, total, creado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$numero, $numeroCliente, $fecha, $cliente_id, $lista_id, $estado, $notas, $envio, $tipo_entrega, 0, 0, $_SESSION['usuario_id']]);
        $compId = $db->lastInsertId();

        $stmtItem = $db->prepare("
            INSERT INTO comprobante_items
                (comprobante_id, producto_id, nombre_producto, costo_unitario, margen_aplicado, precio_unitario, unidades_por_caja, cantidad_cajas, cantidad_unidades, subtotal, descuento_tipo, descuento_valor, descuento_monto)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmtProd = $db->prepare("
            SELECT p.nombre, p.marca, p.unidades_por_caja, p.precio_por_pack, COALESCE(p.categoria,'') AS categoria, lp.costo
            FROM productos p
            JOIN lista_precios lp ON lp.producto_id = p.id AND lp.lista_id = ?
            WHERE p.id = ? AND p.activo = 1
        ");

        $subtotalCalculado = 0.0;
        $descuentoTotal    = 0.0;

        foreach ($items as $item) {
            $pid      = (int)($item['producto_id']       ?? 0);
            $cajas    = (int)($item['cantidad_cajas']    ?? 0);
            $unidades = (int)($item['cantidad_unidades'] ?? 0);
            if (!$pid || ($cajas <= 0 && $unidades <= 0)) continue;

            // Precio y datos del producto siempre desde lista_precios — nunca del POST
            $stmtProd->execute([$lista_id, $pid]);
            $p = $stmtProd->fetch();
            if (!$p) continue;

            $upc = (int)$p['unidades_por_caja'];
            $pr  = calcularPreciosProducto(
                (float)$p['costo'], $margen,
                $upc, (int)$p['precio_por_pack'],
                $p['categoria'], $p['marca'] ?? ''
            );
            $pUnit         = $pr['precio_unit'];
            $pCaja         = $pr['precio_caja'];
            $costoUnitario = $pr['costo_unit'];
            $sub = round($pCaja * $cajas + $pUnit * $unidades, 2);

            // Descuento server-side
            $dTipo  = in_array($item['descuento_tipo'] ?? '', ['porcentaje', 'fijo'])
                      ? $item['descuento_tipo'] : 'ninguno';
            $dValor = max(0.0, (float)($item['descuento_valor'] ?? 0));
            $dMonto = 0.0;
            if ($dTipo === 'porcentaje' && $dValor > 0)
                $dMonto = round($sub * min($dValor, 100) / 100, 2);
            elseif ($dTipo === 'fijo' && $dValor > 0)
                $dMonto = round(min($dValor, $sub), 2);

            $stmtItem->execute([
                $compId, $pid, $p['nombre'],
                $costoUnitario, $margen, round($pUnit, 4),
                $upc, $cajas, $unidades, $sub,
                $dTipo, $dValor, $dMonto,
            ]);

            $subtotalCalculado += $sub;
            $descuentoTotal    += $dMonto;
        }

        // Totales calculados enteramente en servidor
        $subtotalCalculado = round($subtotalCalculado, 2);
        $descuentoTotal    = round($descuentoTotal, 2);
        $totalCalculado    = round($subtotalCalculado + $envio - $descuentoTotal, 2);

        $db->prepare("UPDATE comprobantes SET subtotal=?, descuento=?, total=? WHERE id=?")
           ->execute([$subtotalCalculado, $descuentoTotal, $totalCalculado, $compId]);

        $db->commit();
        redirect(BASE_PATH . '/comprobantes/ver.php?id=' . $compId . '&msg=created');
    } catch (Exception $e) {
        $db->rollBack();
        redirect(BASE_PATH . '/comprobantes/crear.php');
    }
}

if ($action === 'update') {
    $id         = (int)($_POST['id']         ?? 0);
    $fecha      = $_POST['fecha']            ?? date('Y-m-d');
    $cliente_id = (int)($_POST['cliente_id'] ?? 0);
    $lista_id   = (int)($_POST['lista_id']   ?? 0);
    $estado     = $_POST['estado']           ?? 'borrador';
    $notas      = trim($_POST['notas']       ?? '');
    $envio        = max(0.0, (float)str_replace(',', '.', $_POST['envio'] ?? '0'));
    $tipo_entrega = in_array($_POST['tipo_entrega'] ?? '', ['envio','retira']) ? $_POST['tipo_entrega'] : 'envio';
    $items        = $_POST['items'] ?? [];

    if (!$id || !$cliente_id || !$lista_id || empty($items)) {
        redirect(BASE_PATH . '/comprobantes/crear.php?id=' . $id);
    }

    // Verify the comprobante exists and is still borrador
    $chk = $db->prepare("SELECT estado FROM comprobantes WHERE id=?");
    $chk->execute([$id]);
    $current = $chk->fetchColumn();
    if ($current !== 'borrador') {
        redirect(BASE_PATH . '/comprobantes/ver.php?id=' . $id . '&msg=not_borrador');
    }

    $listaRow = $db->prepare("SELECT margen FROM listas WHERE id=?");
    $listaRow->execute([$lista_id]);
    $margen = (float)($listaRow->fetchColumn() ?? 0);

    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM comprobante_items WHERE comprobante_id=?")->execute([$id]);

        $stmtItem = $db->prepare("
            INSERT INTO comprobante_items
                (comprobante_id, producto_id, nombre_producto, costo_unitario, margen_aplicado, precio_unitario, unidades_por_caja, cantidad_cajas, cantidad_unidades, subtotal, descuento_tipo, descuento_valor, descuento_monto)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmtProd = $db->prepare("
            SELECT p.nombre, p.marca, p.unidades_por_caja, p.precio_por_pack, COALESCE(p.categoria,'') AS categoria, lp.costo
            FROM productos p
            JOIN lista_precios lp ON lp.producto_id = p.id AND lp.lista_id = ?
            WHERE p.id = ? AND p.activo = 1
        ");

        $subtotalCalculado = 0.0;
        $descuentoTotal    = 0.0;

        foreach ($items as $item) {
            $pid      = (int)($item['producto_id']       ?? 0);
            $cajas    = (int)($item['cantidad_cajas']    ?? 0);
            $unidades = (int)($item['cantidad_unidades'] ?? 0);
            if (!$pid || ($cajas <= 0 && $unidades <= 0)) continue;

            $stmtProd->execute([$lista_id, $pid]);
            $p = $stmtProd->fetch();
            if (!$p) continue;

            $upc = (int)$p['unidades_por_caja'];
            $pr  = calcularPreciosProducto(
                (float)$p['costo'], $margen,
                $upc, (int)$p['precio_por_pack'],
                $p['categoria'], $p['marca'] ?? ''
            );
            $pUnit         = $pr['precio_unit'];
            $pCaja         = $pr['precio_caja'];
            $costoUnitario = $pr['costo_unit'];
            $sub = round($pCaja * $cajas + $pUnit * $unidades, 2);

            $dTipo  = in_array($item['descuento_tipo'] ?? '', ['porcentaje', 'fijo'])
                      ? $item['descuento_tipo'] : 'ninguno';
            $dValor = max(0.0, (float)($item['descuento_valor'] ?? 0));
            $dMonto = 0.0;
            if ($dTipo === 'porcentaje' && $dValor > 0)
                $dMonto = round($sub * min($dValor, 100) / 100, 2);
            elseif ($dTipo === 'fijo' && $dValor > 0)
                $dMonto = round(min($dValor, $sub), 2);

            $stmtItem->execute([
                $id, $pid, $p['nombre'],
                $costoUnitario, $margen, round($pUnit, 4),
                $upc, $cajas, $unidades, $sub,
                $dTipo, $dValor, $dMonto,
            ]);

            $subtotalCalculado += $sub;
            $descuentoTotal    += $dMonto;
        }

        $subtotalCalculado = round($subtotalCalculado, 2);
        $descuentoTotal    = round($descuentoTotal, 2);
        $totalCalculado    = round($subtotalCalculado + $envio - $descuentoTotal, 2);

        $allowed = ['borrador', 'emitido', 'cobrado'];
        $estadoFinal = in_array($estado, $allowed) ? $estado : 'borrador';

        $db->prepare("UPDATE comprobantes SET fecha=?, cliente_id=?, lista_id=?, estado=?, notas=?, envio=?, tipo_entrega=?, subtotal=?, descuento=?, total=?, modificado_por=? WHERE id=?")
           ->execute([$fecha, $cliente_id, $lista_id, $estadoFinal, $notas, $envio, $tipo_entrega, $subtotalCalculado, $descuentoTotal, $totalCalculado, $_SESSION['usuario_id'], $id]);

        $db->commit();
        redirect(BASE_PATH . '/comprobantes/ver.php?id=' . $id . '&msg=updated');
    } catch (Exception $e) {
        $db->rollBack();
        redirect(BASE_PATH . '/comprobantes/crear.php?id=' . $id);
    }
}

if ($action === 'estado') {
    $id         = (int)($_POST['id']    ?? 0);
    $estado     = $_POST['estado']      ?? '';
    $medio_pago = in_array($_POST['medio_pago'] ?? '', ['efectivo','transferencia','mixto'])
                  ? $_POST['medio_pago'] : 'efectivo';
    $montoEfectivo      = max(0.0, (float)str_replace(',', '.', $_POST['monto_efectivo']      ?? '0'));
    $montoTransferencia = max(0.0, (float)str_replace(',', '.', $_POST['monto_transferencia'] ?? '0'));

    $allowed = ['borrador', 'emitido', 'cobrado'];
    if (!$id || !in_array($estado, $allowed)) {
        redirect(BASE_PATH . '/comprobantes/ver.php?id=' . $id);
    }

    // Validación: cobro mixto requiere al menos un monto > 0
    if ($estado === 'cobrado' && $medio_pago === 'mixto' && ($montoEfectivo + $montoTransferencia) <= 0) {
        redirect(BASE_PATH . '/comprobantes/ver.php?id=' . $id . '&msg=error_montos');
    }

    $rowStmt = $db->prepare("
        SELECT c.estado, c.total, c.numero, cl.nombre AS cliente
        FROM comprobantes c JOIN clientes cl ON cl.id = c.cliente_id
        WHERE c.id = ?
    ");
    $rowStmt->execute([$id]);
    $row = $rowStmt->fetch();

    if (!$row) redirect(BASE_PATH . '/comprobantes/ver.php?id=' . $id);

    $estadoAnterior = $row['estado'];

    // Si no hay cambio real, redirigir sin hacer nada
    if ($estadoAnterior === $estado) {
        redirect(BASE_PATH . '/comprobantes/ver.php?id=' . $id);
    }

    $db->beginTransaction();
    try {
        if ($estado === 'cobrado') {
            if ($medio_pago === 'mixto') {
                $db->prepare("UPDATE comprobantes SET estado=?, medio_pago=?, monto_efectivo=?, monto_transferencia=? WHERE id=?")
                   ->execute([$estado, $medio_pago, $montoEfectivo, $montoTransferencia, $id]);
            } else {
                $db->prepare("UPDATE comprobantes SET estado=?, medio_pago=?, monto_efectivo=NULL, monto_transferencia=NULL WHERE id=?")
                   ->execute([$estado, $medio_pago, $id]);
            }
        } else {
            $db->prepare("UPDATE comprobantes SET estado=? WHERE id=?")->execute([$estado, $id]);
        }

        if ($estado === 'cobrado' && $estadoAnterior !== 'cobrado') {
            $total = (float)$row['total'];
            $desc  = 'Cobro comp. #' . $row['numero'] . ' — ' . $row['cliente'];

            if ($total > 0) {
                $db->prepare("
                    INSERT INTO movimientos_cuenta (fecha, cuenta, tipo, monto, descripcion, comprobante_id)
                    VALUES (?, 'patrimonio', 'cargo', ?, ?, ?)
                ")->execute([date('Y-m-d'), $total, $desc, $id]);
            }

            if ($medio_pago === 'mixto') {
                if ($montoEfectivo > 0) {
                    $db->prepare("
                        INSERT INTO caja_movimientos (tipo, concepto, medio_pago, monto, descripcion, comprobante_id, usuario_id)
                        VALUES ('ingreso', 'venta', 'efectivo', ?, ?, ?, ?)
                    ")->execute([$montoEfectivo, $desc, $id, $_SESSION['usuario_id']]);
                }
                if ($montoTransferencia > 0) {
                    $db->prepare("
                        INSERT INTO caja_movimientos (tipo, concepto, medio_pago, monto, descripcion, comprobante_id, usuario_id)
                        VALUES ('ingreso', 'venta', 'transferencia', ?, ?, ?, ?)
                    ")->execute([$montoTransferencia, $desc, $id, $_SESSION['usuario_id']]);
                }
            } else {
                $db->prepare("
                    INSERT INTO caja_movimientos (tipo, concepto, medio_pago, monto, descripcion, comprobante_id, usuario_id)
                    VALUES ('ingreso', 'venta', ?, ?, ?, ?, ?)
                ")->execute([$medio_pago, $total, $desc, $id, $_SESSION['usuario_id']]);
            }

        } elseif ($estadoAnterior === 'cobrado' && $estado !== 'cobrado') {
            $db->prepare("DELETE FROM movimientos_cuenta WHERE comprobante_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM caja_movimientos   WHERE comprobante_id = ?")->execute([$id]);
            $db->prepare("UPDATE comprobantes SET medio_pago = NULL, monto_efectivo = NULL, monto_transferencia = NULL WHERE id = ?")->execute([$id]);
        }

        $db->commit();
        redirect(BASE_PATH . '/comprobantes/ver.php?id=' . $id . '&msg=estado_ok');

    } catch (Exception $e) {
        $db->rollBack();
        // Pasar el mensaje de error (solo en dev) para depuración
        $errMsg = APP_ENVIRONMENT === 'development'
            ? '&err=' . urlencode($e->getMessage())
            : '';
        redirect(BASE_PATH . '/comprobantes/ver.php?id=' . $id . '&msg=error_db' . $errMsg);
    }
}

if ($action === 'delete') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $db->prepare("DELETE FROM comprobante_items WHERE comprobante_id=?")->execute([$id]);
        $db->prepare("DELETE FROM comprobantes WHERE id=?")->execute([$id]);
    }
    redirect(BASE_PATH . '/comprobantes/?msg=deleted');
}

redirect(BASE_PATH . '/comprobantes/');

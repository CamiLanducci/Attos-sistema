<?php
require_once __DIR__ . '/../config/db.php';

$db     = getDB();
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

// ── Crear pedido ──────────────────────────────────────────────────────────────
if ($action === 'create') {
    $proveedorId  = (int)($_POST['proveedor_id']  ?? 0);
    $fechaPedido  = $_POST['fecha_pedido']         ?? date('Y-m-d');
    $observaciones= trim($_POST['observaciones']   ?? '');
    $total        = isset($_POST['total']) && $_POST['total'] !== '' ? (float)str_replace(',', '.', $_POST['total']) : null;
    $items        = $_POST['items'] ?? [];

    if (!$proveedorId || empty($items)) {
        redirect('/attos/pedidos_galpon/crear.php');
    }

    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO pedidos_galpon (proveedor_id, fecha_pedido, observaciones, total)
            VALUES (?,?,?,?)
        ")->execute([$proveedorId, $fechaPedido, $observaciones ?: null, $total]);
        $pedidoId = $db->lastInsertId();

        $stmtItem = $db->prepare("
            INSERT INTO pedidos_galpon_items (pedido_id, producto_id, codigo, nombre, cajas, unidades, costo_unitario, subtotal)
            VALUES (?,?,?,?,?,?,?,?)
        ");
        foreach ($items as $item) {
            $pid   = (int)($item['producto_id'] ?? 0);
            $cajas = (int)($item['cajas']       ?? 0);
            $unid  = (int)($item['unidades']    ?? 0);
            $costo = isset($item['costo_unitario']) && $item['costo_unitario'] !== '' ? (float)$item['costo_unitario'] : null;
            $sub   = isset($item['subtotal'])       && $item['subtotal']       !== '' ? (float)$item['subtotal']       : null;
            if (!$pid || $cajas + $unid <= 0) continue;
            $stmtItem->execute([$pedidoId, $pid, $item['codigo'] ?? '', $item['nombre'] ?? '', $cajas, $unid, $costo, $sub]);
        }

        $db->commit();
        redirect('/attos/pedidos_galpon/ver.php?id=' . $pedidoId . '&msg=created');
    } catch (Exception $e) {
        $db->rollBack();
        redirect('/attos/pedidos_galpon/crear.php');
    }
}

// ── Actualizar pedido (solo borrador) ─────────────────────────────────────────
if ($action === 'update') {
    $id           = (int)($_POST['id']            ?? 0);
    $proveedorId  = (int)($_POST['proveedor_id']  ?? 0);
    $fechaPedido  = $_POST['fecha_pedido']         ?? date('Y-m-d');
    $observaciones= trim($_POST['observaciones']   ?? '');
    $total        = isset($_POST['total']) && $_POST['total'] !== '' ? (float)str_replace(',', '.', $_POST['total']) : null;
    $items        = $_POST['items'] ?? [];

    if (!$id || !$proveedorId || empty($items)) {
        redirect('/attos/pedidos_galpon/crear.php' . ($id ? '?id=' . $id : ''));
    }

    $chk = $db->prepare("SELECT estado_pedido FROM pedidos_galpon WHERE id=?");
    $chk->execute([$id]);
    if ($chk->fetchColumn() !== 'borrador') {
        redirect('/attos/pedidos_galpon/ver.php?id=' . $id . '&msg=no_editable');
    }

    $db->beginTransaction();
    try {
        $db->prepare("
            UPDATE pedidos_galpon SET proveedor_id=?, fecha_pedido=?, observaciones=?, total=? WHERE id=?
        ")->execute([$proveedorId, $fechaPedido, $observaciones ?: null, $total, $id]);

        $db->prepare("DELETE FROM pedidos_galpon_items WHERE pedido_id=?")->execute([$id]);

        $stmtItem = $db->prepare("
            INSERT INTO pedidos_galpon_items (pedido_id, producto_id, codigo, nombre, cajas, unidades, costo_unitario, subtotal)
            VALUES (?,?,?,?,?,?,?,?)
        ");
        foreach ($items as $item) {
            $pid   = (int)($item['producto_id'] ?? 0);
            $cajas = (int)($item['cajas']       ?? 0);
            $unid  = (int)($item['unidades']    ?? 0);
            $costo = isset($item['costo_unitario']) && $item['costo_unitario'] !== '' ? (float)$item['costo_unitario'] : null;
            $sub   = isset($item['subtotal'])       && $item['subtotal']       !== '' ? (float)$item['subtotal']       : null;
            if (!$pid || $cajas + $unid <= 0) continue;
            $stmtItem->execute([$id, $pid, $item['codigo'] ?? '', $item['nombre'] ?? '', $cajas, $unid, $costo, $sub]);
        }

        $db->commit();
        redirect('/attos/pedidos_galpon/ver.php?id=' . $id . '&msg=updated');
    } catch (Exception $e) {
        $db->rollBack();
        redirect('/attos/pedidos_galpon/crear.php?id=' . $id);
    }
}

// ── Eliminar (solo borrador) ───────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $chk = $db->prepare("SELECT estado_pedido FROM pedidos_galpon WHERE id=?");
        $chk->execute([$id]);
        if ($chk->fetchColumn() === 'borrador') {
            $db->prepare("DELETE FROM pedidos_galpon WHERE id=?")->execute([$id]);
        }
    }
    redirect('/attos/pedidos_galpon/?msg=deleted');
}

// ── Marcar como enviado (borrador → enviado) ──────────────────────────────────
if ($action === 'enviar') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $chk = $db->prepare("SELECT estado_pedido FROM pedidos_galpon WHERE id=?");
        $chk->execute([$id]);
        if ($chk->fetchColumn() === 'borrador') {
            $db->prepare("UPDATE pedidos_galpon SET estado_pedido='enviado' WHERE id=?")->execute([$id]);
        }
    }
    redirect('/attos/pedidos_galpon/ver.php?id=' . $id . '&msg=enviado');
}

// ── Registrar recepción (enviado → recibido) ──────────────────────────────────
if ($action === 'recibir') {
    $id             = (int)($_POST['id']              ?? 0);
    $fechaRecepcion = $_POST['fecha_recepcion']        ?? date('Y-m-d');
    $cantidades     = $_POST['cantidad_recibida']       ?? [];  // [item_id => cantidad]
    $totalAjustado  = isset($_POST['total_ajustado']) && $_POST['total_ajustado'] !== ''
                        ? (float)str_replace(',', '.', $_POST['total_ajustado']) : null;

    if (!$id) redirect('/attos/pedidos_galpon/');

    $chk = $db->prepare("SELECT estado_pedido FROM pedidos_galpon WHERE id=?");
    $chk->execute([$id]);
    if ($chk->fetchColumn() !== 'enviado') {
        redirect('/attos/pedidos_galpon/ver.php?id=' . $id . '&msg=no_editable');
    }

    $db->beginTransaction();
    try {
        // Actualizar cantidad_recibida por item
        $stmtUpd = $db->prepare("UPDATE pedidos_galpon_items SET cantidad_recibida=? WHERE id=? AND pedido_id=?");
        foreach ($cantidades as $itemId => $cant) {
            $stmtUpd->execute([(int)$cant, (int)$itemId, $id]);
        }
        // Marcar pedido como recibido
        $db->prepare("
            UPDATE pedidos_galpon SET estado_pedido='recibido', fecha_recepcion=?
            " . ($totalAjustado !== null ? ", total=?" : "") . "
            WHERE id=?
        ")->execute($totalAjustado !== null
            ? [$fechaRecepcion, $totalAjustado, $id]
            : [$fechaRecepcion, $id]);

        // Movimiento de deuda en la cuenta del proveedor (solo si tiene cuenta asignada y total)
        $pedRow = $db->prepare("
            SELECT pg.total, pv.nombre AS proveedor_nombre, pv.cuenta
            FROM pedidos_galpon pg
            JOIN proveedores pv ON pv.id = pg.proveedor_id
            WHERE pg.id = ?
        ");
        $pedRow->execute([$id]);
        $pedRow = $pedRow->fetch();

        if ($pedRow && $pedRow['cuenta'] && (float)$pedRow['total'] > 0) {
            $yaRegistrado = $db->prepare("SELECT COUNT(*) FROM movimientos_cuenta WHERE pedido_galpon_id = ?");
            $yaRegistrado->execute([$id]);
            if (!(int)$yaRegistrado->fetchColumn()) {
                $desc = 'Deuda pedido #' . $id . ' — ' . $pedRow['proveedor_nombre'];
                $db->prepare("
                    INSERT INTO movimientos_cuenta (fecha, cuenta, tipo, monto, descripcion, pedido_galpon_id)
                    VALUES (?, ?, 'cargo', ?, ?, ?)
                ")->execute([$fechaRecepcion, $pedRow['cuenta'], (float)$pedRow['total'], $desc, $id]);
            }
        }

        $db->commit();
        redirect('/attos/pedidos_galpon/ver.php?id=' . $id . '&msg=recibido');
    } catch (Exception $e) {
        $db->rollBack();
        redirect('/attos/pedidos_galpon/ver.php?id=' . $id . '&msg=error');
    }
}

// ── Marcar como pagado ────────────────────────────────────────────────────────
if ($action === 'pagar') {
    $id        = (int)($_POST['id']         ?? 0);
    $fechaPago = $_POST['fecha_pago']        ?? date('Y-m-d');

    if (!$id) redirect('/attos/pedidos_galpon/');

    $chk = $db->prepare("SELECT estado_pedido, estado_pago FROM pedidos_galpon WHERE id=?");
    $chk->execute([$id]);
    $row = $chk->fetch();
    if (!$row || $row['estado_pedido'] !== 'recibido' || $row['estado_pago'] !== 'pendiente') {
        redirect('/attos/pedidos_galpon/ver.php?id=' . $id . '&msg=no_editable');
    }

    $db->prepare("UPDATE pedidos_galpon SET estado_pago='pagado', fecha_pago=? WHERE id=?")
       ->execute([$fechaPago, $id]);
    redirect('/attos/pedidos_galpon/ver.php?id=' . $id . '&msg=pagado');
}

redirect('/attos/pedidos_galpon/');

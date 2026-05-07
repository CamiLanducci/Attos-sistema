<?php
require_once __DIR__ . '/../config/db.php';

$db     = getDB();
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

// ── Op 3: Registrar pago a galpón / Alfre ────────────────────────────────────
if ($action === 'pago') {
    $cuenta = in_array($_POST['cuenta'] ?? '', ['area_520','alfre']) ? $_POST['cuenta'] : null;
    $monto  = max(0.0, (float)str_replace(',', '.', $_POST['monto'] ?? '0'));
    $fecha  = $_POST['fecha']       ?? date('Y-m-d');
    $desc   = trim($_POST['descripcion'] ?? 'Pago a ' . ($cuenta ?? ''));

    if (!$cuenta || $monto <= 0) {
        redirect('/attos/cuentas/pago.php?msg=error');
    }

    $db->beginTransaction();
    try {
        // Movimiento A: pago en la cuenta destino
        $stmtA = $db->prepare("
            INSERT INTO movimientos_cuenta (fecha, cuenta, tipo, monto, descripcion)
            VALUES (?, ?, 'pago', ?, ?)
        ");
        $stmtA->execute([$fecha, $cuenta, $monto, $desc]);
        $idA = $db->lastInsertId();

        // Movimiento B: pago en Patrimonio (sale plata del usuario)
        $stmtB = $db->prepare("
            INSERT INTO movimientos_cuenta (fecha, cuenta, tipo, monto, descripcion, movimiento_par_id)
            VALUES (?, 'patrimonio', 'pago', ?, ?, ?)
        ");
        $stmtB->execute([$fecha, $monto, $desc, $idA]);
        $idB = $db->lastInsertId();

        // Cruzar referencias
        $db->prepare("UPDATE movimientos_cuenta SET movimiento_par_id=? WHERE id=?")->execute([$idB, $idA]);

        $db->commit();
        redirect('/attos/cuentas/?msg=pago_ok');
    } catch (Exception $e) {
        $db->rollBack();
        redirect('/attos/cuentas/pago.php?msg=error');
    }
}

// ── Crear movimiento manual ───────────────────────────────────────────────────
if ($action === 'crear_movimiento') {
    $cuenta  = in_array($_POST['cuenta'] ?? '', ['area_520','alfre','patrimonio']) ? $_POST['cuenta'] : null;
    $tipo    = in_array($_POST['tipo']   ?? '', ['cargo','pago'])                  ? $_POST['tipo']   : null;
    $monto   = max(0.0, (float)str_replace(',', '.', $_POST['monto'] ?? '0'));
    $fecha   = $_POST['fecha']       ?? date('Y-m-d');
    $desc    = trim($_POST['descripcion'] ?? '');
    $pedId   = (int)($_POST['pedido_galpon_id'] ?? 0) ?: null;
    $compId  = (int)($_POST['comprobante_id']   ?? 0) ?: null;

    if (!$cuenta || !$tipo || $monto <= 0) {
        redirect('/attos/cuentas/crear_movimiento.php?msg=error');
    }

    try {
        $db->prepare("
            INSERT INTO movimientos_cuenta (fecha, cuenta, tipo, monto, descripcion, pedido_galpon_id, comprobante_id)
            VALUES (?,?,?,?,?,?,?)
        ")->execute([$fecha, $cuenta, $tipo, $monto, $desc, $pedId, $compId]);
        redirect('/attos/cuentas/?msg=creado');
    } catch (Exception $e) {
        redirect('/attos/cuentas/crear_movimiento.php?msg=error');
    }
}

// ── Eliminar movimiento ───────────────────────────────────────────────────────
if ($action === 'delete_movimiento') {
    $id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
    if (!$id) redirect('/attos/cuentas/');

    $mov = $db->prepare("SELECT * FROM movimientos_cuenta WHERE id=?");
    $mov->execute([$id]);
    $mov = $mov->fetch();
    if (!$mov) redirect('/attos/cuentas/');

    // Guard: vinculado a comprobante → el usuario debe revertir el estado cobrado
    if ($mov['comprobante_id']) {
        redirect('/attos/cuentas/?msg=no_delete_comp');
    }

    // Guard: vinculado a pedido recibido → no eliminar (la deuda ya está contabilizada)
    if ($mov['pedido_galpon_id']) {
        $est = $db->prepare("SELECT estado_pedido FROM pedidos_galpon WHERE id=?");
        $est->execute([$mov['pedido_galpon_id']]);
        if ($est->fetchColumn() === 'recibido') {
            redirect('/attos/cuentas/?msg=no_delete_ped');
        }
    }

    $db->beginTransaction();
    try {
        $parId = $mov['movimiento_par_id'];
        // Eliminar el movimiento principal (ON DELETE SET NULL libera la FK del par)
        $db->prepare("DELETE FROM movimientos_cuenta WHERE id=?")->execute([$id]);
        // Si tenía par, eliminarlo también
        if ($parId) {
            $db->prepare("DELETE FROM movimientos_cuenta WHERE id=?")->execute([$parId]);
        }
        $db->commit();
        redirect('/attos/cuentas/?msg=deleted');
    } catch (Exception $e) {
        $db->rollBack();
        redirect('/attos/cuentas/?msg=error');
    }
}

redirect('/attos/cuentas/');

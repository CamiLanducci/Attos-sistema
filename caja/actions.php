<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

if (($_SESSION['rol'] ?? 'admin') !== 'admin') redirect(BASE_PATH . '/index.php');

$db     = getDB();
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

function medioPago(): string {
    $v = $_POST['medio_pago'] ?? 'efectivo';
    return in_array($v, ['efectivo','transferencia']) ? $v : 'efectivo';
}
function montoPost(string $key): float {
    return max(0.0, (float)str_replace([',', ' '], ['.', ''], $_POST[$key] ?? '0'));
}

// ── Pago proveedor ────────────────────────────────────────────
if ($action === 'pago_proveedor') {
    $desc  = trim($_POST['descripcion'] ?? '');
    $monto = montoPost('monto');
    $medio = medioPago();
    if ($monto > 0 && $desc !== '') {
        $db->prepare("INSERT INTO caja_movimientos (tipo, concepto, medio_pago, monto, descripcion, usuario_id)
                      VALUES ('egreso','pago_proveedor',?,?,?,?)")
           ->execute([$medio, $monto, $desc, $_SESSION['usuario_id']]);
    }
    redirect(BASE_PATH . '/caja/?msg=ok');
}

// ── Compra de dólares ─────────────────────────────────────────
if ($action === 'compra_dolares') {
    $dol   = montoPost('monto_dolares');
    $pComp = montoPost('precio_compra');
    $pVent = montoPost('precio_venta');
    $medio = medioPago();
    if ($dol > 0 && $pComp > 0) {
        $monto_pesos = round($dol * $pComp, 2);
        $desc = 'Compra USD ' . number_format($dol, 2, ',', '.') . ' a $' . number_format($pComp, 2, ',', '.');
        $db->prepare("INSERT INTO caja_movimientos
                        (tipo, concepto, medio_pago, monto, monto_dolares, precio_dolar_compra, precio_dolar_venta, descripcion, usuario_id)
                      VALUES ('egreso','compra_dolares',?,?,?,?,?,?,?)")
           ->execute([$medio, $monto_pesos, $dol, $pComp, $pVent ?: null, $desc, $_SESSION['usuario_id']]);
    }
    redirect(BASE_PATH . '/caja/?msg=ok');
}

// ── Sueldo ────────────────────────────────────────────────────
if ($action === 'sueldo') {
    $desc  = trim($_POST['descripcion'] ?? '');
    $monto = montoPost('monto');
    $medio = medioPago();
    if ($monto > 0 && $desc !== '') {
        $db->prepare("INSERT INTO caja_movimientos (tipo, concepto, medio_pago, monto, descripcion, usuario_id)
                      VALUES ('egreso','sueldo',?,?,?,?)")
           ->execute([$medio, $monto, $desc, $_SESSION['usuario_id']]);
    }
    redirect(BASE_PATH . '/caja/?msg=ok');
}

// ── Gasto ─────────────────────────────────────────────────────
if ($action === 'gasto') {
    $desc  = trim($_POST['descripcion'] ?? '');
    $monto = montoPost('monto');
    $medio = medioPago();
    if ($monto > 0 && $desc !== '') {
        $db->prepare("INSERT INTO caja_movimientos (tipo, concepto, medio_pago, monto, descripcion, usuario_id)
                      VALUES ('egreso','gasto',?,?,?,?)")
           ->execute([$medio, $monto, $desc, $_SESSION['usuario_id']]);
    }
    redirect(BASE_PATH . '/caja/?msg=ok');
}

// ── Eliminar movimiento ───────────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $mov = $db->prepare("SELECT concepto FROM caja_movimientos WHERE id=?");
        $mov->execute([$id]);
        $mov = $mov->fetch();
        if ($mov && $mov['concepto'] !== 'venta') {
            $db->prepare("DELETE FROM caja_movimientos WHERE id=?")->execute([$id]);
        }
    }
    redirect(BASE_PATH . '/caja/?msg=deleted');
}

redirect(BASE_PATH . '/caja/');

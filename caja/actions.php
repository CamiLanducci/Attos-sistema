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
// Convierte texto de un input a número. Soporta tanto "1.500,00" (formato
// argentino, con punto de miles) como "1500.50" (inputs type="number", que el
// navegador siempre manda con punto decimal y sin separador de miles) — antes
// esto trataba la coma como decimal SIN sacar el punto de miles primero, así
// que "1.500,00" quedaba "1.500.00" y el cast a float lo truncaba en "1.5".
function montoPost(string $key): float {
    $raw = trim(str_replace(' ', '', $_POST[$key] ?? '0'));
    if ($raw === '') return 0.0;
    if (strpos($raw, ',') !== false) {
        $raw = str_replace('.', '', $raw);  // saca separador de miles (si hay)
        $raw = str_replace(',', '.', $raw); // coma decimal -> punto
    }
    return max(0.0, (float)$raw);
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

// ── Fijar mi saldo actual (por usuario) ─────────────────────────
// El formulario pide "cuánto tenés ahora", no el saldo inicial técnico —
// así que acá se hace la cuenta al revés: se guarda como saldo inicial lo que
// haga falta para que inicial + movimientos ya cargados = el monto que puso
// el usuario. Puede dar un saldo inicial negativo, y es correcto (compensa
// movimientos que ya estaban de más).
if ($action === 'mi_saldo_inicial') {
    $miId              = (int)$_SESSION['usuario_id'];
    $efectivoDeseado      = montoPost('efectivo');
    $transferenciaDeseada = montoPost('transferencia');

    $mov = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN medio_pago='efectivo'      AND tipo='ingreso' THEN monto
                              WHEN medio_pago='efectivo'      AND tipo='egreso'  THEN -monto ELSE 0 END), 0) AS efectivo,
            COALESCE(SUM(CASE WHEN medio_pago='transferencia' AND tipo='ingreso' THEN monto
                              WHEN medio_pago='transferencia' AND tipo='egreso'  THEN -monto ELSE 0 END), 0) AS transferencia
        FROM caja_movimientos WHERE usuario_id = ?
    ");
    $mov->execute([$miId]);
    $mov = $mov->fetch() ?: ['efectivo' => 0, 'transferencia' => 0];

    $efectivo      = $efectivoDeseado      - (float)$mov['efectivo'];
    $transferencia = $transferenciaDeseada - (float)$mov['transferencia'];

    $db->prepare("
        INSERT INTO caja_saldo_inicial_usuario (usuario_id, efectivo, transferencia)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE efectivo=VALUES(efectivo), transferencia=VALUES(transferencia)
    ")->execute([$miId, $efectivo, $transferencia]);
    redirect(BASE_PATH . '/caja/?msg=mi_saldo_ok');
}

// ── Arqueo (conteo físico vs. saldo calculado, por usuario) ───
if ($action === 'arqueo') {
    $miId = (int)$_SESSION['usuario_id'];

    $ini = $db->prepare("SELECT efectivo, transferencia FROM caja_saldo_inicial_usuario WHERE usuario_id = ?");
    $ini->execute([$miId]);
    $ini = $ini->fetch() ?: ['efectivo' => 0, 'transferencia' => 0];

    $mov = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN medio_pago='efectivo'      AND tipo='ingreso' THEN monto
                              WHEN medio_pago='efectivo'      AND tipo='egreso'  THEN -monto ELSE 0 END), 0) AS efectivo,
            COALESCE(SUM(CASE WHEN medio_pago='transferencia' AND tipo='ingreso' THEN monto
                              WHEN medio_pago='transferencia' AND tipo='egreso'  THEN -monto ELSE 0 END), 0) AS transferencia
        FROM caja_movimientos WHERE usuario_id = ?
    ");
    $mov->execute([$miId]);
    $mov = $mov->fetch() ?: ['efectivo' => 0, 'transferencia' => 0];

    $efectivoCalculado      = (float)$ini['efectivo']      + (float)$mov['efectivo'];
    $transferenciaCalculado = (float)$ini['transferencia'] + (float)$mov['transferencia'];
    $efectivoContado        = montoPost('efectivo_contado');
    $transferenciaContado   = montoPost('transferencia_contado');
    $notas                  = trim($_POST['notas'] ?? '');

    $db->prepare("
        INSERT INTO caja_arqueos
            (usuario_id, efectivo_calculado, efectivo_contado, diferencia_efectivo,
             transferencia_calculado, transferencia_contado, diferencia_transferencia, notas)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $miId,
        $efectivoCalculado, $efectivoContado, $efectivoContado - $efectivoCalculado,
        $transferenciaCalculado, $transferenciaContado, $transferenciaContado - $transferenciaCalculado,
        $notas !== '' ? $notas : null,
    ]);
    redirect(BASE_PATH . '/caja/?msg=arqueo_ok');
}

// ── Aplicar como ajuste la diferencia de un arqueo ─────────────
// Genera movimiento(s) de tipo 'ajuste' por la diferencia (ingreso si sobró,
// egreso si faltó) para que el saldo calculado quede en línea con lo contado
// — pensado para casos como intereses bancarios que el sistema no ve solo.
// Sólo se puede aplicar una vez por arqueo, y sólo el usuario dueño del arqueo.
if ($action === 'aplicar_ajuste_arqueo') {
    $miId     = (int)$_SESSION['usuario_id'];
    $arqueoId = (int)($_POST['arqueo_id'] ?? 0);

    $stmt = $db->prepare("SELECT * FROM caja_arqueos WHERE id = ? AND usuario_id = ? AND ajustado = 0");
    $stmt->execute([$arqueoId, $miId]);
    $arqueo = $stmt->fetch();

    if ($arqueo) {
        $db->beginTransaction();
        try {
            $fecha = date('d/m/Y', strtotime($arqueo['created_at']));
            foreach ([
                'efectivo'      => (float)$arqueo['diferencia_efectivo'],
                'transferencia' => (float)$arqueo['diferencia_transferencia'],
            ] as $medio => $dif) {
                if (abs($dif) < 0.01) continue;
                $db->prepare("
                    INSERT INTO caja_movimientos (tipo, concepto, medio_pago, monto, descripcion, usuario_id)
                    VALUES (?, 'ajuste', ?, ?, ?, ?)
                ")->execute([
                    $dif > 0 ? 'ingreso' : 'egreso',
                    $medio,
                    abs($dif),
                    'Ajuste por arqueo del ' . $fecha,
                    $miId,
                ]);
            }
            $db->prepare("UPDATE caja_arqueos SET ajustado = 1, ajustado_at = NOW() WHERE id = ?")
               ->execute([$arqueoId]);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
        }
    }
    redirect(BASE_PATH . '/caja/?msg=ajuste_ok');
}

// ── Saldo inicial ─────────────────────────────────────────────
if ($action === 'saldo_inicial') {
    $efectivo      = montoPost('efectivo');
    $transferencia = montoPost('transferencia');
    $dolares       = max(0.0, (float)str_replace([',', ' '], ['.', ''], $_POST['dolares'] ?? '0'));
    $dolaresPrecio = montoPost('dolares_precio');
    if ($dolaresPrecio <= 0) $dolaresPrecio = 1;
    $db->prepare("
        INSERT INTO caja_saldo_inicial (id, efectivo, transferencia, dolares, dolares_precio)
        VALUES (1, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE efectivo=VALUES(efectivo), transferencia=VALUES(transferencia),
                                dolares=VALUES(dolares), dolares_precio=VALUES(dolares_precio)
    ")->execute([$efectivo, $transferencia, $dolares, $dolaresPrecio]);
    redirect(BASE_PATH . '/caja/?msg=saldo_ok');
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

<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

if (($_SESSION['rol'] ?? 'admin') !== 'admin') redirect(BASE_PATH . '/index.php');

$db     = getDB();
$action = $_POST['action'] ?? '';

if ($action === 'update_stock') {
    $items = $_POST['productos'] ?? [];
    $stmt = $db->prepare("
        UPDATE productos
        SET stock_cajas = ?, stock_unidades = ?, costo_compra = ?
        WHERE id = ?
    ");
    foreach ($items as $pid => $vals) {
        $pid       = (int)$pid;
        $cajas     = max(0, (int)($vals['stock_cajas']    ?? 0));
        $unidades  = max(0, (int)($vals['stock_unidades'] ?? 0));
        $costoRaw  = trim($vals['costo_compra'] ?? '');
        $costo     = $costoRaw !== '' && $costoRaw !== '—'
            ? max(0.0, (float)str_replace([',', ' '], ['.', ''], $costoRaw))
            : null;
        if ($pid > 0) {
            $stmt->execute([$cajas, $unidades, $costo, $pid]);
        }
    }
    redirect(BASE_PATH . '/stock/?msg=ok');
}

redirect(BASE_PATH . '/stock/');

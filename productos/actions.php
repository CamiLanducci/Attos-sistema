<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($action === 'delete' && $id > 0) {
    $db = getDB();
    $stmt = $db->prepare("UPDATE productos SET activo = 0 WHERE id = ?");
    $stmt->execute([$id]);
    redirect('/attos/productos/?msg=deleted');
}

redirect('/attos/productos/');

<?php
require_once __DIR__ . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['usuario_id'])) {
    try {
        $db = getDB();
        $db->prepare("UPDATE sesiones SET logout_at = NOW() WHERE usuario_id = ? AND logout_at IS NULL ORDER BY login_at DESC LIMIT 1")
           ->execute([$_SESSION['usuario_id']]);
    } catch (Exception $e) {
        // Continuar con el logout aunque falle la auditoría
    }
}

$_SESSION = [];
session_destroy();
redirect(BASE_PATH . '/login.php');

<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}
if (empty($_SESSION['usuario_id'])) {
    $redir = '/attos/login.php';
    if (!empty($_SERVER['REQUEST_URI'])) {
        $redir .= '?redirect=' . urlencode($_SERVER['REQUEST_URI']);
    }
    header('Location: ' . $redir);
    exit;
}
session_write_close(); // Release file lock; $_SESSION stays readable in memory

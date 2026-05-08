<?php
require_once __DIR__ . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

if (!empty($_SESSION['usuario_id'])) {
    redirect('/attos/index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario  = trim($_POST['usuario']  ?? '');
    $password = $_POST['password'] ?? '';

    if ($usuario && $password) {
        try {
            $db   = getDB();
            $stmt = $db->prepare("SELECT id, nombre_real, password_hash, activo FROM usuarios WHERE usuario = ? LIMIT 1");
            $stmt->execute([$usuario]);
            $user = $stmt->fetch();

            if ($user && (int)$user['activo'] === 1 && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['usuario_id']  = (int)$user['id'];
                $_SESSION['nombre_real'] = $user['nombre_real'];
                $_SESSION['usuario']     = $usuario;

                $db->prepare("UPDATE usuarios SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
                $db->prepare("INSERT INTO sesiones (usuario_id, ip_address, user_agent) VALUES (?, ?, ?)")
                   ->execute([$user['id'], $_SERVER['REMOTE_ADDR'] ?? '', substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)]);

                $redirect = $_POST['redirect'] ?? $_GET['redirect'] ?? '/attos/index.php';
                if (strpos($redirect, '/attos/') !== 0) $redirect = '/attos/index.php';
                redirect($redirect);
            } else {
                $error = 'Usuario o contraseña incorrectos.';
            }
        } catch (Exception $e) {
            $error = 'Error al conectar con la base de datos.';
        }
    } else {
        $error = 'Completá usuario y contraseña.';
    }
}

$redirect = e($_GET['redirect'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Attos — Iniciar sesión</title>
    <link rel="stylesheet" href="/attos/assets/css/style.css">
    <style>
        body { display:flex; align-items:center; justify-content:center; min-height:100vh; }
        .login-wrap { width:360px; }
        .login-logo { text-align:center; margin-bottom:28px; }
        .login-logo .brand-name { font-size:30px; font-weight:800; color:var(--bordo); letter-spacing:2px; }
        .login-logo .brand-sub  { display:block; color:var(--text-muted); font-size:13px; margin-top:4px; }
    </style>
</head>
<body>
<div class="login-wrap">
    <div class="login-logo">
        <span class="brand-name">ATTOS</span>
        <span class="brand-sub">Sistema  — Distribuidora</span>
    </div>
    <div class="card">
        <div class="card-header"><span class="card-title">Iniciar sesión</span></div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-warning" style="margin-bottom:16px;"><?= e($error) ?></div>
            <?php endif; ?>
            <form method="POST">
                <?php if ($redirect): ?>
                    <input type="hidden" name="redirect" value="<?= $redirect ?>">
                <?php endif; ?>
                <div class="form-group">
                    <label class="form-label">Usuario</label>
                    <input type="text" name="usuario" class="form-control" autofocus
                           value="<?= e($_POST['usuario'] ?? '') ?>" autocomplete="username">
                </div>
                <div class="form-group">
                    <label class="form-label">Contraseña</label>
                    <input type="password" name="password" class="form-control" autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-primary w-100" style="margin-top:12px;">Ingresar al sistema</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>

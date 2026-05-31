<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

$batchToken = trim($_POST['batch_token'] ?? '');

if (!$batchToken || !preg_match('/^[a-f0-9]{32}$/', $batchToken)) {
    echo json_encode(['ok' => false, 'error' => 'Token inválido']);
    exit;
}
if ($batchToken !== ($_SESSION['catalogo_reducido_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Token de sesión no coincide']);
    exit;
}

$tmpBase = __DIR__ . DIRECTORY_SEPARATOR . 'tmp';
$tmpDir  = $tmpBase . DIRECTORY_SEPARATOR . $batchToken;

if (!is_dir($tmpBase)) {
    mkdir($tmpBase, 0755, true);
    file_put_contents($tmpBase . DIRECTORY_SEPARATOR . '.htaccess', "Options -Indexes\nDeny from all\n");
}
if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);

// Limpiar directorios de sesiones anteriores (más de 4 horas)
foreach (glob($tmpBase . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
    if (basename($dir) === $batchToken) continue;
    if (filemtime($dir) < time() - 14400) {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
    }
}

$saved  = 0;
$errors = [];

if (!empty($_FILES['imagenes']['name'])) {
    $names = (array)$_FILES['imagenes']['name'];
    $tmps  = (array)$_FILES['imagenes']['tmp_name'];
    $count = count($names);

    for ($i = 0; $i < $count; $i++) {
        $name = basename($names[$i] ?? '');
        $tmp  = $tmps[$i] ?? '';

        if (!$name || !$tmp || !is_uploaded_file($tmp)) continue;

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
            $errors[] = $name . ' (extensión no permitida)';
            continue;
        }

        if (!preg_match('/^\d+_/i', $name)) {
            $errors[] = $name . ' (no sigue el patrón CODIGO_nombre.jpg)';
            continue;
        }

        // Sanitizar nombre de archivo
        $safeName = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', $name);
        $dest = $tmpDir . DIRECTORY_SEPARATOR . $safeName;

        if (move_uploaded_file($tmp, $dest)) {
            $saved++;
        } else {
            $errors[] = $name . ' (error al guardar)';
        }
    }
}

$totalFiles = count(glob($tmpDir . DIRECTORY_SEPARATOR . '*') ?: []);

echo json_encode([
    'ok'     => true,
    'saved'  => $saved,
    'total'  => $totalFiles,
    'errors' => $errors,
]);

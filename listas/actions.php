<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$db     = getDB();
$action = $_POST['action'] ?? 'update';

// ── Crear lista ────────────────────────────────────────────────
if ($action === 'create') {
    $codigo = trim($_POST['codigo'] ?? '');
    $margen = (float)str_replace(',', '.', $_POST['margen'] ?? '0');
    if ($codigo !== '') {
        try {
            $db->prepare("INSERT INTO listas (codigo, margen) VALUES (?, ?)")
               ->execute([$codigo, $margen]);
        } catch (PDOException $e) {
            redirect('/attos/listas/?msg=duplicate');
        }
    }
    redirect('/attos/listas/?msg=updated');
}

// ── Actualizar lista (codigo + url_actualizacion) ─────────────
$id     = (int)($_POST['id'] ?? 0);
$codigo = trim($_POST['codigo'] ?? '');
$url    = trim($_POST['url_actualizacion'] ?? '');

if ($url !== '' && !preg_match('#^https?://#i', $url)) {
    $url = '';
}

if ($id > 0 && $codigo !== '') {
    $db->prepare("UPDATE listas SET codigo=?, url_actualizacion=? WHERE id=?")
       ->execute([$codigo, $url ?: null, $id]);
}

redirect('/attos/listas/?msg=updated');

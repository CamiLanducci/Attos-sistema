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
            redirect(BASE_PATH . '/listas/?msg=duplicate');
        }
    }
    redirect(BASE_PATH . '/listas/?msg=updated');
}

// ── Actualizar lista (codigo + url_actualizacion o deriva_de_lista_id) ────
$id         = (int)($_POST['id'] ?? 0);
$codigo     = trim($_POST['codigo'] ?? '');
$url        = trim($_POST['url_actualizacion'] ?? '');
$derivaDeId = (int)($_POST['deriva_de_lista_id'] ?? 0);

if ($url !== '' && !preg_match('#^https?://#i', $url)) {
    $url = '';
}

// Una lista tiene URL propia o deriva de otra, no ambas a la vez.
if ($derivaDeId === $id) $derivaDeId = 0;
if ($derivaDeId > 0) $url = '';

if ($id > 0 && $codigo !== '') {
    $db->prepare("UPDATE listas SET codigo=?, url_actualizacion=?, deriva_de_lista_id=? WHERE id=?")
       ->execute([$codigo, $url ?: null, $derivaDeId ?: null, $id]);
}

redirect(BASE_PATH . '/listas/?msg=updated');

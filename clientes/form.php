<?php
require_once __DIR__ . '/../config/db.php';

$db   = getDB();
$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$edit = $id > 0;

if ($edit) {
    $stmt = $db->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmt->execute([$id]);
    $cliente = $stmt->fetch();
    if (!$cliente) { redirect('/attos/clientes/'); }
} else {
    $cliente = ['nombre' => '', 'telefono' => '', 'ciudad' => '', 'direccion' => '', 'email' => '', 'notas' => ''];
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre    = trim($_POST['nombre']    ?? '');
    $telefono  = trim($_POST['telefono']  ?? '');
    $ciudad    = trim($_POST['ciudad']    ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $email     = trim($_POST['email']     ?? '');
    $notas     = trim($_POST['notas']     ?? '');

    if ($nombre === '') $errors[] = 'El nombre es obligatorio.';

    if (empty($errors)) {
        if ($edit) {
            $stmt = $db->prepare("UPDATE clientes SET nombre=?, telefono=?, ciudad=?, direccion=?, email=?, notas=? WHERE id=?");
            $stmt->execute([$nombre, $telefono, $ciudad, $direccion, $email, $notas, $id]);
            redirect('/attos/clientes/?msg=updated');
        } else {
            $stmt = $db->prepare("INSERT INTO clientes (nombre, telefono, ciudad, direccion, email, notas) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$nombre, $telefono, $ciudad, $direccion, $email, $notas]);
            redirect('/attos/clientes/?msg=created');
        }
    }

    $cliente = compact('nombre', 'telefono', 'ciudad', 'direccion', 'email', 'notas');
}

$pageTitle     = $edit ? 'Editar cliente' : 'Nuevo cliente';
$topbarActions = '<a href="/attos/clientes/" class="btn btn-secondary">← Volver</a>';
require_once __DIR__ . '/../config/layout.php';
?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="card" style="max-width:620px;">
    <div class="card-header">
        <span class="card-title"><?= $edit ? 'Editar cliente' : 'Nuevo cliente' ?></span>
    </div>
    <div class="card-body">
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Nombre *</label>
                <input type="text" name="nombre" class="form-control" value="<?= e($cliente['nombre']) ?>" required autofocus>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Teléfono</label>
                    <input type="text" name="telefono" class="form-control" value="<?= e($cliente['telefono'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Ciudad</label>
                    <input type="text" name="ciudad" class="form-control" value="<?= e($cliente['ciudad'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Dirección</label>
                <input type="text" name="direccion" class="form-control" value="<?= e($cliente['direccion'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= e($cliente['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Notas internas</label>
                <textarea name="notas" class="form-control" rows="3"><?= e($cliente['notas'] ?? '') ?></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><?= $edit ? 'Guardar cambios' : 'Crear cliente' ?></button>
                <a href="/attos/clientes/" class="btn btn-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

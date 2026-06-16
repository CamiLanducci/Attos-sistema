<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
$pageTitle = 'Catálogo Reducido';

// Resetear sesión y borrar imágenes del lote anterior
if (($_GET['action'] ?? '') === 'reset') {
    $oldToken = $_SESSION['catalogo_reducido_token'] ?? null;
    if ($oldToken) {
        $oldDir = __DIR__ . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $oldToken;
        if (is_dir($oldDir)) {
            foreach (glob($oldDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) @unlink($f);
            @rmdir($oldDir);
        }
    }
    unset($_SESSION['catalogo_reducido_token']);
    redirect(BASE_PATH . '/catalogo/reducido.php');
}

// Generar token de sesión para este lote
if (empty($_SESSION['catalogo_reducido_token'])) {
    $_SESSION['catalogo_reducido_token'] = bin2hex(random_bytes(16));
}
$batchToken = $_SESSION['catalogo_reducido_token'];

// Contar imágenes ya subidas para este token
$tmpDir = __DIR__ . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $batchToken;
$existingFiles = is_dir($tmpDir)
    ? (glob($tmpDir . DIRECTORY_SEPARATOR . '*.{jpg,jpeg,png,JPG,JPEG,PNG}', GLOB_BRACE) ?: [])
    : [];
$existingCount = count($existingFiles);

$db     = getDB();
$listas = $db->query("SELECT * FROM listas ORDER BY margen DESC")->fetchAll();
$mPdfInstalado = file_exists(__DIR__ . '/../vendor/autoload.php');

$errMap = [
    'noimgs'      => 'No hay imágenes en el lote. Subí las fotos primero.',
    'noproductos' => 'Ninguna imagen coincidió con productos de la lista seleccionada.',
    'token'       => 'Sesión inválida. Empezá de nuevo.',
    'nompdf'      => 'mPDF no está instalado. Ejecutá <code>composer install</code>.',
];
$errorMsg = $errMap[$_GET['error'] ?? ''] ?? '';

require_once __DIR__ . '/../config/layout.php';
?>

<?php if ($errorMsg): ?>
<div class="alert alert-warning" style="max-width:660px;"><?= $errorMsg ?></div>
<?php endif; ?>

<div class="card" style="max-width:660px;">
    <div class="card-header" style="display:flex; align-items:center;">
        <span class="card-title">Catálogo Reducido con Fotos</span>
        <?php if ($existingCount > 0): ?>
        <a href="<?= BASE_PATH ?>/catalogo/reducido.php?action=reset"
           class="btn btn-secondary btn-sm"
           style="margin-left:auto; font-size:11px;"
           onclick="return confirm('¿Borrar las <?= $existingCount ?> imágenes del lote actual y empezar de nuevo?')">
            Limpiar lote
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body">

        <p style="font-size:13px; color:#666; margin-bottom:20px; line-height:1.65;">
            Cada foto debe llamarse <code>CODIGO_nombre.jpg</code> — por ejemplo
            <code>1024_rutini_cabernet.jpg</code>. El sistema extrae el código
            y vincula automáticamente el producto y sus precios desde la base de datos.
        </p>

        <?php if (!$mPdfInstalado): ?>
        <div class="alert alert-warning" style="font-size:12px; margin-bottom:16px;">
            <strong>mPDF no instalado.</strong> Ejecutá <code>composer install</code> en
            <code>c:\xampp\htdocs\Attos\</code> para generar PDFs.
        </div>
        <?php endif; ?>

        <!-- ─── Zona de carga ─────────────────────────────────── -->
        <div style="margin-bottom:20px;">
            <label class="form-label">Imágenes de productos</label>

            <div id="dropZone" style="
                border: 2px dashed #631636;
                border-radius: 8px;
                padding: 30px 20px;
                text-align: center;
                background: #FAF6EF;
                cursor: pointer;
                transition: background 0.15s, border-color 0.15s;
                user-select: none;
            ">
                <div style="font-size:30px; margin-bottom:8px; pointer-events:none;">🖼️</div>
                <div style="font-weight:600; color:#631636; font-size:14px; pointer-events:none;">
                    Arrastrá las fotos aquí
                </div>
                <div style="color:#999; font-size:12px; margin-top:5px; pointer-events:none;">
                    o hacé clic para seleccionar · JPG / PNG · múltiples archivos
                </div>
            </div>
            <input type="file" id="fileInput" multiple accept=".jpg,.jpeg,.png" style="display:none;">

            <!-- Barra de progreso -->
            <div id="progressWrap" style="display:none; margin-top:12px;">
                <div style="background:#EAE4DC; border-radius:4px; height:7px; overflow:hidden;">
                    <div id="progressFill" style="background:#631636; height:100%; width:0%; transition:width 0.25s;"></div>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:11px; color:#888; margin-top:4px;">
                    <span id="progressStatus">Subiendo...</span>
                    <span id="progressPct">0%</span>
                </div>
            </div>

            <!-- Contador de imágenes en el lote -->
            <div id="countWrap" style="<?= $existingCount > 0 ? '' : 'display:none;' ?> margin-top:12px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <span id="countLabel" style="
                    background: #eaf4ea;
                    color: #2d7a3a;
                    padding: 4px 14px;
                    border-radius: 20px;
                    font-size: 12px;
                    font-weight: 600;
                    border: 1px solid #c2e0c2;
                "><?= $existingCount ?> imagen<?= $existingCount !== 1 ? 'es' : '' ?> en el lote</span>
                <button type="button" id="btnAddMore" class="btn btn-secondary btn-sm" style="font-size:11px;">
                    + Agregar más fotos
                </button>
            </div>

            <!-- Lista de archivos recién subidos -->
            <div id="fileListWrap" style="display:none; margin-top:10px;">
                <div id="fileList" style="
                    max-height: 210px;
                    overflow-y: auto;
                    border: 1px solid #EAE4DC;
                    border-radius: 5px;
                    background: #fff;
                "></div>
            </div>
        </div>

        <!-- ─── Formulario de generación ─────────────────────── -->
        <form method="POST" action="<?= BASE_PATH ?>/catalogo/generar_reducido.php" target="_blank" id="formGenerar">
            <input type="hidden" name="batch_token" value="<?= e($batchToken) ?>">

            <div class="form-group">
                <label class="form-label">Lista de precios</label>
                <select name="lista_id" class="form-control" required>
                    <option value="">— Seleccionar —</option>
                    <?php foreach ($listas as $l): ?>
                    <option value="<?= $l['id'] ?>"><?= e($l['codigo']) ?> — <?= $l['margen'] ?>%</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-actions">
                <button type="submit" id="btnGenerate" class="btn btn-primary"
                    <?= ($existingCount === 0 || !$mPdfInstalado) ? 'disabled' : '' ?>>
                    Generar Catálogo PDF →
                </button>
                <span id="btnHint" style="font-size:12px; color:#999; margin-left:12px;">
                    <?php if ($existingCount === 0): ?>Subí imágenes primero<?php endif; ?>
                </span>
            </div>
        </form>

    </div>
</div>

<script>
(function () {
    'use strict';

    const BATCH_SIZE   = 10;
    const UPLOAD_URL   = '<?= BASE_PATH ?>/catalogo/upload_img.php';
    const batchToken   = <?= json_encode($batchToken) ?>;
    const mPdfOk       = <?= json_encode((bool)$mPdfInstalado) ?>;

    let uploadedTotal  = <?= (int)$existingCount ?>;
    let isUploading    = false;

    const dropZone       = document.getElementById('dropZone');
    const fileInput      = document.getElementById('fileInput');
    const progressWrap   = document.getElementById('progressWrap');
    const progressFill   = document.getElementById('progressFill');
    const progressPct    = document.getElementById('progressPct');
    const progressStatus = document.getElementById('progressStatus');
    const countWrap      = document.getElementById('countWrap');
    const countLabel     = document.getElementById('countLabel');
    const fileListWrap   = document.getElementById('fileListWrap');
    const fileList       = document.getElementById('fileList');
    const btnGenerate    = document.getElementById('btnGenerate');
    const btnHint        = document.getElementById('btnHint');
    const btnAddMore     = document.getElementById('btnAddMore');

    dropZone.addEventListener('click', () => fileInput.click());
    if (btnAddMore) btnAddMore.addEventListener('click', () => fileInput.click());

    dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.style.background = '#F2E8E8'; dropZone.style.borderColor = '#4A0F28'; });
    dropZone.addEventListener('dragenter', e => { e.preventDefault(); });
    dropZone.addEventListener('dragleave', () => resetDrop());
    dropZone.addEventListener('dragend',   () => resetDrop());

    function resetDrop() {
        dropZone.style.background   = '#FAF6EF';
        dropZone.style.borderColor  = '#631636';
    }

    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        resetDrop();
        const files = Array.from(e.dataTransfer.files).filter(f => /\.(jpe?g|png)$/i.test(f.name));
        if (files.length) startUpload(files);
    });

    fileInput.addEventListener('change', () => {
        const files = Array.from(fileInput.files).filter(f => /\.(jpe?g|png)$/i.test(f.name));
        fileInput.value = '';
        if (files.length) startUpload(files);
    });

    async function startUpload(files) {
        if (isUploading) return;
        isUploading = true;

        progressWrap.style.display = '';
        setProgress(0, files.length);
        btnGenerate.disabled = true;
        btnHint.textContent  = 'Subiendo imágenes...';

        // Advertir archivos sin código
        const invalid = files.filter(f => !/^\d+_/i.test(f.name));
        if (invalid.length) addLog('⚠ Sin código (se ignorarán): ' + invalid.map(f => f.name).join(', '), 'warn');

        const valid   = files.filter(f => /^\d+_/i.test(f.name));
        let processed = 0;

        for (let i = 0; i < valid.length; i += BATCH_SIZE) {
            const batch = valid.slice(i, i + BATCH_SIZE);
            const fd    = new FormData();
            fd.append('batch_token', batchToken);
            batch.forEach(f => fd.append('imagenes[]', f));

            try {
                const res  = await fetch(UPLOAD_URL, { method: 'POST', body: fd });
                const json = await res.json();

                if (json.ok) {
                    processed    += batch.length;
                    uploadedTotal = json.total;
                    setProgress(processed, valid.length);
                    updateCount(json.total);
                    batch.forEach(f => {
                        const hasErr = json.errors && json.errors.some(e => e.includes(f.name));
                        addFileRow(f.name, hasErr ? 'error' : 'ok');
                    });
                    if (json.errors && json.errors.length) {
                        json.errors.forEach(err => addLog('✗ ' + err, 'error'));
                    }
                } else {
                    addLog('✗ Error en lote: ' + (json.error || 'desconocido'), 'error');
                }
            } catch (err) {
                addLog('✗ Error de red en lote ' + (Math.floor(i / BATCH_SIZE) + 1), 'error');
            }
        }

        isUploading = false;
        setProgress(valid.length, valid.length);
        progressStatus.textContent = '¡Subida completada!';

        if (uploadedTotal > 0 && mPdfOk) {
            btnGenerate.disabled = false;
            btnHint.textContent  = '';
        }
    }

    function setProgress(done, total) {
        const pct = total > 0 ? Math.round(done / total * 100) : 0;
        progressFill.style.width  = pct + '%';
        progressPct.textContent   = pct + '%';
        progressStatus.textContent = done < total
            ? 'Subiendo... (' + done + ' / ' + total + ')'
            : 'Subida completada';
    }

    function updateCount(n) {
        countWrap.style.display = '';
        countLabel.textContent  = n + ' imagen' + (n !== 1 ? 'es' : '') + ' en el lote';
    }

    function addFileRow(name, status) {
        const codeMatch = name.match(/^(\d+)_/);
        const code  = codeMatch ? '#' + codeMatch[1] : '—';
        const icon  = status === 'ok' ? '✓' : '✗';
        const color = status === 'ok' ? '#2d7a3a' : '#cc3300';
        const row   = document.createElement('div');
        row.style.cssText = 'display:flex; align-items:center; padding:4px 10px; border-bottom:1px solid #f5f0ea; font-size:12px; gap:8px;';
        row.innerHTML = '<span style="color:' + color + '; flex-shrink:0; width:14px;">' + icon + '</span>'
            + '<span style="flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#444;">' + name + '</span>'
            + '<span style="color:#bbb; flex-shrink:0; font-size:11px;">' + code + '</span>';
        fileList.appendChild(row);
        fileList.scrollTop = fileList.scrollHeight;
        fileListWrap.style.display = '';
    }

    function addLog(msg, type) {
        const el = document.createElement('div');
        el.style.cssText = 'padding:4px 10px; font-size:11px; color:' + (type === 'error' ? '#cc3300' : '#aa6600') + ';';
        el.textContent = msg;
        fileList.appendChild(el);
        fileListWrap.style.display = '';
    }
}());
</script>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

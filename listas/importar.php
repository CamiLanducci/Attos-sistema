<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/_parser_proveedor.php';

set_time_limit(0);
ignore_user_abort(true);

$db = getDB();

// ─── Listas con URL configurada ────────────────────────────────────────────────
$listas = $db->query("
    SELECT * FROM listas
    WHERE url_actualizacion IS NOT NULL AND url_actualizacion != ''
    ORDER BY margen ASC
")->fetchAll();

// ─── Pantalla de confirmación (GET) ────────────────────────────────────────────
$confirmed = ($_GET['confirm'] ?? '') === '1';

if (!$confirmed) {
    $todasListas = $db->query("SELECT * FROM listas ORDER BY margen ASC")->fetchAll();
    $pageTitle     = 'Importar precios';
    $topbarActions = '<a href="/attos/listas/" class="btn btn-secondary">← Volver</a>';
    require_once __DIR__ . '/../config/layout.php';
    ?>
    <div class="card" style="max-width:680px;">
        <div class="card-header"><span class="card-title">Confirmar importación</span></div>
        <div class="card-body">
            <p style="font-size:13px; color:var(--text-soft); margin-bottom:16px;">
                Los precios se toman <strong>directamente de cada URL</strong> — sin aplicar margen.
                Cada lista importa su propio precio real desde el proveedor.
            </p>
            <table style="width:100%; font-size:13px; border-collapse:collapse; margin-bottom:20px;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border);">
                        <th style="padding:6px 8px; text-align:left;">Lista</th>
                        <th style="padding:6px 8px; text-align:left;">URL</th>
                        <th style="padding:6px 8px; text-align:center;">Estado</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($todasListas as $l): ?>
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:6px 8px;">
                        <strong><?= e($l['codigo']) ?></strong>
                        <span class="text-muted" style="font-size:11px;">(<?= $l['margen'] ?>%)</span>
                    </td>
                    <td style="padding:6px 8px; max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                        <?php if ($l['url_actualizacion']): ?>
                            <span style="font-size:11px; color:var(--text-soft);" title="<?= e($l['url_actualizacion']) ?>">
                                <?= e(substr($l['url_actualizacion'], 0, 60)) ?>…
                            </span>
                        <?php else: ?>
                            <span class="text-muted" style="font-size:11px;">Sin URL — se omite</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:6px 8px; text-align:center;">
                        <?php if ($l['url_actualizacion']): ?>
                            <span class="badge badge-success">✓ importar</span>
                        <?php else: ?>
                            <span class="badge badge-gray">omitir</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (empty($listas)): ?>
            <div class="alert" style="background:#fef3cd; color:#856404; padding:10px 14px; border-radius:4px; margin-bottom:16px; font-size:13px;">
                Ninguna lista tiene URL configurada. Configurá las URLs en la pantalla de Listas antes de importar.
            </div>
            <a href="/attos/listas/" class="btn btn-secondary">← Volver a Listas</a>
            <?php else: ?>
            <div class="form-actions">
                <a href="?confirm=1" class="btn btn-primary">↓ Importar <?= count($listas) ?> lista<?= count($listas) !== 1 ? 's' : '' ?> ahora</a>
                <a href="/attos/listas/" class="btn btn-secondary">Cancelar</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../config/layout_end.php';
    exit;
}

if (empty($listas)) {
    redirect('/attos/listas/?msg=config_missing');
}

// ─── Streaming: abrir layout ───────────────────────────────────────────────────
@ob_end_flush();
@ob_implicit_flush(1);

$pageTitle     = 'Importar precios';
$topbarActions = '<a href="/attos/listas/" class="btn btn-secondary">← Volver</a>';
require_once __DIR__ . '/../config/layout.php';

echo '<div class="card">';
echo '<div class="card-header"><span class="card-title">Progreso de importación</span></div>';
echo '<div class="card-body">';

// ─── Queries reutilizables ─────────────────────────────────────────────────────
$stmtByCode = $db->prepare("SELECT id, origen FROM productos WHERE codigo = ? LIMIT 1");
$stmtInsert = $db->prepare("
    INSERT INTO productos (codigo, nombre, marca, unidades_por_caja, origen, activo)
    VALUES (?,?,?,?,'url',1)
");
$stmtUpdate = $db->prepare("
    UPDATE productos
    SET nombre=?, marca=?, unidades_por_caja=?, activo=1, updated_at=NOW()
    WHERE id=?
");
$stmtLpUp = $db->prepare("
    INSERT INTO lista_precios (lista_id, producto_id, costo, costo_caja)
    VALUES (?,?,?,?)
    ON DUPLICATE KEY UPDATE costo=VALUES(costo), costo_caja=VALUES(costo_caja), updated_at=NOW()
");

$resumenGlobal = [];

// ─── Loop por lista ────────────────────────────────────────────────────────────
foreach ($listas as $lista) {
    $listaId  = (int)$lista['id'];
    $listaCod = $lista['codigo'];
    $url      = $lista['url_actualizacion'];

    echo '<pre style="font-size:12px; background:#1a1a1a; color:#e0e0e0; padding:12px; border-radius:4px;'
       . ' white-space:pre-wrap; margin-bottom:16px;">';
    echo "═══ Lista {$listaCod} ({$lista['margen']}%) ═══\n";
    echo "Conectando...\n";
    flush();

    // Descargar
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        CURLOPT_HTTPHEADER     => ['Accept: text/html, */*'],
    ]);
    $rawHtml  = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($rawHtml === false || $httpCode !== 200) {
        echo "ERROR: HTTP {$httpCode}. {$curlErr}\n";
        echo '</pre>';
        flush();
        $resumenGlobal[] = ['lista' => $listaCod, 'error' => "HTTP {$httpCode}", 'nuevos' => 0, 'actualizados' => 0, 'errores' => 0];
        continue;
    }

    $kb = round(strlen($rawHtml) / 1024, 1);
    echo "Descargados {$kb} KB. Parseando...\n";
    flush();

    $productos = parsearHTMLProveedor($rawHtml);

    if (empty($productos)) {
        echo "ERROR: No se encontraron productos en el HTML.\n";
        echo '</pre>';
        flush();
        $resumenGlobal[] = ['lista' => $listaCod, 'error' => 'Sin productos', 'nuevos' => 0, 'actualizados' => 0, 'errores' => 0];
        continue;
    }

    echo count($productos) . " productos encontrados. Importando...\n\n";
    flush();

    $cntNuevos  = 0;
    $cntActualizados = 0;
    $cntSkip    = 0;
    $cntErrores = 0;

    $db->beginTransaction();
    try {
        foreach ($productos as $i => $prod) {
            $codigo       = $prod['codigo'];
            $nombre       = $prod['nombre'];
            $marca        = $prod['marca'];
            $pack         = $prod['pack'];
            $precioUnidad = $prod['precio_unidad'];
            $precioCaja   = round($precioUnidad * $pack, 2);

            $stmtByCode->execute([$codigo]);
            $existente = $stmtByCode->fetch();

            if ($existente && $existente['origen'] === 'manual') {
                $productoId = (int)$existente['id'];
                $cntSkip++;
            } elseif ($existente) {
                $stmtUpdate->execute([$nombre, $marca, $pack, (int)$existente['id']]);
                $productoId = (int)$existente['id'];
                $cntActualizados++;
            } else {
                $stmtInsert->execute([$codigo, $nombre, $marca, $pack]);
                $productoId = (int)$db->lastInsertId();
                echo "[nuevo] {$nombre} [{$codigo}]\n";
                $cntNuevos++;
            }

            $stmtLpUp->execute([$listaId, $productoId, $precioUnidad, $precioCaja]);

            if (($i + 1) % 30 === 0) flush();
            if (($i + 1) % 100 === 0) {
                echo "✓ " . ($i + 1) . " productos procesados...\n";
                flush();
            }
        }

        $db->prepare("UPDATE listas SET ultima_actualizacion = NOW() WHERE id = ?")
           ->execute([$listaId]);

        $db->commit();

        echo "\n✓ {$listaCod} completada: {$cntNuevos} nuevos, {$cntActualizados} actualizados, {$cntSkip} manuales (sin tocar), {$cntErrores} errores.\n";
        $resumenGlobal[] = [
            'lista'        => $listaCod,
            'margen'       => $lista['margen'],
            'error'        => null,
            'nuevos'       => $cntNuevos,
            'actualizados' => $cntActualizados,
            'skip'         => $cntSkip,
            'total'        => count($productos),
        ];

    } catch (Exception $e) {
        $db->rollBack();
        echo "\nERROR en transacción: " . htmlspecialchars($e->getMessage()) . "\n";
        $resumenGlobal[] = ['lista' => $listaCod, 'error' => $e->getMessage(), 'nuevos' => 0, 'actualizados' => 0, 'errores' => 0];
    }

    echo '</pre>';
    flush();
}

echo '</div></div>';

// ─── Resumen global ────────────────────────────────────────────────────────────
?>
<div class="card" style="max-width:560px; margin-top:16px;">
    <div class="card-header"><span class="card-title">Resumen</span></div>
    <div class="card-body">
        <table style="width:100%; font-size:13px; border-collapse:collapse;">
            <thead>
                <tr style="border-bottom:2px solid var(--border);">
                    <th style="padding:5px 8px; text-align:left;">Lista</th>
                    <th style="padding:5px 8px; text-align:center;">Total</th>
                    <th style="padding:5px 8px; text-align:center;">Nuevos</th>
                    <th style="padding:5px 8px; text-align:center;">Actualizados</th>
                    <th style="padding:5px 8px; text-align:left;">Estado</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($resumenGlobal as $r): ?>
            <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:5px 8px;"><strong><?= e($r['lista']) ?></strong>
                    <?php if (isset($r['margen'])): ?>
                    <span class="text-muted" style="font-size:11px;">(<?= $r['margen'] ?>%)</span>
                    <?php endif; ?>
                </td>
                <td style="padding:5px 8px; text-align:center;"><?= $r['total'] ?? '—' ?></td>
                <td style="padding:5px 8px; text-align:center; color:var(--bordo); font-weight:600;"><?= $r['nuevos'] ?></td>
                <td style="padding:5px 8px; text-align:center;"><?= $r['actualizados'] ?? '—' ?></td>
                <td style="padding:5px 8px;">
                    <?php if ($r['error']): ?>
                        <span class="badge badge-danger">Error</span>
                    <?php else: ?>
                        <span class="badge badge-success">OK</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="form-actions" style="margin-top:20px;">
            <a href="/attos/listas/" class="btn btn-primary">Volver a Listas</a>
            <a href="/attos/productos/" class="btn btn-secondary">Ver productos</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../config/layout_end.php'; ?>

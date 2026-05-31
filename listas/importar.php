<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/_parser_proveedor.php';

set_time_limit(0);
ignore_user_abort(true);

$db     = getDB();
$listas = $db->query("
    SELECT * FROM listas
    WHERE url_actualizacion IS NOT NULL AND url_actualizacion != ''
    ORDER BY margen ASC
")->fetchAll();

$step   = $_GET['step'] ?? 'confirm';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

// ══════════════════════════════════════════════════════════════════════════════
// PASO 1 — Pantalla inicial
// ══════════════════════════════════════════════════════════════════════════════
if (!$isPost && $step === 'confirm') {
    $todasListas   = $db->query("SELECT * FROM listas ORDER BY margen ASC")->fetchAll();
    $pageTitle     = 'Importar precios';
    $topbarActions = '<a href="/attos/listas/" class="btn btn-secondary">← Volver</a>';
    require_once __DIR__ . '/../config/layout.php';
    ?>
    <div class="card" style="max-width:680px;">
        <div class="card-header"><span class="card-title">Confirmar importación</span></div>
        <div class="card-body">
            <p style="font-size:13px; color:var(--text-soft); margin-bottom:16px;">
                Los precios se toman <strong>directamente de cada URL</strong> — sin aplicar margen.
                El sistema descargará y mostrará todos los cambios antes de guardarlos.
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
                <a href="?step=preview" class="btn btn-primary">
                    ↓ Ver cambios de <?= count($listas) ?> lista<?= count($listas) !== 1 ? 's' : '' ?>
                </a>
                <a href="/attos/listas/" class="btn btn-secondary">Cancelar</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../config/layout_end.php';
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// PASO 2 — Descargar, comparar y mostrar preview
// ══════════════════════════════════════════════════════════════════════════════
if (!$isPost && $step === 'preview') {
    if (empty($listas)) redirect('/attos/listas/?msg=config_missing');

    $previewData = [];

    foreach ($listas as $lista) {
        $listaId = (int)$lista['id'];

        // Descargar
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $lista['url_actualizacion'],
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
            $previewData[$listaId] = ['error' => "HTTP {$httpCode}: {$curlErr}", 'lista' => $lista];
            continue;
        }

        $productos = parsearHTMLProveedor($rawHtml);

        if (empty($productos)) {
            $previewData[$listaId] = ['error' => 'No se encontraron productos en el HTML', 'lista' => $lista];
            continue;
        }

        // Costos actuales de esta lista (por codigo de producto)
        $stmtOld = $db->prepare("
            SELECT p.codigo, lp.costo
            FROM lista_precios lp
            JOIN productos p ON p.id = lp.producto_id
            WHERE lp.lista_id = ? AND p.activo = 1
        ");
        $stmtOld->execute([$listaId]);
        $oldCostos = [];
        foreach ($stmtOld->fetchAll() as $row) {
            $oldCostos[$row['codigo']] = (float)$row['costo'];
        }

        $changes   = [];
        $newProds  = [];
        $unchanged = 0;

        foreach ($productos as $prod) {
            $codigo = $prod['codigo'];
            $nuevo  = (float)$prod['precio_unidad'];

            if (array_key_exists($codigo, $oldCostos)) {
                $viejo = $oldCostos[$codigo];
                if (abs($nuevo - $viejo) > 0.01) {
                    $pct = $viejo > 0.001 ? (($nuevo - $viejo) / $viejo) * 100 : 0;
                    $changes[] = [
                        'codigo' => $codigo,
                        'nombre' => $prod['nombre'],
                        'marca'  => $prod['marca'],
                        'viejo'  => $viejo,
                        'nuevo'  => $nuevo,
                        'pct'    => $pct,
                    ];
                } else {
                    $unchanged++;
                }
            } else {
                $newProds[] = [
                    'codigo' => $codigo,
                    'nombre' => $prod['nombre'],
                    'marca'  => $prod['marca'],
                    'precio' => $nuevo,
                    'pack'   => $prod['pack'],
                ];
            }
        }

        // Ordenar por mayor variación absoluta primero
        usort($changes, fn($a, $b) => abs($b['pct']) <=> abs($a['pct']));

        $previewData[$listaId] = [
            'lista'         => $lista,
            'productos_raw' => $productos,
            'changes'       => $changes,
            'new_prods'     => $newProds,
            'unchanged'     => $unchanged,
            'total'         => count($productos),
            'error'         => null,
        ];
    }

    // Guardar en sesión (TTL 30 min)
    $_SESSION['import_preview']    = $previewData;
    $_SESSION['import_preview_ts'] = time();

    $pageTitle     = 'Revisión de cambios';
    $topbarActions = '<a href="/attos/listas/importar.php" class="btn btn-secondary">← Cancelar</a>';
    require_once __DIR__ . '/../config/layout.php';

    $hayAlgo = false;
    foreach ($previewData as $d) {
        if (!($d['error'] ?? null) && (count($d['changes']) + count($d['new_prods'])) > 0) {
            $hayAlgo = true;
            break;
        }
    }
    ?>

    <form method="POST">
    <input type="hidden" name="step" value="apply">

    <?php foreach ($previewData as $listaId => $data): ?>

    <?php if ($data['error'] ?? null): ?>
    <div class="alert alert-danger" style="max-width:760px;">
        <strong><?= e($data['lista']['codigo']) ?>:</strong> <?= e($data['error']) ?>
    </div>
    <?php continue; endif; ?>

    <?php
    $lista     = $data['lista'];
    $changes   = $data['changes'];
    $newProds  = $data['new_prods'];
    $unchanged = $data['unchanged'];
    $total     = $data['total'];
    $pctCamb   = $total > 0 ? round(count($changes) / $total * 100, 1) : 0;
    $umbral    = 20;
    $esMayor   = $pctCamb > $umbral;
    $hayModifs = count($changes) > 0 || count($newProds) > 0;
    ?>

    <div class="card" style="max-width:900px; margin-bottom:24px;">

        <!-- Header de lista -->
        <div class="card-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
            <div style="display:flex; align-items:center; gap:12px;">
                <span class="card-title" style="margin:0;">
                    Lista <strong><?= e($lista['codigo']) ?></strong>
                    <span class="text-muted" style="font-weight:400; font-size:13px;">(<?= $lista['margen'] ?>% margen)</span>
                </span>
                <?php if ($esMayor): ?>
                <span class="badge badge-danger" title="Más del <?= $umbral ?>% del catálogo cambió — actualización mayor">
                    Actualización mayor · <?= $pctCamb ?>%
                </span>
                <?php elseif ($hayModifs): ?>
                <span class="badge badge-gray" title="Menos del <?= $umbral ?>% del catálogo cambió">
                    Cambio menor · <?= $pctCamb ?>%
                </span>
                <?php else: ?>
                <span class="badge badge-success">Sin cambios</span>
                <?php endif; ?>
            </div>
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:13px; font-weight:600; white-space:nowrap;">
                <input type="checkbox" name="listas_aceptadas[]" value="<?= $listaId ?>"
                       id="chk_<?= $listaId ?>"
                       <?= $hayModifs ? 'checked' : '' ?>>
                Aplicar esta lista
            </label>
        </div>

        <div class="card-body">

            <!-- Estadísticas rápidas -->
            <div style="display:flex; gap:28px; margin-bottom:18px; font-size:13px; flex-wrap:wrap;">
                <div>
                    <strong style="color:<?= count($changes) > 0 ? '#c0392b' : 'inherit' ?>;">
                        <?= count($changes) ?>
                    </strong>
                    <span class="text-muted">precios modificados</span>
                </div>
                <div>
                    <strong style="color:var(--bordo);"><?= count($newProds) ?></strong>
                    <span class="text-muted">productos nuevos</span>
                </div>
                <div>
                    <strong style="color:var(--text-soft);"><?= $unchanged ?></strong>
                    <span class="text-muted">sin cambio</span>
                </div>
                <div>
                    <strong><?= $total ?></strong>
                    <span class="text-muted">total</span>
                </div>
            </div>

            <?php if (!empty($changes)): ?>
            <!-- Tabla de variaciones de precio -->
            <div style="overflow-x:auto; margin-bottom:16px;">
                <table style="width:100%; font-size:12px; border-collapse:collapse;">
                    <thead>
                        <tr style="background:var(--bg-soft); border-bottom:2px solid var(--border);">
                            <th style="padding:6px 8px; text-align:left; font-weight:600; white-space:nowrap;">Código</th>
                            <th style="padding:6px 8px; text-align:left; font-weight:600;">Producto</th>
                            <th style="padding:6px 8px; text-align:left; font-weight:600;">Marca</th>
                            <th style="padding:6px 8px; text-align:right; font-weight:600; white-space:nowrap;">Precio anterior</th>
                            <th style="padding:6px 8px; text-align:right; font-weight:600; white-space:nowrap;">Precio nuevo</th>
                            <th style="padding:6px 8px; text-align:right; font-weight:600; white-space:nowrap;">Variación</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($changes as $c):
                        $sube    = $c['pct'] > 0;
                        $pctFmt  = ($sube ? '+' : '') . number_format($c['pct'], 1) . '%';
                        $clrPct  = $sube ? '#c0392b' : '#27ae60';
                        $rowBg   = $sube ? '#fff5f5' : '#f0fff4';
                    ?>
                    <tr style="border-bottom:1px solid var(--border); background:<?= $rowBg ?>;">
                        <td style="padding:5px 8px; color:#888; font-size:11px; white-space:nowrap;"><?= e($c['codigo']) ?></td>
                        <td style="padding:5px 8px;"><?= e($c['nombre']) ?></td>
                        <td style="padding:5px 8px; color:var(--text-soft); white-space:nowrap;"><?= e($c['marca']) ?></td>
                        <td style="padding:5px 8px; text-align:right; color:var(--text-soft); white-space:nowrap;"><?= precio($c['viejo']) ?></td>
                        <td style="padding:5px 8px; text-align:right; font-weight:600; white-space:nowrap;"><?= precio($c['nuevo']) ?></td>
                        <td style="padding:5px 8px; text-align:right; font-weight:700; color:<?= $clrPct ?>; white-space:nowrap;"><?= $pctFmt ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($newProds)): ?>
            <!-- Productos nuevos (colapsable) -->
            <details style="margin-bottom:4px;">
                <summary style="font-size:12px; color:var(--bordo); cursor:pointer; padding:4px 0; font-weight:600;">
                    + <?= count($newProds) ?> producto<?= count($newProds) > 1 ? 's' : '' ?> nuevo<?= count($newProds) > 1 ? 's' : '' ?> (expandir)
                </summary>
                <div style="overflow-x:auto; margin-top:8px;">
                    <table style="width:100%; font-size:12px; border-collapse:collapse;">
                        <thead>
                            <tr style="background:var(--bg-soft); border-bottom:2px solid var(--border);">
                                <th style="padding:5px 8px; text-align:left;">Código</th>
                                <th style="padding:5px 8px; text-align:left;">Producto</th>
                                <th style="padding:5px 8px; text-align:left;">Marca</th>
                                <th style="padding:5px 8px; text-align:center;">Pack</th>
                                <th style="padding:5px 8px; text-align:right;">Precio</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($newProds as $np): ?>
                        <tr style="border-bottom:1px solid var(--border);">
                            <td style="padding:4px 8px; color:#888; font-size:11px;"><?= e($np['codigo']) ?></td>
                            <td style="padding:4px 8px;"><?= e($np['nombre']) ?></td>
                            <td style="padding:4px 8px; color:var(--text-soft);"><?= e($np['marca']) ?></td>
                            <td style="padding:4px 8px; text-align:center; color:var(--text-soft);"><?= (int)$np['pack'] ?></td>
                            <td style="padding:4px 8px; text-align:right;"><?= precio($np['precio']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
            <?php endif; ?>

            <?php if (!$hayModifs): ?>
            <p style="font-size:13px; color:var(--text-soft); text-align:center; padding:12px 0; margin:0;">
                Sin cambios detectados — precios idénticos a los actuales.
            </p>
            <?php endif; ?>

        </div>
    </div>
    <?php endforeach; ?>

    <!-- Barra de confirmación final -->
    <div class="card" style="max-width:900px;">
        <div class="card-body">
            <?php if (!$hayAlgo): ?>
            <p style="font-size:13px; color:var(--text-soft); margin-bottom:12px;">
                No se detectaron diferencias en ninguna lista.
            </p>
            <?php endif; ?>
            <div class="form-actions">
                <?php if ($hayAlgo): ?>
                <button type="submit" class="btn btn-primary">✓ Confirmar y aplicar listas seleccionadas</button>
                <?php endif; ?>
                <a href="/attos/listas/importar.php" class="btn btn-secondary">Cancelar</a>
            </div>
        </div>
    </div>

    </form>

    <?php
    require_once __DIR__ . '/../config/layout_end.php';
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// PASO 3 — Aplicar listas aceptadas (POST)
// ══════════════════════════════════════════════════════════════════════════════
if ($isPost && ($_POST['step'] ?? '') === 'apply') {
    $previewData = $_SESSION['import_preview']    ?? null;
    $previewTs   = $_SESSION['import_preview_ts'] ?? 0;

    if (!$previewData || (time() - $previewTs) > 1800) {
        redirect('/attos/listas/importar.php?step=confirm&error=expired');
    }

    $listasAceptadas = array_map('intval', $_POST['listas_aceptadas'] ?? []);

    if (empty($listasAceptadas)) {
        redirect('/attos/listas/?msg=no_listas');
    }

    @ob_end_flush();
    @ob_implicit_flush(1);

    $pageTitle     = 'Aplicando cambios';
    $topbarActions = '<a href="/attos/listas/" class="btn btn-secondary">← Volver</a>';
    require_once __DIR__ . '/../config/layout.php';

    echo '<div class="card" style="max-width:900px;">';
    echo '<div class="card-header"><span class="card-title">Progreso de importación</span></div>';
    echo '<div class="card-body">';

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

    foreach ($listasAceptadas as $listaId) {
        $data = $previewData[$listaId] ?? null;
        if (!$data || ($data['error'] ?? null)) continue;

        $lista     = $data['lista'];
        $productos = $data['productos_raw'];
        $listaCod  = $lista['codigo'];

        echo '<pre style="font-size:12px; background:#1a1a1a; color:#e0e0e0; padding:12px; border-radius:4px;'
           . ' white-space:pre-wrap; margin-bottom:16px;">';
        echo "═══ Lista {$listaCod} ({$lista['margen']}%) ═══\n";
        echo count($productos) . " productos. Guardando...\n\n";
        flush();

        $cntNuevos       = 0;
        $cntActualizados = 0;
        $cntSkip         = 0;
        $cntPreciosModif = count($data['changes']) + count($data['new_prods']);

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

            // El usuario revisó y aceptó → siempre registrar la actualización
            $db->prepare("UPDATE listas SET ultima_actualizacion = NOW() WHERE id = ?")
               ->execute([$listaId]);

            $db->commit();

            echo "\n✓ {$listaCod} completada: {$cntNuevos} nuevos, {$cntActualizados} actualizados, {$cntSkip} manuales (sin tocar).\n";

            $resumenGlobal[] = [
                'lista'          => $listaCod,
                'margen'         => $lista['margen'],
                'error'          => null,
                'nuevos'         => $cntNuevos,
                'actualizados'   => $cntActualizados,
                'skip'           => $cntSkip,
                'total'          => count($productos),
                'precios_modif'  => $cntPreciosModif,
                'pct_modif'      => $data['total'] > 0
                                    ? ($cntPreciosModif / $data['total']) * 100
                                    : 0,
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

    unset($_SESSION['import_preview'], $_SESSION['import_preview_ts']);

    // ─── Resumen final ─────────────────────────────────────────────────────────
    ?>
    <div class="card" style="max-width:680px; margin-top:16px;">
        <div class="card-header"><span class="card-title">Resumen</span></div>
        <div class="card-body">
            <table style="width:100%; font-size:13px; border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border);">
                        <th style="padding:5px 8px; text-align:left;">Lista</th>
                        <th style="padding:5px 8px; text-align:center;">Total</th>
                        <th style="padding:5px 8px; text-align:center;">Nuevos</th>
                        <th style="padding:5px 8px; text-align:center;">Actualizados</th>
                        <th style="padding:5px 8px; text-align:center;">Precios modif.</th>
                        <th style="padding:5px 8px; text-align:left;">Estado</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($resumenGlobal as $r): ?>
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:5px 8px;">
                        <strong><?= e($r['lista']) ?></strong>
                        <?php if (isset($r['margen'])): ?>
                        <span class="text-muted" style="font-size:11px;">(<?= $r['margen'] ?>%)</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:5px 8px; text-align:center;"><?= $r['total'] ?? '—' ?></td>
                    <td style="padding:5px 8px; text-align:center; color:var(--bordo); font-weight:600;"><?= $r['nuevos'] ?></td>
                    <td style="padding:5px 8px; text-align:center;"><?= $r['actualizados'] ?? '—' ?></td>
                    <td style="padding:5px 8px; text-align:center;">
                        <?php if (isset($r['precios_modif'])): ?>
                            <?= $r['precios_modif'] ?>
                            <span class="text-muted" style="font-size:11px;">
                                (<?= number_format($r['pct_modif'], 1) ?>%)
                            </span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="padding:5px 8px;">
                        <?php if ($r['error'] ?? null): ?>
                            <span class="badge badge-danger">Error</span>
                        <?php else: ?>
                            <span class="badge badge-success">Aplicada</span>
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

    <?php
    require_once __DIR__ . '/../config/layout_end.php';
    exit;
}

// Fallback — redirigir al inicio
redirect('/attos/listas/importar.php');

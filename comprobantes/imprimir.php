<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$db = getDB();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) die('No encontrado');

$comp = $db->prepare("
    SELECT c.*, cl.nombre AS cliente_nombre, cl.telefono AS cliente_tel,
           cl.ciudad AS cliente_ciudad, cl.direccion AS cliente_dir, cl.email AS cliente_email,
           l.codigo AS lista_codigo, l.margen AS lista_margen
    FROM comprobantes c
    JOIN clientes cl ON cl.id = c.cliente_id
    JOIN listas   l  ON l.id  = c.lista_id
    WHERE c.id = ?
");
$comp->execute([$id]);
$comp = $comp->fetch();
if (!$comp) die('No encontrado');

$items = $db->prepare("
    SELECT ci.*, p.marca
    FROM comprobante_items ci
    LEFT JOIN productos p ON p.id = ci.producto_id
    WHERE ci.comprobante_id = ?
    ORDER BY ci.id ASC
");
$items->execute([$id]);
$items = $items->fetchAll();

$totalCajas    = 0;
$totalUnidades = 0;
foreach ($items as $it) {
    $totalCajas    += (int)$it['cantidad_cajas'];
    $totalUnidades += (int)$it['cantidad_unidades'];
}

$estadoLabel = ['borrador' => 'Borrador', 'emitido' => 'Emitido', 'cobrado' => 'Cobrado'];
$estadoColor = ['borrador' => '#b7770d', 'emitido' => '#631636', 'cobrado' => '#2d7a4f'];
$est = $comp['estado'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprobante N.° <?= str_pad($comp['numero_cliente'] ?? $comp['numero'], 4, '0', STR_PAD_LEFT) ?> — Attos</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
            font-size: 13px;
            color: #1a1a1a;
            background: #f5f5f5;
        }

        .page {
            background: #fff;
            max-width: 780px;
            margin: 30px auto;
            padding: 44px 48px;
            box-shadow: 0 4px 24px rgba(0,0,0,.10);
            border-radius: 4px;
        }

        /* ── HEADER ──────────────────────────────────────────── */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding-bottom: 20px;
            margin-bottom: 20px;
            border-bottom: 3px solid #631636;
        }

        .header-left { display: flex; align-items: center; gap: 16px; }

        .logo img {
            height: 52px;
            width: auto;
            display: block;
        }

        .brand-info { line-height: 1.3; }
        .brand-name {
            font-size: 20px;
            font-weight: 900;
            color: #631636;
            letter-spacing: 4px;
            text-transform: uppercase;
        }
        .brand-sub {
            font-size: 10px;
            color: #999;
            letter-spacing: 1.8px;
            text-transform: uppercase;
            margin-top: 2px;
        }
        .brand-contact {
            font-size: 11px;
            color: #777;
            margin-top: 6px;
            line-height: 1.5;
        }

        .header-right { text-align: right; }

        .doc-label {
            font-size: 9.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #aaa;
            margin-bottom: 4px;
        }
        .doc-num {
            font-size: 26px;
            font-weight: 800;
            color: #631636;
            letter-spacing: 1px;
            font-variant-numeric: tabular-nums;
        }
        .doc-date {
            font-size: 12px;
            color: #777;
            margin-top: 5px;
        }
        .doc-estado {
            display: inline-block;
            margin-top: 8px;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            background: <?= $estadoColor[$est] ?? '#aaa' ?>;
            color: #fff;
        }

        /* ── INFO BLOCK ──────────────────────────────────────── */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        .info-block {
            background: #faf8f5;
            border: 1px solid #e8e0d5;
            border-radius: 6px;
            padding: 14px 16px;
        }

        .info-block-label {
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #631636;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid #e8e0d5;
        }

        .info-block-name {
            font-size: 14px;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 4px;
        }

        .info-block-detail {
            font-size: 11px;
            color: #666;
            line-height: 1.65;
        }

        /* ── TABLE ───────────────────────────────────────────── */
        .items-section { margin-bottom: 22px; }

        .items-section table {
            width: 100%;
            border-collapse: collapse;
        }

        .items-section thead tr {
            background: #631636;
        }

        .items-section thead th {
            padding: 7px 10px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #fff;
            text-align: left;
        }

        .items-section thead th.num { text-align: right; }

        .items-section tbody tr:nth-child(even) { background: #faf8f5; }
        .items-section tbody tr:last-child td { border-bottom: 2px solid #ddd0c4; }

        .items-section tbody td {
            padding: 6px 10px;
            font-size: 12px;
            border-bottom: 1px solid #ece8e0;
            vertical-align: middle;
        }

        .items-section tbody td.num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .prod-nombre { font-weight: 600; color: #1a1a1a; }
        .prod-marca  { font-size: 10.5px; color: #999; margin-top: 1px; }
        .desc-row    { font-size: 10px; color: #631636; margin-top: 2px; font-weight: 400; }

        /* ── TOTALS ──────────────────────────────────────────── */
        .totals-wrap {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 28px;
        }

        .totals-box {
            width: 250px;
            border: 1px solid #e8e0d5;
            border-radius: 6px;
            overflow: hidden;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 16px;
            font-size: 12px;
            border-bottom: 1px solid #ece8e0;
        }

        .total-row:last-child { border-bottom: none; }

        .total-row .label { color: #666; }
        .total-row .value { font-weight: 600; font-variant-numeric: tabular-nums; }

        .total-row.main-total {
            background: #631636;
            padding: 11px 16px;
        }

        .total-row.main-total .label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255,255,255,.85);
        }

        .total-row.main-total .value {
            font-size: 17px;
            font-weight: 800;
            color: #fff;
        }

        /* ── NOTAS ───────────────────────────────────────────── */
        .notas-block {
            background: #faf8f5;
            border-left: 3px solid #ddd0c4;
            padding: 10px 14px;
            font-size: 12px;
            color: #555;
            margin-bottom: 28px;
            border-radius: 0 4px 4px 0;
        }
        .notas-block strong { color: #1a1a1a; }

        /* ── FOOTER ──────────────────────────────────────────── */
        .footer {
            border-top: 1px solid #e8e0d5;
            padding-top: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .footer-brand {
            font-size: 11px;
            font-weight: 700;
            color: #631636;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .footer-info {
            font-size: 10px;
            color: #aaa;
            text-align: right;
        }

        /* ── PRINT ───────────────────────────────────────────── */
        @media print {
            body { background: #fff; }
            .page {
                margin: 0;
                padding: 18mm 16mm;
                box-shadow: none;
                border-radius: 0;
            }
            @page { margin: 0; size: A4; }
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .doc-estado { display: none; }
        }

        .no-print {
            text-align: center;
            margin-bottom: 20px;
        }

        .no-print button {
            background: #631636;
            color: #fff;
            border: none;
            padding: 10px 28px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            letter-spacing: .5px;
        }

        .no-print button:hover { background: #4a1028; }

        @media print { .no-print { display: none; } }

        .no-print-btn {
            background: #fff;
            color: #631636;
            border: 2px solid #631636;
            padding: 10px 22px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            letter-spacing: .5px;
        }
        .no-print-btn:hover { background: #f4ede3; }
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()">🖨 Imprimir</button>
    &nbsp;
    <button class="no-print-btn" id="btn-pdf" onclick="guardarPDF()">📄 Guardar PDF</button>
    &nbsp;&nbsp;
    <a href="<?= BASE_PATH ?>/comprobantes/ver.php?id=<?= $id ?>" style="font-size:13px; color:#631636;">← Volver</a>
</div>
<script>
var _pdfNombre = 'Comprobante-<?= str_pad($comp['numero_cliente'] ?? $comp['numero'], 4, '0', STR_PAD_LEFT) ?>-<?= $comp['fecha'] ?>.pdf';

function guardarPDF() {
    if (typeof html2pdf === 'undefined') {
        var t = document.title;
        document.title = _pdfNombre.replace('.pdf', '');
        window.print();
        document.title = t;
        return;
    }
    var btn = document.getElementById('btn-pdf');
    btn.disabled = true;
    btn.textContent = 'Generando…';
    var opt = {
        margin:      [8, 8, 8, 8],
        filename:    _pdfNombre,
        image:       { type: 'jpeg', quality: 0.97 },
        html2canvas: { scale: 2, useCORS: true, logging: false },
        jsPDF:       { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };
    html2pdf().set(opt).from(document.querySelector('.page')).save().then(function() {
        btn.disabled = false;
        btn.textContent = '📄 Guardar PDF';
    });
}
<?php if (($_GET['pdf'] ?? '') === '1'): ?>
window.addEventListener('load', function() { guardarPDF(); });
<?php endif; ?>
</script>

<div class="page">

    <!-- HEADER -->
    <div class="header">
        <div class="header-left">
            <div class="logo">
                <img src="<?= BASE_PATH ?>/assets/img/logo.png" alt="Attos">
            </div>
            <div class="brand-info">
                <div class="brand-name">Attos</div>
                <div class="brand-sub">Distribuidora de Bebidas</div>
                <div class="brand-contact">
                    La Plata, Buenos Aires
                </div>
            </div>
        </div>
        <div class="header-right">
            <div class="doc-label">Pedido / Comprobante</div>
            <div class="doc-num">N.° <?= str_pad($comp['numero_cliente'] ?? $comp['numero'], 4, '0', STR_PAD_LEFT) ?></div>
            <div class="doc-date"><?= date('d/m/Y', strtotime($comp['fecha'])) ?></div>
            <div class="doc-estado"><?= $estadoLabel[$est] ?? $est ?></div>
        </div>
    </div>

    <!-- INFO GRID -->
    <div class="info-grid">
        <div class="info-block">
            <div class="info-block-label">Cliente</div>
            <div class="info-block-name"><?= e($comp['cliente_nombre']) ?></div>
            <div class="info-block-detail">
                <?php if ($comp['cliente_ciudad']): ?>
                    <?= e($comp['cliente_ciudad']) ?><?= $comp['cliente_dir'] ? ' — ' . e($comp['cliente_dir']) : '' ?><br>
                <?php endif; ?>
                <?php if ($comp['cliente_tel']): ?>
                    Tel: <?= e($comp['cliente_tel']) ?><br>
                <?php endif; ?>
                <?php if ($comp['cliente_email']): ?>
                    <?= e($comp['cliente_email']) ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="info-block">
            <div class="info-block-label">Detalles</div>
            <div class="info-block-detail">
                <strong>Fecha:</strong> <?= date('d \d\e F \d\e Y', strtotime($comp['fecha'])) ?><br>
                <strong>N.° de pedido:</strong> <?= str_pad($comp['numero_cliente'] ?? $comp['numero'], 4, '0', STR_PAD_LEFT) ?><br>
                <strong>Entrega:</strong> <?= ($comp['tipo_entrega'] ?? 'envio') === 'retira' ? 'Retira en local' : 'Envío a domicilio' ?>
            </div>
        </div>
    </div>

    <!-- ITEMS -->
    <div class="items-section">
        <table>
            <thead>
                <tr>
                    <th style="width:44%;">Producto</th>
                    <th class="num" style="width:12%;">Precio/ud</th>
                    <th class="num" style="width:10%;">Cajas</th>
                    <th class="num" style="width:10%;">Unid.</th>
                    <th class="num" style="width:14%;">Precio/caja</th>
                    <th class="num" style="width:10%;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $it):
                $precioCaja = (float)$it['precio_unitario'] * (int)$it['unidades_por_caja'];
                $descMonto  = (float)($it['descuento_monto'] ?? 0);
            ?>
                <tr>
                    <td>
                        <div class="prod-nombre"><?= e($it['nombre_producto']) ?></div>
                        <?php if (!empty($it['marca'])): ?>
                            <div class="prod-marca"><?= e($it['marca']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= precio((float)$it['precio_unitario']) ?></td>
                    <td class="num"><?= (int)$it['cantidad_cajas'] ?></td>
                    <td class="num"><?= (int)$it['cantidad_unidades'] > 0 ? (int)$it['cantidad_unidades'] : '—' ?></td>
                    <td class="num"><?= precio($precioCaja) ?></td>
                    <td class="num" style="font-weight:700; color:#631636;">
                        <?= precio((float)$it['subtotal']) ?>
                        <?php if ($descMonto > 0): ?>
                            <div class="desc-row">−<?= precio($descMonto) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- TOTALES -->
    <div class="totals-wrap">
        <div class="totals-box">
            <div class="total-row" style="background:#faf8f5;">
                <span class="label" style="color:#631636; font-weight:600;">Total cajas</span>
                <span class="value" style="color:#631636;"><?= $totalCajas ?></span>
            </div>
            <div class="total-row" style="background:#faf8f5; border-bottom:2px solid #ddd0c4;">
                <span class="label" style="color:#631636; font-weight:600;">Total unidades sueltas</span>
                <span class="value" style="color:#631636;"><?= $totalUnidades ?></span>
            </div>
            <div class="total-row">
                <span class="label">Subtotal</span>
                <span class="value"><?= precio((float)$comp['subtotal']) ?></span>
            </div>
            <?php if (($comp['tipo_entrega'] ?? 'envio') !== 'retira'): ?>
            <div class="total-row">
                <span class="label">Envío</span>
                <span class="value"><?= (float)$comp['envio'] > 0 ? precio((float)$comp['envio']) : 'Gratis' ?></span>
            </div>
            <?php endif; ?>
            <?php if ((float)($comp['descuento'] ?? 0) > 0): ?>
            <div class="total-row">
                <span class="label" style="font-weight:600; color:#631636;">Bonificación</span>
                <span class="value" style="color:#631636;">−<?= precio((float)$comp['descuento']) ?></span>
            </div>
            <?php endif; ?>
            <div class="total-row main-total">
                <span class="label">Total</span>
                <span class="value"><?= precio((float)$comp['total']) ?></span>
            </div>
        </div>
    </div>

    <!-- NOTAS -->
    <?php if ($comp['notas']): ?>
    <div class="notas-block">
        <strong>Observaciones:</strong> <?= e($comp['notas']) ?>
    </div>
    <?php endif; ?>

    <!-- FOOTER -->
    <div class="footer">
        <div class="footer-brand">Attos Distribuidora</div>
        <div class="footer-info">
            La Plata, Buenos Aires<br>
            Generado el <?= date('d/m/Y') ?>
        </div>
    </div>

</div>

</body>
</html>

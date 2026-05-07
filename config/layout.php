<?php
// Incluir desde cualquier módulo: require_once __DIR__ . '/../config/layout.php';
$base = str_repeat('../', substr_count($_SERVER['SCRIPT_NAME'], '/') - 2);

function navLink(string $href, string $icon, string $label, string $current): string {
    $active = strpos($_SERVER['SCRIPT_NAME'], $href) !== false ? ' active' : '';
    return "<a href=\"{$href}\" class=\"{$active}\">
        <span class=\"nav-icon\">{$icon}</span> {$label}
    </a>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $pageTitle ?? 'Attos' ?> — Sistema</title>
    <link rel="stylesheet" href="/attos/assets/css/style.css">
    <?= $extraHead ?? '' ?>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="sidebar-logo">
            <span class="brand-name">ATTOS</span>
            <span class="brand-sub">Distribuidora</span>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section">Principal</div>
            <?= navLink('/attos/index.php', '▦', 'Dashboard', 'index') ?>
            <div class="nav-section">Gestión</div>
            <?= navLink('/attos/clientes/', '👤', 'Clientes', 'clientes') ?>
            <?= navLink('/attos/productos/', '📦', 'Productos', 'productos') ?>
            <?= navLink('/attos/listas/', '📋', 'Listas / Márgenes', 'listas') ?>
            <div class="nav-section">Operaciones</div>
            <?= navLink('/attos/comprobantes/', '🧾', 'Comprobantes', 'comprobantes') ?>
            <?= navLink('/attos/pedidos_galpon/', '📦', 'Pedidos galpón', 'pedidos_galpon') ?>
            <?= navLink('/attos/cuentas/', '💰', 'Cuentas', 'cuentas') ?>
            <?= navLink('/attos/reportes/', '📊', 'Reportes', 'reportes') ?>
            <div class="nav-section">Catálogo</div>
            <?= navLink('/attos/catalogo/', '📄', 'Generar Catálogo', 'catalogo') ?>
        </nav>
    </aside>
    <div class="main">
        <div class="topbar">
            <span class="topbar-title"><?= $pageTitle ?? '' ?></span>
            <div class="topbar-actions">
                <?php if (!empty($topbarActions)) echo $topbarActions; ?>
            </div>
        </div>
        <div class="content">

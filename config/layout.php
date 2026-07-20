<?php
// Incluir desde cualquier módulo: require_once __DIR__ . '/../config/layout.php';
if (session_status() === PHP_SESSION_NONE) session_start();

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
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">
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
            <?= navLink(BASE_PATH . '/index.php', '▦', 'Dashboard', 'index') ?>
            <div class="nav-section">Gestión</div>
            <?= navLink(BASE_PATH . '/clientes/', '👤', 'Clientes', 'clientes') ?>
            <?= navLink(BASE_PATH . '/productos/', '📦', 'Productos', 'productos') ?>
            <?= navLink(BASE_PATH . '/listas/', '📋', 'Listas / Márgenes', 'listas') ?>
            <div class="nav-section">Operaciones</div>
            <?= navLink(BASE_PATH . '/comprobantes/', '🧾', 'Comprobantes', 'comprobantes') ?>
            <?= navLink(BASE_PATH . '/pedidos_galpon/', '📦', 'Pedidos galpón', 'pedidos_galpon') ?>
            <?php if (($_SESSION['rol'] ?? 'admin') === 'admin'): ?>
            <?= navLink(BASE_PATH . '/cuentas/', '💰', 'Cuentas', 'cuentas') ?>
            <?php endif; ?>
            <?= navLink(BASE_PATH . '/reportes/', '📊', (($_SESSION['rol'] ?? 'admin') === 'admin' ? 'Reportes' : 'Pedidos y Clientes'), 'reportes') ?>
            <?php if (($_SESSION['rol'] ?? 'admin') === 'admin'): ?>
            <div class="nav-section">Administración</div>
            <?= navLink(BASE_PATH . '/caja/', '💵', 'Caja de Plata', 'caja') ?>
            <?= navLink(BASE_PATH . '/stock/', '📦', 'Stock', 'stock') ?>
            <?php endif; ?>
            <div class="nav-section">Catálogo</div>
            <?= navLink(BASE_PATH . '/catalogo/', '📄', 'Generar Catálogo', 'catalogo') ?>
        </nav>
        <div style="padding:14px 16px; border-top:1px solid rgba(255,255,255,.15);">
            <div style="font-size:10px; text-transform:uppercase; letter-spacing:.6px; opacity:.55; margin-bottom:3px;">Administrador</div>
            <div style="font-size:14px; font-weight:700; margin-bottom:10px;"><?= e($_SESSION['nombre_real'] ?? 'Usuario') ?></div>
            <a href="<?= BASE_PATH ?>/logout.php"
               style="display:block; text-align:center; padding:7px 0; background:rgba(255,255,255,.13);
                      border:1px solid rgba(255,255,255,.18); border-radius:6px; font-size:12px;
                      font-weight:600; text-decoration:none; letter-spacing:.3px; opacity:.9;
                      transition:background .15s;"
               onmouseover="this.style.background='rgba(255,255,255,.22)'"
               onmouseout="this.style.background='rgba(255,255,255,.13)'">
                Cerrar sesión
            </a>
        </div>
    </aside>
    <div class="main">
        <div class="topbar">
            <span class="topbar-title"><?= $pageTitle ?? '' ?></span>
            <div class="topbar-actions">
                <?php if (!empty($topbarActions)) echo $topbarActions; ?>
            </div>
        </div>
        <div class="content">

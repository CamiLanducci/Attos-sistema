# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**ATTOS** is a traditional LAMP stack business management web app for a distribution company. It manages clients, products, price lists, invoices (comprobantes), and monthly sales reports. No frameworks — pure PHP, vanilla JavaScript, custom CSS.

## Development Environment

- **Stack:** PHP (XAMPP), MySQL, vanilla JS/CSS — no build tools, no package managers
- **Server:** Apache via XAMPP on Windows; files served directly from `c:\xampp\htdocs\Attos\`
- **Database:** MySQL at `localhost`, database name `attos`, user `root`, no password (see [config/db.php](config/db.php))
- **Access:** `http://localhost/Attos/`

There is no build step, test suite, or linter. Development is edit-and-refresh.

## Database Setup

Initialize the database by running the SQL scripts in order:

```sql
-- 1. Initial schema
source db/schema.sql

-- 2. Apply updates
source db/update_v2.sql
source db/update_v3.sql
```

Note: `lista_precios` (the product-price-per-list junction table) is not in `schema.sql` but is referenced throughout the code — it must exist for the app to function.

## Architecture

### Directory Structure

Each business module lives in its own subdirectory with a consistent internal pattern:

```
/config/        — db.php (PDO connection + helpers), layout.php / layout_end.php (shared header/footer)
/clientes/      — client CRUD
/productos/     — product catalog with CSV/JSON/HTML import
/listas/        — price list management and product import
/comprobantes/  — invoice creation, viewing, printing, state transitions
/reportes/      — monthly sales analytics
/catalogo/      — printable/PDF catalog generator
/assets/        — css/style.css, js/main.js
/db/            — schema.sql, update_v2.sql, update_v3.sql
```

Root-level `.php` files (`clientes.php`, `productos.php`, etc.) are deprecated; use the module directories instead.

### Module File Pattern

Every module follows this structure:
- `index.php` — list/dashboard view
- `form.php` — create/edit form (GET renders form, POST processes it)
- `actions.php` — handles delete and state change operations via `?action=X`

Every page wraps content with:
```php
require_once __DIR__ . '/../config/layout.php';    // renders <head> + sidebar nav
// ... page content ...
require_once __DIR__ . '/../config/layout_end.php'; // closes HTML
```

### Data Model

```
listas (price list / margin tier)
  ├── clientes (each customer is assigned to a lista)
  │     └── comprobantes (invoices reference cliente + lista)
  │           └── comprobante_items (line-item snapshot: prices/costs at invoice time)
  └── lista_precios (product costs per price list — junction table)
        └── productos (product catalog)
```

Key business rule: `precio_unitario = costo × (1 + margen / 100)`. Price calculations happen client-side in JS during invoice creation and are stored as snapshots in `comprobante_items` for historical accuracy.

### Database Access Pattern

All queries use PDO with prepared statements via the `getDB()` singleton:

```php
$db = getDB();
$stmt = $db->prepare("SELECT * FROM clientes WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();          // single row
$rows = $stmt->fetchAll();      // multiple rows
```

Helper functions in [config/db.php](config/db.php):
- `getDB()` — PDO singleton
- `e($str)` — HTML-escape output
- `precio($n)` — format as Argentine peso currency
- `redirect($url)` — HTTP redirect + exit

### Soft Deletes

Clients and products use soft deletes (`activo = 0`), not `DELETE`. Always filter with `WHERE activo = 1` when listing records.

## Comprobante States

Invoices cycle through: `borrador` (draft) → `emitido` (issued) → `cobrado` (paid). State transitions are handled in [comprobantes/actions.php](comprobantes/actions.php).

## Price List Import

[listas/importar.php](listas/importar.php) fetches a URL and auto-detects CSV, JSON, or HTML table formats. Column detection is heuristic — it scans headers for keywords like `codigo`, `precio`, `costo`.

## CSS & JS

- CSS variables define the theme (burgundy `#631636`, beige). All styling is in [assets/css/style.css](assets/css/style.css).
- [assets/js/main.js](assets/js/main.js) handles: confirmation dialogs for destructive actions, auto-dismiss alerts, real-time price/total calculation on invoice creation, and client-side table filtering.

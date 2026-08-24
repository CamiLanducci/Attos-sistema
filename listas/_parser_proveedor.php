<?php
/**
 * Parser HTML para listas de precios del proveedor.
 * Las funciones helper son puras y no dependen de estado global.
 */

function _lp_getInnerHTML(DOMNode $node): string {
    $html = '';
    foreach ($node->childNodes as $child) {
        $html .= $node->ownerDocument->saveHTML($child);
    }
    return $html;
}

function _lp_limpiarTexto(string $texto): string {
    $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $texto = strip_tags($texto);
    $texto = preg_replace('/\s+/u', ' ', $texto);
    return trim($texto);
}

function _lp_contieneDatosProducto(string $texto): bool {
    return (
        stripos($texto, 'Pack:')    !== false &&
        (stripos($texto, 'Código:') !== false || stripos($texto, 'CÃ³digo:') !== false) &&
        stripos($texto, 'Precio:')  !== false
    );
}

function _lp_esCabeceraMarca(string $texto): bool {
    if ($texto === '' || _lp_contieneDatosProducto($texto)) return false;
    foreach (['Buscar artículo','Lista de Precios','Los Precios son x Unidad','Precio','Pack','Código','CÃ³digo'] as $p) {
        if (stripos($texto, $p) !== false) return false;
    }
    return true;
}

function _lp_extraerProducto(string $innerHtml): ?array {
    $html = html_entity_decode($innerHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = str_replace(["\r", "\n"], ' ', $html);

    if (stripos($html, 'Pack:')    === false ||
        (stripos($html, 'Código:') === false && stripos($html, 'CÃ³digo:') === false) ||
        stripos($html, 'Precio:')  === false) {
        return null;
    }

    $nombre = preg_replace('/<br\s*\/?>.*$/i', '', $html);
    $nombre = _lp_limpiarTexto($nombre);

    preg_match('/Pack:\s*(\d+)/iu',                      $html, $packMatch);
    preg_match('/C(?:Ã³|ó)digo:\s*([0-9]+)/iu',         $html, $codigoMatch);
    preg_match('/Precio:\s*<b>\s*([\d\.,]+)\s*<\/b>/iu', $html, $precioMatch);
    if (empty($precioMatch)) preg_match('/Precio:\s*([\d\.,]+)/iu', $html, $precioMatch);

    if (empty($nombre) || empty($packMatch) || empty($codigoMatch) || empty($precioMatch)) return null;

    $pack   = (int)$packMatch[1];
    $codigo = trim($codigoMatch[1]);
    $p      = trim($precioMatch[1]);

    if (substr_count($p, '.') === 1 && substr_count($p, ',') === 0) {
        $precioUnidad = (float)$p;
    } else {
        $precioUnidad = (float)str_replace(',', '.', str_replace('.', '', $p));
    }

    if ($pack <= 0 || $precioUnidad <= 0 || $codigo === '') return null;

    return [
        'nombre'        => $nombre,
        'codigo'        => $codigo,
        'pack'          => $pack,
        'precio_unidad' => $precioUnidad,
    ];
}

/**
 * Parsea el HTML completo de una lista del proveedor.
 * Devuelve array de ['codigo', 'nombre', 'marca', 'pack', 'precio_unidad'].
 */
function parsearHTMLProveedor(string $html): array {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);
    $nodos = $xpath->query("//td[contains(@class,'dxgv')]");

    if (!$nodos || $nodos->length === 0) return [];

    $productos    = [];
    $marcaActual  = 'SIN MARCA';

    foreach ($nodos as $nodo) {
        $textoPlano = _lp_limpiarTexto($nodo->textContent);
        $innerHtml  = _lp_getInnerHTML($nodo);

        if ($textoPlano === '') continue;

        if (_lp_esCabeceraMarca($textoPlano)) {
            $marcaActual = $textoPlano;
            continue;
        }

        if (!_lp_contieneDatosProducto($textoPlano) && !_lp_contieneDatosProducto($innerHtml)) continue;

        $prod = _lp_extraerProducto($innerHtml);
        if (!$prod) continue;

        $prod['marca'] = $marcaActual;
        $productos[]   = $prod;
    }

    return $productos;
}

/**
 * Los catálogos públicos nuevos (ej: aqv.soysowi.com) son una SPA: la URL que
 * se comparte (/catalogo/publico/{token}) sirve el HTML vacío de la app, y los
 * datos reales están en /api/products/catalog/public/{token}/ (JSON). Si la URL
 * configurada es la de la página pública, la reescribimos a la del API.
 */
function _lp_resolverUrlApiCatalogo(string $url): string {
    if (preg_match('~^(https?://[^/]+)/catalogo/publico/([^/?#]+)~i', $url, $m)) {
        return $m[1] . '/api/products/catalog/public/' . $m[2] . '/';
    }
    return $url;
}

/**
 * Parsea la respuesta JSON del API de catálogo público (formato nuevo).
 * Estructura: { estado, bodegas: [{ bodega, productos: [{codigo, descripcion, presentacion, precio}] }] }
 * "bodega" se usa como marca. No siempre viene el pack (unidades por caja) —
 * cuando no viene, 'pack' queda en null para no pisar el valor ya cargado del producto.
 */
function parsearJSONCatalogoProveedor(array $data): array {
    if (($data['estado'] ?? '') !== 'vigente' || empty($data['bodegas'])) return [];

    $productos = [];
    foreach ($data['bodegas'] as $bodega) {
        $marca = trim((string)($bodega['bodega'] ?? ''));
        if ($marca === '') $marca = 'SIN MARCA';

        foreach (($bodega['productos'] ?? []) as $p) {
            $codigo       = trim((string)($p['codigo'] ?? ''));
            $nombre       = trim((string)($p['descripcion'] ?? ''));
            $precioUnidad = (float)($p['precio'] ?? 0);

            if ($codigo === '' || $nombre === '' || $precioUnidad <= 0) continue;

            $pack = null;
            if (!empty($p['presentacion']) && preg_match('/x\s*(\d+)/i', $p['presentacion'], $pm)) {
                $pack = (int)$pm[1];
            }

            $productos[] = [
                'nombre'        => $nombre,
                'codigo'        => $codigo,
                'marca'         => $marca,
                'pack'          => $pack,
                'precio_unidad' => $precioUnidad,
            ];
        }
    }

    return $productos;
}

/**
 * Decodifica un stream ASCII85 (delimitado sin '<~', puede terminar en '~>').
 * Usado porque el catálogo PDF de aqv.soysowi.com comprime sus content
 * streams con /Filter [ /ASCII85Decode /FlateDecode ].
 */
function _lp_pdfAscii85Decode(string $data): string {
    $s = preg_replace('/\s+/', '', $data);
    if (substr($s, -2) === '~>') $s = substr($s, 0, -2);

    $out   = '';
    $group = [];
    $len   = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];
        if ($c === 'z' && empty($group)) {
            $out .= "\x00\x00\x00\x00";
            continue;
        }
        $group[] = ord($c) - 33;
        if (count($group) === 5) {
            $val = 0;
            foreach ($group as $g) $val = $val * 85 + $g;
            $out  .= pack('N', $val);
            $group = [];
        }
    }
    if (!empty($group)) {
        $n = count($group);
        while (count($group) < 5) $group[] = 84;
        $val = 0;
        foreach ($group as $g) $val = $val * 85 + $g;
        $bytes = pack('N', $val);
        $out  .= substr($bytes, 0, $n - 1);
    }
    return $out;
}

/**
 * Extrae y concatena todos los content streams de texto de un PDF (páginas),
 * probando FlateDecode directo y ASCII85Decode+FlateDecode (ambos usados por
 * distintos generadores de PDF). Streams que no descomprimen o no contienen
 * operadores de texto (Tj) se ignoran (son imágenes, fuentes embebidas, etc).
 *
 * Los streams de imágenes/fuentes embebidas pueden pesar varios MB — sin
 * límite, intentar descomprimirlos (y tokenizar el resultado) puede tardar
 * minutos o agotar memory_limit, dejando la importación "colgada" sin ningún
 * output. Por eso se descartan de entrada los streams demasiado grandes para
 * ser texto y se acota el tamaño de salida de gzuncompress.
 */
function _lp_pdfExtraerContenido(string $pdfBytes): string {
    $maxRawStreamBytes     = 2 * 1024 * 1024; // streams de texto reales no llegan a esto
    $maxDecodedStreamBytes = 4 * 1024 * 1024; // límite pasado a gzuncompress()

    $contenido = '';
    $offset    = 0;
    while (preg_match('/(?<!d)stream\r?\n/', $pdfBytes, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $start = $m[0][1] + strlen($m[0][0]);
        $end   = strpos($pdfBytes, 'endstream', $start);
        if ($end === false) break;

        if ($end - $start > $maxRawStreamBytes) {
            // Casi seguro una imagen o fuente embebida: se salta sin descomprimir.
            $offset = $end + 9;
            continue;
        }

        $raw = rtrim(substr($pdfBytes, $start, $end - $start), "\r\n");

        $decoded = @gzuncompress($raw, $maxDecodedStreamBytes);
        if ($decoded === false) {
            $decoded = @gzuncompress(_lp_pdfAscii85Decode($raw), $maxDecodedStreamBytes);
        }
        if ($decoded !== false && strpos($decoded, 'Tj') !== false) {
            $contenido .= $decoded . "\n";
        }

        $offset = $end + 9; // len('endstream')
    }
    return $contenido;
}

/**
 * Tokeniza un content stream PDF: strings (...), nombres /Foo, números y
 * operadores sueltos (q, Q, cm, rg, Tf, Tj, etc). Suficiente para el
 * subconjunto de operadores que generan las tablas de precios — no es un
 * intérprete PDF completo (ignora arrays TJ, dicts, matrices no triviales).
 */
function _lp_pdfTokenizar(string $s): array {
    $tokens = [];
    $n      = strlen($s);
    $i      = 0;
    while ($i < $n) {
        $c = $s[$i];
        if ($c === ' ' || $c === "\n" || $c === "\r" || $c === "\t") { $i++; continue; }

        if ($c === '(') {
            $depth = 1; $j = $i + 1; $buf = '';
            while ($j < $n && $depth > 0) {
                $cj = $s[$j];
                if ($cj === '\\') { $buf .= $cj . ($s[$j + 1] ?? ''); $j += 2; continue; }
                if ($cj === '(') $depth++;
                if ($cj === ')') { $depth--; if ($depth === 0) { $j++; break; } }
                $buf .= $cj; $j++;
            }
            $tokens[] = ['t' => 'STR', 'v' => $buf];
            $i = $j;
            continue;
        }

        if ($c === '/') {
            $j = $i + 1;
            while ($j < $n && strpbrk($s[$j], " \n\r\t/[]<>()") === false) $j++;
            $tokens[] = ['t' => 'NAME', 'v' => substr($s, $i + 1, $j - $i - 1)];
            $i = $j;
            continue;
        }

        if ($c === '[' || $c === ']' || $c === '<' || $c === '>') { $i++; continue; }

        if (strpbrk($c, '0123456789+-.') !== false) {
            $j = $i + 1;
            while ($j < $n && strpbrk($s[$j], '0123456789+-.eE') !== false) $j++;
            $tokens[] = ['t' => 'NUM', 'v' => (float)substr($s, $i, $j - $i)];
            $i = $j;
            continue;
        }

        $j = $i + 1;
        while ($j < $n && strpbrk($s[$j], " \n\r\t/[]()<>") === false) $j++;
        $tokens[] = ['t' => 'OP', 'v' => substr($s, $i, $j - $i)];
        $i = $j;
    }
    return $tokens;
}

/** Decodifica escapes de un string literal PDF (\ddd octal, \(, \), \\) y lo pasa a UTF-8. */
function _lp_pdfDecodeStr(string $raw): string {
    $bytes = '';
    $n     = strlen($raw);
    for ($i = 0; $i < $n; $i++) {
        $c = $raw[$i];
        if ($c === '\\' && $i + 1 < $n) {
            $next = $raw[$i + 1];
            if ($next >= '0' && $next <= '7') {
                $oct = $next;
                $k   = $i + 2;
                for ($cnt = 0; $cnt < 2 && $k < $n && $raw[$k] >= '0' && $raw[$k] <= '7'; $cnt++, $k++) $oct .= $raw[$k];
                $bytes .= chr(octdec($oct) & 0xFF);
                $i = $k - 1;
                continue;
            }
            $map = ['(' => '(', ')' => ')', '\\' => '\\', 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C"];
            $bytes .= $map[$next] ?? $next;
            $i++;
            continue;
        }
        $bytes .= $c;
    }
    // Conversión manual Latin-1 -> UTF-8 (sin depender de la extensión iconv,
    // que no siempre está instalada): todo byte 0x00-0xFF de Latin-1 mapea
    // directo al mismo codepoint Unicode, así que la codificación UTF-8 es
    // mecánica (1 byte si <0x80, 2 bytes si no).
    $utf8 = '';
    foreach (str_split($bytes) as $b) {
        $o = ord($b);
        $utf8 .= $o < 0x80 ? $b : chr(0xC0 | ($o >> 6)) . chr(0x80 | ($o & 0x3F));
    }
    return $utf8;
}

/**
 * Interpreta el stream de tokens llevando el estado gráfico mínimo necesario
 * (pila q/Q, color rg, fuente Tf, traslación cm) y emite una celda por cada
 * operador Tj, fusionando en una sola celda las líneas que un mismo bloque de
 * texto parte con T* (p.ej. una descripción que ocupa dos renglones).
 */
function _lp_pdfCeldas(array $tokens): array {
    $pila = [];
    $rg = $font = $size = $cmX = $cmY = null;
    $operandos = [];
    $celdas    = [];

    foreach ($tokens as $tok) {
        if ($tok['t'] !== 'OP') { $operandos[] = $tok; continue; }
        $op = $tok['v'];

        if ($op === 'q') {
            $pila[] = [$rg, $font, $size, $cmX, $cmY];
        } elseif ($op === 'Q') {
            $st = array_pop($pila);
            if ($st !== null) [$rg, $font, $size, $cmX, $cmY] = $st;
        } elseif ($op === 'cm') {
            $nums = array_values(array_map(fn($o) => $o['v'], array_filter($operandos, fn($o) => $o['t'] === 'NUM')));
            if (count($nums) >= 6) { $cmX = $nums[count($nums) - 2]; $cmY = $nums[count($nums) - 1]; }
        } elseif ($op === 'rg') {
            $nums = array_values(array_map(fn($o) => $o['v'], array_filter($operandos, fn($o) => $o['t'] === 'NUM')));
            $rg = implode(' ', $nums);
        } elseif ($op === 'Tf') {
            $names = array_values(array_map(fn($o) => $o['v'], array_filter($operandos, fn($o) => $o['t'] === 'NAME')));
            $nums  = array_values(array_map(fn($o) => $o['v'], array_filter($operandos, fn($o) => $o['t'] === 'NUM')));
            if ($names) $font = end($names);
            if ($nums)  $size = end($nums);
        } elseif ($op === 'Tj') {
            $strs = array_values(array_filter($operandos, fn($o) => $o['t'] === 'STR'));
            if ($strs) {
                $texto = end($strs)['v'];
                $ultima = end($celdas);
                if ($ultima !== false && $ultima['x'] === $cmX && $ultima['y'] === $cmY
                    && $ultima['rg'] === $rg && $ultima['font'] === $font && $ultima['size'] === $size) {
                    $celdas[array_key_last($celdas)]['texto'] .= ' ' . $texto;
                } else {
                    $celdas[] = ['texto' => $texto, 'rg' => $rg, 'font' => $font, 'size' => $size, 'x' => $cmX, 'y' => $cmY];
                }
            }
        }
        $operandos = [];
    }

    return $celdas;
}

/**
 * Parsea el catálogo PDF público de aqv.soysowi.com (lista l56 — el proveedor
 * bloquea la descarga automática, así que se sube el PDF manualmente). Es un
 * PDF de texto real generado con tablas: cada sección lleva un encabezado de
 * marca (fuente F2, tamaño 12) seguido de filas de 4 celdas en fuente F1,
 * tamaño 9, color negro puro — Código, Descripción, Presentación, Precio, en
 * ese orden fijo. Frágil ante un cambio de plantilla del proveedor: si deja
 * de encontrar productos, revisar si cambiaron fuente/tamaño/orden de columnas.
 */
function parsearPDFCatalogoProveedor(string $pdfBytes): array {
    $contenido = _lp_pdfExtraerContenido($pdfBytes);
    if ($contenido === '') return [];

    $tokens = _lp_pdfTokenizar($contenido);
    $celdas = _lp_pdfCeldas($tokens);

    $productos    = [];
    $marcaActual  = 'SIN MARCA';
    $buffer       = [];

    foreach ($celdas as $c) {
        if ($c['font'] === 'F2' && $c['size'] == 12) {
            $marca = _lp_pdfDecodeStr($c['texto']);
            $marca = preg_replace('/^[^\p{L}\p{N}]+/u', '', $marca);
            $marcaActual = trim($marca) !== '' ? trim($marca) : 'SIN MARCA';
            $buffer = [];
            continue;
        }
        if ($c['font'] !== 'F1' || $c['size'] != 9 || $c['rg'] !== '0 0 0') continue;

        $buffer[] = preg_replace('/\s+/u', ' ', trim(_lp_pdfDecodeStr($c['texto'])));
        if (count($buffer) < 4) continue;

        [$codigo, $descripcion, $presentacion, $precioTxt] = $buffer;
        $buffer = [];

        if (!preg_match('/^\d+$/', $codigo)) continue;
        if (!preg_match('/^\$[\d,.]+$/', $precioTxt)) continue;

        $precioUnidad = (float)str_replace(',', '', ltrim($precioTxt, '$'));
        if ($descripcion === '' || $precioUnidad <= 0) continue;

        $pack = null;
        if ($presentacion !== '-' && preg_match('/x\s*(\d+)/i', $presentacion, $pm)) {
            $pack = (int)$pm[1];
        }

        $productos[] = [
            'nombre'        => $descripcion,
            'codigo'        => $codigo,
            'marca'         => $marcaActual,
            'pack'          => $pack,
            'precio_unidad' => $precioUnidad,
        ];
    }

    return $productos;
}

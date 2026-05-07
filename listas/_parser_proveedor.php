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

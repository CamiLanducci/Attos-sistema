<?php
// Helper reutilizable para filtros temporales — dashboard, reportes, export

/**
 * Lee $_GET['periodo'] (y opcionalmente $_GET['desde'] / $_GET['hasta'])
 * y devuelve el rango activo como array.
 *
 * @return array{key:string, desde:string, hasta:string, label:string}
 */
function getPeriodoActual(): array
{
    $valid   = ['hoy', 'semana', 'mes', 'anio', 'mes_anterior', 'custom'];
    $periodo = $_GET['periodo'] ?? 'mes';
    if (!in_array($periodo, $valid, true)) $periodo = 'mes';

    $hoy = new DateTime('today');

    switch ($periodo) {
        case 'hoy':
            $desde = new DateTime('today');
            $hasta = new DateTime('today');
            $label = 'Hoy';
            break;

        case 'semana':
            $dow   = (int)$hoy->format('N'); // 1 = lunes, 7 = domingo
            $desde = (clone $hoy)->modify('-' . ($dow - 1) . ' days');
            $hasta = clone $hoy;
            $label = 'Esta semana';
            break;

        case 'anio':
            $desde = new DateTime($hoy->format('Y') . '-01-01');
            $hasta = clone $hoy;
            $label = 'Este año';
            break;

        case 'mes_anterior':
            $desde = (new DateTime($hoy->format('Y-m-01')))->modify('-1 month');
            $hasta = (clone $desde)->modify('last day of this month');
            $label = 'Mes anterior';
            break;

        case 'custom':
            $dr = preg_replace('/[^0-9\-]/', '', $_GET['desde'] ?? '');
            $hr = preg_replace('/[^0-9\-]/', '', $_GET['hasta'] ?? '');
            $d  = DateTime::createFromFormat('Y-m-d', $dr);
            $h  = DateTime::createFromFormat('Y-m-d', $hr);
            if ($d && $h && $d <= $h) {
                $desde   = $d;
                $hasta   = $h;
                $label   = 'Rango personalizado';
                break;
            }
            // Fechas inválidas → cae a "este mes"
            $periodo = 'mes';
            $desde   = new DateTime($hoy->format('Y-m-01'));
            $hasta   = clone $hoy;
            $label   = 'Este mes';
            break;

        default: // mes
            $periodo = 'mes';
            $desde   = new DateTime($hoy->format('Y-m-01'));
            $hasta   = clone $hoy;
            $label   = 'Este mes';
    }

    return [
        'key'   => $periodo,
        'desde' => $desde->format('Y-m-d'),
        'hasta' => $hasta->format('Y-m-d'),
        'label' => $label,
    ];
}

/**
 * Dado un rango actual, devuelve el rango anterior equivalente
 * (misma cantidad de días, corrido hacia atrás, terminando el día
 * anterior al inicio del rango actual).
 *
 * Ejemplo: actual 01/04–23/04 (23 días) → anterior 09/03–31/03
 *
 * @return array{desde:string, hasta:string}
 */
function getPeriodoAnterior(string $desde, string $hasta): array
{
    $d    = new DateTime($desde);
    $h    = new DateTime($hasta);
    $dias = (int)$d->diff($h)->days; // diferencia en días (0 si son el mismo día)

    $hastaAnt = (clone $d)->modify('-1 day');
    $desdeAnt = (clone $hastaAnt)->modify("-{$dias} days");

    return [
        'desde' => $desdeAnt->format('Y-m-d'),
        'hasta' => $hastaAnt->format('Y-m-d'),
    ];
}

/**
 * Formatea el rango como string legible para mostrar en el header.
 * Si desde == hasta muestra solo una fecha.
 */
function formatRangoVisible(string $desde, string $hasta): string
{
    $fd = (new DateTime($desde))->format('d/m/Y');
    $fh = (new DateTime($hasta))->format('d/m/Y');
    return $fd === $fh ? $fd : "{$fd} – {$fh}";
}

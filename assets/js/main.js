// ============================================================
//  ATTOS — JavaScript principal
// ============================================================

function formatPeso(n) {
    const parts = Number(n).toFixed(2).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return '$' + parts[0] + ',' + parts[1];
}

// Confirmación de eliminación
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', e => {
            if (!confirm(el.dataset.confirm || '¿Confirmar acción?')) {
                e.preventDefault();
            }
        });
    });

    // Auto-dismiss de alertas
    document.querySelectorAll('.alert[data-autodismiss]').forEach(el => {
        setTimeout(() => el.style.display = 'none', 3500);
    });
});

// Búsqueda en tabla
function filtrarTabla(inputId, tablaId) {
    const input = document.getElementById(inputId);
    const tabla = document.getElementById(tablaId);
    if (!input || !tabla) return;

    input.addEventListener('input', () => {
        const q = input.value.toLowerCase();
        tabla.querySelectorAll('tbody tr').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });
}

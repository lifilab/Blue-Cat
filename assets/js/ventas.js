var ventasOriginales = [];

function formatAmount(value) {
    var number = Number(value || 0);
    return '$' + number.toLocaleString('es-CL');
}

function formatDate(value) {
    if (!value) {
        return '--';
    }

    var date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString('es-CL');
}

function setText(id, value) {
    var element = document.getElementById(id);
    if (element) {
        element.textContent = value == null || value === '' ? '--' : value;
    }
}

function createCell(text, className) {
    var cell = document.createElement('td');
    cell.textContent = text == null || text === '' ? '--' : text;

    if (className) {
        cell.className = className;
    }

    return cell;
}

function getSearchTerm() {
    var input = document.getElementById('buscar_venta');
    return input ? input.value.trim().toLowerCase() : '';
}

function ventaCoincideBusqueda(venta, term) {
    if (!term) {
        return true;
    }

    return [
        venta.id_pedido,
        venta.empleado,
        venta.usuario,
        venta.cliente_nombre,
        venta.tipo_documento,
        venta.metodos_pago,
        venta.fecha
    ].some(function(value) {
        return String(value || '').toLowerCase().indexOf(term) !== -1;
    });
}

function renderVentas() {
    var tbody = document.getElementById('ventas_body');
    var empty = document.getElementById('ventas_empty');
    var term = getSearchTerm();
    var ventas = ventasOriginales.filter(function(venta) {
        return ventaCoincideBusqueda(venta, term);
    });

    tbody.textContent = '';

    ventas.forEach(function(venta) {
        var row = document.createElement('tr');

        row.appendChild(createCell(formatDate(venta.fecha)));
        row.appendChild(createCell('#' + venta.id_pedido));
        row.appendChild(createCell(venta.empleado));
        row.appendChild(createCell(venta.id_caja ? '#' + venta.id_caja : '--'));
        row.appendChild(createCell(venta.tipo_documento || 'BOLETA'));
        row.appendChild(createCell(venta.cliente_nombre || 'Consumidor final'));
        row.appendChild(createCell(formatAmount(venta.precio_total), 'amount'));
        row.appendChild(createCell(formatAmount(venta.pago_total), 'amount'));
        row.appendChild(createCell(formatAmount(venta.diferencia), 'amount'));
        row.appendChild(createCell(venta.metodos_pago));

        tbody.appendChild(row);
    });

    empty.style.display = ventas.length === 0 ? 'block' : 'none';
}

function renderResumen(data) {
    var totales = data.totales || {};

    setText('total_ventas', Number(totales.cantidad || 0).toLocaleString('es-CL'));
    setText('monto_vendido', formatAmount(totales.monto_total));
    setText('efectivo_total', formatAmount(totales.efectivo));
    setText('tarjeta_total', formatAmount(totales.tarjeta));
    setText('transferencia_total', formatAmount(totales.transferencia));
    setText('alcance_ventas', data.puede_ver_todos ? 'Ventas de la cuenta' : 'Mis ventas');
}

function cargarVentas() {
    var periodo = document.getElementById('periodo_ventas').value;
    var estado = document.getElementById('estado_carga');

    estado.textContent = 'Cargando ventas...';

    fetch('../assets/PHP/ventas.php?periodo=' + encodeURIComponent(periodo), {
        credentials: 'same-origin'
    })
        .then(function(response) {
            return response.json().then(function(data) {
                return {
                    ok: response.ok && data.ok,
                    status: response.status,
                    data: data
                };
            });
        })
        .then(function(result) {
            if (!result.ok) {
                ventasOriginales = [];
                renderVentas();
                renderResumen({ totales: {} });
                estado.textContent = result.data.mensaje || 'No se pudieron cargar las ventas.';
                return;
            }

            ventasOriginales = result.data.ventas || [];
            renderResumen(result.data);
            renderVentas();
            estado.textContent = result.data.mensaje || 'Ventas cargadas correctamente.';
        })
        .catch(function() {
            ventasOriginales = [];
            renderVentas();
            renderResumen({ totales: {} });
            estado.textContent = 'Error de red al cargar ventas.';
        });
}

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('periodo_ventas').addEventListener('change', cargarVentas);
    document.getElementById('buscar_venta').addEventListener('input', renderVentas);
    document.getElementById('recargar_ventas').addEventListener('click', cargarVentas);

    cargarVentas();
});

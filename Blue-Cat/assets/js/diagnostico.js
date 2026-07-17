(function () {
  'use strict';
  function setText(id, value) { document.getElementById(id).textContent = value; }
  function refresh() {
    var dot = document.getElementById('diag-dot'); dot.className = 'diag-dot';
    setText('diag-message', 'Comprobando el servidor…');
    fetch('../assets/api/health.php', {cache: 'no-store'})
      .then(function (response) { return response.json().then(function (data) { return {ok: response.ok, data: data}; }); })
      .then(function (result) {
        var data = result.data || {}; var ready = result.ok && data.status === 'ok';
        dot.classList.add(ready ? 'ok' : 'warn');
        setText('diag-message', ready ? 'Servidor operativo' : (data.status === 'setup_required' ? 'Falta completar la configuración inicial' : 'Servidor degradado'));
        setText('diag-service', result.ok ? 'Disponible' : 'Con problemas'); setText('diag-database', data.database ? 'Conectada' : 'No disponible');
        setText('diag-setup', data.setup ? 'Completa' : 'Pendiente'); setText('diag-version', data.version || 'Desconocida');
      })
      .catch(function () {
        dot.classList.add('err'); setText('diag-message', 'No fue posible contactar al servidor');
        setText('diag-service', 'No disponible'); setText('diag-database', '—'); setText('diag-setup', '—'); setText('diag-version', '—');
      });
  }
  document.getElementById('diag-refresh').addEventListener('click', refresh); refresh();
})();

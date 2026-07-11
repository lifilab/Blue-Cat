document.addEventListener('DOMContentLoaded', function () {
  var menuUl = document.querySelector('.menu ul');
  if (!menuUl) return;

  var currentPage = location.pathname.replace(/\\/g, '/').split('/').pop() || 'Inicio.html';

  fetch('../assets/api/core.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ accion: 'sidebar' })
  })
    .then(function (res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    })
    .then(function (data) {
      var modulos = data.modulos || [];
      var html = '';
      for (var i = 0; i < modulos.length; i++) {
        var m = modulos[i];
        html += '<li><a href="' + m.ruta + '"><i class="fas ' + m.icono + '"></i> ' + m.nombre + '</a></li>';
      }
      html += '<li><a href="#" onclick="openCerrarSesionPopup()"><i class="fas fa-sign-out-alt"></i> Salir</a></li>';
      menuUl.innerHTML = html;

      var links = menuUl.querySelectorAll('a');
      for (var j = 0; j < links.length; j++) {
        if (links[j].getAttribute('href') === currentPage) {
          links[j].classList.add('active');
        }
      }
    })
    .catch(function () {
      menuUl.innerHTML = '<li><a href="Inicio.html"><i class="fas fa-home"></i> Inicio</a></li>' +
        '<li><a href="#" onclick="openCerrarSesionPopup()"><i class="fas fa-sign-out-alt"></i> Salir</a></li>';
      var links = menuUl.querySelectorAll('a');
      for (var k = 0; k < links.length; k++) {
        if (links[k].getAttribute('href') === currentPage) {
          links[k].classList.add('active');
        }
      }
    });
});

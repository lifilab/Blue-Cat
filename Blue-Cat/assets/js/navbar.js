(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var menu = document.querySelector('.menu');
    if (!menu) return;

    var currentPage = (location.pathname.replace(/\\/g, '/').split('/').pop() || 'Inicio.html').toLowerCase();
    var primaryCodes = ['inicio', 'pos', 'ventas', 'inventario'];

    function esc(value) {
      return String(value == null ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function uniqueModules(items) {
      var seen = {};
      return (Array.isArray(items) ? items : []).filter(function (item) {
        var code = String(item && item.codigo || '').trim().toLowerCase();
        if (!code || !item.ruta || seen[code]) return false;
        seen[code] = true;
        item.codigo = code;
        return true;
      });
    }

    function isCurrent(module) {
      return String(module.ruta || '').split('/').pop().toLowerCase() === currentPage;
    }

    function moduleLink(module, compact) {
      var active = isCurrent(module);
      return '<li data-code="' + esc(module.codigo) + '"><a class="bc-nav-link' + (active ? ' active' : '') + '" href="' + esc(module.ruta) + '"' +
        (active ? ' aria-current="page"' : '') + '><i class="fas ' + esc(module.icono || 'fa-cube') + '" aria-hidden="true"></i>' +
        (compact ? '' : '<span>' + esc(module.nombre) + '</span>') + '</a></li>';
    }

    function logoutButton() {
      return '<li class="bc-nav-separator"><button type="button" class="bc-nav-link bc-nav-logout" data-nav-logout>' +
        '<i class="fas fa-sign-out-alt" aria-hidden="true"></i><span>Cerrar sesión</span></button></li>';
    }

    function render(modules) {
      modules = uniqueModules(modules);
      var primary = modules.filter(function (module) { return primaryCodes.indexOf(module.codigo) !== -1; });
      primary.sort(function (a, b) { return primaryCodes.indexOf(a.codigo) - primaryCodes.indexOf(b.codigo); });
      var secondary = modules.filter(function (module) { return primaryCodes.indexOf(module.codigo) === -1; });
      var secondaryActive = secondary.some(isCurrent);

      var primaryHtml = primary.map(function (module) { return moduleLink(module, false); }).join('');
      var secondaryHtml = secondary.map(function (module) { return moduleLink(module, false); }).join('');
      var mobileHtml = modules.map(function (module) { return moduleLink(module, false); }).join('') + logoutButton();

      menu.innerHTML =
        '<button type="button" class="bc-nav-toggle" aria-expanded="false" aria-controls="bc-nav-drawer" aria-label="Abrir menú">' +
          '<i class="fas fa-bars" aria-hidden="true"></i><span>Menú</span></button>' +
        '<div class="bc-nav-backdrop" data-nav-close></div>' +
        '<div class="bc-nav-panel" id="bc-nav-drawer">' +
          '<div class="bc-nav-drawer-head"><span>Navegación</span><button type="button" data-nav-close aria-label="Cerrar menú"><i class="fas fa-times"></i></button></div>' +
          '<div class="bc-nav-desktop"><ul class="bc-nav-primary">' + primaryHtml + '</ul>' +
            (secondary.length ? '<div class="bc-nav-more"><button type="button" class="bc-nav-more-button' + (secondaryActive ? ' active' : '') + '" aria-expanded="false">' +
              '<i class="fas fa-th-large" aria-hidden="true"></i><span>Más</span><i class="fas fa-chevron-down bc-nav-chevron" aria-hidden="true"></i></button>' +
              '<ul class="bc-nav-dropdown">' + secondaryHtml + logoutButton() + '</ul></div>' :
              '<ul class="bc-nav-primary">' + logoutButton() + '</ul>') +
          '</div>' +
          '<ul class="bc-nav-mobile">' + mobileHtml + '</ul>' +
        '</div>';

      bindInteractions();
      applyDashboardPermissions(modules);
    }

    function closeMenus() {
      menu.classList.remove('is-open', 'more-open');
      document.body.classList.remove('bc-nav-open');
      var toggle = menu.querySelector('.bc-nav-toggle');
      var more = menu.querySelector('.bc-nav-more-button');
      if (toggle) toggle.setAttribute('aria-expanded', 'false');
      if (more) more.setAttribute('aria-expanded', 'false');
    }

    function bindInteractions() {
      var toggle = menu.querySelector('.bc-nav-toggle');
      var more = menu.querySelector('.bc-nav-more-button');
      if (toggle) toggle.addEventListener('click', function () {
        var open = !menu.classList.contains('is-open');
        closeMenus();
        if (open) {
          menu.classList.add('is-open');
          document.body.classList.add('bc-nav-open');
          toggle.setAttribute('aria-expanded', 'true');
        }
      });
      if (more) more.addEventListener('click', function (event) {
        event.stopPropagation();
        var open = !menu.classList.contains('more-open');
        closeMenus();
        if (open) {
          menu.classList.add('more-open');
          more.setAttribute('aria-expanded', 'true');
        }
      });
      menu.querySelectorAll('[data-nav-close]').forEach(function (button) { button.addEventListener('click', closeMenus); });
      menu.querySelectorAll('[data-nav-logout]').forEach(function (button) {
        button.addEventListener('click', function () {
          closeMenus();
          if (typeof window.openCerrarSesionPopup === 'function') window.openCerrarSesionPopup();
        });
      });
      document.addEventListener('click', function (event) {
        if (!menu.contains(event.target)) closeMenus();
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeMenus();
      });
    }

    function applyDashboardPermissions(modules) {
      var allowed = {};
      modules.forEach(function (module) { allowed[module.codigo] = true; });
      document.querySelectorAll('.dash-card').forEach(function (card) {
        var rawHref = card.getAttribute('href') || '';
        if (rawHref === '#') return;
        var href = rawHref.split('/').pop().replace('.html', '').toLowerCase();
        var aliases = { punto_de_venta: 'pos', inicio: 'inicio', cuadre_de_ventas: 'ventas' };
        var code = aliases[href] || href;
        card.style.display = allowed[code] ? '' : 'none';
      });
    }

    function showSetupState(setup, requestFailed) {
      var current = document.querySelector('.bc-setup-alert');
      if (current) current.remove();
      if (!requestFailed && setup && setup.complete) return;
      var host = document.querySelector('main') || document.querySelector('.container') || document.body;
      var alert = document.createElement('section');
      alert.className = 'bc-setup-alert';
      var title = document.createElement('strong');
      title.textContent = requestFailed ? 'No fue posible cargar los módulos' : 'Configuración inicial incompleta';
      var detail = document.createElement('p');
      var labels = { catalogo_modulos: 'catálogo de módulos', suscripcion: 'plan o suscripción', permisos_modulos: 'permisos del usuario' };
      var missing = setup && Array.isArray(setup.missing) ? setup.missing.map(function (item) { return labels[item] || item; }) : [];
      detail.textContent = requestFailed
        ? 'El servidor no entregó la navegación. Abra Diagnóstico Blue-Cat desde el menú Inicio o ejecute Reparar.'
        : 'Falta completar: ' + missing.join(', ') + '. Ejecute Reparar para finalizar la instalación.';
      alert.appendChild(title); alert.appendChild(detail);
      host.insertBefore(alert, host.firstChild);
    }

    fetch('../assets/api/core.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ accion: 'sidebar' })
    }).then(function (response) {
      if (!response.ok) throw new Error('HTTP ' + response.status);
      return response.json();
    }).then(function (data) {
      window.BlueCatPermissions = data.permisos || {};
      window.blueCatHasPermission = function (modulo, accion) {
        return !!(window.BlueCatPermissions[modulo] && window.BlueCatPermissions[modulo].indexOf(accion) !== -1);
      };
      document.dispatchEvent(new CustomEvent('bluecat:permissions-ready', { detail: window.BlueCatPermissions }));
      render(data.modulos || []);
      showSetupState(data.setup || null, false);
    }).catch(function () {
      render([{ codigo: 'inicio', nombre: 'Inicio', icono: 'fa-home', ruta: 'Inicio.html' }]);
      showSetupState(null, true);
    });
  });
}());

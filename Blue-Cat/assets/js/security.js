(function () {
  'use strict';
  function csrfToken() {
    var match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
  }
  function isUnsafe(method) {
    return !['GET', 'HEAD', 'OPTIONS'].includes(String(method || 'GET').toUpperCase());
  }
  function plainMessage(value) {
    return String(value == null ? '' : value)
      .replace(/<[^>]*>/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }
  function renderToast(element, message, type) {
    while (element.firstChild) element.removeChild(element.firstChild);
    var icon = document.createElement('i');
    icon.className = 'fas fa-' + ((type === 'error' || type === 'err') ? 'exclamation-circle' : 'check-circle');
    icon.setAttribute('aria-hidden', 'true');
    element.appendChild(icon);
    element.appendChild(document.createTextNode(' ' + plainMessage(message)));
  }
  window.BlueCatSecurity = Object.freeze({
    plainMessage: plainMessage,
    renderToast: renderToast
  });
  var nativeFetch = window.fetch;
  window.fetch = function (input, init) {
    init = init || {};
    var method = init.method || (input && input.method) || 'GET';
    if (isUnsafe(method)) {
      var headers = new Headers(init.headers || (input && input.headers) || {});
      headers.set('X-Requested-With', 'XMLHttpRequest');
      var token = csrfToken();
      if (token) headers.set('X-CSRF-Token', token);
      init.headers = headers;
    }
    init.credentials = init.credentials || 'same-origin';
    return nativeFetch.call(this, input, init);
  };
  var nativeOpen = XMLHttpRequest.prototype.open;
  var nativeSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (method) {
    this.__bluecatUnsafe = isUnsafe(method);
    return nativeOpen.apply(this, arguments);
  };
  XMLHttpRequest.prototype.send = function () {
    if (this.__bluecatUnsafe) {
      this.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      var token = csrfToken();
      if (token) this.setRequestHeader('X-CSRF-Token', token);
    }
    return nativeSend.apply(this, arguments);
  };
})();

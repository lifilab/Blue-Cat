'use strict';

function parseHttpsUrl(rawValue, settingName, options = {}) {
  const raw = String(rawValue || '').trim();
  if (!raw) {
    throw new Error(`${settingName} no está configurada.`);
  }

  let url;
  try {
    url = new URL(raw);
  } catch (_err) {
    throw new Error(`${settingName} debe ser una URL válida.`);
  }

  const allowLocalHttp = options.allowLocalHttp === true
    && ['localhost', '127.0.0.1'].includes(url.hostname);
  if (url.protocol !== 'https:' && !(url.protocol === 'http:' && allowLocalHttp)) {
    throw new Error(`${settingName} debe usar HTTPS.`);
  }
  if (url.username || url.password || url.hash) {
    throw new Error(`${settingName} no puede incluir credenciales ni fragmentos.`);
  }

  return url;
}

function getPublicBaseUrl(env = process.env) {
  const configured = String(env.PUBLIC_BASE_URL || '').trim();
  if (!configured && env.NODE_ENV !== 'production') {
    return new URL(`http://localhost:${env.PORT || 3050}`);
  }

  const url = parseHttpsUrl(configured, 'PUBLIC_BASE_URL', {
    allowLocalHttp: env.NODE_ENV !== 'production'
  });
  url.pathname = url.pathname.replace(/\/$/, '');
  url.search = '';
  return url;
}

function getClientPortalUrl(env = process.env) {
  return new URL('/ingresar', getPublicBaseUrl(env)).toString();
}

module.exports = {
  getClientPortalUrl,
  getPublicBaseUrl,
  parseHttpsUrl
};

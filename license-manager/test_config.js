'use strict';

const assert = require('assert');
const { getClientPortalUrl, getPublicBaseUrl } = require('./config');

const production = {
  NODE_ENV: 'production',
  PUBLIC_BASE_URL: 'https://blue-cat-mu.vercel.app'
};

assert.strictEqual(getClientPortalUrl(production), 'https://blue-cat-mu.vercel.app/ingresar');
assert.strictEqual(getPublicBaseUrl(production).origin, 'https://blue-cat-mu.vercel.app');
assert.throws(() => getPublicBaseUrl({ NODE_ENV: 'production' }), /PUBLIC_BASE_URL/);
assert.throws(
  () => getPublicBaseUrl({ NODE_ENV: 'production', PUBLIC_BASE_URL: 'http://attacker.example' }),
  /HTTPS/
);

console.log('Canonical portal URL tests passed.');

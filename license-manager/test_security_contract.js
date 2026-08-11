'use strict';

const fs = require('fs');
const path = require('path');

const serverSource = fs.readFileSync(path.join(__dirname, 'server.js'), 'utf8');
const dbSource = fs.readFileSync(path.join(__dirname, 'db.js'), 'utf8');
const vercelSource = fs.readFileSync(path.join(__dirname, 'vercel.json'), 'utf8');
const nodeSdkSource = fs.readFileSync(path.join(__dirname, 'client_sdk', 'license_client.js'), 'utf8');
const pythonSdkSource = fs.readFileSync(path.join(__dirname, 'client_sdk', 'license_client.py'), 'utf8');

function assertContract(condition, message) {
  if (!condition) {
    console.error(`Security contract failed: ${message}`);
    process.exit(1);
  }
}

assertContract(!serverSource.includes('/api/admin/setup'), 'public admin setup endpoint is present.');
assertContract(
  !serverSource.includes('INSERT OR REPLACE') && !dbSource.includes('INSERT OR REPLACE'),
  'SQLite INSERT OR REPLACE syntax is present.'
);
assertContract(!serverSource.includes('/api/download/bluecat-installer'), 'the public installer endpoint is present.');
assertContract(
  !serverSource.includes("req.headers['x-forwarded-host']") && !serverSource.includes("req.get('host')"),
  'an emailed or packaged URL can still be derived from an HTTP Host header.'
);
assertContract(serverSource.includes("decoded.role !== 'admin'"), 'admin routes do not enforce an explicit admin role.');
assertContract(!serverSource.includes('antigravity_license_secret'), 'a known production JWT fallback remains present.');
assertContract(!dbSource.includes("'admin123'"), 'known default administrator credentials remain present.');
assertContract(
  serverSource.includes("app.post('/api/admin/login', adminLoginLimiter"),
  'the administrator login does not have a dedicated brute-force limit.'
);
assertContract(
  !vercelSource.includes('BlueCat-Server-Setup.exe')
    && !fs.existsSync(path.join(__dirname, 'public', 'BlueCat-Server-Setup.exe')),
  'an installer executable is still bundled in the web application.'
);
assertContract(
  !nodeSdkSource.includes('http://localhost:3050')
    && !pythonSdkSource.includes('http://localhost:3050'),
  'a packaged license client still defaults to the local license server.'
);

console.log('Security contract passed: admin and installer delivery boundaries are enforced.');
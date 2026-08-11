const http = require('http');

function request(method, path, data, headers = {}) {
  return new Promise((resolve, reject) => {
    const payload = data ? JSON.stringify(data) : '';
    const req = http.request({
      hostname: 'localhost',
      port: 3050,
      path,
      method,
      headers: {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(payload),
        ...headers
      }
    }, res => {
      let body = '';
      res.on('data', chunk => body += chunk);
      res.on('end', () => {
        try {
          resolve({ status: res.statusCode, data: JSON.parse(body) });
        } catch (e) {
          resolve({ status: res.statusCode, data: body });
        }
      });
    });
    req.on('error', reject);
    if (payload) req.write(payload);
    req.end();
  });
}

async function runTest() {
  console.log('=== TEST 1: Login de Admin ===');
  const loginRes = await request('POST', '/api/admin/login', { username: 'admin', password: 'admin123' });
  console.log('Login Status:', loginRes.status, loginRes.data.message);
  const token = loginRes.data.token;

  console.log('\n=== TEST 2: Registrar Cliente + Emitir Licencia ===');
  const clientRes = await request('POST', '/api/admin/clients', {
    name: 'Cliente Prueba Juan',
    email: 'juan@empresa.com',
    phone: '+573001234567',
    payment_reference: 'Nequi-Ref-99201'
  }, { 'Authorization': 'Bearer ' + token });
  console.log('Cliente Creado:', clientRes.data);
  const licenseKey = clientRes.data.license.license_key;
  const licenseId = clientRes.data.license.id;

  console.log('\n=== TEST 3: Verificación de Licencia desde el Software Cliente ===');
  const verifyRes = await request('POST', '/api/license/verify', {
    email: 'juan@empresa.com',
    license_key: licenseKey,
    hwid: 'PC-OFICINA-JUAN-001'
  });
  console.log('Verificación Cliente:', verifyRes.data);
  let sessionToken = verifyRes.data.session_token;

  console.log('\n=== TEST 4: Heartbeat con Rotación Dinámica de Token ===');
  const hb1 = await request('POST', '/api/license/heartbeat', {
    session_token: sessionToken,
    license_key: licenseKey,
    hwid: 'PC-OFICINA-JUAN-001'
  });
  console.log('Heartbeat #1 OK - Nuevo Token Rotado:', hb1.data.next_session_token);
  sessionToken = hb1.data.next_session_token;

  console.log('\n=== TEST 5: Suspender Licencia desde Panel Admin ===');
  const toggleRes = await request('POST', `/api/admin/licenses/${licenseId}/toggle`, { status: 'suspended' }, { 'Authorization': 'Bearer ' + token });
  console.log('Estado Licencia:', toggleRes.data);

  console.log('\n=== TEST 6: Heartbeat posterior a la Suspensión (Kill Switch) ===');
  const hb2 = await request('POST', '/api/license/heartbeat', {
    session_token: sessionToken,
    license_key: licenseKey,
    hwid: 'PC-OFICINA-JUAN-001'
  });
  console.log('Respuesta tras suspensión (Bloqueo):', hb2.status, hb2.data);
}

runTest().catch(console.error);

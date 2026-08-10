/**
 * SDK / Script Cliente de Demostración en Node.js
 * Validación Online de Licencia Anti-Keygens y Heartbeat con Rotación de Tokens.
 */

const http = require('http');
const fs = require('fs');
const path = require('path');
const os = require('os');
const readline = require('readline');

const CONFIG_FILE = path.join(__dirname, 'license_config.json');

class NodeLicenseClient {
  constructor() {
    this.serverUrl = 'http://localhost:3050';
    this.email = null;
    this.licenseKey = null;
    this.sessionToken = null;
    this.hwid = os.hostname() + '-' + os.arch() + '-' + os.platform();
    this.isValid = false;
    this.heartbeatTimer = null;
  }

  async init() {
    if (fs.existsSync(CONFIG_FILE)) {
      try {
        const config = JSON.parse(fs.readFileSync(CONFIG_FILE, 'utf8'));
        this.serverUrl = config.server_url || this.serverUrl;
        this.email = config.email;
        this.licenseKey = config.license_key;
      } catch (e) {
        console.error('[!] Error al leer license_config.json');
      }
    }

    if (!this.email || !this.licenseKey) {
      const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
      const question = (q) => new Promise((res) => rl.question(q, res));

      console.log('=================================================');
      console.log('  CONFIGURACIÓN DE LICENCIA DEL CLIENTE');
      console.log('=================================================');
      this.email = await question('Tu Correo Electrónico Registrado: ');
      this.licenseKey = await question('Tu Clave de Licencia (XXXX-XXXX-XXXX-XXXX): ');
      rl.close();
    }

    return this.verifyAndStart();
  }

  async verifyAndStart() {
    console.log(`\n[*] Conectando al servidor ${this.serverUrl}...`);

    try {
      const response = await this.postJSON('/api/license/verify', {
        email: this.email,
        license_key: this.licenseKey,
        hwid: this.hwid
      });

      if (response.status === 'success') {
        this.sessionToken = response.session_token;
        this.isValid = true;
        console.log('=================================================');
        console.log('  [✓] LICENCIA VERIFICADA Y ACTIVA');
        console.log(`  Cliente: ${response.client_name}`);
        console.log(`  ID de Sesión Dinámica: ${this.sessionToken.substring(0, 15)}...`);
        console.log('=================================================');

        this.startHeartbeat();
        return true;
      } else {
        const safeMessage = String(response && response.message ? response.message : 'Error desconocido').replace(/[\r\n]/g, ' ');
        console.error(`\n[X] ERROR DE LICENCIA: ${safeMessage}`);
        process.exit(1);
      }
    } catch (err) {
      const safeErrorMessage = String(err && err.message ? err.message : err).replace(/[\r\n]/g, ' ');
      console.error(`\n[X] ERROR DE CONEXIÓN O LICENCIA INVÁLIDA: ${safeErrorMessage}`);
      process.exit(1);
    }
  }

  startHeartbeat() {
    this.heartbeatTimer = setInterval(async () => {
      if (!this.isValid) return;

      try {
        const response = await this.postJSON('/api/license/heartbeat', {
          session_token: this.sessionToken,
          license_key: this.licenseKey,
          hwid: this.hwid
        });

        if (response.status === 'ok') {
          this.sessionToken = response.next_session_token; // Token Rotado
        } else {
          this.triggerKillSwitch('Licencia revocada o invalidad por el servidor.');
        }
      } catch (err) {
        this.triggerKillSwitch('Pérdida de conexión con el servidor de validación.');
      }
    }, 15000);
  }

  triggerKillSwitch(reason) {
    this.isValid = false;
    if (this.heartbeatTimer) clearInterval(this.heartbeatTimer);
    console.log('\n=================================================');
    console.log('  [X] PROGRAMA BLOQUEADO / LICENCIA REVOCADA');
    console.log(`  Motivo: ${reason}`);
    console.log('=================================================');
    process.exit(1);
  }

  postJSON(endpoint, data) {
    return new Promise((resolve, reject) => {
      const url = new URL(this.serverUrl + endpoint);
      const postData = JSON.stringify(data);

      const req = http.request(
        url,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Content-Length': Buffer.byteLength(postData)
          }
        },
        (res) => {
          let body = '';
          res.on('data', (chunk) => (body += chunk));
          res.on('end', () => {
            try {
              const parsed = JSON.parse(body);
              if (res.statusCode >= 400) {
                return reject(new Error(parsed.message || `HTTP ${res.statusCode}`));
              }
              resolve(parsed);
            } catch (e) {
              reject(new Error('Respuesta del servidor no válida'));
            }
          });
        }
      );

      req.on('error', (e) => reject(e));
      req.write(postData);
      req.end();
    });
  }
}

// Ejecución
if (require.main === module) {
  const client = new NodeLicenseClient();
  client.init().then(() => {
    console.log('\n[+] Programa principal en ejecución...');
    let i = 1;
    setInterval(() => {
      console.log(` -> [Software Activo] Procesando ciclo #${i++}... (Conexión activa)`);
    }, 3000);
  });
}

const express = require('express');
const cors = require('cors');
const rateLimit = require('express-rate-limit');
const path = require('path');
const crypto = require('crypto');
const bcrypt = require('bcryptjs');
const jwt = require('jsonwebtoken');
const archiver = require('archiver');
const fs = require('fs');
const db = require('./db');

const app = express();
const PORT = process.env.PORT || 3050;
const JWT_SECRET = process.env.JWT_SECRET || 'antigravity_license_secret_key_2026_super_secure';

app.use(cors());
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// Standard rate limiter to satisfy CodeQL's rate limiting rules
const apiLimiter = rateLimit({
  windowMs: 15 * 60 * 1000, // 15 minutes
  max: 200, // Limit each IP to 200 requests per windowMs
  standardHeaders: true, // Return rate limit info in the `RateLimit-*` headers
  legacyHeaders: false, // Disable the `X-RateLimit-*` headers
  message: { error: 'Demasiadas solicitudes a la API, por favor intenta más tarde.' }
});
app.use('/api/', apiLimiter);

app.use(express.static(path.join(__dirname, 'public')));

// Helper: Generador de Claves de Licencia Seguras (Formato: XXXX-XXXX-XXXX-XXXX)
function generateLicenseKey() {
  const bytes = crypto.randomBytes(8).toString('hex').toUpperCase();
  return `${bytes.slice(0, 4)}-${bytes.slice(4, 8)}-${bytes.slice(8, 12)}-${bytes.slice(12, 16)}`;
}

// Helper: Generador de Dynamic Session Token
function generateSessionToken(licenseKey, hwid) {
  const nonce = crypto.randomBytes(16).toString('hex');
  const timestamp = Date.now().toString();
  const hash = crypto.createHmac('sha256', JWT_SECRET)
    .update(`${licenseKey}:${hwid}:${timestamp}:${nonce}`)
    .digest('hex');
  return `ST-${hash.slice(0, 32)}`;
}

// Middleware de Autenticación de Administrador
function authAdminMiddleware(req, res, next) {
  const authHeader = req.headers.authorization;
  if (!authHeader || !authHeader.startsWith('Bearer ')) {
    return res.status(401).json({ error: 'No autorizado. Se requiere token de administrador.' });
  }

  const token = authHeader.split(' ')[1];
  try {
    const decoded = jwt.verify(token, JWT_SECRET);
    req.admin = decoded;
    next();
  } catch (err) {
    return res.status(401).json({ error: 'Token inválido o expirado. Inicia sesión nuevamente.' });
  }
}

// ==========================================
// RUTAS DEL PANEL DE ADMINISTRACIÓN
// ==========================================

// Ruta temporal para inicializar/restablecer credenciales de admin vía body (POST)
app.post('/api/admin/setup', (req, res) => {
  const username = req.body.username || 'admin';
  const password = req.body.password || 'admin123';
  const hash = bcrypt.hashSync(password, 10);
  
  db.run(`DELETE FROM admins WHERE username = ?`, [username], (delErr) => {
    db.run(`INSERT INTO admins (username, password_hash) VALUES (?, ?)`, [username, hash], (insErr) => {
      if (insErr) {
        return res.status(500).json({ error: 'Error al inicializar admin: ' + insErr.message });
      }
      res.json({ message: 'Credenciales de administrador inicializadas con éxito.', username });
    });
  });
});

// Login de Admin
app.post('/api/admin/login', (req, res) => {
  const { username, password } = req.body;
  if (!username || !password) {
    return res.status(400).json({ error: 'Usuario y contraseña son requeridos.' });
  }

  db.get(`SELECT * FROM admins WHERE username = ?`, [username], (err, admin) => {
    if (err || !admin) {
      return res.status(401).json({ error: 'Credenciales inválidas.' });
    }

    const isValid = bcrypt.compareSync(password, admin.password_hash);
    if (!isValid) {
      return res.status(401).json({ error: 'Credenciales inválidas.' });
    }

    const token = jwt.sign(
      { id: admin.id, username: admin.username },
      JWT_SECRET,
      { expiresIn: '24h' }
    );

    res.json({
      message: 'Inicio de sesión exitoso',
      token,
      username: admin.username
    });
  });
});

// Cambiar contraseña de Admin
app.post('/api/admin/change-password', authAdminMiddleware, (req, res) => {
  const { currentPassword, newPassword } = req.body;
  if (!currentPassword || !newPassword) {
    return res.status(400).json({ error: 'Todos los campos son requeridos.' });
  }

  db.get(`SELECT * FROM admins WHERE id = ?`, [req.admin.id], (err, admin) => {
    if (err || !admin) return res.status(404).json({ error: 'Admin no encontrado' });

    if (!bcrypt.compareSync(currentPassword, admin.password_hash)) {
      return res.status(400).json({ error: 'La contraseña actual no es correcta.' });
    }

    const newHash = bcrypt.hashSync(newPassword, 10);
    db.run(`UPDATE admins SET password_hash = ? WHERE id = ?`, [newHash, req.admin.id], (updateErr) => {
      if (updateErr) return res.status(500).json({ error: 'Error actualizando contraseña' });
      res.json({ message: 'Contraseña actualizada correctamente.' });
    });
  });
});

// Métricas y Estadísticas del Dashboard
app.get('/api/admin/stats', authAdminMiddleware, (req, res) => {
  db.get(`
    SELECT 
      (SELECT COUNT(*) FROM clients) as total_clients,
      (SELECT COUNT(*) FROM licenses WHERE status = 'active') as active_licenses,
      (SELECT COUNT(*) FROM licenses WHERE status = 'suspended') as suspended_licenses,
      (SELECT COUNT(*) FROM sessions WHERE datetime(last_heartbeat) >= datetime('now', '-2 minutes')) as online_sessions
  `, (err, stats) => {
    if (err) return res.status(500).json({ error: err.message });
    res.json(stats);
  });
});

// Listar todos los Clientes con sus Licencias y Estado de Sesión
app.get('/api/admin/clients', authAdminMiddleware, (req, res) => {
  const sql = `
    SELECT 
      c.id as client_id, c.name, c.email, c.phone, c.payment_reference, c.notes, c.created_at as client_created_at,
      l.id as license_id, l.license_key, l.status as license_status, l.hwid, l.created_at as license_created_at,
      s.session_token, s.ip_address, s.last_heartbeat,
      CASE 
        WHEN s.last_heartbeat IS NOT NULL AND datetime(s.last_heartbeat) >= datetime('now', '-2 minutes') THEN 1
        ELSE 0 
      END as is_online
    FROM clients c
    LEFT JOIN licenses l ON c.id = l.client_id
    LEFT JOIN sessions s ON l.id = s.license_id
    ORDER BY c.id DESC
  `;
  db.all(sql, [], (err, rows) => {
    if (err) return res.status(500).json({ error: err.message });
    res.json(rows);
  });
});

// Registrar Nuevo Cliente y Generar Licencia de 1 Uso
app.post('/api/admin/clients', authAdminMiddleware, (req, res) => {
  const { name, email, phone, payment_reference, notes, custom_license_key } = req.body;

  if (!name || !email) {
    return res.status(400).json({ error: 'El nombre y correo electrónico son obligatorios.' });
  }

  db.get(`SELECT id FROM clients WHERE email = ?`, [email], (err, existing) => {
    if (existing) {
      return res.status(400).json({ error: 'Ya existe un cliente registrado con este correo electrónico.' });
    }

    db.run(
      `INSERT INTO clients (name, email, phone, payment_reference, notes) VALUES (?, ?, ?, ?, ?)`,
      [name, email.toLowerCase().trim(), phone || '', payment_reference || '', notes || ''],
      function (insertClientErr) {
        if (insertClientErr) {
          return res.status(500).json({ error: 'Error al registrar cliente: ' + insertClientErr.message });
        }

        const clientId = this.lastID;
        const licenseKey = custom_license_key && custom_license_key.trim().length > 0 
          ? custom_license_key.trim().toUpperCase() 
          : generateLicenseKey();

        db.run(
          `INSERT INTO licenses (client_id, email, license_key, status) VALUES (?, ?, ?, 'active')`,
          [clientId, email.toLowerCase().trim(), licenseKey],
          function (insertLicenseErr) {
            if (insertLicenseErr) {
              return res.status(500).json({ error: 'Cliente creado pero falló la generación de licencia: ' + insertLicenseErr.message });
            }

            res.json({
              message: 'Cliente y licencia generados exitosamente.',
              client: {
                id: clientId,
                name,
                email: email.toLowerCase().trim(),
                phone,
                payment_reference,
                notes
              },
              license: {
                id: this.lastID,
                license_key: licenseKey,
                status: 'active'
              }
            });
          }
        );
      }
    );
  });
});

// Cambiar estado de Licencia (Activar / Suspender / Revocar)
app.post('/api/admin/licenses/:id/toggle', authAdminMiddleware, (req, res) => {
  const licenseId = req.params.id;
  const { status } = req.body; // 'active', 'suspended', 'revoked'

  if (!['active', 'suspended', 'revoked'].includes(status)) {
    return res.status(400).json({ error: 'Estado no válido. Use active, suspended o revoked.' });
  }

  db.run(`UPDATE licenses SET status = ? WHERE id = ?`, [status, licenseId], function (err) {
    if (err) return res.status(500).json({ error: err.message });
    if (this.changes === 0) return res.status(404).json({ error: 'Licencia no encontrada.' });

    // Si se suspende o revoca, eliminar las sesiones activas asociadas inmediatamente
    if (status !== 'active') {
      db.run(`DELETE FROM sessions WHERE license_id = ?`, [licenseId]);
    }

    res.json({ message: `Licencia actualizada a estado: ${status}`, status });
  });
});

// Resetear bloqueo HWID para que el cliente pueda instalar en otro equipo
app.post('/api/admin/licenses/:id/reset-hwid', authAdminMiddleware, (req, res) => {
  const licenseId = req.params.id;
  db.run(`UPDATE licenses SET hwid = NULL WHERE id = ?`, [licenseId], function (err) {
    if (err) return res.status(500).json({ error: err.message });
    if (this.changes === 0) return res.status(404).json({ error: 'Licencia no encontrada.' });

    res.json({ message: 'Vínculo de HWID reseteado con éxito. El cliente podrá vincular su nuevo equipo en la siguiente sesión.' });
  });
});

// Actualizar Datos de Cliente
app.put('/api/admin/clients/:id', authAdminMiddleware, (req, res) => {
  const clientId = req.params.id;
  const { name, email, phone, payment_reference, notes } = req.body;

  if (!name || !email) {
    return res.status(400).json({ error: 'Nombre y correo electrónico son obligatorios.' });
  }

  db.run(
    `UPDATE clients SET name = ?, email = ?, phone = ?, payment_reference = ?, notes = ? WHERE id = ?`,
    [name.trim(), email.toLowerCase().trim(), phone || '', payment_reference || '', notes || '', clientId],
    function (err) {
      if (err) return res.status(500).json({ error: err.message });
      
      // También actualizar el email vinculado en la tabla de licencias
      db.run(`UPDATE licenses SET email = ? WHERE client_id = ?`, [email.toLowerCase().trim(), clientId], function () {
        res.json({ message: 'Datos del cliente y licencia actualizados correctamente.' });
      });
    }
  );
});

// Eliminar Cliente y su Licencia
app.delete('/api/admin/clients/:id', authAdminMiddleware, (req, res) => {
  const clientId = req.params.id;
  db.run(`DELETE FROM clients WHERE id = ?`, [clientId], function (err) {
    if (err) return res.status(500).json({ error: err.message });
    res.json({ message: 'Cliente y sus licencias eliminadas correctamente.' });
  });
});

// Generar y Descargar Paquete de Entrega (.zip) para el Cliente
app.get('/api/admin/licenses/:id/package', authAdminMiddleware, (req, res) => {
  const licenseId = req.params.id;

  const sql = `
    SELECT l.license_key, l.email, c.name 
    FROM licenses l 
    JOIN clients c ON l.client_id = c.id 
    WHERE l.id = ?
  `;

  db.get(sql, [licenseId], (err, data) => {
    if (err || !data) {
      return res.status(404).json({ error: 'Licencia o cliente no encontrado.' });
    }

    const hostUrl = `${req.protocol}://${req.get('host')}`;
    const clientConfig = {
      server_url: hostUrl,
      email: data.email,
      license_key: data.license_key,
      customer_name: data.name,
      generated_at: new Date().toISOString()
    };

    res.attachment(`Paquete_Licencia_${data.name.replace(/\s+/g, '_')}.zip`);

    const archive = archiver('zip', { zlib: { level: 9 } });
    archive.pipe(res);

    // 1. Archivo de configuración JSON de la licencia
    archive.append(JSON.stringify(clientConfig, null, 2), { name: 'license_config.json' });

    // 2. Adjuntar los archivos SDK de demostración
    const pythonClientPath = path.join(__dirname, 'client_sdk', 'license_client.py');
    const nodeClientPath = path.join(__dirname, 'client_sdk', 'license_client.js');
    const readmePath = path.join(__dirname, 'client_sdk', 'README_CLIENTE.md');

    if (fs.existsSync(pythonClientPath)) {
      archive.file(pythonClientPath, { name: 'ejecutar_cliente.py' });
    }
    if (fs.existsSync(nodeClientPath)) {
      archive.file(nodeClientPath, { name: 'ejecutar_cliente.js' });
    }
    if (fs.existsSync(readmePath)) {
      archive.file(readmePath, { name: 'INSTRUCCIONES_INSTALACION.md' });
    }

    archive.finalize();
  });
});

// Endpoint Público/Admin: Descargar Instalador Oficial Ejecutable BlueCat-Server-Setup.exe
app.get('/api/download/bluecat-installer', (req, res) => {
  const installerPath = path.join(__dirname, '..', 'Blue-Cat', 'packaging', 'windows', 'output', 'BlueCat-Server-Setup.exe');
  if (fs.existsSync(installerPath)) {
    res.download(installerPath, 'BlueCat-Server-Setup.exe');
  } else {
    res.status(404).json({ error: 'El instalador ejecutable BlueCat-Server-Setup.exe no fue encontrado en el servidor.' });
  }
});

// Helper: Generar buffer ZIP en memoria
function createZipBuffer(data, hostUrl) {
  return new Promise((resolve, reject) => {
    const archive = archiver('zip', { zlib: { level: 9 } });
    const buffers = [];

    archive.on('data', chunk => buffers.push(chunk));
    archive.on('end', () => resolve(Buffer.concat(buffers)));
    archive.on('error', err => reject(err));

    const clientConfig = {
      server_url: hostUrl,
      email: data.email,
      license_key: data.license_key,
      customer_name: data.name,
      generated_at: new Date().toISOString()
    };

    const clientConfigJson = JSON.stringify(clientConfig, null, 2);

    // Archivo de licencia en la raíz y en carpeta config
    archive.append(clientConfigJson, { name: 'license_config.json' });
    archive.append(clientConfigJson, { name: 'config/license_config.json' });

    // Script Automático de Instalación de Licencia en Blue-Cat (Windows Batch)
    const installBatContent = `@echo off
title Instalador de Licencia Comercial Blue-Cat ERP
color 0A
echo =========================================================
echo   INSTALADOR DE LICENCIA COMERCIAL BLUE-CAT ERP (1 USO)
echo =========================================================
echo.
echo Cliente: ${data.name}
echo Licencia: ${data.license_key}
echo.
echo Instalando archivo de licencia en Blue-Cat ERP...

if exist "C:\\laragon\\www\\Blue-Cat\\config" (
    copy /Y "license_config.json" "C:\\laragon\\www\\Blue-Cat\\config\\license_config.json" >nul
    echo [✓] Licencia instalada correctamente en C:\\laragon\\www\\Blue-Cat\\config\\
) else (
    echo [!] No se encontro la ruta por defecto de Blue-Cat.
    echo Copie manualmente el archivo license_config.json dentro de la carpeta config\\ de Blue-Cat.
)

echo.
echo Iniciando Blue-Cat ERP en el navegador...
timeout /t 2 >nul
start http://localhost/Blue-Cat/
echo.
echo [✓] Proceso completado exitosamente.
pause
`;
    archive.append(installBatContent, { name: 'Instalar_Licencia_en_BlueCat_bat.txt' });

    // Acceso directo para abrir Blue-Cat
    const openBatContent = `@echo off
title Abrir Blue-Cat ERP
start http://localhost/Blue-Cat/
exit
`;
    archive.append(openBatContent, { name: 'Abrir_BlueCat_bat.txt' });

    const pythonClientPath = path.join(__dirname, 'client_sdk', 'license_client.py');
    const nodeClientPath = path.join(__dirname, 'client_sdk', 'license_client.js');
    const readmePath = path.join(__dirname, 'client_sdk', 'README_CLIENTE.md');

    if (fs.existsSync(pythonClientPath)) archive.file(pythonClientPath, { name: 'ejecutar_cliente_py.txt' });
    if (fs.existsSync(nodeClientPath)) archive.file(nodeClientPath, { name: 'ejecutar_cliente_js.txt' });
    if (fs.existsSync(readmePath)) archive.file(readmePath, { name: 'INSTRUCCIONES_INSTALACION.md' });

    archive.finalize();
  });
}

function escapeHtml(value) {
  if (!value) return '';
  return String(value).replace(/[&<>"']/g, (char) => {
    return {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    }[char] || char;
  });
}

// Helper: Enviar Correo con el Paquete ZIP Adjunto
async function sendLicenseEmail(data, targetEmail, hostUrl, smtpConfig = {}) {
  const nodemailer = require('nodemailer');
  const zipBuffer = await createZipBuffer(data, hostUrl);

  let smtpHost = smtpConfig.host || process.env.SMTP_HOST || '';
  const smtpPort = parseInt(smtpConfig.port || process.env.SMTP_PORT || '587');
  const smtpUser = (smtpConfig.user || process.env.SMTP_USER || '').trim();
  const smtpPass = (smtpConfig.pass || process.env.SMTP_PASS || '').trim();
  const smtpFrom = (smtpConfig.from || process.env.SMTP_FROM || '').trim() || `"Ventas LicenceGuard" <${smtpUser || 'noreply@licenceguard.com'}>`;

  // Auto-detectar servidor SMTP para Gmail y Outlook si el campo Host quedó vacío
  if (!smtpHost && smtpUser) {
    if (smtpUser.includes('@gmail.com')) {
      smtpHost = 'smtp.gmail.com';
    } else if (smtpUser.includes('@outlook.com') || smtpUser.includes('@hotmail.com')) {
      smtpHost = 'smtp.office365.com';
    }
  }

  let transporter;
  let testPreviewUrl = null;

  if (smtpHost && smtpUser && smtpPass) {
    transporter = nodemailer.createTransport({
      host: smtpHost,
      port: smtpPort,
      secure: smtpPort === 465,
      auth: { user: smtpUser, pass: smtpPass }
    });
  } else {
    // Si aún no se han guardado credenciales SMTP reales, creamos una cuenta de prueba Ethereal
    const testAccount = await nodemailer.createTestAccount();
    transporter = nodemailer.createTransport({
      host: 'smtp.ethereal.email',
      port: 587,
      secure: false,
      auth: {
        user: testAccount.user,
        pass: testAccount.pass
      }
    });
  }

  const mailOptions = {
    from: smtpFrom,
    to: targetEmail,
    subject: `🔐 Entrega de Tu Licencia Comercial y Software Blue-Cat ERP - ${escapeHtml(data.name)}`,
    html: `
      <div style="font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #0b0f19; color: #f3f4f6; padding: 32px; border-radius: 12px; max-width: 600px; margin: 0 auto; border: 1px solid rgba(255,255,255,0.1);">
        <div style="text-align: center; margin-bottom: 24px;">
          <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #3b82f6, #8b5cf6); border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 24px; color: #fff;">
            🛡️
          </div>
          <h2 style="color: #ffffff; margin-top: 12px; font-size: 22px;">¡Entrega de Licencia Comercial & Software Blue-Cat!</h2>
          <p style="color: #9ca3af; font-size: 14px;">Hola <strong>${escapeHtml(data.name)}</strong>, gracias por adquirir Blue-Cat ERP.</p>
        </div>

        <div style="background: rgba(255,255,255,0.04); padding: 20px; border-left: 4px solid #3b82f6; border-radius: 6px; margin: 24px 0;">
          <p style="margin: 0; font-size: 12px; color: #9ca3af; text-transform: uppercase; letter-spacing: 1px;">TU CLAVE DE LICENCIA DE 1 USO:</p>
          <p style="font-family: monospace; font-size: 22px; font-weight: bold; color: #f59e0b; margin: 8px 0 0 0;">${escapeHtml(data.license_key)}</p>
          <p style="margin: 8px 0 0 0; font-size: 13px; color: #9ca3af;">Correo Registrado: <strong>${escapeHtml(data.email)}</strong></p>
        </div>

        <div style="text-align: center; margin: 26px 0;">
          <a href="${escapeHtml(hostUrl)}/api/download/bluecat-installer" 
             style="display: inline-block; padding: 14px 28px; background: linear-gradient(135deg, #10b981, #059669); color: #ffffff; text-decoration: none; font-weight: bold; border-radius: 8px; font-size: 15px; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);">
            ⬇️ Descargar Instalador Completo (BlueCat-Server-Setup.exe)
          </a>
        </div>

        <h3 style="color: #ffffff; font-size: 16px;"> Archivo Adjunto de Configuración:</h3>
        <p style="font-size: 14px; color: #d1d5db;">Adjuntamos tu paquete con la clave de licencia lista: <code>Paquete_Licencia_${escapeHtml(data.name.replace(/\s+/g, '_'))}.zip</code></p>

        <h3 style="color: #ffffff; font-size: 16px;"> Pasos para la Instalación y Activación:</h3>
        <ol style="font-size: 14px; color: #d1d5db; line-height: 1.6;">
          <li>Haz clic en el botón superior para descargar el instalador ejecutable <code>BlueCat-Server-Setup.exe</code>.</li>
          <li>Ejecuta el instalador e instala Blue-Cat ERP en tu computador.</li>
          <li>Descomprime el archivo ZIP adjunto y ejecuta <code>Instalar_Licencia_en_BlueCat</code> o ingresa tu correo y clave cuando la aplicación abra.</li>
          <li>El sistema se activará automáticamente mediante la verificación en línea con el servidor.</li>
        </ol>

        <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.1); margin: 30px 0;">
        <p style="color: #6b7280; font-size: 12px; text-align: center; margin: 0;">Sistema de Gestión de Licencias &copy; 2026 LicenceGuard</p>
      </div>
    `,
    attachments: [
      {
        filename: `Paquete_Licencia_${escapeHtml(data.name.replace(/\s+/g, '_'))}.zip`,
        content: zipBuffer,
        contentType: 'application/zip'
      }
    ]
  };

  const info = await transporter.sendMail(mailOptions);
  if (!smtpHost) {
    testPreviewUrl = nodemailer.getTestMessageUrl(info);
  }
  return { info, testPreviewUrl };
}

// Endpoint: Enviar Paquete ZIP por Correo al Cliente
app.post('/api/admin/licenses/:id/send-email', authAdminMiddleware, async (req, res) => {
  const licenseId = req.params.id;
  const { target_email } = req.body;

  const sql = `
    SELECT l.license_key, l.email, c.name 
    FROM licenses l 
    JOIN clients c ON l.client_id = c.id 
    WHERE l.id = ?
  `;

  db.get(sql, [licenseId], async (err, data) => {
    if (err || !data) {
      return res.status(404).json({ error: 'Licencia no encontrada.' });
    }

    const destination = target_email && target_email.trim() ? target_email.trim() : data.email;
    const configuredPublicUrl = (process.env.PUBLIC_BASE_URL || '').trim();
    const hostUrl = configuredPublicUrl || `http://localhost:${PORT}`;

    // Consultar si hay configuración SMTP guardada en BD
    db.all(`SELECT key, value FROM settings WHERE key LIKE 'smtp_%'`, async (settingsErr, rows) => {
      const smtpConfig = {};
      if (rows) {
        rows.forEach(r => smtpConfig[r.key.replace('smtp_', '')] = r.value);
      }

      try {
        const sendResult = await sendLicenseEmail(data, destination, hostUrl, smtpConfig);
        const isTestMode = !smtpConfig.user || !smtpConfig.pass;
        res.json({
          message: isTestMode 
            ? `Paquete generado en MODO PRUEBA (Ethereal). Para entregarlo a bandejas reales de Gmail como ${destination}, configura tu Servidor SMTP.`
            : `Paquete de licencia enviado con éxito por correo real a ${destination}`,
          destination,
          is_test_mode: isTestMode,
          preview_url: sendResult.testPreviewUrl || null
        });
      } catch (sendErr) {
        console.error("Error enviando correo:", sendErr);
        res.status(500).json({ error: `Fallo al enviar correo a ${destination}: ` + sendErr.message });
      }
    });
  });
});

// Endpoint: Guardar / Obtener Configuración SMTP
app.get('/api/admin/smtp-settings', authAdminMiddleware, (req, res) => {
  db.all(`SELECT key, value FROM settings WHERE key LIKE 'smtp_%'`, (err, rows) => {
    if (err) return res.status(500).json({ error: err.message });
    const config = {};
    if (rows) rows.forEach(r => config[r.key.replace('smtp_', '')] = r.value);
    res.json(config);
  });
});

app.post('/api/admin/smtp-settings', authAdminMiddleware, (req, res) => {
  const { host, port, user, pass, from } = req.body;
  const stmt = db.prepare(`INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)`);

  stmt.run('smtp_host', host || '');
  stmt.run('smtp_port', port || '587');
  stmt.run('smtp_user', user || '');
  stmt.run('smtp_pass', pass || '');
  stmt.run('smtp_from', from || '');
  stmt.finalize(err => {
    if (err) return res.status(500).json({ error: err.message });
    res.json({ message: 'Configuración SMTP guardada correctamente.' });
  });
});

// ==========================================
// RUTAS DE VALIDACIÓN ONLINE DEL SOFTWARE CLIENTE
// (Anti-Keygens & Rotación Dinámica de Tokens)
// ==========================================

// Handshake Inicial del Cliente
app.post('/api/license/verify', (req, res) => {
  const { email, license_key, hwid } = req.body;
  const ipAddress = req.headers['x-forwarded-for'] || req.socket.remoteAddress || '127.0.0.1';

  if (!email || !license_key) {
    return res.status(400).json({
      status: 'error',
      code: 'MISSING_FIELDS',
      message: 'Se requiere correo electrónico y clave de licencia.'
    });
  }

  const sql = `
    SELECT l.*, c.name as client_name 
    FROM licenses l
    JOIN clients c ON l.client_id = c.id
    WHERE LOWER(l.email) = LOWER(?) AND UPPER(l.license_key) = UPPER(?)
  `;

  db.get(sql, [email.trim(), license_key.trim()], (err, license) => {
    if (err) return res.status(500).json({ status: 'error', message: err.message });

    if (!license) {
      return res.status(401).json({
        status: 'error',
        code: 'INVALID_CREDENTIALS',
        message: 'Licencia o correo electrónico no válido.'
      });
    }

    if (license.status !== 'active') {
      return res.status(403).json({
        status: 'error',
        code: 'LICENSE_SUSPENDED',
        message: 'Esta licencia se encuentra suspendida o revocada. Contacta al soporte.'
      });
    }

    // Comprobar HWID (Hardware Lock)
    const clientHwid = hwid || 'UNBOUND_DEVICE';
    if (license.hwid && license.hwid !== clientHwid) {
      return res.status(403).json({
        status: 'error',
        code: 'HWID_MISMATCH',
        message: 'Esta licencia está vinculada a otro equipo informático. Solicita un reset de HWID al administrador.'
      });
    }

    // Si la licencia aún no tiene HWID asignado, la vinculamos al equipo actual (1 uso / 1 equipo)
    if (!license.hwid && hwid) {
      db.run(`UPDATE licenses SET hwid = ? WHERE id = ?`, [hwid, license.id]);
    }

    // Eliminar cualquier sesión previa para asegurar 1 sola sesión activa simultánea
    db.run(`DELETE FROM sessions WHERE license_id = ?`, [license.id], () => {
      // Generar token dinámico de sesión único para este inicio de sesión
      const sessionToken = generateSessionToken(license.license_key, clientHwid);

      db.run(
        `INSERT INTO sessions (license_id, session_token, ip_address, hwid, last_heartbeat) VALUES (?, ?, ?, ?, datetime('now'))`,
        [license.id, sessionToken, ipAddress, clientHwid],
        function (sessionErr) {
          if (sessionErr) {
            return res.status(500).json({ status: 'error', message: 'Error iniciando sesión de validación.' });
          }

          // Actualizar last_token en la licencia
          db.run(`UPDATE licenses SET last_token = ? WHERE id = ?`, [sessionToken, license.id]);

          res.json({
            status: 'success',
            message: 'Licencia verificada exitosamente.',
            session_token: sessionToken,
            client_name: license.client_name,
            heartbeat_interval_seconds: 15,
            server_timestamp: Date.now()
          });
        }
      );
    });
  });
});

// Heartbeat Periódico (Ping constante que rota la clave dinámica)
app.post('/api/license/heartbeat', (req, res) => {
  const { session_token, license_key, hwid } = req.body;

  if (!session_token || !license_key) {
    return res.status(400).json({
      status: 'error',
      code: 'INVALID_REQUEST',
      message: 'Token de sesión y clave requeridos.'
    });
  }

  // Buscar sesión activa y validar estado de la licencia asociada
  const sql = `
    SELECT s.*, l.status as license_status, l.license_key, l.hwid as bound_hwid
    FROM sessions s
    JOIN licenses l ON s.license_id = l.id
    WHERE s.session_token = ?
  `;

  db.get(sql, [session_token], (err, session) => {
    if (err) return res.status(500).json({ status: 'error', message: err.message });

    if (!session) {
      return res.status(401).json({
        status: 'error',
        code: 'SESSION_EXPIRED',
        message: 'Sesión expirada o invalidad por el servidor. Cierre inmediato.'
      });
    }

    if (session.license_status !== 'active') {
      // Eliminar la sesión
      db.run(`DELETE FROM sessions WHERE id = ?`, [session.id]);
      return res.status(403).json({
        status: 'error',
        code: 'LICENSE_SUSPENDED',
        message: 'La licencia fue suspendida remotamente por el administrador.'
      });
    }

    // Generar el SIGUIENTE token dinámico de la cadena (Token Rotation)
    const nextToken = generateSessionToken(session.license_key, hwid || 'UNBOUND_DEVICE');

    // Actualizar la sesión con la nueva fecha de heartbeat y el nuevo token rotado
    db.run(
      `UPDATE sessions SET session_token = ?, last_heartbeat = datetime('now') WHERE id = ?`,
      [nextToken, session.id],
      function (updateErr) {
        if (updateErr) {
          return res.status(500).json({ status: 'error', message: 'Error al actualizar heartbeat.' });
        }

        res.json({
          status: 'ok',
          next_session_token: nextToken,
          server_time: new Date().toISOString()
        });
      }
    );
  });
});

// Iniciar Servidor Express (Solo si se ejecuta directamente)
if (require.main === module) {
  app.listen(PORT, () => {
    console.log(`=================================================`);
    console.log(` Servidor de Licencias Anti-Keygens Activo`);
    console.log(` Puerto: ${PORT}`);
    console.log(` Panel de Administración: http://localhost:${PORT}`);
    console.log(` API Endpoint Validación: http://localhost:${PORT}/api/license/verify`);
    console.log(`=================================================`);
  });
}

module.exports = app;

const { Pool } = require('pg');
const bcrypt = require('bcryptjs');
require('dotenv').config();

// Obtener la URL de conexión de la variable de entorno
const connectionString = process.env.DATABASE_URL;

// Configurar el Pool de conexión de PostgreSQL
// Habilitar SSL para conexiones remotas a Supabase
const pool = connectionString ? new Pool({
  connectionString: connectionString,
  ssl: connectionString.includes('localhost') || connectionString.includes('127.0.0.1')
    ? false
    : { rejectUnauthorized: false }
}) : null;

// Función de traducción de consultas SQLite a PostgreSQL
function translateSql(sql) {
  if (!sql) return sql;
  
  let translated = sql;
  
  // Reemplazar marcadores '?' por '$1, $2, $3...'
  let paramIndex = 1;
  translated = translated.replace(/\?/g, () => `$${paramIndex++}`);
  
  // Reemplazar funciones y expresiones de fecha específicas de SQLite
  translated = translated.replace(/datetime\('now'\)/gi, 'CURRENT_TIMESTAMP');
  translated = translated.replace(/datetime\(last_heartbeat\)/gi, 'last_heartbeat');
  translated = translated.replace(/datetime\(s\.last_heartbeat\)/gi, 's.last_heartbeat');
  translated = translated.replace(/datetime\('now',\s*'-2 minutes'\)/gi, "NOW() - INTERVAL '2 minutes'");
  
  // Agregar prefijo de esquema 'licensing.' a todas las tablas conocidas
  translated = translated.replace(/(?<!licensing\.)\b(admins|clients|licenses|sessions|settings|download_tokens)\b/g, 'licensing.$1');

  return translated;
}

// Objeto de base de datos con interfaz compatible con SQLite3
const db = {
  pool,
  
  get(sql, params, callback) {
    if (typeof params === 'function') {
      callback = params;
      params = [];
    }
    const pgSql = translateSql(sql);
    pool.query(pgSql, params)
      .then(res => {
        callback(null, res.rows[0]);
      })
      .catch(err => {
        console.error("Error en db.get:", err, "SQL original:", sql, "SQL traducido:", pgSql);
        callback(err);
      });
  },
  
  all(sql, params, callback) {
    if (typeof params === 'function') {
      callback = params;
      params = [];
    }
    const pgSql = translateSql(sql);
    pool.query(pgSql, params)
      .then(res => {
        callback(null, res.rows);
      })
      .catch(err => {
        console.error("Error en db.all:", err, "SQL original:", sql, "SQL traducido:", pgSql);
        callback(err);
      });
  },
  
  run(sql, params, callback) {
    if (typeof params === 'function') {
      callback = params;
      params = [];
    }
    
    let pgSql = translateSql(sql);
    
    // Capturar si es una inserción para retornar el ID recién creado
    const isInsert = pgSql.trim().toUpperCase().startsWith('INSERT');
    if (isInsert && !pgSql.toUpperCase().includes('RETURNING')) {
      pgSql += ' RETURNING id';
    }
    
    pool.query(pgSql, params)
      .then(res => {
        const context = {
          lastID: isInsert && res.rows[0] ? res.rows[0].id : null,
          changes: res.rowCount
        };
        if (callback) {
          callback.call(context, null);
        }
      })
      .catch(err => {
        console.error("Error en db.run:", err, "SQL original:", sql, "SQL traducido:", pgSql);
        if (callback) {
          callback(err);
        }
      });
  },
  
  prepare(sql) {
    const pgSql = translateSql(sql);
    const runs = [];
    
    return {
      run(...args) {
        runs.push(args);
        return this;
      },
      finalize(callback) {
        const promises = runs.map(runArgs => {
          return pool.query(pgSql, runArgs);
        });
        
        Promise.all(promises)
          .then(() => {
            if (callback) callback(null);
          })
          .catch(err => {
            console.error("Error en stmt.finalize:", err, "SQL original:", sql, "SQL traducido:", pgSql);
            if (callback) callback(err);
          });
      }
    };
  },
  
  serialize(callback) {
    callback();
  }
};

// Función autoejecutable para inicializar los esquemas y tablas de licensing
async function initDb() {
  if (!pool) {
    throw new Error("La variable de entorno DATABASE_URL no está configurada.");
  }
  let client;
  try {
    client = await pool.connect();
    console.log("Inicializando base de datos de validación de licencias...");
    
    // Asegurar esquema y búsqueda del mismo
    await client.query("CREATE SCHEMA IF NOT EXISTS licensing");
    await client.query("SET search_path TO licensing");
    
    // Crear tablas
    await client.query(`
      CREATE TABLE IF NOT EXISTS admins (
        id SERIAL PRIMARY KEY,
        username VARCHAR(100) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
      )
    `);

    await client.query(`
      CREATE TABLE IF NOT EXISTS clients (
        id SERIAL PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(190) UNIQUE NOT NULL,
        phone VARCHAR(50),
        payment_reference VARCHAR(150),
        notes TEXT,
        created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
      )
    `);

    await client.query(`
      CREATE TABLE IF NOT EXISTS licenses (
        id SERIAL PRIMARY KEY,
        client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
        email VARCHAR(190) NOT NULL,
        license_key VARCHAR(100) UNIQUE NOT NULL,
        status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'suspended', 'revoked')),
        hwid VARCHAR(255) DEFAULT NULL,
        allow_hwid_change INTEGER DEFAULT 0,
        max_sessions INTEGER DEFAULT 1,
        expires_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
        last_token VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
      )
    `);

    await client.query(`
      CREATE TABLE IF NOT EXISTS sessions (
        id SERIAL PRIMARY KEY,
        license_id INTEGER NOT NULL REFERENCES licenses(id) ON DELETE CASCADE,
        session_token VARCHAR(255) UNIQUE NOT NULL,
        ip_address VARCHAR(100),
        hwid VARCHAR(255),
        last_heartbeat TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
      )
    `);

    await client.query(`
      CREATE TABLE IF NOT EXISTS settings (
        key VARCHAR(100) PRIMARY KEY,
        value TEXT
      )
    `);

    await client.query(`
      CREATE TABLE IF NOT EXISTS download_tokens (
        id SERIAL PRIMARY KEY,
        token_hash VARCHAR(64) UNIQUE NOT NULL,
        client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
        license_id INTEGER NOT NULL REFERENCES licenses(id) ON DELETE CASCADE,
        portal_user_id UUID,
        expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
        used_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
        ip_hash CHAR(64),
        user_agent_hash CHAR(64),
        created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await client.query(`
      ALTER TABLE download_tokens
      ADD COLUMN IF NOT EXISTS portal_user_id UUID,
      ADD COLUMN IF NOT EXISTS ip_hash CHAR(64),
      ADD COLUMN IF NOT EXISTS user_agent_hash CHAR(64)
    `);
    await client.query(`
      CREATE INDEX IF NOT EXISTS idx_download_tokens_lookup
      ON download_tokens (token_hash, expires_at)
    `);
    await client.query(`
      CREATE INDEX IF NOT EXISTS idx_download_tokens_license_created
      ON download_tokens (license_id, created_at DESC)
    `);

    // El bootstrap es opcional, pero nunca utiliza credenciales conocidas por defecto.
    const bootstrapUsername = (process.env.ADMIN_USERNAME || '').trim();
    const bootstrapPassword = process.env.ADMIN_PASSWORD || '';
    if (Boolean(bootstrapUsername) !== Boolean(bootstrapPassword) || (bootstrapPassword && bootstrapPassword.length < 12)) {
      throw new Error('ADMIN_USERNAME y ADMIN_PASSWORD deben configurarse juntos con una contraseña de al menos 12 caracteres.');
    }
    if (bootstrapUsername && bootstrapPassword) {
      const res = await client.query("SELECT id FROM admins WHERE username = $1 LIMIT 1", [bootstrapUsername]);
      if (res.rowCount === 0) {
        const hash = bcrypt.hashSync(bootstrapPassword, 10);
        await client.query("INSERT INTO admins (username, password_hash) VALUES ($1, $2)", [bootstrapUsername, hash]);
        console.log("Usuario administrador inicial creado desde variables de entorno.");
      }
    }

    // Asegurar que todas las conexiones del pool utilicen el esquema licensing por defecto
    await pool.query("SET search_path TO licensing");
    
    console.log("Base de datos de validación inicializada correctamente.");
  } catch (err) {
    console.error("Error al inicializar la base de datos:", err);
    throw err;
  } finally {
    if (client) client.release();
  }
}

// Exponer la inicialización para que la API pueda responder 503 mientras la BD no esté lista.
db.ready = initDb();
db.ready.catch(() => {});

module.exports = db;

const { Pool } = require('pg');
const bcrypt = require('bcryptjs');
require('dotenv').config();

// Obtener la URL de conexión de la variable de entorno
const connectionString = process.env.DATABASE_URL;

if (!connectionString) {
  console.error("ERROR: La variable de entorno DATABASE_URL no está configurada.");
  process.exit(1);
}

// Configurar el Pool de conexión de PostgreSQL
// Habilitar SSL para conexiones remotas a Supabase
const pool = new Pool({
  connectionString: connectionString,
  ssl: connectionString.includes('localhost') || connectionString.includes('127.0.0.1')
    ? false
    : { rejectUnauthorized: false }
});

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
  
  // Reemplazar INSERT OR REPLACE
  if (translated.toUpperCase().includes('INSERT OR REPLACE INTO settings')) {
    translated = 'INSERT INTO settings (key, value) VALUES ($1, $2) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value';
  }
  
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
  const client = await pool.connect();
  try {
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

    // Crear usuario admin inicial si no existe
    const res = await client.query("SELECT * FROM admins WHERE username = 'admin' LIMIT 1");
    if (res.rowCount === 0) {
      const defaultPassword = 'admin123';
      const hash = bcrypt.hashSync(defaultPassword, 10);
      await client.query("INSERT INTO admins (username, password_hash) VALUES ($1, $2)", ['admin', hash]);
      console.log("=========================================");
      console.log("Usuario Administrador Inicial Creado:");
      console.log("Usuario: admin");
      console.log("Contraseña: admin123");
      console.log("=========================================");
    }
    
    // Asegurar que todas las conexiones del pool utilicen el esquema licensing por defecto
    await pool.query("SET search_path TO licensing");
    
    console.log("Base de datos de validación inicializada correctamente.");
  } catch (err) {
    console.error("Error al inicializar la base de datos:", err);
  } finally {
    client.release();
  }
}

// Lanzar inicialización asíncrona
initDb();

module.exports = db;

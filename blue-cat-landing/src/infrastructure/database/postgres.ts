import { Pool } from "pg";

const globalDatabase = globalThis as typeof globalThis & { blueCatPool?: Pool };

function createPool(): Pool {
  const databaseUrl = process.env.DATABASE_URL;
  if (!databaseUrl) throw new Error("DATABASE_NOT_CONFIGURED");
  
  return new Pool({
    connectionString: databaseUrl,
    max: 8,
    idleTimeoutMillis: 30000,
    connectionTimeoutMillis: 2000,
    ssl: databaseUrl.includes('localhost') || databaseUrl.includes('127.0.0.1')
      ? false
      : { rejectUnauthorized: false }
  });
}

export function getPool(): Pool {
  if (!globalDatabase.blueCatPool) {
    const pool = createPool();
    
    // Configurar el esquema landing automáticamente para todas las conexiones del pool
    pool.on('connect', (client) => {
      client.query('SET search_path TO landing').catch(err => {
        console.error("Error al establecer search_path en la conexión:", err);
      });
    });
    
    globalDatabase.blueCatPool = pool;
  }
  return globalDatabase.blueCatPool;
}

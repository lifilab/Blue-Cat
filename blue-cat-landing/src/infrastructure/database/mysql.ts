import mysql, { type Pool } from "mysql2/promise";

const globalDatabase = globalThis as typeof globalThis & { blueCatPool?: Pool };

function createPool(): Pool {
  const databaseUrl = process.env.DATABASE_URL;
  if (!databaseUrl) throw new Error("DATABASE_NOT_CONFIGURED");
  return mysql.createPool({ uri: databaseUrl, connectionLimit: 8, enableKeepAlive: true, timezone: "Z" });
}

export function getPool(): Pool {
  if (!globalDatabase.blueCatPool) globalDatabase.blueCatPool = createPool();
  return globalDatabase.blueCatPool;
}

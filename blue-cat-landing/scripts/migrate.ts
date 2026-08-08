import { createHash } from "node:crypto";
import { readdir, readFile } from "node:fs/promises";
import path from "node:path";
import mysql from "mysql2/promise";

async function main() {
  const databaseUrl = process.env.DATABASE_URL;
  if (!databaseUrl) throw new Error("DATABASE_URL_REQUIRED");
  const url = new URL(databaseUrl);
  const database = url.pathname.replace(/^\//, "");
  if (!/^[a-zA-Z0-9_]+$/.test(database)) throw new Error("INVALID_DATABASE_NAME");
  const connection = await mysql.createConnection({
    host: url.hostname,
    port: Number(url.port || 3306),
    user: decodeURIComponent(url.username),
    password: decodeURIComponent(url.password),
    multipleStatements: true,
  });
  try {
    await connection.query(`CREATE DATABASE IF NOT EXISTS \`${database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
    await connection.query(`USE \`${database}\``);
    await connection.query(`
      CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_name VARCHAR(190) PRIMARY KEY,
        sha256 CHAR(64) NOT NULL,
        applied_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
      ) ENGINE=InnoDB
    `);
    const migrationsDirectory = path.resolve(process.cwd(), "database", "migrations");
    const migrationNames = (await readdir(migrationsDirectory)).filter((name) => /^\d+_.+\.sql$/.test(name)).sort();
    for (const migrationName of migrationNames) {
      const sql = await readFile(path.join(migrationsDirectory, migrationName), "utf8");
      const sha256 = createHash("sha256").update(sql).digest("hex");
      const [rows] = await connection.query<Array<{ sha256: string } & mysql.RowDataPacket>>(
        "SELECT sha256 FROM schema_migrations WHERE migration_name=? LIMIT 1",
        [migrationName],
      );
      if (rows[0]) {
        if (rows[0].sha256 !== sha256) throw new Error(`MIGRATION_CHANGED_${migrationName}`);
        console.info(`skip ${migrationName}`);
        continue;
      }
      await connection.query(sql);
      await connection.execute("INSERT INTO schema_migrations (migration_name,sha256) VALUES (?,?)", [migrationName, sha256]);
      console.info(`apply ${migrationName}`);
    }
  } finally {
    await connection.end();
  }
}

main().catch((error) => {
  console.error(error instanceof Error ? error.message : "MIGRATION_FAILED");
  process.exitCode = 1;
});

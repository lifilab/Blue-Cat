const fs = require('fs');
const path = require('path');

const serverPath = path.join(__dirname, 'server.js');
const dbPath = path.join(__dirname, 'db.js');
const serverSource = fs.readFileSync(serverPath, 'utf8');
const dbSource = fs.readFileSync(dbPath, 'utf8');

if (serverSource.includes('/api/admin/setup')) {
  console.error('Security contract failed: public admin setup endpoint is present.');
  process.exit(1);
}

if (serverSource.includes('INSERT OR REPLACE') || dbSource.includes('INSERT OR REPLACE')) {
  console.error('Database contract failed: SQLite INSERT OR REPLACE syntax is present.');
  process.exit(1);
}

console.log('Security contract passed: no public admin setup endpoint or SQLite upsert syntax.');

const fs = require('fs');
const path = require('path');

const serverPath = path.join(__dirname, 'server.js');
const serverSource = fs.readFileSync(serverPath, 'utf8');

if (serverSource.includes('/api/admin/setup')) {
  console.error('Security contract failed: public admin setup endpoint is present.');
  process.exit(1);
}

console.log('Security contract passed: no public admin setup endpoint.');

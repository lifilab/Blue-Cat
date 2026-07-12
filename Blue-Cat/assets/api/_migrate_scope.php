<?php
require_once __DIR__ . '/_db.php';
$conn = getDB();

$r = $conn->query("SHOW COLUMNS FROM rol_permiso LIKE 'id_empresa'");
if ($r->num_rows == 0) {
    $conn->query('ALTER TABLE rol_permiso ADD COLUMN id_empresa INT DEFAULT NULL');
    echo "Added id_empresa column\n";
} else {
    echo "id_empresa column already exists\n";
}

$r2 = $conn->query("SHOW COLUMNS FROM rol_permiso LIKE 'id_sucursal'");
if ($r2->num_rows == 0) {
    $conn->query('ALTER TABLE rol_permiso ADD COLUMN id_sucursal INT DEFAULT NULL');
    echo "Added id_sucursal column\n";
} else {
    echo "id_sucursal column already exists\n";
}
echo "Migration complete.\n";

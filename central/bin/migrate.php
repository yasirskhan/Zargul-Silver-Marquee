<?php
declare(strict_types=1);

$dsn = getenv('CENTRAL_DB_DSN');
$username = getenv('CENTRAL_DB_USERNAME');
$password = getenv('CENTRAL_DB_PASSWORD');
if (!$dsn || !$username || $password === false || !str_starts_with($dsn, 'pgsql:')) {
    fwrite(STDERR, "Set CENTRAL_DB_DSN (PostgreSQL), CENTRAL_DB_USERNAME and CENTRAL_DB_PASSWORD.\n");
    exit(2);
}
$db = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (glob(dirname(__DIR__).'/schema/*.sql') as $migration) {
    $db->exec(file_get_contents($migration));
}
echo "HAFZATECH central identity schema installed in dedicated database.\n";

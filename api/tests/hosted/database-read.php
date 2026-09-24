<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

\Wellness\Config::loadEnvFile(dirname(__DIR__, 2) . '/.env');
$config = \Wellness\Config::fromEnvironment();
$pdo = (new \Wellness\Database($config))->connection();
$required = ['clinics', 'users', 'practitioners', 'services', 'appointments', 'notification_events'];
$statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
foreach ($required as $table) {
    $statement->execute([$table]);
    if ((int)$statement->fetchColumn() !== 1) throw new RuntimeException('Required table missing: ' . $table);
}
echo 'Database connection and ' . count($required) . ' required tables verified; server version ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ".\n";

<?php
declare(strict_types=1);

namespace Wellness;

use PDO;

final class Database
{
    private ?PDO $connection = null;

    public function __construct(private readonly Config $config) {}

    public function connection(): PDO
    {
        if($this->connection!==null)return $this->connection;
        $class = $this->config->browserSessionClinicIds !== [] || $this->config->browserSessionCentralEnabled ? SessionConnection::class : PDO::class;
        return $this->connection ??= new $class(
            "mysql:host={$this->config->dbHost};port={$this->config->dbPort};dbname={$this->config->dbName};charset=utf8mb4",
            $this->config->dbUser,
            $this->config->dbPassword,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
            ],
        );
    }
}

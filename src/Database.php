<?php

declare(strict_types=1);

class Database
{
    private PDO $pdo;
    private string $prefix;

    public function __construct(
        string $host,
        string $port,
        string $dbname,
        string $user,
        string $password,
        string $prefix = ''
    ) {
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];
        $this->pdo    = new PDO($dsn, $user, $password, $options);
        $this->prefix = $prefix;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /** Run a SELECT query and return all rows. */
    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Run a SELECT query and return the first row or null. */
    public function queryOne(string $sql, array $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    /** Execute an INSERT/UPDATE/DELETE and return the last insert id (or 0). */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $this->pdo->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        $this->pdo->rollBack();
    }

    public function tableExists(string $table): bool
    {
        $result = $this->queryOne('SHOW TABLES LIKE ?', [$table]);
        return $result !== null;
    }

    /** Return a list of existing table names matching a prefix. */
    public function getTables(string $likePrefix = ''): array
    {
        if ($likePrefix !== '') {
            $rows = $this->query('SHOW TABLES LIKE ?', [$likePrefix . '%']);
        } else {
            $rows = $this->query('SHOW TABLES');
        }
        return array_map('current', $rows);
    }
}

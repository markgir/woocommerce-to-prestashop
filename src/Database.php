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
        // For TCP connections, do a quick pre-check with a short timeout so that
        // an unreachable host fails fast (within ~5 s) instead of hanging for the
        // full OS-level TCP timeout (~30 s). Without this, a PHP web request can
        // race against the server's request timeout and the server returns HTTP 500
        // before PHP has a chance to catch the PDOException and send a proper error.
        //
        // Skip the pre-check for Unix-socket paths and for 'localhost', which in
        // the MySQL client library maps to the local Unix socket rather than a TCP
        // connection; a missing Unix socket fails immediately via PDO anyway.
        $isUnixSocket = $host === '' || $host === 'localhost' || str_starts_with($host, '/');
        if (!$isUnixSocket) {
            $fp = @fsockopen($host, (int) $port, $errno, $errstr, 5);
            if ($fp === false) {
                $detail = ($errstr !== '') ? $errstr : 'connection timed out or host unreachable';
                throw new \RuntimeException(
                    "Cannot reach database server at {$host}:{$port} – {$detail}"
                );
            }
            fclose($fp);
        }

        // Limit PHP-level socket/stream operations to 5 s so that a stalled
        // connection (e.g. 'localhost' falling through to a slow TCP attempt on
        // shared hosting where no Unix socket exists) does not hang until the
        // web-server's request timeout kills the PHP process with a raw 500.
        $prevSocketTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '5');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $password, $options);
        } finally {
            ini_set('default_socket_timeout', $prevSocketTimeout ?: '60');
        }
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

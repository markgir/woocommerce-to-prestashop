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
        string $prefix = '',
        string $socket = ''
    ) {
        // For TCP connections, do a quick pre-check with a short timeout so that
        // an unreachable host fails fast (within ~5 s) instead of hanging for the
        // full OS-level TCP timeout (~30 s). Without this, a PHP web request can
        // race against the server's request timeout and the server returns HTTP 500
        // before PHP has a chance to catch the PDOException and send a proper error.
        //
        // Skip the pre-check for Unix-socket paths, for 'localhost' (which in
        // the MySQL client library maps to the local Unix socket rather than a TCP
        // connection), and when an explicit socket path is given.
        $isUnixSocket = $socket !== ''
            || $host === ''
            || $host === 'localhost'
            || str_starts_with($host, '/');
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

        // Build DSN – include explicit unix_socket when provided, otherwise
        // fall back to host+port (PDO will use the default socket for 'localhost').
        if ($socket !== '') {
            $dsn = "mysql:unix_socket={$socket};dbname={$dbname};charset=utf8mb4";
        } else {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $password, $options);
        } catch (\PDOException $e) {
            throw new \RuntimeException(self::friendlyError($e, $host, $port, $socket, $user));
        } finally {
            ini_set('default_socket_timeout', $prevSocketTimeout ?: '60');
        }
        $this->prefix = $prefix;
    }

    /**
     * Translate a PDOException into an actionable, human-readable message.
     *
     * Common MySQL error codes:
     *   1045 – Access denied (wrong user/password or host mismatch)
     *   1698 – Access denied (auth_socket/unix_socket plugin – no password login)
     *   2002 – Can't connect via socket (wrong path or MySQL not running)
     *   2054 – Server requested unknown authentication method
     */
    private static function friendlyError(
        \PDOException $e,
        string $host,
        string $port,
        string $socket,
        string $user
    ): string {
        $msg  = $e->getMessage();
        $code = (int) ($e->errorInfo[1] ?? 0);

        $isLocal = $host === '' || $host === 'localhost' || $host === '127.0.0.1';
        $altHost = ($host === 'localhost') ? '127.0.0.1' : 'localhost';

        // 1045 – Access denied (most common authentication error)
        if ($code === 1045) {
            $hint = "Access denied for user '{$user}'."
                . ' Check that the username and password are correct.';
            if ($isLocal) {
                $hint .= " MySQL treats 'localhost' (Unix socket) and '127.0.0.1'"
                    . " (TCP) as different hosts — if the current host doesn't work,"
                    . " try switching to '{$altHost}'.";
            }
            return $hint;
        }

        // 1698 – auth_socket / unix_socket plugin (common on Ubuntu/Debian)
        if ($code === 1698) {
            return "Access denied for user '{$user}': the MySQL account uses"
                . ' socket-based authentication (auth_socket / unix_socket plugin)'
                . ' which does not accept a password from PHP.'
                . ' Create a dedicated MySQL user with password authentication: '
                . "CREATE USER '{$user}'@'localhost' IDENTIFIED BY 'yourpassword';"
                . " GRANT ALL ON `yourdb`.* TO '{$user}'@'localhost'; FLUSH PRIVILEGES;";
        }

        // 2054 – Unknown authentication method (caching_sha2_password on old PHP)
        if ($code === 2054) {
            return 'The MySQL server uses an authentication method not supported'
                . ' by this PHP installation (likely caching_sha2_password).'
                . ' Fix: run  ALTER USER \'' . $user . '\'@\'localhost\''
                . ' IDENTIFIED WITH mysql_native_password BY \'yourpassword\';'
                . '  or upgrade PHP to 7.4+ with mysqlnd.';
        }

        // 2002 – Socket not found / connection refused
        if ($code === 2002) {
            if ($socket !== '') {
                return "Cannot connect via socket '{$socket}' — verify the path"
                    . ' is correct and that the MySQL server is running.';
            }
            $hint = 'Cannot connect to the MySQL server.';
            if ($host === 'localhost') {
                $hint .= ' When host is "localhost", MySQL connects via Unix socket.'
                    . ' If the socket path is wrong or MySQL only listens on TCP,'
                    . " try using '127.0.0.1' as host instead, or provide the"
                    . ' correct socket path in the Socket Path field.';
            } elseif ($host === '127.0.0.1') {
                $hint .= ' Verify that MySQL is running and accepting TCP connections'
                    . " on port {$port}. You can also try 'localhost' to connect via"
                    . ' Unix socket.';
            } else {
                $hint .= " Verify that the MySQL server at {$host}:{$port} is"
                    . ' running and accepting connections.';
            }
            return $hint;
        }

        // Fallback – include the original PDO message
        return $msg;
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

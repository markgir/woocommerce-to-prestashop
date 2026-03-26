<?php

declare(strict_types=1);

class DebugLogger
{
    private string $logFile;
    private array  $buffer = [];

    public function __construct(string $sessionId)
    {
        $dir           = __DIR__ . '/../migration_progress';
        $this->logFile = $dir . '/log_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) . '.json';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function success(string $message, array $context = []): void
    {
        $this->write('success', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    private function write(string $level, string $message, array $context = []): void
    {
        $entry = [
            'ts'      => date('Y-m-d H:i:s'),
            'level'   => $level,
            'message' => $message,
        ];
        if (!empty($context)) {
            $entry['context'] = $context;
        }

        $this->buffer[] = $entry;

        // Append to file immediately so the browser can poll it
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Return recent log entries from the log file.
     *
     * @param int $offset  Line offset to start reading from (for incremental polling).
     * @return array{entries: array, next_offset: int}
     */
    public function getEntries(int $offset = 0): array
    {
        if (!file_exists($this->logFile)) {
            return ['entries' => [], 'next_offset' => 0];
        }

        $lines   = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $total   = count($lines);
        $slice   = array_slice($lines, $offset);
        $entries = [];
        foreach ($slice as $line) {
            $decoded = json_decode($line, true);
            if ($decoded) {
                $entries[] = $decoded;
            }
        }

        return [
            'entries'     => $entries,
            'next_offset' => $total,
        ];
    }

    public function clearLog(): void
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }
}

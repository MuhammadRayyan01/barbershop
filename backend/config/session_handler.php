<?php
declare(strict_types=1);

 // Stores PHP sessions in PostgreSQL (Supabase) instead of local files,
 // so logins survive across Vercel's serverless instances.
final class PdoSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private bool $tableChecked = false;

    public function __construct(private PDO $pdo)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $row = $this->run(
            'SELECT data FROM php_sessions WHERE id = :id AND expires_at > NOW()',
            ['id' => $id]
        )->fetchColumn();

        return $row === false ? '' : (string) $row;
    }

    public function write(string $id, string $data): bool
    {
        $this->run(
            'INSERT INTO php_sessions (id, data, expires_at)
             VALUES (:id, :data, NOW() + make_interval(secs => :ttl))
             ON CONFLICT (id) DO UPDATE
                SET data = EXCLUDED.data, expires_at = EXCLUDED.expires_at',
            ['id' => $id, 'data' => $data, 'ttl' => $this->ttl()]
        );

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->run('DELETE FROM php_sessions WHERE id = :id', ['id' => $id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->run('DELETE FROM php_sessions WHERE expires_at <= NOW()')->rowCount();
    }

    public function validateId(string $id): bool
    {
        return $this->run(
            'SELECT 1 FROM php_sessions WHERE id = :id AND expires_at > NOW()',
            ['id' => $id]
        )->fetchColumn() !== false;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $this->run(
            'UPDATE php_sessions SET expires_at = NOW() + make_interval(secs => :ttl) WHERE id = :id',
            ['id' => $id, 'ttl' => $this->ttl()]
        );
        return true;
    }

    private function ttl(): int
    {
        return max(60, (int) ini_get('session.gc_maxlifetime'));
    }

    private function run(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            // Create the table automatically the first time if it does not exist yet.
            if ($this->tableChecked || $e->getCode() !== '42P01') {
                throw $e;
            }
            $this->tableChecked = true;
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS php_sessions (
                    id VARCHAR(128) PRIMARY KEY,
                    data TEXT NOT NULL DEFAULT \'\',
                    expires_at TIMESTAMPTZ NOT NULL
                 )'
            );
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS php_sessions_expires_at_idx ON php_sessions (expires_at)');

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        }
    }
}
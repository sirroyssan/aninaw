<?php
/**
 * Database connection (PDO, MySQL/MariaDB).
 */
declare(strict_types=1);

final class DatabaseUnavailableException extends RuntimeException
{
}

/**
 * Returns the shared PDO connection, connecting on first use.
 * Prepared statements are native (not emulated) so parameters never reach SQL text.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        // Keep MySQL NOW()/CURDATE() aligned with PHP's configured timezone.
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    } catch (PDOException $exception) {
        log_error('Database connection failed', ['code' => $exception->getCode(), 'message' => $exception->getMessage()]);
        throw new DatabaseUnavailableException('The database is currently unavailable.', 0, $exception);
    }

    return $pdo;
}

/** Runs a parameterised query and returns the statement. */
function db_query(string $sql, array $params = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function db_one(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

function db_value(string $sql, array $params = []): mixed
{
    $value = db_query($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

/** Whether a column exists (cached per request), so a page keeps working before an optional migration is run. */
function db_column_exists(string $table, string $column): bool
{
    static $cache = [];
    return $cache[$table . '.' . $column] ??= (bool) db_value(
        'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        [$table, $column]
    );
}

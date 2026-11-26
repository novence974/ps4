<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/** Thin PDO wrapper. Every query goes through prepared statements with typed bindings. */
final class DB
{
    private static ?PDO $pdo = null;
    public static int $queries = 0;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = Config::get('db');
            $dsn = !empty($c['socket'])
                ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $c['socket'], $c['database'])
                : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int) $c['port'], $c['database']);
            self::$pdo = new PDO($dsn, (string) $c['username'], (string) $c['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            // Same strict SQL mode on every host (MySQL 8 defaults), so a query never behaves differently between servers.
            self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00', "
                . "sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        }
        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with((string) $key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value), is_bool($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, is_bool($value) ? (int) $value : $value, $type);
        }
        $stmt->execute();
        self::$queries++;
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function column(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Column names always come from application code, never from user input. */
    public static function insert(string $table, array $data, bool $ignore = false): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT %sINTO `%s` (`%s`) VALUES (%s)',
            $ignore ? 'IGNORE ' : '',
            $table,
            implode('`, `', $cols),
            implode(', ', array_map(static fn ($c) => ':' . $c, $cols))
        );
        self::run($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if (!$data) {
            return 0;
        }
        $sets = [];
        $params = [];
        foreach ($data as $col => $val) {
            $sets[] = "`$col` = :set_$col";
            $params["set_$col"] = $val;
        }
        foreach ($whereParams as $k => $v) {
            $params[is_int($k) ? 'w' . $k : ltrim((string) $k, ':')] = $v;
        }
        if (array_is_list($whereParams)) {
            $i = 0;
            $where = preg_replace_callback('/\?/', static function () use (&$i) {
                return ':w' . $i++;
            }, $where);
        }
        return self::run(sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where), $params)->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        return self::run(sprintf('DELETE FROM `%s` WHERE %s', $table, $where), $params)->rowCount();
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** IN (...) helper: returns [placeholders, params]. */
    public static function in(array $values, string $prefix = 'in'): array
    {
        $ph = [];
        $params = [];
        foreach (array_values($values) as $i => $v) {
            $ph[] = ":{$prefix}{$i}";
            $params["{$prefix}{$i}"] = $v;
        }
        return [$ph ? implode(',', $ph) : 'NULL', $params];
    }
}

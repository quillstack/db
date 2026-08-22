<?php

declare(strict_types=1);

namespace Quillstack\Db\Tests\Mocks;

use Quillstack\Db\Connection;

/**
 * A database which lives for the length of one test. Building queries can be checked by
 * reading the SQL, but only running them against something real says whether it works.
 */
class InMemoryDatabase
{
    public static function connection(): Connection
    {
        $connection = new Connection('sqlite::memory:');

        $connection->execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            deleted_at TEXT NULL
        )');

        $connection->execute('CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL
        )');

        return $connection;
    }

    public static function withRows(): Connection
    {
        $connection = self::connection();

        foreach ([
            ['ada@example.com', 1, null],
            ['grace@example.com', 1, null],
            ['alan@example.com', 0, '2026-01-01 00:00:00'],
        ] as [$email, $active, $deletedAt]) {
            $connection->table('users')->insert([
                'email' => $email,
                'active' => $active,
                'deleted_at' => $deletedAt,
            ]);
        }

        foreach ([[1, 'First'], [1, 'Second'], [2, 'Third']] as [$userId, $title]) {
            $connection->table('posts')->insert(['user_id' => $userId, 'title' => $title]);
        }

        return $connection;
    }
}

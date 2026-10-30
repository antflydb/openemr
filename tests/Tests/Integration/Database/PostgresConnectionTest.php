<?php

/**
 * PostgreSQL reference tests for the database port.
 *
 * @package   OpenEMR
 * @copyright Copyright (c) 2026 AntflyDB
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use OpenEMR\BC\DatabaseBackend;
use OpenEMR\BC\DatabaseConnectionOptions;
use PHPUnit\Framework\TestCase;

final class PostgresConnectionTest extends TestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $host = getenv('OPENEMR_POSTGRES_TEST_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('Set OPENEMR_POSTGRES_TEST_HOST to run PostgreSQL reference tests');
        }
        $options = new DatabaseConnectionOptions(
            dbname: getenv('OPENEMR_POSTGRES_TEST_DATABASE') ?: 'openemr',
            user: getenv('OPENEMR_POSTGRES_TEST_USER') ?: 'openemr',
            password: getenv('OPENEMR_POSTGRES_TEST_PASSWORD') ?: '',
            host: $host,
            port: 5432,
            backend: DatabaseBackend::PostgreSQL,
            sslMode: getenv('OPENEMR_POSTGRES_TEST_SSLMODE') ?: 'verify-full',
        );
        $this->connection = DriverManager::getConnection($options->toDbalParams());
    }

    protected function tearDown(): void
    {
        $this->connection?->close();
    }

    public function testPreparedValuesAndTransactionBoundaries(): void
    {
        $connection = $this->connection;
        self::assertNotNull($connection);
        $connection->executeStatement(
            'CREATE TEMPORARY TABLE openemr_port_probe (id BIGINT PRIMARY KEY, note TEXT, amount NUMERIC(18, 2))'
        );
        $note = "Patient's literal ? and :value; Unicode: café 日本語";
        $connection->beginTransaction();
        $connection->executeStatement(
            'INSERT INTO openemr_port_probe (id, note, amount) VALUES (?, ?, ?)',
            ['9007199254740993', $note, '1234567890123456.78'],
        );
        $row = $connection->fetchAssociative(
            'SELECT id, note, amount FROM openemr_port_probe WHERE id = ?',
            ['9007199254740993'],
        );
        self::assertIsArray($row);
        self::assertContains($row['id'], ['9007199254740993', 9007199254740993]);
        self::assertSame($note, $row['note']);
        self::assertSame('1234567890123456.78', $row['amount']);
        $connection->rollBack();
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM openemr_port_probe'));

        $connection->beginTransaction();
        $connection->executeStatement(
            'INSERT INTO openemr_port_probe (id, note, amount) VALUES (?, ?, ?)',
            [1, null, '0.01'],
        );
        $connection->commit();
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM openemr_port_probe'));
        self::assertNull($connection->fetchOne('SELECT note FROM openemr_port_probe WHERE id = 1'));
    }
}

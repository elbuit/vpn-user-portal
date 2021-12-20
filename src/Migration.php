<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2021, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace Vpn\Portal;

use Exception;
use PDO;
use PDOException;
use RangeException;
use RuntimeException;
use Vpn\Portal\Exception\MigrationException;

class Migration
{
    public const NO_VERSION = '0000000000';

    private PDO $dbh;
    private string $schemaVersion;
    private string $schemaDir;

    public function __construct(PDO $dbh, string $schemaDir, string $schemaVersion)
    {
        $this->dbh = $dbh;
        $this->schemaDir = $schemaDir;
        $this->schemaVersion = self::validateSchemaVersion($schemaVersion);
    }

    /**
     * Initialize the database using the schema file located in the schema
     * directory with schema version.
     */
    public function init(): void
    {
        $this->runQueries(self::getQueriesFromFile($this->getNameForDriver(sprintf('%s/%s.schema', $this->schemaDir, $this->schemaVersion))));
        $this->createVersionTable($this->schemaVersion);
    }

    /**
     * Run the migration.
     */
    public function run(): bool
    {
        $currentVersion = $this->getCurrentVersion();
        if ($currentVersion === $this->schemaVersion) {
            // database schema is up to date, no update required
            return false;
        }

        /** @var array<string>|false $migrationList */
        $migrationList = glob($this->getNameForDriver(sprintf('%s/*_*.migration', $this->schemaDir)));
        if (false === $migrationList) {
            throw new RuntimeException(sprintf('unable to read schema directory "%s"', $this->schemaDir));
        }

        $this->lock();

        try {
            foreach ($migrationList as $migrationFile) {
                $migrationVersion = $this->getNameForDriver(basename($migrationFile, '.migration'));
                [$fromVersion, $toVersion] = self::validateMigrationVersion($migrationVersion);
                if ($fromVersion === $currentVersion && $fromVersion !== $this->schemaVersion) {
                    // get the queries before we start the transaction as we
                    // ONLY want to deal with "PDOExceptions" once the
                    // transacation started...
                    $queryList = self::getQueriesFromFile($this->getNameForDriver(sprintf('%s/%s.migration', $this->schemaDir, $migrationVersion)));

                    try {
                        $this->dbh->beginTransaction();
                        $this->dbh->exec(sprintf("DELETE FROM version WHERE current_version = '%s'", $fromVersion));
                        $this->runQueries($queryList);
                        $this->dbh->exec(sprintf("INSERT INTO version (current_version) VALUES('%s')", $toVersion));
                        $this->dbh->commit();
                        $currentVersion = $toVersion;
                    } catch (PDOException $e) {
                        // something went wrong with the SQL queries
                        $this->dbh->rollback();

                        throw $e;
                    }
                }
            }
        } catch (Exception $e) {
            // something went wrong that was not related to SQL queries
            $this->unlock();

            throw $e;
        }

        $this->unlock();

        $currentVersion = $this->getCurrentVersion();
        if ($currentVersion !== $this->schemaVersion) {
            throw new MigrationException(sprintf('unable to migrate to database schema version "%s", not all required migrations are available', $this->schemaVersion));
        }

        return true;
    }

    /**
     * Gets the current version of the database schema.
     */
    public function getCurrentVersion(): string
    {
        try {
            $sth = $this->dbh->query('SELECT current_version FROM version');
            $currentVersion = $sth->fetchColumn();
            if (!\is_string($currentVersion)) {
                throw new MigrationException('unable to retrieve current version');
            }

            return $currentVersion;
        } catch (PDOException $e) {
            $this->createVersionTable(self::NO_VERSION);

            return self::NO_VERSION;
        }
    }

    /**
     * See if there is a file available specifically for this DB driver. If
     * so, use it, if not fallback to the "default".
     */
    private function getNameForDriver(string $fileName): string
    {
        $driverName = $this->dbh->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (file_exists($fileName.'.'.$driverName)) {
            return $fileName.'.'.$driverName;
        }

        return $fileName;
    }

    private function lock(): void
    {
        // this creates a "lock" as only one process will succeed in this...
        $this->dbh->exec('CREATE TABLE _migration_in_progress (dummy INTEGER)');

        if ('sqlite' === $this->dbh->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            $this->dbh->exec('PRAGMA foreign_keys = OFF');
        }
    }

    private function unlock(): void
    {
        if ('sqlite' === $this->dbh->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            $this->dbh->exec('PRAGMA foreign_keys = ON');
        }

        // release "lock"
        $this->dbh->exec('DROP TABLE _migration_in_progress');
    }

    private function createVersionTable(string $schemaVersion): void
    {
        $this->dbh->exec('CREATE TABLE version (current_version TEXT NOT NULL)');
        // we know that schemaVersion is a 10 digit string as per
        // validateSchemaVersion
        $this->dbh->exec(sprintf("INSERT INTO version (current_version) VALUES('%s')", $schemaVersion));
    }

    /**
     * @param array<string> $queryList
     */
    private function runQueries(array $queryList): void
    {
        foreach ($queryList as $dbQuery) {
            if (0 === Binary::safeStrlen(trim($dbQuery))) {
                // ignore empty line(s)
                continue;
            }
            $this->dbh->exec($dbQuery);
        }
    }

    /**
     * @return array<string>
     */
    private static function getQueriesFromFile(string $filePath): array
    {
        /** @var false|string $fileContent */
        $fileContent = file_get_contents($filePath);
        if (false === $fileContent) {
            throw new RuntimeException(sprintf('unable to read "%s"', $filePath));
        }

        return explode(';', $fileContent);
    }

    private static function validateSchemaVersion(string $schemaVersion): string
    {
        if (1 !== preg_match('/^[0-9]{10}$/', $schemaVersion)) {
            throw new RangeException('schemaVersion must be 10 a digit string');
        }

        return $schemaVersion;
    }

    /**
     * @return array<string>
     */
    private static function validateMigrationVersion(string $migrationVersion): array
    {
        if (1 !== preg_match('/^[0-9]{10}_[0-9]{10}$/', $migrationVersion)) {
            throw new RangeException('migrationVersion must be two times a 10 digit string separated by an underscore');
        }

        return explode('_', $migrationVersion);
    }
}

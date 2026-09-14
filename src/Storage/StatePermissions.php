<?php

declare(strict_types=1);

namespace NaviBrain\Storage;

use RuntimeException;

class StatePermissions
{
    public static function prepareDatabaseDirectory(string $databasePath, string $projectRoot): void
    {
        $directory = dirname($databasePath);
        $created = false;
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf( 'Unable to create database directory: %s', $directory ));
            }
            $created = true;
        }

        $permissions = fileperms($directory);
        if ($permissions === false) {
            throw new RuntimeException(sprintf( 'Unable to inspect database directory permissions: %s', $directory ));
        }
        if (($permissions & 0077) === 0) {
            return;
        }

        if (!$created && !self::mayHardenExistingDirectory($directory, $projectRoot)) {
            throw new RuntimeException(sprintf( 'Database directory must be private (0700): %s', $directory ));
        }
        if (!chmod($directory, 0700)) {
            throw new RuntimeException(sprintf( 'Unable to harden database directory permissions: %s', $directory ));
        }
    }

    public static function hardenDatabaseFiles(string $databasePath): void
    {
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
            if (is_file($path) && !chmod($path, 0600)) {
                throw new RuntimeException(sprintf( 'Unable to harden database file permissions: %s', $path ));
            }
        }
    }

    private static function mayHardenExistingDirectory(string $directory, string $projectRoot): bool
    {
        $resolved = realpath($directory);
        $projectState = realpath($projectRoot . '/var');
        if ($resolved !== false && $projectState !== false && $resolved === $projectState) {
            return true;
        }

        $normalized = str_replace('\\', '/', rtrim($directory, '/'));
        return str_ends_with($normalized, '/.local/state/navi-brain');
    }
}

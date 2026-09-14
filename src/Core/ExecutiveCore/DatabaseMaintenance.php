<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use NaviBrain\Model\SqliteCatalog;
use RuntimeException;

class DatabaseMaintenance extends Component
{
    public function quickCheck(): array
    {
        return array_map(static fn (SqliteCatalog $row): string => (string) $row->quick_check, SqliteCatalog::getAllByQuery('PRAGMA quick_check'));
    }

    public function periodicQuickCheck(int $now): array
    {
        if ($this->executive->cachedQuickCheck !== null
            && $this->executive->cachedQuickCheckAt > 0
            && $now - $this->executive->cachedQuickCheckAt < Executive::QUICK_CHECK_INTERVAL_SECONDS
        ) {
            return $this->executive->cachedQuickCheck;
        }

        $this->executive->cachedQuickCheck = $this->quickCheck();
        $this->executive->cachedQuickCheckAt = $now;
        return $this->executive->cachedQuickCheck;
    }

    public function backupDatabase(string $reason): array
    {
        $this->requireText($reason, 'backup reason');
        $database = SqliteCatalog::getAllByQuery('PRAGMA database_list');
        $main = array_values(array_filter( $database, static fn (SqliteCatalog $row): bool => $row->name === 'main' ));
        $source = is_string($main[0]->file ?? null) ? (string) $main[0]->file : '';
        if ($source === '') {
            throw new RuntimeException('Cannot back up an in-memory or unidentified SQLite database.');
        }

        $directory = dirname($source) . '/backups';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create database backup directory: %s', $directory));
        }
        $stem = pathinfo($source, PATHINFO_FILENAME);
        $target = sprintf('%s/%s-%s-%s.memory-bundle', $directory, $stem, gmdate('Ymd-His'), bin2hex(random_bytes(3)));

        $projectRoot = dirname(__DIR__, 3);
        $bundleTool = $projectRoot . '/bin/token-memory-bundle';
        $tokmem = $projectRoot . '/memories/build/tokmem';
        $configuredStore = getenv('NAVI_TOKEN_MEMORY_STORE');
        $store = is_string($configuredStore) && $configuredStore !== ''
            ? rtrim($configuredStore, '/')
            : $projectRoot . '/memories/store';
        foreach ([$bundleTool, $tokmem] as $executable) {
            if (!is_file($executable) || !is_executable($executable)) {
                throw new RuntimeException('Token-memory backup executable is unavailable: ' . $executable);
            }
        }
        if (!is_dir($store)) {
            throw new RuntimeException('Token-memory store is unavailable: ' . $store);
        }

        $pipes = [];
        $process = proc_open(
            [
                $bundleTool,
                'create',
                '--store', $store,
                '--database', $source,
                '--destination', $target,
                '--tokmem', $tokmem,
                '--close-daemon',
                '--deep-verify',
            ],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $projectRoot,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to launch token-memory bundle backup.');
        }
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('Token-memory bundle backup failed: ' . trim((string) $stderr));
        }

        $receipt = [];
        foreach (preg_split('/\R/', trim((string) $stdout)) ?: [] as $line) {
            if (preg_match('/\A([a-z][a-z0-9_]*)=(.*)\z/', $line, $matches) === 1) {
                $receipt[$matches[1]] = $matches[2];
            }
        }
        $manifest = $target . '/MANIFEST.sha256';
        if (($receipt['bundle'] ?? null) !== $target
            || !is_file($manifest)
            || !is_string($receipt['manifest_sha256'] ?? null)
            || !hash_equals((string) $receipt['manifest_sha256'], hash_file('sha256', $manifest))
        ) {
            throw new RuntimeException('Token-memory bundle returned an invalid verification receipt.');
        }

        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $bytes += $item->getSize();
            }
        }
        $result = [
            'path' => $target,
            'format' => 'NAVITOKBACKUP1',
            'sha256' => (string) $receipt['manifest_sha256'],
            'manifest_sha256' => (string) $receipt['manifest_sha256'],
            'bytes' => $bytes,
            'files' => (int) ($receipt['files'] ?? 0),
            'memory_records' => (int) ($receipt['records'] ?? 0),
            'memory_sequence' => (int) ($receipt['sqlite_sequence'] ?? 0),
            'quick_check' => ['ok'],
        ];
        $this->emit('backup.created', array_merge($result, ['reason' => $reason]));
        return $result;
    }
}

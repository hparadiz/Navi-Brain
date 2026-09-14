<?php

declare(strict_types=1);

namespace NaviBrain\Storage;

use RuntimeException;

class TokenMemoryDaemon
{
    public const DEFAULT_CONTEXT_TOKENS = 1024;
    public const MAX_CONTEXT_TOKENS = 67_108_864;

    private const PROTOCOL_ABI = 'TOKMEM/1';
    private const PROBE_TIMEOUT_SECONDS = 0.25;
    private const IO_TIMEOUT_SECONDS = 5.0;
    private const START_TIMEOUT_SECONDS = 15.0;
    private const MAX_RESPONSE_BYTES = 67_108_864;

    private static bool $ready = false;

    public static function activate(string $cue, int $tokenBudget = self::DEFAULT_CONTEXT_TOKENS): string {
        if (trim($cue) === '' || strlen($cue) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('Token-memory activation cue is out of range.');
        }
        if ($tokenBudget < 1 || $tokenBudget > self::MAX_CONTEXT_TOKENS) {
            throw new RuntimeException('Token-memory activation budget is out of range.');
        }
        return self::request(sprintf( "ACTIVATE %d %d\n", $tokenBudget, strlen($cue) ), $cue);
    }

    public static function flush(): void
    {
        if (self::request("FLUSH\n") !== '') {
            throw new RuntimeException('Token-memory daemon returned an invalid flush receipt.');
        }
    }

    public static function ensureRunning(): void
    {
        if (self::$ready) {
            return;
        }

        $projectRoot = dirname(__DIR__, 2);
        $storePath = self::storePath($projectRoot);
        if (!is_dir($storePath)) {
            throw new RuntimeException('Token-memory store is unavailable: ' . $storePath);
        }

        $socketPath = $storePath . '/tokmem.sock';
        if (self::probe($socketPath)) {
            self::$ready = true;
            return;
        }

        self::start($projectRoot, $storePath, $socketPath);
        self::$ready = true;
    }

    public static function operationKey(string $scope, string $identity): string
    {
        if ($scope === '' || $identity === '') {
            throw new RuntimeException('Token-memory operation identity cannot be empty.');
        }
        return hash('sha256', $scope . "\0" . $identity);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public static function create(array $record, string $operationKey): array
    {
        $record['id'] = 0;
        $metadata = self::encodeMetadata($record);
        $content = (string) ($record['content'] ?? '');
        self::validateOperationKey($operationKey);
        $payload = self::receiptRequest(sprintf( "CREATE %s %d %d\n", $operationKey, strlen($metadata), strlen($content) ), $metadata . $content);
        $records = self::decodeRecords($payload);
        if (count($records) !== 1) {
            throw new RuntimeException('Token-memory daemon returned an invalid create receipt.');
        }
        return $records[0];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public static function update(array $record, string $operationKey): array
    {
        $metadata = self::encodeMetadata($record);
        $content = (string) ($record['content'] ?? '');
        self::validateOperationKey($operationKey);
        $payload = self::receiptRequest(sprintf( "UPDATE %s %d %d\n", $operationKey, strlen($metadata), strlen($content) ), $metadata . $content);
        $records = self::decodeRecords($payload);
        if (count($records) !== 1 ||
            (int) $records[0]['id'] !== (int) ($record['id'] ?? 0)) {
            throw new RuntimeException('Token-memory daemon returned an invalid update receipt.');
        }
        return $records[0];
    }

    /**
     * @param array<string, mixed> $newRecord
     * @param array<string, mixed> $oldRecord
     * @return array{new: array<string, mixed>, old: array<string, mixed>}
     */
    public static function replace(array $newRecord, array $oldRecord, string $operationKey): array {
        $newRecord['id'] = 0;
        $newMetadata = self::encodeMetadata($newRecord);
        $oldMetadata = self::encodeMetadata($oldRecord);
        $content = (string) ($newRecord['content'] ?? '');
        self::validateOperationKey($operationKey);
        $payload = self::receiptRequest(sprintf( "REPLACE %s %d %d %d\n", $operationKey, strlen($newMetadata), strlen($content), strlen($oldMetadata) ), $newMetadata . $content . $oldMetadata);
        $records = self::decodeRecords($payload);
        if (count($records) !== 2 ||
            (int) $records[1]['id'] !== (int) ($oldRecord['id'] ?? 0)) {
            throw new RuntimeException('Token-memory daemon returned an invalid replacement receipt.');
        }
        return ['new' => $records[0], 'old' => $records[1]];
    }

    /** @return array<string, mixed>|null */
    public static function fetch(int $id, bool $counted = true): ?array
    {
        if ($id < 1) {
            return null;
        }
        $mode = $counted ? 'COUNTED' : 'NEUTRAL';
        [$ok, $payload] = self::exchange("FETCH {$mode} {$id}\n", '');
        if (!$ok && $payload === "memory not found\n") {
            return null;
        }
        if (!$ok) {
            throw new RuntimeException('Token-memory fetch failed: ' . trim($payload));
        }
        $records = self::decodeRecords($payload);
        if (count($records) !== 1 || (int) $records[0]['id'] !== $id) {
            throw new RuntimeException('Token-memory fetch returned the wrong record.');
        }
        return $records[0];
    }

    /**
     * @param array<string, mixed> $where
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public static function list(array $where = [], array $options = [], bool $counted = true): array
    {
        $unsupported = array_diff(array_keys($where), ['tier', 'status']);
        if ($unsupported !== []) {
            throw new RuntimeException('Unsupported token-memory filter(s): ' . implode(', ', $unsupported));
        }
        $tier = isset($where['tier']) ? self::protocolWord((string) $where['tier']) : '-';
        $status = isset($where['status']) ? self::protocolWord((string) $where['status']) : '-';
        $order = $options['order'] ?? ['id' => 'ASC'];
        if (!is_array($order) || count($order) !== 1) {
            throw new RuntimeException('Token-memory ordering requires exactly one field.');
        }
        $field = (string) array_key_first($order);
        $direction = strtoupper((string) current($order));
        if (!in_array($field, ['id', 'updated_at'], true)
            || !in_array($direction, ['ASC', 'DESC'], true)
        ) {
            throw new RuntimeException('Unsupported token-memory ordering.');
        }
        $limit = isset($options['limit']) ? (int) $options['limit'] : 0;
        if ($limit < 0 || $limit > 1_000_000) {
            throw new RuntimeException('Token-memory list limit is out of range.');
        }
        $mode = $counted ? 'COUNTED' : 'NEUTRAL';
        return self::decodeRecords(self::request( sprintf("LIST %s %s %s %s %s %d\n", $mode, $tier, $status, $field, $direction, $limit) ));
    }

    /** @return list<array<string, mixed>> */
    public static function page(int $afterId, int $limit, ?string $tier = null, ?string $status = null, bool $counted = false): array {
        if ($afterId < 0 || $limit < 1 || $limit > 1000) {
            throw new RuntimeException('Token-memory page bounds are out of range.');
        }
        $tierWord = $tier === null ? '-' : self::protocolWord($tier);
        $statusWord = $status === null ? '-' : self::protocolWord($status);
        $mode = $counted ? 'COUNTED' : 'NEUTRAL';
        return self::decodeRecords(self::request(sprintf( "PAGE %s %s %s %d %d\n", $mode, $tierWord, $statusWord, $afterId, $limit )));
    }

    public static function countRecords(?string $tier = null, ?string $status = null, int $afterId = 0): int {
        if ($afterId < 0) {
            throw new RuntimeException('Token-memory count cursor is out of range.');
        }
        $tierWord = $tier === null ? '-' : self::protocolWord($tier);
        $statusWord = $status === null ? '-' : self::protocolWord($status);
        $payload = self::request(sprintf( "COUNT %s %s %d\n", $tierWord, $statusWord, $afterId ));
        if (preg_match('/\A(0|[1-9][0-9]*)\n\z/', $payload, $matches) !== 1) {
            throw new RuntimeException('Token-memory daemon returned an invalid count.');
        }
        $count = filter_var($matches[1], FILTER_VALIDATE_INT);
        if (!is_int($count) || $count < 0) {
            throw new RuntimeException('Token-memory count exceeds the PHP integer range.');
        }
        return $count;
    }

    /** @return array<string, mixed>|null */
    public static function provenance(string $kind, int $sourceId, ?string $tier = null, ?string $status = null): ?array {
        $kind = strtoupper($kind);
        if (!in_array($kind, ['MEMORY', 'EVENT', 'SENSE_EVENT'], true) || $sourceId < 1) {
            throw new RuntimeException('Token-memory provenance bounds are invalid.');
        }
        $tierWord = $tier === null ? '-' : self::protocolWord($tier);
        $statusWord = $status === null ? '-' : self::protocolWord($status);

        $command = $kind === 'MEMORY' ? 'PROVENANCE' : 'PROVENANCE_TYPED';
        $payload = self::request(sprintf( "%s %s %d %s %s\n", $command, $kind, $sourceId, $tierWord, $statusWord ));
        $records = self::decodeRecords($payload);
        if ($records === []) {
            return null;
        }
        if (count($records) !== 1) {
            throw new RuntimeException('Token-memory provenance returned the wrong record count.');
        }
        $field = $kind === 'MEMORY' ? 'source_memory_id' : 'source_event_id';
        if ((int) ($records[0][$field] ?? 0) !== $sourceId) {
            throw new RuntimeException('Token-memory provenance returned the wrong source record.');
        }
        if ($kind !== 'MEMORY'
            && ($records[0]['source_event_kind'] ?? null) !== strtolower($kind)
        ) {
            throw new RuntimeException('Token-memory provenance returned the wrong source namespace.');
        }
        return $records[0];
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public static function batch(array $ids, bool $counted): array
    {
        $body = self::idBody($ids);
        if ($body === '') {
            return [];
        }
        $mode = $counted ? 'COUNTED' : 'NEUTRAL';
        return self::decodeRecords(self::request(sprintf( "BATCH %s %d %d\n", $mode, count($ids), strlen($body) ), $body));
    }

    /** @param list<int> $ids */
    public static function observe(array $ids): void
    {
        $body = self::idBody($ids);
        if ($body === '') {
            return;
        }
        $payload = self::request(sprintf( "OBSERVE %d %d\n", count($ids), strlen($body) ), $body);
        if ($payload !== count($ids) . "\n") {
            throw new RuntimeException('Token-memory daemon returned an invalid observe receipt.');
        }
    }

    /** @return list<array<string, mixed>> */
    public static function recall(string $cue, int $limit): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new RuntimeException('Token-memory recall limit is out of range.');
        }
        $records = self::decodeRecords(self::request(sprintf( "RECALL_RECORDS %d %d\n", $limit, strlen($cue) ), $cue));
        return self::sortRecallByKeywordCoverage($records, $cue);
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    private static function sortRecallByKeywordCoverage(array $records, string $cue): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($cue), $matches);
        $keywords = array_values(array_unique($matches[0] ?? []));
        if (count($records) < 2 || $keywords === []) {
            return $records;
        }

        usort($keywords, static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));
        $pattern = '~(?<![\p{L}\p{N}])(' . implode('|', array_map( static fn (string $keyword): string => preg_quote($keyword, '~'), $keywords )) . ')(?![\p{L}\p{N}])~iu';

        $ranked = [];
        foreach ($records as $index => $record) {
            $hits = [];
            preg_match_all($pattern, (string) ($record['content'] ?? ''), $hits);
            $matched = array_values(array_unique(array_map( static fn (string $keyword): string => mb_strtolower($keyword), $hits[1] ?? [] )));
            $ranked[] = [
                'record' => $record,
                'coverage' => count($matched),
                'specificity' => array_sum(array_map('mb_strlen', $matched)),
                'daemon_rank' => $index,
            ];
        }

        usort($ranked, static fn (array $left, array $right): int =>
            ($right['coverage'] <=> $left['coverage'])
            ?: ($right['specificity'] <=> $left['specificity'])
            ?: ($left['daemon_rank'] <=> $right['daemon_rank'])
        );
        return array_column($ranked, 'record');
    }

    /** @return list<array<string, mixed>> */
    public static function rank(string $cue, int $limit, ?string $tier = null, ?string $status = null): array {
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('Token-memory rank limit is out of range.');
        }
        $tierWord = $tier === null ? '-' : self::protocolWord($tier);
        $statusWord = $status === null ? '-' : self::protocolWord($status);
        return self::decodeRecords(self::request(sprintf( "RANK_RECORDS %s %s %d %d\n", $tierWord, $statusWord, $limit, strlen($cue) ), $cue));
    }

    private static function storePath(string $projectRoot): string
    {
        $configured = getenv('NAVI_TOKEN_MEMORY_STORE');
        return is_string($configured) && $configured !== ''
            ? rtrim($configured, '/')
            : $projectRoot . '/memories/store';
    }

    private static function start(string $projectRoot, string $storePath, string $socketPath): void
    {
        $binaryPath = $projectRoot . '/memories/build/tokmem';
        $catalogPath = $storePath . '/catalog.sqlite3';
        if (!is_executable($binaryPath)) {
            throw new RuntimeException('Token-memory daemon binary is not executable: ' . $binaryPath);
        }
        if (!is_file($catalogPath)) {
            throw new RuntimeException('Token-memory store catalog is unavailable: ' . $catalogPath);
        }
        if (!is_executable('/usr/bin/setsid')) {
            throw new RuntimeException('Token-memory daemon requires /usr/bin/setsid.');
        }

        $startLock = self::openLock($storePath . '/.start.lock');
        $deadline = microtime(true) + self::START_TIMEOUT_SECONDS;
        try {
            while (!flock($startLock, LOCK_EX | LOCK_NB)) {
                if (self::probe($socketPath)) {
                    return;
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting for the token-memory startup lock.');
                }
                usleep(50_000);
            }

            if (self::probe($socketPath)) {
                return;
            }

            $storeLock = self::openLock($storePath . '/.lock');
            try {
                while (!flock($storeLock, LOCK_EX | LOCK_NB)) {
                    if (self::probe($socketPath)) {
                        return;
                    }
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('The token-memory store is locked but its daemon is not responding.');
                    }
                    usleep(50_000);
                }

                self::removeStaleSocket($socketPath);
                flock($storeLock, LOCK_UN);
            } finally {
                fclose($storeLock);
            }

            self::spawn($projectRoot, $binaryPath, $storePath, $socketPath);
            while (!self::probe($socketPath)) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Token-memory daemon did not become ready; inspect var/token-memory-daemon.log.');
                }
                usleep(50_000);
            }
        } finally {
            flock($startLock, LOCK_UN);
            fclose($startLock);
        }
    }

    /** @return resource */
    private static function openLock(string $path)
    {
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to open token-memory lock: ' . $path);
        }
        if (!@chmod($path, 0600)) {
            fclose($handle);
            throw new RuntimeException('Unable to harden token-memory lock: ' . $path);
        }
        return $handle;
    }

    private static function removeStaleSocket(string $socketPath): void
    {
        $status = @lstat($socketPath);
        if ($status === false) {
            return;
        }
        if (($status['mode'] & 0170000) !== 0140000) {
            throw new RuntimeException('Refusing to replace non-socket token-memory path: ' . $socketPath);
        }
        if (!@unlink($socketPath)) {
            throw new RuntimeException('Unable to remove stale token-memory socket: ' . $socketPath);
        }
    }

    private static function spawn(string $projectRoot, string $binaryPath, string $storePath, string $socketPath): void {
        $logPath = $projectRoot . '/var/token-memory-daemon.log';
        $process = @proc_open(
            ['/usr/bin/setsid', '--fork', $binaryPath, 'daemon', $storePath, $socketPath],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $logPath, 'a'],
                2 => ['file', $logPath, 'a'],
            ],
            $pipes,
            $projectRoot,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to launch token-memory daemon.');
        }
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('Token-memory launcher exited with status ' . $exitCode . '.');
        }
    }

    private static function probe(string $socketPath): bool
    {
        $socket = self::connect($socketPath, $errorMessage, self::PROBE_TIMEOUT_SECONDS);
        if (!is_resource($socket)) {
            return false;
        }

        try {
            stream_set_timeout($socket, 0, (int) (self::PROBE_TIMEOUT_SECONDS * 1_000_000));
            $request = self::PROTOCOL_ABI . " ABI\n";
            if (@fwrite($socket, $request) !== strlen($request)) {
                return false;
            }
            $header = @fgets($socket, 128);
            if (!is_string($header) || preg_match('/\AOK ([0-9]+)\n\z/', $header, $match) !== 1) {
                return false;
            }
            $length = (int) $match[1];
            if ($length < 1 || $length > 4096) {
                return false;
            }
            $payload = self::readExact($socket, $length);
            return $payload === self::PROTOCOL_ABI . "\n";
        } finally {
            fclose($socket);
        }
    }

    private static function request(string $header, string $body = ''): string
    {
        [$ok, $payload] = self::exchange($header, $body);
        if (!$ok) {
            throw new RuntimeException('Token-memory daemon rejected request: ' . trim($payload));
        }
        return $payload;
    }

    private static function receiptRequest(string $header, string $body): string
    {
        $last = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                [$ok, $payload] = self::exchange($header, $body);
                if (!$ok) {
                    throw new RuntimeException('Token-memory daemon rejected receipt request: ' . trim($payload));
                }
                return $payload;
            } catch (RuntimeException $exception) {
                $last = $exception;
                if ($attempt !== 0) {
                    throw $exception;
                }
            }
        }
        throw $last ?? new RuntimeException('Token-memory receipt request failed.');
    }

    /** @return array{bool, string} */
    private static function exchange(string $header, string $body): array
    {
        $socketPath = self::storePath(dirname(__DIR__, 2)) . '/tokmem.sock';
        $socket = self::connect($socketPath, $errorMessage);
        if (!is_resource($socket)) {
            self::$ready = false;
            self::ensureRunning();
            $socket = self::connect($socketPath, $errorMessage);
            if (!is_resource($socket)) {
                throw new RuntimeException('Unable to connect to token-memory daemon: ' . $errorMessage);
            }
        }
        try {
            stream_set_timeout($socket, (int) self::IO_TIMEOUT_SECONDS);
            self::writeExact($socket, self::PROTOCOL_ABI . ' ' . $header . $body);
            $responseHeader = @fgets($socket, 128);
            if (!is_string($responseHeader)
                || preg_match('/\A(OK|ERR) ([0-9]+)\n\z/', $responseHeader, $match) !== 1
            ) {
                throw new RuntimeException('Token-memory daemon returned an invalid response header.');
            }
            $length = (int) $match[2];
            if ($length > self::MAX_RESPONSE_BYTES) {
                throw new RuntimeException('Token-memory daemon response exceeds the client bound.');
            }
            $payload = self::readExact($socket, $length);
            if ($payload === null) {
                throw new RuntimeException('Token-memory daemon response ended early.');
            }
            return [$match[1] === 'OK', $payload];
        } finally {
            fclose($socket);
        }
    }

    /** @return resource|false */
    private static function connect(string $socketPath, ?string &$errorMessage, float $timeout = self::IO_TIMEOUT_SECONDS)
    {
        $socket = @stream_socket_client('unix://' . $socketPath, $errorNumber, $errorMessage, $timeout, STREAM_CLIENT_CONNECT);

        if (!is_resource($socket) && in_array($errorNumber, [1, 13], true)) {
            throw new RuntimeException(
                'Token-memory socket access denied: ' . $errorMessage
                . '. This process may be sandboxed; retry with approved local socket access.'
                . ' The resident daemon has not been shown to be unhealthy.'
            );
        }
        return $socket;
    }

    /** @param resource $stream */
    private static function writeExact($stream, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = @fwrite($stream, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) {
                throw new RuntimeException('Token-memory daemon request write failed.');
            }
            $offset += $written;
        }
    }

    /** @param resource $stream */
    private static function readExact($stream, int $length): ?string
    {
        $result = '';
        while (strlen($result) < $length) {
            $chunk = @fread($stream, $length - strlen($result));
            if (!is_string($chunk) || $chunk === '') {
                return null;
            }
            $result .= $chunk;
        }
        return $result;
    }

    /** @param array<string, mixed> $record */
    private static function encodeMetadata(array $record): string
    {
        $confidence = (float) ($record['confidence'] ?? 0.0);
        $bits = bin2hex(strrev(pack('d', $confidence)));
        $lines = [
            (string) ((int) ($record['id'] ?? 0)),
            self::hexField((string) ($record['created_at'] ?? '')),
            self::hexField((string) ($record['updated_at'] ?? '')),
            self::hexField((string) ($record['tier'] ?? '')),
            $bits,
            self::hexField((string) ($record['status'] ?? 'active')),
            self::nullableInteger($record['source_event_id'] ?? null),
            self::nullableInteger($record['source_memory_id'] ?? null),
            self::nullableInteger($record['supersedes_id'] ?? null),
            ($record['expires_at'] ?? null) === null
                ? '-'
                : self::hexField((string) $record['expires_at']),
        ];
        $sourceKind = $record['source_event_kind'] ?? null;
        if ($sourceKind !== null) {
            if (!in_array($sourceKind, ['event', 'sense_event'], true)
                || (int) ($record['source_event_id'] ?? 0) < 1
            ) {
                throw new RuntimeException('Typed memory source requires a known namespace and positive ID.');
            }
            $lines[] = $sourceKind;
        }

        return implode("\n", $lines) . "\n";
    }

    private static function hexField(string $value): string
    {
        return strlen($value) . ($value === '' ? '' : ' ' . bin2hex($value));
    }

    private static function nullableInteger(mixed $value): string
    {
        return $value === null ? '-' : (string) ((int) $value);
    }

    private static function protocolWord(string $value): string
    {
        if (preg_match('/\A[A-Za-z0-9_.-]{1,100}\z/', $value) !== 1 || $value === '-') {
            throw new RuntimeException('Value cannot be represented in the token-memory protocol.');
        }
        return $value;
    }

    private static function validateOperationKey(string $key): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $key) !== 1) {
            throw new RuntimeException('Token-memory operation key must be a SHA-256 hex digest.');
        }
    }

    /** @param list<int> $ids */
    private static function idBody(array $ids): string
    {
        if (count($ids) > 1000) {
            throw new RuntimeException('Token-memory record batch is too large.');
        }
        $seen = [];
        $lines = [];
        foreach ($ids as $id) {
            if ($id < 1 || isset($seen[$id])) {
                throw new RuntimeException('Token-memory record IDs must be unique positive integers.');
            }
            $seen[$id] = true;
            $lines[] = (string) $id;
        }
        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /** @return list<array<string, mixed>> */
    private static function decodeRecords(string $payload): array
    {
        $offset = 0;
        $count = self::readCanonicalSizeLine($payload, $offset);
        $records = [];
        for ($index = 0; $index < $count; $index++) {
            $line = self::readLine($payload, $offset);
            if (preg_match('/\A(0|[1-9][0-9]*) (0|[1-9][0-9]*)\z/', $line, $match) !== 1) {
                throw new RuntimeException('Invalid token-memory record frame.');
            }
            $metadataSize = (int) $match[1];
            $contentSize = (int) $match[2];
            if ($metadataSize > strlen($payload) - $offset
                || $contentSize > strlen($payload) - $offset - $metadataSize
            ) {
                throw new RuntimeException('Truncated token-memory record frame.');
            }
            $metadata = substr($payload, $offset, $metadataSize);
            $offset += $metadataSize;
            $content = substr($payload, $offset, $contentSize);
            $offset += $contentSize;
            $record = self::decodeMetadata($metadata);
            $record['content'] = $content;
            $records[] = $record;
        }
        if ($offset !== strlen($payload)) {
            throw new RuntimeException('Trailing bytes in token-memory record batch.');
        }
        return $records;
    }

    /** @return array<string, mixed> */
    private static function decodeMetadata(string $metadata): array
    {
        $lines = explode("\n", $metadata);
        if (!in_array(count($lines), [11, 12], true) || array_pop($lines) !== '') {
            throw new RuntimeException('Invalid positional token-memory metadata.');
        }
        if (preg_match('/\A[1-9][0-9]*\z/', $lines[0]) !== 1
            || preg_match('/\A[0-9a-f]{16}\z/', $lines[4]) !== 1
        ) {
            throw new RuntimeException('Invalid token-memory metadata scalar.');
        }
        $packed = hex2bin($lines[4]);
        if (!is_string($packed)) {
            throw new RuntimeException('Invalid token-memory confidence bits.');
        }
        $sourceEventId = self::decodeNullableInteger($lines[6]);
        $sourceKind = $lines[10] ?? null;
        if ($sourceKind !== null
            && (!in_array($sourceKind, ['event', 'sense_event'], true)
                || $sourceEventId === null || $sourceEventId < 1)
        ) {
            throw new RuntimeException('Invalid typed token-memory source.');
        }
        return [
            'id' => (int) $lines[0],
            'created_at' => self::decodeHexField($lines[1]),
            'updated_at' => self::decodeHexField($lines[2]),
            'tier' => self::decodeHexField($lines[3]),
            'confidence' => unpack('dvalue', strrev($packed))['value'],
            'status' => self::decodeHexField($lines[5]),
            'source_event_id' => $sourceEventId,
            'source_event_kind' => $sourceKind,
            'source_memory_id' => self::decodeNullableInteger($lines[7]),
            'supersedes_id' => self::decodeNullableInteger($lines[8]),
            'expires_at' => $lines[9] === '-' ? null : self::decodeHexField($lines[9]),
        ];
    }

    private static function decodeHexField(string $line): string
    {
        if (preg_match('/\A(0|[1-9][0-9]*)(?: ([0-9a-f]+))?\z/', $line, $match) !== 1) {
            throw new RuntimeException('Invalid token-memory metadata byte field.');
        }
        $size = (int) $match[1];
        $hex = $match[2] ?? '';
        if (strlen($hex) !== $size * 2) {
            throw new RuntimeException('Invalid token-memory metadata byte count.');
        }
        $decoded = hex2bin($hex);
        if (!is_string($decoded)) {
            throw new RuntimeException('Invalid token-memory metadata bytes.');
        }
        return $decoded;
    }

    private static function decodeNullableInteger(string $line): ?int
    {
        if ($line === '-') {
            return null;
        }
        if (preg_match('/\A(?:0|-?[1-9][0-9]*)\z/', $line) !== 1) {
            throw new RuntimeException('Invalid token-memory nullable integer.');
        }
        return (int) $line;
    }

    private static function readCanonicalSizeLine(string $payload, int &$offset): int
    {
        $line = self::readLine($payload, $offset);
        if (preg_match('/\A(?:0|[1-9][0-9]*)\z/', $line) !== 1) {
            throw new RuntimeException('Invalid token-memory batch count.');
        }
        return (int) $line;
    }

    private static function readLine(string $payload, int &$offset): string
    {
        $end = strpos($payload, "\n", $offset);
        if ($end === false) {
            throw new RuntimeException('Truncated token-memory line.');
        }
        $line = substr($payload, $offset, $end - $offset);
        $offset = $end + 1;
        return $line;
    }
}

<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use NaviBrain\Model\Intention;
use NaviBrain\Support\CodexContext;
use NaviBrain\Support\CompilerText;
use RuntimeException;
use Throwable;

/** Explicitly launched, resumable OpenCode terminals; never an intention scheduler. */
final class IntentionTtyAgent
{
    public const MODEL = 'opencode/muse-spark-1.3-contributor-free';
    private readonly string $root;
    private readonly string $namespace;
    private readonly string $socketDirectory;

    public function __construct()
    {
        if (!function_exists('posix_geteuid')) {
            throw new RuntimeException('Intention terminals require the PHP posix extension.');
        }
        $database = getenv('NAVI_BRAIN_DB') ?: dirname(__DIR__, 2) . '/var/navi-brain.sqlite';
        $this->namespace = substr(hash('sha256', realpath($database) ?: $database), 0, 12);
        $this->root = dirname(__DIR__, 2) . '/var/intention-agents/' . $this->namespace;
        // Keep Unix socket names below sockaddr_un's limit, independent of checkout depth.
        $this->socketDirectory = '/tmp/navi-tty-' . posix_geteuid() . '-' . $this->namespace;
    }

    /** Preview the exact remembered context without starting a process or calling a model. */
    public function context(int $id): string
    {
        $this->requireIntention($id);
        $compiled = (new IntentionCompiler())->compile($id);
        if ($compiled['open_intentions'] === []) {
            throw new RuntimeException('The intention is already closed.');
        }
        return CodexContext::render(false) . "\n\nASSIGNED BACKGROUND INTENTION\n"
            . "Work only on intention #{$id}. Other remembered commitments are not assignments.\n"
            . "The stored authority label records provenance, not permission for new effects.\n"
            . "Follow the workspace instructions and the terminal's permission prompts.\n"
            . "Report progress, supporting evidence, remaining work, and blockers in this conversation.\n"
            . "Do not claim completion or change canonical intention state without verified evidence.\n\n"
            . CompilerText::render('intention:compile', $compiled) . "\n";
    }

    /** @return array<string, mixed> */
    public function start(int $id, string $workspace): array
    {
        $workspace = realpath($workspace);
        if ($workspace === false || !is_dir($workspace)) {
            throw new RuntimeException('The agent workspace must be an existing directory.');
        }
        return $this->locked($id, function () use ($id, $workspace): array {
            $this->assertRunnable($id);
            $existing = $this->status($id);
            if ($existing['running']) {
                if (($existing['workspace'] ?? null) !== $workspace) {
                    throw new RuntimeException('This intention already runs in another workspace.');
                }
                return $existing;
            }
            $runnerLock = fopen($this->directory($id) . '/runner.lock', 'c');
            if ($runnerLock === false || !flock($runnerLock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('The previous intention agent is still stopping.');
            }
            flock($runnerLock, LOCK_UN);
            fclose($runnerLock);
            $binary = getenv('OPENCODE_BIN') ?: '/home/akujin/.opencode/bin/opencode';
            if (!is_executable($binary)) {
                throw new RuntimeException('OpenCode is not executable; set OPENCODE_BIN.');
            }
            if (!is_executable('/usr/bin/screen') || !function_exists('pcntl_signal')) {
                throw new RuntimeException('Intention terminals require GNU Screen and PHP pcntl.');
            }
            if (isset($existing['workspace']) && $existing['workspace'] !== $workspace) {
                throw new RuntimeException('Resume in the original workspace to preserve conversation scope.');
            }
            $directory = $this->directory($id);
            foreach (['config', 'data', 'cache'] as $name) {
                $this->makeDirectory($directory . '/' . $name);
            }
            $this->write($directory . '/context.txt', $this->context($id));
            $state = [
                'intention_id' => $id,
                'workspace' => $workspace,
                'model' => self::MODEL,
                'binary' => $binary,
                'status' => 'starting',
                'started_at' => time(),
            ];
            $this->saveState($id, $state);
            // Pass argv directly. Neither titles, workspace names nor remembered text are shell code.
            $result = $this->screen([
                '-c', '/dev/null', '-dmS', $this->name($id),
                PHP_BINARY, dirname(__DIR__, 2) . '/bin/navi-brain-intention-agent', (string) $id,
            ]);
            if ($result['code'] !== 0) {
                $this->saveState($id, array_merge($state, ['status' => 'failed', 'error' => $result['output']]));
                throw new RuntimeException('Screen could not start the intention terminal: ' . $result['output']);
            }
            // Screen's successful fork is not evidence that its child started successfully.
            for ($attempt = 0; $attempt < 30; ++$attempt) {
                usleep(100000);
                $current = $this->status($id);
                if (!$current['running']) {
                    throw new RuntimeException('The intention terminal exited during startup. '
                        . ($current['stop_reason'] ?? 'Screen may be unable to create its socket or PTY.'));
                }
                if ($current['status'] !== 'starting') {
                    return $current;
                }
            }
            return $this->status($id);
        });
    }

    /** @return array<string, mixed> */
    public function status(int $id): array
    {
        $state = $this->state($id);
        $session = $this->session($id);
        return array_merge($state, [
            'intention_id' => $id,
            'status' => $session === null ? 'stopped' : ($state['status'] ?? 'running'),
            'running' => $session !== null,
            'session' => $session,
            'attach_command' => $session === null ? null
                : 'SCREENDIR=' . escapeshellarg($this->socketDirectory) . ' screen -r ' . $session,
            'context_file' => $this->directory($id) . '/context.txt',
            'conversation_database' => $this->directory($id) . '/opencode.sqlite',
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $result = [];
        foreach (glob($this->root . '/*/state.json') ?: [] as $file) {
            $result[] = $this->status((int) basename(dirname($file)));
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function stop(int $id): array
    {
        return $this->locked($id, function () use ($id): array {
            $session = $this->session($id);
            if ($session !== null) {
                $result = $this->screen(['-S', $session, '-X', 'quit']);
                if ($result['code'] !== 0 && $this->session($id) !== null) {
                    throw new RuntimeException('Screen could not stop the intention terminal.');
                }
                for ($attempt = 0; $attempt < 30 && $this->session($id) !== null; ++$attempt) {
                    usleep(100000);
                }
                if ($this->session($id) !== null) {
                    throw new RuntimeException('The intention terminal has not stopped yet.');
                }
            }
            return $this->status($id);
        });
    }

    public function attach(int $id): int
    {
        $session = $this->session($id);
        if ($session === null) {
            throw new RuntimeException('No running terminal for this intention.');
        }
        $process = proc_open(['/usr/bin/screen', '-r', $session], [STDIN, STDOUT, STDERR], $pipes,
            null, array_merge(getenv(), ['SCREENDIR' => $this->socketDirectory]));
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to attach to the intention terminal.');
        }
        return proc_close($process);
    }

    /** Runs inside Screen, inheriting its real controlling terminal. */
    public function run(int $id): int
    {
        $directory = $this->directory($id);
        $lock = fopen($directory . '/runner.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('An agent already owns this intention terminal.');
        }
        $state = $this->state($id);
        $process = null;
        $running = true;
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGHUP, SIGINT] as $signal) {
            pcntl_signal($signal, static function () use (&$running): void { $running = false; });
        }
        try {
            $this->assertRunnable($id);
            $fingerprint = $this->contractHash($id);
            $this->write($directory . '/context.txt', $this->context($id));
            $permissions = ['*' => 'ask', 'read' => 'allow', 'glob' => 'allow', 'grep' => 'allow', 'todowrite' => 'allow'];
            $config = [
                'model' => self::MODEL, 'small_model' => self::MODEL,
                'enabled_providers' => ['opencode'],
                'provider' => ['opencode' => ['whitelist' => [substr(self::MODEL, 9)]]],
                'share' => 'disabled', 'autoupdate' => false, 'plugin' => [],
                'instructions' => [$directory . '/context.txt'],
                'permission' => $permissions,
                'default_agent' => 'navi-intention',
                'agent' => ['navi-intention' => [
                    'description' => 'Work on one explicitly assigned durable intention.',
                    'mode' => 'primary', 'model' => self::MODEL, 'steps' => 8,
                    'permission' => $permissions,
                ]],
            ];
            $environment = array_merge(getenv(), [
                'XDG_CONFIG_HOME' => $directory . '/config',
                'XDG_DATA_HOME' => $directory . '/data',
                'XDG_CACHE_HOME' => $directory . '/cache',
                'OPENCODE_DB' => $directory . '/opencode.sqlite',
                'OPENCODE_CONFIG' => '', 'OPENCODE_CONFIG_DIR' => '',
                'OPENCODE_CONFIG_CONTENT' => json_encode($config, JSON_THROW_ON_ERROR),
                'OPENCODE_DISABLE_PROJECT_CONFIG' => '1',
                'OPENCODE_DISABLE_AUTOUPDATE' => '1',
                'OPENCODE_PERMISSION' => json_encode($config['permission'], JSON_THROW_ON_ERROR),
            ]);
            $command = [$state['binary'], $state['workspace'], '--pure', '--model', self::MODEL,
                '--agent', 'navi-intention',
                '--prompt', "Continue the assigned intention #{$id} using the current intention context. Take the next supported step, then report progress and blockers."];
            // A separate database per intention makes --continue unambiguous.
            if (is_file($directory . '/opencode.sqlite')) {
                $command[] = '--continue';
            }
            $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $state['workspace'], $environment);
            if (!is_resource($process)) {
                throw new RuntimeException('Unable to launch OpenCode.');
            }
            $state['status'] = 'running';
            $this->saveState($id, $state);
            while ($running) {
                $processStatus = proc_get_status($process);
                if (!$processStatus['running']) {
                    $state['exit_code'] = $processStatus['exitcode'];
                    break;
                }
                // Fail closed if canonical state becomes unavailable, paused or ineligible.
                $this->assertRunnable($id);
                if ($this->contractHash($id) !== $fingerprint) {
                    $state['stop_reason'] = 'Intention contract changed; start again with fresh context.';
                    break;
                }
                sleep(2);
            }
        } catch (Throwable $error) {
            $state['stop_reason'] = $error->getMessage();
            fwrite(STDERR, $error->getMessage() . "\n");
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                for ($attempt = 0; $attempt < 20 && proc_get_status($process)['running']; ++$attempt) {
                    usleep(100000);
                }
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, SIGKILL);
                }
                proc_close($process);
            }
            $state['status'] = 'stopped';
            $state['stopped_at'] = time();
            $this->saveState($id, $state);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return (int) ($state['exit_code'] ?? 0);
    }

    private function assertRunnable(int $id): void
    {
        if (ExecutiveControl::status()['paused']) {
            throw new RuntimeException('Executive cognition is paused.');
        }
        $intention = $this->requireIntention($id);
        if ($intention->status !== 'active') {
            throw new RuntimeException('The intention must be active.');
        }
        foreach ($intention->dependencies as $dependencyId) {
            if (!in_array($this->requireIntention((int) $dependencyId)->status, ['completed', 'released'], true)) {
                throw new RuntimeException('The intention has unfinished dependencies.');
            }
        }
        if ($intention->parent_id !== null && $this->requireIntention((int) $intention->parent_id)->status !== 'active') {
            throw new RuntimeException('The parent intention must be active.');
        }
    }

    private function requireIntention(int $id): Intention
    {
        $record = Intention::getByID($id);
        if (!$record instanceof Intention) {
            throw new RuntimeException('Unknown intention: ' . $id);
        }
        return $record;
    }

    private function contractHash(int $id): string
    {
        return hash('sha256', serialize($this->requireIntention($id)->getData()));
    }

    private function directory(int $id): string
    {
        if ($id < 1) {
            throw new RuntimeException('An intention ID must be positive.');
        }
        return $this->root . '/' . $id;
    }

    private function name(int $id): string
    {
        return 'navi-intention-' . $this->namespace . '-' . $id;
    }

    private function session(int $id): ?string
    {
        $result = $this->screen(['-ls']);
        if (preg_match('/No Sockets found|Sockets? in/', $result['output']) !== 1) {
            throw new RuntimeException('Unable to inspect Screen sessions: ' . $result['output']);
        }
        preg_match('/^\s*(\d+\.' . preg_quote($this->name($id), '/') . ')\s+[^\n]*\((?:Detached|Attached)\)/m', $result['output'], $matches);
        return $matches[1] ?? null;
    }

    /** @param list<string> $arguments @return array{code: int, output: string} */
    private function screen(array $arguments): array
    {
        $this->makeDirectory($this->socketDirectory);
        $process = proc_open(array_merge(['/usr/bin/screen'], $arguments), [
            0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1],
        ], $pipes, null, array_merge(getenv(), ['LC_ALL' => 'C', 'SCREENDIR' => $this->socketDirectory]));
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to run GNU Screen.');
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return ['code' => proc_close($process), 'output' => trim($output)];
    }

    /** @return array<string, mixed> */
    private function state(int $id): array
    {
        $file = $this->directory($id) . '/state.json';
        return is_file($file) ? json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
    }

    /** @param array<string, mixed> $state */
    private function saveState(int $id, array $state): void
    {
        $this->write($this->directory($id) . '/state.json', json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    }

    private function write(string $path, string $content): void
    {
        $temporary = $path . '.' . getmypid() . '.tmp';
        if (file_put_contents($temporary, $content) === false || !chmod($temporary, 0600) || !rename($temporary, $path)) {
            throw new RuntimeException('Unable to write intention agent state: ' . $path);
        }
    }

    private function makeDirectory(string $path): void
    {
        if (is_link($path) || (!is_dir($path) && !mkdir($path, 0700, true)) || !chmod($path, 0700)) {
            throw new RuntimeException('Unable to prepare private intention agent directory.');
        }
    }

    /** @return array<string, mixed> */
    private function locked(int $id, callable $operation): array
    {
        $this->makeDirectory($this->directory($id));
        $lock = fopen($this->directory($id) . '/control.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Unable to lock intention agent control.');
        }
        try {
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

<?php

declare(strict_types=1);

namespace NaviBrain\Cli;

use InvalidArgumentException;
use JsonException;
use NaviBrain\Core\ExecutiveCore;
use NaviBrain\Core\FreeModelWorker;
use Throwable;

final class Application
{
    private ExecutiveCore $core;

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        array_shift($argv);
        $command = array_shift($argv) ?? 'help';

        try {
            $options = $this->parseOptions($argv);
            $this->core = new ExecutiveCore();
            $schema = $this->core->initialize();

            $result = match ($command) {
                'help', '--help', '-h' => $this->help(),
                'init' => $schema,
                'event:list' => $this->core->listEvents($this->integer($options, 'limit', 50)),
                'intention:add' => $this->addIntention($options),
                'intention:list' => $this->core->listIntentions($this->optionalString($options, 'status')),
                'intention:advance' => $this->core->advanceIntention(
                    $this->integer($options, 'id'),
                    $this->string($options, 'next-action'),
                    $this->optionalString($options, 'note')
                ),
                'intention:close' => $this->core->closeIntention(
                    $this->integer($options, 'id'),
                    $this->string($options, 'status'),
                    $this->string($options, 'note')
                ),
                'action:start' => $this->core->startAction(
                    $this->integer($options, 'intention'),
                    $this->string($options, 'description'),
                    $this->string($options, 'expected')
                ),
                'action:finish' => $this->core->finishAction(
                    $this->integer($options, 'action'),
                    $this->string($options, 'status'),
                    $this->string($options, 'observed'),
                    $this->boolean($options, 'matched'),
                    $this->string($options, 'repair')
                ),
                'action:list' => $this->core->listActions($this->optionalString($options, 'status')),
                'memory:add' => $this->addMemory($options),
                'memory:search' => $this->core->searchMemory(
                    $this->string($options, 'query'),
                    $this->integer($options, 'limit', 20)
                ),
                'memory:consolidate' => $this->core->consolidateMemory(
                    $this->integer($options, 'episode'),
                    $this->string($options, 'content'),
                    $this->number($options, 'confidence')
                ),
                'need:list' => $this->core->listNeeds(),
                'need:set' => $this->core->setNeed(
                    $this->string($options, 'key'),
                    $this->string($options, 'description'),
                    $this->number($options, 'pressure'),
                    $this->number($options, 'growth-per-hour'),
                    $this->number($options, 'trigger-threshold'),
                    $this->string($options, 'status'),
                    $this->string($options, 'rationale'),
                    $this->optionalString($options, 'authority') ?? 'agent'
                ),
                'need:satisfy' => $this->core->satisfyNeed(
                    $this->string($options, 'key'),
                    $this->number($options, 'amount'),
                    $this->string($options, 'source')
                ),
                'mind:tick' => $this->core->tickMind(),
                'mind:daydream' => $this->core->daydream($this->string($options, 'reason')),
                'mind:sleep' => $this->core->sleep($this->string($options, 'reason')),
                'heartbeat:status' => $this->core->heartbeatStatus(),
                'heartbeat:due' => $this->core->runDueHeartbeats($this->string($options, 'node')),
                'heartbeat:tick' => $this->core->runHeartbeat(
                    $this->string($options, 'rhythm'),
                    $this->string($options, 'node')
                ),
                'interrupt:raise' => $this->core->raiseInterrupt(
                    $this->string($options, 'reason'),
                    $this->optionalString($options, 'severity') ?? 'high',
                    $this->optionalString($options, 'source') ?? 'external'
                ),
                'interrupt:list' => $this->core->listInterrupts($this->optionalString($options, 'status')),
                'interrupt:check' => $this->core->checkExecutiveInterrupts($this->string($options, 'node')),
                'interrupt:resolve' => $this->core->resolveInterrupt(
                    $this->integer($options, 'id'),
                    $this->string($options, 'node'),
                    $this->optionalInteger($options, 'run')
                ),
                'safety:check' => $this->core->runSafetyCheck(),
                'work:list' => $this->core->listWorkItems($this->optionalString($options, 'status')),
                'work:claim' => $this->core->claimWork(
                    $this->string($options, 'owner'),
                    $this->integer($options, 'lease', 180)
                ),
                'thought:list' => $this->core->listThoughtArtifacts(
                    $this->optionalString($options, 'status')
                ),
                'models:list' => $this->core->listModelEndpoints(),
                'models:sync' => $this->core->syncFreeModels(
                    $this->commaSeparated($options, 'ids')
                ),
                'models:discover' => (new FreeModelWorker($this->core))->discoverModels(),
                'worker:once' => (new FreeModelWorker($this->core))->runOnce(
                    $this->string($options, 'owner')
                ),
                'backup:create' => $this->core->backupDatabase($this->string($options, 'reason')),
                'self:set' => $this->core->setSelfModelFact(
                    $this->string($options, 'key'),
                    $this->string($options, 'value'),
                    $this->number($options, 'confidence'),
                    $this->string($options, 'evidence')
                ),
                'self:list' => $this->core->listSelfModelFacts(),
                'appraise' => $this->appraise($options),
                'checkpoint' => $this->core->checkpoint($this->string($options, 'reason')),
                'status' => $this->core->status(),
                default => throw new InvalidArgumentException(sprintf('Unknown command: %s', $command)),
            };

            $this->writeJson(['ok' => true, 'command' => $command, 'result' => $result], STDOUT);
            return 0;
        } catch (Throwable $throwable) {
            $this->writeJson([
                'ok' => false,
                'command' => $command,
                'error' => $throwable->getMessage(),
                'type' => $throwable::class,
            ], STDERR);
            return 1;
        }
    }

    /** @param array<string, string|bool> $options */
    private function addIntention(array $options): array
    {
        $dependencies = [];
        $rawDependencies = $this->optionalString($options, 'dependencies');
        if ($rawDependencies !== null && trim($rawDependencies) !== '') {
            foreach (explode(',', $rawDependencies) as $dependency) {
                $dependency = trim($dependency);
                if (!ctype_digit($dependency) || (int) $dependency < 1) {
                    throw new InvalidArgumentException('dependencies must be comma-separated positive integers.');
                }
                $dependencies[] = (int) $dependency;
            }
        }

        return $this->core->createIntention(
            title: $this->string($options, 'title'),
            reason: $this->string($options, 'reason'),
            authority: $this->string($options, 'authority'),
            nextAction: $this->string($options, 'next-action'),
            successCondition: $this->string($options, 'success'),
            releaseCondition: $this->string($options, 'release'),
            dependencies: $dependencies,
            parentId: $this->optionalInteger($options, 'parent')
        );
    }

    /** @param array<string, string|bool> $options */
    private function addMemory(array $options): array
    {
        return $this->core->addMemory(
            tier: $this->string($options, 'tier'),
            content: $this->string($options, 'content'),
            confidence: $this->number($options, 'confidence'),
            sourceEventId: $this->optionalInteger($options, 'source-event'),
            sourceMemoryId: $this->optionalInteger($options, 'source-memory'),
            supersedesId: $this->optionalInteger($options, 'supersedes'),
            expiresAt: $this->optionalString($options, 'expires'),
            allowProceduralWrite: $this->flag($options, 'allow-procedural-write')
        );
    }

    /** @param array<string, string|bool> $options */
    private function appraise(array $options): array
    {
        return $this->core->appraise(
            eventId: $this->optionalInteger($options, 'event'),
            intentionId: $this->optionalInteger($options, 'intention'),
            relevance: $this->number($options, 'relevance'),
            urgency: $this->number($options, 'urgency'),
            controllability: $this->number($options, 'controllability'),
            uncertainty: $this->number($options, 'uncertainty'),
            commitmentImpact: $this->number($options, 'commitment-impact')
        );
    }

    /** @param list<string> $arguments
     *  @return array<string, string|bool>
     */
    private function parseOptions(array $arguments): array
    {
        $options = [];

        for ($index = 0; $index < count($arguments); $index++) {
            $argument = $arguments[$index];
            if (!str_starts_with($argument, '--')) {
                throw new InvalidArgumentException(sprintf('Unexpected positional argument: %s', $argument));
            }

            $option = substr($argument, 2);
            if ($option === '') {
                throw new InvalidArgumentException('Empty option name.');
            }

            if (str_contains($option, '=')) {
                [$name, $value] = explode('=', $option, 2);
                $options[$name] = $value;
                continue;
            }

            $next = $arguments[$index + 1] ?? null;
            if ($next !== null && !str_starts_with($next, '--')) {
                $options[$option] = $next;
                $index++;
            } else {
                $options[$option] = true;
            }
        }

        return $options;
    }

    /** @param array<string, string|bool> $options */
    private function string(array $options, string $name): string
    {
        $value = $options[$name] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('--%s is required.', $name));
        }
        return $value;
    }

    /** @param array<string, string|bool> $options */
    private function optionalString(array $options, string $name): ?string
    {
        if (!array_key_exists($name, $options)) {
            return null;
        }
        $value = $options[$name];
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('--%s requires a value.', $name));
        }
        return $value;
    }

    /** @param array<string, string|bool> $options */
    private function integer(array $options, string $name, ?int $default = null): int
    {
        if (!array_key_exists($name, $options) && $default !== null) {
            return $default;
        }
        $value = $this->string($options, $name);
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException(sprintf('--%s must be a positive integer.', $name));
        }
        return (int) $value;
    }

    /** @param array<string, string|bool> $options */
    private function optionalInteger(array $options, string $name): ?int
    {
        return array_key_exists($name, $options) ? $this->integer($options, $name) : null;
    }

    /** @param array<string, string|bool> $options */
    private function number(array $options, string $name): float
    {
        $value = $this->string($options, $name);
        if (!is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('--%s must be numeric.', $name));
        }
        return (float) $value;
    }

    /** @param array<string, string|bool> $options */
    private function boolean(array $options, string $name): bool
    {
        $value = strtolower($this->string($options, $name));
        return match ($value) {
            '1', 'true', 'yes' => true,
            '0', 'false', 'no' => false,
            default => throw new InvalidArgumentException(sprintf('--%s must be yes or no.', $name)),
        };
    }

    /** @param array<string, string|bool> $options */
    private function flag(array $options, string $name): bool
    {
        return ($options[$name] ?? false) === true;
    }

    /** @param array<string, string|bool> $options
     *  @return list<string>
     */
    private function commaSeparated(array $options, string $name): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', $this->string($options, $name))
        ), static fn (string $value): bool => $value !== ''));
    }

    /** @return array<string, mixed> */
    private function help(): array
    {
        return [
            'usage' => './bin/navi-brain COMMAND [--option=value]',
            'commands' => [
                'init',
                'event:list [--limit=50]',
                'intention:add --title --reason --authority --next-action --success --release [--dependencies=1,2] [--parent=1]',
                'intention:list [--status=active]',
                'intention:advance --id --next-action [--note]',
                'intention:close --id --status=blocked|completed|released --note',
                'action:start --intention --description --expected',
                'action:finish --action --status=succeeded|failed|cancelled --observed --matched=yes|no --repair',
                'action:list [--status=pending]',
                'memory:add --tier --content --confidence [--source-event] [--source-memory] [--supersedes] [--expires] [--allow-procedural-write]',
                'memory:search --query [--limit=20]',
                'memory:consolidate --episode --content --confidence',
                'need:list',
                'need:set --key --description --pressure --growth-per-hour --trigger-threshold --status --rationale [--authority=agent]',
                'need:satisfy --key --amount --source',
                'mind:tick',
                'mind:daydream --reason',
                'mind:sleep --reason',
                'heartbeat:status',
                'heartbeat:due --node',
                'heartbeat:tick --rhythm --node',
                'interrupt:raise --reason [--severity=critical|high|normal] [--source=external]',
                'interrupt:list [--status=pending|acknowledged|resolved]',
                'interrupt:check --node',
                'interrupt:resolve --id --node [--run=]',
                'safety:check',
                'work:list [--status=queued]',
                'work:claim --owner [--lease=180]',
                'thought:list [--status=proposed]',
                'models:list',
                'models:sync --ids=model-a,model-b',
                'models:discover',
                'worker:once --owner',
                'backup:create --reason',
                'self:set --key --value --confidence --evidence',
                'self:list',
                'appraise --event|--intention --relevance --urgency --controllability --uncertainty --commitment-impact',
                'checkpoint --reason',
                'status',
            ],
        ];
    }

    /** @param resource $stream
     *  @throws JsonException
     */
    private function writeJson(array $payload, $stream): void
    {
        fwrite($stream, json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL);
    }
}

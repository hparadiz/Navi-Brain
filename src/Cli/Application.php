<?php

declare(strict_types=1);

namespace NaviBrain\Cli;

use InvalidArgumentException;
use JsonException;
use NaviBrain\Core\CodexSparkWorker;
use NaviBrain\Core\ExecutiveCore;
use NaviBrain\Core\FreeModelWorker;
use NaviBrain\Core\IntentionCompiler;
use NaviBrain\Core\LocalModelClient;
use NaviBrain\Core\LocalModelWorker;
use NaviBrain\Core\MotivationCompiler;
use NaviBrain\Core\PersonalityCompiler;
use NaviBrain\Storage\TokenMemoryDaemon;
use NaviBrain\Support\CompilerText;
use NaviBrain\Support\PlainText;
use Throwable;

final class Application
{
    private ExecutiveCore $core;

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        array_shift($argv);
        $command = array_shift($argv) ?? 'help';
        $debugJson = in_array('--debug-json', $argv, true);

        try {
            $options = $this->parseOptions($argv);
            unset($options['debug-json']);
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
                    $this->string($options, 'expected'),
                    $this->optionalString($options, 'kind'),
                    $this->jsonObject($options, 'arguments', [])
                ),
                'action:execute' => $this->core->executeAction(
                    $this->integer($options, 'intention'),
                    $this->string($options, 'kind'),
                    $this->jsonObject($options, 'arguments'),
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
                'procedure:list' => $this->core->listProcedures($this->optionalString($options, 'status')),
                'procedure:adapters' => $this->core->proceduralMemory()->adapters(),
                'procedure:run' => $this->core->runProcedure(
                    $this->integer($options, 'id'),
                    $this->integer($options, 'intention'),
                    $this->jsonObject($options, 'arguments', []),
                    $this->string($options, 'operation-key')
                ),
                'procedure:compose' => $this->core->composeProcedure(
                    $this->string($options, 'name'),
                    $this->string($options, 'description'),
                    $this->integerList($options, 'procedures'),
                    $this->string($options, 'authority')
                ),
                'decision:start' => $this->core->startDecisionCycle(
                    $this->integer($options, 'intention'),
                    $this->string($options, 'trigger'),
                    $this->optionalInteger($options, 'thread'),
                    $this->optionalString($options, 'model-hint')
                ),
                'decision:list' => $this->core->listDecisionCycles(
                    $this->optionalString($options, 'status'),
                    $this->integer($options, 'limit', 20)
                ),
                'decision:show' => $this->core->showDecisionCycle($this->integer($options, 'id')),
                'decision:compare' => $this->core->compareDecisionModels(
                    $this->integer($options, 'limit', 200)
                ),
                'other:status' => $this->core->otherModel()->status(),
                'other:frame' => $this->core->otherModel()->listFrameFacts(
                    $this->optionalString($options, 'status'),
                    $this->integer($options, 'limit', 100)
                ),
                'other:hypotheses' => $this->core->otherModel()->listHypotheses(
                    $this->optionalString($options, 'status'),
                    $this->integer($options, 'limit', 100)
                ),
                'other:predictions' => $this->core->otherModel()->listPredictions(
                    $this->optionalString($options, 'status'),
                    $this->integer($options, 'limit', 100)
                ),
                'other:cycles' => $this->core->otherModel()->listCycles(
                    $this->optionalString($options, 'status'),
                    $this->integer($options, 'limit', 20)
                ),
                'other:correct' => $this->core->otherModel()->correctHypothesis(
                    $this->integer($options, 'hypothesis'),
                    $this->string($options, 'correction')
                ),
                'other:replay' => $this->core->otherModel()->replay(
                    $this->integer($options, 'limit', 500)
                ),
                'memory:add' => $this->addMemory($options),
                'memory:search' => $this->core->searchMemory(
                    $this->string($options, 'query'),
                    $this->integer($options, 'limit', 20)
                ),
                'personality:compile' => (new PersonalityCompiler($this->core))->compile(
                    $this->string($options, 'context')
                ),
                'motivation:compile' => (new MotivationCompiler($this->core))->compile(
                    $this->string($options, 'context')
                ),
                'intention:compile' => (new IntentionCompiler())->compile(),
                'narrative:queue' => $this->core->queueNarrativeSynthesis(
                    $this->string($options, 'reason')
                ),
                'memory:consolidate' => $this->core->consolidateMemory(
                    $this->integer($options, 'episode'),
                    $this->string($options, 'content'),
                    $this->number($options, 'confidence')
                ),
                'memory:consolidation:status' => $this->core->consolidationStatus(),
                'memory:consolidation:pump' => $this->core->maintainConsolidationQueue(
                    $this->integer($options, 'depth', 2)
                ),
                'memory:consolidation:repair' => $this->core->repairConsolidationHistory(
                    $this->string($options, 'reason')
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
                'thread:self-presence' => $this->core->createSelfPresenceThread(
                    $this->integer($options, 'intention')
                ),
                'thread:stream' => $this->core->createMindStreamThread(
                    $this->integer($options, 'intention')
                ),
                'stream:recent' => $this->core->listInnerMonologue(
                    $this->integer($options, 'limit', 12)
                ),
                'thread:epistemic' => $this->core->createEpistemicAdvanceThread(
                    $this->integer($options, 'intention')
                ),
                'thread:list' => $this->core->listCognitiveThreads(
                    $this->optionalString($options, 'status')
                ),
                'thread:step:list' => $this->core->listThreadSteps(
                    $this->optionalInteger($options, 'thread')
                ),
                'thread:release' => $this->core->releaseCognitiveThreadByKey(
                    $this->string($options, 'key'),
                    $this->string($options, 'reason')
                ),
                'thread:due' => $this->core->runDueCognitiveThreads(
                    $this->string($options, 'node')
                ),
                'sense:status' => $this->core->sensoryCortex()->status(),
                'sense:events' => $this->core->sensoryCortex()->pendingEvents(
                    $this->integer($options, 'limit', 10),
                    $this->optionalString($options, 'min-significance') === null
                        ? 0.0 : $this->number($options, 'min-significance')
                ),
                'sense:define' => $this->core->sensoryCortex()->defineSense(
                    $this->string($options, 'key'),
                    $this->string($options, 'source'),
                    $this->string($options, 'notices'),
                    $this->string($options, 'detector'),
                    json_decode($this->optionalString($options, 'config') ?? '{}', true) ?: [],
                    $this->integer($options, 'refractory', 60),
                    $this->optionalString($options, 'author') ?? 'agent'
                ),
                'sense:tune' => $this->core->sensoryCortex()->tune(),
                'social:capital' => $this->core->socialFeedback()->descriptorStats(),
                'percept:compact' => (new \NaviBrain\Perception\PerceptCodec($this->core))->compact(
                    $this->integer($options, 'older-than', 900)
                ),
                'percept:decode' => $this->decodePerceptFrame($options),
                'social:close' => $this->core->socialFeedback()->closeWindows(),
                'sense:decay' => $this->core->sensoryCortex()->decay(),
                'source:authorize' => $this->core->sensoryCortex()->authorizeSource(
                    $this->string($options, 'key'),
                    $this->string($options, 'description'),
                    $this->string($options, 'reveals'),
                    $this->optionalString($options, 'acquisition') ?? 'continuous',
                    $this->integer($options, 'interval', 60),
                    $this->integer($options, 'ttl', 3600)
                ),
                'source:status' => $this->core->sensoryCortex()->setSourceStatus(
                    $this->string($options, 'key'),
                    $this->string($options, 'status')
                ),
                'capsule:list' => $this->core->listContextCapsules(
                    $this->optionalInteger($options, 'thread'),
                    $this->integer($options, 'limit', 10)
                ),
                'capsule:show' => $this->core->showContextCapsule(
                    $this->optionalInteger($options, 'id')
                ),
                'metrics:snapshot' => $this->core->recordMetricSnapshot(
                    $this->string($options, 'node'),
                    $this->optionalString($options, 'scope')
                ),
                'metrics:report' => $this->core->metricsReport(
                    $this->integer($options, 'limit', 10),
                    $this->optionalString($options, 'scope')
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
                'spark:once' => (new CodexSparkWorker($this->core))->runOnce(
                    $this->string($options, 'owner')
                ),
                'worker:once' => (new CodexSparkWorker($this->core))->runOnce(
                    $this->string($options, 'owner')
                ),
                'opencode:once' => (new FreeModelWorker($this->core))->runOnce(
                    $this->string($options, 'owner')
                ),
                'reflection:once' => (new \NaviBrain\Core\PublicReflection($this->core))->runOnce($this->string($options, 'owner')),
                'reflection:status' => (new \NaviBrain\Core\PublicReflection($this->core))->status(),
                'reflection:review' => (new \NaviBrain\Core\PublicReflection($this->core))->review(
                    $this->integer($options, 'work'), $this->string($options, 'verdict'), $this->string($options, 'note')
                ),
                'local:status' => $this->localModelStatus(),
                'local:reset' => $this->core->resetLocalModelBackoff(
                    LocalModelWorker::MODEL_ID,
                    $this->string($options, 'reason')
                ),
                'local:once' => (new LocalModelWorker($this->core))->runOnce(
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
                'status' => $debugJson
                    ? array_merge(
                        ['primary_intention' => $this->optionalString($options, 'active-intention')],
                        $this->core->status()
                    )
                    : $this->core->contextStatus(
                        $this->string($options, 'active-intention'),
                        $this->integer(
                            $options,
                            'token-budget',
                            TokenMemoryDaemon::DEFAULT_CONTEXT_TOKENS
                        )
                    ),
                default => throw new InvalidArgumentException(sprintf('Unknown command: %s', $command)),
            };

            $this->writeOutput(
                ['ok' => true, 'command' => $command, 'result' => $result],
                STDOUT,
                $debugJson
            );
            return 0;
        } catch (Throwable $throwable) {
            $this->writeOutput([
                'ok' => false,
                'command' => $command,
                'error' => $throwable->getMessage(),
                'type' => $throwable::class,
            ], STDERR, $debugJson);
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
            idempotencyKey: $this->string($options, 'idempotency-key'),
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
    /** @return array<string, mixed> */
    private function decodePerceptFrame(array $options): array
    {
        $id = $this->optionalInteger($options, 'id');
        $frames = $id === null
            ? \NaviBrain\Model\PerceptFrame::getAll(['order' => ['id' => 'DESC'], 'limit' => 1])
            : [\NaviBrain\Model\PerceptFrame::getByID($id)];
        $frame = $frames[0] ?? null;
        if (!$frame instanceof \NaviBrain\Model\PerceptFrame) {
            throw new InvalidArgumentException('No such percept frame.');
        }
        $codec = new \NaviBrain\Perception\PerceptCodec($this->core);
        return [
            'frame' => $frame->getData(),
            'compression' => $frame->packed_bytes > 0
                ? round($frame->source_bytes / $frame->packed_bytes, 2)
                : null,
            'samples' => $codec->unpack($frame),
        ];
    }

    /** @return array<string, mixed> */
    private function localModelStatus(): array
    {
        $client = new LocalModelClient();
        $healthy = $client->isHealthy();
        $endpoint = null;
        foreach ($this->core->listModelEndpoints() as $candidate) {
            if (($candidate['model_id'] ?? null) === LocalModelWorker::MODEL_ID) {
                $endpoint = $candidate;
                break;
            }
        }
        return [
            'model_id' => LocalModelWorker::MODEL_ID,
            'endpoint' => $client->endpoint(),
            'healthy' => $healthy,
            'registered_endpoint' => $endpoint,
        ];
    }

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
     *  @return array<string, mixed>
     */
    private function jsonObject(array $options, string $name, ?array $default = null): array
    {
        if (!array_key_exists($name, $options) && $default !== null) {
            return $default;
        }
        $raw = $this->string($options, $name);
        try {
            $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(sprintf('--%s must be valid JSON: %s', $name, $exception->getMessage()));
        }
        if (!is_object($decoded)) {
            throw new InvalidArgumentException(sprintf('--%s must be a JSON object.', $name));
        }
        return get_object_vars($decoded);
    }

    /** @param array<string, string|bool> $options
     *  @return list<int>
     */
    private function integerList(array $options, string $name): array
    {
        $values = [];
        foreach ($this->commaSeparated($options, $name) as $value) {
            if (!ctype_digit($value) || (int) $value < 1) {
                throw new InvalidArgumentException(sprintf('--%s must contain positive integers.', $name));
            }
            $values[] = (int) $value;
        }
        return $values;
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
            'usage' => './bin/navi-brain COMMAND with named options; add --debug-json only for raw serialization output',
            'commands' => [
                'init',
                'event:list [--limit=50]',
                'intention:add --title --reason --authority --next-action --success --release [--dependencies=1,2] [--parent=1]',
                'intention:list [--status=active]',
                'intention:advance --id --next-action [--note]',
                'intention:close --id --status=blocked|completed|released --note',
                'action:start --intention --description --expected [--kind] [--arguments=JSON]',
                'action:execute --intention --kind --arguments=JSON --description --expected',
                'action:finish --action --status=succeeded|failed|cancelled --observed --matched=yes|no --repair',
                'action:list [--status=pending]',
                'procedure:list [--status=active|invalidated]',
                'procedure:adapters',
                'procedure:run --id --intention --operation-key [--arguments=JSON]',
                'procedure:compose --name --description --procedures=1,2 --authority=user|developer',
                'decision:start --intention --trigger [--thread] [--model-hint]',
                'decision:list [--status=running|waiting|completed|impasse|failed|cancelled] [--limit=20]',
                'decision:show --id',
                'decision:compare [--limit=200]',
                'other:status',
                'other:frame [--status=active|superseded|corrected|rejected|expired] [--limit=100]',
                'other:hypotheses [--status=active|rejected|expired|corrected] [--limit=100]',
                'other:predictions [--status=pending|matched|violated|expired|unresolvable] [--limit=100]',
                'other:cycles [--status=running|waiting|completed|abstained|failed] [--limit=20]',
                'other:correct --hypothesis --correction',
                'other:replay [--limit=500]',
                'memory:add --tier --content --confidence --idempotency-key [--source-event] [--source-memory] [--supersedes] [--expires] [--allow-procedural-write]',
                'memory:search --query [--limit=20]',
                'personality:compile --context',
                'motivation:compile --context',
                'intention:compile',
                'narrative:queue --reason',
                'memory:consolidate --episode --content --confidence',
                'memory:consolidation:status',
                'memory:consolidation:pump [--depth=2]',
                'memory:consolidation:repair --reason',
                'need:list',
                'need:set --key --description --pressure --growth-per-hour --trigger-threshold --status --rationale [--authority=agent]',
                'need:satisfy --key --amount --source',
                'mind:tick',
                'mind:daydream --reason',
                'mind:sleep --reason',
                'heartbeat:status',
                'heartbeat:due --node',
                'heartbeat:tick --rhythm --node',
                'thread:self-presence --intention',
                'thread:epistemic --intention',
                'thread:stream --intention',
                'stream:recent [--limit=12]',
                'thread:list [--status=active|waiting|blocked|complete|released]',
                'thread:step:list [--thread]',
                'thread:release --key --reason',
                'thread:due --node',
                'sense:status',
                'sense:events [--limit=10] [--min-significance]',
                'sense:define --key --source --notices --detector=change|threshold|absence|rate|pattern [--config=JSON] [--refractory=60]',
                'sense:tune',
                'social:capital',
                'percept:compact [--older-than=900]',
                'percept:decode [--id]',
                'social:close',
                'sense:decay',
                'source:authorize --key --description --reveals [--acquisition] [--interval] [--ttl]',
                'source:status --key --status=active|paused|revoked',
                'capsule:list [--thread] [--limit=10]',
                'capsule:show [--id]',
                'metrics:snapshot --node [--scope]',
                'metrics:report [--limit] [--scope]',
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
                'spark:once --owner',
                'worker:once --owner',
                'opencode:once --owner',
                'reflection:once --owner',
                'reflection:status',
                'reflection:review --work --verdict=useful|rejected --note',
                'local:status',
                'local:reset --reason',
                'local:once --owner',
                'backup:create --reason',
                'self:set --key --value --confidence --evidence',
                'self:list',
                'appraise --event|--intention --relevance --urgency --controllability --uncertainty --commitment-impact',
                'checkpoint --reason',
                'status --active-intention="current user-directed objective" [--token-budget=1024]',
            ],
        ];
    }

    /** @param resource $stream
     *  @throws JsonException
     */
    private function writeOutput(array $payload, $stream, bool $debugJson): void
    {
        if (!$debugJson) {
            $command = (string) ($payload['command'] ?? '');
            if (($payload['ok'] ?? false) === true
                && in_array(
                    $command,
                    ['personality:compile', 'motivation:compile', 'intention:compile'],
                    true
                )
            ) {
                $result = $payload['result'] ?? [];
                fwrite(
                    $stream,
                    CompilerText::render($command, is_array($result) ? $result : []) . PHP_EOL
                );
                return;
            }
            if (($payload['ok'] ?? false) === true && $command === 'status') {
                $result = $payload['result'] ?? [];
                fwrite($stream, is_string($result) ? $result : '');
                return;
            }
            if (($payload['ok'] ?? false) === true && $command === 'checkpoint') {
                fwrite($stream, 'saved' . PHP_EOL);
                return;
            }
            $maxCharacters = $command === 'help' ? 30000 : 20000;
            $listLimit = $command === 'help' ? 200 : 12;
            fwrite($stream, PlainText::render($payload, $maxCharacters, $listLimit) . PHP_EOL);
            return;
        }
        fwrite($stream, json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL);
    }
}

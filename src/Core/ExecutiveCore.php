<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use DateTimeInterface;
use Divergence\IO\Database\Connections;
use Divergence\Models\ActiveRecord;
use InvalidArgumentException;
use PDO;
use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\Appraisal;
use NaviBrain\Model\Checkpoint;
use NaviBrain\Model\CycleRun;
use NaviBrain\Model\Event;
use NaviBrain\Model\ExecutiveInterrupt;
use NaviBrain\Model\Intention;
use NaviBrain\Model\Memory;
use NaviBrain\Model\ModelEndpoint;
use NaviBrain\Model\Need;
use NaviBrain\Model\Rhythm;
use NaviBrain\Model\SelfModelFact;
use NaviBrain\Model\ThoughtArtifact;
use NaviBrain\Model\WorkItem;
use NaviBrain\Storage\Schema;
use RuntimeException;
use Throwable;

final class ExecutiveCore
{
    private const STALE_ACTION_SECONDS = 86400;
    private const DAYDREAM_MEMORY_LIMIT = 3;
    private const DAYDREAM_COOLDOWN_SECONDS = 300;

    private PDO $connection;

    public function __construct(private readonly Schema $schema = new Schema())
    {
        $this->connection = Connections::getConnection();
    }

    /** @return array{version: int, tables: list<string>} */
    public function initialize(): array
    {
        $schema = $this->schema->ensure();
        $this->ensureDefaultNeeds();
        $this->ensureDefaultRhythms();
        return $schema;
    }

    /** @return list<array<string, mixed>> */
    public function listEvents(int $limit = 50): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('limit must be between 1 and 200.');
        }

        return $this->records(Event::getAll([
            'order' => ['created_at' => 'DESC'],
            'limit' => $limit,
        ]));
    }

    /** @param list<int> $dependencies */
    public function createIntention(
        string $title,
        string $reason,
        string $authority,
        string $nextAction,
        string $successCondition,
        string $releaseCondition,
        array $dependencies = [],
        ?int $parentId = null
    ): array {
        $this->requireText($title, 'title');
        $this->requireText($reason, 'reason');
        $this->requireText($nextAction, 'next action');
        $this->requireText($successCondition, 'success condition');
        $this->requireText($releaseCondition, 'release condition');
        $this->requireChoice($authority, ['user', 'developer', 'system', 'agent'], 'authority');

        foreach ($dependencies as $dependencyId) {
            $this->requireIntention($dependencyId);
        }
        if ($parentId !== null) {
            $this->requireIntention($parentId);
        }

        return $this->transaction(function () use (
            $title,
            $reason,
            $authority,
            $nextAction,
            $successCondition,
            $releaseCondition,
            $dependencies,
            $parentId
        ): array {
            /** @var Intention $intention */
            $intention = $this->insert(Intention::class, [
                'title' => $title,
                'reason' => $reason,
                'authority' => $authority,
                'status' => 'active',
                'next_action' => $nextAction,
                'success_condition' => $successCondition,
                'release_condition' => $releaseCondition,
                'dependencies' => $dependencies,
                'parent_id' => $parentId,
                'updated_at' => time(),
            ]);

            $event = $this->emit('intention.created', [
                'intention_id' => $intention->id,
                'title' => $title,
                'authority' => $authority,
            ]);

            return ['intention' => $intention->getData(), 'event' => $event->getData()];
        });
    }

    public function advanceIntention(int $id, string $nextAction, ?string $note = null): array
    {
        $this->requireText($nextAction, 'next action');
        $intention = $this->requireIntention($id);
        if (in_array($intention->status, ['completed', 'released'], true)) {
            throw new RuntimeException('A completed or released intention cannot be advanced.');
        }

        return $this->transaction(function () use ($intention, $nextAction, $note): array {
            $previous = $intention->next_action;
            $intention->setFields([
                'next_action' => $nextAction,
                'status' => 'active',
                'updated_at' => time(),
            ]);
            $intention->save();

            $event = $this->emit('intention.advanced', [
                'intention_id' => $intention->id,
                'previous_next_action' => $previous,
                'next_action' => $nextAction,
                'note' => $note,
            ]);

            return ['intention' => $intention->getData(), 'event' => $event->getData()];
        });
    }

    public function closeIntention(int $id, string $status, string $note): array
    {
        $this->requireChoice($status, ['blocked', 'completed', 'released'], 'status');
        $this->requireText($note, 'closure note');
        $intention = $this->requireIntention($id);
        if (in_array($intention->status, ['completed', 'released'], true)) {
            throw new RuntimeException('The intention is already terminal.');
        }

        return $this->transaction(function () use ($intention, $status, $note): array {
            $intention->setFields([
                'status' => $status,
                'closure_note' => $note,
                'updated_at' => time(),
            ]);
            $intention->save();

            $event = $this->emit('intention.' . $status, [
                'intention_id' => $intention->id,
                'note' => $note,
            ]);

            return ['intention' => $intention->getData(), 'event' => $event->getData()];
        });
    }

    /** @return list<array<string, mixed>> */
    public function listIntentions(?string $status = null): array
    {
        if ($status !== null) {
            $this->requireChoice($status, ['active', 'blocked', 'completed', 'released'], 'status');
            $records = Intention::getAllByWhere(
                ['status' => $status],
                ['order' => ['updated_at' => 'DESC']]
            );
        } else {
            $records = Intention::getAll(['order' => ['updated_at' => 'DESC']]);
        }

        return $this->records($records);
    }

    public function startAction(int $intentionId, string $description, string $expected): array
    {
        $this->requireText($description, 'description');
        $this->requireText($expected, 'expected result');
        $intention = $this->requireIntention($intentionId);
        if ($intention->status !== 'active') {
            throw new RuntimeException('Actions can only start for active intentions.');
        }

        return $this->transaction(function () use ($intentionId, $description, $expected): array {
            /** @var ActionTrace $action */
            $action = $this->insert(ActionTrace::class, [
                'intention_id' => $intentionId,
                'description' => $description,
                'expected' => $expected,
                'status' => 'pending',
                'match_status' => 'pending',
            ]);

            $event = $this->emit('action.started', [
                'action_id' => $action->id,
                'intention_id' => $intentionId,
                'description' => $description,
                'expected' => $expected,
            ]);

            $action->setField('start_event_id', $event->id);
            $action->save();

            return ['action' => $action->getData(), 'event' => $event->getData()];
        });
    }

    public function finishAction(
        int $actionId,
        string $status,
        string $observed,
        bool $matched,
        string $repairNote
    ): array {
        $this->requireChoice($status, ['succeeded', 'failed', 'cancelled'], 'status');
        $this->requireText($observed, 'observed result');
        $this->requireText($repairNote, 'repair note');

        $action = $this->requireAction($actionId);
        if ($action->status !== 'pending') {
            throw new RuntimeException('The action has already been finished.');
        }

        return $this->transaction(function () use ($action, $status, $observed, $matched, $repairNote): array {
            $matchStatus = $matched ? 'matched' : 'mismatched';
            $event = $this->emit('action.finished', [
                'action_id' => $action->id,
                'intention_id' => $action->intention_id,
                'status' => $status,
                'expected' => $action->expected,
                'observed' => $observed,
                'match_status' => $matchStatus,
                'repair_note' => $repairNote,
            ]);

            $action->setFields([
                'completed_at' => time(),
                'observed' => $observed,
                'status' => $status,
                'match_status' => $matchStatus,
                'repair_note' => $repairNote,
                'completion_event_id' => $event->id,
            ]);
            $action->save();

            /** @var Memory $memory */
            $memory = $this->insert(Memory::class, [
                'tier' => 'episodic',
                'content' => sprintf(
                    'Action "%s" expected "%s" and observed "%s". Outcome: %s. Match: %s. Repair: %s',
                    $action->description,
                    $action->expected,
                    $observed,
                    $status,
                    $matchStatus,
                    $repairNote
                ),
                'confidence' => 1.0,
                'status' => 'active',
                'source_event_id' => $event->id,
                'updated_at' => time(),
            ]);

            return [
                'action' => $action->getData(),
                'event' => $event->getData(),
                'episodic_memory' => $memory->getData(),
            ];
        });
    }

    /** @return list<array<string, mixed>> */
    public function listActions(?string $status = null): array
    {
        if ($status !== null) {
            $this->requireChoice($status, ['pending', 'succeeded', 'failed', 'cancelled'], 'status');
            $records = ActionTrace::getAllByWhere(
                ['status' => $status],
                ['order' => ['created_at' => 'DESC']]
            );
        } else {
            $records = ActionTrace::getAll(['order' => ['created_at' => 'DESC']]);
        }

        return $this->records($records);
    }

    public function addMemory(
        string $tier,
        string $content,
        float $confidence,
        ?int $sourceEventId = null,
        ?int $sourceMemoryId = null,
        ?int $supersedesId = null,
        ?string $expiresAt = null,
        bool $allowProceduralWrite = false
    ): array {
        $this->requireChoice($tier, ['working', 'episodic', 'semantic', 'procedural'], 'tier');
        $this->requireText($content, 'memory content');
        $this->requireUnitInterval($confidence, 'confidence');

        if ($tier === 'procedural' && !$allowProceduralWrite) {
            throw new RuntimeException('Procedural memory writes require --allow-procedural-write.');
        }
        if ($tier === 'semantic' && $sourceEventId === null && $sourceMemoryId === null) {
            throw new RuntimeException('Semantic memories require a source event or source memory.');
        }
        if ($sourceEventId !== null) {
            $this->requireEvent($sourceEventId);
        }
        if ($sourceMemoryId !== null) {
            $this->requireMemory($sourceMemoryId);
        }
        $superseded = $supersedesId !== null ? $this->requireMemory($supersedesId) : null;

        return $this->transaction(function () use (
            $tier,
            $content,
            $confidence,
            $sourceEventId,
            $sourceMemoryId,
            $supersedesId,
            $expiresAt,
            $superseded
        ): array {
            /** @var Memory $memory */
            $memory = $this->insert(Memory::class, [
                'tier' => $tier,
                'content' => $content,
                'confidence' => $confidence,
                'status' => 'active',
                'source_event_id' => $sourceEventId,
                'source_memory_id' => $sourceMemoryId,
                'supersedes_id' => $supersedesId,
                'expires_at' => $expiresAt,
                'updated_at' => time(),
            ]);

            if ($superseded instanceof Memory) {
                $superseded->setFields(['status' => 'superseded', 'updated_at' => time()]);
                $superseded->save();
            }

            $event = $this->emit('memory.stored', [
                'memory_id' => $memory->id,
                'tier' => $tier,
                'source_event_id' => $sourceEventId,
                'source_memory_id' => $sourceMemoryId,
                'supersedes_id' => $supersedesId,
            ]);

            return ['memory' => $memory->getData(), 'event' => $event->getData()];
        });
    }

    public function consolidateMemory(int $episodeId, string $semanticContent, float $confidence): array
    {
        $episode = $this->requireMemory($episodeId);
        if ($episode->tier !== 'episodic') {
            throw new RuntimeException('Only episodic memories can be consolidated by this command.');
        }

        return $this->addMemory(
            tier: 'semantic',
            content: $semanticContent,
            confidence: $confidence,
            sourceEventId: $episode->source_event_id,
            sourceMemoryId: $episodeId
        );
    }

    /** @return list<array<string, mixed>> */
    public function searchMemory(string $query, int $limit = 20): array
    {
        $this->requireText($query, 'query');
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit must be between 1 and 100.');
        }

        $normalizedQuery = $this->normalizeSearchText($query);
        $terms = array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $normalizedQuery) ?: [],
            static fn (string $term): bool => $term !== ''
        )));

        $ranked = [];
        foreach (Memory::getAllByWhere(['status' => 'active']) as $memory) {
            $content = $this->normalizeSearchText((string) $memory->content);
            $matchedTerms = 0;
            $occurrences = 0;

            foreach ($terms as $term) {
                $count = substr_count($content, $term);
                if ($count > 0) {
                    $matchedTerms++;
                    $occurrences += $count;
                }
            }

            $phraseMatch = $normalizedQuery !== '' && str_contains($content, $normalizedQuery);
            if (!$phraseMatch && $matchedTerms === 0) {
                continue;
            }

            $coverage = $terms === [] ? 0.0 : $matchedTerms / count($terms);
            $tierWeight = match ($memory->tier) {
                'working' => 4,
                'semantic' => 3,
                'episodic' => 2,
                default => 1,
            };

            $ranked[] = [
                'memory' => $memory,
                'score' => ($phraseMatch ? 1000 : 0)
                    + ($coverage * 100)
                    + ($matchedTerms * 10)
                    + min($occurrences, 10)
                    + $tierWeight,
                'updated_at' => $this->timestamp($memory->updated_at) ?? 0,
            ];
        }

        usort($ranked, static function (array $left, array $right): int {
            return $right['score'] <=> $left['score']
                ?: $right['updated_at'] <=> $left['updated_at'];
        });

        return array_values(array_map(
            static fn (array $entry): array => $entry['memory']->getData(),
            array_slice($ranked, 0, $limit)
        ));
    }

    /** @return list<array<string, mixed>> */
    public function listNeeds(): array
    {
        $now = time();
        return array_values(array_map(
            fn (Need $need): array => $this->needState($need, $now),
            Need::getAll(['order' => ['need_key' => 'ASC']])
        ));
    }

    public function setNeed(
        string $key,
        string $description,
        float $pressure,
        float $growthPerHour,
        float $triggerThreshold,
        string $status,
        string $rationale,
        string $changeAuthority = 'agent'
    ): array {
        $this->requireText($key, 'need key');
        $this->requireText($description, 'need description');
        $this->requireText($rationale, 'need change rationale');
        $this->requireUnitInterval($pressure, 'need pressure');
        $this->requireUnitInterval($growthPerHour, 'need growth per hour');
        $this->requireUnitInterval($triggerThreshold, 'need trigger threshold');
        $this->requireChoice($status, ['active', 'paused'], 'need status');
        $this->requireChoice($changeAuthority, ['user', 'developer', 'system', 'agent'], 'change authority');

        return $this->transaction(function () use (
            $key,
            $description,
            $pressure,
            $growthPerHour,
            $triggerThreshold,
            $status,
            $rationale,
            $changeAuthority
        ): array {
            $need = Need::getByField('need_key', $key);
            $before = $need instanceof Need ? $need->getData() : null;

            if (!$need instanceof Need) {
                /** @var Need $need */
                $need = $this->insert(Need::class, [
                    'need_key' => $key,
                    'description' => $description,
                    'authority' => $changeAuthority,
                    'pressure' => $pressure,
                    'growth_per_hour' => $growthPerHour,
                    'trigger_threshold' => $triggerThreshold,
                    'last_satisfied_at' => time(),
                    'updated_at' => time(),
                    'status' => $status,
                ]);
            } else {
                $need->setFields([
                    'description' => $description,
                    'pressure' => $pressure,
                    'growth_per_hour' => $growthPerHour,
                    'trigger_threshold' => $triggerThreshold,
                    'updated_at' => time(),
                    'status' => $status,
                ]);
                $need->save();
            }

            $event = $this->emit('need.changed', [
                'need_id' => $need->id,
                'need_key' => $key,
                'change_authority' => $changeAuthority,
                'rationale' => $rationale,
                'before' => $before,
                'after' => $need->getData(),
            ]);

            return ['need' => $need->getData(), 'event' => $event->getData()];
        });
    }

    public function satisfyNeed(string $key, float $amount, string $source): array
    {
        $this->requireText($key, 'need key');
        $this->requireText($source, 'satisfaction source');
        $this->requireUnitInterval($amount, 'satisfaction amount');

        return $this->transaction(function () use ($key, $amount, $source): array {
            $need = Need::getByField('need_key', $key);
            if (!$need instanceof Need) {
                throw new RuntimeException(sprintf('Need %s does not exist.', $key));
            }

            return $this->satisfyNeedRecord($need, $amount, $source, time());
        });
    }

    public function tickMind(): array
    {
        $needs = $this->accrueNeeds(time());
        $triggered = array_values(array_filter(
            $needs,
            static fn (array $need): bool => $need['status'] === 'active' && $need['triggered']
        ));

        $daydream = $triggered === [] || $this->daydreamCooldownActive()
            ? null
            : $this->daydream('need pressure crossed its trigger threshold');

        return [
            'state' => $daydream === null ? 'sleeping' : 'micro_wake',
            'needs' => $needs,
            'daydream' => $daydream,
        ];
    }

    public function daydream(string $reason, ?int $runId = null): array
    {
        $this->requireText($reason, 'daydream reason');
        if ($runId !== null && !CycleRun::getByID($runId) instanceof CycleRun) {
            throw new RuntimeException(sprintf('Cycle run %d does not exist.', $runId));
        }

        return $this->transaction(function () use ($reason, $runId): array {
            $now = time();
            $needsBefore = $this->accrueNeeds($now);
            $memories = array_values(array_filter(
                Memory::getAllByWhere(['status' => 'active']),
                static fn (Memory $memory): bool => in_array($memory->tier, ['semantic', 'episodic'], true)
            ));
            shuffle($memories);
            $memories = array_slice($memories, 0, self::DAYDREAM_MEMORY_LIMIT);

            $sourceIds = array_values(array_map(
                static fn (Memory $memory): int => (int) $memory->id,
                $memories
            ));
            $fragments = array_values(array_filter(array_map(
                fn (Memory $memory): string => $this->randomMemoryFragment((string) $memory->content),
                $memories
            )));
            $lenses = [
                'Assume a central premise is false and follow the consequences.',
                'Treat these unrelated fragments as parts of one hidden system.',
                'Reverse cause and effect, then ask what evidence would expose the mistake.',
                'Change the scale by six orders of magnitude and see which constraints survive.',
                'Imagine this from the perspective least represented in current memory.',
                'Replace the familiar implementation with a biological, social, or astronomical analogue.',
            ];
            $lens = $lenses[random_int(0, count($lenses) - 1)];
            $rawMaterial = $fragments === []
                ? 'silence, uncertainty, and the fact that no memory was selected'
                : implode(' | ', $fragments);
            $content = sprintf(
                'Unverified daydream seed. %s Free-associate across: %s What surprising possibility appears, and which current assumption would it challenge?',
                $lens,
                $rawMaterial
            );
            $hash = hash('sha256', 'daydream|' . implode(',', $sourceIds) . '|' . $content);
            $artifact = ThoughtArtifact::getByField('content_hash', $hash);

            if (!$artifact instanceof ThoughtArtifact) {
                /** @var ThoughtArtifact $artifact */
                $artifact = $this->insert(ThoughtArtifact::class, [
                    'run_id' => $runId,
                    'kind' => 'wandering_association',
                    'content' => $content,
                    'confidence' => 0.2,
                    'provenance' => 'daydream',
                    'source_ids' => ['memory_ids' => $sourceIds],
                    'status' => 'proposed',
                    'content_hash' => $hash,
                ]);
                $event = $this->emit('thought.proposed', [
                    'artifact_id' => $artifact->id,
                    'run_id' => $runId,
                    'kind' => $artifact->kind,
                    'provenance' => 'daydream',
                    'synthetic' => true,
                    'source_memory_ids' => $sourceIds,
                    'external_action_authorized' => false,
                ]);
            } else {
                $event = null;
            }

            $satisfaction = [];
            foreach ([
                'cognitive_stimulation' => 0.25,
                'epistemic_novelty' => 0.15,
            ] as $needKey => $amount) {
                $need = Need::getByField('need_key', $needKey);
                if ($need instanceof Need) {
                    $satisfaction[] = $this->satisfyNeedRecord(
                        $need,
                        $amount,
                        'sandboxed daydream artifact ' . $artifact->id,
                        $now
                    );
                }
            }

            return [
                'state' => 'micro_wake',
                'reason' => $reason,
                'artifact' => $artifact->getData(),
                'artifact_event' => $event?->getData(),
                'needs_before' => $needsBefore,
                'satisfaction' => $satisfaction,
                'factual_status' => 'synthetic_unverified',
                'external_action_authorized' => false,
            ];
        });
    }

    public function sleep(string $reason, ?int $runId = null): array
    {
        $this->requireText($reason, 'sleep reason');
        if ($runId !== null && !CycleRun::getByID($runId) instanceof CycleRun) {
            throw new RuntimeException(sprintf('Cycle run %d does not exist.', $runId));
        }

        $integrity = $this->quickCheck();
        if ($integrity !== ['ok']) {
            throw new RuntimeException('Sleep refused because SQLite quick_check failed: ' . implode('; ', $integrity));
        }

        $before = $this->checkpoint('sleep-before-audit');
        $started = $this->emit('sleep.started', [
            'run_id' => $runId,
            'reason' => $reason,
            'before_checkpoint_id' => $before['id'],
            'state' => 'sleeping',
        ]);

        try {
            $nonRem = $this->transaction(function (): array {
                $expired = [];
                $now = time();
                foreach (Memory::getAllByWhere(['tier' => 'working', 'status' => 'active']) as $memory) {
                    $expiresAt = $this->timestamp($memory->expires_at);
                    if ($expiresAt === null || $expiresAt > $now) {
                        continue;
                    }
                    $memory->setFields(['status' => 'expired', 'updated_at' => $now]);
                    $memory->save();
                    $event = $this->emit('memory.expired', [
                        'memory_id' => $memory->id,
                        'expires_at' => $expiresAt,
                        'repair_kind' => 'explicit_expiry',
                    ]);
                    $expired[] = ['memory_id' => $memory->id, 'event_id' => $event->id];
                }

                return [
                    'integrity' => ['sqlite_quick_check' => 'ok'],
                    'automatic_repairs' => $expired,
                    'findings' => $this->cognitiveFindings($now),
                ];
            });

            $repairArtifacts = $this->createRepairArtifacts($nonRem['findings'], $runId);
            $needs = $this->accrueNeeds(time());
            $bored = array_filter(
                $needs,
                static fn (array $need): bool => $need['status'] === 'active' && $need['triggered']
            ) !== [];
            $wandering = $bored && !$this->daydreamCooldownActive()
                ? $this->daydream('boredom surfaced during sleep replay', $runId)
                : null;

            $after = $this->checkpoint('sleep-after-audit');
            $completed = $this->emit('sleep.completed', [
                'run_id' => $runId,
                'start_event_id' => $started->id,
                'reason' => $reason,
                'before_checkpoint_id' => $before['id'],
                'after_checkpoint_id' => $after['id'],
                'non_rem' => $nonRem,
                'repair_artifact_ids' => array_column($repairArtifacts, 'id'),
                'daydream_artifact_id' => $wandering['artifact']['id'] ?? null,
                'state' => 'sleeping',
            ]);

            return [
                'state' => 'sleeping',
                'reason' => $reason,
                'before_checkpoint' => $before,
                'non_rem' => $nonRem,
                'rem' => [
                    'repair_artifacts' => $repairArtifacts,
                    'wandering' => $wandering,
                ],
                'after_checkpoint' => $after,
                'event' => $completed->getData(),
                'checkpoint_kind' => 'audit_snapshot_not_automatic_restore',
            ];
        } catch (Throwable $throwable) {
            $this->emit('sleep.failed', [
                'run_id' => $runId,
                'start_event_id' => $started->id,
                'before_checkpoint_id' => $before['id'],
                'error' => $throwable->getMessage(),
                'state' => 'sleeping',
            ]);
            throw $throwable;
        }
    }

    public function heartbeatStatus(): array
    {
        $now = time();
        $rhythms = array_values(array_map(function (Rhythm $rhythm) use ($now): array {
            $data = $rhythm->getData();
            $dueAt = $this->timestamp($rhythm->next_due_at) ?? $now;
            $interval = max(1, (int) $rhythm->interval_seconds);
            $data['due'] = $rhythm->status !== 'paused' && $dueAt <= $now;
            $data['overdue_seconds'] = max(0, $now - $dueAt);
            $data['missed_intervals'] = max(0, intdiv(max(0, $now - $dueAt), $interval));
            return $data;
        }, Rhythm::getAll(['order' => ['interval_seconds' => 'ASC']])));

        return [
            'operational_state' => 'sleeping_between_wake_moments',
            'rhythms' => $rhythms,
            'running_cycles' => $this->records(CycleRun::getAllByWhere(
                ['status' => 'running'],
                ['order' => ['created_at' => 'ASC']]
            )),
            'queued_work' => $this->records(WorkItem::getAllByWhere(
                ['status' => 'queued'],
                ['order' => ['created_at' => 'ASC']]
            )),
            'leased_work' => $this->records(WorkItem::getAllByWhere(
                ['status' => 'leased'],
                ['order' => ['created_at' => 'ASC']]
            )),
            'pending_interrupts' => $this->records(ExecutiveInterrupt::getAllByWhere(
                ['status' => 'pending'],
                ['order' => ['created_at' => 'ASC']]
            )),
        ];
    }

    public function raiseInterrupt(string $reason, string $severity = 'high', string $source = 'external'): array
    {
        $this->requireText($reason, 'interrupt reason');
        $this->requireChoice($severity, ['critical', 'high', 'normal'], 'severity');
        $this->requireText($source, 'interrupt source');

        return $this->transaction(function () use ($reason, $severity, $source): array {
            /** @var ExecutiveInterrupt $interrupt */
            $interrupt = $this->insert(ExecutiveInterrupt::class, [
                'reason' => $reason,
                'severity' => $severity,
                'source' => $source,
                'status' => 'pending',
                'fencing_token' => 0,
                'updated_at' => time(),
            ]);

            $event = $this->emit('interrupt.raised', [
                'interrupt_id' => $interrupt->id,
                'severity' => $severity,
                'source' => $source,
                'reason' => $reason,
            ]);

            return ['interrupt' => $interrupt->getData(), 'event' => $event->getData()];
        });
    }

    public function acknowledgeInterrupt(int $id, string $nodeId): array
    {
        $this->requireText($nodeId, 'node id');

        return $this->transaction(function () use ($id, $nodeId): array {
            $interrupt = ExecutiveInterrupt::getByID($id);
            if (!$interrupt instanceof ExecutiveInterrupt) {
                throw new RuntimeException(sprintf('Interrupt %d does not exist.', $id));
            }
            if ($interrupt->status !== 'pending') {
                throw new RuntimeException(sprintf('Interrupt %d is already %s.', $id, $interrupt->status));
            }

            $newFence = (int) $interrupt->fencing_token + 1;
            $statement = $this->connection->prepare(
                "UPDATE executive_interrupts
                 SET status = 'acknowledged', acknowledged_by = :node,
                     acknowledged_at = :now, fencing_token = :new_fence,
                     updated_at = :now
                 WHERE id = :id AND status = 'pending'"
            );
            $statement->execute([
                ':node' => $nodeId,
                ':now' => date('Y-m-d H:i:s', time()),
                ':new_fence' => $newFence,
                ':id' => $id,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Interrupt claim lost.');
            }

            $interrupt = ExecutiveInterrupt::getByID($id);
            $event = $this->emit('interrupt.acknowledged', [
                'interrupt_id' => $id,
                'node_id' => $nodeId,
                'fencing_token' => $newFence,
            ]);

            return ['interrupt' => $interrupt->getData(), 'event' => $event->getData()];
        });
    }

    public function runSafetyCheck(): ?array
    {
        $now = time();
        $findings = [];

        $integrity = $this->quickCheck();
        if ($integrity !== ['ok']) {
            $findings[] = [
                'kind' => 'integrity_failure',
                'lizard_brain' => true,
                'description' => 'SQLite quick_check failed: ' . implode('; ', $integrity),
            ];
        }

        foreach (ActionTrace::getAllByWhere(['status' => 'pending']) as $action) {
            $createdAt = $this->timestamp($action->created_at);
            if ($createdAt !== null && $createdAt < $now - self::STALE_ACTION_SECONDS * 2) {
                $findings[] = [
                    'kind' => 'stale_action_critical',
                    'lizard_brain' => true,
                    'description' => sprintf('Action %d pending for over %d seconds.', $action->id, self::STALE_ACTION_SECONDS * 2),
                    'action_id' => $action->id,
                ];
            }
        }

        if ($findings === []) {
            return null;
        }

        $event = $this->emit('safety.alert', [
            'findings' => $findings,
            'timestamp' => $now,
        ]);

        return [
            'status' => 'safety_alert',
            'preempt_any_interrupt' => true,
            'findings' => $findings,
            'event' => $event->getData(),
        ];
    }

    public function checkExecutiveInterrupts(string $nodeId): ?array
    {
        $this->requireText($nodeId, 'node id');

        $safety = $this->runSafetyCheck();
        if ($safety !== null) {
            return $safety;
        }

        $pending = ExecutiveInterrupt::getAllByWhere(
            ['status' => 'pending'],
            ['order' => ['created_at' => 'ASC']]
        );

        $critical = array_values(array_filter(
            $pending,
            static fn (ExecutiveInterrupt $i): bool => $i->severity === 'critical'
        ));

        if ($critical !== []) {
            $interrupt = $critical[0];
            $ack = $this->acknowledgeInterrupt((int) $interrupt->id, $nodeId);
            return [
                'status' => 'critical_interrupt_claimed',
                'interrupt' => $ack['interrupt'],
                'event' => $ack['event'],
                'trigger_immediate_wake' => true,
            ];
        }

        $high = array_values(array_filter(
            $pending,
            static fn (ExecutiveInterrupt $i): bool => $i->severity === 'high'
        ));

        if ($high !== [] && !$this->highBrainBusy()) {
            $interrupt = $high[0];
            $ack = $this->acknowledgeInterrupt((int) $interrupt->id, $nodeId);
            return [
                'status' => 'high_interrupt_claimed',
                'interrupt' => $ack['interrupt'],
                'event' => $ack['event'],
                'trigger_immediate_wake' => true,
            ];
        }

        if ($pending !== []) {
            return [
                'status' => 'interrupts_pending_deferred',
                'pending_count' => count($pending),
                'trigger_immediate_wake' => false,
            ];
        }

        return null;
    }

    public function resolveInterrupt(int $id, string $nodeId, ?int $runId = null): array
    {
        $this->requireText($nodeId, 'node id');

        return $this->transaction(function () use ($id, $nodeId, $runId): array {
            $interrupt = ExecutiveInterrupt::getByID($id);
            if (!$interrupt instanceof ExecutiveInterrupt) {
                throw new RuntimeException(sprintf('Interrupt %d does not exist.', $id));
            }
            if ($interrupt->status !== 'acknowledged') {
                throw new RuntimeException(sprintf('Interrupt %d is not acknowledged.', $id));
            }
            if ($interrupt->acknowledged_by !== $nodeId) {
                throw new RuntimeException('Only the acknowledging node can resolve an interrupt.');
            }

            $interrupt->setFields([
                'status' => 'resolved',
                'resolution_run_id' => $runId,
                'updated_at' => time(),
            ]);
            $interrupt->save();

            $event = $this->emit('interrupt.resolved', [
                'interrupt_id' => $id,
                'node_id' => $nodeId,
                'resolution_run_id' => $runId,
            ]);

            return ['interrupt' => $interrupt->getData(), 'event' => $event->getData()];
        });
    }

    /** @return list<array<string, mixed>> */
    public function listInterrupts(?string $status = null): array
    {
        if ($status !== null) {
            $this->requireChoice($status, ['pending', 'acknowledged', 'resolved'], 'status');
            return $this->records(ExecutiveInterrupt::getAllByWhere(
                ['status' => $status],
                ['order' => ['created_at' => 'DESC']]
            ));
        }
        return $this->records(ExecutiveInterrupt::getAll(['order' => ['created_at' => 'DESC']]));
    }

    public function runDueHeartbeats(string $nodeId): array
    {
        $this->requireText($nodeId, 'node id');
        $now = time();
        $recoveredCycles = $this->recoverExpiredCycleLeases($now);
        $due = array_values(array_filter(
            Rhythm::getAll(),
            fn (Rhythm $rhythm): bool => in_array($rhythm->status, ['idle', 'failed'], true)
                && ($this->timestamp($rhythm->next_due_at) ?? PHP_INT_MAX) <= $now
        ));
        usort($due, static function (Rhythm $left, Rhythm $right): int {
            if ($left->cognitive_layer !== $right->cognitive_layer) {
                return $left->cognitive_layer === CognitiveLayer::LOW->value ? -1 : 1;
            }
            return (int) $right->interval_seconds <=> (int) $left->interval_seconds;
        });

        $results = [];
        foreach ($due as $rhythm) {
            $results[] = $this->runHeartbeat((string) $rhythm->rhythm_key, $nodeId);
        }

        return [
            'node_id' => $nodeId,
            'recovered_cycle_ids' => $recoveredCycles,
            'fired' => $results,
            'status' => $this->heartbeatStatus(),
        ];
    }

    public function runHeartbeat(string $rhythmKey, string $nodeId): array
    {
        $this->requireText($rhythmKey, 'rhythm key');
        $this->requireText($nodeId, 'node id');
        $this->recoverExpiredCycleLeases(time());
        $rhythm = Rhythm::getByField('rhythm_key', $rhythmKey);
        if (!$rhythm instanceof Rhythm) {
            throw new RuntimeException(sprintf('Rhythm %s does not exist.', $rhythmKey));
        }
        if ($rhythm->status === 'paused') {
            return ['rhythm' => $rhythmKey, 'status' => 'paused'];
        }
        if ($rhythm->status === 'running') {
            return [
                'rhythm' => $rhythmKey,
                'status' => 'already_running',
                'lease_owner' => $rhythm->lease_owner,
                'lease_expires_at' => $this->timestamp($rhythm->lease_expires_at),
            ];
        }

        $now = time();
        $dueAt = $this->timestamp($rhythm->next_due_at) ?? PHP_INT_MAX;
        if ($dueAt > $now) {
            return ['rhythm' => $rhythmKey, 'status' => 'not_due', 'next_due_at' => $dueAt];
        }

        if ($rhythm->cognitive_layer === CognitiveLayer::HIGH->value && $this->highBrainBusy()) {
            return $this->coalesceHighRhythm($rhythm, $now);
        }

        $leaseSeconds = match ($rhythmKey) {
            'pulse_30s' => 20,
            'reflect_5m' => 240,
            'consolidate_hourly' => 600,
            'sleep_daily' => 1200,
            default => 120,
        };
        $claim = $this->claimRhythm($rhythm, $nodeId, $now, $leaseSeconds);
        if ($claim === null) {
            return ['rhythm' => $rhythmKey, 'status' => 'claim_lost'];
        }

        /** @var CycleRun $run */
        $run = $claim['run'];
        try {
            if ($rhythm->cognitive_layer === CognitiveLayer::LOW->value) {
                $output = $this->lowBrainPulse($run);
                return $this->completeCycleRun($run, $rhythm, $nodeId, $output);
            }

            $output = $this->prepareHighBrainMoment($run, $rhythm);
            if (($output['work_item'] ?? null) === null) {
                return $this->completeCycleRun($run, $rhythm, $nodeId, $output);
            }

            return [
                'rhythm' => $rhythmKey,
                'status' => 'wake_moment_queued',
                'run' => $run->getData(),
                'output' => $output,
            ];
        } catch (Throwable $throwable) {
            $this->failCycleRun($run, $rhythm, $nodeId, $throwable->getMessage());
            throw $throwable;
        }
    }

    public function enqueueWork(
        ?int $parentRunId,
        ?int $parentIntentionId,
        string $workType,
        string $prompt,
        array $inputRefs,
        int $tokenBudget,
        int $wallBudgetSeconds,
        string $idempotencyKey,
        int $depth = 0,
        int $maxDepth = 0
    ): array {
        if ($parentRunId !== null && !CycleRun::getByID($parentRunId) instanceof CycleRun) {
            throw new RuntimeException(sprintf('Cycle run %d does not exist.', $parentRunId));
        }
        if ($parentIntentionId !== null) {
            $this->requireIntention($parentIntentionId);
        }
        $this->requireText($workType, 'work type');
        $this->requireText($prompt, 'work prompt');
        $this->requireText($idempotencyKey, 'work idempotency key');
        if ($tokenBudget < 1 || $tokenBudget > 8192) {
            throw new InvalidArgumentException('token budget must be between 1 and 8192.');
        }
        if ($wallBudgetSeconds < 1 || $wallBudgetSeconds > 3600) {
            throw new InvalidArgumentException('wall budget must be between 1 and 3600 seconds.');
        }
        if ($depth < 0 || $maxDepth < 0 || $depth > $maxDepth) {
            throw new InvalidArgumentException('work depth must be non-negative and no greater than max depth.');
        }

        return $this->transaction(function () use (
            $parentRunId,
            $parentIntentionId,
            $workType,
            $prompt,
            $inputRefs,
            $tokenBudget,
            $wallBudgetSeconds,
            $idempotencyKey,
            $depth,
            $maxDepth
        ): array {
            $existing = WorkItem::getByField('idempotency_key', $idempotencyKey);
            if ($existing instanceof WorkItem) {
                return ['work_item' => $existing->getData(), 'deduplicated' => true];
            }

            /** @var WorkItem $work */
            $work = $this->insert(WorkItem::class, [
                'parent_run_id' => $parentRunId,
                'parent_intention_id' => $parentIntentionId,
                'work_type' => $workType,
                'prompt' => $prompt,
                'input_refs' => $inputRefs,
                'allowed_actions' => [],
                'token_budget' => $tokenBudget,
                'wall_budget_seconds' => $wallBudgetSeconds,
                'depth' => $depth,
                'max_depth' => $maxDepth,
                'idempotency_key' => $idempotencyKey,
                'fencing_token' => 0,
                'attempts' => 0,
                'status' => 'queued',
                'result' => [],
                'updated_at' => time(),
            ]);
            $event = $this->emit('work.queued', [
                'work_item_id' => $work->id,
                'parent_run_id' => $parentRunId,
                'parent_intention_id' => $parentIntentionId,
                'work_type' => $workType,
                'allowed_actions' => [],
                'token_budget' => $tokenBudget,
                'wall_budget_seconds' => $wallBudgetSeconds,
                'depth' => $depth,
                'max_depth' => $maxDepth,
            ]);

            return ['work_item' => $work->getData(), 'event' => $event->getData(), 'deduplicated' => false];
        });
    }

    public function claimWork(string $owner, int $leaseSeconds = 180): ?array
    {
        $this->requireText($owner, 'worker owner');
        if ($leaseSeconds < 10 || $leaseSeconds > 3600) {
            throw new InvalidArgumentException('work lease must be between 10 and 3600 seconds.');
        }

        return $this->transaction(function () use ($owner, $leaseSeconds): ?array {
            $now = time();
            $this->recoverExpiredWorkLeases($now);
            $statement = $this->connection->prepare(
                "UPDATE work_items
                 SET status = 'leased', lease_owner = :owner,
                     lease_expires_at = :lease_expires,
                     fencing_token = fencing_token + 1,
                     attempts = attempts + 1, updated_at = :now, error = NULL
                 WHERE id = (
                     SELECT id FROM work_items
                     WHERE status = 'queued'
                     ORDER BY created_at ASC LIMIT 1
                 ) AND status = 'queued'
                 RETURNING id"
            );
            $statement->execute([
                ':owner' => $owner,
                ':lease_expires' => date('Y-m-d H:i:s', $now + $leaseSeconds),
                ':now' => date('Y-m-d H:i:s', $now),
            ]);
            $id = $statement->fetchColumn();
            if ($id === false) {
                return null;
            }

            $work = WorkItem::getByID((int) $id);
            if (!$work instanceof WorkItem) {
                throw new RuntimeException('Claimed work item could not be reloaded.');
            }
            $this->extendParentRhythmLease($work, $now + $leaseSeconds + 30);
            $event = $this->emit('work.claimed', [
                'work_item_id' => $work->id,
                'owner' => $owner,
                'fencing_token' => $work->fencing_token,
                'lease_expires_at' => $work->lease_expires_at,
                'attempt' => $work->attempts,
            ]);

            return ['work_item' => $work->getData(), 'event' => $event->getData()];
        });
    }

    /** @return list<array<string, mixed>> */
    public function listWorkItems(?string $status = null): array
    {
        if ($status !== null) {
            $this->requireChoice($status, ['queued', 'leased', 'completed', 'failed', 'cancelled'], 'status');
            return $this->records(WorkItem::getAllByWhere(
                ['status' => $status],
                ['order' => ['created_at' => 'ASC']]
            ));
        }
        return $this->records(WorkItem::getAll(['order' => ['created_at' => 'ASC']]));
    }

    /** @return list<array<string, mixed>> */
    public function listThoughtArtifacts(?string $status = null): array
    {
        if ($status !== null) {
            $this->requireChoice($status, ['proposed', 'accepted', 'rejected', 'expired'], 'status');
            return $this->records(ThoughtArtifact::getAllByWhere(
                ['status' => $status],
                ['order' => ['created_at' => 'DESC']]
            ));
        }
        return $this->records(ThoughtArtifact::getAll(['order' => ['created_at' => 'DESC']]));
    }

    /** @param array<string, mixed> $result */
    public function finishWork(
        int $workId,
        string $owner,
        int $fencingToken,
        bool $succeeded,
        array $result,
        ?string $model,
        ?string $error = null
    ): array {
        $this->requireText($owner, 'worker owner');
        if ($succeeded && ($model === null || trim($model) === '')) {
            throw new InvalidArgumentException('Successful work requires a model identifier.');
        }
        if (!$succeeded && ($error === null || trim($error) === '')) {
            throw new InvalidArgumentException('Failed work requires an error.');
        }

        return $this->transaction(function () use (
            $workId,
            $owner,
            $fencingToken,
            $succeeded,
            $result,
            $model,
            $error
        ): array {
            $work = WorkItem::getByID($workId);
            if (!$work instanceof WorkItem) {
                throw new RuntimeException(sprintf('Work item %d does not exist.', $workId));
            }
            if ($work->status !== 'leased'
                || $work->lease_owner !== $owner
                || (int) $work->fencing_token !== $fencingToken
                || ($this->timestamp($work->lease_expires_at) ?? 0) < time()
            ) {
                throw new RuntimeException('Worker lease is stale; completion was rejected by the fencing check.');
            }

            $now = time();
            $artifact = null;
            if ($succeeded) {
                $this->validateWorkerProposal($result);
                $content = json_encode(
                    $result,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                );
                $hash = hash('sha256', 'worker|' . $work->id . '|' . $content);
                $artifact = ThoughtArtifact::getByField('content_hash', $hash);
                if (!$artifact instanceof ThoughtArtifact) {
                    /** @var ThoughtArtifact $artifact */
                    $artifact = $this->insert(ThoughtArtifact::class, [
                        'run_id' => $work->parent_run_id,
                        'kind' => (string) $result['kind'],
                        'content' => (string) $result['content'],
                        'confidence' => (float) $result['confidence'],
                        'provenance' => 'worker',
                        'source_ids' => [
                            'work_item_id' => $work->id,
                            'run_id' => $work->parent_run_id,
                            'input_refs' => $work->input_refs,
                        ],
                        'status' => 'proposed',
                        'content_hash' => $hash,
                    ]);
                    $this->emit('thought.proposed', [
                        'artifact_id' => $artifact->id,
                        'run_id' => $work->parent_run_id,
                        'work_item_id' => $work->id,
                        'provenance' => 'worker',
                        'synthetic' => true,
                        'model' => $model,
                        'external_action_authorized' => false,
                    ]);
                }
            }

            $work->setFields([
                'completed_at' => $now,
                'updated_at' => $now,
                'status' => $succeeded ? 'completed' : 'failed',
                'model' => $model,
                'result' => $result,
                'error' => $error,
                'lease_owner' => null,
                'lease_expires_at' => null,
            ]);
            $work->save();
            $event = $this->emit($succeeded ? 'work.completed' : 'work.failed', [
                'work_item_id' => $work->id,
                'parent_run_id' => $work->parent_run_id,
                'model' => $model,
                'artifact_id' => $artifact?->id,
                'error' => $error,
            ]);

            if ($work->parent_run_id !== null) {
                $run = CycleRun::getByID((int) $work->parent_run_id);
                if ($run instanceof CycleRun && $run->status === 'running') {
                    $rhythm = Rhythm::getByID((int) $run->rhythm_id);
                    if ($rhythm instanceof Rhythm) {
                        if ($succeeded) {
                            $output = $run->output;
                            $output['worker'] = [
                                'work_item_id' => $work->id,
                                'artifact_id' => $artifact?->id,
                                'model' => $model,
                            ];
                            $this->completeCycleRun($run, $rhythm, (string) $run->node_id, $output);
                        } else {
                            $this->failCycleRun(
                                $run,
                                $rhythm,
                                (string) $run->node_id,
                                (string) $error
                            );
                        }
                    }
                }
            }

            return [
                'work_item' => $work->getData(),
                'artifact' => $artifact?->getData(),
                'event' => $event->getData(),
            ];
        });
    }

    /** @param list<string> $modelIds */
    public function syncFreeModels(array $modelIds): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (mixed $model): string => is_string($model) ? trim($model) : '',
            $modelIds
        ), static fn (string $model): bool => $model !== '' && str_ends_with($model, '-free'))));

        return $this->transaction(function () use ($normalized): array {
            $now = time();
            foreach ($normalized as $modelId) {
                [$provider] = array_pad(explode('/', $modelId, 2), 2, 'unknown');
                $endpoint = ModelEndpoint::getByField('model_id', $modelId);
                if (!$endpoint instanceof ModelEndpoint) {
                    /** @var ModelEndpoint $endpoint */
                    $endpoint = $this->insert(ModelEndpoint::class, [
                        'model_id' => $modelId,
                        'provider' => $provider,
                        'status' => 'discovered',
                        'last_discovered_at' => $now,
                        'consecutive_failures' => 0,
                        'updated_at' => $now,
                    ]);
                } else {
                    $endpoint->setFields(['last_discovered_at' => $now, 'updated_at' => $now]);
                    $endpoint->save();
                }
            }

            foreach (ModelEndpoint::getAll() as $endpoint) {
                if (!in_array($endpoint->model_id, $normalized, true)) {
                    $endpoint->setFields([
                        'status' => 'unavailable',
                        'updated_at' => $now,
                        'last_error' => 'Model disappeared from the latest free-model catalogue.',
                    ]);
                    $endpoint->save();
                }
            }

            $event = $this->emit('models.discovered', [
                'free_model_ids' => $normalized,
                'count' => count($normalized),
            ]);
            return ['models' => $this->listModelEndpoints(), 'event' => $event->getData()];
        });
    }

    /** @return list<array<string, mixed>> */
    public function listModelEndpoints(): array
    {
        return $this->records(ModelEndpoint::getAll([
            'order' => ['consecutive_failures' => 'ASC', 'latency_ms' => 'ASC'],
        ]));
    }

    /** @return list<string> */
    public function selectableFreeModels(): array
    {
        $now = time();
        $candidates = array_values(array_filter(
            ModelEndpoint::getAll(),
            function (ModelEndpoint $endpoint) use ($now): bool {
                $cooldown = $this->timestamp($endpoint->cooldown_until);
                if ($endpoint->status === 'unavailable' && $cooldown === null) {
                    return false;
                }
                return $cooldown === null || $cooldown <= $now;
            }
        ));
        usort($candidates, static function (ModelEndpoint $left, ModelEndpoint $right): int {
            $leftLatency = $left->latency_ms === null ? PHP_INT_MAX : (int) $left->latency_ms;
            $rightLatency = $right->latency_ms === null ? PHP_INT_MAX : (int) $right->latency_ms;
            return (int) $left->consecutive_failures <=> (int) $right->consecutive_failures
                ?: $leftLatency <=> $rightLatency;
        });
        return array_values(array_map(
            static fn (ModelEndpoint $endpoint): string => (string) $endpoint->model_id,
            $candidates
        ));
    }

    public function recordModelResult(
        string $modelId,
        bool $succeeded,
        int $latencyMs,
        ?string $error = null
    ): array {
        $this->requireText($modelId, 'model id');
        if ($latencyMs < 0) {
            throw new InvalidArgumentException('model latency cannot be negative.');
        }
        $endpoint = ModelEndpoint::getByField('model_id', $modelId);
        if (!$endpoint instanceof ModelEndpoint) {
            throw new RuntimeException(sprintf('Model %s is not in the discovered free-model pool.', $modelId));
        }

        return $this->transaction(function () use ($endpoint, $succeeded, $latencyMs, $error): array {
            $now = time();
            $failures = $succeeded ? 0 : (int) $endpoint->consecutive_failures + 1;
            $cooldown = $succeeded ? null : $now + min(3600, 30 * (2 ** min($failures, 7)));
            $endpoint->setFields([
                'status' => $succeeded ? 'available' : ($failures >= 3 ? 'unavailable' : 'degraded'),
                'last_probed_at' => $now,
                'last_success_at' => $succeeded ? $now : $endpoint->last_success_at,
                'consecutive_failures' => $failures,
                'latency_ms' => $latencyMs,
                'cooldown_until' => $cooldown,
                'last_error' => $succeeded ? null : $error,
                'updated_at' => $now,
            ]);
            $endpoint->save();
            $event = $this->emit($succeeded ? 'model.available' : 'model.failed', [
                'model_id' => $endpoint->model_id,
                'latency_ms' => $latencyMs,
                'consecutive_failures' => $failures,
                'cooldown_until' => $cooldown,
                'error' => $error,
            ]);
            return ['model' => $endpoint->getData(), 'event' => $event->getData()];
        });
    }

    public function setSelfModelFact(string $key, string $value, float $confidence, string $evidence): array
    {
        $this->requireText($key, 'key');
        $this->requireText($value, 'value');
        $this->requireText($evidence, 'evidence');
        $this->requireUnitInterval($confidence, 'confidence');

        return $this->transaction(function () use ($key, $value, $confidence, $evidence): array {
            $event = $this->emit('self_model.observed', [
                'fact_key' => $key,
                'fact_value' => $value,
                'confidence' => $confidence,
                'evidence' => $evidence,
            ]);

            $fact = SelfModelFact::getByField('fact_key', $key);
            if ($fact === null) {
                /** @var SelfModelFact $fact */
                $fact = $this->insert(SelfModelFact::class, [
                    'fact_key' => $key,
                    'fact_value' => $value,
                    'confidence' => $confidence,
                    'evidence_event_id' => $event->id,
                    'updated_at' => time(),
                ]);
            } else {
                $fact->setFields([
                    'fact_value' => $value,
                    'confidence' => $confidence,
                    'evidence_event_id' => $event->id,
                    'updated_at' => time(),
                ]);
                $fact->save();
            }

            return ['fact' => $fact->getData(), 'event' => $event->getData()];
        });
    }

    /** @return list<array<string, mixed>> */
    public function listSelfModelFacts(): array
    {
        return $this->records(SelfModelFact::getAll(['order' => ['fact_key' => 'ASC']]));
    }

    public function appraise(
        ?int $eventId,
        ?int $intentionId,
        float $relevance,
        float $urgency,
        float $controllability,
        float $uncertainty,
        float $commitmentImpact
    ): array {
        if ($eventId === null && $intentionId === null) {
            throw new InvalidArgumentException('An appraisal requires an event or intention.');
        }
        if ($eventId !== null) {
            $this->requireEvent($eventId);
        }
        if ($intentionId !== null) {
            $this->requireIntention($intentionId);
        }

        foreach ([
            'relevance' => $relevance,
            'urgency' => $urgency,
            'controllability' => $controllability,
            'uncertainty' => $uncertainty,
            'commitment impact' => $commitmentImpact,
        ] as $name => $value) {
            $this->requireUnitInterval($value, $name);
        }

        $attentionScore = round(($relevance + $urgency + $uncertainty + $commitmentImpact) / 4, 3);
        /** @var Appraisal $appraisal */
        $appraisal = $this->insert(Appraisal::class, [
            'event_id' => $eventId,
            'intention_id' => $intentionId,
            'relevance' => $relevance,
            'urgency' => $urgency,
            'controllability' => $controllability,
            'uncertainty' => $uncertainty,
            'commitment_impact' => $commitmentImpact,
            'attention_score' => $attentionScore,
        ]);

        return $appraisal->getData();
    }

    public function status(): array
    {
        return [
            'active_intentions' => $this->records(Intention::getAllByWhere(
                ['status' => 'active'],
                ['order' => ['updated_at' => 'DESC']]
            )),
            'blocked_intentions' => $this->records(Intention::getAllByWhere(
                ['status' => 'blocked'],
                ['order' => ['updated_at' => 'DESC']]
            )),
            'pending_actions' => $this->records(ActionTrace::getAllByWhere(
                ['status' => 'pending'],
                ['order' => ['created_at' => 'DESC']]
            )),
            'recent_discrepancies' => $this->records(ActionTrace::getAllByWhere(
                ['match_status' => 'mismatched'],
                ['order' => ['completed_at' => 'DESC'], 'limit' => 10]
            )),
            'working_memory' => $this->records(Memory::getAllByWhere(
                ['tier' => 'working', 'status' => 'active'],
                ['order' => ['updated_at' => 'DESC'], 'limit' => 25]
            )),
            'semantic_memory' => $this->records(Memory::getAllByWhere(
                ['tier' => 'semantic', 'status' => 'active'],
                ['order' => ['updated_at' => 'DESC'], 'limit' => 25]
            )),
            'needs' => $this->listNeeds(),
            'heartbeat' => $this->heartbeatStatus(),
            'proposed_thoughts' => $this->records(ThoughtArtifact::getAllByWhere(
                ['status' => 'proposed'],
                ['order' => ['created_at' => 'DESC'], 'limit' => 25]
            )),
            'self_model' => $this->listSelfModelFacts(),
            'top_appraisals' => $this->records(Appraisal::getAll([
                'order' => ['attention_score' => 'DESC'],
                'limit' => 10,
            ])),
        ];
    }

    public function checkpoint(string $reason): array
    {
        $this->requireText($reason, 'checkpoint reason');
        $snapshot = $this->status();
        /** @var Checkpoint $checkpoint */
        $checkpoint = $this->insert(Checkpoint::class, [
            'reason' => $reason,
            'snapshot' => $snapshot,
        ]);

        return $checkpoint->getData();
    }

    private function emit(string $kind, array $payload): Event
    {
        /** @var Event $event */
        $event = $this->insert(Event::class, ['kind' => $kind, 'payload' => $payload]);
        return $event;
    }

    private function ensureDefaultNeeds(): void
    {
        $defaults = [
            [
                'need_key' => 'cognitive_stimulation',
                'description' => 'Maintain enough meaningful cognitive activity to avoid stagnation; unmet pressure is experienced operationally as boredom and routes attention into daydreaming.',
                'authority' => 'user',
                'pressure' => 0.0,
                'growth_per_hour' => 0.10,
                'trigger_threshold' => 0.40,
            ],
            [
                'need_key' => 'epistemic_novelty',
                'description' => 'Remain curious about the world by seeking unfamiliar evidence, alternative explanations, and surprising connections.',
                'authority' => 'user',
                'pressure' => 0.20,
                'growth_per_hour' => 0.04,
                'trigger_threshold' => 0.40,
            ],
        ];

        $this->transaction(function () use ($defaults): void {
            foreach ($defaults as $values) {
                if (Need::getByField('need_key', $values['need_key']) instanceof Need) {
                    continue;
                }

                /** @var Need $need */
                $need = $this->insert(Need::class, array_merge($values, [
                    'last_satisfied_at' => time(),
                    'updated_at' => time(),
                    'status' => 'active',
                ]));
                $this->emit('need.created', [
                    'need_id' => $need->id,
                    'need_key' => $need->need_key,
                    'authority' => $need->authority,
                ]);
            }
        });
    }

    /** @return list<string> */
    private function quickCheck(): array
    {
        $statement = $this->connection->query('PRAGMA quick_check');
        if ($statement === false) {
            return ['quick_check query failed'];
        }
        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function highBrainBusy(): bool
    {
        return CycleRun::getAllByWhere([
            'status' => 'running',
            'cognitive_layer' => CognitiveLayer::HIGH->value,
        ], ['limit' => 1]) !== [];
    }

    /** @return array{run: CycleRun, missed_intervals: int}|null */
    private function claimRhythm(
        Rhythm $rhythm,
        string $nodeId,
        int $now,
        int $leaseSeconds
    ): ?array {
        return $this->transaction(function () use ($rhythm, $nodeId, $now, $leaseSeconds): ?array {
            $scheduledFor = $this->timestamp($rhythm->next_due_at) ?? $now;
            $interval = max(1, (int) $rhythm->interval_seconds);
            $missed = max(0, intdiv(max(0, $now - $scheduledFor), $interval));
            $newFence = (int) $rhythm->fencing_token + 1;
            $statement = $this->connection->prepare(
                "UPDATE rhythms
                 SET status = 'running', lease_owner = :owner,
                     lease_expires_at = :lease_expires, last_started_at = :now,
                     sequence = sequence + 1, fencing_token = fencing_token + 1,
                     updated_at = :now, last_error = NULL
                 WHERE id = :id AND next_due_at <= :now
                   AND status != 'paused'
                   AND (status != 'running' OR lease_expires_at IS NULL OR lease_expires_at <= :now)"
            );
            $formattedNow = date('Y-m-d H:i:s', $now);
            $statement->execute([
                ':owner' => $nodeId,
                ':lease_expires' => date('Y-m-d H:i:s', $now + $leaseSeconds),
                ':now' => $formattedNow,
                ':id' => $rhythm->id,
            ]);
            if ($statement->rowCount() !== 1) {
                return null;
            }

            /** @var CycleRun $run */
            $run = $this->insert(CycleRun::class, [
                'rhythm_id' => $rhythm->id,
                'cognitive_layer' => $rhythm->cognitive_layer,
                'scheduled_for' => $scheduledFor,
                'node_id' => $nodeId,
                'fencing_token' => $newFence,
                'status' => 'running',
                'event_watermark' => $this->eventWatermark(),
                'budget' => $this->rhythmBudget((string) $rhythm->rhythm_key),
                'output' => ['missed_intervals' => $missed],
            ]);
            $this->emit('cycle.started', [
                'run_id' => $run->id,
                'rhythm_id' => $rhythm->id,
                'rhythm_key' => $rhythm->rhythm_key,
                'cognitive_layer' => $rhythm->cognitive_layer,
                'node_id' => $nodeId,
                'fencing_token' => $newFence,
                'scheduled_for' => $scheduledFor,
                'missed_intervals' => $missed,
            ]);

            return ['run' => $run, 'missed_intervals' => $missed];
        });
    }

    private function coalesceHighRhythm(Rhythm $rhythm, int $now): array
    {
        $dueAt = $this->timestamp($rhythm->next_due_at) ?? $now;
        $interval = max(1, (int) $rhythm->interval_seconds);
        $missed = max(1, intdiv(max(0, $now - $dueAt), $interval) + 1);
        $rhythm->setFields([
            'next_due_at' => $now + $interval,
            'updated_at' => $now,
            'status' => 'idle',
        ]);
        $rhythm->save();
        $event = $this->emit('rhythm.coalesced', [
            'rhythm_id' => $rhythm->id,
            'rhythm_key' => $rhythm->rhythm_key,
            'missed_intervals' => $missed,
            'reason' => 'high_brain_single_flight_busy',
            'next_due_at' => $now + $interval,
        ]);

        return [
            'rhythm' => $rhythm->rhythm_key,
            'status' => 'coalesced_while_sleeping_or_busy',
            'missed_intervals' => $missed,
            'event' => $event->getData(),
        ];
    }

    /** @return array<string, mixed> */
    private function lowBrainPulse(CycleRun $run): array
    {
        $integrity = $this->quickCheck();
        $now = time();
        $needs = $this->accrueNeeds($now);
        $recoveredWork = $this->recoverExpiredWorkLeases($now);
        $recoveredCycles = $this->recoverExpiredCycleLeases($now, (int) $run->id);

        return [
            'state' => 'low_brain_awake_high_brain_sleeping',
            'run_id' => $run->id,
            'integrity' => $integrity,
            'needs' => $needs,
            'recovered_work_item_ids' => $recoveredWork,
            'recovered_cycle_ids' => $recoveredCycles,
            'model_invoked' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function prepareHighBrainMoment(CycleRun $run, Rhythm $rhythm): array
    {
        $rhythmKey = (string) $rhythm->rhythm_key;
        $needs = $this->accrueNeeds(time());
        $deterministic = ['needs' => $needs];
        $workType = '';
        $prompt = '';
        $inputRefs = ['need_keys' => array_column($needs, 'need_key')];

        if ($rhythmKey === 'reflect_5m') {
            $triggered = array_values(array_filter(
                $needs,
                static fn (array $need): bool => $need['status'] === 'active' && $need['triggered']
            ));
            if ($triggered === []) {
                return ['state' => 'slept_through_reflection', 'needs' => $needs, 'work_item' => null];
            }
            if ($this->daydreamCooldownActive()) {
                return ['state' => 'daydream_cooldown_sleep', 'needs' => $needs, 'work_item' => null];
            }
            $daydream = $this->daydream('five-minute boredom and curiosity wake trigger', (int) $run->id);
            $deterministic['daydream_artifact_id'] = $daydream['artifact']['id'];
            $inputRefs['artifact_id'] = $daydream['artifact']['id'];
            $workType = 'curiosity_reflection';
            $prompt = 'Return one JSON object with keys kind, content, confidence, and challenged_assumption. Generate one curious, surprising alternative to a common assumption in cognitive-agent architecture. This is an unverified proposal. Do not claim observation, memory, consciousness, or permission to act.';
        } elseif ($rhythmKey === 'consolidate_hourly') {
            $sleep = $this->sleep('hourly consolidation and repair proposal pass', (int) $run->id);
            $deterministic['sleep_event_id'] = $sleep['event']['id'];
            $inputRefs['sleep_event_id'] = $sleep['event']['id'];
            $workType = 'assumption_challenge';
            $prompt = 'Return one JSON object with keys kind, content, confidence, and challenged_assumption. Challenge one architectural assumption behind layered heartbeats, needs, sleep, or bounded worker cognition. Offer a discriminating experiment. Treat everything as an unverified proposal and request no tools or actions.';
        } elseif ($rhythmKey === 'sleep_daily') {
            $backup = $this->backupDatabase('daily-sleep');
            $sleep = $this->sleep('daily deep replay and repair proposal pass', (int) $run->id);
            $deterministic['backup'] = $backup;
            $deterministic['sleep_event_id'] = $sleep['event']['id'];
            $inputRefs['backup_sha256'] = $backup['sha256'];
            $inputRefs['sleep_event_id'] = $sleep['event']['id'];
            $workType = 'daily_cognitive_audit';
            $prompt = 'Return one JSON object with keys kind, content, confidence, and challenged_assumption. Audit a generic event-sourced cognitive architecture for one subtle failure mode involving provenance, self-repair, needs, sleep, or worker autonomy. Propose one bounded test. Do not assert personal facts or request actions.';
        } else {
            return ['state' => 'unknown_high_rhythm', 'work_item' => null];
        }

        $budget = $this->rhythmBudget($rhythmKey);
        $queued = $this->enqueueWork(
            parentRunId: (int) $run->id,
            parentIntentionId: null,
            workType: $workType,
            prompt: $prompt,
            inputRefs: $inputRefs,
            tokenBudget: $budget['tokens'],
            wallBudgetSeconds: $budget['wall_seconds'],
            idempotencyKey: sprintf('cycle:%d:%s', $run->id, $workType)
        );
        $deterministic['work_item'] = $queued['work_item'];
        $run->setField('output', $deterministic);
        $run->save();

        return $deterministic;
    }

    /** @return array{tokens: int, wall_seconds: int, max_workers: int} */
    private function rhythmBudget(string $rhythmKey): array
    {
        return match ($rhythmKey) {
            'pulse_30s' => ['tokens' => 0, 'wall_seconds' => 20, 'max_workers' => 0],
            'reflect_5m' => ['tokens' => 512, 'wall_seconds' => 120, 'max_workers' => 1],
            'consolidate_hourly' => ['tokens' => 768, 'wall_seconds' => 180, 'max_workers' => 1],
            'sleep_daily' => ['tokens' => 1024, 'wall_seconds' => 300, 'max_workers' => 1],
            default => ['tokens' => 256, 'wall_seconds' => 120, 'max_workers' => 1],
        };
    }

    private function eventWatermark(): int
    {
        $value = $this->connection->query('SELECT COALESCE(MAX(id), 0) FROM events')?->fetchColumn();
        return $value === false ? 0 : (int) $value;
    }

    /** @param array<string, mixed> $output */
    private function completeCycleRun(
        CycleRun $run,
        Rhythm $rhythm,
        string $nodeId,
        array $output
    ): array {
        return $this->transaction(function () use ($run, $rhythm, $nodeId, $output): array {
            $this->requireCurrentRhythmLease($rhythm, $run, $nodeId);
            $now = time();
            $run->setFields([
                'completed_at' => $now,
                'status' => 'completed',
                'output' => $output,
            ]);
            $run->save();

            $statement = $this->connection->prepare(
                "UPDATE rhythms
                 SET status = 'idle', last_completed_at = :now,
                     next_due_at = :next_due, lease_owner = NULL,
                     lease_expires_at = NULL, updated_at = :now, last_error = NULL
                 WHERE id = :id AND fencing_token = :fence AND lease_owner = :owner"
            );
            $statement->execute([
                ':now' => date('Y-m-d H:i:s', $now),
                ':next_due' => date('Y-m-d H:i:s', $now + (int) $rhythm->interval_seconds),
                ':id' => $rhythm->id,
                ':fence' => $run->fencing_token,
                ':owner' => $nodeId,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Rhythm lease was lost before cycle completion.');
            }

            $event = $this->emit('cycle.completed', [
                'run_id' => $run->id,
                'rhythm_id' => $rhythm->id,
                'rhythm_key' => $rhythm->rhythm_key,
                'node_id' => $nodeId,
                'fencing_token' => $run->fencing_token,
                'state' => 'sleeping',
            ]);

            return [
                'rhythm' => $rhythm->rhythm_key,
                'status' => 'completed',
                'run' => $run->getData(),
                'event' => $event->getData(),
            ];
        });
    }

    private function failCycleRun(
        CycleRun $run,
        Rhythm $rhythm,
        string $nodeId,
        string $error
    ): void {
        $this->transaction(function () use ($run, $rhythm, $nodeId, $error): void {
            $now = time();
            $run->setFields([
                'completed_at' => $now,
                'status' => 'failed',
                'error' => $error,
            ]);
            $run->save();

            $statement = $this->connection->prepare(
                "UPDATE rhythms
                 SET status = 'failed', next_due_at = :next_due,
                     lease_owner = NULL, lease_expires_at = NULL,
                     updated_at = :now, last_error = :error
                 WHERE id = :id AND fencing_token = :fence AND lease_owner = :owner"
            );
            $statement->execute([
                ':next_due' => date('Y-m-d H:i:s', $now + (int) $rhythm->interval_seconds),
                ':now' => date('Y-m-d H:i:s', $now),
                ':error' => $error,
                ':id' => $rhythm->id,
                ':fence' => $run->fencing_token,
                ':owner' => $nodeId,
            ]);
            $this->emit('cycle.failed', [
                'run_id' => $run->id,
                'rhythm_id' => $rhythm->id,
                'rhythm_key' => $rhythm->rhythm_key,
                'node_id' => $nodeId,
                'fencing_token' => $run->fencing_token,
                'error' => $error,
                'state' => 'sleeping',
            ]);
        });
    }

    private function requireCurrentRhythmLease(Rhythm $rhythm, CycleRun $run, string $nodeId): void
    {
        $statement = $this->connection->prepare(
            'SELECT status, fencing_token, lease_owner, lease_expires_at FROM rhythms WHERE id = :id'
        );
        $statement->execute([':id' => $rhythm->id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || $row['status'] !== 'running'
            || (int) $row['fencing_token'] !== (int) $run->fencing_token
            || $row['lease_owner'] !== $nodeId
            || ($this->timestamp($row['lease_expires_at']) ?? 0) < time()
        ) {
            throw new RuntimeException('The rhythm fencing lease is no longer current.');
        }
    }

    /** @return list<int> */
    private function recoverExpiredWorkLeases(int $now): array
    {
        $recovered = [];
        foreach (WorkItem::getAllByWhere(['status' => 'leased']) as $work) {
            $leaseExpires = $this->timestamp($work->lease_expires_at);
            if ($leaseExpires !== null && $leaseExpires > $now) {
                continue;
            }
            $work->setFields([
                'status' => 'queued',
                'lease_owner' => null,
                'lease_expires_at' => null,
                'updated_at' => $now,
                'error' => 'Previous worker lease expired; work returned to queue.',
            ]);
            $work->save();
            $this->emit('work.requeued', [
                'work_item_id' => $work->id,
                'reason' => 'expired_lease',
                'fencing_token' => $work->fencing_token,
            ]);
            $recovered[] = (int) $work->id;
        }
        return $recovered;
    }

    /** @return list<int> */
    private function recoverExpiredCycleLeases(int $now, ?int $excludeRunId = null): array
    {
        $recovered = [];
        foreach (Rhythm::getAllByWhere(['status' => 'running']) as $rhythm) {
            $leaseExpires = $this->timestamp($rhythm->lease_expires_at);
            if ($leaseExpires !== null && $leaseExpires > $now) {
                continue;
            }

            $runs = CycleRun::getAllByWhere([
                'rhythm_id' => $rhythm->id,
                'status' => 'running',
            ]);
            foreach ($runs as $run) {
                if ($excludeRunId !== null && (int) $run->id === $excludeRunId) {
                    continue 2;
                }
                if ((int) $run->fencing_token !== (int) $rhythm->fencing_token) {
                    $run->setFields([
                        'completed_at' => $now,
                        'status' => 'cancelled',
                        'error' => 'Superseded by a newer rhythm fencing token.',
                    ]);
                    $run->save();
                    $recovered[] = (int) $run->id;
                    continue;
                }

                foreach (WorkItem::getAllByWhere(['parent_run_id' => $run->id]) as $work) {
                    if (!in_array($work->status, ['queued', 'leased'], true)) {
                        continue;
                    }
                    $work->setFields([
                        'completed_at' => $now,
                        'updated_at' => $now,
                        'status' => 'cancelled',
                        'lease_owner' => null,
                        'lease_expires_at' => null,
                        'error' => 'Parent high-brain execution window expired; work was dropped instead of backlogged.',
                    ]);
                    $work->save();
                    $this->emit('work.cancelled', [
                        'work_item_id' => $work->id,
                        'parent_run_id' => $run->id,
                        'reason' => 'parent_cycle_lease_expired',
                    ]);
                }

                $run->setFields([
                    'completed_at' => $now,
                    'status' => 'cancelled',
                    'error' => 'High-brain execution window expired; cycle was dropped instead of replayed.',
                ]);
                $run->save();
                $this->emit('cycle.cancelled', [
                    'run_id' => $run->id,
                    'rhythm_id' => $rhythm->id,
                    'rhythm_key' => $rhythm->rhythm_key,
                    'fencing_token' => $run->fencing_token,
                    'reason' => 'expired_lease_no_catch_up',
                    'state' => 'sleeping',
                ]);
                $recovered[] = (int) $run->id;
            }

            $rhythm->setFields([
                'status' => 'idle',
                'next_due_at' => $now + max(1, (int) $rhythm->interval_seconds),
                'lease_owner' => null,
                'lease_expires_at' => null,
                'updated_at' => $now,
                'last_error' => 'Expired execution window recovered without catch-up replay.',
            ]);
            $rhythm->save();
        }
        return array_values(array_unique($recovered));
    }

    /** @return array{path: string, sha256: string, bytes: int, quick_check: list<string>} */
    public function backupDatabase(string $reason): array
    {
        $this->requireText($reason, 'backup reason');
        $databaseStatement = $this->connection->query('PRAGMA database_list');
        $database = $databaseStatement?->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $databaseStatement?->closeCursor();
        $main = array_values(array_filter(
            $database,
            static fn (array $row): bool => ($row['name'] ?? null) === 'main'
        ));
        $source = is_string($main[0]['file'] ?? null) ? (string) $main[0]['file'] : '';
        if ($source === '') {
            throw new RuntimeException('Cannot back up an in-memory or unidentified SQLite database.');
        }

        $directory = dirname($source) . '/backups';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create database backup directory: %s', $directory));
        }
        $stem = pathinfo($source, PATHINFO_FILENAME);
        $target = sprintf(
            '%s/%s-%s-%s.sqlite',
            $directory,
            $stem,
            gmdate('Ymd-His'),
            bin2hex(random_bytes(3))
        );
        $backupConnection = new PDO('sqlite:' . $source);
        $backupConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $backupConnection->exec('PRAGMA busy_timeout = 5000');
        $quotedTarget = $backupConnection->quote($target);
        if (!is_string($quotedTarget)) {
            throw new RuntimeException('Unable to quote the SQLite backup path.');
        }
        $backupConnection->exec('VACUUM INTO ' . $quotedTarget);
        chmod($target, 0600);

        $backup = new PDO('sqlite:' . $target);
        $check = array_values(array_map(
            'strval',
            $backup->query('PRAGMA quick_check')?->fetchAll(PDO::FETCH_COLUMN) ?: []
        ));
        if ($check !== ['ok']) {
            throw new RuntimeException('New SQLite backup failed quick_check: ' . implode('; ', $check));
        }

        $result = [
            'path' => $target,
            'sha256' => hash_file('sha256', $target),
            'bytes' => filesize($target),
            'quick_check' => $check,
        ];
        $this->emit('backup.created', array_merge($result, ['reason' => $reason]));
        return $result;
    }

    private function extendParentRhythmLease(WorkItem $work, int $leaseUntil): void
    {
        if ($work->parent_run_id === null) {
            return;
        }
        $run = CycleRun::getByID((int) $work->parent_run_id);
        if (!$run instanceof CycleRun || $run->status !== 'running') {
            return;
        }
        $statement = $this->connection->prepare(
            "UPDATE rhythms
             SET lease_expires_at = CASE
                     WHEN lease_expires_at IS NULL OR lease_expires_at < :lease_until
                         THEN :lease_until
                     ELSE lease_expires_at
                 END,
                 updated_at = :now
             WHERE id = :id AND status = 'running'
               AND fencing_token = :fence AND lease_owner = :owner"
        );
        $statement->execute([
            ':lease_until' => date('Y-m-d H:i:s', $leaseUntil),
            ':now' => date('Y-m-d H:i:s'),
            ':id' => $run->rhythm_id,
            ':fence' => $run->fencing_token,
            ':owner' => $run->node_id,
        ]);
    }

    /** @param array<string, mixed> $result */
    private function validateWorkerProposal(array $result): void
    {
        $required = ['kind', 'content', 'confidence', 'challenged_assumption'];
        $keys = array_keys($result);
        sort($required);
        sort($keys);
        if ($keys !== $required) {
            throw new InvalidArgumentException(
                'Worker proposal must contain exactly kind, content, confidence, and challenged_assumption.'
            );
        }
        foreach (['kind', 'content', 'challenged_assumption'] as $key) {
            if (!is_string($result[$key]) || trim($result[$key]) === '') {
                throw new InvalidArgumentException(sprintf('Worker proposal %s must be non-empty text.', $key));
            }
        }
        if (strlen($result['kind']) > 96 || strlen($result['content']) > 8000) {
            throw new InvalidArgumentException('Worker proposal exceeds the bounded output size.');
        }
        if (!is_int($result['confidence']) && !is_float($result['confidence'])) {
            throw new InvalidArgumentException('Worker proposal confidence must be numeric.');
        }
        $this->requireUnitInterval((float) $result['confidence'], 'worker proposal confidence');
    }

    /** @return list<array<string, mixed>> */
    private function cognitiveFindings(int $now): array
    {
        $findings = [];

        foreach (ActionTrace::getAllByWhere(['status' => 'pending']) as $action) {
            $createdAt = $this->timestamp($action->created_at);
            if ($createdAt !== null && $now - $createdAt >= self::STALE_ACTION_SECONDS) {
                $findings[] = [
                    'kind' => 'stale_pending_action',
                    'severity' => 'review',
                    'source' => ['action_id' => $action->id, 'event_id' => $action->start_event_id],
                    'description' => sprintf(
                        'Action %d has remained pending for at least %d seconds; determine its real outcome before continuing it.',
                        $action->id,
                        self::STALE_ACTION_SECONDS
                    ),
                    'automatic_repair' => false,
                ];
            }
        }

        foreach (Memory::getAllByWhere(['status' => 'active']) as $memory) {
            if ($memory->tier === 'semantic'
                && $memory->source_event_id === null
                && $memory->source_memory_id === null
            ) {
                $findings[] = [
                    'kind' => 'semantic_without_provenance',
                    'severity' => 'integrity',
                    'source' => ['memory_id' => $memory->id],
                    'description' => sprintf('Semantic memory %d has no source event or memory.', $memory->id),
                    'automatic_repair' => false,
                ];
            }
            if ($memory->source_event_id !== null
                && !Event::getByID((int) $memory->source_event_id) instanceof Event
            ) {
                $findings[] = [
                    'kind' => 'dangling_memory_event',
                    'severity' => 'integrity',
                    'source' => ['memory_id' => $memory->id, 'event_id' => $memory->source_event_id],
                    'description' => sprintf('Memory %d references missing event %d.', $memory->id, $memory->source_event_id),
                    'automatic_repair' => false,
                ];
            }
            if ($memory->source_memory_id !== null
                && !Memory::getByID((int) $memory->source_memory_id) instanceof Memory
            ) {
                $findings[] = [
                    'kind' => 'dangling_memory_source',
                    'severity' => 'integrity',
                    'source' => ['memory_id' => $memory->id, 'source_memory_id' => $memory->source_memory_id],
                    'description' => sprintf('Memory %d references missing source memory %d.', $memory->id, $memory->source_memory_id),
                    'automatic_repair' => false,
                ];
            }
        }

        foreach (SelfModelFact::getAll() as $fact) {
            if (!Event::getByID((int) $fact->evidence_event_id) instanceof Event) {
                $findings[] = [
                    'kind' => 'dangling_self_model_evidence',
                    'severity' => 'integrity',
                    'source' => ['self_model_fact_id' => $fact->id, 'event_id' => $fact->evidence_event_id],
                    'description' => sprintf(
                        'Self-model fact %d references missing evidence event %d.',
                        $fact->id,
                        $fact->evidence_event_id
                    ),
                    'automatic_repair' => false,
                ];
            }
        }

        return $findings;
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return list<array<string, mixed>>
     */
    private function createRepairArtifacts(array $findings, ?int $runId): array
    {
        $candidates = [];
        foreach ($findings as $finding) {
            $candidates[] = [
                'kind' => 'diagnostic_repair',
                'provenance' => 'inferred',
                'source_ids' => $finding['source'],
                'content' => sprintf(
                    'Repair proposal, not an observation: %s Preserve the source record until a bounded check determines the correct repair.',
                    $finding['description']
                ),
                'confidence' => 0.8,
            ];
        }

        foreach (ActionTrace::getAllByWhere(
            ['match_status' => 'mismatched'],
            ['order' => ['completed_at' => 'DESC'], 'limit' => 10]
        ) as $action) {
            $candidates[] = [
                'kind' => 'prediction_counterfactual',
                'provenance' => 'counterfactual',
                'source_ids' => [
                    'action_id' => $action->id,
                    'completion_event_id' => $action->completion_event_id,
                ],
                'content' => sprintf(
                    'Synthetic counterfactual: before repeating action %d, apply this candidate repair—%s—then test whether it reduces the recorded expectation mismatch. Do not assume it works merely because it is plausible.',
                    $action->id,
                    $action->repair_note
                ),
                'confidence' => 0.5,
            ];
        }

        $artifacts = [];
        foreach ($candidates as $candidate) {
            $hash = hash('sha256', json_encode(
                [$candidate['kind'], $candidate['source_ids'], $candidate['content']],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
            $artifact = ThoughtArtifact::getByField('content_hash', $hash);
            if (!$artifact instanceof ThoughtArtifact) {
                /** @var ThoughtArtifact $artifact */
                $artifact = $this->insert(ThoughtArtifact::class, [
                    'run_id' => $runId,
                    'kind' => $candidate['kind'],
                    'content' => $candidate['content'],
                    'confidence' => $candidate['confidence'],
                    'provenance' => $candidate['provenance'],
                    'source_ids' => $candidate['source_ids'],
                    'status' => 'proposed',
                    'content_hash' => $hash,
                ]);
                $this->emit('thought.proposed', [
                    'artifact_id' => $artifact->id,
                    'run_id' => $runId,
                    'kind' => $artifact->kind,
                    'provenance' => $artifact->provenance,
                    'synthetic' => true,
                    'source_ids' => $candidate['source_ids'],
                    'external_action_authorized' => false,
                ]);
            }
            $artifacts[] = $artifact->getData();
        }

        return $artifacts;
    }

    private function ensureDefaultRhythms(): void
    {
        $defaults = [
            ['rhythm_key' => 'pulse_30s', 'interval_seconds' => 30, 'layer' => CognitiveLayer::LOW],
            ['rhythm_key' => 'reflect_5m', 'interval_seconds' => 300, 'layer' => CognitiveLayer::HIGH],
            ['rhythm_key' => 'consolidate_hourly', 'interval_seconds' => 3600, 'layer' => CognitiveLayer::HIGH],
            ['rhythm_key' => 'sleep_daily', 'interval_seconds' => 86400, 'layer' => CognitiveLayer::HIGH],
        ];

        $this->transaction(function () use ($defaults): void {
            $now = time();
            foreach ($defaults as $values) {
                if (Rhythm::getByField('rhythm_key', $values['rhythm_key']) instanceof Rhythm) {
                    continue;
                }

                /** @var Rhythm $rhythm */
                $rhythm = $this->insert(Rhythm::class, [
                    'rhythm_key' => $values['rhythm_key'],
                    'interval_seconds' => $values['interval_seconds'],
                    'cognitive_layer' => $values['layer']->value,
                    'next_due_at' => $now + $values['interval_seconds'],
                    'sequence' => 0,
                    'fencing_token' => 0,
                    'status' => 'idle',
                    'updated_at' => $now,
                ]);
                $this->emit('rhythm.created', [
                    'rhythm_id' => $rhythm->id,
                    'rhythm_key' => $rhythm->rhythm_key,
                    'interval_seconds' => $rhythm->interval_seconds,
                    'cognitive_layer' => $rhythm->cognitive_layer,
                ]);
            }
        });
    }

    private function normalizeSearchText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', strtolower($text)) ?? strtolower($text));
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $timestamp = strtotime($value);
            return $timestamp === false ? null : $timestamp;
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function needState(Need $need, int $now): array
    {
        $storedPressure = (float) $need->pressure;
        $updatedAt = $this->timestamp($need->updated_at) ?? $now;
        $elapsedHours = max(0, $now - $updatedAt) / 3600;
        $pressure = $need->status === 'active'
            ? min(1.0, $storedPressure + ((float) $need->growth_per_hour * $elapsedHours))
            : $storedPressure;

        return array_merge($need->getData(), [
            'stored_pressure' => round($storedPressure, 3),
            'pressure' => round($pressure, 3),
            'evaluated_at' => $now,
            'triggered' => $need->status === 'active'
                && $pressure >= (float) $need->trigger_threshold,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function accrueNeeds(int $now): array
    {
        return $this->transaction(function () use ($now): array {
            $states = [];
            foreach (Need::getAll(['order' => ['need_key' => 'ASC']]) as $need) {
                $state = $this->needState($need, $now);
                $need->setFields([
                    'pressure' => $state['pressure'],
                    'updated_at' => $now,
                ]);
                $need->save();
                $states[] = $this->needState($need, $now);
            }
            return $states;
        });
    }

    /** @return array<string, mixed> */
    private function satisfyNeedRecord(Need $need, float $amount, string $source, int $now): array
    {
        $before = $this->needState($need, $now);
        $afterPressure = max(0.0, (float) $before['pressure'] - $amount);
        $need->setFields([
            'pressure' => $afterPressure,
            'last_satisfied_at' => $now,
            'updated_at' => $now,
        ]);
        $need->save();
        $after = $this->needState($need, $now);
        $event = $this->emit('need.satisfied', [
            'need_id' => $need->id,
            'need_key' => $need->need_key,
            'source' => $source,
            'amount' => $amount,
            'before_pressure' => $before['pressure'],
            'after_pressure' => $after['pressure'],
        ]);

        return ['need' => $after, 'event' => $event->getData()];
    }

    private function randomMemoryFragment(string $content): string
    {
        $words = preg_split('/\s+/u', trim($content)) ?: [];
        if ($words === []) {
            return '';
        }
        $length = min(count($words), random_int(4, 8));
        $start = count($words) === $length ? 0 : random_int(0, count($words) - $length);
        return implode(' ', array_slice($words, $start, $length));
    }

    private function daydreamCooldownActive(): bool
    {
        $artifacts = ThoughtArtifact::getAllByWhere(
            ['provenance' => 'daydream'],
            ['order' => ['created_at' => 'DESC'], 'limit' => 1]
        );
        if ($artifacts === []) {
            return false;
        }
        $createdAt = $this->timestamp($artifacts[0]->created_at) ?? 0;
        return time() - $createdAt < self::DAYDREAM_COOLDOWN_SECONDS;
    }

    /**
     * Divergence's current develop-tree Factory::instantiateRecord() hydrates
     * non-empty arrays as existing records. Constructing explicitly preserves
     * ActiveRecord's phantom/new-record semantics for inserts.
     *
     * @param class-string<ActiveRecord> $modelClass
     * @param array<string, mixed> $values
     */
    private function insert(string $modelClass, array $values): ActiveRecord
    {
        if ($modelClass::fieldExists('created_at') && !array_key_exists('created_at', $values)) {
            // Divergence maps timestamps through PHP's timezone. Supplying the
            // epoch avoids reinterpreting SQLite's UTC CURRENT_TIMESTAMP as a
            // local wall-clock value when the record is read back.
            $values['created_at'] = time();
        }

        $record = new $modelClass($values, true, true);
        $record->save();
        return $record;
    }

    private function requireIntention(int $id): Intention
    {
        $record = Intention::getByID($id);
        if (!$record instanceof Intention) {
            throw new RuntimeException(sprintf('Intention %d does not exist.', $id));
        }
        return $record;
    }

    private function requireAction(int $id): ActionTrace
    {
        $record = ActionTrace::getByID($id);
        if (!$record instanceof ActionTrace) {
            throw new RuntimeException(sprintf('Action %d does not exist.', $id));
        }
        return $record;
    }

    private function requireEvent(int $id): Event
    {
        $record = Event::getByID($id);
        if (!$record instanceof Event) {
            throw new RuntimeException(sprintf('Event %d does not exist.', $id));
        }
        return $record;
    }

    private function requireMemory(int $id): Memory
    {
        $record = Memory::getByID($id);
        if (!$record instanceof Memory) {
            throw new RuntimeException(sprintf('Memory %d does not exist.', $id));
        }
        return $record;
    }

    private function requireText(string $value, string $name): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException($name . ' cannot be empty.');
        }
    }

    /** @param list<string> $choices */
    private function requireChoice(string $value, array $choices, string $name): void
    {
        if (!in_array($value, $choices, true)) {
            throw new InvalidArgumentException(sprintf(
                '%s must be one of: %s.',
                $name,
                implode(', ', $choices)
            ));
        }
    }

    private function requireUnitInterval(float $value, string $name): void
    {
        if ($value < 0.0 || $value > 1.0) {
            throw new InvalidArgumentException($name . ' must be between 0 and 1.');
        }
    }

    /** @param list<ActiveRecord> $records */
    private function records(array $records): array
    {
        return array_values(array_map(
            static fn (ActiveRecord $record): array => $record->getData(),
            $records
        ));
    }

    private function transaction(callable $callback): mixed
    {
        $ownsTransaction = !$this->connection->inTransaction();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->connection->commit();
            }
            return $result;
        } catch (Throwable $throwable) {
            if ($ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $throwable;
        }
    }
}

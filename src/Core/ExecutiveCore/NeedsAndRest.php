<?php

declare(strict_types=1);

namespace NaviBrain\Core\ExecutiveCore;

use InvalidArgumentException;
use Divergence\IO\Database\SQLite;
use NaviBrain\Core\NarrativeSynthesis;
use NaviBrain\Model\CycleRun;
use NaviBrain\Model\Memory;
use NaviBrain\Model\Need;
use NaviBrain\Model\ThoughtArtifact;
use NaviBrain\Storage\TokenMemoryDaemon;
use RuntimeException;
use Throwable;

class NeedsAndRest extends Component
{
    public function listNeeds(): array
    {
        $now = time();
        return array_values(array_map( fn (Need $need): array => $this->needState($need, $now), Need::getAll(['order' => ['need_key' => 'ASC']]) ));
    }

    public function setNeed(Need $proposal, string $rationale): array
    {
        $this->requireText($rationale, 'need change rationale');
        if (!$proposal->validate()) {
            throw new InvalidArgumentException(implode('; ', $proposal->validationErrors));
        }
        $need = Need::getByField('need_key', $proposal->need_key);
        $before = $need instanceof Need ? $need->getData() : null;
        if (!$need instanceof Need) {
            $need = $proposal;
            $need->last_satisfied_at = time();
        } else {
            $need->setFields([
                'description' => $proposal->description,
                'pressure' => $proposal->pressure,
                'growth_per_hour' => $proposal->growth_per_hour,
                'trigger_threshold' => $proposal->trigger_threshold,
                'status' => $proposal->status,
            ]);
        }
        $need->updated_at = time();
        $need->save();
        $event = $this->emit('need.changed', [
            'need_id' => $need->id,
            'need_key' => $need->need_key,
            'change_authority' => $proposal->authority,
            'rationale' => $rationale,
            'before' => $before,
            'after' => $need->getData(),
        ]);
        return ['need' => $need->getData(), 'event' => $event->getData()];
    }

    public function satisfyNeed(string $key, float $amount, string $source): array
    {
        $this->requireText($key, 'need key');
        $this->requireText($source, 'satisfaction source');
        $this->requireUnitInterval($amount, 'satisfaction amount');

        $need = Need::getByField('need_key', $key);
        if (!$need instanceof Need) {
            throw new RuntimeException(sprintf('Need %s does not exist.', $key));
        }

        return $this->satisfyNeedRecord($need, $amount, $source, time());
    }

    public function tickMind(): array
    {
        $needs = $this->accrueNeeds(time());
        $triggered = array_values(array_filter( $needs, static fn (array $need): bool => $need['status'] === 'active' && $need['triggered'] ));

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

        $now = time();
        $needsBefore = $this->accrueNeeds($now);
        $memoriesById = [];
        foreach (['semantic', 'episodic'] as $tier) {
            foreach (Memory::rankCandidates($reason, 48, $tier, 'active') as $memory) {
                $memoriesById[(int) $memory->id] = $memory;
            }
        }
        $memories = array_values($memoriesById);
        shuffle($memories);
        $memories = array_slice($memories, 0, Executive::DAYDREAM_MEMORY_LIMIT);
        Memory::observeRecords($memories);

        $sourceIds = array_values(array_map( static fn (Memory $memory): int => (int) $memory->id, $memories ));
        $fragments = array_values(array_filter(array_map( fn (Memory $memory): string => $this->randomMemoryFragment((string) $memory->content), $memories )));
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
        $content = sprintf('Unverified daydream seed. %s Free-associate across: %s What surprising possibility appears, and which current assumption would it challenge?', $lens, $rawMaterial);
        if ($this->containsFirstPersonModelIdentity($content)) {
            return [
                'state' => 'micro_wake',
                'reason' => $reason,
                'artifact' => null,
                'artifact_event' => null,
                'needs_before' => $needsBefore,
                'satisfaction' => [],
                'factual_status' => 'rejected_model_identity_claim',
                'external_action_authorized' => false,
            ];
        }
        $hash = hash('sha256', 'daydream|' . implode(',', $sourceIds) . '|' . $content);
        $artifact = ThoughtArtifact::getByField('content_hash', $hash);

        if (!$artifact instanceof ThoughtArtifact) {

            $artifact = new ThoughtArtifact([
                'run_id' => $runId,
                'kind' => 'wandering_association',
                'content' => $content,
                'confidence' => 0.2,
                'provenance' => 'daydream',
                'source_ids' => ['memory_ids' => $sourceIds],
                'status' => 'proposed',
                'content_hash' => $hash,
            ], true, true);
            $artifact->save();
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
                $satisfaction[] = $this->satisfyNeedRecord($need, $amount, 'sandboxed daydream artifact ' . $artifact->id, $now);
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
        $started = $this->emit('sleep.started', [ 'run_id' => $runId, 'reason' => $reason, 'before_checkpoint_id' => $before['id'], 'state' => 'sleeping', ]);

        try {
            $expiredMemories = [];
            $now = time();
            foreach (Memory::inspectAllByWhere(['tier' => 'working', 'status' => 'active']) as $memory) {
                $expiresAt = $this->timestamp($memory->expires_at);
                if ($expiresAt === null || $expiresAt > $now) {
                    continue;
                }
                $memory->setFields(['status' => 'expired', 'updated_at' => $now]);
                $memory->setOperationKey(TokenMemoryDaemon::operationKey( 'sleep-expire-working-memory', (string) $memory->id . ':' . (string) $expiresAt ));
                $memory->save();
                $expiredMemories[] = ['memory_id' => (int) $memory->id, 'expires_at' => $expiresAt];
            }
            $expired = [];
            foreach ($expiredMemories as $expiredMemory) {
                $event = $this->emit('memory.expired', [ 'memory_id' => $expiredMemory['memory_id'], 'expires_at' => $expiredMemory['expires_at'], 'repair_kind' => 'explicit_expiry', ]);
                $expired[] = [
                    'memory_id' => $expiredMemory['memory_id'],
                    'event_id' => $event->id,
                ];
            }

            $nonRem = [
                'integrity' => ['sqlite_quick_check' => 'ok'],
                'automatic_repairs' => $expired,
                'findings' => $this->cognitiveFindings($now),
            ];

            $repairArtifacts = $this->createRepairArtifacts($nonRem['findings'], $runId);

            $consolidation = $this->enqueueConsolidation($runId, time());
            $needs = $this->accrueNeeds(time());
            $bored = array_filter($needs, static fn (array $need): bool => $need['status'] === 'active' && $need['triggered']) !== [];
            $wandering = $bored && !$this->daydreamCooldownActive()
                ? $this->daydream('boredom surfaced during sleep replay', $runId)
                : null;

            $valueReview = $this->reviewValuesDue(time());

            $after = $this->checkpoint('sleep-after-audit');
            $completed = $this->emit('sleep.completed', [
                'values_due_for_review' => array_column($valueReview, 'value_key'),
                'run_id' => $runId,
                'start_event_id' => $started->id,
                'reason' => $reason,
                'before_checkpoint_id' => $before['id'],
                'after_checkpoint_id' => $after['id'],
                'non_rem' => $nonRem,
                'repair_artifact_ids' => array_column($repairArtifacts, 'id'),
                'consolidation_work_item_id' => $consolidation['work_item']['id'] ?? null,
                'daydream_artifact_id' => $wandering['artifact']['id'] ?? null,
                'state' => 'sleeping',
            ]);

            try {
                $narratives = (new NarrativeSynthesis($this->executive))->enqueue('completed sleep cycle: ' . $reason, (int) $completed->id);
            } catch (Throwable $throwable) {
                $failure = $this->emit('narrative.synthesis_queue_failed', [ 'sleep_event_id' => $completed->id, 'reason' => $reason, 'error' => $throwable->getMessage(), 'experimental' => true, ]);
                $narratives = [
                    'status' => 'queue_failed',
                    'error' => $throwable->getMessage(),
                    'event' => $failure->getData(),
                ];
            }

            return [
                'state' => 'sleeping',
                'reason' => $reason,
                'before_checkpoint' => $before,
                'non_rem' => $nonRem,
                'rem' => [
                    'repair_artifacts' => $repairArtifacts,
                    'wandering' => $wandering,
                ],
                'value_review' => $valueReview,
                'narrative_synthesis' => $narratives,
                'after_checkpoint' => $after,
                'event' => $completed->getData(),
                'checkpoint_kind' => 'persistence_barrier_not_restore_image',
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

    public function queueNarrativeSynthesis(string $reason): array
    {
        return (new NarrativeSynthesis($this->executive))->enqueue($reason);
    }

    public function ensureDefaultNeeds(): void
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

        foreach ($defaults as $values) {
            if (Need::getByField('need_key', $values['need_key']) instanceof Need) {
                continue;
            }

            $need = new Need(array_merge($values, [ 'last_satisfied_at' => time(), 'updated_at' => time(), 'status' => 'active', ]), true, true);
            $need->save();
            $this->emit('need.created', [ 'need_id' => $need->id, 'need_key' => $need->need_key, 'authority' => $need->authority, ]);
        }
    }

    public function needState(Need $need, int $now): array
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

    public function accrueNeeds(int $now): array
    {
        $states = [];
        foreach (Need::getAll(['order' => ['need_key' => 'ASC']]) as $need) {
            $state = $this->needState($need, $now);
            $need->setFields([ 'pressure' => $state['pressure'], 'updated_at' => $now, ]);
            $need->save();
            $states[] = $this->needState($need, $now);
        }
        return $states;
    }

    public function satisfyNeedRecord(Need $need, float $amount, string $source, int $now): array
    {
        $before = $this->needState($need, $now);
        $afterPressure = max(0.0, (float) $before['pressure'] - $amount);
        $need->setFields([ 'pressure' => $afterPressure, 'last_satisfied_at' => $now, 'updated_at' => $now, ]);
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

    public function randomMemoryFragment(string $content): string
    {
        $words = preg_split('/\s+/u', trim($content)) ?: [];
        if ($words === []) {
            return '';
        }
        $length = min(count($words), random_int(4, 8));
        $start = count($words) === $length ? 0 : random_int(0, count($words) - $length);
        return implode(' ', array_slice($words, $start, $length));
    }

    public function daydreamCooldownActive(): bool
    {
        $artifacts = ThoughtArtifact::getAllByWhere(['provenance' => 'daydream'], ['order' => ['created_at' => 'DESC'], 'limit' => 1]);
        if ($artifacts === []) {
            return false;
        }
        $createdAt = $this->timestamp($artifacts[0]->created_at) ?? 0;
        return time() - $createdAt < Executive::DAYDREAM_COOLDOWN_SECONDS;
    }
}

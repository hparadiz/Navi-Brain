<?php

declare(strict_types=1);

namespace NaviBrain\Core;

use NaviBrain\Core\ExecutiveCore\Executive;

use NaviBrain\Model\ActionTrace;
use NaviBrain\Model\CapsuleSlot;
use NaviBrain\Model\CognitiveThread;
use NaviBrain\Model\ContextCapsule;
use NaviBrain\Model\ExecutiveInterrupt;
use NaviBrain\Model\Intention;
use NaviBrain\Model\Memory;
use NaviBrain\Model\SenseEvent;
use NaviBrain\Model\SelfModelFact;
use NaviBrain\Model\ThreadStep;

class CapsuleAssembler
{
    /** @var array<string, array{query: string, types: list<string>, reserved?: bool}> */
    private const DEFAULT_ROSTER = [
        'safety_notice' => [
            'query' => 'safety interrupt halt pause authority revoked',
            'types' => ['interrupt'],
            'reserved' => true,
        ],
        'active_intention' => [
            'query' => 'authorized intention reason success release condition',
            'types' => ['intention'],
        ],
        'supporting_evidence' => [
            'query' => 'evidence observation record provenance verified',
            'types' => ['memory'],
        ],
        'open_discrepancy' => [
            'query' => 'discrepancy mismatch failed unexpected contradiction repair',
            'types' => ['memory', 'action'],
        ],
        'standing_constraint' => [
            'query' => 'constraint boundary must not authority ceiling permission limit',
            'types' => ['memory', 'self_model'],
        ],
        'self_model_caveat' => [
            'query' => 'capability limit cannot uncertain revisable caveat',
            'types' => ['self_model'],
        ],
        'applicable_procedure' => [
            'query' => 'procedure skill method how repeat expected verified',
            'types' => ['procedure'],
        ],
        'last_action_outcome' => [
            'query' => 'outcome observed result completed action',
            'types' => ['action', 'thread_step'],
        ],
    ];

    private const DEFAULT_HYSTERESIS = 0.15;

    private const RECENCY_WEIGHT = 7.0;
    private const RECENCY_HALF_LIFE = 900;

    private const MAX_CLAIM_CHARS = 400;
    private const CANDIDATES_PER_TYPE = 12;

    public function __construct(private readonly Executive $core)
    {
    }

    /** @return array{capsule: ContextCapsule, slots: list<CapsuleSlot>} */
    public function assemble(CognitiveThread $thread, ?ThreadStep $step, string $trigger): array {
        $budget = is_array($thread->budget) ? $thread->budget : [];
        $roster = $this->roster($budget);
        $slotBudget = max(1, (int) ($budget['capsule_slots'] ?? count($roster)));
        $roster = array_slice($roster, 0, $slotBudget, true);
        $hysteresis = is_numeric($budget['capsule_hysteresis'] ?? null)
            ? (float) $budget['capsule_hysteresis']
            : self::DEFAULT_HYSTERESIS;

        $previous = $this->previousCapsule((int) $thread->id);

        $incumbents = $this->core->workingMemory()->incumbents((int) $thread->id);

        $candidates = $this->gatherCandidates($thread, $roster);
        $candidateCount = 0;
        foreach ($candidates as $pool) {
            $candidateCount += count($pool);
        }

        $claimed = [];
        $resolved = [];
        foreach ($roster as $role => $spec) {
            $incumbent = $incumbents[$role] ?? null;
            $reserved = ($spec['reserved'] ?? false) === true;
            $pool = [];
            foreach ($spec['types'] as $type) {
                foreach ($candidates[$type] ?? [] as $candidate) {
                    $token = $candidate['record_type'] . ':' . $candidate['record_id'];
                    if (isset($claimed[$token])) {
                        continue;
                    }
                    $pool[] = $candidate;
                }
            }

            if (is_array($incumbent)
                && ($incumbent['record_id'] ?? null) !== null
                && isset($claimed[$incumbent['record_type'] . ':' . $incumbent['record_id']])
            ) {
                $incumbent = null;
            }
            $slot = $this->contest($role, $spec, $pool, $incumbent, $hysteresis);
            if ($slot['record_id'] !== null) {
                $claimed[$slot['record_type'] . ':' . $slot['record_id']] = true;
            }
            $resolved[$role] = $slot;
        }

        $assembled = (new ContextCapsule([
            'thread_id' => (int) $thread->id,
            'thread_step_id' => $step?->id,
            'previous_capsule_id' => $previous?->id,
            'trigger' => $trigger,
            'slot_budget' => $slotBudget,
            'candidate_count' => $candidateCount,
        ], true, true))->saveSlots($resolved);

        $this->core->workingMemory()->refresh($thread, $assembled['slots']);
        return $assembled;
    }

    /** @return list<array<string, mixed>> */
    public function serialize(int $capsuleId): array
    {
        return ContextCapsule::serializeSlots($capsuleId);
    }

    public function checksum(array $serialized): string
    {
        return hash('sha256', json_encode( $serialized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ));
    }

    public function maxCarryoverDepth(int $capsuleId): int
    {
        $depth = 0;
        foreach (CapsuleSlot::getAllByWhere(['capsule_id' => $capsuleId]) as $slot) {
            $depth = max($depth, (int) $slot->carryover_depth);
        }
        return $depth;
    }

    /** @return array<string, array{query: string, types: list<string>, reserved?: bool}> */
    private function roster(array $budget): array
    {
        $configured = $budget['capsule_roster'] ?? null;
        if (!is_array($configured) || $configured === []) {
            return self::DEFAULT_ROSTER;
        }
        $roster = [];
        foreach ($configured as $role => $spec) {
            if (!is_string($role) || !is_array($spec) || !is_array($spec['types'] ?? null)) {
                continue;
            }
            $roster[$role] = [
                'query' => is_string($spec['query'] ?? null) ? $spec['query'] : $role,
                'types' => array_values(array_filter( $spec['types'], static fn (mixed $type): bool => is_string($type) && $type !== '' )),
                'reserved' => ($spec['reserved'] ?? false) === true,
            ];
        }
        return $roster === [] ? self::DEFAULT_ROSTER : $roster;
    }

    private function previousCapsule(int $threadId): ?ContextCapsule
    {
        $capsules = ContextCapsule::getAllByWhere(['thread_id' => $threadId], ['order' => ['id' => 'DESC'], 'limit' => 1]);
        $capsule = $capsules[0] ?? null;
        return $capsule instanceof ContextCapsule ? $capsule : null;
    }

    /**
     * @param array<string, array{query: string, types: list<string>, reserved?: bool}> $roster
     * @return array<string, list<array<string, mixed>>>
     */
    private function gatherCandidates(CognitiveThread $thread, array $roster): array
    {
        $wanted = [];
        foreach ($roster as $spec) {
            foreach ($spec['types'] as $type) {
                $wanted[$type] = true;
            }
        }

        $candidates = [];
        $concern = (string) $thread->concern;

        if (isset($wanted['memory'])) {
            $candidates['memory'] = [];
            foreach ($this->core->searchMemory($concern, self::CANDIDATES_PER_TYPE * 2) as $memory) {

                if (!$this->eligibleMemoryCandidate($memory, 'memory')) {
                    continue;
                }
                $candidates['memory'][] = [
                    'record_type' => 'memory',
                    'record_id' => (int) ($memory['id'] ?? 0),
                    'claim' => $this->trim((string) ($memory['content'] ?? '')),
                    'confidence' => (float) ($memory['confidence'] ?? 0.0),
                    'recorded_at' => $memory['updated_at'] ?? null,
                ];
                if (count($candidates['memory']) >= self::CANDIDATES_PER_TYPE) {
                    break;
                }
            }
        }

        if (isset($wanted['procedure'])) {
            $candidates['procedure'] = [];
            foreach (Memory::getAllByWhere(['tier' => 'procedural', 'status' => 'active'], ['order' => ['updated_at' => 'DESC'], 'limit' => self::CANDIDATES_PER_TYPE]) as $procedure) {
                if (!$this->eligibleMemoryCandidate($procedure->getData(), 'procedure')) {
                    continue;
                }
                $candidates['procedure'][] = [
                    'record_type' => 'procedure',
                    'record_id' => (int) $procedure->id,
                    'claim' => $this->trim((string) $procedure->content),
                    'confidence' => (float) $procedure->confidence,
                    'recorded_at' => $procedure->updated_at,
                ];
            }
        }

        if (isset($wanted['self_model'])) {
            $candidates['self_model'] = [];
            foreach (SelfModelFact::getAll([ 'order' => ['updated_at' => 'DESC'], ]) as $fact) {
                if (!SelfModelFact::isModelContextVisible((string) $fact->fact_key)) {
                    continue;
                }
                $candidates['self_model'][] = [
                    'record_type' => 'self_model',
                    'record_id' => (int) $fact->id,
                    'claim' => $this->trim(sprintf('%s: %s', $fact->fact_key, $fact->fact_value)),
                    'confidence' => (float) $fact->confidence,
                    'recorded_at' => $fact->updated_at,
                ];
                if (count($candidates['self_model']) >= self::CANDIDATES_PER_TYPE) {
                    break;
                }
            }
        }

        if (isset($wanted['intention'])) {
            $candidates['intention'] = [];
            $intention = Intention::getByID((int) $thread->parent_intention_id);
            if ($intention instanceof Intention) {
                $candidates['intention'][] = [
                    'record_type' => 'intention',
                    'record_id' => (int) $intention->id,
                    'claim' => $this->trim(sprintf( '%s. Reason: %s. Release when: %s', $intention->title, $intention->reason, $intention->release_condition )),
                    'confidence' => 1.0,
                    'recorded_at' => $intention->updated_at,
                ];
            }
        }

        if (isset($wanted['action'])) {
            $candidates['action'] = [];
            foreach (ActionTrace::getAll([ 'order' => ['id' => 'DESC'], 'limit' => self::CANDIDATES_PER_TYPE, ]) as $action) {
                $candidates['action'][] = [
                    'record_type' => 'action',
                    'record_id' => (int) $action->id,
                    'claim' => $this->trim(sprintf('%s -> expected: %s; observed: %s (%s)', $action->description, $action->expected, (string) ($action->observed ?? 'not yet observed'), (string) $action->match_status)),
                    'confidence' => $action->match_status === 'matched' ? 0.9 : 0.5,
                    'recorded_at' => $action->completed_at ?? $action->created_at,
                ];
            }
        }

        if (isset($wanted['thread_step'])) {
            $candidates['thread_step'] = [];
            foreach (ThreadStep::getAllByWhere(['thread_id' => (int) $thread->id, 'curator_verdict' => 'accepted'], ['order' => ['id' => 'DESC'], 'limit' => self::CANDIDATES_PER_TYPE]) as $step) {
                $proposal = is_array($step->proposal) ? $step->proposal : [];
                $claim = is_string($proposal['content'] ?? null) ? (string) $proposal['content'] : '';
                if ($claim === '') {
                    continue;
                }
                $candidates['thread_step'][] = [
                    'record_type' => 'thread_step',
                    'record_id' => (int) $step->id,
                    'claim' => $this->trim(sprintf('%s: %s', $step->operation, $claim)),
                    'confidence' => (float) ($proposal['confidence'] ?? 0.0),
                    'recorded_at' => $step->completed_at,
                ];
            }
        }

        if (isset($wanted['sense_edge'])) {
            $candidates['sense_edge'] = [];
            foreach (SenseEvent::getAllByWhere(['outcome' => 'pending'], ['order' => ['id' => 'DESC'], 'limit' => self::CANDIDATES_PER_TYPE]) as $event) {
                $candidates['sense_edge'][] = [
                    'record_type' => 'sense_edge',
                    'record_id' => (int) $event->id,
                    'claim' => $this->trim(sprintf('[%s] %s', $event->sense_key, $event->summary)),
                    'confidence' => (float) $event->significance,
                    'recorded_at' => $event->observed_at,
                ];
            }
        }

        if (isset($wanted['interrupt'])) {
            $candidates['interrupt'] = [];
            foreach (ExecutiveInterrupt::getAllByWhere(['status' => 'pending'], ['order' => ['id' => 'DESC'], 'limit' => 4]) as $interrupt) {
                $candidates['interrupt'][] = [
                    'record_type' => 'interrupt',
                    'record_id' => (int) $interrupt->id,
                    'claim' => $this->trim(sprintf('[%s] %s', $interrupt->severity, $interrupt->reason)),
                    'confidence' => 1.0,
                    'recorded_at' => $interrupt->created_at,
                ];
            }
        }

        return $candidates;
    }

    /**
     * @param array{query: string, types: list<string>, reserved?: bool} $spec
     * @param list<array<string, mixed>> $pool
     * @return array<string, mixed>
     */
    private function contest(string $role, array $spec, array $pool, ?array $incumbent, float $hysteresis): array {
        $reserved = ($spec['reserved'] ?? false) === true;
        $incumbentPayload = null;
        if (is_array($incumbent) && ($incumbent['record_id'] ?? null) !== null) {
            $incumbentPayload = [
                'record_type' => (string) $incumbent['record_type'],
                'record_id' => (int) $incumbent['record_id'],
                'claim' => (string) $incumbent['claim'],
                'confidence' => (float) $incumbent['confidence'],
                'recorded_at' => $incumbent['recorded_at'] ?? null,
                'carryover_depth' => (int) ($incumbent['carryover_depth'] ?? 0),
            ];
        }

        if ($reserved) {
            $best = $pool[0] ?? null;
            if ($best === null) {
                return $this->emptySlot($role, $reserved);
            }
            $carried = $incumbentPayload !== null
                && $incumbentPayload['record_id'] === $best['record_id']
                && $incumbentPayload['record_type'] === $best['record_type'];
            return [
                'role' => $role,
                'reserved' => 1,
                'record_type' => $best['record_type'],
                'record_id' => $best['record_id'],
                'claim' => $best['claim'],
                'confidence' => $best['confidence'],
                'recorded_at' => $best['recorded_at'],
                'score' => 1.0,
                'transition' => $carried ? 'keep' : 'fill',
                'reason' => 'Reserved safety slot is placed without competition.',
                'carryover_depth' => $carried ? ($incumbentPayload['carryover_depth'] + 1) : 0,
                'carried_from' => $carried ? true : false,
            ];
        }

        $scored = [];
        foreach ($pool as $candidate) {
            $scored[] = [
                'candidate' => $candidate,
                'score' => $this->score($spec['query'], $candidate),
            ];
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $challenger = $scored[0] ?? null;

        $incumbentScore = $incumbentPayload === null
            ? null
            : $this->score($spec['query'], $incumbentPayload);

        if ($incumbentPayload === null) {
            if ($challenger === null) {
                return $this->emptySlot($role, $reserved);
            }
            return $this->fromCandidate($challenger['candidate'], (object) ['role' => $role, 'score' => $challenger['score'], 'transition' => 'fill', 'reason' => 'Slot was empty.', 'carryoverDepth' => 0, 'carried' => false]);
        }

        if ($challenger === null) {
            return $this->fromCandidate(
                $incumbentPayload, (object) ['role' => $role, 'score' => $incumbentScore ?? 0.0, 'transition' => 'keep', 'reason' => 'No challenger was available for this slot.', 'carryoverDepth' => $incumbentPayload['carryover_depth'] + 1, 'carried' => true]
            );
        }

        $sameRecord = $challenger['candidate']['record_type'] === $incumbentPayload['record_type']
            && $challenger['candidate']['record_id'] === $incumbentPayload['record_id'];
        if ($sameRecord) {
            return $this->fromCandidate(
                $challenger['candidate'], (object) ['role' => $role, 'score' => $challenger['score'], 'transition' => 'keep', 'reason' => 'The incumbent record won its own slot again.', 'carryoverDepth' => $incumbentPayload['carryover_depth'] + 1, 'carried' => true]
            );
        }

        if ($challenger['score'] > ($incumbentScore ?? 0.0) * (1.0 + $hysteresis)) {
            return $this->fromCandidate(
                $challenger['candidate'], (object) ['role' => $role, 'score' => $challenger['score'], 'transition' => 'replace', 'reason' => sprintf('Challenger scored %.4f against incumbent %.4f, clearing the %.0f%% hysteresis margin.', $challenger['score'], $incumbentScore ?? 0.0, $hysteresis * 100), 'carryoverDepth' => 0, 'carried' => false]
            );
        }

        return $this->fromCandidate(
            $incumbentPayload, (object) ['role' => $role, 'score' => $incumbentScore ?? 0.0, 'transition' => 'keep', 'reason' => sprintf('Incumbent held at %.4f; best challenger %.4f did not clear the margin.', $incumbentScore ?? 0.0, $challenger['score']), 'carryoverDepth' => $incumbentPayload['carryover_depth'] + 1, 'carried' => true]
        );
    }

    /** @param array<string, mixed> $candidate */
    private function score(string $query, array $candidate): float
    {
        $haystack = $this->normalize((string) ($candidate['claim'] ?? ''));
        if ($haystack === '') {
            return 0.0;
        }
        $terms = array_values(array_unique(array_filter( preg_split('/[^\p{L}\p{N}]+/u', $this->normalize($query)) ?: [], static fn (string $term): bool => strlen($term) > 2 )));
        if ($terms === []) {
            return (float) ($candidate['confidence'] ?? 0.0);
        }

        $matched = 0;
        foreach ($terms as $term) {
            if (str_contains($haystack, $term)) {
                $matched++;
            }
        }
        $coverage = $matched / count($terms);
        $confidence = max(0.0, min(1.0, (float) ($candidate['confidence'] ?? 0.0)));

        $recency = 0.0;
        $recordedAt = $this->timestampOf($candidate['recorded_at'] ?? null);
        if ($recordedAt !== null) {
            $age = max(0, time() - $recordedAt);
            $recency = self::RECENCY_WEIGHT * (2 ** (-$age / self::RECENCY_HALF_LIFE));
        }

        return round(($coverage * 10.0) + $confidence + $recency, 4);
    }

    /** @param array<string, mixed> $record */
    private function eligibleMemoryCandidate(array $record, string $type): bool
    {

        $tiers = $type === 'procedure' ? ['procedural'] : ['episodic', 'semantic'];
        if (($record['status'] ?? null) !== 'active' || !in_array($record['tier'] ?? null, $tiers, true)) {
            return false;
        }
        $expiry = $record['expires_at'] ?? null;
        return $expiry === null || ($this->timestampOf($expiry) ?? 0) > time();
    }

    private function timestampOf(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_numeric($value)) {
            return (int) $value;
        }
        $parsed = strtotime((string) $value);
        return $parsed === false ? null : $parsed;
    }

    /** @return array<string, mixed> */
    private function fromCandidate(array $candidate, \stdClass $placement): array {
        return [
            'role' => $placement->role,
            'reserved' => 0,
            'record_type' => $candidate['record_type'],
            'record_id' => $candidate['record_id'],
            'claim' => $candidate['claim'],
            'confidence' => $candidate['confidence'],
            'recorded_at' => $candidate['recorded_at'] ?? null,
            'score' => $placement->score,
            'transition' => $placement->transition,
            'reason' => $placement->reason,
            'carryover_depth' => $placement->carryoverDepth,
            'carried_from' => $placement->carried,
        ];
    }

    /** @return array<string, mixed> */
    private function emptySlot(string $role, bool $reserved): array
    {
        return [
            'role' => $role,
            'reserved' => $reserved ? 1 : 0,
            'record_type' => null,
            'record_id' => null,
            'claim' => null,
            'confidence' => 0.0,
            'recorded_at' => null,
            'score' => 0.0,
            'transition' => 'empty',
            'reason' => 'No candidate of an accepted type was available.',
            'carryover_depth' => 0,
            'carried_from' => false,
        ];
    }

    private function trim(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? $text;
        return mb_substr($text, 0, self::MAX_CLAIM_CHARS);
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(trim($text));
    }
}
